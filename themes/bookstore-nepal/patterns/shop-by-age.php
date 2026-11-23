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
use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;

$bsn_tiles = array(
	'0-3'  => array( 'rose', '0–3', Wording::text( 'bsn_age_0_3_title' ), Wording::text( 'bsn_age_0_3_text' ) ),
	'4-7'  => array( 'mint', '4–7', Wording::text( 'bsn_age_4_7_title' ), Wording::text( 'bsn_age_4_7_text' ) ),
	'8-12' => array( 'sky', '8–12', Wording::text( 'bsn_age_8_12_title' ), Wording::text( 'bsn_age_8_12_text' ) ),
);
?>
<!-- wp:group {"align":"wide","anchor":"ages","className":"bsn-section bsn-ages","layout":{"type":"default"}} -->
<div id="ages" class="wp-block-group alignwide bsn-section bsn-ages"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html( Wording::text( 'bsn_ages_heading' ) ); ?></h2>
<!-- /wp:heading -->

<!-- wp:html -->
<div class="bsn-ages__grid">
<?php foreach ( $bsn_tiles as $bsn_slug => $bsn_tile ) : ?>
<a class="bsn-age-tile bsn-age-tile--<?php echo esc_attr( $bsn_tile[0] ); ?>" href="<?php echo esc_url( Theme::age_url( (string) $bsn_slug ) ); ?>"><span class="bsn-age-tile__age"><span class="screen-reader-text"><?php esc_html_e( 'Ages', 'bookstore-nepal' ); ?> </span><?php echo esc_html( $bsn_tile[1] ); ?></span><span class="bsn-age-tile__text"><strong><?php echo esc_html( $bsn_tile[2] ); ?></strong><span><?php echo esc_html( $bsn_tile[3] ); ?></span></span></a>
<?php endforeach; ?>
</div>
<!-- /wp:html --></div>
<!-- /wp:group -->
