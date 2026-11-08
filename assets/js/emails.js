/**
 * RepuVio - Email & Șabloane: inserts {{placeholder}} pills at the cursor position of the
 * last focused field (subject or HTML body) of the template editor.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var targets = document.querySelectorAll( '[data-placeholder-target]' );
	var lastField = document.getElementById( 'tplBody' );

	if ( ! targets.length ) {
		return;
	}

	targets.forEach( function ( field ) {
		field.addEventListener( 'focus', function () {
			lastField = field;
		} );
	} );

	document.querySelectorAll( '[data-insert-placeholder]' ).forEach( function ( pill ) {
		// Keep the caret where it is: do not move focus to the pill on mouse down.
		pill.addEventListener( 'mousedown', function ( event ) {
			event.preventDefault();
		} );

		pill.addEventListener( 'click', function () {
			var text = pill.getAttribute( 'data-insert-placeholder' );
			var field = lastField;

			if ( ! field ) {
				return;
			}

			var start = 'number' === typeof field.selectionStart ? field.selectionStart : field.value.length;
			var end = 'number' === typeof field.selectionEnd ? field.selectionEnd : field.value.length;

			field.value = field.value.slice( 0, start ) + text + field.value.slice( end );
			field.focus();
			field.setSelectionRange( start + text.length, start + text.length );
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	} );
}() );
