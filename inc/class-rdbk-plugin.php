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

		if ( is_admin() ) {
			RDBK_Admin::instance();
		}
	}
}
