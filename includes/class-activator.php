<?php
namespace SmartAssistant;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Aktivasyon: default option'lar.
 * DB tablosu yok — sohbetler RAM'de kalır (kullanıcı isteği).
 */
class Activator {

    public static function activate() {
        $existing = get_option( 'smart_assistant_options' );
        if ( false === $existing ) {
            add_option( 'smart_assistant_options', [
                'mode'               => 'simple',
                'api_base_url'       => 'https://api.MiniMax.chat/v1',
                'model'              => 'MiniMax-M3',
                'post_types'         => [ 'post' ],
                'max_results'        => 5,
                'rate_limit_per_min' => 20,
                'enable_abilities'   => true,
            ] );
        }

        // Settings kullanıcısına ufak not.
        set_transient( 'smart_assistant_activated_notice', true, 60 );
    }
}