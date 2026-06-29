<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LLM API istemcisi.
 *
 * Desteklenenler:
 *  - MiniMax / OpenAI (ve tüm OpenAI-uyumlu) → /chat/completions, Bearer auth
 *  - Google Gemini                            → /v1beta/models/{model}:generateContent, API-key query
 *  - Anthropic Claude                         → /v1/messages, x-api-key auth
 *
 * Ortak giriş noktası: chat(). Provider seçimi opts['provider'] ile.
 * System prompt + source injection + thinking strip + context window optimizasyonu
 * tüm provider'larda çalışır; her provider kendi istek/yanıt formatına dönüştürülür.
 */
class AIClient {

    /**
     * Ana chat metodu. Hem Mod 1 (basit WP search + LLM) hem Mod 2 (ON)
     * için ortak giriş noktası.
     *
     * @param array $messages  OpenAI uyumlu messages dizisi
     * @param array $context   ['sources' => [...], 'mode' => 'simple'|'open_notebook', 'system_suffix' => '...']
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
        // Summarize handler kendi system message'ını ekliyor; chat handler yalnızca
        // user/history gönderiyor, bu durumda kimlik şablonunu kullan.
        if ( empty( $messages ) || ! isset( $messages[0]['role'] ) || 'system' !== $messages[0]['role'] ) {
            $default_system = smart_assistant_build_identity_prompt( $opts['system_prompt'] ?? '' );
            if ( ! empty( $context['system_suffix'] ) ) {
                $default_system .= "\n\n" . $context['system_suffix'];
            }
            array_unshift( $messages, [ 'role' => 'system', 'content' => $default_system ] );
        }

        $messages = $this->optimize_context( $messages );

        if ( ! empty( $context['sources'] ) ) {
            $messages = $this->inject_sources( $messages, $context['sources'] );
        }

        $raw = $this->dispatch_chat_request( $messages, $opts );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $content = $this->strip_thinking( $raw['content'] );

        if ( preg_match( '/target="[^"]*"|rel="[^"]*"|href="[^"]*"|<a\s/i', $content ) ) {
            smart_assistant_log( 'AI bozuk HTML içeriyor (pre-cleanup): ' . mb_substr( $content, 0, 500 ) );
        }

        $content = $this->strip_broken_links( $content );

        return [
            'content' => (string) $content,
            'model'   => $raw['model'],
            'usage'   => $raw['usage'],
        ];
    }

    /**
     * Düşünme etiketlerini ve prefix'lerini temizler.
     */
    public function strip_thinking( $text ) {
        $text = preg_replace( '/<(thinking|think|THINK)>.*?<\/\1>/si', '', $text );
        $text = preg_replace( '/^(Düşünüyorum|Let me think|Hadi bakalım|Let me consider|I should)(:\s*|\s+).*?\n/i', '', $text, 1 );
        $text = preg_replace( '/```thinking.*?```/si', '', $text );
        return trim( $text );
    }

    /**
     * Makale için 3 öneri soru üretir.
     */
    public function suggest_questions( $context_title, $context_excerpt, $count = 3 ) {
        $opts = smart_assistant_get_options();

        if ( empty( $opts['api_key'] ) ) {
            return new \WP_Error( 'no_api_key', __( 'API anahtarı ayarlanmamış.', 'smart-assistant' ) );
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

        $messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user', 'content' => trim( "Başlık: " . $context_title . "\n\nÖzet: " . $context_excerpt ) ],
        ];

        // Öneri sorular için düşük token, yüksek yaratıcılık; ana opts'tan türetilir.
        $sq_opts = array_merge( $opts, [ 'temperature' => 0.7, 'max_tokens' => 200 ] );
        $raw     = $this->dispatch_chat_request( $messages, $sq_opts );

        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $content = $raw['content'];
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

    // =========================================================================
    // Provider dispatch
    // =========================================================================

    /**
     * Provider'a göre uygun istek metodunu çağırır.
     * Tüm metodlar aynı dönüş formatını kullanır:
     *   ['content' => string, 'model' => string, 'usage' => array|null]
     * veya WP_Error.
     */
    private function dispatch_chat_request( array $messages, array $opts ) {
        $provider = strtolower( $opts['provider'] ?? 'minimax' );
        switch ( $provider ) {
            case 'gemini':
                return $this->request_gemini( $messages, $opts );
            case 'anthropic':
                return $this->request_anthropic( $messages, $opts );
            default: // 'minimax', 'openai' ve diğer OpenAI-uyumlu provider'lar
                return $this->request_openai_compat( $messages, $opts );
        }
    }

    /**
     * MiniMax, OpenAI ve OpenAI-uyumlu provider'lar.
     * Auth: Authorization: Bearer {api_key}
     * Endpoint: {api_base_url}/chat/completions
     * MiniMax Token Plan için group_id sorgu parametresi olarak eklenir.
     */
    private function request_openai_compat( array $messages, array $opts ) {
        $endpoint = rtrim( $opts['api_base_url'], '/' ) . '/chat/completions';

        if ( ! empty( $opts['group_id'] ) ) {
            $endpoint .= ( false === strpos( $endpoint, '?' ) ? '?' : '&' )
                        . 'GroupId=' . rawurlencode( $opts['group_id'] );
        }

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( ! empty( $opts['api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $opts['api_key'];
        }

        $body = [
            'model'       => $opts['model'],
            'messages'    => $messages,
            'temperature' => (float) $opts['temperature'],
            'max_tokens'  => (int) $opts['max_tokens'],
            'stream'      => false,
        ];

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

        return [
            'content' => $content,
            'model'   => $data['model'] ?? $body['model'],
            'usage'   => $data['usage'] ?? null,
        ];
    }

    /**
     * Google Gemini — Native API (OpenAI-uyumlu değil).
     *
     * Auth:     ?key={api_key} sorgu parametresi
     * Endpoint: {api_base_url}/v1beta/models/{model}:generateContent
     *
     * OpenAI'den farklar:
     *  - Sistem mesajları `systemInstruction` üst alanına taşınır (contents'a dahil edilemez).
     *  - Asistan rolü 'model', kullanıcı rolü 'user' (assistant yerine).
     *  - Ardışık aynı-rol turn'ler API hatası verir; birleştirilmeli.
     *  - İçerik 'parts' dizisi içinde { text: ... } nesnesi olarak verilmeli.
     *  - generationConfig.maxOutputTokens (max_tokens yerine).
     */
    private function request_gemini( array $messages, array $opts ) {
        if ( empty( $opts['api_key'] ) ) {
            return new \WP_Error( 'no_api_key', __( 'API anahtarı ayarlanmamış.', 'smart-assistant' ) );
        }

        $system_parts = [];
        $contents     = [];

        foreach ( $messages as $m ) {
            if ( 'system' === $m['role'] ) {
                $system_parts[] = [ 'text' => (string) $m['content'] ];
                continue;
            }
            $role       = 'assistant' === $m['role'] ? 'model' : 'user';
            $contents[] = [
                'role'  => $role,
                'parts' => [ [ 'text' => (string) $m['content'] ] ],
            ];
        }

        // Ardışık aynı-rol turn'leri birleştir; ilk turn 'user' olmak zorunda.
        $contents = $this->gemini_normalize_turns( $contents );

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => (float) $opts['temperature'],
                'maxOutputTokens' => (int) $opts['max_tokens'],
            ],
        ];

        if ( ! empty( $system_parts ) ) {
            $body['systemInstruction'] = [ 'parts' => $system_parts ];
        }

        $model    = ! empty( $opts['model'] ) ? $opts['model'] : 'gemini-2.0-flash';
        $base     = rtrim( $opts['api_base_url'], '/' );
        $endpoint = $base . '/v1beta/models/' . rawurlencode( $model )
                    . ':generateContent?key=' . rawurlencode( $opts['api_key'] );

        $response = wp_remote_post( $endpoint, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            smart_assistant_log( 'Gemini HTTP error: ' . $response->get_error_message(), 'error' );
            return new \WP_Error(
                'ai_http_error',
                sprintf( __( 'AI servisine ulaşılamadı: %s', 'smart-assistant' ), $response->get_error_message() )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $this->extract_error( $data ) ?: sprintf( __( 'HTTP %d', 'smart-assistant' ), $code );
            smart_assistant_log( 'Gemini API error ' . $code . ': ' . $err, 'error' );
            return new \WP_Error( 'ai_api_error', $err, [ 'status' => $code ] );
        }

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ( '' === $content ) {
            return new \WP_Error( 'empty_response', __( 'AI boş cevap döndü.', 'smart-assistant' ) );
        }

        $meta = $data['usageMetadata'] ?? [];
        return [
            'content' => $content,
            'model'   => $model,
            'usage'   => [
                'prompt_tokens'     => $meta['promptTokenCount']     ?? null,
                'completion_tokens' => $meta['candidatesTokenCount'] ?? null,
                'total_tokens'      => $meta['totalTokenCount']      ?? null,
            ],
        ];
    }

    /**
     * Gemini ardışık aynı-rol turn'leri kabul etmez; içeriklerini birleştiririz.
     * Konuşma 'user' turn ile başlamalı (API şartı).
     */
    private function gemini_normalize_turns( array $contents ) {
        $out = [];
        foreach ( $contents as $c ) {
            if ( ! empty( $out ) && end( $out )['role'] === $c['role'] ) {
                $last          = array_pop( $out );
                $last['parts'] = array_merge( $last['parts'], $c['parts'] );
                $out[]         = $last;
            } else {
                $out[] = $c;
            }
        }
        if ( ! empty( $out ) && 'user' !== $out[0]['role'] ) {
            array_unshift( $out, [ 'role' => 'user', 'parts' => [ [ 'text' => '' ] ] ] );
        }
        return $out;
    }

    /**
     * Anthropic Claude — Messages API (OpenAI-uyumlu değil).
     *
     * Auth:     x-api-key: {api_key} başlığı + anthropic-version başlığı
     * Endpoint: {api_base_url}/v1/messages
     *
     * OpenAI'den farklar:
     *  - Sistem mesajları 'system' üst alanına taşınır (messages'a dahil edilemez).
     *  - max_tokens zorunlu; temperature opsiyonel (0 olunca gönderilmez).
     *  - Yanıt: content[].text (choices[].message.content yerine).
     *  - usage: input_tokens / output_tokens (prompt_tokens / completion_tokens yerine).
     */
    private function request_anthropic( array $messages, array $opts ) {
        if ( empty( $opts['api_key'] ) ) {
            return new \WP_Error( 'no_api_key', __( 'API anahtarı ayarlanmamış.', 'smart-assistant' ) );
        }

        $system_parts       = [];
        $anthropic_messages = [];

        foreach ( $messages as $m ) {
            if ( 'system' === $m['role'] ) {
                $system_parts[] = (string) $m['content'];
                continue;
            }
            $anthropic_messages[] = [
                'role'    => $m['role'], // 'user' | 'assistant'
                'content' => (string) $m['content'],
            ];
        }

        $body = [
            'model'      => ! empty( $opts['model'] ) ? $opts['model'] : 'claude-sonnet-4-6',
            'messages'   => $anthropic_messages,
            'max_tokens' => (int) $opts['max_tokens'],
        ];

        if ( ! empty( $system_parts ) ) {
            $body['system'] = implode( "\n\n", $system_parts );
        }

        $temp = (float) $opts['temperature'];
        if ( $temp > 0 ) {
            $body['temperature'] = $temp;
        }

        $endpoint = rtrim( $opts['api_base_url'], '/' ) . '/v1/messages';
        $headers  = [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $opts['api_key'],
            'anthropic-version' => '2023-06-01',
        ];

        $response = wp_remote_post( $endpoint, [
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            smart_assistant_log( 'Anthropic HTTP error: ' . $response->get_error_message(), 'error' );
            return new \WP_Error(
                'ai_http_error',
                sprintf( __( 'AI servisine ulaşılamadı: %s', 'smart-assistant' ), $response->get_error_message() )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $this->extract_error( $data ) ?: sprintf( __( 'HTTP %d', 'smart-assistant' ), $code );
            smart_assistant_log( 'Anthropic API error ' . $code . ': ' . $err, 'error' );
            return new \WP_Error( 'ai_api_error', $err, [ 'status' => $code ] );
        }

        // Anthropic yanıt: { content: [{ type: 'text', text: '...' }, ...] }
        $content = '';
        foreach ( ( $data['content'] ?? [] ) as $block ) {
            if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
                $content .= $block['text'];
            }
        }

        if ( '' === $content ) {
            return new \WP_Error( 'empty_response', __( 'AI boş cevap döndü.', 'smart-assistant' ) );
        }

        $usage_raw = $data['usage'] ?? [];
        $in        = (int) ( $usage_raw['input_tokens']  ?? 0 );
        $out_tok   = (int) ( $usage_raw['output_tokens'] ?? 0 );

        return [
            'content' => $content,
            'model'   => $data['model'] ?? $body['model'],
            'usage'   => [
                'prompt_tokens'     => $in,
                'completion_tokens' => $out_tok,
                'total_tokens'      => $in + $out_tok,
            ],
        ];
    }

    // =========================================================================
    // Context / Source helpers
    // =========================================================================

    /**
     * Context window optimizasyonu.
     * - Token budget (default 4000): toplam context'i sınırlar.
     * - Sliding window: son N mesajı tamamen korur, eskileri özetler.
     * - Eski mesajlar kısa bir özete çevrilir (LLM call yok; hızlı).
     *
     * @param array $messages  OpenAI uyumlu messages
     * @return array           Optimize edilmiş messages
     */
    private function optimize_context( $messages ) {
        $max_tokens    = 4000;
        $keep_recent   = 6;
        $chars_per_tok = 3; // Türkçe için ~3 char/token (muhafazakâr).

        $system = null;
        if ( ! empty( $messages ) && isset( $messages[0]['role'] ) && 'system' === $messages[0]['role'] ) {
            $system = array_shift( $messages );
        }

        $estimate = function ( $arr ) use ( $chars_per_tok ) {
            $chars = 0;
            foreach ( $arr as $m ) {
                $chars += isset( $m['content'] ) ? mb_strlen( $m['content'] ) : 0;
            }
            return (int) ceil( $chars / $chars_per_tok );
        };

        $sys_tokens   = $system ? $estimate( [ $system ] ) : 0;
        $total_tokens = $sys_tokens + $estimate( $messages );

        if ( count( $messages ) <= $keep_recent + 2 && $total_tokens <= $max_tokens ) {
            return $system ? array_merge( [ $system ], $messages ) : $messages;
        }

        $recent = array_values( array_slice( $messages, -$keep_recent ) );
        $older  = array_values( array_slice( $messages, 0, count( $messages ) - $keep_recent ) );

        $summary_parts = [];
        $char_budget   = 800;
        $used          = 0;
        foreach ( array_reverse( $older ) as $m ) {
            if ( $used >= $char_budget ) break;
            $role  = isset( $m['role'] ) ? $m['role'] : 'user';
            $label = 'user' === $role ? 'Kullanıcı' : 'Asistan';
            $snip  = str_replace( [ "\n", "\r" ], ' ', mb_substr( trim( $m['content'] ?? '' ), 0, 140 ) );
            $line  = "{$label}: {$snip}";
            $summary_parts[] = $line;
            $used += mb_strlen( $line );
        }
        $summary_text = mb_substr(
            'Önceki konuşma özeti (sırayla): ' . implode( ' | ', array_reverse( $summary_parts ) ),
            0,
            $char_budget
        );

        $result = [];
        if ( $system ) {
            $result[] = $system;
        }
        $result[] = [ 'role' => 'system', 'content' => $summary_text ];
        foreach ( $recent as $m ) {
            $result[] = $m;
        }
        return $result;
    }

    /**
     * Kaynakları son user mesajına context olarak enjekte eder.
     */
    private function inject_sources( $messages, $sources ) {
        $count  = count( $sources );
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
            $n         = $i + 1;
            $title     = $s['title']     ?? '(başlıksız)';
            $url       = $s['url']       ?? '';
            $content   = ! empty( $s['content'] ) ? $s['content'] : ( $s['excerpt'] ?? '' );
            $truncated = ! empty( $s['truncated'] );
            $words     = $s['word_count'] ?? null;

            $header = "[" . $n . "] " . $title;
            if ( $words ) {
                $header .= " (" . $words . " kelime" . ( $truncated ? ', özetli' : '' ) . ')';
            }
            $header .= "\nURL: " . $url . "\n";
            $block  .= $header . $content . "\n\n";
        }

        $block .= "\nSONUÇ: Yukarıdaki " . $count . " kaynaktan en alakalı olanı ilk sırada. "
                . "Kullanıcının sorusuna cevap verirken bu kaynakların içeriğini özetle ve markdown linki ile yönlendir. "
                . "KENDI BİLGİNLE CEVAP YAZMA, sadece kaynaklara dayan.\n";
        $block .= "!!! LİNK FORMATI !!!: URL'leri SADECE markdown formatında ver: [Başlık](URL). "
                . "ASLA <a href=\"...\"> HTML link formatı KULLANMA, ASLA target=\"_blank\" rel=\"...\" attribute'leri EKLEME. "
                . "Bağlam metninde gördüğün HTML taglerini (varsa) KOPYALAMA.\n";

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
     * formatını cevabına kopyalarken eksik veya bozuk üretir. Bunları temizleriz.
     */
    public function strip_broken_links( $text ) {
        // Pattern 1: URL sonrası target="_blank" attribute + rel="..." + >TEXT
        $text = preg_replace(
            '/(?:https?:\/\/[^\s<>"\']+)["\']\s+target="[^"]*"\s+rel="[^"]*"\s*>\s*([^\n]+?)(?=\s*[\n\.,;!?]|\s*$)/iu',
            '$1',
            $text
        );

        // Pattern 2: Sadece target="_blank" rel="..." kalmışsa (URL öncesinde kesilmiş)
        $text = preg_replace(
            '/\s*target="_blank"\s+rel="[^"]*"\s*>\s*([^\n]+?)(?=\s*[\n\.,;!?]|\s*$)/iu',
            ' $1',
            $text
        );

        // Pattern 3: Yarım/kapanmamış <a ...> tagı (href sonrası kesilmiş)
        $text = preg_replace( '/<a\s+[^>]*$/imu', '', $text );

        return trim( $text );
    }

    /**
     * API hata mesajını çeşitli formatlardan çıkar.
     * Gemini, Anthropic ve OpenAI farklı hata şemaları kullanır.
     */
    private function extract_error( $data ) {
        if ( ! is_array( $data ) ) return null;
        if ( isset( $data['error']['message'] ) ) return $data['error']['message'];
        if ( isset( $data['message'] ) )          return $data['message'];
        if ( isset( $data['detail'] ) ) {
            if ( is_string( $data['detail'] ) )   return $data['detail'];
            if ( is_array( $data['detail'] ) && isset( $data['detail'][0]['msg'] ) ) {
                return $data['detail'][0]['msg'];
            }
        }
        return null;
    }
}
