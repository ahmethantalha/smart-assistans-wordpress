<?php
/**
 * Uninstall Smart Assistant — kullanıcı plugin'i sildiğinde çalışır.
 *
 * @package SmartAssistant
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Options temizle.
delete_option( 'smart_assistant_options' );

// Rate limit transient'leri (önek ile).
global $wpdb;
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_smart_assistant_rl_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_smart_assistant_rl_' ) . '%'
) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( '_transient_smart_assistant_activated_notice' ) . '%'
) );