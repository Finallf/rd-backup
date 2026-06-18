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

	public function enqueue( string $hook ): void {
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rdbk_runner' ),
				'i18n'    => array(
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
					'warningsLbl'      => __( 'Warnings', 'rd-backup' ),
					'noWarnings'       => __( 'No compatibility warnings.', 'rd-backup' ),
					'restoreWarnTitle' => __( 'Heads up:', 'rd-backup' ),
					'restoreWarn'      => __( 'This overwrites the current database. A full safety backup is taken first. You will be signed out when it finishes (the restore replaces the users table) — just log back in.', 'rd-backup' ),
					'typeRestore'      => __( 'Type RESTORE to confirm:', 'rd-backup' ),
					'restoreBtn'       => __( 'Restore this backup', 'rd-backup' ),
					'safetyBackup'     => __( 'Creating safety backup…', 'rd-backup' ),
					'restoring'        => __( 'Restoring…', 'rd-backup' ),
					'restoreDone'      => __( 'Restore complete. You may need to log in again — reload the page to see the restored site.', 'rd-backup' ),
					'confirmReset'     => __( 'Clear the current job state? This does not touch your backups.', 'rd-backup' ),
					'resetDone'        => __( 'Job state cleared.', 'rd-backup' ),
					'uploadPick'       => __( 'Choose a .zip first.', 'rd-backup' ),
					'uploadZipOnly'    => __( 'Only .zip backups can be uploaded.', 'rd-backup' ),
					'uploading'        => __( 'Uploading…', 'rd-backup' ),
					'uploadDone'       => __( 'Uploaded — refreshing…', 'rd-backup' ),
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
			'backup'  => __( 'Backup', 'rd-backup' ),
			'restore' => __( 'Restore', 'rd-backup' ),
			'health'  => __( 'Health', 'rd-backup' ),
		);
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'backup';
		}

		echo '<div class="wrap rdbk-wrap">';

		echo '<div class="rdbk-panel-header">';
		echo '<h1 class="rdbk-panel-title">' . esc_html__( 'ReloadeD Backup', 'rd-backup' ) . '</h1>';
		// Logo slot — renders once the .webp is dropped at assets/img/logo-rdbk-panel.webp.
		if ( file_exists( RDBK_PLUGIN_DIR . 'assets/img/logo-rdbk-panel.webp' ) ) {
			printf(
				'<img class="rdbk-panel-logo" src="%s" alt="%s">',
				esc_url( RDBK_PLUGIN_URL . 'assets/img/logo-rdbk-panel.webp' ),
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
					<p>
						<input type="file" id="rdbk-upload-file" accept=".zip">
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

	private function render_health(): void {
		$checks = RDBK_Healthcheck::run();
		?>
		<div class="rdbk-section-header">
			<span class="dashicons dashicons-heart" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Health', 'rd-backup' ); ?></h2>
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
}
