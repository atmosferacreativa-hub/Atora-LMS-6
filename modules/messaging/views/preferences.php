<?php
/**
 * Mensajería → Preferencias de estudiantes — PT-4.5 (sprint 6.4.0)
 *
 * Busca un estudiante y muestra qué canales tiene activos y por qué
 * (consentimiento, teléfono, verificación), más sus últimos mensajes
 * en cola con el motivo si fallaron. Sin esto, soporte no puede
 * diagnosticar "no me llegó nada" — es exactamente la pregunta que
 * responde esta pantalla.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$search = sanitize_text_field( (string) wp_unslash( $_GET['student_q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$student = null;

if ( '' !== $search ) {
	$found = get_users( array(
		'search'         => '*' . $search . '*',
		'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
		'number'         => 1,
	) );
	$student = $found[0] ?? null;
}
?>
<h2><?php esc_html_e( 'Diagnóstico por estudiante', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Busca por nombre, usuario o correo para ver qué canales tiene activos y por qué no le llegó un mensaje.', 'atora-lms' ); ?></p>

<form method="get" style="margin-bottom:16px">
	<input type="hidden" name="page" value="atora-messaging">
	<input type="hidden" name="tab" value="preferences">
	<input type="text" name="student_q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Nombre, usuario o correo…', 'atora-lms' ); ?>" style="min-width:260px">
	<button type="submit" class="button"><?php esc_html_e( 'Buscar', 'atora-lms' ); ?></button>
</form>

<?php if ( '' !== $search && ! $student ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'No se encontró ningún estudiante con ese dato.', 'atora-lms' ); ?></p></div>
<?php elseif ( $student ) :
	$user_id          = (int) $student->ID;
	$phone            = trim( (string) get_user_meta( $user_id, 'atora_phone', true ) );
	$whatsapp_consent = (bool) get_user_meta( $user_id, 'atora_consent_whatsapp', true );
	$whatsapp_verified = class_exists( '\ATORA\Messaging\Preferences' ) && \ATORA\Messaging\Preferences::is_phone_verified( $user_id );
	$whatsapp_active  = class_exists( '\ATORA\Messaging\Preferences' ) && \ATORA\Messaging\Preferences::is_whatsapp_active( $user_id );
	$telegram_active  = (bool) get_user_meta( $user_id, 'atora_consent_telegram', true );
	$prefs            = class_exists( '\ATORA\Messaging\Preferences' ) ? \ATORA\Messaging\Preferences::get( $user_id ) : array( 'categories' => array(), 'frequency' => array(), 'dnd_start' => '', 'dnd_end' => '' );

	$whatsapp_reason = '';
	if ( ! $whatsapp_active ) {
		if ( '' === $phone ) {
			$whatsapp_reason = __( 'Sin número de teléfono cargado.', 'atora-lms' );
		} elseif ( ! $whatsapp_consent ) {
			$whatsapp_reason = __( 'No dio consentimiento para WhatsApp.', 'atora-lms' );
		} elseif ( ! $whatsapp_verified ) {
			$whatsapp_reason = __( 'Número sin verificar.', 'atora-lms' );
		}
	}
	?>
	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:8px;padding:16px;margin-bottom:16px">
		<h3 style="margin-top:0"><?php echo esc_html( $student->display_name ); ?> <span style="color:#64748b;font-weight:400;font-size:13px">(<?php echo esc_html( $student->user_email ); ?>)</span></h3>

		<table class="widefat striped" style="max-width:700px">
			<thead><tr><th><?php esc_html_e( 'Canal', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Motivo si está inactivo', 'atora-lms' ); ?></th></tr></thead>
			<tbody>
				<tr><td>Correo</td><td style="color:#166534;font-weight:600"><?php esc_html_e( 'Activo', 'atora-lms' ); ?></td><td>—</td></tr>
				<tr>
					<td>WhatsApp</td>
					<td style="color:<?php echo $whatsapp_active ? '#166534' : '#991b1b'; ?>;font-weight:600"><?php echo $whatsapp_active ? esc_html__( 'Activo', 'atora-lms' ) : esc_html__( 'Inactivo', 'atora-lms' ); ?></td>
					<td><?php echo esc_html( $whatsapp_reason ); ?></td>
				</tr>
				<tr>
					<td>Telegram</td>
					<td style="color:<?php echo $telegram_active ? '#166534' : '#991b1b'; ?>;font-weight:600"><?php echo $telegram_active ? esc_html__( 'Activo', 'atora-lms' ) : esc_html__( 'Inactivo', 'atora-lms' ); ?></td>
					<td><?php echo $telegram_active ? '—' : esc_html__( 'No vinculó su cuenta de Telegram.', 'atora-lms' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h4><?php esc_html_e( 'Categorías', 'atora-lms' ); ?></h4>
		<p>
			<?php foreach ( $prefs['categories'] as $cat => $enabled ) : ?>
				<span style="display:inline-block;margin:0 8px 4px 0;padding:2px 10px;border-radius:20px;font-size:12px;background:<?php echo $enabled ? '#dcfce7' : '#f1f5f9'; ?>;color:<?php echo $enabled ? '#166534' : '#64748b'; ?>">
					<?php echo esc_html( ucfirst( $cat ) ); ?> — <?php echo $enabled ? esc_html( $prefs['frequency'][ $cat ] ?? 'instant' ) : esc_html__( 'desactivada', 'atora-lms' ); ?>
				</span>
			<?php endforeach; ?>
		</p>
		<?php if ( $prefs['dnd_start'] || $prefs['dnd_end'] ) : ?>
			<p><?php echo esc_html( sprintf( __( 'Horario de no molestar: %1$s a %2$s', 'atora-lms' ), $prefs['dnd_start'], $prefs['dnd_end'] ) ); ?></p>
		<?php endif; ?>
	</div>

	<?php
	global $wpdb;
	$queue_table = "{$wpdb->prefix}atora_message_queue";
	$log_table   = "{$wpdb->prefix}atora_message_log";
	$queue_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $queue_table ) ) ) === $queue_table;
	$log_exists   = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $log_table ) ) ) === $log_table;
	$recent = array();

	if ( $queue_exists ) {
		$latest_log_join = '';
		$latest_log_cols = '';
		if ( $log_exists ) {
			$latest_log_join = "LEFT JOIN (
				SELECT ml1.queue_id, ml1.event_type, ml1.event_data
				FROM {$log_table} ml1
				INNER JOIN ( SELECT queue_id, MAX(id) AS max_id FROM {$log_table} GROUP BY queue_id ) ml2 ON ml2.max_id = ml1.id
			) ml ON ml.queue_id = q.id";
			$latest_log_cols = ', ml.event_type, ml.event_data';
		}

		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.id, q.channel, q.template_key, q.status, q.scheduled_at{$latest_log_cols}
				 FROM {$queue_table} q {$latest_log_join}
				 WHERE q.user_id = %d ORDER BY q.id DESC LIMIT 15",
				$user_id
			)
		);
	}
	?>
	<h3><?php esc_html_e( 'Últimos mensajes', 'atora-lms' ); ?></h3>
	<?php if ( empty( $recent ) ) : ?>
		<p style="color:#64748b"><?php esc_html_e( 'Sin mensajes en cola para este estudiante todavía.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php esc_html_e( 'Cuándo', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Canal', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Detalle', 'atora-lms' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $recent as $row ) :
				$detail = '';
				if ( 'failed' === $row->status && ! empty( $row->event_data ) ) {
					$decoded = json_decode( (string) $row->event_data, true );
					$detail  = is_array( $decoded ) ? (string) ( $decoded['reason'] ?? $decoded['message'] ?? '' ) : '';
				}
				?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( (string) $row->scheduled_at, 'd/m H:i' ) ); ?></td>
					<td><?php echo esc_html( (string) $row->template_key ); ?></td>
					<td><?php echo esc_html( (string) $row->channel ); ?></td>
					<td style="color:<?php echo 'failed' === $row->status ? '#991b1b' : ( 'sent' === $row->status || 'delivered' === $row->status ? '#166534' : '#64748b' ); ?>"><?php echo esc_html( (string) $row->status ); ?></td>
					<td><?php echo esc_html( $detail ?: '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php endif; ?>
