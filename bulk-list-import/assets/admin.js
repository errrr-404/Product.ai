/**
 * Bulk List Import — preview table behaviour.
 *
 * Plain JS on purpose: no build step until the parser is proven.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '.bli-preview-form' );

		if ( ! form ) {
			return;
		}

		var selectAll = document.getElementById( 'bli-select-all' );
		var warning = document.getElementById( 'bli-batch-warning' );
		var button = document.getElementById( 'bli-import-button' );
		var checks = Array.prototype.slice.call( form.querySelectorAll( '.bli-row-check' ) );
		var batch = warning ? parseInt( warning.getAttribute( 'data-batch' ), 10 ) : 20;
		var template = ( window.bliStrings && window.bliStrings.selected ) || 'Import %d products';

		function selectedCount() {
			return checks.filter( function ( box ) {
				return box.checked;
			} ).length;
		}

		function refresh() {
			var count = selectedCount();

			if ( button ) {
				button.value = template.replace( '%d', count );
				button.disabled = count === 0;
			}

			// Soft warning only. Warn, never forbid.
			if ( warning ) {
				warning.hidden = count <= batch;
			}

			if ( selectAll ) {
				selectAll.checked = count > 0 && count === checks.length;
				selectAll.indeterminate = count > 0 && count < checks.length;
			}
		}

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				checks.forEach( function ( box ) {
					box.checked = selectAll.checked;
				} );
				refresh();
			} );
		}

		checks.forEach( function ( box ) {
			box.addEventListener( 'change', refresh );
		} );

		refresh();
	} );
} )();
