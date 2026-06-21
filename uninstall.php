<?php
/**
 * Uninstall cleanup for ReloadeD Backup.
 *
 * Runs ONLY when the plugin is deleted from the admin (not on deactivation).
 * Removes the plugin's own option + cached transient. User backups in
 * wp-content/rd-backup/ are deliberately left untouched — deleting them on
 * uninstall would be data loss for anyone reinstalling or keeping the .zips.
 *
 * @package RD_Backup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Updater: opt-in beta-channel flag + cached release lookup.
// (Literals mirror RDBK_Updater::BETA_OPTION / RDBK_Updater::TRANSIENT — the
// plugin classes are not loaded during uninstall, so we can't use the consts.)
delete_option( 'rdbk_update_beta_channel' );
delete_transient( 'rdbk_update_release' );

// Retention: the "keep last N" setting (mirrors RDBK_Storage::RETENTION_OPTION).
delete_option( 'rdbk_retention_keep' );

// Defensive: a pre-1.0 build kept the job state in an option before it moved to
// a file (wp-content/rd-backup/.job.json). Drop any lingering row.
delete_option( 'rdbk_job' );
