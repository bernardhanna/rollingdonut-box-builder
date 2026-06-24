<?php
/**
 * Per-product "Enable Box Builder" flag (admin) + setup validation notice.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Flag {

    public static function init(): void {
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'render_field'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_field'));
        add_action('admin_notices', array(__CLASS__, 'maybe_show_config_notice'));
    }

    /**
     * Checkbox in the product General data panel. Only relevant for bundles, so
     * we add a note rather than hiding it (WPC swaps panels dynamically by type).
     */
    public static function render_field(): void {
        woocommerce_wp_checkbox(array(
            'id'          => '_rd_enable_box_builder',
            'label'       => __('Enable Box Builder', 'rd-box-builder'),
            'description' => __('Show the interactive "Build Your Own Box" UI for this bundle. Requires this to be a Product Bundle with optional items and a whole-quantity limit.', 'rd-box-builder'),
            'desc_tip'    => false,
        ));
    }

    public static function save_field(int $post_id): void {
        $value = isset($_POST['_rd_enable_box_builder']) ? 'yes' : '';
        update_post_meta($post_id, '_rd_enable_box_builder', $value);
    }

    /**
     * On the edit screen of a flagged product, warn if the bundle is not
     * configured the way the builder needs.
     */
    public static function maybe_show_config_notice(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen || 'product' !== $screen->id) {
            return;
        }

        global $post;

        if (! $post instanceof WP_Post) {
            return;
        }

        $product = wc_get_product($post->ID);

        if (! $product instanceof WC_Product || 'yes' !== $product->get_meta('_rd_enable_box_builder')) {
            return;
        }

        $issues = rd_box_builder_config_issues($product);

        if (empty($issues)) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>';
        echo esc_html__('Box Builder is enabled but this bundle needs setup:', 'rd-box-builder');
        echo '</strong></p><ul style="list-style:disc;margin-left:20px;">';

        foreach ($issues as $issue) {
            echo '<li>' . esc_html($issue) . '</li>';
        }

        echo '</ul></div>';
    }
}
