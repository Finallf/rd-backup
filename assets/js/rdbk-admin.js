/**
 * ReloadeD Backup — admin progress loop.
 *
 * Drives a resumable job from the browser: start → step → step … until done,
 * updating the progress bar. State lives server-side (in a job file), so the run
 * survives the tab being closed.
 */
( function () {
	'use strict';

	var cfg = window.rdbkData || {};
	var i18n = cfg.i18n || {};

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

	// --- Archive list + delete ---
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

	// --- Full backup ---
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

	// Preview buttons live in both the backups list and the safety-snapshots
	// list — delegate on the document so either one works.
	document.addEventListener( 'click', function ( e ) {
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

	// --- Reset job state ---
	var resetBtn = document.getElementById( 'rdbk-reset-job' );
	var resetMsg = document.getElementById( 'rdbk-reset-msg' );
	if ( resetBtn ) {
		resetBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.confirmReset || 'Clear the current job state?' ) ) {
				return;
			}
			resetBtn.disabled = true;
			if ( resetMsg ) {
				resetMsg.textContent = i18n.working || 'Working…';
			}
			post( 'rdbk_cancel' ).then( function () {
				resetBtn.disabled = false;
				if ( resetMsg ) {
					resetMsg.textContent = i18n.resetDone || 'Job state cleared.';
				}
			} ).catch( function () {
				resetBtn.disabled = false;
				if ( resetMsg ) {
					resetMsg.textContent = i18n.failed || 'Failed.';
				}
			} );
		} );
	}
} )();
