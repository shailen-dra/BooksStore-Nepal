<?php
/**
 * Title: Featured books
 * Slug: bookstore-nepal/featured-books
 * Categories: bookstore-nepal
 * Description: Eight books marked as featured.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","className":"bsn-section bsn-featured","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-section bsn-featured"><!-- wp:group {"className":"bsn-section__head","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group bsn-section__head"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Our favourites', 'bookstore-nepal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"bsn-section__more"} -->
<p class="bsn-section__more"><a href="<?php echo esc_url( Theme::shop_url() ); ?>"><?php esc_html_e( 'See all books', 'bookstore-nepal' ); ?></a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup built and escaped by Theme.
echo Theme::collection_markup(
	array(
		'query_id' => 2,
		'query'    => array( 'featured' => true ),
	)
);
?>
</div>
<!-- /wp:group -->
