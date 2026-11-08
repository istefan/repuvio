/**
 * RepuVio - Printables Studio controller (live preview, print link, employee / department
 * ?ref= tag, SVG download).
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var data = window.RM_DATA;
	var frame = document.getElementById( 'rmPreview' );

	if ( ! data || ! frame ) {
		return;
	}

	var cta = document.getElementById( 'rmCta' );
	var refSelect = document.querySelector( '[data-ref-select]' );
	var printLink = document.getElementById( 'rmPrintLink' );
	var label = document.getElementById( 'rmPreviewLabel' );
	var trackedUrl = document.getElementById( 'rmTrackedUrl' );
	var svgButton = document.getElementById( 'rmSvgDownload' );
	var timer = null;

	function currentTemplate() {
		var checked = document.querySelector( '[data-template]:checked' );
		return checked ? checked.value : 'a4';
	}

	function currentRef() {
		return refSelect ? refSelect.value : '';
	}

	function buildUrl( extra ) {
		var params = new URLSearchParams( {
			company_id: String( data.companyId ),
			template: currentTemplate(),
			cta: cta ? cta.value : ''
		} );

		if ( currentRef() ) {
			params.set( 'ref', currentRef() );
		}

		Object.keys( extra ).forEach( function ( key ) {
			params.set( key, extra[ key ] );
		} );

		return data.printUrl + '?' + params.toString();
	}

	function trackedLink() {
		var source = data.sources[ currentTemplate() ] || 'qr';
		var url = data.publicUrl + ( -1 === data.publicUrl.indexOf( '?' ) ? '?' : '&' ) + 'src=' + encodeURIComponent( source );

		if ( currentRef() ) {
			url += '&ref=' + encodeURIComponent( currentRef() );
		}

		return url;
	}

	function refresh() {
		frame.src = buildUrl( { preview: '1' } );
		printLink.href = buildUrl( { print: '1' } );
		trackedUrl.textContent = trackedLink();

		var checked = document.querySelector( '[data-template]:checked' );
		if ( checked && label ) {
			var text = checked.parentNode.querySelector( '.fw-semibold' ).textContent;

			if ( refSelect && refSelect.selectedIndex > 0 ) {
				text += ' · ' + refSelect.options[ refSelect.selectedIndex ].getAttribute( 'data-label' );
			}

			label.textContent = text;
		}
	}

	document.querySelectorAll( '[data-template]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', refresh );
	} );

	if ( refSelect ) {
		refSelect.addEventListener( 'change', refresh );
	}

	if ( cta ) {
		cta.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 450 );
		} );
	}

	document.querySelectorAll( '[data-cta-suggestion]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			cta.value = button.getAttribute( 'data-cta-suggestion' );
			refresh();
		} );
	} );

	if ( svgButton ) {
		svgButton.addEventListener( 'click', function () {
			if ( 'function' !== typeof window.rmBuildQrSvg ) {
				return;
			}

			var blob = new Blob( [ window.rmBuildQrSvg( trackedLink() ) ], { type: 'image/svg+xml' } );
			var link = document.createElement( 'a' );

			link.href = URL.createObjectURL( blob );
			link.download = 'qr-' + data.slug + '-' + currentTemplate() + ( currentRef() ? '-' + currentRef() : '' ) + '.svg';
			document.body.appendChild( link );
			link.click();
			link.remove();
		} );
	}

	refresh();
}() );
