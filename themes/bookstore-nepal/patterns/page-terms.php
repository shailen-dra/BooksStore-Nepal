<?php
/**
 * Title: Page: Terms & conditions
 * Slug: bookstore-nepal/page-terms
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Terms of sale; to be reviewed with the client before launch.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php
echo Theme::blocks( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Theme::blocks().
	array(
		array(
			'p_html',
			sprintf(
				/* translators: 1: link to Delivery information, 2: link to Returns and refunds, 3: link to Privacy policy. */
				esc_html__( 'By placing an order with us you agree to these terms. Please read them together with our %1$s, %2$s and %3$s pages.', 'bookstore-nepal' ),
				Theme::page_link( 'delivery', __( 'Delivery information', 'bookstore-nepal' ) ),
				Theme::page_link( 'returns', __( 'Returns & refunds', 'bookstore-nepal' ) ),
				Theme::page_link( 'privacy', __( 'Privacy policy', 'bookstore-nepal' ) )
			),
		),
		array( 'h2', __( 'Ordering', 'bookstore-nepal' ) ),
		array( 'p', __( 'You order by adding books to your cart and checking out. Please give a correct delivery address and a phone number we can reach. We may cancel an order if a book is not available or the details look wrong. If we do, we contact you and refund anything you have paid.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Prices', 'bookstore-nepal' ) ),
		array( 'p', __( 'All prices are in Nepali rupees (NPR). [CLIENT: Say whether prices include VAT.] The delivery charge is shown at checkout and added to your total. If we find a price on the site is wrong, we tell you before we send your order, and you can choose to go ahead or cancel.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Availability', 'bookstore-nepal' ) ),
		array( 'p', __( 'Our stock is limited. If a book sells out before we can pack your order, we contact you to offer another book or a refund.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Payment', 'bookstore-nepal' ) ),
		array( 'p', __( 'You can pay with eSewa or with cash on delivery. If you pay cash on delivery, please make sure someone can receive the books and pay the rider. [CLIENT: Say what happens if a cash-on-delivery order is refused or cannot be delivered.]', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Delivery', 'bookstore-nepal' ) ),
		array( 'p', __( 'Delivery times we give are estimates, not promises. Delays caused by couriers, weather, road closures, strikes or holidays are outside our control. The books are yours once they are delivered to the address you gave us.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Returns and refunds', 'bookstore-nepal' ) ),
		array(
			'p_html',
			sprintf(
				/* translators: %s: link to the Returns and refunds page. */
				esc_html__( 'If something is wrong with your books, our %s page explains what to do.', 'bookstore-nepal' ),
				Theme::page_link( 'returns', __( 'Returns & refunds', 'bookstore-nepal' ) )
			),
		),
		array( 'h2', __( 'Our responsibility', 'bookstore-nepal' ) ),
		array( 'p', __( 'We take care to describe our books correctly. As far as the law allows, our responsibility for any order is limited to the price you paid for it.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Governing law', 'bookstore-nepal' ) ),
		array( 'p', __( 'These terms are governed by the laws of Nepal, and any dispute will be dealt with by the courts of Nepal. [CLIENT: Add the city or district if you want one named.]', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Changes to these terms', 'bookstore-nepal' ) ),
		array( 'p', __( 'We may change these terms from time to time. The terms on this page when you place your order are the ones that apply to that order.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Contact', 'bookstore-nepal' ) ),
		array(
			'p_html',
			sprintf(
				/* translators: %s: link to the Contact page. */
				esc_html__( 'Questions about these terms? Get in touch using the details on the %s page.', 'bookstore-nepal' ),
				Theme::page_link( 'contact', __( 'Contact us', 'bookstore-nepal' ) )
			),
		),
	)
);
?></div>
<!-- /wp:group -->
