<?php
/**
 * Box integrity safeguard.
 *
 * Guarantees a box always contains exactly its configured number of donuts, so a
 * "box of 12" can never reach a packing slip showing 11. WPC enforces the whole
 * quantity limit on the normal add-to-cart path, but boxes can still drift on
 * other paths (order-again, REST/Store API, admin edits, data migration). This
 * module is the independent backstop:
 *
 *   1. Cart guard  (woocommerce_check_cart_items): blocks checkout with a clear
 *      message if any box's donuts don't sum to its size. Pure validation — it
 *      never mutates the cart, so it can't introduce pricing bugs.
 *
 *   2. Order self-heal (after the order is created): the box's child donut lines
 *      are zero-priced (the parent bundle holds the price), so topping up / trimming
 *      a child quantity is total-safe. If a box is short or over, we reconcile the
 *      child line quantities back to the exact box size and leave an order note for
 *      staff. This directly fixes what the packing slip renders.
 *
 * The reconciliation maths lives in reconcile() — a pure, dependency-free function
 * that is unit tested.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Integrity {

    public static function init(): void {
        // Prevention: stop a mismatched box from being purchased.
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'audit_cart'), 5);

        // Self-heal: correct the created order so the packing slip is always right.
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'heal_order'), 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'heal_order'), 20, 1);
    }

    /* ------------------------------------------------------------- pure logic */

    /**
     * Adjust a set of flavour quantities so they sum to exactly the box size.
     *
     * Pure and dependency-free (no WordPress) so it can be unit tested in isolation.
     * Keys are preserved. When short, the shortfall is added to the largest flavour
     * ("add the missing donut(s) back" to the most-chosen flavour). When over, units
     * are trimmed from the largest flavour first, never below zero.
     *
     * @param array<int|string,int> $quantities Flavour quantities, keyed by id.
     * @param int                   $required   Exact box size (>= 0).
     * @return array<int|string,int> Adjusted quantities summing to $required.
     */
    public static function reconcile(array $quantities, int $required): array {
        $q = array();
        foreach ($quantities as $key => $value) {
            $q[$key] = max(0, (int) $value);
        }

        if ($required < 0) {
            $required = 0;
        }

        if (empty($q)) {
            return $q;
        }

        $sum = array_sum($q);

        if ($sum === $required) {
            return $q;
        }

        if ($sum < $required) {
            $key      = self::key_of_max($q);
            $q[$key] += ($required - $sum);

            return $q;
        }

        // Over the limit: trim from the largest flavour one unit at a time so the
        // remaining donuts stay as balanced as possible and never go negative.
        while ($sum > $required) {
            $key = self::key_of_max($q);
            if ($key === null || $q[$key] <= 0) {
                break;
            }
            $q[$key]--;
            $sum--;
        }

        return $q;
    }

    /** Key of the largest value (first key wins on a tie). */
    private static function key_of_max(array $q) {
        $max_key = null;
        $max_val = -1;
        foreach ($q as $key => $val) {
            if ($val > $max_val) {
                $max_val = $val;
                $max_key = $key;
            }
        }

        return $max_key;
    }

    /**
     * The exact donut count a box must contain, or 0 when it isn't a fixed-size
     * box (variable min/max boxes are left to WPC and skipped here).
     */
    public static function box_size($product): int {
        if (! $product instanceof WC_Product) {
            return 0;
        }
        $min = (int) $product->get_meta('woosb_limit_whole_min');
        $max = (int) $product->get_meta('woosb_limit_whole_max');

        return ($min > 0 && $min === $max) ? $max : 0;
    }

    /* ------------------------------------------------------------- cart guard */

    /** True for a box-builder bundle parent cart line (not a child donut line). */
    private static function is_cart_box_parent($cart_item): bool {
        if (class_exists('RD_Box_Builder_Cart_Edit')) {
            return RD_Box_Builder_Cart_Edit::is_box_parent($cart_item);
        }

        return is_array($cart_item)
            && empty($cart_item['woosb_parent_id'])
            && rd_box_builder_is_enabled((int) ($cart_item['product_id'] ?? 0));
    }

    /** Sum of the donut quantities currently in a given box (by parent cart key). */
    private static function cart_children_total(string $parent_key): int {
        $total = 0;
        if (! function_exists('WC') || ! WC()->cart) {
            return $total;
        }
        foreach (WC()->cart->get_cart() as $ci) {
            if (! empty($ci['woosb_parent_key']) && $ci['woosb_parent_key'] === $parent_key) {
                $total += (int) $ci['quantity'];
            }
        }

        return $total;
    }

    /**
     * Block checkout when a box's donuts don't add up to its size. Pure validation:
     * it surfaces a customer-facing error rather than mutating the cart.
     */
    public static function audit_cart(): void {
        if (! function_exists('WC') || ! WC()->cart) {
            return;
        }

        foreach (WC()->cart->get_cart() as $key => $item) {
            if (! self::is_cart_box_parent($item)) {
                continue;
            }

            $product = $item['data'] instanceof WC_Product ? $item['data'] : wc_get_product((int) $item['product_id']);
            $size    = self::box_size($product);
            if ($size <= 0) {
                continue;
            }

            $boxes    = max(1, (int) $item['quantity']);
            $expected = $size * $boxes;
            $actual   = self::cart_children_total((string) $key);

            if ($actual !== $expected && function_exists('wc_add_notice')) {
                wc_add_notice(
                    sprintf(
                        /* translators: 1: box name, 2: current donut count, 3: required donut count */
                        __('Your "%1$s" currently has %2$d of %3$d donuts. Please adjust your box to exactly %3$d donuts before checking out.', 'rd-box-builder'),
                        $product instanceof WC_Product ? $product->get_name() : __('box', 'rd-box-builder'),
                        $actual,
                        $expected
                    ),
                    'error'
                );
            }
        }
    }

    /* -------------------------------------------------------- order self-heal */

    /**
     * Inspect a created order and correct any box whose donut lines don't sum to
     * the box size. Child donut lines are zero-priced so quantity changes never
     * affect order totals.
     *
     * @param int|WC_Order $order_or_id
     */
    public static function heal_order($order_or_id): void {
        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order($order_or_id);
        if (! $order instanceof WC_Order) {
            return;
        }

        $changed = false;

        foreach (self::order_box_groups($order) as $group) {
            if ($group['expected'] <= 0 || $group['actual'] === $group['expected']) {
                continue;
            }

            // Ambiguous (the same box product appears on more than one line) or no
            // child lines to adjust: flag for manual review rather than guessing.
            if ($group['ambiguous'] || empty($group['children'])) {
                $order->add_order_note(sprintf(
                    /* translators: 1: box name, 2: current count, 3: required count */
                    __('Box builder integrity warning: "%1$s" has %2$d of %3$d donuts and could not be auto-corrected — please review manually.', 'rd-box-builder'),
                    $group['name'],
                    $group['actual'],
                    $group['expected']
                ));
                $changed = true;
                continue;
            }

            $current = array();
            foreach ($group['children'] as $cid => $child_item) {
                $current[$cid] = (int) $child_item->get_quantity();
            }

            $fixed = self::reconcile($current, $group['expected']);

            $notes = array();
            foreach ($fixed as $cid => $qty) {
                $child_item = $group['children'][$cid];
                $was        = (int) $child_item->get_quantity();
                if ($was === (int) $qty) {
                    continue;
                }
                $child_item->set_quantity((int) $qty);
                // Keep the line free — the parent bundle carries the price.
                $child_item->set_subtotal('0');
                $child_item->set_total('0');
                $child_item->save();
                $notes[] = sprintf('%s %+d', $child_item->get_name(), (int) $qty - $was);
            }

            $order->add_order_note(sprintf(
                /* translators: 1: box name, 2: original count, 3: corrected count, 4: per-flavour changes */
                __('Box builder auto-correction: "%1$s" had %2$d of %3$d donuts; restored to %3$d (%4$s).', 'rd-box-builder'),
                $group['name'],
                $group['actual'],
                $group['expected'],
                implode(', ', $notes)
            ));
            $changed = true;
        }

        if ($changed) {
            $order->save();
        }
    }

    /**
     * Report (without changing anything) any box in an order whose donut lines
     * don't sum to the box size. Used by the CLI and for dry-run checks.
     *
     * @param int|WC_Order $order_or_id
     * @return array<int, array{name:string, expected:int, actual:int, ambiguous:bool}>
     */
    public static function audit_order($order_or_id): array {
        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order($order_or_id);
        if (! $order instanceof WC_Order) {
            return array();
        }

        $issues = array();
        foreach (self::order_box_groups($order) as $group) {
            if ($group['expected'] > 0 && $group['actual'] !== $group['expected']) {
                $issues[] = array(
                    'name'      => $group['name'],
                    'expected'  => $group['expected'],
                    'actual'    => $group['actual'],
                    'ambiguous' => $group['ambiguous'],
                );
            }
        }

        return $issues;
    }

    /**
     * Group a box parent order line with its child donut lines.
     *
     * @return array<int, array{name:string, parent:WC_Order_Item_Product, children:array<int,WC_Order_Item_Product>, expected:int, actual:int, ambiguous:bool}>
     */
    private static function order_box_groups(WC_Order $order): array {
        $items = $order->get_items();

        // Count how many parent lines exist per box product, to detect ambiguity.
        $parents_by_product = array();
        foreach ($items as $item_id => $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ($item->get_meta('_woosb_parent_id')) {
                continue; // this is a child line
            }
            $product_id = (int) $item->get_product_id();
            if ($product_id > 0 && rd_box_builder_is_enabled($product_id)) {
                $parents_by_product[$product_id][] = $item_id;
            }
        }

        $groups = array();

        foreach ($parents_by_product as $product_id => $parent_ids) {
            $ambiguous = count($parent_ids) > 1;

            // Children link to their box by parent product id.
            $children = array();
            foreach ($items as $child_id => $child) {
                if ($child instanceof WC_Order_Item_Product
                    && (int) $child->get_meta('_woosb_parent_id') === $product_id) {
                    $children[$child_id] = $child;
                }
            }

            foreach ($parent_ids as $parent_id) {
                $parent   = $items[$parent_id];
                $product  = $parent->get_product();
                $size     = self::box_size($product);
                $boxes    = max(1, (int) $parent->get_quantity());
                $expected = $size * $boxes;

                $actual = 0;
                foreach ($children as $child) {
                    $actual += (int) $child->get_quantity();
                }

                $groups[] = array(
                    'name'      => $parent->get_name(),
                    'parent'    => $parent,
                    'children'  => $ambiguous ? array() : $children,
                    'expected'  => $expected,
                    'actual'    => $actual,
                    'ambiguous' => $ambiguous,
                );
            }
        }

        return $groups;
    }
}
