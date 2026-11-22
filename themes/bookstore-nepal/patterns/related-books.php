<?php
/**
 * Title: You may also like
 * Slug: bookstore-nepal/related-books
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Four related books on the book page.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","className":"bsn-section bsn-related","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-section bsn-related"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'You may also like', 'bookstore-nepal' ); ?></h2>
<!-- /wp:heading -->

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup built and escaped by Theme.
echo Theme::collection_markup(
	array(
		'query_id'   => 3,
		'collection' => 'woocommerce/product-collection/related',
		'query'      => array(
			'perPage'   => 4,
			'relatedBy' => array(
				'categories' => true,
				'tags'       => true,
			),
		),
	)
);
?>
</div>
<!-- /wp:group -->
