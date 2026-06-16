<?php
/**
 * Environment preflight — reports what the backup/restore engine needs, with a
 * status and a hint per item. Server-aware (Apache vs nginx) for the storage
 * protection note.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of environment checks rendered on the Health tab.
 */
class RDBK_Healthcheck {

	/**
	 * Returns an array of checks: { label, status: ok|warn|fail, value, hint }.
	 */
	public static function run(): array {
		$checks = array();

		$has_zip  = class_exists( 'ZipArchive' );
		$checks[] = array(
			'label'  => __( 'ZIP extension (ZipArchive)', 'rd-backup' ),
			'status' => $has_zip ? 'ok' : 'fail',
			'value'  => $has_zip ? __( 'available', 'rd-backup' ) : __( 'missing', 'rd-backup' ),
			'hint'   => $has_zip ? '' : __( 'Enable the PHP "zip" extension — required to build and restore archives.', 'rd-backup' ),
		);

		$writable = wp_is_writable( WP_CONTENT_DIR );
		$checks[] = array(
			'label'  => __( 'Writable wp-content/', 'rd-backup' ),
			'status' => $writable ? 'ok' : 'fail',
			'value'  => $writable ? __( 'writable', 'rd-backup' ) : __( 'not writable', 'rd-backup' ),
			'hint'   => $writable ? '' : __( 'The backup store (wp-content/rd-backup/) cannot be created. Fix folder permissions.', 'rd-backup' ),
		);

		$free     = self::free_space();
		$checks[] = array(
			'label'  => __( 'Free disk space', 'rd-backup' ),
			'status' => ( null === $free || $free > 0 ) ? 'ok' : 'warn',
			'value'  => null === $free ? __( 'unknown', 'rd-backup' ) : size_format( $free ),
			'hint'   => __( 'Needs room for the database dump plus the uploads folder, with headroom.', 'rd-backup' ),
		);

		$checks[] = self::ini_row( __( 'PHP max_execution_time', 'rd-backup' ), 'max_execution_time' );
		$checks[] = self::ini_row( __( 'PHP memory_limit', 'rd-backup' ), 'memory_limit' );
		$checks[] = self::ini_row( __( 'PHP upload_max_filesize', 'rd-backup' ), 'upload_max_filesize' );
		$checks[] = self::ini_row( __( 'PHP post_max_size', 'rd-backup' ), 'post_max_size' );

		$server   = self::server_software();
		$checks[] = array(
			'label'  => __( 'Web server', 'rd-backup' ),
			'status' => 'ok',
			'value'  => '' === $server ? __( 'unknown', 'rd-backup' ) : $server,
			'hint'   => self::server_hint( $server ),
		);

		$store = RDBK_Storage::instance();
		$store->ensure_dir();
		$st       = $store->status();
		$checks[] = array(
			'label'  => __( 'Backup store', 'rd-backup' ),
			'status' => $st['protected'] ? 'ok' : ( $st['exists'] ? 'warn' : 'fail' ),
			'value'  => $st['exists']
				? ( $st['protected'] ? __( 'ready & protected', 'rd-backup' ) : __( 'exists (unprotected)', 'rd-backup' ) )
				: __( 'missing', 'rd-backup' ),
			'hint'   => $st['dir'],
		);

		return $checks;
	}

	private static function ini_row( string $label, string $key ): array {
		$raw = (string) ini_get( $key );
		return array(
			'label'  => $label,
			'status' => 'ok',
			'value'  => '' === $raw ? __( 'n/a', 'rd-backup' ) : $raw,
			'hint'   => '',
		);
	}

	private static function free_space(): ?int {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space can warn on restricted hosts; the false/null path is handled.
		$bytes = @disk_free_space( WP_CONTENT_DIR );
		return ( false === $bytes ) ? null : (int) $bytes;
	}

	private static function server_software(): string {
		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		if ( stripos( $raw, 'nginx' ) !== false ) {
			return 'nginx';
		}
		if ( stripos( $raw, 'apache' ) !== false ) {
			return 'Apache';
		}
		if ( stripos( $raw, 'litespeed' ) !== false ) {
			return 'LiteSpeed';
		}
		return $raw;
	}

	private static function server_hint( string $server ): string {
		$base = __( 'The backup store is protected by a random token + PHP-only download — works on any server.', 'rd-backup' );

		if ( 'Apache' === $server || 'LiteSpeed' === $server ) {
			return $base . ' ' . __( 'A .htaccess deny rule is written automatically too. Heads-up: if nginx fronts Apache (e.g. HestiaCP) it serves static files directly and may bypass the .htaccess — an optional nginx deny rule adds defense in depth.', 'rd-backup' );
		}
		if ( 'nginx' === $server ) {
			return $base . ' ' . __( 'On nginx, optionally add a server-level deny rule (you control the config) for defense in depth.', 'rd-backup' );
		}
		return $base;
	}
}
