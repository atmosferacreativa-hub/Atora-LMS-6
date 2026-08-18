<?php
/**
 * Mensajería → Estado — PT-6.1/6.2 (sprint 6.4.0)
 *
 * Estado de canales, plantillas aprobadas/pendientes, cola pendiente
 * y fallos recientes. "La falla más probable en producción, hoy sería
 * invisible" — la OT, sobre plantillas no aprobadas.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Guardar cambios de estado de plantillas.
if ( ! empty( $_POST['atora_template_status_nonce'] ) && check_admin_referer( 'atora_template_status', 'atora_template_status_nonce' ) ) {
	$submitted = isset( $_POST['template_status'] ) ? (array) wp_unslash( $_POST['template_status'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( $submitted as $key => $status ) {
		\ATORA\Messaging\Messaging_Router::set_template_approval( sanitize_key( (string) $key ), sanitize_key( (string) $status ) );
	}
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Estado de plantillas actualizado.', 'atora-lms' ) . '</p></div>';
}

global $wpdb;
$queue_table = "{$wpdb->prefix}atora_message_queue";
$has_queue   = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $queue_table ) ) ) === $queue_table;

$pending_count = 0;
$failed_recent = array();
$template_not_approved_count = 0;

if ( $has_queue ) {
	$pending_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status IN ('pending','sending')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$log_table  = "{$wpdb->prefix}atora_message_log";
	$has_log    = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $log_table ) ) ) === $log_table;

	$failed_recent = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		"SELECT id, user_id, channel, template_key, scheduled_at FROM {$queue_table} WHERE status = 'failed' ORDER BY id DESC LIMIT 10"
	);

	if ( $has_log ) {
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		$template_not_approved_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} WHERE event_type = 'template_not_approved' AND created_at >= %s",
				$since
			)
		);
	}
}

$whatsapp_connected = (bool) get_option( 'atora_whatsapp_token', '' ) || (bool) get_option( 'atora_whatsapp_phone_id', '' );
$telegram_connected = (bool) get_option( 'atora_telegram_bot_token', '' );
$template_map = class_exists( '\ATORA\Messaging\Messaging_Router' ) ? \ATORA\Messaging\Messaging_Router::get_template_approval_map() : array();
$approved_count = count( array_filter( $template_map, static fn( $s ) => 'approved' === $s ) );
?>
<h2><?php esc_html_e( 'Estado de mensajería', 'atora-lms' ); ?></h2>

<?php if ( $template_not_approved_count > 0 ) : ?>
<div class="notice notice-warning" style="padding:12px 16px">
	<p style="font-weight:600;margin:0 0 4px">
		<?php echo esc_html( sprintf(
			/* translators: %d: cantidad de mensajes */
			_n( '%d mensaje cayó a email esta semana por una plantilla de WhatsApp sin aprobar.', '%d mensajes cayeron a email esta semana por plantillas de WhatsApp sin aprobar.', $template_not_approved_count, 'atora-lms' ),
			$template_not_approved_count
		) ); ?>
	</p>
	<p style="margin:0"><?php esc_html_e( 'Es la falla más probable en producción — revisa el estado de plantillas más abajo.', 'atora-lms' ); ?></p>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0">
	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:8px;padding:14px">
		<div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700">WhatsApp</div>
		<div style="font-size:15px;font-weight:600;color:<?php echo $whatsapp_connected ? '#166534' : '#991b1b'; ?>"><?php echo $whatsapp_connected ? esc_html__( 'Conectado', 'atora-lms' ) : esc_html__( 'Sin configurar', 'atora-lms' ); ?></div>
	</div>
	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:8px;padding:14px">
		<div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700">Telegram</div>
		<div style="font-size:15px;font-weight:600;color:<?php echo $telegram_connected ? '#166534' : '#991b1b'; ?>"><?php echo $telegram_connected ? esc_html__( 'Conectado', 'atora-lms' ) : esc_html__( 'Sin configurar', 'atora-lms' ); ?></div>
	</div>
	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:8px;padding:14px">
		<div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700"><?php esc_html_e( 'Plantillas aprobadas', 'atora-lms' ); ?></div>
		<div style="font-size:15px;font-weight:600"><?php echo esc_html( $approved_count ); ?> / <?php echo esc_html( count( $template_map ) ); ?></div>
	</div>
	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:8px;padding:14px">
		<div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700"><?php esc_html_e( 'En cola', 'atora-lms' ); ?></div>
		<div style="font-size:15px;font-weight:600"><?php echo esc_html( $pending_count ); ?></div>
	</div>
</div>

<h3><?php esc_html_e( 'Plantillas de WhatsApp — catálogo de 6.4.0', 'atora-lms' ); ?></h3>
<p class="description"><?php esc_html_e( 'Marca cada una según su estado real en Meta Business. Ver docs/PLANTILLAS-WHATSAPP.md para el texto y las variables de cada plantilla.', 'atora-lms' ); ?></p>
<form method="post">
	<?php wp_nonce_field( 'atora_template_status', 'atora_template_status_nonce' ); ?>
	<table class="widefat striped" style="max-width:700px">
		<thead><tr><th><?php esc_html_e( 'Plantilla', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $template_map as $key => $status ) : ?>
			<tr>
				<td><code><?php echo esc_html( $key ); ?></code></td>
				<td>
					<select name="template_status[<?php echo esc_attr( $key ); ?>]">
						<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pendiente', 'atora-lms' ); ?></option>
						<option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Aprobada', 'atora-lms' ); ?></option>
						<option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Rechazada', 'atora-lms' ); ?></option>
					</select>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar estado', 'atora-lms' ); ?></button></p>
</form>

<h3><?php esc_html_e( 'Fallos recientes', 'atora-lms' ); ?></h3>
<?php if ( empty( $failed_recent ) ) : ?>
	<p style="color:#64748b"><?php esc_html_e( 'Sin fallos recientes.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped" style="max-width:700px">
		<thead><tr><th><?php esc_html_e( 'Cuándo', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Plantilla', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Canal', 'atora-lms' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $failed_recent as $row ) : ?>
			<tr>
				<td><?php echo esc_html( get_date_from_gmt( (string) $row->scheduled_at, 'd/m H:i' ) ); ?></td>
				<td><?php echo esc_html( (string) $row->template_key ); ?></td>
				<td><?php echo esc_html( (string) $row->channel ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=atora-messaging&tab=inbox&status=failed' ) ); ?>"><?php esc_html_e( 'Ver todos en la bandeja →', 'atora-lms' ); ?></a></p>
<?php endif; ?>
