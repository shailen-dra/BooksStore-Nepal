<?php
/**
 * Title: Hero with search
 * Slug: bookstore-nepal/hero
 * Categories: bookstore-nepal
 * Description: Headline, book search and links to shop by age and the gift finder.
 *
 * @package BookstoreNepal
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","className":"bsn-hero","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-hero"><!-- wp:group {"className":"bsn-hero__text","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-hero__text"><!-- wp:paragraph {"className":"bsn-pill bsn-pill--mint"} -->
<p class="bsn-pill bsn-pill--mint"><?php esc_html_e( 'Delivery all over Nepal', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading"><?php esc_html_e( 'Books that grow with your child', 'bookstore-nepal' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"bsn-hero__lead"} -->
<p class="bsn-hero__lead"><?php esc_html_e( 'Picture books, stories and activity books in Nepali and English — chosen by age, delivered to your door.', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:search {"label":"<?php echo esc_attr__( 'Search books', 'bookstore-nepal' ); ?>","showLabel":false,"placeholder":"<?php echo esc_attr__( 'Search by title, author or topic', 'bookstore-nepal' ); ?>","buttonText":"<?php echo esc_attr__( 'Search', 'bookstore-nepal' ); ?>","buttonPosition":"button-outside","buttonUseIcon":true,"query":{"post_type":"product"},"className":"bsn-search bsn-hero__search"} /-->

<!-- wp:buttons {"className":"bsn-hero__actions"} -->
<div class="wp-block-buttons bsn-hero__actions"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#ages"><?php esc_html_e( 'Shop by age', 'bookstore-nepal' ); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#gift"><?php esc_html_e( 'Find a gift', 'bookstore-nepal' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:html -->
<div class="bsn-hero__books" aria-hidden="true"><span class="bsn-hero__book bsn-hero__book--1">The Little Yak's Big Day</span><span class="bsn-hero__book bsn-hero__book--2">हिमालको कथा</span><span class="bsn-hero__book bsn-hero__book--3">Count the Momos</span></div>
<!-- /wp:html --></div>
<!-- /wp:group -->
