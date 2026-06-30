<?php
/**
 * Plugin Name:       Smart Assistant
 * Plugin URI:        https://github.com/ahmethantalha/smart-assistans-wordpress
 * Description:       AI destekli site asistanı — MiniMax API ile sitenizin içeriklerinden hareketle cevap verir. Sağ alt köşede chatbot widget ve makale özetleme butonu. Mod 1: yerleşik WP araması. Mod 2: Open Notebook (MCP) entegrasyonu.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ahmethan T. Gültekin
 * Author URI:        https://github.com/ahmethantalha
 * License:           GPL2+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       smart-assistant
 * Domain Path:       /languages
 *
 * @package SmartAssistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Doğrudan erişim yok.
}

// === Sabitler ===
define( 'SMART_ASSISTANT_VERSION', '1.0.0' );
define( 'SMART_ASSISTANT_FILE', __FILE__ );
define( 'SMART_ASSISTANT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMART_ASSISTANT_URL', plugin_dir_url( __FILE__ ) );
define( 'SMART_ASSISTANT_BASENAME', plugin_basename( __FILE__ ) );

// === Autoloader (PSR-4 benzeri) ===
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'SmartAssistant\\' ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( 'SmartAssistant\\' ) );

    // Sınıf → dosya eşleme tablosu (PSR-4 case-insensitive dosya isimleri için).
    $map = [
        'Plugin'         => 'includes/class-plugin.php',
        'Activator'      => 'includes/class-activator.php',
        'Deactivator'    => 'includes/class-deactivator.php',
        'Loader'         => 'includes/class-loader.php',
        'Settings'       => 'includes/class-settings.php',
        'AIClient'       => 'includes/class-ai-client.php',
        'Search'         => 'includes/class-search.php',
        'OpenNotebook'   => 'includes/class-open-notebook.php',
        'RestController' => 'includes/class-rest-controller.php',
        'Abilities'      => 'includes/class-abilities.php',
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

// === Yardımcılar ===
require_once SMART_ASSISTANT_PATH . 'includes/helpers.php';

// === Aktivasyon / Deaktivasyon ===
register_activation_hook( __FILE__, [ \SmartAssistant\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \SmartAssistant\Deactivator::class, 'deactivate' ] );

// === Bootstrap ===
add_action( 'plugins_loaded', function () {
    \SmartAssistant\Plugin::instance()->boot();
} );