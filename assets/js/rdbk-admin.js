/**
 * RD Backup — admin progress loop.
 *
 * Drives the resumable job from the browser: start → step → step … until done,
 * updating the progress bar. State lives server-side, so closing the tab does
 * not lose the job (resume support arrives with the real engine).
 */
( function () {
	'use strict';

	var cfg = window.rdbkData || {};
	var i18n = cfg.i18n || {};
	var wrap = document.getElementById( 'rdbk-runner' );
	var runBtn = document.getElementById( 'rdbk-test-run' );
	var cancelBtn = document.getElementById( 'rdbk-test-cancel' );

	var progress = wrap ? wrap.querySelector( '.rdbk-progress' ) : null;
	var bar = document.getElementById( 'rdbk-progress-bar' );
	var status = document.getElementById( 'rdbk-progress-status' );
	var running = false;

	function post( action, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				body.append( k, extra[ k ] );
			} );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( res ) {
			return res.text().then( function ( text ) {
				var data = null;
				try {
					data = JSON.parse( text );
				} catch ( e ) {
					data = null;
				}
				if ( ! res.ok ) {
					var err = new Error( 'HTTP ' + res.status );
					err.status = res.status;
					err.body = text;
					throw err;
				}
				return data;
			} );
		} );
	}

	function setStatus( text ) {
		if ( status ) {
			status.textContent = text;
		}
	}

	function setBar( pct ) {
		if ( bar ) {
			bar.style.width = pct + '%';
		}
	}

	function finish( label ) {
		running = false;
		runBtn.disabled = false;
		if ( cancelBtn ) {
			cancelBtn.hidden = true;
		}
		setStatus( label );
	}

	function handle( data, onContinue ) {
		setBar( data.progress || 0 );
		if ( data.done ) {
			setBar( 100 );
			finish( i18n.done || 'Done!' );
			return;
		}
		setStatus( ( i18n.working || 'Working…' ) + ' ' + ( data.progress || 0 ) + '%' );
		onContinue();
	}

	function loop() {
		if ( ! running ) {
			return;
		}
		post( 'rdbk_step' ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				finish( i18n.failed || 'Failed.' );
				return;
			}
			handle( r.data || {}, loop );
		} ).catch( function () {
			finish( i18n.failed || 'Failed.' );
		} );
	}

	runBtn && runBtn.addEventListener( 'click', function () {
		if ( running ) {
			return;
		}
		running = true;
		runBtn.disabled = true;
		if ( cancelBtn ) {
			cancelBtn.hidden = false;
		}
		if ( progress ) {
			progress.hidden = false;
		}
		setBar( 0 );
		setStatus( i18n.starting || 'Starting…' );

		post( 'rdbk_start', { type: 'test' } ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				finish( i18n.failed || 'Failed.' );
				return;
			}
			handle( r.data || {}, loop );
		} ).catch( function () {
			finish( i18n.failed || 'Failed.' );
		} );
	} );

	if ( cancelBtn ) {
		cancelBtn.addEventListener( 'click', function () {
			post( 'rdbk_cancel' ).then( function () {
				finish( i18n.cancelled || 'Cancelled.' );
				setBar( 0 );
			} );
		} );
	}

	// --- Storage self-test + archive list (PR2) ---
	var testStorageBtn = document.getElementById( 'rdbk-test-storage' );
	var storageMsg = document.getElementById( 'rdbk-storage-msg' );
	var archivesBody = document.getElementById( 'rdbk-archives-body' );

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = ( null === s || undefined === s ) ? '' : String( s );
		return d.innerHTML;
	}

	function renderArchives( items ) {
		if ( ! archivesBody ) {
			return;
		}
		if ( ! items || ! items.length ) {
			archivesBody.innerHTML = '<tr class="rdbk-archives__empty"><td colspan="4">' + esc( i18n.noArchives || 'No archives yet.' ) + '</td></tr>';
			return;
		}
		var html = '';
		items.forEach( function ( it ) {
			html += '<tr><td><code>' + esc( it.name ) + '</code></td>' +
				'<td>' + esc( it.sizeh ) + '</td>' +
				'<td>' + esc( it.dateh ) + '</td>' +
				'<td><a class="button button-small" href="' + esc( it.url ) + '">' + esc( i18n.download || 'Download' ) + '</a> ' +
				'<button type="button" class="button button-small button-link-delete rdbk-del" data-file="' + esc( it.name ) + '">' + esc( i18n.del || 'Delete' ) + '</button></td></tr>';
		} );
		archivesBody.innerHTML = html;
	}

	if ( testStorageBtn ) {
		testStorageBtn.addEventListener( 'click', function () {
			testStorageBtn.disabled = true;
			if ( storageMsg ) {
				storageMsg.textContent = i18n.working || 'Working…';
			}
			post( 'rdbk_test_storage' ).then( function ( r ) {
				testStorageBtn.disabled = false;
				if ( r && r.success ) {
					if ( storageMsg ) {
						storageMsg.textContent = ( r.data && r.data.message ) || '';
					}
					renderArchives( r.data && r.data.items );
				} else if ( storageMsg ) {
					storageMsg.textContent = ( r && r.data && r.data.message ) || i18n.failed || 'Failed.';
				}
			} ).catch( function () {
				testStorageBtn.disabled = false;
				if ( storageMsg ) {
					storageMsg.textContent = i18n.failed || 'Failed.';
				}
			} );
		} );
	}

	if ( archivesBody ) {
		archivesBody.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.rdbk-del' );
			if ( ! btn ) {
				return;
			}
			if ( ! window.confirm( i18n.confirmDel || 'Delete this file?' ) ) {
				return;
			}
			var body = new FormData();
			body.append( 'action', 'rdbk_delete_archive' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'file', btn.getAttribute( 'data-file' ) );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
				return r.json();
			} ).then( function ( r ) {
				if ( r && r.success ) {
					renderArchives( r.data && r.data.items );
				}
			} );
		} );
	}

	// --- DB dump test (PR3) ---
	var dbRunBtn = document.getElementById( 'rdbk-dbdump-run' );
	var dbProgress = document.getElementById( 'rdbk-dbdump-progress' );
	var dbBar = document.getElementById( 'rdbk-dbdump-bar' );
	var dbStatus = document.getElementById( 'rdbk-dbdump-status' );
	var dbResult = document.getElementById( 'rdbk-dbdump-result' );
	var dbRunning = false;

	function dbSetBar( p ) {
		if ( dbBar ) {
			dbBar.style.width = p + '%';
		}
	}

	function dbSetStatus( t ) {
		if ( dbStatus ) {
			dbStatus.textContent = t;
		}
	}

	function renderDbResult( data ) {
		if ( ! dbResult ) {
			return;
		}
		var s = ( data && data.stats ) || null;
		if ( ! s ) {
			dbResult.hidden = true;
			return;
		}
		dbResult.hidden = false;
		var line = esc( i18n.dbDone || 'Dump complete:' ) + ' <strong>' + esc( s.tables ) + '</strong> ' + esc( i18n.tables || 'tables' ) +
			', <strong>' + esc( s.rows ) + '</strong> ' + esc( i18n.rows || 'rows' ) +
			', <strong>' + esc( s.sizeh || '' ) + '</strong>.';
		var dl = s.url ? '<p><a class="button button-primary" href="' + esc( s.url ) + '">' + esc( i18n.downloadSql || 'Download database.sql' ) + '</a></p>' : '';
		dbResult.innerHTML = '<p>' + line + '</p>' + dl;
	}

	function dbFinish( label, data ) {
		dbRunning = false;
		if ( dbRunBtn ) {
			dbRunBtn.disabled = false;
		}
		dbSetStatus( label );
		renderDbResult( data );
	}

	function dbLoop() {
		if ( ! dbRunning ) {
			return;
		}
		post( 'rdbk_step' ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				dbFinish( i18n.failed || 'Failed.' );
				return;
			}
			var d = r.data || {};
			dbSetBar( d.progress || 0 );
			if ( d.done ) {
				dbSetBar( 100 );
				dbFinish( i18n.done || 'Done!', d );
				return;
			}
			dbSetStatus( ( i18n.working || 'Working…' ) + ' ' + ( d.progress || 0 ) + '%' );
			dbLoop();
		} ).catch( function () {
			dbFinish( i18n.failed || 'Failed.' );
		} );
	}

	if ( dbRunBtn ) {
		dbRunBtn.addEventListener( 'click', function () {
			if ( dbRunning ) {
				return;
			}
			dbRunning = true;
			dbRunBtn.disabled = true;
			if ( dbProgress ) {
				dbProgress.hidden = false;
			}
			if ( dbResult ) {
				dbResult.hidden = true;
			}
			dbSetBar( 0 );
			dbSetStatus( i18n.starting || 'Starting…' );
			post( 'rdbk_start', { type: 'db_dump' } ).then( function ( r ) {
				if ( ! r || ! r.success ) {
					dbFinish( i18n.failed || 'Failed.' );
					return;
				}
				var d = r.data || {};
				dbSetBar( d.progress || 0 );
				if ( d.done ) {
					dbFinish( i18n.done || 'Done!', d );
					return;
				}
				dbLoop();
			} ).catch( function () {
				dbFinish( i18n.failed || 'Failed.' );
			} );
		} );
	}

	// --- Full backup (PR4) ---
	var bkRunBtn = document.getElementById( 'rdbk-backup-run' );
	var bkProgress = document.getElementById( 'rdbk-backup-progress' );
	var bkBar = document.getElementById( 'rdbk-backup-bar' );
	var bkStatus = document.getElementById( 'rdbk-backup-status' );
	var bkMsg = document.getElementById( 'rdbk-backup-msg' );
	var bkRunning = false;

	function bkSetBar( p ) {
		if ( bkBar ) {
			bkBar.style.width = p + '%';
		}
	}

	function bkSetStatus( t ) {
		if ( bkStatus ) {
			bkStatus.textContent = t;
		}
	}

	function bkFinish( label, data ) {
		bkRunning = false;
		if ( bkRunBtn ) {
			bkRunBtn.disabled = false;
		}
		bkSetStatus( label );
		var s = ( data && data.stats ) || null;
		if ( s ) {
			if ( bkMsg ) {
				bkMsg.textContent = ( i18n.backupDone || 'Backup created:' ) + ' ' + ( s.file || '' ) + ' (' + ( s.sizeh || '' ) + ')';
			}
			if ( s.items ) {
				renderArchives( s.items );
			}
		}
	}

	function bkLoop() {
		if ( ! bkRunning ) {
			return;
		}
		post( 'rdbk_step' ).then( function ( r ) {
			if ( ! r || ! r.success ) {
				bkFinish( i18n.failed || 'Failed.' );
				return;
			}
			var d = r.data || {};
			bkSetBar( d.progress || 0 );
			if ( d.done ) {
				bkSetBar( 100 );
				bkFinish( i18n.done || 'Done!', d );
				return;
			}
			bkSetStatus( ( i18n.working || 'Working…' ) + ' ' + ( d.progress || 0 ) + '%' );
			bkLoop();
		} ).catch( function () {
			bkFinish( i18n.failed || 'Failed.' );
		} );
	}

	if ( bkRunBtn ) {
		bkRunBtn.addEventListener( 'click', function () {
			if ( bkRunning ) {
				return;
			}
			bkRunning = true;
			bkRunBtn.disabled = true;
			if ( bkProgress ) {
				bkProgress.hidden = false;
			}
			if ( bkMsg ) {
				bkMsg.textContent = '';
			}
			bkSetBar( 0 );
			bkSetStatus( i18n.starting || 'Starting…' );
			post( 'rdbk_start', { type: 'backup' } ).then( function ( r ) {
				if ( ! r || ! r.success ) {
					bkFinish( i18n.failed || 'Failed.' );
					return;
				}
				var d = r.data || {};
				bkSetBar( d.progress || 0 );
				if ( d.done ) {
					bkFinish( i18n.done || 'Done!', d );
					return;
				}
				bkLoop();
			} ).catch( function () {
				bkFinish( i18n.failed || 'Failed.' );
			} );
		} );
	}

	// --- Restore preview (PR5) + apply (PR6) ---
	var previewBox = document.getElementById( 'rdbk-preview' );
	var restoreList = document.querySelector( '.rdbk-restore-list' );
	var currentFile = null;

	function fmtBytes( n ) {
		n = Number( n ) || 0;
		if ( n < 1024 ) {
			return n + ' B';
		}
		var units = [ 'KB', 'MB', 'GB', 'TB' ];
		var i = -1;
		do {
			n /= 1024;
			i++;
		} while ( n >= 1024 && i < units.length - 1 );
		return n.toFixed( 1 ) + ' ' + units[ i ];
	}

	function integrityBadge( v ) {
		if ( true === v ) {
			return '<span class="rdbk-badge rdbk-badge--ok">' + esc( i18n.intOk || 'verified' ) + '</span>';
		}
		if ( false === v ) {
			return '<span class="rdbk-badge rdbk-badge--fail">' + esc( i18n.intFail || 'FAILED' ) + '</span>';
		}
		return '<span class="rdbk-badge rdbk-badge--warn">' + esc( i18n.intUnknown || 'unknown' ) + '</span>';
	}

	function renderPreview( d ) {
		if ( ! previewBox ) {
			return;
		}
		previewBox.hidden = false;

		if ( ! d || ! d.ok ) {
			previewBox.innerHTML = '<div class="notice notice-error inline"><p>' + esc( ( d && d.error ) || i18n.failed || 'Failed.' ) + '</p></div>';
			return;
		}

		var m = d.manifest || {};
		var site = m.site || {};
		var env = m.environment || {};
		var db = m.database || {};
		var up = m.uploads || {};

		var warns;
		if ( d.warnings && d.warnings.length ) {
			warns = '<ul class="rdbk-warn-list">';
			d.warnings.forEach( function ( w ) {
				warns += '<li>' + esc( w ) + '</li>';
			} );
			warns += '</ul>';
		} else {
			warns = '<p>' + esc( i18n.noWarnings || 'No compatibility warnings.' ) + '</p>';
		}

		var contents = ( db.table_count || 0 ) + ' tables, ' + ( db.rows || 0 ) + ' rows · ' +
			( up.files || 0 ) + ' files (' + fmtBytes( up.bytes || 0 ) + ')';

		previewBox.innerHTML =
			'<h3><code>' + esc( d.file || '' ) + '</code></h3>' +
			'<table class="widefat striped"><tbody>' +
			'<tr><td>' + esc( i18n.origin || 'Origin' ) + '</td><td>' + esc( site.home_url || '' ) + '</td></tr>' +
			'<tr><td>' + esc( i18n.created || 'Created' ) + '</td><td>' + esc( m.created_at || '' ) + '</td></tr>' +
			'<tr><td>WordPress / PHP</td><td>' + esc( ( env.wp_version || '?' ) + ' / ' + ( env.php_version || '?' ) ) + '</td></tr>' +
			'<tr><td>' + esc( i18n.contents || 'Contents' ) + '</td><td>' + esc( contents ) + '</td></tr>' +
			'<tr><td>' + esc( i18n.integrity || 'Integrity' ) + '</td><td>' + integrityBadge( d.integrity ) + '</td></tr>' +
			'</tbody></table>' +
			'<h4>' + esc( i18n.warningsLbl || 'Warnings' ) + '</h4>' + warns +
			restoreControlsHtml();
		wireRestoreControls();
	}

	function restoreControlsHtml() {
		return '<div class="rdbk-restore-apply">' +
			'<div class="notice notice-warning inline"><p><strong>' + esc( i18n.restoreWarnTitle || 'Heads up:' ) + '</strong> ' +
			esc( i18n.restoreWarn || 'This overwrites the current database. A full safety backup is taken first. You will be signed out when it finishes (the restore replaces the users table) — just log back in.' ) + '</p></div>' +
			'<p><label>' + esc( i18n.typeRestore || 'Type RESTORE to confirm:' ) +
			' <input type="text" id="rdbk-restore-confirm" autocomplete="off" spellcheck="false"></label> ' +
			'<button type="button" class="button rdbk-danger" id="rdbk-restore-go" disabled>' +
			esc( i18n.restoreBtn || 'Restore this backup' ) + '</button></p>' +
			'<div class="rdbk-progress" id="rdbk-restore-progress" hidden>' +
			'<div class="rdbk-progress__track"><div class="rdbk-progress__bar" id="rdbk-restore-bar" style="width:0%"></div></div>' +
			'<p class="rdbk-progress__status" id="rdbk-restore-status" aria-live="polite"></p></div>' +
			'<div id="rdbk-restore-msg"></div>' +
			'<pre id="rdbk-restore-log" class="rdbk-log" hidden></pre></div>';
	}

	function wireRestoreControls() {
		var confirmInput = document.getElementById( 'rdbk-restore-confirm' );
		var goBtn = document.getElementById( 'rdbk-restore-go' );
		if ( ! confirmInput || ! goBtn ) {
			return;
		}
		confirmInput.addEventListener( 'input', function () {
			goBtn.disabled = ( 'RESTORE' !== confirmInput.value.trim() );
		} );
		goBtn.addEventListener( 'click', function () {
			if ( ! goBtn.disabled ) {
				doRestore( goBtn );
			}
		} );
	}

	// Runs a job (start → step … → done) and resolves with the final payload.
	// The per-job secret returned by start authorizes each step even after a
	// restore logs the admin out mid-run (siteurl swap → COOKIEHASH → no cookie).
	function runJob( type, extra, onProgress ) {
		return new Promise( function ( resolve, reject ) {
			var secret = '';
			function loop() {
				post( 'rdbk_step', { secret: secret } ).then( function ( r ) {
					if ( ! r || ! r.success ) {
						reject();
						return;
					}
					var d = r.data || {};
					if ( onProgress ) {
						onProgress( d );
					}
					if ( d.done ) {
						resolve( d );
						return;
					}
					loop();
				} ).catch( reject );
			}
			var payload = { type: type };
			if ( extra ) {
				Object.keys( extra ).forEach( function ( k ) {
					payload[ k ] = extra[ k ];
				} );
			}
			post( 'rdbk_start', payload ).then( function ( r ) {
				if ( ! r || ! r.success ) {
					reject();
					return;
				}
				var d = r.data || {};
				secret = d.secret || '';
				if ( onProgress ) {
					onProgress( d );
				}
				if ( d.done ) {
					resolve( d );
					return;
				}
				loop();
			} ).catch( reject );
		} );
	}

	function doRestore( goBtn ) {
		var prog = document.getElementById( 'rdbk-restore-progress' );
		var bar = document.getElementById( 'rdbk-restore-bar' );
		var status = document.getElementById( 'rdbk-restore-status' );
		var msg = document.getElementById( 'rdbk-restore-msg' );
		var logEl = document.getElementById( 'rdbk-restore-log' );

		goBtn.disabled = true;
		if ( prog ) {
			prog.hidden = false;
		}
		if ( logEl ) {
			logEl.hidden = false;
			logEl.textContent = '';
		}

		function showLog( lines ) {
			if ( logEl && lines && lines.length ) {
				logEl.textContent = lines.join( '\n' );
				logEl.scrollTop = logEl.scrollHeight;
			}
		}

		function onProgress( d ) {
			if ( bar ) {
				bar.style.width = ( d.progress || 0 ) + '%';
			}
			if ( status ) {
				status.textContent = ( d.phase || '' ) + ' ' + ( d.progress || 0 ) + '%';
			}
			showLog( d.log );
		}

		if ( status ) {
			status.textContent = i18n.safetyBackup || 'Creating safety backup…';
		}

		runJob( 'backup', { kind: 'safe' }, onProgress ).then( function () {
			if ( bar ) {
				bar.style.width = '0%';
			}
			if ( status ) {
				status.textContent = i18n.restoring || 'Restoring…';
			}
			return runJob( 'restore', { file: currentFile }, onProgress );
		} ).then( function ( d ) {
			if ( bar ) {
				bar.style.width = '100%';
			}
			if ( status ) {
				status.textContent = '';
			}
			showLog( d && d.log );
			if ( msg ) {
				msg.innerHTML = '<div class="notice notice-success inline"><p>' +
					esc( i18n.restoreDone || 'Restore complete. You may need to log in again — reload the page to see the restored site.' ) + '</p></div>';
			}
		} ).catch( function ( err ) {
			var detail = ( err && err.status ) ? ( ' (HTTP ' + err.status + ')' ) : '';
			if ( status ) {
				status.textContent = ( i18n.failed || 'Failed.' ) + detail;
			}
			if ( msg ) {
				msg.innerHTML = '<div class="notice notice-error inline"><p>' +
					esc( ( i18n.failed || 'Failed.' ) + detail ) + '</p></div>';
			}
			goBtn.disabled = false;
		} );
	}

	if ( restoreList ) {
		restoreList.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.rdbk-preview-btn' );
			if ( ! btn ) {
				return;
			}
			currentFile = btn.getAttribute( 'data-file' );
			if ( previewBox ) {
				previewBox.hidden = false;
				previewBox.innerHTML = '<p>' + esc( i18n.previewing || 'Reading…' ) + '</p>';
			}
			post( 'rdbk_preview', { file: currentFile } ).then( function ( r ) {
				renderPreview( r && r.data );
			} ).catch( function () {
				renderPreview( null );
			} );
		} );
	}
} )();
