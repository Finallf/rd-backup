<?php
/**
 * Plugin Name: RD Backup
 * Plugin URI: https://github.com/Finallf/rd-backup
 * Description: Complete, portable WordPress backup & restore — a full database dump plus the uploads folder in a single .zip, restorable on any host. Pairs with the ReloadeD theme but runs standalone with any theme.
 * Version: 1.0.0-beta.7
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

define( 'RDBK_VERSION', '1.0.0-beta.7' );
define( 'RDBK_PLUGIN_FILE', __FILE__ );
define( 'RDBK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RDBK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Module bootstrap. Engine classes live in inc/; the orchestrator wires the
 * admin UI, the resumable job runner and the environment health-check. Backup,
 * restore, storage and the auto-updater land in the following releases.
 */
require_once RDBK_PLUGIN_DIR . 'inc/job/class-rdbk-job.php';
require_once RDBK_PLUGIN_DIR . 'inc/job/class-rdbk-runner.php';
require_once RDBK_PLUGIN_DIR . 'inc/storage/class-rdbk-storage.php';
require_once RDBK_PLUGIN_DIR . 'inc/backup/class-rdbk-db-dump.php';
require_once RDBK_PLUGIN_DIR . 'inc/backup/class-rdbk-archiver.php';
require_once RDBK_PLUGIN_DIR . 'inc/backup/class-rdbk-manifest.php';
require_once RDBK_PLUGIN_DIR . 'inc/backup/class-rdbk-backup.php';
require_once RDBK_PLUGIN_DIR . 'inc/restore/class-rdbk-db-import.php';
require_once RDBK_PLUGIN_DIR . 'inc/restore/class-rdbk-uploads-extract.php';
require_once RDBK_PLUGIN_DIR . 'inc/restore/class-rdbk-search-replace.php';
require_once RDBK_PLUGIN_DIR . 'inc/restore/class-rdbk-restore.php';
require_once RDBK_PLUGIN_DIR . 'inc/admin/class-rdbk-healthcheck.php';
require_once RDBK_PLUGIN_DIR . 'inc/admin/class-rdbk-admin.php';
require_once RDBK_PLUGIN_DIR . 'inc/class-rdbk-plugin.php';

add_action( 'plugins_loaded', array( 'RDBK_Plugin', 'instance' ) );
register_activation_hook( __FILE__, array( 'RDBK_Storage', 'on_activation' ) );
