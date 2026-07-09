<?php
/**
 * Front-end rendering of the box-builder shell.
 *
 * We hook around WPC's bundle output (it renders the `.woosb-wrap` on
 * `woocommerce_before_add_to_cart_button` at the default priority 10):
 *   - priority 9  -> the "Build Your Own Box" toggle, above the bundle list.
 *   - priority 11 -> the box + picker shell, below the bundle list.
 * The JS then relocates the picker into the gallery column and reorganises the
 * box, using the native bundle list (kept in the DOM) as the data layer.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Render {

    public static function init(): void {
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_toggle'), 9);
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_shell'), 11);
        // Late priority so we win over the theme's own add-to-cart-text filter.
        add_filter('woocommerce_product_single_add_to_cart_text', array(__CLASS__, 'add_to_cart_text'), 99, 2);
    }

    /** Box-builder products add a whole configured box, so label the button to match. */
    public static function add_to_cart_text($text, $product = null) {
        if ($product && rd_box_builder_is_enabled($product)) {
            return __('Add Box to Cart', 'rd-box-builder');
        }

        return $text;
    }

    public static function render_toggle(): void {
        if (! rd_box_builder_is_enabled()) {
            return;
        }

        global $product;
        $box_name      = ($product instanceof WC_Product) ? $product->get_title() : '';
        $uses_side_cart = function_exists('matrix_rd_uses_side_cart') && matrix_rd_uses_side_cart();
        $cart_count     = (function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;
        $cart_url       = function_exists('wc_get_cart_url') ? wc_get_cart_url() : '';

        ?>
        <div class="rd-bb-toggle-wrap">
            <div class="rd-bb-toggle-row">
                <button type="button" class="rd-bb-toggle" aria-pressed="false">
                    <span class="rd-bb-toggle-icon" aria-hidden="true"></span>
                    <span class="rd-bb-toggle-label"><?php esc_html_e('Build Your Own Box', 'rd-box-builder'); ?></span>
                </button>
                <a
                    href="<?php echo esc_url($cart_url); ?>"
                    class="rd-bb-cart-btn"
                    title="<?php esc_attr_e('View your shopping cart', 'rd-box-builder'); ?>"
                    aria-label="<?php esc_attr_e('View your shopping cart', 'rd-box-builder'); ?>"
                    data-initial-count="<?php echo esc_attr((string) $cart_count); ?>"
                    <?php echo $uses_side_cart ? 'data-rd-side-cart-trigger' : ''; ?>
                >
                    <span class="iconify rd-bb-cart-btn__icon" data-icon="grommet-icons:basket" data-width="28" data-height="28" aria-hidden="true"></span>
                    <span
                        class="rd-bb-cart-btn__count"
                        <?php echo $cart_count > 0 ? '' : 'hidden'; ?>
                        aria-hidden="<?php echo $cart_count > 0 ? 'false' : 'true'; ?>"
                    ><?php echo esc_html((string) $cart_count); ?></span>
                </a>
            </div>
            <?php if ($box_name !== '') : ?>
            <p class="rd-bb-set-box-notice">
                <?php
                printf(
                    /* translators: %s: preset box product name */
                    esc_html__('Or select our set %s box containing the following flavours:', 'rd-box-builder'),
                    esc_html($box_name)
                );
                ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_shell(): void {
        global $product;

        if (! rd_box_builder_is_enabled($product)) {
            return;
        }

        $placeholder = rd_box_builder_placeholder_url();

        ?>
        <div id="rd-bb-summary" class="rd-bb-summary"></div>

        <div id="rd-bb" class="rd-bb" data-placeholder-url="<?php echo esc_url($placeholder); ?>" hidden>
            <div class="rd-bb-mobile-head" aria-hidden="true">
                <span class="rd-bb-mobile-title"><?php echo esc_html($product->get_title()); ?></span>
                <span class="rd-bb-mobile-price"></span>
            </div>

            <div class="rd-bb-box" aria-live="polite">
                <div class="rd-bb-box-heading">
                    <span class="rd-bb-box-heading-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.25"/><path d="m8.4 6.6.7 1.2"/><path d="m15.2 6.4-.6 1.3"/><path d="m17.7 9.6-1.3.5"/><path d="m6.3 9.3 1.3.6"/></svg>
                    </span>
                    <span class="rd-bb-box-heading-label"><?php esc_html_e('My Box', 'rd-box-builder'); ?></span>
                    <span class="rd-bb-qtydisplay"><span class="rd-bb-count-current">0</span>/<span class="rd-bb-count-max">0</span></span>
                    <div class="rd-bb-box-actions">
                        <button type="button" class="rd-bb-layout-toggle" aria-pressed="false" aria-label="<?php esc_attr_e('Switch to list view', 'rd-box-builder'); ?>" title="<?php esc_attr_e('Switch layout', 'rd-box-builder'); ?>">
                            <span class="rd-bb-layout-toggle-icon rd-bb-layout-toggle-icon--list" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            </span>
                            <span class="rd-bb-layout-toggle-icon rd-bb-layout-toggle-icon--grid" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                            </span>
                        </button>
                        <button type="button" class="rd-bb-clear">
                            <?php esc_html_e('Clear Box', 'rd-box-builder'); ?>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 33 33" fill="none" aria-hidden="true">
                                <path d="M1.476 5.773v8.038h8.038" stroke="currentColor" stroke-width="2.679" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M4.839 20.509a13 13 0 1 0 2.853-12.54L1.476 13.81" stroke="currentColor" stroke-width="2.679" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="rd-bb-progress" hidden>
                    <div class="rd-bb-progress-track"><span class="rd-bb-progress-fill"></span></div>
                    <p class="rd-bb-progress-text"></p>
                </div>
                <div class="rd-bb-slots container-box" role="list"></div>
            </div>

            <?php do_action('rd_box_builder_after_box', $product); ?>

            <button type="button" class="rd-bb-open-picker">
                <span class="rd-bb-open-picker-icon" aria-hidden="true">+</span>
                <?php esc_html_e('Add or swap flavours', 'rd-box-builder'); ?>
            </button>

            <div class="rd-bb-picker" hidden>
                <div class="rd-bb-sheet-head">
                    <button type="button" class="rd-bb-sheet-handle" aria-label="<?php esc_attr_e('Drag to resize the flavour picker, or tap to close', 'rd-box-builder'); ?>">
                        <span class="rd-bb-sheet-handle-bar" aria-hidden="true"></span>
                        <span class="rd-bb-sheet-handle-hint" aria-hidden="true"><?php esc_html_e('Drag to resize', 'rd-box-builder'); ?></span>
                    </button>
                    <div class="rd-bb-sheet-head-row">
                        <span class="rd-bb-sheet-title"><?php esc_html_e('Add Flavours', 'rd-box-builder'); ?></span>
                        <span class="rd-bb-sheet-count"><span class="rd-bb-count-current">0</span>/<span class="rd-bb-count-max">0</span></span>
                        <button type="button" class="rd-bb-sheet-close" aria-label="<?php esc_attr_e('Close', 'rd-box-builder'); ?>">&times;</button>
                    </div>
                </div>
                <div class="rd-bb-picker-top">
                <div class="rd-bb-picker-title-row">
                <div class="rd-bb-title">
                    <span class="rd-bb-title-name"><?php echo esc_html($product->get_title()); ?></span>
                    <div class="rd-bb-title-sub">
                        <span class="rd-bb-title-suffix">&mdash; <?php esc_html_e('Customised Box', 'rd-box-builder'); ?></span>
                        <span class="rd-bb-title-price"></span>
                    </div>
                </div>
                    <button type="button" class="rd-bb-filter-toggle" aria-expanded="false" aria-controls="rd-bb-filter" aria-label="<?php esc_attr_e('Show filters, search and sort', 'rd-box-builder'); ?>" data-label-open="<?php esc_attr_e('Show filters, search and sort', 'rd-box-builder'); ?>" data-label-close="<?php esc_attr_e('Hide filters, search and sort', 'rd-box-builder'); ?>" data-label-text-open="<?php esc_attr_e('Show filters & search', 'rd-box-builder'); ?>" data-label-text-close="<?php esc_attr_e('Hide filters & search', 'rd-box-builder'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18"></path><path d="M6 12h12"></path><path d="M10 19h4"></path></svg>
                        <span class="rd-bb-filter-toggle-label"><?php esc_html_e('Show filters & search', 'rd-box-builder'); ?></span>
                        <span class="rd-bb-filter-toggle-chevron" aria-hidden="true"></span>
                    </button>
                    <div class="rd-bb-help">
                        <button type="button" class="rd-bb-help-toggle" aria-expanded="false" aria-controls="rd-bb-help-panel" aria-label="<?php esc_attr_e('How to use the box builder', 'rd-box-builder'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><path d="M12 17h.01"></path></svg>
                            <span class="rd-bb-help-label"><?php esc_html_e('Help', 'rd-box-builder'); ?></span>
                        </button>
                        <div class="rd-bb-help-panel" id="rd-bb-help-panel" role="dialog" aria-modal="false" aria-label="<?php esc_attr_e('How to use the box builder', 'rd-box-builder'); ?>" hidden>
                            <div class="rd-bb-help-panel-head">
                                <span class="rd-bb-help-panel-title"><?php esc_html_e('How to build your box', 'rd-box-builder'); ?></span>
                                <button type="button" class="rd-bb-help-close" aria-label="<?php esc_attr_e('Close', 'rd-box-builder'); ?>">&times;</button>
                            </div>
                            <ol class="rd-bb-help-list">
                                <li><?php esc_html_e('Tap a flavour to add it to your box — or drag it into an empty slot on the right.', 'rd-box-builder'); ?></li>
                                <li><?php esc_html_e('Use the −/+ on a flavour, or the category chips and search, to find and adjust flavours.', 'rd-box-builder'); ?></li>
                                <li><?php esc_html_e('Tap the “i” on a flavour to see its allergen information.', 'rd-box-builder'); ?></li>
                                <li><?php esc_html_e('Drag donuts around inside your box to rearrange them, or tap the × on a donut to remove it.', 'rd-box-builder'); ?></li>
                                <li><?php esc_html_e('Your box is full when every slot is taken. To swap a flavour, remove one first (tap the × on a donut) — or use “Clear Box” at the top right to empty the whole box and start again.', 'rd-box-builder'); ?></li>
                                <li><?php esc_html_e('When your box is full, choose “Add Box to Cart”. Use the −/+ there to order more than one box.', 'rd-box-builder'); ?></li>
                            </ol>
                            <div class="rd-bb-help-panel-foot">
                                <button type="button" class="rd-bb-hints-off-btn" aria-pressed="false"><?php esc_html_e("Don't show tips again", 'rd-box-builder'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rd-bb-controls">
                    <div id="rd-bb-filter" class="rd-bb-filter" role="group" aria-label="<?php esc_attr_e('Filter donuts by category', 'rd-box-builder'); ?>"></div>
                    <button type="button" class="rd-bb-search-toggle" aria-expanded="false" aria-controls="rd-bb-search-input" aria-label="<?php esc_attr_e('Search flavours', 'rd-box-builder'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                    </button>
                    <div class="rd-bb-search-wrap">
                        <svg class="rd-bb-search-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                        <input type="search" id="rd-bb-search-input" class="rd-bb-search" placeholder="<?php esc_attr_e('Search flavours…', 'rd-box-builder'); ?>" aria-label="<?php esc_attr_e('Search flavours', 'rd-box-builder'); ?>">
                    </div>
                </div>
                </div>
                <div class="rd-bb-picker-headrow">
                    <div class="rd-bb-picker-header"><?php esc_html_e('Add Flavours to your box!', 'rd-box-builder'); ?></div>
                    <p class="rd-bb-picker-hint"><?php esc_html_e('Add a flavour, or drag it into your box', 'rd-box-builder'); ?> <span class="rd-bb-picker-hint-arrow" aria-hidden="true">&rarr;</span></p>
                    <div class="rd-bb-sort-wrap">
                        <svg class="rd-bb-sort-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h12"></path><path d="M3 12h8"></path><path d="M3 18h4"></path><path d="m17 8 3-3 3 3"></path><path d="M20 5v14"></path></svg>
                        <label class="rd-bb-sort-label" for="rd-bb-sort"><?php esc_html_e('Sort', 'rd-box-builder'); ?></label>
                        <select id="rd-bb-sort" class="rd-bb-sort" aria-label="<?php esc_attr_e('Sort flavours', 'rd-box-builder'); ?>">
                            <option value="az"><?php esc_html_e('A–Z', 'rd-box-builder'); ?></option>
                            <option value="za"><?php esc_html_e('Z–A', 'rd-box-builder'); ?></option>
                            <option value="popularity"><?php esc_html_e('Most popular', 'rd-box-builder'); ?></option>
                            <option value="vegan"><?php esc_html_e('Vegan first', 'rd-box-builder'); ?></option>
                            <option value="selected"><?php esc_html_e('Currently Selected', 'rd-box-builder'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="rd-bb-picker-grid" role="list"></div>
            </div>

            <?php do_action('rd_box_builder_after_picker', $product); ?>
        </div>

        <div class="rd-bb-sheet-backdrop" hidden></div>

        <div class="rd-bb-mobilebar" hidden>
            <span class="rd-bb-mobilebar-count">
                <span class="rd-bb-count-current">0</span>/<span class="rd-bb-count-max">0</span>
            </span>
            <button type="button" class="rd-bb-mobilebar-addcart" disabled>
                <?php esc_html_e('Add to Cart', 'rd-box-builder'); ?>
            </button>
            <button type="button" class="rd-bb-mobilebar-buynow" disabled>
                <?php esc_html_e('Buy Now', 'rd-box-builder'); ?>
            </button>
            <?php if (function_exists('matrix_rd_uses_side_cart') && matrix_rd_uses_side_cart()) : ?>
                <button type="button" class="rd-bb-mobilebar-cart" data-rd-side-cart-trigger aria-label="<?php esc_attr_e('View your basket', 'rd-box-builder'); ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
                        <path d="M3 6h18"></path>
                        <path d="M16 10a4 4 0 0 1-8 0"></path>
                    </svg>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }
}
