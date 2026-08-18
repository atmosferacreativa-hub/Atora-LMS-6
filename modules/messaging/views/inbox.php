<?php
/**
 * Mensajería Inbox tab.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$queue_table = "{$wpdb->prefix}atora_message_queue";
$log_table   = "{$wpdb->prefix}atora_message_log";
$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $queue_table ) ) ) === $queue_table;
$log_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $log_table ) ) ) === $log_table;
$rows = array();

if ( $exists ) {
	$allowed_channels = array( 'email', 'whatsapp', 'telegram', 'sms' );
	$allowed_statuses = array( 'pending', 'sending', 'sent', 'failed', 'delivered', 'read' );
	$channel_filter   = sanitize_key( (string) wp_unslash( $_GET['channel'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status_filter    = sanitize_key( (string) wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search_filter    = sanitize_text_field( (string) wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$where            = '1=1';
	$params           = array();

	if ( in_array( $channel_filter, $allowed_channels, true ) ) {
		$where    .= ' AND q.channel = %s';
		$params[] = $channel_filter;
	}
	if ( in_array( $status_filter, $allowed_statuses, true ) ) {
		$where    .= ' AND q.status = %s';
		$params[] = $status_filter;
	}
	if ( '' !== $search_filter ) {
		$where    .= ' AND q.template_key LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search_filter ) . '%';
	}

	// PT-6.3 (6.4.0): filtro por tipo — 'type' no es su propia columna,
	// va embebido en variables._message_type (ver Messaging_Router::enqueue()).
	$type_filter = sanitize_key( (string) wp_unslash( $_GET['msg_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' !== $type_filter ) {
		$where    .= ' AND q.variables LIKE %s';
		$params[] = '%"_message_type":"' . $wpdb->esc_like( $type_filter ) . '"%';
	}

	$has_priority_column = false !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SHOW COLUMNS FROM {$queue_table} LIKE %s",
			'priority'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$priority_select = $has_priority_column ? 'q.priority' : '30 AS priority';
	$latest_log_join = '';
	$latest_log_cols = '';

	if ( $log_exists ) {
		$latest_log_join = "LEFT JOIN (
			SELECT ml1.queue_id, ml1.event_type, ml1.event_data, ml1.created_at
			FROM {$log_table} ml1
			INNER JOIN (
				SELECT queue_id, MAX(id) AS max_id
				FROM {$log_table}
				GROUP BY queue_id
			) ml2 ON ml2.max_id = ml1.id
		) ml ON ml.queue_id = q.id";
		$latest_log_cols = ', ml.event_type, ml.event_data, ml.created_at AS log_created_at';
	}

	$sql = "SELECT q.id, q.user_id, q.channel, q.template_key, q.status, q.scheduled_at, q.sent_at, {$priority_select}{$latest_log_cols}
		FROM {$queue_table} q
		{$latest_log_join}
		WHERE {$where}
		ORDER BY q.id DESC
		LIMIT 80";

	$rows = ! empty( $params )
		? (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
?>
<h2><?php esc_html_e( 'Bandeja de mensajería', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Vista operativa de cola multicanal con prioridad, estado y último evento de trazabilidad.', 'atora-lms' ); ?></p>

<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm&tab=inbox' ) ); ?>"><?php esc_html_e( 'Abrir bandeja unificada CRM', 'atora-lms' ); ?></a></p>

<?php if ( ! $exists ) : ?>
	<p><?php esc_html_e( 'Tabla de mensajería no disponible.', 'atora-lms' ); ?></p>
<?php elseif ( empty( $rows ) ) : ?>
	<p><?php esc_html_e( 'No hay mensajes en cola.', 'atora-lms' ); ?></p>
<?php else : ?>
	<form method="get" style="margin:8px 0 14px;">
		<input type="hidden" name="page" value="atora-messaging">
		<input type="hidden" name="tab" value="inbox">
		<select name="channel">
			<option value=""><?php esc_html_e( 'Todos los canales', 'atora-lms' ); ?></option>
			<option value="email" <?php selected( sanitize_key( (string) wp_unslash( $_GET['channel'] ?? '' ) ), 'email' ); ?>>email</option>
			<option value="whatsapp" <?php selected( sanitize_key( (string) wp_unslash( $_GET['channel'] ?? '' ) ), 'whatsapp' ); ?>>whatsapp</option>
			<option value="telegram" <?php selected( sanitize_key( (string) wp_unslash( $_GET['channel'] ?? '' ) ), 'telegram' ); ?>>telegram</option>
			<option value="sms" <?php selected( sanitize_key( (string) wp_unslash( $_GET['channel'] ?? '' ) ), 'sms' ); ?>>sms</option>
		</select>
		<select name="status">
			<option value=""><?php esc_html_e( 'Todos los estados', 'atora-lms' ); ?></option>
			<?php foreach ( array( 'pending', 'sending', 'sent', 'failed', 'delivered', 'read' ) as $st ) : ?>
				<option value="<?php echo esc_attr( $st ); ?>" <?php selected( sanitize_key( (string) wp_unslash( $_GET['status'] ?? '' ) ), $st ); ?>><?php echo esc_html( $st ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="msg_type">
			<option value=""><?php esc_html_e( 'Todos los tipos', 'atora-lms' ); ?></option>
			<?php foreach ( array( 'assignment_graded', 'assignment_due_soon', 'submission_received', 'student_inactive', 'at_risk_flagged', 'improvement_plan_assigned', 'lesson_published', 'section_announcement', 'student_digest', 'teacher_digest' ) as $t ) : ?>
				<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type_filter, $t ); ?>><?php echo esc_html( $t ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( sanitize_text_field( (string) wp_unslash( $_GET['s'] ?? '' ) ) ); ?>" placeholder="<?php esc_attr_e( 'Buscar template…', 'atora-lms' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'atora-lms' ); ?></button>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Canal', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Template', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Prioridad', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Último evento', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Usuario', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$priority_weight = absint( $row->priority ?? 30 );
				$priority_label  = __( 'Media', 'atora-lms' );
				if ( $priority_weight <= 0 ) {
					$priority_label = __( 'Crítica', 'atora-lms' );
				} elseif ( $priority_weight <= 10 ) {
					$priority_label = __( 'Alta', 'atora-lms' );
				} elseif ( $priority_weight >= 50 ) {
					$priority_label = __( 'Baja', 'atora-lms' );
				}

				$event_label = '—';
				if ( ! empty( $row->event_type ) ) {
					$event_label = sanitize_key( (string) $row->event_type );
					$event_data  = json_decode( (string) ( $row->event_data ?? '' ), true );
					$event_data  = is_array( $event_data ) ? $event_data : array();
					$event_notes = array();
					if ( ! empty( $event_data['attempt_channel'] ) ) {
						$event_notes[] = sanitize_key( (string) $event_data['attempt_channel'] );
					}
					if ( ! empty( $event_data['applied_channel'] ) ) {
						$event_notes[] = '→ ' . sanitize_text_field( (string) $event_data['applied_channel'] );
					}
					if ( ! empty( $event_notes ) ) {
						$event_label .= ' (' . implode( ' ', $event_notes ) . ')';
					}
				}
				?>
				<tr>
					<td><?php echo esc_html( (string) absint( $row->id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_key( $row->channel ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_key( $row->template_key ?? '' ) ); ?></td>
					<td><?php echo esc_html( $priority_label ); ?></td>
					<td><?php echo esc_html( (string) sanitize_key( $row->status ?? '' ) ); ?></td>
					<td><?php echo esc_html( $event_label ); ?></td>
					<td><?php echo esc_html( (string) absint( $row->user_id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_text_field( $row->sent_at ?: $row->scheduled_at ) ); ?></td>
					<td>
						<?php if ( 'failed' === sanitize_key( (string) ( $row->status ?? '' ) ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=atora_retry_message&queue_id=' . absint( $row->id ?? 0 ) ), 'atora_retry_message_' . absint( $row->id ?? 0 ) ) ); ?>"><?php esc_html_e( 'Reintentar', 'atora-lms' ); ?></a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
