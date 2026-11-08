/**
 * RepuVio - public pages behaviour: mobile menu, sticky nav shadow, interactive Smart Funnel demo,
 * ROI estimator, monthly / yearly pricing toggle, cookie banner ("Am înțeles" -> 365-day cookie),
 * legal page print button and table-of-contents highlighting.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var nav = document.getElementById( 'lpNav' );
	var menu = document.getElementById( 'lpMenu' );
	var toggle = document.querySelector( '[data-lp-toggle]' );

	// Mobile menu.
	if ( toggle && menu ) {
		toggle.addEventListener( 'click', function () {
			var open = menu.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		menu.querySelectorAll( 'a[href^="#"]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function () {
				menu.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
			} );
		} );
	}

	// Navbar border once the page scrolls.
	if ( nav ) {
		var syncNav = function () {
			nav.classList.toggle( 'is-scrolled', window.scrollY > 8 );
		};

		window.addEventListener( 'scroll', syncNav, { passive: true } );
		syncNav();
	}

	// Smart Funnel demo: stars light up and the matching branch is highlighted (threshold 3★).
	var stars = document.querySelectorAll( '[data-lp-star]' );
	var branches = document.querySelectorAll( '[data-lp-branch]' );

	stars.forEach( function ( star ) {
		star.addEventListener( 'click', function () {
			var value = parseInt( star.getAttribute( 'data-lp-star' ), 10 );
			var target = value >= 3 ? 'google' : 'private';

			stars.forEach( function ( item ) {
				item.classList.toggle( 'is-on', parseInt( item.getAttribute( 'data-lp-star' ), 10 ) <= value );
			} );

			branches.forEach( function ( branch ) {
				branch.classList.toggle( 'is-active', target === branch.getAttribute( 'data-lp-branch' ) );
			} );
		} );
	} );

	// ROI estimator.
	var inputs = {};

	document.querySelectorAll( '[data-lp-input]' ).forEach( function ( input ) {
		inputs[ input.getAttribute( 'data-lp-input' ) ] = input;
	} );

	function format( number ) {
		return Math.round( number ).toLocaleString( 'ro-RO' );
	}

	function setText( selector, text ) {
		var element = document.querySelector( selector );

		if ( element ) {
			element.textContent = text;
		}
	}

	function syncRoi() {
		if ( ! inputs.clients ) {
			return;
		}

		var clients = parseInt( inputs.clients.value, 10 );
		var scan = parseInt( inputs.scan.value, 10 ) / 100;
		var happy = parseInt( inputs.happy.value, 10 ) / 100;
		var ratings = clients * scan;

		setText( '[data-lp-out="clients"]', format( clients ) );
		setText( '[data-lp-out="scan"]', Math.round( scan * 100 ) + '%' );
		setText( '[data-lp-out="happy"]', Math.round( happy * 100 ) + '%' );
		setText( '[data-lp-result="google"]', format( ratings * happy ) );
		setText( '[data-lp-result="private"]', format( ratings * ( 1 - happy ) ) );
		setText( '[data-lp-result="year"]', format( ratings * happy * 12 ) );
	}

	Object.keys( inputs ).forEach( function ( key ) {
		inputs[ key ].addEventListener( 'input', syncRoi );
	} );

	syncRoi();

	// Pricing toggle.
	document.querySelectorAll( '[data-lp-cycle]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			document.querySelectorAll( '[data-lp-price]' ).forEach( function ( price ) {
				price.hidden = price.getAttribute( 'data-lp-price' ) !== radio.value;
			} );
		} );
	} );

	// Cookie information banner.
	var cookieButton = document.querySelector( '[data-cookie-accept]' );

	if ( cookieButton ) {
		cookieButton.addEventListener( 'click', function () {
			var name = cookieButton.getAttribute( 'data-cookie-name' );
			var secure = '1' === cookieButton.getAttribute( 'data-cookie-secure' ) ? '; Secure' : '';
			var banner = document.querySelector( '[data-cookie-banner]' );

			document.cookie = name + '=1; max-age=31536000; path=/; SameSite=Lax' + secure;

			if ( banner ) {
				banner.remove();
			}
		} );
	}

	// Legal pages: print / PDF.
	document.querySelectorAll( '[data-lp-print]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	} );

	// Legal pages: highlight the current section in the table of contents.
	var sections = document.querySelectorAll( '[data-toc-section]' );

	if ( sections.length && 'IntersectionObserver' in window ) {
		var links = document.querySelectorAll( '[data-toc-link]' );
		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					links.forEach( function ( link ) {
						link.classList.toggle( 'is-current', link.getAttribute( 'href' ) === '#' + entry.target.id );
					} );
				}
			} );
		}, { rootMargin: '-90px 0px -70% 0px' } );

		sections.forEach( function ( section ) {
			observer.observe( section );
		} );
	}
}() );
