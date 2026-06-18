<?php
/**
 * Job runner — admin-ajax endpoints that drive the resumable engine one step
 * at a time.
 *
 * In this scaffold the "engine" is a fake counter (0→100%), so the AJAX loop,
 * the progress UI and the resume/cancel flow can be validated end-to-end before
 * the real backup/restore phases land in the next releases.
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
		add_action( 'wp_ajax_rdbk_test_storage', array( $this, 'ajax_test_storage' ) );
		add_action( 'wp_ajax_rdbk_delete_archive', array( $this, 'ajax_delete_archive' ) );
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
		if ( 'db_dump' === $type ) {
			$this->db_dump_init( $job );
		} elseif ( 'backup' === $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
			$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
			RDBK_Backup::instance()->init( $job, $kind );
		} elseif ( 'restore' === $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
			$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
			if ( ! RDBK_Restore::instance()->init_restore( $job, $file ) ) {
				$job->clear();
				wp_send_json_error( array( 'message' => __( 'Could not start the restore (archive not found or unreadable).', 'rd-backup' ) ), 400 );
			}
		}
		wp_send_json_success( $this->payload( $job ) );
	}

	/**
	 * Reads and whitelists the requested job type.
	 */
	private function requested_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		return in_array( $type, array( 'db_dump', 'backup', 'restore' ), true ) ? $type : '';
	}

	/**
	 * Standalone DB dump: writes the .sql straight into the store for download.
	 */
	private function db_dump_init( RDBK_Job $job ): void {
		$storage = RDBK_Storage::instance();
		$storage->ensure_dir();
		$sql_name = 'database-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$job->set( 'sql_name', $sql_name );
		RDBK_DB_Dump::instance()->init( $job, $storage->dir() . '/' . $sql_name );
		$job->set( 'phase', 'db' );
		$job->save();
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
		} elseif ( 'db_dump' === $type ) {
			$this->db_dump_step( $job );
		}

		wp_send_json_success( $this->payload( $job ) );
	}

	/**
	 * Advances a standalone DB dump; finalizes the job with download stats.
	 */
	private function db_dump_step( RDBK_Job $job ): void {
		$dump = RDBK_DB_Dump::instance();
		$done = $dump->step( $job );
		$job->set( 'progress', $dump->progress( $job ) );

		if ( $done ) {
			$storage  = RDBK_Storage::instance();
			$sql_name = (string) $job->get( 'sql_name' );
			$sql_path = $storage->dir() . '/' . $sql_name;
			$db_stats = (array) $job->get( 'db_stats', array() );
			$size     = file_exists( $sql_path ) ? (int) filesize( $sql_path ) : 0;

			$job->set(
				'stats',
				array(
					'tables' => (int) ( $db_stats['tables'] ?? 0 ),
					'rows'   => (int) ( $db_stats['rows'] ?? 0 ),
					'sizeh'  => size_format( $size ),
					'file'   => $sql_name,
					'url'    => $storage->download_url( $sql_name ),
				)
			);
			$job->set( 'progress', 100 );
			$job->set( 'phase', 'done' );
			$job->set( 'status', 'done' );
		}

		$job->save();
	}

	public function ajax_cancel(): void {
		$this->guard();

		$job = RDBK_Job::load();
		if ( $job ) {
			$job->clear();
		}
		wp_send_json_success( array( 'status' => 'cancelled' ) );
	}

	public function ajax_test_storage(): void {
		$this->guard();

		$storage = RDBK_Storage::instance();
		$name    = $storage->write_test_file();
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Could not write the test archive (is ZipArchive available and the store writable?).', 'rd-backup' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Test archive written to the store.', 'rd-backup' ),
				'items'   => $storage->list_archives(),
			)
		);
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
	private function payload( RDBK_Job $job ): array {
		return array(
			'status'   => (string) $job->get( 'status' ),
			'phase'    => (string) $job->get( 'r_phase', (string) $job->get( 'phase', '' ) ),
			'progress' => (int) $job->get( 'progress', 0 ),
			'done'     => 'done' === $job->get( 'status' ),
			'stats'    => $job->get( 'stats' ),
			'log'      => (array) $job->get( 'log', array() ),
			'secret'   => (string) $job->get( 'secret', '' ),
		);
	}
}
