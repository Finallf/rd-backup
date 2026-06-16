<?php
/**
 * Resumable job state — persisted in a single option, one job at a time.
 *
 * The whole engine (backup and restore) is driven by a single job object whose
 * state is saved to the database between steps, so a run survives the tab being
 * closed and can be resumed.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds and persists the state of the current job.
 */
class RDBK_Job {

	const OPTION = 'rdbk_job';

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
	 * Loads the current job from storage, or null when there is none.
	 */
	public static function load(): ?self {
		$data = get_option( self::OPTION, null );
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

	public function save(): void {
		update_option( self::OPTION, $this->data, false );
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}

	public function to_array(): array {
		return $this->data;
	}
}
