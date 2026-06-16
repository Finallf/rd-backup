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
} )();
