<?php
/**
 * Tab: Cola y logs.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$queue_table = "{$wpdb->prefix}atora_email_queue";
$event_table = "{$wpdb->prefix}atora_email_events";

$queue_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) === $queue_table;
$event_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $event_table ) ) === $event_table;

$pending_count = 0;
$failed_count  = 0;
$sent_today    = 0;
$recent_rows   = array();
$recent_events = array();

if ( $queue_exists ) {
	$pending_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$failed_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$sent_today    = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$queue_table} WHERE status = 'sent' AND DATE(sent_at) = %s",
			gmdate( 'Y-m-d' )
		)
	);
	$recent_rows = (array) $wpdb->get_results(
		"SELECT id, recipient_email, subject, status, provider, scheduled_at, sent_at
		 FROM {$queue_table}
		 ORDER BY id DESC
		 LIMIT 30"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

if ( $event_exists ) {
	$recent_events = (array) $wpdb->get_results(
		"SELECT id, queue_id, event_type, created_at
		 FROM {$event_table}
		 ORDER BY id DESC
		 LIMIT 30"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
?>
<h2><?php esc_html_e( 'Cola y logs', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Monitorea pendientes, enviados y fallos.', 'atora-lms' ); ?></p>
<p class="description"><?php esc_html_e( 'Las pruebas enviadas desde "Prueba de envío" se registran en esta cola para trazabilidad. Si el destinatario está asociado a un usuario ATORA, también se sincronizan con CRM.', 'atora-lms' ); ?></p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;max-width:780px;margin-bottom:14px;">
	<div style="border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;padding:10px;"><strong><?php echo esc_html( (string) $pending_count ); ?></strong><br><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></div>
	<div style="border:1px solid #dcfce7;background:#f0fdf4;border-radius:10px;padding:10px;"><strong><?php echo esc_html( (string) $sent_today ); ?></strong><br><?php esc_html_e( 'Enviados hoy', 'atora-lms' ); ?></div>
	<div style="border:1px solid #fee2e2;background:#fef2f2;border-radius:10px;padding:10px;"><strong><?php echo esc_html( (string) $failed_count ); ?></strong><br><?php esc_html_e( 'Fallidos', 'atora-lms' ); ?></div>
</div>

<p>
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm&tab=emails' ) ); ?>"><?php esc_html_e( 'Abrir CRM → Correos', 'atora-lms' ); ?></a>
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm&tab=inbox' ) ); ?>"><?php esc_html_e( 'Abrir bandeja unificada', 'atora-lms' ); ?></a>
</p>

<h3><?php esc_html_e( 'Últimos correos en cola', 'atora-lms' ); ?></h3>
<?php if ( empty( $recent_rows ) ) : ?>
	<p><?php esc_html_e( 'Sin registros de cola.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Destinatario', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Asunto', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Provider', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $recent_rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) absint( $row->id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_email( $row->recipient_email ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_text_field( $row->subject ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_key( $row->status ?? '' ) ); ?></td>
					<td><?php echo esc_html( strtoupper( (string) sanitize_key( $row->provider ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row->sent_at ?: $row->scheduled_at ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<h3 style="margin-top:18px;"><?php esc_html_e( 'Últimos eventos', 'atora-lms' ); ?></h3>
<?php if ( empty( $recent_events ) ) : ?>
	<p><?php esc_html_e( 'Sin eventos registrados.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Queue ID', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Evento', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $recent_events as $event ) : ?>
				<tr>
					<td><?php echo esc_html( (string) absint( $event->id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) absint( $event->queue_id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_key( $event->event_type ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_text_field( $event->created_at ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
