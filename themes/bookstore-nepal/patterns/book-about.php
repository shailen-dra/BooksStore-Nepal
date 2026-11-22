<?php
/**
 * Title: About this book
 * Slug: bookstore-nepal/book-about
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Heading, short description and full description of the current book.
 *
 * @package BookstoreNepal
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"bsn-book__about","layout":{"type":"default"}} -->
<section class="wp-block-group bsn-book__about"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'About this book', 'bookstore-nepal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:post-excerpt {"__woocommerceNamespace":"woocommerce/product-query/product-summary"} /-->

<!-- wp:post-content {"layout":{"type":"default"}} /--></section>
<!-- /wp:group -->
