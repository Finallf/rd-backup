<?php
/**
 * Job runner — admin-ajax endpoints that drive the resumable engine one step at
 * a time: start a job, step it to completion, cancel it. The step loop is
 * authorized by the per-job secret, so it survives a restore swapping the whole
 * database (which would otherwise log the admin out mid-run).
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the step/start/cancel AJAX endpoints.
 */
class RDBK_Runner {

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Runner|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_rdbk_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_rdbk_step', array( $this, 'ajax_step' ) );
		// nopriv too: a restore logs the admin out mid-run (it swaps siteurl, which
		// changes COOKIEHASH and orphans the auth cookie). The per-job secret keeps
		// the step loop authorized through that window — see authorize_step().
		add_action( 'wp_ajax_nopriv_rdbk_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_rdbk_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_rdbk_delete_archive', array( $this, 'ajax_delete_archive' ) );
		add_action( 'wp_ajax_rdbk_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_rdbk_preview', array( $this, 'ajax_preview' ) );
	}

	/**
	 * Capability + nonce gate. Dies with a JSON error on failure.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd-backup' ) ), 403 );
		}
		check_ajax_referer( 'rdbk_runner', 'nonce' );
	}

	/**
	 * Authorizes a step. A restore swaps the whole database, which changes
	 * siteurl → COOKIEHASH and logs the admin out mid-run; the per-job secret
	 * (issued to the browser by the authenticated start) keeps the loop alive
	 * without the auth cookie or nonce. Falls back to the normal cap+nonce gate
	 * when no valid secret is presented.
	 */
	private function authorize_step( RDBK_Job $job ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the per-job secret is the auth here (hash_equals below); the cap+nonce gate is the fallback.
		$secret   = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
		$expected = (string) $job->get( 'secret', '' );
		if ( '' !== $expected && '' !== $secret && hash_equals( $expected, $secret ) ) {
			return;
		}
		$this->guard();
	}

	public function ajax_start(): void {
		$this->guard();
		$type = $this->requested_type();
		if ( '' === $type ) {
			wp_send_json_error( array( 'message' => __( 'Unknown job type.', 'rd-backup' ) ), 400 );
		}

		// Always start fresh: resuming a stale job across page loads is fragile
		// (the browser would have lost the per-job secret anyway), and a leftover
		// 'running' job from a crashed run would otherwise block every new start.
		$job = RDBK_Job::start( $type );
		if ( 'backup' === $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
			$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
			RDBK_Backup::instance()->init( $job, $kind );
		} elseif ( 'restore' === $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
			$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';

			// Re-validate server-side — never trust the browser's preview. A
			// failed integrity check (sha256 mismatch = corrupt / truncated /
			// tampered .sql) MUST hard-block the restore, not merely warn. A null
			// result (no hash in the manifest) can't be proven bad, so it passes.
			$inspect = RDBK_Restore::instance()->inspect( $file );
			if ( empty( $inspect['ok'] ) ) {
				$job->clear();
				wp_send_json_error( array( 'message' => $inspect['error'] ), 400 );
			}
			if ( false === $inspect['integrity'] ) {
				$job->clear();
				wp_send_json_error( array( 'message' => __( 'Integrity check failed — the archive is corrupt or its database was tampered with. Restore aborted.', 'rd-backup' ) ), 400 );
			}

			if ( ! RDBK_Restore::instance()->init_restore( $job, $file ) ) {
				$job->clear();
				wp_send_json_error( array( 'message' => __( 'Could not start the restore (archive not found or unreadable).', 'rd-backup' ) ), 400 );
			}
		}
		wp_send_json_success( $this->payload( $job, true ) );
	}

	/**
	 * Reads and whitelists the requested job type.
	 */
	private function requested_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		return in_array( $type, array( 'backup', 'restore' ), true ) ? $type : '';
	}

	public function ajax_step(): void {
		$job = RDBK_Job::load();
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'No active job.', 'rd-backup' ) ), 404 );
		}
		$this->authorize_step( $job );

		$type = (string) $job->get( 'type' );
		if ( 'backup' === $type ) {
			RDBK_Backup::instance()->step( $job );
		} elseif ( 'restore' === $type ) {
			RDBK_Restore::instance()->step_restore( $job );
		}

		// A step can mark the job failed (e.g. a backup that fails its post-build
		// integrity verification). Surface it as an error and drop the job file.
		if ( 'error' === $job->get( 'status' ) ) {
			$message = (string) $job->get( 'error', __( 'The job failed.', 'rd-backup' ) );
			$job->clear();
			wp_send_json_error( array( 'message' => $message ) );
		}

		$payload = $this->payload( $job );
		// Once the job is done, drop the job file: it holds the per-job secret and
		// (for a restore) the session token. The final payload is already captured
		// above, and a fresh run always creates a new job file.
		if ( 'done' === $job->get( 'status' ) ) {
			$job->clear();
		}
		wp_send_json_success( $payload );
	}

	public function ajax_cancel(): void {
		$this->guard();

		$job = RDBK_Job::load();
		if ( $job ) {
			$job->clear();
		}
		wp_send_json_success( array( 'status' => 'cancelled' ) );
	}

	public function ajax_delete_archive(): void {
		$this->guard();

		// sanitize_text_field (not sanitize_file_name, which mangles dotted hosts); resolve_safe() does the path validation.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() (check_ajax_referer) before any input is read.
		$name = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		if ( ! RDBK_Storage::instance()->delete( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'rd-backup' ) ), 404 );
		}

		wp_send_json_success( array( 'items' => RDBK_Storage::instance()->list_archives() ) );
	}

	/**
	 * Stores an uploaded .zip into the store (capped by the server's upload limit;
	 * larger archives go in via SFTP). Returns the refreshed archive list.
	 */
	public function ajax_upload(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() above.
		$error = isset( $_FILES['file']['error'] ) ? (int) $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			$message = ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error )
				? __( 'The file is larger than this server allows — upload it via SFTP instead.', 'rd-backup' )
				: __( 'Upload failed — no file received.', 'rd-backup' );
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in guard(); tmp_name is PHP's own upload path, re-validated by is_uploaded_file() in store_upload().
		$tmp = isset( $_FILES['file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['file']['tmp_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() above.
		$name = isset( $_FILES['file']['name'] ) ? sanitize_text_field( wp_unslash( $_FILES['file']['name'] ) ) : '';

		$stored = RDBK_Storage::instance()->store_upload( $tmp, $name );
		if ( '' === $stored ) {
			wp_send_json_error( array( 'message' => __( 'Only valid .zip backups can be uploaded.', 'rd-backup' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'name'  => $stored,
				'items' => RDBK_Storage::instance()->list_archives(),
			)
		);
	}

	/**
	 * Read-only restore preview: validates an archive and returns its manifest.
	 */
	public function ajax_preview(): void {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
		$name = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		wp_send_json_success( RDBK_Restore::instance()->inspect( $name ) );
	}

	/**
	 * Normalizes the job state into the JSON payload the UI consumes.
	 */
	private function payload( RDBK_Job $job, bool $with_secret = false ): array {
		$payload = array(
			'status'   => (string) $job->get( 'status' ),
			'phase'    => (string) $job->get( 'r_phase', (string) $job->get( 'phase', '' ) ),
			'progress' => (int) $job->get( 'progress', 0 ),
			'done'     => 'done' === $job->get( 'status' ),
			'stats'    => $job->get( 'stats' ),
			'log'      => (array) $job->get( 'log', array() ),
		);
		// The per-job secret is handed out once (on start) and reused by every
		// step — no need to echo it back in each step response.
		if ( $with_secret ) {
			$payload['secret'] = (string) $job->get( 'secret', '' );
		}
		return $payload;
	}
}
