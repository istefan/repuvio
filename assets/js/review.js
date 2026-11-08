/**
 * RepuVio - public funnel interactions: star labels, low-rating modal, submit guard.
 * Progressive enhancement: without JS every star submits the form to the server.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	// "Immediate" redirect mode: after the vote the page comes back with the Google link and leaves at once
	// (a <meta refresh> in the head does the same without JavaScript).
	var autoRedirect = document.querySelector( '[data-rf-autoredirect]' );

	if ( autoRedirect ) {
		window.location.replace( autoRedirect.href );
	}

	var rateForm = document.getElementById( 'rfRateForm' );
	var modal = document.getElementById( 'rfModal' );
	var label = document.getElementById( 'rfStarLabel' );
	var defaultLabel = label ? label.textContent : '';
	var lastFocus = null;

	function closeModal() {
		if ( ! modal ) {
			return;
		}

		modal.hidden = true;
		document.body.classList.remove( 'rf-modal-open' );

		if ( lastFocus ) {
			lastFocus.focus();
		}
	}

	function openModal( rating, trigger ) {
		var input = document.getElementById( 'rfRatingInput' );

		if ( ! modal || ! input ) {
			return false;
		}

		input.value = String( rating );
		lastFocus = trigger;
		modal.hidden = false;
		document.body.classList.add( 'rf-modal-open' );

		window.setTimeout( function () {
			var message = document.getElementById( 'rf-message' );
			if ( message ) {
				message.focus();
			}
		}, 250 );

		return true;
	}

	if ( rateForm ) {
		var threshold = parseInt( rateForm.getAttribute( 'data-threshold' ), 10 ) || 3;
		var stars = rateForm.querySelectorAll( '.rf-star' );
		var submitting = false;

		stars.forEach( function ( star ) {
			star.addEventListener( 'mouseenter', function () {
				label.textContent = star.getAttribute( 'data-label' );
			} );

			star.addEventListener( 'focus', function () {
				label.textContent = star.getAttribute( 'data-label' );
			} );

			star.addEventListener( 'mouseleave', function () {
				var selected = rateForm.querySelector( '.rf-star.is-selected' );
				label.textContent = selected ? selected.getAttribute( 'data-label' ) : defaultLabel;
			} );

			star.addEventListener( 'click', function ( event ) {
				var rating = parseInt( star.value, 10 );

				event.preventDefault();

				if ( submitting ) {
					return;
				}

				stars.forEach( function ( item ) {
					item.classList.remove( 'is-selected' );
				} );
				star.classList.add( 'is-selected' );
				label.textContent = star.getAttribute( 'data-label' );

				if ( rating < threshold && openModal( rating, star ) ) {
					return;
				}

				// Happy path: short celebration, then submit (server logs + redirects to Google).
				submitting = true;
				label.textContent = 'Mulțumim! Te redirecționăm…';

				var hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = 'rating';
				hidden.value = String( rating );
				rateForm.appendChild( hidden );

				window.setTimeout( function () {
					rateForm.submit();
				}, 450 );
			} );
		} );
	}

	if ( modal ) {
		modal.querySelectorAll( '[data-rf-close]' ).forEach( function ( element ) {
			element.addEventListener( 'click', closeModal );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				closeModal();
			}
		} );
	}

	var feedbackForm = document.getElementById( 'rfFeedbackForm' );

	if ( feedbackForm ) {
		feedbackForm.addEventListener( 'submit', function ( event ) {
			var message = document.getElementById( 'rf-message' );
			var button = feedbackForm.querySelector( 'button[type="submit"]' );

			if ( message && 3 > message.value.trim().length ) {
				event.preventDefault();
				message.focus();
				message.setAttribute( 'aria-invalid', 'true' );
				return;
			}

			// GDPR consent is mandatory (the server checks it too).
			var consent = feedbackForm.querySelector( 'input[name="privacy_consent"]' );

			if ( consent && ! consent.checked ) {
				event.preventDefault();
				consent.focus();
				consent.closest( '.rf-consent' ).classList.add( 'is-invalid' );
				return;
			}

			if ( button ) {
				button.disabled = true;
				button.textContent = 'Se trimite…';
			}
		} );
	}
}() );
