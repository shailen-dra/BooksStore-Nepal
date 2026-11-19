<?php
/**
 * eSewa ePay v2 payment gateway.
 *
 * Flow: process_payment() stores a transaction UUID and sends the customer to the
 * order-pay receipt page, which auto-posts a signed form to eSewa. eSewa sends the
 * customer back to the wc-api callback (result=success|failure). A success is only
 * accepted after the signed callback data matches this order AND eSewa's server-side
 * status API confirms COMPLETE for the same UUID and amount.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Gateway;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * eSewa wallet payments.
 */
class EsewaGateway extends WC_Payment_Gateway {

	public const ID                = 'esewa';
	public const API_ACTION        = 'bsn_esewa';
	public const META_UUID         = '_bsn_esewa_uuid';
	public const META_REF_ID       = '_bsn_esewa_ref_id';
	public const TEST_PRODUCT_CODE = 'EPAYTEST';
	// Public UAT secret published in eSewa's developer docs. Live secret lives in settings only.
	public const TEST_SECRET     = '8gBm/:&EnhH.1/q';
	public const FORM_URL_TEST   = 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';
	public const FORM_URL_LIVE   = 'https://epay.esewa.com.np/api/epay/main/v2/form';
	public const STATUS_URL_TEST = 'https://rc.esewa.com.np/api/epay/transaction/status/';
	public const STATUS_URL_LIVE = 'https://esewa.com.np/api/epay/transaction/status/';
	public const REQUEST_SIGNED  = array( 'total_amount', 'transaction_uuid', 'product_code' );
	// Fields that must be covered by the callback signature before we read them.
	public const CALLBACK_SIGNED = array( 'transaction_code', 'status', 'total_amount', 'transaction_uuid', 'product_code' );
	public const STATUS_TIMEOUT  = 15;

	/**
	 * Hooks the gateway into WooCommerce.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateway' ) );
		add_action( 'woocommerce_api_' . self::API_ACTION, array( self::class, 'dispatch_callback' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( self::class, 'register_blocks' ) );
	}

	/**
	 * Declares HPOS and block checkout compatibility. Hooked on before_woocommerce_init.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BSN_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BSN_PLUGIN_FILE, true );
		}
	}

	/**
	 * Adds the gateway class to WooCommerce's list.
	 *
	 * @param array $gateways Gateway classes.
	 * @return array
	 */
	public static function add_gateway( $gateways ) {
		$gateways   = is_array( $gateways ) ? $gateways : array();
		$gateways[] = self::class;
		return $gateways;
	}

	/**
	 * Registers the Cart & Checkout blocks integration.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
	 */
	public static function register_blocks( $registry ): void {
		$registry->register( new EsewaBlocks() );
	}

	/**
	 * Handles the wc-api request: redirects and exits.
	 */
	public static function dispatch_callback(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = isset( $gateways[ self::ID ] ) && $gateways[ self::ID ] instanceof self ? $gateways[ self::ID ] : new self();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Third-party redirect; authenticity comes from the order key, HMAC and status API.
		$redirect = $gateway->handle_callback( wp_unslash( $_GET ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'eSewa', 'bookstore-nepal' );
		$this->method_description = __( 'Customers pay with their eSewa wallet (ePay v2). Payments are confirmed with eSewa before the order is marked paid.', 'bookstore-nepal' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	/**
	 * Admin settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable/Disable', 'bookstore-nepal' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable eSewa', 'bookstore-nepal' ),
				'default' => 'no',
			),
			'title'        => array(
				'title'   => __( 'Title', 'bookstore-nepal' ),
				'type'    => 'text',
				'default' => __( 'eSewa', 'bookstore-nepal' ),
			),
			'description'  => array(
				'title'   => __( 'Description', 'bookstore-nepal' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with your eSewa wallet. You will be sent to eSewa to log in and confirm.', 'bookstore-nepal' ),
			),
			'testmode'     => array(
				'title'       => __( 'Test mode', 'bookstore-nepal' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the eSewa sandbox (product code EPAYTEST and eSewa\'s public test secret)', 'bookstore-nepal' ),
				'default'     => 'yes',
				'description' => __( 'While test mode is on, the live product code and secret below are ignored.', 'bookstore-nepal' ),
			),
			'product_code' => array(
				'title'       => __( 'Live product code', 'bookstore-nepal' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Merchant product code issued by eSewa.', 'bookstore-nepal' ),
			),
			'secret_key'   => array(
				'title'       => __( 'Live secret key', 'bookstore-nepal' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Secret key issued by eSewa. Stored in the database only.', 'bookstore-nepal' ),
			),
		);
	}

	/**
	 * Whether the sandbox is in use.
	 */
	public function is_test_mode(): bool {
		return 'yes' === $this->get_option( 'testmode', 'yes' );
	}

	/**
	 * Merchant product code for the current mode.
	 */
	public function product_code(): string {
		return $this->is_test_mode() ? self::TEST_PRODUCT_CODE : trim( (string) $this->get_option( 'product_code' ) );
	}

	/**
	 * Secret key for the current mode.
	 */
	private function secret(): string {
		return $this->is_test_mode() ? self::TEST_SECRET : (string) $this->get_option( 'secret_key' );
	}

	/**
	 * eSewa form URL for the current mode.
	 */
	public function form_url(): string {
		return $this->is_test_mode() ? self::FORM_URL_TEST : self::FORM_URL_LIVE;
	}

	/**
	 * Status-check URL for the current mode.
	 */
	public function status_url(): string {
		return $this->is_test_mode() ? self::STATUS_URL_TEST : self::STATUS_URL_LIVE;
	}

	/**
	 * Available only for NPR stores with credentials configured.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( 'NPR' !== get_woocommerce_currency() ) {
			return false;
		}
		return '' !== $this->product_code() && '' !== $this->secret();
	}

	/**
	 * Starts a payment attempt: new transaction UUID, then the receipt page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Order not found.', 'bookstore-nepal' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$order->update_meta_data( self::META_UUID, $order->get_id() . '-' . time() );
		$order->save();
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Amount string sent to eSewa (store decimals; NPR has none).
	 *
	 * @param WC_Order $order Order.
	 */
	private function order_amount( WC_Order $order ): string {
		return (string) wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
	}

	/**
	 * Callback URL for eSewa to send the customer back to.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $result success|failure.
	 */
	public function callback_url( WC_Order $order, string $result ): string {
		return add_query_arg(
			array(
				'result'   => $result,
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( self::API_ACTION )
		);
	}

	/**
	 * The signed fields posted to eSewa.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	public function form_fields_for( WC_Order $order ): array {
		$uuid = (string) $order->get_meta( self::META_UUID );
		if ( '' === $uuid ) {
			$uuid = $order->get_id() . '-' . time();
			$order->update_meta_data( self::META_UUID, $uuid );
			$order->save();
		}
		$amount = $this->order_amount( $order );
		$fields = array(
			'amount'                  => $amount,
			'tax_amount'              => '0',
			'total_amount'            => $amount,
			'transaction_uuid'        => $uuid,
			'product_code'            => $this->product_code(),
			'product_service_charge'  => '0',
			'product_delivery_charge' => '0',
			'success_url'             => $this->callback_url( $order, 'success' ),
			'failure_url'             => $this->callback_url( $order, 'failure' ),
			'signed_field_names'      => implode( ',', self::REQUEST_SIGNED ),
		);
		$fields['signature'] = EsewaSignature::sign( $fields, self::REQUEST_SIGNED, $this->secret() );
		return $fields;
	}

	/**
	 * Receipt page: a form that auto-posts to eSewa, with a button if JS is off.
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->is_paid() ) {
			return;
		}
		$fields = $this->form_fields_for( $order );
		echo '<p>' . esc_html__( 'Taking you to eSewa to complete your payment…', 'bookstore-nepal' ) . '</p>';
		echo '<form id="bsn-esewa-form" action="' . esc_url( $this->form_url() ) . '" method="post">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<button type="submit" class="button alt wp-element-button">' . esc_html__( 'Pay with eSewa', 'bookstore-nepal' ) . '</button>';
		echo '</form>';
		echo '<script>document.getElementById("bsn-esewa-form").submit();</script>';
	}

	/**
	 * Normalises a query array: eSewa appends "?data=..." to success_url even when it
	 * already has a query string, so a value may carry a second query string.
	 *
	 * @param array $query Raw query args.
	 * @return array<string,string>
	 */
	private function normalise_query( array $query ): array {
		$out   = array();
		$extra = array();
		foreach ( $query as $name => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$pos = strpos( $value, '?' );
			if ( false !== $pos ) {
				parse_str( substr( $value, $pos + 1 ), $parsed );
				$extra = array_merge( $extra, $parsed );
				$value = substr( $value, 0, $pos );
			}
			$out[ (string) $name ] = $value;
		}
		foreach ( $extra as $name => $value ) {
			if ( is_string( $value ) && ! isset( $out[ $name ] ) ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Handles a customer returning from eSewa. Never trusts the query string alone.
	 *
	 * @param array $query Query args (unslashed $_GET).
	 * @return string URL to redirect the customer to.
	 */
	public function handle_callback( array $query ): string {
		$query  = $this->normalise_query( $query );
		$result = $query['result'] ?? '';
		$order  = wc_get_order( absint( $query['order_id'] ?? 0 ) );
		$key    = $query['key'] ?? '';

		if ( ! $order instanceof WC_Order || self::ID !== $order->get_payment_method() || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wc_add_notice( __( 'We could not match this eSewa payment to an order. Please contact us if money was deducted.', 'bookstore-nepal' ), 'error' );
			return wc_get_cart_url();
		}

		if ( 'success' !== $result && 'failure' !== $result ) {
			wc_add_notice( __( 'We could not match this eSewa payment to an order. Please contact us if money was deducted.', 'bookstore-nepal' ), 'error' );
			return wc_get_cart_url();
		}

		$order_id = $order->get_id();
		$lock     = $this->lock( $order_id );
		if ( null === $lock ) {
			// Another request for this order is still running (e.g. waiting on the status API).
			error_log( sprintf( 'Bookstore Nepal eSewa: could not lock order %d for the %s callback; another request is still processing it.', $order_id, $result ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wc_add_notice( __( 'We’re still confirming your eSewa payment. Please refresh this page in a moment, or contact us if it does not update.', 'bookstore-nepal' ), 'notice' );
			return $order->get_checkout_order_received_url();
		}
		try {
			// Re-read from the database, not this request's caches, so a request that
			// waited for the lock sees what the previous holder committed.
			$order = $this->reload_order( $order_id );
			if ( null === $order ) {
				wc_add_notice( __( 'We could not match this eSewa payment to an order. Please contact us if money was deducted.', 'bookstore-nepal' ), 'error' );
				return wc_get_cart_url();
			}
			if ( 'failure' === $result ) {
				return $this->handle_failure( $order );
			}
			if ( $order->is_paid() ) {
				return $order->get_checkout_order_received_url();
			}
			$confirmed = $this->verify_success( $order, $query['data'] ?? '' );
			if ( is_string( $confirmed ) ) {
				return $this->reject( $order, $confirmed );
			}
			$order->update_meta_data( self::META_REF_ID, $confirmed['ref_id'] );
			$order->add_order_note(
				sprintf(
					/* translators: 1: eSewa transaction code, 2: eSewa reference id. */
					__( 'eSewa payment confirmed by status check. Transaction code: %1$s, reference: %2$s.', 'bookstore-nepal' ),
					$confirmed['transaction_code'],
					$confirmed['ref_id']
				)
			);
			$order->payment_complete( $confirmed['transaction_code'] );
			return $order->get_checkout_order_received_url();
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Verifies a success callback. Returns the confirmed transaction or a rejection reason.
	 *
	 * @param WC_Order $order Order (unpaid).
	 * @param string   $data  Base64 JSON from eSewa.
	 * @return array{transaction_code:string,ref_id:string}|string
	 */
	private function verify_success( WC_Order $order, string $data ) {
		// "+" in base64 arrives as a space when the value was not URL-encoded.
		$json    = base64_decode( str_replace( ' ', '+', $data ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return __( 'eSewa callback rejected: response data missing or unreadable.', 'bookstore-nepal' );
		}
		if ( ! EsewaSignature::verify( $payload, $this->secret() ) ) {
			return __( 'eSewa callback rejected: invalid signature.', 'bookstore-nepal' );
		}
		$signed = explode( ',', (string) $payload['signed_field_names'] );
		if ( array_diff( self::CALLBACK_SIGNED, $signed ) ) {
			return __( 'eSewa callback rejected: required fields are not signed.', 'bookstore-nepal' );
		}
		if ( (string) $payload['product_code'] !== $this->product_code() ) {
			return __( 'eSewa callback rejected: product code does not match this store.', 'bookstore-nepal' );
		}
		$uuid = (string) $order->get_meta( self::META_UUID );
		if ( '' === $uuid || ! hash_equals( $uuid, (string) $payload['transaction_uuid'] ) ) {
			return __( 'eSewa callback rejected: transaction does not belong to this order.', 'bookstore-nepal' );
		}
		if ( ! $this->amounts_match( $payload['total_amount'], $order ) ) {
			return sprintf(
				/* translators: 1: amount reported by eSewa, 2: order total. */
				__( 'eSewa callback rejected: amount mismatch (eSewa %1$s, order total %2$s).', 'bookstore-nepal' ),
				sanitize_text_field( (string) $payload['total_amount'] ),
				$this->order_amount( $order )
			);
		}
		if ( 'COMPLETE' !== $payload['status'] ) {
			return sprintf(
				/* translators: %s: status reported by eSewa. */
				__( 'eSewa callback rejected: payment status is %s, not COMPLETE.', 'bookstore-nepal' ),
				sanitize_text_field( (string) $payload['status'] )
			);
		}
		$transaction_code = sanitize_text_field( (string) $payload['transaction_code'] );
		if ( '' === $transaction_code ) {
			return __( 'eSewa callback rejected: no transaction code.', 'bookstore-nepal' );
		}

		$status = $this->fetch_status( $order, $uuid );
		if ( is_string( $status ) ) {
			return $status;
		}
		return array(
			'transaction_code' => $transaction_code,
			'ref_id'           => $status['ref_id'],
		);
	}

	/**
	 * Server-side confirmation from eSewa's status API.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $uuid  Transaction UUID.
	 * @return array{ref_id:string}|string Confirmation or rejection reason.
	 */
	private function fetch_status( WC_Order $order, string $uuid ) {
		$url      = add_query_arg(
			array(
				'product_code'     => rawurlencode( $this->product_code() ),
				'total_amount'     => rawurlencode( $this->order_amount( $order ) ),
				'transaction_uuid' => rawurlencode( $uuid ),
			),
			$this->status_url()
		);
		$response = wp_remote_get( $url, array( 'timeout' => self::STATUS_TIMEOUT ) );
		if ( is_wp_error( $response ) ) {
			return sprintf(
				/* translators: %s: HTTP error message. */
				__( 'eSewa status check failed: %s. Payment not confirmed.', 'bookstore-nepal' ),
				$response->get_error_message()
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'eSewa status check failed: HTTP %d. Payment not confirmed.', 'bookstore-nepal' ),
				$code
			);
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return __( 'eSewa status check failed: unreadable response. Payment not confirmed.', 'bookstore-nepal' );
		}
		$status = is_scalar( $body['status'] ?? null ) ? (string) $body['status'] : '';
		if ( 'COMPLETE' !== $status ) {
			return sprintf(
				/* translators: %s: status from eSewa. */
				__( 'eSewa status check returned %s, not COMPLETE. Payment not confirmed.', 'bookstore-nepal' ),
				'' === $status ? '(none)' : sanitize_text_field( $status )
			);
		}
		if ( ! is_scalar( $body['transaction_uuid'] ?? null ) || ! hash_equals( $uuid, (string) $body['transaction_uuid'] )
			|| (string) ( $body['product_code'] ?? '' ) !== $this->product_code() ) {
			return __( 'eSewa status check rejected: transaction does not match this order.', 'bookstore-nepal' );
		}
		if ( ! $this->amounts_match( $body['total_amount'] ?? null, $order ) ) {
			return __( 'eSewa status check rejected: amount mismatch. Payment not confirmed.', 'bookstore-nepal' );
		}
		return array( 'ref_id' => sanitize_text_field( (string) ( $body['ref_id'] ?? '' ) ) );
	}

	/**
	 * Compares an eSewa amount ("1,000.0", 500, "500.00") with the order total.
	 *
	 * @param mixed    $amount Amount from eSewa.
	 * @param WC_Order $order  Order.
	 */
	private function amounts_match( $amount, WC_Order $order ): bool {
		if ( ! is_scalar( $amount ) ) {
			return false;
		}
		$amount = str_replace( ',', '', trim( (string) $amount ) );
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $amount ) ) {
			return false;
		}
		$paid  = (int) round( (float) $amount * 100 );
		$total = (int) round( (float) $order->get_total() * 100 );
		return $total > 0 && $paid === $total;
	}

	/**
	 * Customer came back via failure_url.
	 *
	 * @param WC_Order $order Order.
	 */
	private function handle_failure( WC_Order $order ): string {
		if ( $order->is_paid() ) {
			return $order->get_checkout_order_received_url();
		}
		if ( $order->has_status( 'pending' ) ) {
			$order->update_status( 'failed', __( 'eSewa payment was not completed (customer returned via the eSewa failure URL).', 'bookstore-nepal' ) );
		}
		wc_add_notice( __( 'eSewa payment was not completed. You can try again or choose another payment method.', 'bookstore-nepal' ), 'error' );
		return wc_get_cart_url();
	}

	/**
	 * Records why a success callback was refused and sends the customer back.
	 *
	 * @param WC_Order $order  Order (left unpaid).
	 * @param string   $reason Reason for the order note.
	 */
	private function reject( WC_Order $order, string $reason ): string {
		$order->add_order_note( $reason );
		wc_add_notice(
			sprintf(
				/* translators: %s: order number. */
				__( 'We could not confirm your eSewa payment, so order #%s has not been marked as paid. You can try again or choose another payment method. If money was deducted from your eSewa account, please contact us.', 'bookstore-nepal' ),
				$order->get_order_number()
			),
			'error'
		);
		return wc_get_cart_url();
	}

	/**
	 * Loads an order bypassing request-local caches: WooCommerce's order object cache
	 * (HPOS), the HPOS datastore cache, and the post/meta caches (CPT store). Without
	 * this, a request that waited for the lock would see the order as it was before
	 * the other request paid it, and pay or fail it a second time.
	 *
	 * @param int $order_id Order ID.
	 */
	private function reload_order( int $order_id ): ?WC_Order {
		if ( class_exists( OrderCache::class ) ) {
			wc_get_container()->get( OrderCache::class )->remove( $order_id );
		}
		if ( OrderUtil::custom_orders_table_usage_is_enabled() && class_exists( OrdersTableDataStore::class ) ) {
			wc_get_container()->get( OrdersTableDataStore::class )->clear_cached_data( array( $order_id ) );
		}
		clean_post_cache( $order_id );
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Serialises callbacks for one order (MySQL named lock), so a double submit
	 * cannot complete the order twice.
	 *
	 * @param int $order_id Order ID.
	 * @return string|null Lock name when held.
	 */
	private function lock( int $order_id ): ?string {
		global $wpdb;
		$name = 'bsn_esewa_' . $order_id;
		/**
		 * Seconds to wait for another callback on the same order to finish.
		 *
		 * @param int $timeout Seconds.
		 */
		$timeout = max( 0, (int) apply_filters( 'bsn_esewa_lock_timeout', self::STATUS_TIMEOUT + 5 ) );
		$got     = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $got ? $name : null;
	}

	/**
	 * Releases a lock taken by lock().
	 *
	 * @param string $name Lock name.
	 */
	private function unlock( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
