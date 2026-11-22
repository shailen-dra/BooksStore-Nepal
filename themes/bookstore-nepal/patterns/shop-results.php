<?php
/**
 * Title: Shop results
 * Slug: bookstore-nepal/shop-results
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Filters, sorting and the books matching the current shop URL (filters, price, search).
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","className":"bsn-shop","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-shop"><!-- wp:woocommerce/store-notices /-->

<!-- wp:pattern {"slug":"bookstore-nepal/shop-filters"} /-->

<!-- wp:group {"className":"bsn-shop__bar","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group bsn-shop__bar"><!-- wp:woocommerce/product-results-count /-->

<!-- wp:woocommerce/catalog-sorting /--></div>
<!-- /wp:group -->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup built and escaped by Theme.
echo Theme::collection_markup(
	array(
		'query_id'      => 0,
		'query'         => array(
			'inherit' => true,
			'perPage' => 12,
			'order'   => 'asc',
			'orderBy' => 'title',
		),
		'heading_level' => 2,
		'pagination'    => true,
	)
);
?>
</div>
<!-- /wp:group -->
