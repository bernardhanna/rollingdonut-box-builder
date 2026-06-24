/**
 * Rolling Donut Box Builder skin.
 *
 * Drives WPC Product Bundles' native optional-item quantity inputs (.woosb-qty)
 * from a friendlier box-builder UI. We never touch cart/price logic: setting a
 * .woosb-qty value and firing `change` lets WPC recalculate totals and toggle
 * the native add-to-cart button. This file only renders UI and mirrors state.
 */
(function ($) {
    'use strict';

    var cfg = window.rdBoxBuilder || {};
    var i18n = cfg.i18n || {};
    var itemCats = cfg.itemCats || {};
    var categories = cfg.categories || [];
    var itemsMeta = cfg.itemsMeta || {};
    var itemFlags = cfg.itemFlags || {};

    var REMOVE_ICON = '<svg viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
        + '<g fill="#f55959" fill-rule="evenodd" stroke="#000" stroke-linecap="round" stroke-linejoin="round" transform="translate(2 2)">'
        + '<circle cx="8.5" cy="8.5" r="8"></circle>'
        + '<g transform="matrix(0 1 -1 0 17 0)"><path d="m5.5 11.5 6-6"></path><path d="m5.5 5.5 6 6"></path></g>'
        + '</g></svg>';

    // Allergen info "i" badge (matches the theme's .allergen_svg icon).
    var ALLERGEN_INFO_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="31" height="30" viewBox="0 0 31 30" fill="none" aria-hidden="true" focusable="false">'
        + '<circle cx="15.678" cy="14.85" r="14.721" fill="#000"/>'
        + '<path stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.677" d="M15.677 26.004c6.159 0 11.152-4.993 11.152-11.153 0-6.159-4.993-11.152-11.152-11.152-6.16 0-11.153 4.993-11.153 11.153 0 6.159 4.993 11.152 11.153 11.152M15.678 19.313v-4.461M15.678 19.313v-4.461M15.678 10.39h.01"/>'
        + '</svg>';

    // Close badge (yellow circle + X), matches the theme's allergen close icon.
    var ALLERGEN_CLOSE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="23" height="22" viewBox="0 0 23 22" fill="none" aria-hidden="true" focusable="false">'
        + '<rect x="1.5" y="1" width="20" height="20" rx="10" fill="black"/>'
        + '<circle cx="11.5" cy="11" r="11" fill="#FFED56"/>'
        + '<path d="M11.4993 19.3346C16.1017 19.3346 19.8327 15.6037 19.8327 11.0013C19.8327 6.39893 16.1017 2.66797 11.4993 2.66797C6.89698 2.66797 3.16602 6.39893 3.16602 11.0013C3.16602 15.6037 6.89698 19.3346 11.4993 19.3346Z" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '<path d="M14.5 14L8.5 8" stroke="#0E1217" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '<path d="M8.5 14L14.5 8" stroke="#0E1217" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '</svg>';

    // Chevron used by the "more to scroll" hint at the bottom of the picker.
    var CHEVRON_DOWN_ICON = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"></path></svg>';

    // Tick shown on a picker card's "in box" badge.
    var CHECK_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"></path></svg>';

    function clamp(n, min, max) {
        if (max > 0 && n > max) { n = max; }
        if (n < min) { n = min; }
        return n;
    }

    // The picker is a side-by-side panel on desktop, but a slide-up bottom sheet
    // on smaller screens. Mirror the CSS breakpoint so behaviour matches layout.
    function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 1023px)').matches;
    }

    // Slide the flavour picker up as a bottom sheet. `targetPos` (optional) is the
    // empty box slot that triggered it, so the next flavour added lands there.
    function openSheet(state, dom, targetPos) {
        state.sheetTarget = (targetPos != null && !isNaN(targetPos)) ? parseInt(targetPos, 10) : null;
        $('body').addClass('rd-bb-sheet-open');
        dom.backdrop.prop('hidden', false);
        // The grid only knows its scrollable height once the sheet is on screen.
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () { updateScrollHint(dom); });
        }
    }

    function closeSheet(state, dom) {
        state.sheetTarget = null;
        $('body').removeClass('rd-bb-sheet-open');
        dom.backdrop.prop('hidden', true);
        // Drop any drag-resized height so the next open starts at the CSS default
        // and a stale px height can never leak into the desktop side panel.
        var picker = dom.picker && dom.picker.get(0);
        if (picker) {
            picker.style.height = '';
            picker.style.maxHeight = '';
            picker.classList.remove('rd-bb-sheet-resizing');
        }
    }

    // Drag the sheet handle up/down to resize the bottom sheet; a plain tap (no
    // real movement) closes it, matching the old handle behaviour. Mobile only,
    // and a no-op anywhere PointerEvents/touch aren't available — so it can never
    // make the desktop or a non-supporting browser glitchy.
    function initSheetResize(state, dom) {
        var handle = dom.sheetHandle.get(0);
        var picker = dom.picker.get(0);
        if (!handle || !picker) { return; }

        var MIN_VH = 0.35;   // smaller than this on release = dismiss
        var MAX_VH = 0.92;
        var TAP_PX = 5;      // movement under this counts as a tap, not a drag

        var dragging = false;
        var moved = false;
        var startY = 0;
        var startH = 0;
        var activeId = null;

        function vh() {
            return window.innerHeight || document.documentElement.clientHeight || 0;
        }

        function point(e) {
            return (e.touches && e.touches[0]) ? e.touches[0] : e;
        }

        function onDown(e) {
            if (!isMobile() || dragging) { return; }
            var p = point(e);
            if (p.clientY == null) { return; }
            dragging = true;
            moved = false;
            startY = p.clientY;
            startH = picker.getBoundingClientRect().height;
            activeId = (e.pointerId != null) ? e.pointerId : null;
            picker.classList.add('rd-bb-sheet-resizing');
            if (activeId != null && handle.setPointerCapture) {
                try { handle.setPointerCapture(activeId); } catch (err) {}
            }
            e.preventDefault();
        }

        function onMove(e) {
            if (!dragging) { return; }
            var p = point(e);
            if (p.clientY == null) { return; }
            var dy = startY - p.clientY; // drag up (positive) = grow
            if (Math.abs(dy) > TAP_PX) { moved = true; }
            var h = startH + dy;
            var min = vh() * MIN_VH;
            var max = vh() * MAX_VH;
            if (h < min) { h = min; }
            if (h > max) { h = max; }
            picker.style.height = h + 'px';
            picker.style.maxHeight = h + 'px';
            if (e.cancelable) { e.preventDefault(); }
        }

        function onUp(e) {
            if (!dragging) { return; }
            dragging = false;
            picker.classList.remove('rd-bb-sheet-resizing');
            if (activeId != null && handle.releasePointerCapture) {
                try { handle.releasePointerCapture(activeId); } catch (err) {}
            }
            activeId = null;

            // No real movement → treat as a tap and close.
            if (!moved) {
                closeSheet(state, dom);
                return;
            }
            // Dragged down to (near) the minimum → dismiss instead of leaving a sliver.
            if (picker.getBoundingClientRect().height <= vh() * (MIN_VH + 0.02)) {
                closeSheet(state, dom);
            }
            updateScrollHint(dom);
        }

        if (window.PointerEvent) {
            handle.addEventListener('pointerdown', onDown);
            document.addEventListener('pointermove', onMove, { passive: false });
            document.addEventListener('pointerup', onUp);
            document.addEventListener('pointercancel', onUp);
        } else {
            handle.addEventListener('touchstart', onDown, { passive: false });
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onUp);
            handle.addEventListener('mousedown', onDown);
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }
    }

    function init() {
        var $root = $('#rd-bb');
        if (!$root.length || $root.data('rdbbReady')) { return; }

        var $wrap = $('.woosb-wrap').first();
        var $products = $wrap.find('.woosb-products').first();
        if (!$products.length) { return; }

        $root.data('rdbbReady', true);

        // The bundle plugin slideDowns its inline "Please choose at least one
        // product…" alert on every readiness check — so it shows on the initial
        // (empty) box. Suppress it until the customer actually tries to add/checkout
        // (revealWoosbAlert removes this class in cartAdd's not-ready guard).
        $wrap.addClass('rd-bb-suppress-woosb-alert');

        var state = {
            capacity: computeCapacity($products),
            items: collectItems($products),
            active: false,
            filter: '',
            search: '',
            // Default picker order: flavours A–Z with vegan grouped last. The sort
            // control beside the search box switches between az / za / popularity.
            sort: 'az',
            // The box opens pre-filled to capacity; suppress the "box full" banner
            // until the customer actually changes something (add/remove/clear).
            touched: false,
            // Mobile only: the empty box slot that opened the picker sheet. The
            // next flavour added fills this slot, then the target clears.
            sheetTarget: null,
            // "grid" (donut slots) or "list" (simple name + remove rows). Remembered
            // per browser so the customer's preferred layout sticks.
            boxView: readBoxView(),
            // Customer can opt out of the instructional nudges (Help panel);
            // remembered per browser. The functional count/progress stays.
            hintsOff: readHintsOff()
        };
        // Fixed positional map of the box (one entry per slot). Lets a removed
        // donut leave its gap in place instead of the rest re-flowing left.
        state.layout = buildLayout(state);

        var dom = {
            root: $root,
            summary: $('#rd-bb-summary'),
            box: $root.find('.rd-bb-box'),
            slots: $root.find('.rd-bb-slots'),
            picker: $root.find('.rd-bb-picker'),
            pickerGrid: $root.find('.rd-bb-picker-grid'),
            openPicker: $root.find('.rd-bb-open-picker'),
            sheetClose: $root.find('.rd-bb-sheet-close'),
            sheetHandle: $root.find('.rd-bb-sheet-handle'),
            backdrop: $('.rd-bb-sheet-backdrop'),
            title: $root.find('.rd-bb-title'),
            filter: $root.find('.rd-bb-filter'),
            search: $root.find('.rd-bb-search'),
            sort: $root.find('.rd-bb-sort'),
            countCurrent: $('.rd-bb-count-current'),
            countMax: $('.rd-bb-count-max'),
            toggle: $('.rd-bb-toggle'),
            clear: $root.find('.rd-bb-clear'),
            progress: $root.find('.rd-bb-progress'),
            progressFill: $root.find('.rd-bb-progress-fill'),
            progressText: $root.find('.rd-bb-progress-text'),
            layoutToggle: $root.find('.rd-bb-layout-toggle'),
            mobilebar: $('.rd-bb-mobilebar'),
            mobileAdd: $('.rd-bb-mobilebar-addcart'),
            mobileBuy: $('.rd-bb-mobilebar-buynow'),
            gallery: $('.woocommerce-product-gallery').first(),
            woosbWrap: $wrap,
            // WPC renders the visible bundle list outside the form, but the hidden
            // woosb_ids input and the add-to-cart button live inside it — so resolve
            // the form from the button (fallback to the first cart form).
            form: ($('.single_add_to_cart_button').first().closest('form.cart').length
                ? $('.single_add_to_cart_button').first().closest('form.cart')
                : $('form.cart').first()),
            placeholder: $root.data('placeholder-url') || cfg.placeholderUrl
        };

        // Move the picker into the gallery column so it can replace the gallery
        // visually. Fall back to leaving it in place if there is no gallery.
        if (dom.gallery.length) {
            dom.gallery.append(dom.picker);
        }

        // The add-on option fields (team/occasion dropdowns, logo upload, note)
        // render above the bundle; the box should come first, so relocate the
        // whole add-ons block below the builder shell — between the box and the
        // "Add Box to Cart" button. It stays inside the cart form, so submission
        // (and our AJAX serialisation of these fields) is unaffected.
        var $addons = $('#custom-product-addons');
        if ($addons.length) {
            $root.after($addons);
        } else {
            var $note = $('#special_requests').closest('.product-addon-field');
            if ($note.length) { $root.after($note); }
        }

        renderFilter(state, dom);
        applyBoxView(state, dom);
        applyHintsPref(state);
        bindEvents(state, dom);
        initDragAndDrop(state, dom);
        setupScrollHint(state, dom);
        setupMobileBarAutohide(state, dom);
        render(state, dom);
        initCart(state, dom);

        // Add-to-cart redirects/reloads the page; reopen the builder if it was
        // open when the customer added their box.
        if (consumeReopenFlag()) {
            setActive(state, dom, true);
        }
    }

    // Remember the customer's preferred box layout (grid/list) across visits.
    function readBoxView() {
        try {
            return window.localStorage.getItem('rdbb_box_view') === 'list' ? 'list' : 'grid';
        } catch (e) { return 'grid'; }
    }

    function writeBoxView(view) {
        try { window.localStorage.setItem('rdbb_box_view', view); } catch (e) { /* unavailable */ }
    }

    // Remember whether the customer has opted out of the instructional nudges
    // (Help panel -> "Don't show tips again"), per browser, across visits.
    function readHintsOff() {
        try { return window.localStorage.getItem('rdbb_hints_off') === '1'; }
        catch (e) { return false; }
    }

    function writeHintsOff(off) {
        try { window.localStorage.setItem('rdbb_hints_off', off ? '1' : '0'); }
        catch (e) { /* unavailable */ }
    }

    // Hide/show the instructional nudges (get-started picker hint, scroll-down
    // arrow, full-box swap notice) via a body class, and reflect the choice on
    // the Help-panel toggle. The functional count/progress bar stays visible.
    // The class lives on <body> because the picker is relocated into the gallery
    // column at init, so it is no longer a descendant of #rd-bb.
    function applyHintsPref(state) {
        $('body').toggleClass('rd-bb--hints-off', !!state.hintsOff);
        $('.rd-bb-hints-off-btn')
            .text(state.hintsOff ? (i18n.hintsOn || 'Show tips') : (i18n.hintsOff || "Don't show tips again"))
            .attr('aria-pressed', state.hintsOff ? 'true' : 'false');
    }

    function toggleHintsPref(state) {
        state.hintsOff = !state.hintsOff;
        writeHintsOff(state.hintsOff);
        applyHintsPref(state);
    }

    // Reflect the current box view on the DOM (CSS swaps grid<->list) and update
    // the toggle's pressed state + accessible label.
    function applyBoxView(state, dom) {
        var isList = state.boxView === 'list';
        if (dom.box && dom.box.length) {
            dom.box.toggleClass('rd-bb-box--list', isList);
        }
        if (dom.layoutToggle && dom.layoutToggle.length) {
            dom.layoutToggle.attr('aria-pressed', isList ? 'true' : 'false');
            dom.layoutToggle.attr('aria-label', isList
                ? (i18n.gridView || 'Switch to grid view')
                : (i18n.listView || 'Switch to list view'));
        }
    }

    function toggleBoxView(state, dom) {
        state.boxView = state.boxView === 'list' ? 'grid' : 'list';
        writeBoxView(state.boxView);
        applyBoxView(state, dom);
        render(state, dom);
    }

    // Persist "builder was open" across the add-to-cart reload, keyed per product.
    function reopenKey() {
        return 'rdbb_reopen:' + window.location.pathname;
    }

    function rememberOpen(state) {
        try {
            if (state.active) { window.sessionStorage.setItem(reopenKey(), '1'); }
        } catch (e) { /* sessionStorage unavailable */ }
    }

    function consumeReopenFlag() {
        try {
            if (window.sessionStorage.getItem(reopenKey()) === '1') {
                window.sessionStorage.removeItem(reopenKey());
                return true;
            }
        } catch (e) { /* sessionStorage unavailable */ }
        return false;
    }

    function computeCapacity($products) {
        var max = parseFloat($products.attr('data-max'));
        var min = parseFloat($products.attr('data-min'));
        if (max > 0) { return max; }
        if (min > 0) { return min; }
        return 0; // unknown / unlimited
    }

    function collectItems($products) {
        var items = [];

        $products.find('.woosb-product').each(function () {
            var $node = $(this);
            var $input = $node.find('.woosb-qty').first();
            var editable = $input.length > 0 && !$input.is(':disabled');
            var order = parseInt($node.attr('data-order'), 10);
            var $img = $node.find('.woosb-thumb img').first();

            var qty;
            if (editable) {
                qty = parseFloat($input.val());
            } else {
                qty = parseFloat($node.attr('data-qty'));
            }
            if (!qty || isNaN(qty)) { qty = 0; }

            var id = $node.attr('data-id');
            var meta = itemsMeta[id] || {};

            items.push({
                order: order,
                id: id,
                cats: itemCats[id] || [],
                flags: itemFlags[id] || {},
                sales: parseInt(meta.sales, 10) || 0,
                allergens: meta.allergens || [],
                name: meta.name || $node.attr('data-name') || ($img.attr('alt') || ''),
                price: $node.attr('data-price'),
                priceHtml: $node.find('.woosb-price-ori').html() || '',
                thumb: $img.attr('src') || '',
                editable: editable,
                $input: $input,
                min: parseFloat($input.attr('min')) || 0,
                max: parseFloat($input.attr('max')) || 0,
                qty: qty,
                // The bundle's out-of-the-box quantity, snapshotted on load so we
                // can revert to the default selection when builder mode is closed.
                defaultQty: qty
            });
        });

        return items;
    }

    function total(state) {
        return state.items.reduce(function (sum, it) { return sum + it.qty; }, 0);
    }

    // Build the initial slot map from current quantities (capacity-limited boxes
    // only). Returns null for unlimited boxes, where there are no fixed slots.
    function buildLayout(state) {
        if (state.capacity <= 0) { return null; }

        var layout = [];
        for (var i = 0; i < state.capacity; i++) { layout.push(null); }

        var idx = 0;
        state.items.forEach(function (item) {
            for (var n = 0; n < item.qty && idx < state.capacity; n++) {
                layout[idx++] = item.order;
            }
        });
        return layout;
    }

    function countInLayout(state, order) {
        var c = 0;
        state.layout.forEach(function (o) { if (o === order) { c++; } });
        return c;
    }

    function firstEmptyIndex(state) {
        return state.layout.indexOf(null);
    }

    function lastIndexOfOrder(state, order) {
        for (var i = state.layout.length - 1; i >= 0; i--) {
            if (state.layout[i] === order) { return i; }
        }
        return -1;
    }

    // Push the layout's per-item counts into WPC's native inputs, then re-render.
    function commitLayout(state, dom) {
        state.touched = true;
        state.items.forEach(function (item) {
            if (!item.editable) { return; }
            var c = countInLayout(state, item.order);
            if (c !== item.qty) {
                item.qty = c;
                item.$input.val(c).trigger('change');
            }
        });
        render(state, dom);
    }

    function setQty(state, dom, item, next) {
        if (!item.editable) { return; }

        next = clamp(next, item.min, item.max);

        if (state.capacity > 0) {
            var others = total(state) - item.qty;
            if (others + next > state.capacity) {
                next = state.capacity - others;
                if (next > item.qty) {
                    // would overflow beyond what's left; cap and warn
                    toast(dom, i18n.full || 'Your box is full.');
                }
            }
        }

        if (next === item.qty) {
            render(state, dom);
            return;
        }

        item.qty = next;
        state.touched = true;
        // Hand off to WPC: it updates data-qty, price and add-to-cart state.
        item.$input.val(next).trigger('change');
        render(state, dom);
    }

    function addedMsg(item) {
        return (i18n.addedFlavour || 'Added %s').replace('%s', item ? item.name : '');
    }

    function removedMsg(item) {
        return (i18n.removedFlavour || 'Removed %s').replace('%s', item ? item.name : '');
    }

    function addOne(state, dom, order, preferredIndex) {
        var item = findItem(state, order);
        if (!item || !item.editable) { return; }

        if (state.layout) {
            if (item.max > 0 && countInLayout(state, item.order) >= item.max) {
                toast(dom, i18n.full || 'Your box is full.');
                return;
            }

            var idx = -1;
            if (preferredIndex != null) {
                var p = parseInt(preferredIndex, 10);
                if (!isNaN(p) && p >= 0 && p < state.layout.length && state.layout[p] == null) {
                    idx = p;
                }
            }
            if (idx === -1) { idx = firstEmptyIndex(state); }
            if (idx === -1) {
                toast(dom, i18n.full || 'Your box is full.');
                return;
            }

            state.layout[idx] = item.order;
            commitLayout(state, dom);
            toast(dom, addedMsg(item));
            return;
        }

        if (state.capacity > 0 && total(state) >= state.capacity) {
            toast(dom, i18n.full || 'Your box is full.');
            return;
        }
        var before = item.qty;
        setQty(state, dom, item, item.qty + 1);
        if (item.qty > before) { toast(dom, addedMsg(item)); }
    }

    function removeOne(state, dom, order) {
        var item = findItem(state, order);
        if (!item) { return; }

        if (state.layout) {
            var idx = lastIndexOfOrder(state, item.order);
            if (idx === -1) { return; }
            state.layout[idx] = null;
            commitLayout(state, dom);
            return;
        }

        setQty(state, dom, item, item.qty - 1);
    }

    // Snapshot/restore the current box contents so destructive actions (remove a
    // flavour, empty a slot, Clear Box) can offer a one-tap Undo.
    function snapshotBox(state) {
        if (state.layout) { return { layout: state.layout.slice() }; }
        return { qtys: state.items.map(function (it) { return { order: it.order, qty: it.qty }; }) };
    }

    function restoreBox(state, dom, snap) {
        if (!snap) { return; }
        if (snap.layout && state.layout) {
            state.layout = snap.layout.slice();
            commitLayout(state, dom);
            return;
        }
        if (snap.qtys) {
            snap.qtys.forEach(function (s) {
                var it = findItem(state, s.order);
                if (it && it.editable && it.qty !== s.qty) {
                    it.qty = s.qty;
                    it.$input.val(s.qty).trigger('change');
                }
            });
            state.touched = true;
            render(state, dom);
        }
    }

    // Remove a single donut of one flavour (used by the list view's per-row
    // remove button), with a one-tap Undo.
    function removeOneUndo(state, dom, order) {
        var item = findItem(state, order);
        if (!item) { return; }
        var snap = snapshotBox(state);
        removeOne(state, dom, order);
        toastUndo(dom, removedMsg(item), function () { restoreBox(state, dom, snap); });
    }

    // Empty one specific box slot, leaving its gap in place.
    function removeAt(state, dom, position) {
        if (!state.layout) { return; }
        position = parseInt(position, 10);
        if (isNaN(position) || state.layout[position] == null) { return; }
        var snap = snapshotBox(state);
        var removed = findItem(state, state.layout[position]);
        state.layout[position] = null;
        commitLayout(state, dom);
        toastUndo(dom, removedMsg(removed), function () { restoreBox(state, dom, snap); });
    }

    // After a drag-reorder inside the box, rebuild the positional layout from the
    // new DOM order of slots (empty slots map to null) so the visual order sticks.
    function syncLayoutFromDom(state, dom) {
        if (!state.layout) { return; }
        var orders = [];
        dom.slots.children('.rd-bb-slot').each(function () {
            var o = this.getAttribute('data-order');
            orders.push((o == null || o === '') ? null : parseInt(o, 10));
        });
        while (orders.length < state.capacity) { orders.push(null); }
        state.layout = orders.slice(0, state.capacity);
    }

    function findItem(state, order) {
        order = parseInt(order, 10);
        for (var i = 0; i < state.items.length; i++) {
            if (state.items[i].order === order) { return state.items[i]; }
        }
        return null;
    }

    // Discard any in-builder customisation and put every item back to the
    // bundle's default quantity. Used when the customer closes builder mode so
    // the static "non-builder" screen always shows the default box selection.
    function restoreDefaults(state, dom) {
        state.items.forEach(function (item) {
            if (!item.editable) { return; }
            if (item.qty !== item.defaultQty) {
                item.qty = item.defaultQty;
                item.$input.val(item.defaultQty).trigger('change');
            }
        });
        state.layout = buildLayout(state);
        state.touched = false;
    }

    function clearBox(state, dom) {
        var snap = snapshotBox(state);

        if (state.layout) {
            for (var i = 0; i < state.layout.length; i++) { state.layout[i] = null; }
            commitLayout(state, dom);
        } else {
            state.items.forEach(function (item) {
                if (item.editable && item.qty !== 0) {
                    item.qty = 0;
                    item.$input.val(0).trigger('change');
                }
            });
            state.touched = true;
            render(state, dom);
        }

        toastUndo(dom, i18n.boxCleared || 'Box cleared', function () { restoreBox(state, dom, snap); });
    }

    /* ----------------------------------------------------------------- render */

    function render(state, dom) {
        var current = total(state);

        dom.countCurrent.text(current);
        dom.countMax.text(state.capacity > 0 ? state.capacity : '');

        updateProgress(state, dom, current);
        renderSummary(state, dom);
        renderSlots(state, dom, current);
        renderPicker(state, dom, current);
        syncAddToCart(state, dom, current);
        syncTitlePrice(state, dom);
        updateScrollHint(dom);
        updateAllergenAccordion(state);
    }

    // Keep the server-rendered "Allergen Info" accordion in sync with the box.
    // The accordion (rendered by box-builder-woo below the cart button) starts
    // with the default box's flavours; here we rebuild it to list exactly the
    // flavours currently in the box, with their allergens. Only the builder's
    // accordion is touched (it carries the data-rd-bb-allergens flag).
    function updateAllergenAccordion(state) {
        var $accordion = $('#allergen-info-open[data-rd-bb-allergens]');
        if (!$accordion.length) { return; }

        var $list = $accordion.find('#allergen-info-open-list');
        if (!$list.length) { return; }

        var rows = '';
        var seen = {};

        state.items.forEach(function (item) {
            if (item.qty <= 0) { return; }

            var names = (item.allergens || []).map(function (a) { return a.name; }).filter(Boolean);
            var key = item.name + ':' + names.join(',');
            if (seen[key]) { return; }
            seen[key] = true;

            var detail = names.length ? names.join(', ') : (i18n.noAllergens || 'No allergens found');
            rows += '<div class="flex flex-wrap items-center py-4 border-t border-solid border-grey-disabled">'
                + '<span class="flex items-start font-medium text-black-full text-sm-font lg:text-base-font">'
                + esc(item.name) + ': ' + esc(detail)
                + '</span></div>';
        });

        if (rows === '') {
            // Empty box: hide the accordion entirely rather than show a heading
            // with nothing under it.
            $accordion.prop('hidden', true).addClass('rd-bb-allergens-empty');
            $list.empty();
            return;
        }

        $accordion.prop('hidden', false).removeClass('rd-bb-allergens-empty');
        $list.html(rows);
    }

    // Fill-progress bar + "add N more to complete your box" guidance under the
    // box heading. Capacity-limited boxes only (unlimited boxes have no target).
    function updateProgress(state, dom, current) {
        if (!dom.progress || !dom.progress.length) { return; }

        if (state.capacity <= 0) {
            dom.progress.prop('hidden', true);
            return;
        }
        dom.progress.prop('hidden', false);

        var pct = Math.max(0, Math.min(100, Math.round((current / state.capacity) * 100)));
        var remaining = state.capacity - current;
        var complete = remaining <= 0;

        if (dom.progressFill && dom.progressFill.length) {
            dom.progressFill.css('width', pct + '%');
        }
        dom.progress.toggleClass('rd-bb-progress--complete', complete);

        var msg;
        if (complete) {
            msg = i18n.boxComplete || 'Your box is complete!';
        } else if (remaining === 1) {
            msg = i18n.addOneMore || 'Add 1 more to complete your box';
        } else {
            msg = (i18n.addMore || 'Add %d more to complete your box').replace('%d', remaining);
        }

        if (dom.progressText && dom.progressText.length) {
            dom.progressText.text(msg);
        }
    }

    // Pulsing translucent arrow shown when the picker can still be scrolled, so
    // customers know there are more flavours below the fold.
    function setupScrollHint(state, dom) {
        if (!dom.picker || !dom.picker.length) { return; }

        dom.scrollHint = $('<div class="rd-bb-scroll-hint is-hidden" aria-hidden="true">'
            + '<span class="rd-bb-scroll-hint-inner">' + CHEVRON_DOWN_ICON + '</span>'
            + '</div>').appendTo(dom.picker);

        dom.picker.on('scroll', function () { updateScrollHint(dom); });
        $(window).on('resize', function () { updateScrollHint(dom); });
    }

    function updateScrollHint(dom) {
        if (!dom.scrollHint || !dom.scrollHint.length) { return; }

        var el = dom.picker.get(0);
        if (!el) { return; }

        var overflow = el.scrollHeight - el.clientHeight;
        var atBottom = (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 8);
        var show = overflow > 24 && !atBottom;

        dom.scrollHint.toggleClass('is-hidden', !show);
    }

    // Mirror WPC's live box total (.woosb-sync-price) next to the builder title
    // (desktop) and in the mobile head shown under the close button.
    function syncTitlePrice(state, dom) {
        var $price = $('.woosb-sync-price').first();
        if (!$price.length) { return; }

        var html = $price.html();

        if (dom.title && dom.title.length) {
            var $target = dom.title.find('.rd-bb-title-price');
            if (!$target.length) {
                $target = $('<span class="rd-bb-title-price"></span>').appendTo(dom.title);
            }
            $target.html(html);
        }

        $('.rd-bb-mobile-price').html(html);
    }

    // Static, non-editable view shown by default. Reuses WPC's `.woosb-products`
    // markup/classes so it inherits the exact theme styling of a fixed bundle
    // (e.g. holy-communion) — a plain "N × Name" list with no quantity steppers.
    function renderSummary(state, dom) {
        if (!dom.summary || !dom.summary.length) { return; }

        var rows = '';
        state.items.forEach(function (item) {
            if (item.qty <= 0) { return; }
            rows += '<div class="woosb-product">'
                + '<div class="woosb-thumb"><div class="woosb-thumb-ori">'
                + '<img src="' + esc(item.thumb) + '" alt="' + esc(item.name) + '">'
                + '</div></div>'
                + '<div class="woosb-title"><div class="woosb-name">' + item.qty + ' &times; ' + esc(item.name) + '</div></div>'
                + '<div class="woosb-price"><div class="woosb-price-ori">' + (item.priceHtml || '') + '</div></div>'
                + '</div>';
        });

        dom.summary.html('<div class="woosb-products">' + rows + '</div>');
    }

    function emptySlotHtml(dom, position) {
        return '<div class="rd-bb-slot rd-bb-slot--empty" role="listitem" data-position="' + position + '">'
            + '<img class="rd-bb-slot-img" src="' + esc(dom.placeholder) + '" alt="" aria-hidden="true">'
            + '</div>';
    }

    function filledSlotHtml(item, position) {
        return '<div class="rd-bb-slot rd-bb-slot--filled" role="listitem" data-position="' + position + '" data-order="' + item.order + '">'
            + '<img class="rd-bb-slot-img" src="' + esc(item.thumb) + '" alt="' + esc(item.name) + '">'
            + '<span class="rd-bb-slot-tip">' + esc(item.name) + '</span>'
            + (item.editable
                ? '<button type="button" class="rd-bb-slot-remove" data-position="' + position + '" data-order="' + item.order + '" aria-label="' + esc((i18n.remove || 'Remove') + ' ' + item.name) + '">' + REMOVE_ICON + '</button>'
                : '')
            + '</div>';
    }

    // "List" view of the box: one row per donut (never collapsed to "×N"), each
    // with its name and a remove button that takes a single donut out of the box.
    function listRowHtml(item) {
        var removeBtn = item.editable
            ? '<button type="button" class="rd-bb-list-remove" data-order="' + item.order + '" aria-label="' + esc((i18n.remove || 'Remove') + ' ' + item.name) + '">' + REMOVE_ICON + '</button>'
            : '';
        return '<div class="rd-bb-list-row" data-order="' + item.order + '" role="listitem">'
            + '<img class="rd-bb-list-thumb" src="' + esc(item.thumb) + '" alt="" aria-hidden="true">'
            + '<span class="rd-bb-list-name">' + esc(item.name) + '</span>'
            + removeBtn
            + '</div>';
    }

    // Empty placeholder row for list view — mirrors the grid's empty slot so the
    // box still shows the donut placeholder image plus an "Add Flavour to Box"
    // prompt, and gives drag-and-drop a visible target to drop onto.
    function listEmptyRowHtml(dom) {
        return '<div class="rd-bb-list-row rd-bb-list-row--empty" role="listitem">'
            + '<img class="rd-bb-list-thumb" src="' + esc(dom.placeholder) + '" alt="" aria-hidden="true" draggable="false">'
            + '<span class="rd-bb-list-name rd-bb-list-add-text">' + esc(i18n.listAdd || 'Add Flavour to Box') + '</span>'
            + '</div>';
    }

    function renderListRows(state, dom) {
        var html = '';
        state.items.forEach(function (item) {
            if (item.qty <= 0) { return; }
            // One row per donut, so a box of 20 reads as 20 individual donuts
            // rather than a single "Dubai Kinder ×20" line.
            for (var n = 0; n < item.qty; n++) {
                html += listRowHtml(item);
            }
        });

        // Capacity-limited boxes keep a row per slot, so each removed/free slot
        // shows a placeholder ready to be filled (just like the grid view).
        var empties = (state.capacity > 0) ? (state.capacity - total(state)) : 0;
        for (var i = 0; i < empties; i++) {
            html += listEmptyRowHtml(dom);
        }

        // Only unlimited boxes (no fixed slots) can end up with nothing to show.
        if (html === '') {
            html = '<p class="rd-bb-list-empty">' + esc(i18n.listEmpty || 'Your box is empty. Add flavours to get started.') + '</p>';
        }

        dom.slots.html(html);
    }

    function renderSlots(state, dom, current) {
        var html = '';

        // List view: ignore positional slots and just list the chosen flavours.
        if (state.boxView === 'list') {
            renderListRows(state, dom);
            return;
        }

        // Positional render: each box position keeps its slot, so a removed
        // donut leaves an empty placeholder exactly where it was.
        if (state.layout) {
            state.layout.forEach(function (order, position) {
                var item = (order == null) ? null : findItem(state, order);
                html += item ? filledSlotHtml(item, position) : emptySlotHtml(dom, position);
            });
            dom.slots.html(html);
            return;
        }

        // Fallback (unlimited boxes): group by item, no fixed slots.
        var pos = 0;
        state.items.forEach(function (item) {
            for (var n = 0; n < item.qty; n++) {
                html += filledSlotHtml(item, pos++);
            }
        });

        dom.slots.html(html);
    }

    // One-time render of the category filter chips above the picker.
    function renderFilter(state, dom) {
        if (!dom.filter || !dom.filter.length || !categories.length) {
            return;
        }

        var html = '<button type="button" class="rd-bb-chip is-active" data-cat="">'
            + esc(i18n.all || 'All') + '</button>';

        categories.forEach(function (cat) {
            html += '<button type="button" class="rd-bb-chip" data-cat="' + esc(cat.slug) + '">'
                + esc(cat.name) + '</button>';
        });

        dom.filter.html(html);
    }

    function setFilter(state, dom, slug) {
        state.filter = slug || '';
        dom.filter.find('.rd-bb-chip').each(function () {
            var $chip = $(this);
            $chip.toggleClass('is-active', ($chip.attr('data-cat') || '') === state.filter);
        });
        renderPicker(state, dom, total(state));
    }

    // Allergen info badge + panel for a picker card. Mirrors the theme's
    // donut-card allergen toggle (info icon top-right, panel with icons/names).
    function allergenHtml(item) {
        if (!item.allergens || !item.allergens.length) { return ''; }

        var rows = '';
        item.allergens.forEach(function (a) {
            rows += '<div class="rd-bb-allergen-item">'
                + (a.icon ? '<img src="' + esc(a.icon) + '" alt="" aria-hidden="true">' : '')
                + '<span>' + esc(a.name) + '</span>'
                + '</div>';
        });

        return '<button type="button" class="rd-bb-allergen-toggle" aria-label="' + esc(i18n.allergenInfo || 'Allergen info') + '" aria-expanded="false">'
            + '<span class="rd-bb-allergen-icon rd-bb-allergen-icon--info">' + ALLERGEN_INFO_ICON + '</span>'
            + '<span class="rd-bb-allergen-icon rd-bb-allergen-icon--close">' + ALLERGEN_CLOSE_ICON + '</span>'
            + '</button>'
            + '<div class="rd-bb-allergen-panel" hidden>'
            + '<span class="rd-bb-allergen-title">' + esc(i18n.allergens || 'Allergens') + '</span>'
            + '<div class="rd-bb-allergen-list">' + rows + '</div>'
            + '</div>';
    }

    function badgePill(modifier, label, text) {
        return '<span class="rd-bb-diet rd-bb-diet--' + modifier + '" title="' + esc(label) + '" aria-label="' + esc(label) + '">' + esc(text) + '</span>';
    }

    // At-a-glance card badges: status badges (best seller / trending / new) come
    // from server-side sales analytics, dietary badges (VG / GF) are derived from
    // the flavour's product categories and a name fallback. All are data-driven:
    // a badge only shows when the data actually backs it, so we never over-claim.
    function cardBadges(item) {
        var flags = item.flags || {};
        var hay = ((item.cats || []).join(' ') + ' ' + String(item.name || '')).toLowerCase();
        var out = '';

        if (flags.bestseller) {
            out += badgePill('best', i18n.bestseller || 'Best Seller', i18n.bestseller || 'Best Seller');
        }
        if (flags.trending) {
            out += badgePill('trending', i18n.trending || 'Trending', i18n.trending || 'Trending');
        }
        if (flags['new']) {
            out += badgePill('new', i18n.newFlavour || 'New', i18n.newFlavour || 'New');
        }
        if (hay.indexOf('vegan') !== -1) {
            out += badgePill('vg', i18n.vegan || 'Vegan', 'VG');
        }
        if (hay.indexOf('gluten-free') !== -1 || hay.indexOf('gluten free') !== -1 || hay.indexOf('gluten_free') !== -1) {
            out += badgePill('gf', i18n.glutenFree || 'Gluten free', 'GF');
        }

        return out ? '<div class="rd-bb-card-diet">' + out + '</div>' : '';
    }

    function isVeganItem(item) {
        var hay = ((item.cats || []).join(' ') + ' ' + String(item.name || '')).toLowerCase();
        return hay.indexOf('vegan') !== -1;
    }

    // Picker ordering. Vegan flavours are grouped last for every mode EXCEPT the
    // "Vegan first" mode, which surfaces them at the top. Within each group the
    // chosen sort applies — name A–Z / Z–A, or lifetime sales for "Most popular"
    // (ties broken alphabetically so the order stays stable).
    function compareItems(a, b, mode) {
        var aV = isVeganItem(a);
        var bV = isVeganItem(b);
        if (aV !== bV) {
            if (mode === 'vegan') { return aV ? -1 : 1; }
            return aV ? 1 : -1;
        }

        var nameCmp = String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' });

        if (mode === 'popularity') {
            var sa = a.sales || 0;
            var sb = b.sales || 0;
            if (sb !== sa) { return sb - sa; }
            return nameCmp;
        }

        return mode === 'za' ? -nameCmp : nameCmp;
    }

    function renderPicker(state, dom, current) {
        var html = '';
        var isFull = state.capacity > 0 && current >= state.capacity;
        var shown = 0;

        // Reflect "box full" on the picker so the whole panel can signal it
        // (banner + blocked buttons), making the greyed-out state self-explaining.
        dom.picker.toggleClass('rd-bb-picker--full', isFull);

        var visible = state.items.filter(function (item) {
            if (!item.editable) { return false; }
            // "Currently Selected" narrows the picker to flavours already in the box.
            if (state.sort === 'selected' && !(item.qty > 0)) { return false; }
            if (state.filter && item.cats.indexOf(state.filter) === -1) { return false; }
            if (state.search && String(item.name).toLowerCase().indexOf(state.search) === -1) { return false; }
            return true;
        });

        visible.sort(function (a, b) { return compareItems(a, b, state.sort); });

        visible.forEach(function (item) {
            shown++;

            var atItemMax = item.max > 0 && item.qty >= item.max;
            var addBlocked = isFull || atItemMax;

            // When blocked we keep the + clickable (aria-disabled instead of the
            // disabled attribute) so a tap still fires addOne() and surfaces a
            // toast explaining why, rather than a silent dead button.
            var blockAttr = addBlocked ? ' aria-disabled="true" class="rd-bb-step rd-bb-step--plus is-blocked"' : ' class="rd-bb-step rd-bb-step--plus"';

            var inBox = item.qty > 0;
            var inBoxBadge = inBox
                ? '<span class="rd-bb-card-badge" aria-hidden="true">' + CHECK_ICON + '<span class="rd-bb-card-badge-count">' + item.qty + '</span></span>'
                : '';

            html += '<div class="rd-bb-card' + (addBlocked ? ' rd-bb-card--blocked' : '') + (inBox ? ' rd-bb-card--in-box' : '') + '" data-order="' + item.order + '" role="listitem">'
                + allergenHtml(item)
                + inBoxBadge
                + '<div class="rd-bb-card-thumb"><img src="' + esc(item.thumb) + '" alt="' + esc(item.name) + '"></div>'
                + '<div class="rd-bb-card-name">' + esc(item.name) + '</div>'
                + cardBadges(item)
                + '<div class="rd-bb-card-controls">'
                + '<button type="button" class="rd-bb-step rd-bb-step--minus" data-order="' + item.order + '" aria-label="-"' + (item.qty <= 0 ? ' disabled' : '') + '>&minus;</button>'
                + '<span class="rd-bb-card-qty">' + item.qty + '</span>'
                + '<button type="button"' + blockAttr + ' data-order="' + item.order + '" aria-label="+">+</button>'
                + '</div>'
                + '</div>';
        });

        if (!shown) {
            var emptyMsg;
            if (state.search) {
                emptyMsg = i18n.noMatches || 'No flavours match your search.';
            } else if (state.sort === 'selected') {
                emptyMsg = i18n.noneSelected || 'No flavours selected yet. Add a flavour to see it here.';
            } else {
                emptyMsg = i18n.noneInCategory || 'No donuts in this category.';
            }
            html = '<p class="rd-bb-empty">' + esc(emptyMsg) + '</p>';
        } else if (isFull && state.touched) {
            // Persistent banner so the reason adding is blocked is always visible.
            html = '<div class="rd-bb-full-notice" role="status">'
                + '<span class="rd-bb-full-notice-count">' + current + '/' + state.capacity + '</span>'
                + '<span class="rd-bb-full-notice-text">' + esc(i18n.fullNotice || 'Your box is full. Remove a donut to swap in a different flavour.') + '</span>'
                + '</div>' + html;
        }

        dom.pickerGrid.html(html);
    }

    function syncAddToCart(state, dom, current) {
        var $btn = dom.form.find('.single_add_to_cart_button');
        var ready = (state.capacity > 0)
            ? (current === state.capacity && !$btn.hasClass('woosb-disabled'))
            : (current > 0 && !$btn.hasClass('woosb-disabled'));

        dom.root.toggleClass('rd-bb-ready', ready);
        dom.mobileAdd.prop('disabled', !ready);
        dom.mobileBuy.prop('disabled', !ready);
        dom.form.find('.rd-buy-now-button').prop('disabled', !ready);
    }

    /* --------------------------------------------------------------------- cart
     * Add the configured box and adjust *its* quantity in the basket over AJAX,
     * so "Add to Basket" morphs in place into a −/+ stepper (no page reload).
     * WPC owns the actual cart; we only call our thin endpoints in class-cart.php.
     */

    // `key`/`qty` track the box added *in this page view only*. We don't persist
    // them: each configured box is a separate, one-off add, so reopening the
    // builder always starts from a clean "Add Box to Cart" button — even if an
    // earlier box (or any other product) is already in the basket. `addedIds` is
    // the woosb config that was added, so editing the box afterwards reverts the
    // stepper to the add button (the edited box is a different, separate one).
    var cart = { key: null, qty: 0, busy: false, ui: false, addedIds: null };

    function cartAjax() { return cfg.ajax || {}; }

    // Open the theme's cart feedback (side-cart slide-out, or notice popup) after
    // an AJAX add, mirroring what a normal add-to-cart would do.
    function openCartFeedback() {
        if (typeof window.matrixRdRefreshCartAndNotices === 'function') {
            window.matrixRdRefreshCartAndNotices();
        } else {
            $(document.body).trigger('added_to_cart');
        }
    }

    function cartPost(action, data, done) {
        var aj = cartAjax();
        if (!aj.url || !action) { done(null); return; }
        $.ajax({
            url: aj.url,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: aj.nonce }, data)
        }).done(function (res) {
            done(res && res.success ? res.data : null, res);
        }).fail(function () { done(null); });
    }

    // Let WooCommerce/the theme refresh the mini-cart + count after a change.
    function refreshFragments() {
        $(document.body).trigger('wc_fragment_refresh');
    }

    // First-party usage tracking (no third party). Fire-and-forget: a failed or
    // missing counter must never affect the shopper, so errors are swallowed.
    function trackEvent(event) {
        var aj = cartAjax();
        if (!aj.url || !aj.track || !cfg.productId) { return; }
        cartPost(aj.track, { product_id: cfg.productId, event: event }, function () {});
    }

    function stepperHtml(extraClass) {
        return '<div class="rd-bb-cart-stepper ' + extraClass + '">'
            + '<button type="button" class="rd-bb-cart-step rd-bb-cart-step--minus" aria-label="' + esc(i18n.decrease || 'Decrease quantity') + '">&minus;</button>'
            + '<span class="rd-bb-cart-qtywrap"><span class="rd-bb-cart-qty">0</span></span>'
            + '<button type="button" class="rd-bb-cart-step rd-bb-cart-step--plus" aria-label="' + esc(i18n.increase || 'Increase quantity') + '">+</button>'
            + '</div>';
    }

    function buildCartUI(state, dom) {
        if (cart.ui) { return; }
        cart.ui = true;

        var $btn = dom.form.find('.single_add_to_cart_button').first();
        if ($btn.length) { $btn.after(stepperHtml('rd-bb-cart-stepper--main')); }
        if (dom.mobileAdd.length) { dom.mobileAdd.after(stepperHtml('rd-bb-cart-stepper--mobile')); }

        $(document).on('click', '.rd-bb-cart-step--plus', function () {
            cartSetQty(state, dom, cart.qty + 1);
        });
        $(document).on('click', '.rd-bb-cart-step--minus', function () {
            cartSetQty(state, dom, cart.qty - 1);
        });
    }

    function renderCart(dom) {
        var inCart = !!(cart.key && cart.qty > 0);
        dom.form.toggleClass('rd-bb-in-cart', inCart);
        dom.mobilebar.toggleClass('rd-bb-in-cart', inCart);
        $('.rd-bb-cart-qty').text(cart.qty);

        // Once a box is in the basket the add button morphs into the −/+ stepper,
        // so the Buy Now button becomes a "Checkout" button instead. This keeps a
        // visible path to checkout even if the customer dismisses the side-cart.
        var $buy = dom.form.find('.rd-buy-now-button').add(dom.mobileBuy);
        $buy.each(function () {
            var $b = $(this);
            if ($b.data('rdBuyLabel') == null) { $b.data('rdBuyLabel', $b.html()); }
            if ($b.data('rdLabel') != null) { return; } // mid "Processing…" swap
            $b.html(inCart ? esc(i18n.checkout || 'Checkout') : $b.data('rdBuyLabel'));
        });
        $buy.toggleClass('rd-bb-checkout-mode', inCart);
    }

    // If the box is edited after being added, it's now a different, separate box.
    // Drop our reference to the added line (it stays in the basket, managed from
    // the side cart) and show "Add Box to Cart" again so the new box can be added.
    function resetCartIfConfigChanged(dom) {
        if (!cart.key) { return; }
        var ids = dom.form.find('input[name="woosb_ids"]').first().val() || '';
        if (ids !== cart.addedIds) {
            cart.key = null;
            cart.qty = 0;
            cart.addedIds = null;
            renderCart(dom);
        }
    }

    function setCartBusy(dom, busy) {
        dom.form.toggleClass('rd-bb-cart-busy', busy);
        dom.mobilebar.toggleClass('rd-bb-cart-busy', busy);
    }

    // The add/buy buttons all live in or beside the cart form.
    function actionButtons(dom) {
        return dom.form.find('.single_add_to_cart_button, .rd-buy-now-button')
            .add(dom.mobileAdd).add(dom.mobileBuy);
    }

    // Swap the clicked action button to a "Processing…" label while the
    // add/checkout request is in flight, so the wait before the side-cart opens
    // has feedback. Only the button the customer pressed shows "Processing…"; the
    // other action buttons are greyed out so it's clear which action is running.
    // The original markup is stashed and restored once the request settles.
    // `active` is the DOM element that was clicked; if omitted every action
    // button falls back to showing "Processing…" (older behaviour).
    function setProcessing(dom, on, active) {
        var $active = active ? $(active) : $();
        actionButtons(dom).each(function () {
            var $b = $(this);
            var isActive = $active.length === 0 || $b.is($active);
            if (on) {
                if (isActive) {
                    if ($b.data('rdLabel') == null) { $b.data('rdLabel', $b.html()); }
                    $b.attr('aria-busy', 'true').html(esc(i18n.processing || 'Processing…'));
                } else {
                    // Grey out the buttons the customer didn't click. Track the ones
                    // we disable here so restoring never re-enables a button that was
                    // already disabled for another reason (e.g. an incomplete box).
                    if (!$b.prop('disabled')) {
                        $b.data('rdBusyDisabled', true).prop('disabled', true);
                    }
                    $b.addClass('rd-bb-busy-disabled');
                }
            } else {
                var orig = $b.data('rdLabel');
                if (orig != null) { $b.html(orig); $b.removeData('rdLabel'); }
                $b.removeAttr('aria-busy');
                if ($b.data('rdBusyDisabled')) {
                    $b.prop('disabled', false).removeData('rdBusyDisabled');
                }
                $b.removeClass('rd-bb-busy-disabled');
            }
        });
    }

    /* ----------------------------------------------------------- add-on options
     * Box products can carry required option fields (a team/occasion dropdown, a
     * logo upload, a customer note) rendered by box-builder-woo inside
     * #custom-product-addons. WPC owns the bundle, but these add-ons must travel
     * with our AJAX add — and required ones must block the add — otherwise the
     * box reaches checkout without the customer's selection.
     */

    function addonsRoot() { return document.getElementById('custom-product-addons'); }

    // The inline "* required" <p> sits right after each field with id `<id>_required`.
    function addonMsgEl(el) {
        return el && el.id ? document.getElementById(el.id + '_required') : null;
    }

    function showAddonError(el) {
        var isFile = el.type === 'file';
        var msg = isFile
            ? (i18n.logoRequired || 'Please upload your logo before adding this box to the cart.')
            : (i18n.optionRequired || 'Please select an option before adding this box to the cart.');
        var m = addonMsgEl(el);
        if (m) { m.textContent = msg; m.classList.remove('hidden'); }
        el.classList.add('rd-bb-addon-invalid');
        return msg;
    }

    function clearAddonError(el) {
        var m = addonMsgEl(el);
        if (m) { m.classList.add('hidden'); }
        el.classList.remove('rd-bb-addon-invalid');
    }

    // Required = native [required] or a custom dropdown group flagged data-required="1".
    function requiredAddonFields() {
        var root = addonsRoot();
        if (!root) { return []; }
        return [].slice.call(root.querySelectorAll(
            'select[required], select[data-required="1"], input[required], textarea[required]'
        )).filter(function (el) { return !el.disabled && el.offsetParent !== null; });
    }

    function validateAddons(dom) {
        var fields = requiredAddonFields();
        var ok = true, firstBad = null, firstMsg = '';
        fields.forEach(function (el) {
            clearAddonError(el);
            var empty = (el.type === 'file') ? !(el.files && el.files.length) : !String(el.value || '').trim();
            if (empty) {
                var msg = showAddonError(el);
                ok = false;
                if (!firstBad) { firstBad = el; firstMsg = msg; }
            }
        });
        if (!ok && firstBad) {
            if (firstBad.scrollIntoView) { firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            toast(dom, firstMsg);
        }
        return ok;
    }

    // Serialise the box config + every named add-on field (files included) so the
    // server captures the selections via box-builder-woo's add-to-cart hooks.
    function addonFormData(base) {
        var fd = new FormData();
        Object.keys(base).forEach(function (k) { fd.append(k, base[k]); });
        var root = addonsRoot();
        if (root) {
            [].forEach.call(root.querySelectorAll('select[name], textarea[name], input[name]'), function (el) {
                if (el.disabled) { return; }
                if (el.type === 'file') {
                    if (el.files && el.files.length) {
                        for (var i = 0; i < el.files.length; i++) { fd.append(el.name, el.files[i]); }
                    }
                } else if (el.type === 'checkbox' || el.type === 'radio') {
                    if (el.checked) { fd.append(el.name, el.value); }
                } else {
                    fd.append(el.name, el.value);
                }
            });
        }
        return fd;
    }

    // Un-gate the bundle plugin's inline alert (suppressed on load) and make sure
    // it's visible now that the customer has attempted to add/checkout.
    function revealWoosbAlert(dom) {
        var $wrap = (dom.woosbWrap && dom.woosbWrap.length) ? dom.woosbWrap : $('.woosb-wrap').first();
        if (!$wrap.length) { return; }
        $wrap.removeClass('rd-bb-suppress-woosb-alert');
        var $alert = $wrap.find('.woosb-alert');
        if ($alert.length) { $alert.stop(true, true).slideDown(); }
    }

    function cartAdd(state, dom, opts) {
        if (cart.busy) { return; }
        opts = opts || {};

        var $btn = dom.form.find('.single_add_to_cart_button').first();
        if ($btn.is(':disabled') || $btn.hasClass('woosb-disabled') || $btn.hasClass('disabled')) {
            // They tried — now surface the bundle plugin's inline validation alert
            // (it was suppressed until this first attempt).
            revealWoosbAlert(dom);
            toast(dom, i18n.full || 'Your box isn’t ready yet.');
            return;
        }

        if (!validateAddons(dom)) { return; }

        var aj = cartAjax();
        if (!aj.url || !aj.add) { toast(dom, i18n.addError || 'Could not add to basket.'); return; }

        var ids = dom.form.find('input[name="woosb_ids"]').first().val() || '';
        var fd = addonFormData({ action: aj.add, nonce: aj.nonce, product_id: cfg.productId, quantity: 1, woosb_ids: ids });

        // The button the customer actually pressed shows "Processing…"; everything
        // else is greyed out. Fall back to the matching inline button when the
        // caller didn't pass one (e.g. a native form submit with no submitter).
        var active = opts.activeBtn
            || (opts.thenCheckout
                ? dom.form.find('.rd-buy-now-button').get(0)
                : dom.form.find('.single_add_to_cart_button').get(0));

        cart.busy = true; setCartBusy(dom, true); setProcessing(dom, true, active);

        $.ajax({
            url: aj.url,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (res) {
            var data = res && res.success ? res.data : null;
            if (data && data.cart_item_key) {
                // Count this as a completed box "use" (covers Buy Now too).
                trackEvent('add');
                // Buy Now: skip the cart + slide-out and head straight to checkout.
                if (opts.thenCheckout && cfg.checkoutUrl) {
                    refreshFragments();
                    window.location.href = cfg.checkoutUrl;
                    return;
                }
                cart.key = data.cart_item_key;
                cart.qty = data.quantity || 1;
                cart.addedIds = ids;
                refreshFragments();
                openCartFeedback();
                toast(dom, i18n.added || 'Added to your basket');
            } else {
                var msg = (res && res.data && res.data.message) || i18n.addError || 'Could not add to basket.';
                toast(dom, msg);
            }
            cart.busy = false; setCartBusy(dom, false); setProcessing(dom, false, active); renderCart(dom);
        }).fail(function () {
            toast(dom, i18n.addError || 'Could not add to basket.');
            cart.busy = false; setCartBusy(dom, false); setProcessing(dom, false, active); renderCart(dom);
        });
    }

    function cartSetQty(state, dom, qty) {
        if (cart.busy || !cart.key) { return; }
        qty = Math.max(0, qty);
        cart.busy = true; setCartBusy(dom, true);

        cartPost(cartAjax().qty, { cart_item_key: cart.key, quantity: qty }, function (data) {
            if (data) {
                if (data.removed || data.quantity <= 0) {
                    cart.key = null; cart.qty = 0; cart.addedIds = null;
                    refreshFragments();
                } else {
                    cart.qty = data.quantity;
                    refreshFragments();
                    // Mirror the initial add: surface the side-cart slide-out (or
                    // notice popup) so the customer always has a path to checkout
                    // after changing the quantity from the stepper.
                    openCartFeedback();
                }
            } else {
                toast(dom, i18n.cartError || 'Could not update your basket.');
            }
            cart.busy = false; setCartBusy(dom, false); renderCart(dom);
        });
    }

    function initCart(state, dom) {
        if (!cartAjax().url || !cfg.productId) { return; }
        buildCartUI(state, dom);
        // Deliberately start from a clean "Add Box to Cart" button on every load.
        // We don't restore a stepper from a previously-added box, nor react to
        // other products already in the basket: the box being configured now is a
        // separate, one-off add. The stepper only appears after the customer adds
        // the box they're building in this page view.
        renderCart(dom);
    }

    /* --------------------------------------------------------------- behaviour */

    function bindEvents(state, dom) {
        dom.toggle.on('click', function () {
            var willOpen = !state.active;
            setActive(state, dom, willOpen);
            // Count each genuine "Build Your Own Box" open click (not programmatic
            // reopens after the add-to-cart reload, which go straight to setActive).
            if (willOpen) { trackEvent('open'); }
        });

        dom.clear.on('click', function () {
            clearBox(state, dom);
        });

        dom.layoutToggle.on('click', function () {
            toggleBoxView(state, dom);
        });

        // List view: each row is one donut; its button removes a single donut.
        dom.slots.on('click', '.rd-bb-list-remove', function () {
            removeOneUndo(state, dom, $(this).data('order'));
        });

        // List view: tapping an empty placeholder row opens the picker (mobile
        // sheet) or scrolls the flavour grid into view on desktop.
        dom.slots.on('click', '.rd-bb-list-row--empty', function () {
            if (isMobile()) {
                openSheet(state, dom, null);
                return;
            }
            if (dom.pickerGrid && dom.pickerGrid.length && dom.pickerGrid.get(0).scrollIntoView) {
                dom.pickerGrid.get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            if (dom.search && dom.search.length) {
                dom.search.trigger('focus');
            }
        });

        dom.filter.on('click', '.rd-bb-chip', function () {
            setFilter(state, dom, $(this).attr('data-cat'));
        });

        dom.search.on('input', function () {
            state.search = String($(this).val() || '').trim().toLowerCase();
            renderPicker(state, dom, total(state));
        });

        if (dom.sort && dom.sort.length) {
            dom.sort.on('change', function () {
                state.sort = String($(this).val() || 'az');
                renderPicker(state, dom, total(state));
            });
        }

        // Compact search (small screens): the icon reveals the search bar below
        // the filter chips and focuses it.
        $(document).on('click', '.rd-bb-search-toggle', function (e) {
            e.preventDefault();
            var $controls = $(this).closest('.rd-bb-controls');
            var open = !$controls.hasClass('rd-bb-search-open');
            $controls.toggleClass('rd-bb-search-open', open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            if (open) { dom.search.trigger('focus'); }
        });

        // Help popover: toggle on the "?" button, dismiss on close/outside/Escape.
        function closeHelp() {
            $('#rd-bb-help-panel').prop('hidden', true);
            $('.rd-bb-help-toggle').attr('aria-expanded', 'false');
        }
        $(document).on('click', '.rd-bb-help-toggle', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $panel = $('#rd-bb-help-panel');
            var willOpen = $panel.prop('hidden');
            $panel.prop('hidden', !willOpen);
            $(this).attr('aria-expanded', willOpen ? 'true' : 'false');
        });
        $(document).on('click', '.rd-bb-help-close', function () { closeHelp(); });
        // "Don't show tips again" / "Show tips" toggle inside the Help panel.
        // stopPropagation keeps the panel open (the outside-click handler ignores
        // clicks within .rd-bb-help anyway, but this guards future markup moves).
        $(document).on('click', '.rd-bb-hints-off-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleHintsPref(state);
        });
        $(document).on('click', function (e) {
            if (e.target && e.target.closest && !e.target.closest('.rd-bb-help')) { closeHelp(); }
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) { closeHelp(); }
        });

        dom.pickerGrid.on('click', '.rd-bb-allergen-toggle', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $card = $(this).closest('.rd-bb-card');
            var open = !$card.hasClass('rd-bb-card--allergens');
            $card.toggleClass('rd-bb-card--allergens', open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $card.find('.rd-bb-allergen-panel').prop('hidden', !open);
        });

        dom.pickerGrid.on('click', '.rd-bb-step--plus', function () {
            // On mobile, an item added from the sheet fills the slot the customer
            // tapped to open it; the target is one-shot, so clear it after use.
            var target = state.sheetTarget;
            state.sheetTarget = null;
            addOne(state, dom, $(this).data('order'), target);
            // Once the box is full there's nothing left to add — drop the sheet so
            // the "Add Box to Cart" bar is revealed.
            if (isMobile() && $('body').hasClass('rd-bb-sheet-open')
                && state.capacity > 0 && total(state) >= state.capacity) {
                closeSheet(state, dom);
            }
        });

        dom.pickerGrid.on('click', '.rd-bb-step--minus', function () {
            removeOne(state, dom, $(this).data('order'));
        });

        dom.slots.on('click', '.rd-bb-slot-remove', function () {
            var pos = $(this).attr('data-position');
            if (state.layout && pos != null) {
                removeAt(state, dom, pos);
            } else {
                removeOne(state, dom, $(this).data('order'));
            }
        });

        // Mobile bottom-sheet picker. Tapping an empty box slot slides the flavour
        // grid up and remembers that slot as the next drop target. A dedicated
        // button opens it generally (e.g. to swap when the box is full).
        dom.slots.on('click', '.rd-bb-slot--empty', function () {
            if (isMobile()) {
                openSheet(state, dom, $(this).attr('data-position'));
                return;
            }
            // Desktop: an empty slot is a drag-and-drop target, so a plain click
            // can't add a flavour on its own. Guide the customer to drag a
            // flavour in or use the + on a flavour card. Suppressed when the
            // customer has opted out of tips.
            if (!state.hintsOff) {
                toast(dom, i18n.dragHint || 'Drag a flavour from the list into this slot, or use the + on a flavour to add it to your box.');
            }
        });

        dom.openPicker.on('click', function () {
            openSheet(state, dom, null);
        });

        dom.sheetClose.on('click', function () { closeSheet(state, dom); });
        initSheetResize(state, dom);
        dom.backdrop.on('click', function () { closeSheet(state, dom); });
        $(document).on('keydown', function (e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && $('body').hasClass('rd-bb-sheet-open')) {
                closeSheet(state, dom);
            }
        });

        dom.mobileAdd.on('click', function () {
            cartAdd(state, dom, { activeBtn: this });
        });

        dom.mobileBuy.on('click', function () {
            if (cart.key && cfg.checkoutUrl) { window.location.href = cfg.checkoutUrl; return; }
            cartAdd(state, dom, { thenCheckout: true, activeBtn: this });
        });

        // Clear an add-on field's "required" error as soon as it's filled in.
        $(document).on('change input', '#custom-product-addons select, #custom-product-addons input, #custom-product-addons textarea', function () {
            clearAddonError(this);
        });

        // Add the box over AJAX instead of the native form POST so the page (and
        // the open builder) stays put. WPC stops propagation on this button, so we
        // intercept in the capture phase (fires first) and cancel the native flow.
        // Buy Now adds the same way, then navigates straight to checkout.
        document.addEventListener('click', function (e) {
            if (!e.target || !e.target.closest) { return; }
            var buyBtn = e.target.closest('.rd-buy-now-button');
            if (buyBtn) {
                e.preventDefault();
                e.stopImmediatePropagation();
                // Already added (button reads "Checkout") → go straight to checkout.
                if (cart.key && cfg.checkoutUrl) { window.location.href = cfg.checkoutUrl; return; }
                cartAdd(state, dom, { thenCheckout: true, activeBtn: buyBtn });
                return;
            }
            var btn = e.target.closest('.single_add_to_cart_button');
            if (!btn) { return; }
            e.preventDefault();
            e.stopImmediatePropagation();
            cartAdd(state, dom, { activeBtn: btn });
        }, true);

        var formEl = dom.form.get(0);
        if (formEl) {
            formEl.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                // Fallback in case the click handler above didn't fire first.
                var buyNow = e.submitter && e.submitter.classList
                    && e.submitter.classList.contains('rd-buy-now-button');
                if (buyNow && cart.key && cfg.checkoutUrl) { window.location.href = cfg.checkoutUrl; return; }
                cartAdd(state, dom, buyNow
                    ? { thenCheckout: true, activeBtn: e.submitter }
                    : { activeBtn: e.submitter });
            }, true);
        }

        // Re-sync the add-to-cart state whenever WPC recalculates.
        $(document).on('woosb_change_count woosb_calc_price', function () {
            syncAddToCart(state, dom, total(state));
            syncTitlePrice(state, dom);
            resetCartIfConfigChanged(dom);
        });
    }

    // Hide the fixed mobile action bar once the real inline Add/Buy buttons (or
    // the basket stepper they morph into) scroll into view, so it never sits on
    // top of them at the bottom of the page.
    function setupMobileBarAutohide(state, dom) {
        if (!('IntersectionObserver' in window) || !dom.mobilebar.length) { return; }

        var anchor = dom.form.find('.rd-buy-now-button').get(0)
            || dom.form.find('.single_add_to_cart_button').get(0)
            || dom.form.find('.rd-bb-cart-stepper--main').get(0);
        if (!anchor) { return; }

        var bar = dom.mobilebar.get(0);
        var observer = new IntersectionObserver(function (entries) {
            bar.classList.toggle('rd-actionbar-hidden', entries[0].isIntersecting);
        }, { threshold: 0, rootMargin: '0px 0px -72px 0px' });
        observer.observe(anchor);
    }

    // Brief full-screen mask shown while the page reflows between the normal
    // product layout and the builder layout, so the customer never sees a flash
    // of the "other" layout mid-toggle. It shows instantly (no fade-in, so the
    // reflow behind it is hidden) and fades out once layout has settled. A hard
    // safety timer guarantees it can never get stuck covering the page.
    var rdbbOverlay = null;
    var rdbbOverlayTimer = null;

    function ensureOverlay() {
        if (rdbbOverlay) { return rdbbOverlay; }

        var ov = document.createElement('div');
        ov.className = 'rd-bb-overlay';

        var logo = document.querySelector('.logo.desktop-logo')
            || document.querySelector('.rd-header .logo')
            || document.querySelector('img.logo');
        if (logo && logo.src) {
            var img = document.createElement('img');
            img.src = logo.src;
            img.alt = '';
            img.setAttribute('aria-hidden', 'true');
            ov.appendChild(img);
        }

        document.body.appendChild(ov);
        rdbbOverlay = ov;
        return ov;
    }

    function showTransitionOverlay() {
        var ov = ensureOverlay();
        if (rdbbOverlayTimer) { clearTimeout(rdbbOverlayTimer); }
        ov.style.transition = 'none';   // instant cover, no see-through fade-in
        ov.classList.add('is-visible');
        void ov.offsetWidth;            // commit the instant state
        rdbbOverlayTimer = setTimeout(hideTransitionOverlay, 900); // safety
    }

    function hideTransitionOverlay() {
        if (!rdbbOverlay) { return; }
        if (rdbbOverlayTimer) { clearTimeout(rdbbOverlayTimer); rdbbOverlayTimer = null; }
        rdbbOverlay.style.transition = ''; // restore CSS fade-out
        rdbbOverlay.classList.remove('is-visible');
    }

    function scheduleOverlayHide() {
        var raf = window.requestAnimationFrame;
        if (raf) {
            raf(function () { raf(function () { setTimeout(hideTransitionOverlay, 80); }); });
        } else {
            setTimeout(hideTransitionOverlay, 160);
        }
    }

    function setActive(state, dom, on) {
        showTransitionOverlay();
        state.active = on;
        $('body').toggleClass('rd-bb-active', on);
        dom.toggle.attr('aria-pressed', on ? 'true' : 'false');
        dom.toggle.find('.rd-bb-toggle-label').text(on ? (i18n.close || i18n.viewBox || 'Close Box Builder Mode') : (i18n.buildYourOwn || 'Build Your Own Box'));
        dom.root.prop('hidden', !on);
        dom.summary.prop('hidden', on);
        dom.picker.prop('hidden', !on);
        dom.mobilebar.prop('hidden', !on);

        // Leaving builder mode always collapses the mobile sheet and reverts any
        // unsaved customisation back to the default box selection.
        if (!on) {
            closeSheet(state, dom);
            restoreDefaults(state, dom);
        }

        render(state, dom);

        // The picker only gets its scrollable height once shown; recheck the
        // scroll hint after layout settles.
        if (on && window.requestAnimationFrame) {
            window.requestAnimationFrame(function () { updateScrollHint(dom); });
        }

        // Hiding the hero/header shifts the page up; scroll to the topmost part
        // of the builder (the title, falling back to the toggle) to keep it in view.
        if (on) {
            var target = (dom.title.length && dom.title.get(0).offsetParent !== null)
                ? dom.title.get(0)
                : dom.toggle.get(0);
            if (target && target.scrollIntoView) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Reveal the settled layout once the reflow (and any image relayout) is done.
        scheduleOverlayHide();
    }

    // Tracks the in-flight drag so the box stays *locked* (no live shuffle) and
    // we only place/swap the donut once it's dropped on a specific target slot.
    var boxDrag = { fromPos: null, overPos: null, overBox: false, lastX: 0, lastY: 0 };

    // Highlight a single target slot (or clear all) while dragging.
    function highlightSlot(dom, pos) {
        dom.slots.find('.rd-bb-slot').removeClass('rd-bb-slot--drop-target');
        if (pos != null && !isNaN(pos)) {
            dom.slots.find('.rd-bb-slot[data-position="' + pos + '"]').addClass('rd-bb-slot--drop-target');
        }
    }

    function resetBoxDrag(dom) {
        boxDrag.fromPos = null;
        boxDrag.overPos = null;
        boxDrag.overBox = false;
        highlightSlot(dom, null);
        $('body').removeClass('rd-bb-dragging');
    }

    // Move a donut between slots inside the box. If the target slot already holds
    // a donut the two swap; an empty target is a plain move. Other donuts never
    // shift — only the dragged one and its target change.
    function moveOrSwap(state, dom, fromPos, toPos) {
        if (!state.layout) { return; }
        if (fromPos < 0 || toPos < 0 || fromPos >= state.layout.length || toPos >= state.layout.length) { return; }
        if (state.layout[fromPos] == null) { return; }
        var moving = state.layout[fromPos];
        state.layout[fromPos] = state.layout[toPos];
        state.layout[toPos] = moving;
        commitLayout(state, dom);
    }

    // Find which box slot (if any) sits under a screen point. The drag ghost is
    // pointer-events:none, so elementFromPoint sees through it to the slot below.
    function slotInfoFromPoint(dom, x, y) {
        var el = document.elementFromPoint(x, y);
        if (!el || !el.closest) { return { overBox: false, pos: null }; }
        var slot = el.closest('.rd-bb-slot');
        var box = el.closest('.rd-bb-slots');
        var pos = slot ? parseInt(slot.getAttribute('data-position'), 10) : null;
        return { overBox: !!(box || slot), pos: (pos != null && !isNaN(pos)) ? pos : null };
    }

    function initDragAndDrop(state, dom) {
        if (typeof window.Sortable === 'undefined') { return; }

        var boxEl = dom.slots.get(0);

        // While a drag is in flight, track the pointer ourselves and decide the
        // drop target from its position. Sortable never reorders/inserts anything
        // (sort:false, put:false, onMove:false) so EVERY donut stays exactly put;
        // the dragged donut only lands/swaps once it's released over a slot.
        function onPointerMove(e) {
            if (!$('body').hasClass('rd-bb-dragging')) { return; }
            var p = (e.touches && e.touches[0]) ? e.touches[0] : e;
            if (p.clientX == null) { return; }
            boxDrag.lastX = p.clientX;
            boxDrag.lastY = p.clientY;
            var info = slotInfoFromPoint(dom, p.clientX, p.clientY);
            boxDrag.overBox = info.overBox;
            boxDrag.overPos = info.pos;
            highlightSlot(dom, info.overBox ? info.pos : null);
        }
        document.addEventListener('mousemove', onPointerMove, true);
        document.addEventListener('touchmove', onPointerMove, true);
        document.addEventListener('pointermove', onPointerMove, true);

        // Lock everything: cancel Sortable's own move so it never shuffles the DOM.
        function lockMove() { return false; }

        // Pointer-based dragging (works on touch + desktop). The floating drag
        // image is styled down to just the donut.
        var fallbackOpts = {
            forceFallback: true,
            fallbackOnBody: true,
            fallbackTolerance: 4,
            fallbackClass: 'rd-bb-drag-ghost',
            chosenClass: 'rd-bb-chosen',
            ghostClass: 'rd-bb-placeholder'
        };

        // Picker cards are clone sources only. Releasing over the box adds the
        // flavour to the targeted slot; nothing in the box moves beforehand.
        window.Sortable.create(dom.pickerGrid.get(0), $.extend({}, fallbackOpts, {
            group: { name: 'rdbb', pull: 'clone', put: false },
            sort: false,
            draggable: '.rd-bb-card',
            filter: '.rd-bb-allergen-toggle, .rd-bb-allergen-panel, .rd-bb-step',
            preventOnFilter: false,
            onStart: function () {
                $('body').addClass('rd-bb-dragging');
                boxDrag.fromPos = null;
                boxDrag.overPos = null;
                boxDrag.overBox = false;
            },
            onMove: lockMove,
            onEnd: function (evt) {
                var order = evt.item.getAttribute('data-order');
                if (boxDrag.overBox && order != null) {
                    // Empty target → fills it; occupied/none → next empty slot.
                    addOne(state, dom, order, boxDrag.overPos);
                }
                resetBoxDrag(dom);
            }
        }));

        // The box: donuts can be rearranged, but they stay locked in place during
        // the drag and only swap with the slot the donut is released over.
        window.Sortable.create(boxEl, $.extend({}, fallbackOpts, {
            // put:false → an incoming picker clone is never inserted (no shuffle);
            // we add it manually on drop. sort:false → box items never reorder.
            group: { name: 'rdbb', pull: false, put: false },
            sort: false,
            animation: 0,
            draggable: '.rd-bb-slot',
            // Let the remove button receive its own click instead of starting a drag.
            filter: '.rd-bb-slot-remove',
            preventOnFilter: false,
            onStart: function (evt) {
                $('body').addClass('rd-bb-dragging');
                boxDrag.fromPos = parseInt(evt.item.getAttribute('data-position'), 10);
                boxDrag.overPos = null;
                boxDrag.overBox = false;
            },
            onMove: lockMove,
            onEnd: function () {
                if (boxDrag.overBox && boxDrag.overPos != null && boxDrag.fromPos != null
                    && boxDrag.overPos !== boxDrag.fromPos) {
                    moveOrSwap(state, dom, boxDrag.fromPos, boxDrag.overPos);
                }
                resetBoxDrag(dom);
            }
        }));
    }

    /* ------------------------------------------------------------------ utils */

    var toastTimer = null;

    // Plain, auto-dismissing status toast.
    function toast(dom, message) {
        showToast(message, null, 2200);
    }

    // Toast with an "Undo" action; stays up longer so the action is reachable.
    function toastUndo(dom, message, onUndo) {
        showToast(message, onUndo, 5000);
    }

    function showToast(message, onUndo, timeout) {
        var $t = $('.rd-bb-toast');
        if (!$t.length) {
            $t = $('<div class="rd-bb-toast" role="status" aria-live="polite"></div>').appendTo('body');
        }
        $t.empty();
        $('<span class="rd-bb-toast-msg"></span>').text(message).appendTo($t);

        var hasAction = (typeof onUndo === 'function');
        $t.toggleClass('rd-bb-toast--action', hasAction);
        if (hasAction) {
            $('<button type="button" class="rd-bb-toast-undo"></button>')
                .text(i18n.undo || 'Undo')
                .on('click', function () {
                    if (toastTimer) { clearTimeout(toastTimer); }
                    $t.removeClass('is-visible');
                    onUndo();
                })
                .appendTo($t);
        }

        $t.addClass('is-visible');
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { $t.removeClass('is-visible'); }, timeout);
    }

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    $(init);
})(jQuery);
