/**
 * WPicker admin client.
 *
 * Talks to the wpicker/v1 REST endpoints using the WP REST nonce + cookie auth.
 * Handles: PIN generation, device revocation, snapshot restore, error toggle.
 */
( function () {
	'use strict';

	if ( ! window.WPickerAdmin ) {
		return;
	}

	var restRoot = WPickerAdmin.rest_root.replace( /\/$/, '' );
	var nonce = WPickerAdmin.nonce;

	function headers() {
		return {
			'X-WP-Nonce': nonce,
			'Content-Type': 'application/json',
		};
	}

	async function api( method, path, body ) {
		var opts = { method: method, headers: headers(), credentials: 'same-origin' };
		if ( body !== undefined ) {
			opts.body = JSON.stringify( body );
		}
		var res = await fetch( restRoot + path, opts );
		var json;
		try {
			json = await res.json();
		} catch ( e ) {
			json = { ok: false, error: { code: 'parse', message: 'Bad JSON response.' } };
		}
		return { status: res.status, body: json };
	}

	// --- PIN generation and auto-refresh ---
	var genBtn = document.getElementById( 'wpicker-generate-pin' );
	var countdownTimer = null;
	var refreshTimer = null;
	var secondsLeft = 10;

	async function fetchPin() {
		var r = await api( 'GET', '/device/challenge' );
		if ( r.body && r.body.ok && r.body.data && r.body.data.pin ) {
			document.getElementById( 'wpicker-pin-code' ).textContent = r.body.data.pin;
			document.getElementById( 'wpicker-pin-display' ).style.display = 'block';
			
			// Reset countdown
			secondsLeft = 10;
			document.getElementById( 'wpicker-pin-countdown' ).textContent = secondsLeft;
		} else {
			console.error( 'Failed to generate PIN.' );
		}
	}

	function startPinLoop() {
		if ( countdownTimer ) clearInterval( countdownTimer );
		if ( refreshTimer ) clearInterval( refreshTimer );

		countdownTimer = setInterval( function() {
			secondsLeft--;
			if ( secondsLeft < 0 ) secondsLeft = 0;
			document.getElementById( 'wpicker-pin-countdown' ).textContent = secondsLeft;
		}, 1000 );

		refreshTimer = setInterval( function() {
			fetchPin();
		}, 10000 );
	}

	if ( genBtn ) {
		genBtn.addEventListener( 'click', async function () {
			genBtn.disabled = true;
			genBtn.textContent = '…';
			await fetchPin();
			startPinLoop();
			genBtn.style.display = 'none'; // Hide button after initial click
		} );
	}

	// --- Device revocation ---
	document.querySelectorAll( '.wpicker-revoke' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', async function () {
			var row = btn.closest( 'tr' );
			var id = row && row.getAttribute( 'data-device-id' );
			if ( ! id || ! confirm( 'Revoke this device? It will immediately lose access.' ) ) {
				return;
			}
			var r = await api( 'DELETE', '/device/' + encodeURIComponent( id ) );
			if ( r.body && r.body.ok ) {
				row.remove();
			} else {
				alert( ( r.body && r.body.error && r.body.error.message ) || 'Revoke failed.' );
			}
		} );
	} );

	// --- Snapshot restore ---
	document.querySelectorAll( '.wpicker-restore' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', async function () {
			var id = btn.getAttribute( 'data-id' );
			if ( ! id || ! confirm( 'Restore snapshot ' + id + '? A safety snapshot of the current state will be taken first.' ) ) {
				return;
			}
			btn.disabled = true;
			var r = await api( 'POST', '/rollback', { manifest_id: id, device: { name: 'admin-web' } } );
			btn.disabled = false;
			if ( r.body && r.body.ok ) {
				alert( 'Restored ' + id + '. Safety snapshot: ' + r.body.data.safety_manifest_id );
				window.location.reload();
			} else {
				alert( ( r.body && r.body.error && r.body.error.message ) || 'Restore failed.' );
			}
		} );
	} );

	// --- Error log toggle ---
	document.querySelectorAll( '.wpicker-show-error' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var id = btn.getAttribute( 'data-id' );
			var row = document.querySelector( '.wpicker-error-row[data-for="' + cssAttr( id ) + '"]' );
			if ( row ) {
				row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
			}
		} );
	} );

	function cssAttr( s ) {
		return String( s ).replace( /[^0-9a-zA-Z_-]/g, '' );
	}
} )();
