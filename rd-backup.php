<?php
/**
 * Plugin Name: ReloadeD Backup
 * Plugin URI: https://github.com/Finallf/rd-backup
 * Description: Complete, portable WordPress backup & restore — a full database dump plus the uploads folder in a single .zip, restorable on any host. Pairs with the ReloadeD theme but runs standalone with any theme.
 * Version: 1.2.0-beta.5
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Regis Vieira Delgado
 * Author URI: https://reloaded.com.br
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rd-backup
 * Domain Path: /languages
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

define( 'RDBK_VERSION', '1.2.0-beta.5' );
define( 'RDBK_PLUGIN_FILE', __FILE__ );
define( 'RDBK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RDBK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Module bootstrap. Engine classes live in inc/; the orchestrator wires the
 * admin UI, the resumable job runner and the environment health-check.
 */
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-job.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-runner.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-storage.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-db-dump.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-archiver.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-manifest.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-backup.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-db-import.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-uploads-extract.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-search-replace.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-restore.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-healthcheck.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-scheduler.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-notifier.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-admin.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-updater.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-plugin.php';

add_action( 'plugins_loaded', array( 'RDBK_Plugin', 'instance' ) );
register_activation_hook( __FILE__, array( 'RDBK_Storage', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'RDBK_Storage', 'on_deactivation' ) );
register_deactivation_hook( __FILE__, array( 'RDBK_Scheduler', 'on_deactivation' ) );

/*
 * Load the plugin's translations from /languages. Hooked on init (not
 * plugins_loaded) because WP 6.7+ emits a doing_it_wrong notice when a text
 * domain is loaded before the locale is set up.
 */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'rd-backup', false, dirname( plugin_basename( RDBK_PLUGIN_FILE ) ) . '/languages' );
	}
);

/**
 * Public API: the most recent user backup, or null when there are none.
 *
 * Lets another plugin or theme surface a "last backup" indicator without
 * reaching into this plugin's internals — guard the call with
 * function_exists( 'rdbk_get_last_backup' ) so it degrades gracefully when the
 * plugin is absent. Returns the array shape from RDBK_Storage::list_archives()
 * for the newest non-safety archive (keys: name, size, sizeh, modified, dateh,
 * url), or null.
 *
 * @return array|null
 */
function rdbk_get_last_backup(): ?array {
	if ( ! class_exists( 'RDBK_Storage' ) ) {
		return null;
	}
	$archives = RDBK_Storage::instance()->list_archives( 'user' );
	return $archives[0] ?? null;
}
