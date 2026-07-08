<?php
/**
 * Conditional asset loading for the box builder.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Assets {

    public static function init(): void {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
    }

    public static function enqueue(): void {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        // On wp_enqueue_scripts the global $product is not populated yet, so
        // resolve the product from the queried object instead.
        $product = wc_get_product(get_queried_object_id());

        if (! rd_box_builder_is_enabled($product)) {
            return;
        }

        wp_enqueue_script(
            'sortablejs',
            RD_BB_URL . 'assets/js/vendor/Sortable.min.js',
            array(),
            '1.15.2',
            true
        );

        $js_path = RD_BB_DIR . 'assets/js/rd-box-builder.js';
        wp_enqueue_script(
            'rd-box-builder',
            RD_BB_URL . 'assets/js/rd-box-builder.js',
            array('jquery', 'sortablejs'),
            file_exists($js_path) ? (string) filemtime($js_path) : RD_BB_VERSION,
            true
        );

        $css_path = RD_BB_DIR . 'assets/css/rd-box-builder.css';
        wp_enqueue_style(
            'rd-box-builder',
            RD_BB_URL . 'assets/css/rd-box-builder.css',
            array(),
            file_exists($css_path) ? (string) filemtime($css_path) : RD_BB_VERSION
        );

        $category_data = rd_box_builder_category_data($product);

        wp_localize_script('rd-box-builder', 'rdBoxBuilder', array(
            'placeholderUrl' => rd_box_builder_placeholder_url(),
            'productId'      => $product->get_id(),
            'checkoutUrl'    => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
            'ajax'           => array(
                'url'   => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(RD_Box_Builder_Cart::NONCE),
                'add'   => RD_Box_Builder_Cart::ADD,
                'qty'   => RD_Box_Builder_Cart::SETQTY,
                'state' => RD_Box_Builder_Cart::STATE,
                'track' => RD_Box_Builder_Stats::TRACK,
            ),
            'categories'     => $category_data['categories'],
            'itemCats'       => $category_data['itemCats'],
            'itemsMeta'      => rd_box_builder_items_meta($product),
            'itemFlags'      => rd_box_builder_status_flags($product),
            'i18n'           => array(
                'addToBasket'   => __('Add Box to Cart', 'rd-box-builder'),
                'buyNow'        => __('Buy Now', 'rd-box-builder'),
                'processing'    => __('Processing…', 'rd-box-builder'),
                'checkout'      => __('Checkout', 'rd-box-builder'),
                'optionRequired' => __('Please select an option before adding this box to the cart.', 'rd-box-builder'),
                'logoRequired'  => __('Please upload your logo before adding this box to the cart.', 'rd-box-builder'),
                'added'         => __('Added to your basket', 'rd-box-builder'),
                'addError'      => __('Could not add this box to your basket.', 'rd-box-builder'),
                'cartError'     => __('Could not update your basket.', 'rd-box-builder'),
                'increase'      => __('Increase quantity', 'rd-box-builder'),
                'decrease'      => __('Decrease quantity', 'rd-box-builder'),
                'inBasket'      => __('in your basket', 'rd-box-builder'),
                'all'           => __('All', 'rd-box-builder'),
                'allergens'     => __('Allergens', 'rd-box-builder'),
                'allergenInfo'  => __('Allergen info', 'rd-box-builder'),
                'noAllergens'   => __('No allergens found', 'rd-box-builder'),
                'allergenEmpty' => __('Add flavours to your box to see their allergens.', 'rd-box-builder'),
                'box'           => __('Box', 'rd-box-builder'),
                'remove'        => __('Remove', 'rd-box-builder'),
                'add'           => __('Add', 'rd-box-builder'),
                'full'          => __('Your box is full. Remove a donut first.', 'rd-box-builder'),
                'fullNotice'    => __('Your box is full. Remove a donut from your box to swap in a different flavour.', 'rd-box-builder'),
                'chooseDonuts'  => __('Add Flavours to your box!', 'rd-box-builder'),
                'buildYourOwn'  => __('Build Your Own Box', 'rd-box-builder'),
                'viewBox'       => __('View Box', 'rd-box-builder'),
                'close'         => __('Close Box Builder Mode', 'rd-box-builder'),
                'noneInCategory' => __('No donuts in this category.', 'rd-box-builder'),
                'noMatches'     => __('No flavours match your search.', 'rd-box-builder'),
                'noneSelected'  => __('No flavours selected yet. Add a flavour to see it here.', 'rd-box-builder'),
                'listAdd'       => __('Add Flavour to Box', 'rd-box-builder'),
                'scrollMore'    => __('Show more flavours', 'rd-box-builder'),
                'listEmpty'     => __('Your box is empty. Add flavours to get started.', 'rd-box-builder'),
                /* translators: %d = number of donuts still needed to fill the box. */
                'addMore'       => __('Add %d more to complete your box', 'rd-box-builder'),
                'addOneMore'    => __('Add 1 more to complete your box', 'rd-box-builder'),
                'boxComplete'   => __('Your box is complete!', 'rd-box-builder'),
                'dragHint'      => __('Drag a flavour from the list into this slot, or use the + on a flavour to add it to your box.', 'rd-box-builder'),
                'hintsOff'      => __("Don't show tips again", 'rd-box-builder'),
                'hintsOn'       => __('Show tips', 'rd-box-builder'),
                /* translators: %s = flavour name. */
                'addedFlavour'  => __('Added %s to your box', 'rd-box-builder'),
                'inBox'         => __('In box', 'rd-box-builder'),
                /* translators: %s = flavour name. */
                'removedFlavour' => __('Removed %s', 'rd-box-builder'),
                'boxCleared'    => __('Box cleared', 'rd-box-builder'),
                'undo'          => __('Undo', 'rd-box-builder'),
                'vegan'         => __('Vegan', 'rd-box-builder'),
                'glutenFree'    => __('Gluten free', 'rd-box-builder'),
                'bestseller'    => __('Best Seller', 'rd-box-builder'),
                'trending'      => __('Trending', 'rd-box-builder'),
                'newFlavour'    => __('New', 'rd-box-builder'),
            ),
        ));
    }
}
