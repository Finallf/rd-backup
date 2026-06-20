<?php
/**
 * Uploads extractor — restores the uploads/ tree from a backup archive into
 * wp-content/uploads in resumable, file-batched steps.
 *
 * Policy (decided with the user): add everything; on a name collision the
 * archived file wins (overwrite); files that exist on disk but not in the
 * archive are left untouched (no deletes).
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streams the archived uploads back onto disk.
 */
class RDBK_Uploads_Extract {

	const BATCH = 200;

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Uploads_Extract|null
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
	 * Indexes the uploads/ file entries in the archive and seeds the cursor.
	 */
	public function init( RDBK_Job $job, string $zip_path ): void {
		$entries = array();
		$zip     = new ZipArchive();
		if ( true === $zip->open( $zip_path ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native ZipArchive property.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( 0 === strpos( $name, 'uploads/' ) && '/' !== substr( $name, -1 ) ) {
					$entries[] = $name;
				}
			}
			$zip->close();
		}

		$job->set( 'up_zip', $zip_path );
		$job->set( 'up_entries', $entries );
		$job->set( 'up_index', 0 );
		$job->set( 'up_total', count( $entries ) );
		$job->save();
	}

	/**
	 * Extracts one batch of files. Returns true when all are written.
	 */
	public function step( RDBK_Job $job ): bool {
		$entries = (array) $job->get( 'up_entries', array() );
		$index   = (int) $job->get( 'up_index', 0 );
		$total   = count( $entries );
		if ( $index >= $total ) {
			return true;
		}

		$dir     = wp_get_upload_dir();
		$basedir = isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
		if ( '' === $basedir ) {
			return true;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( (string) $job->get( 'up_zip' ) ) ) {
			return true;
		}

		$end = min( $total, $index + self::BATCH );
		for ( ; $index < $end; $index++ ) {
			$entry = (string) $entries[ $index ];
			$rel   = substr( $entry, strlen( 'uploads/' ) );
			if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
				continue;
			}
			$this->write_entry( $zip, $entry, $basedir . '/' . $rel );
		}
		$zip->close();

		$job->set( 'up_index', $index );
		$job->save();
		return $index >= $total;
	}

	/**
	 * Progress (0–100) of the uploads extraction.
	 */
	public function progress( RDBK_Job $job ): int {
		$total = max( 1, (int) $job->get( 'up_total', 1 ) );
		$index = (int) $job->get( 'up_index', 0 );
		return (int) floor( $index / $total * 100 );
	}

	/**
	 * Streams one archive entry onto disk, overwriting if present.
	 */
	private function write_entry( ZipArchive $zip, string $entry, string $dest ): void {
		wp_mkdir_p( dirname( $dest ) );

		$in = $zip->getStream( $entry );
		if ( ! $in ) {
			return;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a zip entry to disk (overwrite); WP_Filesystem buffers whole files in memory.
		$out = fopen( $dest, 'wb' );
		if ( $out ) {
			stream_copy_to_stream( $in, $out );
			fclose( $out );
		}
		fclose( $in );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
