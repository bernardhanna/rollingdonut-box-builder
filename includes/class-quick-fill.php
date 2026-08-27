<?php
/**
 * Quick-fill / auto-fill controls for box-builder bundles.
 *
 * Brings the legacy Donut Box Builder's convenience options to the new
 * WPC-bundle-based builder:
 *   - "Auto-fill donut types": All Donuts / All Large / All Midi / By Category
 *     / Selected Flavours Only (manual).
 *   - "Disable flavours": exclude specific donut variations from the auto set.
 *
 * Behaviour:
 *   - When a managed group is chosen, saving the product (re)builds the bundle's
 *     `woosb_ids` from the live catalogue, so WPC keeps owning cart/price/order.
 *   - When the donut catalogue changes (a donut is published/updated/trashed),
 *     every managed box is rebuilt so new flavours appear automatically and
 *     removed flavours drop out — no need to re-open each box.
 *   - "Selected Flavours Only" leaves the bundle items untouched so they can be
 *     curated by hand in WPC's Bundled Products tab.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Quick_Fill {

    /**
     * Re-entrancy guard so a catalogue-triggered rebuild can't recurse.
     */
    private static bool $syncing = false;

    public static function init(): void {
        // Admin UI (rendered just after the "Enable Box Builder" checkbox).
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'render_fields'), 20);

        // Persist the settings, then rebuild this box AFTER WPC has saved its
        // own woosb_ids (priority 100 so we win in managed mode).
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_fields'), 20);
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'rebuild_on_save'), 100);

        // Keep every managed box in sync with the live donut catalogue.
        add_action('woocommerce_update_product', array(__CLASS__, 'sync_for_product'), 100);
        add_action('woocommerce_new_product', array(__CLASS__, 'sync_for_product'), 100);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'sync_for_product'), 100);
        add_action('wp_trash_post', array(__CLASS__, 'sync_for_product'), 100);
        add_action('untrashed_post', array(__CLASS__, 'sync_for_product'), 100);
    }

    // ---------------------------------------------------------------- Admin UI

    public static function render_fields(): void {
        global $post;

        if (! $post instanceof WP_Post) {
            return;
        }

        $pid      = (int) $post->ID;
        $group    = (string) get_post_meta($pid, '_rd_bb_group_selection', true);
        $group    = '' !== $group ? $group : 'none';
        $category = (string) get_post_meta($pid, '_rd_bb_category_selection', true);
        $size     = (string) get_post_meta($pid, '_rd_bb_size_selection', true);
        $size     = '' !== $size ? $size : 'all';

        $disabled = get_post_meta($pid, '_rd_bb_disabled', true);
        $disabled = is_array($disabled) ? array_map('intval', $disabled) : array();

        echo '<div class="options_group rd-bb-quickfill show_if_woosb">';

        echo '<p class="form-field"><strong>' . esc_html__('Box Builder — quick set up', 'rd-box-builder') . '</strong></p>';

        // Group selection.
        woocommerce_wp_select(array(
            'id'          => '_rd_bb_group_selection',
            'label'       => __('Auto-fill donut types', 'rd-box-builder'),
            'description' => __('Fill this box automatically from the live catalogue. Re-saving refreshes the list; newly published matching donuts are added to every box automatically. To retire a flavour, set that product to Draft — it drops out of every box builder on its own. Do not delete rows from Bundled Products: a long flavour list can empty the box on save. Choose "Selected Flavours Only" to manage items by hand.', 'rd-box-builder'),
            'desc_tip'    => true,
            'value'       => $group,
            'options'     => array(
                'none'             => __('Selected Flavours Only (manual)', 'rd-box-builder'),
                'all_donuts'       => __('All Donuts', 'rd-box-builder'),
                'all_large_donuts' => __('All Large Donuts', 'rd-box-builder'),
                'all_midi_donuts'  => __('All Midi Donuts', 'rd-box-builder'),
                'category'         => __('Select by Category', 'rd-box-builder'),
            ),
        ));

        // Category + size (only relevant for the "category" group).
        echo '<div id="rd_bb_category_wrap">';

        $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        echo '<p class="form-field"><label for="_rd_bb_category_selection">' . esc_html__('Donut category', 'rd-box-builder') . '</label>';
        echo '<select id="_rd_bb_category_selection" name="_rd_bb_category_selection" class="wc-enhanced-select" style="width:50%;">';
        echo '<option value="">' . esc_html__('— Select category —', 'rd-box-builder') . '</option>';
        if (! is_wp_error($categories)) {
            foreach ($categories as $cat) {
                echo '<option value="' . esc_attr($cat->slug) . '" ' . selected($category, $cat->slug, false) . '>' . esc_html($cat->name) . '</option>';
            }
        }
        echo '</select></p>';

        echo '<p class="form-field"><label for="_rd_bb_size_selection">' . esc_html__('Size', 'rd-box-builder') . '</label>';
        echo '<select id="_rd_bb_size_selection" name="_rd_bb_size_selection" class="wc-enhanced-select" style="width:50%;">';
        echo '<option value="all" ' . selected($size, 'all', false) . '>' . esc_html__('All sizes', 'rd-box-builder') . '</option>';
        echo '<option value="large" ' . selected($size, 'large', false) . '>' . esc_html__('Large', 'rd-box-builder') . '</option>';
        echo '<option value="midi" ' . selected($size, 'midi', false) . '>' . esc_html__('Midi', 'rd-box-builder') . '</option>';
        echo '</select></p>';

        echo '</div>'; // #rd_bb_category_wrap

        // Disable flavours (applies to the auto modes).
        echo '<p class="form-field"><label for="_rd_bb_disabled">' . esc_html__('Disable flavours', 'rd-box-builder') . '</label>';
        echo '<select id="_rd_bb_disabled" name="_rd_bb_disabled[]" multiple="multiple" class="wc-enhanced-select" style="width:50%;" data-placeholder="' . esc_attr__('No flavours disabled', 'rd-box-builder') . '">';
        foreach (rd_box_builder_donut_variations() as $vid => $vname) {
            echo '<option value="' . esc_attr((string) $vid) . '" ' . (in_array((int) $vid, $disabled, true) ? 'selected' : '') . '>' . esc_html($vname) . '</option>';
        }
        echo '</select>';
        echo '<span class="description">' . esc_html__('Excluded from the auto-fill modes above. Ignored in "Selected Flavours Only". Prefer drafting the flavour product instead — that removes it from every box without touching Bundled Products.', 'rd-box-builder') . '</span></p>';

        echo '</div>'; // .rd-bb-quickfill
        ?>
        <script type="text/javascript">
            jQuery(function ($) {
                var $group = $('#_rd_bb_group_selection');

                function toggleCategory() {
                    var isCat = $group.val() === 'category';
                    $('#rd_bb_category_wrap').toggle(isCat);
                }

                toggleCategory();
                $group.on('change', toggleCategory);
            });
        </script>
        <?php
    }

    public static function save_fields(int $post_id): void {
        if (! isset($_POST['_rd_bb_group_selection'])) {
            return;
        }

        $group   = sanitize_text_field(wp_unslash($_POST['_rd_bb_group_selection']));
        $allowed = array_merge(array('none'), rd_box_builder_managed_groups());
        if (! in_array($group, $allowed, true)) {
            $group = 'none';
        }
        update_post_meta($post_id, '_rd_bb_group_selection', $group);

        $category = isset($_POST['_rd_bb_category_selection']) ? sanitize_text_field(wp_unslash($_POST['_rd_bb_category_selection'])) : '';
        update_post_meta($post_id, '_rd_bb_category_selection', $category);

        $size = isset($_POST['_rd_bb_size_selection']) ? sanitize_text_field(wp_unslash($_POST['_rd_bb_size_selection'])) : 'all';
        if (! in_array($size, array('all', 'large', 'midi'), true)) {
            $size = 'all';
        }
        update_post_meta($post_id, '_rd_bb_size_selection', $size);

        $disabled = isset($_POST['_rd_bb_disabled']) ? array_map('intval', (array) wp_unslash($_POST['_rd_bb_disabled'])) : array();
        update_post_meta($post_id, '_rd_bb_disabled', $disabled);
    }

    // -------------------------------------------------------------- Rebuilding

    /**
     * Rebuild the box that was just saved (runs after WPC's own save).
     */
    public static function rebuild_on_save(int $post_id): void {
        self::rebuild_box($post_id);
    }

    /**
     * Rebuild a single box's woosb_ids from its quick-fill selection.
     *
     * No-ops (returns false) for manual mode, non-bundles, or when the resolver
     * matches nothing (so we never wipe a box because of a mis-typed category).
     */
    public static function rebuild_box(int $box_id): bool {
        $group = (string) get_post_meta($box_id, '_rd_bb_group_selection', true);
        if (! in_array($group, rd_box_builder_managed_groups(), true)) {
            return false;
        }

        $product = wc_get_product($box_id);
        if (! $product instanceof WC_Product || ! $product->is_type('woosb')) {
            return false;
        }

        $size     = (string) get_post_meta($box_id, '_rd_bb_size_selection', true);
        $size     = '' !== $size ? $size : 'all';
        $category = (string) get_post_meta($box_id, '_rd_bb_category_selection', true);
        $disabled = get_post_meta($box_id, '_rd_bb_disabled', true);
        $disabled = is_array($disabled) ? $disabled : array();

        $ids = rd_box_builder_resolve_variation_ids($group, $size, $category, $disabled);
        if (empty($ids)) {
            return false;
        }

        $existing  = get_post_meta($box_id, 'woosb_ids', true);
        $whole_max = (int) get_post_meta($box_id, 'woosb_limit_whole_max', true);
        $woosb     = rd_box_builder_build_woosb_ids($ids, $existing, $whole_max);

        update_post_meta($box_id, 'woosb_ids', $woosb);
        wc_delete_product_transients($box_id);
        clean_post_cache($box_id);

        return true;
    }

    /**
     * React to a donut catalogue change by refreshing every managed box.
     *
     * Ignores bundle (woosb) saves and non-donut products so a normal product
     * edit doesn't trigger a sweep.
     *
     * @param int|WC_Product $product_or_id
     */
    public static function sync_for_product($product_or_id): void {
        if (self::$syncing) {
            return;
        }

        $product = is_numeric($product_or_id) ? wc_get_product((int) $product_or_id) : $product_or_id;
        if ($product instanceof WC_Product) {
            if ($product->is_type('woosb')) {
                return;
            }
            if (! self::is_donut_related($product)) {
                return;
            }
        }

        self::$syncing = true;
        foreach (self::managed_box_ids() as $box_id) {
            self::rebuild_box($box_id);
        }
        foreach (RD_Box_Builder_Bundle_Guard::enabled_box_ids() as $box_id) {
            RD_Box_Builder_Bundle_Guard::prune_box($box_id);
        }
        self::$syncing = false;
    }

    private static function is_donut_related(WC_Product $product): bool {
        $check_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();

        return (bool) has_term('donut', 'rd_product_type', $check_id);
    }

    /**
     * @return int[] Product ids of all boxes in a managed auto-fill mode.
     */
    private static function managed_box_ids(): array {
        $query = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => '_rd_bb_group_selection',
                    'value'   => rd_box_builder_managed_groups(),
                    'compare' => 'IN',
                ),
            ),
        ));

        return array_map('intval', $query->posts);
    }
}
