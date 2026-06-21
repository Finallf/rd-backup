<?php
/**
 * Uploads extractor — restores the uploads/ tree from a backup archive into
 * wp-content/uploads in resumable, file-batched steps.
 *
 * Policy: MIRROR the backup. Two sub-phases:
 *   1. extract — write every archived file; the archived copy wins on collision.
 *   2. prune   — delete files on disk that are NOT in the archive, so the folder
 *                ends up identical to the backup (an exact 1:1 restore).
 * The mandatory pre-restore safety snapshot already captures the current uploads,
 * so the prune is recoverable; that snapshot — not a "never delete" rule — is the
 * safety net.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streams the archived uploads back onto disk and mirrors the folder to it.
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
		$job->set( 'up_sub', 'extract' );
		$job->save();
	}

	/**
	 * Advances the current sub-phase by one batch. Returns true only when both
	 * extraction and pruning are complete.
	 */
	public function step( RDBK_Job $job ): bool {
		if ( 'prune' !== $job->get( 'up_sub' ) ) {
			if ( $this->step_extract( $job ) ) {
				$this->init_prune( $job );
				$job->set( 'up_sub', 'prune' );
				$job->save();
			}
			return false;
		}
		return $this->step_prune( $job );
	}

	/**
	 * Extracts one batch of archived files. Returns true when all are written.
	 */
	private function step_extract( RDBK_Job $job ): bool {
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
	 * Builds the prune list: every file under wp-content/uploads that is NOT in
	 * the archive (so the folder can be mirrored to the backup exactly).
	 */
	private function init_prune( RDBK_Job $job ): void {
		$dir     = wp_get_upload_dir();
		$basedir = isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
		$delete  = array();

		if ( '' !== $basedir && is_dir( $basedir ) ) {
			$keep = array();
			foreach ( (array) $job->get( 'up_entries', array() ) as $entry ) {
				$rel = substr( (string) $entry, strlen( 'uploads/' ) );
				if ( '' !== $rel ) {
					$keep[ $rel ] = true;
				}
			}

			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $item ) {
				if ( ! $item->isFile() ) {
					continue;
				}
				$rel = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $basedir ) ) ), '/' );
				if ( '' !== $rel && ! isset( $keep[ $rel ] ) ) {
					$delete[] = $rel;
				}
			}
		}

		$job->set( 'up_delete', $delete );
		$job->set( 'up_del_index', 0 );
		$job->set( 'up_del_total', count( $delete ) );
		if ( ! empty( $delete ) ) {
			$job->log( 'Mirroring uploads: removing ' . count( $delete ) . ' file(s) not in the backup…' );
		}
	}

	/**
	 * Deletes one batch of pruned files. Returns true when the prune is complete.
	 */
	private function step_prune( RDBK_Job $job ): bool {
		$delete = (array) $job->get( 'up_delete', array() );
		$index  = (int) $job->get( 'up_del_index', 0 );
		$total  = count( $delete );
		if ( $index >= $total ) {
			$this->prune_empty_dirs();
			return true;
		}

		$dir       = wp_get_upload_dir();
		$basedir   = isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
		$real_base = '' !== $basedir ? realpath( $basedir ) : false;
		if ( false === $real_base ) {
			return true;
		}

		$end = min( $total, $index + self::BATCH );
		for ( ; $index < $end; $index++ ) {
			$rel = (string) $delete[ $index ];
			if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
				continue;
			}
			// Scope guard: realpath must resolve INSIDE the uploads basedir.
			$real = realpath( $basedir . '/' . $rel );
			if ( false !== $real && 0 === strpos( $real, $real_base ) && is_file( $real ) ) {
				wp_delete_file( $real );
			}
		}

		$job->set( 'up_del_index', $index );
		$job->save();

		if ( $index >= $total ) {
			$this->prune_empty_dirs();
			return true;
		}
		return false;
	}

	/**
	 * Removes directories left empty by the prune (deepest first), so the tree
	 * matches the backup. Non-empty directories are skipped.
	 */
	private function prune_empty_dirs(): void {
		$dir     = wp_get_upload_dir();
		$basedir = isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
		if ( '' === $basedir || ! is_dir( $basedir ) ) {
			return;
		}

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() && ! ( new FilesystemIterator( $item->getPathname() ) )->valid() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- pruning an emptied uploads dir to mirror the backup; no resumable WP_Filesystem equivalent.
				rmdir( $item->getPathname() );
			}
		}
	}

	/**
	 * Progress (0–100): extraction fills 0–90, the prune fills 90–100.
	 */
	public function progress( RDBK_Job $job ): int {
		if ( 'prune' === $job->get( 'up_sub' ) ) {
			$total = (int) $job->get( 'up_del_total', 0 );
			if ( $total <= 0 ) {
				return 100;
			}
			return 90 + (int) floor( (int) $job->get( 'up_del_index', 0 ) / $total * 10 );
		}
		$total = max( 1, (int) $job->get( 'up_total', 1 ) );
		$index = (int) $job->get( 'up_index', 0 );
		return (int) floor( $index / $total * 90 );
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
