<?php
use BookstoreNepal\Cli\SetupCommand;
use BookstoreNepal\Shipping;

class SetupCommandTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The DB rolls back between tests but registered taxonomies do not.
		foreach ( array( 'age-group', 'language', 'book-type' ) as $slug ) {
			unregister_taxonomy( 'pa_' . $slug );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
	}

	/**
	 * Flat rate cost configured for a zone.
	 */
	private function flat_cost( string $zone_name ): ?string {
		foreach ( WC_Shipping_Zones::get_zones() as $data ) {
			if ( $zone_name !== $data['zone_name'] ) {
				continue;
			}
			foreach ( ( new WC_Shipping_Zone( $data['zone_id'] ) )->get_shipping_methods() as $method ) {
				if ( 'flat_rate' === $method->id ) {
					return (string) $method->get_option( 'cost' );
				}
			}
		}
		return null;
	}

	private function free_min(): ?string {
		foreach ( WC_Shipping_Zones::get_zones() as $data ) {
			if ( Shipping::ZONE_VALLEY !== $data['zone_name'] ) {
				continue;
			}
			foreach ( ( new WC_Shipping_Zone( $data['zone_id'] ) )->get_shipping_methods() as $method ) {
				if ( 'free_shipping' === $method->id ) {
					return (string) $method->get_option( 'min_amount' );
				}
			}
		}
		return null;
	}

	private function zone_count(): int {
		$n = 0;
		foreach ( WC_Shipping_Zones::get_zones() as $data ) {
			if ( in_array( $data['zone_name'], array( Shipping::ZONE_VALLEY, Shipping::ZONE_REST ), true ) ) {
				++$n;
			}
		}
		return $n;
	}

	public function test_setup_applies_store_options(): void {
		$errors = ( new SetupCommand() )->run( array() );
		$this->assertSame( array(), $errors );

		$this->assertSame( 'NPR', get_option( 'woocommerce_currency' ) );
		$this->assertSame( 'NP:NP-D-KATHMANDU', get_option( 'woocommerce_default_country' ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_enable_guest_checkout' ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_manage_stock' ) );
		$this->assertEquals( 3, get_option( 'woocommerce_notify_low_stock_amount' ) );
		$this->assertEquals( 0, get_option( 'woocommerce_notify_no_stock_amount' ) );
		$this->assertEquals( 0, get_option( 'woocommerce_price_num_decimals' ) );

		$cod = get_option( 'woocommerce_cod_settings' );
		$this->assertSame( 'yes', $cod['enabled'] );
		$this->assertSame( 'Cash on delivery', $cod['title'] );
		$this->assertEmpty( $cod['enable_for_methods'] );
		$this->assertSame( 'no', $cod['enable_for_virtual'] );
	}

	public function test_setup_is_idempotent(): void {
		$command = new SetupCommand();
		$command->run( array() );
		$page_id = (int) get_option( 'bsn_tracking_page_id' );
		$command->run( array() );

		$this->assertSame( 2, $this->zone_count() );
		$this->assertSame( $page_id, (int) get_option( 'bsn_tracking_page_id' ) );
		$pages = get_posts( array( 'post_type' => 'page', 'title' => 'Track your order', 'post_status' => 'any', 'numberposts' => -1 ) );
		$this->assertCount( 1, $pages );
		$this->assertSame( '[woocommerce_order_tracking]', $pages[0]->post_content );
	}

	public function test_trashed_tracking_page_is_replaced(): void {
		$command = new SetupCommand();
		$command->run( array() );
		$old = (int) get_option( 'bsn_tracking_page_id' );
		wp_trash_post( $old );
		$command->run( array() );
		$new = (int) get_option( 'bsn_tracking_page_id' );
		$this->assertNotSame( $old, $new );
		$this->assertSame( 'publish', get_post_status( $new ) );
	}

	public function test_rates_survive_activation_and_plain_setup_but_flags_change_them(): void {
		Shipping::install( array( 'valley' => 111, 'rest' => 222, 'free_over' => 3333 ) );

		Shipping::seed(); // What plugin activation runs.
		$this->assertSame( '111', $this->flat_cost( Shipping::ZONE_VALLEY ) );
		$this->assertSame( '222', $this->flat_cost( Shipping::ZONE_REST ) );
		$this->assertSame( '3333', $this->free_min() );

		( new SetupCommand() )->run( array() );
		$this->assertSame( '111', $this->flat_cost( Shipping::ZONE_VALLEY ) );
		$this->assertSame( '222', $this->flat_cost( Shipping::ZONE_REST ) );
		$this->assertSame( '3333', $this->free_min() );

		$errors = ( new SetupCommand() )->run( array( 'valley' => '150' ) );
		$this->assertSame( array(), $errors );
		$this->assertSame( '150', $this->flat_cost( Shipping::ZONE_VALLEY ) );
		$this->assertSame( '222', $this->flat_cost( Shipping::ZONE_REST ) );
		$this->assertSame( '3333', $this->free_min() );

		( new SetupCommand() )->run( array( 'free-over' => '2500', 'rest' => '0' ) );
		$this->assertSame( '0', $this->flat_cost( Shipping::ZONE_REST ) );
		$this->assertSame( '2500', $this->free_min() );
	}

	public function test_seed_creates_zones_with_placeholder_rates_when_missing(): void {
		Shipping::seed();
		$this->assertSame( 2, $this->zone_count() );
		$this->assertSame( '100', $this->flat_cost( Shipping::ZONE_VALLEY ) );
		$this->assertSame( '200', $this->flat_cost( Shipping::ZONE_REST ) );
		$this->assertSame( '2000', $this->free_min() );
	}

	public function test_invalid_rate_flags_rejected_before_any_change(): void {
		Shipping::install( array( 'valley' => 111, 'rest' => 222, 'free_over' => 3333 ) );
		foreach ( array( 'abc', '-5', '12.5', '', '1e3' ) as $bad ) {
			$errors = ( new SetupCommand() )->run( array( 'valley' => '150', 'rest' => $bad ) );
			$this->assertNotEmpty( $errors, "Value '$bad' should be rejected." );
			$this->assertStringContainsString( '--rest', $errors[0] );
		}
		$this->assertSame( '111', $this->flat_cost( Shipping::ZONE_VALLEY ) );
		$this->assertNotSame( 'NPR', get_option( 'woocommerce_currency' ), 'Nothing applied on invalid input.' );
	}
}
