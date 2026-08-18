<?php
/**
 * CRM Observability dashboard.
 *
 * @package ATORA_LMS\CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para observabilidad.', 'atora-lms' ) );
}

global $wpdb;

$email_queue_table = "{$wpdb->prefix}atora_email_queue";
$message_queue_table = "{$wpdb->prefix}atora_message_queue";
$automation_queue_table = "{$wpdb->prefix}atora_automation_queue";

$table_exists = static function ( string $table_name ) use ( $wpdb ): bool {
	$like = $wpdb->esc_like( $table_name );
	return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table_name; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$has_email_queue      = $table_exists( $email_queue_table );
$has_message_queue    = $table_exists( $message_queue_table );
$has_automation_queue = $table_exists( $automation_queue_table );

$count = static function ( string $sql, bool $enabled = true ) use ( $wpdb ): int {
	if ( ! $enabled ) {
		return 0;
	}
	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$metrics = array(
	'emails_pending'        => $count( "SELECT COUNT(*) FROM {$email_queue_table} WHERE status = 'pending'", $has_email_queue ),
	'emails_sent_today'     => $count( $wpdb->prepare( "SELECT COUNT(*) FROM {$email_queue_table} WHERE status = 'sent' AND DATE(sent_at) = %s", gmdate( 'Y-m-d' ) ), $has_email_queue ),
	'emails_failed'         => $count( "SELECT COUNT(*) FROM {$email_queue_table} WHERE status = 'failed'", $has_email_queue ),
	'messages_failed'       => $count( "SELECT COUNT(*) FROM {$message_queue_table} WHERE status = 'failed'", $has_message_queue ),
	'messages_pending'      => $count( "SELECT COUNT(*) FROM {$message_queue_table} WHERE status = 'pending'", $has_message_queue ),
	'automation_failed'     => $count( "SELECT COUNT(*) FROM {$automation_queue_table} WHERE status = 'failed'", $has_automation_queue ),
);

$alerts = array();
$email_settings = (array) get_option( 'atora_email_engine_options', array() );
$wa_settings    = (array) get_option( 'atora_whatsapp_options', array() );
$tg_settings    = (array) get_option( 'atora_telegram_options', array() );

if ( ! $has_email_queue ) {
	$alerts[] = __( 'Tabla de cola de emails no disponible (atora_email_queue).', 'atora-lms' );
}
if ( ! $has_message_queue ) {
	$alerts[] = __( 'Tabla de cola de mensajería no disponible (atora_message_queue).', 'atora-lms' );
}
if ( ! $has_automation_queue ) {
	$alerts[] = __( 'Tabla de cola de automatizaciones no disponible (atora_automation_queue).', 'atora-lms' );
}
if ( empty( $email_settings['smtp_host'] ) && empty( $email_settings['sendgrid_api_key'] ) && empty( $email_settings['brevo_api_key'] ) ) {
	$alerts[] = __( 'SMTP/provider no configurado.', 'atora-lms' );
}
if ( empty( $email_settings['identity_academia_from_email'] ) ) {
	$alerts[] = __( 'Identidad Academia sin remitente.', 'atora-lms' );
}
if ( ! empty( $email_settings['imap_enabled'] ) && ! extension_loaded( 'imap' ) ) {
	$alerts[] = __( 'IMAP activo pero extensión IMAP no disponible.', 'atora-lms' );
}
if ( ! empty( $wa_settings['access_token'] ) && empty( $wa_settings['webhook_verify_token'] ) ) {
	$alerts[] = __( 'WhatsApp sin verify token.', 'atora-lms' );
}
if ( empty( $tg_settings['bot_token'] ) ) {
	$alerts[] = __( 'Telegram sin bot token.', 'atora-lms' );
}
if ( $metrics['emails_failed'] > 50 ) {
	$alerts[] = __( 'Cola de emails con demasiados fallos.', 'atora-lms' );
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Comunicaciones · Estado del sistema', 'atora-lms' ); ?></h1>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;max-width:980px;margin:14px 0;">
		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;"><strong><?php echo esc_html( $has_email_queue ? (string) $metrics['emails_pending'] : 'N/D' ); ?></strong><br><?php esc_html_e( 'Emails pendientes', 'atora-lms' ); ?></div>
		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;"><strong><?php echo esc_html( $has_email_queue ? (string) $metrics['emails_sent_today'] : 'N/D' ); ?></strong><br><?php esc_html_e( 'Emails enviados hoy', 'atora-lms' ); ?></div>
		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;"><strong><?php echo esc_html( $has_email_queue ? (string) $metrics['emails_failed'] : 'N/D' ); ?></strong><br><?php esc_html_e( 'Emails fallidos', 'atora-lms' ); ?></div>
		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;"><strong><?php echo esc_html( $has_message_queue ? (string) $metrics['messages_pending'] : 'N/D' ); ?></strong><br><?php esc_html_e( 'Mensajes pendientes', 'atora-lms' ); ?></div>
		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;"><strong><?php echo esc_html( $has_message_queue ? (string) $metrics['messages_failed'] : 'N/D' ); ?></strong><br><?php esc_html_e( 'Mensajes fallidos', 'atora-lms' ); ?></div>
		<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;"><strong><?php echo esc_html( $has_automation_queue ? (string) $metrics['automation_failed'] : 'N/D' ); ?></strong><br><?php esc_html_e( 'Automatizaciones fallidas', 'atora-lms' ); ?></div>
	</div>

	<h2><?php esc_html_e( 'Alertas', 'atora-lms' ); ?></h2>
	<?php if ( empty( $alerts ) ) : ?>
		<p><?php esc_html_e( 'Sin alertas críticas.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<ul>
			<?php foreach ( $alerts as $alert ) : ?>
				<li><?php echo esc_html( (string) $alert ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
