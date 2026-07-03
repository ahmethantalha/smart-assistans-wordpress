<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend: widget, FAB ve asset'leri yükler.
 */
class Frontend {

    public function register_hooks() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'init', [ $this, 'register_shortcode_and_block' ] );
    }

    /**
     * [smart_assistant] shortcode'u ve Gutenberg block'u kaydet.
     * İkisi de aynı inline mount container'ını basar; widget.js bunu görünce
     * chat panelini sayfa içine gömülü (inline) modda açar.
     */
    public function register_shortcode_and_block() {
        add_shortcode( 'smart_assistant', [ $this, 'render_inline_mount' ] );

        if ( function_exists( 'register_block_type' ) ) {
            wp_register_script(
                'smart-assistant-block',
                SMART_ASSISTANT_URL . 'public/js/block.js',
                [ 'wp-blocks', 'wp-element' ],
                SMART_ASSISTANT_VERSION,
                true
            );
            register_block_type( 'smart-assistant/chat', [
                'editor_script'   => 'smart-assistant-block',
                'render_callback' => [ $this, 'render_inline_mount' ],
            ] );
        }
    }

    /**
     * Inline chat mount noktası (shortcode + block ortak çıktısı).
     */
    public function render_inline_mount() {
        return '<div class="sa-inline-wrap" id="smart-assistant-inline"></div>';
    }

    public function enqueue_assets() {
        // Sadece frontend'de.
        if ( is_admin() ) {
            return;
        }

        // Chatbot widget: her yerde.
        wp_enqueue_style(
            'smart-assistant-widget',
            SMART_ASSISTANT_URL . 'public/css/widget.css',
            [],
            SMART_ASSISTANT_VERSION
        );

        wp_enqueue_script(
            'smart-assistant-widget',
            SMART_ASSISTANT_URL . 'public/js/widget.js',
            [],
            SMART_ASSISTANT_VERSION,
            true
        );

        // FAB: ayarlarda seçili tüm post type'ların single görünümünde görünsün (post, page, CPT).
        $is_single_post = smart_assistant_current_supports_fab();

        $identity = smart_assistant_get_identity();
        $opts     = smart_assistant_get_options();

        $localize = [
            'restUrl'  => esc_url_raw( rest_url( 'smart-assistant/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'isSingle' => (bool) $is_single_post,
            'postId'   => $is_single_post ? (int) get_queried_object_id() : 0,
            'postType' => $is_single_post ? (string) get_post_type() : '',
            'siteUrl'  => esc_url_raw( site_url() ),
            'persistChat' => (bool) $opts['persist_chat'],
            'streaming'   => (bool) $opts['enable_streaming'],
            'feedback'    => (bool) $opts['enable_feedback'],
            'appearance'  => [
                'color'        => (string) $opts['appearance_color'],
                'position'     => (string) $opts['appearance_position'],
                'icon'         => (string) $opts['appearance_icon'],
                'welcomeDelay' => (int) $opts['welcome_delay'],
            ],
            'identity' => [
                'name'     => $identity['name'],
                'greeting' => $identity['greeting'],
                'signature'=> $identity['show_signature'] ? '— ' . $identity['name'] : '',
            ],
            'tools'    => $this->get_tools_for_js(),
            'i18n'     => [
                'askPlaceholder'   => __( 'Bir şey sor…', 'smart-assistant' ),
                'send'             => __( 'Gönder', 'smart-assistant' ),
                'thinking'         => __( 'Düşünüyor…', 'smart-assistant' ),
                'error'            => __( 'Bir hata oluştu. Tekrar dene.', 'smart-assistant' ),
                'clearChat'        => __( 'Sohbeti temizle', 'smart-assistant' ),
                'expand'           => __( 'Genişlet', 'smart-assistant' ),
                'collapse'         => __( 'Küçült', 'smart-assistant' ),
                'summarizeTitle'   => __( 'Bu sayfayı özetle', 'smart-assistant' ),
                'openChat'         => $identity['name'], // Widget header'ında gösterilecek.
                'closeChat'        => __( 'Kapat', 'smart-assistant' ),
                'sources'          => __( 'Kaynaklar', 'smart-assistant' ),
                'welcomeMsg'       => $identity['greeting'],
                'welcomeBubble'    => $identity['greeting'],
                'suggestionsTitle' => __( 'Sorabilirsin', 'smart-assistant' ),
                'welcomeCTA'       => __( '💬 Sohbete başla', 'smart-assistant' ),
                'minimize'         => __( 'Küçült', 'smart-assistant' ),
                'tests'            => __( 'Testler', 'smart-assistant' ),
                'testsHint'        => __( 'Size yardımcı olabilecek hesaplayıcılar', 'smart-assistant' ),
                'backToChat'       => __( 'Sohbet', 'smart-assistant' ),
                'feedbackUp'       => __( 'Faydalı', 'smart-assistant' ),
                'feedbackDown'     => __( 'Faydasız', 'smart-assistant' ),
                'feedbackThanks'   => __( 'Teşekkürler!', 'smart-assistant' ),
            ],
        ];

        wp_localize_script( 'smart-assistant-widget', 'SmartAssistant', $localize );

        if ( $is_single_post ) {
            wp_enqueue_style(
                'smart-assistant-fab',
                SMART_ASSISTANT_URL . 'public/css/fab.css',
                [ 'smart-assistant-widget' ],
                SMART_ASSISTANT_VERSION
            );
            // Not: FAB davranışı widget.js içinde zaten kuruluyor (isSingle true ise buildFab()).
        }
    }

    /**
     * Testler panelinde gösterilecek araç listesi. system_prompt buraya
     * dahil edilmez — yalnızca 'key' frontend'den REST isteğiyle geri döner,
     * gerçek prompt sunucu tarafında AIClient içinde eşleştirilir.
     */
    private function get_tools_for_js() {
        $tools = smart_assistant_get_tools();
        $out   = [];
        foreach ( $tools as $key => $tool ) {
            $out[] = [
                'key'         => $key,
                'label'       => $tool['label'],
                'description' => $tool['description'],
                'icon'        => $tool['icon'],
                'welcomeMsg'  => $tool['welcome_msg'],
            ];
        }
        return $out;
    }
}