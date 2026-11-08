/**
 * RepuVio - "Ajutor & Ghid": instant search across guides, role cards and FAQ accordions.
 * Matching ignores case and Romanian diacritics; while searching, all FAQ categories are shown.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var root = document.getElementById( 'rmHelp' );
	var input = document.getElementById( 'rmHelpSearch' );

	if ( ! root || ! input ) {
		return;
	}

	var items = root.querySelectorAll( '[data-help-item]' );
	var panes = root.querySelectorAll( '.rm-help-faq .tab-pane' );
	var headings = root.querySelectorAll( '[data-help-heading]' );
	var empty = document.getElementById( 'rmHelpEmpty' );
	var count = document.getElementById( 'rmHelpCount' );
	var timer = null;

	function normalize( text ) {
		return ( text || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[şș]/g, 's' ).replace( /[ţț]/g, 't' );
	}

	items.forEach( function ( item ) {
		item.setAttribute( 'data-help-text', normalize( item.textContent ) );
	} );

	function apply() {
		var terms = normalize( input.value ).split( /\s+/ ).filter( Boolean );
		var searching = terms.length > 0;
		var visible = 0;

		root.classList.toggle( 'is-searching', searching );

		items.forEach( function ( item ) {
			var text = item.getAttribute( 'data-help-text' );
			var match = ! searching || terms.every( function ( term ) {
				return -1 !== text.indexOf( term );
			} );

			item.classList.toggle( 'd-none', ! match );

			if ( match ) {
				visible++;
			}

			// Open the collapsed admin documentation when it matches. FAQ accordions stay closed:
			// they share a data-bs-parent, so opening one would close the other matches.
			var collapse = item.querySelector( '[data-help-collapse]' );

			if ( collapse && window.bootstrap ) {
				var instance = window.bootstrap.Collapse.getOrCreateInstance( collapse, { toggle: false } );

				if ( searching && match ) {
					instance.show();
				} else if ( searching ) {
					instance.hide();
				}
			}
		} );

		panes.forEach( function ( pane ) {
			pane.classList.toggle( 'is-empty', 0 === pane.querySelectorAll( '[data-help-item]:not(.d-none)' ).length );
		} );

		headings.forEach( function ( heading ) {
			heading.classList.toggle( 'd-none', searching );
		} );

		if ( empty ) {
			empty.classList.toggle( 'd-none', ! searching || 0 < visible );
		}

		if ( count ) {
			count.classList.toggle( 'd-none', ! searching );
			count.textContent = visible + ( 1 === visible ? ' rezultat' : ' rezultate' );
		}
	}

	input.addEventListener( 'input', function () {
		window.clearTimeout( timer );
		timer = window.setTimeout( apply, 120 );
	} );

	input.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			input.value = '';
			apply();
		}
	} );
}() );
