<?php
/**
 * Database dumper — resumable, table by table, in row batches. Produces a
 * mysqldump-style .sql entirely through $wpdb (works on any host, with no
 * dependency on exec()/mysqldump). Regenerable transients are skipped.
 *
 * Cursor state ({ table_index, row_offset }) lives on the job, so the dump is
 * resumable across requests.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streams the database to a .sql file in row-batched, resumable steps.
 */
class RDBK_DB_Dump {

	const BATCH = 1000;

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_DB_Dump|null
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
	 * Initializes a dump on the job: lists tables, opens a fresh .sql with a
	 * header, and seeds the cursor.
	 */
	public function init( RDBK_Job $job ): void {
		global $wpdb;

		$storage = RDBK_Storage::instance();
		$storage->ensure_dir();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dumping every table; not a cacheable read.
		$tables   = (array) $wpdb->get_col( 'SHOW TABLES' );
		$sql_name = 'database-' . bin2hex( random_bytes( 8 ) ) . '.sql';
		$sql_path = $storage->dir() . '/' . $sql_name;

		$header = "-- RD Backup database dump\n"
			. '-- Generated: ' . gmdate( 'c' ) . "\n"
			. '-- Charset: ' . DB_CHARSET . "\n\n"
			. 'SET NAMES ' . DB_CHARSET . ";\n"
			. "SET FOREIGN_KEY_CHECKS = 0;\n\n";
		$this->write( $sql_path, $header, true );

		$job->set( 'tables', array_values( $tables ) );
		$job->set( 'table_index', 0 );
		$job->set( 'row_offset', 0 );
		$job->set( 'sql_name', $sql_name );
		$job->set( 'total', max( 1, count( $tables ) ) );
		$job->set(
			'stats',
			array(
				'tables' => count( $tables ),
				'rows'   => 0,
			)
		);
		$job->set( 'phase', 'db' );
		$job->save();
	}

	/**
	 * Processes one batch: structure on first touch of a table, then up to BATCH
	 * rows; advances the cursor; finalizes when all tables are done.
	 */
	public function step( RDBK_Job $job ): void {
		$tables   = (array) $job->get( 'tables', array() );
		$ti       = (int) $job->get( 'table_index', 0 );
		$offset   = (int) $job->get( 'row_offset', 0 );
		$sql_path = RDBK_Storage::instance()->dir() . '/' . (string) $job->get( 'sql_name' );

		if ( $ti >= count( $tables ) ) {
			$this->finish( $job, $sql_path );
			return;
		}

		$table = (string) $tables[ $ti ];

		if ( 0 === $offset ) {
			$this->write( $sql_path, $this->table_structure( $table ) );
		}

		$rows  = $this->fetch_rows( $table, $offset, self::BATCH );
		$count = count( $rows );

		if ( $count > 0 ) {
			$this->write( $sql_path, $this->rows_to_inserts( $table, $rows ) );
			$stats         = (array) $job->get( 'stats' );
			$stats['rows'] = (int) ( $stats['rows'] ?? 0 ) + $count;
			$job->set( 'stats', $stats );
		}

		if ( $count < self::BATCH ) {
			$job->set( 'table_index', $ti + 1 );
			$job->set( 'row_offset', 0 );
		} else {
			$job->set( 'row_offset', $offset + $count );
		}

		$done  = (int) $job->get( 'table_index', 0 );
		$total = max( 1, count( $tables ) );
		$job->set( 'progress', (int) floor( $done / $total * 100 ) );

		if ( $done >= count( $tables ) ) {
			$this->finish( $job, $sql_path );
			return;
		}

		$job->save();
	}

	/**
	 * Writes the DROP + CREATE statements for a table.
	 */
	private function table_structure( string $table ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only SHOW CREATE (the CREATE keyword trips SchemaChange); table name comes from SHOW TABLES.
		$create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
		$ddl    = ( is_array( $create ) && isset( $create[1] ) ) ? $create[1] : '';

		return "--\n-- Table: $table\n--\nDROP TABLE IF EXISTS `$table`;\n" . $ddl . ";\n\n";
	}

	/**
	 * Fetches a batch of rows, skipping transients on the options table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_rows( string $table, int $offset, int $limit ): array {
		global $wpdb;

		$where = '';
		if ( $table === $wpdb->options ) {
			$where = " WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- dumping raw table rows; identifiers/limits are internal integers, not user input.
		return (array) $wpdb->get_results( "SELECT * FROM `$table`$where LIMIT $limit OFFSET $offset", ARRAY_A );
	}

	/**
	 * Renders a batch of rows as INSERT statements.
	 *
	 * @param string                         $table Table name.
	 * @param array<int,array<string,mixed>> $rows  Rows to render.
	 */
	private function rows_to_inserts( string $table, array $rows ): string {
		$out = '';
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $value ) {
				$vals[] = ( null === $value ) ? 'NULL' : "'" . esc_sql( (string) $value ) . "'";
			}
			$out .= "INSERT INTO `$table` VALUES (" . implode( ', ', $vals ) . ");\n";
		}
		return $out . "\n";
	}

	/**
	 * Writes the footer and marks the job done with final stats.
	 */
	private function finish( RDBK_Job $job, string $sql_path ): void {
		$this->write( $sql_path, "SET FOREIGN_KEY_CHECKS = 1;\n" );

		$size  = file_exists( $sql_path ) ? (int) filesize( $sql_path ) : 0;
		$stats = (array) $job->get( 'stats' );

		$stats['bytes'] = $size;
		$stats['sizeh'] = size_format( $size );
		$stats['file']  = (string) $job->get( 'sql_name' );
		$stats['url']   = RDBK_Storage::instance()->download_url( (string) $job->get( 'sql_name' ) );

		$job->set( 'stats', $stats );
		$job->set( 'progress', 100 );
		$job->set( 'phase', 'done' );
		$job->set( 'status', 'done' );
		$job->save();
	}

	/**
	 * Appends (or, when $fresh, creates) a chunk to the .sql file via streaming.
	 */
	private function write( string $path, string $text, bool $fresh = false ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming append of dump chunks; WP_Filesystem buffers whole files in memory and is unsuitable for an incremental dump.
		$fh = fopen( $path, $fresh ? 'w' : 'a' );
		if ( ! $fh ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see fopen note above.
		fwrite( $fh, $text );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen note above.
		fclose( $fh );
	}
}
