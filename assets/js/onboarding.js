/**
 * RepuVio - onboarding wizard: step navigation, live slug preview, logo / cover previews,
 * drag-and-drop highlight and the live "reputation shield" diagram.
 * Without JavaScript both steps stay visible as one long form.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var data = window.RM_DATA || {};
	var form = document.getElementById( 'rmWizardForm' );

	if ( ! form ) {
		return;
	}

	var panels = form.querySelectorAll( '[data-wizard-step]' );
	var bar = document.querySelector( '[data-wizard-bar]' );
	var nameInput = document.getElementById( 'wiz-name' );
	var taxInput = document.getElementById( 'wiz-tax-id' );
	var slugInput = form.querySelector( '[data-slug-target]' );
	var slugBase = document.querySelector( '[data-slug-base]' );
	var slugPreview = document.querySelector( '[data-slug-preview]' );
	var initial = document.querySelector( '[data-initial-preview]' );

	function showStep( step ) {
		panels.forEach( function ( panel ) {
			panel.classList.toggle( 'd-none', String( step ) !== panel.getAttribute( 'data-wizard-step' ) );
		} );

		document.querySelectorAll( '[data-wizard-indicator]' ).forEach( function ( indicator ) {
			var number = parseInt( indicator.getAttribute( 'data-wizard-indicator' ), 10 );

			indicator.classList.toggle( 'is-active', number === step );
			indicator.classList.toggle( 'is-done', number < step );
		} );

		if ( bar ) {
			bar.style.width = ( 2 === step ? 66 : 33 ) + '%';
		}

		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	form.querySelectorAll( '[data-wizard-next]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var target = parseInt( button.getAttribute( 'data-wizard-next' ), 10 );

			if ( 2 === target && nameInput.value.trim().length < 2 ) {
				nameInput.classList.add( 'is-invalid' );
				nameInput.focus();
				return;
			}

			nameInput.classList.remove( 'is-invalid' );

			// Fiscal code: basic format check here; the control digit is verified on the server.
			if ( 2 === target && taxInput && ! /^(RO)?[0-9]{2,10}$/i.test( taxInput.value.replace( /[\s.\-]/g, '' ) ) ) {
				taxInput.classList.add( 'is-invalid' );
				taxInput.focus();
				return;
			}

			if ( taxInput ) {
				taxInput.classList.remove( 'is-invalid' );
			}
			showStep( target );
		} );
	} );

	// Enter in step 1 moves to step 2 instead of submitting the whole form.
	nameInput.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			form.querySelector( '[data-wizard-next="2"]' ).click();
		}
	} );

	function syncSlug() {
		// app.js fills the slug field from the name; read it after its listener ran.
		window.setTimeout( function () {
			slugPreview.textContent = slugInput.value || 'nume-afacere';

			if ( initial ) {
				initial.textContent = ( nameInput.value.trim().charAt( 0 ) || 'N' ).toUpperCase();
			}
		}, 0 );
	}

	if ( slugBase ) {
		slugBase.textContent = data.baseUrl || '';
	}

	nameInput.addEventListener( 'input', syncSlug );
	slugInput.addEventListener( 'input', syncSlug );
	syncSlug();

	// Drag-and-drop highlight: the native file input covers the drop zone.
	form.querySelectorAll( '.rm-dropzone, .rm-cover-drop' ).forEach( function ( zone ) {
		var input = zone.querySelector( 'input[type="file"]' );

		[ 'dragenter', 'dragover' ].forEach( function ( name ) {
			zone.addEventListener( name, function () {
				zone.classList.add( 'is-dragover' );
			} );
		} );

		[ 'dragleave', 'drop' ].forEach( function ( name ) {
			zone.addEventListener( name, function () {
				zone.classList.remove( 'is-dragover' );
			} );
		} );

		input.addEventListener( 'change', function () {
			var empty = zone.querySelector( '[data-dropzone-empty]' );

			zone.classList.toggle( 'has-file', !! ( input.files && input.files.length ) );

			if ( empty ) {
				empty.classList.toggle( 'd-none', !! ( input.files && input.files.length ) );
			}
		} );
	} );

	// Live reputation shield diagram.
	var low = document.querySelector( '[data-shield-low]' );
	var high = document.querySelector( '[data-shield-high]' );

	function syncShield() {
		var checked = form.querySelector( '[data-threshold-input]:checked' );
		var threshold = checked ? parseInt( checked.value, 10 ) : 3;

		low.textContent = 2 === threshold ? '1★' : '1★ – ' + ( threshold - 1 ) + '★';
		high.textContent = 5 === threshold ? '5★' : threshold + '★ – 5★';
	}

	form.querySelectorAll( '[data-threshold-input]' ).forEach( function ( input ) {
		input.addEventListener( 'change', syncShield );
	} );

	syncShield();
	showStep( 2 === data.startStep ? 2 : 1 );
}() );
