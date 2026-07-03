<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API controller — frontend'in konuştuğu yer.
 *
 * Endpoint'ler:
 *  POST /wp-json/smart-assistant/v1/chat     → genel sohbet
 *  POST /wp-json/smart-assistant/v1/summarize → makale özetleme
 *  POST /wp-json/smart-assistant/v1/expand    → mini-chat'ten sütuna genişletme (sadece flag)
 */
class RestController {

    const NAMESPACE_V1 = 'smart-assistant/v1';

    public function register_hooks() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        // Debug/test endpoint — admin yetkisi gerekir.
        register_rest_route( self::NAMESPACE_V1, '/test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_test' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( self::NAMESPACE_V1, '/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_chat' ],
            'permission_callback' => '__return_true', // Public; rate limit ile korunuyor.
            'args'                => [
                'message' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'history' => [
                    'required' => false,
                    'type'     => 'array',
                    'default'  => [],
                ],
                'post_id' => [
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 0,
                ],
                'tool'    => [
                    'required' => false,
                    'type'     => 'string',
                    'default'  => '',
                ],
                'nonce'   => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/summarize', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_summarize' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'post_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'message' => [
                    'required' => false,
                    'type'     => 'string',
                    'default'  => '',
                ],
                'history' => [
                    'required' => false,
                    'type'     => 'array',
                    'default'  => [],
                ],
                'nonce'   => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ] );
    }

    /**
     * /test endpoint'i — admin debug. AI'a minimal ping atar ve response'u olduğu gibi döner.
     */
    public function handle_test( \WP_REST_Request $request ) {
        $opts  = smart_assistant_get_options();
        $start = microtime( true );

        $result = \SmartAssistant\Plugin::instance()->ai_client->chat(
            [
                [ 'role' => 'user', 'content' => 'Ping. Lütfen sadece "pong" diye cevap ver.' ],
            ],
            []
        );

        $elapsed = round( ( microtime( true ) - $start ) * 1000 );

        $debug = [
            'provider'        => $opts['provider'],
            'api_base_url'    => $opts['api_base_url'],
            'model'           => $opts['model'],
            'group_id_set'    => ! empty( $opts['group_id'] ),
            'api_key_length'  => strlen( $opts['api_key'] ?? '' ),
            'elapsed_ms'      => $elapsed,
        ];

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'ok'    => false,
                'error' => [
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ],
                'debug' => $debug,
            ] );
        }

        return rest_ensure_response( [
            'ok'      => true,
            'content' => $result['content'],
            'model'   => $result['model'],
            'usage'   => $result['usage'],
            'debug'   => $debug,
        ] );
    }

    /**
     * /chat endpoint'i.
     */
    public function handle_chat( \WP_REST_Request $request ) {
        $check = $this->preflight( $request );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $opts    = smart_assistant_get_options();
        $message = mb_substr( sanitize_text_field( $request->get_param( 'message' ) ), 0, self::MAX_MESSAGE_CHARS );
        $history = $this->normalize_history( $request->get_param( 'history' ) );
        $post_id = absint( $request->get_param( 'post_id' ) );
        $tool    = sanitize_key( (string) $request->get_param( 'tool' ) );
        $tools   = smart_assistant_get_tools();
        if ( '' !== $tool && ! isset( $tools[ $tool ] ) ) {
            $tool = '';
        }

        // Araç (hesaplayıcı) modu: site içeriğine bakılmaz, RAG araması atlanır.
        $sources = [];
        if ( '' !== $tool ) {
            // no-op — $sources boş kalır.
        } elseif ( 'open_notebook' === $opts['mode'] ) {
            $on_resp = \SmartAssistant\Plugin::instance()->open_notebook->ask( $message, $post_id );
            if ( is_wp_error( $on_resp ) ) {
                // ON hata verirse (örn. Gemini embedding quota bitti) sessizce Mod 1'e düş.
                // Kullanıcı yine cevap alır; WP search ile en iyi sonucu sunarız.
                smart_assistant_log(
                    'Mod 2 (Open Notebook) hata verdi, Mod 1 fallback devreye girdi: ' . $on_resp->get_error_message(),
                    'warning'
                );
                $sources = \SmartAssistant\Plugin::instance()->search->search( $message, $post_id );
            } else {
                // Çift güvenlik: ON'den gelen content'i de temizle (chat() yolundan gelmediği için).
                $cleaned = $on_resp['content'];
                $ai_client = \SmartAssistant\Plugin::instance()->ai_client;
                if ( $ai_client && method_exists( $ai_client, 'strip_broken_links' ) ) {
                    $cleaned = $ai_client->strip_broken_links( $cleaned );
                }
                return rest_ensure_response( [
                    'reply'   => $cleaned,
                    'sources' => $on_resp['sources'],
                    'model'   => 'open_notebook',
                ] );
            }
        } else {
            // Mod 1: WP search.
            $sources = \SmartAssistant\Plugin::instance()->search->search( $message, $post_id );
        }

        // Geçmiş + yeni mesaj.
        $messages   = $history;
        $messages[] = [ 'role' => 'user', 'content' => $message ];

        $ai_resp = \SmartAssistant\Plugin::instance()->ai_client->chat( $messages, [
            'sources' => $sources,
            'mode'    => 'simple',
            'tool'    => $tool,
        ] );

        if ( is_wp_error( $ai_resp ) ) {
            return $this->error_response( $ai_resp );
        }

        return rest_ensure_response( [
            'reply'   => $ai_resp['content'],
            'sources' => $sources,
            'model'   => $ai_resp['model'],
            'usage'   => $ai_resp['usage'],
        ] );
    }

    /**
     * /summarize endpoint'i — FAB özetleme butonu için.
     */
    public function handle_summarize( \WP_REST_Request $request ) {
        $check = $this->preflight( $request );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $opts    = smart_assistant_get_options();
        $post_id = absint( $request->get_param( 'post_id' ) );
        $message = mb_substr( sanitize_text_field( $request->get_param( 'message' ) ), 0, self::MAX_MESSAGE_CHARS );
        $history = $this->normalize_history( $request->get_param( 'history' ) );

        if ( ! $post_id ) {
            return $this->error_response( new \WP_Error( 'no_post', __( 'Post ID gerekli.', 'smart-assistant' ) ) );
        }

        // Post içeriğini al.
        $source = \SmartAssistant\Plugin::instance()->search->get_post( $post_id );
        if ( ! $source ) {
            return $this->error_response( new \WP_Error( 'post_not_found', __( 'Yazı bulunamadı.', 'smart-assistant' ) ) );
        }

        // Eğer kullanıcı mesaj göndermemişse, otomatik "özetle" prompt'u.
        // İlk özetleme isteğinde 3 öneri soru da istiyoruz (delimiter ile parse edilecek).
        $ask_suggestions = ( '' === $message );

        if ( '' === $message ) {
            $message = sprintf(
                'Şu yazıyı kısaca özetler misin?\n\nBaşlık: %s\nURL: %s\n\nİçerik:\n%s',
                $source['title'],
                $source['url'],
                $source['content']
            );
            // İlk user mesajı olarak gönderilecek; ama sistem prompt'una "özetle" diyeceğiz.
            $system_suffix = "\n\nŞu anki görevin: kullanıcının verdiği makaleyi özetlemek ve üzerine soruları cevaplamak.";
            $messages = [
                [ 'role' => 'system', 'content' => $opts['system_prompt'] . $system_suffix ],
                [ 'role' => 'user', 'content' => $message ],
            ];
        } else {
            // Konuşma devam ediyor.
            $messages   = $history;
            $messages[] = [ 'role' => 'user', 'content' => $message ];
            array_unshift( $messages, [
                'role'    => 'system',
                'content' => $opts['system_prompt'] . "\n\nŞu anki görevin: kullanıcının okuduğu makaleyi özetlemek ve üzerine soruları cevaplamak.\n\nMakale bilgisi:\nBaşlık: {$source['title']}\nURL: {$source['url']}\nİçerik (özetli):\n{$source['excerpt']}\n\n(İçeriğin tamamını zaten biliyorsun, gerekirse kullan.)",
            ] );
        }

        // Özet modunda cevabın sonuna 3 öneri soru istiyoruz — TEK API çağrısı.
        if ( $ask_suggestions ) {
            $system_msg = &$messages[0]; // by-ref
            $system_msg['content'] .= "\n\nCevabının EN SONUNA, kullanıcının bu yazıyla ilgili sorabileceği 3 kısa ve ilginç Türkçe soruyu ŞU DELIMITER FORMATI'nda ekle:\n"
                . "<<Q1>>Buraya birinci soru<<Q2>>Buraya ikinci soru<<Q3>>Buraya üçüncü soru<<ENDQ>>\n"
                . "Her soru 4-7 kelime, makaleyle doğrudan ilgili, farklı açılardan olsun (içerik, bağlam, uygulama).";
        }

        $ai_resp = \SmartAssistant\Plugin::instance()->ai_client->chat( $messages, [
            'sources' => [ $source ],
            'mode'    => 'simple',
        ] );

        if ( is_wp_error( $ai_resp ) ) {
            return $this->error_response( $ai_resp );
        }

        // Response'tan öneri soruları delimiter ile ayıkla.
        $content     = $ai_resp['content'];
        $suggestions = [];

        if ( $ask_suggestions && preg_match( '/<<Q1>>(.*?)<<Q2>>(.*?)<<Q3>>(.*?)<<ENDQ>>/su', $content, $m ) ) {
            $suggestions = [ trim( $m[1] ), trim( $m[2] ), trim( $m[3] ) ];
            $suggestions = array_values( array_filter( $suggestions, function ( $q ) {
                return '' !== $q && mb_strlen( $q ) >= 3;
            } ) );
            // Delimiter bloğunu kullanıcıya göstereceğimiz metinden çıkar.
            $content = preg_replace( '/<<Q1>>.*?<<ENDQ>>/su', '', $content );
            $content = trim( $content );
        }

        return rest_ensure_response( [
            'reply'       => $content,
            'sources'     => [ $source ],
            'model'       => $ai_resp['model'],
            'post_id'     => $post_id,
            'suggestions' => $suggestions,
        ] );
    }

    /**
     * Ön kontroller: nonce + rate limit.
     *
     * Nonce varsayılan olarak ZORUNLUDUR; geçersiz/eksik nonce reddedilir. Bu,
     * maliyetli LLM çağrılarının çapraz-köken script'lerle kötüye kullanılmasını
     * engeller. Tam-sayfa cache/CDN arkasında nonce eskiyebileceği için, site
     * sahipleri `smart_assistant_enforce_nonce` filter'ı ile zorunluluğu
     * kapatabilir (bu durumda rate limit tek koruma olur).
     */
    private function preflight( \WP_REST_Request $request ) {
        $ip      = $this->get_client_ip();
        $enforce = (bool) apply_filters( 'smart_assistant_enforce_nonce', true );
        $nonce   = $this->extract_nonce( $request );
        $valid   = '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );

        if ( ! $valid ) {
            smart_assistant_log(
                'Geçersiz/eksik nonce (IP: ' . $ip . ', enforce: ' . ( $enforce ? 'on' : 'off' ) . ')',
                'warning'
            );
            if ( $enforce ) {
                return new \WP_Error(
                    'invalid_nonce',
                    __( 'Oturum doğrulaması başarısız. Lütfen sayfayı yenileyip tekrar deneyin.', 'smart-assistant' ),
                    [ 'status' => 403 ]
                );
            }
        }

        if ( ! $this->check_rate_limit( $ip ) ) {
            return new \WP_Error(
                'rate_limited',
                __( 'Çok fazla istek. Lütfen biraz bekleyin.', 'smart-assistant' ),
                [ 'status' => 429 ]
            );
        }

        return true;
    }

    /**
     * Nonce'ı birden çok kaynaktan al (parametre, header, X-WP-Nonce).
     */
    private function extract_nonce( \WP_REST_Request $request ) {
        // 1. Body/param.
        $nonce = $request->get_param( 'nonce' );
        if ( is_string( $nonce ) && '' !== $nonce ) {
            return $nonce;
        }
        // 2. X-WP-Nonce header (WP standardı).
        $hdr = $request->get_header( 'x_wp_nonce' );
        if ( is_string( $hdr ) && '' !== $hdr ) {
            return $hdr;
        }
        // 3. Authorization: "Nonce <değer>" şeması.
        $auth = $request->get_header( 'authorization' );
        if ( is_string( $auth ) && 0 === stripos( $auth, 'nonce ' ) ) {
            return trim( substr( $auth, 6 ) );
        }
        return '';
    }

    private function check_rate_limit( $ip ) {
        $opts   = smart_assistant_get_options();
        $key    = smart_assistant_rate_limit_key( $ip );
        $window = 60;
        $max    = (int) $opts['rate_limit_per_min'];

        // Kalıcı object cache varsa: atomik increment ile race condition'ı önle.
        // Aksi halde transient tabanlı sayaç fallback'i (tek sunucuda yeterli).
        if ( wp_using_ext_object_cache() ) {
            $group = 'smart_assistant_rl';
            // add() yalnızca anahtar yoksa yazar; TTL'i tek noktada belirler.
            wp_cache_add( $key, 0, $group, $window );
            $count = wp_cache_incr( $key, 1, $group );
            if ( false === $count ) {
                // incr başarısızsa (nadiren) engelleme, isteği geçir.
                return true;
            }
            return $count <= $max;
        }

        $now  = time();
        $data = get_transient( $key );
        if ( false === $data || ! is_array( $data ) || $now > ( $data['reset_at'] ?? 0 ) ) {
            $data = [ 'count' => 0, 'reset_at' => $now + $window ];
        }

        $data['count']++;
        set_transient( $key, $data, $window );

        return $data['count'] <= $max;
    }

    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Not: production'da trusted proxy kontrolü ekle.
        return sanitize_text_field( $ip );
    }

    /**
     * Mesaj başına izin verilen maksimum karakter (token/maliyet istismarını sınırlar).
     */
    const MAX_MESSAGE_CHARS = 4000;

    private function normalize_history( $history ) {
        $out = [];
        if ( ! is_array( $history ) ) {
            return $out;
        }
        // Son 10 mesajla sınırla.
        $history = array_slice( $history, -10 );
        foreach ( $history as $m ) {
            if ( ! is_array( $m ) || empty( $m['role'] ) || empty( $m['content'] ) ) {
                continue;
            }
            // GÜVENLİK: İstemci ASLA 'system' rolü gönderemez. Aksi halde sunucunun
            // kimlik/kural prompt'unu ezip endpoint'i serbest bir LLM proxy'sine
            // çevirebilir (bkz. AIClient::chat — messages[0] system ise default eklenmez).
            $role = in_array( $m['role'], [ 'user', 'assistant' ], true ) ? $m['role'] : 'user';
            $out[] = [
                'role'    => $role,
                'content' => mb_substr( sanitize_textarea_field( $m['content'] ), 0, self::MAX_MESSAGE_CHARS ),
            ];
        }
        return $out;
    }

    private function error_response( \WP_Error $err ) {
        return new \WP_REST_Response(
            [
                'code'    => $err->get_error_code(),
                'message' => $err->get_error_message(),
            ],
            400
        );
    }
}