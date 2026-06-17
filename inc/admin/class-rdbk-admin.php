<?php
/**
 * Admin page — Tools → RD Backup, with Backup / Restore / Health tabs and the
 * AJAX progress UI.
 *
 * In this scaffold the Backup tab runs the fake engine to exercise the
 * resumable loop; Restore is a placeholder; Health renders the preflight.
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
			__( 'RD Backup', 'rd-backup' ),
			__( 'RD Backup', 'rd-backup' ),
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
					'starting'    => __( 'Starting…', 'rd-backup' ),
					'working'     => __( 'Working…', 'rd-backup' ),
					'done'        => __( 'Done!', 'rd-backup' ),
					'cancelled'   => __( 'Cancelled.', 'rd-backup' ),
					'failed'      => __( 'Failed. Check the logs.', 'rd-backup' ),
					'noArchives'  => __( 'No archives yet.', 'rd-backup' ),
					'download'    => __( 'Download', 'rd-backup' ),
					'del'         => __( 'Delete', 'rd-backup' ),
					'confirmDel'  => __( 'Delete this file?', 'rd-backup' ),
					'dbDone'      => __( 'Dump complete:', 'rd-backup' ),
					'tables'      => __( 'tables', 'rd-backup' ),
					'rows'        => __( 'rows', 'rd-backup' ),
					'downloadSql' => __( 'Download database.sql', 'rd-backup' ),
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
		echo '<h1>' . esc_html__( 'RD Backup', 'rd-backup' ) . '</h1>';

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

		echo '<div class="rdbk-tab-content">';
		switch ( $active ) {
			case 'health':
				$this->render_health();
				break;
			case 'restore':
				$this->render_placeholder( __( 'Restore', 'rd-backup' ) );
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
		<p class="description">
			<?php esc_html_e( 'Scaffold stage: the button below runs a fake job (0→100%) to validate the resumable engine. Real backup phases arrive in the next releases.', 'rd-backup' ); ?>
		</p>

		<div class="rdbk-runner" id="rdbk-runner">
			<button type="button" class="button button-primary" id="rdbk-test-run">
				<?php esc_html_e( 'Test engine', 'rd-backup' ); ?>
			</button>
			<button type="button" class="button" id="rdbk-test-cancel" hidden>
				<?php esc_html_e( 'Cancel', 'rd-backup' ); ?>
			</button>

			<div class="rdbk-progress" hidden>
				<div class="rdbk-progress__track">
					<div class="rdbk-progress__bar" id="rdbk-progress-bar" style="width:0%"></div>
				</div>
				<p class="rdbk-progress__status" id="rdbk-progress-status" aria-live="polite"></p>
			</div>
		</div>

		<hr>

		<h2><?php esc_html_e( 'Backup store', 'rd-backup' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: absolute path to the store directory */
				esc_html__( 'Backups are stored in %s — outside the plugin and outside uploads. Files carry a random token and are only downloadable through an authenticated handler, never a direct URL.', 'rd-backup' ),
				'<code>' . esc_html( RDBK_Storage::instance()->dir() ) . '</code>'
			);
			?>
		</p>

		<p>
			<button type="button" class="button" id="rdbk-test-storage"><?php esc_html_e( 'Test storage', 'rd-backup' ); ?></button>
			<span id="rdbk-storage-msg" class="rdbk-inline-msg" aria-live="polite"></span>
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

		<h3><?php esc_html_e( 'Optional — nginx deny rule', 'rd-backup' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Defense in depth for nginx (including nginx-in-front setups like HestiaCP, where the .htaccess can be bypassed). Add this to the site config:', 'rd-backup' ); ?>
		</p>
		<pre class="rdbk-snippet"><?php echo esc_html( RDBK_Storage::instance()->nginx_rule() ); ?></pre>

		<hr>

		<h2><?php esc_html_e( 'Database dump', 'rd-backup' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Runs the resumable database dumper and writes database.sql to the store. Download it to verify the dump. This is the first piece of the real backup.', 'rd-backup' ); ?>
		</p>

		<p>
			<button type="button" class="button button-primary" id="rdbk-dbdump-run"><?php esc_html_e( 'Test DB dump', 'rd-backup' ); ?></button>
		</p>

		<div class="rdbk-progress" id="rdbk-dbdump-progress" hidden>
			<div class="rdbk-progress__track">
				<div class="rdbk-progress__bar" id="rdbk-dbdump-bar" style="width:0%"></div>
			</div>
			<p class="rdbk-progress__status" id="rdbk-dbdump-status" aria-live="polite"></p>
		</div>

		<div class="rdbk-db-result" id="rdbk-dbdump-result" hidden></div>
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

	private function render_placeholder( string $title ): void {
		echo '<p class="description">';
		printf(
			/* translators: %s: section name */
			esc_html__( '%s will be available in an upcoming release.', 'rd-backup' ),
			esc_html( $title )
		);
		echo '</p>';
	}

	private function render_health(): void {
		$checks = RDBK_Healthcheck::run();

		echo '<table class="widefat striped rdbk-health">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'rd-backup' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'rd-backup' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes', 'rd-backup' ) . '</th>';
		echo '</tr></thead><tbody>';

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

		echo '</tbody></table>';
	}
}
