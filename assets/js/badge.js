/**
 * RepuVio - Website "Trust Badge" (Insignă de încredere).
 *
 * Embed on any website:
 * <script src="https://APP/assets/js/badge.js" data-rm-badge data-url="https://APP/r/slug?src=widget"
 *         data-name="Firma" data-position="bottom-right|bottom-left" data-theme="light|dark" async></script>
 *
 * No cookies, no tracking, no network calls: it only renders a link to the public review page.
 * Styles are applied through the CSSOM so the badge works under strict host CSPs.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var script = document.currentScript;

	function palette( theme ) {
		if ( 'dark' === theme ) {
			return { bg: '#0F172A', text: '#FFFFFF', muted: '#CBD5E1', border: '#1E293B' };
		}

		return { bg: '#FFFFFF', text: '#0F172A', muted: '#64748B', border: '#E2E8F0' };
	}

	function css( element, styles ) {
		Object.keys( styles ).forEach( function ( key ) {
			element.style[ key ] = styles[ key ];
		} );

		return element;
	}

	function safeUrl( url ) {
		return /^https?:\/\//i.test( url || '' ) ? url : '#';
	}

	/**
	 * Renders the badge.
	 *
	 * @param {HTMLElement} container Element to append to (document.body for the live widget).
	 * @param {Object}      options   url, name, position (bottom-right|bottom-left), theme, preview (bool).
	 * @return {HTMLElement} Badge wrapper.
	 */
	function render( container, options ) {
		var colors = palette( options.theme );
		var side = 'bottom-left' === options.position ? 'left' : 'right';
		var wrapper = document.createElement( 'div' );
		var link = document.createElement( 'a' );
		var stars = document.createElement( 'span' );
		var textBox = document.createElement( 'span' );
		var title = document.createElement( 'strong' );
		var name = document.createElement( 'span' );
		var close = document.createElement( 'button' );

		css( wrapper, {
			position: options.preview ? 'absolute' : 'fixed',
			bottom: options.preview ? '16px' : '20px',
			zIndex: '2147483000',
			fontFamily: 'Inter, "Segoe UI", Arial, sans-serif',
			lineHeight: '1.2'
		} );
		wrapper.style[ side ] = options.preview ? '16px' : '20px';
		wrapper.setAttribute( 'data-rm-badge-root', '' );

		link.href = safeUrl( options.url );
		link.target = '_blank';
		link.rel = 'noopener';
		link.setAttribute( 'aria-label', 'Lasă o recenzie pentru ' + ( options.name || '' ) );
		css( link, {
			display: 'flex',
			alignItems: 'center',
			gap: '10px',
			padding: '10px 34px 10px 14px',
			borderRadius: '16px',
			border: '1px solid ' + colors.border,
			background: colors.bg,
			color: colors.text,
			textDecoration: 'none',
			boxShadow: '0 10px 30px rgba(15, 23, 42, 0.18)',
			transition: 'transform .2s ease'
		} );

		stars.textContent = '★★★★★';
		css( stars, { color: '#F59E0B', fontSize: '16px', letterSpacing: '1px' } );

		title.textContent = 'Evaluează-ne';
		css( title, { display: 'block', fontSize: '14px' } );

		name.textContent = options.name || '';
		css( name, { display: 'block', fontSize: '12px', color: colors.muted, maxWidth: '180px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } );

		textBox.appendChild( title );
		textBox.appendChild( name );
		link.appendChild( stars );
		link.appendChild( textBox );

		link.addEventListener( 'mouseenter', function () {
			link.style.transform = 'translateY(-2px)';
		} );
		link.addEventListener( 'mouseleave', function () {
			link.style.transform = 'none';
		} );

		close.type = 'button';
		close.textContent = '×';
		close.setAttribute( 'aria-label', 'Închide' );
		css( close, {
			position: 'absolute',
			top: '6px',
			right: '8px',
			border: '0',
			background: 'transparent',
			color: colors.muted,
			fontSize: '16px',
			lineHeight: '1',
			cursor: 'pointer',
			padding: '2px'
		} );
		close.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			wrapper.remove();

			if ( ! options.preview ) {
				try {
					window.sessionStorage.setItem( 'rmBadgeClosed', '1' );
				} catch ( error ) {
					// Storage unavailable: the badge simply reappears on the next page.
				}
			}
		} );

		wrapper.appendChild( link );
		wrapper.appendChild( close );
		container.appendChild( wrapper );

		return wrapper;
	}

	window.RepuVioBadge = { render: render };

	if ( ! script || ! script.hasAttribute( 'data-rm-badge' ) ) {
		return;
	}

	try {
		if ( '1' === window.sessionStorage.getItem( 'rmBadgeClosed' ) ) {
			return;
		}
	} catch ( error ) {
		// Ignore storage errors.
	}

	var options = {
		url: script.getAttribute( 'data-url' ),
		name: script.getAttribute( 'data-name' ),
		position: script.getAttribute( 'data-position' ) || 'bottom-right',
		theme: script.getAttribute( 'data-theme' ) || 'light',
		preview: false
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			render( document.body, options );
		} );
	} else {
		render( document.body, options );
	}
}() );
