<?php
/**
 * Khalti Web Checkout (KPG-2) payment gateway.
 *
 * Flow (https://docs.khalti.com/khalti-epayment/): process_payment() asks Khalti's initiate API
 * for a payment (pidx + payment_url) with a server-side call, remembers the pidx on the order
 * and sends the customer to payment_url. Khalti sends the customer back to the wc-api return
 * URL with pidx, status, amount, etc. in the query string. None of those are trusted: the pidx
 * must be one minted for this order, and only Khalti's lookup API answering Completed for that
 * pidx with the order total (in paisa) marks the order paid.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Gateway;

use WC_Order;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Khalti wallet, mobile banking and card payments.
 */
class KhaltiGateway extends WC_Payment_Gateway {
	use LocksOrders;

	// Not "khalti": that id belongs to the third-party integrate-khalti-in-wc-store plugin.
	public const ID         = 'bsn_khalti';
	public const API_ACTION = 'bsn_khalti';
	// The pidx of the latest attempt.
	public const META_PIDX = '_bsn_khalti_pidx';
	// Every pidx minted for the order (pidx => amount in paisa), oldest first, so the return
	// handler and the reconciler accept a payment made on an earlier attempt.
	public const META_PIDXS = '_bsn_khalti_pidxs';
	// The pidx that paid the order (any other paid one is a second payment).
	public const META_PAID_PIDX    = '_bsn_khalti_paid_pidx';
	public const META_STRAY_PREFIX = '_bsn_khalti_stray_';
	// pidx => [ status => time ] for settled lookup answers already recorded (one note each).
	public const META_STATUS_NOTES = '_bsn_khalti_status_notes';
	// pidx => [ status => time ] for "not confirmed yet" answers noted by the return handler.
	public const META_RETURN_NOTES = '_bsn_khalti_return_notes';
	// Set while an order with Khalti payments is paid by another method, for the stray check.
	public const META_STRAY_CHECK = '_bsn_khalti_stray_check';
	// Set once an order has more than one pidx, for the second-payment check.
	public const META_MULTIPLE = '_bsn_khalti_multiple';

	public const BASE_URL_TEST = 'https://dev.khalti.com';
	public const BASE_URL_LIVE = 'https://khalti.com';
	public const INITIATE_PATH = '/api/v2/epayment/initiate/';
	public const LOOKUP_PATH   = '/api/v2/epayment/lookup/';
	public const TIMEOUT       = 8;

	public const STATUS_COMPLETED = 'Completed';
	// Lookup statuses that may still turn into Completed.
	public const OPEN_STATUSES = array( 'Pending', 'Initiated' );
	// Lookup statuses after which a pidx is not checked again.
	public const FINAL_STATUSES = array( 'User canceled', 'Expired', 'Refunded', 'Partially Refunded' );
	// Final statuses meaning the customer did not pay (the order may be retried).
	public const CANCELLED_STATUSES = array( 'User canceled', 'Expired' );

	// lookup() outcomes.
	private const CHECK_COMPLETE = 'complete';
	private const CHECK_RETRY    = 'retry';
	private const CHECK_UNPAID   = 'unpaid';
	private const CHECK_MISMATCH = 'mismatch';

	// reconcile() outcomes (same meanings as EsewaGateway's).
	public const RECONCILE_PAID         = 'paid';
	public const RECONCILE_ALREADY_PAID = 'already_paid';
	public const RECONCILE_RETRY        = 'retry';
	public const RECONCILE_MISMATCH     = 'mismatch';
	public const RECONCILE_UNPAID       = 'unpaid';
	public const RECONCILE_STRAY        = 'stray';
	public const RECONCILE_SKIPPED      = 'skipped';
	// Bounds lookup calls per order per reconciliation run.
	public const MAX_REMEMBERED_PIDXS = 20;

	/**
	 * Hooks the gateway into WooCommerce.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateway' ) );
		add_action( 'woocommerce_api_' . self::API_ACTION, array( self::class, 'dispatch_return' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( self::class, 'register_blocks' ) );
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
		$registry->register( new KhaltiBlocks() );
	}

	/**
	 * Handles the wc-api request: redirects and exits.
	 */
	public static function dispatch_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Third-party redirect; authenticity comes from the order key and Khalti's lookup API.
		$redirect = self::instance()->handle_return( wp_unslash( $_GET ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * WooCommerce's gateway object (built once, with the saved settings), or a new one.
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
		$this->method_title       = __( 'Khalti', 'bookstore-nepal' );
		$this->method_description = __( 'Customers pay with Khalti (wallet, mobile banking or card). Payments are confirmed with Khalti\'s lookup API before the order is marked paid.', 'bookstore-nepal' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings. Keys are stored in the database only.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'bookstore-nepal' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Khalti', 'bookstore-nepal' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'   => __( 'Title', 'bookstore-nepal' ),
				'type'    => 'text',
				'default' => __( 'Khalti', 'bookstore-nepal' ),
			),
			'description'     => array(
				'title'   => __( 'Description', 'bookstore-nepal' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with your Khalti wallet, mobile banking or card. You will be sent to Khalti to confirm.', 'bookstore-nepal' ),
			),
			'testmode'        => array(
				'title'       => __( 'Test mode', 'bookstore-nepal' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the Khalti sandbox (dev.khalti.com) with the test secret key', 'bookstore-nepal' ),
				'default'     => 'yes',
				'description' => __( 'While test mode is on, the live secret key is ignored.', 'bookstore-nepal' ),
			),
			'secret_key'      => array(
				'title'       => __( 'Live secret key', 'bookstore-nepal' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Live secret key from the Khalti merchant dashboard (admin.khalti.com). Stored in the database only.', 'bookstore-nepal' ),
			),
			'test_secret_key' => array(
				'title'       => __( 'Test secret key', 'bookstore-nepal' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Secret key from the Khalti sandbox dashboard (test-admin.khalti.com).', 'bookstore-nepal' ),
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
	 * Secret key for the current mode.
	 */
	private function secret(): string {
		return trim( (string) $this->get_option( $this->is_test_mode() ? 'test_secret_key' : 'secret_key' ) );
	}

	/**
	 * Whether the key for the current mode is set.
	 */
	public function has_key(): bool {
		return '' !== $this->secret();
	}

	/**
	 * API base URL for the current mode.
	 */
	private function base_url(): string {
		return $this->is_test_mode() ? self::BASE_URL_TEST : self::BASE_URL_LIVE;
	}

	/**
	 * Request headers for Khalti's API. Never logged.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		return array(
			'Authorization' => 'Key ' . $this->secret(),
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Available only for NPR stores with the key for the current mode.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( 'NPR' !== get_woocommerce_currency() ) {
			return false;
		}
		return $this->has_key();
	}

	/**
	 * Starts a payment attempt: asks Khalti for a pidx, remembers it, sends the customer to Khalti.
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
		$order_id  = $order->get_id();
		$initiated = $this->initiate( $order );
		if ( is_string( $initiated ) ) {
			return $this->initiate_failed( $order, $initiated );
		}
		// Under the lock on a fresh copy, so a concurrent attempt or return cannot drop this pidx
		// from the list: a pidx missing from it could never be verified.
		$lock = $this->lock( $order_id );
		if ( null === $lock ) {
			return $this->initiate_failed( $order, __( 'the order is busy in another request', 'bookstore-nepal' ) );
		}
		try {
			$order = $this->reload_order( $order_id );
			if ( null === $order ) {
				wc_add_notice( __( 'Order not found.', 'bookstore-nepal' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$this->remember_pidx( $order, $initiated['pidx'] );
			$order->save();
		} finally {
			$this->unlock( $lock );
		}
		return array(
			'result'   => 'success',
			'redirect' => $initiated['payment_url'],
		);
	}

	/**
	 * Records a failed initiate and tells the customer. The order stays pending.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Why it failed (no secrets).
	 * @return array
	 */
	private function initiate_failed( WC_Order $order, string $reason ): array {
		$order->add_order_note(
			sprintf(
				/* translators: %s: reason from Khalti or the HTTP layer. */
				__( 'Khalti payment could not be started: %s. The customer was asked to try again.', 'bookstore-nepal' ),
				$reason
			)
		);
		error_log( sprintf( 'Bookstore Nepal Khalti: initiate failed for order %d: %s', $order->get_id(), $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		wc_add_notice( __( 'We could not start your Khalti payment. Please try again in a moment or choose another payment method.', 'bookstore-nepal' ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Calls Khalti's initiate API.
	 *
	 * @param WC_Order $order Order.
	 * @return array{pidx:string,payment_url:string}|string The payment, or why it failed.
	 */
	private function initiate( WC_Order $order ) {
		$body     = array(
			'return_url'          => $this->return_url( $order ),
			'website_url'         => home_url( '/' ),
			'amount'              => $this->order_paisa( $order ),
			'purchase_order_id'   => (string) $order->get_id(),
			/* translators: %s: order number. */
			'purchase_order_name' => sprintf( __( 'Order #%s', 'bookstore-nepal' ), $order->get_order_number() ),
		);
		$customer = $this->customer_info( $order );
		if ( $customer ) {
			$body['customer_info'] = $customer;
		}
		$response = wp_remote_post(
			$this->base_url() . self::INITIATE_PATH,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code   = (int) wp_remote_retrieve_response_code( $response );
		$answer = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$detail = $this->error_detail( $answer );
			return '' === $detail ? sprintf( 'HTTP %d', $code ) : sprintf( 'HTTP %d (%s)', $code, $detail );
		}
		$pidx = is_array( $answer ) && is_string( $answer['pidx'] ?? null ) ? $answer['pidx'] : '';
		$url  = is_array( $answer ) && is_string( $answer['payment_url'] ?? null ) ? $answer['payment_url'] : '';
		if ( ! $this->is_valid_pidx( $pidx ) ) {
			return __( 'Khalti returned no payment id', 'bookstore-nepal' );
		}
		if ( ! $this->is_khalti_url( $url ) ) {
			return __( 'Khalti returned an unexpected payment URL', 'bookstore-nepal' );
		}
		return array(
			'pidx'        => $pidx,
			'payment_url' => $url,
		);
	}

	/**
	 * Whether a value looks like a pidx (Khalti's are short alphanumeric strings).
	 *
	 * @param string $pidx Candidate.
	 */
	private function is_valid_pidx( string $pidx ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $pidx );
	}

	/**
	 * Whether a payment URL is an https URL on khalti.com, so a bad answer cannot send the
	 * customer elsewhere.
	 *
	 * @param string $url URL.
	 */
	private function is_khalti_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return 'khalti.com' === $host || str_ends_with( $host, '.khalti.com' );
	}

	/**
	 * A short, sanitised summary of a Khalti error body ({"detail": ...} or field => [messages]).
	 *
	 * @param mixed $answer Decoded body.
	 */
	private function error_detail( $answer ): string {
		if ( ! is_array( $answer ) ) {
			return '';
		}
		$messages = array();
		foreach ( $answer as $field => $value ) {
			if ( 'error_key' === $field || 'status_code' === $field ) {
				continue;
			}
			foreach ( (array) $value as $message ) {
				if ( is_scalar( $message ) ) {
					$messages[] = 'detail' === $field ? (string) $message : $field . ': ' . $message;
				}
			}
		}
		return substr( sanitize_text_field( implode( '; ', $messages ) ), 0, 200 );
	}

	/**
	 * Optional customer details for Khalti. The phone is sent only as a 10-digit Nepali mobile
	 * number, so an unusual billing phone cannot make Khalti refuse the payment.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	private function customer_info( WC_Order $order ): array {
		$info  = array();
		$name  = trim( $order->get_formatted_billing_full_name() );
		$email = (string) $order->get_billing_email();
		$phone = preg_replace( '/\D/', '', (string) $order->get_billing_phone() );
		if ( 13 === strlen( $phone ) && 0 === strpos( $phone, '977' ) ) {
			$phone = substr( $phone, 3 );
		}
		if ( '' !== $name ) {
			$info['name'] = $name;
		}
		if ( '' !== $email ) {
			$info['email'] = $email;
		}
		if ( preg_match( '/^9[678]\d{8}$/', $phone ) ) {
			$info['phone'] = $phone;
		}
		return $info;
	}

	/**
	 * Return URL Khalti sends the customer back to (Khalti appends its own parameters).
	 *
	 * @param WC_Order $order Order.
	 */
	public function return_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( self::API_ACTION )
		);
	}

	/**
	 * Order total in paisa.
	 *
	 * @param WC_Order $order Order.
	 */
	private function order_paisa( WC_Order $order ): int {
		return (int) round( (float) $order->get_total() * 100 );
	}

	/**
	 * Makes a pidx the order's current one and remembers it with its amount. The caller saves.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $pidx  New pidx.
	 */
	private function remember_pidx( WC_Order $order, string $pidx ): void {
		$remembered = $this->remembered_pidxs( $order );
		unset( $remembered[ $pidx ] );
		$remembered[ $pidx ] = (string) $this->order_paisa( $order );
		$remembered          = array_slice( $remembered, -self::MAX_REMEMBERED_PIDXS, null, true );
		$order->update_meta_data( self::META_PIDX, $pidx );
		$order->update_meta_data( self::META_PIDXS, $remembered );
		if ( count( $remembered ) > 1 ) {
			$order->update_meta_data( self::META_MULTIPLE, '1' );
		}
	}

	/**
	 * Every pidx minted for the order (pidx => amount in paisa), oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	public function remembered_pidxs( WC_Order $order ): array {
		$stored     = $order->get_meta( self::META_PIDXS );
		$remembered = array();
		foreach ( is_array( $stored ) ? $stored : array() as $pidx => $amount ) {
			if ( is_string( $pidx ) && '' !== $pidx && is_scalar( $amount ) ) {
				$remembered[ $pidx ] = (string) $amount;
			}
		}
		$current = (string) $order->get_meta( self::META_PIDX );
		if ( '' !== $current && ! isset( $remembered[ $current ] ) ) {
			$remembered[ $current ] = (string) $this->order_paisa( $order );
		}
		return $remembered;
	}

	/**
	 * The remembered pidx of this order equal to $candidate (constant-time compare), or ''.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $candidate pidx from the query string.
	 */
	private function remembered_pidx_matching( WC_Order $order, string $candidate ): string {
		$found = '';
		foreach ( array_keys( $this->remembered_pidxs( $order ) ) as $pidx ) {
			$pidx = (string) $pidx;
			if ( '' !== $candidate && hash_equals( $pidx, $candidate ) ) {
				$found = $pidx;
			}
		}
		return $found;
	}

	/**
	 * Normalises a query array: a redirect may append "?pidx=..." to a URL that already has a
	 * query string, so a value may carry a second query string.
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
	 * Handles a customer returning from Khalti. Only the order key, a pidx minted for the order
	 * and Khalti's lookup API are trusted; status, amount and transaction_id in the query are not.
	 *
	 * @param array $query Query args (unslashed $_GET).
	 * @return string URL to redirect the customer to.
	 */
	public function handle_return( array $query ): string {
		$query    = $this->normalise_query( $query );
		$order    = wc_get_order( absint( $query['order_id'] ?? 0 ) );
		$key      = $query['key'] ?? '';
		$pidx     = $query['pidx'] ?? '';
		$no_match = __( 'We could not match this Khalti payment to an order. Please contact us if money was deducted.', 'bookstore-nepal' );

		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wc_add_notice( $no_match, 'error' );
			return wc_get_cart_url();
		}
		$known = $this->remembered_pidx_matching( $order, $pidx );

		if ( self::ID !== $order->get_payment_method() ) {
			if ( '' === $known ) {
				wc_add_notice( $no_match, 'error' );
				return wc_get_cart_url();
			}
			// The customer started a Khalti payment, then switched this order to another method.
			$this->check_stray_return( $order->get_id(), $known );
			wc_add_notice( $this->switched_notice(), 'error' );
			return wc_get_cart_url();
		}

		if ( '' === $known ) {
			if ( '' !== $pidx ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: pidx from the return URL. */
						__( 'Khalti return rejected: pidx %s does not belong to this order. Payment not confirmed.', 'bookstore-nepal' ),
						substr( sanitize_text_field( $pidx ), 0, 64 )
					)
				);
			}
			wc_add_notice( $no_match, 'error' );
			return wc_get_cart_url();
		}

		$order_id = $order->get_id();
		$lock     = $this->lock( $order_id );
		if ( null === $lock ) {
			// Another request for this order is still running (e.g. waiting on the lookup API).
			error_log( sprintf( 'Bookstore Nepal Khalti: could not lock order %d for the return; another request is still processing it.', $order_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wc_add_notice( $this->confirming_notice(), 'notice' );
			return $order->get_checkout_order_received_url();
		}
		try {
			// Re-read from the database, not this request's caches, so a request that waited
			// for the lock sees what the previous holder committed.
			$order = $this->reload_order( $order_id );
			if ( null === $order ) {
				wc_add_notice( $no_match, 'error' );
				return wc_get_cart_url();
			}
			if ( $order->is_paid() ) {
				return $order->get_checkout_order_received_url();
			}
			if ( self::ID !== $order->get_payment_method() ) {
				wc_add_notice( $this->switched_notice(), 'error' ); // Switched meanwhile; the stray check covers it.
				return wc_get_cart_url();
			}
			return $this->act_on_return( $order, $known, $this->lookup( $known ) );
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Customer notice for a return to an order no longer paid with Khalti.
	 */
	private function switched_notice(): string {
		return __( 'This order is no longer set to be paid with Khalti. If money was deducted from your Khalti account, please contact us and we will refund it.', 'bookstore-nepal' );
	}

	/**
	 * Customer notice while Khalti has not confirmed the payment yet.
	 */
	private function confirming_notice(): string {
		return __( 'We’re confirming your Khalti payment. Please refresh this page in a moment, or contact us if it does not update.', 'bookstore-nepal' );
	}

	/**
	 * Acts on the lookup answer for a return. The caller holds the lock; the order is fresh,
	 * unpaid and still a Khalti order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $pidx  pidx (one of the order's).
	 * @param array    $check lookup() result.
	 * @return string Redirect URL.
	 */
	private function act_on_return( WC_Order $order, string $pidx, array $check ): string {
		if ( self::CHECK_COMPLETE === $check['outcome'] && ! $this->amounts_match( $check['amount'], $order ) ) {
			$check['outcome'] = self::CHECK_MISMATCH;
			$check['message'] = $this->amount_mismatch_message();
		}
		switch ( $check['outcome'] ) {
			case self::CHECK_COMPLETE:
				$this->complete_payment(
					$order,
					$pidx,
					$check['transaction_id'],
					sprintf(
						/* translators: 1: Khalti pidx, 2: Khalti transaction id. */
						__( 'Khalti payment confirmed by lookup. pidx: %1$s, transaction: %2$s.', 'bookstore-nepal' ),
						$pidx,
						'' === $check['transaction_id'] ? __( 'unknown', 'bookstore-nepal' ) : $check['transaction_id']
					)
				);
				return $order->get_checkout_order_received_url();

			case self::CHECK_UNPAID:
				if ( in_array( $check['status'], self::CANCELLED_STATUSES, true ) ) {
					if ( $order->has_status( 'pending' ) ) {
						$this->record_statuses( $order, array( $pidx => $check ), false );
						$order->update_status(
							'failed',
							sprintf(
								/* translators: 1: Khalti status, 2: Khalti pidx. */
								__( 'Khalti payment was not completed: Khalti lookup returned %1$s for pidx %2$s.', 'bookstore-nepal' ),
								$check['status'],
								$pidx
							)
						);
					} elseif ( $this->record_statuses( $order, array( $pidx => $check ), true ) ) {
						$order->save();
					}
					wc_add_notice( __( 'Khalti payment was not completed. You can try again or choose another payment method.', 'bookstore-nepal' ), 'error' );
					return wc_get_cart_url();
				}
				// Refunded or partially refunded: never paid.
				if ( $this->record_statuses( $order, array( $pidx => $check ), true ) ) {
					$order->save();
				}
				return $this->not_confirmed( $order );

			case self::CHECK_MISMATCH:
				if ( $this->record_statuses( $order, array( $pidx => $check ), true ) ) {
					$order->save();
				}
				return $this->not_confirmed( $order );

			default: // Retry: Pending, Initiated, no usable answer.
				$this->log_failed_check( $order->get_id(), $pidx, $check );
				$this->note_return_once( $order, $pidx, $check );
				wc_add_notice( $this->confirming_notice(), 'notice' );
				return $order->get_checkout_order_received_url();
		}
	}

	/**
	 * Tells the customer the payment was not confirmed and sends them to the cart.
	 *
	 * @param WC_Order $order Order (left unpaid).
	 */
	private function not_confirmed( WC_Order $order ): string {
		wc_add_notice(
			sprintf(
				/* translators: %s: order number. */
				__( 'We could not confirm your Khalti payment, so order #%s has not been marked as paid. You can try again or choose another payment method. If money was deducted from your Khalti account, please contact us.', 'bookstore-nepal' ),
				$order->get_order_number()
			),
			'error'
		);
		return wc_get_cart_url();
	}

	/**
	 * Notes an unconfirmed return once per pidx and answer, however often the customer comes back.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $pidx  pidx.
	 * @param array    $check lookup() result.
	 */
	private function note_return_once( WC_Order $order, string $pidx, array $check ): void {
		$noted = $order->get_meta( self::META_RETURN_NOTES );
		$noted = is_array( $noted ) ? $noted : array();
		$key   = '' === $check['status'] ? 'failed' : $check['status'];
		if ( isset( $noted[ $pidx ][ $key ] ) ) {
			return;
		}
		$noted[ $pidx ][ $key ] = gmdate( 'c' );
		$order->update_meta_data( self::META_RETURN_NOTES, array_intersect_key( $noted, $this->remembered_pidxs( $order ) ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: Khalti pidx, 2: result of the lookup. */
				__( 'Customer returned from Khalti (pidx %1$s), payment not confirmed yet: %2$s The automatic check will complete the order if Khalti confirms the payment.', 'bookstore-nepal' ),
				$pidx,
				$check['message']
			)
		);
		$order->save();
	}

	/**
	 * Marks an order paid. Callers hold the order lock and have re-read the order and checked
	 * it is unpaid, so payment_complete() (stock, emails) runs once per order.
	 *
	 * @param WC_Order $order          Order (fresh, unpaid, locked).
	 * @param string   $pidx           pidx that paid it.
	 * @param string   $transaction_id Khalti transaction id.
	 * @param string   $note           Order note.
	 */
	private function complete_payment( WC_Order $order, string $pidx, string $transaction_id, string $note ): void {
		$order->update_meta_data( self::META_PAID_PIDX, $pidx );
		$order->add_order_note( $note );
		$order->payment_complete( $transaction_id );
	}

	/**
	 * Return to an order switched to another method: if Khalti says the pidx is Completed, adds
	 * one stray-payment note (the order is never marked paid).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $pidx     One of the order's pidx values.
	 */
	private function check_stray_return( int $order_id, string $pidx ): void {
		$order = $this->reload_order( $order_id );
		if ( null === $order || $order->get_meta( $this->verified_stray_key( $pidx ) ) ) {
			return;
		}
		$check = $this->lookup( $pidx );
		$this->log_failed_check( $order_id, $pidx, $check );
		if ( self::CHECK_COMPLETE !== $check['outcome'] ) {
			return; // Not paid, or Khalti did not answer: the scheduled stray check asks again.
		}
		$lock = $this->lock( $order_id );
		if ( null === $lock ) {
			return;
		}
		try {
			$order = $this->reload_order( $order_id );
			if ( null === $order || self::ID === $order->get_payment_method() || $order->get_meta( $this->verified_stray_key( $pidx ) ) ) {
				return;
			}
			$order->update_meta_data( $this->verified_stray_key( $pidx ), gmdate( 'c' ) );
			$this->add_stray_note( $order, $pidx, $check['transaction_id'] );
			$order->save();
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Meta key marking a Completed pidx as already noted (stray or second payment).
	 *
	 * @param string $pidx pidx.
	 */
	private function verified_stray_key( string $pidx ): string {
		return self::META_STRAY_PREFIX . md5( $pidx . '|verified' );
	}

	/**
	 * Adds the stray-payment note. The caller saves the order.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $pidx           pidx.
	 * @param string   $transaction_id Khalti transaction id.
	 */
	private function add_stray_note( WC_Order $order, string $pidx, string $transaction_id ): void {
		$order->add_order_note(
			sprintf(
				/* translators: 1: Khalti pidx, 2: Khalti transaction id or "unknown". */
				__( 'Khalti confirmed a payment for this order after it was switched to another payment method. pidx: %1$s, transaction: %2$s. The order was not marked paid: check the payment and refund in the Khalti merchant portal if needed.', 'bookstore-nepal' ),
				$pidx,
				'' === $transaction_id ? __( 'unknown', 'bookstore-nepal' ) : $transaction_id
			)
		);
	}

	/**
	 * Asks Khalti's lookup API about one pidx. Does not compare the amount with an order;
	 * callers do, against the order they are about to complete.
	 *
	 * Per Khalti's docs, Completed, Pending, Initiated, Refunded and Partially Refunded come
	 * with HTTP 200; Expired and User canceled with HTTP 400 and the same body.
	 *
	 * @param string $pidx pidx.
	 * @return array{outcome:string,status:string,message:string,transaction_id:string,amount:mixed,failed:bool,api_down:bool}
	 *         outcome: complete, retry (Pending/Initiated/unknown status, or no usable answer),
	 *         unpaid (a final status) or mismatch (an answer about another pidx).
	 *         failed: no usable answer. api_down: transport failure, 5xx or a rejected key, i.e.
	 *         no lookup can succeed now (other 4xx or a malformed answer concern only this pidx).
	 */
	private function lookup( string $pidx ): array {
		$result   = array(
			'outcome'        => self::CHECK_RETRY,
			'status'         => '',
			'message'        => '',
			'transaction_id' => '',
			'amount'         => null,
			'failed'         => true,
			'api_down'       => true,
		);
		$response = wp_remote_post(
			$this->base_url() . self::LOOKUP_PATH,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( array( 'pidx' => $pidx ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$result['message'] = sprintf(
				/* translators: %s: HTTP error message. */
				__( 'Khalti lookup failed: %s. Payment not confirmed.', 'bookstore-nepal' ),
				$response->get_error_message()
			);
			return $result;
		}
		$code               = (int) wp_remote_retrieve_response_code( $response );
		$result['api_down'] = $code >= 500 || 401 === $code || 403 === $code;
		$body               = json_decode( wp_remote_retrieve_body( $response ), true );
		$status             = is_array( $body ) && is_scalar( $body['status'] ?? null ) ? sanitize_text_field( (string) $body['status'] ) : '';
		$answered           = '' !== $status && ( 200 === $code || ( 400 === $code && in_array( $status, self::CANCELLED_STATUSES, true ) ) );
		if ( ! $answered ) {
			$detail            = $this->error_detail( $body );
			$result['message'] = 200 === $code
				? __( 'Khalti lookup failed: unreadable response. Payment not confirmed.', 'bookstore-nepal' )
				: sprintf(
					/* translators: 1: HTTP status code, 2: Khalti's error detail. */
					__( 'Khalti lookup failed: HTTP %1$d%2$s. Payment not confirmed.', 'bookstore-nepal' ),
					$code,
					'' === $detail ? '' : ' (' . $detail . ')'
				);
			return $result;
		}
		$result['failed']   = false;
		$result['api_down'] = false;
		$result['status']   = $status;
		if ( ! is_scalar( $body['pidx'] ?? null ) || ! hash_equals( $pidx, (string) $body['pidx'] ) ) {
			$result['outcome'] = self::CHECK_MISMATCH;
			$result['message'] = __( 'Khalti lookup rejected: the answer does not match this transaction.', 'bookstore-nepal' );
			return $result;
		}
		if ( self::STATUS_COMPLETED === $status && empty( $body['refunded'] ) ) {
			$result['outcome']        = self::CHECK_COMPLETE;
			$result['transaction_id'] = is_scalar( $body['transaction_id'] ?? null ) ? sanitize_text_field( (string) $body['transaction_id'] ) : '';
			$result['amount']         = $body['total_amount'] ?? null;
			return $result;
		}
		$final             = in_array( $status, self::FINAL_STATUSES, true );
		$result['outcome'] = $final ? self::CHECK_UNPAID : self::CHECK_RETRY;
		$result['message'] = sprintf(
			/* translators: %s: status from Khalti. */
			__( 'Khalti lookup returned %s, not Completed. Payment not confirmed.', 'bookstore-nepal' ),
			self::STATUS_COMPLETED === $status ? __( 'Completed but refunded', 'bookstore-nepal' ) : $status
		);
		if ( self::STATUS_COMPLETED === $status ) {
			// Completed yet flagged refunded: treat as refunded.
			$result['outcome'] = self::CHECK_UNPAID;
			$result['status']  = 'Refunded';
		}
		return $result;
	}

	/**
	 * Compares a Khalti amount (paisa, e.g. 50000 or "50000") with the order total.
	 *
	 * @param mixed    $amount Amount from Khalti.
	 * @param WC_Order $order  Order.
	 */
	private function amounts_match( $amount, WC_Order $order ): bool {
		if ( ! is_scalar( $amount ) || is_bool( $amount ) ) {
			return false;
		}
		$amount = trim( (string) $amount );
		if ( ! preg_match( '/^\d+(\.0+)?$/', $amount ) ) {
			return false;
		}
		$total = $this->order_paisa( $order );
		return $total > 0 && (int) $amount === $total;
	}

	/**
	 * Rejection reason for a Completed lookup whose amount differs from the order total.
	 */
	private function amount_mismatch_message(): string {
		return __( 'Khalti lookup rejected: amount mismatch. Payment not confirmed.', 'bookstore-nepal' );
	}

	/**
	 * Background check of an order's Khalti payments with the lookup API (see EsewaGateway::reconcile()).
	 *
	 * - Unpaid Khalti order (pending or failed): completed when Khalti reports one of its pidx
	 *   Completed for the order total; other settled statuses get one note per pidx and status.
	 * - Paid Khalti order: its other pidx values are checked for a second payment.
	 * - Order now paid by another method: its pidx values are checked for a stray payment.
	 *
	 * Lookups are made without the lock; every decision is taken under the order lock on a fresh
	 * copy, like the return handler, so the two never both complete the order.
	 *
	 * @param int $order_id Order ID.
	 * @return array{outcome:string,api_down:bool}
	 */
	public function reconcile( int $order_id ): array {
		$result = array(
			'outcome'  => self::RECONCILE_SKIPPED,
			'api_down' => false,
		);
		$order  = $this->reload_order( $order_id );
		if ( null === $order || ! $this->has_key() ) {
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

		$checks   = array(); // pidx => lookup() result.
		$complete = '';      // pidx Khalti reports paid.
		// Newest first: the latest attempt is the likeliest to be paid.
		foreach ( array_reverse( $this->unchecked_pidxs( $order ), true ) as $pidx => $amount ) {
			$pidx  = (string) $pidx;
			$check = $this->lookup( $pidx );
			if ( self::CHECK_COMPLETE === $check['outcome'] && ! $this->amounts_match( $check['amount'], $order ) ) {
				$check['outcome'] = self::CHECK_MISMATCH;
				$check['message'] = $this->amount_mismatch_message();
			}
			$checks[ $pidx ] = $check;
			if ( self::CHECK_COMPLETE === $check['outcome'] ) {
				$complete = $pidx;
				break;
			}
			$this->log_failed_check( $order_id, $pidx, $check );
			if ( $check['api_down'] ) {
				$result['api_down'] = true;
				break;
			}
		}
		if ( ! $checks ) {
			$result['outcome'] = self::RECONCILE_UNPAID;
			return $result;
		}
		$to_record = array_filter( $checks, static fn( $check ) => self::CHECK_RETRY !== $check['outcome'] );
		if ( '' === $complete && ! $to_record ) {
			$result['outcome'] = self::RECONCILE_RETRY;
			return $result;
		}

		// Don't wait: a request holding the lock (the return) is deciding; the next run checks again.
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
			$paid = $this->reload_order( $order_id );
			if ( null !== $paid ) {
				$result['api_down'] = $this->check_unexpected_payments( $paid, true )['api_down'];
			}
		}
		return $result;
	}

	/**
	 * Acts on lookups for an unpaid Khalti order. The caller holds the order lock.
	 *
	 * @param int    $order_id Order ID.
	 * @param array  $checks   pidx => lookup() result.
	 * @param string $complete pidx Khalti reports paid, or ''.
	 * @return string A RECONCILE_* outcome.
	 */
	private function decide( int $order_id, array $checks, string $complete ): string {
		$order = $this->reload_order( $order_id );
		if ( null === $order ) {
			return self::RECONCILE_SKIPPED;
		}
		if ( self::ID !== $order->get_payment_method() ) {
			return self::RECONCILE_RETRY;
		}
		if ( $order->is_paid() ) {
			return self::RECONCILE_ALREADY_PAID;
		}
		if ( '' !== $complete ) {
			$check = $checks[ $complete ];
			if ( ! $order->has_status( array( 'pending', 'failed' ) ) || ! $this->amounts_match( $check['amount'], $order ) ) {
				return self::RECONCILE_RETRY;
			}
			unset( $checks[ $complete ] );
			$this->record_statuses( $order, $checks, false );
			$this->complete_payment(
				$order,
				$complete,
				$check['transaction_id'],
				sprintf(
					/* translators: 1: Khalti transaction id, 2: Khalti pidx. */
					__( 'Payment confirmed by Khalti lookup (customer did not return to the site). Transaction: %1$s, pidx: %2$s.', 'bookstore-nepal' ),
					'' === $check['transaction_id'] ? __( 'unknown', 'bookstore-nepal' ) : $check['transaction_id'],
					$complete
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
	 * Remembers each settled (non-retry) answer, once per pidx and status, with an order note
	 * when asked (always for Partially Refunded). The caller saves the order.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $checks     pidx => lookup() result.
	 * @param bool     $with_notes Add an order note for each newly seen answer.
	 * @return bool Whether anything was added.
	 */
	private function record_statuses( WC_Order $order, array $checks, bool $with_notes ): bool {
		$noted   = $order->get_meta( self::META_STATUS_NOTES );
		$noted   = is_array( $noted ) ? $noted : array();
		$changed = false;
		foreach ( $checks as $pidx => $check ) {
			if ( self::CHECK_RETRY === $check['outcome'] || self::CHECK_COMPLETE === $check['outcome'] ) {
				continue;
			}
			$key = self::CHECK_MISMATCH === $check['outcome'] ? 'MISMATCH' : $check['status'];
			if ( isset( $noted[ $pidx ][ $key ] ) ) {
				continue;
			}
			$noted[ $pidx ][ $key ] = gmdate( 'c' );
			$changed                = true;
			if ( 'Partially Refunded' === $key ) {
				// Money is still held, whatever the order's state: staff always need to know.
				$order->add_order_note(
					sprintf(
						/* translators: %s: Khalti pidx. */
						__( 'Khalti lookup for pidx %s returned Partially Refunded. Khalti still holds part of this payment: check the Khalti merchant portal and refund the rest or complete the order by hand.', 'bookstore-nepal' ),
						$pidx
					)
				);
			} elseif ( $with_notes ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: Khalti pidx, 2: result of the lookup. */
						__( 'Khalti payment check for pidx %1$s: %2$s', 'bookstore-nepal' ),
						$pidx,
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
	 * Looks for money Khalti took that the order does not account for: on a paid Khalti order,
	 * any other pidx that is Completed (a second payment); on an order now paid by another
	 * method, any Completed pidx (a stray payment). Each gets one refund note. When nothing is
	 * left to ask, the order's flag is removed so the scheduled query stops returning it.
	 *
	 * @param WC_Order $order Order (fresh).
	 * @param bool     $paid  True for a paid Khalti order, false for an order paid by another method.
	 * @return array{found:bool,api_down:bool}
	 */
	private function check_unexpected_payments( WC_Order $order, bool $paid ): array {
		$result   = array(
			'found'    => false,
			'api_down' => false,
		);
		$order_id = $order->get_id();
		$todo     = $this->unexplained_pidxs( $order, $paid );
		$flag     = $paid ? self::META_MULTIPLE : self::META_STRAY_CHECK;
		if ( ! $todo && ! $order->get_meta( $flag ) ) {
			return $result;
		}
		$checks = array();
		foreach ( array_reverse( $todo, true ) as $pidx => $amount ) {
			$pidx  = (string) $pidx;
			$check = $this->lookup( $pidx );
			$this->log_failed_check( $order_id, $pidx, $check );
			if ( self::CHECK_RETRY !== $check['outcome'] ) {
				$checks[ $pidx ] = $check;
			}
			if ( $check['api_down'] ) {
				$result['api_down'] = true;
				break;
			}
		}
		$result['found'] = in_array( self::CHECK_COMPLETE, wp_list_pluck( $checks, 'outcome' ), true );
		if ( $todo && ! $checks ) {
			return $result;
		}
		$lock = $this->lock( $order_id, 0 );
		if ( null === $lock ) {
			return $result;
		}
		try {
			$order = $this->reload_order( $order_id );
			if ( null === $order || ( self::ID === $order->get_payment_method() ) !== $paid || ( $paid && ! $order->is_paid() ) ) {
				return $result;
			}
			$changed = $this->record_statuses( $order, $checks, false );
			foreach ( $checks as $pidx => $check ) {
				if ( self::CHECK_COMPLETE !== $check['outcome'] || $order->get_meta( $this->verified_stray_key( $pidx ) ) ) {
					continue;
				}
				$order->update_meta_data( $this->verified_stray_key( $pidx ), gmdate( 'c' ) );
				$changed = true;
				if ( $paid ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: Khalti transaction id, 2: Khalti pidx. */
							__( 'Khalti took a second payment for this order — refund transaction %1$s in the Khalti merchant portal. pidx: %2$s.', 'bookstore-nepal' ),
							'' === $check['transaction_id'] ? __( 'unknown', 'bookstore-nepal' ) : $check['transaction_id'],
							$pidx
						)
					);
				} else {
					$this->add_stray_note( $order, $pidx, $check['transaction_id'] );
				}
			}
			if ( ! $this->unexplained_pidxs( $order, $paid ) && $order->get_meta( $flag ) ) {
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
	 * pidx values Khalti may hold unexpected money for and that are still unanswered: all
	 * remembered ones except the one that paid the order, those already noted as a stray or
	 * second payment, and those with a settled status.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $paid  True for a paid Khalti order.
	 * @return array<string,string> pidx => amount, oldest first.
	 */
	private function unexplained_pidxs( WC_Order $order, bool $paid ): array {
		$paid_pidx = '';
		if ( $paid ) {
			$paid_pidx = (string) $order->get_meta( self::META_PAID_PIDX );
			$paid_pidx = '' !== $paid_pidx ? $paid_pidx : (string) $order->get_meta( self::META_PIDX );
		}
		$noted = $order->get_meta( self::META_STATUS_NOTES );
		$noted = is_array( $noted ) ? $noted : array();
		$out   = array();
		foreach ( $this->remembered_pidxs( $order ) as $pidx => $amount ) {
			$pidx = (string) $pidx;
			if ( $pidx === $paid_pidx || ! empty( $noted[ $pidx ] ) || $order->get_meta( $this->verified_stray_key( $pidx ) ) ) {
				continue;
			}
			$out[ $pidx ] = $amount;
		}
		return $out;
	}

	/**
	 * pidx values of an unpaid Khalti order still worth looking up: not in a final status.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string> pidx => amount, oldest first.
	 */
	private function unchecked_pidxs( WC_Order $order ): array {
		$noted = $order->get_meta( self::META_STATUS_NOTES );
		$noted = is_array( $noted ) ? $noted : array();
		$out   = array();
		foreach ( $this->remembered_pidxs( $order ) as $pidx => $amount ) {
			if ( array_intersect( self::FINAL_STATUSES, array_keys( (array) ( $noted[ $pidx ] ?? array() ) ) ) ) {
				continue;
			}
			$out[ $pidx ] = $amount;
		}
		return $out;
	}

	/**
	 * Logs a lookup that got no usable answer, for the debug log (never the key).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $pidx     pidx.
	 * @param array  $check    lookup() result.
	 */
	private function log_failed_check( int $order_id, string $pidx, array $check ): void {
		if ( $check['failed'] ) {
			error_log( sprintf( 'Bookstore Nepal Khalti: lookup for order %d (pidx %s) failed: %s', $order_id, $pidx, $check['message'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Serialises work on one order (MySQL named lock), so a double return cannot complete the
	 * order twice.
	 *
	 * @param int      $order_id Order ID.
	 * @param int|null $timeout  Seconds to wait; null for the default.
	 * @return string|null Lock name when held.
	 */
	private function lock( int $order_id, ?int $timeout = null ): ?string {
		if ( null === $timeout ) {
			/**
			 * Seconds to wait for another request on the same Khalti order to finish.
			 *
			 * @param int $timeout Seconds.
			 */
			$timeout = (int) apply_filters( 'bsn_khalti_lock_timeout', self::TIMEOUT + 5 );
		}
		return $this->acquire_lock( 'bsn_khalti_' . $order_id, $timeout );
	}
}
