/**
 * WPicker admin client.
 *
 * Talks to the wpicker/v1 REST endpoints using the WP REST nonce + cookie auth.
 * Handles: device revocation, and the Snapshots screen
 * (search, filters, pagination, and the detail dialog with its diff viewer,
 * restore, and apply / reject of held pushes).
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

	// --- "A device just paired — not you? Revoke it" -------------------------
	document.querySelectorAll( '.wpicker-notice-revoke' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', async function () {
			var box = btn.closest( '[data-device-id]' );
			var id = box && box.getAttribute( 'data-device-id' );
			if ( ! id || ! window.confirm( 'Revoke this device? It will immediately lose access.' ) ) {
				return;
			}
			var r = await api( 'DELETE', '/device/' + encodeURIComponent( id ) );
			if ( r.body && r.body.ok ) {
				window.location.reload();
			} else {
				toast( errorMessage( r, 'Revoke failed.' ), true );
			}
		} );
	} );

	// --- Backups -----------------------------------------------------------
	var backupTable = document.getElementById( 'wpicker-backup-table' );
	if ( backupTable ) {
		initBackups( backupTable );
	}

	function initBackups( table ) {
		table.querySelectorAll( '.wpicker-time[datetime]' ).forEach( function ( time ) {
			var iso = time.getAttribute( 'datetime' );
			time.title = iso;
			time.textContent = relativeTime( iso );
		} );

		table.querySelectorAll( '.wpicker-backup-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', async function () {
				var id = button.closest( 'tr' ).getAttribute( 'data-backup-id' );
				if ( ! window.confirm( 'Delete backup ' + id + '?\n\nThis cannot be undone. The site itself is not changed.' ) ) {
					return;
				}
				button.disabled = true;
				var r = await api( 'DELETE', '/backup/' + encodeURIComponent( id ) );
				if ( r.body && r.body.ok ) {
					toast( 'Backup deleted.' );
					setTimeout( function () {
						window.location.reload();
					}, 700 );
				} else {
					button.disabled = false;
					toast( errorMessage( r, 'Delete failed.' ), true );
				}
			} );
		} );

		var create = document.getElementById( 'wpicker-backup-create' );
		if ( ! create ) {
			return; // backups are switched off
		}

		function megabytes( bytes ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}

		// --- Upload a backup file -------------------------------------------
		var fileInput = document.getElementById( 'wpicker-upload-file' );
		var uploadStart = document.getElementById( 'wpicker-upload-start' );
		var uploadProgress = document.getElementById( 'wpicker-upload-progress' );
		var uploadBar = document.getElementById( 'wpicker-upload-bar' );
		var uploadStatus = document.getElementById( 'wpicker-upload-status' );
		var CHUNK = 2 * 1048576; // stays under common upload limits

		fileInput.addEventListener( 'change', function () {
			uploadStart.disabled = ! fileInput.files.length;
		} );

		uploadStart.addEventListener( 'click', async function () {
			var file = fileInput.files[ 0 ];
			if ( ! file ) {
				return;
			}
			uploadStart.disabled = true;
			fileInput.disabled = true;
			uploadProgress.hidden = false;
			uploadBar.style.width = '0%';
			uploadStatus.textContent = 'Starting…';

			function fail( r, fallback ) {
				uploadStart.disabled = false;
				fileInput.disabled = false;
				uploadStatus.textContent = errorMessage( r, fallback );
				toast( errorMessage( r, fallback ), true );
			}

			var r = await api( 'POST', '/backup/upload', { name: file.name, size: file.size } );
			if ( ! ( r.body && r.body.ok ) ) {
				return fail( r, 'Could not start the upload.' );
			}
			var id = r.body.data.id;
			var sent = 0;
			var failures = 0;
			while ( sent < file.size ) {
				var piece = file.slice( sent, sent + CHUNK );
				var res;
				try {
					res = await fetch( restRoot + '/backup/' + encodeURIComponent( id ) + '/chunk?offset=' + sent, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/octet-stream' },
						body: piece,
					} );
				} catch ( e ) {
					res = null;
				}
				var json = null;
				if ( res ) {
					try {
						json = await res.json();
					} catch ( e ) {
						json = null;
					}
				}
				if ( ! ( json && json.ok ) ) {
					// The server accepts a repeated chunk, so a retry is safe.
					if ( ++failures > 3 ) {
						await api( 'DELETE', '/backup/' + encodeURIComponent( id ) );
						return fail( { body: json }, 'The upload was interrupted.' );
					}
					continue;
				}
				failures = 0;
				sent = json.data.received;
				uploadBar.style.width = Math.round( 100 * sent / file.size ) + '%';
				uploadStatus.textContent = megabytes( sent ) + ' of ' + megabytes( file.size );
			}

			r = await api( 'POST', '/backup/' + encodeURIComponent( id ) + '/finish' );
			if ( ! ( r.body && r.body.ok ) ) {
				return fail( r, 'This file could not be used.' );
			}
			toast( 'Backup file added. You can restore it now.' );
			setTimeout( function () {
				window.location.reload();
			}, 900 );
		} );

		// --- Restore this site from a backup -------------------------------
		var restoreModal = document.getElementById( 'wpicker-restore-modal' );
		var restoreDialog = document.getElementById( 'wpicker-restore-dialog' );
		var thisSite = restoreModal.getAttribute( 'data-site' );
		var restoring = false;
		var partLabels = { db: 'Database (content, users, settings)', plugins: 'Plugins', themes: 'Themes', uploads: 'Uploads (media)' };

		var dismiss = document.getElementById( 'wpicker-restore-dismiss' );
		if ( dismiss ) {
			dismiss.addEventListener( 'click', async function () {
				await api( 'DELETE', '/restore' );
				document.getElementById( 'wpicker-restore-result' ).hidden = true;
			} );
		}

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( restoring ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		function closeRestore() {
			if ( restoring ) {
				return; // leaving mid-restore would strand it
			}
			restoreModal.hidden = true;
			restoreDialog.textContent = '';
			document.body.classList.remove( 'wpicker-modal-open' );
		}
		restoreModal.querySelector( '.wpicker-modal-backdrop' ).addEventListener( 'click', closeRestore );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && ! restoreModal.hidden ) {
				closeRestore();
			}
		} );

		table.querySelectorAll( '.wpicker-backup-restore' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var row = button.closest( 'tr' );
				askRestore( {
					id: row.getAttribute( 'data-backup-id' ),
					include: JSON.parse( row.getAttribute( 'data-include' ) || '{}' ),
					source: row.getAttribute( 'data-source' ) || '',
					created: row.getAttribute( 'data-created' ) || '',
					label: row.getAttribute( 'data-label' ) || '',
				} );
			} );
		} );

		function askRestore( backup ) {
			var parts = {};
			var partList = el( 'div', { class: 'wpicker-restore-parts' } );
			Object.keys( partLabels ).forEach( function ( key ) {
				if ( ! backup.include[ key ] ) {
					return;
				}
				var box = el( 'input', { type: 'checkbox' } );
				box.checked = true;
				parts[ key ] = box;
				partList.appendChild( el( 'label', {}, [ box, document.createTextNode( ' ' + partLabels[ key ] ) ] ) );
			} );

			var safety = el( 'input', { type: 'checkbox' } );
			safety.checked = true;
			var exact = el( 'input', { type: 'checkbox' } );
			exact.checked = true;
			var understood = el( 'input', { type: 'checkbox' } );
			var go = el( 'button', { type: 'button', class: 'button button-primary', text: 'Restore now' } );
			var cancel = el( 'button', { type: 'button', class: 'button', text: 'Cancel' } );
			go.disabled = true;
			understood.addEventListener( 'change', function () {
				go.disabled = ! understood.checked;
			} );
			cancel.addEventListener( 'click', closeRestore );

			var facts = [
				[ 'Backup', backup.id + ( backup.label ? ' — ' + backup.label : '' ) ],
				[ 'Taken', relativeTime( backup.created ) ],
				[ 'From', backup.source || 'unknown' ],
			];
			if ( backup.source && backup.source !== thisSite ) {
				facts.push( [ 'Addresses', backup.source + ' → ' + thisSite + ' (rewritten everywhere)' ] );
			}
			var factList = el( 'dl', { class: 'wpicker-facts' } );
			facts.forEach( function ( fact ) {
				factList.appendChild( el( 'dt', { text: fact[ 0 ] } ) );
				factList.appendChild( el( 'dd', { text: fact[ 1 ] } ) );
			} );

			var warnings = el( 'ul', { class: 'wpicker-restore-warn' }, [
				el( 'li', { text: 'The database is replaced: pages, posts, settings and user accounts become those of the backup. You will be signed out and log in with an account from the backup.' } ),
				el( 'li', { text: 'Files in the backup overwrite the ones here. With "exact copy" on, files that exist only here are deleted from the restored folders.' } ),
				el( 'li', { text: 'Kept as they are: this site\'s address, the WPicker plugin, its settings, backups and snapshots.' } ),
				el( 'li', { text: 'Paired devices may have to pair again if the backup comes from another site.' } ),
			] );

			restoreDialog.textContent = '';
			restoreDialog.appendChild( el( 'header', { class: 'wpicker-modal-head' }, [
				el( 'div', { class: 'wpicker-modal-title' }, [ el( 'h2', { id: 'wpicker-restore-title', text: 'Restore this site from a backup' } ) ] ),
			] ) );
			restoreDialog.appendChild( el( 'div', { class: 'wpicker-restore-body' }, [
				factList,
				el( 'h3', { text: 'What to restore' } ),
				partList,
				el( 'h3', { text: 'Before you start' } ),
				warnings,
				el( 'label', { class: 'wpicker-restore-check' }, [ exact, document.createTextNode( ' Exact copy: also remove files that are not in the backup' ) ] ),
				el( 'label', { class: 'wpicker-restore-check' }, [ safety, document.createTextNode( ' Back up the current site first, so this can be undone (recommended)' ) ] ),
				el( 'label', { class: 'wpicker-restore-check' }, [ understood, document.createTextNode( ' I understand this replaces what is on this site now' ) ] ),
			] ) );
			restoreDialog.appendChild( el( 'footer', { class: 'wpicker-restore-foot' }, [ cancel, go ] ) );

			go.addEventListener( 'click', function () {
				var chosen = {};
				Object.keys( parts ).forEach( function ( key ) {
					chosen[ key ] = parts[ key ].checked;
				} );
				if ( ! Object.keys( chosen ).some( function ( key ) {
					return chosen[ key ];
				} ) ) {
					toast( 'Choose at least one thing to restore.', true );
					return;
				}
				runRestore( backup, chosen, safety.checked, exact.checked );
			} );

			document.body.classList.add( 'wpicker-modal-open' );
			restoreModal.hidden = false;
			restoreDialog.focus();
		}

		async function runRestore( backup, parts, safety, exact ) {
			var steps = [
				[ 'safety', 'Back up the current site' ],
				[ 'scan', 'Read the backup' ],
				[ 'db', 'Load the database' ],
				[ 'rewrite', 'Rewrite addresses' ],
				[ 'files', 'Unpack files' ],
				[ 'prune', 'Remove files not in the backup' ],
				[ 'swap', 'Switch over' ],
			];
			var list = el( 'ol', { class: 'wpicker-restore-steps' } );
			var items = {};
			steps.forEach( function ( step ) {
				items[ step[ 0 ] ] = el( 'li', {}, [ el( 'span', { class: 'wpicker-step-name', text: step[ 1 ] } ), el( 'span', { class: 'wpicker-step-note' } ) ] );
				list.appendChild( items[ step[ 0 ] ] );
			} );
			var message = el( 'p', { class: 'wpicker-restore-message', role: 'status', text: 'Keep this tab open until it finishes.' } );
			var foot = el( 'footer', { class: 'wpicker-restore-foot' } );

			restoreDialog.textContent = '';
			restoreDialog.appendChild( el( 'header', { class: 'wpicker-modal-head' }, [
				el( 'div', { class: 'wpicker-modal-title' }, [ el( 'h2', { id: 'wpicker-restore-title', text: 'Restoring ' + backup.id } ) ] ),
			] ) );
			restoreDialog.appendChild( el( 'div', { class: 'wpicker-restore-body' }, [ list, message ] ) );
			restoreDialog.appendChild( foot );

			function paint( d ) {
				var order = steps.map( function ( step ) {
					return step[ 0 ];
				} );
				var at = d.done ? order.length : order.indexOf( d.phase );
				order.forEach( function ( name, i ) {
					var skipped = ( name === 'safety' && ! safety ) ||
						( ( name === 'db' || name === 'rewrite' || name === 'swap' ) && ! d.want.db ) ||
						( ( name === 'files' || name === 'prune' ) && ! ( d.want.plugins || d.want.themes || d.want.uploads ) ) ||
						( name === 'prune' && ! exact );
					items[ name ].className = skipped ? 'is-skipped' : ( i < at ? 'is-done' : ( i === at && ! d.failed ? 'is-active' : '' ) );
				} );
				items.db.lastChild.textContent = d.statements ? d.dump_percent + '% · ' + d.statements + ' statements' : '';
				items.rewrite.lastChild.textContent = d.rows_rewritten ? d.rows_rewritten + ' rows' : '';
				items.files.lastChild.textContent = d.files_total ? d.files_done + ' of ' + d.files_total : '';
				items.prune.lastChild.textContent = d.files_removed ? d.files_removed + ' removed' : '';
			}

			restoring = true;
			var r = await api( 'POST', '/restore', { backup_id: backup.id, parts: parts, safety: safety, exact: exact } );
			if ( ! ( r.body && r.body.ok ) ) {
				restoring = false;
				message.textContent = errorMessage( r, 'The restore could not start.' );
				message.classList.add( 'is-error' );
				var back = el( 'button', { type: 'button', class: 'button', text: 'Close' } );
				back.addEventListener( 'click', closeRestore );
				foot.appendChild( back );
				return;
			}

			var token = r.body.data.token;
			var d = r.body.data;
			var failures = 0;
			paint( d );
			while ( ! d.done && ! d.failed ) {
				var json = null;
				try {
					// No cookie and no nonce: half-way through, the user this
					// session belongs to is replaced. The token is what counts.
					var res = await fetch( restRoot + '/restore/step', {
						method: 'POST',
						credentials: 'omit',
						headers: { 'X-WPicker-Restore-Token': token },
					} );
					json = await res.json();
				} catch ( e ) {
					json = null;
				}
				if ( ! ( json && json.ok ) ) {
					if ( ++failures > 5 ) {
						d = { failed: true, done: false, phase: d.phase, want: d.want, error: ( json && json.message ) || 'The site stopped answering. Reload this page to see where the restore stands.' };
						break;
					}
					await new Promise( function ( resolve ) {
						setTimeout( resolve, 2000 );
					} );
					continue;
				}
				failures = 0;
				d = json.data;
				paint( d );
			}
			restoring = false;

			if ( d.done ) {
				message.textContent = 'Restore complete. You have been signed out — log in with an account from the backup.';
				message.classList.add( 'is-ok' );
				foot.appendChild( el( 'a', { class: 'button button-primary', href: d.login_url || restoreModal.getAttribute( 'data-login' ), text: 'Log in' } ) );
				return;
			}
			message.textContent = 'Restore failed: ' + ( d.error || 'unknown error' ) + ' The database was not changed.';
			message.classList.add( 'is-error' );
			var close = el( 'button', { type: 'button', class: 'button', text: 'Close' } );
			close.addEventListener( 'click', function () {
				window.location.reload();
			} );
			foot.appendChild( close );
		}

		var progress = document.getElementById( 'wpicker-backup-progress' );
		var bar = document.getElementById( 'wpicker-backup-bar' );
		var status = document.getElementById( 'wpicker-backup-status' );
		var label = document.getElementById( 'wpicker-backup-label' );

		// The database is one phase and the files another; show them as halves
		// when both are included.
		function paint( d ) {
			var db = d.tables_total ? d.tables_done / d.tables_total : ( d.include.db ? 0 : 1 );
			var files = d.phase === 'done' ? 1 : ( d.files_total ? d.files_done / d.files_total : 0 );
			var hasFiles = d.include.plugins || d.include.themes || d.include.uploads;
			var done = d.include.db && hasFiles ? ( db + files ) / 2 : ( d.include.db ? db : files );
			bar.style.width = Math.round( done * 100 ) + '%';
			if ( d.phase === 'db' ) {
				status.textContent = 'Database: table ' + d.tables_done + ' of ' + d.tables_total + ' (' + d.rows_done + ' rows)';
			} else if ( d.phase === 'done' ) {
				status.textContent = 'Done — ' + megabytes( d.bytes ) + '.';
			} else {
				status.textContent = 'Files: ' + d.files_done + ' of ' + d.files_total + ' · ' + megabytes( d.bytes );
			}
		}

		create.addEventListener( 'click', async function () {
			var include = {};
			document.querySelectorAll( '.wpicker-backup-part' ).forEach( function ( box ) {
				include[ box.value ] = box.checked;
			} );
			if ( ! Object.keys( include ).some( function ( key ) {
				return include[ key ];
			} ) ) {
				toast( 'Choose at least one thing to include.', true );
				return;
			}

			create.disabled = true;
			progress.hidden = false;
			bar.style.width = '0%';
			status.textContent = 'Starting…';

			var r = await api( 'POST', '/backup', { include: include, label: label.value } );
			var failures = 0;
			while ( r.body && r.body.ok && ! r.body.data.done ) {
				paint( r.body.data );
				var next = await api( 'POST', '/backup/' + encodeURIComponent( r.body.data.id ) + '/step' );
				// One step failing (a timeout, a dropped connection) is not the
				// end: the job keeps its place, so try the same step again.
				if ( ! ( next.body && next.body.ok ) && ++failures <= 3 ) {
					continue;
				}
				r = next;
			}

			if ( r.body && r.body.ok ) {
				paint( r.body.data );
				toast( 'Backup created.' );
				setTimeout( function () {
					window.location.reload();
				}, 900 );
				return;
			}
			create.disabled = false;
			status.textContent = 'The backup did not finish. It is listed below as incomplete; delete it and try again.';
			toast( errorMessage( r, 'Backup failed.' ), true );
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

	// --- Snapshots: search, filter, detail panel ---------------------------
	var table = document.getElementById( 'wpicker-vault-table' );
	if ( table ) {
		initSnapshots( table );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'class' ) {
				node.className = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function errorMessage( r, fallback ) {
		var body = r.body || {};
		return ( body.error && body.error.message ) || body.message || fallback;
	}

	var toastTimer = null;
	function toast( message, isError ) {
		var node = document.getElementById( 'wpicker-toast' );
		if ( ! node ) {
			return;
		}
		node.textContent = message;
		node.classList.toggle( 'is-error', !! isError );
		node.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			node.hidden = true;
		}, isError ? 9000 : 4000 );
	}

	function relativeTime( iso ) {
		var then = Date.parse( iso );
		if ( isNaN( then ) ) {
			return iso;
		}
		var seconds = Math.round( ( Date.now() - then ) / 1000 );
		var units = [ [ 60, 'second' ], [ 60, 'minute' ], [ 24, 'hour' ], [ 30, 'day' ], [ 12, 'month' ], [ Infinity, 'year' ] ];
		var value = seconds;
		for ( var i = 0; i < units.length; i++ ) {
			if ( Math.abs( value ) < units[ i ][ 0 ] ) {
				return new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } ).format( -Math.round( value ), units[ i ][ 1 ] );
			}
			value = value / units[ i ][ 0 ];
		}
		return iso;
	}

	function store( key, value ) {
		try {
			if ( value === undefined ) {
				return window.localStorage.getItem( 'wpicker.' + key );
			}
			window.localStorage.setItem( 'wpicker.' + key, value );
		} catch ( e ) {
			// Private mode or storage disabled: fall back to defaults.
		}
		return null;
	}

	function initSnapshots( table ) {
		var rows = Array.prototype.slice.call( table.querySelectorAll( '.wpicker-row' ) );
		var search = document.getElementById( 'wpicker-search' );
		var kind = document.getElementById( 'wpicker-filter-kind' );
		var device = document.getElementById( 'wpicker-filter-device' );
		var chips = Array.prototype.slice.call( document.querySelectorAll( '.wpicker-chip' ) );
		var empty = document.getElementById( 'wpicker-empty' );
		var pager = document.getElementById( 'wpicker-pager' );
		var pageSize = document.getElementById( 'wpicker-page-size' );
		var pageRange = document.getElementById( 'wpicker-pager-range' );
		var pagePrev = document.getElementById( 'wpicker-page-prev' );
		var pageNext = document.getElementById( 'wpicker-page-next' );
		var modal = document.getElementById( 'wpicker-modal' );
		var dialog = document.getElementById( 'wpicker-modal-dialog' );
		var status = '';
		var page = 0;
		var opener = null;

		var restorable = ( modal.getAttribute( 'data-restorable' ) || '' ).split( ',' );
		var statusLabels = JSON.parse( modal.getAttribute( 'data-status-labels' ) || '{}' );
		var kindLabels = JSON.parse( modal.getAttribute( 'data-kind-labels' ) || '{}' );

		table.querySelectorAll( '.wpicker-time' ).forEach( function ( time ) {
			var iso = time.getAttribute( 'datetime' );
			time.title = iso;
			time.textContent = relativeTime( iso );
		} );

		// --- Search, filters, pagination -----------------------------------
		var saved = store( 'pageSize' );
		pageSize.value = [ '5', '10', '50', '100', '0' ].indexOf( saved ) !== -1 ? saved : '5';

		function render() {
			var words = search.value.toLowerCase().split( /\s+/ ).filter( Boolean );
			var matches = rows.filter( function ( row ) {
				var haystack = row.getAttribute( 'data-search' ) || '';
				return ( ! status || row.getAttribute( 'data-status' ) === status ) &&
					( ! kind.value || row.getAttribute( 'data-kind' ) === kind.value ) &&
					( ! device.value || row.getAttribute( 'data-device' ) === device.value ) &&
					words.every( function ( word ) {
						return haystack.indexOf( word ) !== -1;
					} );
			} );

			var size = parseInt( pageSize.value, 10 ) || matches.length || 1;
			var pages = Math.max( 1, Math.ceil( matches.length / size ) );
			page = Math.min( Math.max( 0, page ), pages - 1 );
			var first = page * size;
			var visible = matches.slice( first, first + size );

			rows.forEach( function ( row ) {
				row.hidden = visible.indexOf( row ) === -1;
			} );
			empty.hidden = matches.length > 0;
			pager.hidden = rows.length === 0;
			pageRange.textContent = matches.length
				? ( first + 1 ) + '–' + ( first + visible.length ) + ' of ' + matches.length
				: '0 of 0';
			pagePrev.disabled = page === 0;
			pageNext.disabled = page >= pages - 1;
			syncSelection();
		}

		function refilter() {
			page = 0;
			render();
		}

		search.addEventListener( 'input', refilter );
		kind.addEventListener( 'change', refilter );
		device.addEventListener( 'change', refilter );
		pageSize.addEventListener( 'change', function () {
			store( 'pageSize', pageSize.value );
			refilter();
		} );
		pagePrev.addEventListener( 'click', function () {
			page--;
			render();
		} );
		pageNext.addEventListener( 'click', function () {
			page++;
			render();
		} );
		chips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				status = chip.getAttribute( 'data-status' );
				chips.forEach( function ( other ) {
					other.classList.toggle( 'is-active', other === chip );
				} );
				refilter();
			} );
		} );
		// --- Selection and deletion ------------------------------------------
		var checkAll = document.getElementById( 'wpicker-check-all' );
		var bulk = document.getElementById( 'wpicker-bulk' );
		var bulkCount = document.getElementById( 'wpicker-bulk-count' );
		var deleteAll = document.getElementById( 'wpicker-delete-all' );

		function checkOf( row ) {
			return row.querySelector( '.wpicker-check' );
		}

		function selectedIds() {
			return rows.filter( function ( row ) {
				return checkOf( row ).checked;
			} ).map( function ( row ) {
				return row.getAttribute( 'data-id' );
			} );
		}

		// Selection survives paging and filtering; the header box reflects
		// only the rows on screen.
		function syncSelection() {
			var shown = rows.filter( function ( row ) {
				return ! row.hidden;
			} );
			var shownChecked = shown.filter( function ( row ) {
				return checkOf( row ).checked;
			} ).length;
			var total = selectedIds().length;
			checkAll.checked = shown.length > 0 && shownChecked === shown.length;
			checkAll.indeterminate = shownChecked > 0 && shownChecked < shown.length;
			bulk.hidden = total === 0;
			bulkCount.textContent = total + ' selected';
		}

		rows.forEach( function ( row ) {
			var box = checkOf( row );
			// The checkbox cell must not open the dialog.
			box.parentNode.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
			} );
			box.addEventListener( 'change', syncSelection );
		} );
		checkAll.addEventListener( 'change', function () {
			rows.forEach( function ( row ) {
				if ( ! row.hidden ) {
					checkOf( row ).checked = checkAll.checked;
				}
			} );
			syncSelection();
		} );
		document.getElementById( 'wpicker-clear-selection' ).addEventListener( 'click', function () {
			rows.forEach( function ( row ) {
				checkOf( row ).checked = false;
			} );
			syncSelection();
		} );
		document.getElementById( 'wpicker-delete-selected' ).addEventListener( 'click', function () {
			var ids = selectedIds();
			removeSnapshots( { ids: ids }, 'Delete ' + ids.length + ' snapshot' + ( ids.length === 1 ? '' : 's' ) + '?' );
		} );
		deleteAll.hidden = rows.length === 0;
		deleteAll.addEventListener( 'click', function () {
			var review = parseInt( deleteAll.getAttribute( 'data-review' ), 10 ) || 0;
			var question = 'Delete ALL ' + deleteAll.getAttribute( 'data-total' ) + ' snapshots?';
			if ( review ) {
				question += ' ' + review + ' push' + ( review === 1 ? '' : 'es' ) + ' waiting for review will be discarded.';
			}
			removeSnapshots( { all: true }, question );
		} );

		// First paint, now that everything render() touches exists.
		render();

		async function removeSnapshots( body, question ) {
			if ( ! window.confirm( question + '\n\nThis cannot be undone: you will no longer be able to restore to them. The site itself is not changed.' ) ) {
				return;
			}
			var r = await api( 'POST', '/snapshot/delete', body );
			if ( r.body && r.body.ok ) {
				toast( r.body.data.count + ' snapshot' + ( r.body.data.count === 1 ? '' : 's' ) + ' deleted.' );
				setTimeout( function () {
					window.location.reload();
				}, 900 );
				return;
			}
			toast( errorMessage( r, 'Delete failed.' ), true );
		}

		// --- Detail dialog ---------------------------------------------------
		rows.forEach( function ( row ) {
			row.addEventListener( 'click', function () {
				openModal( row );
			} );
			row.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' && event.target === row ) {
					openModal( row );
				}
			} );
		} );

		modal.addEventListener( 'click', function ( event ) {
			if ( event.target.getAttribute( 'data-close' ) ) {
				closeModal();
			}
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( modal.hidden ) {
				return;
			}
			if ( event.key === 'Escape' ) {
				closeModal();
				return;
			}
			// Keep Tab inside the dialog while it is open.
			if ( event.key === 'Tab' ) {
				var focusable = dialog.querySelectorAll( 'button:not([disabled]), input:not([disabled]), [href]' );
				if ( ! focusable.length ) {
					return;
				}
				var firstEl = focusable[ 0 ];
				var lastEl = focusable[ focusable.length - 1 ];
				if ( event.shiftKey && document.activeElement === firstEl ) {
					event.preventDefault();
					lastEl.focus();
				} else if ( ! event.shiftKey && document.activeElement === lastEl ) {
					event.preventDefault();
					firstEl.focus();
				}
			}
		} );

		function closeModal() {
			modal.hidden = true;
			dialog.textContent = '';
			document.body.classList.remove( 'wpicker-modal-open' );
			rows.forEach( function ( row ) {
				row.classList.remove( 'is-selected' );
			} );
			if ( opener ) {
				opener.focus();
			}
		}

		async function openModal( row ) {
			var id = row.getAttribute( 'data-id' );
			opener = row;
			rows.forEach( function ( other ) {
				other.classList.toggle( 'is-selected', other === row );
			} );
			document.body.classList.add( 'wpicker-modal-open' );
			modal.hidden = false;
			dialog.textContent = '';
			dialog.appendChild( el( 'p', { class: 'wpicker-diff-note', text: 'Loading…' } ) );
			dialog.focus();

			var r = await api( 'GET', '/history/' + encodeURIComponent( id ) );
			if ( modal.hidden ) {
				return; // closed while loading
			}
			if ( ! ( r.body && r.body.ok ) ) {
				dialog.textContent = '';
				dialog.appendChild( el( 'p', { class: 'wpicker-diff-note', text: errorMessage( r, 'Could not load this snapshot.' ) } ) );
				return;
			}
			renderModal( r.body.data );
		}

		function renderModal( m ) {
			var inReview = m.status === 'review';
			var canRestore = restorable.indexOf( m.status ) !== -1 && m.snapshot_exists;
			// Old records have a file list but no per-file action.
			var changes = ( m.changes && m.changes.length ) ? m.changes : ( m.files || [] ).map( function ( path ) {
				return { path: path, action: '' };
			} );

			var actions = el( 'div', { class: 'wpicker-modal-actions' } );
			if ( inReview ) {
				var reject = el( 'button', { type: 'button', class: 'button wpicker-danger', text: 'Reject' } );
				var apply = el( 'button', { type: 'button', class: 'button button-primary', text: 'Apply to site' } );
				reject.addEventListener( 'click', function () {
					act( m.id, 'reject', 'Reject this push? The files are discarded.', [ apply, reject ] );
				} );
				apply.addEventListener( 'click', function () {
					act( m.id, 'apply', 'Apply these ' + changes.length + ' change(s) to the live site?', [ apply, reject ] );
				} );
				actions.appendChild( reject );
				actions.appendChild( apply );
			}
			// Two different ways back. Revert undoes this one push and keeps
			// later ones; restore rewinds the whole theme to before this entry.
			var revert = null;
			if ( m.can_revert ) {
				revert = el( 'button', { type: 'button', class: 'button', text: 'Revert this push' } );
				revert.title = 'Undo only the ' + changes.length + ' file change(s) of this push. Later pushes are kept.';
			}
			if ( canRestore ) {
				var restore = el( 'button', { type: 'button', class: 'button', text: 'Restore to before this' } );
				restore.title = 'Put the whole theme back to how it was just before this entry. This push and every later one are undone.';
				restore.addEventListener( 'click', function () {
					act( m.id, 'restore', 'Restore the WHOLE theme to how it was just before ' + m.id + '?\n\nThis push and every later push are undone. The current state is saved first.', [ restore, revert ] );
				} );
				if ( revert ) {
					revert.addEventListener( 'click', function () {
						act( m.id, 'revert', 'Revert this push?\n\nOnly its ' + changes.length + ' file change(s) are undone. Later pushes are kept.', [ restore, revert ] );
					} );
					actions.appendChild( revert );
				}
				actions.appendChild( restore );
			}
			var remove = el( 'button', { type: 'button', class: 'button-link wpicker-danger-link', text: 'Delete' } );
			remove.addEventListener( 'click', function () {
				removeSnapshots( { ids: [ m.id ] }, inReview
					? 'Delete this push? It is waiting for review and will be discarded.'
					: 'Delete snapshot ' + m.id + '?' );
			} );
			actions.insertBefore( remove, actions.firstChild );

			var close = el( 'button', { type: 'button', class: 'wpicker-modal-close', 'aria-label': 'Close', text: '×' } );
			close.addEventListener( 'click', closeModal );
			actions.appendChild( close );

			var head = el( 'header', { class: 'wpicker-modal-head' }, [
				el( 'div', { class: 'wpicker-modal-title' }, [
					el( 'h2', { id: 'wpicker-modal-title', text: m.id } ),
					el( 'span', { class: 'wpicker-status wpicker-status-' + m.status, text: statusLabels[ m.status ] || m.status } ),
					el( 'span', {
						class: 'wpicker-modal-meta',
						text: ( kindLabels[ m.kind ] || m.kind ) + ' · ' + ( m.device_name || 'unknown device' ) + ' · ' + relativeTime( m.created_at_gmt ),
						title: m.created_at_gmt,
					} ),
				] ),
				actions,
			] );

			var body = el( 'div', { class: 'wpicker-modal-body' } );
			var tabChanges = el( 'button', { type: 'button', class: 'wpicker-subtab is-active', text: 'Changes (' + changes.length + ')' } );
			var tabLog = el( 'button', { type: 'button', class: 'wpicker-subtab', text: 'Log' + ( m.error ? ' ⚠' : '' ) } );
			tabChanges.addEventListener( 'click', function () {
				tabChanges.classList.add( 'is-active' );
				tabLog.classList.remove( 'is-active' );
				renderChanges( body, m, changes, canRestore );
			} );
			tabLog.addEventListener( 'click', function () {
				tabLog.classList.add( 'is-active' );
				tabChanges.classList.remove( 'is-active' );
				renderLog( body, m );
			} );

			dialog.textContent = '';
			dialog.appendChild( head );
			if ( inReview ) {
				dialog.appendChild( el( 'p', { class: 'wpicker-modal-banner', text: 'Nothing has changed on the site yet. Check each file, then apply or reject.' } ) );
			}
			dialog.appendChild( el( 'div', { class: 'wpicker-subtabs' }, [ tabChanges, tabLog ] ) );
			dialog.appendChild( body );
			renderChanges( body, m, changes, canRestore );
		}

		function renderChanges( body, m, changes, canRestore ) {
			var badge = { added: 'A', modified: 'M', deleted: 'D' };
			var mode = 'change';
			var layout = store( 'diffLayout' ) === 'unified' ? 'unified' : 'split';
			var current = null;
			var last = null;

			body.className = 'wpicker-modal-body';
			body.textContent = '';
			if ( ! changes.length ) {
				body.appendChild( el( 'p', { class: 'wpicker-diff-note', text: 'No file list was recorded for this entry.' } ) );
				return;
			}

			var actionLabel = { added: 'Added', modified: 'Modified', deleted: 'Deleted' };
			var root = m.theme_path ? m.theme_path + '/' : '';

			// "inc/parts/card.php" → folder "inc/parts/", name "card.php".
			function split( full ) {
				var cut = full.lastIndexOf( '/' ) + 1;
				return { dir: full.slice( 0, cut ), name: full.slice( cut ) };
			}

			function counts( added, removed ) {
				return el( 'span', { class: 'wpicker-file-counts' }, [
					added ? el( 'span', { class: 'is-added', text: '+' + added } ) : null,
					removed ? el( 'span', { class: 'is-deleted', text: '−' + removed } ) : null,
				] );
			}

			var totalAdded = 0;
			var totalRemoved = 0;
			changes.forEach( function ( change ) {
				totalAdded += change.added || 0;
				totalRemoved += change.removed || 0;
			} );

			var items = el( 'div', { class: 'wpicker-file-list' } );
			var list = el( 'nav', { class: 'wpicker-files', 'aria-label': 'Changed files' }, [
				el( 'div', { class: 'wpicker-files-head' }, [
					el( 'span', { class: 'wpicker-files-root', text: root || 'Theme files', title: 'Folder these paths are relative to' } ),
					el( 'span', { class: 'wpicker-files-total' }, [
						document.createTextNode( changes.length + ( changes.length === 1 ? ' file' : ' files' ) ),
						counts( totalAdded, totalRemoved ),
					] ),
				] ),
				items,
			] );

			var crumb = el( 'span', { class: 'wpicker-diff-path' } );
			var chip = el( 'span', { class: 'wpicker-diff-action' } );
			var stats = el( 'span', { class: 'wpicker-diff-stats' } );
			var copy = el( 'button', { type: 'button', class: 'button-link wpicker-diff-copy', text: 'Copy path' } );
			var code = el( 'div', { class: 'wpicker-diff' } );
			var bar = el( 'div', { class: 'wpicker-diff-bar' }, [ crumb, chip, copy, stats ] );

			copy.addEventListener( 'click', function () {
				if ( ! current || ! navigator.clipboard ) {
					return;
				}
				navigator.clipboard.writeText( root + current ).then( function () {
					copy.textContent = 'Copied';
					setTimeout( function () {
						copy.textContent = 'Copy path';
					}, 1500 );
				} );
			} );

			// Header of the diff pane: muted folders, bold file name, what happened.
			function showPath( change ) {
				var parts = split( change.path );
				crumb.textContent = '';
				crumb.appendChild( el( 'span', { class: 'wpicker-diff-dir', text: root + parts.dir } ) );
				crumb.appendChild( el( 'strong', { text: parts.name } ) );
				chip.className = 'wpicker-diff-action is-' + ( change.action || 'modified' );
				chip.textContent = actionLabel[ change.action ] || 'Changed';
			}

			changes.forEach( function ( change ) {
				var parts = split( change.path );
				var detail = change.binary ? 'binary' : ( change.too_large ? 'large file' : '' );
				var button = el( 'button', { type: 'button', class: 'wpicker-file', title: root + change.path }, [
					el( 'span', { class: 'wpicker-file-badge is-' + ( change.action || 'modified' ), text: badge[ change.action ] || '•', title: actionLabel[ change.action ] || '' } ),
					el( 'span', { class: 'wpicker-file-text' }, [
						el( 'span', { class: 'wpicker-file-name', text: parts.name } ),
						el( 'span', { class: 'wpicker-file-dir', text: parts.dir || './' } ),
					] ),
					detail ? el( 'span', { class: 'wpicker-file-counts wpicker-muted', text: detail } ) : counts( change.added, change.removed ),
				] );
				button.addEventListener( 'click', function () {
					current = change.path;
					Array.prototype.forEach.call( items.children, function ( other ) {
						other.classList.toggle( 'is-active', other === button );
					} );
					showPath( change );
					loadDiff();
				} );
				items.appendChild( button );
			} );

			// A pending push has no snapshot yet; a safety backup made no change
			// of its own, so for it the only meaningful diff is the restore preview.
			if ( canRestore && m.kind !== 'rollback_safety' ) {
				var toggle = el( 'input', { type: 'checkbox', id: 'wpicker-diff-restore' } );
				toggle.addEventListener( 'change', function () {
					mode = toggle.checked ? 'restore' : 'change';
					loadDiff();
				} );
				bar.appendChild( el( 'label', { class: 'wpicker-diff-option', for: 'wpicker-diff-restore' }, [
					toggle,
					document.createTextNode( ' Preview restore' ),
				] ) );
			} else if ( canRestore ) {
				mode = 'restore';
			}

			var seg = el( 'div', { class: 'wpicker-seg', role: 'group', 'aria-label': 'Diff layout' } );
			[ 'split', 'unified' ].forEach( function ( name ) {
				var button = el( 'button', { type: 'button', class: 'wpicker-seg-btn' + ( name === layout ? ' is-active' : '' ), text: name === 'split' ? 'Split' : 'Unified' } );
				button.addEventListener( 'click', function () {
					layout = name;
					store( 'diffLayout', name );
					Array.prototype.forEach.call( seg.children, function ( other ) {
						other.classList.toggle( 'is-active', other === button );
					} );
					if ( last ) {
						paint( last );
					}
				} );
				seg.appendChild( button );
			} );
			bar.appendChild( seg );

			function note( text ) {
				code.textContent = '';
				code.appendChild( el( 'p', { class: 'wpicker-diff-note', text: text } ) );
			}

			function paint( d ) {
				stats.textContent = '';
				if ( d.identical ) {
					return note( 'No difference between "' + d.left_title + '" and "' + d.right_title + '".' );
				}
				if ( d.binary ) {
					return note( 'Binary file — contents differ.' );
				}
				if ( d.too_large ) {
					return note( 'File too large to show a diff.' );
				}
				if ( d.added ) {
					stats.appendChild( el( 'span', { class: 'is-added', text: '+' + d.added } ) );
				}
				if ( d.removed ) {
					stats.appendChild( el( 'span', { class: 'is-deleted', text: '−' + d.removed } ) );
				}

				code.textContent = '';
				var caption = '';
				if ( ! d.left_exists ) {
					caption = 'New file';
				} else if ( ! d.right_exists ) {
					caption = 'File removed';
				}
				code.appendChild( layout === 'split'
					? splitTable( d, caption )
					: unifiedTable( d, caption ) );
			}

			async function loadDiff() {
				if ( ! current ) {
					return;
				}
				stats.textContent = '';
				note( 'Loading…' );
				var wanted = current + '|' + mode;
				var r = await api( 'GET', '/snapshot/' + encodeURIComponent( m.id ) + '/diff?mode=' + mode + '&path=' + encodeURIComponent( current ) );
				if ( wanted !== current + '|' + mode ) {
					return; // a newer selection is already loading
				}
				if ( ! ( r.body && r.body.ok ) ) {
					last = null;
					return note( errorMessage( r, 'Could not load the diff.' ) );
				}
				last = r.body.data;
				paint( last );
			}

			body.appendChild( list );
			body.appendChild( el( 'section', { class: 'wpicker-diff-pane' }, [ bar, code ] ) );
			items.firstChild.click();
		}

		// --- Diff rendering ---------------------------------------------------

		// Mark the part of a replaced line that actually differs.
		function inline( mine, other, cls ) {
			var cell = document.createDocumentFragment();
			if ( other === undefined || mine === other ) {
				cell.appendChild( document.createTextNode( mine ) );
				return cell;
			}
			var start = 0;
			var max = Math.min( mine.length, other.length );
			while ( start < max && mine[ start ] === other[ start ] ) {
				start++;
			}
			var end = 0;
			while ( end < max - start && mine[ mine.length - 1 - end ] === other[ other.length - 1 - end ] ) {
				end++;
			}
			cell.appendChild( document.createTextNode( mine.slice( 0, start ) ) );
			if ( mine.length - end > start ) {
				cell.appendChild( el( 'span', { class: cls, text: mine.slice( start, mine.length - end ) } ) );
			}
			cell.appendChild( document.createTextNode( mine.slice( mine.length - end ) ) );
			return cell;
		}

		function cell( cls, content ) {
			var td = el( 'td', { class: cls } );
			if ( content !== undefined && content !== null ) {
				td.appendChild( typeof content === 'object' ? content : document.createTextNode( String( content ) ) );
			}
			return td;
		}

		function gapRow( n, span ) {
			return el( 'tr', { class: 'wpicker-d-gap' }, [
				el( 'td', { colspan: String( span ), text: '⋯ ' + n + ' unchanged line' + ( n === 1 ? '' : 's' ) } ),
			] );
		}

		function head( titles, span, caption ) {
			var thead = el( 'thead' );
			var tr = el( 'tr' );
			titles.forEach( function ( title ) {
				tr.appendChild( el( 'th', { colspan: String( span ), text: title } ) );
			} );
			thead.appendChild( tr );
			if ( caption ) {
				thead.appendChild( el( 'tr', { class: 'wpicker-d-caption' }, [
					el( 'td', { colspan: String( span * titles.length ), text: caption } ),
				] ) );
			}
			return thead;
		}

		function splitTable( d, caption ) {
			var tbody = el( 'tbody' );
			d.rows.forEach( function ( row ) {
				if ( row.t === 'gap' ) {
					tbody.appendChild( gapRow( row.n, 4 ) );
					return;
				}
				if ( row.t === 'ctx' ) {
					tbody.appendChild( el( 'tr', {}, [
						cell( 'wpicker-d-num', row.l ), cell( 'wpicker-d-code', row.s ),
						cell( 'wpicker-d-num', row.r ), cell( 'wpicker-d-code', row.s ),
					] ) );
					return;
				}
				var hasL = row.l !== undefined;
				var hasR = row.r !== undefined;
				tbody.appendChild( el( 'tr', {}, [
					cell( 'wpicker-d-num' + ( hasL ? ' is-del' : ' is-void' ), hasL ? row.l : '' ),
					cell( 'wpicker-d-code' + ( hasL ? ' is-del' : ' is-void' ), hasL ? inline( row.ls, row.rs, 'wpicker-d-mark-del' ) : null ),
					cell( 'wpicker-d-num' + ( hasR ? ' is-add' : ' is-void' ), hasR ? row.r : '' ),
					cell( 'wpicker-d-code' + ( hasR ? ' is-add' : ' is-void' ), hasR ? inline( row.rs, row.ls, 'wpicker-d-mark-add' ) : null ),
				] ) );
			} );
			return el( 'table', { class: 'wpicker-d wpicker-d-split' }, [
				el( 'colgroup', {}, [
					el( 'col', { class: 'wpicker-d-col-num' } ), el( 'col' ),
					el( 'col', { class: 'wpicker-d-col-num' } ), el( 'col' ),
				] ),
				head( [ d.left_title, d.right_title ], 2, caption ),
				tbody,
			] );
		}

		function unifiedTable( d, caption ) {
			var tbody = el( 'tbody' );
			var block = [];

			// Like git: within one changed block, all removals, then all additions.
			function flush() {
				block.forEach( function ( row ) {
					if ( row.l !== undefined ) {
						tbody.appendChild( el( 'tr', {}, [
							cell( 'wpicker-d-num is-del', row.l ), cell( 'wpicker-d-num is-del', '' ),
							cell( 'wpicker-d-sign is-del', '−' ),
							cell( 'wpicker-d-code is-del', inline( row.ls, row.rs, 'wpicker-d-mark-del' ) ),
						] ) );
					}
				} );
				block.forEach( function ( row ) {
					if ( row.r !== undefined ) {
						tbody.appendChild( el( 'tr', {}, [
							cell( 'wpicker-d-num is-add', '' ), cell( 'wpicker-d-num is-add', row.r ),
							cell( 'wpicker-d-sign is-add', '+' ),
							cell( 'wpicker-d-code is-add', inline( row.rs, row.ls, 'wpicker-d-mark-add' ) ),
						] ) );
					}
				} );
				block = [];
			}

			d.rows.forEach( function ( row ) {
				if ( row.t === 'chg' ) {
					block.push( row );
					return;
				}
				flush();
				if ( row.t === 'gap' ) {
					tbody.appendChild( gapRow( row.n, 4 ) );
					return;
				}
				tbody.appendChild( el( 'tr', {}, [
					cell( 'wpicker-d-num', row.l ), cell( 'wpicker-d-num', row.r ),
					cell( 'wpicker-d-sign', '' ), cell( 'wpicker-d-code', row.s ),
				] ) );
			} );
			flush();

			return el( 'table', { class: 'wpicker-d wpicker-d-unified' }, [
				el( 'colgroup', {}, [
					el( 'col', { class: 'wpicker-d-col-num' } ), el( 'col', { class: 'wpicker-d-col-num' } ),
					el( 'col', { class: 'wpicker-d-col-sign' } ), el( 'col' ),
				] ),
				head( [ d.left_title + ' → ' + d.right_title ], 4, caption ),
				tbody,
			] );
		}

		function renderLog( body, m ) {
			body.className = 'wpicker-modal-body is-log';
			body.textContent = '';
			var entries = m.log || [];
			if ( ! entries.length && ! m.error ) {
				body.appendChild( el( 'p', { class: 'wpicker-diff-note', text: 'No activity recorded for this entry.' } ) );
				return;
			}
			var list = el( 'ul', { class: 'wpicker-log' } );
			entries.forEach( function ( entry ) {
				list.appendChild( el( 'li', {}, [
					el( 'span', { class: 'wpicker-log-event', text: entry.event } ),
					el( 'span', { class: 'wpicker-log-time', text: relativeTime( entry.time_gmt ) + ( entry.by ? ' · ' + entry.by : '' ), title: entry.time_gmt } ),
					entry.message ? el( 'p', { class: 'wpicker-log-msg', text: entry.message } ) : null,
				] ) );
			} );
			body.appendChild( list );
			if ( m.error ) {
				body.appendChild( el( 'h3', { class: 'wpicker-log-heading', text: 'Error details' } ) );
				body.appendChild( el( 'pre', { class: 'wpicker-error-pre', text: JSON.stringify( m.error, null, 2 ) } ) );
			}
		}

		// resolve: decisions for files that collide, { path: 'apply' | 'skip' }.
		async function act( id, action, question, buttons, resolve ) {
			if ( question && ! window.confirm( question ) ) {
				return;
			}
			buttons = buttons.filter( Boolean );
			buttons.forEach( function ( button ) {
				button.disabled = true;
			} );
			var r = action === 'restore'
				? await api( 'POST', '/rollback', { manifest_id: id, device: { name: 'admin-web' } } )
				: await api( 'POST', '/snapshot/' + encodeURIComponent( id ) + '/' + action, { resolve: resolve || {} } );

			if ( r.body && r.body.ok ) {
				var done = { apply: 'Applied to the site.', reject: 'Push rejected.', restore: 'Theme restored.', revert: 'Push reverted.' };
				toast( done[ action ] );
				setTimeout( function () {
					window.location.reload();
				}, 900 );
				return;
			}
			buttons.forEach( function ( button ) {
				button.disabled = false;
			} );

			// Colliding changes are not a failure: ask what to do with each file.
			var error = ( r.body && r.body.error ) || {};
			if ( error.resolvable && error.files && error.files.length ) {
				showConflicts( id, action, error.files );
				return;
			}

			toast( errorMessage( r, 'The action failed.' ), true );
			// A failed apply changes the entry (aborted, with an error log): show it.
			if ( action === 'apply' ) {
				setTimeout( function () {
					window.location.reload();
				}, 2500 );
			}
		}

		// Conflict resolution: the files this action wants to write were changed
		// by something else in the meantime. Per file the admin chooses which
		// side wins, with a diff of exactly what "apply" would overwrite.
		function showConflicts( id, action, files ) {
			var wording = action === 'revert'
				? {
					title: files.length + ' file' + ( files.length === 1 ? ' was' : 's were' ) + ' changed again after this push',
					intro: 'Undoing this push would also throw away those later changes. Choose what happens to each file.',
					apply: 'Undo anyway',
					applyHint: 'Put back the version from before this push. The later change is lost.',
					skip: 'Keep as it is',
					skipHint: 'Leave this file alone. The rest of the push is still undone.',
					go: 'Revert with these choices',
					mode: 'restore',
				}
				: {
					title: files.length + ' file' + ( files.length === 1 ? ' has' : 's have' ) + ' changed on the site since this push was submitted',
					intro: 'The pushed version was written against an older copy. Choose which one the site ends up with.',
					apply: 'Use the pushed version',
					applyHint: 'Overwrite the newer change on the site.',
					skip: 'Keep the site\'s version',
					skipHint: 'Leave this file alone. The rest of the push is still applied.',
					go: 'Apply with these choices',
					mode: 'change',
				};

			var body = dialog.querySelector( '.wpicker-modal-body' );
			var choices = {};
			var go = el( 'button', { type: 'button', class: 'button button-primary', text: wording.go } );
			var cancel = el( 'button', { type: 'button', class: 'button', text: 'Cancel' } );
			var view = el( 'div', { class: 'wpicker-diff wpicker-conflict-diff' }, [
				el( 'p', { class: 'wpicker-diff-note', text: 'Choose "Compare" on a file to see what would be overwritten.' } ),
			] );
			var list = el( 'div', { class: 'wpicker-conflict-list' } );
			go.disabled = true;

			files.forEach( function ( file ) {
				var apply = el( 'button', { type: 'button', class: 'wpicker-seg-btn', text: wording.apply, title: wording.applyHint } );
				var skip = el( 'button', { type: 'button', class: 'wpicker-seg-btn', text: wording.skip, title: wording.skipHint } );
				var compare = el( 'button', { type: 'button', class: 'button-link', text: 'Compare' } );

				function choose( value ) {
					choices[ file ] = value;
					apply.classList.toggle( 'is-active', value === 'apply' );
					skip.classList.toggle( 'is-active', value === 'skip' );
					go.disabled = Object.keys( choices ).length < files.length;
				}
				apply.addEventListener( 'click', function () {
					choose( 'apply' );
				} );
				skip.addEventListener( 'click', function () {
					choose( 'skip' );
				} );
				compare.addEventListener( 'click', async function () {
					view.textContent = '';
					view.appendChild( el( 'p', { class: 'wpicker-diff-note', text: 'Loading…' } ) );
					var r = await api( 'GET', '/snapshot/' + encodeURIComponent( id ) + '/diff?mode=' + wording.mode + '&path=' + encodeURIComponent( file ) );
					view.textContent = '';
					if ( ! ( r.body && r.body.ok ) ) {
						view.appendChild( el( 'p', { class: 'wpicker-diff-note', text: errorMessage( r, 'Could not load the diff.' ) } ) );
						return;
					}
					var d = r.body.data;
					if ( d.identical || d.binary || d.too_large ) {
						view.appendChild( el( 'p', { class: 'wpicker-diff-note', text: d.identical ? 'Both versions are the same.' : ( d.binary ? 'Binary file — contents differ.' : 'File too large to show a diff.' ) } ) );
						return;
					}
					view.appendChild( el( 'p', { class: 'wpicker-diff-caption', text: file + ' — left: what is on the site now. Right: what "' + wording.apply + '" would put there.' } ) );
					view.appendChild( splitTable( d, '' ) );
				} );

				list.appendChild( el( 'div', { class: 'wpicker-conflict-row' }, [
					el( 'code', { class: 'wpicker-conflict-path', text: file } ),
					compare,
					el( 'div', { class: 'wpicker-seg', role: 'group', 'aria-label': 'Decision for ' + file }, [ apply, skip ] ),
				] ) );
			} );

			cancel.addEventListener( 'click', function () {
				if ( opener ) {
					openModal( opener );
				}
			} );
			go.addEventListener( 'click', function () {
				act( id, action, null, [ go, cancel ], choices );
			} );

			body.className = 'wpicker-modal-body is-conflict';
			body.textContent = '';
			body.appendChild( el( 'div', { class: 'wpicker-conflict' }, [
				el( 'h3', { text: wording.title } ),
				el( 'p', { class: 'wpicker-muted', text: wording.intro } ),
				list,
				el( 'div', { class: 'wpicker-conflict-foot' }, [ cancel, go ] ),
				view,
			] ) );
		}
	}
} )();
