<?php
/**
 * Orchestrator — boots the job runner (admin-ajax) and the admin UI.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's subsystems together.
 */
class RDBK_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Plugin|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// The runner registers admin-ajax endpoints — needed in the admin-ajax
		// context (where is_admin() is true), so it loads unconditionally.
		RDBK_Runner::instance();
		RDBK_Storage::instance();
		// Self-updater: hooks the plugin-update transient, which is also checked in
		// cron (non-admin), so it loads unconditionally.
		RDBK_Updater::instance();
		// Scheduler: registers the WP-Cron hooks that run automatic backups —
		// these fire in the non-admin cron context, so it loads unconditionally.
		RDBK_Scheduler::instance();
		// Notifier: the scheduler calls it (cron context) to report results, and
		// it registers the settings/test AJAX — loads unconditionally.
		RDBK_Notifier::instance();

		if ( is_admin() ) {
			RDBK_Admin::instance();
		}
	}
}
