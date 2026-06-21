<?php
/**
 * Notifier — reports each automatic backup result (success/failure) to the
 * enabled channels: email (wp_mail) and/or Telegram (Bot API over HTTPS). Both
 * can be on at once. Best-effort: a failed notification never affects the
 * backup, which has already finished. Manual backups are not notified — their
 * result is already on screen. Portable: wp_mail + an HTTPS POST, no extra deps.
 *
 * @package RD_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backup-result notifications (email + Telegram).
 */
class RDBK_Notifier {

	const ON_OPTION       = 'rdbk_notify_on';             // Either failures or all.
	const EMAIL_ON_OPTION = 'rdbk_notify_email';          // Boolean flag.
	const EMAIL_TO_OPTION = 'rdbk_notify_email_to';
	const TG_ON_OPTION    = 'rdbk_notify_telegram';       // Boolean flag.
	const TG_TOKEN_OPTION = 'rdbk_notify_telegram_token'; // Secret token.
	const TG_CHAT_OPTION  = 'rdbk_notify_telegram_chat';

	/**
	 * Singleton instance.
	 *
	 * @var RDBK_Notifier|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_rdbk_save_notify', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_rdbk_test_notify', array( $this, 'ajax_test' ) );
	}

	/* ---- Settings getters ---------------------------------------------- */

	/**
	 * When to notify: 'all' (success + failure) or 'failures' (default).
	 */
	public function notify_on(): string {
		return 'all' === get_option( self::ON_OPTION, 'failures' ) ? 'all' : 'failures';
	}

	/**
	 * Whether the email channel is enabled.
	 */
	public function email_enabled(): bool {
		return (bool) get_option( self::EMAIL_ON_OPTION, false );
	}

	/**
	 * The notification recipient, falling back to the site admin email.
	 */
	public function email_to(): string {
		$to = (string) get_option( self::EMAIL_TO_OPTION, '' );
		return '' !== $to ? $to : (string) get_option( 'admin_email', '' );
	}

	/**
	 * Whether the Telegram channel is enabled.
	 */
	public function telegram_enabled(): bool {
		return (bool) get_option( self::TG_ON_OPTION, false );
	}

	/**
	 * The configured Telegram chat/channel ID.
	 */
	public function telegram_chat(): string {
		return (string) get_option( self::TG_CHAT_OPTION, '' );
	}

	/**
	 * Whether a Telegram token is stored — lets the UI show a "set" hint without
	 * ever exposing the secret itself.
	 */
	public function has_telegram_token(): bool {
		return '' !== (string) get_option( self::TG_TOKEN_OPTION, '' );
	}

	/* ---- Dispatch ------------------------------------------------------- */

	/**
	 * Notifies the enabled channels about an automatic backup result. Skips a
	 * success when "notify on" is failures-only. Best-effort.
	 */
	public function notify_backup_result( string $status, string $file, string $size, string $message ): void {
		$is_error = 'done' !== $status;
		if ( 'failures' === $this->notify_on() && ! $is_error ) {
			return;
		}
		$msg = $this->build_message( $is_error, $file, $size, $message );
		if ( $this->email_enabled() ) {
			$this->send_email( $this->email_to(), $msg['subject'], $msg['text'] );
		}
		if ( $this->telegram_enabled() ) {
			$this->send_telegram( (string) get_option( self::TG_TOKEN_OPTION, '' ), $this->telegram_chat(), $msg['text'] );
		}
	}

	/**
	 * Builds the subject + text for a result message.
	 *
	 * @return array{subject:string,text:string}
	 */
	private function build_message( bool $is_error, string $file, string $size, string $message ): array {
		$site = $this->site_label();
		$when = wp_date( 'Y-m-d H:i' );

		/* translators: %s: site name/host. */
		$header = sprintf( __( 'ReloadeD Backup — %s', 'rd-backup' ), $site );

		if ( $is_error ) {
			/* translators: %s: site name/host. */
			$subject = sprintf( __( '[%s] Automatic backup FAILED', 'rd-backup' ), $site );
			$lines   = array(
				'❌ ' . $header,
				__( 'Automatic backup FAILED', 'rd-backup' ),
			);
			if ( '' !== $message ) {
				$lines[] = $message;
			}
			$lines[] = $when;
		} else {
			/* translators: %s: site name/host. */
			$subject = sprintf( __( '[%s] Automatic backup OK', 'rd-backup' ), $site );
			$lines   = array(
				'✅ ' . $header,
				__( 'Automatic backup completed', 'rd-backup' ),
				trim( $file . ( '' !== $size ? ' (' . $size . ')' : '' ) ),
				$when,
			);
		}

		return array(
			'subject' => $subject,
			'text'    => implode( "\n", $lines ),
		);
	}

	private function site_label(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? (string) $host : (string) home_url();
	}

	/**
	 * Best-effort email send. Returns whether wp_mail accepted it.
	 */
	private function send_email( string $to, string $subject, string $body ): bool {
		if ( '' === $to ) {
			return false;
		}
		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Best-effort Telegram send via the Bot API. Returns whether it went through.
	 */
	private function send_telegram( string $token, string $chat, string $text ): bool {
		if ( '' === $token || '' === $chat ) {
			return false;
		}
		$resp = wp_remote_post(
			'https://api.telegram.org/bot' . $token . '/sendMessage',
			array(
				'timeout' => 15,
				'body'    => array(
					'chat_id' => $chat,
					'text'    => $text,
				),
			)
		);
		return ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp );
	}

	/* ---- AJAX ----------------------------------------------------------- */

	/**
	 * AJAX: save the notification settings. The Telegram token is only overwritten
	 * when a new value is submitted (the field renders blank), so saving the other
	 * fields never wipes a stored token.
	 */
	public function ajax_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd-backup' ) ), 403 );
		}
		check_ajax_referer( 'rdbk_notify', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$on = isset( $_POST['on'] ) ? sanitize_key( wp_unslash( $_POST['on'] ) ) : 'failures';
		update_option( self::ON_OPTION, 'all' === $on ? 'all' : 'failures' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$email_on = isset( $_POST['email_on'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['email_on'] ) );
		update_option( self::EMAIL_ON_OPTION, $email_on ? 1 : 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$email_to = isset( $_POST['email_to'] ) ? sanitize_email( wp_unslash( $_POST['email_to'] ) ) : '';
		update_option( self::EMAIL_TO_OPTION, $email_to );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$tg_on = isset( $_POST['telegram_on'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['telegram_on'] ) );
		update_option( self::TG_ON_OPTION, $tg_on ? 1 : 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$chat = isset( $_POST['telegram_chat'] ) ? sanitize_text_field( wp_unslash( $_POST['telegram_chat'] ) ) : '';
		update_option( self::TG_CHAT_OPTION, $chat );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$token = isset( $_POST['telegram_token'] ) ? sanitize_text_field( wp_unslash( $_POST['telegram_token'] ) ) : '';
		if ( '' !== $token ) {
			update_option( self::TG_TOKEN_OPTION, $token );
		}

		wp_send_json_success( array( 'hasToken' => $this->has_telegram_token() ) );
	}

	/**
	 * AJAX: send a test notification to the enabled channels (using the saved
	 * settings — save before testing).
	 */
	public function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rd-backup' ) ), 403 );
		}
		check_ajax_referer( 'rdbk_notify', 'nonce' );

		$site = $this->site_label();
		/* translators: %s: site name/host. */
		$subject = sprintf( __( '[%s] Test notification', 'rd-backup' ), $site );
		/* translators: %s: site name/host. */
		$text = '🔔 ' . sprintf( __( 'ReloadeD Backup — %s', 'rd-backup' ), $site ) . "\n"
			. __( 'Test notification — your settings work.', 'rd-backup' );

		$results = array();
		if ( $this->email_enabled() ) {
			$results[] = $this->send_email( $this->email_to(), $subject, $text );
		}
		if ( $this->telegram_enabled() ) {
			$results[] = $this->send_telegram( (string) get_option( self::TG_TOKEN_OPTION, '' ), $this->telegram_chat(), $text );
		}

		if ( empty( $results ) ) {
			wp_send_json_error( array( 'message' => __( 'Enable a channel and save before testing.', 'rd-backup' ) ), 400 );
		}

		wp_send_json_success( array( 'ok' => ! in_array( false, $results, true ) ) );
	}
}
