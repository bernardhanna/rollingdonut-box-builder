<?php
/**
 * Keep box-builder bundle contents intact across admin saves, and drop flavours
 * that are no longer for sale without emptying the rest of the box.
 *
 * WPC Product Bundles saves `woosb_ids` from the product form. When that field
 * is missing (quick/bulk edit) or truncated (`max_input_vars` on a large flavour
 * list), WPC deletes or shortens the bundle. This guard snapshots the list
 * before WPC writes, restores it when the POST was incomplete, then prunes
 * unpublished flavours while preserving the pre-filled mix.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Bundle_Guard {

    private const NOTICE_TRANSIENT = 'rd_bb_bundle_restored';

    /**
     * @var array<int, mixed>
     */
    private static array $snapshots = array();

    public static function init(): void {
        add_action('woocommerce_process_product_meta_woosb', array(__CLASS__, 'snapshot'), 1);
        add_action('woocommerce_process_product_meta_woosb', array(__CLASS__, 'restore_if_clobbered'), 20);
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'prune_saved_box'), 110);
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'ensure_optional_on_save'), 120);
        add_filter('woosb_get_items', array(__CLASS__, 'force_optional_items'), 10, 2);
        add_filter('woosb_item_exclude', array(__CLASS__, 'exclude_unavailable'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'maybe_show_restored_notice'));
    }

    /**
     * Remember the bundle as it was before WPC overwrites it.
     */
    public static function snapshot(int $post_id): void {
        self::$snapshots[$post_id] = get_post_meta($post_id, 'woosb_ids', true);
    }

    /**
     * Undo a WPC save that dropped the bundle because POST was incomplete.
     */
    public static function restore_if_clobbered(int $post_id): void {
        if (! array_key_exists($post_id, self::$snapshots)) {
            return;
        }

        $previous = self::$snapshots[$post_id];
        $posted   = array_key_exists('woosb_ids', $_POST) ? wp_unslash($_POST['woosb_ids']) : null;
        $max_vars = (int) ini_get('max_input_vars');
        $leaves   = rd_box_builder_count_leaf_vars($_POST);

        if (! rd_box_builder_posted_bundle_was_clobbered($previous, $posted, $leaves, $max_vars)) {
            return;
        }

        update_post_meta($post_id, 'woosb_ids', $previous);
        wc_delete_product_transients($post_id);
        clean_post_cache($post_id);

        if (function_exists('get_current_user_id')) {
            set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), $post_id, MINUTE_IN_SECONDS);
        }
    }

    /**
     * After a box product is saved, drop flavours that are no longer for sale.
     */
    public static function prune_saved_box(int $post_id): void {
        self::prune_box($post_id);
    }

    /**
     * Remove unpublished / unpurchasable flavours from a box-builder bundle.
     *
     * Qty on dropped rows is moved onto the remaining mix so a set box of 12
     * stays a set box of 12.
     */
    public static function prune_box(int $box_id): bool {
        $product = wc_get_product($box_id);
        if (! $product instanceof WC_Product || ! $product->is_type('woosb')) {
            return false;
        }
        if ('yes' !== $product->get_meta('_rd_enable_box_builder')) {
            return false;
        }

        $existing = get_post_meta($box_id, 'woosb_ids', true);
        $drop     = rd_box_builder_unavailable_ids_in_bundle($existing);
        if ($drop === array()) {
            return false;
        }

        $next = rd_box_builder_force_optional_on_items(
            rd_box_builder_prune_woosb_ids(is_array($existing) ? $existing : array(), $drop)
        );
        update_post_meta($box_id, 'woosb_ids', $next);
        wc_delete_product_transients($box_id);
        clean_post_cache($box_id);

        return true;
    }

    /**
     * @return int[] Ids of products that were removed from this box.
     */
    public static function prune_box_report(int $box_id): array {
        $product = wc_get_product($box_id);
        if (! $product instanceof WC_Product || ! $product->is_type('woosb')) {
            return array();
        }
        if ('yes' !== $product->get_meta('_rd_enable_box_builder')) {
            return array();
        }

        $existing = get_post_meta($box_id, 'woosb_ids', true);

        return rd_box_builder_unavailable_ids_in_bundle($existing);
    }

    /**
     * Keep Custom quantity ticked after a product Update. WPC only persists
     * `optional` from checked checkboxes; `'on'` looks unticked so a save would
     * otherwise strip it and empty the flavour picker.
     */
    public static function ensure_optional_on_save(int $post_id): void {
        self::ensure_optional($post_id);
    }

    public static function ensure_optional(int $box_id): bool {
        $product = wc_get_product($box_id);
        if (! $product instanceof WC_Product || ! rd_box_builder_is_enabled($product)) {
            return false;
        }

        $existing = get_post_meta($box_id, 'woosb_ids', true);
        if (! is_array($existing) || $existing === array()) {
            return false;
        }

        $next = rd_box_builder_force_optional_on_items($existing);
        if ($next === $existing) {
            return false;
        }

        update_post_meta($box_id, 'woosb_ids', $next);
        wc_delete_product_transients($box_id);
        clean_post_cache($box_id);

        return true;
    }

    /**
     * Storefront: always treat box-builder flavours as optional so WPC renders
     * the `.woosb-qty` inputs the picker drives, even if meta was saved without
     * the checkbox.
     *
     * @param mixed $items
     * @param mixed $product
     * @return mixed
     */
    public static function force_optional_items($items, $product) {
        if (! is_array($items) || ! rd_box_builder_is_enabled($product)) {
            return $items;
        }

        return rd_box_builder_force_optional_on_items($items);
    }

    /**
     * Hide retired flavours on the storefront even before the bundle meta is pruned.
     *
     * @param bool       $exclude
     * @param WC_Product $product Bundled flavour.
     * @param WC_Product $bundle  Parent box.
     */
    public static function exclude_unavailable($exclude, $product, $bundle): bool {
        if ($exclude) {
            return true;
        }
        if (! $bundle instanceof WC_Product || ! rd_box_builder_is_enabled($bundle)) {
            return (bool) $exclude;
        }

        return ! rd_box_builder_flavour_is_available($product);
    }

    /**
     * @return int[] Box-builder product ids.
     */
    public static function enabled_box_ids(): array {
        $query = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => '_rd_enable_box_builder',
            'meta_value'     => 'yes',
        ));

        return array_map('intval', $query->posts);
    }

    public static function maybe_show_restored_notice(): void {
        if (! function_exists('get_current_user_id')) {
            return;
        }

        $key     = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
        $post_id = get_transient($key);
        if (! $post_id) {
            return;
        }

        delete_transient($key);

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo esc_html__('Box contents were preserved because the product form was incomplete (too many fields for PHP to save). Unpublished flavours are still removed automatically — you do not need to delete them from Bundled Products.', 'rd-box-builder');
        echo '</p></div>';
    }
}
