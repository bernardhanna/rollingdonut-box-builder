<?php
/**
 * Cart / order extension layer.
 *
 * The box builder drives WPC Product Bundles' native quantity inputs, so WPC
 * owns pricing, validation, the parent/child line model, the order line and
 * emails. We never recreate that logic.
 *
 * What lives here: a thin AJAX layer so the product page can add the configured
 * box and adjust *its* quantity in the basket without a full page reload. WPC
 * bundles can't use WooCommerce's stock `?wc-ajax=add_to_cart` (the plugin
 * strips the `ajax_add_to_cart` class because the bundle config travels in the
 * `woosb_ids` form field), so we add the bundle through WC()->cart->add_to_cart()
 * with `woosb_ids` exposed on $_REQUEST exactly the way the form POST would — WPC
 * then builds the bundle in its own `woocommerce_add_cart_item_data` filter.
 *
 * Everything is additive and gated by rd_box_builder_is_enabled(); removing this
 * file simply reverts to the native form-POST add-to-cart.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Cart {

    const NONCE  = 'rd_bb_cart';
    const ADD    = 'rd_bb_add';
    const SETQTY = 'rd_bb_set_qty';
    const STATE  = 'rd_bb_state';

    public static function init(): void {
        foreach (array(self::ADD, self::SETQTY, self::STATE) as $action) {
            $cb = array(__CLASS__, 'ajax_' . str_replace('rd_bb_', '', $action));
            add_action('wp_ajax_' . $action, $cb);
            add_action('wp_ajax_nopriv_' . $action, $cb);
        }
    }

    /** Verify the nonce and make sure the cart/session is available on admin-ajax. */
    private static function boot(): void {
        check_ajax_referer(self::NONCE, 'nonce');

        if (function_exists('wc_load_cart') && (is_null(WC()->cart) || is_null(WC()->session))) {
            wc_load_cart();
        }

        if (is_null(WC()->cart)) {
            wp_send_json_error(array('message' => __('Your basket is unavailable. Please refresh and try again.', 'rd-box-builder')));
        }
    }

    public static function ajax_add(): void {
        self::boot();

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity   = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;
        $woosb_ids  = isset($_POST['woosb_ids']) ? wc_clean(wp_unslash($_POST['woosb_ids'])) : '';

        if (! $product_id || ! ($product = wc_get_product($product_id))) {
            wp_send_json_error(array('message' => __('We couldn’t find that box.', 'rd-box-builder')));
        }

        // WPC reads the bundle configuration from $_REQUEST['woosb_ids'] inside its
        // woocommerce_add_cart_item_data filter, so present it the same way a normal
        // form POST would.
        if ($woosb_ids !== '') {
            $_REQUEST['woosb_ids'] = $woosb_ids;
            $_POST['woosb_ids']    = $woosb_ids;
        }

        $key = WC()->cart->add_to_cart($product_id, $quantity);

        if (! $key) {
            wp_send_json_error(array('message' => self::first_error(__('Could not add this box to your basket.', 'rd-box-builder'))));
        }

        $item = WC()->cart->get_cart_item($key);
        wp_send_json_success(self::payload($key, $item ? (int) $item['quantity'] : $quantity));
    }

    public static function ajax_set_qty(): void {
        self::boot();

        $key      = isset($_POST['cart_item_key']) ? wc_clean(wp_unslash($_POST['cart_item_key'])) : '';
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 0;

        if ($key === '' || ! WC()->cart->get_cart_item($key)) {
            wp_send_json_success(self::payload('', 0, true));
        }

        if ($quantity <= 0) {
            WC()->cart->remove_cart_item($key);
            wp_send_json_success(self::payload('', 0, true));
        }

        WC()->cart->set_quantity($key, $quantity, true);
        $item = WC()->cart->get_cart_item($key);
        wp_send_json_success(self::payload($key, $item ? (int) $item['quantity'] : $quantity));
    }

    public static function ajax_state(): void {
        self::boot();

        $key  = isset($_POST['cart_item_key']) ? wc_clean(wp_unslash($_POST['cart_item_key'])) : '';
        $item = $key !== '' ? WC()->cart->get_cart_item($key) : null;

        if (! $item) {
            wp_send_json_success(self::payload('', 0, true));
        }

        wp_send_json_success(self::payload($key, (int) $item['quantity']));
    }

    private static function payload(string $key, int $qty, bool $removed = false): array {
        WC()->cart->calculate_totals();

        return array(
            'cart_item_key' => $key,
            'quantity'      => $qty,
            'removed'       => $removed,
            'cart_count'    => WC()->cart->get_cart_contents_count(),
            'cart_hash'     => WC()->cart->get_cart_hash(),
        );
    }

    /** Pull the first queued WooCommerce error notice (e.g. WPC validation). */
    private static function first_error(string $fallback): string {
        if (! function_exists('wc_get_notices')) {
            return $fallback;
        }

        $errors = wc_get_notices('error');
        wc_clear_notices();

        if (! empty($errors) && isset($errors[0]['notice'])) {
            return wp_strip_all_tags($errors[0]['notice']);
        }

        return $fallback;
    }
}
