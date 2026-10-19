<?php
/**
 * Plugin Name:       Cupcake Collection Time Picker
 * Plugin URI:        https://yourstore.com
 * Description:       Adds a collection date & time picker to WooCommerce block checkout. Enforces pickup-only for cupcake orders, hides the Ship tab, validates time slots, and saves details to the order.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Tested up to:      6.7
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 * Requires PHP:      7.4
 * Author:            Your Store
 * Text Domain:       cupcake-picker
 * License:           GPL v2 or later
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * INSTALLATION:
 *   Option A – As a standalone plugin (recommended):
 *     1. Create folder: /wp-content/plugins/cupcake-collection-picker/
 *     2. Place this file inside as:  cupcake-collection-picker.php
 *     3. Activate via WP Admin → Plugins
 *
 *   Option B – Via Code Snippets plugin:
 *     1. Install & activate the "Code Snippets" plugin
 *     2. Go to Snippets → Add New
 *     3. Set "Run snippet everywhere"
 *     4. Paste the ENTIRE contents of this file (including opening <?php tag)
 *     5. Save & Activate
 *
 * REQUIREMENTS:
 *   • WooCommerce active with Block-based Checkout enabled
 *   • "Local Pickup" shipping method configured in WooCommerce
 *     (WooCommerce → Settings → Shipping → Add shipping zone → Local Pickup)
 *   • Product with ID 513 is your cupcake product
 *     (verify in WooCommerce → Products → edit product → check URL for ID)
 * ─────────────────────────────────────────────────────────────────────────────
 */

defined( 'ABSPATH' ) || exit;

// ─── HPOS & Block Checkout compatibility declarations ─────────────────────────
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables', __FILE__, true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks', __FILE__, true
		);
	}
} );

// ─── Main Plugin Class ────────────────────────────────────────────────────────
if ( ! class_exists( 'Cupcake_Collection_Time_Picker' ) ) :

class Cupcake_Collection_Time_Picker {

	// ── Configuration constants ───────────────────────────────────────────────
	const VERSION      = '1.0.0';
	const PRODUCT_ID   = 513;          // ← Change this to your cupcake product ID
	const META_DATE    = '_collection_date';
	const META_TIME    = '_collection_time';
	const SESSION_DATE = 'cupcake_coll_date';
	const SESSION_TIME = 'cupcake_coll_time';
	const COLOR_GOLD   = '#febf56';
	const COLOR_BROWN  = '#4d312f';
	const NONCE_KEY    = 'cupcake_coll_nonce';

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────
	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Frontend assets
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX – persist selection to WC session as user selects
		add_action( 'wp_ajax_cupcake_save_collection',        [ $this, 'ajax_save_collection' ] );
		add_action( 'wp_ajax_nopriv_cupcake_save_collection', [ $this, 'ajax_save_collection' ] );

		// ── Order saving ──────────────────────────────────────────────────────
		// Block checkout (Store API)
		add_action( 'woocommerce_store_api_checkout_update_order_from_request',
			[ $this, 'save_from_store_api' ], 10, 2 );

		// General "order just created" fallback (covers edge cases)
		add_action( 'woocommerce_checkout_order_created',
			[ $this, 'save_from_session_fallback' ] );

		// Classic checkout fallback
		add_action( 'woocommerce_checkout_update_order_meta',
			[ $this, 'save_from_classic_checkout' ] );

		// ── Display ───────────────────────────────────────────────────────────
		add_action( 'woocommerce_admin_order_data_after_shipping_address',
			[ $this, 'display_in_admin' ] );

		add_action( 'woocommerce_email_after_order_table',
			[ $this, 'display_in_email' ], 10, 4 );

		add_action( 'woocommerce_before_cart_totals',
			[ $this, 'display_in_cart' ] );

		add_action( 'woocommerce_thankyou',
			[ $this, 'display_on_thankyou' ] );

		// Register Store API extension schema (WooCommerce 8.6+)
		add_action( 'woocommerce_blocks_loaded',
			[ $this, 'register_store_api_extension' ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// HELPERS
	// ─────────────────────────────────────────────────────────────────────────

	/** Is cupcake product (ID 513) currently in the cart? */
	public function has_cupcake_in_cart(): bool {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if (
				(int) $item['product_id']  === self::PRODUCT_ID ||
				(int) $item['variation_id'] === self::PRODUCT_ID
			) {
				return true;
			}
		}
		return false;
	}

	/** Is cupcake product in a given WC_Order? */
	private function order_has_cupcake( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === self::PRODUCT_ID ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return formatted collection info for an order, or null if not set.
	 * @param int|\WC_Order $order
	 * @return array{date:string,time:string,date_fmt:string,time_fmt:string}|null
	 */
	private function get_collection_info( $order ): ?array {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return null;
		}
		$date = $order->get_meta( self::META_DATE );
		$time = $order->get_meta( self::META_TIME );
		if ( ! $date && ! $time ) {
			return null;
		}
		return [
			'date'     => $date,
			'time'     => $time,
			'date_fmt' => $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '',
			'time_fmt' => $time ? $this->format_time_slot( $time ) : '',
		];
	}

	/**
	 * Convert stored "HH:00-HH:00" value to human-readable "H:00 AM/PM – H:00 AM/PM".
	 * Examples:  "10:00-11:00" → "10:00 AM – 11:00 AM"
	 *            "13:00-14:00" → "1:00 PM – 2:00 PM"
	 */
	private function format_time_slot( string $value ): string {
		if ( empty( $value ) ) {
			return $value;
		}
		$parts = explode( '-', $value );
		if ( count( $parts ) !== 2 ) {
			return $value;
		}
		$fmt = function ( int $h ): string {
			$suffix  = $h < 12 ? 'AM' : 'PM';
			$display = ( $h === 0 || $h === 24 ) ? 12 : ( $h > 12 ? $h - 12 : $h );
			return $display . ':00 ' . $suffix;
		};
		$start_h = (int) explode( ':', trim( $parts[0] ) )[0];
		$end_h   = (int) explode( ':', trim( $parts[1] ) )[0];
		return $fmt( $start_h ) . ' – ' . $fmt( $end_h );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// STORE API EXTENSION REGISTRATION (WooCommerce 8.6+)
	// ─────────────────────────────────────────────────────────────────────────
	public function register_store_api_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		try {
			woocommerce_store_api_register_endpoint_data( [
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
				'namespace'       => 'cupcake_collection',
				'schema_callback' => function () {
					return [
						'date' => [
							'description' => 'Collection date (YYYY-MM-DD)',
							'type'        => 'string',
							'readonly'    => false,
						],
						'time' => [
							'description' => 'Collection time slot (HH:00-HH:00)',
							'type'        => 'string',
							'readonly'    => false,
						],
					];
				},
				'schema_type'     => ARRAY_A,
			] );
		} catch ( \Exception $e ) {
			// Silently fail on older WC versions – session fallback handles saving
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// ASSETS
	// ─────────────────────────────────────────────────────────────────────────
	public function enqueue_assets(): void {
		if ( ! is_checkout() && ! is_cart() ) {
			return;
		}

		$has_cupcake = $this->has_cupcake_in_cart();

		// Inline CSS (no external file needed)
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );

		// JavaScript config passed to window scope
		$js_config = wp_json_encode( [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE_KEY ),
			'hasCupcake' => $has_cupcake,
			'productId'  => self::PRODUCT_ID,
			'colors'     => [
				'gold'  => self::COLOR_GOLD,
				'brown' => self::COLOR_BROWN,
			],
		] );

		// Register a virtual handle for inline script
		wp_register_script( 'cupcake-collection-picker', false, [ 'jquery' ], self::VERSION, true );
		wp_enqueue_script( 'cupcake-collection-picker' );
		wp_add_inline_script( 'cupcake-collection-picker', "window.CupcakePickerCFG={$js_config};", 'before' );
		wp_add_inline_script( 'cupcake-collection-picker', $this->get_inline_js() );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX – SAVE TO WC SESSION
	// ─────────────────────────────────────────────────────────────────────────
	public function ajax_save_collection(): void {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );

		// Boot WC session if not running
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		$time = sanitize_text_field( wp_unslash( $_POST['time'] ?? '' ) );

		if ( WC()->session ) {
			WC()->session->set( self::SESSION_DATE, $date );
			WC()->session->set( self::SESSION_TIME, $time );
		}

		wp_send_json_success( [ 'date' => $date, 'time' => $time ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// ORDER SAVING
	// ─────────────────────────────────────────────────────────────────────────

	/** Block checkout – Store API hook (primary save path). */
	public function save_from_store_api( \WC_Order $order, \WP_REST_Request $request ): void {
		$date = '';
		$time = '';

		// 1. Try to read from request extensions (injected by our JS fetch interceptor)
		$extensions = $request->get_param( 'extensions' );
		if ( is_array( $extensions ) && ! empty( $extensions['cupcake_collection'] ) ) {
			$ext  = $extensions['cupcake_collection'];
			$date = sanitize_text_field( $ext['date'] ?? '' );
			$time = sanitize_text_field( $ext['time'] ?? '' );
		}

		// 2. Fallback: read from WC session (saved by AJAX as user made selection)
		if ( ( empty( $date ) || empty( $time ) ) && WC()->session ) {
			$date = $date ?: (string) WC()->session->get( self::SESSION_DATE, '' );
			$time = $time ?: (string) WC()->session->get( self::SESSION_TIME, '' );
		}

		// Save to order
		if ( ! empty( $date ) ) {
			$order->update_meta_data( self::META_DATE, $date );
		}
		if ( ! empty( $time ) ) {
			$order->update_meta_data( self::META_TIME, $time );
		}

		// Clear session
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_DATE, '' );
			WC()->session->set( self::SESSION_TIME, '' );
		}

		// Server-side validation for cupcake orders
		if ( $this->order_has_cupcake( $order ) && ( empty( $date ) || empty( $time ) ) ) {
			// Throw a WC-understood exception so block checkout shows the message inline
			throw new \Exception(
				esc_html__( 'Please select a collection date and time before placing your order.', 'cupcake-picker' )
			);
		}
	}

	/** General order-created fallback (catches any path not covered above). */
	public function save_from_session_fallback( \WC_Order $order ): void {
		// Skip if already saved (e.g. store API hook already ran)
		if ( $order->get_meta( self::META_DATE ) ) {
			return;
		}
		if ( ! WC()->session ) {
			return;
		}
		$date = (string) WC()->session->get( self::SESSION_DATE, '' );
		$time = (string) WC()->session->get( self::SESSION_TIME, '' );
		if ( $date ) {
			$order->update_meta_data( self::META_DATE, $date );
		}
		if ( $time ) {
			$order->update_meta_data( self::META_TIME, $time );
		}
		$order->save();
		WC()->session->set( self::SESSION_DATE, '' );
		WC()->session->set( self::SESSION_TIME, '' );
	}

	/** Classic checkout fallback (non-block). */
	public function save_from_classic_checkout( int $order_id ): void {
		$date = sanitize_text_field( wp_unslash( $_POST['cupcake_collection_date'] ?? '' ) );
		$time = sanitize_text_field( wp_unslash( $_POST['cupcake_collection_time'] ?? '' ) );
		if ( $date ) {
			update_post_meta( $order_id, self::META_DATE, $date );
		}
		if ( $time ) {
			update_post_meta( $order_id, self::META_TIME, $time );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// DISPLAY
	// ─────────────────────────────────────────────────────────────────────────

	/** Admin order page – displayed below shipping address. */
	public function display_in_admin( \WC_Order $order ): void {
		$info = $this->get_collection_info( $order );
		if ( ! $info ) {
			return;
		}
		printf(
			'<div style="margin-top:20px;padding:14px 18px;background:#fffaf0;border-left:4px solid %s;border-radius:6px;">
				<h4 style="margin:0 0 10px 0;color:%s;font-size:14px;">🧁 %s</h4>
				<table style="width:100%%;border-collapse:collapse;">
					<tr>
						<td style="padding:3px 0;color:%s;font-weight:600;width:120px;">%s</td>
						<td style="padding:3px 0;">%s</td>
					</tr>
					<tr>
						<td style="padding:3px 0;color:%s;font-weight:600;">%s</td>
						<td style="padding:3px 0;">%s</td>
					</tr>
				</table>
			</div>',
			esc_attr( self::COLOR_GOLD ),
			esc_attr( self::COLOR_BROWN ),
			esc_html__( 'Collection Details', 'cupcake-picker' ),
			esc_attr( self::COLOR_BROWN ),
			esc_html__( 'Collection Date:', 'cupcake-picker' ),
			esc_html( $info['date_fmt'] ),
			esc_attr( self::COLOR_BROWN ),
			esc_html__( 'Collection Time:', 'cupcake-picker' ),
			esc_html( $info['time_fmt'] )
		);
	}

	/** Order confirmation & admin emails. */
	public function display_in_email( \WC_Order $order, bool $sent_to_admin, bool $plain_text, $email ): void {
		$info = $this->get_collection_info( $order );
		if ( ! $info ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n\n========================================\n";
			echo esc_html__( 'COLLECTION DETAILS', 'cupcake-picker' ) . "\n";
			echo esc_html__( 'Date: ', 'cupcake-picker' ) . esc_html( $info['date_fmt'] ) . "\n";
			echo esc_html__( 'Time: ', 'cupcake-picker' ) . esc_html( $info['time_fmt'] ) . "\n";
			echo "========================================\n";
		} else {
			printf(
				'<div style="margin:24px 0;padding:18px 20px;background:#fffaf0;border-left:5px solid %s;border-radius:4px;font-family:Arial,sans-serif;">
					<h2 style="margin:0 0 12px 0;color:%s;font-size:18px;">🧁 %s</h2>
					<table style="width:100%%;border-collapse:collapse;font-size:15px;">
						<tr>
							<td style="padding:5px 0;color:%s;font-weight:700;width:160px;">%s</td>
							<td style="padding:5px 0;color:#333;">%s</td>
						</tr>
						<tr>
							<td style="padding:5px 0;color:%s;font-weight:700;">%s</td>
							<td style="padding:5px 0;color:#333;">%s</td>
						</tr>
					</table>
				</div>',
				esc_attr( self::COLOR_GOLD ),
				esc_attr( self::COLOR_BROWN ),
				esc_html__( 'Your Collection Details', 'cupcake-picker' ),
				esc_attr( self::COLOR_BROWN ),
				esc_html__( 'Collection Date:', 'cupcake-picker' ),
				esc_html( $info['date_fmt'] ),
				esc_attr( self::COLOR_BROWN ),
				esc_html__( 'Collection Time:', 'cupcake-picker' ),
				esc_html( $info['time_fmt'] )
			);
		}
	}

	/** Cart page – shown in cart totals area. */
	public function display_in_cart(): void {
		if ( ! WC()->session ) {
			return;
		}
		$date = (string) WC()->session->get( self::SESSION_DATE, '' );
		$time = (string) WC()->session->get( self::SESSION_TIME, '' );
		if ( ! $date && ! $time ) {
			return;
		}
		$date_fmt = $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '';
		$time_fmt = $this->format_time_slot( $time );
		printf(
			'<tr class="cupcake-cart-collection">
				<th style="color:%s;">🧁 %s</th>
				<td>%s<br><em style="font-size:0.9em;color:#555;">%s</em></td>
			</tr>',
			esc_attr( self::COLOR_BROWN ),
			esc_html__( 'Collection:', 'cupcake-picker' ),
			esc_html( $date_fmt ),
			esc_html( $time_fmt )
		);
	}

	/** Thank-you / order-received page. */
	public function display_on_thankyou( int $order_id ): void {
		$info = $this->get_collection_info( $order_id );
		if ( ! $info ) {
			return;
		}
		printf(
			'<section class="woocommerce-order-details" style="margin-top:30px;">
				<h2 class="woocommerce-order-details__title" style="color:%s;">🧁 %s</h2>
				<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
					<tbody>
						<tr><th>%s</th><td>%s</td></tr>
						<tr><th>%s</th><td>%s</td></tr>
					</tbody>
				</table>
			</section>',
			esc_attr( self::COLOR_BROWN ),
			esc_html__( 'Collection Details', 'cupcake-picker' ),
			esc_html__( 'Collection Date', 'cupcake-picker' ),
			esc_html( $info['date_fmt'] ),
			esc_html__( 'Collection Time', 'cupcake-picker' ),
			esc_html( $info['time_fmt'] )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// INLINE CSS
	// ─────────────────────────────────────────────────────────────────────────
	private function get_inline_css(): string {
		$gold  = self::COLOR_GOLD;
		$brown = self::COLOR_BROWN;
		return <<<CSS
/* ── Cupcake Collection Time Picker ────────────────────────────────── */
#cupcake-picker-wrapper {
    margin: 24px 0;
    padding: 22px 24px;
    background: #fffaf0;
    border: 2px solid {$gold};
    border-radius: 10px;
    font-size: 15px;
    line-height: 1.5;
    box-shadow: 0 2px 8px rgba(77,49,47,.08);
}
.cupcake-picker-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 18px 0;
    font-size: 1.05em;
    font-weight: 700;
    color: {$brown};
}
.cupcake-picker-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}
@media (max-width: 640px) {
    .cupcake-picker-grid { grid-template-columns: 1fr; }
}
.cupcake-picker-field label {
    display: block;
    font-weight: 600;
    font-size: .88em;
    color: {$brown};
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.cupcake-picker-field select {
    width: 100%;
    padding: 11px 38px 11px 13px;
    border: 2px solid #e8d5a3;
    border-radius: 7px;
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%234d312f' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 13px center;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    color: {$brown};
    font-size: 16px; /* iOS zoom fix: min 16px prevents auto-zoom */
    line-height: 1.3;
    cursor: pointer;
    transition: border-color .2s, box-shadow .2s;
    /* iOS specific fixes */
    -webkit-tap-highlight-color: transparent;
}
.cupcake-picker-field select:focus {
    outline: none;
    border-color: {$gold};
    box-shadow: 0 0 0 3px rgba(254,191,86,.25);
}
.cupcake-picker-field select:disabled {
    opacity: .45;
    cursor: not-allowed;
}
#cupcake-picker-error {
    display: none;
    margin-top: 14px;
    padding: 11px 15px;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 6px;
    color: #b91c1c;
    font-size: .9em;
    font-weight: 500;
}
#cupcake-picker-error.is-visible { display: block; }
#cupcake-picker-confirmed {
    display: none;
    margin-top: 14px;
    padding: 11px 15px;
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 6px;
    color: #166534;
    font-size: .9em;
    font-weight: 500;
}
#cupcake-picker-confirmed.is-visible { display: block; }
/* Cart collection row */
.cupcake-cart-collection th { color: {$brown}; font-weight: 700; }
CSS;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// INLINE JAVASCRIPT
	// ─────────────────────────────────────────────────────────────────────────
	private function get_inline_js(): string {
		// phpcs:disable
		return <<<'JAVASCRIPT'
/* ── Cupcake Collection Time Picker JS ────────────────────────────────── */
(function () {
    'use strict';

    var CFG = window.CupcakePickerCFG;
    if (!CFG) return;

    /* ── Time slot definitions ──────────────────────────────────────────── */
    var SLOTS = [
        { value: '10:00-10:30', label: '10:00 AM – 10:30 AM', startH: 10, startM: 0 },
        { value: '10:30-11:00', label: '10:30 AM – 11:00 AM', startH: 10, startM: 30 },
        { value: '11:00-11:30', label: '11:00 AM – 11:30 AM', startH: 11, startM: 0 },
        { value: '11:30-12:00', label: '11:30 AM – 12:00 PM', startH: 11, startM: 30 },
        { value: '12:00-12:30', label: '12:00 PM – 12:30 PM', startH: 12, startM: 0 },
        { value: '12:30-13:00', label: '12:30 PM – 1:00 PM',  startH: 12, startM: 30 },
        { value: '13:00-13:30', label: '1:00 PM – 1:30 PM',   startH: 13, startM: 0 },
        { value: '13:30-14:00', label: '1:30 PM – 2:00 PM',   startH: 13, startM: 30 },
        { value: '14:00-14:30', label: '2:00 PM – 2:30 PM',   startH: 14, startM: 0 },
        { value: '14:30-15:00', label: '2:30 PM – 3:00 PM',   startH: 14, startM: 30 },
        { value: '15:00-15:30', label: '3:00 PM – 3:30 PM',   startH: 15, startM: 0 },
        { value: '15:30-16:00', label: '3:30 PM – 4:00 PM',   startH: 15, startM: 30 },
        { value: '16:00-16:30', label: '4:00 PM – 4:30 PM',   startH: 16, startM: 0 },
        { value: '16:30-17:00', label: '4:30 PM – 5:00 PM',   startH: 16, startM: 30 },
    ];

    var DAYS_LONG   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var MONTHS_SHORT= ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    /* ── State ──────────────────────────────────────────────────────────── */
    var state = {
        date:       '',
        time:       '',
        injected:   false,
        activeTab:  'unknown', // 'pickup' | 'ship' | 'unknown'
        saveTimer:  null,
        tabAttempts: 0,
    };

    /* ── Date helpers ───────────────────────────────────────────────────── */
    function padZ(n) { return n < 10 ? '0' + n : '' + n; }

    function toDateStr(d) {
        return d.getFullYear() + '-' + padZ(d.getMonth() + 1) + '-' + padZ(d.getDate());
    }

    function formatDisplay(dateStr) {
        var d = new Date(dateStr + 'T00:00:00');
        return DAYS_LONG[d.getDay()] + ', ' + MONTHS_SHORT[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    /* ── Slot helpers ───────────────────────────────────────────────────── */
    function getMinTime() {
        return new Date(Date.now() + 4 * 60 * 60 * 1000); // now + 4 h
    }

    function getSlotsForDate(dateStr) {
        var minTime  = getMinTime();
        var todayStr = toDateStr(new Date());
        var isToday  = (dateStr === todayStr);

        return SLOTS.filter(function (slot) {
            if (!isToday) return true;
            var slotStart = new Date(dateStr + 'T' + padZ(slot.startH) + ':' + padZ(slot.startM) + ':00');
            return slotStart > minTime;
        });
    }

    function getAvailableDates() {
        var dates = [];
        var now   = new Date();

        for (var i = 0; i <= 21; i++) {
            var d   = new Date(now);
            d.setDate(d.getDate() + i);
            var dow = d.getDay(); // 0=Sun 1=Mon 2=Tue ... 6=Sat

            if (dow === 1) continue; // Closed Monday

            var ds    = toDateStr(d);
            var slots = getSlotsForDate(ds);
            if (slots.length === 0) continue; // No available slots (today all passed)

            dates.push(ds);
            if (dates.length >= 14) break; // Limit to 14 forward dates
        }
        return dates;
    }

    /* ── Build picker HTML ──────────────────────────────────────────────── */
    function buildPickerHTML() {
        var dates = getAvailableDates();
        var opts  = '<option value="">— Select Date —</option>';
        dates.forEach(function (ds) {
            opts += '<option value="' + ds + '">' + formatDisplay(ds) + '</option>';
        });

        return '<div id="cupcake-picker-wrapper" class="cupcake-picker-wrapper" style="display:none;">' +
            '<h4 class="cupcake-picker-heading">' +
                '<span style="font-size:1.3em;">🧁</span> Collection Date &amp; Time' +
            '</h4>' +
            '<div class="cupcake-picker-grid">' +
                '<div class="cupcake-picker-field">' +
                    '<label for="cupcake-date-sel">Collection Date <span style="color:red;">*</span></label>' +
                    '<select id="cupcake-date-sel">' + opts + '</select>' +
                '</div>' +
                '<div class="cupcake-picker-field">' +
                    '<label for="cupcake-time-sel">Time Slot <span style="color:red;">*</span></label>' +
                    '<select id="cupcake-time-sel" disabled>' +
                        '<option value="">— Select Date First —</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
            '<div id="cupcake-picker-error">⚠️ Please select both a collection date and time slot before placing your order.</div>' +
            '<div id="cupcake-picker-confirmed">✅ <span id="cupcake-confirmed-text"></span></div>' +
        '</div>';
    }

    /* ── Inject picker into block checkout ─────────────────────────────── */
    function findInsertTarget() {
        // Try in order of preference – after shipping method block
        var selectors = [
            '[data-block-name="woocommerce/checkout-shipping-method-block"]',
            '.wc-block-checkout__shipping-method',
            '.wp-block-woocommerce-checkout-shipping-method-block',
            '[data-block-name="woocommerce/checkout-contact-information-block"]',
            '.wc-block-checkout__contact-fields',
            '[data-block-name="woocommerce/checkout-shipping-address-block"]',
            '.wc-block-checkout__shipping-fields',
            '.wc-block-checkout__order-notes',
            '.wc-block-checkout__actions',
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el) return el;
        }
        return null;
    }

    function injectPicker() {
        if (state.injected) return;
        if (!isCheckoutPage()) return;

        var target = findInsertTarget();
        if (!target) return;

        var wrapper = document.createElement('div');
        wrapper.innerHTML = buildPickerHTML();
        var pickerEl = wrapper.firstElementChild;

        target.parentNode.insertBefore(pickerEl, target.nextSibling);
        state.injected = true;

        bindPickerEvents();
        syncPickerVisibility();
    }

    /* ── Picker event handlers ──────────────────────────────────────────── */
    function bindPickerEvents() {
        var dateSel = document.getElementById('cupcake-date-sel');
        var timeSel = document.getElementById('cupcake-time-sel');
        if (!dateSel || !timeSel) return;

        dateSel.addEventListener('change', function () {
            state.date = this.value;
            state.time = '';
            populateTimeSlots(this.value);
            syncConfirmed();
            queueSaveToSession();
        });

        timeSel.addEventListener('change', function () {
            state.time = this.value;
            syncConfirmed();
            hideError();
            queueSaveToSession();
        });
    }

    function populateTimeSlots(dateStr) {
        var timeSel = document.getElementById('cupcake-time-sel');
        if (!timeSel) return;

        if (!dateStr) {
            timeSel.innerHTML = '<option value="">— Select Date First —</option>';
            timeSel.disabled = true;
            return;
        }

        var slots = getSlotsForDate(dateStr);
        var html  = '<option value="">— Select Time Slot —</option>';
        slots.forEach(function (s) {
            html += '<option value="' + s.value + '">' + s.label + '</option>';
        });
        timeSel.innerHTML = html;
        timeSel.disabled  = false;
    }

    function syncConfirmed() {
        var el   = document.getElementById('cupcake-picker-confirmed');
        var text = document.getElementById('cupcake-confirmed-text');
        if (!el || !text) return;

        if (state.date && state.time) {
            var slotLabel = '';
            SLOTS.forEach(function (s) {
                if (s.value === state.time) slotLabel = s.label;
            });
            text.textContent = 'Collecting on ' + formatDisplay(state.date) + ' at ' + slotLabel;
            el.classList.add('is-visible');
            hideError();
        } else {
            el.classList.remove('is-visible');
        }
    }

    function showError() {
        var el = document.getElementById('cupcake-picker-error');
        if (el) {
            el.classList.add('is-visible');
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function hideError() {
        var el = document.getElementById('cupcake-picker-error');
        if (el) el.classList.remove('is-visible');
    }

    /* ── Save selection to WC session via AJAX ──────────────────────────── */
    function queueSaveToSession() {
        if (state.saveTimer) clearTimeout(state.saveTimer);
        state.saveTimer = setTimeout(doSaveToSession, 600);
    }

    function doSaveToSession() {
        var body = new FormData();
        body.append('action', 'cupcake_save_collection');
        body.append('nonce',  CFG.nonce);
        body.append('date',   state.date);
        body.append('time',   state.time);

        // Use XMLHttpRequest to avoid polluting the fetch interceptor
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.ajaxUrl);
        xhr.send(body);
    }

    /* ── Shipping tab management ────────────────────────────────────────── */
    function handleShippingTabs() {
        // Selectors for the WC block shipping-method container
        var containerSel = [
            '[data-block-name="woocommerce/checkout-shipping-method-block"]',
            '.wc-block-checkout__shipping-method',
            '.wp-block-woocommerce-checkout-shipping-method-block',
            '#shipping-method',
            '.wc-block-checkout__shipping-method-container',
        ].join(', ');

        var container = document.querySelector(containerSel);
        if (!container) {
            console.log('[CupcakePicker] Shipping container not found');
            return;
        }

        // Find all shipping method options (divs with role="radio")
        var options = container.querySelectorAll('.wc-block-checkout__shipping-method-option, [role="radio"]');
        console.log('[CupcakePicker] Found', options.length, 'shipping options');

        var detectedTab = 'unknown';
        var pickupEl = null;
        var shipEl = null;

        options.forEach(function (el, idx) {
            // Get text from the element or its children
            var text = el.textContent.toLowerCase().trim();

            var isPickup = text.indexOf('pickup') !== -1 || text.indexOf('collect') !== -1;
            var isShip = text.indexOf('ship') !== -1 || text.indexOf('delivery') !== -1;

            if (!isPickup && !isShip) return;

            console.log('[CupcakePicker] Option', idx, '- text:', text.substring(0, 30), 'isPickup:', isPickup, 'isShip:', isShip);

            // Check if selected: aria-checked="true" OR has --selected class
            var isSelected = el.getAttribute('aria-checked') === 'true' ||
                             el.classList.contains('wc-block-checkout__shipping-method-option--selected');

            console.log('[CupcakePicker] Option', idx, 'isSelected:', isSelected);

            if (isPickup) {
                pickupEl = el;
                if (isSelected) detectedTab = 'pickup';
            }

            if (isShip) {
                shipEl = el;
                if (isSelected) detectedTab = 'ship';
            }
        });

        // Cupcake logic: auto-select pickup, hide ship
        if (CFG.hasCupcake && pickupEl && shipEl) {
            if (detectedTab !== 'pickup') {
                pickupEl.click();
            }
            shipEl.style.display = 'none';
            detectedTab = 'pickup';
        }

        console.log('[CupcakePicker] detectedTab:', detectedTab);

        // Update state with detected tab
        state.activeTab = detectedTab;
        syncPickerVisibility();
    }

    /* ── Show/hide picker based on active tab ───────────────────────────── */
    function syncPickerVisibility() {
        var wrapper = document.getElementById('cupcake-picker-wrapper');
        if (!wrapper) return;

        // ONLY show picker when pickup tab is explicitly selected
        // Hide for ship tab AND for unknown state (before user selects a tab)
        var show = (state.activeTab === 'pickup');
        wrapper.style.display = show ? 'block' : 'none';
    }

    /* ── Watch for tab-switch clicks ────────────────────────────────────── */
    function monitorTabSwitches() {
        // Use a more general click handler that catches clicks on the shipping options
        document.addEventListener('click', function (e) {
            var el = e.target.closest('.wc-block-checkout__shipping-method-option, [role="radio"]');
            if (!el) return;

            var text = el.textContent.toLowerCase();
            if (text.indexOf('pickup') !== -1 || text.indexOf('collect') !== -1) {
                state.activeTab = 'pickup';
                console.log('[CupcakePicker] Clicked: pickup');
            } else if (text.indexOf('ship') !== -1 || text.indexOf('delivery') !== -1) {
                state.activeTab = 'ship';
                console.log('[CupcakePicker] Clicked: ship');
            }
            setTimeout(syncPickerVisibility, 50);
        });

        // Also handle keyboard navigation (change events)
        document.addEventListener('change', function (e) {
            var containerSel = [
                '[data-block-name="woocommerce/checkout-shipping-method-block"]',
                '.wc-block-checkout__shipping-method',
                '#shipping-method',
            ].join(', ');
            if (!e.target.closest(containerSel)) return;

            // Re-run detection after a short delay
            setTimeout(handleShippingTabs, 100);
        });
    }

    /* ── Intercept Store API fetch to inject extension data ─────────────── */
    function interceptStoreFetch() {
        var _fetch = window.fetch;
        window.fetch = function (url, options) {
            if (typeof url === 'string' && /\/wc\/store(\/v1)?\/checkout/.test(url)) {

                // Client-side validation: only validate when pickup is explicitly selected
                var needsValidation = (state.activeTab === 'pickup');
                if (needsValidation && (!state.date || !state.time)) {
                    showError();
                    // Return a rejected promise to stop the checkout
                    return Promise.reject(new Error('Cupcake: Collection date/time not selected.'));
                }

                // Inject extension data into request body
                if (options && options.body) {
                    try {
                        var body = JSON.parse(options.body);
                        body.extensions = body.extensions || {};
                        body.extensions.cupcake_collection = {
                            date: state.date,
                            time: state.time,
                        };
                        options = Object.assign({}, options, { body: JSON.stringify(body) });
                    } catch (err) {
                        // Couldn't parse body – session fallback will handle saving
                    }
                }
            }
            return _fetch.call(window, url, options);
        };
    }

    /* ── Classic checkout form interception (fallback) ──────────────────── */
    function interceptClassicForm() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.classList.contains('woocommerce-checkout')) return;

            // Only validate when pickup is explicitly selected
            var needsValidation = (state.activeTab === 'pickup');
            if (needsValidation && (!state.date || !state.time)) {
                e.preventDefault();
                showError();
                return;
            }

            // Append hidden fields for classic checkout save
            function addHidden(name, val) {
                var inp = form.querySelector('[name="' + name + '"]');
                if (!inp) {
                    inp = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.name  = name;
                    form.appendChild(inp);
                }
                inp.value = val;
            }
            addHidden('cupcake_collection_date', state.date);
            addHidden('cupcake_collection_time', state.time);
        });
    }

    /* ── Utility ────────────────────────────────────────────────────────── */
    function isCheckoutPage() {
        return (
            document.body.classList.contains('woocommerce-checkout') ||
            !!document.querySelector('.wp-block-woocommerce-checkout') ||
            !!document.querySelector('[data-block-name="woocommerce/checkout"]')
        );
    }

    /* ── MutationObserver to handle async block rendering ───────────────── */
    function startObserver() {
        // Retry injection + tab handling at startup
        var attempts = 0;
        var maxAttempts = 60; // Increased for slower devices

        function trySetup() {
            attempts++;
            if (!state.injected) injectPicker();
            if (state.tabAttempts < 20) {
                handleShippingTabs();
                state.tabAttempts++;
            }
            if (attempts < maxAttempts && !state.injected) {
                setTimeout(trySetup, 300);
            }
        }
        trySetup();

        // Fallback: keep checking periodically even after max attempts (for iOS)
        setInterval(function() {
            if (!state.injected) {
                injectPicker();
            }
            handleShippingTabs();
        }, 1000);

        // Also watch for DOM mutations (blocks can re-render on field change)
        if (window.MutationObserver) {
            var observer = new MutationObserver(function (mutations) {
                // Only re-run if a meaningful subtree changed
                var shouldCheck = mutations.some(function (m) {
                    return m.addedNodes.length > 0;
                });
                if (!shouldCheck) return;

                if (!state.injected) injectPicker();
                handleShippingTabs();
            });

            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    /* ── Debug: Log current state ───────────────────────────────────────── */
    function logState() {
        console.log('[CupcakePicker] activeTab:', state.activeTab, 'hasCupcake:', CFG.hasCupcake);
    }

    /* ── Bootstrap ──────────────────────────────────────────────────────── */
    function init() {
        if (!isCheckoutPage()) return;
        console.log('[CupcakePicker] Initializing...');

        // iOS Safari fix: ensure DOM is fully ready
        if (document.readyState !== 'complete') {
            setTimeout(init, 100);
            return;
        }

        interceptStoreFetch();
        interceptClassicForm();
        monitorTabSwitches();
        startObserver();

        // Force initial visibility check after a delay (helps with iOS)
        setTimeout(function() {
            handleShippingTabs();
            syncPickerVisibility();
        }, 500);

        // Debug: Log state every 2 seconds for first 10 seconds
        for (var i = 1; i <= 5; i++) {
            setTimeout(logState, i * 2000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
JAVASCRIPT;
		// phpcs:enable
	}
}

// ── Boot ──────────────────────────────────────────────────────────────────────
Cupcake_Collection_Time_Picker::get_instance();

endif; // class_exists guard