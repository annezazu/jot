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

		const toggleBtn = t.closest( '.jot-widget__ai-toggle-form-toggle' )
			|| ( t.classList.contains( 'jot-widget__ai-toggle-label' )
				? t.parentElement.querySelector( '.jot-widget__ai-toggle-form-toggle' )
				: null );
		if ( toggleBtn ) {
			event.preventDefault();
			handleAiToggle( toggleBtn );
			return;
		}

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

	function handleAiToggle( btn ) {
		const next     = btn.getAttribute( 'aria-checked' ) !== 'true';
		const wrap     = btn.closest( '.jot-widget__ai-toggle' );
		const caption  = wrap ? wrap.querySelector( '.jot-widget__ai-toggle-caption' ) : null;

		// Optimistic flip.
		btn.classList.toggle( 'is-checked', next );
		btn.setAttribute( 'aria-checked', next ? 'true' : 'false' );
		btn.classList.add( 'is-saving' );
		if ( caption ) {
			caption.textContent = next
				? caption.dataset.on
				: caption.dataset.off;
		}

		const revert = function () {
			btn.classList.remove( 'is-saving' );
			btn.classList.toggle( 'is-checked', ! next );
			btn.setAttribute( 'aria-checked', next ? 'false' : 'true' );
			if ( caption ) {
				caption.textContent = next
					? caption.dataset.off
					: caption.dataset.on;
			}
		};

		request( 'POST', 'ai-toggle', { enabled: next } ).then( function ( res ) {
			if ( res.status !== 200 || ! res.data || ! res.data.ok ) {
				revert();
				return;
			}
			// Re-render so suggestion-card actions reflect the new mode
			// (tier buttons when AI is on; Quick draft when off).
			return request( 'GET', 'render' ).then( function ( renderRes ) {
				if ( renderRes.status === 200 && renderRes.data && renderRes.data.html ) {
					replaceWidget( renderRes.data.html );
				} else {
					btn.classList.remove( 'is-saving' );
				}
			} );
		} ).catch( function () {
			revert();
		} );
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
		button.textContent = __( 'Creating…' );

		const body = { angle_key: angleKey };
		if ( tier ) { body.tier = tier; }

		request( 'POST', 'draft', body ).then( function ( res ) {
			if ( res.status === 201 && res.data && res.data.ok ) {
				promoteCardToDraft(
					card,
					res.data.title || '',
					res.data.edit_url || '#',
					res.data.tier || ''
				);
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

	// Slide the source card out and insert a freshly-created draft into the
	// drafts list below, with a brief background flash so it's obvious where
	// the new draft landed. The drafts section is an aria-live region, so
	// screen readers get the announcement without us moving focus into the
	// new row (which would leave a focus ring during the fade).
	function promoteCardToDraft( card, title, editUrl, tier ) {
		const row = buildDraftRow( title, editUrl, tier );
		insertDraftRow( row );

		card.classList.add( 'jot-widget__card--dismissing' );
		setTimeout( function () { card.remove(); maybeShowAllCaughtUp(); }, 180 );
	}

	// When the user has acted on every visible suggestion (drafted or ignored),
	// swap the now-empty list for a calm "all caught up" state. Only fires
	// after a user action — the cron-empty case keeps its own server-rendered
	// "Nothing new in your activity yet" copy.
	function maybeShowAllCaughtUp() {
		const list = document.querySelector( '.jot-widget__digests' );
		if ( ! list || list.children.length > 0 ) { return; }

		const empty = document.createElement( 'div' );
		empty.className = 'jot-widget__empty';
		const p = document.createElement( 'p' );
		p.textContent = __( 'All caught up! Work on your drafts, refresh the connection, or come back tomorrow.' );
		empty.appendChild( p );
		list.replaceWith( empty );
	}

	function buildDraftRow( title, editUrl, tier ) {
		const li = document.createElement( 'li' );
		li.className = 'jot-widget__draft jot-widget__draft--new';

		const titleLink = document.createElement( 'a' );
		titleLink.className = 'jot-widget__draft-title';
		titleLink.href = editUrl;
		titleLink.textContent = title || __( '(no title)' );
		li.appendChild( titleLink );

		const meta = document.createElement( 'span' );
		meta.className = 'jot-widget__draft-meta';

		if ( tier && tier !== 'quick_draft' ) {
			const pill = document.createElement( 'span' );
			pill.className = 'jot-widget__tier-pill';
			pill.textContent = tierLabel( tier );
			meta.appendChild( pill );
		}

		const when = document.createElement( 'span' );
		when.className = 'jot-widget__muted';
		when.textContent = __( 'Just now' );
		meta.appendChild( when );

		li.appendChild( meta );
		return li;
	}

	function insertDraftRow( row ) {
		const section = document.querySelector( '.jot-widget__drafts' );
		if ( ! section ) { return false; }

		let list = section.querySelector( '.jot-widget__drafts-list' );
		if ( ! list ) {
			// The drafts section was rendered with the empty hint. Swap the hint
			// out for a real list so we have somewhere to add to.
			const hint = section.querySelector( '.jot-widget__muted' );
			if ( hint ) { hint.remove(); }
			list = document.createElement( 'ul' );
			list.className = 'jot-widget__drafts-list';
			section.appendChild( list );
		}
		list.insertBefore( row, list.firstChild );
		return true;
	}

	function tierLabel( tier ) {
		if ( tier === 'spark' )   { return __( 'Spark' ); }
		if ( tier === 'outline' ) { return __( 'Outline' ); }
		return tier;
	}

	function handleDismiss( button ) {
		const card = button.closest( '.jot-widget__card' );
		if ( ! card ) { return; }
		const angleKey = card.dataset.angleKey;
		if ( ! angleKey ) { return; }

		card.classList.add( 'jot-widget__card--dismissing' );
		request( 'POST', 'dismiss', { angle_key: angleKey } ).then( function ( res ) {
			if ( res.status === 200 && res.data && res.data.ok ) {
				setTimeout( function () { card.remove(); maybeShowAllCaughtUp(); }, 180 );
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
				const retry = ( res.data && res.data.retry_after ) || 0;
				toast(
					'warning',
					retry > 0
						? __( 'Please wait' ) + ' ' + Math.ceil( retry / 60 ) + ' ' + __( 'min before refreshing again.' )
						: __( 'Please wait a few minutes before refreshing again.' )
				);
				return;
			}
			if ( res.status === 200 && res.data && res.data.ok ) {
				return request( 'GET', 'render' ).then( function ( renderRes ) {
					if ( renderRes.status === 200 && renderRes.data && renderRes.data.html ) {
						replaceWidget( renderRes.data.html );
						toast( 'success', __( 'Updated just now.' ) );
						announce( __( 'Suggestions updated.' ) );
					} else {
						button.disabled = false;
						button.textContent = original;
					}
				} );
			}
			button.disabled = false;
			button.textContent = original;
			toast( 'error', __( 'Refresh failed. Try again.' ) );
		} ).catch( function () {
			button.disabled = false;
			button.textContent = original;
			toast( 'error', __( 'Network error. Try again.' ) );
		} );
	}

	function toast( kind, message ) {
		// Drop any existing toast so repeated clicks don't stack.
		const existing = currentRoot.querySelector( '.jot-widget__toast' );
		if ( existing ) { existing.remove(); }

		const el = document.createElement( 'div' );
		el.className = 'jot-widget__toast jot-widget__toast--' + kind;
		el.setAttribute( 'role', kind === 'error' ? 'alert' : 'status' );
		el.textContent = message;
		currentRoot.insertBefore( el, currentRoot.firstChild );
		// Auto-dismiss after a few seconds.
		setTimeout( function () {
			el.classList.add( 'jot-widget__toast--leaving' );
			setTimeout( function () { el.remove(); }, 180 );
		}, kind === 'error' || kind === 'warning' ? 5000 : 2500 );
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
