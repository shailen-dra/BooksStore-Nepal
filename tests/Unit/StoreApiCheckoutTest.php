<?php
/**
 * Block checkout: the Store API rejects a missing or unknown district.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Shipping;

class StoreApiCheckoutTest extends WP_UnitTestCase {

	/** @var mixed Cash on delivery settings before the test. */
	private $cod_settings;

	public function set_up(): void {
		parent::set_up();
		$this->cod_settings = get_option( 'woocommerce_cod_settings' );
		update_option( 'woocommerce_default_country', 'NP:NP-D-KATHMANDU' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'NP' ) );
		update_option( 'woocommerce_ship_to_countries', 'specific' );
		update_option( 'woocommerce_specific_ship_to_countries', array( 'NP' ) );
		update_option(
			'woocommerce_cod_settings',
			array(
				'enabled'            => 'yes',
				'title'              => 'Cash on delivery',
				'enable_for_methods' => array(),
				'enable_for_virtual' => 'no',
			)
		);
		// Gateways read their settings when built, so rebuild them with COD enabled.
		WC()->payment_gateways()->init();
		Shipping::install();
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		$product = new WC_Product_Simple();
		$product->set_name( 'Muna Madan' );
		$product->set_regular_price( '500' );
		$product->set_status( 'publish' );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id() );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		// The checkout copies the posted addresses onto the in-memory customer.
		WC()->customer = new WC_Customer( 0, true );
		if ( false === $this->cod_settings ) {
			delete_option( 'woocommerce_cod_settings' );
		} else {
			update_option( 'woocommerce_cod_settings', $this->cod_settings );
		}
		WC()->payment_gateways()->init();
		parent::tear_down();
	}

	private function address( string $district ): array {
		return array(
			'first_name' => 'Sita',
			'last_name'  => 'Sharma',
			'company'    => '',
			'address_1'  => 'Jhamsikhel',
			'address_2'  => '',
			'city'       => 'Lalitpur',
			'state'      => $district,
			'postcode'   => '',
			'country'    => 'NP',
			'phone'      => '9800000000',
		);
	}

	private function checkout( string $district ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'billing_address'  => $this->address( $district ) + array( 'email' => 'sita@example.com' ),
					'shipping_address' => $this->address( $district ),
					'payment_method'   => 'cod',
				)
			)
		);
		return rest_do_request( $request );
	}

	public function test_missing_district_is_rejected(): void {
		$response = $this->checkout( '' );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_invalid_address', $data['code'] );
		// "District" is the label our locale gives Nepal's state field.
		$this->assertSame( array( 'District is required' ), $data['data']['errors']['billing'] );
		$this->assertSame( array( 'District is required' ), $data['data']['errors']['shipping'] );
	}

	public function test_unknown_district_is_rejected(): void {
		$response = $this->checkout( 'NP-D-ATLANTIS' );
		$data     = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertArrayHasKey( 'billing_address', $data['data']['params'] );
		$this->assertStringContainsString( 'NP-D-ATLANTIS', $data['data']['params']['billing_address'] );
		// The allowed list is our 77 districts.
		$this->assertStringContainsString( 'NP-D-KASKI', $data['data']['params']['billing_address'] );
		$this->assertArrayHasKey( 'shipping_address', $data['data']['params'] );
	}

	public function test_known_district_is_accepted(): void {
		$response = $this->checkout( 'NP-D-LALITPUR' );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$order = wc_get_order( $data['order_id'] );
		$this->assertSame( 'NP-D-LALITPUR', $order->get_billing_state() );
		$this->assertSame( 'NP-D-LALITPUR', $order->get_shipping_state() );
	}
}
