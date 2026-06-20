<?php
/**
 * Archiver — builds the backup .zip with ZipArchive in resumable, incremental
 * steps. Uploads are added in file batches (reopen/close per batch, so memory
 * stays flat) and stored without recompression; the .sql is deflated.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds files to the backup archive in batches.
 */
class RDBK_Archiver {

	const BATCH = 200;

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Archiver|null
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
	 * Seeds the uploads phase: scans the uploads dir into a file index on the job.
	 */
	public function init_uploads( RDBK_Job $job, string $zip_path ): void {
		$dir   = wp_get_upload_dir();
		$base  = isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
		$files = '' !== $base ? $this->scan( $base ) : array();

		$job->set( 'up_base', $base );
		$job->set( 'up_files', $files );
		$job->set( 'up_index', 0 );
		$job->set( 'up_total', count( $files ) );
		$job->set( 'up_bytes', 0 );
		$job->set( 'zip_path', $zip_path );
		$job->save();
	}

	/**
	 * Adds one batch of upload files to the archive. Returns true when done.
	 */
	public function step_uploads( RDBK_Job $job ): bool {
		$base  = (string) $job->get( 'up_base' );
		$files = (array) $job->get( 'up_files', array() );
		$index = (int) $job->get( 'up_index', 0 );
		$total = count( $files );

		if ( $index >= $total ) {
			return true;
		}

		$zip = $this->open( (string) $job->get( 'zip_path' ) );
		if ( null === $zip ) {
			return true;
		}

		$end   = min( $total, $index + self::BATCH );
		$bytes = (int) $job->get( 'up_bytes', 0 );
		for ( ; $index < $end; $index++ ) {
			$rel = (string) $files[ $index ];
			$abs = $base . '/' . $rel;
			if ( is_file( $abs ) ) {
				$entry = 'uploads/' . $rel;
				$zip->addFile( $abs, $entry );
				if ( method_exists( $zip, 'setCompressionName' ) ) {
					$zip->setCompressionName( $entry, ZipArchive::CM_STORE );
				}
				$bytes += (int) filesize( $abs );
			}
		}
		$zip->close();

		$job->set( 'up_index', $index );
		$job->set( 'up_bytes', $bytes );
		$job->save();

		return $index >= $total;
	}

	/**
	 * Progress (0–100) of the uploads phase.
	 */
	public function progress( RDBK_Job $job ): int {
		$total = max( 1, (int) $job->get( 'up_total', 1 ) );
		$index = (int) $job->get( 'up_index', 0 );
		return (int) floor( $index / $total * 100 );
	}

	/**
	 * Adds the dumped .sql to the archive (deflated).
	 */
	public function add_sql( string $zip_path, string $sql_path ): void {
		$zip = $this->open( $zip_path );
		if ( null === $zip ) {
			return;
		}
		$zip->addFile( $sql_path, 'database.sql' );
		if ( method_exists( $zip, 'setCompressionName' ) ) {
			$zip->setCompressionName( 'database.sql', ZipArchive::CM_DEFLATE );
		}
		$zip->close();
	}

	/**
	 * Writes the manifest.json into the archive.
	 */
	public function add_manifest( string $zip_path, string $json ): void {
		$zip = $this->open( $zip_path );
		if ( null === $zip ) {
			return;
		}
		$zip->addFromString( 'manifest.json', $json );
		$zip->close();
	}

	/**
	 * Opens an existing archive for appending. Returns the ZipArchive or null.
	 *
	 * @return ZipArchive|null
	 */
	private function open( string $zip_path ): ?ZipArchive {
		$zip = new ZipArchive();
		// CREATE makes the first write create the file and later writes append.
		// An empty ZipArchive is never persisted, so we must NOT pre-create it —
		// the first real addFile/addFromString is what brings the archive to disk.
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
			return null;
		}
		return $zip;
	}

	/**
	 * Recursively lists files under $base, returned as paths relative to it.
	 *
	 * @return array<int,string>
	 */
	private function scan( string $base ): array {
		$out = array();
		if ( ! is_dir( $base ) ) {
			return $out;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$out[] = ltrim( str_replace( $base, '', $file->getPathname() ), '/\\' );
			}
		}
		return $out;
	}
}
