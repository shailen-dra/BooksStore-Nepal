<?php
/**
 * Default wording for the returns page: box field name => content items for Theme::html().
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

return array(
	'bsn_returns_text' => array(
			array( 'p', __( 'We want you to be happy with your books. If something is not right, please tell us and we will put it right.', 'bookstore-nepal' ) ),
			array( 'h2', __( 'When you can send a book back', 'bookstore-nepal' ) ),
			array( 'p', __( 'Tell us within [CLIENT: 7] days of delivery if:', 'bookstore-nepal' ) ),
			array(
				'ul',
				array(
					esc_html__( 'the book arrived damaged, for example torn or missing pages or a misprint, or', 'bookstore-nepal' ),
					esc_html__( 'we sent a different book from the one you ordered.', 'bookstore-nepal' ),
				),
			),
			array( 'h2', __( 'What we cannot take back', 'bookstore-nepal' ) ),
			array(
				'ul',
				array(
					esc_html__( 'Books that have been written in, damaged or used after they were delivered.', 'bookstore-nepal' ),
					esc_html__( 'Books you tell us about after the [CLIENT: 7] days.', 'bookstore-nepal' ),
					esc_html__( '[CLIENT: Say whether you accept a book back if the child simply changes their mind, and in what condition.]', 'bookstore-nepal' ),
				),
			),
			array( 'h2', __( 'How to ask', 'bookstore-nepal' ) ),
			array(
				'p_html',
				sprintf(
					/* translators: %s: link to the Contact page. */
					esc_html__( 'Contact us on WhatsApp, by phone or by email (details on the %s page). Send your order number and a photo of the problem. We reply within [CLIENT: 2 working days] and tell you what to do next. Please wait for our reply before you send anything back.', 'bookstore-nepal' ),
					Theme::page_link( 'contact', __( 'Contact us', 'bookstore-nepal' ) )
				),
			),
			array( 'h2', __( 'Replacement or refund', 'bookstore-nepal' ) ),
			array( 'p', __( 'For a damaged or wrong book we will first offer a replacement. If you would rather have your money back, or the book is out of stock, we refund the price of the book:', 'bookstore-nepal' ) ),
			array(
				'ul',
				array(
					esc_html__( 'If you paid with eSewa, the refund goes back to your eSewa wallet, usually within [CLIENT: 5 working days].', 'bookstore-nepal' ),
					esc_html__( 'If you paid cash on delivery, we refund you in cash or by [CLIENT: eSewa or bank transfer].', 'bookstore-nepal' ),
				),
			),
			array( 'h2', __( 'Who pays for return delivery', 'bookstore-nepal' ) ),
			array( 'p', __( 'If the book arrived damaged or was the wrong one, we pay for the return delivery. [CLIENT: Confirm, and say what happens in any other case.]', 'bookstore-nepal' ) ),
	),
);
