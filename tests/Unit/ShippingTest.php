<?php
use BookstoreNepal\Districts;
use BookstoreNepal\Shipping;

class ShippingTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_default_country', 'NP' );
		Shipping::install();
	}

	/**
	 * Rates offered for a package to a Nepal district.
	 *
	 * @return WC_Shipping_Rate[] Keyed by rate id.
	 */
	private function rates_for( string $district, float $cost ): array {
		// Free shipping's minimum is checked against the cart, so use a real cart.
		if ( null === WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		$product = new WC_Product_Simple();
		$product->set_name( 'Test book' );
		$product->set_regular_price( (string) $cost );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id() );
		$packages                       = WC()->cart->get_shipping_packages();
		$package                        = $packages[0];
		$package['destination']['country'] = 'NP';
		$package['destination']['state']   = $district;
		WC()->shipping()->reset_shipping();
		$calculated = WC()->shipping()->calculate_shipping_for_package( $package );
		return $calculated['rates'];
	}

	private function costs( array $rates ): array {
		$out = array();
		foreach ( $rates as $rate ) {
			$out[ $rate->get_method_id() ] = (float) $rate->get_cost();
		}
		return $out;
	}

	public function test_77_districts_and_valley_subset(): void {
		$all = Districts::all();
		$this->assertCount( 77, $all );
		foreach ( Districts::VALLEY as $code ) {
			$this->assertArrayHasKey( $code, $all );
		}
		$this->assertArrayHasKey( 'NP-D-NAWALPARASI-EAST', $all );
		$this->assertArrayHasKey( 'NP-D-RUKUM-WEST', $all );
		$names = array_values( $all );
		$sorted = $names;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $names, 'Sorted by name.' );
	}

	public function test_valley_package_gets_valley_rate(): void {
		$costs = $this->costs( $this->rates_for( 'NP-D-LALITPUR', 500 ) );
		$this->assertSame( array( 'flat_rate' => 100.0 ), $costs );
	}

	public function test_outside_valley_gets_rest_rate(): void {
		$costs = $this->costs( $this->rates_for( 'NP-D-KASKI', 500 ) );
		$this->assertSame( array( 'flat_rate' => 200.0 ), $costs );
	}

	public function test_free_over_threshold(): void {
		$this->assertArrayHasKey( 'free_shipping', $this->costs( $this->rates_for( 'NP-D-KASKI', 2000 ) ) );
		$this->assertArrayHasKey( 'free_shipping', $this->costs( $this->rates_for( 'NP-D-KATHMANDU', 2000 ) ) );
		$this->assertArrayNotHasKey( 'free_shipping', $this->costs( $this->rates_for( 'NP-D-KASKI', 1999 ) ) );
	}

	public function test_states_and_locale_make_district_required(): void {
		$states = WC()->countries->get_states( 'NP' );
		$this->assertSame( Districts::all(), $states );
		$locale = WC()->countries->get_country_locale();
		$this->assertSame( 'District', $locale['NP']['state']['label'] );
		$this->assertTrue( $locale['NP']['state']['required'] );
	}

	public function test_checkout_requires_known_district(): void {
		$orders_before = count( wc_get_orders( array( 'limit' => -1 ) ) );

		foreach ( array( '', 'NP-D-ATLANTIS', 'Kathmandu' ) as $bad ) {
			$errors = new WP_Error();
			Shipping::validate_checkout( array( 'billing_country' => 'NP', 'billing_state' => $bad, 'ship_to_different_address' => 0 ), $errors );
			$this->assertTrue( $errors->has_errors(), "State '$bad' must be rejected." );
		}

		$errors = new WP_Error();
		Shipping::validate_checkout( array( 'billing_country' => 'NP', 'billing_state' => 'NP-D-KASKI' ), $errors );
		$this->assertFalse( $errors->has_errors() );

		$errors = new WP_Error();
		Shipping::validate_checkout(
			array( 'billing_country' => 'NP', 'billing_state' => 'NP-D-KASKI', 'ship_to_different_address' => 1, 'shipping_country' => 'NP', 'shipping_state' => '' ),
			$errors
		);
		$this->assertTrue( $errors->has_errors(), 'Shipping district checked when shipping separately.' );

		$this->assertSame( $orders_before, count( wc_get_orders( array( 'limit' => -1 ) ) ) );
	}

	public function test_install_idempotent_and_updates_rates(): void {
		Shipping::install();
		$names = array_map( static fn( $z ) => $z['zone_name'], WC_Shipping_Zones::get_zones() );
		sort( $names );
		$this->assertSame( array( 'Kathmandu Valley', 'Rest of Nepal' ), $names );

		$this->assertSame( array(), Shipping::install( array( 'valley' => 120, 'rest' => 250, 'free_over' => 3000 ) ) );
		$this->assertCount( 2, WC_Shipping_Zones::get_zones() );
		$this->assertSame( array( 'flat_rate' => 120.0 ), $this->costs( $this->rates_for( 'NP-D-BHAKTAPUR', 500 ) ) );
		$this->assertSame( array( 'flat_rate' => 250.0 ), $this->costs( $this->rates_for( 'NP-D-KASKI', 500 ) ) );
		$this->assertArrayNotHasKey( 'free_shipping', $this->costs( $this->rates_for( 'NP-D-KASKI', 2500 ) ) );
		$this->assertArrayHasKey( 'free_shipping', $this->costs( $this->rates_for( 'NP-D-KASKI', 3000 ) ) );
	}

	private function rate( string $id, string $method, float $cost = 0 ): WC_Shipping_Rate {
		return new WC_Shipping_Rate( $id, $method, $cost, array(), $method );
	}

	public function test_free_delivery_hides_flat_rate(): void {
		$rates  = array(
			'flat_rate:1'     => $this->rate( 'flat_rate:1', 'flat_rate', 100 ),
			'free_shipping:2' => $this->rate( 'free_shipping:2', 'free_shipping' ),
		);
		$result = Shipping::hide_paid_rates_when_free( $rates );
		$this->assertSame( array( 'free_shipping:2' ), array_keys( $result ) );
		$this->assertSame( array( 'free_shipping:2' ), array_keys( apply_filters( 'woocommerce_package_rates', $rates, array() ) ) );
	}

	public function test_free_delivery_keeps_pickup_and_other_methods(): void {
		$rates  = array(
			'flat_rate:1'       => $this->rate( 'flat_rate:1', 'flat_rate', 100 ),
			'free_shipping:2'   => $this->rate( 'free_shipping:2', 'free_shipping' ),
			'local_pickup:3'    => $this->rate( 'local_pickup:3', 'local_pickup' ),
			'pickup_location:0' => $this->rate( 'pickup_location:0', 'pickup_location' ),
			'courier:4'         => $this->rate( 'courier:4', 'courier', 300 ),
		);
		$this->assertSame(
			array( 'free_shipping:2', 'local_pickup:3', 'pickup_location:0', 'courier:4' ),
			array_keys( Shipping::hide_paid_rates_when_free( $rates ) )
		);
	}

	public function test_rates_without_free_delivery_are_unchanged(): void {
		$rates = array(
			'flat_rate:1'    => $this->rate( 'flat_rate:1', 'flat_rate', 100 ),
			'local_pickup:3' => $this->rate( 'local_pickup:3', 'local_pickup' ),
		);
		$this->assertSame( $rates, Shipping::hide_paid_rates_when_free( $rates ) );
	}
}
