<?php
/**
 * Plugin Name:  Pymes Modernas — Portal Cliente
 * Plugin URI:   https://pymesmodernas.com
 * Description:  Portal de mantenimiento para clientes de Pymes Modernas. Muestra estado de créditos, tickets y rendimiento de la tienda WooCommerce. Aparece como menú "Pymes Modernas" en el admin de WordPress.
 * Version:      1.6.3
 * Author:       Pymes Modernas
 * Author URI:   https://pymesmodernas.com
 * Text Domain:  pm-portal
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'PMP_VERSION',    '1.6.3' );
define( 'PMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ──── Clases ────────────────────────────────────────────────────────────────
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-settings.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-api-client.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-woocommerce.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-ga4.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-meta.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-klaviyo.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-order-sync.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-search-console.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-digest.php';
require_once PMP_PLUGIN_DIR . 'includes/class-pmp-portal.php';
require_once PMP_PLUGIN_DIR . 'admin/class-pmp-admin.php';

// ──── Cron: resumen semanal ──────────────────────────────────────────────────
add_action( PMP_Digest::HOOK, [ 'PMP_Digest', 'send' ] );
register_activation_hook(   __FILE__, [ 'PMP_Digest', 'schedule'   ] );
register_deactivation_hook( __FILE__, [ 'PMP_Digest', 'unschedule' ] );

// ──── Bootstrap ─────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    PMP_Digest::schedule(); // garantiza que el cron esté activo aunque no se reactivó el plugin

    new PMP_Portal();
    new PMP_Order_Sync();

    if ( is_admin() ) {
        new PMP_Admin();
    }
} );
