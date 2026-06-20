<?php
/**
 * Database importer — extracts database.sql from a backup archive and executes
 * it statement by statement, in resumable, time-boxed steps.
 *
 * Statement splitting: the dumper escapes newlines inside values (esc_sql turns
 * a real newline into "\n"), so every INSERT is a single physical line and a
 * multi-line CREATE TABLE only ends with ";" on its last line. We therefore
 * accumulate lines until one ends in ";" — that is a complete statement. The
 * cursor is the byte offset into the .sql, so a run survives across requests.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restores a database from the .sql extracted out of a backup archive.
 */
class RDBK_DB_Import {

	const TIME_BUDGET = 15;

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_DB_Import|null
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
	 * Extracts database.sql to a work dir and seeds the import cursor.
	 */
	public function init( RDBK_Job $job, string $zip_path ): bool {
		$work = RDBK_Storage::instance()->dir() . '/.restore';
		if ( ! wp_mkdir_p( $work ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}
		$ok = $zip->extractTo( $work, 'database.sql' );
		$zip->close();

		$sql_path = $work . '/database.sql';
		if ( ! $ok || ! file_exists( $sql_path ) ) {
			return false;
		}

		$job->set( 'imp_work', $work );
		$job->set( 'imp_sql_path', $sql_path );
		$job->set( 'imp_offset', 0 );
		$job->set( 'imp_size', (int) filesize( $sql_path ) );
		$job->set( 'imp_statements', 0 );
		$job->set( 'imp_failed', 0 );
		$job->save();
		return true;
	}

	/**
	 * Executes one time-boxed batch of statements. Returns true when the whole
	 * .sql has been consumed.
	 */
	public function step( RDBK_Job $job ): bool {
		global $wpdb;

		$sql_path = (string) $job->get( 'imp_sql_path' );
		$offset   = (int) $job->get( 'imp_offset', 0 );
		$size     = (int) $job->get( 'imp_size', 0 );
		if ( $offset >= $size || ! file_exists( $sql_path ) ) {
			return true;
		}

		// FK checks off so tables can be (re)created in dump order. Statements run
		// through $wpdb->query() below; the dump already escapes values with
		// mysqli_real_escape_string, so there's no literal-% mangling to dodge here
		// (query() doesn't add placeholder-escapes — only prepare() does).
		$dbh = $wpdb->dbh;
		$this->exec( $dbh, 'SET FOREIGN_KEY_CHECKS = 0' );

		$count = (int) $job->get( 'imp_statements', 0 );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a large .sql by byte offset; WP_Filesystem buffers whole files and can't seek.
		$fh = fopen( $sql_path, 'rb' );
		if ( false === $fh ) {
			return true;
		}
		fseek( $fh, $offset );

		// Best-effort: swallow a failing statement (don't echo its error into the
		// AJAX response or abort) — count it and move on, logged when the import ends.
		$prev_suppress = $wpdb->suppress_errors( true );

		$start  = time();
		$buffer = '';
		while ( true ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$trimmed = rtrim( $line );

			// Skip blank lines and comments between statements.
			if ( '' === $buffer && ( '' === $trimmed || 0 === strpos( ltrim( $line ), '--' ) ) ) {
				$offset = ftell( $fh );
				continue;
			}

			$buffer .= $line;

			if ( '' !== $trimmed && ';' === substr( $trimmed, -1 ) ) {
				$stmt   = trim( $buffer );
				$buffer = '';
				if ( '' !== $stmt ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- executing a trusted backup dump.
					$result = $wpdb->query( $stmt );
					++$count;
					if ( false === $result ) {
						$this->record_failure( $job, $stmt );
					}
				}
				$offset = ftell( $fh );
				if ( time() - $start >= self::TIME_BUDGET ) {
					break;
				}
			}
		}
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$wpdb->suppress_errors( $prev_suppress );

		$job->set( 'imp_offset', $offset );
		$job->set( 'imp_statements', $count );
		$job->save();

		return $offset >= $size;
	}

	/**
	 * Records a failed statement on the job: a running count plus a short, capped
	 * list of samples (table / first chars), so a best-effort skip isn't silent —
	 * the restore logs the summary when the import finishes.
	 */
	private function record_failure( RDBK_Job $job, string $stmt ): void {
		$job->set( 'imp_failed', (int) $job->get( 'imp_failed', 0 ) + 1 );
		$samples = (array) $job->get( 'imp_failed_samples', array() );
		if ( count( $samples ) < 10 ) {
			$samples[] = substr( (string) preg_replace( '/\s+/', ' ', $stmt ), 0, 80 );
			$job->set( 'imp_failed_samples', $samples );
		}
	}

	/**
	 * Runs a single control statement on the live mysqli handle (used to toggle FK
	 * checks). Best-effort: a failure here must not abort the import.
	 *
	 * @param mysqli|null $dbh The live database handle ($wpdb->dbh).
	 */
	private function exec( $dbh, string $sql ): void {
		if ( null === $dbh ) {
			return;
		}
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- intentional verbatim exec of a trusted dump; $wpdb->query() mangles literal % (see docblock).
			mysqli_query( $dbh, $sql );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Progress (0–100) of the import, by bytes consumed.
	 */
	public function progress( RDBK_Job $job ): int {
		$size   = max( 1, (int) $job->get( 'imp_size', 1 ) );
		$offset = (int) $job->get( 'imp_offset', 0 );
		return (int) floor( $offset / $size * 100 );
	}

	/**
	 * Removes the extracted .sql and its work dir.
	 */
	public function cleanup( RDBK_Job $job ): void {
		$sql = (string) $job->get( 'imp_sql_path' );
		if ( '' !== $sql && file_exists( $sql ) ) {
			wp_delete_file( $sql );
		}

		$work = (string) $job->get( 'imp_work' );
		if ( '' === $work || ! is_dir( $work ) ) {
			return;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( $wp_filesystem ) {
			$wp_filesystem->rmdir( $work );
		}
	}
}
