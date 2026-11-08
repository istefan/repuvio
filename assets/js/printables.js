/**
 * RepuVio - vector QR rendering for Printables Studio (qrcode-generator 1.4.4).
 * Builds a crisp SVG path from the QR matrix (no raster scaling).
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var settings = window.RM_PRINT || {};

	/**
	 * Builds an SVG string for the given text.
	 *
	 * @param {string} text Data to encode.
	 * @return {string} SVG markup.
	 */
	function buildQrSvg( text ) {
		var qr = window.qrcode( 0, 'M' );
		qr.addData( text );
		qr.make();

		var count = qr.getModuleCount();
		var quiet = 2;
		var size = count + ( quiet * 2 );
		var path = '';

		for ( var row = 0; row < count; row++ ) {
			for ( var col = 0; col < count; col++ ) {
				if ( qr.isDark( row, col ) ) {
					path += 'M' + ( col + quiet ) + ' ' + ( row + quiet ) + 'h1v1h-1z';
				}
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges" role="img" aria-label="Cod QR">' +
			'<rect width="' + size + '" height="' + size + '" fill="#FFFFFF"/>' +
			'<path d="' + path + '" fill="#0F172A"/></svg>';
	}

	window.rmBuildQrSvg = buildQrSvg;

	if ( 'function' !== typeof window.qrcode ) {
		return;
	}

	document.querySelectorAll( '[data-qr]' ).forEach( function ( element ) {
		element.innerHTML = buildQrSvg( element.getAttribute( 'data-qr' ) );
	} );

	// Scale sheets to fit the preview iframe width.
	var sheets = document.getElementById( 'rmSheets' );

	function fitPreview() {
		if ( ! sheets || ! settings.preview ) {
			return;
		}

		sheets.style.transform = 'none';

		var widest = 0;

		sheets.querySelectorAll( '.rm-sheet' ).forEach( function ( sheet ) {
			widest = Math.max( widest, sheet.offsetWidth );
		} );

		var available = document.documentElement.clientWidth - 32;
		var scale = widest > available ? available / widest : 1;

		sheets.style.transform = 'scale(' + scale + ')';
		document.body.style.height = ( sheets.scrollHeight * scale + 32 ) + 'px';
	}

	if ( settings.preview ) {
		window.addEventListener( 'resize', fitPreview );
		window.addEventListener( 'load', fitPreview );
		fitPreview();
	}

	var printButton = document.getElementById( 'rmPrintBtn' );

	if ( printButton ) {
		printButton.addEventListener( 'click', function () {
			window.print();
		} );
	}

	if ( settings.autoPrint ) {
		window.addEventListener( 'load', function () {
			window.setTimeout( function () {
				window.print();
			}, 400 );
		} );
	}
}() );
