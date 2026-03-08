/**
 * smp-ajax.js  —  v15.0.0
 * Client-side JavaScript for My Custom WC Account
 *
 * Globals injected by PHP (wp_localize_script or inline):
 *   window.SMP_AJAX_URL     — admin-ajax.php URL
 *   window.SMP_DETAIL_NONCE — nonce for smp_get_admin_details
 *   window.SMP_ORDER_NONCE  — nonce for smp_ajax_update_status
 *   window.SMP_PTS_NONCE    — nonce for checkout points handlers
 *   window.SMP_PTS_RATE     — redeem rate (pts per currency unit)
 *   window.SMP_PTS_MAXD     — max discount amount (float)
 *   window.SMP_PTS_MAX      — max redeemable points (int)
 *   window.SMP_PTS_MIN      — min redemption threshold (int)
 *   window.SMP_CUR          — currency symbol (e.g. "€")
 *   window.SMP_AUTO_APPLIED — bool: were points already applied from session?
 */

/* ============================================================================
   UTILITY — smpToast: lightweight, self-contained toast notification
   No external CSS dependency; injects its own <style> block on first use.
   ============================================================================ */

var _smpToastStyled = false;

/**
 * Display a brief, auto-dismissing toast notification.
 *
 * @param {string} message  Text to display (plain text, not HTML).
 * @param {string} type     'success' | 'error' | 'info'  (default: 'info')
 * @param {number} duration Milliseconds before auto-dismiss (default: 3500)
 */
function smpToast(message, type, duration) {
    if (!_smpToastStyled) {
        _smpToastStyled = true;
        var s = document.createElement('style');
        s.textContent =
            '.smp-toast{' +
                'position:fixed;bottom:24px;right:24px;z-index:99999;' +
                'padding:13px 20px;border-radius:8px;font-size:14px;font-weight:600;' +
                'color:#fff;max-width:360px;box-shadow:0 4px 18px rgba(0,0,0,.22);' +
                'opacity:0;transform:translateY(8px);pointer-events:none;' +
                'transition:opacity .3s ease,transform .3s ease;' +
            '}' +
            '.smp-toast.smp-toast-show{opacity:1;transform:translateY(0);}' +
            '.smp-toast-success{background:#16a34a;}' +
            '.smp-toast-error  {background:#dc2626;}' +
            '.smp-toast-info   {background:#2271b1;}';
        document.head.appendChild(s);
    }
    var ms  = typeof duration === 'number' ? duration : 3500;
    var el  = document.createElement('div');
    el.className   = 'smp-toast smp-toast-' + (type || 'info');
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(function() { el.classList.add('smp-toast-show'); }, 16);
    setTimeout(function() {
        el.classList.remove('smp-toast-show');
        setTimeout(function() { el.parentNode && el.parentNode.removeChild(el); }, 400);
    }, ms);
}

/**
 * Extract a human-readable error message from a WP AJAX JSON response.
 * Returns the msg field if present, otherwise a generic fallback.
 *
 * @param  {object|null} res  Parsed JSON response object.
 * @param  {string}      fb   Fallback string.
 * @return {string}
 */
function smpErrorMsg(res, fb) {
    if (res && res.data && res.data.msg) return res.data.msg;
    return fb || 'An unexpected error occurred. Please try again.';
}

/* ============================================================================
   SECTION A — Dashboard: Tab switcher, Order Detail, Status Updater
   ============================================================================ */

/**
 * Switch visible tab panel in the [order_lookup] dashboard.
 * @param {string} tabId  The id attribute of the target .smp-tab-content element.
 */
function smpSwitchTab(tabId) {
    jQuery('.smp-tab-link').removeClass('active');
    jQuery('.smp-tab-content').removeClass('active');
    jQuery('[data-tab="' + tabId + '"]').addClass('active');
    jQuery('#' + tabId).addClass('active');
}

/**
 * Load order detail HTML via AJAX then switch to the details tab.
 * PHP returns { success: true, data: { html: '...' } } on success,
 * or { success: false, data: { code, msg } } on error.
 *
 * @param {number} orderId  WooCommerce order ID.
 */
function smpViewDetails(orderId) {
    var $tab = jQuery('#tab-details');
    $tab.html(
        '<div style="padding:50px;text-align:center;color:#999;">' +
        '<span style="font-size:20px;display:block;margin-bottom:10px;">⏳</span>' +
        'Loading order details…</div>'
    );
    smpSwitchTab('tab-details');

    jQuery.post(window.SMP_AJAX_URL, {
        action:   'smp_get_admin_details',
        order_id: orderId,
        nonce:    window.SMP_DETAIL_NONCE
    }, function(res) {
        if (res && res.success && res.data && res.data.html) {
            $tab.html(res.data.html);
        } else {
            var msg = smpErrorMsg(res, 'Failed to load order details.');
            $tab.html(
                '<div style="margin:30px;padding:20px 24px;background:#fff1f1;border:1px solid #fca5a5;' +
                'border-radius:8px;color:#991b1b;font-size:14px;">' +
                '⚠ ' + smpEscHtml(msg) + '</div>'
            );
        }
    }, 'json').fail(function() {
        $tab.html(
            '<div style="margin:30px;padding:20px 24px;background:#fff1f1;border:1px solid #fca5a5;' +
            'border-radius:8px;color:#991b1b;font-size:14px;">' +
            '⚠ Server error — please try again or refresh the page.</div>'
        );
    });
}

/**
 * Admin-only: update order status via dropdown.
 * @param {number} id      WooCommerce order ID.
 * @param {string} status  New status slug (including "wc-" prefix).
 */
function smpUpdateStatus(id, status) {
    jQuery.post(window.SMP_AJAX_URL, {
        action:   'smp_ajax_update_status',
        order_id: id,
        status:   status,
        nonce:    window.SMP_ORDER_NONCE
    }, function(res) {
        if (res && res.success) {
            smpToast('✓ Status updated to: ' + res.data.name, 'success');
        } else {
            smpToast('⚠ ' + smpErrorMsg(res, 'Status update failed.'), 'error');
        }
    }, 'json').fail(function() {
        smpToast('⚠ Server error. Please refresh and try again.', 'error');
    });
}

/* ============================================================================
   SECTION B — Login / Register tab switcher ([smp_auth] shortcode)
   ============================================================================ */

jQuery(function($) {
    var $wrap = $('#customer_login');
    if (!$wrap.length) return;

    // Inject tab bar before the two WooCommerce columns
    $wrap.prepend(
        '<div class="smp-auth-tabs">' +
            '<button class="smp-auth-tab active" data-col=".u-column1">\uD83D\uDD10 Login</button>' +
            '<button class="smp-auth-tab"        data-col=".u-column2">\uD83D\uDCDD Register</button>' +
        '</div>'
    );

    // Show login panel by default
    $wrap.find('.u-column1').addClass('smp-tab-active');

    // Tab click: switch active panel
    $wrap.on('click', '.smp-auth-tab', function() {
        var col = $(this).data('col');
        $wrap.find('.smp-auth-tab').removeClass('active');
        $(this).addClass('active');
        $wrap.find('.u-column1, .u-column2').removeClass('smp-tab-active');
        $wrap.find(col).addClass('smp-tab-active');
    });

    // If WooCommerce redirected back with a registration error, open Register tab
    if ($wrap.find('.u-column2 .woocommerce-error').length) {
        $wrap.find('.smp-auth-tab[data-col=".u-column1"]').removeClass('active');
        $wrap.find('.smp-auth-tab[data-col=".u-column2"]').addClass('active');
        $wrap.find('.u-column1').removeClass('smp-tab-active');
        $wrap.find('.u-column2').addClass('smp-tab-active');
    }
});

/* ============================================================================
   SECTION C — Checkout Points Redemption Panel
   Requires window.SMP_AJAX_URL, SMP_PTS_NONCE, SMP_PTS_RATE, SMP_PTS_MAXD,
   SMP_PTS_MAX, SMP_PTS_MIN, SMP_CUR, SMP_AUTO_APPLIED to be set by PHP.
   ============================================================================ */

jQuery(function($) {
    // Only run on pages where the checkout panel exists
    if (!$('#smp-checkout-pts-panel').length) return;

    var AJAX    = window.SMP_AJAX_URL    || '';
    var NONCE   = window.SMP_PTS_NONCE   || '';
    var RATE    = parseFloat(window.SMP_PTS_RATE)  || 100;
    var MAXD    = parseFloat(window.SMP_PTS_MAXD)  || 0;
    var MAX_PTS = parseInt(window.SMP_PTS_MAX, 10) || 0;
    var MIN_PTS = parseInt(window.SMP_PTS_MIN, 10) || 0;
    var SYM     = window.SMP_CUR || '€';

    /**
     * smpAutoApplied: true when points are already applied from session,
     * or after the first auto-apply fires, preventing re-apply on subsequent
     * updated_checkout events and after the user explicitly removes points.
     */
    var smpAutoApplied    = !!window.SMP_AUTO_APPLIED;

    /**
     * smpDiscountActive: controls whether the preview span is shown.
     * Set to true after a successful apply, false after an explicit removal.
     */
    var smpDiscountActive = !!window.SMP_AUTO_APPLIED;

    // ── Blocks-checkout fallback: inject panel into order summary ───────── //
    function smpInjectPanel() {
        var $p = $('#smp-checkout-pts-panel');
        if (!$p.length || $p.is(':visible')) return;
        var targets = [
            'table.woocommerce-checkout-review-order-table',
            '#order_review',
            '.woocommerce-checkout-review-order',
            '.wc-block-checkout__order-summary',
            '.wp-block-woocommerce-checkout-order-summary-block',
            '.wc-block-components-totals-wrapper',
            '.wc-block-checkout',
            '.site-main',
            'form.checkout',
        ];
        for (var i = 0; i < targets.length; i++) {
            var $t = $(targets[i]);
            if ($t.length) {
                $p.insertAfter($t).show();
                return;
            }
        }
        // Absolute last resort: visible floating card
        $p.css({
            position: 'fixed', bottom: '20px', right: '20px',
            zIndex: 9999, maxWidth: '420px',
            boxShadow: '0 4px 24px rgba(0,0,0,.18)'
        }).show();
    }

    // Only inject via JS when the panel starts hidden (Blocks checkout path)
    if ($('#smp-checkout-pts-panel').is(':hidden')) {
        smpInjectPanel();
        $(document.body).on('updated_checkout', smpInjectPanel);
    }

    // ── Toggle button appearance ─────────────────────────────────────────── //
    function smpSetToggleState(applied) {
        var $btn = $('#smp_pts_toggle');
        if (applied) {
            $btn.text('Punkte entfernen')
                .removeClass('smp-pts-apply').addClass('smp-pts-clear')
                .data('state', 'applied').prop('disabled', false);
        } else {
            $btn.text('Punkte einlösen')
                .removeClass('smp-pts-clear').addClass('smp-pts-apply')
                .data('state', 'empty').prop('disabled', false);
        }
    }

    // ── Preview calculation ──────────────────────────────────────────────── //
    function updatePreview(pts) {
        var disc = MAXD > 0 ? Math.min(pts / RATE, MAXD) : pts / RATE;
        $('#smp_pts_preview').text('~' + SYM + disc.toFixed(2));
    }

    // ── Apply helper ─────────────────────────────────────────────────────── //
    function smpDoApply(pts) {
        if (pts <= 0)      { showMsg('Bitte geben Sie eine gültige Punktzahl ein.', 'error'); return; }
        if (pts < MIN_PTS) { showMsg('Mindestens ' + MIN_PTS + ' Punkte erforderlich.', 'error'); return; }
        $('#smp_pts_toggle').prop('disabled', true).text('...');
        $.post(AJAX, { action: 'smp_apply_pts_discount', points: pts, security: NONCE },
            function(res) {
                showMsg(res.data.msg, res.success ? 'success' : 'error');
                if (res.success) {
                    smpAutoApplied    = true;
                    smpDiscountActive = true;
                    smpSetToggleState(true);
                    updatePreview(pts);
                    $(document.body).trigger('update_checkout');
                } else {
                    smpSetToggleState(false);
                }
            }
        );
    }

    // ── After WC recalculates cart: refresh limits, maybe auto-apply ─────── //
    $(document.body).on('updated_checkout', function() {
        $.post(AJAX, { action: 'smp_get_pts_limits', security: NONCE }, function(res) {
            if (res.success) {
                MAXD    = res.data.max_disc;
                MAX_PTS = res.data.max_pts;
                $('#smp_pts_input').attr('max', MAX_PTS);
                if (smpDiscountActive) {
                    updatePreview(parseInt($('#smp_pts_input').val()) || 0);
                }
                if (!smpAutoApplied && MAX_PTS > 0) {
                    smpAutoApplied = true;
                    $('#smp_pts_input').val(MAX_PTS);
                    smpDoApply(MAX_PTS);
                }
            }
        });
    });

    // ── DOM-ready fallback: auto-apply after 2s if updated_checkout never fires //
    if (!smpAutoApplied && MAX_PTS > 0) {
        setTimeout(function() {
            if (!smpAutoApplied && MAX_PTS > 0) {
                smpAutoApplied = true;
                $('#smp_pts_input').val(MAX_PTS);
                smpDoApply(MAX_PTS);
            }
        }, 2000);
    }

    // ── Input change: update preview, reset button if value changes ──────── //
    $('#smp_pts_input').on('input', function() {
        var v = Math.min(Math.max(0, parseInt(this.value) || 0), MAX_PTS);
        this.value = v;
        updatePreview(v);
        if ($('#smp_pts_toggle').data('state') === 'applied') {
            $('#smp_pts_toggle')
                .text('Einlösen')
                .removeClass('smp-pts-clear').addClass('smp-pts-apply')
                .data('state', 'empty');
        }
    });

    // ── Single toggle button: apply when empty, remove when applied ──────── //
    $(document).on('click', '#smp_pts_toggle', function() {
        if ($(this).data('state') === 'applied') {
            $(this).prop('disabled', true).text('...');
            $.post(AJAX, { action: 'smp_clear_pts_discount', security: NONCE },
                function() {
                    showMsg('Punkterabatt entfernt.', 'success');
                    smpAutoApplied    = true;
                    smpDiscountActive = false;
                    $('#smp_pts_input').val(MAX_PTS);
                    $('#smp_pts_preview').text('');
                    smpSetToggleState(false);
                    $(document.body).trigger('update_checkout');
                }
            );
        } else {
            smpDoApply(parseInt($('#smp_pts_input').val()) || 0);
        }
    });

    // ── Message helper ───────────────────────────────────────────────────── //
    function showMsg(msg, type) {
        $('#smp_pts_msg').removeClass('success error').addClass(type).text(msg);
    }
});

/* ============================================================================
   SECTION D — Admin Points Management (user-edit.php)
   Requires: ajaxurl (WordPress global), jQuery
   ============================================================================ */

/**
 * Minimal HTML escaper for admin panel template literals.
 * Prevents stored XSS when note text or usernames contain HTML characters.
 * @param  {*}      str  Value to escape.
 * @return {string}
 */
function smpEscHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

jQuery(function($) {
    $('#smp-adj-btn').on('click', function() {
        var btn      = $(this);
        var amount   = parseInt($('#smp_adj_amount').val());
        var action   = $('#smp_adj_action').val();
        var note     = $('#smp_adj_note').val();
        var userId   = btn.data('user');
        var nonce    = btn.data('nonce');
        var rate     = parseFloat(btn.data('rate')) || 100;
        var $notice  = $('#smp-adj-notice');

        // Client-side validation
        if (!amount || amount < 1) {
            $notice.removeClass('success error').addClass('error')
                   .text('Please enter a valid points amount (minimum 1).').show();
            return;
        }

        btn.prop('disabled', true).text('Saving...');
        $notice.removeClass('success error').hide();

        $.post(ajaxurl, {
            action:     'smp_admin_adjust_points',
            user_id:    userId,
            amount:     amount,
            adj_action: action,
            note:       note,
            nonce:      nonce
        }, function(res) {
            btn.prop('disabled', false).text('Save Adjustment');

            if (res.success) {
                var d = res.data;

                // Update live balance display
                $('#smp-live-balance').text(d.balance.toLocaleString());
                $('#smp-live-cash').text('$' + (d.balance / rate).toFixed(2));

                // Show success message
                $notice.removeClass('error').addClass('success')
                       .text('✅ ' + d.msg + ' — New balance: ' + d.balance.toLocaleString() + ' pts').show();

                // Prepend new row to log table
                var colorMap = { add: '#16a34a', deduct: '#dc2626', set: '#16a34a' };
                var badgeCls = { add: 'add',     deduct: 'deduct',  set: 'set'     };
                var sign     = action === 'deduct' ? '-' : '+';
                var color    = colorMap[action] || '#333';
                var newRow   =
                    '<tr>' +
                        '<td>' + smpEscHtml(d.date) + '</td>' +
                        '<td><span class="smp-pts-badge ' + (badgeCls[action] || '') + '">' +
                            smpEscHtml(action.charAt(0).toUpperCase() + action.slice(1)) + '</span></td>' +
                        '<td style="font-weight:700;color:' + color + '">' + sign + amount.toLocaleString() + '</td>' +
                        '<td>' + d.balance.toLocaleString() + '</td>' +
                        '<td style="color:#666">' + smpEscHtml(note || '—') + '</td>' +
                        '<td><span class="smp-pts-source">' + smpEscHtml(d.by) + '</span></td>' +
                    '</tr>';

                $('#smp-log-empty').remove();
                $('#smp-log-body').prepend(newRow);

                // Clear inputs
                $('#smp_adj_amount').val('');
                $('#smp_adj_note').val('');

            } else {
                $notice.removeClass('success').addClass('error')
                       .text('❌ ' + res.data.msg).show();
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Save Adjustment');
            $notice.removeClass('success').addClass('error')
                   .text('❌ Server error. Please try again.').show();
        });
    });
});

/* ============================================================================
   SECTION E — Admin Order Page: Auto-fill Refund Fields
   Requires: jQuery, WC_DEC (injected by PHP as window.SMP_WC_DEC)
   ============================================================================ */

jQuery(function($) {
    // Only run on pages with the WC order items panel
    if (!$('#woocommerce-order-items').length) return;

    var WC_DEC = window.SMP_WC_DEC || '.';

    // ── Format a signed float using the store's decimal separator ─────────── //
    function smpFmt(n) {
        var f   = parseFloat(n);
        var abs = Math.abs(f).toFixed(2).replace('.', WC_DEC);
        return f < 0 ? '-' + abs : abs;
    }

    // ── Parse a WC currency string; preserveSign=true keeps "−" ──────────── //
    function smpParse(str, preserveSign) {
        str = (str || '').trim();
        var neg = /[-\u2212]/.test(str);
        str = str.replace(/[€$£¥\u00a0\u2212\-\s]/g, '').trim();
        if (/\d\.\d{3}/.test(str)) {           // DE thousands: 1.234,56
            str = str.replace(/\./g, '').replace(',', '.');
        } else if (/\d,\d{3}/.test(str)) {     // EN thousands: 1,234.56
            str = str.replace(/,/g, '');
        } else {
            str = str.replace(',', '.');        // DE decimal:   1,56
        }
        var val = parseFloat(str) || 0;
        return (preserveSign && neg) ? -val : val;
    }

    // ── Read UNSIGNED amount (product lines — always positive) ────────────── //
    function smpGetAmt($input, $viewCell) {
        var ph = parseFloat($input.attr('placeholder'));
        if (!isNaN(ph) && ph !== 0) return Math.abs(ph);
        return smpParse(($viewCell || $input.closest('td')).find('.view').text(), false);
    }

    // ── Read SIGNED amount (fees — negative fees stay negative) ──────────── //
    function smpGetSignedAmt($input, $viewCell) {
        var ph = parseFloat($input.attr('placeholder'));
        if (!isNaN(ph) && ph !== 0) return ph;
        return smpParse(($viewCell || $input.closest('td')).find('.view').text(), true);
    }

    // ── Fill every refund field in every order row ────────────────────────── //
    function smpFillAllRefunds() {

        // Product line items (tr.item)
        $('tr.item').each(function() {
            var $row = $(this);

            // Quantity
            var $qty = $row.find('input.refund_line_qty');
            if ($qty.length && ($qty.val() === '' || $qty.val() === '0')) {
                var maxQ = parseInt($qty.attr('max')) || 0;
                if (maxQ > 0) $qty.val(maxQ).trigger('change');
            }

            // Total
            var $tot = $row.find('input.refund_line_total');
            if ($tot.length && $tot.val() === '') {
                var a = smpGetAmt($tot, $row.find('.line_cost'));
                if (a > 0) $tot.val(smpFmt(a)).trigger('change');
            }

            // Tax(es)
            $row.find('input.refund_line_tax').each(function() {
                var $t = $(this);
                if ($t.val() !== '') return;
                var ta = smpGetAmt($t, null);
                if (ta > 0) $t.val(smpFmt(ta)).trigger('change');
            });
        });

        // Fee rows (tr.fee) — preserve sign: Points Discount is negative
        $('tr.fee').each(function() {
            var $row = $(this);

            // Total
            var $tot = $row.find('input.refund_line_total');
            if ($tot.length && $tot.val() === '') {
                var a = smpGetSignedAmt($tot, $row.find('.line_cost'));
                if (a !== 0) $tot.val(smpFmt(a)).trigger('change');
            }

            // Tax(es)
            $row.find('input.refund_line_tax').each(function() {
                var $t = $(this);
                if ($t.val() !== '') return;
                var ta = smpGetSignedAmt($t, null);
                if (ta !== 0) $t.val(smpFmt(ta)).trigger('change');
            });
        });
    }

    // Trigger 1: Refund button click
    $(document.body).on('click', 'button.refund-items', function() {
        setTimeout(smpFillAllRefunds, 300);
    });

    // Trigger 2: MutationObserver fires as WC shows the refund panel
    var refundPanel = document.querySelector('.wc-order-refund-items');
    if (refundPanel) {
        new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (m.attributeName === 'style' && $(refundPanel).is(':visible')) {
                    setTimeout(smpFillAllRefunds, 200);
                }
            });
        }).observe(refundPanel, { attributes: true });
    }

    // Trigger 3: WC AJAX order items reload
    $(document.body).on('wc_order_items_reload', function() {
        if ($('.wc-order-refund-items').is(':visible')) {
            setTimeout(smpFillAllRefunds, 300);
        }
    });

    // ── Rename "Fees:" → "Bonus Discount:" in order totals table ─────────── //
    function smpRenameFees() {
        $('.wc-order-totals td.label, .wc-order-totals th').each(function() {
            var $el = $(this);
            if (/^\s*Fees\s*:?\s*$/i.test($el.text())) {
                $el.text('Bonus Discount:');
            }
        });
        $('table.wc-order-totals tr td').each(function() {
            var $el = $(this);
            if (/^\s*Fees\s*:?\s*$/i.test($el.text())) {
                $el.text('Bonus Discount:');
            }
        });
    }
    smpRenameFees();
    $(document.body).on('wc_order_items_reload updated_order_items', smpRenameFees);

    // Also observe DOM changes in the totals table for HPOS/Blocks
    var totalsEl = document.querySelector('.wc-order-totals');
    if (totalsEl) {
        new MutationObserver(smpRenameFees).observe(totalsEl, { childList: true, subtree: true });
    }
});

/* ============================================================================
   SECTION F — Admin Settings Page: Tab switcher, live preview, run-now button
   (ShopCredit / Membership Settings — smp-points-settings admin page)
   ============================================================================ */

(function() {
    // Run only on the settings page
    if (!document.getElementById('smp-pts-settings-wrap')) return;

    // ── Tab switching ─────────────────────────────────────────────────────── //
    document.querySelectorAll('#smp-pts-settings-wrap .smp-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#smp-pts-settings-wrap .smp-tab-btn')
                    .forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('#smp-pts-settings-wrap .smp-tab-pane')
                    .forEach(function(p) { p.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).classList.add('active');
        });
    });

    // ── Membership number live preview ────────────────────────────────────── //
    var mnPrefix  = document.getElementById('smp-mn-prefix');
    var mnLen     = document.getElementById('smp-mn-len');
    var mnPreview = document.getElementById('smp-mn-preview');
    var mnCounter = parseInt(document.getElementById('smp-mn-preview') &&
                              document.getElementById('smp-mn-preview').dataset.counter, 10) || 0;

    function updateMnPreview() {
        if (!mnPrefix || !mnLen || !mnPreview) return;
        var p = mnPrefix.value.replace(/[^A-Za-z]/g, '').toUpperCase() || 'MBR';
        var l = Math.max(1, Math.min(10, parseInt(mnLen.value) || 6));
        var n = String(mnCounter + 1).padStart(l, '0');
        mnPreview.textContent = p + n;
    }
    if (mnPrefix) mnPrefix.addEventListener('input', updateMnPreview);
    if (mnLen)    mnLen.addEventListener('input', updateMnPreview);

    // ── Toggle label ──────────────────────────────────────────────────────── //
    var toggle = document.getElementById('smp-topup-toggle');
    var lbl    = document.getElementById('smp-topup-status-lbl');
    if (toggle && lbl) {
        toggle.addEventListener('change', function() {
            lbl.innerHTML = toggle.checked
                ? '<span style="color:#16a34a;">Enabled</span>'
                : '<span style="color:#9ca3af;">Disabled</span>';
        });
    }

    // ── Run Now button ────────────────────────────────────────────────────── //
    var runBtn    = document.getElementById('smp-run-now-btn');
    var runResult = document.getElementById('smp-run-now-result');
    var runNonce  = window.SMP_TOPUP_NONCE || '';

    if (runBtn && runResult) {
        runBtn.addEventListener('click', function() {
            if (!confirm(
                'Dispatch top-up to all qualifying users right now?\n\n' +
                'This will credit points immediately and send email notifications.'
            )) return;

            runBtn.disabled    = true;
            runBtn.textContent = '⏳ Running…';
            runResult.style.display = 'none';

            var fd = new FormData();
            fd.append('action',   'smp_topup_run_now');
            fd.append('_wpnonce', runNonce);

            fetch(window.ajaxurl || '', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    runBtn.disabled    = false;
                    runBtn.textContent = '▶ Run Top Up Now';
                    runResult.style.display = 'inline';
                    if (res.success) {
                        runResult.style.color = '#16a34a';
                        runResult.textContent  = '✓ ' + res.data.msg;
                    } else {
                        runResult.style.color = '#dc2626';
                        runResult.textContent  = '✗ ' + (res.data ? res.data.msg : 'Unknown error');
                    }
                })
                .catch(function() {
                    runBtn.disabled    = false;
                    runBtn.textContent = '▶ Run Top Up Now';
                    runResult.style.display  = 'inline';
                    runResult.style.color    = '#dc2626';
                    runResult.textContent     = '✗ Request failed — check server logs';
                });
        });
    }
}());

/* ============================================================================
   SECTION G — CustomerAnalytics: Email reveal toggle
   ============================================================================ */

(function() {
    document.querySelectorAll('.smp-email-cell').forEach(function(el) {
        el.addEventListener('click', function() {
            var showing = el.dataset.showing;
            if (showing === '1') {
                el.textContent     = el.dataset.masked;
                el.title           = 'Click to reveal';
                el.dataset.showing = '0';
            } else {
                el.textContent     = el.dataset.full;
                el.title           = 'Click to mask';
                el.dataset.showing = '1';
            }
        });
    });
}());

/* ============================================================================
   SECTION H — Responsive Header: Hamburger Menu
   Self-contained IIFE; no jQuery dependency; no global leakage.
   ============================================================================ */

(function() {
    var BREAK = 768;
    var btn   = null;
    var nav   = null;
    var menu  = null;

    function findNav() {
        return document.querySelector(
            '.main-navigation, .primary-navigation, .nav-primary, ' +
            '.site-header nav, header nav'
        );
    }

    function injectHamburger() {
        if (btn) return;
        nav  = findNav();
        if (!nav) return;
        menu = nav.querySelector('ul');
        if (!menu) return;

        btn = document.createElement('button');
        btn.className = 'smp-hamburger';
        btn.setAttribute('aria-label', 'Toggle navigation');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '<span></span><span></span><span></span>';

        (nav.parentNode || nav).insertBefore(btn, nav);

        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var open = menu.classList.toggle('smp-nav-open');
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', String(open));
        });

        document.addEventListener('click', function(e) {
            if (btn && !btn.contains(e.target) && !nav.contains(e.target)) {
                closeMenu();
            }
        });
    }

    function removeHamburger() {
        if (!btn) return;
        closeMenu();
        btn.parentNode && btn.parentNode.removeChild(btn);
        btn = null;
    }

    function closeMenu() {
        if (!menu) return;
        menu.classList.remove('smp-nav-open');
        if (btn) { btn.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false'); }
    }

    function adapt() {
        if (window.innerWidth <= BREAK) {
            injectHamburger();
        } else {
            removeHamburger();
            nav = nav || findNav();
            if (nav) {
                menu = menu || nav.querySelector('ul');
                if (menu) menu.classList.remove('smp-nav-open');
            }
        }
    }

    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(adapt, 120);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', adapt);
    } else {
        adapt();
    }
}());
