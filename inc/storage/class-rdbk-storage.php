<?php
/**
 * Backup store — manages wp-content/rd-backup/: creation, hardening, archive
 * naming, listing, path-safe resolution and authenticated download.
 *
 * The store lives OUTSIDE the plugin folder (survives plugin updates) and
 * OUTSIDE uploads/ (so it never ends up inside its own backups). Protection is
 * universal — a random token in the filename plus a PHP-only download handler —
 * with a .htaccess deny for Apache; on nginx an optional server rule adds
 * defense in depth.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the backup storage directory and the authenticated download endpoint.
 */
class RDBK_Storage {

	const DIR_NAME        = 'rd-backup';
	const DOWNLOAD_ACTION = 'rdbk_download';

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Storage|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
	}

	/**
	 * Creates the store on activation.
	 */
	public static function on_activation(): void {
		self::instance()->ensure_dir();
	}

	/**
	 * Absolute path to the store directory (no trailing slash).
	 */
	public function dir(): string {
		return WP_CONTENT_DIR . '/' . self::DIR_NAME;
	}

	/**
	 * Ensures the store dir exists and is hardened. Returns true on success.
	 */
	public function ensure_dir(): bool {
		$dir = $this->dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$this->write_guards( $dir );
		return is_dir( $dir );
	}

	/**
	 * Writes the index.php and .htaccess guards (idempotent).
	 */
	private function write_guards( string $dir ): void {
		$fs = $this->filesystem();
		if ( ! $fs ) {
			return;
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			$fs->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# RD Backup — deny direct access (Apache).\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			$fs->put_contents( $htaccess, $rules, FS_CHMOD_FILE );
		}
	}

	/**
	 * Lazily boots and returns the WP_Filesystem abstraction.
	 */
	private function filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	/**
	 * Builds a fresh, unguessable archive filename.
	 */
	public function new_archive_name(): string {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$host  = $host ? preg_replace( '/[^a-zA-Z0-9.\-]/', '', $host ) : 'site';
		$token = bin2hex( random_bytes( 16 ) );
		return 'rd-backup-' . $host . '-' . gmdate( 'Ymd-His' ) . '-' . $token . '.zip';
	}

	/**
	 * Lists the archives in the store, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_archives(): array {
		$dir = $this->dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$items = array();
		foreach ( (array) glob( $dir . '/*.zip' ) as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$name    = basename( $path );
			$size    = (int) filesize( $path );
			$mtime   = (int) filemtime( $path );
			$items[] = array(
				'name'     => $name,
				'size'     => $size,
				'sizeh'    => size_format( $size ),
				'modified' => $mtime,
				'dateh'    => wp_date( 'Y-m-d H:i', $mtime ),
				'url'      => $this->download_url( $name ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['modified'] <=> $a['modified'];
			}
		);

		return $items;
	}

	/**
	 * Resolves a requested filename to a safe absolute path inside the store, or
	 * an empty string if it escapes the directory or does not exist.
	 */
	public function resolve_safe( string $name ): string {
		$name = basename( $name );
		$ext  = strtolower( (string) substr( $name, -4 ) );
		if ( '' === $name || ( '.zip' !== $ext && '.sql' !== $ext ) ) {
			return '';
		}
		$real = realpath( $this->dir() . '/' . $name );
		$base = realpath( $this->dir() );
		if ( false === $real || false === $base || 0 !== strpos( $real, $base ) ) {
			return '';
		}
		return $real;
	}

	/**
	 * Authenticated download URL — points at the admin-post handler, never the
	 * file directly.
	 */
	public function download_url( string $name ): string {
		// Raw URL (unescaped `&`) — the escape happens at output (esc_url in PHP,
		// textContent in JS). Using wp_nonce_url() here would esc_html the `&` into
		// `&#038;`, which a second escape in JS turns into a `#` fragment, dropping
		// the nonce from the request.
		return add_query_arg(
			array(
				'action'   => self::DOWNLOAD_ACTION,
				'file'     => $name,
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Deletes an archive from the store (path-safe). Returns true when removed.
	 */
	public function delete( string $name ): bool {
		$path = $this->resolve_safe( $name );
		if ( '' === $path ) {
			return false;
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/**
	 * Writes a small real .zip into the store — used by the storage self-test to
	 * prove write + list + authenticated download (and ZipArchive) end-to-end.
	 * Returns the filename, or an empty string on failure.
	 */
	public function write_test_file(): string {
		if ( ! class_exists( 'ZipArchive' ) || ! $this->ensure_dir() ) {
			return '';
		}
		$name = $this->new_archive_name();
		$path = $this->dir() . '/' . $name;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return '';
		}
		$zip->addFromString(
			'readme.txt',
			"RD Backup storage self-test.\nCreated: " . gmdate( 'c' ) . "\nThis is a placeholder, not a real backup.\n"
		);
		$zip->close();

		return file_exists( $path ) ? $name : '';
	}

	/**
	 * Status snapshot for the Health tab.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$dir    = $this->dir();
		$exists = is_dir( $dir );
		return array(
			'dir'       => $dir,
			'exists'    => $exists,
			'protected' => $exists && file_exists( $dir . '/.htaccess' ) && file_exists( $dir . '/index.php' ),
		);
	}

	/**
	 * The optional nginx deny rule shown in the UI for defense in depth.
	 */
	public function nginx_rule(): string {
		return 'location ^~ /wp-content/' . self::DIR_NAME . "/ {\n\tdeny all;\n\treturn 403;\n}";
	}

	/**
	 * Streams an archive to the browser after capability + nonce checks.
	 */
	public function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download backups.', 'rd-backup' ), 403 );
		}
		check_admin_referer( self::DOWNLOAD_ACTION );

		// Not sanitize_file_name(): its multi-extension guard mangles the dotted
		// host in the name (theme.reloaded.com.br → ...com_.br). The real safety is
		// resolve_safe() — basename + .zip check + realpath inside the store.
		$requested = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$path      = $this->resolve_safe( $requested );
		if ( '' === $path ) {
			wp_die( esc_html__( 'Backup file not found.', 'rd-backup' ), 404 );
		}

		$mime = ( '.sql' === strtolower( (string) substr( $path, -4 ) ) ) ? 'application/sql' : 'application/zip';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a validated, in-store file to the browser; WP_Filesystem buffers in memory and is unsuitable for large archives.
		readfile( $path );
		exit;
	}
}
