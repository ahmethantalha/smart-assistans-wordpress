<?php
/**
 * PHPUnit bootstrap — WordPress bağımsız birim testleri.
 *
 * Brain\Monkey ile WP fonksiyonları ve hook sistemi taklit edilir.
 * Gerçek bir WP kurulumu gerekmez; yalnızca plugin'in saf PHP mantığı test edilir.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ── WordPress sabitleri ──────────────────────────────────────────────────────
define( 'ABSPATH',                    sys_get_temp_dir() . '/' );
define( 'SMART_ASSISTANT_VERSION',    '1.0.0' );
define( 'SMART_ASSISTANT_FILE',       dirname( __DIR__ ) . '/smart-assistant.php' );
define( 'SMART_ASSISTANT_PATH',       dirname( __DIR__ ) . '/' );
define( 'SMART_ASSISTANT_URL',        'https://example.com/wp-content/plugins/smart-assistant/' );
define( 'SMART_ASSISTANT_BASENAME',   'smart-assistant/smart-assistant.php' );
define( 'DAY_IN_SECONDS',            86400 );
define( 'WP_DEBUG',                  false );

// ── WP yardımcı fonksiyon stub'ları ─────────────────────────────────────────
// Brain\Monkey WP hook sistemini simüle eder. Aşağıdaki stub'lar Brain\Monkey
// tarafından karşılanmayan veya saf PHP mantığı olan fonksiyonlardır.

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = [] ) {
        if ( is_object( $args ) ) {
            $args = (array) $args;
        }
        return array_merge( (array) $defaults, (array) $args );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0 ) {
        return json_encode( $data, $options );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        return implode( "\n", array_map( 'sanitize_text_field', explode( "\n", $str ) ) );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
    }
}

// ── Plugin class autoloader ──────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'SmartAssistant\\' ) !== 0 ) {
        return;
    }
    if ( strpos( $class, 'SmartAssistant\\Tests\\' ) === 0 ) {
        return; // Test sınıfları Composer PSR-4 ile yüklenir.
    }

    $relative = substr( $class, strlen( 'SmartAssistant\\' ) );
    $map = [
        'AIClient'       => 'includes/class-ai-client.php',
        'Search'         => 'includes/class-search.php',
        'OpenNotebook'   => 'includes/class-open-notebook.php',
        'RestController' => 'includes/class-rest-controller.php',
        'Abilities'      => 'includes/class-abilities.php',
        'Settings'       => 'includes/class-settings.php',
        'Plugin'         => 'includes/class-plugin.php',
        'Activator'      => 'includes/class-activator.php',
        'Deactivator'    => 'includes/class-deactivator.php',
        'Loader'         => 'includes/class-loader.php',
        'Frontend'       => 'public/class-frontend.php',
        'Admin'          => 'admin/class-admin.php',
    ];

    if ( isset( $map[ $relative ] ) ) {
        $path = SMART_ASSISTANT_PATH . $map[ $relative ];
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
} );

// helpers.php — fonksiyon tanımları, sınıf değil.
require_once SMART_ASSISTANT_PATH . 'includes/helpers.php';
