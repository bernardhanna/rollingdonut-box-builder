<?php
/**
 * First-party usage tracking for the box builder.
 *
 * Counts two per-product events with no third-party service:
 *   - "open": the customer clicked "Build Your Own Box" to enter builder mode.
 *   - "add":  the customer added a configured box to the basket (incl. Buy Now).
 *
 * Counts live in two post-meta keys on the bundle product (_rd_bb_opens /
 * _rd_bb_adds) so they survive without an extra table and surface as sortable
 * columns on the Products admin screen. Increments go through a single atomic
 * SQL statement so concurrent shoppers can never clobber each other's count.
 *
 * Everything is additive and gated by rd_box_builder_is_enabled(); deleting this
 * file simply stops the counting and removes the admin columns.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Stats {

    const TRACK      = 'rd_bb_track';
    const META_OPENS = '_rd_bb_opens';
    const META_ADDS  = '_rd_bb_adds';

    public static function init(): void {
        add_action('wp_ajax_' . self::TRACK, array(__CLASS__, 'ajax_track'));
        add_action('wp_ajax_nopriv_' . self::TRACK, array(__CLASS__, 'ajax_track'));

        add_filter('manage_product_posts_columns', array(__CLASS__, 'add_columns'));
        add_action('manage_product_posts_custom_column', array(__CLASS__, 'render_column'), 10, 2);
        add_filter('manage_edit-product_sortable_columns', array(__CLASS__, 'sortable_columns'));
        add_action('pre_get_posts', array(__CLASS__, 'sort_by_column'));
    }

    /**
     * Record one usage event for a box-builder product.
     *
     * Reuses the cart nonce (already localised on the product page) and only
     * counts events for products that actually have the builder enabled, so a
     * spoofed product_id can't inflate an unrelated product's counter.
     */
    public static function ajax_track(): void {
        check_ajax_referer(RD_Box_Builder_Cart::NONCE, 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $event      = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';

        $meta_key = self::meta_key_for_event($event);

        if (! $product_id || null === $meta_key || ! rd_box_builder_is_enabled($product_id)) {
            wp_send_json_error(array('message' => 'invalid'), 400);
        }

        self::increment($product_id, $meta_key);

        wp_send_json_success(array('event' => $event));
    }

    /** Map a tracked event name to its storage meta key (null = unknown event). */
    private static function meta_key_for_event(string $event): ?string {
        switch ($event) {
            case 'open':
                return self::META_OPENS;
            case 'add':
                return self::META_ADDS;
            default:
                return null;
        }
    }

    /**
     * Atomically add 1 to a numeric post-meta counter.
     *
     * A single `meta_value = meta_value + 1` UPDATE is race-safe across
     * concurrent requests. When the row doesn't exist yet the UPDATE matches
     * nothing, so we seed it; if another request seeded it first we retry the
     * UPDATE so no hit is lost.
     */
    private static function increment(int $product_id, string $meta_key): void {
        global $wpdb;

        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
            $product_id,
            $meta_key
        );

        if (0 === (int) $wpdb->query($sql)) {
            if (false === add_post_meta($product_id, $meta_key, 1, true)) {
                // Lost the race to create it; the row now exists, so increment.
                $wpdb->query($sql);
            }
        }

        // The direct SQL bypasses WP's meta cache, so drop the stale entry.
        wp_cache_delete($product_id, 'post_meta');
    }

    /** Read a counter as an integer. */
    public static function get_count(int $product_id, string $meta_key): int {
        return (int) get_post_meta($product_id, $meta_key, true);
    }

    /* ------------------------------------------------------------- admin list */

    /**
     * Append "Box opens" / "Box adds" columns to the Products list table,
     * placed just before the date column when present.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function add_columns(array $columns): array {
        $insert = array(
            'rd_bb_opens' => __('Box opens', 'rd-box-builder'),
            'rd_bb_adds'  => __('Box adds', 'rd-box-builder'),
        );

        if (! isset($columns['date'])) {
            return array_merge($columns, $insert);
        }

        $out = array();
        foreach ($columns as $key => $label) {
            if ('date' === $key) {
                $out += $insert;
            }
            $out[$key] = $label;
        }

        return $out;
    }

    /** Render a counter cell; non-builder products show an em dash. */
    public static function render_column(string $column, int $post_id): void {
        if ('rd_bb_opens' !== $column && 'rd_bb_adds' !== $column) {
            return;
        }

        if (! rd_box_builder_is_enabled($post_id)) {
            echo '&mdash;';

            return;
        }

        $meta_key = 'rd_bb_opens' === $column ? self::META_OPENS : self::META_ADDS;

        echo esc_html(number_format_i18n(self::get_count($post_id, $meta_key)));
    }

    /**
     * Make both columns sortable.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function sortable_columns(array $columns): array {
        $columns['rd_bb_opens'] = 'rd_bb_opens';
        $columns['rd_bb_adds']  = 'rd_bb_adds';

        return $columns;
    }

    /** Translate an orderby on our columns into a numeric meta sort. */
    public static function sort_by_column(WP_Query $query): void {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');
        $map     = array(
            'rd_bb_opens' => self::META_OPENS,
            'rd_bb_adds'  => self::META_ADDS,
        );

        if (! isset($map[$orderby])) {
            return;
        }

        $query->set('meta_key', $map[$orderby]);
        $query->set('orderby', 'meta_value_num');
    }
}
