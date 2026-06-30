<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin: settings sayfası menüsü ve asset'leri.
 */
class Admin {

    public function register_hooks() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'maybe_handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Sadece Smart Assistant sayfasında modern CSS/JS yükle.
     */
    public function enqueue_assets( $hook_suffix ) {
        // Sadece bizim settings sayfamızda.
        if ( $hook_suffix !== 'settings_page_smart-assistant' ) {
            return;
        }
        wp_enqueue_style(
            'smart-assistant-admin',
            SMART_ASSISTANT_URL . 'admin/css/admin.css',
            [],
            SMART_ASSISTANT_VERSION
        );
        wp_enqueue_script(
            'smart-assistant-admin',
            SMART_ASSISTANT_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            SMART_ASSISTANT_VERSION,
            true
        );
        // JS'e REST URL + nonce + i18n aktar.
        wp_localize_script( 'smart-assistant-admin', 'SmartAssistantAdmin', [
            'restUrl'  => esc_url_raw( rest_url( 'smart-assistant/v1/test' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'i18n'     => [
                'saved'        => __( '✓ Ayarlar kaydedildi', 'smart-assistant' ),
                'testing'      => __( 'Test ediliyor...', 'smart-assistant' ),
                'testSuccess'  => __( '✓ Bağlantı başarılı', 'smart-assistant' ),
            ],
        ] );
        // Provider preset'lerini JS'e aktar.
        if ( function_exists( 'smart_assistant_get_provider_presets' ) ) {
            wp_localize_script( 'smart-assistant-admin', 'SmartAssistantPresets', smart_assistant_get_provider_presets() );
        }
    }

    public function add_menu() {
        add_options_page(
            __( 'Smart Assistant', 'smart-assistant' ),
            __( 'Smart Assistant', 'smart-assistant' ),
            'manage_options',
            'smart-assistant',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        require_once SMART_ASSISTANT_PATH . 'admin/views/settings-page.php';
    }

    /**
     * Sync action: tüm postları Open Notebook'e gönder.
     */
    public function maybe_handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( empty( $_POST['smart_assistant_action'] ) ) {
            return;
        }
        check_admin_referer( 'smart_assistant_action' );

        $action = sanitize_text_field( $_POST['smart_assistant_action'] );

        if ( 'sync_all_posts' === $action ) {
            $count = $this->sync_all_posts();
            add_settings_error(
                'smart_assistant_options',
                'sync_done',
                sprintf( __( '%d yazı Open Notebook\'e senkronize edildi.', 'smart-assistant' ), $count ),
                'updated'
            );
        }
    }

    /**
     * Tüm post'ları Open Notebook'e sync et.
     */
    private function sync_all_posts() {
        $opts = smart_assistant_get_options();
        if ( empty( $opts['open_notebook_url'] ) ) {
            return 0;
        }

        $q = new \WP_Query([
            'post_type'      => (array) $opts['post_types'],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        $count = 0;
        $on    = \SmartAssistant\Plugin::instance()->open_notebook;
        foreach ( $q->posts as $post_id ) {
            $r = $on->sync_post( $post_id );
            if ( ! is_wp_error( $r ) ) {
                $count++;
            }
        }
        return $count;
    }
}