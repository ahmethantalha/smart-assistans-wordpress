<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LLM API istemcisi.
 *
 * Desteklenenler: MiniMax (varsayılan), OpenAI, Gemini, Anthropic.
 * Streaming opsiyonel. System prompt + source injection + thinking strip
 * + kullanıcı mesajı -> [content, sources, model] formatında yanıt.
 */
class AIClient {

    /**
     * Ana chat metodu. Hem mod 1 (basit WP search + LLM) hem mod 2 (ON)
     * için ortak giriş noktası.
     *
     * @param array $messages  OpenAI uyumlu messages array
     * @param array $context   ['sources' => [...], 'mode' => 'simple'|'open_notebook']
     * @return array|WP_Error  ['content' => ..., 'model' => ..., 'usage' => ...]
     */
    public function chat( $messages, $context = [] ) {
        $opts = smart_assistant_get_options();

        if ( empty( $opts['api_key'] ) && 'open_notebook' !== ( $context['mode'] ?? '' ) ) {
            return new \WP_Error(
                'no_api_key',
                __( 'API anahtarı ayarlanmamış. Yönetici panelinden girin.', 'smart-assistant' )
            );
        }

        // System prompt: çağıran taraf eklemediyse otomatik ekle.
        // Summarize handle'ı kendi system message'ını ekliyor (özel direktiflerle);
        // chat handler sadece user/history gönderiyor, o zaman kimlik şablonunu kullan.
        if ( empty( $messages ) || ! isset( $messages[0]['role'] ) || 'system' !== $messages[0]['role'] ) {
            $default_system = smart_assistant_build_identity_prompt( $opts['system_prompt'] ?? '' );
            // Summarize modunda ek direktifler.
            if ( ! empty( $context['system_suffix'] ) ) {
                $default_system .= "\n\n" . $context['system_suffix'];
            }
            array_unshift( $messages, [ 'role' => 'system', 'content' => $default_system ] );
        }

        // Context yönetimi: token budget + sliding window + eski mesajları özetle.
        // Bu adım API'ye göndermeden HEMEN ÖNCE yapılır.
        $messages = $this->optimize_context( $messages );

        // Mod 2'de ON tarafında LLM çalışıyor; bu fonksiyon çağrılmaz.
        // Normal mod: kaynakları (varsa) en son user mesajına inject et.
        if ( ! empty( $context['sources'] ) ) {
            $messages = $this->inject_sources( $messages, $context['sources'] );
        }

        $body = [
            'model'       => $opts['model'],
            'messages'    => $messages,
            'temperature' => (float) $opts['temperature'],
            'max_tokens'  => (int) $opts['max_tokens'],
            'stream'      => false,
        ];

        $endpoint = rtrim( $opts['api_base_url'], '/' ) . '/chat/completions';
        $headers  = [
            'Content-Type'  => 'application/json',
        ];
        if ( ! empty( $opts['api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $opts['api_key'];
        }

        $response = wp_remote_post( $endpoint, [
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            smart_assistant_log( 'AI HTTP error: ' . $response->get_error_message(), 'error' );
            return new \WP_Error(
                'ai_http_error',
                sprintf( __( 'AI servisine ulaşılamadı: %s', 'smart-assistant' ), $response->get_error_message() )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $this->extract_error( $data ) ?: sprintf( __( 'HTTP %d', 'smart-assistant' ), $code );
            smart_assistant_log( 'AI API error ' . $code . ': ' . $err, 'error' );
            return new \WP_Error( 'ai_api_error', $err, [ 'status' => $code ] );
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( '' === $content ) {
            return new \WP_Error( 'empty_response', __( 'AI boş cevap döndü.', 'smart-assistant' ) );
        }

        $content = $this->strip_thinking( $content );

        // Bozuk HTML link kalıntısı varsa debug için log'a yaz.
        if ( preg_match( '/target="[^"]*"|rel="[^"]*"|href="[^"]*"|<a\s/i', $content ) ) {
            smart_assistant_log( 'AI bozuk HTML içeriyor (pre-cleanup): ' . mb_substr( $content, 0, 500 ) );
        }

        $content = $this->strip_broken_links( $content );

        return [
            'content' => (string) $content,
            'model'   => $data['model'] ?? $body['model'],
            'usage'   => $data['usage'] ?? null,
        ];
    }

    /**
     * Düşünme etiketlerini ve prefix'lerini temizler.
     * AI'lar bazen <thinking>...</thinking> veya "Düşünüyorum: ..." gibi
     * iç muhakemelerini cevaba ekler. Bunları temizleriz.
     */
    public function strip_thinking( $text ) {
        // <thinking>...</thinking>, <think>...</think>, [THINK]...[/THINK]
        $text = preg_replace( '/<(thinking|think|THINK)>.*?<\/\1>/si', '', $text );
        // Düşünüyorum: ... / Let me think: ... / Hadi bakalım: ...
        $text = preg_replace( '/^(Düşünüyorum|Let me think|Hadi bakalım|Let me consider|I should)(:\s*|\s+).*?\n/i', '', $text, 1 );
        // Markdown code block içinde <thinking> olabilir
        $text = preg_replace( '/```thinking.*?```/si', '', $text );
        return trim( $text );
    }

    /**
     * Bir özet/metin için "kullanıcının sorabileceği 3 kısa ilginç soru" üretir.
     *
     * @param string $context_title   Makale başlığı
     * @param string $context_excerpt Makalenin özeti (ilk 500 char)
     * @param int    $count           Kaç soru üretileceği (default 3)
     * @return array|WP_Error         string[] veya WP_Error
     */
    public function suggest_questions( $context_title, $context_excerpt, $count = 3 ) {
        $opts = smart_assistant_get_options();

        if ( empty( $opts['api_key'] ) ) {
            return new \WP_Error(
                'no_api_key',
                __( 'API anahtarı ayarlanmamış.', 'smart-assistant' )
            );
        }

        $system = sprintf(
            "Sen bir içerik öneri asistanısın. Sana bir makale başlığı ve özeti verilecek.\n" .
            "Bu makaleyi okuyan kullanıcının sorabileceği %d kısa ve ilginç soru öner.\n\n" .
            "KURALLAR:\n" .
            "- Sorular Türkçe olsun.\n" .
            "- Her soru 4-8 kelime, kısa ve net olsun.\n" .
            "- Sorular makaleyle doğrudan ilgili olsun, genel sorulardan kaçın.\n" .
            "- Sorular farklı açılardan olsun (içerik, bağlam, uygulama, görüş, vs.).\n" .
            "- SADECE geçerli bir JSON array döndür, başka hiçbir şey yazma. Markdown, açıklama yok.\n" .
            "Format örneği: [\"soru 1\", \"soru 2\", \"soru 3\"]",
            max( 1, min( 5, $count ) )
        );

        $user = trim( "Başlık: " . $context_title . "\n\nÖzet: " . $context_excerpt );

        $messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user', 'content' => $user ],
        ];

        $body = [
            'model'       => $opts['model'],
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 200,
            'stream'      => false,
        ];

        $endpoint = rtrim( $opts['api_base_url'], '/' ) . '/chat/completions';

        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $opts['api_key'],
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error( 'ai_api_error', sprintf( __( 'AI hatası (HTTP %d)', 'smart-assistant' ), $code ) );
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( '' === $content ) {
            smart_assistant_log( 'suggest_questions: AI boş içerik döndü.', 'warning' );
            return new \WP_Error( 'empty_suggestions', __( 'AI boş öneri döndü.', 'smart-assistant' ) );
        }

        $clean = trim( $content );
        $clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
        $clean = preg_replace( '/\s*```\s*$/', '', $clean );

        $questions = json_decode( $clean, true );

        if ( ! is_array( $questions ) ) {
            if ( preg_match( '/\[[\s\S]*?\]/u', $clean, $m ) ) {
                $questions = json_decode( $m[0], true );
            }
        }

        if ( ! is_array( $questions ) ) {
            smart_assistant_log(
                'suggest_questions: JSON parse başarısız. AI ham çıktı: ' . mb_substr( $clean, 0, 200 ),
                'error'
            );
            return new \WP_Error( 'parse_error', __( 'Öneriler parse edilemedi.', 'smart-assistant' ) );
        }

        $questions = array_values( array_filter( array_map( 'strval', $questions ), function ( $q ) {
            return '' !== trim( $q );
        } ) );

        return array_slice( $questions, 0, $count );
    }

    /**
     * Sources dizisini son user mesajına context olarak ekler.
     * AI'a en alakalı kaynağın ilk sırada verildiğinden emin olur.
     */
    /**
     * Context window optimizasyonu.
     * - Token budget (default 4000): toplam context'i sınırlar.
     * - Sliding window: son N mesajı tamamen korur, eskileri özetler.
     * - Eski mesajlar otomatik olarak kısa bir özete çevrilir (LLM call yok; hızlı).
     *
     * @param array $messages  OpenAI uyumlu messages
     * @return array           Optimize edilmiş messages
     */
    private function optimize_context( $messages ) {
        $max_tokens   = 4000;           // Toplam context üst sınırı.
        $keep_recent  = 6;              // Son N mesajı tam koru.
        $chars_per_tok = 3;             // Türkçe için ~3 char/token (muhafazakâr).

        // System message'ı ayır (korunur).
        $system = null;
        if ( ! empty( $messages ) && isset( $messages[0]['role'] ) && 'system' === $messages[0]['role'] ) {
            $system = array_shift( $messages );
        }

        // Token tahmini.
        $estimate = function ( $arr ) use ( $chars_per_tok ) {
            $chars = 0;
            foreach ( $arr as $m ) {
                $chars += isset( $m['content'] ) ? mb_strlen( $m['content'] ) : 0;
            }
            return (int) ceil( $chars / $chars_per_tok );
        };

        $sys_tokens = $system ? $estimate( [ $system ] ) : 0;

        // Mesaj sayısı az ve toplam token budget'a sığıyorsa, olduğu gibi döndür.
        $total_tokens = $sys_tokens + $estimate( $messages );
        if ( count( $messages ) <= $keep_recent + 2 && $total_tokens <= $max_tokens ) {
            return $system ? array_merge( [ $system ], $messages ) : $messages;
        }

        // Sliding window: son N mesajı koru.
        $recent  = array_slice( $messages, -$keep_recent );
        $recent  = array_values( $recent );
        $older   = array_slice( $messages, 0, count( $messages ) - $keep_recent );
        $older   = array_values( $older );

        // Eski mesajları basit ama etkili bir özete çevir.
        // Her mesajdan ilk 140 karakteri al, role belirt, topla. Max 800 char.
        $summary_parts = [];
        $char_budget   = 800;
        $used          = 0;
        foreach ( array_reverse( $older ) as $m ) { // Eski→Yeni sırayla göster.
            if ( $used >= $char_budget ) break;
            $role  = isset( $m['role'] ) ? $m['role'] : 'user';
            $label = 'user' === $role ? 'Kullanıcı' : 'Asistan';
            $snip  = mb_substr( trim( $m['content'] ?? '' ), 0, 140 );
            $snip  = str_replace( [ "\n", "\r" ], ' ', $snip );
            $line  = "{$label}: {$snip}";
            $summary_parts[] = $line;
            $used += mb_strlen( $line );
        }
        $summary_text = 'Önceki konuşma özeti (sırayla): ' . implode( ' | ', array_reverse( $summary_parts ) );
        $summary_text = mb_substr( $summary_text, 0, $char_budget );

        $summary_msg = [
            'role'    => 'system',
            'content' => $summary_text,
        ];

        $result = [];
        if ( $system ) {
            $result[] = $system;
        }
        $result[] = $summary_msg;
        foreach ( $recent as $m ) {
            $result[] = $m;
        }

        return $result;
    }

    private function inject_sources( $messages, $sources ) {
        $count = count( $sources );
        $block  = "\n\nİŞTE KULLANILABILIR KAYNAKLAR (site içeriğinden):\n";
        $block .= "Bu kaynaklar relevance scoring'e göre sıralanmıştır (EN ALAKALI İLK SIRADA). ";
        $block .= "!!! KESIN KURALLAR !!!\n";
        $block .= "1. Bu kaynaklardan en az BİRİNİ MUTLAKA kullan, kendi bilginle cevap YAZMA.\n";
        $block .= "2. ASLA 'sitede böyle bir şey varsa', 'eğer paylaşırsanız', 'bilgi verir misiniz' gibi cümleler kurma.\n";
        $block .= "3. ASLA 'sitede yazı bulamadım' deme, en az bir kaynağı yönlendir.\n";
        $block .= "4. Cevabının İLK cümlesi en alakalı kaynağın adı olsun.\n";
        $block .= "5. Sonra markdown linki ver: [Başlık](URL).\n\n";
        $block .= "KAYNAKLAR:\n";

        foreach ( $sources as $i => $s ) {
            $n        = $i + 1;
            $title    = $s['title']   ?? '(başlıksız)';
            $url      = $s['url']     ?? '';
            $content  = ! empty( $s['content'] ) ? $s['content'] : ( $s['excerpt'] ?? '' );
            $truncated = ! empty( $s['truncated'] );
            $words    = $s['word_count'] ?? null;

            $header = "[" . $n . "] " . $title;
            if ( $words ) {
                $header .= " (" . $words . " kelime" . ( $truncated ? ', özetli' : '' ) . ')';
            }
            $header .= "\nURL: " . $url . "\n";

            $block .= $header . $content . "\n\n";
        }
        $block .= "\nSONUÇ: Yukarıdaki " . $count . " kaynaktan en alakalı olanı ilk sırada. Kullanıcının sorusuna cevap verirken bu kaynakların içeriğini özetle ve markdown linki ile yönlendir. KENDI BİLGİNLE CEVAP YAZMA, sadece kaynaklara dayan.\n";
        $block .= "!!! LİNK FORMATI !!!: URL'leri SADECE markdown formatında ver: [Başlık](URL). ASLA <a href=\"...\">HTML link formatı KULLANMA, ASLA target=\"_blank\" rel=\"...\" attribute'leri EKLEME. Bağlam metninde gördüğün HTML taglerini (varsa) KOPYALAMA.\n";

        $last = end( $messages );
        if ( isset( $last['role'] ) && 'user' === $last['role'] ) {
            $key = key( $messages );
            $messages[ $key ]['content'] .= $block;
        } else {
            $messages[] = [ 'role' => 'user', 'content' => $block ];
        }
        return $messages;
    }

    /**
     * Bozuk HTML link kalıntılarını temizler.
     * AI bazen context'te gördüğü <a href="URL" target="_blank" rel="...">TEXT</a>
     * formatını cevabına kopyalarken <a href="URL" kısmını unutup gerisini yapıştırır.
     * Örnek bozuk output:
     *   https://site.com/yazi/" target="_blank" rel="noopener noreferrer">Yazı Başlığı
     * Bunu sadece "Yazı Başlığı" na çevirir.
     */
    public function strip_broken_links( $text ) {
        // Pattern 1: URL sonrası target="_blank" attribute + rel="..." + >TEXT
        // Örn: https://site.com/foo/" target="_blank" rel="noopener noreferrer">Some Title
        $text = preg_replace(
            '/(?:https?:\/\/[^\s<>"\']+)["\']\s+target="[^"]*"\s+rel="[^"]*"\s*>\s*([^\n]+?)(?=\s*[\n\.,;!?]|\s*$)/iu',
            '$1',
            $text
        );

        // Pattern 2: Sadece target="_blank" rel="..." kalmışsa (URL öncesinde kesilmiş)
        // Örn: " target="_blank" rel="noopener noreferrer">Some Title
        $text = preg_replace(
            '/\s*target="_blank"\s+rel="[^"]*"\s*>\s*([^\n]+?)(?=\s*[\n\.,;!?]|\s*$)/iu',
            ' $1',
            $text
        );

        // Pattern 3: Yarım <a ...> tagı (href sonrası kesilmiş)
        // Örn: <a href="https://site.com/foo" (kapanmamış)
        $text = preg_replace( '/<a\s+[^>]*$/imu', '', $text );

        // Pattern 4: Bozuk kapanmamış <a ...> tagları tamamen
        $text = preg_replace( '/<a\s+[^>]*$/imu', '', $text );

        return trim( $text );
    }

    /**
     * API hata mesajını çeşitli formatlardan çıkar.
     */
    private function extract_error( $data ) {
        if ( ! is_array( $data ) ) return null;
        if ( isset( $data['error']['message'] ) ) return $data['error']['message'];
        if ( isset( $data['message'] ) ) return $data['message'];
        if ( isset( $data['detail'] ) ) {
            if ( is_string( $data['detail'] ) ) return $data['detail'];
            if ( is_array( $data['detail'] ) && isset( $data['detail'][0]['msg'] ) ) {
                return $data['detail'][0]['msg'];
            }
        }
        return null;
    }
}
