<?php
/**
 * Plugin Name: My Custom WC Account Ultimate 15.0.0
 * Description: Tabbed order dashboard with admin-style details, WP User points management field, flexible points redemption at checkout, and CustomerAnalytics admin panel.
 * Version: 15.0.0
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
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Namespacing / class refactor in 14.0.0 (professional plugin standard):
 * ─────────────────────────────────────────────────────────────────────────────
 *  All global smp_* functions and anonymous closures are encapsulated in
 *  dedicated static classes, eliminating naming collisions with other plugins.
 *  Each class exposes a static init() that registers its hooks; the file
 *  bottom bootstraps everything in one place.
 *
 *    SMP_Error                — unified JSON error response helper (v14.1+)
 *    SMP_Points_Engine        — core points CRUD (no hooks)
 *    SMP_Member_ID            — membership-number system + hooks
 *    SMP_Admin_Points_Field   — WP admin user-profile points UI
 *    SMP_Assets               — wp_enqueue_scripts / admin_enqueue_scripts
 *    SMP_Order_Ajax           — order-detail & status AJAX handlers
 *    SMP_Checkout_Points      — checkout redemption flow
 *    SMP_Shortcodes           — [order_lookup] and [smp_auth]
 *    SMP_Admin_Pages          — CustomerAnalytics + Settings admin pages
 *    SMP_Automation           — Action Scheduler top-up tasks
 *    SMP_Login_Redirect       — wp-login.php → My Account redirect
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Error handling & security hardening in 14.1.0 → 15.0.0:
 * ─────────────────────────────────────────────────────────────────────────────
 *  • SMP_Error class: all AJAX handlers return { success, data: { code, msg } }
 *    — wp_die() fully replaced; machine-readable error codes for the frontend.
 *  • add_user_points(): atomic INSERT-or-UPDATE with concurrent-insert guard.
 *  • deduct_user_points(): $wpdb->last_error checked after every query.
 *  • refund_points_for_order(): MySQL GET_LOCK advisory lock + try/finally
 *    prevents double-credit under concurrent webhook / status-change events.
 *  • ajax_adjust_points(): adj_action strict whitelist before any DB touch;
 *    add/deduct return values checked; DB errors surfaced as user-visible JSON.
 *  • ajax_get_admin_details() / ajax_update_status(): nonce failures and
 *    permission errors return typed JSON instead of wp_die() plain text.
 *  • JS smpToast(): self-contained toast notifications replace all alert() calls.
 *  • JS smpViewDetails(): handles JSON envelope; shows inline error card on fail.
 *  • JS smpErrorMsg(): shared utility extracts error text from any AJAX response.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SMP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMP_VERSION', '15.0.0' );

// Maximum points allowed in a single admin adjustment.
// Override in wp-config.php:  define( 'SMP_MAX_ADMIN_POINTS_ADJUST', 500_000 );
if ( ! defined( 'SMP_MAX_ADMIN_POINTS_ADJUST' ) ) {
	define( 'SMP_MAX_ADMIN_POINTS_ADJUST', 1_000_000 );
}

if ( ! defined( 'SMP_RECHARGE_ROLES' ) ) {
	define(
		'SMP_RECHARGE_ROLES',
		array(
			'customer'      => 100,
			'vip_customer'  => 500,
			'administrator' => 1000,
		)
	);
}

/*
==========================================================================
	CLASS 0 — SMP_Error
	Centralised, typed JSON error responses.
	All AJAX handlers call SMP_Error::send() instead of wp_die() so the client
	always receives a consistent { success: false, data: { code, msg } } envelope.
	========================================================================== */

/**
 * Centralised, typed JSON error responses.
 *
 * All AJAX handlers call SMP_Error::send() instead of wp_die() so the client
 * always receives a consistent { success: false, data: { code, msg } } envelope.
 * Error codes are defined as class constants for use in both PHP and the JS layer.
 */
class SMP_Error {

	/** Security or nonce check failed. */
	const SECURITY_FAILED = 'security_failed';
	/** Current user lacks the required capability. */
	const PERMISSION_DENIED = 'permission_denied';
	/** Too many requests in a short period. */
	const RATE_LIMITED = 'rate_limited';
	/** User ID does not correspond to a real account. */
	const INVALID_USER = 'invalid_user';
	/** Order ID is missing, zero, or not found. */
	const INVALID_ORDER = 'invalid_order';
	/** Points amount is out of the accepted range. */
	const INVALID_AMOUNT = 'invalid_amount';
	/** Action value is not in the allowed whitelist. */
	const INVALID_ACTION = 'invalid_action';
	/** User does not have enough points for this operation. */
	const INSUFFICIENT_PTS = 'insufficient_points';
	/** A database query failed or returned an unexpected result. */
	const DB_ERROR = 'db_error';
	/** A concurrent lock or unique-constraint conflict was detected. */
	const CONCURRENCY = 'concurrency_error';

	/**
	 * Send a typed JSON error and terminate execution.
	 *
	 * @param string $code        Machine-readable constant from this class.
	 * @param string $message     Human-readable message shown in the UI.
	 * @param int    $http_status HTTP status code (default 400).
	 */
	public static function send( string $code, string $message, int $http_status = 400 ): void {
		wp_send_json_error(
			array(
				'code' => $code,
				'msg'  => $message,
			),
			$http_status
		);
	}

	/**
	 * Log a security/DB anomaly to the error log.
	 *
	 * @param string $context  Short label, e.g. 'SMP_DEDUCT'.
	 * @param string $detail   Full detail string.
	 */
	public static function log( string $context, string $detail ): void {
		error_log( sprintf( '[%s] %s — site=%s', strtoupper( $context ), $detail, home_url() ) );
	}
}

/*
==========================================================================
	CLASS 1 — SMP_Points_Engine
	Core points CRUD. Pure data logic — no hooks registered here.
	========================================================================== */

/**
 * Core points CRUD engine.
 *
 * Pure data-access logic — no WordPress hooks are registered here.
 * Delegates to WC_Points_Rewards_Manager when that plugin is active;
 * otherwise operates directly on the smp_points user-meta row.
 */
class SMP_Points_Engine {

	/**
	 * Retrieve the current points balance for a user.
	 *
	 * Delegates to WC_Points_Rewards_Manager when active; otherwise reads
	 * the smp_points user-meta (may be served from object cache).
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to the current user.
	 * @return int Current points balance.
	 */
	public static function get_user_points( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
			return (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
		}
		return (int) get_user_meta( $user_id, 'smp_points', true );
	}

	/**
	 * Read the current points balance directly from the database.
	 *
	 * Bypasses the object cache to obtain an authoritative value immediately
	 * after a write. Delegates to WC_Points_Rewards_Manager when active.
	 *
	 * @param int|null $user_id WordPress user ID. Defaults to the current user.
	 * @return int Current points balance.
	 */
	public static function get_user_points_from_db( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
			return (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
		}
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CAST(meta_value AS UNSIGNED) FROM {$wpdb->usermeta}
             WHERE user_id = %d AND meta_key = 'smp_points' LIMIT 1",
				(int) $user_id
			)
		);
		return (int) $val;
	}

	/**
	 * Atomically deduct points from a user's balance.
	 *
	 * Uses a single UPDATE with an inline WHERE guard so the balance cannot
	 * go below zero even under concurrent requests. Returns false without
	 * modifying the database if the balance is insufficient.
	 *
	 * @param int      $user_id  Target WordPress user ID.
	 * @param int      $points   Number of points to deduct.
	 * @param int|null $order_id Optional order ID to include in the audit log.
	 * @return bool True on success, false if insufficient balance or DB error.
	 */
	public static function deduct_user_points( $user_id, $points, $order_id = null ) {
		if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
			$current = (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
			if ( $current < $points ) {
				return false;
			}
			$note = $order_id
				? sprintf( 'Points redeemed for Order #%d', $order_id )
				: 'Points redeemed at checkout';
			WC_Points_Rewards_Manager::decrease_points( $user_id, $points, 'order-redeem', $note, $order_id );
			return true;
		}

		global $wpdb;

		/*
		 * Atomic single-statement deduction:
		 * The WHERE clause `CAST(meta_value AS UNSIGNED) >= %d` acts as an
		 * optimistic concurrency guard — if another request already deducted
		 * points and the balance dropped below `$points`, this UPDATE matches
		 * zero rows and we return false without touching the balance.
		 */
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta}
             SET    meta_value = CAST(meta_value AS UNSIGNED) - %d
             WHERE  user_id   = %d
             AND    meta_key  = 'smp_points'
             AND    CAST(meta_value AS UNSIGNED) >= %d",
				(int) $points,
				(int) $user_id,
				(int) $points
			)
		);

		if ( $wpdb->last_error ) {
			SMP_Error::log( 'SMP_DEDUCT_DB', "user={$user_id} err={$wpdb->last_error}" );
			return false;
		}

		if ( $rows > 0 ) {
			wp_cache_delete( $user_id, 'user_meta' );
			if ( function_exists( 'clean_user_cache' ) ) {
				clean_user_cache( $user_id );
			}
			SMP_Error::log(
				'SMP_DEDUCT',
				sprintf(
					'actor=%d target=%d pts=%d order=%s',
					get_current_user_id(),
					$user_id,
					$points,
					$order_id ? (int) $order_id : 'n/a'
				)
			);
		}

		return ( $rows > 0 );
	}

	/**
	 * Atomically add points to a user's balance.
	 *
	 * Tries an UPDATE first. If no row exists, inserts via add_user_meta()
	 * with $unique = true; a concurrent-insert guard retries the UPDATE on failure.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $points  Number of points to add.
	 * @param string $note    Optional human-readable note for the log.
	 * @return bool True on success, false on DB error.
	 */
	public static function add_user_points( $user_id, $points, $note = '' ) {
		if ( class_exists( 'WC_Points_Rewards_Manager' ) ) {
			WC_Points_Rewards_Manager::increase_points( $user_id, $points, 'admin-adjustment', $note ?: 'Manual adjustment by admin' );
			return true;
		}

		global $wpdb;

		/*
		 * Atomic balance increase.  We try an UPDATE first; if zero rows are
		 * affected the meta row does not yet exist, so we fall through to an
		 * INSERT via add_user_meta(..., $unique = true).
		 *
		 * Using $unique = true means add_user_meta() itself will refuse to
		 * insert if a concurrent request has already created the row between
		 * our UPDATE and INSERT — in that case we do one final UPDATE retry,
		 * which is now guaranteed to match exactly one row.
		 */
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta}
             SET    meta_value = CAST(meta_value AS UNSIGNED) + %d
             WHERE  user_id  = %d
             AND    meta_key = 'smp_points'",
				(int) $points,
				(int) $user_id
			)
		);

		if ( $wpdb->last_error ) {
			SMP_Error::log( 'SMP_ADD_DB', "user={$user_id} err={$wpdb->last_error}" );
			return false;
		}

		if ( 0 === $rows ) {
			// No existing row — try a unique INSERT
			$inserted = add_user_meta( $user_id, 'smp_points', (int) $points, true );
			if ( ! $inserted ) {
				// Another concurrent process inserted the row first — retry UPDATE
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->usermeta}
                     SET    meta_value = CAST(meta_value AS UNSIGNED) + %d
                     WHERE  user_id  = %d
                     AND    meta_key = 'smp_points'",
						(int) $points,
						(int) $user_id
					)
				);
				if ( $wpdb->last_error ) {
					SMP_Error::log( 'SMP_ADD_RETRY_DB', "user={$user_id} err={$wpdb->last_error}" );
					return false;
				}
			}
		}

		wp_cache_delete( $user_id, 'user_meta' );
		if ( function_exists( 'clean_user_cache' ) ) {
			clean_user_cache( $user_id );
		}
		return true;
	}

	/**
	 * Return the active points configuration.
	 *
	 * Priority: (1) custom smp_pts_config option, (2) WC Points & Rewards
	 * plugin settings, (3) built-in defaults.
	 *
	 * @return array{earn_rate: float, redeem_rate: float, max_discount_pct: float, min_redeem: int, label: string}
	 */
	public static function get_points_config() {
		$defaults = array(
			'earn_rate'        => 1,
			'redeem_rate'      => 100,
			'max_discount_pct' => 50,
			'min_redeem'       => 100,
			'label'            => 'Points',
		);
		$custom   = get_option( 'smp_pts_config', array() );
		if ( ! empty( $custom ) ) {
			return wp_parse_args( $custom, $defaults );
		}
		if ( class_exists( 'WC_Points_Rewards' ) ) {
			$options = get_option( 'wc_points_rewards' );
			if ( ! empty( $options ) ) {
				$config = $defaults;
				if ( isset( $options['earn_points_per_dollar'] ) ) {
					$config['earn_rate'] = (float) $options['earn_points_per_dollar'];
				}
				if ( isset( $options['redeem_points_per_dollar'] ) ) {
					$config['redeem_rate'] = (float) $options['redeem_points_per_dollar'];
				}
				if ( isset( $options['max_discount'] ) && '' !== $options['max_discount'] ) {
																		$config['max_discount_pct'] = (float) $options['max_discount'];
				}
				if ( isset( $options['minimum_points_amount'] ) ) {
					$config['min_redeem'] = (int) $options['minimum_points_amount'];
				}
				if ( isset( $options['points_label'] ) ) {
					$config['label'] = $options['points_label'];
				}
				return $config;
			}
		}
		return $defaults;
	}

	/**
	 * Calculate the maximum discount a user may apply on the current cart.
	 *
	 * Returns the lesser of (a) the monetary value of all the user's points
	 * and (b) the configured percentage cap applied to the cart subtotal.
	 *
	 * @param int   $user_points Current points balance of the user.
	 * @param float $cart_total  Cart subtotal in the store currency.
	 * @return float Maximum discount amount, rounded to two decimal places.
	 */
	public static function calc_max_discount( $user_points, $cart_total ) {
		$cfg           = self::get_points_config();
		$max_by_points = floor( $user_points / $cfg['redeem_rate'] * 100 ) / 100;
		$max_by_order  = round( $cart_total * ( $cfg['max_discount_pct'] / 100 ), 2 );
		return min( $max_by_points, $max_by_order );
	}

	/**
	 * Refund the redeemed points for a cancelled or refunded order.
	 *
	 * Acquires a MySQL advisory lock scoped to the order ID to prevent
	 * two concurrent webhooks from both passing the "already refunded?"
	 * guard and issuing a double credit.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $reason   Human-readable reason written to the points log.
	 * @return bool True when points were successfully refunded, false otherwise.
	 */
	public static function refund_points_for_order( $order_id, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		global $wpdb;

		/*
		 * MySQL advisory lock prevents two concurrent requests (e.g. two rapid
		 * status-change webhooks) from both passing the "already refunded?"
		 * check and issuing a double credit.
		 *
		 * GET_LOCK(name, timeout):
		 *   Returns 1  → we own the lock; proceed.
		 *   Returns 0  → timed out; another process holds it → abort.
		 *   Returns NULL → DB error → abort.
		 * The lock name is scoped to the order ID so parallel refunds for
		 * different orders do not block each other.
		 */
		$lock_name   = 'smp_refund_' . (int) $order_id;
		$lock_result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

		if ( '1' !== $lock_result ) {
			SMP_Error::log( 'SMP_REFUND_LOCK', "order={$order_id} — could not acquire lock (result={$lock_result})" );
			return false;
		}

		try {
			// Re-read order meta after acquiring the lock (double-checked locking).
			$order->read_meta_data( true );

			$pts_used = (int) $order->get_meta( '_smp_pts_used' );
			if ( $pts_used <= 0 ) {
				return false;
			}
			if ( $order->get_meta( '_smp_points_refunded' ) ) {
				return false;
			}

			$user_id = (int) $order->get_customer_id();
			if ( $user_id <= 0 ) {
				return false;
			}

			if ( ! $reason ) {
				$reason = sprintf( 'Points refunded — Order #%d cancelled/refunded', $order_id );
			}

			// Mark as refunded before crediting to prevent any second entry.
			$order->update_meta_data( '_smp_points_refunded', 1 );
			$order->save();

			self::add_user_points( $user_id, $pts_used, $reason );
			$balance_after = self::get_user_points_from_db( $user_id );
			self::append_log( $user_id, 'refund', $pts_used, $balance_after, $reason, 'system' );

			return true;

		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Append one entry to a user's points log, keeping only the last 100 records.
	 *
	 * @param int    $user_id       Target user ID.
	 * @param string $action        Action type: 'add', 'deduct', or 'set'.
	 * @param int    $amount        Number of points affected.
	 * @param int    $balance_after Points balance recorded after this operation.
	 * @param string $note          Human-readable description.
	 * @param string $by            Actor identifier (e.g. user_login or 'system').
	 * @return void
	 */
	public static function append_log( $user_id, $action, $amount, $balance_after, $note, $by ) {
		$log = get_user_meta( $user_id, 'smp_points_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'date'          => current_time( 'Y-m-d H:i' ),
			'action'        => $action,
			'amount'        => $amount,
			'balance_after' => $balance_after,
			'note'          => $note,
			'by'            => $by,
		);
		update_user_meta( $user_id, 'smp_points_log', array_slice( $log, -100 ) );
	}
}

/*
==========================================================================
	CLASS 2 — SMP_Member_ID
	Membership-number assignment, display, and admin columns.
	========================================================================== */

/**
 * Membership-number assignment, display, and admin columns.
 *
 * Generates a sequential, prefixed member ID (e.g. MBR000042) for every
 * registered user and exposes it in the WP admin user-list and on the
 * WooCommerce account page.
 */
class SMP_Member_ID {

	/**
	 * Cached member-number configuration array.
	 *
	 * @var array|null
	 */
	private static $config_cache = null;

	/**
	 * Register all WordPress hooks for the membership-number system.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'assign' ) );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'show_user_profile', array( __CLASS__, 'render_admin_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_admin_field' ) );
		add_action( 'woocommerce_edit_account_form_start', array( __CLASS__, 'render_account_cards' ) );
		add_filter( 'smp_dashboard_member_id', array( __CLASS__, 'get_current' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'handle_sort' ) );
	}

	/**
	 * Return the validated member-number configuration, with in-memory caching.
	 *
	 * @return array{prefix: string, num_length: int}
	 */
	public static function get_config() {
		if ( null !== self::$config_cache ) {
			return self::$config_cache;
		}
		$saved               = get_option( 'smp_member_num_config', array() );
		$cache               = wp_parse_args(
			$saved,
			array(
				'prefix'     => 'MBR',
				'num_length' => 6,
			)
		);
		$cache['prefix']     = strtoupper( preg_replace( '/[^A-Za-z]/', '', $cache['prefix'] ) ) ?: 'MBR';
		$cache['num_length'] = max( 1, min( 10, intval( $cache['num_length'] ) ) );
		self::$config_cache  = $cache;
		return self::$config_cache;
	}

	/**
	 * Assign a new sequential member ID to a user if one does not yet exist.
	 *
	 * Uses MySQL LAST_INSERT_ID() for an atomic auto-increment on the
	 * smp_member_num_counter option, avoiding race conditions on concurrent logins.
	 *
	 * @param int $user_id WordPress user ID to assign a member ID to.
	 * @return string|false The assigned member ID string, or false on failure.
	 */
	public static function assign( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$existing = get_user_meta( $user_id, 'smp_member_id', true );
		if ( $existing ) {
			return (string) $existing;
		}
		if ( get_option( 'smp_member_num_counter' ) === false ) {
			add_option( 'smp_member_num_counter', 0, '', 'no' );
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
             SET    option_value = LAST_INSERT_ID( CAST(option_value AS UNSIGNED) + 1 )
             WHERE  option_name  = %s",
				'smp_member_num_counter'
			)
		);
		$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ( $seq <= 0 ) {
			return false;
		}
		$cfg       = self::get_config();
		$member_id = $cfg['prefix'] . str_pad( $seq, $cfg['num_length'], '0', STR_PAD_LEFT );
		update_user_meta( $user_id, 'smp_member_id', $member_id );
		return $member_id;
	}

	/**
	 * Get the member ID for a user, assigning one on-the-fly if needed.
	 *
	 * @param int $user_id WordPress user ID. Defaults to the current user.
	 * @return string Member ID string, or empty string if the user is not logged in.
	 */
	public static function get( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return '';
		}
		$id = get_user_meta( $user_id, 'smp_member_id', true );
		if ( $id ) {
			return (string) $id;
		}
		return (string) self::assign( $user_id );
	}

	/**
	 * Return the member ID for the currently authenticated user.
	 *
	 * Callback for the smp_dashboard_member_id filter.
	 *
	 * @return string Member ID string, or empty string if not logged in.
	 */
	public static function get_current() {
		return self::get();
	}

	/**
	 * Ensure a member ID is assigned whenever a user logs in.
	 *
	 * Hooked to wp_login (priority 10). Calls assign(), which is a no-op when
	 * the user already has a member ID.
	 *
	 * @param string  $user_login The user's login name (unused).
	 * @param WP_User $user       The WP_User object of the authenticating user.
	 * @return void
	 */
	public static function on_login( $user_login, $user ) {
		self::assign( $user->ID );
	}

	/**
	 * Render the read-only member ID field on the WP admin user-edit screen.
	 *
	 * @param WP_User $user The user object for the profile being viewed.
	 * @return void
	 */
	public static function render_admin_field( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$member_id = self::get( $user->ID );
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

	/**
	 * Render the member ID and points balance cards on the WooCommerce account page.
	 *
	 * Hooked to woocommerce_edit_account_form_start.
	 *
	 * @return void
	 */
	public static function render_account_cards() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id   = get_current_user_id();
		$member_id = self::get( $user_id );
		$pts       = SMP_Points_Engine::get_user_points( $user_id );
		$cfg       = SMP_Points_Engine::get_points_config();
		$sym       = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		if ( ! $member_id && $pts <= 0 ) {
			return;
		}
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

			<?php
			if ( $pts > 0 ) :
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
	}

	/**
	 * Inject Member ID and Points columns into the WP Users list table.
	 *
	 * @param array $columns Existing column definitions.
	 * @return array Modified column definitions with the new columns inserted after Email.
	 */
	public static function add_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'email' === $key ) {
				$new['smp_member_id'] = '🪪 Member ID';
				$new['smp_points']    = '⭐ Points';
			}
		}
		return $new;
	}

	/**
	 * Render the cell content for the custom Member ID and Points columns.
	 *
	 * @param string $output      Default column output (passed through for unknown columns).
	 * @param string $column_name Slug of the column being rendered.
	 * @param int    $user_id     ID of the user for the current row.
	 * @return string HTML content for the cell.
	 */
	public static function render_column( $output, $column_name, $user_id ) {
		if ( 'smp_member_id' === $column_name ) {
			$mid = get_user_meta( $user_id, 'smp_member_id', true );
			if ( ! $mid ) {
				return '<span style="color:#aaa;">—</span>';
			}
			return '<code style="font-size:13px;letter-spacing:1.5px;background:#f1f5f9;padding:3px 8px;border-radius:4px;font-family:monospace;">' . esc_html( $mid ) . '</code>';
		}
		if ( 'smp_points' === $column_name ) {
			$pts = SMP_Points_Engine::get_user_points( $user_id );
			if ( $pts <= 0 ) {
				return '<span style="color:#aaa;">0</span>';
			}
			$edit_url = admin_url( 'user-edit.php?user_id=' . intval( $user_id ) . '#smp-points-panel' );
			return '<a href="' . esc_url( $edit_url ) . '" style="display:inline-block;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;padding:2px 10px;font-weight:700;font-size:13px;text-decoration:none;">' . esc_html( number_format( $pts ) ) . '</a>';
		}
		return $output;
	}

	/**
	 * Register the custom columns as sortable in the WP Users list table.
	 *
	 * @param array $columns Existing sortable column definitions.
	 * @return array Modified definitions including smp_member_id and smp_points.
	 */
	public static function sortable_columns( $columns ) {
		$columns['smp_member_id'] = 'smp_member_id';
		$columns['smp_points']    = 'smp_points';
		return $columns;
	}

	/**
	 * Apply meta-value sorting when the custom user-list columns are selected.
	 *
	 * @param WP_User_Query $query The current user query object, modified in-place.
	 * @return void
	 */
	public static function handle_sort( $query ) {
		if ( ! is_admin() ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( 'smp_member_id' === $orderby ) {
			$query->set( 'meta_key', 'smp_member_id' );
			$query->set( 'orderby', 'meta_value_num' ); }
		if ( 'smp_points' === $orderby ) {
			$query->set( 'meta_key', 'smp_points' );
			$query->set( 'orderby', 'meta_value_num' ); }
	}
}

/*
==========================================================================
	CLASS 3 — SMP_Admin_Points_Field
	WP admin user-profile points management UI + AJAX handler.
	========================================================================== */

/**
 * WP admin user-profile points management UI and AJAX handler.
 *
 * Adds a dedicated "Points Management" panel to the user-edit screen that
 * allows administrators to add, deduct, or set an exact points balance
 * and view the last 10 adjustment log entries.
 */
class SMP_Admin_Points_Field {

	/**
	 * Register WordPress hooks for the admin points management UI.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'wp_ajax_smp_admin_adjust_points', array( __CLASS__, 'ajax_adjust_points' ) );
	}

	/**
	 * Render the "Points Management" panel on the WP admin user-edit screen.
	 *
	 * Displays the live balance, an adjustment form (add / deduct / set), and
	 * the last 10 log entries. Restricted to administrators.
	 *
	 * @param WP_User $user The user object for the profile being edited.
	 * @return void
	 */
	public static function render_field( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_pts = SMP_Points_Engine::get_user_points( $user->ID );
		$cfg         = SMP_Points_Engine::get_points_config();

		$log = get_user_meta( $user->ID, 'smp_points_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log_display = array_slice( array_reverse( $log ), 0, 10 );
		?>
		<div id="smp-points-section">
			<div class="smp-section-head">
				<h2>🌟 Points Management</h2>
				<span style="font-size:12px;color:#666;">Plugin: My Custom WC Account V<?php echo esc_html( SMP_VERSION ); ?></span>
			</div>
			<div class="smp-section-body">

				<?php if ( class_exists( 'WC_Points_Rewards' ) ) : ?>
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
						<strong>Cash value:</strong> <span id="smp-live-cash">$<?php echo esc_html( number_format( $current_pts / $cfg['redeem_rate'], 2 ) ); ?></span><br>
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
									<td><span class="smp-pts-badge <?php echo esc_attr( $entry['action'] ); ?>"><?php echo esc_html( ucfirst( $entry['action'] ) ); ?></span></td>
									<td style="font-weight:700;color:<?php echo esc_attr( 'deduct' === $entry['action'] ? '#dc2626' : '#16a34a' ); ?>">
										<?php echo 'deduct' === $entry['action'] ? '-' : '+'; ?><?php echo esc_html( number_format( $entry['amount'] ) ); ?>
									</td>
									<td><?php echo esc_html( number_format( $entry['balance_after'] ) ); ?></td>
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

	/**
	 * AJAX handler: apply an admin points adjustment (add / deduct / set).
	 *
	 * Validates nonce, capability, rate limit, user, amount, and action whitelist
	 * before touching the database. Returns the updated balance on success or a
	 * typed JSON error via SMP_Error::send() on failure.
	 *
	 * @return void Terminates with wp_send_json_success() or SMP_Error::send().
	 */
	public static function ajax_adjust_points() {
		$user_id = intval( $_POST['user_id'] ?? 0 );

		if ( ! check_ajax_referer( 'smp_adjust_points_' . $user_id, 'nonce', false ) ) {
			SMP_Error::send( SMP_Error::SECURITY_FAILED, 'Security check failed. Please refresh the page.', 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			SMP_Error::send( SMP_Error::PERMISSION_DENIED, 'Permission denied.', 403 );
		}

		// Rate limiting
		$admin_rate_key = 'smp_admin_adj_rate_' . get_current_user_id();
		$admin_attempts = (int) get_transient( $admin_rate_key );
		if ( $admin_attempts >= 30 ) {
			SMP_Error::send( SMP_Error::RATE_LIMITED, 'Rate limit reached. Please wait 10 minutes before making more adjustments.', 429 );
		}
		set_transient( $admin_rate_key, $admin_attempts + 1, 10 * MINUTE_IN_SECONDS );

		// User validation
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			SMP_Error::send( SMP_Error::INVALID_USER, 'Invalid or non-existent user.', 400 );
		}

		// Amount validation
		$amount = abs( intval( $_POST['amount'] ?? 0 ) );
		if ( $amount < 1 ) {
			SMP_Error::send( SMP_Error::INVALID_AMOUNT, 'Amount must be at least 1.', 400 );
		}
		if ( $amount > SMP_MAX_ADMIN_POINTS_ADJUST ) {
			SMP_Error::send(
				SMP_Error::INVALID_AMOUNT,
				sprintf(
					'Amount exceeds the maximum single adjustment limit (%s pts).',
					number_format( SMP_MAX_ADMIN_POINTS_ADJUST )
				),
				400
			);
		}

		// Action whitelist — reject anything not in the allowed set before touching DB
		$action          = sanitize_key( $_POST['adj_action'] ?? '' );
		$allowed_actions = array( 'add', 'deduct', 'set' );
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			SMP_Error::send( SMP_Error::INVALID_ACTION, 'Unknown adjustment action.', 400 );
		}

		$note        = sanitize_text_field( $_POST['note'] ?? '' );
		$admin       = wp_get_current_user();
		$current_pts = SMP_Points_Engine::get_user_points( $user_id );
		$msg         = '';
		$ok          = true;

		if ( 'add' === $action ) {
			$ok  = SMP_Points_Engine::add_user_points( $user_id, $amount, $note ?: 'Manual addition by admin' );
			$msg = $amount . ' points added';

		} elseif ( 'deduct' === $action ) {
			$amount = min( $amount, $current_pts );
			$ok     = SMP_Points_Engine::deduct_user_points( $user_id, $amount );
			$msg    = $amount . ' points deducted';

		} elseif ( 'set' === $action ) {
			$delta = $amount - $current_pts;
			if ( $delta > 0 ) {
				$ok = SMP_Points_Engine::add_user_points( $user_id, $delta, $note ?: 'Balance set by admin' );
			} elseif ( $delta < 0 ) {
				$ok = SMP_Points_Engine::deduct_user_points( $user_id, abs( $delta ) );
			}
			$note = $note ?: 'Balance set to ' . $amount;
			$msg  = 'Balance set to ' . $amount . ' points';
		}

		if ( ! $ok ) {
			SMP_Error::send( SMP_Error::DB_ERROR, 'The adjustment could not be saved. This may be caused by a database error or insufficient points balance. Please try again.', 500 );
		}

		$balance_after = SMP_Points_Engine::get_user_points_from_db( $user_id );
		SMP_Points_Engine::append_log( $user_id, $action, $amount, $balance_after, $note, $admin->user_login );

		wp_send_json_success(
			array(
				'msg'     => $msg,
				'balance' => $balance_after,
				'date'    => current_time( 'Y-m-d H:i' ),
				'by'      => $admin->user_login,
			)
		);
	}
}

/*
==========================================================================
	CLASS 4 — SMP_Assets
	wp_enqueue_scripts and admin_enqueue_scripts management.
	========================================================================== */

/**
 * Script and style enqueue management.
 *
 * Handles wp_enqueue_scripts (frontend account + checkout) and
 * admin_enqueue_scripts (user-edit + settings pages), and passes
 * runtime PHP values to JavaScript via wp_localize_script().
 */
class SMP_Assets {

	/**
	 * Register all WordPress script and style enqueue hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_global_css' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'wp', array( __CLASS__, 'maybe_load_for_shortcodes' ) );
	}

	/**
	 * Enqueue frontend scripts and styles on the account and checkout pages.
	 *
	 * Passes runtime data (AJAX URL, nonces, points limits) to JavaScript via
	 * wp_localize_script(). The smp_load_scripts filter lets themes force-enable
	 * or disable loading.
	 *
	 * @return void
	 */
	public static function enqueue_frontend() {
		$on_account  = function_exists( 'is_account_page' ) && is_account_page();
		$on_checkout = function_exists( 'is_checkout' ) && is_checkout();
		$load_smp    = apply_filters( 'smp_load_scripts', $on_account || $on_checkout );

		if ( ! $load_smp ) {
			return;
		}

		wp_enqueue_style(
			'smp-frontend',
			SMP_PLUGIN_URL . 'assets/css/smp-frontend.css',
			array(),
			SMP_VERSION
		);

		wp_enqueue_script(
			'smp-ajax',
			SMP_PLUGIN_URL . 'assets/js/smp-ajax.js',
			array( 'jquery' ),
			SMP_VERSION,
			true
		);

		$data = array(
			'ajaxUrl' => esc_url( admin_url( 'admin-ajax.php' ) ),
		);

		if ( $on_account || ( $load_smp && ! $on_checkout ) ) {
			$data['detailNonce'] = wp_create_nonce( 'smp_detail_nonce' );
			$data['orderNonce']  = wp_create_nonce( 'smp_order_nonce' );
		}

		if ( $on_checkout && is_user_logged_in()
			&& ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) ) {

			$user_id     = get_current_user_id();
			$user_pts    = SMP_Points_Engine::get_user_points( $user_id );
			$cfg         = SMP_Points_Engine::get_points_config();
			$cart_total  = self::get_cart_total();
			$max_disc    = SMP_Points_Engine::calc_max_discount( $user_pts, $cart_total );
			$max_pts     = min( $user_pts, (int) ceil( $max_disc * $cfg['redeem_rate'] ) );
			$applied_pts = WC()->session ? (int) WC()->session->get( 'smp_pts_used', 0 ) : 0;
			$sym         = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

			$data['ptsNonce']    = wp_create_nonce( 'smp_pts_nonce' );
			$data['ptsRate']     = floatval( $cfg['redeem_rate'] > 0 ? $cfg['redeem_rate'] : 100 );
			$data['ptsMaxd']     = floatval( $max_disc );
			$data['ptsMax']      = intval( $max_pts > 0 ? $max_pts : $user_pts );
			$data['ptsMin']      = intval( $cfg['min_redeem'] );
			$data['ptsCur']      = $sym;
			$data['autoApplied'] = ( $applied_pts > 0 );
		}

		wp_localize_script( 'smp-ajax', 'SMP', $data );

		wp_add_inline_script(
			'smp-ajax',
			'window.SMP_AJAX_URL     = (window.SMP && window.SMP.ajaxUrl)     || "";' .
			'window.SMP_DETAIL_NONCE = (window.SMP && window.SMP.detailNonce) || "";' .
			'window.SMP_ORDER_NONCE  = (window.SMP && window.SMP.orderNonce)  || "";',
			'after'
		);
	}

	/**
	 * Enqueue the frontend stylesheet on non-admin pages that may render shortcodes.
	 *
	 * Covers the front page, shop, cart, checkout, and all static pages.
	 *
	 * @return void
	 */
	public static function enqueue_global_css() {
		if ( is_admin() ) {
			return;
		}
		$on_target = is_front_page() || is_home()
					|| ( function_exists( 'is_shop' ) && is_shop() )
					|| ( function_exists( 'is_cart' ) && is_cart() )
					|| ( function_exists( 'is_checkout' ) && is_checkout() )
					|| is_page();
		if ( ! $on_target ) {
			return;
		}
		wp_enqueue_style( 'smp-frontend', SMP_PLUGIN_URL . 'assets/css/smp-frontend.css', array(), SMP_VERSION );
	}

	/**
	 * Enqueue admin scripts and styles on user-edit and plugin settings screens.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin( $hook ) {
		$admin_pages  = array( 'profile.php', 'user-edit.php' );
		$our_pages    = array( 'smp-customer-analytics', 'smp-points-settings' );
		$current_page = $_GET['page'] ?? '';

		if ( ! in_array( $hook, $admin_pages, true ) && ! in_array( $current_page, $our_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'smp-admin',
			SMP_PLUGIN_URL . 'assets/css/smp-admin.css',
			array(),
			SMP_VERSION
		);

		wp_enqueue_script(
			'smp-ajax',
			SMP_PLUGIN_URL . 'assets/js/smp-ajax.js',
			array( 'jquery' ),
			SMP_VERSION,
			true
		);

		$dec = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		wp_localize_script(
			'smp-ajax',
			'SMP',
			array(
				'ajaxUrl'    => esc_url( admin_url( 'admin-ajax.php' ) ),
				'wcDec'      => $dec,
				'topupNonce' => wp_create_nonce( 'smp_topup_run_now' ),
			)
		);

		wp_add_inline_script(
			'smp-ajax',
			'window.SMP_AJAX_URL    = (window.SMP && window.SMP.ajaxUrl)    || "";' .
			'window.SMP_TOPUP_NONCE = (window.SMP && window.SMP.topupNonce) || "";' .
			'window.SMP_WC_DEC      = (window.SMP && window.SMP.wcDec)      || ".";',
			'after'
		);
	}

	/**
	 * Force-enable frontend asset loading when a plugin shortcode is present.
	 *
	 * Hooked to wp (after the global $post is available). Adds the
	 * smp_load_scripts filter callback when [smp_auth] or [order_lookup]
	 * is found in the current post's content.
	 *
	 * @return void
	 */
	public static function maybe_load_for_shortcodes() {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( has_shortcode( $post->post_content, 'smp_auth' )
			|| has_shortcode( $post->post_content, 'order_lookup' ) ) {
			add_filter( 'smp_load_scripts', '__return_true' );
		}
	}

	/**
	 * Return the current WooCommerce cart subtotal, with layered fallbacks.
	 *
	 * Tries get_subtotal(), then get_cart_contents_total(), then iterates
	 * line items to ensure a non-zero value during partial cart-calculation cycles.
	 *
	 * @return float Cart subtotal in the store's base currency.
	 */
	public static function get_cart_total() {
		$cart_total = 0;
		if ( WC()->cart && ! is_admin() ) {
			$cart_total = (float) WC()->cart->get_subtotal();
			if ( $cart_total <= 0 ) {
				$cart_total = (float) WC()->cart->get_cart_contents_total();
			}
			if ( $cart_total <= 0 ) {
				foreach ( WC()->cart->get_cart() as $item ) {
					$cart_total += isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0;
				}
			}
		}
		return $cart_total;
	}
}

/*
==========================================================================
	CLASS 5 — SMP_Order_Ajax
	AJAX handlers for the order-detail panel and inline status updates.
	========================================================================== */

/**
 * AJAX handlers for the order-detail panel and inline status updates.
 *
 * Serves the tabbed order dashboard shortcode: one handler returns an HTML
 * snapshot of admin-style order details wrapped in a JSON envelope; the
 * other applies an inline status change and returns the new status label.
 */
class SMP_Order_Ajax {

	/**
	 * Register AJAX action hooks for order details and status updates.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_smp_get_admin_details', array( __CLASS__, 'ajax_get_admin_details' ) );
		add_action( 'wp_ajax_smp_ajax_update_status', array( __CLASS__, 'ajax_update_status' ) );
	}

	/**
	 * AJAX handler: return admin-style order details as an HTML snippet.
	 *
	 * Verifies the nonce, validates the order ID, checks ownership or admin
	 * capability, then renders the detail view into an output buffer and returns
	 * it wrapped in a JSON success envelope.
	 *
	 * @return void Terminates with wp_send_json_success() or SMP_Error::send().
	 */
	public static function ajax_get_admin_details() {
		if ( ! check_ajax_referer( 'smp_detail_nonce', 'nonce', false ) ) {
			SMP_Error::send( SMP_Error::SECURITY_FAILED, 'Security check failed. Please refresh and try again.', 403 );
		}

		$order_id = intval( $_POST['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			SMP_Error::send( SMP_Error::INVALID_ORDER, 'Invalid order ID.', 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			SMP_Error::send( SMP_Error::INVALID_ORDER, 'Order not found.', 404 );
		}

		if ( ! current_user_can( 'manage_options' ) && (int) $order->get_customer_id() !== (int) get_current_user_id() ) {
			SMP_Error::send( SMP_Error::PERMISSION_DENIED, 'You do not have permission to view this order.', 403 );
		}

		$is_admin = current_user_can( 'manage_options' );
		ob_start();
		?>

		<div class="smp-detail-wrapper">

			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:1px solid #eee;padding-bottom:15px;">
				<h2 style="margin:0;">Order #<?php echo esc_html( $order->get_id() ); ?> Details</h2>
				<button onclick="smpSwitchTab('tab-list')" style="padding:8px 16px;cursor:pointer;border:1px solid #ddd;border-radius:6px;background:#fff;">← Back to List</button>
			</div>

			<div class="smp-admin-grid">

				<div>
					<div class="smp-section-title">General</div>
					<span class="smp-label">Date Placed</span>
					<div class="smp-value-box">
					<?php
						$date_obj = $order->get_date_created();
						echo $date_obj ? esc_html( $date_obj->date( 'Y-m-d H:i' ) ) : '—';
					?>
					</div>
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
						<td><?php echo wp_kses_post( wc_price( $order->get_item_subtotal( $item ) ) ); ?></td>
						<td>× <?php echo intval( $item->get_quantity() ); ?></td>
						<td style="text-align:right;padding-right:15px;font-weight:700;"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
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
			$pts_used     = (int) $order->get_meta( '_smp_pts_used' );
			$pts_refunded = (bool) $order->get_meta( '_smp_points_refunded' );
			if ( $pts_used > 0 ) :
				?>
			<div style="margin-top:15px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:13px;color:#166534;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
				<span>🎁 <strong><?php echo esc_html( number_format( $pts_used ) ); ?> points</strong> were redeemed on this order.</span>
				<?php if ( $pts_refunded ) : ?>
					<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">✅ Points Refunded</span>
				<?php elseif ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) : ?>
					<span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">⚠️ Refund Pending</span>
				<?php else : ?>
					<span style="background:#fef9c3;color:#854d0e;border:1px solid #fde047;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">⏳ Active</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div>

		<?php
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX handler: update the status of a WooCommerce order.
	 *
	 * Requires manage_options capability and a valid nonce. The new status is
	 * validated against the list returned by wc_get_order_statuses().
	 *
	 * @return void Terminates with wp_send_json_success() or SMP_Error::send().
	 */
	public static function ajax_update_status() {
		if ( ! check_ajax_referer( 'smp_order_nonce', 'nonce', false ) ) {
			SMP_Error::send( SMP_Error::SECURITY_FAILED, 'Security check failed.', 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			SMP_Error::send( SMP_Error::PERMISSION_DENIED, 'Permission denied.', 403 );
		}
		$order_id = intval( $_POST['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			SMP_Error::send( SMP_Error::INVALID_ORDER, 'Invalid order ID.', 400 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			SMP_Error::send( SMP_Error::INVALID_ORDER, 'Order not found.', 404 );
		}
		$new_status = str_replace( 'wc-', '', sanitize_text_field( $_POST['status'] ?? '' ) );
		$allowed    = array_keys( wc_get_order_statuses() );
		if ( ! in_array( 'wc-' . $new_status, $allowed, true ) ) {
			SMP_Error::send( SMP_Error::INVALID_ACTION, 'Invalid order status value.', 400 );
		}
		$order->update_status( $new_status );
		wp_send_json_success( array( 'name' => wc_get_order_status_name( $new_status ) ) );
	}
}

/*
==========================================================================
	CLASS 6 — SMP_Checkout_Points
	Checkout redemption panel, cart fees, order processing, and refunds.
	========================================================================== */

/**
 * Checkout redemption panel, cart fees, order processing, and refunds.
 *
 * Renders the points-redemption widget on the checkout page, handles the
 * AJAX apply/clear actions, adds a WooCommerce cart fee for the discount,
 * deducts points when an order is placed, and refunds them on cancellation.
 */
class SMP_Checkout_Points {

	/**
	 * Prevents the checkout redemption panel from rendering more than once per request.
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Register all WooCommerce hooks for the checkout points flow.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_panel_early' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'render_panel_late' ), 5 );
		add_action( 'wp_ajax_smp_get_pts_limits', array( __CLASS__, 'ajax_get_pts_limits' ) );
		add_action( 'wp_ajax_smp_apply_pts_discount', array( __CLASS__, 'ajax_apply_pts_discount' ) );
		add_action( 'wp_ajax_smp_clear_pts_discount', array( __CLASS__, 'ajax_clear_pts_discount' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'cart_calculate_fees' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'checkout_order_processed' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 10, 3 );
	}

	/**
	 * Render the points redemption panel via the woocommerce_before_checkout_form hook.
	 *
	 * @return void
	 */
	public static function render_panel_early() {
		self::render_panel( false );
	}

	/**
	 * Render the points panel via wp_footer as a JS-injected fallback.
	 *
	 * Used when the active theme does not fire woocommerce_before_checkout_form.
	 *
	 * @return void
	 */
	public static function render_panel_late() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		self::render_panel( true );
	}

	/**
	 * Output the points redemption panel HTML and inline runtime script.
	 *
	 * The panel is rendered at most once per request (guarded by $rendered).
	 * When $inject_via_js is true the panel is hidden by default and the JS
	 * layer moves it inside the checkout form after page load.
	 *
	 * @param bool $inject_via_js Whether to hide the panel for JS-side injection.
	 * @return void
	 */
	public static function render_panel( $inject_via_js = false ) {
		if ( self::$rendered ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$user_id  = get_current_user_id();
		$user_pts = SMP_Points_Engine::get_user_points( $user_id );
		if ( $user_pts <= 0 ) {
			return;
		}

		$cfg         = SMP_Points_Engine::get_points_config();
		$cart_total  = SMP_Assets::get_cart_total();
		$max_disc    = SMP_Points_Engine::calc_max_discount( $user_pts, $cart_total );
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
		// Runtime values for checkout panel — supplement wp_localize_script data
		?>
		<script>
		window.SMP = window.SMP || {};
		window.SMP.ajaxUrl    = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
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
		self::$rendered = true;
	}

	/**
	 * AJAX handler: return the current max discount and max points for the user's cart.
	 *
	 * @return void Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public static function ajax_get_pts_limits() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array(), 401 );
		}
		check_ajax_referer( 'smp_pts_nonce', 'security' );
		$user_id    = get_current_user_id();
		$user_pts   = SMP_Points_Engine::get_user_points( $user_id );
		$cfg        = SMP_Points_Engine::get_points_config();
		$cart_total = SMP_Assets::get_cart_total();
		$max_disc   = SMP_Points_Engine::calc_max_discount( $user_pts, $cart_total );
		$max_pts    = min( $user_pts, (int) ceil( $max_disc * $cfg['redeem_rate'] ) );
		wp_send_json_success(
			array(
				'max_disc' => round( $max_disc, 2 ),
				'max_pts'  => $max_pts,
			)
		);
	}

	/**
	 * AJAX handler: store the chosen points amount in the WooCommerce session.
	 *
	 * Validates against the user's live balance and the configured maximum
	 * discount before committing the value to the session.
	 *
	 * @return void Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public static function ajax_apply_pts_discount() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'msg' => 'Authentication required.' ), 401 );
		}
		check_ajax_referer( 'smp_pts_nonce', 'security' );
		$rate_key      = 'smp_apply_rate_' . get_current_user_id();
		$rate_attempts = (int) get_transient( $rate_key );
		if ( $rate_attempts >= 10 ) {
			wp_send_json_error( array( 'msg' => 'Zu viele Versuche. Bitte warte 5 Minuten.' ), 429 );
		}
		set_transient( $rate_key, $rate_attempts + 1, 5 * MINUTE_IN_SECONDS );
		$pts = intval( $_POST['points'] ?? 0 );
		if ( $pts <= 0 ) {
			wp_send_json_error( array( 'msg' => 'Ungültige Punktzahl.' ), 400 );
		}
		$user_id  = get_current_user_id();
		$cfg      = SMP_Points_Engine::get_points_config();
		$user_pts = SMP_Points_Engine::get_user_points_from_db( $user_id );
		if ( $pts < $cfg['min_redeem'] ) {
			wp_send_json_error( array( 'msg' => 'Mindestens ' . intval( $cfg['min_redeem'] ) . ' Punkte erforderlich.' ) );
		}
		if ( $pts > $user_pts ) {
			wp_send_json_error( array( 'msg' => 'Nicht genügend Punkte (Guthaben: ' . intval( $user_pts ) . ').' ) );
		}
		$cart_total = WC()->cart ? (float) WC()->cart->get_subtotal() : 0;
		$max_disc   = SMP_Points_Engine::calc_max_discount( $user_pts, $cart_total );
		$discount   = min( round( $pts / max( 1, (int) $cfg['redeem_rate'] ), 2 ), $max_disc );
		if ( $discount <= 0 ) {
			wp_send_json_error( array( 'msg' => 'Punkteeinlösung für diese Bestellung nicht verfügbar.' ) );
		}
		WC()->session->set( 'smp_pts_discount', $discount );
		WC()->session->set( 'smp_pts_used', $pts );
		$sym_decoded = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		wp_send_json_success(
			array(
				'msg' => '✅ ' . $sym_decoded . number_format_i18n( $discount, 2 ) . ' Rabatt eingelöst (' . $pts . ' Punkte verwendet)',
			)
		);
	}

	/**
	 * AJAX handler: remove any pending points discount from the WooCommerce session.
	 *
	 * @return void Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public static function ajax_clear_pts_discount() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'msg' => 'Authentication required.' ), 401 );
		}
		check_ajax_referer( 'smp_pts_nonce', 'security' );
		WC()->session->set( 'smp_pts_discount', null );
		WC()->session->set( 'smp_pts_used', null );
		wp_send_json_success();
	}

	/**
	 * Apply the points discount as a negative WooCommerce cart fee.
	 *
	 * Hooked to woocommerce_cart_calculate_fees. Re-validates the session values
	 * against the live DB balance before calling WC_Cart::add_fee().
	 *
	 * @return void
	 */
	public static function cart_calculate_fees() {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$discount = (float) WC()->session->get( 'smp_pts_discount' );
		if ( $discount <= 0 ) {
			return;
		}
		$pts_used = (int) WC()->session->get( 'smp_pts_used' );
		$user_id  = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$current_db = SMP_Points_Engine::get_user_points_from_db( $user_id );
		if ( $current_db < $pts_used ) {
			WC()->session->set( 'smp_pts_discount', null );
			WC()->session->set( 'smp_pts_used', null );
			return;
		}
		$cfg        = SMP_Points_Engine::get_points_config();
		$cart_total = WC()->cart ? (float) WC()->cart->get_subtotal() : 0;
		$max_disc   = SMP_Points_Engine::calc_max_discount( $current_db, $cart_total );
		$discount   = min( round( $pts_used / $cfg['redeem_rate'], 2 ), $max_disc );
		if ( $discount <= 0 ) {
			WC()->session->set( 'smp_pts_discount', null );
			WC()->session->set( 'smp_pts_used', null );
			return;
		}
		WC()->session->set( 'smp_pts_discount', $discount );
		WC()->cart->add_fee( 'Points Discount (' . $pts_used . ' pts)', -$discount, false );
	}

	/**
	 * Deduct redeemed points when a checkout order is successfully placed.
	 *
	 * A meta guard prevents double-deduction. Throws a WooCommerce checkout
	 * exception on failure, which cancels the order and shows an error to the
	 * customer.
	 *
	 * @param int $order_id Newly created WooCommerce order ID.
	 * @return void
	 */
	public static function checkout_order_processed( $order_id ) {
		$pts_used = (int) WC()->session->get( 'smp_pts_used' );
		if ( $pts_used <= 0 ) {
			return;
		}
		$uid   = get_current_user_id();
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			WC()->session->set( 'smp_pts_discount', null );
			WC()->session->set( 'smp_pts_used', null );
			throw new \Exception( __( 'Order could not be retrieved. Please try again or contact support.', 'smp' ) );
		}
		if ( $order->get_meta( '_smp_points_deducted' ) ) {
			WC()->session->set( 'smp_pts_discount', null );
			WC()->session->set( 'smp_pts_used', null );
			return;
		}
		$deducted = SMP_Points_Engine::deduct_user_points( $uid, $pts_used, $order_id );
		if ( ! $deducted ) {
			$order->update_status( 'cancelled', __( 'Points deduction failed: insufficient balance at checkout.', 'smp' ) );
			$order->save();
			WC()->session->set( 'smp_pts_discount', null );
			WC()->session->set( 'smp_pts_used', null );
			throw new \Exception( __( 'Your points balance has changed. Please refresh the page and try again.', 'smp' ) );
		}
		$order->update_meta_data( '_smp_points_deducted', 1 );
		$order->update_meta_data( '_smp_pts_used', $pts_used );
		$order->save();
		$balance_after = SMP_Points_Engine::get_user_points_from_db( $uid );
		SMP_Points_Engine::append_log( $uid, 'deduct', $pts_used, $balance_after, 'Redeemed at checkout — Order #' . $order_id, 'customer' );
		WC()->session->set( 'smp_pts_discount', null );
		WC()->session->set( 'smp_pts_used', null );
	}

	/**
	 * Refund redeemed points when an order is cancelled or refunded.
	 *
	 * Hooked to woocommerce_order_status_changed. Delegates to
	 * SMP_Points_Engine::refund_points_for_order(), which is idempotent.
	 *
	 * @param int    $order_id   WooCommerce order ID.
	 * @param string $old_status Previous order status slug (without wc- prefix).
	 * @param string $new_status New order status slug (without wc- prefix).
	 * @return void
	 */
	public static function order_status_changed( $order_id, $old_status, $new_status ) {
		if ( ! in_array( $new_status, array( 'cancelled', 'refunded' ), true ) ) {
			return;
		}
		$reason = ( 'cancelled' === $new_status )
			? sprintf( 'Points refunded — Order #%d cancelled', $order_id )
			: sprintf( 'Points refunded — Order #%d fully refunded', $order_id );
		SMP_Points_Engine::refund_points_for_order( $order_id, $reason );
	}
}

/*
==========================================================================
	CLASS 7 — SMP_Shortcodes
	[order_lookup] tabbed dashboard and [smp_auth] login/register form.
	========================================================================== */

/**
 * Plugin shortcodes.
 *
 * Registers [order_lookup] — a tabbed order-dashboard and member-card widget —
 * and [smp_auth] — a WooCommerce login/register form with a logged-in state.
 */
class SMP_Shortcodes {

	/**
	 * Register plugin shortcodes with WordPress.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'order_lookup', array( __CLASS__, 'order_lookup' ) );
		add_shortcode( 'smp_auth', array( __CLASS__, 'smp_auth' ) );
	}

	/**
	 * Render the [order_lookup] tabbed order dashboard shortcode.
	 *
	 * Displays a member ID card, points balance banner, and a list of recent
	 * orders with an AJAX-powered details tab. Requires the user to be logged in.
	 *
	 * @return string HTML output for the shortcode.
	 */
	public static function order_lookup() {
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in to view your dashboard.</p>';
		}

		$is_admin = current_user_can( 'manage_options' );
		$orders   = wc_get_orders(
			array(
				'limit'    => 20,
				'customer' => $is_admin ? null : get_current_user_id(),
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);

		$user_pts = SMP_Points_Engine::get_user_points();
		$cfg      = SMP_Points_Engine::get_points_config();

		ob_start();
		?>
		<div class="smp-tab-container">

			<div class="smp-tab-nav">
				<div class="smp-tab-link active" data-tab="tab-list"    onclick="smpSwitchTab('tab-list')">📦 My Orders</div>
				<div class="smp-tab-link"         data-tab="tab-details" onclick="smpSwitchTab('tab-details')">📋 Order Details</div>
			</div>

			<div id="tab-list" class="smp-tab-content active">

				<?php $member_id = SMP_Member_ID::get( get_current_user_id() ); ?>
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
							&nbsp;|&nbsp; Worth up to <strong><?php echo esc_html( get_woocommerce_currency_symbol() ); ?><?php echo esc_html( number_format( $user_pts / $cfg['redeem_rate'], 2 ) ); ?></strong>
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
						<?php if ( empty( $orders ) ) : ?>
							<tr><td colspan="4" style="padding:50px;text-align:center;color:#aaa;">No orders found.</td></tr>
							<?php
						else :
							foreach ( $orders as $order ) :
								$s = $order->get_status();
								?>
							<tr style="border-bottom:1px solid #eee;">
								<td style="padding:16px;">
									<strong>#<?php echo esc_html( $order->get_id() ); ?></strong>
									<?php
									$pts    = (int) $order->get_meta( '_smp_pts_used' );
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
								<td>
															<?php
																$d = $order->get_date_created();
																echo $d ? esc_html( $d->date( 'Y-m-d' ) ) : '—';
															?>
								</td>
								<td><span class="smp-badge badge-<?php echo esc_attr( $s ); ?>"><?php echo esc_html( wc_get_order_status_name( $s ) ); ?></span></td>
								<td style="text-align:right;padding-right:15px;">
									<button onclick="smpViewDetails(<?php echo intval( $order->get_id() ); ?>)"
										style="background:#2271b1;color:#fff;border:none;padding:8px 15px;border-radius:6px;cursor:pointer;font-weight:700;font-size:12px;">
										View Details
									</button>
								</td>
							</tr>
													<?php
						endforeach;
endif;
						?>
					</tbody>
				</table>
			</div>

			<div id="tab-details" class="smp-tab-content">
				<div style="padding:80px;text-align:center;color:#ccc;font-size:15px;">
					📋 Select an order from the list to view its details.
				</div>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the [smp_auth] login/register form shortcode.
	 *
	 * Shows a "you are logged in" confirmation card for authenticated users,
	 * or delegates to the WooCommerce login form template for guests.
	 *
	 * @return string HTML output for the shortcode.
	 */
	public static function smp_auth() {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$url  = function_exists( 'wc_get_account_endpoint_url' )
						? wc_get_account_endpoint_url( 'dashboard' )
						: get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
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
		if ( ! function_exists( 'wc_get_template' ) ) {
			return '<p>' . esc_html__( 'WooCommerce is required for this form.', 'smp' ) . '</p>';
		}
		ob_start();
		wc_get_template( 'myaccount/form-login.php' );
		return ob_get_clean();
	}
}

/*
==========================================================================
	CLASS 8 — SMP_Admin_Pages
	Admin menu, CustomerAnalytics page, and Membership Settings page.
	========================================================================== */

/**
 * Admin menu, CustomerAnalytics page, and Membership Settings page.
 *
 * Registers the top-level "CustomerAnalytics" menu and two submenu pages
 * (customer list and membership settings), handles settings form submissions
 * with nonce verification, and renames the WooCommerce fee line label.
 */
class SMP_Admin_Pages {

	/**
	 * Register all WordPress hooks for the admin pages and settings.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_pts_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_member_num_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_topup_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'reset_pts_settings' ) );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'rename_fees_label' ), 10, 3 );
	}

	/**
	 * Register the CustomerAnalytics top-level menu and its submenu pages.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page( 'CustomerAnalytics', '📊 CustomerAnalytics', 'manage_options', 'smp-customer-analytics', array( __CLASS__, 'render_customers_page' ), 'dashicons-chart-bar', 71 );
		add_submenu_page( 'smp-customer-analytics', 'Customers', '👥 Customers', 'manage_options', 'smp-customer-analytics', array( __CLASS__, 'render_customers_page' ) );
		add_submenu_page( 'smp-customer-analytics', 'Membership Settings', '⭐ Memebership', 'manage_options', 'smp-points-settings', array( __CLASS__, 'render_points_settings_page' ) );
	}

	/**
	 * Handle the points configuration settings form submission.
	 *
	 * Verifies nonce and capability before sanitising and saving the config.
	 * Redirects back to the settings page on success.
	 *
	 * @return void
	 */
	public static function save_pts_settings() {
		if ( ! isset( $_POST['smp_pts_save'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_pts_settings_save', 'smp_pts_nonce_field' ) ) {
			return;
		}
		$config = array(
			'redeem_rate'      => max( 1, intval( $_POST['smp_redeem_rate'] ?? 100 ) ),
			'earn_rate'        => max( 0, floatval( $_POST['smp_earn_rate'] ?? 1 ) ),
			'min_redeem'       => max( 0, intval( $_POST['smp_min_redeem'] ?? 100 ) ),
			'max_discount_pct' => min( 100, max( 1, intval( $_POST['smp_max_discount_pct'] ?? 50 ) ) ),
			'label'            => sanitize_text_field( $_POST['smp_label'] ?? 'Points' ),
		);
		update_option( 'smp_pts_config', $config );
		wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1' ) );
		exit;
	}

	/**
	 * Handle the membership-number configuration form submission.
	 *
	 * When the prefix changes the sequential counter is reset to zero so that
	 * new member IDs start from 1 under the new prefix.
	 *
	 * @return void
	 */
	public static function save_member_num_settings() {
		if ( ! isset( $_POST['smp_member_num_save'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_member_num_settings_save', 'smp_member_num_nonce_field' ) ) {
			return;
		}
		$new_prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', $_POST['smp_mn_prefix'] ?? 'MBR' ) ) ?: 'MBR';
		$new_prefix = substr( $new_prefix, 0, 5 );
		$num_length = max( 1, min( 10, intval( $_POST['smp_mn_num_length'] ?? 6 ) ) );
		$old_cfg    = get_option( 'smp_member_num_config', array() );
		$old_prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', $old_cfg['prefix'] ?? '' ) );
		if ( $new_prefix !== $old_prefix ) {
			update_option( 'smp_member_num_counter', 0, 'no' );
		}
		update_option(
			'smp_member_num_config',
			array(
				'prefix'     => $new_prefix,
				'num_length' => $num_length,
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1&smp_tab=member_num' ) );
		exit;
	}

	/**
	 * Handle the automatic top-up configuration form submission.
	 *
	 * Saves the config and calls SMP_Automation::sync_schedule() to reschedule
	 * or cancel the Action Scheduler job.
	 *
	 * @return void
	 */
	public static function save_topup_settings() {
		if ( ! isset( $_POST['smp_topup_save'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_topup_settings_save', 'smp_topup_nonce_field' ) ) {
			return;
		}
		$roles_raw = is_array( $_POST['smp_topup_roles'] ?? null ) ? $_POST['smp_topup_roles'] : array();
		$roles     = array();
		foreach ( $roles_raw as $slug => $pts ) {
			$slug = sanitize_key( $slug );
			if ( '' !== $slug ) {
				$roles[ $slug ] = max( 0, intval( $pts ) );
			}
		}
		$config = array(
			'enabled'     => isset( $_POST['smp_topup_enabled'] ) ? 1 : 0,
			'cycle_weeks' => max( 1, intval( $_POST['smp_topup_cycle_weeks'] ?? 4 ) ),
			'roles'       => $roles,
		);
		update_option( 'smp_topup_config', $config );
		SMP_Automation::sync_schedule( $config );
		wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1&smp_tab=topup' ) );
		exit;
	}

	/**
	 * Handle the "reset to defaults" request for points configuration.
	 *
	 * Triggered by a GET request with smp_reset=1 on the settings page.
	 * Verifies the nonce before deleting the option.
	 *
	 * @return void
	 */
	public static function reset_pts_settings() {
		if ( ! isset( $_GET['smp_reset'] ) || ! isset( $_GET['page'] ) || 'smp-points-settings' !== $_GET['page'] || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'smp_pts_reset' ) ) {
			return;
		}
		delete_option( 'smp_pts_config' );
		wp_safe_redirect( admin_url( 'admin.php?page=smp-points-settings&smp_saved=1' ) );
		exit;
	}

	/**
	 * Rename the WooCommerce "Fees" line item label to "Bonus Discount:" on orders.
	 *
	 * @param array    $total_rows  Associative array of order total row data.
	 * @param WC_Order $order       The WooCommerce order object.
	 * @param string   $tax_display Tax display mode (unused).
	 * @return array Modified total rows.
	 */
	public static function rename_fees_label( $total_rows, $order, $tax_display ) {
		if ( isset( $total_rows['fees'] ) ) {
			$total_rows['fees']['label'] = __( 'Bonus Discount:', 'woocommerce' );
		}
		return $total_rows;
	}

	/**
	 * Render the CustomerAnalytics admin page.
	 *
	 * Displays a searchable, paginated table of all WordPress users enriched
	 * with WooCommerce order statistics. Access restricted to administrators.
	 *
	 * @return void
	 */
	public static function render_customers_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		$admin_id     = get_current_user_id();
		$throttle_key = 'smp_analytics_view_' . $admin_id;
		if ( ! get_transient( $throttle_key ) ) {
			$admin_user = get_userdata( $admin_id );
			$raw_ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
			$client_ip  = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : 'invalid';
			error_log(
				sprintf(
					'SMP_ANALYTICS_VIEW: admin_id=%d login=%s ip=%s site=%s',
					$admin_id,
					$admin_user ? $admin_user->user_login : 'unknown',
					$client_ip,
					home_url()
				)
			);
			set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
		}

		$per_page     = 20;
		$current_page = max( 1, intval( $_GET['paged'] ?? 1 ) );
		$search       = sanitize_text_field( $_GET['smp_search'] ?? '' );
		$offset       = ( $current_page - 1 ) * $per_page;

		$order_dir       = ( strtoupper( $_GET['smp_order'] ?? 'DESC' ) === 'ASC' ) ? 'ASC' : 'DESC';
		$allowed_orderby = array( 'registered', 'display_name', 'user_email' );
		$raw_orderby     = sanitize_key( $_GET['smp_orderby'] ?? 'registered' );
		$safe_orderby    = in_array( $raw_orderby, $allowed_orderby, true ) ? $raw_orderby : 'registered';

		$user_query_args = array(
			'number'  => $per_page,
			'offset'  => $offset,
			'orderby' => $safe_orderby,
			'order'   => $order_dir,
			'fields'  => 'all',
		);
		if ( '' !== $search ) {
			$user_query_args['search']         = '*' . $search . '*';
			$user_query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' ); }
		$user_query  = new WP_User_Query( $user_query_args );
		$wp_users    = $user_query->get_results();
		$total_users = $user_query->get_total();
		$total_pages = max( 1, (int) ceil( $total_users / $per_page ) );

		$wc_data = array();
		if ( class_exists( 'WooCommerce' ) && ! empty( $wp_users ) ) {
			$user_ids = wp_list_pluck( $wp_users, 'ID' );
			foreach ( $user_ids as $uid ) {
				$orders      = wc_get_orders(
					array(
						'customer' => $uid,
						'limit'    => -1,
						'status'   => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
						'return'   => 'objects',
					)
				);
				$count       = count( $orders );
				$total_spent = 0.0;
				$last_order  = null;
				foreach ( $orders as $o ) {
					$total_spent += floatval( $o->get_total() );
					$date         = $o->get_date_created();
					if ( $date && ( $last_order === null || $date > $last_order ) ) {
						$last_order = $date;
					}
				}
				$wc_data[ $uid ] = array(
					'order_count'  => $count,
					'total_spent'  => $total_spent,
					'aov'          => $count > 0 ? $total_spent / $count : null,
					'last_order'   => $last_order ? $last_order->date_i18n( 'd.m.Y' ) : '—',
					'customer_url' => admin_url( 'user-edit.php?user_id=' . $uid ),
				);
			}
		}

		$sym      = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$base_url = admin_url( 'admin.php?page=smp-customer-analytics' );
		?>
		<div class="wrap" id="smp-customers-wrap">
		<h1>📊 CustomerAnalytics <span style="font-size:14px;color:#9ca3af;font-weight:400;">– Customers</span></h1>
		<p style="color:#6b7280;margin-top:0;font-size:13px;">Unified view of WordPress user accounts and WooCommerce customer data.</p>

		<?php
		$total_wp_users     = count_users();
		$total_wc_customers = 0;
		if ( class_exists( 'WooCommerce' ) ) {
			$all_cust           = get_users(
				array(
					'role'   => 'customer',
					'fields' => 'ID',
					'number' => -1,
				)
			);
			$total_wc_customers = count( $all_cust );
		}
		?>
		<div class="smp-ca-stat-row">
			<div class="smp-ca-stat"><div class="label">Total WP Users</div><div class="value"><?php echo esc_html( number_format( $total_wp_users['total_users'] ) ); ?></div></div>
			<div class="smp-ca-stat"><div class="label">WC Customers (role)</div><div class="value"><?php echo esc_html( number_format( $total_wc_customers ) ); ?></div></div>
			<div class="smp-ca-stat"><div class="label">Showing (page <?php echo intval( $current_page ); ?>/<?php echo intval( $total_pages ); ?>)</div><div class="value"><?php echo esc_html( number_format( $total_users ) ); ?></div></div>
		</div>

		<form method="get" class="smp-ca-search">
			<input type="hidden" name="page" value="smp-customer-analytics">
			<input type="search" name="smp_search" placeholder="Search by name / email / login…" value="<?php echo esc_attr( $search ); ?>">
			<button type="submit" class="button">🔍 Search</button>
			<?php
			if ( '' !== $search ) :
				?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="button">✕ Clear</a><?php endif; ?>
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
				<?php
				foreach ( $wp_users as $i => $user ) :
					$uid          = $user->ID;
					$roles        = implode( ', ', (array) $user->roles );
					$registered   = mysql2date( 'd.m.Y', $user->user_registered );
					$member_id    = get_user_meta( $uid, 'smp_member_id', true );
					$points       = SMP_Points_Engine::get_user_points( $uid );
					$wc           = $wc_data[ $uid ] ?? array(
						'order_count' => '—',
						'total_spent' => null,
						'aov'         => null,
						'last_order'  => '—',
					);
					$avatar       = get_avatar_url( $uid, array( 'size' => 32 ) );
					$edit_url     = esc_url( admin_url( 'user-edit.php?user_id=' . $uid ) );
					$row_num      = $offset + $i + 1;
					$full_email   = $user->user_email;
					$parts        = explode( '@', $full_email, 2 );
					$local        = $parts[0] ?? '';
					$domain       = isset( $parts[1] ) ? '@' . $parts[1] : '';
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
			<?php
			for ( $p = 1; $p <= $total_pages; $p++ ) :
				$page_url = esc_url(
					add_query_arg(
						array(
							'page'       => 'smp-customer-analytics',
							'paged'      => $p,
							'smp_search' => $search,
						),
						admin_url( 'admin.php' )
					)
				);
				if ( $current_page === $p ) :
					?>
				<span class="current"><?php echo intval( $p ); ?></span>
			<?php else : ?>
				<a href="<?php echo esc_url( $page_url ); ?>"><?php echo intval( $p ); ?></a>
				<?php
			endif;
endfor;
			?>
		</div>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Membership Settings admin page.
	 *
	 * Provides tabbed configuration panels for points rules, automatic top-up
	 * schedules, and the membership-number prefix and length.
	 * Access restricted to administrators.
	 *
	 * @return void
	 */
	public static function render_points_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		$cfg_defaults = array(
			'earn_rate'        => 1,
			'redeem_rate'      => 100,
			'max_discount_pct' => 50,
			'min_redeem'       => 100,
			'label'            => 'Points',
		);
		$saved_config = get_option( 'smp_pts_config', array() );
		$cfg          = wp_parse_args( $saved_config, $cfg_defaults );
		$sym          = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$has_custom   = ! empty( $saved_config );
		$has_wcpr     = class_exists( 'WC_Points_Rewards' ) && ! empty( get_option( 'wc_points_rewards' ) );
		$saved        = isset( $_GET['smp_saved'] );
		$active_tab   = sanitize_key( $_GET['smp_tab'] ?? 'config' );

		if ( $has_custom ) {
			$source_html = '<span style="color:#16a34a;font-weight:700;">✓ Custom settings (this page)</span>';
		} elseif ( $has_wcpr ) {
			$source_html = '<span style="color:#d97706;font-weight:700;">⚠ WC Points &amp; Rewards plugin</span>';
		} else {
			$source_html = '<span style="color:#6b7280;">System defaults</span>';
		}

		$reset_url = wp_nonce_url( admin_url( 'admin.php?page=smp-points-settings&smp_reset=1' ), 'smp_pts_reset' );

		$topup_defaults = array(
			'enabled'     => 0,
			'cycle_weeks' => 4,
			'roles'       => array(
				'customer'      => 100,
				'vip_customer'  => 500,
				'administrator' => 1000,
			),
		);
		$topup_saved    = get_option( 'smp_topup_config', array() );
		$topup          = wp_parse_args( $topup_saved, $topup_defaults );
		if ( ! is_array( $topup['roles'] ) ) {
			$topup['roles'] = $topup_defaults['roles'];
		}

		$all_wp_roles = wp_roles()->roles;
		$next_run_ts  = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'smp_topup_dispatch_hook' ) : false;

		$mn_defaults = array(
			'prefix'     => 'MBR',
			'num_length' => 6,
		);
		$mn_saved    = get_option( 'smp_member_num_config', array() );
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
			<button type="button" class="smp-tab-btn <?php echo 'config' === $active_tab ? 'active' : ''; ?>" data-tab="smp-pane-config">💳 Credit Configuration</button>
			<button type="button" class="smp-tab-btn <?php echo 'topup' === $active_tab ? 'active' : ''; ?>" data-tab="smp-pane-topup">🔄 Credit Top Up</button>
			<button type="button" class="smp-tab-btn <?php echo 'member_num' === $active_tab ? 'active' : ''; ?>" data-tab="smp-pane-member-num">🪪 Membership Number</button>
		</div>

		<!-- TAB 1 — Credit Configuration -->
		<div id="smp-pane-config" class="smp-tab-pane <?php echo 'config' === $active_tab ? 'active' : ''; ?>">
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
		<div id="smp-pane-topup" class="smp-tab-pane <?php echo 'topup' === $active_tab ? 'active' : ''; ?>">
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
							<?php
							foreach ( $all_wp_roles as $slug => $role_data ) :
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
						<div class="smp-schedule-banner ok">✓ Next scheduled run: <strong><?php echo esc_html( wp_date( 'l, d M Y \a\t H:i', $next_run_ts ) ); ?></strong> &nbsp;·&nbsp; repeats every <?php echo intval( $topup['cycle_weeks'] ); ?> week<?php echo 1 !== (int) $topup['cycle_weeks'] ? 's' : ''; ?> on Monday</div>
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
		<div id="smp-pane-member-num" class="smp-tab-pane <?php echo 'member_num' === $active_tab ? 'active' : ''; ?>">
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
}

/*
==========================================================================
	CLASS 9 — SMP_Automation
	Action Scheduler top-up tasks (dispatch, execute, AJAX run-now).
	========================================================================== */

/**
 * Action Scheduler-based automatic points top-up engine.
 *
 * Dispatches async per-user recharge jobs on a configurable weekly cycle,
 * executes the recharge logic (adds points by role, logs, and e-mails the
 * user), and provides an admin AJAX endpoint for an immediate manual run.
 */
class SMP_Automation {

	/**
	 * Cached top-up configuration array.
	 *
	 * @var array|null
	 */
	private static $config_cache = null;

	/**
	 * Register Action Scheduler and AJAX hooks for the top-up engine.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'smp_topup_dispatch_hook', array( __CLASS__, 'dispatch_customer_recharges' ) );
		add_action( 'smp_monthly_main_dispatch_hook', array( __CLASS__, 'dispatch_customer_recharges' ) );
		add_action( 'smp_as_do_single_recharge', array( __CLASS__, 'execute_recharge_logic' ) );
		add_action( 'wp_ajax_smp_topup_run_now', array( __CLASS__, 'ajax_topup_run_now' ) );
		add_filter(
			'action_scheduler_retention_period',
			static function () {
				return DAY_IN_SECONDS * 30;
			}
		);
	}

	/**
	 * Return the validated top-up configuration with in-memory caching.
	 *
	 * @return array{enabled: int, cycle_weeks: int, roles: array<string, int>}
	 */
	public static function get_config() {
		if ( null !== self::$config_cache ) {
			return self::$config_cache;
		}
		$defaults           = array(
			'enabled'     => 0,
			'cycle_weeks' => 4,
			'roles'       => SMP_RECHARGE_ROLES,
		);
		$saved              = get_option( 'smp_topup_config', array() );
		self::$config_cache = wp_parse_args( $saved, $defaults );
		if ( ! is_array( self::$config_cache['roles'] ) ) {
			self::$config_cache['roles'] = SMP_RECHARGE_ROLES;
		}
		return self::$config_cache;
	}

	/**
	 * Calculate the Unix timestamp for next Monday at 00:05:00 (site time).
	 *
	 * @return int Unix timestamp.
	 */
	public static function next_run_timestamp() {
		return (int) strtotime( 'next monday 00:05:00', current_time( 'timestamp' ) );
	}

	/**
	 * Reschedule (or cancel) the top-up Action Scheduler job to match the current config.
	 *
	 * Unschedules all existing smp_topup_dispatch_hook actions, then re-schedules
	 * one for the next Monday if the top-up is enabled.
	 *
	 * @param array|null $config Configuration array; defaults to self::get_config().
	 * @return void
	 */
	public static function sync_schedule( $config = null ) {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		if ( $config === null ) {
			$config = self::get_config();
		}
		as_unschedule_all_actions( 'smp_topup_dispatch_hook' );
		as_unschedule_all_actions( 'smp_monthly_main_dispatch_hook' );
		if ( empty( $config['enabled'] ) ) {
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( self::next_run_timestamp(), 'smp_topup_dispatch_hook' );
		}
	}

	/**
	 * Schedule the top-up Action Scheduler job on plugin activation.
	 *
	 * A no-op when the top-up is disabled or a job is already queued.
	 *
	 * @return void
	 */
	public static function on_activation() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$config = self::get_config();
		if ( ! empty( $config['enabled'] ) && false === as_next_scheduled_action( 'smp_topup_dispatch_hook' ) ) {
			as_schedule_single_action( self::next_run_timestamp(), 'smp_topup_dispatch_hook' );
		}
	}

	/**
	 * Unschedule all top-up Action Scheduler jobs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'smp_topup_dispatch_hook' );
			as_unschedule_all_actions( 'smp_monthly_main_dispatch_hook' );
			as_unschedule_all_actions( 'smp_as_do_single_recharge' );
		}
	}

	/**
	 * Dispatch one async recharge job per qualifying user via Action Scheduler.
	 *
	 * Called by the smp_topup_dispatch_hook scheduled action. After dispatching,
	 * re-schedules the next run based on the configured cycle length.
	 *
	 * @return void
	 */
	public static function dispatch_customer_recharges() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$config = self::get_config();
		if ( empty( $config['enabled'] ) ) {
			return;
		}
		$role_pts         = array_filter( (array) $config['roles'], fn( $p ) => (int) $p > 0 );
		$qualifying_roles = array_keys( $role_pts );
		if ( empty( $qualifying_roles ) ) {
			return;
		}
		$user_ids = get_users(
			array(
				'role__in' => $qualifying_roles,
				'fields'   => 'ID',
				'number'   => -1,
			)
		);
		foreach ( $user_ids as $user_id ) {
			as_enqueue_async_action( 'smp_as_do_single_recharge', array( 'user_id' => (int) $user_id ), 'smp_recharge_group' );
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$monday_base = (int) strtotime( 'this monday 00:00:00', current_time( 'timestamp' ) );
			$cycle_secs  = max( 1, (int) $config['cycle_weeks'] ) * WEEK_IN_SECONDS;
			as_schedule_single_action( $monday_base + $cycle_secs + 5 * MINUTE_IN_SECONDS, 'smp_topup_dispatch_hook' );
		}
	}

	/**
	 * Execute the top-up recharge for a single user.
	 *
	 * Determines the highest points grant across the user's roles, adds the
	 * points, appends a log entry, and sends a notification e-mail.
	 *
	 * @param int $user_id WordPress user ID to recharge.
	 * @return void
	 */
	public static function execute_recharge_logic( $user_id ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$config        = self::get_config();
		$role_pts      = (array) $config['roles'];
		$points_to_add = 0;
		foreach ( $role_pts as $role => $pts ) {
			if ( (int) $pts > 0 && in_array( $role, (array) $user->roles, true ) ) {
				$points_to_add = max( $points_to_add, (int) $pts );
			}
		}
		if ( $points_to_add <= 0 ) {
			return;
		}
		SMP_Points_Engine::add_user_points( $user_id, $points_to_add, 'Auto top-up' );
		$new_total = SMP_Points_Engine::get_user_points_from_db( $user_id );
		error_log(
			sprintf(
				'SMP_TOPUP: user_id=%d email=%s role_match=%s added=%d new_balance=%d site=%s',
				$user_id,
				$user->user_email,
				implode( ',', array_intersect( (array) $user->roles, array_keys( $role_pts ) ) ),
				$points_to_add,
				$new_total,
				home_url()
			)
		);
		$cycle_weeks = max( 1, (int) $config['cycle_weeks'] );
		$cycle_label = 1 === $cycle_weeks ? 'weekly' : 'every ' . $cycle_weeks . ' weeks';
		SMP_Points_Engine::append_log( $user_id, 'add', $points_to_add, $new_total, 'Auto top-up (' . $cycle_label . ')', 'system' );
		$site_name = get_bloginfo( 'name' );
		$pts_cfg   = SMP_Points_Engine::get_points_config();
		$label     = $pts_cfg['label'] ?? 'Punkte';
		$subject   = sprintf( '[%s] Ihre automatische %s-Gutschrift', $site_name, $label );
		$message   = sprintf( "Hallo %s,\n\n", $user->display_name );
		$message  .= sprintf( "wir haben Ihrem Konto %d %s gutgeschrieben (%s).\n", $points_to_add, $label, $cycle_label );
		$message  .= sprintf( "Ihr aktuelles Guthaben beträgt: %s %s.\n\nViel Spaß beim Einkaufen!\n– Das %s-Team\n", number_format( $new_total, 0, ',', '.' ), $label, $site_name );
		wp_mail( $user->user_email, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * AJAX handler: trigger an immediate top-up run for all qualifying users.
	 *
	 * Uses Action Scheduler async jobs when available; falls back to synchronous
	 * execution. Restricted to administrators.
	 *
	 * @return void Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public static function ajax_topup_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'msg' => 'Permission denied.' ) );
		}
		if ( ! check_ajax_referer( 'smp_topup_run_now', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => 'Security check failed.' ) );
		}
		$config           = self::get_config();
		$role_pts         = array_filter( (array) $config['roles'], fn( $p ) => (int) $p > 0 );
		$qualifying_roles = array_keys( $role_pts );
		if ( empty( $qualifying_roles ) ) {
			wp_send_json_error( array( 'msg' => 'No roles configured with points > 0.' ) );
		}
		$user_ids = get_users(
			array(
				'role__in' => $qualifying_roles,
				'fields'   => 'ID',
				'number'   => -1,
			)
		);
		$count    = 0;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			foreach ( $user_ids as $user_id ) {
				as_enqueue_async_action( 'smp_as_do_single_recharge', array( 'user_id' => (int) $user_id ), 'smp_recharge_group' );
				++$count; }
			$msg = sprintf( 'Dispatched %d async top-up job%s via Action Scheduler.', $count, 1 !== $count ? 's' : '' );
		} else {
			foreach ( $user_ids as $user_id ) {
				self::execute_recharge_logic( (int) $user_id );
				++$count; }
			$msg = sprintf( 'Processed %d user%s synchronously.', $count, 1 !== $count ? 's' : '' );
		}
		wp_send_json_success(
			array(
				'msg'   => $msg,
				'count' => $count,
			)
		);
	}
}

/*
==========================================================================
	CLASS 10 — SMP_Login_Redirect
	Redirect wp-login.php to the WooCommerce My Account page.
	========================================================================== */

/**
 * Redirect wp-login.php to the WooCommerce My Account page.
 *
 * Intercepts requests to wp-login.php and transparently redirects visitors
 * to the WooCommerce My Account page, while still allowing logout,
 * lost-password, and other special actions to pass through.
 */
class SMP_Login_Redirect {

	/**
	 * Register hooks for the wp-login.php redirect and login/register URL filters.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'redirect_wp_login' ) );
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ), 100, 2 );
		add_filter( 'register_url', array( __CLASS__, 'filter_register_url' ), 100 );
	}

	/**
	 * Redirect non-admin wp-login.php requests to the WooCommerce My Account page.
	 *
	 * Special actions (logout, lostpassword, etc.) are allowed through unchanged.
	 *
	 * @return void
	 */
	public static function redirect_wp_login() {
		global $pagenow;
		$is_login_page = ( 'wp-login.php' === $pagenow )
			|| ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'wp-login.php' ) !== false );
		if ( ! $is_login_page ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}
		$action          = sanitize_key( $_GET['action'] ?? '' );
		$allowed_actions = array( 'logout', 'lostpassword', 'rp', 'resetpass', 'confirm_admin_email', 'postpass' );
		if ( in_array( $action, $allowed_actions, true ) ) {
			return;
		}
		$my_account_url = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
		if ( ! $my_account_url ) {
			return;
		}
		wp_safe_redirect( esc_url_raw( $my_account_url ) );
		exit;
	}

	/**
	 * Replace the WordPress login URL with the WooCommerce My Account URL.
	 *
	 * Appends a validated redirect parameter when present to preserve the
	 * post-login destination. Uses wp_validate_redirect() to prevent open redirects.
	 *
	 * @param string $login_url The original WordPress login URL.
	 * @param string $redirect  Optional redirect destination.
	 * @return string The WooCommerce My Account URL (with optional redirect param).
	 */
	public static function filter_login_url( $login_url, $redirect ) {
		$my_account_url = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
		// Only append redirect if it resolves to a same-site URL to prevent open redirect attacks.
		if ( ! empty( $redirect ) && wp_validate_redirect( $redirect, false ) ) {
			$my_account_url = add_query_arg( 'redirect', urlencode( $redirect ), $my_account_url );
		}
		return $my_account_url;
	}

	/**
	 * Replace the WordPress registration URL with the WooCommerce My Account URL.
	 *
	 * @param string $register_url The original WordPress registration URL.
	 * @return string The WooCommerce My Account page URL.
	 */
	public static function filter_register_url( $register_url ) {
		return get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
	}
}

/*
==========================================================================
	BOOTSTRAP — initialise all classes and bind activation / deactivation hooks
	========================================================================== */

SMP_Member_ID::init();
SMP_Admin_Points_Field::init();
SMP_Assets::init();
SMP_Order_Ajax::init();
SMP_Checkout_Points::init();
SMP_Shortcodes::init();
SMP_Admin_Pages::init();
SMP_Automation::init();
SMP_Login_Redirect::init();

register_activation_hook( __FILE__, array( 'SMP_Automation', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'SMP_Automation', 'on_deactivation' ) );
