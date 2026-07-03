<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin ayarları: WP Settings API ile.
 */
class Settings {

    public function register_hooks() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'activation_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_js' ] );
    }

    public function enqueue_admin_js( $hook ) {
        if ( 'settings_page_smart-assistant' !== $hook ) {
            return;
        }
        wp_enqueue_script(
            'smart-assistant-admin',
            SMART_ASSISTANT_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            SMART_ASSISTANT_VERSION,
            true
        );
        wp_localize_script( 'smart-assistant-admin', 'SmartAssistantPresets', smart_assistant_get_provider_presets() );

        // Test butonu için wp-api-fetch ve nonce.
        wp_enqueue_script( 'wp-api-fetch' );
        wp_add_inline_script( 'wp-api-fetch', sprintf(
            'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
            wp_json_encode( wp_create_nonce( 'wp_rest' ) )
        ) );
        wp_enqueue_script(
            'smart-assistant-test',
            SMART_ASSISTANT_URL . 'admin/js/test-button.js',
            [ 'jquery', 'wp-api-fetch' ],
            SMART_ASSISTANT_VERSION,
            true
        );
    }

    public function register_settings() {
        register_setting(
            'smart_assistant_settings_group',
            'smart_assistant_options',
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => smart_assistant_get_options(),
            ]
        );

        add_settings_section(
            'smart_assistant_mode',
            __( 'Çalışma Modu', 'smart-assistant' ),
            function () {
                echo '<p>' . esc_html__( 'Mod 1: WordPress\'in kendi araması + kendi LLM anahtarın. Mod 2: Open Notebook üzerinden semantik arama (ayrıca Open Notebook kurulu olmalı).', 'smart-assistant' ) . '</p>';
            },
            'smart-assistant'
        );

        $this->add_field( 'mode', __( 'Mod', 'smart-assistant' ), 'render_mode_field', 'smart_assistant_mode' );

        add_settings_section(
            'smart_assistant_general',
            __( 'Genel Ayarlar', 'smart-assistant' ),
            function () {
                echo '<p>' . esc_html__( 'Provider seçimi, API anahtarı ve model ayarları.', 'smart-assistant' ) . '</p>';
            },
            'smart-assistant'
        );

        $this->add_field( 'provider', __( 'AI Provider', 'smart-assistant' ), 'render_provider_field' );
        $this->add_field( 'api_key', __( 'API Anahtarı / Token', 'smart-assistant' ), 'render_api_key_field' );
        $this->add_field( 'group_id', __( 'Group ID (MiniMax Token Plan)', 'smart-assistant' ), 'render_group_id_field' );
        $this->add_field( 'api_base_url', __( 'API Base URL', 'smart-assistant' ), 'render_api_base_url_field' );
        $this->add_field( 'model', __( 'Model', 'smart-assistant' ), 'render_model_field' );
        $this->add_field( 'system_prompt', __( 'Sistem Prompt\'u', 'smart-assistant' ), 'render_system_prompt_field' );
        $this->add_field( 'temperature', __( 'Temperature', 'smart-assistant' ), 'render_temperature_field' );
        $this->add_field( 'max_tokens', __( 'Max Tokens', 'smart-assistant' ), 'render_max_tokens_field' );

        add_settings_section(
            'smart_assistant_content',
            __( 'İçerik Ayarları', 'smart-assistant' ),
            function () {
                echo '<p>' . esc_html__( 'Hangi post type\'lardan arama yapılacak ve kaç sonuç getirilecek.', 'smart-assistant' ) . '</p>';
            },
            'smart-assistant'
        );

        $this->add_field( 'post_types', __( 'İçerik Kaynakları (Post Type)', 'smart-assistant' ), 'render_post_types_field', 'smart_assistant_content' );
        $this->add_field( 'max_results', __( 'Maksimum Sonuç', 'smart-assistant' ), 'render_max_results_field', 'smart_assistant_content' );
        $this->add_field( 'max_content_chars', __( 'Yazı Başına Max Karakter', 'smart-assistant' ), 'render_max_content_chars_field', 'smart_assistant_content' );

        add_settings_section(
            'smart_assistant_advanced',
            __( 'Gelişmiş', 'smart-assistant' ),
            function () {
                echo '<p>' . esc_html__( 'Open Notebook entegrasyonu, rate limit, Abilities API.', 'smart-assistant' ) . '</p>';
            },
            'smart-assistant'
        );

        $this->add_field( 'open_notebook_url', __( 'Open Notebook URL (Mod 2)', 'smart-assistant' ), 'render_open_notebook_url_field', 'smart_assistant_advanced' );
        $this->add_field( 'open_notebook_notebook_id', __( 'Open Notebook Notebook ID', 'smart-assistant' ), 'render_open_notebook_notebook_id_field', 'smart_assistant_advanced' );
        $this->add_field( 'on_strategy_model', __( 'ON Strateji Modeli', 'smart-assistant' ), 'render_on_strategy_model_field', 'smart_assistant_advanced' );
        $this->add_field( 'on_answer_model', __( 'ON Cevap Modeli', 'smart-assistant' ), 'render_on_answer_model_field', 'smart_assistant_advanced' );
        $this->add_field( 'on_final_answer_model', __( 'ON Final Cevap Modeli', 'smart-assistant' ), 'render_on_final_answer_model_field', 'smart_assistant_advanced' );
        $this->add_field( 'rate_limit_per_min', __( 'Dakikada Max İstek', 'smart-assistant' ), 'render_rate_limit_field', 'smart_assistant_advanced' );
        $this->add_field( 'enable_abilities', __( 'Abilities API Aktif', 'smart-assistant' ), 'render_abilities_field', 'smart_assistant_advanced' );

        // AI Kimliği bölümü.
        add_settings_section(
            'smart_assistant_identity',
            __( 'AI Kimliği', 'smart-assistant' ),
            function () {
                echo '<p>' . esc_html__( 'Asistanın adı, tonu, selamlama mesajı ve few-shot örnekler. Bunlar system prompt\'a otomatik eklenir.', 'smart-assistant' ) . '</p>';
            },
            'smart-assistant'
        );
        $this->add_field( 'ai_name', __( 'AI Adı', 'smart-assistant' ), 'render_ai_name_field', 'smart_assistant_identity' );
        $this->add_field( 'ai_tone', __( 'Ton', 'smart-assistant' ), 'render_ai_tone_field', 'smart_assistant_identity' );
        $this->add_field( 'ai_greeting', __( 'Selamlama Mesajı', 'smart-assistant' ), 'render_ai_greeting_field', 'smart_assistant_identity' );
        $this->add_field( 'ai_examples', __( 'Few-shot Örnekler', 'smart-assistant' ), 'render_ai_examples_field', 'smart_assistant_identity' );
        $this->add_field( 'show_signature', __( 'Cevap Sonuna İmza', 'smart-assistant' ), 'render_show_signature_field', 'smart_assistant_identity' );

        // Görünüm & Davranış bölümü (Faz A).
        add_settings_section(
            'smart_assistant_appearance',
            __( 'Görünüm & Davranış', 'smart-assistant' ),
            function () {
                echo '<p>' . esc_html__( 'Widget rengi, konumu, ikonu ve davranış tercihleri.', 'smart-assistant' ) . '</p>';
            },
            'smart-assistant'
        );
        $this->add_field( 'appearance_color', __( 'Ana Renk', 'smart-assistant' ), 'render_appearance_color_field', 'smart_assistant_appearance' );
        $this->add_field( 'appearance_position', __( 'Widget Konumu', 'smart-assistant' ), 'render_appearance_position_field', 'smart_assistant_appearance' );
        $this->add_field( 'appearance_icon', __( 'Balon İkonu', 'smart-assistant' ), 'render_appearance_icon_field', 'smart_assistant_appearance' );
        $this->add_field( 'welcome_delay', __( 'Karşılama Balonu Gecikmesi', 'smart-assistant' ), 'render_welcome_delay_field', 'smart_assistant_appearance' );
        $this->add_field( 'persist_chat', __( 'Sohbeti Hatırla', 'smart-assistant' ), 'render_persist_chat_field', 'smart_assistant_appearance' );
        $this->add_field( 'enable_streaming', __( 'Akan Yanıt (Streaming)', 'smart-assistant' ), 'render_streaming_field', 'smart_assistant_appearance' );
        $this->add_field( 'enable_feedback', __( 'Geri Bildirim Butonları', 'smart-assistant' ), 'render_feedback_field', 'smart_assistant_appearance' );
    }

    /**
     * @param string $id       Alan anahtarı.
     * @param string $title    Etiket.
     * @param string $callback Render metodu.
     * @param string $section  Kayıt olunacak section (varsayılan: general).
     * @param array  $args     Ek argümanlar.
     */
    private function add_field( $id, $title, $callback, $section = 'smart_assistant_general', $args = [] ) {
        add_settings_field(
            'smart_assistant_' . $id,
            $title,
            [ $this, $callback ],
            'smart-assistant',
            $section,
            array_merge( [ 'key' => $id ], $args )
        );
    }

    public function sanitize( $input ) {
        $out = smart_assistant_get_options();

        $out['mode'] = in_array( $input['mode'] ?? 'simple', [ 'simple', 'open_notebook' ], true ) ? $input['mode'] : 'simple';

        // Provider whitelist.
        $presets       = smart_assistant_get_provider_presets();
        $provider_keys = array_keys( $presets );
        $out['provider'] = in_array( $input['provider'] ?? 'MiniMax', $provider_keys, true )
            ? $input['provider']
            : 'MiniMax';

        // API key: sadece gerçekten yeni değer girildiyse güncelle.
        $api_key_input = $input['api_key'] ?? '';
        if ( is_string( $api_key_input ) && '' !== $api_key_input && strpos( $api_key_input, '•' ) === false ) {
            $out['api_key'] = sanitize_text_field( $api_key_input );
        }

        $out['api_base_url']  = esc_url_raw( $input['api_base_url']  ?? '' );
        $out['group_id']      = sanitize_text_field( $input['group_id'] ?? '' );
        $out['model']         = sanitize_text_field( $input['model'] ?? $presets[ $out['provider'] ]['models'][0] );
        $out['system_prompt'] = wp_kses_post( $input['system_prompt'] ?? '' );
        $out['temperature']   = max( 0, min( 2, floatval( $input['temperature'] ?? 0.3 ) ) );
        $out['max_tokens']    = max( 50, min( 4000, intval( $input['max_tokens'] ?? 800 ) ) );

        $allowed_pt = array_keys( get_post_types( [ 'public' => true ] ) );
        $post_types = (array) ( $input['post_types'] ?? [ 'post' ] );
        $out['post_types']    = array_values( array_intersect( $post_types, $allowed_pt ) );
        if ( empty( $out['post_types'] ) ) {
            $out['post_types'] = [ 'post' ];
        }

        $out['max_results']        = max( 1, min( 20, intval( $input['max_results'] ?? 5 ) ) );
        $out['max_content_chars'] = max( 500, min( 50000, intval( $input['max_content_chars'] ?? 6000 ) ) );
        $out['open_notebook_url']  = esc_url_raw( $input['open_notebook_url']  ?? '' );
        $out['open_notebook_notebook_id'] = sanitize_text_field( $input['open_notebook_notebook_id'] ?? '' );
        $out['on_strategy_model']   = sanitize_text_field( $input['on_strategy_model'] ?? '' );
        $out['on_answer_model']     = sanitize_text_field( $input['on_answer_model'] ?? '' );
        $out['on_final_answer_model']= sanitize_text_field( $input['on_final_answer_model'] ?? '' );
        $out['rate_limit_per_min'] = max( 1, min( 200, intval( $input['rate_limit_per_min'] ?? 20 ) ) );
        $out['enable_abilities']   = ! empty( $input['enable_abilities'] );

        // Görünüm & davranış.
        $color = sanitize_hex_color( $input['appearance_color'] ?? '' );
        $out['appearance_color']    = $color ?: '';
        $out['appearance_position'] = in_array( $input['appearance_position'] ?? 'right', [ 'right', 'left' ], true )
            ? $input['appearance_position'] : 'right';
        $out['appearance_icon']     = mb_substr( sanitize_text_field( $input['appearance_icon'] ?? '💬' ), 0, 8 );
        if ( '' === $out['appearance_icon'] ) {
            $out['appearance_icon'] = '💬';
        }
        $out['welcome_delay']    = max( 0, min( 60, intval( $input['welcome_delay'] ?? 2 ) ) );
        $out['persist_chat']     = ! empty( $input['persist_chat'] );
        $out['enable_streaming'] = ! empty( $input['enable_streaming'] );
        $out['enable_feedback']  = ! empty( $input['enable_feedback'] );

        // AI Kimliği.
        $out['ai_name']        = sanitize_text_field( $input['ai_name'] ?? '' );
        $out['ai_tone']        = in_array( $input['ai_tone'] ?? 'friendly', [ 'friendly', 'professional', 'expert' ], true )
            ? $input['ai_tone'] : 'friendly';
        $out['ai_greeting']    = sanitize_textarea_field( $input['ai_greeting'] ?? '' );
        $out['ai_examples']    = sanitize_textarea_field( $input['ai_examples'] ?? '' );
        $out['show_signature'] = ! empty( $input['show_signature'] );

        // Testler (hesaplayıcılar):
        //  - tools_reset=1  → 'tools' anahtarını kaldır ki varsayılanlar geri yüklensin.
        //  - tools_submitted=1 → gönderilen listeyi (BOŞ bile olsa) kalıcı kaydet;
        //    böylece kullanıcı bir testi sildiğinde yenilemede geri gelmez.
        if ( ! empty( $input['tools_reset'] ) ) {
            unset( $out['tools'] );
        } elseif ( ! empty( $input['tools_submitted'] ) ) {
            $raw_tools       = is_array( $input['tools'] ?? null ) ? $input['tools'] : [];
            $sanitized_tools = [];
            $seen_keys       = [];
            foreach ( $raw_tools as $t ) {
                if ( ! is_array( $t ) ) {
                    continue;
                }
                $key   = sanitize_key( $t['key'] ?? '' );
                $label = sanitize_text_field( $t['label'] ?? '' );
                // Key boşsa başlıktan otomatik türet (kullanıcı yeni test eklerken
                // key girmeyi unutursa satır sessizce kaybolmasın).
                if ( '' === $key && '' !== $label ) {
                    $key = sanitize_key( sanitize_title( $label ) );
                }
                // Hâlâ boşsa ya da bu key daha önce kullanıldıysa atla (çakışma önlemi).
                if ( '' === $key || isset( $seen_keys[ $key ] ) ) {
                    continue;
                }
                $seen_keys[ $key ] = true;
                $sanitized_tools[] = [
                    'key'           => $key,
                    'label'         => $label,
                    'icon'          => sanitize_text_field( $t['icon'] ?? '🤖' ),
                    'description'   => sanitize_text_field( $t['description'] ?? '' ),
                    'welcome_msg'   => sanitize_textarea_field( $t['welcome_msg'] ?? '' ),
                    'system_prompt' => wp_kses_post( $t['system_prompt'] ?? '' ),
                ];
            }
            $out['tools'] = $sanitized_tools;
        }

        add_settings_error( 'smart_assistant_options', 'smart_assistant_saved', __( 'Ayarlar kaydedildi.', 'smart-assistant' ), 'updated' );
        return $out;
    }

    public function activation_notice() {
        if ( ! get_transient( 'smart_assistant_activated_notice' ) ) {
            return;
        }
        delete_transient( 'smart_assistant_activated_notice' );
        $url = admin_url( 'options-general.php?page=smart-assistant' );
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo wp_kses_post( sprintf(
            __( 'Smart Assistant aktif! <a href="%s">Ayarları aç</a> ve API anahtarınızı girin.', 'smart-assistant' ),
            esc_url( $url )
        ) );
        echo '</p></div>';
    }

    // === Field render'ları ===

    public function render_mode_field() {
        $opts = smart_assistant_get_options();
        $mode = $opts['mode'] ?? 'simple';
        ?>
        <fieldset>
            <label style="display:block; margin-bottom: 10px;">
                <input type="radio" name="smart_assistant_options[mode]" value="simple" <?php checked( $mode, 'simple' ); ?> />
                <strong><?php esc_html_e( 'Mod 1 — Basit (WordPress araması + LLM)', 'smart-assistant' ); ?></strong>
                <p class="description" style="margin: 4px 0 0 24px;">
                    <?php esc_html_e( 'WordPress\'in FULLTEXT aramasını kullanır. Kurulumu en kolay mod, ekstra altyapı gerektirmez. Az sayıda yazı için idealdir.', 'smart-assistant' ); ?>
                </p>
            </label>
            <label style="display:block;">
                <input type="radio" name="smart_assistant_options[mode]" value="open_notebook" <?php checked( $mode, 'open_notebook' ); ?> />
                <strong><?php esc_html_e( 'Mod 2 — Open Notebook (Semantik arama)', 'smart-assistant' ); ?></strong>
                <p class="description" style="margin: 4px 0 0 24px;">
                    <?php esc_html_e( 'Anlam tabanlı arama, daha doğru sonuçlar. Open Notebook kurulu olmalı (ayrıca URL ve Notebook ID girilmelidir). Çok sayıda yazı için idealdir.', 'smart-assistant' ); ?>
                </p>
            </label>
        </fieldset>
        <?php
    }

    public function render_provider_field() {
        $opts    = smart_assistant_get_options();
        $presets = smart_assistant_get_provider_presets();
        $notes   = wp_json_encode( array_column( $presets, 'note', 'note' ) );
        // Her provider'ın notunu JS'e aktaracağız.
        $provider_notes = [];
        foreach ( $presets as $key => $p ) {
            $provider_notes[ $key ] = $p['note'] ?? '';
        }
        ?>
        <select id="smart_assistant_provider" name="smart_assistant_options[provider]">
            <?php foreach ( $presets as $key => $p ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $opts['provider'], $key ); ?>>
                    <?php echo esc_html( $p['label'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description" id="smart_assistant_provider_note">
            <?php
            $current_note = $presets[ $opts['provider'] ]['note'] ?? '';
            if ( $current_note ) {
                echo esc_html( $current_note );
            } else {
                esc_html_e( 'MiniMax varsayılandır (OpenAI uyumlu). Gemini ve Anthropic native API formatında çalışır.', 'smart-assistant' );
            }
            ?>
        </p>
        <script>
        (function(){
            var notes = <?php echo wp_json_encode( $provider_notes ); ?>;
            var sel   = document.getElementById('smart_assistant_provider');
            var note  = document.getElementById('smart_assistant_provider_note');
            if ( sel && note ) {
                sel.addEventListener('change', function(){
                    note.textContent = notes[this.value] || '';
                });
            }
        })();
        </script>
        <?php
    }

    public function render_api_key_field() {
        $opts    = smart_assistant_get_options();
        $has_key = ! empty( $opts['api_key'] );
        $placeholder = $has_key
            ? str_repeat( '•', 8 ) . substr( $opts['api_key'], -4 ) . ' — boş bırakırsan değişmez'
            : 'sk-...';
        ?>
        <input type="password" id="smart_assistant_api_key" name="smart_assistant_options[api_key]"
               value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" class="regular-text" autocomplete="new-password" />
        <p class="description">
            <?php
            if ( $has_key ) {
                esc_html_e( 'Şu an bir anahtar kayıtlı. Değiştirmek için yeni anahtarı girin. Boş bırakırsanız mevcut anahtar korunur.', 'smart-assistant' );
            } else {
                esc_html_e( 'API anahtarını (veya token) girin. Token plan kullanıyorsanız token\'inizi buraya yapıştırın.', 'smart-assistant' );
            }
            ?>
        </p>
        <?php
    }

    public function render_group_id_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="text" name="smart_assistant_options[group_id]"
               value="<?php echo esc_attr( $opts['group_id'] ); ?>" class="regular-text"
               placeholder="örn. 123456789012345678901234" />
        <p class="description">
            <?php esc_html_e( 'MiniMax Token Plan kullanıyorsanız, platformdaki "组织管理 → Group ID" alanından 24 haneli ID\'yi girin. Pay-as-you-go kullanıyorsanız boş bırakın.', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_api_base_url_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="url" id="smart_assistant_api_base_url" name="smart_assistant_options[api_base_url]"
               value="<?php echo esc_attr( $opts['api_base_url'] ); ?>" class="regular-text" />
        <p class="description" id="smart_assistant_base_url_desc">
            <?php esc_html_e( 'Provider değişince otomatik dolar. Gerekirse elle override edin.', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_model_field() {
        $opts    = smart_assistant_get_options();
        $presets = smart_assistant_get_provider_presets();
        $current_models = $presets[ $opts['provider'] ]['models'] ?? [];
        ?>
        <input type="text" id="smart_assistant_model" name="smart_assistant_options[model]"
               value="<?php echo esc_attr( $opts['model'] ); ?>" class="regular-text"
               list="smart_assistant_model_presets" />
        <datalist id="smart_assistant_model_presets">
            <?php foreach ( $current_models as $m ) : ?>
                <option value="<?php echo esc_attr( $m ); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="description"><?php esc_html_e( 'Dropdown\'dan seçebilir veya elle girebilirsiniz.', 'smart-assistant' ); ?></p>
        <?php
    }

    public function render_system_prompt_field() {
        $opts = smart_assistant_get_options();
        ?>
        <textarea name="smart_assistant_options[system_prompt]" rows="6" class="large-text"><?php
            echo esc_textarea( $opts['system_prompt'] );
        ?></textarea>
        <?php
    }

    public function render_temperature_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="number" step="0.1" min="0" max="2" name="smart_assistant_options[temperature]"
               value="<?php echo esc_attr( $opts['temperature'] ); ?>" />
        <?php
    }

    public function render_max_tokens_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="number" min="50" max="4000" step="50" name="smart_assistant_options[max_tokens]"
               value="<?php echo esc_attr( $opts['max_tokens'] ); ?>" />
        <?php
    }

    public function render_post_types_field() {
        $opts     = smart_assistant_get_options();
        $selected = (array) $opts['post_types'];
        $pts      = get_post_types( [ 'public' => true ], 'objects' );
        foreach ( $pts as $pt ) {
            ?>
            <label style="display:inline-block;margin-right:12px;">
                <input type="checkbox" name="smart_assistant_options[post_types][]"
                       value="<?php echo esc_attr( $pt->name ); ?>"
                    <?php checked( in_array( $pt->name, $selected, true ) ); ?> />
                <?php echo esc_html( $pt->labels->singular_name ); ?>
            </label>
            <?php
        }
    }

    public function render_max_results_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="number" min="1" max="20" name="smart_assistant_options[max_results]"
               value="<?php echo esc_attr( $opts['max_results'] ); ?>" />
        <?php
    }

    public function render_max_content_chars_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="number" min="500" max="50000" step="500" name="smart_assistant_options[max_content_chars]"
               value="<?php echo esc_attr( $opts['max_content_chars'] ); ?>" />
        <p class="description">
            <?php
            printf(
                /* translators: %d: default value */
                esc_html__( 'Her yazıdan AI\'a gönderilecek maksimum karakter (varsayılan %d ≈ 1500 token). Yazılarınız çok uzunsa artırın, maliyet kontrolü için düşürün. Aşıldığında "...[içerik burada kesildi]" notu düşer ve tam metin için yazıya link verir.', 'smart-assistant' ),
                6000
            );
            ?>
        </p>
        <?php
    }

    public function render_open_notebook_url_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="url" name="smart_assistant_options[open_notebook_url]"
               value="<?php echo esc_attr( $opts['open_notebook_url'] ); ?>" class="regular-text"
               placeholder="https://opennotebook-api.hizliadisyo.com" />
        <p class="description">
            <?php esc_html_e( 'Open Notebook API\'nin temel URL\'si. Mod 2 için gerekir.', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_open_notebook_notebook_id_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="text" name="smart_assistant_options[open_notebook_notebook_id]"
               value="<?php echo esc_attr( $opts['open_notebook_notebook_id'] ); ?>" class="regular-text"
               placeholder="örn. notebook:abc123def456" />
        <p class="description">
            <?php esc_html_e( 'Open Notebook\'te bir notebook oluşturup URL\'sinden veya UI\'dan ID\'sini kopyalayın. WP yazıları bu notebook\'a source olarak eklenecek.', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_on_strategy_model_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="text" name="smart_assistant_options[on_strategy_model]"
               value="<?php echo esc_attr( $opts['on_strategy_model'] ); ?>" class="regular-text"
               placeholder="<?php echo esc_attr( \SmartAssistant\OpenNotebook::DEFAULT_STRATEGY_MODEL ); ?>" />
        <p class="description">
            <?php esc_html_e( 'Strateji seçim modeli (boşsa ON varsayılanı: MiniMax-M3).', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_on_answer_model_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="text" name="smart_assistant_options[on_answer_model]"
               value="<?php echo esc_attr( $opts['on_answer_model'] ); ?>" class="regular-text"
               placeholder="<?php echo esc_attr( \SmartAssistant\OpenNotebook::DEFAULT_ANSWER_MODEL ); ?>" />
        <p class="description">
            <?php esc_html_e( 'Cevap üretim modeli (boşsa ON varsayılanı: MiniMax-M3).', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_on_final_answer_model_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="text" name="smart_assistant_options[on_final_answer_model]"
               value="<?php echo esc_attr( $opts['on_final_answer_model'] ); ?>" class="regular-text"
               placeholder="<?php echo esc_attr( \SmartAssistant\OpenNotebook::DEFAULT_FINAL_MODEL ); ?>" />
        <p class="description">
            <?php esc_html_e( 'Son cevap modeli (boşsa ON varsayılanı: MiniMax-M3). Büyük context gerekirse büyük_context_model\'i kullanabilirsin.', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_rate_limit_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="number" min="1" max="200" name="smart_assistant_options[rate_limit_per_min]"
               value="<?php echo esc_attr( $opts['rate_limit_per_min'] ); ?>" />
        <p class="description"><?php esc_html_e( 'IP başına dakikada izin verilen istek sayısı.', 'smart-assistant' ); ?></p>
        <?php
    }

    public function render_abilities_field() {
        $opts = smart_assistant_get_options();
        ?>
        <label>
            <input type="checkbox" name="smart_assistant_options[enable_abilities]" value="1"
                <?php checked( $opts['enable_abilities'] ); ?> />
            <?php esc_html_e( 'search_content ve get_post yeteneklerini Abilities API ile aç', 'smart-assistant' ); ?>
        </label>
        <?php
    }

    public function render_ai_name_field() {
        $opts = smart_assistant_get_options();
        $default_name = get_bloginfo( 'name' ) . ' Asistanı';
        ?>
        <input type="text" name="smart_assistant_options[ai_name]"
               value="<?php echo esc_attr( $opts['ai_name'] ); ?>" class="regular-text"
               placeholder="<?php echo esc_attr( $default_name ); ?>" />
        <p class="description">
            <?php
            printf(
                /* translators: %s: default name */
                esc_html__( 'Asistanın görünen adı. Boş bırakırsan "%s" kullanılır.', 'smart-assistant' ),
                esc_html( $default_name )
            );
            ?>
        </p>
        <?php
    }

    public function render_ai_tone_field() {
        $opts = smart_assistant_get_options();
        $tones = [
            'friendly'     => __( 'Samimi — sıcak, günlük konuşma dili, emoji kullanabilir', 'smart-assistant' ),
            'professional' => __( 'Profesyonel — ciddi, kurumsal ton, emoji KULLANMA', 'smart-assistant' ),
            'expert'       => __( 'Uzman — detaylı, kanıt ve kaynak gösteren', 'smart-assistant' ),
        ];
        ?>
        <select name="smart_assistant_options[ai_tone]">
            <?php foreach ( $tones as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $opts['ai_tone'], $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_ai_greeting_field() {
        $opts = smart_assistant_get_options();
        $default_greeting = sprintf( 'Merhaba! 👋 Ben %s. Sitenin içeriklerinden sana yardımcı olabilirim.', get_bloginfo( 'name' ) . ' Asistanı' );
        ?>
        <textarea name="smart_assistant_options[ai_greeting]" rows="2" class="large-text"
                  placeholder="<?php echo esc_attr( $default_greeting ); ?>"><?php echo esc_textarea( $opts['ai_greeting'] ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'Widget açıldığında gösterilen hoş geldin balonu. Boş bırakırsan otomatik oluşturulur.', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_ai_examples_field() {
        $opts = smart_assistant_get_options();
        ?>
        <textarea name="smart_assistant_options[ai_examples]" rows="6" class="large-text"
                  placeholder="<?php esc_attr_e( 'S: selam | C: Merhaba! Ben [Asistan Adı], nasıl yardımcı olabilirim?&#10;S: sen kimsin | C: Ben [Asistan Adı], [Site Adı] sitesinin yapay zeka asistanıyım.', 'smart-assistant' ); ?>"><?php echo esc_textarea( $opts['ai_examples'] ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'AI\'a istediğin tarzı öğret. Her satır: "S: soru | C: cevap". 1-3 örnek yeterli.', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_show_signature_field() {
        $opts = smart_assistant_get_options();
        ?>
        <label>
            <input type="checkbox" name="smart_assistant_options[show_signature]" value="1"
                <?php checked( $opts['show_signature'] ); ?> />
            <?php esc_html_e( 'Her cevabın sonuna "— AI Adı" imzası eklensin', 'smart-assistant' ); ?>
        </label>
        <?php
    }

    // === Görünüm & Davranış field render'ları ===

    public function render_appearance_color_field() {
        $opts  = smart_assistant_get_options();
        $value = $opts['appearance_color'] ?: '#0f172a';
        ?>
        <input type="color" name="smart_assistant_options[appearance_color]"
               value="<?php echo esc_attr( $value ); ?>" />
        <p class="description">
            <?php esc_html_e( 'Balon, panel başlığı ve butonların ana rengi. Varsayılan: koyu lacivert (#0f172a).', 'smart-assistant' ); ?>
        </p>
        <?php
    }

    public function render_appearance_position_field() {
        $opts = smart_assistant_get_options();
        ?>
        <select name="smart_assistant_options[appearance_position]">
            <option value="right" <?php selected( $opts['appearance_position'], 'right' ); ?>><?php esc_html_e( 'Sağ alt (varsayılan)', 'smart-assistant' ); ?></option>
            <option value="left" <?php selected( $opts['appearance_position'], 'left' ); ?>><?php esc_html_e( 'Sol alt', 'smart-assistant' ); ?></option>
        </select>
        <?php
    }

    public function render_appearance_icon_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="text" name="smart_assistant_options[appearance_icon]"
               value="<?php echo esc_attr( $opts['appearance_icon'] ); ?>" style="width:70px" maxlength="8" />
        <p class="description"><?php esc_html_e( 'Launcher balonunda gösterilen emoji (örn. 💬, 🤖, ✨).', 'smart-assistant' ); ?></p>
        <?php
    }

    public function render_welcome_delay_field() {
        $opts = smart_assistant_get_options();
        ?>
        <input type="number" min="0" max="60" name="smart_assistant_options[welcome_delay]"
               value="<?php echo esc_attr( $opts['welcome_delay'] ); ?>" /> <?php esc_html_e( 'saniye', 'smart-assistant' ); ?>
        <p class="description"><?php esc_html_e( 'Sayfa açıldıktan kaç saniye sonra tanıtım balonu görünsün. 0 = balon gösterilmesin.', 'smart-assistant' ); ?></p>
        <?php
    }

    public function render_persist_chat_field() {
        $opts = smart_assistant_get_options();
        ?>
        <label>
            <input type="checkbox" name="smart_assistant_options[persist_chat]" value="1"
                <?php checked( $opts['persist_chat'] ); ?> />
            <?php esc_html_e( 'Sohbet, sekme açık kaldığı sürece sayfalar arasında korunsun (sessionStorage)', 'smart-assistant' ); ?>
        </label>
        <?php
    }

    public function render_streaming_field() {
        $opts = smart_assistant_get_options();
        ?>
        <label>
            <input type="checkbox" name="smart_assistant_options[enable_streaming]" value="1"
                <?php checked( $opts['enable_streaming'] ); ?> />
            <?php esc_html_e( 'Yanıtlar kelime kelime akarak gelsin (desteklenmeyen sunucularda otomatik normal moda düşer)', 'smart-assistant' ); ?>
        </label>
        <?php
    }

    public function render_feedback_field() {
        $opts = smart_assistant_get_options();
        ?>
        <label>
            <input type="checkbox" name="smart_assistant_options[enable_feedback]" value="1"
                <?php checked( $opts['enable_feedback'] ); ?> />
            <?php esc_html_e( 'AI cevaplarının altında 👍/👎 geri bildirim butonları gösterilsin', 'smart-assistant' ); ?>
        </label>
        <?php
    }
}