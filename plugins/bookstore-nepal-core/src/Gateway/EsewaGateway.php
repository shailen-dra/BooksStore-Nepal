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
	// Every UUID minted for the order (uuid => amount sent to eSewa), oldest first, so the
	// reconciler can find a payment made on an earlier attempt.
	public const META_UUIDS        = '_bsn_esewa_uuids';
	public const META_REF_ID       = '_bsn_esewa_ref_id';
	// The transaction UUID that paid the order (any other paid one is a second payment).
	public const META_PAID_UUID    = '_bsn_esewa_paid_uuid';
	public const META_STRAY_PREFIX = '_bsn_esewa_stray_';
	// uuid => [ status => time ] for statuses already noted by the reconciler (one note each).
	public const META_STATUS_NOTES = '_bsn_esewa_status_notes';
	// Set while an order with eSewa transactions is paid by another method, for the stray check.
	public const META_STRAY_CHECK  = '_bsn_esewa_stray_check';
	// Set once an order has more than one transaction, for the second-payment check.
	public const META_MULTIPLE     = '_bsn_esewa_multiple';
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
	public const STATUS_TIMEOUT  = 8;
	// Status-API statuses after which a transaction is not checked again.
	public const FINAL_STATUSES = array( 'CANCELED', 'FULL_REFUND', 'PARTIAL_REFUND' );
	// Status-API statuses that may still turn into COMPLETE.
	public const OPEN_STATUSES = array( 'PENDING', 'AMBIGUOUS' );

	// check_status() outcomes.
	private const CHECK_COMPLETE = 'complete';
	private const CHECK_RETRY    = 'retry';
	private const CHECK_UNPAID   = 'unpaid';
	private const CHECK_MISMATCH = 'mismatch';

	// reconcile() outcomes.
	public const RECONCILE_PAID         = 'paid';         // Completed now.
	public const RECONCILE_ALREADY_PAID = 'already_paid'; // Paid before (e.g. by the callback).
	public const RECONCILE_RETRY        = 'retry';        // eSewa undecided, API unavailable or order busy.
	public const RECONCILE_MISMATCH     = 'mismatch';     // COMPLETE for a different amount: staff must look.
	public const RECONCILE_UNPAID       = 'unpaid';       // eSewa has no completed payment.
	public const RECONCILE_STRAY        = 'stray';        // Paid on eSewa, but the order uses another method now.
	public const RECONCILE_SKIPPED      = 'skipped';      // Nothing to check.
	// Customers retry rarely; the cap bounds status-API calls per order per reconciliation run.
	public const MAX_REMEMBERED_UUIDS = 20;

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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Third-party redirect; authenticity comes from the order key, HMAC and status API.
		$redirect = self::instance()->handle_callback( wp_unslash( $_GET ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * WooCommerce's gateway object (built once, with the saved settings), or a new one if
	 * WooCommerce has not registered it.
	 */
	public static function instance(): self {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ self::ID ] ) && $gateways[ self::ID ] instanceof self ? $gateways[ self::ID ] : new self();
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
		$this->mint_uuid( $order );
		$order->save();
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Starts a new transaction for the order: sets the current UUID and remembers it with the
	 * amount eSewa will be asked for. The caller saves the order.
	 *
	 * @param WC_Order $order Order.
	 * @return string The new UUID.
	 */
	private function mint_uuid( WC_Order $order ): string {
		$uuid       = $order->get_id() . '-' . time();
		$remembered = $this->remembered_uuids( $order );
		unset( $remembered[ $uuid ] ); // Same second: move it to the end.
		$remembered[ $uuid ] = $this->order_amount( $order );
		$remembered          = array_slice( $remembered, -self::MAX_REMEMBERED_UUIDS, null, true );
		$order->update_meta_data( self::META_UUID, $uuid );
		$order->update_meta_data( self::META_UUIDS, $remembered );
		if ( count( $remembered ) > 1 ) {
			$order->update_meta_data( self::META_MULTIPLE, '1' );
		}
		return $uuid;
	}

	/**
	 * Every transaction UUID minted for the order (uuid => amount sent to eSewa), oldest first.
	 * Includes the current UUID of orders from before the list was kept.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	public function remembered_uuids( WC_Order $order ): array {
		$stored     = $order->get_meta( self::META_UUIDS );
		$remembered = array();
		foreach ( is_array( $stored ) ? $stored : array() as $uuid => $amount ) {
			if ( is_string( $uuid ) && '' !== $uuid && is_scalar( $amount ) ) {
				$remembered[ $uuid ] = (string) $amount;
			}
		}
		$current = (string) $order->get_meta( self::META_UUID );
		if ( '' !== $current && ! isset( $remembered[ $current ] ) ) {
			$remembered[ $current ] = $this->order_amount( $order );
		}
		return $remembered;
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
			$uuid = $this->mint_uuid( $order );
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

		if ( $order instanceof WC_Order && self::ID !== $order->get_payment_method() && 'success' === $result
			&& '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			// The customer paid with eSewa, then switched this order to another method (e.g. cash on delivery).
			$this->note_stray_payment( $order, $query['data'] ?? '' );
			wc_add_notice( __( 'This order is no longer set to be paid with eSewa. If money was deducted from your eSewa account, please contact us and we will refund it.', 'bookstore-nepal' ), 'error' );
			return wc_get_cart_url();
		}

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
			wc_add_notice(
				'failure' === $result
					? __( 'We’re still checking this order with eSewa. Please refresh this page in a moment, or contact us if it does not update.', 'bookstore-nepal' )
					: __( 'We’re still confirming your eSewa payment. Please refresh this page in a moment, or contact us if it does not update.', 'bookstore-nepal' ),
				'notice'
			);
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
			$this->complete_payment(
				$order,
				$confirmed['uuid'],
				$confirmed['transaction_code'],
				$confirmed['ref_id'],
				sprintf(
					/* translators: 1: eSewa transaction code, 2: eSewa reference id. */
					__( 'eSewa payment confirmed by status check. Transaction code: %1$s, reference: %2$s.', 'bookstore-nepal' ),
					$confirmed['transaction_code'],
					$confirmed['ref_id']
				)
			);
			return $order->get_checkout_order_received_url();
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Marks an order paid. Callers hold the order lock and have re-read the order and checked
	 * it is unpaid, so payment_complete() (stock, emails) runs once per order.
	 *
	 * @param WC_Order $order          Order (fresh, unpaid, locked).
	 * @param string   $uuid           Transaction UUID that paid it.
	 * @param string   $transaction_id Transaction id to record.
	 * @param string   $ref_id         eSewa reference id.
	 * @param string   $note           Order note.
	 */
	private function complete_payment( WC_Order $order, string $uuid, string $transaction_id, string $ref_id, string $note ): void {
		$order->update_meta_data( self::META_PAID_UUID, $uuid );
		$order->update_meta_data( self::META_REF_ID, $ref_id );
		$order->add_order_note( $note );
		$order->payment_complete( $transaction_id );
	}

	/**
	 * Notes an eSewa success that arrived for an order no longer paid with eSewa, so staff can
	 * check the merchant portal and refund. Adds one note per transaction.
	 *
	 * @param WC_Order $order Order (owner verified by order key).
	 * @param string   $data  Base64 JSON from eSewa.
	 */
	private function note_stray_payment( WC_Order $order, string $data ): void {
		$json    = base64_decode( str_replace( ' ', '+', $data ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload = false === $json ? null : json_decode( $json, true );
		$signed  = is_array( $payload ) && EsewaSignature::verify( $payload, $this->secret() );
		$uuid    = $signed && is_scalar( $payload['transaction_uuid'] ?? null )
			? sanitize_text_field( (string) $payload['transaction_uuid'] )
			: (string) $order->get_meta( self::META_UUID );
		$code    = $signed && is_scalar( $payload['transaction_code'] ?? null ) ? sanitize_text_field( (string) $payload['transaction_code'] ) : '';
		$noted   = self::META_STRAY_PREFIX . md5( $uuid . '|' . $code );
		if ( $order->get_meta( $noted ) || ( $signed && $order->get_meta( $this->verified_stray_key( $uuid ) ) ) ) {
			return;
		}
		$order->update_meta_data( $noted, gmdate( 'c' ) );
		if ( $signed ) {
			// Genuine eSewa data: the reconciler need not note this transaction again.
			$order->update_meta_data( $this->verified_stray_key( $uuid ), gmdate( 'c' ) );
		}
		$this->add_stray_note( $order, $uuid, $code, $signed ? __( 'signed by eSewa', 'bookstore-nepal' ) : __( 'signature not verified', 'bookstore-nepal' ) );
		$order->save();
	}

	/**
	 * Meta key marking a stray payment for this transaction as noted from eSewa-verified data
	 * (a signed callback or the status API).
	 *
	 * @param string $uuid Transaction UUID.
	 */
	private function verified_stray_key( string $uuid ): string {
		return self::META_STRAY_PREFIX . md5( $uuid . '|verified' );
	}

	/**
	 * Adds the stray-payment note. The caller saves the order.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $uuid   Transaction UUID.
	 * @param string   $code   Transaction code or reference.
	 * @param string   $source Where the information came from.
	 */
	private function add_stray_note( WC_Order $order, string $uuid, string $code, string $source ): void {
		$order->add_order_note(
			sprintf(
				/* translators: 1: eSewa transaction UUID, 2: eSewa transaction code or "unknown", 3: "signed by eSewa", "signature not verified" or "confirmed by eSewa status check". */
				__( 'eSewa reported a successful payment for this order after it was switched to another payment method. Transaction UUID: %1$s, transaction code: %2$s (%3$s). Possible payment received: check the eSewa merchant portal and refund the customer if needed.', 'bookstore-nepal' ),
				'' === $uuid ? __( 'unknown', 'bookstore-nepal' ) : $uuid,
				'' === $code ? __( 'unknown', 'bookstore-nepal' ) : $code,
				$source
			)
		);
	}

	/**
	 * Verifies a success callback. Returns the confirmed transaction or a rejection reason.
	 *
	 * @param WC_Order $order Order (unpaid).
	 * @param string   $data  Base64 JSON from eSewa.
	 * @return array{uuid:string,transaction_code:string,ref_id:string}|string
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
		// Any attempt started for this order counts: the customer may have paid an earlier one,
		// lost the tab and retried before eSewa's redirect for the first came through.
		$uuid = $this->remembered_uuid_matching( $order, (string) $payload['transaction_uuid'] );
		if ( '' === $uuid ) {
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
			'uuid'             => $uuid,
			'transaction_code' => $transaction_code,
			'ref_id'           => $status['ref_id'],
		);
	}

	/**
	 * The remembered UUID of this order equal to $candidate (compared in constant time), or ''.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $candidate UUID from eSewa.
	 */
	private function remembered_uuid_matching( WC_Order $order, string $candidate ): string {
		$found = '';
		foreach ( array_keys( $this->remembered_uuids( $order ) ) as $uuid ) {
			$uuid = (string) $uuid;
			if ( '' !== $candidate && hash_equals( $uuid, $candidate ) ) {
				$found = $uuid;
			}
		}
		return $found;
	}

	/**
	 * Server-side confirmation from eSewa's status API, for the callback.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $uuid  Transaction UUID.
	 * @return array{ref_id:string}|string Confirmation or rejection reason.
	 */
	private function fetch_status( WC_Order $order, string $uuid ) {
		$check = $this->check_status( $uuid, $this->order_amount( $order ) );
		if ( self::CHECK_COMPLETE !== $check['outcome'] ) {
			return $check['message'];
		}
		if ( ! $this->amounts_match( $check['amount'], $order ) ) {
			return $this->amount_mismatch_message();
		}
		return array( 'ref_id' => $check['ref_id'] );
	}

	/**
	 * Rejection reason for a COMPLETE status whose amount differs from the order total.
	 */
	private function amount_mismatch_message(): string {
		return __( 'eSewa status check rejected: amount mismatch. Payment not confirmed.', 'bookstore-nepal' );
	}

	/**
	 * Asks eSewa's status API about one transaction. Does not compare the amount with an
	 * order; callers do, against the order they are about to complete.
	 *
	 * @param string $uuid   Transaction UUID.
	 * @param string $amount Amount the transaction was started with.
	 * @return array{outcome:string,status:string,message:string,ref_id:string,amount:mixed,failed:bool,api_down:bool}
	 *         outcome: complete (UUID and product code match), retry (PENDING/AMBIGUOUS/no status, or
	 *         the request failed), unpaid (any other status) or mismatch (another transaction).
	 *         failed: no usable answer. api_down: transport failure or 5xx, i.e. eSewa itself is
	 *         unreachable (a 4xx or malformed answer concerns only this transaction).
	 */
	private function check_status( string $uuid, string $amount ): array {
		$result   = array(
			'outcome'   => self::CHECK_RETRY,
			'status'    => '',
			'message'   => '',
			'ref_id'    => '',
			'amount'    => null,
			'failed'    => true,
			'api_down'  => true,
		);
		$url      = add_query_arg(
			array(
				'product_code'     => rawurlencode( $this->product_code() ),
				'total_amount'     => rawurlencode( $amount ),
				'transaction_uuid' => rawurlencode( $uuid ),
			),
			$this->status_url()
		);
		$response = wp_remote_get( $url, array( 'timeout' => self::STATUS_TIMEOUT ) );
		if ( is_wp_error( $response ) ) {
			$result['message'] = sprintf(
				/* translators: %s: HTTP error message. */
				__( 'eSewa status check failed: %s. Payment not confirmed.', 'bookstore-nepal' ),
				$response->get_error_message()
			);
			return $result;
		}
		$code               = (int) wp_remote_retrieve_response_code( $response );
		$result['api_down'] = $code >= 500;
		if ( 200 !== $code ) {
			$result['message'] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'eSewa status check failed: HTTP %d. Payment not confirmed.', 'bookstore-nepal' ),
				$code
			);
			return $result;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$result['message'] = __( 'eSewa status check failed: unreadable response. Payment not confirmed.', 'bookstore-nepal' );
			return $result;
		}
		$result['failed'] = false;
		$status           = is_scalar( $body['status'] ?? null ) ? sanitize_text_field( (string) $body['status'] ) : '';
		$result['status'] = $status;
		if ( 'COMPLETE' !== $status ) {
			$result['outcome'] = '' === $status || in_array( $status, self::OPEN_STATUSES, true ) ? self::CHECK_RETRY : self::CHECK_UNPAID;
			$result['message'] = sprintf(
				/* translators: %s: status from eSewa. */
				__( 'eSewa status check returned %s, not COMPLETE. Payment not confirmed.', 'bookstore-nepal' ),
				'' === $status ? '(none)' : $status
			);
			return $result;
		}
		if ( ! is_scalar( $body['transaction_uuid'] ?? null ) || ! hash_equals( $uuid, (string) $body['transaction_uuid'] )
			|| (string) ( $body['product_code'] ?? '' ) !== $this->product_code() ) {
			$result['outcome'] = self::CHECK_MISMATCH;
			$result['message'] = __( 'eSewa status check rejected: transaction does not match this order.', 'bookstore-nepal' );
			return $result;
		}
		$result['outcome'] = self::CHECK_COMPLETE;
		$result['ref_id']  = sanitize_text_field( (string) ( $body['ref_id'] ?? '' ) );
		$result['amount']  = $body['total_amount'] ?? null;
		return $result;
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
	 * Background check of an order's eSewa transactions with the status API.
	 *
	 * - Unpaid eSewa order (pending or failed), for customers who did not come back from eSewa:
	 *   completed when eSewa reports one of its transactions COMPLETE for the order total; other
	 *   statuses get one note per transaction and status.
	 * - Paid eSewa order: its other transactions are checked for a second payment.
	 * - Order now paid by another method: its transactions are checked for a stray payment.
	 *
	 * Status requests are made without the lock; every decision is taken under the order lock on
	 * a fresh copy, like the callback, so the two never both complete the order.
	 *
	 * @param int $order_id Order ID.
	 * @return array{outcome:string,api_down:bool} outcome: a RECONCILE_* value; api_down: eSewa's
	 *         status API is unreachable (callers stop calling it for now).
	 */
	public function reconcile( int $order_id ): array {
		$result = array(
			'outcome'  => self::RECONCILE_SKIPPED,
			'api_down' => false,
		);
		$order  = $this->reload_order( $order_id );
		if ( null === $order || '' === $this->product_code() ) {
			return $result;
		}
		if ( self::ID !== $order->get_payment_method() ) {
			$extra              = $this->check_unexpected_payments( $order, false );
			$result['outcome']  = $extra['found'] ? self::RECONCILE_STRAY : self::RECONCILE_SKIPPED;
			$result['api_down'] = $extra['api_down'];
			return $result;
		}
		if ( $order->is_paid() ) {
			$result['outcome']  = self::RECONCILE_ALREADY_PAID;
			$result['api_down'] = $this->check_unexpected_payments( $order, true )['api_down'];
			return $result;
		}
		if ( ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return $result;
		}

		$checks   = array(); // uuid => check_status() result.
		$complete = '';      // UUID eSewa reports paid.
		// Newest first: the latest attempt is the likeliest to be paid.
		foreach ( array_reverse( $this->unchecked_uuids( $order ), true ) as $uuid => $amount ) {
			$uuid  = (string) $uuid;
			$check = $this->check_status( $uuid, $amount );
			if ( self::CHECK_COMPLETE === $check['outcome'] && ! $this->amounts_match( $check['amount'], $order ) ) {
				$check['outcome'] = self::CHECK_MISMATCH;
				$check['message'] = $this->amount_mismatch_message();
			}
			$checks[ $uuid ] = $check;
			if ( self::CHECK_COMPLETE === $check['outcome'] ) {
				$complete = $uuid;
				break;
			}
			$this->log_failed_check( $order_id, $uuid, $check );
			if ( $check['api_down'] ) {
				$result['api_down'] = true;
				break; // Unreachable: don't wait on it for the other transactions.
			}
		}
		if ( ! $checks ) {
			$result['outcome'] = self::RECONCILE_UNPAID;
			return $result;
		}
		$to_record = array_filter( $checks, static fn( $check ) => self::CHECK_RETRY !== $check['outcome'] );
		if ( '' === $complete && ! $to_record ) {
			// Only undecided answers or failed requests: nothing to write.
			$result['outcome'] = self::RECONCILE_RETRY;
			return $result;
		}

		// Don't wait: a request holding the lock (the callback) is deciding; the next run checks again.
		$lock = $this->lock( $order_id, 0 );
		if ( null === $lock ) {
			$result['outcome'] = self::RECONCILE_RETRY;
			return $result;
		}
		try {
			$result['outcome'] = $this->decide( $order_id, $checks, $complete );
		} finally {
			$this->unlock( $lock );
		}
		if ( self::RECONCILE_PAID === $result['outcome'] && ! $result['api_down'] ) {
			// The same check a paid order gets on later runs, now: another attempt may be paid too.
			$paid = $this->reload_order( $order_id );
			if ( null !== $paid ) {
				$result['api_down'] = $this->check_unexpected_payments( $paid, true )['api_down'];
			}
		}
		return $result;
	}

	/**
	 * Acts on status checks for an unpaid eSewa order. The caller holds the order lock.
	 *
	 * @param int    $order_id Order ID.
	 * @param array  $checks   uuid => check_status() result.
	 * @param string $complete UUID eSewa reports paid, or ''.
	 * @return string A RECONCILE_* outcome.
	 */
	private function decide( int $order_id, array $checks, string $complete ): string {
		// Fresh copy: the callback may have paid it, or the customer switched method, meanwhile.
		$order = $this->reload_order( $order_id );
		if ( null === $order ) {
			return self::RECONCILE_SKIPPED;
		}
		if ( self::ID !== $order->get_payment_method() ) {
			return self::RECONCILE_RETRY; // Switched meanwhile: the stray check takes it from here.
		}
		if ( $order->is_paid() ) {
			return self::RECONCILE_ALREADY_PAID;
		}
		if ( '' !== $complete ) {
			$check = $checks[ $complete ];
			if ( ! $order->has_status( array( 'pending', 'failed' ) ) || ! $this->amounts_match( $check['amount'], $order ) ) {
				// Changed while we asked eSewa (status set by staff, total edited): look again next run.
				return self::RECONCILE_RETRY;
			}
			// Remember the other answers quietly, so the second-payment check does not ask again.
			unset( $checks[ $complete ] );
			$this->record_statuses( $order, $checks, false );
			$this->complete_payment(
				$order,
				$complete,
				$check['ref_id'],
				$check['ref_id'],
				sprintf(
					/* translators: %s: eSewa reference id. */
					__( 'Payment confirmed by eSewa status check (customer did not return to the site). Ref: %s', 'bookstore-nepal' ),
					'' === $check['ref_id'] ? __( 'unknown', 'bookstore-nepal' ) : $check['ref_id']
				)
			);
			return self::RECONCILE_PAID;
		}

		if ( $this->record_statuses( $order, $checks, true ) ) {
			$order->save();
		}
		$outcomes = wp_list_pluck( $checks, 'outcome' );
		if ( in_array( self::CHECK_RETRY, $outcomes, true ) ) {
			return self::RECONCILE_RETRY;
		}
		return in_array( self::CHECK_MISMATCH, $outcomes, true ) ? self::RECONCILE_MISMATCH : self::RECONCILE_UNPAID;
	}

	/**
	 * Remembers each settled (non-retry) answer, once per transaction and status, with an order
	 * note when asked. The caller saves the order.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $checks     uuid => check_status() result.
	 * @param bool     $with_notes Add an order note for each newly seen answer.
	 * @return bool Whether anything was added.
	 */
	private function record_statuses( WC_Order $order, array $checks, bool $with_notes ): bool {
		$noted   = $order->get_meta( self::META_STATUS_NOTES );
		$noted   = is_array( $noted ) ? $noted : array();
		$changed = false;
		foreach ( $checks as $uuid => $check ) {
			if ( self::CHECK_RETRY === $check['outcome'] || self::CHECK_COMPLETE === $check['outcome'] ) {
				continue;
			}
			$key = self::CHECK_MISMATCH === $check['outcome'] ? 'MISMATCH' : $check['status'];
			if ( isset( $noted[ $uuid ][ $key ] ) ) {
				continue;
			}
			$noted[ $uuid ][ $key ] = gmdate( 'c' );
			$changed                = true;
			if ( $with_notes ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: eSewa transaction UUID, 2: result of the status check. */
						__( 'Automatic eSewa payment check for transaction %1$s: %2$s', 'bookstore-nepal' ),
						$uuid,
						$check['message']
					)
				);
			}
		}
		if ( $changed ) {
			$order->update_meta_data( self::META_STATUS_NOTES, $noted );
		}
		return $changed;
	}

	/**
	 * Looks for money eSewa took that the order does not account for: on a paid eSewa order, any
	 * of its other transactions that is COMPLETE (a second payment); on an order now paid by
	 * another method, any COMPLETE transaction (a stray payment). Each gets one refund note.
	 * Transactions already noted, or with a settled answer, are not asked again; when none are
	 * left, the order's flag is removed so the scheduled query stops returning it.
	 *
	 * @param WC_Order $order Order (fresh).
	 * @param bool     $paid  True for a paid eSewa order, false for an order paid by another method.
	 * @return array{found:bool,api_down:bool} found: a COMPLETE transaction was seen.
	 */
	private function check_unexpected_payments( WC_Order $order, bool $paid ): array {
		$result   = array(
			'found'    => false,
			'api_down' => false,
		);
		$order_id = $order->get_id();
		$todo     = $this->unexplained_uuids( $order, $paid );
		$flag     = $paid ? self::META_MULTIPLE : self::META_STRAY_CHECK;
		if ( ! $todo && ! $order->get_meta( $flag ) ) {
			return $result;
		}
		$checks = array();
		foreach ( array_reverse( $todo, true ) as $uuid => $amount ) {
			$uuid  = (string) $uuid;
			$check = $this->check_status( $uuid, $amount );
			$this->log_failed_check( $order_id, $uuid, $check );
			if ( self::CHECK_RETRY !== $check['outcome'] ) {
				$checks[ $uuid ] = $check;
			}
			if ( $check['api_down'] ) {
				$result['api_down'] = true;
				break;
			}
		}
		$result['found'] = in_array( self::CHECK_COMPLETE, wp_list_pluck( $checks, 'outcome' ), true );
		if ( $todo && ! $checks ) {
			return $result; // Only undecided answers: ask again next run.
		}
		$lock = $this->lock( $order_id, 0 );
		if ( null === $lock ) {
			return $result;
		}
		try {
			$order = $this->reload_order( $order_id );
			if ( null === $order || ( self::ID === $order->get_payment_method() ) !== $paid || ( $paid && ! $order->is_paid() ) ) {
				return $result; // Changed meanwhile; the next run looks again.
			}
			$changed = $this->record_statuses( $order, $checks, false );
			foreach ( $checks as $uuid => $check ) {
				if ( self::CHECK_COMPLETE !== $check['outcome'] || $order->get_meta( $this->verified_stray_key( $uuid ) ) ) {
					continue;
				}
				$order->update_meta_data( $this->verified_stray_key( $uuid ), gmdate( 'c' ) );
				$changed = true;
				if ( $paid ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: eSewa reference id, 2: eSewa transaction UUID. */
							__( 'eSewa took a second payment for this order — refund ref %1$s in the eSewa merchant portal. Transaction UUID: %2$s.', 'bookstore-nepal' ),
							'' === $check['ref_id'] ? __( 'unknown', 'bookstore-nepal' ) : $check['ref_id'],
							$uuid
						)
					);
				} else {
					$this->add_stray_note( $order, $uuid, $check['ref_id'], __( 'confirmed by eSewa status check', 'bookstore-nepal' ) );
				}
			}
			if ( ! $this->unexplained_uuids( $order, $paid ) && $order->get_meta( $flag ) ) {
				$order->delete_meta_data( $flag );
				$changed = true;
			}
			if ( $changed ) {
				$order->save();
			}
		} finally {
			$this->unlock( $lock );
		}
		return $result;
	}

	/**
	 * Transactions of an order that eSewa may hold unexpected money for and that are still
	 * unanswered: all remembered UUIDs except the one that paid the order, those already noted
	 * as a stray or second payment, and those with a settled status.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $paid  True for a paid eSewa order.
	 * @return array<string,string> uuid => amount, oldest first.
	 */
	private function unexplained_uuids( WC_Order $order, bool $paid ): array {
		$paid_uuid = '';
		if ( $paid ) {
			// Orders paid before the paying UUID was recorded were paid by their current UUID.
			$paid_uuid = (string) $order->get_meta( self::META_PAID_UUID );
			$paid_uuid = '' !== $paid_uuid ? $paid_uuid : (string) $order->get_meta( self::META_UUID );
		}
		$noted = $order->get_meta( self::META_STATUS_NOTES );
		$noted = is_array( $noted ) ? $noted : array();
		$out   = array();
		foreach ( $this->remembered_uuids( $order ) as $uuid => $amount ) {
			$uuid = (string) $uuid;
			if ( $uuid === $paid_uuid || ! empty( $noted[ $uuid ] ) || $order->get_meta( $this->verified_stray_key( $uuid ) ) ) {
				continue;
			}
			$out[ $uuid ] = $amount;
		}
		return $out;
	}

	/**
	 * Logs a status request that got no usable answer, for the debug log.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $uuid     Transaction UUID.
	 * @param array  $check    check_status() result.
	 */
	private function log_failed_check( int $order_id, string $uuid, array $check ): void {
		if ( $check['failed'] ) {
			error_log( sprintf( 'Bookstore Nepal eSewa: automatic check of order %d (transaction %s) failed: %s', $order_id, $uuid, $check['message'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Transactions of an unpaid eSewa order still worth asking eSewa about: not in a final status.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string> uuid => amount, oldest first.
	 */
	private function unchecked_uuids( WC_Order $order ): array {
		$noted = $order->get_meta( self::META_STATUS_NOTES );
		$noted = is_array( $noted ) ? $noted : array();
		$out   = array();
		foreach ( $this->remembered_uuids( $order ) as $uuid => $amount ) {
			if ( array_intersect( self::FINAL_STATUSES, array_keys( (array) ( $noted[ $uuid ] ?? array() ) ) ) ) {
				continue;
			}
			$out[ $uuid ] = $amount;
		}
		return $out;
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
	 * @param int      $order_id Order ID.
	 * @param int|null $timeout  Seconds to wait; null for the callback default.
	 * @return string|null Lock name when held.
	 */
	private function lock( int $order_id, ?int $timeout = null ): ?string {
		global $wpdb;
		$name = 'bsn_esewa_' . $order_id;
		if ( null === $timeout ) {
			/**
			 * Seconds to wait for another callback on the same order to finish.
			 *
			 * @param int $timeout Seconds.
			 */
			$timeout = (int) apply_filters( 'bsn_esewa_lock_timeout', self::STATUS_TIMEOUT + 5 );
		}
		$timeout = max( 0, $timeout );
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
