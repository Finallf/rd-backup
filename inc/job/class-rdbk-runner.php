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
		add_action( 'wp_ajax_rdbk_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_rdbk_test_storage', array( $this, 'ajax_test_storage' ) );
		add_action( 'wp_ajax_rdbk_delete_archive', array( $this, 'ajax_delete_archive' ) );
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

	public function ajax_start(): void {
		$this->guard();
		$type = $this->requested_type();

		// One job at a time: resume the running one instead of starting another.
		$existing = RDBK_Job::load();
		if ( $existing && 'running' === $existing->get( 'status' ) ) {
			wp_send_json_success( $this->payload( $existing ) );
		}

		$job = RDBK_Job::start( $type );
		if ( 'db_dump' === $type ) {
			RDBK_DB_Dump::instance()->init( $job );
		}
		wp_send_json_success( $this->payload( $job ) );
	}

	/**
	 * Reads and whitelists the requested job type.
	 */
	private function requested_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard() before this runs.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'test';
		return in_array( $type, array( 'test', 'db_dump' ), true ) ? $type : 'test';
	}

	public function ajax_step(): void {
		$this->guard();

		$job = RDBK_Job::load();
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'No active job.', 'rd-backup' ) ), 404 );
		}

		if ( 'db_dump' === $job->get( 'type' ) ) {
			RDBK_DB_Dump::instance()->step( $job );
		} else {
			$this->fake_step( $job );
		}

		wp_send_json_success( $this->payload( $job ) );
	}

	/**
	 * Placeholder engine (PR1): advances a counter to exercise the AJAX loop.
	 */
	private function fake_step( RDBK_Job $job ): void {
		$cursor = min( 100, (int) $job->get( 'cursor', 0 ) + 10 );
		$job->set( 'cursor', $cursor );
		$job->set( 'progress', $cursor );

		if ( $cursor >= 100 ) {
			$job->set( 'phase', 'done' );
			$job->set( 'status', 'done' );
		} else {
			$job->set( 'phase', $cursor < 30 ? 'init' : 'working' );
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
	 * Normalizes the job state into the JSON payload the UI consumes.
	 */
	private function payload( RDBK_Job $job ): array {
		return array(
			'status'   => (string) $job->get( 'status' ),
			'phase'    => (string) $job->get( 'phase' ),
			'progress' => (int) $job->get( 'progress', 0 ),
			'done'     => 'done' === $job->get( 'status' ),
			'stats'    => $job->get( 'stats' ),
		);
	}
}
