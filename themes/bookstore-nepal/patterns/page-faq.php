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

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_rates   = Theme::delivery_rates();
$bsn_methods = Theme::payment_methods();
$bsn_link    = array(
	'delivery' => Theme::page_link( 'delivery', __( 'Delivery information', 'bookstore-nepal' ) ),
	'tracking' => Theme::page_link( 'tracking', __( 'Track your order', 'bookstore-nepal' ) ),
	'returns'  => Theme::page_link( 'returns', __( 'Returns & refunds', 'bookstore-nepal' ) ),
	'contact'  => Theme::page_link( 'contact', __( 'Contact us', 'bookstore-nepal' ) ),
);

if ( null !== $bsn_rates['valley'] && null !== $bsn_rates['rest'] ) {
	$bsn_cost = sprintf(
		/* translators: 1: Valley charge, 2: rest of Nepal charge, 3: free-delivery text, 4: link to Delivery information. */
		esc_html__( 'Delivery is %1$s inside Kathmandu Valley and %2$s in the rest of Nepal.%3$s Delivery times are on our %4$s page.', 'bookstore-nepal' ),
		esc_html( Theme::npr( $bsn_rates['valley'] ) ),
		esc_html( Theme::npr( $bsn_rates['rest'] ) ),
		null === $bsn_rates['free_over'] ? '' : ' ' . esc_html( sprintf( /* translators: %s: order total, e.g. NPR 2,000. */ __( 'It is free on orders over %s.', 'bookstore-nepal' ), Theme::npr( $bsn_rates['free_over'] ) ) ),
		$bsn_link['delivery']
	);
} else {
	/* translators: %s: link to Delivery information. */
	$bsn_cost = sprintf( esc_html__( 'You will find our delivery charges and times on the %s page.', 'bookstore-nepal' ), $bsn_link['delivery'] );
}

$bsn_ordering = array(
	array( 'h2', __( 'Ordering and delivery', 'bookstore-nepal' ) ),
	array( 'qa', __( 'How much does delivery cost, and how long does it take?', 'bookstore-nepal' ), $bsn_cost ),
);
if ( isset( $bsn_methods['cod'] ) ) {
	$bsn_ordering[] = array( 'qa', __( 'Can I pay when the books arrive?', 'bookstore-nepal' ), esc_html__( 'Yes. Choose cash on delivery at checkout and pay the rider in cash when your books arrive. Please keep your phone nearby, because the rider calls before arriving.', 'bookstore-nepal' ) );
}
if ( isset( $bsn_methods['esewa'] ) ) {
	$bsn_ordering[] = array( 'qa', __( 'How does paying with eSewa work?', 'bookstore-nepal' ), esc_html__( 'Choose eSewa at checkout and place your order. You are taken to eSewa to approve the payment, then brought back to our shop with your confirmation. We never see your eSewa PIN or password. If eSewa charged you but you did not see a confirmation, message us with your phone number and we will check.', 'bookstore-nepal' ) );
}
$bsn_ordering[] = array(
	'qa',
	__( 'How do I track my order?', 'bookstore-nepal' ),
	sprintf(
		/* translators: %s: link to the Track your order page. */
		esc_html__( 'Open the %s page and enter your order number and the email address you used at checkout. You will see whether your order is Processing, Packed, Shipped or Delivered.', 'bookstore-nepal' ),
		$bsn_link['tracking']
	),
);
$bsn_ordering[] = array( 'qa', __( 'Do you deliver outside Nepal?', 'bookstore-nepal' ), esc_html__( 'Not at the moment. We deliver to all 77 districts of Nepal.', 'bookstore-nepal' ) );
$bsn_ordering[] = array(
	'qa',
	__( 'Can I change my order after I place it?', 'bookstore-nepal' ),
	sprintf(
		/* translators: %s: link to the Contact page. */
		esc_html__( 'Often, yes, if we have not packed it yet. Message us on WhatsApp as soon as you can with your order number. Our details are on the %s page.', 'bookstore-nepal' ),
		$bsn_link['contact']
	),
);

$bsn_books = array(
	array( 'h2', __( 'Choosing books', 'bookstore-nepal' ) ),
	array( 'qa', __( 'What ages are your books for?', 'bookstore-nepal' ), esc_html__( 'We sell books for ages 0 to 12 and group them as 0–3, 4–7 and 8–12. Every book shows its age group, and you can filter the shop by age.', 'bookstore-nepal' ) ),
	array(
		'qa',
		__( 'Do you have books in Nepali?', 'bookstore-nepal' ),
		sprintf(
			/* translators: %s: link to the shop. */
			esc_html__( 'Yes. We sell books in both Nepali and English. Use the language filter in the %s to see just one.', 'bookstore-nepal' ),
			'<a href="' . esc_url( Theme::shop_url() ) . '">' . esc_html__( 'shop', 'bookstore-nepal' ) . '</a>'
		),
	),
	array(
		'qa',
		__( 'I am not sure what to buy as a gift. Can you help?', 'bookstore-nepal' ),
		sprintf(
			/* translators: %s: link to the gift finder. */
			esc_html__( 'Try our %s. Tell it the child\'s age and your budget, and it shows books that suit.', 'bookstore-nepal' ),
			'<a href="' . esc_url( home_url( '/#gift' ) ) . '">' . esc_html__( 'gift finder', 'bookstore-nepal' ) . '</a>'
		),
	),
);

$bsn_help = array(
	array( 'h2', __( 'Returns and help', 'bookstore-nepal' ) ),
	array(
		'qa',
		__( 'What if my book arrives damaged or is the wrong one?', 'bookstore-nepal' ),
		sprintf(
			/* translators: %s: link to the Returns and refunds page. */
			esc_html__( 'Tell us as soon as you can and we will put it right. The steps are on our %s page.', 'bookstore-nepal' ),
			$bsn_link['returns']
		),
	),
	array(
		'qa',
		__( 'How can I contact you?', 'bookstore-nepal' ),
		sprintf(
			/* translators: %s: link to the Contact page. */
			esc_html__( 'WhatsApp is the fastest way. You will find our phone, WhatsApp and email on the %s page.', 'bookstore-nepal' ),
			$bsn_link['contact']
		),
	),
);
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Theme::blocks( array_merge( $bsn_ordering, $bsn_books, $bsn_help ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Theme::blocks(). ?></div>
<!-- /wp:group -->
