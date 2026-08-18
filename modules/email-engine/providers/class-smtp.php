<?php
/**
 * ATORA LMS v5 — Provider SMTP genérico
 *
 * Usa wp_mail() con PHPMailer configurado vía action phpmailer_init.
 * Sirve como fallback universal cuando no hay proveedor API configurado.
 *
 * @package ATORA_LMS\EmailEngine\Providers
 * @since   5.0.0
 */

namespace ATORA\EmailEngine\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SMTP implements Provider_Interface {

	/**
	 * Overrides temporales por envío (from/reply-to).
	 *
	 * @var array<string,mixed>
	 */
	private static array $runtime_options = array();

	public static function get_name(): string { return 'SMTP Genérico'; }

	public static function send( string $to, string $subject, string $body_html, string $body_text, array $options = array() ): bool {
		// Configurar PHPMailer con los ajustes guardados.
		self::$runtime_options = $options;
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), 10, 1 );

		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply_to = sanitize_email( (string) ( $options['reply_to'] ?? '' ) );
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$sent    = wp_mail( $to, $subject, $body_html, $headers );

		remove_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), 10 );
		self::$runtime_options = array();

		return (bool) $sent;
	}

	public static function test_connection(): bool {
		$opts = get_option( 'atora_email_engine_options', array() );
		return ! empty( $opts['smtp_host'] ) && ! empty( $opts['smtp_user'] );
	}

	/**
	 * Configura PHPMailer con ajustes del panel de ATORA.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Instancia PHPMailer.
	 * @return void
	 */
	public static function configure_phpmailer( $mailer ): void {
		$opts = get_option( 'atora_email_engine_options', array() );
		$opts = is_array( $opts ) ? $opts : array();
		$overrides = self::$runtime_options;
		$overrides = is_array( $overrides ) ? $overrides : array();

		$smtp_host = sanitize_text_field( (string) ( $overrides['smtp_host'] ?? $opts['smtp_host'] ?? '' ) );
		$smtp_port = absint( $overrides['smtp_port'] ?? $opts['smtp_port'] ?? 587 );
		if ( $smtp_port <= 0 ) {
			$smtp_port = 587;
		}
		$smtp_user = sanitize_text_field( (string) ( $overrides['smtp_user'] ?? $opts['smtp_user'] ?? '' ) );
		$smtp_pass = (string) ( $overrides['smtp_pass'] ?? $opts['smtp_pass'] ?? '' );
		$smtp_auth = isset( $overrides['smtp_auth'] )
			? ! empty( $overrides['smtp_auth'] )
			: ! empty( $opts['smtp_auth'] );
		if ( ! $smtp_auth && '' !== $smtp_user ) {
			$smtp_auth = true;
		}
		$smtp_encryption = sanitize_key( (string) ( $overrides['smtp_encryption'] ?? $opts['smtp_encryption'] ?? '' ) );
		if ( ! in_array( $smtp_encryption, array( 'tls', 'ssl' ), true ) ) {
			$smtp_encryption = '';
		}

		if ( '' === $smtp_host ) {
			return;
		}

		$mailer->isSMTP();
		$mailer->Host       = $smtp_host;
		$mailer->Port       = $smtp_port;
		$mailer->SMTPAuth   = $smtp_auth;
		$mailer->Username   = $smtp_user;
		$mailer->Password   = $smtp_pass;
		$mailer->SMTPSecure = $smtp_encryption;

		$from_email = sanitize_email( (string) ( $overrides['from_email'] ?? $opts['from_email'] ?? get_option( 'admin_email' ) ) );
		if ( ! $from_email || ! is_email( $from_email ) ) {
			$from_email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		$from_name  = sanitize_text_field( (string) ( $overrides['from_name'] ?? $opts['from_name'] ?? get_bloginfo( 'name' ) ) );
		$reply_to   = sanitize_email( (string) ( $overrides['reply_to'] ?? '' ) );

		$mailer->setFrom( $from_email, $from_name );
		if ( $reply_to && is_email( $reply_to ) ) {
			$mailer->addReplyTo( $reply_to );
		}
	}
}
