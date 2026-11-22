<?php
/**
 * Title: New arrivals
 * Slug: bookstore-nepal/new-arrivals
 * Categories: bookstore-nepal
 * Description: The eight newest books.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_slider = 'new-arrivals';
?>
<!-- wp:group {"align":"wide","className":"bsn-section bsn-new","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-section bsn-new"><!-- wp:group {"className":"bsn-section__head","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group bsn-section__head"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'New arrivals', 'bookstore-nepal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"bsn-section__tools","layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group bsn-section__tools"><!-- wp:paragraph {"className":"bsn-section__more"} -->
<p class="bsn-section__more"><a href="<?php echo esc_url( add_query_arg( 'orderby', 'date', Theme::shop_url() ) ); ?>"><?php esc_html_e( 'See all new books', 'bookstore-nepal' ); ?></a></p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped by Theme.
echo Theme::slider_controls( Theme::slider_id( $bsn_slider ) );
?>
<!-- /wp:html --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup built and escaped by Theme.
echo Theme::collection_markup(
	array(
		'query_id' => 1,
		'slider'   => $bsn_slider,
		'query'    => array(
			'orderBy' => 'date',
			'order'   => 'desc',
		),
	)
);
?>
</div>
<!-- /wp:group -->
