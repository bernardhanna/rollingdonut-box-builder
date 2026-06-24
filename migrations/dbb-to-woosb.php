<?php
/**
 * Migration: convert legacy `donut_box_builder` products into WPC Product
 * Bundles (`woosb`) configured for the new box builder.
 *
 * It replicates the old plugin's available-flavour logic (see the legacy theme
 * template woocommerce/content-single-product-donut-box-builder.php) to build
 * the bundle's optional items, uses `_prefilled_box_products` for default
 * quantities, sets a whole-quantity limit = box size, marks the bundle as
 * fixed-price, and enables the box builder flag.
 *
 * Reversible: stores `_rd_bb_orig_type` and `_rd_bb_migrated` so a product can
 * be rolled back to its original product type.
 *
 * Usage (WP-CLI):
 *   wp eval-file wp-content/plugins/rd-box-builder/migrations/dbb-to-woosb.php "1958,1959" --dry
 *   wp eval-file wp-content/plugins/rd-box-builder/migrations/dbb-to-woosb.php "1958,1959"
 *   wp eval-file wp-content/plugins/rd-box-builder/migrations/dbb-to-woosb.php rollback "1958"
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

/**
 * Replicate the legacy builder's available-variation selection for a product.
 *
 * @return int[] Variation IDs.
 */
function rd_bb_collect_available_variations(int $product_id): array {
    $disabled = get_post_meta($product_id, '_disabled_box_products', true) ?: array();
    $disabled = array_map('intval', (array) $disabled);
    $group    = get_post_meta($product_id, '_donut_group_selection', true);
    $size     = get_post_meta($product_id, '_donut_size_selection', true);
    $out      = array();

    $push_variations_by_size = static function (array $products, ?string $want_size) use (&$out, $disabled) {
        foreach ($products as $prod) {
            if (! $prod->is_type('variable')) {
                continue;
            }
            foreach ($prod->get_children() as $vid) {
                $vid = (int) $vid;
                if (in_array($vid, $disabled, true)) {
                    continue;
                }
                $vp = wc_get_product($vid);
                if (! $vp) {
                    continue;
                }
                if ($want_size && 'all' !== $want_size) {
                    $attrs = $vp->get_attributes();
                    if (! isset($attrs['pa_size']) || strtolower((string) $attrs['pa_size']) !== strtolower($want_size)) {
                        continue;
                    }
                }
                $out[] = $vid;
            }
        }
    };

    if ('all_donuts' === $group) {
        $products = wc_get_products(array(
            'status'    => 'publish',
            'limit'     => -1,
            'type'      => 'variable',
            'tax_query' => array(array('taxonomy' => 'rd_product_type', 'field' => 'slug', 'terms' => 'donut')),
        ));
        $push_variations_by_size($products, null);
    } elseif ('all_large_donuts' === $group || 'all_midi_donuts' === $group) {
        $want = ('all_large_donuts' === $group) ? 'large' : 'midi';
        $products = wc_get_products(array(
            'status'    => 'publish',
            'limit'     => -1,
            'type'      => 'variable',
            'tax_query' => array(array('taxonomy' => 'rd_product_type', 'field' => 'slug', 'terms' => 'donut')),
        ));
        $push_variations_by_size($products, $want);
    } elseif ('category' === $group) {
        $cat = get_post_meta($product_id, '_donut_category_selection', true);
        if ($cat) {
            $products = wc_get_products(array(
                'status'    => 'publish',
                'limit'     => -1,
                'type'      => 'variable',
                'tax_query' => array(array('taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $cat)),
            ));
            $push_variations_by_size($products, $size);
        }
    } else {
        // selected_flavours_only / none / default -> manual custom list.
        $selected = get_post_meta($product_id, '_custom_box_products', true) ?: array();
        foreach ((array) $selected as $vid) {
            $vid = (int) $vid;
            if (in_array($vid, $disabled, true)) {
                continue;
            }
            if (wc_get_product($vid)) {
                $out[] = $vid;
            }
        }
    }

    return array_values(array_unique(array_map('intval', $out)));
}

/**
 * Build a woosb_ids structure for a legacy box product.
 *
 * @return array{woosb_ids: array, box_qty: int, available: int, default_sum: int}
 */
function rd_bb_build_bundle_items(int $product_id): array {
    $available = rd_bb_collect_available_variations($product_id);
    $prefilled = get_post_meta($product_id, '_prefilled_box_products', true) ?: array();
    $box_qty   = (int) get_post_meta($product_id, '_donut_box_builder_box_quantity', true);

    $defaults = array();
    foreach ((array) $prefilled as $vid) {
        $vid = (int) $vid;
        $defaults[$vid] = ($defaults[$vid] ?? 0) + 1;
    }

    // Union of available + any prefilled not already present.
    $ids = $available;
    foreach (array_keys($defaults) as $vid) {
        if (! in_array($vid, $ids, true)) {
            $ids[] = $vid;
        }
    }

    $woosb_ids = array();
    foreach ($ids as $vid) {
        if (! wc_get_product($vid)) {
            continue;
        }
        $key = substr(md5($vid . '-' . wp_rand()), 0, 4);
        $woosb_ids[$key] = array(
            'id'       => (string) $vid,
            'sku'      => '',
            'qty'      => (string) ($defaults[$vid] ?? 0),
            'min'      => '0',
            'max'      => $box_qty ? (string) $box_qty : '',
            'optional' => 'on',
        );
    }

    return array(
        'woosb_ids'   => $woosb_ids,
        'box_qty'     => $box_qty,
        'available'   => count($available),
        'default_sum' => array_sum($defaults),
    );
}

function rd_bb_migrate_product(int $product_id, bool $dry = false): array {
    $p = wc_get_product($product_id);
    if (! $p) {
        return array('id' => $product_id, 'status' => 'missing');
    }
    if ($p->is_type('woosb')) {
        return array('id' => $product_id, 'status' => 'already-woosb', 'slug' => $p->get_slug());
    }

    $built = rd_bb_build_bundle_items($product_id);

    $row = array(
        'id'          => $product_id,
        'slug'        => $p->get_slug(),
        'from_type'   => $p->get_type(),
        'box_qty'     => $built['box_qty'],
        'available'   => $built['available'],
        'items'       => count($built['woosb_ids']),
        'default_sum' => $built['default_sum'],
        'price'       => $p->get_price(),
    );

    if ($dry) {
        $row['status'] = 'dry-run';
        return $row;
    }

    if (empty($built['woosb_ids']) || $built['box_qty'] <= 0) {
        $row['status'] = 'skipped-no-items-or-qty';
        return $row;
    }

    update_post_meta($product_id, '_rd_bb_orig_type', $p->get_type());
    update_post_meta($product_id, '_rd_bb_migrated', current_time('mysql'));

    wp_set_object_terms($product_id, 'woosb', 'product_type');
    update_post_meta($product_id, 'woosb_ids', $built['woosb_ids']);
    update_post_meta($product_id, 'woosb_disable_auto_price', 'on');
    update_post_meta($product_id, 'woosb_limit_whole_min', $built['box_qty']);
    update_post_meta($product_id, 'woosb_limit_whole_max', $built['box_qty']);
    update_post_meta($product_id, '_rd_enable_box_builder', 'yes');

    wc_delete_product_transients($product_id);
    clean_post_cache($product_id);

    $row['status'] = 'migrated';
    return $row;
}

function rd_bb_rollback_product(int $product_id): array {
    $orig = get_post_meta($product_id, '_rd_bb_orig_type', true);
    if (! $orig) {
        return array('id' => $product_id, 'status' => 'no-rollback-info');
    }
    wp_set_object_terms($product_id, $orig, 'product_type');
    delete_post_meta($product_id, '_rd_enable_box_builder');
    wc_delete_product_transients($product_id);
    clean_post_cache($product_id);
    return array('id' => $product_id, 'status' => 'rolled-back', 'to_type' => $orig);
}

// ----------------------------------------------------------------- CLI runner

if (defined('WP_CLI') && WP_CLI) {
    $args = isset($args) && is_array($args) ? $args : array();

    $mode = 'migrate';
    $dry  = false;
    $ids  = array();

    foreach ($args as $a) {
        if ('--dry' === $a || 'dry' === $a) {
            $dry = true;
        } elseif ('rollback' === $a) {
            $mode = 'rollback';
        } else {
            foreach (explode(',', $a) as $piece) {
                $piece = trim($piece);
                if (is_numeric($piece)) {
                    $ids[] = (int) $piece;
                }
            }
        }
    }

    if (empty($ids)) {
        WP_CLI::error('Provide product IDs, e.g. "1958,1959".');
    }

    $results = array();
    foreach ($ids as $id) {
        $results[] = ('rollback' === $mode) ? rd_bb_rollback_product($id) : rd_bb_migrate_product($id, $dry);
    }

    WP_CLI\Utils\format_items('table', $results, array_keys($results[0]));
}
