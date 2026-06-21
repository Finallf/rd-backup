<?php
/**
 * Self-update — auto-update via GitHub Releases, no external deps.
 *
 * Mirrors the ReloadeD theme's updater (inc/mod-self-update.php), swapped to the
 * plugin update hooks: WordPress detects new releases on the project's GitHub and
 * offers a 1-click update on the Plugins screen, like any wp.org plugin.
 *
 * End-to-end flow:
 *   1. Push conventional commits → CI runs semantic-release → new version + tag.
 *   2. CI attaches rd-backup.zip to the GitHub Release.
 *   3. This site fetches /releases (24h cache) → compares semver → injects into
 *      the WP update transient → admin sees "Update available" and updates.
 *
 * Channels: stable (default) uses /releases/latest (GitHub excludes prereleases);
 * the opt-in beta channel (option rdbk_update_beta_channel) follows the newest
 * release including prereleases — when a stable ships after the betas it IS the
 * newest entry, so beta installs auto-promote to stable. See channel().
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connects the plugin to its GitHub Releases for 1-click updates.
 */
class RDBK_Updater {

	const REPO        = 'Finallf/rd-backup';
	const SLUG        = 'rd-backup';
	const TRANSIENT   = 'rdbk_update_release';
	const CACHE_HOURS = 24;
	const API_TIMEOUT = 8;
	const BETA_OPTION = 'rdbk_update_beta_channel';

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Updater|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source' ), 10, 4 );
		add_action( 'wp_ajax_rdbk_check_update', array( $this, 'ajax_check' ) );
		add_action( 'wp_ajax_rdbk_toggle_beta', array( $this, 'ajax_toggle_beta' ) );
	}

	/**
	 * Plugin file path relative to the plugins dir (the WP update key).
	 */
	public function plugin_basename(): string {
		return plugin_basename( RDBK_PLUGIN_FILE );
	}

	/**
	 * 'beta' when the opt-in channel option is on, 'stable' otherwise.
	 */
	public function channel(): string {
		return get_option( self::BETA_OPTION ) ? 'beta' : 'stable';
	}

	/**
	 * The core 1-click update URL (update.php) with a raw nonce — built with
	 * add_query_arg, not wp_nonce_url, whose esc_html'd "&" breaks a JS href.
	 */
	public function update_url(): string {
		$basename = $this->plugin_basename();
		return add_query_arg(
			array(
				'action'   => 'upgrade-plugin',
				'plugin'   => $basename,
				'_wpnonce' => wp_create_nonce( 'upgrade-plugin_' . $basename ),
			),
			self_admin_url( 'update.php' )
		);
	}

	/**
	 * The cached release for the current channel, WITHOUT triggering a fetch
	 * (cheap server-side render). Returns null when there's no fresh cache.
	 *
	 * @return array<string,mixed>|null
	 */
	public function cached_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['version'] ) && ( $cached['channel'] ?? 'stable' ) === $this->channel() ) {
			return $cached;
		}
		return null;
	}

	/**
	 * Latest release for the current channel, cached in a transient (24h; 1h on
	 * error). Returns a normalized array or null.
	 *
	 * @param bool $force Bypass the cache and re-fetch now.
	 * @return array<string,mixed>|null
	 */
	public function fetch_release( bool $force = false ): ?array {
		$channel = $this->channel();

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			// Cache only counts for the CURRENT channel — switching invalidates the
			// view at once instead of serving the other channel's release for 24h.
			if ( is_array( $cached ) && ! empty( $cached['version'] ) && ( $cached['channel'] ?? 'stable' ) === $channel ) {
				return $cached;
			}
		}

		// stable → /releases/latest (prereleases excluded by GitHub); beta → the
		// list (newest first, prereleases included) and we take the tip.
		$url = 'https://api.github.com/repos/' . self::REPO
			. ( 'beta' === $channel ? '/releases?per_page=15' : '/releases/latest' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::API_TIMEOUT,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Short "empty" cache on error to avoid hammering GitHub during an outage.
			set_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 'beta' === $channel ) {
			// GitHub's /releases order is NOT reliably newest-first, so don't trust
			// [0] — pick the highest semver tag. A stable release outranks the betas
			// (version_compare: 1.0.0 > 1.0.0-beta.14), so beta installs auto-promote.
			$newest = null;
			foreach ( (array) $data as $rel ) {
				if ( ! is_array( $rel ) || empty( $rel['tag_name'] ) || ! empty( $rel['draft'] ) ) {
					continue;
				}
				if ( null === $newest
					|| version_compare( ltrim( (string) $rel['tag_name'], 'v' ), ltrim( (string) $newest['tag_name'], 'v' ), '>' ) ) {
					$newest = $rel;
				}
			}
			$data = $newest;
		}
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		// Find the installable .zip asset (CI uploads rd-backup.zip).
		$zip_url = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( ! empty( $asset['browser_download_url'] ) && str_ends_with( strtolower( $name ), '.zip' ) ) {
				$zip_url = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $zip_url ) {
			return null;
		}

		$release = array(
			'version'      => ltrim( (string) $data['tag_name'], 'v' ),
			'download_url' => $zip_url,
			'release_url'  => (string) ( $data['html_url'] ?? '' ),
			'body'         => (string) ( $data['body'] ?? '' ),
			'published_at' => (string) ( $data['published_at'] ?? '' ),
			'checked_at'   => time(),
			'channel'      => $channel,
		);

		set_transient( self::TRANSIENT, $release, self::CACHE_HOURS * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Injects our update into the plugins transient when a newer release exists.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->fetch_release();
		if ( ! $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], RDBK_VERSION, '>' ) ) {
			$basename                         = $this->plugin_basename();
			$transient->response[ $basename ] = (object) array(
				'slug'        => self::SLUG,
				'plugin'      => $basename,
				'new_version' => $release['version'],
				'url'         => $release['release_url'],
				'package'     => $release['download_url'],
			);
		}

		return $transient;
	}

	/**
	 * Feeds the "View details" modal (plugins_api) with our release info. The
	 * changelog gets a minimal Markdown pass (headings, lists, bold, code, links)
	 * — no Parsedown dependency, just escaped text with a few inline tags.
	 *
	 * @param mixed  $result The default (false) or another plugin's payload.
	 * @param string $action The plugins_api action.
	 * @param object $args   Request args (carries ->slug).
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_release();
		if ( ! $release ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( RDBK_PLUGIN_FILE, false, false );

		return (object) array(
			'name'          => (string) ( $data['Name'] ?? 'ReloadeD Backup' ),
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => (string) ( $data['Author'] ?? '' ),
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['download_url'],
			'sections'      => array(
				'changelog' => $this->format_changelog( (string) $release['body'] ),
			),
		);
	}

	/**
	 * Converts a release's Markdown body to safe HTML for the changelog modal —
	 * a deliberately tiny pass (headings, lists, **bold**, `code`, [text](url)),
	 * NOT a full Markdown parser, to keep the plugin dependency-free.
	 *
	 * @param string $md The raw release body (GitHub Markdown).
	 * @return string Safe HTML.
	 */
	private function format_changelog( string $md ): string {
		$out     = '';
		$in_list = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $md ) as $raw ) {
			$line = trim( (string) $raw );

			// Skip blanks AND standalone separators (--- / *** / ___ / <br>) —
			// semantic-release sprinkles these between version sections, but the
			// headings already separate things, so they'd just be noise here.
			if ( '' === $line
				|| (bool) preg_match( '/^(?:-{3,}|\*{3,}|_{3,})$/', $line )
				|| (bool) preg_match( '#^<br\s*/?>$#i', $line ) ) {
				if ( $in_list ) {
					$out    .= '</ul>';
					$in_list = false;
				}
				continue;
			}

			// Heading (## … / ### …): ## → h3, ### → h4, capped at h4.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				if ( $in_list ) {
					$out    .= '</ul>';
					$in_list = false;
				}
				$level = (string) min( strlen( $m[1] ) + 1, 4 );
				$out  .= '<h' . $level . '>' . $this->inline_md( $m[2] ) . '</h' . $level . '>';
				continue;
			}

			// List item (* … / - …).
			if ( preg_match( '/^[*-]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_list ) {
					$out    .= '<ul>';
					$in_list = true;
				}
				$out .= '<li>' . $this->inline_md( $m[1] ) . '</li>';
				continue;
			}

			// Plain paragraph.
			if ( $in_list ) {
				$out    .= '</ul>';
				$in_list = false;
			}
			$out .= '<p>' . $this->inline_md( $line ) . '</p>';
		}

		if ( $in_list ) {
			$out .= '</ul>';
		}
		return $out;
	}

	/**
	 * Escapes a line, then re-introduces the few inline elements the changelog
	 * uses: [text](url) links, **bold** and `code`. Order matters — escape first,
	 * so the converted tags are the only HTML in the output.
	 *
	 * @param string $text One line of Markdown (block markers already stripped).
	 * @return string Safe HTML.
	 */
	private function inline_md( string $text ): string {
		$text = esc_html( $text );
		// An inline <br> (escaped to &lt;br&gt; above) → a real line break.
		$text = (string) preg_replace( '#&lt;br\s*/?&gt;#i', '<br>', $text );
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
			},
			$text
		);
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', (string) $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', (string) $text );
		return (string) $text;
	}

	/**
	 * Defense in depth: after WP unpacks the ZIP, force the extracted folder to
	 * 'rd-backup' so the plugin lands at the right path even if the archive's
	 * internal folder is named differently.
	 *
	 * @param string $source        Extracted folder path.
	 * @param string $remote_source Parent temp dir.
	 * @param mixed  $upgrader      The WP_Upgrader instance.
	 * @param array  $hook_extra    Context (carries ['plugin']).
	 * @return string|WP_Error
	 */
	public function fix_source( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . self::SLUG;
		if ( untrailingslashit( $source ) === $expected ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $expected ) ) {
			return trailingslashit( $expected );
		}

		return new WP_Error( 'rdbk_rename_failed', __( 'Failed to rename the plugin directory during update.', 'rd-backup' ) );
	}

	/**
	 * AJAX "Check for updates": invalidate the cache, re-fetch, return the status.
	 */
	public function ajax_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd-backup' ) ), 403 );
		}
		check_ajax_referer( 'rdbk_check_update', 'nonce' );

		// Drop WP's own cache too, so the Plugins screen reflects it on next load.
		delete_site_transient( 'update_plugins' );

		$release = $this->fetch_release( true );
		$current = RDBK_VERSION;

		if ( ! $release ) {
			wp_send_json_success(
				array(
					'ok'        => false,
					'current'   => $current,
					'latest'    => '',
					'status'    => __( 'Could not reach GitHub. Try again later.', 'rd-backup' ),
					'hasUpdate' => false,
				)
			);
		}

		$has_update = version_compare( $release['version'], $current, '>' );
		wp_send_json_success(
			array(
				'ok'         => true,
				'current'    => $current,
				'latest'     => $release['version'],
				'status'     => $has_update ? __( 'Update available', 'rd-backup' ) : __( 'Up to date', 'rd-backup' ),
				'hasUpdate'  => $has_update,
				'releaseUrl' => $release['release_url'],
			)
		);
	}

	/**
	 * AJAX: flip the beta-channel option (the switch in the Updates card). The JS
	 * chains an immediate re-check so the card reflects the new channel at once.
	 */
	public function ajax_toggle_beta(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd-backup' ) ), 403 );
		}
		check_ajax_referer( 'rdbk_toggle_beta', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$on = isset( $_POST['on'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['on'] ) );
		update_option( self::BETA_OPTION, $on ? 1 : 0 );

		wp_send_json_success( array( 'beta' => $on ) );
	}
}
