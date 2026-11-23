<?php
/**
 * Default wording for the about page: box field name => content items for Theme::html().
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_shop = Theme::shop_url();

return array(
	'bsn_about_text' => array(
			array( 'p', __( 'We are a bookshop for young readers. We sell children\'s books in Nepali and in English, for ages 0 to 12, and we deliver them to your door anywhere in Nepal.', 'bookstore-nepal' ) ),
			array( 'h2', __( 'What you will find', 'bookstore-nepal' ) ),
			array(
				'ul',
				array(
					esc_html__( 'Picture books, rhymes and first words for ages 0–3.', 'bookstore-nepal' ),
					esc_html__( 'Story books and early readers for ages 4–7.', 'bookstore-nepal' ),
					esc_html__( 'Longer stories and chapter books for ages 8–12.', 'bookstore-nepal' ),
					esc_html__( 'Books in Nepali and English, so children can grow up reading in both.', 'bookstore-nepal' ),
				),
			),
			array(
				'p_html',
				sprintf(
					/* translators: 1: link to the shop, 2: link to the gift finder. */
					esc_html__( 'You can %1$s by age, language and type of book. Buying for someone else? Try the %2$s: tell us the child\'s age and your budget, and we will show books that suit.', 'bookstore-nepal' ),
					'<a href="' . esc_url( $bsn_shop ) . '">' . esc_html__( 'browse the shop', 'bookstore-nepal' ) . '</a>',
					'<a href="' . esc_url( home_url( '/#gift' ) ) . '">' . esc_html__( 'gift finder', 'bookstore-nepal' ) . '</a>'
				),
			),
			array( 'h2', __( 'Our story', 'bookstore-nepal' ) ),
			array( 'note', __( '[CLIENT: Write the shop\'s story here in two or three short paragraphs: who started it, why, and what you love about children\'s books. Add a photo if you have one.]', 'bookstore-nepal' ) ),
			array( 'h2', __( 'Find us', 'bookstore-nepal' ) ),
			array(
				'p_html',
				sprintf(
					/* translators: %s: link to the Contact page. */
					esc_html__( 'Our phone, WhatsApp, email and address are on the %s page.', 'bookstore-nepal' ),
					Theme::page_link( 'contact', __( 'Contact us', 'bookstore-nepal' ) )
				),
			),
			array( 'note', __( '[CLIENT: Say whether customers can visit the shop, and add the opening hours if so.]', 'bookstore-nepal' ) ),
	),
);
