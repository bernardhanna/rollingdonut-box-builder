/**
 * In-cart box editor (main cart + slide-out side cart).
 *
 * Handlers are delegated off the document so they survive the side cart's
 * AJAX re-renders. Saving posts the add-on fields (incl. an optional logo file)
 * to admin-ajax and swaps the read-only summary in place — add-ons don't affect
 * price, so no cart reload is needed.
 */
(function () {
    'use strict';

    function cfg() {
        return window.rdBoxBuilderCart || {};
    }

    function closest(el, selector) {
        return el && el.closest ? el.closest(selector) : null;
    }

    function updateBoxContentsScroll(scrollEl) {
        if (!scrollEl) {
            return;
        }

        var wrap = closest(scrollEl, '.rd-bb-cart-contents-wrap');
        var hint = wrap ? wrap.querySelector('.rd-bb-cart-scroll-hint') : null;
        var scrollable = scrollEl.scrollHeight > scrollEl.clientHeight + 1;
        var atEnd = scrollEl.scrollTop + scrollEl.clientHeight >= scrollEl.scrollHeight - 1;

        scrollEl.classList.toggle('is-scrollable', scrollable);
        scrollEl.classList.toggle('is-at-end', atEnd);

        if (hint) {
            hint.hidden = !scrollable;
            hint.setAttribute('aria-hidden', scrollable ? 'false' : 'true');

            if (scrollable && hint.id) {
                scrollEl.setAttribute('aria-describedby', hint.id);
            } else {
                scrollEl.removeAttribute('aria-describedby');
            }
        }
    }

    function refreshAllBoxContentsScroll() {
        document.querySelectorAll('.rd-bb-cart-contents-scroll').forEach(updateBoxContentsScroll);
    }

    window.rdRefreshBoxContentsScroll = refreshAllBoxContentsScroll;

    document.addEventListener('scroll', function (event) {
        if (event.target && event.target.classList && event.target.classList.contains('rd-bb-cart-contents-scroll')) {
            updateBoxContentsScroll(event.target);
        }
    }, true);

    window.addEventListener('resize', refreshAllBoxContentsScroll);

    document.addEventListener('click', function (event) {
        var toggle = closest(event.target, '.rd-bb-cart-acc-toggle');
        if (toggle) {
            event.preventDefault();
            var acc = closest(toggle, '.rd-bb-cart-acc');
            var body = acc ? acc.querySelector('.rd-bb-cart-acc-body') : null;
            if (!body) { return; }
            var open = body.hasAttribute('hidden');
            if (open) {
                body.removeAttribute('hidden');
            } else {
                body.setAttribute('hidden', '');
            }
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            acc.classList.toggle('is-open', open);

            if (open) {
                window.requestAnimationFrame(function () {
                    refreshAllBoxContentsScroll();
                });
            }

            return;
        }

        var editBtn = closest(event.target, '.rd-bb-cart-addons-edit');
        if (editBtn) {
            event.preventDefault();
            var addons = closest(editBtn, '.rd-bb-cart-addons');
            if (!addons) { return; }
            var view = addons.querySelector('.rd-bb-cart-addons-view');
            var form = addons.querySelector('.rd-bb-cart-addons-form');
            if (view) { view.setAttribute('hidden', ''); }
            if (form) { form.removeAttribute('hidden'); }
            return;
        }

        var cancelBtn = closest(event.target, '.rd-bb-cart-addons-cancel');
        if (cancelBtn) {
            event.preventDefault();
            var addonsC = closest(cancelBtn, '.rd-bb-cart-addons');
            if (!addonsC) { return; }
            var viewC = addonsC.querySelector('.rd-bb-cart-addons-view');
            var formC = addonsC.querySelector('.rd-bb-cart-addons-form');
            if (formC) {
                formC.setAttribute('hidden', '');
                var msgC = formC.querySelector('.rd-bb-cart-form-msg');
                if (msgC) { msgC.textContent = ''; msgC.classList.remove('is-error'); }
            }
            if (viewC) { viewC.removeAttribute('hidden'); }
            return;
        }

        var saveBtn = closest(event.target, '.rd-bb-cart-addons-save');
        if (saveBtn) {
            event.preventDefault();
            saveAddons(saveBtn);
        }
    });

    /**
     * Collect the add-on fields and persist them over admin-ajax. The container is
     * a <div> (not a <form>) because the checkout order review is itself inside
     * WooCommerce's <form class="checkout"> and nested forms can't submit — so we
     * build the payload by hand instead of relying on FormData(form)/submit.
     */
    function saveAddons(saveBtn) {
        var container = closest(saveBtn, '.rd-bb-cart-addons-form');
        if (!container) { return; }

        var conf = cfg();
        if (!conf.ajaxUrl) { return; }

        var key = container.getAttribute('data-key') || '';
        var context = container.getAttribute('data-context') || 'cart';
        var msg = container.querySelector('.rd-bb-cart-form-msg');

        var data = new FormData();
        var fields = container.querySelectorAll('[name]');
        [].forEach.call(fields, function (el) {
            var name = el.getAttribute('name');
            if (!name) { return; }
            if (el.type === 'file') {
                if (el.files && el.files.length) {
                    for (var i = 0; i < el.files.length; i += 1) {
                        data.append(name, el.files[i]);
                    }
                }
            } else if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) { data.append(name, el.value); }
            } else {
                data.append(name, el.value);
            }
        });
        data.append('action', conf.action || 'rd_bb_update_addons');
        data.append('nonce', conf.nonce || '');
        data.append('cart_item_key', key);
        // Tells the server which surface saved: at checkout only the note is
        // persisted so the locked-in box options are preserved.
        data.append('context', context);

        if (msg) { msg.classList.remove('is-error'); msg.textContent = (conf.i18n && conf.i18n.saving) || 'Saving…'; }
        saveBtn.disabled = true;

        fetch(conf.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                saveBtn.disabled = false;

                if (!res || !res.success) {
                    if (msg) {
                        msg.classList.add('is-error');
                        msg.textContent = (res && res.data && res.data.message) || (conf.i18n && conf.i18n.error) || 'Error';
                    }
                    return;
                }

                var addons = closest(container, '.rd-bb-cart-addons');
                var view = addons ? addons.querySelector('.rd-bb-cart-addons-view') : null;
                if (view && res.data && res.data.summary) {
                    view.innerHTML = res.data.summary;
                }
                container.setAttribute('hidden', '');
                if (view) { view.removeAttribute('hidden'); }
                if (msg) { msg.textContent = ''; }

                // Side cart re-fetch keeps every line in sync with the server.
                if (context === 'side' && typeof window.matrixRdRefreshSideCart === 'function') {
                    window.matrixRdRefreshSideCart();
                }
            })
            .catch(function () {
                saveBtn.disabled = false;
                if (msg) {
                    msg.classList.add('is-error');
                    msg.textContent = (conf.i18n && conf.i18n.error) || 'Error';
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshAllBoxContentsScroll);
    } else {
        refreshAllBoxContentsScroll();
    }
})();
