/**
 * Two screen-reader fixes the server-rendered markup cannot make on its own.
 *
 * 1. WooCommerce's mini-cart drawer stays in the page while closed, hidden with aria-hidden but its
 *    links still reachable with Tab. `inert` follows aria-hidden so a closed drawer takes no focus.
 * 2. Text written in Devanagari gets lang="ne" so screen readers read it with a Nepali voice instead of
 *    spelling it out with the English one. Runs again on content added later (cart, filters).
 */
( function () {
	'use strict';

	var DEVANAGARI = /[ऀ-ॿ]/g;
	var LATIN = /[A-Za-z]/g;

	function syncDrawer( drawer ) {
		drawer.inert = drawer.getAttribute( 'aria-hidden' ) === 'true';
	}

	function watchDrawers( root ) {
		var drawers = root.querySelectorAll ? root.querySelectorAll( '.wc-block-mini-cart__drawer' ) : [];
		Array.prototype.forEach.call( drawers, function ( drawer ) {
			if ( drawer.dataset.bsnInert ) {
				return;
			}
			drawer.dataset.bsnInert = '1';
			syncDrawer( drawer );
			new MutationObserver( function () {
				syncDrawer( drawer );
			} ).observe( drawer, { attributes: true, attributeFilter: [ 'aria-hidden' ] } );
		} );
	}

	function mostlyNepali( text ) {
		var ne = ( text.match( DEVANAGARI ) || [] ).length;
		return ne > 0 && ne >= ( text.match( LATIN ) || [] ).length;
	}

	function markNepali( root ) {
		if ( ! root || root.nodeType !== 1 && root.nodeType !== 9 ) {
			return;
		}
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, {
			acceptNode: function ( node ) {
				return /[ऀ-ॿ]/.test( node.nodeValue ) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			},
		} );
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var el = node.parentElement;
			if ( ! el || /^(SCRIPT|STYLE|TEXTAREA|TITLE)$/.test( el.tagName ) ) {
				continue;
			}
			var scope = el.closest( '[lang]' );
			if ( scope && scope.lang.indexOf( 'ne' ) === 0 ) {
				continue;
			}
			if ( mostlyNepali( el.textContent ) ) {
				el.lang = 'ne';
			}
		}
	}

	function run( root ) {
		watchDrawers( root );
		markNepali( root );
	}

	function start() {
		run( document.body );
		var pending = [];
		var queued = false;
		new MutationObserver( function ( records ) {
			records.forEach( function ( record ) {
				Array.prototype.forEach.call( record.addedNodes, function ( added ) {
					pending.push( added.nodeType === 3 ? added.parentElement : added );
				} );
			} );
			if ( queued || ! pending.length ) {
				return;
			}
			queued = true;
			window.requestAnimationFrame( function () {
				var roots = pending;
				pending = [];
				queued = false;
				roots.forEach( function ( root ) {
					if ( root && root.isConnected ) {
						run( root );
					}
				} );
			} );
		} ).observe( document.body, { childList: true, subtree: true, characterData: false } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
