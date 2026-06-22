<?php
/**
 * Admin page — Tools → ReloadeD Backup, with Backup / Restore / Health tabs and
 * the AJAX progress UI. Backup builds the .zip, Restore inspects and applies an
 * archive, Health runs the environment preflight.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin page and renders its tabs.
 */
class RDBK_Admin {

	const MENU_SLUG = 'rd-backup';
	const HOOK      = 'tools_page_rd-backup';

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Admin|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_rdbk_save_retention', array( $this, 'ajax_save_retention' ) );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'ReloadeD Backup', 'rd-backup' ),
			__( 'ReloadeD Backup', 'rd-backup' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue( ?string $hook ): void {
		// Nullable: WP fires admin_enqueue_scripts with a null hook suffix in some
		// screenless contexts (iframe / admin-post pages), and a strict `string`
		// typehint would fatal there. We only act on our own page anyway.
		if ( self::HOOK !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'rdbk-admin',
			RDBK_PLUGIN_URL . 'assets/css/rdbk-admin.css',
			array(),
			RDBK_VERSION
		);

		wp_enqueue_script(
			'rdbk-admin',
			RDBK_PLUGIN_URL . 'assets/js/rdbk-admin.js',
			array(),
			RDBK_VERSION,
			true
		);

		wp_localize_script(
			'rdbk-admin',
			'rdbkData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'rdbk_runner' ),
				'updateUrl' => RDBK_Updater::instance()->update_url(),
				'i18n'      => array(
					'starting'         => __( 'Starting…', 'rd-backup' ),
					'working'          => __( 'Working…', 'rd-backup' ),
					'done'             => __( 'Done!', 'rd-backup' ),
					'cancelled'        => __( 'Cancelled.', 'rd-backup' ),
					'failed'           => __( 'Failed. Check the logs.', 'rd-backup' ),
					'noArchives'       => __( 'No archives yet.', 'rd-backup' ),
					'download'         => __( 'Download', 'rd-backup' ),
					'del'              => __( 'Delete', 'rd-backup' ),
					'confirmDel'       => __( 'Delete this file?', 'rd-backup' ),
					'backupDone'       => __( 'Backup created:', 'rd-backup' ),
					'previewing'       => __( 'Reading archive…', 'rd-backup' ),
					'origin'           => __( 'Origin', 'rd-backup' ),
					'created'          => __( 'Created', 'rd-backup' ),
					'contents'         => __( 'Contents', 'rd-backup' ),
					'integrity'        => __( 'Integrity', 'rd-backup' ),
					'intOk'            => __( 'verified', 'rd-backup' ),
					'intFail'          => __( 'FAILED — archive may be corrupt', 'rd-backup' ),
					'intUnknown'       => __( 'no hash in manifest', 'rd-backup' ),
					'intBlockedTitle'  => __( 'Restore blocked', 'rd-backup' ),
					'intBlocked'       => __( 'This archive failed its integrity check (corrupt or tampered database) and cannot be restored.', 'rd-backup' ),
					'warningsLbl'      => __( 'Warnings', 'rd-backup' ),
					'noWarnings'       => __( 'No compatibility warnings.', 'rd-backup' ),
					'restoreWarnTitle' => __( 'Heads up:', 'rd-backup' ),
					'restoreWarn'      => __( 'This overwrites the current database. A full safety backup is taken first.', 'rd-backup' ),
					'typeRestore'      => __( 'Type RESTORE to confirm:', 'rd-backup' ),
					'restoreBtn'       => __( 'Restore this backup', 'rd-backup' ),
					'safetyBackup'     => __( 'Creating safety backup…', 'rd-backup' ),
					'restoring'        => __( 'Restoring…', 'rd-backup' ),
					'restoreDone'      => __( 'Restore complete. You may need to log in again — reload the page to see the restored site.', 'rd-backup' ),
					'confirmReset'     => __( 'Clear the current job state? This does not touch your backups.', 'rd-backup' ),
					'resetDone'        => __( 'Job state cleared.', 'rd-backup' ),
					'uploadPick'       => __( 'Choose a .zip first.', 'rd-backup' ),
					'uploadZipOnly'    => __( 'Only .zip backups can be uploaded.', 'rd-backup' ),
					'uploadNoFile'     => __( 'No file chosen', 'rd-backup' ),
					'uploading'        => __( 'Uploading…', 'rd-backup' ),
					'uploadDone'       => __( 'Uploaded — refreshing…', 'rd-backup' ),
					'updateNow'        => __( 'Update now', 'rd-backup' ),
					'viewRelease'      => __( 'View release on GitHub', 'rd-backup' ),
					'justNow'          => __( 'just now', 'rd-backup' ),
					'saved'            => __( 'Saved.', 'rd-backup' ),
					'testing'          => __( 'Sending…', 'rd-backup' ),
					'testOk'           => __( 'Test sent.', 'rd-backup' ),
					'testFail'         => __( 'Test failed for a channel — check the settings.', 'rd-backup' ),
				),
			)
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch in the admin UI, no form is processed.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'backup';
		$tabs   = array(
			'backup'   => __( 'Backup', 'rd-backup' ),
			'restore'  => __( 'Restore', 'rd-backup' ),
			'schedule' => __( 'Schedule', 'rd-backup' ),
			'health'   => __( 'Health', 'rd-backup' ),
		);
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'backup';
		}

		echo '<div class="wrap rdbk-wrap">';

		echo '<div class="rdbk-panel-header">';
		echo '<h1 class="rdbk-panel-title">' . esc_html__( 'ReloadeD Backup', 'rd-backup' ) . '</h1>';
		// Logo slot — renders once the .webp exists at assets/img/.
		if ( file_exists( RDBK_PLUGIN_DIR . 'assets/img/reloaded-logo-200-55.webp' ) ) {
			printf(
				'<img class="rdbk-panel-logo" src="%s" alt="%s">',
				esc_url( RDBK_PLUGIN_URL . 'assets/img/reloaded-logo-200-55.webp' ),
				esc_attr__( 'ReloadeD Backup', 'rd-backup' )
			);
		}
		echo '</div>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = 'nav-tab' . ( $active === $slug ? ' nav-tab-active' : '' );
			$url   = add_query_arg(
				array(
					'page' => self::MENU_SLUG,
					'tab'  => $slug,
				),
				admin_url( 'tools.php' )
			);
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		echo '<div class="rdbk-panel-form">';
		switch ( $active ) {
			case 'health':
				$this->render_health();
				break;
			case 'restore':
				$this->render_restore();
				break;
			case 'schedule':
				$this->render_schedule();
				break;
			default:
				$this->render_backup();
				break;
		}
		echo '</div>';

		echo '</div>';
	}

	private function render_backup(): void {
		?>
		<div class="rdbk-section-header">
			<span class="dashicons dashicons-database-export" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Backup', 'rd-backup' ); ?></h2>
		</div>
		<div class="rdbk-pdash">
			<div class="rdbk-pgrid">
				<div class="rdbk-card">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Create a backup', 'rd-backup' ); ?></h3>
					<p class="rdbk-card__desc">
						<?php esc_html_e( 'Builds a complete .zip — the full database dump plus the uploads folder — and saves it to the store below.', 'rd-backup' ); ?>
					</p>
					<p>
						<button type="button" class="button button-primary button-hero" id="rdbk-backup-run"><?php esc_html_e( 'Create backup', 'rd-backup' ); ?></button>
						<span id="rdbk-backup-msg" class="rdbk-inline-msg" aria-live="polite"></span>
					</p>
					<div class="rdbk-progress" id="rdbk-backup-progress" hidden>
						<div class="rdbk-progress__track">
							<div class="rdbk-progress__bar" id="rdbk-backup-bar"></div>
						</div>
						<p class="rdbk-progress__status" id="rdbk-backup-status" aria-live="polite"></p>
					</div>
				</div>

				<div class="rdbk-card">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Backup store', 'rd-backup' ); ?></h3>
					<p class="rdbk-card__desc">
						<?php
						printf(
							/* translators: %s: absolute path to the store directory */
							esc_html__( 'Backups are stored in %s — outside the plugin and outside uploads. Files carry a random token and are only downloadable through an authenticated handler, never a direct URL.', 'rd-backup' ),
							'<code>' . esc_html( RDBK_Storage::instance()->dir() ) . '</code>'
						);
						?>
					</p>

					<p class="rdbk-retention-row">
						<label for="rdbk-retention"><?php esc_html_e( 'Backups to keep:', 'rd-backup' ); ?></label>
						<select id="rdbk-retention" data-nonce="<?php echo esc_attr( wp_create_nonce( 'rdbk_save_retention' ) ); ?>">
							<?php
							$rdbk_keep = RDBK_Storage::instance()->retention_keep();
							foreach ( RDBK_Storage::RETENTION_CHOICES as $rdbk_choice ) {
								printf(
									'<option value="%1$d"%2$s>%1$d</option>',
									(int) $rdbk_choice,
									selected( (int) $rdbk_choice, $rdbk_keep, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed, safe attribute string.
								);
							}
							?>
						</select>
						<span id="rdbk-retention-msg" class="rdbk-inline-msg" aria-live="polite"></span>
					</p>
					<p class="rdbk-card__hint">
						<?php esc_html_e( 'After each new backup, only the most recent backups (up to this limit) are kept — older ones are deleted automatically. Safety snapshots are kept separately.', 'rd-backup' ); ?>
					</p>

					<table class="widefat striped rdbk-archives">
						<thead>
							<tr>
								<th><?php esc_html_e( 'File', 'rd-backup' ); ?></th>
								<th><?php esc_html_e( 'Size', 'rd-backup' ); ?></th>
								<th><?php esc_html_e( 'Date', 'rd-backup' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="rdbk-archives-body">
							<?php $this->render_archive_rows( RDBK_Storage::instance()->list_archives() ); ?>
						</tbody>
					</table>

					<div class="rdbk-nginx-rule">
						<p class="rdbk-card__hint">
							<?php esc_html_e( 'Optional — for nginx (including nginx-in-front setups like HestiaCP, where the .htaccess can be bypassed), add this server-level deny rule for defense in depth:', 'rd-backup' ); ?>
						</p>
						<pre class="rdbk-snippet"><?php echo esc_html( RDBK_Storage::instance()->nginx_rule() ); ?></pre>
					</div>
				</div>

				<div class="rdbk-card rdbk-card--placeholder">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Maintenance', 'rd-backup' ); ?></h3>
					<p>
						<button type="button" class="button" id="rdbk-reset-job"><?php esc_html_e( 'Reset job state', 'rd-backup' ); ?></button>
						<span id="rdbk-reset-msg" class="rdbk-inline-msg" aria-live="polite"></span>
					</p>
					<p class="rdbk-card__desc"><?php esc_html_e( 'Clears a stuck backup or restore job if one was interrupted. Your backups are not touched.', 'rd-backup' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_archive_rows( array $items ): void {
		if ( empty( $items ) ) {
			echo '<tr class="rdbk-archives__empty"><td colspan="4">' . esc_html__( 'No archives yet.', 'rd-backup' ) . '</td></tr>';
			return;
		}
		foreach ( $items as $item ) {
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td><a class="button button-small" href="%4$s">%5$s</a> <button type="button" class="button button-small button-link-delete rdbk-del" data-file="%6$s">%7$s</button></td></tr>',
				esc_html( $item['name'] ),
				esc_html( $item['sizeh'] ),
				esc_html( $item['dateh'] ),
				esc_url( $item['url'] ),
				esc_html__( 'Download', 'rd-backup' ),
				esc_attr( $item['name'] ),
				esc_html__( 'Delete', 'rd-backup' )
			);
		}
	}

	private function render_restore(): void {
		$archives  = RDBK_Storage::instance()->list_archives();
		$snapshots = RDBK_Storage::instance()->list_archives( 'safety' );
		?>
		<div class="rdbk-section-header">
			<span class="dashicons dashicons-database-import" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Restore', 'rd-backup' ); ?></h2>
		</div>
		<div class="rdbk-pdash">
			<div class="rdbk-pgrid">
				<div class="rdbk-card">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Upload a backup', 'rd-backup' ); ?></h3>
					<p class="rdbk-card__desc">
						<?php
						printf(
							/* translators: %s: maximum upload size, e.g. 256 MB */
							esc_html__( 'Upload a ReloadeD Backup .zip from another site (up to %s here). For larger archives, drop the file into the store via SFTP instead.', 'rd-backup' ),
							esc_html( size_format( wp_max_upload_size() ) )
						);
						?>
					</p>
					<p class="rdbk-upload-row">
						<input type="file" id="rdbk-upload-file" class="rdbk-file-input" accept=".zip">
						<label for="rdbk-upload-file" class="button"><?php esc_html_e( 'Choose .zip', 'rd-backup' ); ?></label>
						<span id="rdbk-upload-name" class="rdbk-file-name"><?php esc_html_e( 'No file chosen', 'rd-backup' ); ?></span>
						<button type="button" class="button" id="rdbk-upload-btn"><?php esc_html_e( 'Upload', 'rd-backup' ); ?></button>
						<span id="rdbk-upload-msg" class="rdbk-inline-msg" aria-live="polite"></span>
					</p>
					<div class="rdbk-progress" id="rdbk-upload-progress" hidden>
						<div class="rdbk-progress__track">
							<div class="rdbk-progress__bar" id="rdbk-upload-bar"></div>
						</div>
						<p class="rdbk-progress__status" id="rdbk-upload-status" aria-live="polite"></p>
					</div>
				</div>

				<div class="rdbk-card">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Restore from a backup', 'rd-backup' ); ?></h3>
					<p class="rdbk-card__desc">
						<?php esc_html_e( 'Select a backup to inspect it. This is read-only: it validates the archive and previews what a restore would do — nothing is changed. To restore a backup from another site, drop its .zip into the store via SFTP and it shows up here.', 'rd-backup' ); ?>
					</p>
					<table class="widefat striped rdbk-restore-list">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Backup', 'rd-backup' ); ?></th>
								<th><?php esc_html_e( 'Size', 'rd-backup' ); ?></th>
								<th><?php esc_html_e( 'Date', 'rd-backup' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $archives ) ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'No backups in the store yet — create one in the Backup tab, or drop a .zip via SFTP.', 'rd-backup' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $archives as $item ) : ?>
									<tr>
										<td><code><?php echo esc_html( $item['name'] ); ?></code></td>
										<td><?php echo esc_html( $item['sizeh'] ); ?></td>
										<td><?php echo esc_html( $item['dateh'] ); ?></td>
										<td><button type="button" class="button button-small rdbk-preview-btn" data-file="<?php echo esc_attr( $item['name'] ); ?>"><?php esc_html_e( 'Preview', 'rd-backup' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php if ( ! empty( $snapshots ) ) : ?>
					<div class="rdbk-card">
						<h3 class="rdbk-card__title"><?php esc_html_e( 'Safety snapshots', 'rd-backup' ); ?></h3>
						<p class="rdbk-card__desc">
							<?php esc_html_e( 'Full backups taken automatically right before each restore (the last 2 are kept). Restore one to undo your last restore.', 'rd-backup' ); ?>
						</p>
						<table class="widefat striped rdbk-restore-list">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Snapshot', 'rd-backup' ); ?></th>
									<th><?php esc_html_e( 'Size', 'rd-backup' ); ?></th>
									<th><?php esc_html_e( 'Date', 'rd-backup' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $snapshots as $item ) : ?>
									<tr>
										<td><code><?php echo esc_html( $item['name'] ); ?></code></td>
										<td><?php echo esc_html( $item['sizeh'] ); ?></td>
										<td><?php echo esc_html( $item['dateh'] ); ?></td>
										<td><button type="button" class="button button-small rdbk-preview-btn" data-file="<?php echo esc_attr( $item['name'] ); ?>"><?php esc_html_e( 'Preview', 'rd-backup' ); ?></button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div id="rdbk-preview" class="rdbk-preview" hidden></div>
		<?php
	}

	private function render_schedule(): void {
		$scheduler = RDBK_Scheduler::instance();
		$freq      = $scheduler->freq();
		$time      = $scheduler->time_of_day();
		$next      = $scheduler->next_scheduled();
		$last      = $scheduler->last_run();

		$freq_labels = array(
			'off'     => __( 'Off', 'rd-backup' ),
			'daily'   => __( 'Daily', 'rd-backup' ),
			'weekly'  => __( 'Weekly', 'rd-backup' ),
			'monthly' => __( 'Monthly', 'rd-backup' ),
		);
		?>
		<div class="rdbk-section-header">
			<span class="dashicons dashicons-clock" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Schedule', 'rd-backup' ); ?></h2>
		</div>
		<div class="rdbk-pdash">
			<div class="rdbk-pgrid">
				<div class="rdbk-card">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Automatic backups', 'rd-backup' ); ?></h3>
					<p class="rdbk-card__desc">
						<?php esc_html_e( 'Run a full backup automatically on a schedule. Automatic backups follow the same "keep last N" retention as manual ones (set in the Backup tab) and do not take a safety snapshot.', 'rd-backup' ); ?>
					</p>

					<p class="rdbk-schedule-row">
						<label for="rdbk-schedule-freq"><?php esc_html_e( 'Frequency:', 'rd-backup' ); ?></label>
						<select id="rdbk-schedule-freq">
							<?php
							foreach ( $freq_labels as $value => $label ) {
								printf(
									'<option value="%1$s"%2$s>%3$s</option>',
									esc_attr( $value ),
									selected( $value, $freq, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed, safe attribute string.
									esc_html( $label )
								);
							}
							?>
						</select>
						<label for="rdbk-schedule-time"><?php esc_html_e( 'at', 'rd-backup' ); ?></label>
						<input type="time" id="rdbk-schedule-time" value="<?php echo esc_attr( $time ); ?>">
						<button type="button" class="button button-primary" id="rdbk-schedule-save" data-nonce="<?php echo esc_attr( wp_create_nonce( 'rdbk_save_schedule' ) ); ?>"><?php esc_html_e( 'Save schedule', 'rd-backup' ); ?></button>
						<span id="rdbk-schedule-msg" class="rdbk-inline-msg" aria-live="polite"></span>
					</p>

					<p>
						<strong><?php esc_html_e( 'Next backup:', 'rd-backup' ); ?></strong>
						<span id="rdbk-schedule-next"><?php echo $next ? esc_html( wp_date( 'Y-m-d H:i', $next ) ) : esc_html__( '—', 'rd-backup' ); ?></span>
					</p>
					<p>
						<strong><?php esc_html_e( 'Last automatic backup:', 'rd-backup' ); ?></strong>
						<?php
						if ( is_array( $last ) ) {
							$ok = 'done' === ( $last['status'] ?? '' );
							printf(
								'<span class="rdbk-badge rdbk-badge--%1$s">%2$s</span> %3$s',
								esc_attr( $ok ? 'ok' : 'fail' ),
								$ok ? esc_html__( 'OK', 'rd-backup' ) : esc_html__( 'Failed', 'rd-backup' ),
								esc_html( wp_date( 'Y-m-d H:i', (int) $last['time'] ) )
							);
						} else {
							echo esc_html__( '—', 'rd-backup' );
						}
						?>
					</p>

					<p class="rdbk-card__hint">
						<?php esc_html_e( 'WordPress runs scheduled tasks when the site gets traffic, so on a quiet site a backup may run a little after the chosen time. For exact timing, point a real system cron at wp-cron.php.', 'rd-backup' ); ?>
					</p>
				</div>

				<?php
				$notifier     = RDBK_Notifier::instance();
				$notify_on    = $notifier->notify_on();
				$email_on     = $notifier->email_enabled();
				$email_to     = $notifier->email_to();
				$tg_on        = $notifier->telegram_enabled();
				$tg_chat      = $notifier->telegram_chat();
				$has_token    = $notifier->has_telegram_token();
				$notify_nonce = wp_create_nonce( 'rdbk_notify' );
				?>
				<div class="rdbk-card">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Notifications', 'rd-backup' ); ?></h3>
					<p class="rdbk-card__desc">
						<?php esc_html_e( 'Get notified when an automatic backup finishes. Manual backups are not notified — you see their result on screen.', 'rd-backup' ); ?>
					</p>

					<p class="rdbk-notify-row">
						<label for="rdbk-notify-on"><?php esc_html_e( 'Notify on:', 'rd-backup' ); ?></label>
						<select id="rdbk-notify-on">
							<option value="failures"<?php selected( 'failures', $notify_on ); ?>><?php esc_html_e( 'Failures only', 'rd-backup' ); ?></option>
							<option value="all"<?php selected( 'all', $notify_on ); ?>><?php esc_html_e( 'Success and failures', 'rd-backup' ); ?></option>
						</select>
					</p>

					<p class="rdbk-notify-row">
						<label><input type="checkbox" id="rdbk-notify-email"<?php checked( $email_on ); ?>> <?php esc_html_e( 'Email', 'rd-backup' ); ?></label>
						<input type="email" id="rdbk-notify-email-to" class="regular-text" value="<?php echo esc_attr( $email_to ); ?>" placeholder="you@example.com">
					</p>

					<p class="rdbk-notify-row">
						<label><input type="checkbox" id="rdbk-notify-telegram"<?php checked( $tg_on ); ?>> <?php esc_html_e( 'Telegram', 'rd-backup' ); ?></label>
					</p>
					<p class="rdbk-notify-row">
						<label for="rdbk-notify-tg-token"><?php esc_html_e( 'Bot token:', 'rd-backup' ); ?></label>
						<input type="password" id="rdbk-notify-tg-token" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_token ? esc_attr__( '•••••• (leave blank to keep)', 'rd-backup' ) : ''; ?>">
						<label for="rdbk-notify-tg-chat"><?php esc_html_e( 'Chat ID:', 'rd-backup' ); ?></label>
						<input type="text" id="rdbk-notify-tg-chat" value="<?php echo esc_attr( $tg_chat ); ?>">
					</p>

					<p class="rdbk-notify-row">
						<button type="button" class="button button-primary" id="rdbk-notify-save" data-nonce="<?php echo esc_attr( $notify_nonce ); ?>"><?php esc_html_e( 'Save notifications', 'rd-backup' ); ?></button>
						<button type="button" class="button" id="rdbk-notify-test" data-nonce="<?php echo esc_attr( $notify_nonce ); ?>"><?php esc_html_e( 'Send test', 'rd-backup' ); ?></button>
						<span id="rdbk-notify-msg" class="rdbk-inline-msg" aria-live="polite"></span>
					</p>
					<p class="rdbk-card__hint">
						<?php esc_html_e( 'Telegram: create a bot with @BotFather, then paste its token and your chat/channel ID. Save before sending a test — the token is stored privately and never shown again.', 'rd-backup' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_health(): void {
		?>
		<div class="rdbk-section-header">
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Updates', 'rd-backup' ); ?></h2>
		</div>
		<div class="rdbk-pdash">
			<div class="rdbk-pgrid">
				<?php $this->render_updates_card(); ?>
			</div>
		</div>

		<?php $checks = RDBK_Healthcheck::run(); ?>
		<div class="rdbk-section-header">
			<span class="dashicons dashicons-heart" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Environment', 'rd-backup' ); ?></h2>
		</div>
		<div class="rdbk-pdash">
			<div class="rdbk-pgrid">
				<div class="rdbk-card">
					<h3 class="rdbk-card__title"><?php esc_html_e( 'Preflight checks', 'rd-backup' ); ?></h3>
					<table class="widefat striped rdbk-health">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Check', 'rd-backup' ); ?></th>
								<th><?php esc_html_e( 'Result', 'rd-backup' ); ?></th>
								<th><?php esc_html_e( 'Notes', 'rd-backup' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $checks as $check ) {
								$status = isset( $check['status'] ) ? $check['status'] : 'ok';
								printf(
									'<tr><td>%s</td><td><span class="rdbk-badge rdbk-badge--%s">%s</span></td><td>%s</td></tr>',
									esc_html( $check['label'] ),
									esc_attr( $status ),
									esc_html( $check['value'] ),
									esc_html( $check['hint'] )
								);
							}
							?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The "Release status" card (1:1 with the theme's updates card): current/latest
	 * version, status badge, beta-channel switch, check + update-now controls.
	 */
	private function render_updates_card(): void {
		$updater     = RDBK_Updater::instance();
		$current     = RDBK_VERSION;
		$beta        = 'beta' === $updater->channel();
		$cached      = $updater->cached_release();
		$latest      = is_array( $cached ) ? (string) ( $cached['version'] ?? '' ) : '';
		$checked_at  = is_array( $cached ) ? (int) ( $cached['checked_at'] ?? 0 ) : 0;
		$release_url = is_array( $cached ) ? (string) ( $cached['release_url'] ?? '' ) : '';

		$has_update = '' !== $latest && version_compare( $latest, $current, '>' );
		if ( '' === $latest ) {
			$badge_variant = 'neutral';
			$badge_text    = __( 'Never checked', 'rd-backup' );
			$last_check    = __( 'never', 'rd-backup' );
		} else {
			$badge_variant = $has_update ? 'warning' : 'success';
			$badge_text    = $has_update ? __( 'Update available', 'rd-backup' ) : __( 'Up to date', 'rd-backup' );
			$last_check    = $checked_at > 0
				/* translators: %s: human-readable time-ago, e.g. "2 hours" */
				? sprintf( __( '%s ago', 'rd-backup' ), human_time_diff( $checked_at, time() ) )
				: __( 'unknown', 'rd-backup' );
		}
		?>
		<div class="rdbk-card rdbk-self-update">
			<div class="rdbk-self-update__header">
				<span class="rdbk-self-update__controls">
					<span class="rdbk-self-update__channel">
						<span class="rdbk-self-update__channel-label"><?php esc_html_e( 'Beta channel', 'rd-backup' ); ?></span>
						<button type="button" class="rdbk-pswitch" role="switch" aria-checked="<?php echo $beta ? 'true' : 'false'; ?>" id="rdbk-beta-switch" data-nonce="<?php echo esc_attr( wp_create_nonce( 'rdbk_toggle_beta' ) ); ?>">
							<span class="rdbk-pswitch__track"></span>
							<span class="rdbk-pswitch__thumb"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Beta channel', 'rd-backup' ); ?></span>
						</button>
					</span>
					<button type="button" class="button" id="rdbk-update-check" data-nonce="<?php echo esc_attr( wp_create_nonce( 'rdbk_check_update' ) ); ?>">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( 'Check for updates', 'rd-backup' ); ?>
					</button>
				</span>
				<h3 class="rdbk-self-update__title"><?php esc_html_e( 'Release status', 'rd-backup' ); ?></h3>
			</div>

			<dl class="rdbk-self-update__grid">
				<dt><?php esc_html_e( 'Current version', 'rd-backup' ); ?></dt>
				<dd><code><?php echo esc_html( $current ); ?></code></dd>

				<dt><?php esc_html_e( 'Latest version', 'rd-backup' ); ?></dt>
				<dd>
					<code id="rdbk-update-latest"><?php echo esc_html( '' !== $latest ? $latest : '—' ); ?></code>
					<span id="rdbk-update-status"><span class="rdbk-badge rdbk-badge--<?php echo esc_attr( $badge_variant ); ?>"><?php echo esc_html( $badge_text ); ?></span></span>
					<span id="rdbk-update-beta-badge"<?php echo $beta ? '' : ' hidden'; ?>><span class="rdbk-badge rdbk-badge--info"><?php esc_html_e( 'BETA', 'rd-backup' ); ?></span></span>
				</dd>

				<dt><?php esc_html_e( 'Last check', 'rd-backup' ); ?></dt>
				<dd id="rdbk-update-last-check"><?php echo esc_html( $last_check ); ?></dd>
			</dl>
			<?php
			echo '<p class="rdbk-self-update__action" id="rdbk-update-action">';
			if ( $has_update ) {
				printf(
					'<a class="button button-primary rdbk-update-now" href="%1$s"><span class="dashicons dashicons-update" aria-hidden="true"></span>%2$s</a>',
					esc_url( $updater->update_url() ),
					esc_html__( 'Update now', 'rd-backup' )
				);
				if ( '' !== $release_url ) {
					printf(
						'<a class="button-link" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( $release_url ),
						esc_html__( 'View release on GitHub', 'rd-backup' )
					);
				}
			}
			echo '</p>';
			?>
		</div>
		<?php
	}

	/**
	 * AJAX: save the "keep last N" retention setting (the Backup-tab select).
	 * Validates against the allowed choices before storing the option.
	 */
	public function ajax_save_retention(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd-backup' ) ), 403 );
		}
		check_ajax_referer( 'rdbk_save_retention', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$keep = isset( $_POST['keep'] ) ? (int) $_POST['keep'] : 0;
		if ( ! in_array( $keep, RDBK_Storage::RETENTION_CHOICES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid value.', 'rd-backup' ) ), 400 );
		}

		update_option( RDBK_Storage::RETENTION_OPTION, $keep );
		wp_send_json_success( array( 'keep' => $keep ) );
	}
}
