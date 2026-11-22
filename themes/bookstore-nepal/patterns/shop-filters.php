<?php
/**
 * Title: Shop filters
 * Slug: bookstore-nepal/shop-filters
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Age, language and book type filter links for the shop; rendered per request.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\BookstoreNepal\Catalog' ) ) {
	return;
}

// Root-relative, so filter links never change host or scheme.
$bsn_current = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
$bsn_active  = false;
?>
<!-- wp:html -->
<nav class="bsn-filters alignwide" aria-label="<?php esc_attr_e( 'Filter books', 'bookstore-nepal' ); ?>">
<?php
foreach ( Theme::FILTER_ATTRIBUTES as $bsn_attribute ) :
	list( $bsn_label, $bsn_terms ) = \BookstoreNepal\Catalog::ATTRIBUTES[ $bsn_attribute ];
	$bsn_selected                  = Theme::active_filter_values( $bsn_current, $bsn_attribute );
	$bsn_active                    = $bsn_active || (bool) $bsn_selected;
	?>
<div class="bsn-filters__group" role="group" aria-label="<?php echo esc_attr( $bsn_label ); ?>">
<span class="bsn-filters__label" aria-hidden="true"><?php echo esc_html( $bsn_label ); ?></span>
	<?php
	foreach ( $bsn_terms as $bsn_slug => $bsn_name ) :
		$bsn_on = in_array( (string) $bsn_slug, $bsn_selected, true );
		?>
<a class="bsn-chip<?php echo $bsn_on ? ' is-active' : ''; ?>" href="<?php echo esc_url( Theme::toggle_filter_url( $bsn_current, $bsn_attribute, (string) $bsn_slug ) ); ?>"<?php echo $bsn_on ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $bsn_name ); ?><?php if ( $bsn_on ) : ?><span class="screen-reader-text"> <?php esc_html_e( '(selected, select to remove)', 'bookstore-nepal' ); ?></span><?php endif; ?></a>
	<?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the price filter.
$bsn_max = isset( $_GET['max_price'] ) && is_numeric( $_GET['max_price'] ) ? (float) $_GET['max_price'] : null;
if ( null !== $bsn_max || $bsn_active ) :
	?>
<div class="bsn-filters__group bsn-filters__active">
	<?php if ( null !== $bsn_max ) : ?>
		<?php /* translators: %s: maximum price, e.g. NPR 1,000. */ ?>
<a class="bsn-chip is-active" href="<?php echo esc_url( Theme::remove_filter_arg( $bsn_current, 'max_price' ) ); ?>"><?php echo esc_html( sprintf( __( 'Up to %s', 'bookstore-nepal' ), Theme::npr( $bsn_max ) ) ); ?><span class="screen-reader-text"> <?php esc_html_e( '(select to remove)', 'bookstore-nepal' ); ?></span> <span aria-hidden="true">×</span></a>
	<?php endif; ?>
<a class="bsn-filters__clear" href="<?php echo esc_url( Theme::clear_filters_url( $bsn_current ) ); ?>"><?php esc_html_e( 'Clear all filters', 'bookstore-nepal' ); ?></a>
</div>
<?php endif; ?>
</nav>
<!-- /wp:html -->
