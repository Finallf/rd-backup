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

		// One job at a time: resume the running one instead of starting another.
		$existing = RDBK_Job::load();
		if ( $existing && 'running' === $existing->get( 'status' ) ) {
			wp_send_json_success( $this->payload( $existing ) );
		}

		$job = RDBK_Job::start( 'test' );
		wp_send_json_success( $this->payload( $job ) );
	}

	public function ajax_step(): void {
		$this->guard();

		$job = RDBK_Job::load();
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'No active job.', 'rd-backup' ) ), 404 );
		}

		// Fake engine: advance the counter to simulate one slice of work.
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
		wp_send_json_success( $this->payload( $job ) );
	}

	public function ajax_cancel(): void {
		$this->guard();

		$job = RDBK_Job::load();
		if ( $job ) {
			$job->clear();
		}
		wp_send_json_success( array( 'status' => 'cancelled' ) );
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
		);
	}
}
