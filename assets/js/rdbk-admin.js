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

	if ( ! wrap || ! runBtn ) {
		return;
	}

	var progress = wrap.querySelector( '.rdbk-progress' );
	var bar = document.getElementById( 'rdbk-progress-bar' );
	var status = document.getElementById( 'rdbk-progress-status' );
	var running = false;

	function post( action ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( res ) {
			return res.json();
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

	runBtn.addEventListener( 'click', function () {
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

		post( 'rdbk_start' ).then( function ( r ) {
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
} )();
