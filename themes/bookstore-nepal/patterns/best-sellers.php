<?php
/**
 * Title: Best sellers
 * Slug: bookstore-nepal/best-sellers
 * Categories: bookstore-nepal
 * Description: The eight best-selling books.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","className":"bsn-section bsn-best","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-section bsn-best"><!-- wp:group {"className":"bsn-section__head","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group bsn-section__head"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Best sellers', 'bookstore-nepal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"bsn-section__more"} -->
<p class="bsn-section__more"><a href="<?php echo esc_url( add_query_arg( 'orderby', 'popularity', Theme::shop_url() ) ); ?>"><?php esc_html_e( 'See all best sellers', 'bookstore-nepal' ); ?></a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup built and escaped by Theme.
echo Theme::collection_markup(
	array(
		'query_id' => 4,
		'query'    => array(
			'orderBy'                => 'popularity',
			'order'                  => 'desc',
			'woocommerceStockStatus' => array( 'instock' ),
		),
	)
);
?>
</div>
<!-- /wp:group -->
