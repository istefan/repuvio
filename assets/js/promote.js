/**
 * RepuVio - "Widget & Semnătură Email": live trust badge configurator and rich-HTML signature copy.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var data = window.RM_DATA || {};
	var preview = document.getElementById( 'rmWidgetPreview' );
	var inlineSlot = document.getElementById( 'rmInlineSlot' );
	var code = document.getElementById( 'rmWidgetCode' );

	function checkedValue( selector, fallback ) {
		var checked = document.querySelector( selector + ':checked' );
		return checked ? checked.value : fallback;
	}

	function refreshWidget() {
		if ( ! preview || ! data.snippets ) {
			return;
		}

		var position = checkedValue( '[data-widget-position]', 'bottom-right' );
		var theme = checkedValue( '[data-widget-theme]', 'light' );

		code.value = data.snippets[ position ][ theme ];

		preview.querySelectorAll( '[data-rm-badge-root]' ).forEach( function ( element ) {
			element.remove();
		} );
		inlineSlot.innerHTML = '';

		if ( 'inline' === position ) {
			// Server-generated, escaped markup (same as the copied snippet).
			inlineSlot.innerHTML = data.inline[ theme ];
			return;
		}

		if ( window.RepuVioBadge ) {
			window.RepuVioBadge.render( preview, {
				url: data.widgetUrl,
				name: data.name,
				position: position,
				theme: theme,
				preview: true
			} );
		}
	}

	document.querySelectorAll( '[data-widget-position], [data-widget-theme]' ).forEach( function ( input ) {
		input.addEventListener( 'change', refreshWidget );
	} );

	refreshWidget();

	function flash( button ) {
		var original = button.innerHTML;

		button.innerHTML = '<i class="bi bi-check2"></i> Copiat! Lipește în semnătură';
		window.setTimeout( function () {
			button.innerHTML = original;
		}, 2000 );
	}

	function copyBySelection( element ) {
		var range = document.createRange();
		var selection = window.getSelection();

		range.selectNodeContents( element );
		selection.removeAllRanges();
		selection.addRange( range );
		document.execCommand( 'copy' );
		selection.removeAllRanges();
	}

	// Rich copy: puts text/html on the clipboard so Gmail / Outlook paste the formatted signature.
	document.querySelectorAll( '[data-copy-html]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var element = document.querySelector( button.getAttribute( 'data-copy-html' ) );

			if ( ! element ) {
				return;
			}

			if ( navigator.clipboard && window.ClipboardItem && window.isSecureContext ) {
				var item = new window.ClipboardItem( {
					'text/html': new Blob( [ element.innerHTML ], { type: 'text/html' } ),
					'text/plain': new Blob( [ element.innerText ], { type: 'text/plain' } )
				} );

				navigator.clipboard.write( [ item ] ).then( function () {
					flash( button );
				}, function () {
					copyBySelection( element );
					flash( button );
				} );

				return;
			}

			copyBySelection( element );
			flash( button );
		} );
	} );
}() );
