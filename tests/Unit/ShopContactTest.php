<?php
use BookstoreNepal\ShopContact;

class ShopContactTest extends WP_UnitTestCase {

	private function fields(): array {
		return ( new WC_Settings_General() )->get_settings_for_section( '' );
	}

	private function ids( array $fields ): array {
		return array_values( array_filter( array_column( $fields, 'id' ) ) );
	}

	public function test_fields_sit_inside_the_store_address_block(): void {
		$ids  = $this->ids( $this->fields() );
		$end  = array_search( 'store_address', array_reverse( $ids, true ), true );
		$post = array_search( 'woocommerce_store_postcode', $ids, true );
		$this->assertNotFalse( $post );
		foreach ( array( ShopContact::PHONE, ShopContact::WHATSAPP, ShopContact::EMAIL ) as $id ) {
			$pos = array_search( $id, $ids, true );
			$this->assertNotFalse( $pos, "$id missing" );
			$this->assertGreaterThan( $post, $pos );
		}
		$this->assertGreaterThan( array_search( ShopContact::EMAIL, $ids, true ), $end );
	}

	public function test_email_field_defaults_to_empty_not_the_admin_email(): void {
		foreach ( $this->fields() as $field ) {
			if ( ShopContact::EMAIL === ( $field['id'] ?? '' ) ) {
				$this->assertSame( '', $field['default'] );
				$this->assertStringNotContainsString( 'Leave empty', $field['desc'] );
				return;
			}
		}
		$this->fail( 'email field missing' );
	}

	public function test_non_array_settings_pass_through(): void {
		$this->assertSame( 'x', ShopContact::add_fields( 'x' ) );
	}

	public function test_phone_is_cleaned(): void {
		$this->assertSame( '+977 1 4123456', ShopContact::sanitize_phone( "  +977   1 <b>4123456</b>\n" ) );
		$this->assertSame( '(01) 4-123456', ShopContact::sanitize_phone( '(01) 4-123456' ) );
		$this->assertSame( '', ShopContact::sanitize_phone( array( 'x' ) ) );
	}

	public function test_whatsapp_is_normalised_to_country_code_plus_mobile(): void {
		$this->assertSame( '9779812345678', ShopContact::sanitize_whatsapp( '+977 98-1234 5678' ) );
		$this->assertSame( '9779812345678', ShopContact::sanitize_whatsapp( '9812345678' ) );
		$this->assertSame( '9779812345678', ShopContact::sanitize_whatsapp( '00977 9812345678' ) );
		$this->assertSame( '', ShopContact::sanitize_whatsapp( '' ) );
	}

	public function test_whatsapp_of_the_wrong_shape_is_rejected(): void {
		foreach ( array( 'call me', '98123', '014123456', '97798123', '97798123456789', '8812345678' ) as $bad ) {
			$this->assertSame( '', ShopContact::sanitize_whatsapp( $bad ), $bad );
		}
	}

	public function test_email_must_be_valid(): void {
		$this->assertSame( 'shop@example.com', ShopContact::sanitize_email( ' shop@example.com ' ) );
		$this->assertSame( '', ShopContact::sanitize_email( 'not an email' ) );
	}

	public function test_sanitisers_run_on_woocommerce_save(): void {
		$this->assertSame( '9779812345678', apply_filters( 'woocommerce_admin_settings_sanitize_option_' . ShopContact::WHATSAPP, '+977 9812345678', array(), '' ) );
		$this->assertSame( '', apply_filters( 'woocommerce_admin_settings_sanitize_option_' . ShopContact::EMAIL, 'bad', array(), '' ) );
	}
}
