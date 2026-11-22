<?php
use BookstoreNepal\Cli\SetupCommand;
use BookstoreNepal\StorePages;

class StorePagesTest extends WP_UnitTestCase {
	use RestoresGlobalState;

	public function set_up(): void {
		parent::set_up();
		$this->save_attribute_taxonomies();
		$this->save_permalink_structure();
		foreach ( StorePages::definitions() as $def ) {
			delete_option( $def['option'] );
		}
	}

	public function tear_down(): void {
		$this->restore_permalink_structure();
		$this->restore_attribute_taxonomies();
		parent::tear_down();
	}

	private function page_id( string $key ): int {
		return (int) get_option( StorePages::definitions()[ $key ]['option'] );
	}

	/** A draft as WordPress or WooCommerce creates it: modified equals created. */
	private function default_draft( string $title, string $slug ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => 'Default text.',
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2026-01-01 10:00:00', 'post_date' => '2026-01-01 10:00:00' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return $id;
	}

	public function test_first_run_creates_every_page_published(): void {
		$report = StorePages::ensure_all();
		$this->assertSame( array( 'about', 'contact', 'delivery', 'returns', 'privacy', 'terms', 'faq' ), array_keys( $report ) );
		foreach ( $report as $key => $outcome ) {
			$this->assertSame( StorePages::CREATED, $outcome, $key );
			$id = $this->page_id( $key );
			$this->assertSame( 'publish', get_post_status( $id ), $key );
			$this->assertSame( 'page', get_post_type( $id ) );
		}
		$this->assertSame( 'about', get_post_field( 'post_name', $this->page_id( 'about' ) ) );
		$this->assertSame( 'terms', get_post_field( 'post_name', $this->page_id( 'terms' ) ) );
	}

	public function test_options_wire_privacy_terms_and_returns(): void {
		StorePages::ensure_all();
		$this->assertSame( $this->page_id( 'privacy' ), (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assertSame( $this->page_id( 'terms' ), (int) get_option( 'woocommerce_terms_page_id' ) );
		$this->assertSame( $this->page_id( 'returns' ), (int) get_option( 'woocommerce_refund_returns_page_id' ) );
		$this->assertGreaterThan( 0, $this->page_id( 'terms' ) );
	}

	public function test_second_run_creates_nothing(): void {
		StorePages::ensure_all();
		$before = wp_count_posts( 'page' )->publish;
		$ids    = array_map( fn( $k ) => $this->page_id( $k ), array_keys( StorePages::definitions() ) );
		foreach ( StorePages::ensure_all() as $key => $outcome ) {
			$this->assertStringStartsWith( StorePages::KEPT, $outcome, $key );
		}
		$this->assertSame( $before, wp_count_posts( 'page' )->publish );
		$this->assertSame( $ids, array_map( fn( $k ) => $this->page_id( $k ), array_keys( StorePages::definitions() ) ) );
	}

	public function test_published_page_is_never_overwritten(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Our story', 'post_content' => 'Written by the owner.' ) );
		update_option( 'bsn_page_about', $id );
		$this->assertSame( StorePages::KEPT, StorePages::ensure_all()['about'] );
		$this->assertSame( 'Written by the owner.', get_post_field( 'post_content', $id ) );
		$this->assertSame( 'Our story', get_post_field( 'post_title', $id ) );
	}

	public function test_untouched_default_draft_is_filled_and_published(): void {
		$id = $this->default_draft( 'Privacy Policy', 'privacy-policy' );
		update_option( 'wp_page_for_privacy_policy', $id );
		$this->assertSame( StorePages::FILLED, StorePages::ensure_all()['privacy'] );
		$this->assertSame( $id, $this->page_id( 'privacy' ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( 'Privacy policy', get_post_field( 'post_title', $id ) );
		$this->assertStringNotContainsString( 'Default text', get_post_field( 'post_content', $id ) );
	}

	public function test_draft_with_zeroed_gmt_dates_counts_as_untouched(): void {
		$id = $this->default_draft( 'Refund and Returns Policy', 'refund_returns' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '0000-00-00 00:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		update_option( 'woocommerce_refund_returns_page_id', $id );
		$this->assertSame( StorePages::FILLED, StorePages::ensure_all()['returns'] );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_draft_the_owner_edited_is_left_alone(): void {
		$id = $this->default_draft( 'Privacy Policy', 'privacy-policy' );
		wp_update_post( array( 'ID' => $id, 'post_content' => 'Owner draft.' ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2026-02-02 10:00:00' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		update_option( 'wp_page_for_privacy_policy', $id );
		$this->assertStringStartsWith( StorePages::KEPT, StorePages::ensure_all()['privacy'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Owner draft.', get_post_field( 'post_content', $id ) );
	}

	public function test_trashed_page_is_replaced(): void {
		StorePages::ensure_all();
		$old = $this->page_id( 'faq' );
		wp_trash_post( $old );
		$this->assertSame( StorePages::CREATED, StorePages::ensure_all()['faq'] );
		$this->assertNotSame( $old, $this->page_id( 'faq' ) );
	}

	public function test_content_is_the_theme_pattern_when_registered(): void {
		register_block_pattern( 'bookstore-nepal/page-about', array( 'title' => 'About', 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		try {
			$this->assertSame( '<!-- wp:pattern {"slug":"bookstore-nepal/page-about"} /-->', StorePages::content( 'about', 'About us' ) );
		} finally {
			unregister_block_pattern( 'bookstore-nepal/page-about' );
		}
	}

	public function test_content_falls_back_to_a_plain_paragraph_without_the_theme(): void {
		$content = StorePages::content( 'about', 'About us' );
		$this->assertStringContainsString( '[CLIENT', $content );
		$this->assertStringNotContainsString( 'wp:pattern', $content );
	}

	public function test_setup_runs_pages_every_time_even_after_first_run(): void {
		$command = new SetupCommand();
		$command->run( array() );
		$this->assertSame( 'publish', get_post_status( $this->page_id( 'terms' ) ) );
		wp_delete_post( $this->page_id( 'about' ), true );
		$command->run( array() );
		$this->assertSame( 'publish', get_post_status( $this->page_id( 'about' ) ) );
	}

	public function test_setup_contact_flags_set_only_what_is_passed(): void {
		update_option( 'bsn_shop_email', 'keep@example.com' );
		$errors = ( new SetupCommand() )->run( array( 'phone' => '+977 1 4123456', 'whatsapp' => '+977 98-1234-5678' ) );
		$this->assertSame( array(), $errors );
		$this->assertSame( '+977 1 4123456', get_option( 'bsn_shop_phone' ) );
		$this->assertSame( '9779812345678', get_option( 'bsn_shop_whatsapp' ) );
		$this->assertSame( 'keep@example.com', get_option( 'bsn_shop_email' ) );
	}

	public function test_setup_without_contact_flags_leaves_contact_alone(): void {
		update_option( 'bsn_shop_phone', '01 4000000' );
		( new SetupCommand() )->run( array() );
		$this->assertSame( '01 4000000', get_option( 'bsn_shop_phone' ) );
	}

	public function test_invalid_contact_flags_are_rejected_before_any_change(): void {
		$errors = ( new SetupCommand() )->run( array( 'email' => 'nope', 'whatsapp' => 'abc', 'phone' => '0141' ) );
		$this->assertCount( 2, $errors );
		$this->assertNotSame( '0141', get_option( 'bsn_shop_phone' ) );
		$this->assertFalse( get_option( 'bsn_setup_done' ) );
	}

	public function test_empty_contact_flag_clears_the_value(): void {
		update_option( 'bsn_shop_whatsapp', '9779800000000' );
		( new SetupCommand() )->run( array( 'whatsapp' => '' ) );
		$this->assertSame( '', get_option( 'bsn_shop_whatsapp' ) );
	}

	public function test_trashed_page_is_recreated_with_the_clean_slug(): void {
		StorePages::ensure_all();
		$old = $this->page_id( 'about' );
		wp_trash_post( $old );
		StorePages::ensure_all();
		$new = $this->page_id( 'about' );
		$this->assertNotSame( $old, $new );
		$this->assertSame( 'about', get_post_field( 'post_name', $new ) );
	}

	public function test_option_pointing_at_a_deleted_post_is_recreated(): void {
		StorePages::ensure_all();
		$old = $this->page_id( 'faq' );
		wp_delete_post( $old, true );
		$this->assertSame( StorePages::CREATED, StorePages::ensure_all()['faq'] );
		$this->assertNotSame( $old, $this->page_id( 'faq' ) );
		$this->assertSame( 'publish', get_post_status( $this->page_id( 'faq' ) ) );
	}

	public function test_private_pending_and_future_pages_are_kept(): void {
		foreach ( array( 'private', 'pending', 'future' ) as $status ) {
			$args = array( 'post_type' => 'page', 'post_status' => $status, 'post_title' => 'Mine ' . $status, 'post_content' => 'Owner ' . $status );
			if ( 'future' === $status ) {
				$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 30 );
			}
			$id = self::factory()->post->create( $args );
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_modified' => get_post_field( 'post_date', $id ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			update_option( 'bsn_page_about', $id );
			$this->assertStringStartsWith( StorePages::KEPT, StorePages::ensure_all()['about'], $status );
			$this->assertSame( $status, get_post_status( $id ), $status );
			$this->assertSame( 'Owner ' . $status, get_post_field( 'post_content', $id ), $status );
		}
	}

	public function test_existing_published_page_with_the_slug_is_adopted_not_duplicated(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'about', 'post_title' => 'About', 'post_content' => 'Owner text.' ) );
		$this->assertStringStartsWith( StorePages::KEPT, StorePages::ensure_all()['about'] );
		$this->assertSame( $id, $this->page_id( 'about' ) );
		$this->assertSame( 'Owner text.', get_post_field( 'post_content', $id ) );
		$this->assertNull( get_page_by_path( 'about-2', OBJECT, 'page' ) );
	}

	public function test_existing_edited_draft_with_the_slug_is_adopted_and_left_alone(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'contact', 'post_content' => 'Owner draft.' ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2030-01-01 00:00:00' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$this->assertStringStartsWith( StorePages::KEPT, StorePages::ensure_all()['contact'] );
		$this->assertSame( $id, $this->page_id( 'contact' ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Owner draft.', get_post_field( 'post_content', $id ) );
		$this->assertNull( get_page_by_path( 'contact-2', OBJECT, 'page' ) );
	}

	public function test_existing_untouched_draft_with_the_slug_is_adopted_and_filled(): void {
		$id = $this->default_draft( 'Delivery', 'delivery' );
		$this->assertSame( StorePages::FILLED, StorePages::ensure_all()['delivery'] );
		$this->assertSame( $id, $this->page_id( 'delivery' ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_stored_content_is_the_pattern_block_when_the_theme_pattern_exists(): void {
		register_block_pattern( 'bookstore-nepal/page-faq', array( 'title' => 'FAQ', 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		try {
			( new SetupCommand() )->run( array() );
			$this->assertSame( '<!-- wp:pattern {"slug":"bookstore-nepal/page-faq"} /-->', get_post_field( 'post_content', $this->page_id( 'faq' ) ) );
			$this->assertTrue( StorePages::pattern_available() );
		} finally {
			unregister_block_pattern( 'bookstore-nepal/page-faq' );
		}
		$this->assertFalse( StorePages::pattern_available() );
	}
}
