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
use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;

$bsn_slider = 'best-sellers';
?>
<!-- wp:group {"align":"wide","className":"bsn-section bsn-best","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-section bsn-best"><!-- wp:group {"className":"bsn-section__head","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group bsn-section__head"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html( Wording::text( 'bsn_best_heading' ) ); ?></h2>
<!-- /wp:heading -->

<!-- wp:group {"className":"bsn-section__tools","layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group bsn-section__tools"><!-- wp:paragraph {"className":"bsn-section__more"} -->
<p class="bsn-section__more"><a href="<?php echo esc_url( add_query_arg( 'orderby', 'popularity', Theme::shop_url() ) ); ?>"><?php echo esc_html( Wording::text( 'bsn_best_more' ) ); ?></a></p>
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
// Books the owner picked, in their order; otherwise the slider fills itself.
$bsn_picked = Wording::ids( 'bsn_best_books' );
$bsn_query  = $bsn_picked
	? array(
		'orderBy'                       => 'post__in',
		'order'                         => 'asc',
		'perPage'                       => count( $bsn_picked ),
		'woocommerceHandPickedProducts' => $bsn_picked,
		'woocommerceStockStatus'        => array( 'instock' ),
	)
	: array(
		'orderBy'                => 'popularity',
		'order'                  => 'desc',
		'woocommerceStockStatus' => array( 'instock' ),
	);
echo Theme::collection_markup(
	array(
		'query_id' => 4,
		'slider'   => $bsn_slider,
		'query'    => $bsn_query,
	)
);
?>
</div>
<!-- /wp:group -->
