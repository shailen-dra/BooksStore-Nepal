<?php
/**
 * Title: Footer help links
 * Slug: bookstore-nepal/footer-help
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Order tracking, delivery charges and the returns policy.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_tracking = Theme::tracking_url();
$bsn_returns  = Theme::returns_url();
$bsn_rates    = Theme::delivery_rates();
?>
<!-- wp:group {"className":"bsn-footer__col","layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group bsn-footer__col"><!-- wp:paragraph {"className":"bsn-footer__heading"} -->
<p class="bsn-footer__heading"><strong><?php esc_html_e( 'Help', 'bookstore-nepal' ); ?></strong></p>
<!-- /wp:paragraph -->
<?php if ( null !== $bsn_tracking ) : ?>
<!-- wp:paragraph -->
<p><a href="<?php echo esc_url( $bsn_tracking ); ?>"><?php esc_html_e( 'Track your order', 'bookstore-nepal' ); ?></a></p>
<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( null !== $bsn_returns ) : ?>
<!-- wp:paragraph -->
<p><a href="<?php echo esc_url( $bsn_returns ); ?>"><?php esc_html_e( 'Returns & refunds', 'bookstore-nepal' ); ?></a></p>
<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( null !== $bsn_rates['valley'] && null !== $bsn_rates['rest'] ) : ?>
<!-- wp:paragraph -->
<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: Kathmandu Valley delivery charge, 2: rest of Nepal delivery charge. */
			__( 'Delivery: Kathmandu Valley %1$s · Rest of Nepal %2$s', 'bookstore-nepal' ),
			Theme::npr( $bsn_rates['valley'] ),
			Theme::npr( $bsn_rates['rest'] )
		)
	);
	?>
</p>
<!-- /wp:paragraph -->
<?php endif; ?>
</div>
<!-- /wp:group -->
