/**
 * Jot dashboard widget client.
 *
 * Talks to the /jot/v1 REST namespace. Root URL + nonce come from data-
 * attributes on the widget root. After Refresh, we re-render the widget in
 * place via /render instead of reloading the dashboard.
 */
( function () {
	'use strict';

	const rootSelector = '.jot-widget';
	let currentRoot = document.querySelector( rootSelector );
	if ( ! currentRoot ) {
		return;
	}

	bind( currentRoot );

	function bind( root ) {
		root.addEventListener( 'click', onClick );
	}

	function restRoot() { return currentRoot.dataset.restRoot || ''; }
	function nonce()    { return currentRoot.dataset.nonce || ''; }

	function request( method, path, body ) {
		return fetch( restRoot() + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce(),
			},
			body: method === 'GET' ? undefined : JSON.stringify( body || {} ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { status: res.status, data: data };
			} );
		} );
	}

	function onClick( event ) {
		const t = event.target;
		if ( ! ( t instanceof HTMLElement ) ) { return; }

		if ( t.classList.contains( 'jot-widget__quick-draft' ) ) {
			event.preventDefault();
			handleQuickDraft( t, '' );
		} else if ( t.classList.contains( 'jot-widget__tier' ) ) {
			event.preventDefault();
			handleQuickDraft( t, t.dataset.tier || '' );
		} else if ( t.classList.contains( 'jot-widget__dismiss' ) ) {
			event.preventDefault();
			handleDismiss( t );
		} else if ( t.classList.contains( 'jot-widget__refresh' ) ) {
			event.preventDefault();
			handleRefresh( t );
		}
	}

	function cardError( card, message ) {
		const slot = card.querySelector( '.jot-widget__card-error' );
		if ( ! slot ) { return; }
		slot.textContent = message;
		slot.hidden = false;
	}

	function clearCardError( card ) {
		const slot = card.querySelector( '.jot-widget__card-error' );
		if ( slot ) { slot.hidden = true; slot.textContent = ''; }
	}

	function handleQuickDraft( button, tier ) {
		const card = button.closest( '.jot-widget__card' );
		if ( ! card ) { return; }
		const angleKey = card.dataset.angleKey;
		if ( ! angleKey ) { return; }

		clearCardError( card );
		const originalLabel = button.textContent;
		const siblings = card.querySelectorAll( 'button' );
		siblings.forEach( function ( b ) { b.disabled = true; } );
		button.textContent = tier === 'full' ? __( 'Drafting…' ) : __( 'Creating…' );

		const body = { angle_key: angleKey };
		if ( tier ) { body.tier = tier; }

		request( 'POST', 'draft', body ).then( function ( res ) {
			if ( res.status === 201 && res.data && res.data.ok ) {
				const editUrl = res.data.edit_url || '#';
				card.innerHTML =
					'<p class="jot-widget__card-success">' +
					escapeHtml( __( 'Draft created' ) ) + ' — ' +
					'<a href="' + escapeHtml( editUrl ) + '">' + escapeHtml( __( 'Edit' ) ) + '</a>' +
					'</p>';
				// Move focus so the screen reader announces the new link.
				const link = card.querySelector( 'a' );
				if ( link ) { link.focus(); }
			} else {
				siblings.forEach( function ( b ) { b.disabled = false; } );
				button.textContent = originalLabel;
				cardError( card, ( res.data && res.data.error ) || __( 'Could not create draft. Try again.' ) );
			}
		} ).catch( function () {
			siblings.forEach( function ( b ) { b.disabled = false; } );
			button.textContent = originalLabel;
			cardError( card, __( 'Network error. Try again.' ) );
		} );
	}

	function handleDismiss( button ) {
		const card = button.closest( '.jot-widget__card' );
		if ( ! card ) { return; }
		const angleKey = card.dataset.angleKey;
		if ( ! angleKey ) { return; }

		card.classList.add( 'jot-widget__card--dismissing' );
		request( 'POST', 'dismiss', { angle_key: angleKey } ).then( function ( res ) {
			if ( res.status === 200 && res.data && res.data.ok ) {
				setTimeout( function () { card.remove(); }, 180 );
			} else {
				card.classList.remove( 'jot-widget__card--dismissing' );
			}
		} ).catch( function () {
			card.classList.remove( 'jot-widget__card--dismissing' );
		} );
	}

	function handleRefresh( button ) {
		button.disabled = true;
		const original = button.textContent;
		button.textContent = __( 'Refreshing…' );

		request( 'POST', 'refresh', {} ).then( function ( res ) {
			if ( res.status === 429 ) {
				button.disabled = false;
				button.textContent = original;
				announce( __( 'Please wait a few minutes before refreshing again.' ) );
				return;
			}
			if ( res.status === 200 && res.data && res.data.ok ) {
				return request( 'GET', 'render' ).then( function ( renderRes ) {
					if ( renderRes.status === 200 && renderRes.data && renderRes.data.html ) {
						replaceWidget( renderRes.data.html );
						announce( __( 'Suggestions updated.' ) );
					} else {
						button.disabled = false;
						button.textContent = original;
					}
				} );
			}
			button.disabled = false;
			button.textContent = original;
		} ).catch( function () {
			button.disabled = false;
			button.textContent = original;
		} );
	}

	function replaceWidget( html ) {
		const parser  = new DOMParser();
		const doc     = parser.parseFromString( html, 'text/html' );
		const fresh   = doc.querySelector( rootSelector );
		if ( ! fresh || ! currentRoot.parentNode ) { return; }
		currentRoot.parentNode.replaceChild( fresh, currentRoot );
		currentRoot = fresh;
		bind( currentRoot );
	}

	function announce( message ) {
		const live = currentRoot.querySelector( '.jot-widget__suggestions' );
		if ( ! live ) { return; }
		// aria-live on the section auto-announces when content changes. Fall
		// back to a hidden sr-only message for cases where content identity
		// didn't change.
		const pad = document.createElement( 'span' );
		pad.className = 'screen-reader-text';
		pad.textContent = message;
		live.appendChild( pad );
		setTimeout( function () { pad.remove(); }, 1500 );
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
