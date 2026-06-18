<?php
/**
 * Search-replace — after a cross-domain restore, swaps the origin URL for the
 * destination URL across every table, in resumable steps.
 *
 * Serialized-aware: a blind str_replace would corrupt serialized data, whose
 * strings carry an embedded length (s:30:"…"). For serialized values we
 * recurse — unserialize → replace → re-serialize — so the lengths are
 * recomputed. Only runs when the origin domain (from the manifest) differs from
 * this site; otherwise it skips entirely.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the site URL across the database, safe for serialized values.
 */
class RDBK_Search_Replace {

	const BATCH = 100;

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Search_Replace|null
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
	 * Seeds the job: origin → destination, and the table/column map. Marks the
	 * phase to be skipped when the domain didn't change.
	 */
	public function init( RDBK_Job $job ): void {
		global $wpdb;

		$from = (string) $job->get( 'r_origin' );
		$to   = home_url();

		if ( '' === $from || $from === $to ) {
			$job->set( 'sr_skip', true );
			$job->save();
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scanning every table at restore time.
		$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
		$list   = array();
		foreach ( $tables as $table ) {
			$meta = $this->table_meta( (string) $table );
			if ( null !== $meta ) {
				$list[] = array(
					'name' => (string) $table,
					'pk'   => $meta['pk'],
					'cols' => $meta['cols'],
				);
			}
		}

		$job->set( 'sr_skip', false );
		$job->set( 'sr_from', $from );
		$job->set( 'sr_to', $to );
		$job->set( 'sr_tables', $list );
		$job->set( 'sr_table_index', 0 );
		$job->set( 'sr_row_offset', 0 );
		$job->set( 'sr_changed', 0 );
		$job->save();
	}

	/**
	 * Processes one batch of rows. Returns true when every table is done.
	 */
	public function step( RDBK_Job $job ): bool {
		if ( (bool) $job->get( 'sr_skip', false ) ) {
			return true;
		}

		global $wpdb;
		$from   = (string) $job->get( 'sr_from' );
		$to     = (string) $job->get( 'sr_to' );
		$tables = (array) $job->get( 'sr_tables', array() );
		$ti     = (int) $job->get( 'sr_table_index', 0 );
		$offset = (int) $job->get( 'sr_row_offset', 0 );

		if ( $ti >= count( $tables ) ) {
			return true;
		}

		$meta  = $tables[ $ti ];
		$table = (string) $meta['name'];
		$pk    = (string) $meta['pk'];
		$cols  = (array) $meta['cols'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- restore-time full scan; identifiers internal, limit/offset are integers.
		$rows    = $wpdb->get_results( "SELECT * FROM `$table` LIMIT " . self::BATCH . ' OFFSET ' . $offset, ARRAY_A );
		$count   = is_array( $rows ) ? count( $rows ) : 0;
		$changed = (int) $job->get( 'sr_changed', 0 );

		foreach ( (array) $rows as $row ) {
			$set = array();
			foreach ( $cols as $col ) {
				$val = isset( $row[ $col ] ) ? $row[ $col ] : null;
				if ( ! is_string( $val ) || '' === $val || false === strpos( $val, $from ) ) {
					continue;
				}
				$new = $this->deep_replace( $val, $from, $to );
				if ( $new !== $val ) {
					$set[ $col ] = $new;
				}
			}
			if ( ! empty( $set ) && isset( $row[ $pk ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted UPDATE by primary key; $wpdb->update prepares the values.
				$wpdb->update( $table, $set, array( $pk => $row[ $pk ] ) );
				++$changed;
			}
		}

		if ( $count < self::BATCH ) {
			$job->set( 'sr_table_index', $ti + 1 );
			$job->set( 'sr_row_offset', 0 );
		} else {
			$job->set( 'sr_row_offset', $offset + $count );
		}
		$job->set( 'sr_changed', $changed );
		$job->save();

		return (int) $job->get( 'sr_table_index', 0 ) >= count( $tables );
	}

	/**
	 * Progress (0–100) by table index.
	 */
	public function progress( RDBK_Job $job ): int {
		if ( (bool) $job->get( 'sr_skip', false ) ) {
			return 100;
		}
		$total = max( 1, count( (array) $job->get( 'sr_tables', array() ) ) );
		$done  = (int) $job->get( 'sr_table_index', 0 );
		return (int) floor( $done / $total * 100 );
	}

	/**
	 * Returns a table's primary key + text columns, or null if it has neither.
	 *
	 * @return array{pk:string,cols:array<int,string>}|null
	 */
	private function table_meta( string $table ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading the schema of a table from SHOW TABLES.
		$cols = $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A );
		if ( ! is_array( $cols ) ) {
			return null;
		}

		$pk   = '';
		$text = array();
		foreach ( $cols as $col ) {
			$field = isset( $col['Field'] ) ? (string) $col['Field'] : '';
			$key   = isset( $col['Key'] ) ? (string) $col['Key'] : '';
			$type  = isset( $col['Type'] ) ? strtolower( (string) $col['Type'] ) : '';
			if ( 'PRI' === $key && '' === $pk ) {
				$pk = $field;
			}
			if ( '' !== $field && preg_match( '/(char|text)/', $type ) ) {
				$text[] = $field;
			}
		}

		if ( '' === $pk || empty( $text ) ) {
			return null;
		}
		return array(
			'pk'   => $pk,
			'cols' => $text,
		);
	}

	/**
	 * Recursively replaces $from with $to, re-serializing serialized strings so
	 * their length prefixes stay valid.
	 *
	 * @param mixed $data Value to process.
	 * @return mixed
	 */
	private function deep_replace( $data, string $from, string $to ) {
		if ( is_string( $data ) && is_serialized( $data ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- unserialize a value from the user's OWN backup; the @ swallows malformed-data warnings, which the false check handles.
			$un = @unserialize( $data );
			if ( false !== $un || 'b:0;' === $data ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- re-serializing to keep the s:N: length prefixes valid after the replace.
				return serialize( $this->deep_replace( $un, $from, $to ) );
			}
			return str_replace( $from, $to, $data );
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$out[ $key ] = $this->deep_replace( $value, $from, $to );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			$out = clone $data;
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$out->$key = $this->deep_replace( $value, $from, $to );
			}
			return $out;
		}

		if ( is_string( $data ) ) {
			return str_replace( $from, $to, $data );
		}

		return $data;
	}
}
