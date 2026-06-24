<?php
/**
 * In-cart box editor.
 *
 * Renders, under each box-builder bundle line (in both the main cart and the
 * slide-out side cart), a collapsible accordion that:
 *   - lists the donuts in the box (name × quantity),
 *   - shows the add-ons (note, special occasion / dropdowns, logo),
 *   - lets the customer Edit + Save those add-ons in place over AJAX.
 *
 * Reads/writes the same cart-line keys used elsewhere (special_requests,
 * custom_product_option, custom_dropdowns, logo_upload) so the order line item
 * (written by RD_Box_Builder_Addons) stays in sync.
 *
 * @package RD_Box_Builder
 */

defined('ABSPATH') || exit;

class RD_Box_Builder_Cart_Edit {

    const NONCE        = 'rd_bb_cart_edit';
    const MAX_BYTES    = 2097152; // 2MB
    const ALLOWED_EXTS = array('png', 'jpg', 'jpeg');

    public static function init(): void {
        add_action('woocommerce_after_cart_item_name', array(__CLASS__, 'render_main_cart'), 30, 2);
        add_action('wp_ajax_rd_bb_update_addons', array(__CLASS__, 'ajax_update'));
        add_action('wp_ajax_nopriv_rd_bb_update_addons', array(__CLASS__, 'ajax_update'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 25);
    }

    /* ------------------------------------------------------------------ helpers */

    /** A box-builder bundle parent line (not one of its child donut lines). */
    public static function is_box_parent($cart_item): bool {
        if (! is_array($cart_item) || ! empty($cart_item['woosb_parent_id'])) {
            return false;
        }
        $product_id = (int) ($cart_item['product_id'] ?? 0);

        return $product_id > 0 && rd_box_builder_is_enabled($product_id);
    }

    /** Donut child lines belonging to a given parent cart key: [name, qty]. */
    private static function children_for(string $parent_key): array {
        $children = array();
        if (! function_exists('WC') || ! WC()->cart) {
            return $children;
        }

        foreach (WC()->cart->get_cart() as $ci) {
            if (empty($ci['woosb_parent_key']) || $ci['woosb_parent_key'] !== $parent_key) {
                continue;
            }
            $product = $ci['data'] ?? null;
            if (! $product instanceof WC_Product) {
                continue;
            }
            $children[] = array(
                'name' => $product->get_name(),
                'qty'  => (int) $ci['quantity'],
            );
        }

        return $children;
    }

    private static function acf_bool(string $field, int $product_id): bool {
        return function_exists('get_field') ? (bool) get_field($field, $product_id) : false;
    }

    /** Legacy special-occasion options (value => label), mirrors the product page. */
    private static function legacy_occasions(): array {
        return array(
            'Happy Birthday Logo'  => 'Happy Birthday',
            'Congratulations Logo' => 'Congratulations',
            'Thank You Logo'       => 'Thank You',
            'Holy Communion Logo'  => 'Holy Communion',
            'Confirmation Logo'    => 'Confirmation',
            'Its a Girl Logo'      => 'Its a Girl',
            'Its a Boy Logo'       => 'Its a Boy',
            'Bride to be Logo'     => 'Bride to be',
            'Groom to be Logo'     => 'Groom to be',
            'Anniversary Logo'     => 'Anniversary',
            'Get well soon Logo'   => 'Get well soon',
            'Good luck Logo'       => 'Good luck',
        );
    }

    /** Dropdown groups configured for a product, or empty array. */
    private static function dropdown_groups(int $product_id): array {
        if (function_exists('dbb_get_custom_dropdown_groups')) {
            $groups = dbb_get_custom_dropdown_groups($product_id);
            if (is_array($groups)) {
                return $groups;
            }
        }

        return array();
    }

    /* ------------------------------------------------------------------ render */

    /** Main cart hook. Renders the accordion for box parents only. */
    public static function render_main_cart($cart_item, $cart_item_key): void {
        if (! self::is_box_parent($cart_item)) {
            return;
        }
        echo self::render($cart_item_key, $cart_item, 'cart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Build the accordion markup for a box line. Public so the side-cart template
     * can call it directly.
     */
    public static function render(string $cart_item_key, $cart_item, string $context = 'cart'): string {
        if (! self::is_box_parent($cart_item)) {
            return '';
        }

        $product_id = (int) ($cart_item['product_id'] ?? 0);
        $children   = self::children_for($cart_item_key);
        $count      = array_sum(array_map(static function ($c) { return $c['qty']; }, $children));

        ob_start();
        ?>
        <div class="rd-bb-cart-acc" data-key="<?php echo esc_attr($cart_item_key); ?>" data-context="<?php echo esc_attr($context); ?>">
            <button type="button" class="rd-bb-cart-acc-toggle" aria-expanded="false">
                <span class="rd-bb-cart-acc-toggle-label"><?php
                    /* translators: %d: number of donuts in the box */
                    echo esc_html(sprintf(_n('View box details (%d item)', 'View box details (%d items)', $count, 'rd-box-builder'), $count));
                ?></span>
                <span class="rd-bb-cart-acc-caret" aria-hidden="true">&#9662;</span>
            </button>

            <div class="rd-bb-cart-acc-body" hidden>
                <?php if (! empty($children)) : ?>
                <div class="rd-bb-cart-section">
                    <div class="rd-bb-cart-section-title"><?php esc_html_e('In your box', 'rd-box-builder'); ?></div>
                    <ul class="rd-bb-cart-contents">
                        <?php foreach ($children as $child) : ?>
                        <li><span class="rd-bb-cart-qty"><?php echo esc_html($child['qty']); ?>&times;</span> <?php echo esc_html($child['name']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="rd-bb-cart-section rd-bb-cart-addons">
                    <div class="rd-bb-cart-addons-view"><?php echo self::render_summary($product_id, $cart_item, $context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <?php
                    // Editing is only offered in the cart. At checkout the box add-ons
                    // (including the note) are read-only, so skip the edit form.
                    if ($context !== 'checkout') {
                        echo self::render_form($product_id, $cart_item, $cart_item_key, $context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Read-only summary of the current add-ons + an Edit button. */
    private static function render_summary(int $product_id, $cart_item, string $context = 'cart'): string {
        $rows = array();

        if (! empty($cart_item['custom_product_option'])) {
            $rows[__('Special Occasion', 'rd-box-builder')] = esc_html($cart_item['custom_product_option']);
        }
        if (! empty($cart_item['custom_dropdowns']) && is_array($cart_item['custom_dropdowns'])) {
            foreach ($cart_item['custom_dropdowns'] as $group) {
                if (! empty($group['label']) && ! empty($group['value'])) {
                    $rows[sanitize_text_field($group['label'])] = esc_html($group['value']);
                }
            }
        }
        if (! empty($cart_item['special_requests'])) {
            $rows[__('Note to customer', 'rd-box-builder')] = esc_html($cart_item['special_requests']);
        }
        if (! empty($cart_item['logo_upload'])) {
            $rows[__('Logo', 'rd-box-builder')] = '<img src="' . esc_url($cart_item['logo_upload']) . '" alt="" class="rd-bb-cart-logo-thumb">';
        }

        ob_start();
        ?>
        <dl class="rd-bb-cart-addons-list"<?php echo empty($rows) ? ' hidden' : ''; ?>>
            <?php foreach ($rows as $label => $value) : ?>
            <div class="rd-bb-cart-addons-row">
                <dt><?php echo esc_html($label); ?></dt>
                <dd><?php echo wp_kses_post($value); ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
        <?php if (empty($rows)) : ?>
        <p class="rd-bb-cart-addons-empty"><?php esc_html_e('No options added yet.', 'rd-box-builder'); ?></p>
        <?php endif; ?>
        <?php // Editing the box add-ons is only allowed in the cart, not at checkout. ?>
        <?php if ($context !== 'checkout') : ?>
        <button type="button" class="rd-bb-cart-addons-edit"><?php esc_html_e('Edit options', 'rd-box-builder'); ?></button>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /** The (hidden) edit form with fields driven by the product's add-on config. */
    private static function render_form(int $product_id, $cart_item, string $cart_item_key, string $context): string {
        $groups          = self::dropdown_groups($product_id);
        $enable_occasion = self::acf_bool('enable_special_occasion', $product_id);
        $enable_logo     = self::acf_bool('enable_logo_upload', $product_id);

        // At checkout the customer may only adjust their note — the box options
        // (occasion / dropdowns / logo) were locked in when the box was built,
        // so only the note field is rendered (and only it is saved server-side).
        $note_only = ($context === 'checkout');

        ob_start();
        ?>
        <?php // A plain <div>, not a <form>: the checkout order review is rendered
        // inside WooCommerce's own <form class="checkout">, and nested forms are
        // invalid, so a real form here would not submit. The Save button posts the
        // fields over fetch (see rd-cart-edit.js) instead of a native submit. ?>
        <div class="rd-bb-cart-addons-form" hidden data-key="<?php echo esc_attr($cart_item_key); ?>" data-context="<?php echo esc_attr($context); ?>">

            <?php if (! $note_only && ! empty($groups)) : ?>
                <?php foreach ($groups as $group) :
                    $g_key   = isset($group['key']) ? sanitize_key($group['key']) : '';
                    $g_label = isset($group['label']) ? sanitize_text_field($group['label']) : '';
                    $g_opts  = isset($group['options']) && is_array($group['options']) ? $group['options'] : array();
                    if ($g_key === '' || $g_label === '' || empty($g_opts)) {
                        continue;
                    }
                    $g_req   = ! empty($group['required']);
                    $current = isset($cart_item['custom_dropdowns'][$g_key]['value']) ? $cart_item['custom_dropdowns'][$g_key]['value'] : '';
                    ?>
                <label class="rd-bb-cart-field">
                    <span class="rd-bb-cart-field-label"><?php echo esc_html($g_label); ?><?php echo $g_req ? ' *' : ''; ?></span>
                    <select name="custom_dropdown_groups[<?php echo esc_attr($g_key); ?>]"<?php echo $g_req ? ' required' : ''; ?>>
                        <option value=""><?php esc_html_e('Select an option…', 'rd-box-builder'); ?></option>
                        <?php foreach ($g_opts as $opt) :
                            $o_val = isset($opt['value']) ? (string) $opt['value'] : '';
                            if ($o_val === '') { continue; }
                            $o_lab = isset($opt['label']) && $opt['label'] !== '' ? $opt['label'] : $o_val;
                            ?>
                        <option value="<?php echo esc_attr($o_val); ?>"<?php selected($current, $o_val); ?>><?php echo esc_html($o_lab); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endforeach; ?>
            <?php elseif (! $note_only && $enable_occasion) :
                $require_occasion = self::acf_bool('require_special_occasion', $product_id);
                $current          = isset($cart_item['custom_product_option']) ? (string) $cart_item['custom_product_option'] : '';
                ?>
                <label class="rd-bb-cart-field">
                    <span class="rd-bb-cart-field-label"><?php esc_html_e('Special Occasion', 'rd-box-builder'); ?><?php echo $require_occasion ? ' *' : ''; ?></span>
                    <select name="custom_product_option"<?php echo $require_occasion ? ' required' : ''; ?>>
                        <option value=""><?php esc_html_e('Select an option…', 'rd-box-builder'); ?></option>
                        <?php foreach (self::legacy_occasions() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"<?php selected($current, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <?php if (! $note_only && $enable_logo) : ?>
                <div class="rd-bb-cart-field">
                    <span class="rd-bb-cart-field-label"><?php esc_html_e('Logo', 'rd-box-builder'); ?><?php echo self::acf_bool('require_logo_upload', $product_id) ? ' *' : ''; ?></span>
                    <?php if (! empty($cart_item['logo_upload'])) : ?>
                    <img src="<?php echo esc_url($cart_item['logo_upload']); ?>" alt="" class="rd-bb-cart-logo-thumb">
                    <?php endif; ?>
                    <input type="file" name="logo_upload" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                    <span class="rd-bb-cart-field-hint"><?php esc_html_e('PNG or JPG, up to 2MB. Leave empty to keep the current logo.', 'rd-box-builder'); ?></span>
                </div>
            <?php endif; ?>

            <label class="rd-bb-cart-field">
                <span class="rd-bb-cart-field-label"><?php esc_html_e('Note to customer', 'rd-box-builder'); ?></span>
                <textarea name="special_requests" rows="3" placeholder="<?php esc_attr_e('Add a note…', 'rd-box-builder'); ?>"><?php echo esc_textarea($cart_item['special_requests'] ?? ''); ?></textarea>
            </label>

            <div class="rd-bb-cart-form-actions">
                <button type="button" class="rd-bb-cart-addons-save"><?php esc_html_e('Save', 'rd-box-builder'); ?></button>
                <button type="button" class="rd-bb-cart-addons-cancel"><?php esc_html_e('Cancel', 'rd-box-builder'); ?></button>
                <span class="rd-bb-cart-form-msg" role="alert" aria-live="polite"></span>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------- ajax */

    public static function ajax_update(): void {
        check_ajax_referer(self::NONCE, 'nonce');

        if (! function_exists('WC') || ! WC()->cart) {
            wp_send_json_error(array('message' => __('Cart unavailable.', 'rd-box-builder')));
        }
        if (is_null(WC()->cart)) {
            wc_load_cart();
        }

        $key = isset($_POST['cart_item_key']) ? sanitize_text_field(wp_unslash($_POST['cart_item_key'])) : '';
        $cart = WC()->cart;
        if ($key === '' || ! isset($cart->cart_contents[$key])) {
            wp_send_json_error(array('message' => __('Item not found in cart.', 'rd-box-builder')));
        }

        $item = $cart->cart_contents[$key];
        if (! self::is_box_parent($item)) {
            wp_send_json_error(array('message' => __('This item cannot be edited here.', 'rd-box-builder')));
        }

        $product_id = (int) $item['product_id'];
        $groups     = self::dropdown_groups($product_id);
        $context    = isset($_POST['context']) ? sanitize_text_field(wp_unslash($_POST['context'])) : 'cart';

        // ---- Collect + validate ------------------------------------------------
        $note = isset($_POST['special_requests']) ? sanitize_textarea_field(wp_unslash($_POST['special_requests'])) : '';

        // At checkout the only editable field is the customer note. Update it and
        // leave the previously chosen options (occasion / dropdowns / logo)
        // untouched so a note-only submit can't wipe them.
        if ($context === 'checkout') {
            $cart->cart_contents[$key]['special_requests'] = $note;
            $cart->set_session();

            wp_send_json_success(array(
                'message' => __('Saved.', 'rd-box-builder'),
                'summary' => self::render_summary($product_id, $cart->cart_contents[$key], 'checkout'),
            ));
        }

        $dropdowns = array();
        if (! empty($groups)) {
            $posted = isset($_POST['custom_dropdown_groups']) && is_array($_POST['custom_dropdown_groups'])
                ? wp_unslash($_POST['custom_dropdown_groups'])
                : array();
            foreach ($groups as $group) {
                $g_key   = isset($group['key']) ? sanitize_key($group['key']) : '';
                $g_label = isset($group['label']) ? sanitize_text_field($group['label']) : '';
                $g_opts  = isset($group['options']) && is_array($group['options']) ? $group['options'] : array();
                if ($g_key === '' || $g_label === '' || empty($g_opts)) {
                    continue;
                }
                $allowed = array();
                foreach ($g_opts as $opt) {
                    if (! empty($opt['value'])) { $allowed[] = (string) $opt['value']; }
                }
                $value = isset($posted[$g_key]) ? sanitize_text_field($posted[$g_key]) : '';
                if ($value !== '' && ! in_array($value, $allowed, true)) {
                    wp_send_json_error(array('message' => sprintf(__('Invalid option for %s.', 'rd-box-builder'), $g_label)));
                }
                if ($value === '' && ! empty($group['required'])) {
                    wp_send_json_error(array('message' => sprintf(__('Please choose an option for %s.', 'rd-box-builder'), $g_label)));
                }
                if ($value !== '') {
                    $dropdowns[$g_key] = array('label' => $g_label, 'value' => $value);
                }
            }
        }

        $occasion = '';
        if (empty($groups) && self::acf_bool('enable_special_occasion', $product_id)) {
            $occasion = isset($_POST['custom_product_option']) ? sanitize_text_field(wp_unslash($_POST['custom_product_option'])) : '';
            $allowed  = array_keys(self::legacy_occasions());
            if ($occasion !== '' && ! in_array($occasion, $allowed, true)) {
                wp_send_json_error(array('message' => __('Invalid option selected.', 'rd-box-builder')));
            }
            if ($occasion === '' && self::acf_bool('require_special_occasion', $product_id)) {
                wp_send_json_error(array('message' => __('Please select an option.', 'rd-box-builder')));
            }
        }

        $logo_url = isset($item['logo_upload']) ? (string) $item['logo_upload'] : '';
        if (self::acf_bool('enable_logo_upload', $product_id)) {
            $new_logo = self::handle_upload('logo_upload');
            if ($new_logo !== '') {
                $logo_url = $new_logo;
            }
            if ($logo_url === '' && self::acf_bool('require_logo_upload', $product_id)) {
                wp_send_json_error(array('message' => __('Please upload a logo.', 'rd-box-builder')));
            }
        }

        // ---- Persist to the cart line -----------------------------------------
        $cart->cart_contents[$key]['special_requests']      = $note;
        $cart->cart_contents[$key]['custom_product_option'] = $occasion;
        $cart->cart_contents[$key]['custom_dropdowns']      = $dropdowns;
        if ($logo_url !== '') {
            $cart->cart_contents[$key]['logo_upload'] = $logo_url;
        }

        $cart->set_session();

        wp_send_json_success(array(
            'message'  => __('Saved.', 'rd-box-builder'),
            'summary'  => self::render_summary($product_id, $cart->cart_contents[$key]),
        ));
    }

    /** Move a single uploaded image into the uploads dir; return its URL. */
    private static function handle_upload(string $field): string {
        if (empty($_FILES[$field]['name']) || empty($_FILES[$field]['size'])) {
            return '';
        }
        if (! function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file  = $_FILES[$field];
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $ext   = strtolower((string) ($check['ext'] ?? ''));
        if (! in_array($ext, self::ALLOWED_EXTS, true) || (int) $file['size'] > self::MAX_BYTES) {
            return '';
        }
        $uploaded = wp_handle_upload($file, array('test_form' => false));

        return (is_array($uploaded) && ! empty($uploaded['url'])) ? esc_url_raw($uploaded['url']) : '';
    }

    /* ----------------------------------------------------------------- assets */

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }
        // Load on the cart and the checkout (the order review now renders the same
        // box accordion), but not on the order-received/thank-you page.
        $on_cart     = function_exists('is_cart') && is_cart();
        $on_checkout = function_exists('is_checkout') && is_checkout()
            && ! (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received'));

        // The slide-out side cart renders this same accordion site-wide, so its
        // toggle/edit JS must load wherever the side cart can open — not just on
        // the cart/checkout pages. (The side cart itself is enqueued on every
        // other front-end page, so mirror that here.)
        $side_cart = function_exists('matrix_rd_uses_side_cart') && matrix_rd_uses_side_cart();

        if (! $on_cart && ! $on_checkout && ! $side_cart) {
            return;
        }

        $css_rel = 'assets/css/rd-cart-edit.css';
        $js_rel  = 'assets/js/rd-cart-edit.js';
        $css_abs = RD_BB_DIR . $css_rel;
        $js_abs  = RD_BB_DIR . $js_rel;

        wp_enqueue_style(
            'rd-bb-cart-edit',
            RD_BB_URL . $css_rel,
            array(),
            file_exists($css_abs) ? (string) filemtime($css_abs) : RD_BB_VERSION
        );

        wp_enqueue_script(
            'rd-bb-cart-edit',
            RD_BB_URL . $js_rel,
            array('jquery'),
            file_exists($js_abs) ? (string) filemtime($js_abs) : RD_BB_VERSION,
            true
        );

        wp_localize_script('rd-bb-cart-edit', 'rdBoxBuilderCart', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => 'rd_bb_update_addons',
            'nonce'   => wp_create_nonce(self::NONCE),
            'i18n'    => array(
                'saving' => __('Saving…', 'rd-box-builder'),
                'error'  => __('Could not save. Please try again.', 'rd-box-builder'),
            ),
        ));
    }
}
