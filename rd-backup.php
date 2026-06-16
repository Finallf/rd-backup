<?php
/**
 * Plugin Name: RD Backup
 * Plugin URI: https://github.com/Finallf/rd-backup
 * Description: Complete, portable WordPress backup & restore — a full database dump plus the uploads folder in a single .zip, restorable on any host. Pairs with the ReloadeD theme but runs standalone with any theme.
 * Version: 0.0.0
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

define( 'RDBK_VERSION', '0.0.0' );
define( 'RDBK_PLUGIN_FILE', __FILE__ );
define( 'RDBK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RDBK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Engine bootstrap (admin UI, backup/restore engine, environment health-check,
 * GitHub auto-updater) gets wired here after the architecture design is
 * approved. Kept intentionally minimal for now so the plugin is installable,
 * activatable, and passes CI from commit zero.
 */
