<?php
/**
 * Full backup orchestrator — drives the three resumable phases that produce the
 * final .zip: db (dump) → uploads (archive) → finalize (sql + manifest, then
 * publish to the store). The archive is built in a hidden work dir and only
 * moved into the store when complete, so partial backups never show in the list.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates RDBK_DB_Dump + RDBK_Archiver + RDBK_Manifest into one job.
 */
class RDBK_Backup {

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Backup|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Sets up the work dir, the empty archive and the db phase.
	 */
	public function init( RDBK_Job $job, string $kind = '' ): void {
		$storage = RDBK_Storage::instance();
		$storage->ensure_dir();

		$work = $storage->dir() . '/.work';
		wp_mkdir_p( $work );

		$final_name = $storage->new_archive_name( $kind );
		$sql_path   = $work . '/database.sql';
		$zip_path   = $work . '/' . $final_name;

		RDBK_DB_Dump::instance()->init( $job, $sql_path );

		$job->set( 'phase', 'db' );
		$job->set( 'work_dir', $work );
		$job->set( 'sql_path', $sql_path );
		$job->set( 'zip_path', $zip_path );
		$job->set( 'final_name', $final_name );
		$job->set( 'progress', 0 );
		$job->save();
	}

	/**
	 * Advances the current phase by one step and updates the overall progress.
	 */
	public function step( RDBK_Job $job ): void {
		$phase = (string) $job->get( 'phase' );

		if ( 'db' === $phase ) {
			$done = RDBK_DB_Dump::instance()->step( $job );
			$job->set( 'progress', (int) round( RDBK_DB_Dump::instance()->progress( $job ) * 0.45 ) );
			if ( $done ) {
				RDBK_Archiver::instance()->init_uploads( $job, (string) $job->get( 'zip_path' ) );
				$job->set( 'phase', 'uploads' );
			}
			$job->save();
			return;
		}

		if ( 'uploads' === $phase ) {
			$done = RDBK_Archiver::instance()->step_uploads( $job );
			$job->set( 'progress', 45 + (int) round( RDBK_Archiver::instance()->progress( $job ) * 0.50 ) );
			if ( $done ) {
				$job->set( 'phase', 'finalize' );
			}
			$job->save();
			return;
		}

		// finalize.
		$this->finalize( $job );
	}

	/**
	 * Adds the sql + manifest, publishes the archive to the store and cleans up.
	 */
	private function finalize( RDBK_Job $job ): void {
		$storage  = RDBK_Storage::instance();
		$archiver = RDBK_Archiver::instance();
		$zip_path = (string) $job->get( 'zip_path' );
		$sql_path = (string) $job->get( 'sql_path' );

		$archiver->add_sql( $zip_path, $sql_path );
		$archiver->add_manifest( $zip_path, RDBK_Manifest::build( $job, $sql_path ) );

		$final_name = (string) $job->get( 'final_name' );
		$final_path = $storage->dir() . '/' . $final_name;

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( $wp_filesystem ) {
			$wp_filesystem->move( $zip_path, $final_path, true );
		}

		// Clean the work dir (sql + the now-moved archive's folder).
		if ( file_exists( $sql_path ) ) {
			wp_delete_file( $sql_path );
		}
		$work_dir = (string) $job->get( 'work_dir' );
		if ( '' !== $work_dir && $wp_filesystem ) {
			$wp_filesystem->rmdir( $work_dir );
		}

		$size = file_exists( $final_path ) ? (int) filesize( $final_path ) : 0;
		$job->set(
			'stats',
			array(
				'file'  => $final_name,
				'sizeh' => size_format( $size ),
				'url'   => $storage->download_url( $final_name ),
				'items' => $storage->list_archives(),
			)
		);
		$job->set( 'progress', 100 );
		$job->set( 'phase', 'done' );
		$job->set( 'status', 'done' );
		$job->save();
	}
}
