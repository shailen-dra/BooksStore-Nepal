( function () {
	var sliders = document.querySelectorAll( '.bsn-slider[id]' );
	Array.prototype.forEach.call( sliders, function ( row ) {
		var prev = document.querySelector( '[data-bsn-slider-prev][aria-controls="' + row.id + '"]' );
		var next = document.querySelector( '[data-bsn-slider-next][aria-controls="' + row.id + '"]' );
		if ( ! prev || ! next ) {
			return;
		}
		var reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );

		function update() {
			var max = row.scrollWidth - row.clientWidth;
			var active = document.activeElement;
			prev.hidden = next.hidden = max <= 1;
			row.classList.toggle( 'is-enhanced', max > 1 );
			prev.disabled = row.scrollLeft <= 1;
			next.disabled = row.scrollLeft >= max - 1;
			// A disabled button cannot hold focus; hand it to the other button.
			if ( active === prev && prev.disabled && ! next.disabled ) {
				next.focus();
			} else if ( active === next && next.disabled && ! prev.disabled ) {
				prev.focus();
			}
		}

		function go( dir ) {
			var card = row.querySelector( 'li' );
			var step = Math.max( row.clientWidth - ( card ? card.offsetWidth : 0 ), card ? card.offsetWidth : 0 );
			row.scrollBy( { left: dir * step, behavior: reduce.matches ? 'auto' : 'smooth' } );
		}

		prev.addEventListener( 'click', function () { go( -1 ); } );
		next.addEventListener( 'click', function () { go( 1 ); } );
		row.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		update();
	} );
}() );
