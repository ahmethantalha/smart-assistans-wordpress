<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress 7.0 Abilities API entegrasyonu.
 *
 * Plugin'in yeteneklerini (search_content, get_post) AI agent'larına açar.
 * Böylece dışarıdan Claude Desktop gibi MCP client'lar bu siteye bağlanıp
 * içerik sorgulayabilir.
 */
class Abilities {

    public function register_hooks() {
        add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
        add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
    }

    public function register_category() {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }
        wp_register_ability_category( 'smart-assistant', [
            'label'       => __( 'Smart Assistant', 'smart-assistant' ),
            'description' => __( 'Site içeriğinde arama ve içerik getirme.', 'smart-assistant' ),
        ] );
    }

    public function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        $opts = smart_assistant_get_options();
        if ( empty( $opts['enable_abilities'] ) ) {
            return;
        }

        wp_register_ability( 'smart-assistant/search_content', [
            'label'               => __( 'İçerikte Ara', 'smart-assistant' ),
            'description'         => __( 'Sitedeki yazılarda anahtar kelimeyle arama yapar, sonuçları başlık, URL ve özet olarak döndürür.', 'smart-assistant' ),
            'category'            => 'smart-assistant',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => __( 'Arama sorgusu', 'smart-assistant' ),
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => __( 'Maks. sonuç sayısı (1-20)', 'smart-assistant' ),
                        'default'     => 5,
                    ],
                ],
                'required'   => [ 'query' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'results' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'      => [ 'type' => 'integer' ],
                                'title'   => [ 'type' => 'string' ],
                                'url'     => [ 'type' => 'string' ],
                                'excerpt' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [ $this, 'ability_search' ],
            'permission_callback' => [ $this, 'permission_logged_in' ],
        ] );

        wp_register_ability( 'smart-assistant/get_post', [
            'label'               => __( 'Yazı Getir', 'smart-assistant' ),
            'description'         => __( 'Belirli bir yazının içeriğini getirir.', 'smart-assistant' ),
            'category'            => 'smart-assistant',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => __( 'Yazı ID', 'smart-assistant' ),
                    ],
                ],
                'required'   => [ 'post_id' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'      => [ 'type' => 'integer' ],
                            'title'   => [ 'type' => 'string' ],
                            'url'     => [ 'type' => 'string' ],
                            'content' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [ $this, 'ability_get_post' ],
            'permission_callback' => [ $this, 'permission_logged_in' ],
        ] );
    }

    public function permission_logged_in() {
        return is_user_logged_in();
    }

    public function ability_search( $input ) {
        $query = isset( $input['query'] ) ? sanitize_text_field( $input['query'] ) : '';
        $limit = isset( $input['limit'] ) ? max( 1, min( 20, intval( $input['limit'] ) ) ) : 5;

        if ( '' === $query ) {
            return new \WP_Error( 'empty_query', 'Sorgu boş olamaz' );
        }

        $opts         = smart_assistant_get_options();
        $orig_max     = $opts['max_results'];
        $opts['max_results'] = $limit;
        update_option( 'smart_assistant_options', $opts );

        $results = \SmartAssistant\Plugin::instance()->search->search( $query );

        $opts['max_results'] = $orig_max;
        update_option( 'smart_assistant_options', $opts );

        return [ 'results' => $results ];
    }

    public function ability_get_post( $input ) {
        $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
        if ( ! $post_id ) {
            return new \WP_Error( 'no_post_id', 'Post ID gerekli' );
        }
        $post = \SmartAssistant\Plugin::instance()->search->get_post( $post_id );
        if ( ! $post ) {
            return new \WP_Error( 'not_found', 'Yazı bulunamadı' );
        }
        return [ 'post' => $post ];
    }
}