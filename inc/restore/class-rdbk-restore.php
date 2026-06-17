<?php
/**
 * Restore — read-only side (PR5): opens a backup archive, validates its
 * manifest and integrity, and builds a human preview with compatibility
 * warnings. Nothing here writes to the site; applying the restore (DB import,
 * search-replace, uploads) is the destructive PR6.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inspects a backup archive and reports what a restore would do.
 */
class RDBK_Restore {

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Restore|null
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
	 * Opens an archive from the store and returns a validation + preview payload.
	 *
	 * @return array<string,mixed>
	 */
	public function inspect( string $name ): array {
		$path = RDBK_Storage::instance()->resolve_safe( $name );
		if ( '' === $path ) {
			return array(
				'ok'    => false,
				'error' => __( 'Backup file not found.', 'rd-backup' ),
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Could not open the archive.', 'rd-backup' ),
			);
		}

		$json = $zip->getFromName( 'manifest.json' );
		if ( false === $json ) {
			$zip->close();
			return array(
				'ok'    => false,
				'error' => __( 'Not an RD Backup archive (no manifest.json).', 'rd-backup' ),
			);
		}

		$manifest = json_decode( $json, true );
		if ( ! is_array( $manifest ) || empty( $manifest['schema_version'] ) ) {
			$zip->close();
			return array(
				'ok'    => false,
				'error' => __( 'The manifest is missing or invalid.', 'rd-backup' ),
			);
		}

		if ( (int) $manifest['schema_version'] > RDBK_Manifest::SCHEMA ) {
			$zip->close();
			return array(
				'ok'    => false,
				'error' => __( 'This backup was made by a newer version of RD Backup — update the plugin to restore it.', 'rd-backup' ),
			);
		}

		$has_sql   = false !== $zip->locateName( 'database.sql' );
		$expected  = (string) ( $manifest['database']['sql_sha256'] ?? '' );
		$integrity = $has_sql ? $this->verify_sql_hash( $zip, $expected ) : false;
		$zip->close();

		return array(
			'ok'        => true,
			'file'      => basename( $path ),
			'manifest'  => $manifest,
			'has_sql'   => $has_sql,
			'integrity' => $integrity,
			'warnings'  => $this->warnings( $manifest ),
		);
	}

	/**
	 * Streams the .sql entry and compares its sha256 with the manifest's.
	 * Returns true/false, or null when the manifest carries no hash.
	 */
	private function verify_sql_hash( ZipArchive $zip, string $expected ): ?bool {
		if ( '' === $expected ) {
			return null;
		}
		$stream = $zip->getStream( 'database.sql' );
		if ( ! $stream ) {
			return false;
		}

		$ctx = hash_init( 'sha256' );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_feof, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a zip entry to hash it; WP_Filesystem can't read inside an archive.
		while ( ! feof( $stream ) ) {
			$buffer = fread( $stream, 1048576 );
			if ( false === $buffer ) {
				break;
			}
			hash_update( $ctx, $buffer );
		}
		fclose( $stream );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_feof, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return hash_equals( $expected, hash_final( $ctx ) );
	}

	/**
	 * Builds compatibility warnings by comparing the manifest with this site.
	 *
	 * @param array<string,mixed> $manifest Decoded manifest.
	 * @return array<int,string>
	 */
	private function warnings( array $manifest ): array {
		$out  = array();
		$site = isset( $manifest['site'] ) ? (array) $manifest['site'] : array();
		$env  = isset( $manifest['environment'] ) ? (array) $manifest['environment'] : array();

		$origin = (string) ( $site['home_url'] ?? '' );
		if ( '' !== $origin && $origin !== home_url() ) {
			$out[] = sprintf(
				/* translators: 1: origin domain, 2: this site's domain */
				__( 'Origin domain (%1$s) differs from this site (%2$s) — a search-replace will run on restore.', 'rd-backup' ),
				$origin,
				home_url()
			);
		}

		if ( (bool) ( $site['is_multisite'] ?? false ) !== is_multisite() ) {
			$out[] = __( 'The multisite flag differs between the backup and this site.', 'rd-backup' );
		}

		$origin_php = (string) ( $env['php_version'] ?? '' );
		if ( '' !== $origin_php && version_compare( $origin_php, PHP_VERSION, '>' ) ) {
			$out[] = sprintf(
				/* translators: 1: origin PHP version, 2: this site's PHP version */
				__( 'Backup was made on PHP %1$s; this site runs %2$s (older) — watch for incompatibilities.', 'rd-backup' ),
				$origin_php,
				PHP_VERSION
			);
		}

		$origin_wp = (string) ( $env['wp_version'] ?? '' );
		if ( '' !== $origin_wp ) {
			global $wp_version;
			if ( version_compare( $origin_wp, (string) $wp_version, '>' ) ) {
				$out[] = sprintf(
					/* translators: 1: origin WP version, 2: this site's WP version */
					__( 'Backup was made on WordPress %1$s; this site runs %2$s (older).', 'rd-backup' ),
					$origin_wp,
					(string) $wp_version
				);
			}
		}

		if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
			$out[] = __( 'A persistent object-cache drop-in (e.g. Redis) is active here — if the restored target has no Redis, it may need to be removed.', 'rd-backup' );
		}

		return $out;
	}
}
