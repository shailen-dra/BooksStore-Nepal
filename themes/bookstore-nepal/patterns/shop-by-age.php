<?php
/**
 * Title: Shop by age
 * Slug: bookstore-nepal/shop-by-age
 * Categories: bookstore-nepal
 * Description: Three tiles linking to the shop filtered by age group.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_tiles = array(
	'0-3'  => array( 'rose', '0–3', __( 'Babies & toddlers', 'bookstore-nepal' ), __( 'Board books, first words, touch and feel', 'bookstore-nepal' ) ),
	'4-7'  => array( 'mint', '4–7', __( 'Early readers', 'bookstore-nepal' ), __( 'Picture stories, alphabet and activity books', 'bookstore-nepal' ) ),
	'8-12' => array( 'sky', '8–12', __( 'Confident readers', 'bookstore-nepal' ), __( 'Chapter books, adventures, how-things-work', 'bookstore-nepal' ) ),
);
?>
<!-- wp:group {"align":"wide","anchor":"ages","className":"bsn-section bsn-ages","layout":{"type":"default"}} -->
<div id="ages" class="wp-block-group alignwide bsn-section bsn-ages"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Shop by age', 'bookstore-nepal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:html -->
<div class="bsn-ages__grid">
<?php foreach ( $bsn_tiles as $bsn_slug => $bsn_tile ) : ?>
<a class="bsn-age-tile bsn-age-tile--<?php echo esc_attr( $bsn_tile[0] ); ?>" href="<?php echo esc_url( Theme::age_url( (string) $bsn_slug ) ); ?>"><span class="bsn-age-tile__age"><span class="screen-reader-text"><?php esc_html_e( 'Ages', 'bookstore-nepal' ); ?> </span><?php echo esc_html( $bsn_tile[1] ); ?></span><span class="bsn-age-tile__text"><strong><?php echo esc_html( $bsn_tile[2] ); ?></strong><span><?php echo esc_html( $bsn_tile[3] ); ?></span></span></a>
<?php endforeach; ?>
</div>
<!-- /wp:html --></div>
<!-- /wp:group -->
