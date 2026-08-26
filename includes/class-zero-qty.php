<?php
/**
 * Drop unused (qty 0) bundled flavours from cart/order payloads and from the
 * "Bundled products" list WPC prints on order details.
 *
 * Box-builder catalogues keep every flavour on the parent bundle at qty 0 so
 * the picker has the full list. WPC's get_ids_str() serialises those zeros
 * onto the order, and the admin/email renderer prints every entry. Child line
 * items are already skipped when qty <= 0; this class makes the parent
 * summary match.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Zero_Qty {

    public static function init(): void {
        add_filter('woosb_get_ids_str', array(__CLASS__, 'strip_ids'));
        add_filter('woosb_clean_ids', array(__CLASS__, 'strip_ids'));
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'strip_cart_item_data'), 20);
        add_filter('woocommerce_get_cart_item_from_session', array(__CLASS__, 'strip_cart_item_data'), 20);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'strip_order_item_ids'), 20, 3);
        add_filter('woosb_admin_order_bundled_product_names', array(__CLASS__, 'filter_bundled_names'), 10, 2);
        add_filter('woosb_order_bundled_product_names', array(__CLASS__, 'filter_bundled_names'), 10, 2);
    }

    /**
     * Remove qty-0 entries from a WPC `woosb_ids` string or array.
     *
     * String forms handled (same rules as WPClever_Helper::get_bundled):
     *   id
     *   id/qty
     *   id/key/qty
     *   id/key/qty/attrs
     *
     * @param mixed $ids
     * @return mixed
     */
    public static function strip_ids($ids) {
        if (is_array($ids)) {
            $kept = array();
            foreach ($ids as $key => $item) {
                if (! is_array($item)) {
                    $kept[$key] = $item;
                    continue;
                }
                if ((float) ($item['qty'] ?? 0) > 0) {
                    $kept[$key] = $item;
                }
            }

            return $kept;
        }

        if (! is_string($ids) || $ids === '') {
            return $ids;
        }

        $kept = array();
        foreach (array_filter(explode(',', $ids)) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $data = explode('/', $segment);
            $qty  = 1.0;
            if (isset($data[1])) {
                if (is_numeric($data[1]) && ! isset($data[2])) {
                    $qty = (float) $data[1];
                } else {
                    $qty = (float) ($data[2] ?? 1);
                }
            }

            if ($qty > 0) {
                $kept[] = $segment;
            }
        }

        return implode(',', $kept);
    }

    /**
     * Rebuild WPC's "Bundled products" HTML without qty-0 rows.
     *
     * @param string               $html  Current markup (ul list or "; "-joined).
     * @param array<int|string,mixed> $items Bundled items from get_bundled().
     * @param callable|null        $title Optional id => name resolver (tests).
     */
    public static function filter_bundled_names($html, $items, $title = null): string {
        if (! is_array($items) || $items === array()) {
            return is_string($html) ? $html : '';
        }

        $has_zero = false;
        foreach ($items as $item) {
            if (is_array($item) && ! empty($item['id']) && (float) ($item['qty'] ?? 0) <= 0) {
                $has_zero = true;
                break;
            }
        }
        if (! $has_zero) {
            return is_string($html) ? $html : '';
        }

        if ($title === null) {
            $title = static function ($id) {
                return function_exists('get_the_title') ? (string) get_the_title($id) : '';
            };
        }

        $is_list = is_string($html) && str_contains($html, '<li>');
        $parts   = array();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $qty = $item['qty'] ?? 0;
            $id  = (int) ($item['id'] ?? 0);
            if ($id <= 0 || (float) $qty <= 0) {
                continue;
            }

            $name = (string) $title($id);
            $safe = function_exists('esc_html') ? esc_html($name) : $name;
            $line = $qty . ' × ' . $safe;
            $parts[] = $is_list ? '<li>' . $line . '</li>' : $line;
        }

        if ($is_list) {
            return '<ul>' . implode('', $parts) . '</ul>';
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string,mixed> $cart_item
     * @return array<string,mixed>
     */
    public static function strip_cart_item_data($cart_item) {
        if (is_array($cart_item) && ! empty($cart_item['woosb_ids'])) {
            $cart_item['woosb_ids'] = self::strip_ids($cart_item['woosb_ids']);
        }

        return $cart_item;
    }

    /**
     * @param mixed $item
     */
    public static function strip_order_item_ids($item, $cart_item_key, $values): void {
        if (! is_object($item) || ! method_exists($item, 'get_meta') || ! method_exists($item, 'update_meta_data')) {
            return;
        }

        $ids = $item->get_meta('_woosb_ids');
        if (! $ids) {
            return;
        }

        $item->update_meta_data('_woosb_ids', self::strip_ids($ids));
    }
}
