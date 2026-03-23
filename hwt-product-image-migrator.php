<?php
/**
 * Plugin Name: heyWebTeam - Product Image Migrator
 * Plugin URI:  https://heywebteam.com
 * Description: Export and import WooCommerce product images (featured + gallery) between sites, matched by SKU.
 * Version:     2.9.1
 * Author:      heyWebTeam
 * Author URI:  https://heywebteam.com
 * License:     GPL-2.0+
 * Text Domain: hwt-product-image-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HWT_PIM_VERSION', '2.9.1' );
define( 'HWT_PIM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HWT_PIM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HWT_PIM_LOG_DIR', HWT_PIM_PLUGIN_DIR . 'logs' );

/**
 * Activation: create logs directory with .htaccess protection.
 */
function hwt_pim_activate() {
    if ( ! is_dir( HWT_PIM_LOG_DIR ) ) {
        wp_mkdir_p( HWT_PIM_LOG_DIR );
    }
    $htaccess = HWT_PIM_LOG_DIR . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Deny from all\n" );
    }
    // Also add an index.php for extra safety
    $index = HWT_PIM_LOG_DIR . '/index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }
}
register_activation_hook( __FILE__, 'hwt_pim_activate' );

/**
 * Check that WooCommerce is active before loading the plugin.
 */
function hwt_pim_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'hwt_pim_wc_missing_notice' );
        return;
    }

    require_once HWT_PIM_PLUGIN_DIR . 'includes/class-hwt-logger.php';
    require_once HWT_PIM_PLUGIN_DIR . 'includes/class-hwt-exporter.php';
    require_once HWT_PIM_PLUGIN_DIR . 'includes/class-hwt-importer.php';
    require_once HWT_PIM_PLUGIN_DIR . 'includes/class-hwt-admin.php';

    $logger   = new HWT_Logger();
    $exporter = new HWT_Exporter( $logger );
    $importer = new HWT_Importer( $logger );
    new HWT_Admin( $exporter, $importer, $logger );
}
add_action( 'plugins_loaded', 'hwt_pim_init' );

/**
 * Admin notice when WooCommerce is not active.
 */
function hwt_pim_wc_missing_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>heyWebTeam - Product Image Migrator</strong> requires WooCommerce to be installed and active.';
    echo '</p></div>';
}
