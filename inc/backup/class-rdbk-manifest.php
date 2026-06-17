<?php
/**
 * Manifest — builds the manifest.json embedded in every backup archive. It
 * carries everything the restore and validation steps need: schema version,
 * origin URLs (for search-replace), environment, per-table counts, integrity
 * hash and uploads totals.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the backup manifest as pretty JSON.
 */
class RDBK_Manifest {

	const SCHEMA = 1;

	/**
	 * Builds the manifest JSON for a finished job.
	 */
	public static function build( RDBK_Job $job, string $sql_path ): string {
		global $wpdb, $wp_version;

		$db_stats = (array) $job->get( 'db_stats', array() );
		$sql_size = file_exists( $sql_path ) ? (int) filesize( $sql_path ) : 0;

		$manifest = array(
			'schema_version' => self::SCHEMA,
			'generator'      => 'RD Backup ' . RDBK_VERSION,
			'created_at'     => gmdate( 'c' ),
			'site'           => array(
				'home_url'     => home_url(),
				'site_url'     => site_url(),
				'is_multisite' => is_multisite(),
			),
			'environment'    => array(
				'wp_version'    => (string) $wp_version,
				'php_version'   => PHP_VERSION,
				'mysql_version' => $wpdb->db_version(),
			),
			'database'       => array(
				'table_prefix' => $wpdb->prefix,
				'charset'      => DB_CHARSET,
				'collate'      => DB_COLLATE,
				'tables'       => isset( $db_stats['per_table'] ) ? (object) $db_stats['per_table'] : (object) array(),
				'table_count'  => (int) ( $db_stats['tables'] ?? 0 ),
				'rows'         => (int) ( $db_stats['rows'] ?? 0 ),
				'sql_bytes'    => $sql_size,
				'sql_sha256'   => $sql_size > 0 ? hash_file( 'sha256', $sql_path ) : '',
			),
			'uploads'        => array(
				'files' => (int) $job->get( 'up_total', 0 ),
				'bytes' => (int) $job->get( 'up_bytes', 0 ),
			),
		);

		return (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
