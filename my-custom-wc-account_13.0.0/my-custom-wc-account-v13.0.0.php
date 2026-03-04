<?php
/**
 * Plugin Name: My Custom WC Account Ultimate 13.0.0
 * Description: Tabbed order dashboard with admin-style details, WP User points management field, flexible points redemption at checkout, and CustomerAnalytics admin panel.
 * Version: 13.0.0
 * Author: Weiwei Chen
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Structural refactor in 13.0.0:
 * ─────────────────────────────────────────────────────────────────────────────
 *  All inline <style> and <script> blocks have been extracted into dedicated
 *  external asset files and loaded via wp_enqueue_style / wp_enqueue_script:
 *
 *    assets/css/smp-frontend.css  — frontend styles (account, checkout, shortcodes)
 *    assets/css/smp-admin.css     — admin styles (user-edit, settings, analytics)
 *    assets/js/smp-ajax.js        — all client-side JavaScript
 *
 *  PHP now passes runtime values to JS via wp_localize_script() instead of
 *  inline variable declarations.  See Section 2 for the full data map.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin root URL and path constants.
define( 'SMP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'SMP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMP_VERSION',     '13.0.0' );

// Maximum points allowed in a single admin adjustment.
// Override in wp-config.php:  define( 'SMP_MAX_ADMIN_POINTS_ADJUST', 500_000 );
if ( ! defined( 'SMP_MAX_ADMIN_POINTS_ADJUST' ) ) {
    define( 'SMP_MAX_ADMIN_POINTS_ADJUST', 1_000_000 );
}

/* ==========================================================================
   0. POINTS ENGINE
   ========================================================================== */

function smp_get_user_points( $user_id = null ) {
    if ( ! $user_id ) $user_id = get_current_user_id();
    if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
        return (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
    }
    return (int) get_user_meta( $user_id, 'smp_points', true );
}

function smp_get_user_points_from_db( $user_id = null ) {
    if ( ! $user_id ) $user_id = get_current_user_id();
    if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
        return (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
    }
    global $wpdb;
    $val = $wpdb->get_var( $wpdb->prepare(
        "SELECT CAST(meta_value AS UNSIGNED) FROM {$wpdb->usermeta}
         WHERE user_id = %d AND meta_key = 'smp_points' LIMIT 1",
        (int) $user_id
    ) );
    return (int) $val;
}

function smp_deduct_user_points( $user_id, $points, $order_id = null ) {
    if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
        $current = (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
        if ( $current < $points ) return false;
        $note = $order_id
            ? sprintf( 'Points redeemed for Order #%d', $order_id )
            : 'Points redeemed at checkout';
        WC_Points_Rewards_Manager::decrease_points( $user_id, $points, 'order-redeem', $note, $order_id );
        return true;
    }
    $current = (int) get_user_meta( $user_id, 'smp_points', true );
    if ( $current < $points ) return false;
    global $wpdb;
    $rows = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->usermeta}
         SET    meta_value = CAST(meta_value AS UNSIGNED) - %d
         WHERE  user_id  = %d AND meta_key = 'smp_points'
         AND    CAST(meta_value AS UNSIGNED) >= %d",
        (int) $points, (int) $user_id, (int) $points
    ) );
    if ( $rows > 0 ) {
        wp_cache_delete( $user_id, 'user_meta' );
        if ( function_exists( 'clean_user_cache' ) ) clean_user_cache( $user_id );
        $actor_id = get_current_user_id();
        error_log( sprintf( 'SMP_DEDUCT: actor=%d target_user=%d deducted=%d order=%s site=%s',
            $actor_id, $user_id, $points, $order_id ? (int) $order_id : 'n/a', home_url() ) );
    }
    return ( $rows > 0 );
}

function smp_add_user_points( $user_id, $points, $note = '' ) {
    if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
        WC_Points_Rewards_Manager::increase_points( $user_id, $points, 'admin-adjustment', $note ?: 'Manual adjustment by admin' );
        return true;
    }
    global $wpdb;
    $rows = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->usermeta}
         SET    meta_value = CAST(meta_value AS UNSIGNED) + %d
         WHERE  user_id = %d AND meta_key = 'smp_points'",
        (int) $points, (int) $user_id
    ) );
    if ( $rows === 0 ) update_user_meta( $user_id, 'smp_points', (int) $points );
    wp_cache_delete( $user_id, 'user_meta' );
    if ( function_exists( 'clean_user_cache' ) ) clean_user_cache( $user_id );
    return true;
}

function smp_get_points_config() {
    $defaults = [
        'earn_rate'        => 1,
        'redeem_rate'      => 100,
        'max_discount_pct' => 50,
        'min_redeem'       => 100,
        'label'            => 'Points',
    ];
    $custom = get_option( 'smp_pts_config', [] );
    if ( ! empty( $custom ) ) return wp_parse_args( $custom, $defaults );
    if ( class_exists( 'WC_Points_Rewards' ) ) {
        $options = get_option( 'wc_points_rewards' );
        if ( ! empty( $options ) ) {
            $config = $defaults;
            if ( isset( $options['earn_points_per_dollar'] ) )    $config['earn_rate']        = (float) $options['earn_points_per_dollar'];
            if ( isset( $options['redeem_points_per_dollar'] ) )  $config['redeem_rate']      = (float) $options['redeem_points_per_dollar'];
            if ( isset( $options['max_discount'] ) && $options['max_discount'] !== '' )
                                                                   $config['max_discount_pct'] = (float) $options['max_discount'];
            if ( isset( $options['minimum_points_amount'] ) )     $config['min_redeem']       = (int)   $options['minimum_points_amount'];
            if ( isset( $options['points_label'] ) )              $config['label']            = $options['points_label'];
            return $config;
        }
    }
    return $defaults;
}

function smp_calc_max_discount( $user_points, $cart_total ) {
    $cfg          = smp_get_points_config();
    $max_by_points = floor( $user_points / $cfg['redeem_rate'] * 100 ) / 100;
    $max_by_order  = round( $cart_total * ( $cfg['max_discount_pct'] / 100 ), 2 );
    return min( $max_by_points, $max_by_order );
}

/* ==========================================================================
   1. WP ADMIN USER PROFILE: POINTS MANAGEMENT FIELD
   ========================================================================== */

add_action( 'show_user_profile', 'smp_render_user_points_field' );
add_action( 'edit_user_profile', 'smp_render_user_points_field' );

function smp_render_user_points_field( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $current_pts = smp_get_user_points( $user->ID );
    $cfg         = smp_get_points_config();

    $log = get_user_meta( $user->ID, 'smp_points_log', true );
    if ( ! is_array( $log ) ) $log = [];
    $log_display = array_slice( array_reverse( $log ), 0, 10 );
    ?>
    <div id="smp-points-section">
        <div class="smp-section-head">
            <h2>🌟 Points Management</h2>
            <span style="font-size:12px;color:#666;">Plugin: My Custom WC Account V<?php echo esc_html( SMP_VERSION ); ?></span>
        </div>
        <div class="smp-section-body">

            <?php if ( class_exists('WC_Points_Rewards') ) : ?>
            <div class="smp-wc-notice">
                ⚠️ <strong>WooCommerce Points & Rewards</strong> is active. Balance is read from that plugin.
                Adjustments are also routed through its manager.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-points-rewards-manage-points&user_id=' . intval( $user->ID ) ) ); ?>">View full WC log →</a>
            </div>
            <?php endif; ?>

            <div style="display:flex;align-items:flex-end;gap:30px;flex-wrap:wrap;">
                <div>
                    <div class="smp-pts-balance" id="smp-live-balance"><?php echo esc_html( number_format( $current_pts ) ); ?></div>
                    <div class="smp-pts-balance-label"><?php echo esc_html( $cfg['label'] ); ?> | <?php echo intval( $cfg['redeem_rate'] ); ?> pts = <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>1</div>
                </div>
                <div style="font-size:13px;color:#666;line-height:1.8;">
                    <strong>Cash value:</strong> <span id="smp-live-cash">$<?php echo esc_html( number_format( $current_pts / $cfg["redeem_rate"], 2 ) ); ?></span><br>
                    <strong>Max order discount:</strong> up to <?php echo intval( $cfg['max_discount_pct'] ); ?>% of cart total<br>
                    <strong>Min to redeem:</strong> <?php echo intval( $cfg['min_redeem'] ); ?> pts
                </div>
            </div>

            <div class="smp-pts-adj-row">
                <div>
                    <label for="smp_adj_amount">Points Amount</label>
                    <input type="number" id="smp_adj_amount" min="1" placeholder="e.g. 500">
                </div>
                <div>
                    <label for="smp_adj_note">Note (optional)</label>
                    <input type="text" id="smp_adj_note" placeholder="e.g. Loyalty bonus, correction...">
                </div>
                <div>
                    <label for="smp_adj_action">Action</label>
                    <select id="smp_adj_action" style="padding:7px 10px;border:1px solid #8c8f94;border-radius:4px;">
                        <option value="add">➕ Add Points</option>
                        <option value="deduct">➖ Deduct Points</option>
                        <option value="set">🔄 Set Exact Balance</option>
                    </select>
                </div>
                <div>
                    <label>&nbsp;</label>
                    <button type="button" id="smp-adj-btn" class="button button-primary"
                        data-user="<?php echo esc_attr( $user->ID ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'smp_adjust_points_' . $user->ID ) ); ?>"
                        data-rate="<?php echo esc_attr( $cfg['redeem_rate'] ); ?>">
                        Save Adjustment
                    </button>
                </div>
            </div>

            <div id="smp-adj-notice" class="smp-adj-notice"></div>

            <div style="margin-top:25px;" id="smp-log-wrap">
                <h3 style="font-size:13px;font-weight:700;color:#1d2327;margin-bottom:10px;">Recent Adjustments (last 10)</h3>
                <table class="smp-pts-log-table">
                    <thead>
                        <tr>
                            <th>Date</th><th>Action</th><th>Amount</th>
                            <th>Balance After</th><th>Note</th><th>By</th>
                        </tr>
                    </thead>
                    <tbody id="smp-log-body">
                        <?php if ( empty( $log_display ) ) : ?>
                            <tr id="smp-log-empty"><td colspan="6" style="color:#aaa;padding:12px;">No adjustment history yet.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $log_display as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['date'] ); ?></td>
                                <td><span class="smp-pts-badge <?php echo esc_attr( $entry['action'] ); ?>"><?php echo esc_html( ucfirst($entry['action']) ); ?></span></td>
                                <td style="font-weight:700;color:<?php echo $entry['action'] === 'deduct' ? '#dc2626' : '#16a34a'; ?>">
                                    <?php echo $entry['action'] === 'deduct' ? '-' : '+'; ?><?php echo esc_html( number_format( $entry["amount"] ) ); ?>
                                </td>
                                <td><?php echo esc_html( number_format( $entry["balance_after"] ) ); ?></td>
                                <td style="color:#666;"><?php echo esc_html( $entry['note'] ); ?></td>
                                <td><span class="smp-pts-source"><?php echo esc_html( $entry['by'] ); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
    <?php
}

add_action( 'wp_ajax_smp_admin_adjust_points', function() {
    $user_id = intval( $_POST['user_id'] ?? 0 );
    if ( ! check_ajax_referer( 'smp_adjust_points_' . $user_id, 'nonce', false ) ) {
        wp_send_json_error( [ 'msg' => 'Security check failed. Please refresh the page.' ] );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'Permission denied.' ] );
    }
    $admin_rate_key = 'smp_admin_adj_rate_' . get_current_user_id();
    $admin_attempts = (int) get_transient( $admin_rate_key );
    if ( $admin_attempts >= 30 ) {
        wp_send_json_error( [ 'msg' => 'Rate limit reached. Please wait 10 minutes before making more adjustments.' ], 429 );
    }
    set_transient( $admin_rate_key, $admin_attempts + 1, 10 * MINUTE_IN_SECONDS );
    if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
        wp_send_json_error(['msg' => 'Invalid user.']);
    }
    $amount = abs( intval( $_POST['amount']     ?? 0  ) );
    $action = sanitize_key( $_POST['adj_action'] ?? '' );
    $note   = sanitize_text_field( $_POST['note'] ?? '' );
    $admin  = wp_get_current_user();
    if ( $amount < 1 ) wp_send_json_error(['msg' => 'Amount must be at least 1.']);
    if ( $amount > SMP_MAX_ADMIN_POINTS_ADJUST ) {
        wp_send_json_error(['msg' => sprintf(
            'Amount exceeds the maximum single adjustment limit (%s pts).',
            number_format( SMP_MAX_ADMIN_POINTS_ADJUST )
        )]);
    }
    $current_pts = smp_get_user_points( $user_id );
    $msg         = '';
    if ( $action === 'add' ) {
        smp_add_user_points( $user_id, $amount, $note ?: 'Manual addition by admin' );
        $msg = $amount . ' points added';
    } elseif ( $action === 'deduct' ) {
        $amount = min( $amount, $current_pts );
        smp_deduct_user_points( $user_id, $amount );
        $msg = $amount . ' points deducted';
    } elseif ( $action === 'set' ) {
        $delta = $amount - $current_pts;
        if ( $delta > 0 )      smp_add_user_points( $user_id, $delta,       $note ?: 'Balance set by admin' );
        elseif ( $delta < 0 )  smp_deduct_user_points( $user_id, abs($delta) );
        $note = $note ?: 'Balance set to ' . $amount;
        $msg  = 'Balance set to ' . $amount . ' points';
    } else {
        wp_send_json_error(['msg' => 'Unknown action.']);
    }
    $balance_after = smp_get_user_points_from_db( $user_id );
    $log = get_user_meta( $user_id, 'smp_points_log', true );
    if ( ! is_array( $log ) ) $log = [];
    $log[] = [
        'date'          => current_time( 'Y-m-d H:i' ),
        'action'        => $action,
        'amount'        => $amount,
        'balance_after' => $balance_after,
        'note'          => $note,
        'by'            => $admin->user_login,
    ];
    update_user_meta( $user_id, 'smp_points_log', array_slice( $log, -100 ) );
    wp_send_json_success([
        'msg'     => $msg,
        'balance' => $balance_after,
        'date'    => current_time( 'Y-m-d H:i' ),
        'by'      => $admin->user_login,
    ]);
});

/* ==========================================================================
   2. STYLES & FRONTEND SCRIPTS — wp_enqueue_style / wp_enqueue_script
   ========================================================================== */

/**
 * Enqueue frontend assets (CSS + JS) on account, checkout, and shortcode pages.
 * wp_localize_script() passes all PHP runtime values to JS as window globals,
 * replacing the previous inline <script> variable declarations.
 */
add_action( 'wp_enqueue_scripts', function() {

    $on_account  = function_exists( 'is_account_page' ) && is_account_page();
    $on_checkout = function_exists( 'is_checkout' )     && is_checkout();
    $load_smp    = apply_filters( 'smp_load_scripts', $on_account || $on_checkout );

    if ( ! $load_smp ) return;

    // ── CSS ─────────────────────────────────────────────────────────────── //
    wp_enqueue_style(
        'smp-frontend',
        SMP_PLUGIN_URL . 'assets/css/smp-frontend.css',
        [],
        SMP_VERSION
    );

    // ── JS ──────────────────────────────────────────────────────────────── //
    wp_enqueue_script(
        'smp-ajax',
        SMP_PLUGIN_URL . 'assets/js/smp-ajax.js',
        array('jquery'),
        SMP_VERSION,
        true   // load in footer
    );

    // ── Localize: pass PHP runtime values to JS ──────────────────────────── //
    $data = [
        'ajaxUrl' => esc_url( admin_url('admin-ajax.php') ),
    ];

    // Dashboard nonces — only on account / shortcode pages (not checkout-only)
    if ( $on_account || ( $load_smp && ! $on_checkout ) ) {
        $data['detailNonce'] = wp_create_nonce( 'smp_detail_nonce' );
        $data['orderNonce']  = wp_create_nonce( 'smp_order_nonce'  );
    }

    // Checkout points panel data — only on checkout pages
    if ( $on_checkout && is_user_logged_in()
         && ! ( function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received') ) ) {

        $user_id  = get_current_user_id();
        $user_pts = smp_get_user_points( $user_id );
        $cfg      = smp_get_points_config();

        $cart_total = 0;
        if ( WC()->cart && ! is_admin() ) {
            $cart_total = (float) WC()->cart->get_subtotal();
            if ( $cart_total <= 0 ) $cart_total = (float) WC()->cart->get_cart_contents_total();
            if ( $cart_total <= 0 ) {
                foreach ( WC()->cart->get_cart() as $item ) {
                    $cart_total += isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0;
                }
            }
        }

        $max_disc    = smp_calc_max_discount( $user_pts, $cart_total );
        $max_pts     = min( $user_pts, (int) ceil( $max_disc * $cfg['redeem_rate'] ) );
        $applied_pts = WC()->session ? (int) WC()->session->get( 'smp_pts_used', 0 ) : 0;
        $sym         = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

        $data['ptsNonce']     = wp_create_nonce( 'smp_pts_nonce' );
        $data['ptsRate']      = floatval( $cfg['redeem_rate'] > 0 ? $cfg['redeem_rate'] : 100 );
        $data['ptsMaxd']      = floatval( $max_disc );
        $data['ptsMax']       = intval( $max_pts > 0 ? $max_pts : $user_pts );
        $data['ptsMin']       = intval( $cfg['min_redeem'] );
        $data['ptsCur']       = $sym;
        $data['autoApplied']  = ( $applied_pts > 0 );
    }

    wp_localize_script( 'smp-ajax', 'SMP', $data );

    // Map SMP.* camelCase properties to the legacy SMP_* globals that smp-ajax.js Section A reads.
    wp_add_inline_script( 'smp-ajax',
        'window.SMP_AJAX_URL     = (window.SMP && window.SMP.ajaxUrl)     || "";' .
        'window.SMP_DETAIL_NONCE = (window.SMP && window.SMP.detailNonce) || "";' .
        'window.SMP_ORDER_NONCE  = (window.SMP && window.SMP.orderNonce)  || "";',
        'after'
    );
} );

/**
 * Enqueue admin assets on relevant admin pages.
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    // Load admin CSS on: user-edit, profile, our custom admin pages
    $admin_pages = [ 'profile.php', 'user-edit.php' ];
    $our_pages   = [ 'smp-customer-analytics', 'smp-points-settings' ];
    $current_page = $_GET['page'] ?? '';

    if ( ! in_array( $hook, $admin_pages, true ) && ! in_array( $current_page, $our_pages, true ) ) return;

    wp_enqueue_style(
        'smp-admin',
        SMP_PLUGIN_URL . 'assets/css/smp-admin.css',
        [],
        SMP_VERSION
    );

    wp_enqueue_script(
        'smp-ajax',
        SMP_PLUGIN_URL . 'assets/js/smp-ajax.js',
        array('jquery'),
        SMP_VERSION,
        true
    );

    // Pass WC decimal separator and topup nonce to JS
    $dec = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
    wp_localize_script( 'smp-ajax', 'SMP', [
        'ajaxUrl'    => esc_url( admin_url('admin-ajax.php') ),
        'wcDec'      => $dec,
        'topupNonce' => wp_create_nonce( 'smp_topup_run_now' ),
    ] );

    // Map SMP.* camelCase properties to the legacy SMP_* globals that smp-ajax.js Sections E/F read.
    wp_add_inline_script( 'smp-ajax',
        'window.SMP_AJAX_URL    = (window.SMP && window.SMP.ajaxUrl)    || "";' .
        'window.SMP_TOPUP_NONCE = (window.SMP && window.SMP.topupNonce) || "";' .
        'window.SMP_WC_DEC      = (window.SMP && window.SMP.wcDec)      || ".";',
        'after'
    );
} );

/* ==========================================================================
   3. AJAX: ORDER DETAIL RENDERER & STATUS UPDATER
   ========================================================================== */

add_action( 'wp_ajax_smp_get_admin_details', function() {
    check_ajax_referer( 'smp_detail_nonce', 'nonce' );

    $order_id = intval( $_POST['order_id'] ?? 0 );
    $order    = wc_get_order( $order_id );
    if ( ! $order ) wp_die( 'Invalid Order' );

    if ( ! current_user_can('manage_options') && (int) $order->get_customer_id() !== (int) get_current_user_id() )
        wp_die( 'Unauthorized', 'Forbidden', ['response' => 403] );

    $is_admin = current_user_can( 'manage_options' );
    ob_start(); ?>

    <div class="smp-detail-wrapper">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:1px solid #eee;padding-bottom:15px;">
            <h2 style="margin:0;">Order #<?php echo esc_html( $order->get_id() ); ?> Details</h2>
            <button onclick="smpSwitchTab('tab-list')" style="padding:8px 16px;cursor:pointer;border:1px solid #ddd;border-radius:6px;background:#fff;">← Back to List</button>
        </div>

        <div class="smp-admin-grid">

            <div>
                <div class="smp-section-title">General</div>
                <span class="smp-label">Date Placed</span>
                <div class="smp-value-box"><?php
                    $date_obj = $order->get_date_created();
                    echo $date_obj ? esc_html( $date_obj->date('Y-m-d H:i') ) : '—';
                ?></div>
                <span class="smp-label">Status</span>
                <?php if ( $is_admin ) : ?>
                    <select onchange="smpUpdateStatus(<?php echo intval( $order->get_id() ); ?>, this.value)"
                        style="width:100%;padding:8px;border:1px solid #2271b1;color:#2271b1;font-weight:700;border-radius:4px;">
                        <?php foreach ( wc_get_order_statuses() as $v => $n ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( 'wc-' . $order->get_status(), $v ); ?>><?php echo esc_html( $n ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <div class="smp-badge badge-<?php echo esc_attr( $order->get_status() ); ?>"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></div>
                <?php endif; ?>
                <span class="smp-label" style="margin-top:12px;">Payment Method</span>
                <div class="smp-value-box"><?php echo esc_html( $order->get_payment_method_title() ); ?></div>
            </div>

            <div>
                <div class="smp-section-title">Billing</div>
                <div style="font-size:13px;line-height:1.8;"><?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?></div>
                <div style="margin-top:10px;font-size:13px;">
                    📧 <a href="mailto:<?php echo esc_attr( $order->get_billing_email() ); ?>" style="color:#2271b1;"><?php echo esc_html( $order->get_billing_email() ); ?></a><br>
                    📞 <?php echo esc_html( $order->get_billing_phone() ); ?>
                </div>
            </div>

            <div>
                <div class="smp-section-title">Shipping</div>
                <div style="font-size:13px;line-height:1.8;">
                    <?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: 'Same as billing address.' ); ?>
                </div>
            </div>

        </div>

        <table style="width:100%;border-collapse:collapse;margin-top:10px;border:1px solid #eee;border-radius:8px;overflow:hidden;">
            <thead style="background:#f8f8f8;font-size:12px;text-transform:uppercase;color:#666;">
                <tr>
                    <th style="padding:12px;">Product</th>
                    <th>Unit Price</th>
                    <th>Qty</th>
                    <th style="text-align:right;padding-right:15px;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $order->get_items() as $item ) : ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:14px;font-weight:600;color:#2271b1;"><?php echo esc_html( $item->get_name() ); ?></td>
                    <td><?php echo wp_kses_post( wc_price( $order->get_item_subtotal($item) ) ); ?></td>
                    <td>× <?php echo intval( $item->get_quantity() ); ?></td>
                    <td style="text-align:right;padding-right:15px;font-weight:700;"><?php echo wp_kses_post( $order->get_formatted_line_subtotal($item) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot style="background:#fafafa;">
                <?php foreach ( $order->get_fees() as $fee ) : ?>
                <tr>
                    <td colspan="3" style="padding:10px 14px;font-size:13px;color:#666;"><?php echo esc_html( $fee->get_name() ); ?></td>
                    <td style="text-align:right;padding-right:15px;color:<?php echo $fee->get_total() < 0 ? '#e53e3e' : '#333'; ?>;">
                        <?php echo wp_kses_post( wc_price( $fee->get_total() ) ); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="3" style="padding:14px;font-weight:700;text-align:right;">Order Total</td>
                    <td style="text-align:right;padding-right:15px;font-size:18px;font-weight:900;color:#d32f2f;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                </tr>
            </tfoot>
        </table>

        <?php
        $pts_used     = (int)  $order->get_meta( '_smp_pts_used' );
        $pts_refunded = (bool) $order->get_meta( '_smp_points_refunded' );
        if ( $pts_used > 0 ) : ?>
        <div style="margin-top:15px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:13px;color:#166534;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span>🎁 <strong><?php echo esc_html( number_format( $pts_used ) ); ?> points</strong> were redeemed on this order.</span>
            <?php if ( $pts_refunded ) : ?>
                <span style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">✅ Points Refunded</span>
            <?php elseif ( in_array( $order->get_status(), [ 'cancelled', 'refunded' ], true ) ) : ?>
                <span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">⚠️ Refund Pending</span>
            <?php else : ?>
                <span style="background:#fef9c3;color:#854d0e;border:1px solid #fde047;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">⏳ Active</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <?php echo ob_get_clean(); wp_die();
} );

add_action( 'wp_ajax_smp_ajax_update_status', function() {
    check_ajax_referer( 'smp_order_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( ['msg' => 'Permission denied.'], 403 );
    $order = wc_get_order( intval( $_POST['order_id'] ?? 0 ) );
    if ( ! $order ) wp_send_json_error( ['msg' => 'Invalid order.'], 404 );
    $new_status = str_replace( 'wc-', '', sanitize_text_field( $_POST['status'] ?? '' ) );
    $allowed = array_keys( wc_get_order_statuses() );
    if ( ! in_array( 'wc-' . $new_status, $allowed, true ) ) wp_send_json_error( ['msg' => 'Invalid order status.'], 400 );
    $order->update_status( $new_status );
    wp_send_json_success( ['name' => wc_get_order_status_name( $new_status )] );
} );

/* ==========================================================================
   4. CHECKOUT POINTS REDEMPTION
   ========================================================================== */

function smp_render_checkout_pts_panel( $inject_via_js = false ) {
    static $rendered = false;
    if ( $rendered ) return;
    if ( ! is_user_logged_in() ) return;
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) return;

    $user_id  = get_current_user_id();
    $user_pts = smp_get_user_points( $user_id );
    if ( $user_pts <= 0 ) return;

    $cfg = smp_get_points_config();

    $cart_total = 0;
    if ( WC()->cart && ! is_admin() ) {
        $cart_total = (float) WC()->cart->get_subtotal();
        if ( $cart_total <= 0 ) $cart_total = (float) WC()->cart->get_cart_contents_total();
        if ( $cart_total <= 0 ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                $cart_total += isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0;
            }
        }
    }

    $max_disc    = smp_calc_max_discount( $user_pts, $cart_total );
    $max_pts     = min( $user_pts, (int) ceil( $max_disc * $cfg['redeem_rate'] ) );
    $applied_pts = ( WC()->session ) ? (int) WC()->session->get( 'smp_pts_used', 0 ) : 0;
    $sym         = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

    $panel_style = $inject_via_js ? 'display:none;' : '';
    ?>
    <div id="smp-checkout-pts-panel" class="smp-points-box" style="<?php echo esc_attr( $panel_style ); ?>">

        <h4>🎁 Punkte einlösen</h4>

        <div class="smp-points-summary">
            Ihr Guthaben: <strong><?php echo esc_html( number_format( $user_pts ) ); ?></strong> <?php echo esc_html( $cfg['label'] ); ?>
            &nbsp;|&nbsp; Kurs: <?php echo intval( $cfg['redeem_rate'] ); ?> Pkt. = <?php echo esc_html( $sym ); ?>1
            <?php if ( $max_disc > 0 ) : ?>
                &nbsp;|&nbsp; Max. Rabatt:
                <strong><?php echo esc_html( $sym . number_format( $max_disc, 2 ) ); ?></strong>
                (<?php echo intval( $cfg['max_discount_pct'] ); ?>% Limit)
            <?php endif; ?>
            <?php if ( $applied_pts > 0 ) : ?>
                &nbsp;&nbsp;<span style="color:#166534;font-weight:700;">✅ <?php echo esc_html( number_format( $applied_pts ) ); ?> Pkt. eingelöst</span>
            <?php endif; ?>
        </div>

        <?php
        $is_applied   = $applied_pts > 0;
        $toggle_text  = $is_applied ? 'Punkte entfernen' : 'Punkte einlösen';
        $toggle_class = $is_applied ? 'smp-pts-clear' : 'smp-pts-apply';
        $toggle_state = $is_applied ? 'applied' : 'empty';
        $preview_val  = $is_applied && $cfg['redeem_rate'] > 0
            ? '~' . $sym . number_format( min( $applied_pts / $cfg['redeem_rate'], $max_disc ), 2 )
            : '';
        ?>
        <div class="smp-points-controls" style="margin-top:14px;">
            <input type="number" id="smp_pts_input"
                min="1"
                max="<?php echo intval( $max_pts > 0 ? $max_pts : $user_pts ); ?>"
                value="<?php echo intval( $applied_pts > 0 ? $applied_pts : ( $max_pts > 0 ? $max_pts : $user_pts ) ); ?>"
                placeholder="Punkte eingeben">
            <button type="button" id="smp_pts_toggle"
                class="smp-pts-btn <?php echo esc_attr( $toggle_class ); ?>"
                data-state="<?php echo esc_attr( $toggle_state ); ?>">
                <?php echo esc_html( $toggle_text ); ?>
            </button>
            <span id="smp_pts_preview" style="font-size:13px;color:#2271b1;font-weight:700;">
                <?php echo esc_html( $preview_val ); ?>
            </span>
        </div>

        <div id="smp_pts_msg" class="smp-pts-msg"></div>
    </div>

    <?php
    // Pass checkout-specific JS data (complements what wp_localize_script already set)
    // The smp-ajax.js reads from window.SMP.*
    ?>
    <script>
    /* Runtime values for checkout panel — supplement wp_localize_script data */
    window.SMP = window.SMP || {};
    window.SMP.ajaxUrl    = '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>';
    window.SMP.ptsNonce   = '<?php echo wp_create_nonce( 'smp_pts_nonce' ); ?>';
    window.SMP.ptsRate    = <?php echo floatval( $cfg['redeem_rate'] > 0 ? $cfg['redeem_rate'] : 100 ); ?>;
    window.SMP.ptsMaxd    = <?php echo floatval( $max_disc ); ?>;
    window.SMP.ptsMax     = <?php echo intval( $max_pts > 0 ? $max_pts : $user_pts ); ?>;
    window.SMP.ptsMin     = <?php echo intval( $cfg['min_redeem'] ); ?>;
    window.SMP.ptsCur     = '<?php echo esc_js( $sym ); ?>';
    window.SMP.autoApplied = <?php echo ( $applied_pts > 0 ) ? 'true' : 'false'; ?>;
    window.SMP_AJAX_URL    = window.SMP.ajaxUrl;
    window.SMP_PTS_NONCE   = window.SMP.ptsNonce;
    window.SMP_PTS_RATE    = window.SMP.ptsRate;
    window.SMP_PTS_MAXD    = window.SMP.ptsMaxd;
    window.SMP_PTS_MAX     = window.SMP.ptsMax;
    window.SMP_PTS_MIN     = window.SMP.ptsMin;
    window.SMP_CUR         = window.SMP.ptsCur;
    window.SMP_AUTO_APPLIED = window.SMP.autoApplied;
    <?php if ( $inject_via_js ) : ?>
    /* Signal JS section C to run injection logic */
    document.getElementById('smp-checkout-pts-panel').style.display = 'none';
    <?php endif; ?>
    </script>
    <?php
    $rendered = true;
}

add_action( 'woocommerce_before_checkout_form', function() {
    smp_render_checkout_pts_panel( false );
}, 5 );

add_action( 'wp_footer', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    smp_render_checkout_pts_panel( true );
}, 5 );

add_action( 'wp_ajax_smp_get_pts_limits', function() {
    if ( ! is_user_logged_in() ) wp_send_json_error( [], 401 );
    check_ajax_referer( 'smp_pts_nonce', 'security' );
    $user_id  = get_current_user_id();
    $user_pts = smp_get_user_points( $user_id );
    $cfg      = smp_get_points_config();
    $cart_total = 0;
    if ( WC()->cart ) {
        $cart_total = (float) WC()->cart->get_subtotal();
        if ( $cart_total <= 0 ) $cart_total = (float) WC()->cart->get_cart_contents_total();
        if ( $cart_total <= 0 ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                $cart_total += isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0;
            }
        }
    }
    $max_disc = smp_calc_max_discount( $user_pts, $cart_total );
    $max_pts  = min( $user_pts, (int) ceil( $max_disc * $cfg['redeem_rate'] ) );
    wp_send_json_success( [ 'max_disc' => round( $max_disc, 2 ), 'max_pts' => $max_pts ] );
} );

add_action( 'wp_ajax_smp_apply_pts_discount', function() {
    if ( ! is_user_logged_in() ) wp_send_json_error( ['msg' => 'Authentication required.'], 401 );
    check_ajax_referer( 'smp_pts_nonce', 'security' );
    $rate_key      = 'smp_apply_rate_' . get_current_user_id();
    $rate_attempts = (int) get_transient( $rate_key );
    if ( $rate_attempts >= 10 ) wp_send_json_error( [ 'msg' => 'Zu viele Versuche. Bitte warte 5 Minuten.' ], 429 );
    set_transient( $rate_key, $rate_attempts + 1, 5 * MINUTE_IN_SECONDS );
    $pts = intval( $_POST['points'] ?? 0 );
    if ( $pts <= 0 ) wp_send_json_error( [ 'msg' => 'Ungültige Punktzahl.' ], 400 );
    $user_id  = get_current_user_id();
    $cfg      = smp_get_points_config();
    $user_pts = smp_get_user_points_from_db( $user_id );
    if ( $pts < $cfg['min_redeem'] ) wp_send_json_error( [ 'msg' => 'Mindestens ' . intval( $cfg['min_redeem'] ) . ' Punkte erforderlich.' ] );
    if ( $pts > $user_pts )          wp_send_json_error( [ 'msg' => 'Nicht genügend Punkte (Guthaben: ' . intval( $user_pts ) . ').' ] );
    $cart_total = WC()->cart ? (float) WC()->cart->get_subtotal() : 0;
    $max_disc   = smp_calc_max_discount( $user_pts, $cart_total );
    $discount   = min( round( $pts / max( 1, (int) $cfg['redeem_rate'] ), 2 ), $max_disc );
    if ( $discount <= 0 ) wp_send_json_error( [ 'msg' => 'Punkteeinlösung für diese Bestellung nicht verfügbar.' ] );
    WC()->session->set( 'smp_pts_discount', $discount );
    WC()->session->set( 'smp_pts_used',     $pts );
    $sym_decoded = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
    wp_send_json_success([
        'msg' => '✅ ' . $sym_decoded . number_format_i18n( $discount, 2 ) . ' Rabatt eingelöst (' . $pts . ' Punkte verwendet)',
    ]);
} );

add_action( 'wp_ajax_smp_clear_pts_discount', function() {
    if ( ! is_user_logged_in() ) wp_send_json_error( ['msg' => 'Authentication required.'], 401 );
    check_ajax_referer( 'smp_pts_nonce', 'security' );
    WC()->session->set( 'smp_pts_discount', null );
    WC()->session->set( 'smp_pts_used',     null );
    wp_send_json_success();
} );

add_action( 'woocommerce_cart_calculate_fees', function() {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    $discount = (float) WC()->session->get('smp_pts_discount');
    if ( $discount <= 0 ) return;
    $pts_used   = (int) WC()->session->get('smp_pts_used');
    $user_id    = get_current_user_id();
    if ( ! $user_id ) return;
    $current_db = smp_get_user_points_from_db( $user_id );
    if ( $current_db < $pts_used ) {
        WC()->session->set( 'smp_pts_discount', null );
        WC()->session->set( 'smp_pts_used',     null );
        return;
    }
    $cfg        = smp_get_points_config();
    $cart_total = WC()->cart ? (float) WC()->cart->get_subtotal() : 0;
    $max_disc   = smp_calc_max_discount( $current_db, $cart_total );
    $discount   = min( round( $pts_used / $cfg['redeem_rate'], 2 ), $max_disc );
    if ( $discount <= 0 ) {
        WC()->session->set( 'smp_pts_discount', null );
        WC()->session->set( 'smp_pts_used',     null );
        return;
    }
    WC()->session->set( 'smp_pts_discount', $discount );
    WC()->cart->add_fee( 'Points Discount (' . $pts_used . ' pts)', -$discount, false );
} );

add_action( 'woocommerce_checkout_order_processed', function( $order_id ) {
    $pts_used = (int) WC()->session->get('smp_pts_used');
    if ( $pts_used <= 0 ) return;
    $uid   = get_current_user_id();
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        WC()->session->set( 'smp_pts_discount', null );
        WC()->session->set( 'smp_pts_used',     null );
        throw new \Exception( __( 'Order could not be retrieved. Please try again or contact support.', 'smp' ) );
    }
    if ( $order->get_meta( '_smp_points_deducted' ) ) {
        WC()->session->set( 'smp_pts_discount', null );
        WC()->session->set( 'smp_pts_used',     null );
        return;
    }
    $deducted = smp_deduct_user_points( $uid, $pts_used, $order_id );
    if ( ! $deducted ) {
        $order->update_status( 'cancelled', __( 'Points deduction failed: insufficient balance at checkout.', 'smp' ) );
        $order->save();
        WC()->session->set( 'smp_pts_discount', null );
        WC()->session->set( 'smp_pts_used',     null );
        throw new \Exception( __( 'Your points balance has changed. Please refresh the page and try again.', 'smp' ) );
    }
    $order->update_meta_data( '_smp_points_deducted', 1 );
    $order->update_meta_data( '_smp_pts_used', $pts_used );
    $order->save();
    $balance_after = smp_get_user_points_from_db( $uid );
    $log = get_user_meta( $uid, 'smp_points_log', true );
    if ( ! is_array( $log ) ) $log = [];
    $log[] = [
        'date'          => current_time( 'Y-m-d H:i' ),
        'action'        => 'deduct',
        'amount'        => $pts_used,
        'balance_after' => $balance_after,
        'note'          => 'Redeemed at checkout — Order #' . $order_id,
        'by'            => 'customer',
    ];
    update_user_meta( $uid, 'smp_points_log', array_slice( $log, -100 ) );
    WC()->session->set( 'smp_pts_discount', null );
    WC()->session->set( 'smp_pts_used',     null );
} );

/* ==========================================================================
   0b. POINTS REFUND ENGINE
   ========================================================================== */

function smp_refund_points_for_order( $order_id, $reason = '' ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return false;
    $pts_used = (int) $order->get_meta( '_smp_pts_used' );
    if ( $pts_used <= 0 ) return false;
    if ( $order->get_meta( '_smp_points_refunded' ) ) return false;
    $user_id = (int) $order->get_customer_id();
    if ( $user_id <= 0 ) return false;
    if ( ! $reason ) $reason = sprintf( 'Points refunded — Order #%d cancelled/refunded', $order_id );
    $order->update_meta_data( '_smp_points_refunded', 1 );
    $order->save();
    smp_add_user_points( $user_id, $pts_used, $reason );
    $balance_after = smp_get_user_points_from_db( $user_id );
    $log = get_user_meta( $user_id, 'smp_points_log', true );
    if ( ! is_array( $log ) ) $log = [];
    $log[] = [
        'date'          => current_time( 'Y-m-d H:i' ),
        'action'        => 'refund',
        'amount'        => $pts_used,
        'balance_after' => $balance_after,
        'note'          => $reason,
        'by'            => 'system',
    ];
    update_user_meta( $user_id, 'smp_points_log', array_slice( $log, -100 ) );
    return true;
}

add_action( 'woocommerce_order_status_changed', function( $order_id, $old_status, $new_status ) {
    if ( ! in_array( $new_status, [ 'cancelled', 'refunded' ], true ) ) return;
    $reason = ( 'cancelled' === $new_status )
        ? sprintf( 'Points refunded — Order #%d cancelled',      $order_id )
        : sprintf( 'Points refunded — Order #%d fully refunded', $order_id );
    smp_refund_points_for_order( $order_id, $reason );
}, 10, 3 );

/* ==========================================================================
   4b. ADMIN ORDER PAGE: AUTO-FILL REFUND FIELDS
   (JS is now in smp-ajax.js Section E; only the WC decimal separator is
   passed via wp_localize_script in admin_enqueue_scripts above.)
   ========================================================================== */

/* ==========================================================================
   5. SHORTCODE: [order_lookup]
   ========================================================================== */

add_shortcode( 'order_lookup', function() {
    if ( ! is_user_logged_in() ) return '<p>Please log in to view your dashboard.</p>';

    $is_admin = current_user_can('manage_options');
    $orders   = wc_get_orders([
        'limit'    => 20,
        'customer' => $is_admin ? null : get_current_user_id(),
        'orderby'  => 'date',
        'order'    => 'DESC',
    ]);

    $user_pts = smp_get_user_points();
    $cfg      = smp_get_points_config();

    ob_start(); ?>
    <div class="smp-tab-container">

        <div class="smp-tab-nav">
            <div class="smp-tab-link active" data-tab="tab-list"    onclick="smpSwitchTab('tab-list')">📦 My Orders</div>
            <div class="smp-tab-link"         data-tab="tab-details" onclick="smpSwitchTab('tab-details')">📋 Order Details</div>
        </div>

        <div id="tab-list" class="smp-tab-content active">

            <?php $member_id = smp_get_member_id( get_current_user_id() ); ?>
            <div style="display:flex;align-items:stretch;gap:12px;margin-bottom:20px;flex-wrap:wrap;">

                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 20px;font-size:13px;color:#475569;display:flex;align-items:center;gap:10px;min-width:200px;">
                    <span style="font-size:18px;">🪪</span>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:2px;">Member ID</div>
                        <div style="font-size:16px;font-weight:800;letter-spacing:2px;color:#1e293b;font-family:monospace;"><?php echo esc_html( $member_id ); ?></div>
                    </div>
                </div>

                <?php if ( $user_pts >= $cfg['min_redeem'] ) : ?>
                <div style="flex:1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 20px;font-size:13px;color:#1e40af;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:18px;">🌟</span>
                    <div>
                        You have <strong><?php echo esc_html( number_format( $user_pts ) ); ?></strong> <?php echo esc_html( $cfg['label'] ); ?>
                        &nbsp;|&nbsp; Worth up to <strong><?php echo esc_html( get_woocommerce_currency_symbol() ); ?><?php echo esc_html( number_format( $user_pts / $cfg["redeem_rate"], 2 ) ); ?></strong>
                        &nbsp;(<?php echo intval( $cfg['redeem_rate'] ); ?> pts = <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>1)
                    </div>
                </div>
                <?php elseif ( $user_pts > 0 ) : ?>
                <div style="flex:1;background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px 20px;font-size:13px;color:#666;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:18px;">🌟</span>
                    <div>You have <strong><?php echo esc_html( number_format( $user_pts ) ); ?></strong> <?php echo esc_html( $cfg['label'] ); ?> — earn <?php echo intval( $cfg['min_redeem'] - $user_pts ); ?> more to start redeeming.</div>
                </div>
                <?php endif; ?>

            </div>

            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;color:#999;font-size:12px;text-transform:uppercase;border-bottom:2px solid #eee;">
                        <th style="padding:15px;">Order</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align:right;padding-right:15px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty($orders) ) : ?>
                        <tr><td colspan="4" style="padding:50px;text-align:center;color:#aaa;">No orders found.</td></tr>
                    <?php else : foreach ( $orders as $order ) : $s = $order->get_status(); ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:16px;">
                                <strong>#<?php echo esc_html( $order->get_id() ); ?></strong>
                                <?php
                                $pts    = (int)  $order->get_meta( '_smp_pts_used' );
                                $pt_ref = (bool) $order->get_meta( '_smp_points_refunded' );
                                if ( $pts > 0 ) {
                                    if ( $pt_ref ) {
                                        echo '<br><span style="font-size:11px;color:#166534;">↩ ' . esc_html( number_format( $pts ) ) . ' pts refunded</span>';
                                    } else {
                                        echo '<br><span style="font-size:11px;color:#059669;">🎁 ' . esc_html( number_format( $pts ) ) . ' pts redeemed</span>';
                                    }
                                }
                                ?>
                            </td>
                            <td><?php
                                $d = $order->get_date_created();
                                echo $d ? esc_html( $d->date('Y-m-d') ) : '—';
                            ?></td>
                            <td><span class="smp-badge badge-<?php echo esc_attr( $s ); ?>"><?php echo esc_html( wc_get_order_status_name( $s ) ); ?></span></td>
                            <td style="text-align:right;padding-right:15px;">
                                <button onclick="smpViewDetails(<?php echo intval( $order->get_id() ); ?>)"
                                    style="background:#2271b1;color:#fff;border:none;padding:8px 15px;border-radius:6px;cursor:pointer;font-weight:700;font-size:12px;">
                                    View Details
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div id="tab-details" class="smp-tab-content">
            <div style="padding:80px;text-align:center;color:#ccc;font-size:15px;">
                📋 Select an order from the list to view its details.
            </div>
        </div>

    </div>
    <?php return ob_get_clean();
} );

/* ==========================================================================
   6. GLOBAL PAGE LAYOUT — HORIZONTAL MARGIN
   CSS is in smp-frontend.css; this hook just enqueues it on target pages.
   ========================================================================== */
add_action( 'wp_enqueue_scripts', function() {
    if ( is_admin() ) return;
    $on_target = is_front_page() || is_home()
              || ( function_exists('is_shop')     && is_shop() )
              || ( function_exists('is_cart')     && is_cart() )
              || ( function_exists('is_checkout') && is_checkout() )
              || is_page();
    if ( ! $on_target ) return;
    wp_enqueue_style( 'smp-frontend', SMP_PLUGIN_URL . 'assets/css/smp-frontend.css', [], SMP_VERSION );
}, 20 );

/* ==========================================================================
   7. SHORTCODE AUTO-SCRIPT LOADER
   ========================================================================== */
add_action( 'wp', function() {
    global $post;
    if ( ! $post instanceof WP_Post ) return;
    if ( has_shortcode( $post->post_content, 'smp_auth' )
      || has_shortcode( $post->post_content, 'order_lookup' ) ) {
        add_filter( 'smp_load_scripts', '__return_true' );
    }
} );

/* ==========================================================================
   8. SHORTCODE: [smp_auth]
   ========================================================================== */
add_shortcode( 'smp_auth', function() {
    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        $url  = function_exists('wc_get_account_endpoint_url')
                    ? wc_get_account_endpoint_url('dashboard')
                    : get_permalink( get_option('woocommerce_myaccount_page_id') );
        return '<div style="max-width:540px;margin:40px auto;text-align:center;'
             . 'padding:36px 30px;background:#f0fdf4;border:1px solid #bbf7d0;'
             . 'border-radius:12px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
             . '<div style="font-size:40px;margin-bottom:14px;">✅</div>'
             . '<p style="font-size:16px;font-weight:700;color:#166534;margin:0 0 6px;">You are already logged in.</p>'
             . '<p style="font-size:13px;color:#4ade80;margin:0 0 22px;">' . esc_html( $user->display_name ) . '</p>'
             . '<a href="' . esc_url( $url ) . '" '
             . 'style="display:inline-block;padding:11px 28px;background:#2271b1;color:#fff;'
             . 'border-radius:6px;text-decoration:none;font-weight:700;font-size:14px;">Go to My Account →</a>'
             . '</div>';
    }
    if ( ! function_exists('wc_get_template') ) {
        return '<p>' . esc_html__( 'WooCommerce is required for this form.', 'smp' ) . '</p>';
    }
    ob_start();
    wc_get_template( 'myaccount/form-login.php' );
    return ob_get_clean();
} );

/* ==========================================================================
   9. RESPONSIVE HEADER — handled entirely in smp-frontend.css + smp-ajax.js
   ========================================================================== */

/* ==========================================================================
   10. MEMBER ID SYSTEM
   ========================================================================== */

function smp_member_num_get_config() {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    $saved  = get_option( 'smp_member_num_config', [] );
    $cache  = wp_parse_args( $saved, [ 'prefix' => 'MBR', 'num_length' => 6 ] );
    $cache['prefix']     = strtoupper( preg_replace( '/[^A-Za-z]/', '', $cache['prefix'] ) ) ?: 'MBR';
    $cache['num_length'] = max( 1, min( 10, intval( $cache['num_length'] ) ) );
    return $cache;
}

function smp_assign_member_id( $user_id ) {
    $user_id = (int) $user_id;
    if ( $user_id <= 0 ) return false;
    $existing = get_user_meta( $user_id, 'smp_member_id', true );
    if ( $existing ) return (string) $existing;
    if ( get_option( 'smp_member_num_counter' ) === false ) {
        add_option( 'smp_member_num_counter', 0, '', 'no' );
    }
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->options}
         SET    option_value = LAST_INSERT_ID( CAST(option_value AS UNSIGNED) + 1 )
         WHERE  option_name  = %s",
        'smp_member_num_counter'
    ) );
    $seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
    if ( $seq <= 0 ) return false;
    $cfg       = smp_member_num_get_config();
    $member_id = $cfg['prefix'] . str_pad( $seq, $cfg['num_length'], '0', STR_PAD_LEFT );
    update_user_meta( $user_id, 'smp_member_id', $member_id );
    return $member_id;
}

function smp_get_member_id( $user_id = 0 ) {
    if ( ! $user_id ) $user_id = get_current_user_id();
    if ( ! $user_id ) return '';
    $id = get_user_meta( $user_id, 'smp_member_id', true );
    if ( $id ) return (string) $id;
    return (string) smp_assign_member_id( $user_id );
}

add_action( 'user_register', 'smp_assign_member_id' );
add_action( 'wp_login', function( $user_login, $user ) {
    smp_assign_member_id( $user->ID );
}, 10, 2 );

add_action( 'show_user_profile', 'smp_render_member_id_field' );
add_action( 'edit_user_profile', 'smp_render_member_id_field' );

function smp_render_member_id_field( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $member_id = smp_get_member_id( $user->ID );
    ?>
    <h2 style="margin-top:30px;">🪪 Membership</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label>Member ID</label></th>
            <td>
                <code id="smp-member-id-display"
                    style="font-size:20px;letter-spacing:2px;padding:8px 16px;
                           background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;
                           user-select:all;cursor:pointer;" title="Click to copy">
                    <?php echo esc_html( $member_id ); ?>
                </code>
                <button type="button" class="button"
                    style="margin-left:10px;vertical-align:middle;"
                    onclick="navigator.clipboard.writeText('<?php echo esc_js( $member_id ); ?>');this.textContent='✅ Copied!';">
                    Copy
                </button>
                <p class="description" style="margin-top:6px;">
                    Sequential membership number. Auto-assigned. Read-only.
                </p>
            </td>
        </tr>
    </table>
    <?php
}

add_filter( 'smp_dashboard_member_id', function() {
    return smp_get_member_id();
} );

add_action( 'woocommerce_edit_account_form_start', function() {
    if ( ! is_user_logged_in() ) return;
    $user_id   = get_current_user_id();
    $member_id = smp_get_member_id( $user_id );
    $pts       = smp_get_user_points( $user_id );
    $cfg       = smp_get_points_config();
    $sym       = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
    if ( ! $member_id && $pts <= 0 ) return;
    $card_base = 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
                  border-radius:10px; padding:16px 20px; flex:1; min-width:0;
                  display:flex; align-items:center; gap:12px;';
    ?>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:24px;">

        <?php if ( $member_id ) : ?>
        <div style="<?php echo esc_attr( $card_base ); ?>
                    background:linear-gradient(135deg,#f0f9ff 0%,#f8fafc 100%);
                    border:1.5px solid #bae6fd;">
            <span style="font-size:26px;line-height:1;flex-shrink:0;">🪪</span>
            <div style="min-width:0;flex:1;">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:3px;">Your Member ID</div>
                <div style="font-size:20px;font-weight:800;letter-spacing:3px;color:#0f172a;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?php echo esc_html( $member_id ); ?>
                </div>
                <div style="font-size:10px;color:#94a3b8;margin-top:2px;">Unique membership number. Keep it safe.</div>
            </div>
            <button type="button"
                onclick="navigator.clipboard.writeText('<?php echo esc_js( $member_id ); ?>');
                         this.textContent='✅ Copied!';
                         setTimeout(()=>this.textContent='Copy',2000);"
                style="flex-shrink:0;padding:6px 14px;border:1.5px solid #bae6fd;
                       border-radius:6px;background:#fff;color:#0284c7;font-weight:700;
                       font-size:12px;cursor:pointer;white-space:nowrap;">
                Copy
            </button>
        </div>
        <?php endif; ?>

        <?php if ( $pts > 0 ) :
            $rate_text = intval( $cfg['redeem_rate'] ) . ' pts = ' . $sym . '1';
            $pts_value = $cfg['redeem_rate'] > 0
                       ? $sym . number_format( $pts / $cfg['redeem_rate'], 2 )
                       : '—';
        ?>
        <div style="<?php echo esc_attr( $card_base ); ?>
                    background:linear-gradient(135deg,#fefce8 0%,#f8fafc 100%);
                    border:1.5px solid #fde68a;">
            <span style="font-size:26px;line-height:1;flex-shrink:0;">⭐</span>
            <div style="min-width:0;flex:1;">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#92400e;margin-bottom:3px;">
                    <?php echo esc_html( $cfg['label'] ); ?> Balance
                </div>
                <div style="font-size:22px;font-weight:800;color:#78350f;letter-spacing:1px;">
                    <?php echo esc_html( number_format( $pts ) ); ?>
                    <span style="font-size:13px;font-weight:600;color:#a16207;margin-left:2px;">pts</span>
                </div>
                <div style="font-size:10px;color:#a16207;margin-top:2px;">
                    ≈ <?php echo esc_html( $pts_value ); ?>
                    &nbsp;·&nbsp; Rate: <?php echo esc_html( $rate_text ); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php
} );

add_filter( 'manage_users_columns', function( $columns ) {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'email' ) {
            $new['smp_member_id'] = '🪪 Member ID';
            $new['smp_points']    = '⭐ Points';
        }
    }
    return $new;
} );

add_filter( 'manage_users_custom_column', function( $output, $column_name, $user_id ) {
    if ( $column_name === 'smp_member_id' ) {
        $mid = get_user_meta( $user_id, 'smp_member_id', true );
        if ( ! $mid ) return '<span style="color:#aaa;">—</span>';
        return '<code style="font-size:13px;letter-spacing:1.5px;background:#f1f5f9;padding:3px 8px;border-radius:4px;font-family:monospace;">' . esc_html( $mid ) . '</code>';
    }
    if ( $column_name === 'smp_points' ) {
        $pts = smp_get_user_points( $user_id );
        if ( $pts <= 0 ) return '<span style="color:#aaa;">0</span>';
        $edit_url = admin_url( 'user-edit.php?user_id=' . intval( $user_id ) . '#smp-points-panel' );
        return '<a href="' . esc_url( $edit_url ) . '" style="display:inline-block;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;padding:2px 10px;font-weight:700;font-size:13px;text-decoration:none;">' . esc_html( number_format( $pts ) ) . '</a>';
    }
    return $output;
}, 10, 3 );

add_filter( 'manage_users_sortable_columns', function( $columns ) {
    $columns['smp_member_id'] = 'smp_member_id';
    $columns['smp_points']    = 'smp_points';
    return $columns;
} );

add_action( 'pre_get_users', function( $query ) {
    if ( ! is_admin() ) return;
    $orderby = $query->get( 'orderby' );
    if ( $orderby === 'smp_member_id' ) { $query->set( 'meta_key', 'smp_member_id' ); $query->set( 'orderby', 'meta_value_num' ); }
    if ( $orderby === 'smp_points' )    { $query->set( 'meta_key', 'smp_points'    ); $query->set( 'orderby', 'meta_value_num' ); }
} );

/* ==========================================================================
   11–13. ADMIN SETTINGS, ANALYTICS & AUTOMATION — unchanged from v11.6.8
   (Full code retained below; only CSS/JS extraction changed)
   ========================================================================== */

add_filter( 'woocommerce_get_order_item_totals', function( $total_rows, $order, $tax_display ) {
    if ( isset( $total_rows['fees'] ) ) {
        $total_rows['fees']['label'] = __( 'Bonus Discount:', 'woocommerce' );
    }
    return $total_rows;
}, 10, 3 );

add_action( 'admin_menu', function () {
    add_menu_page( 'CustomerAnalytics', '📊 CustomerAnalytics', 'manage_options', 'smp-customer-analytics', 'smp_render_customers_page', 'dashicons-chart-bar', 71 );
    add_submenu_page( 'smp-customer-analytics', 'Customers',           '👥 Customers',    'manage_options', 'smp-customer-analytics', 'smp_render_customers_page'    );
    add_submenu_page( 'smp-customer-analytics', 'Membership Settings', '⭐ Memebership',  'manage_options', 'smp-points-settings',     'smp_render_points_settings_page' );
} );

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['smp_pts_save'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_pts_settings_save', 'smp_pts_nonce_field' ) ) return;
    $config = [
        'redeem_rate'      => max( 1,   intval(   $_POST['smp_redeem_rate']      ?? 100 ) ),
        'earn_rate'        => max( 0,   floatval( $_POST['smp_earn_rate']        ?? 1   ) ),
        'min_redeem'       => max( 0,   intval(   $_POST['smp_min_redeem']       ?? 100 ) ),
        'max_discount_pct' => min( 100, max( 1,   intval( $_POST['smp_max_discount_pct'] ?? 50 ) ) ),
        'label'            => sanitize_text_field( $_POST['smp_label'] ?? 'Points' ),
    ];
    update_option( 'smp_pts_config', $config );
    wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1' ) );
    exit;
} );

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['smp_member_num_save'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_member_num_settings_save', 'smp_member_num_nonce_field' ) ) return;
    $new_prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', $_POST['smp_mn_prefix'] ?? 'MBR' ) ) ?: 'MBR';
    $new_prefix = substr( $new_prefix, 0, 5 );
    $num_length = max( 1, min( 10, intval( $_POST['smp_mn_num_length'] ?? 6 ) ) );
    $old_cfg    = get_option( 'smp_member_num_config', [] );
    $old_prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', $old_cfg['prefix'] ?? '' ) );
    if ( $new_prefix !== $old_prefix ) update_option( 'smp_member_num_counter', 0, 'no' );
    update_option( 'smp_member_num_config', [ 'prefix' => $new_prefix, 'num_length' => $num_length ] );
    wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1&smp_tab=member_num' ) );
    exit;
} );

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['smp_topup_save'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_topup_settings_save', 'smp_topup_nonce_field' ) ) return;
    $roles_raw = is_array( $_POST['smp_topup_roles'] ?? null ) ? $_POST['smp_topup_roles'] : [];
    $roles = [];
    foreach ( $roles_raw as $slug => $pts ) {
        $slug = sanitize_key( $slug );
        if ( $slug !== '' ) $roles[ $slug ] = max( 0, intval( $pts ) );
    }
    $config = [ 'enabled' => isset( $_POST['smp_topup_enabled'] ) ? 1 : 0, 'cycle_weeks' => max( 1, intval( $_POST['smp_topup_cycle_weeks'] ?? 4 ) ), 'roles' => $roles ];
    update_option( 'smp_topup_config', $config );
    if ( function_exists( 'smp_topup_sync_schedule' ) ) smp_topup_sync_schedule( $config );
    wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1&smp_tab=topup' ) );
    exit;
} );

add_action( 'admin_init', function () {
    if ( ! isset( $_GET['smp_reset'] ) || ! isset( $_GET['page'] ) || $_GET['page'] !== 'smp-points-settings' || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_pts_reset' ) ) return;
    delete_option( 'smp_pts_config' );
    wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1' ) );
    exit;
} );

// Admin page render functions (smp_render_customers_page, smp_render_points_settings_page)
// are identical to v11.6.8 except their inline <style> and <script> blocks have been
// removed — styles are now in smp-admin.css and JS is in smp-ajax.js.
// The full render functions are included below unchanged in content, just without embedded CSS/JS.

function smp_render_customers_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'You do not have permission to access this page.' );

    $admin_id     = get_current_user_id();
    $throttle_key = 'smp_analytics_view_' . $admin_id;
    if ( ! get_transient( $throttle_key ) ) {
        $admin_user = get_userdata( $admin_id );
        $raw_ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
        $client_ip  = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : 'invalid';
        error_log( sprintf( 'SMP_ANALYTICS_VIEW: admin_id=%d login=%s ip=%s site=%s',
            $admin_id,
            $admin_user ? $admin_user->user_login : 'unknown',
            $client_ip,
            home_url()
        ) );
        set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
    }

    $per_page     = 20;
    $current_page = max( 1, intval( $_GET['paged'] ?? 1 ) );
    $search       = sanitize_text_field( $_GET['smp_search'] ?? '' );
    $offset       = ( $current_page - 1 ) * $per_page;

    $order_dir       = ( strtoupper( $_GET['smp_order'] ?? 'DESC' ) === 'ASC' ) ? 'ASC' : 'DESC';
    $allowed_orderby = [ 'registered', 'display_name', 'user_email' ];
    $raw_orderby     = sanitize_key( $_GET['smp_orderby'] ?? 'registered' );
    $safe_orderby    = in_array( $raw_orderby, $allowed_orderby, true ) ? $raw_orderby : 'registered';

    $user_query_args = [ 'number' => $per_page, 'offset' => $offset, 'orderby' => $safe_orderby, 'order' => $order_dir, 'fields' => 'all' ];
    if ( $search !== '' ) { $user_query_args['search'] = '*' . $search . '*'; $user_query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ]; }
    $user_query  = new WP_User_Query( $user_query_args );
    $wp_users    = $user_query->get_results();
    $total_users = $user_query->get_total();
    $total_pages = max( 1, (int) ceil( $total_users / $per_page ) );

    $wc_data = [];
    if ( class_exists( 'WooCommerce' ) && ! empty( $wp_users ) ) {
        $user_ids = wp_list_pluck( $wp_users, 'ID' );
        foreach ( $user_ids as $uid ) {
            $orders = wc_get_orders( [ 'customer' => $uid, 'limit' => -1, 'status' => [ 'wc-completed', 'wc-processing', 'wc-on-hold' ], 'return' => 'objects' ] );
            $count = count( $orders ); $total_spent = 0.0; $last_order = null;
            foreach ( $orders as $o ) {
                $total_spent += floatval( $o->get_total() );
                $date = $o->get_date_created();
                if ( $date && ( $last_order === null || $date > $last_order ) ) $last_order = $date;
            }
            $wc_data[ $uid ] = [ 'order_count' => $count, 'total_spent' => $total_spent, 'aov' => $count > 0 ? $total_spent / $count : null, 'last_order' => $last_order ? $last_order->date_i18n( 'd.m.Y' ) : '—', 'customer_url' => admin_url( 'user-edit.php?user_id=' . $uid ) ];
        }
    }

    $sym        = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
    $base_url   = admin_url( 'admin.php?page=smp-customer-analytics' );
    ?>
    <div class="wrap" id="smp-customers-wrap">
    <h1>📊 CustomerAnalytics <span style="font-size:14px;color:#9ca3af;font-weight:400;">– Customers</span></h1>
    <p style="color:#6b7280;margin-top:0;font-size:13px;">Unified view of WordPress user accounts and WooCommerce customer data.</p>

    <?php
    $total_wp_users = count_users();
    $total_wc_customers = 0;
    if ( class_exists( 'WooCommerce' ) ) {
        $all_cust = get_users( [ 'role' => 'customer', 'fields' => 'ID', 'number' => -1 ] );
        $total_wc_customers = count( $all_cust );
    }
    ?>
    <div class="smp-ca-stat-row">
        <div class="smp-ca-stat"><div class="label">Total WP Users</div><div class="value"><?php echo esc_html( number_format( $total_wp_users["total_users"] ) ); ?></div></div>
        <div class="smp-ca-stat"><div class="label">WC Customers (role)</div><div class="value"><?php echo esc_html( number_format( $total_wc_customers ) ); ?></div></div>
        <div class="smp-ca-stat"><div class="label">Showing (page <?php echo intval( $current_page ); ?>/<?php echo intval( $total_pages ); ?>)</div><div class="value"><?php echo esc_html( number_format( $total_users ) ); ?></div></div>
    </div>

    <form method="get" class="smp-ca-search">
        <input type="hidden" name="page" value="smp-customer-analytics">
        <input type="search" name="smp_search" placeholder="Search by name / email / login…" value="<?php echo esc_attr( $search ); ?>">
        <button type="submit" class="button">🔍 Search</button>
        <?php if ( $search !== '' ) : ?><a href="<?php echo esc_url( $base_url ); ?>" class="button">✕ Clear</a><?php endif; ?>
    </form>

    <div class="smp-ca-table-wrap">
    <table class="smp-ca-table">
        <thead>
            <tr>
                <th>#</th><th>Avatar</th><th>Display Name</th><th>Username</th><th>Email</th>
                <th>Role(s)</th><th>Registered</th><th>Member ID</th><th>Points</th>
                <th>WC Orders</th><th>Total Spent</th><th>AOV</th><th>Last Order</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $wp_users ) ) : ?>
            <tr><td colspan="14" style="text-align:center;padding:30px;color:#9ca3af;">No users found.</td></tr>
        <?php else : ?>
            <?php foreach ( $wp_users as $i => $user ) :
                $uid        = $user->ID;
                $roles      = implode( ', ', (array) $user->roles );
                $registered = mysql2date( 'd.m.Y', $user->user_registered );
                $member_id  = get_user_meta( $uid, 'smp_member_id', true );
                $points     = function_exists( 'smp_get_user_points' ) ? smp_get_user_points( $uid ) : (int) get_user_meta( $uid, 'smp_points', true );
                $wc         = $wc_data[ $uid ] ?? [ 'order_count' => '—', 'total_spent' => null, 'aov' => null, 'last_order' => '—' ];
                $avatar     = get_avatar_url( $uid, [ 'size' => 32 ] );
                $edit_url   = esc_url( admin_url( 'user-edit.php?user_id=' . $uid ) );
                $row_num    = $offset + $i + 1;
                $full_email = $user->user_email;
                $parts      = explode( '@', $full_email, 2 );
                $local      = $parts[0] ?? '';
                $domain     = isset( $parts[1] ) ? '@' . $parts[1] : '';
                $masked_local = mb_substr( $local, 0, 1 ) . str_repeat( '*', max( 2, mb_strlen( $local ) - 1 ) );
            ?>
            <tr>
                <td style="color:#9ca3af;font-size:12px;"><?php echo intval( $row_num ); ?></td>
                <td><img src="<?php echo esc_url( $avatar ); ?>" width="32" height="32" class="smp-avatar" alt=""></td>
                <td><a href="<?php echo esc_url( $edit_url ); ?>" style="font-weight:600;text-decoration:none;color:#1d4ed8;"><?php echo esc_html( $user->display_name ); ?></a></td>
                <td style="color:#6b7280;font-size:12.5px;"><?php echo esc_html( $user->user_login ); ?></td>
                <td>
                    <span class="smp-email-cell"
                          data-full="<?php echo esc_attr( $full_email ); ?>"
                          data-masked="<?php echo esc_attr( $masked_local . $domain ); ?>"
                          title="Click to reveal"
                          style="cursor:pointer;color:#374151;border-bottom:1px dashed #d1d5db;">
                        <?php echo esc_html( $masked_local . $domain ); ?>
                    </span>
                </td>
                <td style="font-size:12px;color:#6b7280;"><?php echo esc_html( $roles ?: '—' ); ?></td>
                <td style="font-size:12.5px;"><?php echo esc_html( $registered ); ?></td>
                <td style="font-size:12px;color:#6b7280;font-family:monospace;"><?php echo $member_id ? esc_html( $member_id ) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                <td>
                    <?php if ( $points > 0 ) : ?>
                        <a href="<?php echo esc_url( $edit_url ); ?>#smp-points-panel" class="smp-badge-pts"><?php echo esc_html( number_format( $points ) ); ?> pts</a>
                    <?php else : ?>
                        <span class="smp-badge-zero">0</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( is_numeric( $wc['order_count'] ) && $wc['order_count'] > 0 ) : ?>
                        <span class="smp-badge-orders"><?php echo intval( $wc['order_count'] ); ?></span>
                    <?php else : ?>
                        <span class="smp-badge-zero"><?php echo esc_html( $wc['order_count'] ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;"><?php echo $wc['total_spent'] !== null ? esc_html( $sym . number_format( $wc['total_spent'], 2, ',', '.' ) ) : '—'; ?></td>
                <td class="smp-aov"><?php echo $wc['aov'] !== null ? esc_html( $sym . number_format( $wc['aov'], 2, ',', '.' ) ) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                <td style="font-size:12.5px;color:#6b7280;"><?php echo esc_html( $wc['last_order'] ); ?></td>
                <td style="white-space:nowrap;"><a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">✏️ Edit</a></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ( $total_pages > 1 ) : ?>
    <div class="smp-ca-pagination">
        <span>Page <?php echo intval( $current_page ); ?> of <?php echo intval( $total_pages ); ?></span>
        <?php for ( $p = 1; $p <= $total_pages; $p++ ) :
            $page_url = esc_url( add_query_arg( [ 'page' => 'smp-customer-analytics', 'paged' => $p, 'smp_search' => $search ], admin_url( 'admin.php' ) ) );
            if ( $p === $current_page ) : ?>
            <span class="current"><?php echo intval( $p ); ?></span>
        <?php else : ?>
            <a href="<?php echo esc_url( $page_url ); ?>"><?php echo intval( $p ); ?></a>
        <?php endif; endfor; ?>
    </div>
    <?php endif; ?>
    </div>
    <?php
}

function smp_render_points_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'You do not have permission to access this page.' );

    $cfg_defaults = [ 'earn_rate' => 1, 'redeem_rate' => 100, 'max_discount_pct' => 50, 'min_redeem' => 100, 'label' => 'Points' ];
    $saved_config = get_option( 'smp_pts_config', [] );
    $cfg          = wp_parse_args( $saved_config, $cfg_defaults );
    $sym          = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
    $has_custom   = ! empty( $saved_config );
    $has_wcpr     = class_exists( 'WC_Points_Rewards' ) && ! empty( get_option( 'wc_points_rewards' ) );
    $saved        = isset( $_GET['smp_saved'] );
    $active_tab   = sanitize_key( $_GET['smp_tab'] ?? 'config' );

    if ( $has_custom )     $source_html = '<span style="color:#16a34a;font-weight:700;">✓ Custom settings (this page)</span>';
    elseif ( $has_wcpr )   $source_html = '<span style="color:#d97706;font-weight:700;">⚠ WC Points &amp; Rewards plugin</span>';
    else                   $source_html = '<span style="color:#6b7280;">System defaults</span>';

    $reset_url = wp_nonce_url( admin_url( 'admin.php?page=smp-points-settings&smp_reset=1' ), 'smp_pts_reset' );

    $topup_defaults = [ 'enabled' => 0, 'cycle_weeks' => 4, 'roles' => [ 'customer' => 100, 'vip_customer' => 500, 'administrator' => 1000 ] ];
    $topup_saved    = get_option( 'smp_topup_config', [] );
    $topup          = wp_parse_args( $topup_saved, $topup_defaults );
    if ( ! is_array( $topup['roles'] ) ) $topup['roles'] = $topup_defaults['roles'];

    $all_wp_roles  = wp_roles()->roles;
    $next_run_ts   = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'smp_topup_dispatch_hook' ) : false;

    $mn_defaults = [ 'prefix' => 'MBR', 'num_length' => 6 ];
    $mn_saved    = get_option( 'smp_member_num_config', [] );
    $mn_cfg      = wp_parse_args( $mn_saved, $mn_defaults );
    $mn_counter  = (int) get_option( 'smp_member_num_counter', 0 );
    $mn_prefix   = strtoupper( preg_replace( '/[^A-Za-z]/', '', $mn_cfg['prefix'] ?? 'MBR' ) ) ?: 'MBR';
    $mn_len      = max( 1, min( 10, intval( $mn_cfg['num_length'] ?? 6 ) ) );
    $mn_preview  = $mn_prefix . str_pad( $mn_counter + 1, $mn_len, '0', STR_PAD_LEFT );
    ?>
    <div class="wrap" id="smp-pts-settings-wrap">
    <h1 style="margin-bottom:4px;">⭐ Membership – Settings</h1>
    <p style="color:#6b7280;margin-top:4px;font-size:13px;">Configure credit redemption rates, earn rules, and automatic top-up for members.</p>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible" style="margin-top:12px;"><p>✓ Settings saved.</p></div>
    <?php endif; ?>

    <div class="smp-tabs-nav">
        <button type="button" class="smp-tab-btn <?php echo $active_tab === 'config'     ? 'active' : ''; ?>" data-tab="smp-pane-config">💳 Credit Configuration</button>
        <button type="button" class="smp-tab-btn <?php echo $active_tab === 'topup'      ? 'active' : ''; ?>" data-tab="smp-pane-topup">🔄 Credit Top Up</button>
        <button type="button" class="smp-tab-btn <?php echo $active_tab === 'member_num' ? 'active' : ''; ?>" data-tab="smp-pane-member-num">🪪 Membership Number</button>
    </div>

    <!-- TAB 1 — Credit Configuration -->
    <div id="smp-pane-config" class="smp-tab-pane <?php echo $active_tab === 'config' ? 'active' : ''; ?>">
        <div class="smp-sc">
            <h2>📋 Configuration Priority</h2>
            <div class="smp-source-row">Active source: <?php echo wp_kses_post( $source_html ); ?></div>
            <ol style="color:#6b7280;font-size:13px;margin:12px 0 0;padding-left:22px;line-height:2;">
                <li><strong style="color:#374151;">Custom settings (this page)</strong> — takes effect immediately on save, highest priority</li>
                <li>WC Points &amp; Rewards plugin <em>(used when no custom settings are saved)</em></li>
                <li>Hardcoded defaults <em>(fallback)</em></li>
            </ol>
        </div>
        <form method="post">
            <?php wp_nonce_field( 'smp_pts_settings_save', 'smp_pts_nonce_field' ); ?>
            <div class="smp-sc">
                <h2>💳 Credit Configuration</h2>
                <div class="smp-fg">
                    <div class="smp-fl">Redeem Rate<small>How many points equal 1 unit of currency</small></div>
                    <div class="smp-fr"><input type="number" name="smp_redeem_rate" value="<?php echo intval( $cfg['redeem_rate'] ); ?>" min="1" step="1" required><span class="smp-unit">pts = <?php echo esc_html( $sym ); ?>1</span></div>
                </div>
                <div class="smp-fg">
                    <div class="smp-fl">Earn Rate<small>Points awarded per 1 unit of currency spent</small></div>
                    <div class="smp-fr"><span class="smp-unit"><?php echo esc_html( $sym ); ?>1 =</span><input type="number" name="smp_earn_rate" value="<?php echo floatval( $cfg['earn_rate'] ); ?>" min="0" step="0.1" required><span class="smp-unit">pts</span></div>
                </div>
                <div class="smp-fg">
                    <div class="smp-fl">Min Redemption<small>Minimum points balance required before redemption is allowed</small></div>
                    <div class="smp-fr"><input type="number" name="smp_min_redeem" value="<?php echo intval( $cfg['min_redeem'] ); ?>" min="0" step="1" required><span class="smp-unit">pts minimum</span></div>
                </div>
                <div class="smp-fg">
                    <div class="smp-fl">Max Discount %<small>Maximum discount allowed as a percentage of cart total (1–100%)</small></div>
                    <div class="smp-fr"><input type="number" name="smp_max_discount_pct" value="<?php echo intval( $cfg['max_discount_pct'] ); ?>" min="1" max="100" step="1" required><span class="smp-unit">% of cart total</span></div>
                </div>
                <div class="smp-fg">
                    <div class="smp-fl">Points Label<small>Name displayed for points in the frontend</small></div>
                    <div class="smp-fr"><input type="text" name="smp_label" value="<?php echo esc_attr( $cfg['label'] ); ?>" placeholder="Points" required></div>
                </div>
                <div class="smp-preview">
                    <strong>Active config preview:</strong>
                    <?php echo intval( $cfg['redeem_rate'] ); ?> pts = <?php echo esc_html( $sym ); ?>1 &nbsp;·&nbsp;
                    <?php echo esc_html( $sym ); ?>1 = <?php echo floatval( $cfg['earn_rate'] ); ?> pts &nbsp;·&nbsp;
                    min <?php echo intval( $cfg['min_redeem'] ); ?> pts &nbsp;·&nbsp;
                    max <?php echo intval( $cfg['max_discount_pct'] ); ?>% &nbsp;·&nbsp;
                    label: <?php echo esc_html( $cfg['label'] ); ?>
                </div>
            </div>
            <p class="submit" style="margin-top:20px;">
                <input type="submit" name="smp_pts_save" class="button button-primary button-large" value="💾  Save Settings">
                <?php if ( $has_custom ) : ?>
                    <a href="<?php echo esc_url( $reset_url ); ?>" class="smp-reset" onclick="return confirm('Reset all custom settings and revert to defaults?');">↩ Reset to defaults</a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <!-- TAB 2 — Credit Top Up -->
    <div id="smp-pane-topup" class="smp-tab-pane <?php echo $active_tab === 'topup' ? 'active' : ''; ?>">
        <form method="post">
            <?php wp_nonce_field( 'smp_topup_settings_save', 'smp_topup_nonce_field' ); ?>
            <div class="smp-sc">
                <h2>🔄 Auto Top Up</h2>
                <div class="smp-fg">
                    <div class="smp-fl">Enable Auto Top Up<small>Automatically credit points to qualifying members on each cycle</small></div>
                    <div class="smp-fr">
                        <label class="smp-toggle"><input type="checkbox" id="smp-topup-toggle" name="smp_topup_enabled" value="1" <?php checked( 1, $topup['enabled'] ); ?>><span class="smp-toggle-slider"></span></label>
                        <span id="smp-topup-status-lbl" style="font-size:13.5px;font-weight:600;"><?php echo $topup['enabled'] ? '<span style="color:#16a34a;">Enabled</span>' : '<span style="color:#9ca3af;">Disabled</span>'; ?></span>
                    </div>
                </div>
                <div class="smp-fg">
                    <div class="smp-fl">Top Up Cycle<small>How often credits are distributed. Runs on Monday at the start of each cycle.</small></div>
                    <div class="smp-fr"><span class="smp-unit">Every</span><input type="number" name="smp_topup_cycle_weeks" value="<?php echo intval( $topup['cycle_weeks'] ); ?>" min="1" max="52" step="1" style="width:70px;text-align:center;" required><span class="smp-unit">week(s) &nbsp;·&nbsp; starts on <strong>Monday</strong></span></div>
                </div>
            </div>
            <div class="smp-sc">
                <h2>👥 Points Per Role</h2>
                <p style="color:#6b7280;font-size:13px;margin:0 0 16px;">Set the points credited each cycle per member role. Enter <strong>0</strong> to exclude a role.</p>
                <table class="smp-role-table">
                    <thead><tr><th style="width:55%;">Role</th><th>Points per cycle</th></tr></thead>
                    <tbody>
                        <?php foreach ( $all_wp_roles as $slug => $role_data ) :
                            $pts = isset( $topup['roles'][ $slug ] ) ? intval( $topup['roles'][ $slug ] ) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></strong><small style="display:block;color:#9ca3af;font-size:11px;"><?php echo esc_html( $slug ); ?></small></td>
                            <td><input type="number" name="smp_topup_roles[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( intval( $pts ) ); ?>" min="0" step="1" placeholder="0"><span class="smp-unit" style="font-size:13px;">pts</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="smp-sc">
                <h2>🗓 Schedule Status</h2>
                <?php if ( ! $topup['enabled'] ) : ?>
                    <div class="smp-schedule-banner warn">⚠ Auto top-up is currently <strong>disabled</strong>. Enable it above and save to activate the schedule.</div>
                <?php elseif ( $next_run_ts ) : ?>
                    <div class="smp-schedule-banner ok">✓ Next scheduled run: <strong><?php echo esc_html( wp_date( 'l, d M Y \a\t H:i', $next_run_ts ) ); ?></strong> &nbsp;·&nbsp; repeats every <?php echo intval( $topup['cycle_weeks'] ); ?> week<?php echo $topup['cycle_weeks'] != 1 ? 's' : ''; ?> on Monday</div>
                <?php else : ?>
                    <div class="smp-schedule-banner warn">⚠ No run scheduled yet. Save settings to register the schedule.</div>
                <?php endif; ?>
                <div style="margin-top:20px;padding-top:18px;border-top:1px solid #f1f5f9;">
                    <p style="font-size:13px;color:#374151;margin:0 0 10px;"><strong>Manual Top Up Now</strong><small style="display:block;font-weight:400;color:#9ca3af;margin-top:3px;">Immediately dispatches the top-up to all qualifying users, regardless of whether auto top-up is enabled.</small></p>
                    <button type="button" id="smp-run-now-btn" class="button button-secondary">▶ Run Top Up Now</button>
                    <span id="smp-run-now-result" style="margin-left:14px;font-size:13px;display:none;"></span>
                </div>
            </div>
            <p class="submit" style="margin-top:20px;"><input type="submit" name="smp_topup_save" class="button button-primary button-large" value="💾  Save Top Up Settings"></p>
        </form>
    </div>

    <!-- TAB 3 — Membership Number -->
    <div id="smp-pane-member-num" class="smp-tab-pane <?php echo $active_tab === 'member_num' ? 'active' : ''; ?>">
        <form method="post">
            <?php wp_nonce_field( 'smp_member_num_settings_save', 'smp_member_num_nonce_field' ); ?>
            <div class="smp-sc" style="margin-top:18px;">
                <h2>🪪 Membership Number Format</h2>
                <div class="smp-fg">
                    <div class="smp-fl">Prefix<small>1–5 uppercase letters prepended to every member number. Changing the prefix resets the counter to 0.</small></div>
                    <div class="smp-fr"><input type="text" name="smp_mn_prefix" id="smp-mn-prefix" value="<?php echo esc_attr( $mn_prefix ); ?>" maxlength="5" placeholder="MBR" style="width:90px;text-align:center;font-weight:700;letter-spacing:.1em;text-transform:uppercase;" oninput="this.value=this.value.replace(/[^A-Za-z]/g,'').toUpperCase()"></div>
                </div>
                <div class="smp-fg">
                    <div class="smp-fl">Number Length<small>Digits after the prefix (zero-padded). Range: 1–10.</small></div>
                    <div class="smp-fr"><input type="number" name="smp_mn_num_length" id="smp-mn-len" value="<?php echo esc_attr( intval( $mn_len ) ); ?>" min="1" max="10" step="1" style="width:70px;" required><span class="smp-unit">digits</span></div>
                </div>
                <div class="smp-preview" style="margin-top:18px;">
                    <strong>Preview — next member number:</strong>
                    &nbsp;<code id="smp-mn-preview" data-counter="<?php echo intval( $mn_counter ); ?>" style="font-size:16px;letter-spacing:.08em;background:#fff8e7;padding:3px 10px;border-radius:5px;"><?php echo esc_html( $mn_preview ); ?></code>
                    <span style="color:#92400e;font-size:12px;margin-left:10px;">(current counter: <strong><?php echo intval( $mn_counter ); ?></strong>)</span>
                </div>
            </div>
            <div class="smp-sc">
                <h2>⚠ Counter Reset Rule</h2>
                <p style="font-size:13px;color:#374151;margin:0;">Saving a <strong>new prefix</strong> automatically resets the counter to <strong>0</strong>.<br>
                <span style="color:#dc2626;">Existing member numbers already stored in user profiles are <strong>not</strong> changed.</span></p>
            </div>
            <p class="submit" style="margin-top:20px;"><input type="submit" name="smp_member_num_save" class="button button-primary button-large" value="💾  Save Membership Number Settings"></p>
        </form>
    </div>
    </div>
    <?php
}

/* ==========================================================================
   SECTION 13: AUTOMATION TASKS (Action Scheduler)
   ========================================================================== */

if ( ! defined( 'SMP_RECHARGE_ROLES' ) ) {
    define( 'SMP_RECHARGE_ROLES', [ 'customer' => 100, 'vip_customer' => 500, 'administrator' => 1000 ] );
}

function smp_topup_get_config() {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    $defaults = [ 'enabled' => 0, 'cycle_weeks' => 4, 'roles' => SMP_RECHARGE_ROLES ];
    $saved = get_option( 'smp_topup_config', [] );
    $cache = wp_parse_args( $saved, $defaults );
    if ( ! is_array( $cache['roles'] ) ) $cache['roles'] = SMP_RECHARGE_ROLES;
    return $cache;
}

function smp_topup_next_run_timestamp() {
    return (int) strtotime( 'next monday 00:05:00', current_time( 'timestamp' ) );
}

function smp_topup_sync_schedule( $config = null ) {
    if ( ! function_exists( 'as_unschedule_all_actions' ) ) return;
    if ( $config === null ) $config = smp_topup_get_config();
    as_unschedule_all_actions( 'smp_topup_dispatch_hook' );
    as_unschedule_all_actions( 'smp_monthly_main_dispatch_hook' );
    if ( empty( $config['enabled'] ) ) return;
    if ( function_exists( 'as_schedule_single_action' ) ) as_schedule_single_action( smp_topup_next_run_timestamp(), 'smp_topup_dispatch_hook' );
}

register_activation_hook( __FILE__, 'smp_as_register_topup_schedule' );
function smp_as_register_topup_schedule() {
    if ( ! function_exists( 'as_next_scheduled_action' ) ) return;
    $config = smp_topup_get_config();
    if ( ! empty( $config['enabled'] ) && false === as_next_scheduled_action( 'smp_topup_dispatch_hook' ) ) {
        as_schedule_single_action( smp_topup_next_run_timestamp(), 'smp_topup_dispatch_hook' );
    }
}

register_deactivation_hook( __FILE__, 'smp_as_cancel_topup_schedule' );
function smp_as_cancel_topup_schedule() {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'smp_topup_dispatch_hook' );
        as_unschedule_all_actions( 'smp_monthly_main_dispatch_hook' );
        as_unschedule_all_actions( 'smp_as_do_single_recharge' );
    }
}

add_action( 'smp_topup_dispatch_hook',        'smp_as_dispatch_customer_recharges' );
add_action( 'smp_monthly_main_dispatch_hook', 'smp_as_dispatch_customer_recharges' );

function smp_as_dispatch_customer_recharges() {
    if ( ! function_exists( 'as_enqueue_async_action' ) ) return;
    $config = smp_topup_get_config();
    if ( empty( $config['enabled'] ) ) return;
    $role_pts         = array_filter( (array) $config['roles'], fn( $p ) => (int) $p > 0 );
    $qualifying_roles = array_keys( $role_pts );
    if ( empty( $qualifying_roles ) ) return;
    $user_ids = get_users( [ 'role__in' => $qualifying_roles, 'fields' => 'ID', 'number' => -1 ] );
    foreach ( $user_ids as $user_id ) {
        as_enqueue_async_action( 'smp_as_do_single_recharge', [ 'user_id' => (int) $user_id ], 'smp_recharge_group' );
    }
    if ( function_exists( 'as_schedule_single_action' ) ) {
        $monday_base = (int) strtotime( 'this monday 00:00:00', current_time( 'timestamp' ) );
        $cycle_secs  = max( 1, (int) $config['cycle_weeks'] ) * WEEK_IN_SECONDS;
        as_schedule_single_action( $monday_base + $cycle_secs + 5 * MINUTE_IN_SECONDS, 'smp_topup_dispatch_hook' );
    }
}

add_action( 'smp_as_do_single_recharge', 'smp_as_execute_recharge_logic' );
function smp_as_execute_recharge_logic( $user_id ) {
    $user_id = (int) $user_id;
    $user    = get_userdata( $user_id );
    if ( ! $user ) return;
    $config   = smp_topup_get_config();
    $role_pts = (array) $config['roles'];
    $points_to_add = 0;
    foreach ( $role_pts as $role => $pts ) {
        if ( (int) $pts > 0 && in_array( $role, (array) $user->roles, true ) ) {
            $points_to_add = max( $points_to_add, (int) $pts );
        }
    }
    if ( $points_to_add <= 0 ) return;
    smp_add_user_points( $user_id, $points_to_add, 'Auto top-up' );
    $new_total = smp_get_user_points_from_db( $user_id );
    error_log( sprintf( 'SMP_TOPUP: user_id=%d email=%s role_match=%s added=%d new_balance=%d site=%s',
        $user_id, $user->user_email, implode( ',', array_intersect( (array) $user->roles, array_keys( $role_pts ) ) ),
        $points_to_add, $new_total, home_url() ) );
    $cycle_weeks = max( 1, (int) $config['cycle_weeks'] );
    $cycle_label = $cycle_weeks === 1 ? 'weekly' : 'every ' . $cycle_weeks . ' weeks';
    $auto_log = get_user_meta( $user_id, 'smp_points_log', true );
    if ( ! is_array( $auto_log ) ) $auto_log = [];
    $auto_log[] = [ 'date' => current_time( 'Y-m-d H:i' ), 'action' => 'add', 'amount' => $points_to_add, 'balance_after' => $new_total, 'note' => 'Auto top-up (' . $cycle_label . ')', 'by' => 'system' ];
    update_user_meta( $user_id, 'smp_points_log', array_slice( $auto_log, -100 ) );
    $site_name = get_bloginfo( 'name' );
    $pts_cfg   = smp_get_points_config();
    $label     = $pts_cfg['label'] ?? 'Punkte';
    $subject   = sprintf( '[%s] Ihre automatische %s-Gutschrift', $site_name, $label );
    $message   = sprintf( "Hallo %s,\n\n", $user->display_name );
    $message  .= sprintf( "wir haben Ihrem Konto %d %s gutgeschrieben (%s).\n", $points_to_add, $label, $cycle_label );
    $message  .= sprintf( "Ihr aktuelles Guthaben beträgt: %s %s.\n\nViel Spaß beim Einkaufen!\n– Das %s-Team\n", number_format( $new_total, 0, ',', '.' ), $label, $site_name );
    wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );
}

add_action( 'wp_ajax_smp_topup_run_now', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'msg' => 'Permission denied.' ] );
    if ( ! check_ajax_referer( 'smp_topup_run_now', '_wpnonce', false ) ) wp_send_json_error( [ 'msg' => 'Security check failed.' ] );
    $config = smp_topup_get_config();
    $role_pts = array_filter( (array) $config['roles'], fn( $p ) => (int) $p > 0 );
    $qualifying_roles = array_keys( $role_pts );
    if ( empty( $qualifying_roles ) ) wp_send_json_error( [ 'msg' => 'No roles configured with points > 0.' ] );
    $user_ids = get_users( [ 'role__in' => $qualifying_roles, 'fields' => 'ID', 'number' => -1 ] );
    $count = 0;
    if ( function_exists( 'as_enqueue_async_action' ) ) {
        foreach ( $user_ids as $user_id ) { as_enqueue_async_action( 'smp_as_do_single_recharge', [ 'user_id' => (int) $user_id ], 'smp_recharge_group' ); $count++; }
        $msg = sprintf( 'Dispatched %d async top-up job%s via Action Scheduler.', $count, $count !== 1 ? 's' : '' );
    } else {
        foreach ( $user_ids as $user_id ) { smp_as_execute_recharge_logic( (int) $user_id ); $count++; }
        $msg = sprintf( 'Processed %d user%s synchronously.', $count, $count !== 1 ? 's' : '' );
    }
    wp_send_json_success( [ 'msg' => $msg, 'count' => $count ] );
} );

add_filter( 'action_scheduler_retention_period', function() { return DAY_IN_SECONDS * 30; } );

/* ==========================================================================
   LOGIN REDIRECT — wp-login.php → My Account page
   ========================================================================== */

add_action( 'init', function() {
    global $pagenow;
    $is_login_page = ( 'wp-login.php' === $pagenow )
        || ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'wp-login.php' ) !== false );
    if ( ! $is_login_page ) return;
    if ( is_admin() ) return;
    $action = sanitize_key( $_GET['action'] ?? '' );
    $allowed_actions = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'confirm_admin_email', 'postpass' ];
    if ( in_array( $action, $allowed_actions, true ) ) return;
    $my_account_url = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
    if ( ! $my_account_url ) return;
    wp_safe_redirect( esc_url_raw( $my_account_url ) );
    exit;
} );

add_filter( 'login_url', function( $login_url, $redirect ) {
    $my_account_url = get_permalink( get_option('woocommerce_myaccount_page_id') );
    // Only append redirect if it resolves to a same-site URL to prevent open redirect attacks.
    if ( ! empty( $redirect ) && wp_validate_redirect( $redirect, false ) ) {
        $my_account_url = add_query_arg( 'redirect', urlencode( $redirect ), $my_account_url );
    }
    return $my_account_url;
}, 100, 2 );

add_filter( 'register_url', function( $register_url ) {
    return get_permalink( get_option('woocommerce_myaccount_page_id') );
}, 100 );
