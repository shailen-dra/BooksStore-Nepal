<?php
/**
 * Khalti in the Cart & Checkout blocks.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the Khalti gateway to the block checkout. Payment itself runs through
 * KhaltiGateway::process_payment() via the Store API's legacy gateway adapter.
 */
class KhaltiBlocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = KhaltiGateway::ID;

	/**
	 * Gateway instance.
	 *
	 * @var KhaltiGateway|null
	 */
	private $gateway;

	/**
	 * Loads settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . KhaltiGateway::ID . '_settings', array() );
		$this->settings = is_array( $this->settings ) ? $this->settings : array();
	}

	/**
	 * Gateway instance, preferring WooCommerce's own.
	 */
	private function gateway(): KhaltiGateway {
		if ( null === $this->gateway ) {
			$gateways      = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
			$this->gateway = isset( $gateways[ KhaltiGateway::ID ] ) && $gateways[ KhaltiGateway::ID ] instanceof KhaltiGateway ? $gateways[ KhaltiGateway::ID ] : new KhaltiGateway();
		}
		return $this->gateway;
	}

	/**
	 * Active when the gateway is enabled and usable (NPR, credentials).
	 */
	public function is_active() {
		return $this->gateway()->is_available();
	}

	/**
	 * Registers the plain-JS registration script.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = 'bsn-khalti-blocks';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				plugins_url( 'assets/js/khalti-blocks.js', BSN_PLUGIN_FILE ),
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
				BSN_VERSION,
				true
			);
		}
		return array( $handle );
	}

	/**
	 * Data exposed to the script as getSetting( 'bsn_khalti_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();
		return array(
			'title'       => $gateway->get_title(),
			'description' => $gateway->get_description(),
			'supports'    => array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ),
		);
	}
}
