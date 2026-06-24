<?php
/**
 * Shared helpers for the Rolling Donut Box Builder.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

/**
 * Whether the box-builder UI should be applied to a given product.
 *
 * A product qualifies when it is a WPC bundle (`woosb`) AND has been flagged
 * via the per-product "Enable Box Builder" checkbox.
 *
 * @param WC_Product|int|null $product Product or product ID. Defaults to global.
 */
function rd_box_builder_is_enabled($product = null): bool {
    if (null === $product) {
        $product = $GLOBALS['product'] ?? null;
    }

    if (is_numeric($product)) {
        $product = wc_get_product($product);
    }

    if (! $product instanceof WC_Product || ! $product->is_type('woosb')) {
        return false;
    }

    return 'yes' === $product->get_meta('_rd_enable_box_builder');
}

/**
 * The auto-fill group modes that mean "this box's items are managed for you".
 *
 * "none" (Selected Flavours Only) is intentionally excluded: in that mode the
 * shop manager curates the bundle items by hand in WPC's Bundled Products tab
 * and the builder never rewrites them.
 *
 * @return string[]
 */
function rd_box_builder_managed_groups(): array {
    return array('all_donuts', 'all_large_donuts', 'all_midi_donuts', 'category');
}

/**
 * Query all published, variable donut products (rd_product_type = donut).
 *
 * @return WC_Product[]
 */
function rd_box_builder_query_donut_products(): array {
    $products = wc_get_products(array(
        'status'    => 'publish',
        'limit'     => -1,
        'type'      => 'variable',
        'tax_query' => array(
            array('taxonomy' => 'rd_product_type', 'field' => 'slug', 'terms' => 'donut'),
        ),
    ));

    return is_array($products) ? $products : array();
}

/**
 * Flat list of every donut variation, keyed by variation id => display name,
 * sorted naturally. Powers the "Disable flavours" picker in the admin.
 *
 * @return array<int, string>
 */
function rd_box_builder_donut_variations(): array {
    $out = array();

    foreach (rd_box_builder_query_donut_products() as $product) {
        if (! $product instanceof WC_Product || ! $product->is_type('variable')) {
            continue;
        }
        foreach ($product->get_children() as $vid) {
            $vp = wc_get_product((int) $vid);
            if ($vp instanceof WC_Product) {
                $out[(int) $vid] = $vp->get_name();
            }
        }
    }

    asort($out, SORT_NATURAL | SORT_FLAG_CASE);

    return $out;
}

/**
 * Resolve a quick-fill selection into the variation ids it covers.
 *
 * Mirrors the legacy box-builder-woo logic (and the dbb-to-woosb migration):
 *   - all_donuts        -> every donut variation
 *   - all_large_donuts  -> donut variations with pa_size = large
 *   - all_midi_donuts   -> donut variations with pa_size = midi
 *   - category          -> variations of products in $category, optional size
 *   - anything else     -> [] (manual / "Selected Flavours Only" mode)
 *
 * @param int[] $disabled Variation ids to exclude.
 * @return int[] Variation ids.
 */
function rd_box_builder_resolve_variation_ids(string $group, string $size = 'all', string $category = '', array $disabled = array()): array {
    $disabled = array_map('intval', $disabled);
    $out      = array();

    $push = static function (array $products, ?string $want_size) use (&$out, $disabled) {
        foreach ($products as $prod) {
            if (! $prod instanceof WC_Product || ! $prod->is_type('variable')) {
                continue;
            }
            foreach ($prod->get_children() as $vid) {
                $vid = (int) $vid;
                if (in_array($vid, $disabled, true)) {
                    continue;
                }
                $vp = wc_get_product($vid);
                if (! $vp instanceof WC_Product) {
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
        $push(rd_box_builder_query_donut_products(), null);
    } elseif ('all_large_donuts' === $group) {
        $push(rd_box_builder_query_donut_products(), 'large');
    } elseif ('all_midi_donuts' === $group) {
        $push(rd_box_builder_query_donut_products(), 'midi');
    } elseif ('category' === $group && '' !== $category) {
        $products = wc_get_products(array(
            'status'    => 'publish',
            'limit'     => -1,
            'type'      => 'variable',
            'tax_query' => array(
                array('taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $category),
            ),
        ));
        $push(is_array($products) ? $products : array(), $size);
    }

    return array_values(array_unique(array_map('intval', $out)));
}

/**
 * Build a WPC `woosb_ids` structure for the given variation ids, preserving any
 * existing per-item settings (default qty, custom min, item key) so a refresh
 * never wipes pre-filled defaults a manager has set.
 *
 * Items are forced "optional" (so WPC renders the editable quantity inputs the
 * builder skin needs) and their max is clamped to the box's whole-quantity limit
 * when one is configured.
 *
 * @param int[]              $variation_ids Resolved variation ids, in order.
 * @param array|mixed        $existing       Current woosb_ids meta (if any).
 * @param int                $whole_max      Box size (woosb_limit_whole_max), 0 if unset.
 * @return array<string, array<string, string>>
 */
function rd_box_builder_build_woosb_ids(array $variation_ids, $existing, int $whole_max): array {
    $by_id = array();
    if (is_array($existing)) {
        foreach ($existing as $key => $item) {
            if (is_array($item) && isset($item['id'])) {
                $by_id[(int) $item['id']] = array('key' => (string) $key, 'item' => $item);
            }
        }
    }

    $max = $whole_max > 0 ? (string) $whole_max : '';
    $woosb = array();

    foreach ($variation_ids as $vid) {
        $vid = (int) $vid;
        if (! wc_get_product($vid)) {
            continue;
        }

        if (isset($by_id[$vid])) {
            $key  = $by_id[$vid]['key'];
            $item = $by_id[$vid]['item'];
            $item['id']       = (string) $vid;
            $item['sku']      = isset($item['sku']) ? (string) $item['sku'] : '';
            $item['qty']      = isset($item['qty']) ? (string) $item['qty'] : '0';
            $item['min']      = isset($item['min']) ? (string) $item['min'] : '0';
            $item['max']      = '' !== $max ? $max : (isset($item['max']) ? (string) $item['max'] : '');
            $item['optional'] = 'on';
            $woosb[$key]      = $item;
            continue;
        }

        $key         = substr(md5($vid . '-' . wp_rand()), 0, 4);
        $woosb[$key] = array(
            'id'       => (string) $vid,
            'sku'      => '',
            'qty'      => '0',
            'min'      => '0',
            'max'      => $max,
            'optional' => 'on',
        );
    }

    return $woosb;
}

/**
 * Placeholder image used for empty box slots.
 *
 * Defaults to a bundled SVG; filterable so a site can point it at a custom asset.
 */
function rd_box_builder_placeholder_url(): string {
    $uploads = wp_get_upload_dir();
    $default = RD_BB_URL . 'assets/img/placeholder.svg';

    // Prefer the site's donut placeholder when present.
    if (empty($uploads['error'])) {
        $relative = '/2024/04/Donuts.svg';
        if (file_exists($uploads['basedir'] . $relative)) {
            $default = $uploads['baseurl'] . $relative;
        }
    }

    return (string) apply_filters('rd_box_builder_placeholder_url', $default);
}

/**
 * Build product-category data for a bundle's items, used to power the picker's
 * category filter. Variations resolve to their parent product's categories.
 *
 * @return array{categories: array<int, array{slug:string, name:string}>, itemCats: array<string, string[]>}
 */
function rd_box_builder_category_data(WC_Product $product): array {
    $empty = array('categories' => array(), 'itemCats' => array());

    if (! $product->is_type('woosb') || ! method_exists($product, 'get_items')) {
        return $empty;
    }

    $names     = array(); // slug => name
    $item_cats = array(); // item id (string) => [slugs]

    // Broad/duplicate categories that should not appear as filter chips.
    $exclude = (array) apply_filters('rd_box_builder_excluded_categories', array('all', 'uncategorized'));

    foreach ((array) $product->get_items() as $item) {
        $id = isset($item['id']) ? (int) $item['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $child = wc_get_product($id);
        if (! $child instanceof WC_Product) {
            continue;
        }

        $source_id = $child->is_type('variation') ? $child->get_parent_id() : $id;
        $terms     = get_the_terms($source_id, 'product_cat');
        $slugs     = array();

        if ($terms && ! is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (in_array($term->slug, $exclude, true)) {
                    continue;
                }
                $slugs[] = $term->slug;
                if (! isset($names[$term->slug])) {
                    $names[$term->slug] = $term->name;
                }
            }
        }

        $item_cats[(string) $id] = $slugs;
    }

    $categories = array();
    foreach ($names as $slug => $name) {
        $categories[] = array('slug' => $slug, 'name' => $name);
    }

    usort($categories, static function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    return array('categories' => $categories, 'itemCats' => $item_cats);
}

/**
 * Resolve a product's allergens (ACF `product_allergens`) to a display list of
 * name + icon, mirroring the theme's donut-card allergen panel.
 *
 * @return array<int, array{name:string, icon:string}>
 */
function rd_box_builder_allergens(int $product_id): array {
    if (! function_exists('get_field')) {
        return array();
    }

    $raw = get_field('product_allergens', $product_id);
    if (empty($raw)) {
        return array();
    }

    if (function_exists('matrix_rd_acf_posts')) {
        $posts = matrix_rd_acf_posts($raw);
    } else {
        $posts = is_array($raw) ? $raw : array($raw);
    }

    $out = array();
    foreach ($posts as $post) {
        if (! $post instanceof WP_Post || $post->post_title === '') {
            continue;
        }
        $icon  = get_the_post_thumbnail_url($post->ID, 'thumbnail');
        $out[] = array(
            'name' => $post->post_title,
            'icon' => $icon ? $icon : '',
        );
    }

    return $out;
}

/**
 * Build per-item display metadata (clean name without the size variation suffix,
 * plus allergens) keyed by the bundle item id used in the DOM.
 *
 * @return array<string, array{name:string, allergens: array<int, array{name:string, icon:string}>}>
 */
function rd_box_builder_items_meta(WC_Product $product): array {
    $meta = array();

    if (! $product->is_type('woosb') || ! method_exists($product, 'get_items')) {
        return $meta;
    }

    foreach ((array) $product->get_items() as $item) {
        $id = isset($item['id']) ? (int) $item['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $child = wc_get_product($id);
        if (! $child instanceof WC_Product) {
            continue;
        }

        if ($child->is_type('variation')) {
            $parent_id = $child->get_parent_id();
            $parent    = wc_get_product($parent_id);
            $name      = $parent instanceof WC_Product ? $parent->get_title() : $child->get_name();
        } else {
            $parent_id = $id;
            $parent    = $child;
            $name      = $child->get_title();
        }

        // Lifetime sales of the flavour, used to power the "Most popular" sort.
        $sales = $parent instanceof WC_Product ? (int) $parent->get_total_sales() : 0;

        $meta[(string) $id] = array(
            'name'      => $name,
            'sales'     => $sales,
            'allergens' => rd_box_builder_allergens($parent_id),
        );
    }

    return $meta;
}

/**
 * How many flavours to flag as best sellers / trending. Filterable so a site can
 * widen or narrow the badge without touching code.
 */
function rd_box_builder_badge_count(string $context): int {
    $default = 5;

    return max(0, (int) apply_filters('rd_box_builder_badge_count', $default, $context));
}

/**
 * Top-selling donut product IDs by all-time WooCommerce sales (`total_sales`).
 *
 * Scoped to the `donut` rd_product_type so high-volume boxes/merch never crowd
 * out the flavour rankings. Cached for a day because sales totals barely move
 * within a single day and the box builder renders on every product view.
 *
 * @return int[] Parent product IDs.
 */
function rd_box_builder_bestseller_ids(): array {
    $count = rd_box_builder_badge_count('bestseller');
    if ($count <= 0) {
        return array();
    }

    $cache_key = 'rd_bb_bestseller_ids_' . $count;
    $cached    = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $ids = wc_get_products(array(
        'status'   => 'publish',
        'limit'    => $count,
        'orderby'  => 'meta_value_num',
        'meta_key' => 'total_sales',
        'order'    => 'DESC',
        'return'   => 'ids',
        'tax_query' => array(
            array(
                'taxonomy' => 'rd_product_type',
                'field'    => 'slug',
                'terms'    => 'donut',
            ),
        ),
    ));

    $ids = array_map('intval', (array) $ids);
    set_transient($cache_key, $ids, DAY_IN_SECONDS);

    return $ids;
}

/**
 * Trending donut product IDs: the flavours with the most units sold over a
 * rolling recent window (default 30 days). Aggregated from paid orders and
 * cached for a day so the order scan only runs once per day.
 *
 * @return int[] Parent product IDs.
 */
function rd_box_builder_trending_ids(): array {
    $count = rd_box_builder_badge_count('trending');
    if ($count <= 0) {
        return array();
    }

    $days      = max(1, (int) apply_filters('rd_box_builder_trending_days', 30));
    $cache_key = 'rd_bb_trending_ids_' . $count . '_' . $days;
    $cached    = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    if (! function_exists('wc_get_orders')) {
        return array();
    }

    $order_ids = wc_get_orders(array(
        'limit'        => -1,
        'status'       => array('wc-completed', 'wc-processing'),
        'date_created' => '>=' . (time() - $days * DAY_IN_SECONDS),
        'return'       => 'ids',
    ));

    $totals = array(); // parent product id => units sold
    foreach ((array) $order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            continue;
        }

        foreach ($order->get_items() as $line) {
            if (! $line instanceof WC_Order_Item_Product) {
                continue;
            }
            // get_product_id() is the parent product even for variations, so the
            // badge tracks the flavour rather than an individual size.
            $product_id = (int) $line->get_product_id();
            if ($product_id <= 0) {
                continue;
            }
            $totals[$product_id] = ($totals[$product_id] ?? 0) + (int) $line->get_quantity();
        }
    }

    // Keep only donuts, then take the busiest few.
    $donuts = array();
    foreach ($totals as $product_id => $units) {
        if (has_term('donut', 'rd_product_type', $product_id)) {
            $donuts[$product_id] = $units;
        }
    }

    arsort($donuts);
    $ids = array_map('intval', array_slice(array_keys($donuts), 0, $count));
    set_transient($cache_key, $ids, DAY_IN_SECONDS);

    return $ids;
}

/**
 * Whether a product counts as "new": published within the recent window
 * (default 30 days). Filterable window so the badge lifetime can be tuned.
 */
function rd_box_builder_is_new_product(int $product_id): bool {
    $days = max(1, (int) apply_filters('rd_box_builder_new_days', 30));

    $post = get_post($product_id);
    if (! $post instanceof WP_Post) {
        return false;
    }

    $published = strtotime($post->post_date_gmt . ' UTC');
    if (! $published) {
        return false;
    }

    return ($published >= time() - $days * DAY_IN_SECONDS);
}

/**
 * Build per-item status flags (bestseller / trending / new) keyed by the bundle
 * item id used in the DOM, mirroring rd_box_builder_items_meta(). Variations
 * resolve to their parent product so flags track the flavour, not the size.
 *
 * @return array<string, array{bestseller:bool, trending:bool, new:bool}>
 */
function rd_box_builder_status_flags(WC_Product $product): array {
    $flags = array();

    if (! $product->is_type('woosb') || ! method_exists($product, 'get_items')) {
        return $flags;
    }

    $bestsellers = array_flip(rd_box_builder_bestseller_ids());
    $trending    = array_flip(rd_box_builder_trending_ids());

    foreach ((array) $product->get_items() as $item) {
        $id = isset($item['id']) ? (int) $item['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $child = wc_get_product($id);
        if (! $child instanceof WC_Product) {
            continue;
        }

        $parent_id = $child->is_type('variation') ? $child->get_parent_id() : $id;

        $flags[(string) $id] = array(
            'bestseller' => isset($bestsellers[$parent_id]),
            'trending'   => isset($trending[$parent_id]),
            'new'        => rd_box_builder_is_new_product($parent_id),
        );
    }

    return $flags;
}

/**
 * Inspect a flagged bundle and return any configuration problems that would
 * stop the builder from working. Empty array means the bundle is good to go.
 *
 * The builder needs the bundle's flavours configured as OPTIONAL items (so WPC
 * renders editable quantity inputs) and a whole-quantity limit (so an exact box
 * size is enforced). This mirrors WPC's own runtime behaviour in class-woosb.php.
 *
 * @return string[] Human readable issues.
 */
function rd_box_builder_config_issues(WC_Product $product): array {
    $issues = array();

    if (! $product->is_type('woosb')) {
        return array(__('Product is not a WPC Product Bundle.', 'rd-box-builder'));
    }

    if (! method_exists($product, 'has_optional') || ! $product->has_optional()) {
        $issues[] = __('No optional items found. Add the donut flavours as "optional" bundled items so customers can change quantities.', 'rd-box-builder');
    }

    $whole_min = (int) $product->get_meta('woosb_limit_whole_min');
    $whole_max = (int) $product->get_meta('woosb_limit_whole_max');

    if ($whole_min <= 0 || $whole_max <= 0) {
        $issues[] = __('No whole-quantity limit set. Set "Limit the whole quantities" min and max to your box size (e.g. 12 and 12) to enforce an exact box.', 'rd-box-builder');
    }

    return $issues;
}
