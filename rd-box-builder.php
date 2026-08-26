<?php
/**
 * Plugin Name: Rolling Donut Box Builder
 * Description: An interactive "Build Your Own Box" UI layered on top of WPC Product Bundles. Turns flagged bundle (woosb) products into a drag-and-drop box builder while WPC keeps owning the cart, pricing and order logic.
 * Version: 0.1.0
 * Author: Rolling Donut
 * Requires Plugins: woocommerce
 * Text Domain: rd-box-builder
 *
 * @package RD_Box_Builder
 *
 * Architecture (phase 1):
 *   This plugin renders NO cart/price logic of its own. WPC Product Bundles
 *   ("woosb") already renders an editable per-item quantity input for optional
 *   bundle items and recalculates totals + add-to-cart readiness on `change`.
 *   Our skin simply reads the rendered `.woosb-product` nodes, presents a nicer
 *   box-builder UI, and writes back into the native `.woosb-qty` inputs.
 *   See includes/class-cart.php for the additive-hook contract reserved for
 *   later phases (logo upload, special occasion, stands, etc.).
 */

defined('ABSPATH') || exit;

define('RD_BB_VERSION', '0.1.0');
define('RD_BB_FILE', __FILE__);
define('RD_BB_DIR', plugin_dir_path(__FILE__));
define('RD_BB_URL', plugin_dir_url(__FILE__));

require_once RD_BB_DIR . 'includes/helpers.php';
require_once RD_BB_DIR . 'includes/class-flag.php';
require_once RD_BB_DIR . 'includes/class-quick-fill.php';
require_once RD_BB_DIR . 'includes/class-render.php';
require_once RD_BB_DIR . 'includes/class-assets.php';
require_once RD_BB_DIR . 'includes/class-cart.php';
require_once RD_BB_DIR . 'includes/class-stats.php';
require_once RD_BB_DIR . 'includes/class-addons.php';
require_once RD_BB_DIR . 'includes/class-cart-edit.php';
require_once RD_BB_DIR . 'includes/class-integrity.php';
require_once RD_BB_DIR . 'includes/class-zero-qty.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once RD_BB_DIR . 'includes/class-cli.php';
}

/**
 * Boot the plugin once all others are loaded, so we can verify dependencies.
 */
function rd_box_builder_init(): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', 'rd_box_builder_notice_missing_woocommerce');

        return;
    }

    if (! defined('WOOSB_VERSION') || ! class_exists('WC_Product_Woosb')) {
        add_action('admin_notices', 'rd_box_builder_notice_missing_woosb');

        return;
    }

    RD_Box_Builder_Flag::init();
    RD_Box_Builder_Quick_Fill::init();
    RD_Box_Builder_Render::init();
    RD_Box_Builder_Assets::init();
    RD_Box_Builder_Cart::init();
    RD_Box_Builder_Stats::init();
    RD_Box_Builder_Addons::init();
    RD_Box_Builder_Cart_Edit::init();
    RD_Box_Builder_Integrity::init();
    RD_Box_Builder_Zero_Qty::init();

    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::add_command('rd-box-builder', 'RD_Box_Builder_CLI');
    }
}
add_action('plugins_loaded', 'rd_box_builder_init', 20);

function rd_box_builder_notice_missing_woocommerce(): void {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('Rolling Donut Box Builder requires WooCommerce to be installed and active.', 'rd-box-builder');
    echo '</p></div>';
}

function rd_box_builder_notice_missing_woosb(): void {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('Rolling Donut Box Builder requires "WPC Product Bundles for WooCommerce" to be installed and active.', 'rd-box-builder');
    echo '</p></div>';
}
