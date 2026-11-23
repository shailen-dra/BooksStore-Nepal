/**
 * Playful motion: sections and book cards pop in as they scroll into view, and the cart
 * button jiggles when a book is added. Does nothing when the visitor asks for reduced motion,
 * and without this script (or IntersectionObserver) everything simply shows as normal.
 */
( function () {
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' );
	if ( ( reduce && reduce.matches ) || ! ( 'IntersectionObserver' in window ) ) {
		return;
	}

	var root = document.documentElement;
	var targets = document.querySelectorAll(
		'.bsn-main .bsn-section__head, .bsn-main .bsn-products:not(.bsn-slider) .wc-block-product, .bsn-age-tile, .bsn-gift, .bsn-trust__list li, .bsn-book-details, .bsn-delivery, .bsn-faq'
	);

	var observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'is-shown' );
					observer.unobserve( entry.target );
				}
			} );
		},
		{ rootMargin: '0px 0px -8% 0px', threshold: 0.08 }
	);

	// Cards and tiles in the same row pop in one after another. Slider cards are left alone: their
	// scrolling row clips anything that moves above it. Anything already on screen
	// when this runs stays put, so nothing the visitor can already see blinks out and back.
	var fold = window.innerHeight || root.clientHeight;
	Array.prototype.forEach.call( targets, function ( el ) {
		if ( el.getBoundingClientRect().top < fold ) {
			return;
		}
		var siblings = el.parentElement ? el.parentElement.children : [];
		var index = Array.prototype.indexOf.call( siblings, el );
		el.style.setProperty( '--bsn-pop-delay', Math.min( Math.max( index, 0 ), 5 ) * 70 + 'ms' );
		el.classList.add( 'bsn-pop' );
		observer.observe( el );
	} );
	root.classList.add( 'bsn-motion' );

	// Jiggle the cart when a book goes in.
	function jiggle() {
		var cart = document.querySelector( '.bsn-header__cart .wc-block-mini-cart__button' );
		if ( ! cart ) {
			return;
		}
		cart.classList.remove( 'is-jiggling' );
		void cart.offsetWidth; // Restart the animation if it is already running.
		cart.classList.add( 'is-jiggling' );
	}
	document.body.addEventListener( 'wc-blocks_added_to_cart', jiggle );
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', jiggle );
	}
	document.addEventListener( 'animationend', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'is-jiggling' ) ) {
			e.target.classList.remove( 'is-jiggling' );
		}
	} );
} )();
