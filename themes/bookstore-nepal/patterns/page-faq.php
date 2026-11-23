<?php
/**
 * Title: Page: Frequently asked questions
 * Slug: bookstore-nepal/page-faq
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Common questions answered with expandable Details blocks (no JavaScript needed).
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Wording::rich( 'bsn_faq_intro' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?><?php
// The automatic payment questions close the first group: before the second heading, or at the end.
$bsn_paid = false;
$bsn_pay  = static function () use ( &$bsn_paid ): void {
	$bsn_paid = true;
	foreach ( Wording::payment_questions() as $bsn_q ) {
		echo '<details class="wp-block-details bsn-faq"><summary>' . esc_html( $bsn_q['question'] ) . '</summary><p>' . esc_html( $bsn_q['answer'] ) . '</p></details>' . "\n";
	}
};
foreach ( Wording::rows( 'bsn_faq_items' ) as $bsn_i => $bsn_row ) :
	if ( ! $bsn_paid && $bsn_i > 0 && '' !== $bsn_row['heading'] ) {
		$bsn_pay();
	}
	?>
<?php if ( '' !== $bsn_row['heading'] ) : ?>
<h2 class="wp-block-heading"><?php echo esc_html( $bsn_row['heading'] ); ?></h2>
<?php endif; ?>
<details class="wp-block-details bsn-faq"><summary><?php echo esc_html( $bsn_row['question'] ); ?></summary><?php echo wp_kses_post( wpautop( $bsn_row['answer'] ) ); ?></details>
<?php endforeach; ?>
<?php
if ( ! $bsn_paid ) {
	$bsn_pay();
}
?></div>
<!-- /wp:group -->
