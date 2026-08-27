<?php
/**
 * WP-CLI commands for the box builder.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

/**
 * Manage Rolling Donut box-builder data integrity.
 */
class RD_Box_Builder_CLI {

    /**
     * Scan orders for boxes whose donut count doesn't match the box size, and
     * optionally repair them (top up / trim the zero-priced donut lines).
     *
     * Use this to clean up historical orders that drifted before the live
     * safeguards were in place — new orders are corrected automatically at checkout.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Only report mismatches; do not modify any order.
     *
     * [--limit=<number>]
     * : How many recent orders to scan. Default: 500.
     *
     * [--status=<status>]
     * : Comma-separated order statuses to scan. Default: all.
     *
     * ## EXAMPLES
     *
     *     wp rd-box-builder heal_orders --dry-run
     *     wp rd-box-builder heal_orders --limit=1000
     *
     * @when after_wp_load
     */
    public function heal_orders($args, $assoc_args): void {
        $dry_run = isset($assoc_args['dry-run']);
        $limit   = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 500;
        $status  = isset($assoc_args['status']) ? array_map('trim', explode(',', (string) $assoc_args['status'])) : array_keys(wc_get_order_statuses());

        $order_ids = wc_get_orders(array(
            'limit'  => $limit,
            'type'   => 'shop_order',
            'status' => $status,
            'return' => 'ids',
        ));

        $scanned  = 0;
        $affected = 0;

        foreach ($order_ids as $order_id) {
            $scanned++;
            $issues = RD_Box_Builder_Integrity::audit_order($order_id);
            if (empty($issues)) {
                continue;
            }

            $affected++;
            foreach ($issues as $issue) {
                WP_CLI::log(sprintf(
                    'Order #%d — "%s": %d/%d donuts%s',
                    $order_id,
                    $issue['name'],
                    $issue['actual'],
                    $issue['expected'],
                    $issue['ambiguous'] ? ' (ambiguous — manual review)' : ''
                ));
            }

            if (! $dry_run) {
                RD_Box_Builder_Integrity::heal_order($order_id);
            }
        }

        WP_CLI::success(sprintf(
            '%s %d order(s); %d had box mismatches%s.',
            $dry_run ? 'Scanned' : 'Scanned + healed',
            $scanned,
            $affected,
            $dry_run ? ' (dry run, nothing changed)' : ''
        ));
    }

    /**
     * Drop unpublished / unpurchasable flavours from every box-builder bundle.
     *
     * Quantities on retired flavours are moved onto the remaining mix so a set
     * box of 12 stays a set box of 12.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be removed; do not write.
     *
     * ## EXAMPLES
     *
     *     wp rd-box-builder prune_unavailable --dry-run
     *     wp rd-box-builder prune_unavailable
     *
     * @when after_wp_load
     * @subcommand prune_unavailable
     */
    public function prune_unavailable($args, $assoc_args): void {
        $dry_run = isset($assoc_args['dry-run']);
        $boxes   = RD_Box_Builder_Bundle_Guard::enabled_box_ids();
        $changed = 0;

        foreach ($boxes as $box_id) {
            $drop = RD_Box_Builder_Bundle_Guard::prune_box_report($box_id);
            if ($drop === array()) {
                continue;
            }

            $changed++;
            $names = array();
            foreach ($drop as $vid) {
                $product = wc_get_product($vid);
                $names[] = $product instanceof WC_Product ? $product->get_name() : ('#' . $vid);
            }

            $box = wc_get_product($box_id);
            WP_CLI::log(sprintf(
                '#%d %s — dropping %s',
                $box_id,
                $box instanceof WC_Product ? $box->get_name() : '(unknown)',
                implode(', ', $names)
            ));

            if (! $dry_run) {
                RD_Box_Builder_Bundle_Guard::prune_box($box_id);
            }
        }

        WP_CLI::success(sprintf(
            '%s %d box-builder product(s); %d had retired flavours%s.',
            $dry_run ? 'Scanned' : 'Pruned',
            count($boxes),
            $changed,
            $dry_run ? ' (dry run, nothing changed)' : ''
        ));
    }

    /**
     * Show box-builder usage counts (opens + adds) per enabled product.
     *
     * "Opens" is how many times shoppers clicked "Build Your Own Box"; "Adds" is
     * how many configured boxes were added to a basket. Both are tracked
     * first-party (no third-party service) in product meta.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. One of table, csv, json, yaml. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp rd-box-builder stats
     *     wp rd-box-builder stats --format=csv
     *
     * @when after_wp_load
     * @subcommand stats
     */
    public function stats($args, $assoc_args): void {
        $format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';

        $product_ids = wc_get_products(array(
            'status'     => 'publish',
            'limit'      => -1,
            'type'       => 'woosb',
            'return'     => 'ids',
            'meta_key'   => '_rd_enable_box_builder',
            'meta_value' => 'yes',
        ));

        $rows = array();
        foreach ((array) $product_ids as $product_id) {
            $product_id = (int) $product_id;
            $product    = wc_get_product($product_id);

            $rows[] = array(
                'id'    => $product_id,
                'name'  => $product instanceof WC_Product ? $product->get_name() : '(unknown)',
                'opens' => RD_Box_Builder_Stats::get_count($product_id, RD_Box_Builder_Stats::META_OPENS),
                'adds'  => RD_Box_Builder_Stats::get_count($product_id, RD_Box_Builder_Stats::META_ADDS),
            );
        }

        if (empty($rows)) {
            WP_CLI::warning('No box-builder-enabled products found.');

            return;
        }

        usort($rows, static function ($a, $b) {
            return $b['opens'] <=> $a['opens'];
        });

        WP_CLI\Utils\format_items($format, $rows, array('id', 'name', 'opens', 'adds'));
    }
}
