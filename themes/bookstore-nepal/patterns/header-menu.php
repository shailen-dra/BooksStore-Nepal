<?php
/**
 * Title: Header menu
 * Slug: bookstore-nepal/header-menu
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Age, language and book type links, gift finder and order tracking.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_groups = array(
	array(
		'label' => __( 'Age', 'bookstore-nepal' ),
		'links' => array(
			/* translators: %s: age range. */
			array( sprintf( __( 'Ages %s', 'bookstore-nepal' ), '0–3' ), Theme::age_url( '0-3' ) ),
			/* translators: %s: age range. */
			array( sprintf( __( 'Ages %s', 'bookstore-nepal' ), '4–7' ), Theme::age_url( '4-7' ) ),
			/* translators: %s: age range. */
			array( sprintf( __( 'Ages %s', 'bookstore-nepal' ), '8–12' ), Theme::age_url( '8-12' ) ),
		),
	),
	array(
		'label' => __( 'Language', 'bookstore-nepal' ),
		'links' => array(
			array( __( 'Nepali books', 'bookstore-nepal' ), Theme::filter_url( 'language', 'nepali' ) ),
			array( __( 'English books', 'bookstore-nepal' ), Theme::filter_url( 'language', 'english' ) ),
		),
	),
	array(
		'label' => __( 'Type', 'bookstore-nepal' ),
		'links' => array(
			array( __( 'Picture books', 'bookstore-nepal' ), Theme::filter_url( 'book-type', 'picture-book' ) ),
			array( __( 'Stories', 'bookstore-nepal' ), Theme::filter_url( 'book-type', 'story' ) ),
			array( __( 'Activity books', 'bookstore-nepal' ), Theme::filter_url( 'book-type', 'activity' ) ),
			array( __( 'Educational', 'bookstore-nepal' ), Theme::filter_url( 'book-type', 'educational' ) ),
		),
	),
);

/**
 * Serialized navigation-link block.
 *
 * @param string $label Link text.
 * @param string $url   Link URL.
 * @param string $class Extra class.
 */
$bsn_link = static function ( string $label, string $url, string $class = '' ): string {
	$attrs = array(
		'label' => esc_html( $label ),
		'url'   => esc_url_raw( $url ),
		'kind'  => 'custom',
	);
	if ( '' !== $class ) {
		$attrs['className'] = $class;
	}
	return '<!-- wp:navigation-link ' . serialize_block_attributes( $attrs ) . ' /-->' . "\n";
};

$bsn_items = '';
foreach ( $bsn_groups as $bsn_group ) {
	$bsn_items .= '<!-- wp:navigation-submenu ' . serialize_block_attributes(
		array(
			'label' => esc_html( $bsn_group['label'] ),
			'kind'  => 'custom',
		)
	) . ' -->' . "\n";
	foreach ( $bsn_group['links'] as $bsn_item ) {
		$bsn_items .= $bsn_link( $bsn_item[0], $bsn_item[1] );
	}
	$bsn_items .= '<!-- /wp:navigation-submenu -->' . "\n";
}
$bsn_items .= $bsn_link( __( 'Gift finder', 'bookstore-nepal' ), home_url( '/#gift' ) );
$bsn_tracking = Theme::tracking_url();
if ( null !== $bsn_tracking ) {
	$bsn_items .= $bsn_link( __( 'Track your order', 'bookstore-nepal' ), $bsn_tracking, 'bsn-nav-track' );
}
$bsn_nav = serialize_block_attributes(
	array(
		'overlayMenu'         => 'mobile',
		'openSubmenusOnClick' => true,
		'className'           => 'bsn-nav',
		'ariaLabel'           => __( 'Main', 'bookstore-nepal' ),
		'layout'              => array(
			'type'     => 'flex',
			'flexWrap' => 'wrap',
		),
	)
);
?>
<!-- wp:navigation <?php echo $bsn_nav; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block attributes JSON; the navigation block escapes them when rendering. ?> -->
<?php echo $bsn_items; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Serialized blocks; attributes escaped above. ?>
<!-- /wp:navigation -->
