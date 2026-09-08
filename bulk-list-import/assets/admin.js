/**
 * Bulk List Import — preview behaviour.
 *
 * Plain JS on purpose: no build step until the parser is proven.
 *
 * Everything here is an enhancement over a form that already works. If this file
 * fails to load the paste form still posts, the preview still renders, and the
 * recognition gate still blocks — it just does so with a blank browser for a few
 * seconds. Nothing below is load-bearing for correctness.
 */
( function () {
	'use strict';

	var strings = window.bliStrings || {};

	function t( key, fallback ) {
		return strings[ key ] || fallback;
	}

	/* --------------------------------------------------------------- helpers -- */

	function rowsIn( form ) {
		return Array.prototype.slice.call( form.querySelectorAll( 'tr.bli-row' ) );
	}

	function checkboxIn( row ) {
		return row.querySelector( '.bli-row-check' );
	}

	function panelFor( row ) {
		var index = row.getAttribute( 'data-index' );
		return row.parentNode.querySelector( '.bli-panel-row[data-panel-for="' + index + '"]' );
	}

	/* ----------------------------------------------------------- blocked rows -- */

	/**
	 * Apply a choice to one blocked row.
	 *
	 * "details" and "own" both make the row importable again — the user has taken
	 * responsibility for the facts either way. "skip" puts it back.
	 */
	function applyChoice( row, choice ) {
		var panel = panelFor( row );
		if ( ! panel ) {
			return;
		}

		var field = panel.querySelector( '.bli-gate-action' );
		var box = checkboxIn( row );

		if ( field ) {
			field.value = choice;
		}

		panel.classList.toggle( 'bli-panel--details', 'details' === choice );

		Array.prototype.forEach.call( panel.querySelectorAll( '.bli-gate-choice' ), function ( button ) {
			button.classList.toggle( 'button-primary', button.getAttribute( 'data-choice' ) === choice );
		} );

		if ( box ) {
			box.disabled = 'skip' === choice;
			box.checked = 'skip' !== choice;
		}

		row.classList.toggle( 'bli-row--locked', 'skip' === choice );
	}

	function wireBlockedRows( form ) {
		form.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '.bli-gate-choice' ) : null;
			if ( ! button ) {
				return;
			}

			event.preventDefault();

			var panel = button.closest( '.bli-panel-row' );
			var index = panel && panel.getAttribute( 'data-panel-for' );
			var row = index && form.querySelector( 'tr.bli-row[data-index="' + index + '"]' );

			if ( row ) {
				applyChoice( row, button.getAttribute( 'data-choice' ) );
				refresh( form );
			}
		} );
	}

	/**
	 * Bulk controls, so a run with many blocked rows is not a wall.
	 *
	 * If 60 of 200 rows block, clicking through 60 panels is a rage-quit. The gate
	 * should feel like a seatbelt, not a locked door.
	 */
	function addBulkControls( form ) {
		var blocked = rowsIn( form ).filter( function ( row ) {
			return 'blocked' === row.getAttribute( 'data-gate' );
		} );

		if ( ! blocked.length ) {
			return;
		}

		var bar = document.createElement( 'div' );
		bar.className = 'bli-bulk-bar';

		var skipAll = document.createElement( 'button' );
		skipAll.type = 'button';
		skipAll.className = 'button';
		skipAll.textContent = t( 'skipAll', 'Skip all blocked' );
		skipAll.addEventListener( 'click', function () {
			blocked.forEach( function ( row ) {
				applyChoice( row, 'skip' );
			} );
			refresh( form );
		} );

		var fillAll = document.createElement( 'button' );
		fillAll.type = 'button';
		fillAll.className = 'button';
		fillAll.textContent = t( 'bulkFill', 'Fill all blocked from the first' );
		fillAll.addEventListener( 'click', function () {
			var first = panelFor( blocked[ 0 ] );
			if ( ! first ) {
				return;
			}

			// Category and brand only. Copying "key specs" or notes across products
			// would be putting words in the user's mouth about a different item —
			// which is the failure this whole layer exists to avoid.
			[ 'category', 'brand' ].forEach( function ( key ) {
				var source = first.querySelector( '.bli-detail--' + key );
				if ( ! source || ! source.value ) {
					return;
				}

				blocked.forEach( function ( row ) {
					var target = panelFor( row );
					var input = target && target.querySelector( '.bli-detail--' + key );
					if ( input && ! input.value ) {
						input.value = source.value;
					}
				} );
			} );

			blocked.forEach( function ( row ) {
				applyChoice( row, 'details' );
			} );
			refresh( form );
		} );

		bar.appendChild( skipAll );
		bar.appendChild( document.createTextNode( ' ' ) );
		bar.appendChild( fillAll );

		var table = form.querySelector( '.bli-preview' );
		if ( table && table.parentNode ) {
			table.parentNode.insertBefore( bar, table );
		}
	}

	/* ---------------------------------------------------------------- summary -- */

	function refresh( form ) {
		var checks = rowsIn( form ).map( checkboxIn ).filter( Boolean );
		var selectable = checks.filter( function ( box ) {
			return ! box.disabled;
		} );
		var count = selectable.filter( function ( box ) {
			return box.checked;
		} ).length;

		var blocked = rowsIn( form ).filter( function ( row ) {
			var box = checkboxIn( row );
			return 'blocked' === row.getAttribute( 'data-gate' ) && ( ! box || box.disabled );
		} ).length;

		var button = form.querySelector( '#bli-import-button' );
		if ( button ) {
			button.value = blocked
				? t( 'selectedWithBlocked', 'Import %1$d products (%2$d blocked)' )
					.replace( '%1$d', count )
					.replace( '%2$d', blocked )
				: t( 'selected', 'Import %d products' ).replace( '%d', count );
			button.disabled = 0 === count;
		}

		var warning = form.querySelector( '#bli-batch-warning' );
		if ( warning ) {
			// Soft warning only. Warn, never forbid.
			warning.hidden = count <= parseInt( warning.getAttribute( 'data-batch' ), 10 );
		}

		var estimate = form.querySelector( '#bli-token-estimate' );
		if ( estimate ) {
			var per = parseInt( estimate.getAttribute( 'data-per-product' ), 10 ) || 0;
			estimate.textContent = count
				? t( 'tokens', 'Estimated %s tokens for this import.' )
					.replace( '%s', ( count * per ).toLocaleString() )
				: '';
		}

		var selectAll = form.querySelector( '#bli-select-all' );
		if ( selectAll ) {
			selectAll.checked = count > 0 && count === selectable.length;
			selectAll.indeterminate = count > 0 && count < selectable.length;
		}
	}

	function wirePreview( form ) {
		var selectAll = form.querySelector( '#bli-select-all' );

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				rowsIn( form ).map( checkboxIn ).filter( Boolean ).forEach( function ( box ) {
					if ( ! box.disabled ) {
						box.checked = selectAll.checked;
					}
				} );
				refresh( form );
			} );
		}

		form.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'bli-row-check' ) ) {
				refresh( form );
			}
		} );

		wireBlockedRows( form );
		addBulkControls( form );
		refresh( form );
	}

	/* ------------------------------------------------------- async preview -- */

	/**
	 * Fetch the preview instead of posting it.
	 *
	 * With the gate on, building a preview means a provider round trip. A plain
	 * POST leaves the browser blank for several seconds, which reads as a hang and
	 * gets resubmitted. Same request shape, same server rendering — only the
	 * waiting is visible.
	 */
	function wirePasteForm( form ) {
		if ( ! window.fetch || ! strings.ajaxUrl ) {
			return; // Plain POST still works.
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var button = form.querySelector( 'input[type="submit"], button[type="submit"]' );
			var spinner = document.createElement( 'span' );
			spinner.className = 'bli-spinner';
			spinner.textContent = strings.gateOn
				? t( 'checking', 'Checking products…' )
				: t( 'parsing', 'Parsing…' );

			if ( button ) {
				button.disabled = true;
				button.parentNode.insertBefore( spinner, button.nextSibling );
			}

			var body = new FormData( form );
			body.set( 'action', 'bli_preview' );
			body.set( '_ajax_nonce', strings.previewNonce );

			fetch( strings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success || ! payload.data ) {
						throw new Error( 'bad payload' );
					}

					var wrap = form.closest( '.bli-wrap' ) || form.parentNode;
					form.remove();
					wrap.insertAdjacentHTML( 'beforeend', payload.data.html );

					var preview = wrap.querySelector( '.bli-preview-form' );
					if ( preview ) {
						wirePreview( preview );
					}
				} )
				.catch( function () {
					spinner.remove();
					if ( button ) {
						button.disabled = false;
					}

					var notice = document.createElement( 'div' );
					notice.className = 'notice notice-error';
					notice.innerHTML = '<p></p>';
					notice.querySelector( 'p' ).textContent = t( 'previewError', 'The preview could not be built. Try again.' );
					form.parentNode.insertBefore( notice, form );
				} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var paste = document.querySelector( '.bli-paste-form' );
		if ( paste ) {
			wirePasteForm( paste );
		}

		var preview = document.querySelector( '.bli-preview-form' );
		if ( preview ) {
			wirePreview( preview );
		}
	} );
} )();
