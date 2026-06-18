<?php
/**
 * Resumable job state — persisted as a JSON file in the store, one job at a time.
 *
 * The whole engine (backup and restore) is driven by a single job object whose
 * state is saved between steps, so a run survives the tab being closed and can
 * be resumed. State lives in a FILE (not the database): a restore overwrites the
 * entire database, which would wipe the very job that drives it. The store dir
 * (wp-content/rd-backup/) is left untouched by the import and uploads-extract,
 * so a file-based job survives the full-database swap.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds and persists the state of the current job.
 */
class RDBK_Job {

	const FILENAME = '.job.json';

	/**
	 * Job state.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Absolute path to the job-state file in the store.
	 */
	private static function file(): string {
		return RDBK_Storage::instance()->dir() . '/' . self::FILENAME;
	}

	/**
	 * Loads the current job from storage, or null when there is none.
	 */
	public static function load(): ?self {
		$file = self::file();
		if ( ! file_exists( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own local job-state file in the store, not a remote URL.
		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return null;
		}
		return new self( $data );
	}

	/**
	 * Creates and persists a fresh job of the given type.
	 */
	public static function start( string $type ): self {
		$job = new self(
			array(
				'id'         => uniqid( 'rdbk_', true ),
				'type'       => $type,
				'phase'      => 'init',
				'status'     => 'running',
				'progress'   => 0,
				'cursor'     => 0,
				'total'      => 100,
				'started_at' => time(),
				'log'        => array(),
				// Per-job secret: authorizes the step loop without the auth cookie,
				// which a restore destroys mid-run (it swaps siteurl → COOKIEHASH).
				'secret'     => bin2hex( random_bytes( 16 ) ),
			)
		);
		$job->save();
		return $job;
	}

	public function get( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
	}

	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;
	}

	/**
	 * Appends a timestamped line to the job log (kept to the last 100 lines).
	 */
	public function log( string $message ): void {
		$log   = (array) ( $this->data['log'] ?? array() );
		$log[] = gmdate( 'H:i:s' ) . ' — ' . $message;
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}
		$this->data['log'] = $log;
	}

	public function save(): void {
		RDBK_Storage::instance()->ensure_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the plugin's own job-state file in the store (which a DB restore must not be able to wipe).
		file_put_contents( self::file(), (string) wp_json_encode( $this->data ) );
	}

	public function clear(): void {
		$file = self::file();
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	public function to_array(): array {
		return $this->data;
	}
}
