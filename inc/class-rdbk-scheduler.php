<?php
/**
 * Scheduler — runs full backups automatically via WP-Cron, with no browser to
 * drive the resumable engine. Each due tick re-arms the next cycle first (so the
 * chain survives a crash), then runs the backup engine in a loop within a time
 * budget; a big backup that does not finish in one tick continues on a follow-up
 * event. Portable: uses only WP's native cron API — no system cron, no loopback.
 *
 * Scheduled backups use kind 'auto' (no safety snapshot) and fall under the same
 * "keep last N" retention as manual backups (RDBK_Storage::enforce_retention()).
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the automatic-backup schedule and its cron-driven runner.
 */
class RDBK_Scheduler {

	const FREQ_OPTION = 'rdbk_schedule_freq';
	const TIME_OPTION = 'rdbk_schedule_time';
	const LAST_OPTION = 'rdbk_schedule_last';

	const HOOK_RUN      = 'rdbk_scheduled_run';
	const HOOK_CONTINUE = 'rdbk_scheduled_continue';

	const FREQ_CHOICES = array( 'off', 'daily', 'weekly', 'monthly' );
	const TIME_DEFAULT = '03:00';

	// Seconds of a cron tick we spend stepping the engine before yielding to a
	// follow-up event (kept under the request's execution limit in drive()).
	const TIME_BUDGET = 20;

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Scheduler|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::HOOK_RUN, array( $this, 'run' ) );
		add_action( self::HOOK_CONTINUE, array( $this, 'continue_run' ) );
		add_action( 'wp_ajax_rdbk_save_schedule', array( $this, 'ajax_save_schedule' ) );
	}

	/**
	 * Clears the scheduled events on deactivation.
	 */
	public static function on_deactivation(): void {
		wp_clear_scheduled_hook( self::HOOK_RUN );
		wp_clear_scheduled_hook( self::HOOK_CONTINUE );
	}

	/* ---- Settings ------------------------------------------------------- */

	/**
	 * The configured frequency: 'off' / 'daily' / 'weekly' / 'monthly'.
	 */
	public function freq(): string {
		$f = (string) get_option( self::FREQ_OPTION, 'off' );
		return in_array( $f, self::FREQ_CHOICES, true ) ? $f : 'off';
	}

	/**
	 * The configured run time as a zero-padded 'HH:MM' (24h).
	 */
	public function time_of_day(): string {
		return $this->sanitize_time( (string) get_option( self::TIME_OPTION, self::TIME_DEFAULT ) );
	}

	/**
	 * Validates 'HH:MM' (00:00–23:59); returns a zero-padded value or the default.
	 */
	private function sanitize_time( string $time ): string {
		if ( (bool) preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $time ), $m ) ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}
		return self::TIME_DEFAULT;
	}

	/**
	 * The next scheduled run timestamp, or 0 when none is armed.
	 */
	public function next_scheduled(): int {
		return (int) wp_next_scheduled( self::HOOK_RUN );
	}

	/**
	 * The last automatic-run record (time/status/file/message), or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function last_run(): ?array {
		$last = get_option( self::LAST_OPTION );
		return ( is_array( $last ) && ! empty( $last['time'] ) ) ? $last : null;
	}

	/* ---- Scheduling ----------------------------------------------------- */

	/**
	 * Recomputes the cron schedule from the saved frequency + time: clears any
	 * existing events, then arms the next run unless the schedule is off.
	 */
	public function reschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_RUN );
		wp_clear_scheduled_hook( self::HOOK_CONTINUE );
		if ( 'off' === $this->freq() ) {
			return;
		}
		wp_schedule_single_event( $this->next_run_from( time() ), self::HOOK_RUN );
	}

	/**
	 * The next run timestamp strictly after $after, on the HH:MM grid for the
	 * configured frequency (site timezone; calendar-correct for weekly/monthly).
	 */
	private function next_run_from( int $after ): int {
		$tz    = wp_timezone();
		$parts = explode( ':', $this->time_of_day() );
		$hour  = (int) ( $parts[0] ?? 3 );
		$min   = (int) ( $parts[1] ?? 0 );

		$dt = new DateTime( 'now', $tz );
		$dt->setTime( $hour, $min, 0 );

		$freq  = $this->freq();
		$guard = 0;
		while ( $dt->getTimestamp() <= $after && $guard < 500 ) {
			switch ( $freq ) {
				case 'weekly':
					$dt->modify( '+7 days' );
					break;
				case 'monthly':
					$dt->modify( '+1 month' );
					break;
				default:
					$dt->modify( '+1 day' );
					break;
			}
			++$guard;
		}
		return $dt->getTimestamp();
	}

	/* ---- Cron runners --------------------------------------------------- */

	/**
	 * Cron callback for a due schedule: re-arm the next cycle, then start and run
	 * an automatic backup (unless another job is already running).
	 */
	public function run(): void {
		// Re-arm the next cycle FIRST, so the chain survives a crash mid-backup.
		if ( 'off' !== $this->freq() ) {
			wp_schedule_single_event( $this->next_run_from( time() ), self::HOOK_RUN );
		}

		// One job at a time: if a backup/restore is genuinely in progress, skip
		// this cycle — the next occurrence will try again. A crashed run can leave
		// a stale 'running' job behind; after an hour we assume it is dead and
		// proceed (a fresh start replaces it, like the manual flow does), so a dead
		// job never blocks unattended backups forever.
		$existing = RDBK_Job::load();
		if ( $existing && 'running' === (string) $existing->get( 'status' ) ) {
			$started = (int) $existing->get( 'started_at', 0 );
			if ( $started > 0 && ( time() - $started ) < HOUR_IN_SECONDS ) {
				return;
			}
		}

		$job = RDBK_Job::start( 'backup' );
		RDBK_Backup::instance()->init( $job, 'auto' );
		$this->drive( $job );
	}

	/**
	 * Cron callback that resumes an automatic backup that did not finish within a
	 * single tick's time budget.
	 */
	public function continue_run(): void {
		$job = RDBK_Job::load();
		if ( ! $job
			|| 'backup' !== (string) $job->get( 'type' )
			|| 'auto' !== (string) $job->get( 'kind' )
			|| 'running' !== (string) $job->get( 'status' ) ) {
			return;
		}
		$this->drive( $job );
	}

	/**
	 * Steps the job within the time budget; schedules a follow-up when it is not
	 * finished, otherwise records the result and drops the job file.
	 */
	private function drive( RDBK_Job $job ): void {
		$max      = (int) ini_get( 'max_execution_time' );
		$budget   = ( $max > 0 ) ? max( 5, min( self::TIME_BUDGET, (int) floor( $max * 0.7 ) ) ) : self::TIME_BUDGET;
		$deadline = time() + $budget;

		$status = (string) $job->get( 'status' );
		do {
			RDBK_Backup::instance()->step( $job );
			$status = (string) $job->get( 'status' );
		} while ( 'running' === $status && time() < $deadline );

		if ( 'running' === $status ) {
			// Not finished — continue on the next tick (fires on the next request).
			wp_schedule_single_event( time() + 1, self::HOOK_CONTINUE );
			return;
		}

		$this->record_result( $job );
		$job->clear();
	}

	/**
	 * Stores the outcome of the last automatic run for the Schedule tab.
	 */
	private function record_result( RDBK_Job $job ): void {
		$status  = (string) $job->get( 'status' );
		$stats   = (array) $job->get( 'stats', array() );
		$file    = (string) ( $stats['file'] ?? '' );
		$size    = (string) ( $stats['sizeh'] ?? '' );
		$message = 'error' === $status ? (string) $job->get( 'error', '' ) : '';

		update_option(
			self::LAST_OPTION,
			array(
				'time'    => time(),
				'status'  => $status,
				'file'    => $file,
				'message' => $message,
			)
		);

		// Notify the configured channels (best-effort; never affects the backup).
		RDBK_Notifier::instance()->notify_backup_result( $status, $file, $size, $message );
	}

	/* ---- AJAX ----------------------------------------------------------- */

	/**
	 * AJAX: save the schedule (frequency + time) and re-arm the cron. Returns the
	 * next run time for the UI.
	 */
	public function ajax_save_schedule(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd-backup' ) ), 403 );
		}
		check_ajax_referer( 'rdbk_save_schedule', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$freq = isset( $_POST['freq'] ) ? sanitize_key( wp_unslash( $_POST['freq'] ) ) : 'off';
		if ( ! in_array( $freq, self::FREQ_CHOICES, true ) ) {
			$freq = 'off';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$time = isset( $_POST['time'] )
			? $this->sanitize_time( sanitize_text_field( wp_unslash( $_POST['time'] ) ) )
			: self::TIME_DEFAULT;

		update_option( self::FREQ_OPTION, $freq );
		update_option( self::TIME_OPTION, $time );
		$this->reschedule();

		$next = ( 'off' === $freq ) ? 0 : $this->next_scheduled();
		wp_send_json_success(
			array(
				'freq' => $freq,
				'time' => $time,
				'next' => $next ? wp_date( 'Y-m-d H:i', $next ) : '',
			)
		);
	}
}
