/**
 * Jot dashboard widget client.
 *
 * Talks to the /jot/v1 REST namespace. Nonce and root URL come from data-
 * attributes on the widget root.
 */
( function () {
	'use strict';

	const root = document.querySelector( '.jot-widget' );
	if ( ! root ) {
		return;
	}

	const restRoot = root.dataset.restRoot || '';
	const nonce    = root.dataset.nonce || '';

	function request( path, body ) {
		return fetch( restRoot + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( body || {} ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { status: res.status, data: data };
			} );
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		const target = event.target;
		if ( ! ( target instanceof HTMLElement ) ) {
			return;
		}

		if ( target.classList.contains( 'jot-widget__quick-draft' ) ) {
			event.preventDefault();
			handleQuickDraft( target );
			return;
		}

		if ( target.classList.contains( 'jot-widget__refresh' ) ) {
			event.preventDefault();
			handleRefresh( target );
			return;
		}
	} );

	function handleQuickDraft( button ) {
		const card = button.closest( '.jot-widget__card' );
		if ( ! card ) {
			return;
		}
		const angleKey = card.dataset.angleKey;
		if ( ! angleKey ) {
			return;
		}
		button.disabled = true;
		button.textContent = __( 'Creating…' );

		request( 'draft', { angle_key: angleKey } ).then( function ( res ) {
			if ( res.status === 201 && res.data && res.data.ok ) {
				card.innerHTML =
					'<p>' + escapeHtml( __( 'Draft created' ) ) + ' — ' +
					'<a href="' + escapeHtml( res.data.edit_url || '#' ) + '">' +
					escapeHtml( __( 'Edit' ) ) +
					'</a></p>';
			} else {
				button.disabled = false;
				button.textContent = __( 'Quick draft' );
				alert( __( 'Could not create draft. Try again.' ) );
			}
		} ).catch( function () {
			button.disabled = false;
			button.textContent = __( 'Quick draft' );
		} );
	}

	function handleRefresh( button ) {
		button.disabled = true;
		const original = button.textContent;
		button.textContent = __( 'Refreshing…' );

		request( 'refresh', {} ).then( function ( res ) {
			if ( res.status === 429 ) {
				alert( __( 'Please wait a few minutes before refreshing again.' ) );
			} else if ( res.status === 200 && res.data && res.data.ok ) {
				window.location.reload();
				return;
			}
			button.disabled = false;
			button.textContent = original;
		} ).catch( function () {
			button.disabled = false;
			button.textContent = original;
		} );
	}

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] );
		} );
	}

	function __( s ) {
		if ( window.wp && window.wp.i18n && window.wp.i18n.__ ) {
			return window.wp.i18n.__( s, 'jot' );
		}
		return s;
	}
} )();
