<?php
/**
 * Add-on options for box-builder bundles (logo upload, customer note, legacy
 * special-occasion select).
 *
 * box-builder-woo already captures, validates, displays and saves the ACF
 * "custom dropdown groups" (e.g. Football Team / Special Occasion) on the
 * standard WooCommerce add-to-cart path, so we deliberately do NOT touch those.
 *
 * What box-builder-woo does NOT handle on the WPC (woosb) add path are the file
 * upload, the note and the legacy special-occasion select — its cart/order hooks
 * are gated on `part_of_box === false`, which WPC bundle items never set. This
 * class fills that gap for box-builder-enabled bundles only:
 *   - blocks the add when a required logo / special occasion is missing,
 *   - stores the uploaded logo + note + occasion on the bundle's cart line,
 *   - renders them in the cart and writes them to the order line item.
 *
 * Everything is gated by rd_box_builder_is_enabled(); other products are
 * unaffected.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Addons {

    const MAX_BYTES    = 2097152; // 2MB
    const ALLOWED_EXTS = array('png', 'jpg', 'jpeg');

    public static function init(): void {
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_required'), 20, 3);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'capture'), 21, 3);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_in_cart'), 21, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'add_order_meta'), 21, 4);

        // Single source of truth for box add-on rows in the cart item data.
        // box-builder-woo registers a second renderer (dbb_display_dropdown_groups_in_cart_item_data)
        // for the very same custom dropdown groups that display_in_cart() above
        // already outputs, which made rows such as "Special Occasion" appear twice
        // in the cart and the checkout "Order Details" summary. We own this
        // rendering now, so drop their duplicate. box-builder-woo loads before us
        // (alphabetical) so its filter is already registered by this point.
        remove_filter('woocommerce_get_item_data', 'dbb_display_dropdown_groups_in_cart_item_data', 20);
    }

    /** True only for flagged box-builder bundles, where these add-ons apply. */
    private static function applies(int $product_id): bool {
        return $product_id > 0 && rd_box_builder_is_enabled($product_id);
    }

    private static function acf_bool(string $field, int $product_id): bool {
        return function_exists('get_field') ? (bool) get_field($field, $product_id) : false;
    }

    /**
     * Block add-to-cart when a required logo / special occasion isn't provided.
     * Format/size of an uploaded logo is validated separately by box-builder-woo.
     */
    public static function validate_required($passed, $product_id, $quantity) {
        $product_id = (int) $product_id;
        if (! self::applies($product_id)) {
            return $passed;
        }

        if (self::acf_bool('enable_logo_upload', $product_id) && self::acf_bool('require_logo_upload', $product_id)) {
            $has_logo = ! empty($_FILES['logo_upload']['size']) && (int) $_FILES['logo_upload']['size'] > 0;
            if (! $has_logo) {
                wc_add_notice(__('Please upload your logo before adding this box to the cart.', 'rd-box-builder'), 'error');
                return false;
            }
        }

        // Legacy special-occasion select (skipped when a custom group is the occasion field itself).
        $groups = function_exists('dbb_get_custom_dropdown_groups')
            ? dbb_get_custom_dropdown_groups($product_id)
            : array();
        $replaces_occasion = function_exists('dbb_dropdown_groups_replace_legacy_occasion')
            && dbb_dropdown_groups_replace_legacy_occasion($groups);
        if (! $replaces_occasion
            && self::acf_bool('enable_special_occasion', $product_id)
            && self::acf_bool('require_special_occasion', $product_id)) {
            $occasion = isset($_POST['custom_product_option']) ? sanitize_text_field(wp_unslash($_POST['custom_product_option'])) : '';
            if ($occasion === '') {
                wc_add_notice(__('Please select an option before adding this box to the cart.', 'rd-box-builder'), 'error');
                return false;
            }
        }

        return $passed;
    }

    /**
     * Attach the note, legacy occasion and uploaded logo(s) to the bundle's cart
     * line so they persist, display and reach the order.
     */
    public static function capture($cart_item_data, $product_id, $variation_id) {
        $product_id = (int) $product_id;
        if (! self::applies($product_id)) {
            return $cart_item_data;
        }

        if (! isset($cart_item_data['custom_product_option']) && ! empty($_POST['custom_product_option'])) {
            $cart_item_data['custom_product_option'] = sanitize_text_field(wp_unslash($_POST['custom_product_option']));
        }

        if (! isset($cart_item_data['special_requests']) && ! empty($_POST['special_requests'])) {
            $cart_item_data['special_requests'] = sanitize_textarea_field(wp_unslash($_POST['special_requests']));
        }

        if (! isset($cart_item_data['logo_upload'])) {
            $url = self::handle_upload('logo_upload');
            if ($url !== '') {
                $cart_item_data['logo_upload'] = $url;
            }
        }

        if (! isset($cart_item_data['additional_logos'])) {
            $extra = self::handle_multi_upload('logo_uploads');
            if (! empty($extra)) {
                $cart_item_data['additional_logos'] = $extra;
            }
        }

        return $cart_item_data;
    }

    /** Move a single uploaded image into the media uploads dir; return its URL. */
    private static function handle_upload(string $field): string {
        if (empty($_FILES[$field]['name']) || empty($_FILES[$field]['size'])) {
            return '';
        }

        if (! function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file  = $_FILES[$field];
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $ext   = strtolower((string) ($check['ext'] ?? ''));
        if (! in_array($ext, self::ALLOWED_EXTS, true) || (int) $file['size'] > self::MAX_BYTES) {
            return '';
        }

        $uploaded = wp_handle_upload($file, array('test_form' => false));

        return (is_array($uploaded) && ! empty($uploaded['url'])) ? esc_url_raw($uploaded['url']) : '';
    }

    /** Same as handle_upload() but for a `name[]` multi-file field. */
    private static function handle_multi_upload(string $field): array {
        if (empty($_FILES[$field]['name']) || ! is_array($_FILES[$field]['name'])) {
            return array();
        }

        if (! function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $urls  = array();
        $names = $_FILES[$field]['name'];
        foreach ($names as $i => $name) {
            if (empty($name) || empty($_FILES[$field]['size'][$i])) {
                continue;
            }
            $single = array(
                'name'     => $name,
                'type'     => $_FILES[$field]['type'][$i] ?? '',
                'tmp_name' => $_FILES[$field]['tmp_name'][$i] ?? '',
                'error'    => $_FILES[$field]['error'][$i] ?? 0,
                'size'     => $_FILES[$field]['size'][$i] ?? 0,
            );
            $check = wp_check_filetype_and_ext($single['tmp_name'], $single['name']);
            $ext   = strtolower((string) ($check['ext'] ?? ''));
            if (! in_array($ext, self::ALLOWED_EXTS, true) || (int) $single['size'] > self::MAX_BYTES) {
                continue;
            }
            $uploaded = wp_handle_upload($single, array('test_form' => false));
            if (is_array($uploaded) && ! empty($uploaded['url'])) {
                $urls[] = esc_url_raw($uploaded['url']);
            }
        }

        return $urls;
    }

    /** Show the note / occasion / logo under the bundle line in the cart. */
    public static function display_in_cart($item_data, $cart_item) {
        // On the cart page the editable accordion (RD_Box_Builder_Cart_Edit) already
        // renders these for box parents, so skip here to avoid a duplicate listing.
        // Other contexts (checkout review, emails) keep the read-only rows.
        if (function_exists('is_cart') && is_cart()
            && class_exists('RD_Box_Builder_Cart_Edit')
            && RD_Box_Builder_Cart_Edit::is_box_parent($cart_item)) {
            return $item_data;
        }

        if (! empty($cart_item['custom_product_option'])) {
            $item_data[] = array(
                'name'  => __('Special Occasion', 'rd-box-builder'),
                'value' => sanitize_text_field($cart_item['custom_product_option']),
            );
        }
        if (! empty($cart_item['custom_dropdowns']) && is_array($cart_item['custom_dropdowns'])) {
            foreach ($cart_item['custom_dropdowns'] as $group) {
                if (! empty($group['label']) && ! empty($group['value'])) {
                    $item_data[] = array(
                        'name'  => sanitize_text_field($group['label']),
                        'value' => sanitize_text_field($group['value']),
                    );
                }
            }
        }
        if (! empty($cart_item['special_requests'])) {
            $item_data[] = array(
                'name'  => __('Note to customer', 'rd-box-builder'),
                'value' => sanitize_text_field($cart_item['special_requests']),
            );
        }
        if (! empty($cart_item['logo_upload'])) {
            $item_data[] = array(
                'name'  => __('Logo', 'rd-box-builder'),
                'value' => '<img src="' . esc_url($cart_item['logo_upload']) . '" alt="" style="max-width:50px;height:auto;">',
            );
        }

        return $item_data;
    }

    /** Persist the same fields onto the order line item. */
    public static function add_order_meta($item, $cart_item_key, $values, $order): void {
        if (! empty($values['custom_product_option'])) {
            $item->add_meta_data(__('Special Occasion', 'rd-box-builder'), sanitize_text_field($values['custom_product_option']), true);
        }
        if (! empty($values['custom_dropdowns']) && is_array($values['custom_dropdowns'])) {
            foreach ($values['custom_dropdowns'] as $group) {
                if (! empty($group['label']) && ! empty($group['value'])) {
                    $item->add_meta_data(sanitize_text_field($group['label']), sanitize_text_field($group['value']), true);
                }
            }
        }
        if (! empty($values['special_requests'])) {
            $item->add_meta_data(__('Note to customer', 'rd-box-builder'), sanitize_text_field($values['special_requests']), true);
        }
        if (! empty($values['logo_upload'])) {
            $item->add_meta_data(__('Logo Upload', 'rd-box-builder'), esc_url_raw($values['logo_upload']), true);
        }
        if (! empty($values['additional_logos']) && is_array($values['additional_logos'])) {
            $item->add_meta_data(__('Additional Logos', 'rd-box-builder'), implode(', ', array_map('esc_url_raw', $values['additional_logos'])), true);
        }
    }
}
