<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Open Notebook (Mod 2) istemcisi.
 *
 * ON API: https://opennotebook-api.hizliadisyo.com
 * Auth: kapalı (auth_enabled: false) — header'da token gerekmez.
 *
 * Endpoint'ler:
 *  - POST /api/search/ask/simple      → soru sor, cevap al
 *  - POST /api/sources                → source ekle (notebook'a bağla)
 *  - GET  /api/notebooks              → notebook'ları listele
 *  - GET  /api/models/defaults        → default model ID'leri
 */
class OpenNotebook {

    /**
     * ON'in varsayılan model ID'leri (defaults endpoint'inden alındı).
     * Override için ayarlardan değiştirilebilir.
     */
    const DEFAULT_STRATEGY_MODEL = 'model:n2vpcyvunhj0oq0bnfoc';   // MiniMax-M3
    const DEFAULT_ANSWER_MODEL   = 'model:n2vpcyvunhj0oq0bnfoc';   // MiniMax-M3
    const DEFAULT_FINAL_MODEL     = 'model:n2vpcyvunhj0oq0bnfoc';   // MiniMax-M3

    /**
     * Notebook'a soru sor, cevap + kaynak listesi al.
     *
     * @param string $query
     * @param int    $post_id
     * @return array|WP_Error    ['content' => ..., 'sources' => [...], 'model' => ...]
     */
    public function ask( $query, $post_id = 0 ) {
        $opts = $this->check_config();
        if ( is_wp_error( $opts ) ) {
            return $opts;
        }

        $base     = rtrim( $opts['open_notebook_url'], '/' );
        $endpoint = $base . '/api/search/ask/simple';

        $body = [
            'question'         => $query,
            'strategy_model'   => $opts['on_strategy_model']   ?: self::DEFAULT_STRATEGY_MODEL,
            'answer_model'     => $opts['on_answer_model']     ?: self::DEFAULT_ANSWER_MODEL,
            'final_answer_model'=> $opts['on_final_answer_model'] ?: self::DEFAULT_FINAL_MODEL,
        ];

        $response = wp_remote_post( $endpoint, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            smart_assistant_log( 'ON ask HTTP error: ' . $response->get_error_message(), 'error' );
            return new \WP_Error(
                'on_http_error',
                sprintf( __( 'Open Notebook\'e ulaşılamadı: %s', 'smart-assistant' ), $response->get_error_message() )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $this->extract_error( $data ) ?: sprintf( __( 'HTTP %d', 'smart-assistant' ), $code );
            smart_assistant_log( 'ON ask API error ' . $code . ': ' . $err, 'error' );
            return new \WP_Error( 'on_api_error', $err, [ 'status' => $code ] );
        }

        // Yanıt formatı ON versiyonuna göre değişebilir.
        $content = $data['answer']
                ?? $data['final_answer']
                ?? $data['response']
                ?? $data['content']
                ?? '';

        // Mod 2 (ON) yolu chat()'ten geçmediği için burada da temizle:
        // thinking etiketleri + bozuk HTML link kalıntıları.
        if ( $content !== '' ) {
            $ai_client = \SmartAssistant\Plugin::instance()->ai_client;
            if ( $ai_client && method_exists( $ai_client, 'strip_thinking' ) ) {
                $content = $ai_client->strip_thinking( $content );
            }
            if ( $ai_client && method_exists( $ai_client, 'strip_broken_links' ) ) {
                $content = $ai_client->strip_broken_links( $content );
            }
        }

        $raw_sources = $this->extract_sources( $data );

        // ON sources'ta URL yok; title'ı kullanarak WP post permalink'i bul.
        $enriched_sources = $this->enrich_sources_with_wp_urls( $raw_sources );

        return [
            'content' => (string) $content,
            'sources' => $enriched_sources,
            'model'   => $body['answer_model'],
        ];
    }

    /**
     * Bir post'u Open Notebook'e source olarak ekle (notebook'a bağlı).
     *
     * @param int $post_id
     * @return true|string|WP_Error   başarılıysa source ID döner
     */
    public function sync_post( $post_id ) {
        $opts = $this->check_config();
        if ( is_wp_error( $opts ) ) {
            return $opts;
        }

        if ( empty( $opts['open_notebook_notebook_id'] ) ) {
            return new \WP_Error(
                'on_no_notebook',
                __( 'Ayarlardan bir Notebook ID girilmemiş. Önce Open Notebook\'te bir notebook oluşturup ID\'sini ayarlara girin.', 'smart-assistant' )
            );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new \WP_Error( 'on_no_post', __( 'Yazı bulunamadı.', 'smart-assistant' ) );
        }

        $base     = rtrim( $opts['open_notebook_url'], '/' );
        $endpoint = $base . '/api/sources';

        $use_url = (bool) apply_filters( 'smart_assistant_on_use_url_source', true );

        if ( $use_url ) {
            $body = [
                'type'     => 'url',
                'url'      => get_permalink( $post ),
                'title'    => get_the_title( $post ),
                'notebooks'=> [ $opts['open_notebook_notebook_id'] ],
                'embed'    => true,
            ];
        } else {
            $content_clean = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
            $body = [
                'type'     => 'content',
                'title'    => get_the_title( $post ),
                'content'  => $content_clean,
                'notebooks'=> [ $opts['open_notebook_notebook_id'] ],
                'embed'    => true,
            ];
        }

        $response = wp_remote_post( $endpoint, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            smart_assistant_log( 'ON sync HTTP error: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $this->extract_error( $data ) ?: sprintf( __( 'HTTP %d', 'smart-assistant' ), $code );
            smart_assistant_log( 'ON sync API error ' . $code . ': ' . $err, 'error' );
            return new \WP_Error( 'on_sync_error', $err, [ 'status' => $code ] );
        }

        // Source ID → WP post ID map'ini kaydet (sonraki sorgularda hızlı permalink için).
        $source_id = $data['id'] ?? null;
        if ( $source_id ) {
            $this->save_source_mapping( $source_id, $post_id );
        }

        return $source_id ?: true;
    }

    /**
     * Notebook listesini getir.
     */
    public function list_notebooks() {
        $opts = $this->check_config();
        if ( is_wp_error( $opts ) ) {
            return $opts;
        }

        $endpoint = rtrim( $opts['open_notebook_url'], '/' ) . '/api/notebooks';
        $response = wp_remote_get( $endpoint, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error( 'on_list_error', sprintf( __( 'Notebook listesi alınamadı (HTTP %d)', 'smart-assistant' ), $code ) );
        }

        if ( isset( $data['notebooks'] ) && is_array( $data['notebooks'] ) ) {
            return $data['notebooks'];
        }
        if ( is_array( $data ) ) {
            return $data;
        }
        return [];
    }

    /**
     * ON source ID → WP post ID mapping'ini kaydet.
     */
    private function save_source_mapping( $source_id, $post_id ) {
        $map = get_transient( 'sa_on_source_map' );
        if ( ! is_array( $map ) ) {
            $map = [];
        }
        $map[ $source_id ] = $post_id;
        set_transient( 'sa_on_source_map', $map, DAY_IN_SECONDS );
    }

    /**
     * ON'den dönen sources'taki URL eksikse, title'a göre WP post bulup
     * permalink'i ekle. Bu sayede AI kaynak URL'lerini sallama yapmaz.
     */
    private function enrich_sources_with_wp_urls( $sources ) {
        if ( empty( $sources ) ) {
            return $sources;
        }

        $map       = get_transient( 'sa_on_source_map' );
        if ( ! is_array( $map ) ) {
            $map = [];
        }
        $home_url  = home_url();
        $post_types = get_post_types( [ 'public' => true ] );

        foreach ( $sources as &$s ) {
            // Zaten kendi domain'imizdeki bir URL varsa dokunma.
            if ( ! empty( $s['url'] ) && strpos( $s['url'], $home_url ) !== false ) {
                continue;
            }

            // Önce source_id map'ten bak.
            $sid = $s['id'] ?? '';
            if ( $sid && isset( $map[ $sid ] ) ) {
                $pid = (int) $map[ $sid ];
                if ( $pid && get_post( $pid ) ) {
                    $s['url']     = get_permalink( $pid );
                    $s['post_id'] = $pid;
                    continue;
                }
            }

            // Fallback: title ile WP post bul.
            if ( ! empty( $s['title'] ) ) {
                $post = get_page_by_title( $s['title'], OBJECT, $post_types );
                if ( $post ) {
                    $s['url']     = get_permalink( $post );
                    $s['post_id'] = $post->ID;
                    if ( $sid ) {
                        $map[ $sid ] = $post->ID;
                    }
                }
            }
        }
        unset( $s );

        // Yeni öğrenilenleri kaydet.
        if ( ! empty( $map ) ) {
            set_transient( 'sa_on_source_map', $map, DAY_IN_SECONDS );
        }

        return $sources;
    }

    /**
     * Konfigürasyon kontrolü.
     */
    private function check_config() {
        $opts = smart_assistant_get_options();

        if ( empty( $opts['open_notebook_url'] ) ) {
            return new \WP_Error(
                'on_no_url',
                __( 'Open Notebook URL\'si ayarlanmamış. Yönetici panelinden girin.', 'smart-assistant' )
            );
        }
        return $opts;
    }

    /**
     * ON hata mesajını çeşitli formatlardan çıkar.
     */
    private function extract_error( $data ) {
        if ( ! is_array( $data ) ) return null;
        if ( isset( $data['detail'] ) ) {
            if ( is_array( $data['detail'] ) && isset( $data['detail'][0]['msg'] ) ) {
                $msg = $data['detail'][0]['msg'];
                $loc = $data['detail'][0]['loc'] ?? [];
                if ( ! empty( $loc ) ) {
                    $msg .= ' (' . implode( '.', (array) $loc ) . ')';
                }
                return $msg;
            }
            if ( is_string( $data['detail'] ) ) {
                return $data['detail'];
            }
        }
        return $data['message'] ?? $data['error'] ?? null;
    }

    /**
     * ON yanıtındaki kaynak listesini normalize et.
     */
    private function extract_sources( $data ) {
        $out = [];
        $raw = $data['sources']
            ?? $data['context']
            ?? $data['citations']
            ?? [];

        if ( ! is_array( $raw ) ) return $out;

        foreach ( $raw as $s ) {
            if ( ! is_array( $s ) ) continue;
            $out[] = [
                'id'      => $s['id']    ?? '',
                'title'   => $s['title'] ?? ( $s['name'] ?? '' ),
                'url'     => $s['url']   ?? '',
                'excerpt' => $s['excerpt'] ?? ( $s['content'] ?? '' ),
            ];
        }
        return $out;
    }
}