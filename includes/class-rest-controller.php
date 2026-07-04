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

        // Admin sohbet testi — gerçek sohbet akışını admin panelinden dener.
        register_rest_route( self::NAMESPACE_V1, '/admin-chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_admin_chat' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
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
            ],
        ] );

        // Open Notebook bağlantı testi — CF Access token'larıyla birlikte.
        register_rest_route( self::NAMESPACE_V1, '/on-test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_on_test' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        // DEBUG: DB'deki smart_assistant_options snapshot'ını döndür.
        register_rest_route( self::NAMESPACE_V1, '/options-snapshot', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_options_snapshot' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        // DEBUG: Form datasını alıp sanitize sonucunu döndür (DB'ye yazmadan).
        register_rest_route( self::NAMESPACE_V1, '/dry-save', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_dry_save' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        // REST üzerinden doğrudan save — options.php'yi bypass eder.
        register_rest_route( self::NAMESPACE_V1, '/save-options', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_save_options' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        // Veritabanı tanılama ve onarım — option'ı sıfırdan yazar.
        register_rest_route( self::NAMESPACE_V1, '/repair-options', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_repair_options' ],
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
     * /admin-chat endpoint'i — admin panelinden Mod 1/Mod 2 gerçek sohbet akışını dener.
     *
     * Frontend public /chat ile aynı kod yolunu kullanır; tek fark: permission admin-only'dir,
     * rate limit ve nonce kontrolü uygulanmaz (admin zaten giriş yapmıştır).
     */
    public function handle_admin_chat( \WP_REST_Request $request ) {
        $opts    = smart_assistant_get_options();
        $message = sanitize_text_field( $request->get_param( 'message' ) );
        $history = $this->normalize_history( $request->get_param( 'history' ) );
        $post_id = absint( $request->get_param( 'post_id' ) );

        if ( '' === $message ) {
            return $this->error_response( new \WP_Error( 'empty_message', __( 'Mesaj boş olamaz.', 'smart-assistant' ) ) );
        }

        $start = microtime( true );

        // Mod 2: ON üzerinden.
        if ( 'open_notebook' === $opts['mode'] ) {
            $on_resp = \SmartAssistant\Plugin::instance()->open_notebook->ask( $message, $post_id );
            if ( is_wp_error( $on_resp ) ) {
                return new \WP_REST_Response(
                    [
                        'code'    => $on_resp->get_error_code(),
                        'message' => $on_resp->get_error_message(),
                        'data'    => $on_resp->get_error_data(),
                    ],
                    502
                );
            }
            $elapsed = round( ( microtime( true ) - $start ) * 1000 );
            return rest_ensure_response( [
                'reply'      => $on_resp['content'],
                'sources'    => $on_resp['sources'],
                'model'      => 'open_notebook',
                'elapsed_ms' => $elapsed,
            ] );
        }

        // Mod 1: WP search + LLM.
        $sources = \SmartAssistant\Plugin::instance()->search->search( $message, $post_id );

        $messages   = $history;
        $messages[] = [ 'role' => 'user', 'content' => $message ];

        $ai_resp = \SmartAssistant\Plugin::instance()->ai_client->chat( $messages, [
            'sources' => $sources,
            'mode'    => 'simple',
        ] );

        if ( is_wp_error( $ai_resp ) ) {
            return new \WP_REST_Response(
                [
                    'code'    => $ai_resp->get_error_code(),
                    'message' => $ai_resp->get_error_message(),
                ],
                502
            );
        }

        $elapsed = round( ( microtime( true ) - $start ) * 1000 );

        return rest_ensure_response( [
            'reply'      => $ai_resp['content'],
            'sources'    => $sources,
            'model'      => $ai_resp['model'],
            'usage'      => $ai_resp['usage'],
            'elapsed_ms' => $elapsed,
        ] );
    }

    /**
     * /on-test endpoint'i — Open Notebook'e bağlantıyı ve CF Access header'larını doğrular.
     *
     * Notebook listesini çekmeyi dener; HTTP kodu, latency, dönen notebook sayısı ve hata varsa
     * detayını döner. Admin yetkisi zorunlu.
     */
    public function handle_on_test( \WP_REST_Request $request ) {
        $opts = smart_assistant_get_options();

        if ( empty( $opts['open_notebook_url'] ) ) {
            return new \WP_REST_Response( [
                'ok'    => false,
                'error' => [
                    'code'    => 'on_no_url',
                    'message' => __( 'Open Notebook URL ayarlanmamış.', 'smart-assistant' ),
                ],
            ], 400 );
        }

        $start   = microtime( true );
        $notebooks = \SmartAssistant\Plugin::instance()->open_notebook->list_notebooks();
        $elapsed = round( ( microtime( true ) - $start ) * 1000 );

        $has_cf = ! empty( $opts['on_cf_client_id'] ) && ! empty( $opts['on_cf_client_secret'] );

        // CF Access Service Token format doğrulaması (sadece bilgilendirme; bloke etmiyoruz).
        // ID formatı: 32 hex + ".access" suffix. Secret: 64 hex.
        $cf_id_format_ok     = false;
        $cf_secret_format_ok = false;
        $cf_id_masked        = '';
        $cf_secret_masked    = '';

        if ( $has_cf ) {
            $cf_id     = trim( $opts['on_cf_client_id'] );
            $cf_secret = trim( $opts['on_cf_client_secret'] );

            // CF-Access-Client-Id: 32 hex karakter + ".access".
            $cf_id_format_ok = (bool) preg_match( '/^[0-9a-f]{32}\.access$/', $cf_id );
            $cf_id_masked    = ( strlen( $cf_id ) > 12 )
                ? substr( $cf_id, 0, 4 ) . str_repeat( '•', 12 ) . substr( $cf_id, -8 )
                : str_repeat( '•', strlen( $cf_id ) );

            // CF-Access-Client-Secret: 64 hex karakter.
            $cf_secret_format_ok = (bool) preg_match( '/^[0-9a-f]{64}$/', $cf_secret );
            $cf_secret_masked    = ( strlen( $cf_secret ) > 12 )
                ? substr( $cf_secret, 0, 4 ) . str_repeat( '•', 12 ) . substr( $cf_secret, -8 )
                : str_repeat( '•', strlen( $cf_secret ) );
        }

        $debug = [
            'on_url'                  => rtrim( $opts['open_notebook_url'], '/' ),
            'cf_access_configured'    => $has_cf,
            'cf_id_format_ok'         => $cf_id_format_ok,
            'cf_id_present_masked'    => $cf_id_masked,
            'cf_secret_format_ok'     => $cf_secret_format_ok,
            'cf_secret_present_masked'=> $cf_secret_masked,
            'elapsed_ms'              => $elapsed,
        ];

        if ( is_wp_error( $notebooks ) ) {
            return rest_ensure_response( [
                'ok'    => false,
                'error' => [
                    'code'    => $notebooks->get_error_code(),
                    'message' => $notebooks->get_error_message(),
                ],
                'debug' => $debug,
            ] );
        }

        // Format uyarısı: bağlantı çalışsa bile hatalı format kullanıcıya bildirilir.
        $format_warnings = [];
        if ( $has_cf ) {
            if ( ! $cf_id_format_ok ) {
                $format_warnings[] = sprintf(
                    /* translators: %s: actual format hint */
                    __( 'CF Access Client ID beklenen formatta değil (32 hex karakter + ".access" son eki, örn. %s). Kopyaladığınız değerden ".access" kısmını atlamamış olun.', 'smart-assistant' ),
                    '<code>abcdef…32hex.access</code>'
                );
            }
            if ( ! $cf_secret_format_ok ) {
                $format_warnings[] = sprintf(
                    /* translators: %s: actual format hint */
                    __( 'CF Access Client Secret beklenen formatta değil (64 hex karakter, örn. %s).', 'smart-assistant' ),
                    '<code>abcdef0123…64hex</code>'
                );
            }
        }
        if ( ! empty( $format_warnings ) ) {
            $debug['format_warnings'] = $format_warnings;
        }

        $count = is_array( $notebooks ) ? count( $notebooks ) : 0;
        $debug['notebook_count'] = $count;

        // İlk notebook'un yapısını göstermek, hata ayıklamada faydalı olur.
        if ( $count > 0 ) {
            $debug['sample'] = array_intersect_key(
                (array) $notebooks[0],
                array_flip( [ 'id', 'name', 'title', 'created' ] )
            );
        }

        return rest_ensure_response( [
            'ok'        => true,
            'notebooks' => $notebooks,
            'count'     => $count,
            'debug'     => $debug,
        ] );
    }

    /**
     * /options-snapshot endpoint'i — DB'deki smart_assistant_options'un ham snapshot'ını döner.
     *
     * Hassas alanlar (api_key, on_cf_client_*, group_id) maskelenir. Sadece admin yetkisi gerekir.
     * Save sonrası UI ile DB arasında fark olup olmadığını görmek için debug amaçlı.
     */
    public function handle_options_snapshot( \WP_REST_Request $request ) {
        $raw  = get_option( 'smart_assistant_options', [] );
        $opts = smart_assistant_get_options();

        // Hassas alanları maskele.
        $mask = function ( $v ) {
            if ( ! is_string( $v ) || '' === $v ) return $v;
            $len = strlen( $v );
            if ( $len <= 8 ) return str_repeat( '•', $len );
            return substr( $v, 0, 4 ) . str_repeat( '•', 8 ) . substr( $v, -4 ) . ' (len=' . $len . ')';
        };

        $keys_to_mask = [ 'api_key', 'group_id', 'on_cf_client_id', 'on_cf_client_secret' ];

        $snapshot = [
            'is_array'     => is_array( $raw ),
            'raw_empty'    => empty( $raw ),
            'raw_keys'     => is_array( $raw ) ? array_keys( $raw ) : null,
            'cooked_keys'  => is_array( $opts ) ? array_keys( $opts ) : null,
            'raw_version'  => is_array( $raw ) ? ( $raw['mode'] ?? 'N/A' ) : null,
            'cooked_mode'  => $opts['mode'] ?? 'N/A',
            'cooked_tone'  => $opts['ai_tone'] ?? 'N/A',
            'tools_count'  => isset( $opts['tools'] ) && is_array( $opts['tools'] ) ? count( $opts['tools'] ) : 0,
            'tool_keys'    => isset( $opts['tools'] ) && is_array( $opts['tools'] )
                ? array_values( array_filter( array_map( fn( $t ) => is_array( $t ) ? ( $t['key'] ?? '' ) : '', $opts['tools'] ) ) )
                : [],
            'cf'           => [
                'url_set'        => ! empty( $opts['open_notebook_url'] ),
                'notebook_id_set'=> ! empty( $opts['open_notebook_notebook_id'] ),
                'client_id_len'  => strlen( $opts['on_cf_client_id']     ?? '' ),
                'secret_len'     => strlen( $opts['on_cf_client_secret'] ?? '' ),
            ],
            'masked'       => array_intersect_key( $opts, array_flip( $keys_to_mask ) ),
        ];

        // Maskelenmiş hali de hazırla.
        foreach ( $keys_to_mask as $k ) {
            if ( isset( $snapshot['masked'][ $k ] ) ) {
                $snapshot['masked'][ $k ] = $mask( $snapshot['masked'][ $k ] );
            }
        }

        return rest_ensure_response( $snapshot );
    }

    /**
     * /dry-save endpoint'i — form datasını alır, sanitize eder, sonucu DB'ye yazmadan döndürür.
     *
     * "Ayarları kaydet" butonu gerçekten çalışıyor mu sorusunun cevabını doğrudan verir.
     * Hassas alanlar maskelenir.
     */
    public function handle_dry_save( \WP_REST_Request $request ) {
        $input = $request->get_json_params();
        if ( empty( $input ) ) {
            // JSON değilse form-encoded olabilir.
            $input = $request->get_body_params();
        }

        $mask = function ( $v ) {
            if ( ! is_string( $v ) || '' === $v ) return $v;
            $len = strlen( $v );
            return $len <= 8 ? str_repeat( '•', $len ) : substr( $v, 0, 4 ) . str_repeat( '•', 8 ) . substr( $v, -4 ) . ' (len=' . $len . ')';
        };

        $received_keys = is_array( $input ) ? array_keys( $input ) : [];
        $received_summary = [
            'field_count'      => count( $received_keys ),
            'mode'             => $input['mode'] ?? null,
            'ai_tone'          => $input['ai_tone'] ?? null,
            'tools_submitted'  => ! empty( $input['tools_submitted'] ),
            'tools_row_count'  => is_array( $input['tools'] ?? null ) ? count( $input['tools'] ) : 0,
            'cf_id_len'        => strlen( $input['on_cf_client_id']     ?? '' ),
            'cf_secret_len'    => strlen( $input['on_cf_client_secret'] ?? '' ),
            'sample_keys'      => array_slice( $received_keys, 0, 15 ),
        ];

        if ( ! is_array( $input ) || empty( $input ) ) {
            return new \WP_REST_Response( [
                'ok'             => false,
                'received'       => $received_summary,
                'sanitized_keys' => [],
                'sanitized_preview' => [],
                'error'          => 'Boş veya hatalı input.',
            ], 400 );
        }

        // Sanitize çalıştır.
        $settings = \SmartAssistant\Plugin::instance()->settings;
        $sanitized = $settings->sanitize( $input );

        $keys_to_mask = [ 'api_key', 'group_id', 'on_cf_client_id', 'on_cf_client_secret' ];

        // Dönen değerin önizlemesi (hassas maskelenmiş).
        $preview = [];
        foreach ( $sanitized as $k => $v ) {
            $row = [ 'type' => gettype( $v ) ];
            if ( is_string( $v ) ) {
                $row['len']   = strlen( $v );
                $row['value'] = in_array( $k, $keys_to_mask, true ) ? $mask( $v ) : ( strlen( $v ) > 80 ? substr( $v, 0, 80 ) . '…' : $v );
            } elseif ( is_array( $v ) ) {
                $row['count'] = count( $v );
                if ( isset( $v[0]['key'] ) ) {
                    $row['keys'] = array_map( fn( $t ) => $t['key'] ?? '?', $v );
                } elseif ( 'tools' === $k ) {
                    $row['keys'] = array_map( fn( $t ) => is_array( $t ) ? ( $t['key'] ?? '' ) : '', $v );
                } else {
                    $row['values'] = array_map( fn( $x ) => is_scalar( $x ) ? (string) $x : '[…]', $v );
                }
            } elseif ( is_bool( $v ) ) {
                $row['bool'] = $v;
            } elseif ( is_numeric( $v ) ) {
                $row['value'] = $v;
            } else {
                $row['value'] = is_scalar( $v ) ? (string) $v : '[object]';
            }
            $preview[ $k ] = $row;
        }

        return rest_ensure_response( [
            'ok'                  => true,
            'received'            => $received_summary,
            'sanitized_keys'      => array_keys( $sanitized ),
            'sanitized_preview'   => $preview,
            // DB yazma yok — sadece döndür.
            'persisted'           => false,
        ] );
    }

    /**
     * /save-options endpoint'i — sanitize + update_option doğrudan REST üzerinden.
     *
     * Bu, options.php akışı herhangi bir nedenle kapalıysa veya sanitize çalışmıyor
     * izlenimi varsa bypass yolu olarak kullanılır. JS tarafı form submit'ini yakalayıp
     * bu endpoint'e POST'lar.
     */
    public function handle_save_options( \WP_REST_Request $request ) {
        $input = $request->get_json_params();
        if ( empty( $input ) ) {
            $input = $request->get_body_params();
        }

        if ( ! is_array( $input ) || empty( $input ) ) {
            return new \WP_REST_Response( [
                'ok'    => false,
                'error' => 'Boş veya hatalı input.',
            ], 400 );
        }

        $settings = \SmartAssistant\Plugin::instance()->settings;
        $sanitized = $settings->sanitize( $input );

        if ( ! is_array( $sanitized ) ) {
            return new \WP_REST_Response( [
                'ok'    => false,
                'error' => 'Sanitize başarısız oldu, array dönmedi.',
            ], 500 );
        }

        $result = update_option( 'smart_assistant_options', $sanitized );

        if ( ! $result ) {
            // update_option false dönerse: option mevcut değilse add etmeyi de dene.
            if ( false === get_option( 'smart_assistant_options' ) ) {
                $result = add_option( 'smart_assistant_options', $sanitized );
            }
        }

        // Yazma sonrası gerçekten DB'de olan:
        $after = get_option( 'smart_assistant_options', [] );

        return rest_ensure_response( [
            'ok'             => (bool) $result,
            'persisted'      => (bool) $result,
            'keys_after'     => is_array( $after ) ? array_keys( $after ) : null,
            'mode_after'     => is_array( $after ) ? ( $after['mode'] ?? null ) : null,
            'tools_after'    => is_array( $after ) && isset( $after['tools'] ) && is_array( $after['tools'] )
                ? count( $after['tools'] )
                : null,
            'cf_id_len'      => is_array( $after ) ? strlen( $after['on_cf_client_id']     ?? '' ) : null,
            'cf_secret_len'  => is_array( $after ) ? strlen( $after['on_cf_client_secret'] ?? '' ) : null,
        ] );
    }
    /**
     * /repair-options endpoint'i — veritabanı tanılama ve onarım.
     *
     * 1) Option'ın DB'de var olup olmadığını kontrol eder.
     * 2) Serializasyon bütünlüğünü doğrular.
     * 3) Ham DB yazma testi yapar ($wpdb direkt).
     * 4) İstenirse option'ı siler ve varsayılanlarla yeniden oluşturur.
     *
     * Tüm işlemler WP object cache'ini bypass eder.
     */
    public function handle_repair_options( \WP_REST_Request $request ) {
        global $wpdb;

        $action = sanitize_key( $request->get_param( 'action' ) ?? 'diagnose' );
        $report = [
            'action'    => $action,
            'steps'     => [],
            'ok'        => false,
            'db_error'  => null,
        ];

        // Adım 1: Option DB'de var mı?
        $table = $wpdb->options;
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_id, LENGTH(option_value) AS val_length, autoload FROM {$table} WHERE option_name = %s",
                'smart_assistant_options'
            )
        );

        if ( $row ) {
            $report['steps'][] = [
                'step'       => 'option_exists',
                'ok'         => true,
                'option_id'  => (int) $row->option_id,
                'val_length' => (int) $row->val_length,
                'autoload'   => $row->autoload,
            ];
        } else {
            $report['steps'][] = [
                'step' => 'option_exists',
                'ok'   => false,
                'msg'  => 'Option DB\'de bulunamadı.',
            ];
        }

        // Adım 2: Serializasyon bütünlüğü kontrolü.
        if ( $row ) {
            $raw_val = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$table} WHERE option_name = %s",
                    'smart_assistant_options'
                )
            );
            $unserialized = maybe_unserialize( $raw_val );
            $is_valid     = is_array( $unserialized );
            $report['steps'][] = [
                'step'            => 'serialization_check',
                'ok'              => $is_valid,
                'raw_starts_with' => substr( $raw_val ?? '', 0, 30 ),
                'unserialized_type' => gettype( $unserialized ),
                'key_count'       => $is_valid ? count( $unserialized ) : 0,
                'msg'             => $is_valid ? 'Serializasyon geçerli.' : 'BOZUK! Unserialize array döndürmedi.',
            ];
        }

        // Adım 3: WP update_option testi (küçük test değeri yaz, sonra geri al).
        $test_key = 'smart_assistant_write_test';
        $test_val = [ '_test' => wp_generate_password( 8, false ), '_time' => time() ];
        $write_ok = update_option( $test_key, $test_val );
        $read_back = get_option( $test_key );
        $read_ok   = is_array( $read_back ) && ( $read_back['_test'] ?? '' ) === $test_val['_test'];
        delete_option( $test_key );

        $report['steps'][] = [
            'step'     => 'write_test',
            'ok'       => $write_ok && $read_ok,
            'write_ok' => $write_ok,
            'read_ok'  => $read_ok,
            'msg'      => ( $write_ok && $read_ok )
                ? 'DB yazma/okuma çalışıyor.'
                : 'DB yazma/okuma BAŞARISIZ! last_error: ' . ( $wpdb->last_error ?: 'yok' ),
        ];

        // Adım 4: Asıl option'a doğrudan yazma testi.
        if ( $row ) {
            wp_cache_delete( 'smart_assistant_options', 'options' );
            $current = get_option( 'smart_assistant_options', [] );
            // Aynı değeri tekrar yazarak update_option davranışını test et.
            $direct_write = $wpdb->update(
                $table,
                [ 'option_value' => maybe_serialize( $current ) ],
                [ 'option_name' => 'smart_assistant_options' ],
                [ '%s' ],
                [ '%s' ]
            );
            $report['steps'][] = [
                'step'     => 'direct_write_test',
                'ok'       => false !== $direct_write,
                'affected' => $direct_write,
                'db_error' => $wpdb->last_error ?: null,
                'msg'      => false !== $direct_write
                    ? 'Doğrudan DB yazma başarılı.'
                    : 'Doğrudan DB yazma BAŞARISIZ: ' . $wpdb->last_error,
            ];
        }

        // === ONARIM AKSIYONLARI ===
        if ( 'reset' === $action ) {
            // Option'ı sil ve varsayılanlarla yeniden oluştur.
            $deleted = delete_option( 'smart_assistant_options' );
            wp_cache_delete( 'smart_assistant_options', 'options' );
            wp_cache_delete( 'alloptions', 'options' );

            // Varsayılan değerlerle yeniden oluştur (smart_assistant_get_options defaults).
            $defaults = [
                'mode'              => 'simple',
                'provider'          => 'MiniMax',
                'api_key'           => '',
                'group_id'          => '',
                'api_base_url'      => 'https://api.minimax.io/v1',
                'model'             => 'MiniMax-M3',
                'system_prompt'     => '',
                'temperature'       => 0.2,
                'max_tokens'        => 800,
                'post_types'        => [ 'post', 'page' ],
                'max_results'       => 5,
                'max_content_chars' => 6000,
                'rate_limit_per_min' => 20,
                'open_notebook_url' => '',
                'open_notebook_notebook_id' => '',
                'on_strategy_model'   => '',
                'on_answer_model'     => '',
                'on_final_answer_model' => '',
                'on_cf_client_id'     => '',
                'on_cf_client_secret' => '',
                'enable_abilities'  => true,
                'ai_name'         => '',
                'ai_greeting'     => '',
                'ai_tone'         => 'friendly',
                'ai_examples'     => '',
                'show_signature'  => false,
            ];

            $added = add_option( 'smart_assistant_options', $defaults, '', 'yes' );
            wp_cache_delete( 'smart_assistant_options', 'options' );
            wp_cache_delete( 'alloptions', 'options' );

            // Doğrulama: gerçekten yazıldı mı?
            $verify = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT LENGTH(option_value) FROM {$table} WHERE option_name = %s",
                    'smart_assistant_options'
                )
            );

            $report['steps'][] = [
                'step'        => 'reset',
                'deleted'     => $deleted,
                'added'       => $added,
                'verify_len'  => (int) $verify,
                'db_error'    => $wpdb->last_error ?: null,
                'ok'          => $added && $verify > 0,
                'msg'         => ( $added && $verify > 0 )
                    ? 'Option sıfırlandı ve varsayılanlarla yeniden oluşturuldu.'
                    : 'Sıfırlama BAŞARISIZ: ' . ( $wpdb->last_error ?: 'Bilinmeyen hata' ),
            ];
        } elseif ( 'force_save' === $action ) {
            // Mevcut form datasını al ve doğrudan $wpdb ile yaz (tüm WP katmanlarını bypass).
            $input = $request->get_json_params();
            if ( is_array( $input ) && ! empty( $input ) ) {
                // 'action' key'ini formdan kaldır.
                unset( $input['action'] );

                $settings  = \SmartAssistant\Plugin::instance()->settings;
                $sanitized = $settings->sanitize( $input );

                if ( is_array( $sanitized ) && ! empty( $sanitized ) ) {
                    $serialized = maybe_serialize( $sanitized );

                    // Option yoksa INSERT, varsa UPDATE.
                    if ( $row ) {
                        $result = $wpdb->update(
                            $table,
                            [ 'option_value' => $serialized ],
                            [ 'option_name' => 'smart_assistant_options' ],
                            [ '%s' ],
                            [ '%s' ]
                        );
                    } else {
                        $result = $wpdb->insert(
                            $table,
                            [
                                'option_name'  => 'smart_assistant_options',
                                'option_value' => $serialized,
                                'autoload'     => 'yes',
                            ],
                            [ '%s', '%s', '%s' ]
                        );
                    }

                    // Cache'i temizle.
                    wp_cache_delete( 'smart_assistant_options', 'options' );
                    wp_cache_delete( 'alloptions', 'options' );

                    $report['steps'][] = [
                        'step'        => 'force_save',
                        'ok'          => false !== $result,
                        'affected'    => $result,
                        'serial_len'  => strlen( $serialized ),
                        'db_error'    => $wpdb->last_error ?: null,
                        'mode_saved'  => $sanitized['mode'] ?? null,
                        'keys_saved'  => count( $sanitized ),
                        'msg'         => false !== $result
                            ? 'Doğrudan DB yazma (force_save) başarılı.'
                            : 'Force save BAŞARISIZ: ' . ( $wpdb->last_error ?: 'Bilinmeyen hata' ),
                    ];
                } else {
                    $report['steps'][] = [
                        'step' => 'force_save',
                        'ok'   => false,
                        'msg'  => 'Sanitize sonrası boş/geçersiz çıktı.',
                    ];
                }
            } else {
                $report['steps'][] = [
                    'step' => 'force_save',
                    'ok'   => false,
                    'msg'  => 'Form datası boş geldi.',
                ];
            }
        }

        // Genel sonuç.
        $all_ok = true;
        foreach ( $report['steps'] as $s ) {
            if ( ! ( $s['ok'] ?? false ) ) {
                $all_ok = false;
                break;
            }
        }
        $report['ok']       = $all_ok;
        $report['db_error'] = $wpdb->last_error ?: null;

        return rest_ensure_response( $report );
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
        $message = sanitize_text_field( $request->get_param( 'message' ) );
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
        $message = sanitize_text_field( $request->get_param( 'message' ) );
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
        // 3. Authorization: Bearer veya doğrudan.
        $auth = $request->get_header( 'authorization' );
        if ( is_string( $auth ) && 0 === stripos( $auth, 'nonce ' ) ) {
            return trim( substr( $auth, 6 ) );
        }
        return '';
    }

    private function check_rate_limit( $ip ) {
        $opts = smart_assistant_get_options();
        $key  = smart_assistant_rate_limit_key( $ip );
        $now  = time();
        $window = 60;
        $max  = (int) $opts['rate_limit_per_min'];

        $data = get_transient( $key );
        if ( false === $data ) {
            $data = [ 'count' => 0, 'reset_at' => $now + $window ];
        }
        if ( $now > $data['reset_at'] ) {
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
            $role = in_array( $m['role'], [ 'user', 'assistant', 'system' ], true ) ? $m['role'] : 'user';
            $out[] = [
                'role'    => $role,
                'content' => sanitize_textarea_field( $m['content'] ),
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