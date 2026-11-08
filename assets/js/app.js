/**
 * RepuVio - dashboard behaviours (no inline handlers; CSP friendly).
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	// Progress bars: <div class="progress-bar" data-width="42">.
	document.querySelectorAll( '[data-width]' ).forEach( function ( bar ) {
		var width = Math.max( 0, Math.min( 100, parseInt( bar.getAttribute( 'data-width' ), 10 ) || 0 ) );
		bar.style.width = width + '%';
	} );

	// Auto-submit selects (company switcher, quick filters).
	document.querySelectorAll( '[data-autosubmit]' ).forEach( function ( element ) {
		element.addEventListener( 'change', function () {
			if ( element.form ) {
				element.form.submit();
			}
		} );
	} );

	// Confirmation for destructive actions: <form data-confirm="Mesaj">.
	document.querySelectorAll( 'form[data-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// Same confirmation on a single submit button: <button type="submit" data-confirm="Mesaj">.
	document.querySelectorAll( 'button[data-confirm]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( button.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// Copy to clipboard: <button data-copy="#inputId">.
	document.querySelectorAll( '[data-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var target = document.querySelector( button.getAttribute( 'data-copy' ) );
			var original = button.innerHTML;

			if ( ! target ) {
				return;
			}

			var value = target.value || target.textContent;
			var done = function () {
				button.innerHTML = '<i class="bi bi-check2"></i> Copiat';
				window.setTimeout( function () {
					button.innerHTML = original;
				}, 1500 );
			};

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( value ).then( done );
			} else {
				target.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );

	/*
	 * SVG logos / covers: <input type="file" data-svg-raster="1200">.
	 * The server only accepts JPG / PNG / WEBP (an SVG can carry scripts and GD cannot read it), so the
	 * browser renders the SVG to a transparent PNG (longest side = the attribute value) and swaps the file
	 * before upload. Registered before the preview handler so it can stop the original change event.
	 */
	function isSvgFile( file ) {
		return file && ( 'image/svg+xml' === file.type || /\.svg$/i.test( file.name ) );
	}

	function rasterizeSvg( file, maxSide ) {
		return file.text().then( function ( text ) {
			var doc = new DOMParser().parseFromString( text, 'image/svg+xml' );
			var svg = doc.documentElement;

			if ( ! svg || 'svg' !== svg.nodeName.toLowerCase() || doc.querySelector( 'parsererror' ) ) {
				throw new Error( 'invalid' );
			}

			// Intrinsic size: width/height attributes, else viewBox, else a square.
			var box = ( svg.getAttribute( 'viewBox' ) || '' ).trim().split( /[\s,]+/ ).map( parseFloat );
			var width = parseFloat( svg.getAttribute( 'width' ) ) || ( 4 === box.length ? box[ 2 ] : 0 ) || 512;
			var height = parseFloat( svg.getAttribute( 'height' ) ) || ( 4 === box.length ? box[ 3 ] : 0 ) || 512;
			var scale = maxSide / Math.max( width, height );
			var targetW = Math.max( 1, Math.round( width * scale ) );
			var targetH = Math.max( 1, Math.round( height * scale ) );

			if ( 4 !== box.length ) {
				svg.setAttribute( 'viewBox', '0 0 ' + width + ' ' + height );
			}

			svg.setAttribute( 'width', String( targetW ) );
			svg.setAttribute( 'height', String( targetH ) );

			var url = URL.createObjectURL( new Blob( [ new XMLSerializer().serializeToString( svg ) ], { type: 'image/svg+xml' } ) );

			return new Promise( function ( resolve, reject ) {
				var image = new Image();

				image.onload = function () {
					var canvas = document.createElement( 'canvas' );

					canvas.width = targetW;
					canvas.height = targetH;
					canvas.getContext( '2d' ).drawImage( image, 0, 0, targetW, targetH );
					URL.revokeObjectURL( url );

					try {
						canvas.toBlob( function ( blob ) {
							if ( blob ) {
								resolve( new File( [ blob ], file.name.replace( /\.svg$/i, '' ) + '.png', { type: 'image/png' } ) );
							} else {
								reject( new Error( 'blob' ) );
							}
						}, 'image/png' );
					} catch ( error ) {
						reject( error );
					}
				};

				image.onerror = function () {
					URL.revokeObjectURL( url );
					reject( new Error( 'load' ) );
				};

				image.src = url;
			} );
		} );
	}

	document.querySelectorAll( 'input[type="file"][data-svg-raster]' ).forEach( function ( input ) {
		input.addEventListener( 'change', function ( event ) {
			var file = input.files && input.files[ 0 ];

			if ( ! isSvgFile( file ) ) {
				return;
			}

			event.stopImmediatePropagation();

			rasterizeSvg( file, parseInt( input.getAttribute( 'data-svg-raster' ), 10 ) || 1200 ).then( function ( png ) {
				var transfer = new DataTransfer();

				transfer.items.add( png );
				input.files = transfer.files;
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} ).catch( function () {
				window.alert( 'Fișierul SVG nu a putut fi convertit automat. Salvează logo-ul ca PNG (fundal transparent) și încearcă din nou.' );
				input.value = '';
			} );
		} );
	} );

	/*
	 * Image preview for file inputs: <input type="file" data-preview="#imgId">.
	 * Optional size check: data-image-hint="#hintId" data-min-width="300" data-min-height="300"
	 * data-ratio="1" (expected width / height) data-ratio-hint="text shown when the ratio differs".
	 */
	document.querySelectorAll( 'input[type="file"][data-preview]' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			var preview = document.querySelector( input.getAttribute( 'data-preview' ) );
			var hint = input.getAttribute( 'data-image-hint' ) ? document.querySelector( input.getAttribute( 'data-image-hint' ) ) : null;

			if ( ! preview || ! input.files || ! input.files[ 0 ] ) {
				return;
			}

			if ( input.files[ 0 ].size > 5242880 ) {
				window.alert( 'Fișierul depășește 5 MB.' );
				input.value = '';
				return;
			}

			preview.onload = function () {
				if ( ! hint ) {
					return;
				}

				var width = preview.naturalWidth;
				var height = preview.naturalHeight;
				var minWidth = parseInt( input.getAttribute( 'data-min-width' ), 10 ) || 0;
				var minHeight = parseInt( input.getAttribute( 'data-min-height' ), 10 ) || 0;
				var ratio = parseFloat( input.getAttribute( 'data-ratio' ) ) || 0;
				var messages = [];

				if ( width < minWidth || height < minHeight ) {
					messages.push( 'Rezoluție mică — poate apărea neclară la print. Recomandat minim ' + minWidth + ' × ' + minHeight + ' px.' );
				}

				if ( ratio && Math.abs( ( width / height ) - ratio ) / ratio > 0.25 ) {
					messages.push( input.getAttribute( 'data-ratio-hint' ) || 'Proporțiile diferă de cele recomandate.' );
				}

				hint.classList.remove( 'd-none', 'text-success', 'text-warning' );
				hint.classList.add( messages.length ? 'text-warning' : 'text-success' );
				hint.textContent = 'Imagine aleasă: ' + width + ' × ' + height + ' px. ' + ( messages.length ? messages.join( ' ' ) : 'Dimensiune potrivită.' );
			};

			preview.src = URL.createObjectURL( input.files[ 0 ] );
			preview.classList.remove( 'd-none' );
		} );
	} );

	// Slug auto-generation from company name (only while the slug field is untouched).
	var nameInput = document.querySelector( '[data-slug-source]' );
	var slugInput = document.querySelector( '[data-slug-target]' );

	if ( nameInput && slugInput ) {
		var touched = '' !== slugInput.value;
		var map = { 'ă': 'a', 'â': 'a', 'î': 'i', 'ș': 's', 'ş': 's', 'ț': 't', 'ţ': 't' };

		slugInput.addEventListener( 'input', function () {
			touched = true;
		} );

		nameInput.addEventListener( 'input', function () {
			if ( touched ) {
				return;
			}

			slugInput.value = nameInput.value
				.toLowerCase()
				.replace( /[ăâîșşțţ]/g, function ( c ) {
					return map[ c ];
				} )
				.normalize( 'NFD' )
				.replace( /[̀-ͯ]/g, '' )
				.replace( /&/g, ' si ' )
				.replace( /[^a-z0-9]+/g, '-' )
				.replace( /^-+|-+$/g, '' )
				.substring( 0, 80 );
		} );
	}

	// Prefill an amount from the selected option: <select data-price-target="#input"><option data-price="100.00">.
	document.querySelectorAll( 'select[data-price-target]' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			var target = document.querySelector( select.getAttribute( 'data-price-target' ) );
			var option = select.options[ select.selectedIndex ];

			if ( target && option && option.getAttribute( 'data-price' ) ) {
				target.value = option.getAttribute( 'data-price' );
			}
		} );
	} );

	// Show a block only for the "custom" option: <select data-toggle-custom="#blockId">.
	document.querySelectorAll( 'select[data-toggle-custom]' ).forEach( function ( select ) {
		var block = document.querySelector( select.getAttribute( 'data-toggle-custom' ) );

		if ( ! block ) {
			return;
		}

		select.addEventListener( 'change', function () {
			block.classList.toggle( 'd-none', 'custom' !== select.value );
		} );
	} );

	// Live order total: <input data-order-qty data-price="99.00"> inside [data-order-form], total in [data-order-total].
	document.querySelectorAll( '[data-order-form]' ).forEach( function ( form ) {
		var output = form.querySelector( '[data-order-total]' );
		var sync = function () {
			var total = 0;

			form.querySelectorAll( '[data-order-qty]' ).forEach( function ( input ) {
				total += ( parseInt( input.value, 10 ) || 0 ) * parseFloat( input.getAttribute( 'data-price' ) || '0' );
			} );

			if ( output ) {
				output.textContent = total.toLocaleString( 'ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' RON';
			}
		};

		form.querySelectorAll( '[data-order-qty]' ).forEach( function ( input ) {
			input.addEventListener( 'input', sync );
		} );
		sync();
	} );

	// Billing: Lunar / Anual toggle only switches the displayed prices ([data-cycle-show]).
	var cycleInputs = document.querySelectorAll( '[data-cycle-toggle]' );
	var currentCycle = 'monthly';

	cycleInputs.forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			currentCycle = input.value;
			document.querySelectorAll( '[data-cycle-show]' ).forEach( function ( block ) {
				block.classList.toggle( 'd-none', block.getAttribute( 'data-cycle-show' ) !== currentCycle );
			} );
		} );
	} );

	// Billing: the "Comandă" button opens a confirmation modal filled with the plan and selected cycle.
	var planModal = document.getElementById( 'rmPlanModal' );

	if ( planModal ) {
		planModal.addEventListener( 'show.bs.modal', function ( event ) {
			var button = event.relatedTarget;

			if ( ! button ) {
				return;
			}

			var yearly = 'yearly' === currentCycle;

			planModal.querySelector( '[data-modal-plan-id]' ).value = button.getAttribute( 'data-plan-id' );
			planModal.querySelector( '[data-modal-cycle]' ).value = currentCycle;
			planModal.querySelector( '[data-modal-plan-name]' ).textContent = button.getAttribute( 'data-plan-name' );
			planModal.querySelector( '[data-modal-cycle-label]' ).textContent = yearly ? 'Anual (2 luni gratuite)' : 'Lunar';
			planModal.querySelector( '[data-modal-price]' ).textContent = button.getAttribute( yearly ? 'data-price-yearly' : 'data-price-monthly' );
		} );
	}

	// Toggle visibility of mail driver sections in admin settings.
	var driverSelect = document.querySelector( '[data-mail-driver]' );

	if ( driverSelect ) {
		var syncDriver = function () {
			document.querySelectorAll( '[data-driver-section]' ).forEach( function ( section ) {
				var drivers = section.getAttribute( 'data-driver-section' ).split( ' ' );
				section.classList.toggle( 'd-none', -1 === drivers.indexOf( driverSelect.value ) );
			} );
		};

		driverSelect.addEventListener( 'change', syncDriver );
		syncDriver();
	}
}() );
