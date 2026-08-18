<?php
/**
 * Automatizaciones Admin UI.
 *
 * @package ATORA_LMS\Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar automatizaciones.', 'atora-lms' ) );
}

global $wpdb;

// ── Helpers de Log (Fase 7) ───────────────────────────────────────────────
$log_table     = $wpdb->prefix . 'atora_automation_execution_log';
$has_log_table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $log_table ) ) ) === $log_table;
$log_entries   = array();
$log_total     = 0;
$log_filter_status = '';
$log_limit     = 30;
$log_offset    = 0;

if ( $has_log_table ) {
	$log_filter_status = sanitize_key( (string) ( $_GET['log_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$log_page          = max( 1, absint( $_GET['log_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$log_offset        = ( $log_page - 1 ) * $log_limit;
	$where             = '1=1';
	$params            = array();
	if ( $log_filter_status && in_array( $log_filter_status, array( 'success', 'failed', 'skipped' ), true ) ) {
		$where   .= ' AND l.status = %s';
		$params[] = $log_filter_status;
	}
	$count_sql = "SELECT COUNT(*) FROM {$log_table} l WHERE {$where}";
	$log_total = ! empty( $params )
		? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$list_sql    = "SELECT l.*, a.name AS automation_name FROM {$log_table} l LEFT JOIN {$wpdb->prefix}atora_automations a ON a.id = l.automation_id WHERE {$where} ORDER BY l.executed_at DESC LIMIT %d OFFSET %d";
	$list_params = array_merge( $params, array( $log_limit, $log_offset ) );
	$log_entries = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$kpis_24h = array( 'total' => 0, 'success' => 0, 'failed' => 0 );
if ( $has_log_table ) {
	$since = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS cnt FROM {$log_table} WHERE executed_at >= %s GROUP BY status", $since ), ARRAY_A );
	foreach ( $rows as $r ) {
		$s = sanitize_key( (string) ( $r['status'] ?? '' ) );
		$n = absint( $r['cnt'] ?? 0 );
		$kpis_24h['total'] += $n;
		if ( isset( $kpis_24h[ $s ] ) ) { $kpis_24h[ $s ] = $n; }
	}
}

$status = array(
	'type'    => '',
	'message' => '',
);

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['atora_automation_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	check_admin_referer( 'atora_automation_admin_action', 'atora_automation_nonce' );
	$action = sanitize_key( (string) wp_unslash( $_POST['atora_automation_action'] ) );

	if ( 'save_workflow' === $action ) {
		$name         = sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) );
		$description  = sanitize_textarea_field( (string) wp_unslash( $_POST['description'] ?? '' ) );
		$trigger_type = sanitize_key( (string) wp_unslash( $_POST['trigger_type'] ?? 'user_registered' ) );
		$priority     = max( 1, min( 99, absint( wp_unslash( $_POST['priority'] ?? 10 ) ) ) );
		$active       = ! empty( $_POST['active'] ) ? 1 : 0;
		$action_type  = sanitize_key( (string) wp_unslash( $_POST['action_type'] ?? 'send_email' ) );
		$template_raw = sanitize_text_field( (string) wp_unslash( $_POST['template'] ?? 'welcome_course' ) );
		$template     = sanitize_key( $template_raw );
		$delay        = absint( wp_unslash( $_POST['delay_minutes'] ?? 0 ) );
		$email_identity = sanitize_key( (string) wp_unslash( $_POST['email_identity'] ?? 'academia' ) );
		$message_type = sanitize_key( (string) wp_unslash( $_POST['message_type'] ?? 'marketing' ) );
		$message_priority = sanitize_key( (string) wp_unslash( $_POST['message_priority'] ?? 'medium' ) );
		$fallback_channels = sanitize_text_field( (string) wp_unslash( $_POST['fallback_channels'] ?? '' ) );
		$email_identity_fallback = sanitize_text_field( (string) wp_unslash( $_POST['email_identity_fallback'] ?? '' ) );
		$internal_message = sanitize_textarea_field( (string) wp_unslash( $_POST['internal_message'] ?? '' ) );
		$email_identity_fallback_list = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					array_map(
						'trim',
						explode( ',', $email_identity_fallback )
					)
				)
			)
		);

		if ( ! in_array( $message_priority, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			$message_priority = 'medium';
		}
		if ( ! in_array(
			$message_type,
			array( 'purchase', '2fa_code', 'grade', 'live_reminder_1h', 'live_reminder_24h', 'new_course', 'marketing', 'inactivity' ),
			true
		) ) {
			$message_type = 'marketing';
		}

		$action_payload = array(
			'type'           => $action_type,
			'template'       => $template,
			'delay_minutes'  => $delay,
			'email_identity' => $email_identity,
			'message_type'   => $message_type,
			'priority'       => $message_priority,
		);
		if ( in_array( $action_type, array( 'send_whatsapp', 'send_telegram' ), true ) ) {
			$action_payload['fallback_channels'] = $fallback_channels;
			$action_payload['email_identity_fallback'] = $email_identity_fallback_list;
		}
		if ( in_array( $action_type, array( 'add_tag', 'remove_tag' ), true ) ) {
			$action_payload['tag'] = sanitize_text_field( $template_raw );
		}
		if ( 'internal_notification' === $action_type ) {
			$action_payload['message'] = $internal_message ?: __( 'Notificación interna de automatización.', 'atora-lms' );
		}
		if ( 'start_email_sequence' === $action_type ) {
			$action_payload['sequence_id'] = absint( wp_unslash( $_POST['sequence_id'] ?? 0 ) );
		}
		if ( 'move_pipeline_stage' === $action_type ) {
			$action_payload['pipeline'] = sanitize_key( (string) wp_unslash( $_POST['pipeline'] ?? 'sales' ) );
			$action_payload['stage']    = sanitize_key( (string) wp_unslash( $_POST['stage']    ?? '' ) );
		}

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}atora_automations",
			array(
				'name'          => $name ?: __( 'Workflow sin nombre', 'atora-lms' ),
				'description'   => $description,
				'trigger_type'  => $trigger_type,
				'trigger_config'=> '{}',
				'conditions'    => '{}',
				'actions'       => wp_json_encode( array( $action_payload ) ),
				'active'        => $active,
				'priority'      => $priority,
				'created_at'    => current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s','%s','%s','%s','%s','%s','%d','%d','%s','%s' )
		);

		$status = array(
			'type'    => $inserted ? 'success' : 'error',
			'message' => $inserted ? __( 'Workflow guardado.', 'atora-lms' ) : __( 'No se pudo guardar el workflow.', 'atora-lms' ),
		);
	}

	if ( 'toggle_workflow' === $action ) {
		$id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$active = ! empty( $_POST['active'] ) ? 1 : 0;
		$updated = $wpdb->update(
			"{$wpdb->prefix}atora_automations",
			array( 'active' => $active, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		$status = array(
			'type'    => false !== $updated ? 'success' : 'error',
			'message' => false !== $updated ? __( 'Estado actualizado.', 'atora-lms' ) : __( 'No se pudo actualizar el estado.', 'atora-lms' ),
		);
	}
}

$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'workflows'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tabs = array(
	'workflows' => __( 'Workflows activos', 'atora-lms' ),
	'editor'    => __( 'Crear workflow', 'atora-lms' ),
	'templates' => __( 'Plantillas recomendadas', 'atora-lms' ),
	'queue'     => __( 'Cola', 'atora-lms' ),
	'logs'      => __( 'Logs', 'atora-lms' ),
	'errors'    => __( 'Errores', 'atora-lms' ),
);
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'workflows';
}

$automations = (array) $wpdb->get_results(
	"SELECT id, name, trigger_type, active, priority, updated_at
	 FROM {$wpdb->prefix}atora_automations
	 ORDER BY priority ASC, id DESC
	 LIMIT 200"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

$queue_rows = (array) $wpdb->get_results(
	"SELECT id, automation_id, user_id, status, execute_at, executed_at, retry_count
	 FROM {$wpdb->prefix}atora_automation_queue
	 ORDER BY id DESC
	 LIMIT 200"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

$presets = class_exists( '\ATORA\Automation\Automation_Engine' )
	? (array) \ATORA\Automation\Automation_Engine::get_preset_workflows()
	: array();

$logs_rows = $queue_rows;
$errors_rows = array_values(
	array_filter(
		$queue_rows,
		static function ( $row ): bool {
			return 'failed' === sanitize_key( (string) ( $row->status ?? '' ) );
		}
	)
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Automatizaciones ATORA', 'atora-lms' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Workflows configurables con prioridad, cola y trazabilidad operativa.', 'atora-lms' ); ?></p>

	<?php if ( ! empty( $status['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $status['type'] ? 'error' : 'success' ); ?> is-dismissible"><p><?php echo esc_html( (string) $status['message'] ); ?></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-automations&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:10px;padding:16px;">
		<?php if ( 'editor' === $tab ) : ?>
			<?php require __DIR__ . '/editor.php'; ?>
		<?php elseif ( in_array( $tab, array( 'logs', 'queue', 'errors' ), true ) ) : ?>
			<?php require __DIR__ . '/logs.php'; ?>
		<?php elseif ( 'templates' === $tab ) : ?>
			<h2><?php esc_html_e( 'Plantillas recomendadas', 'atora-lms' ); ?></h2>
			<?php if ( empty( $presets ) ) : ?>
				<p><?php esc_html_e( 'No hay presets disponibles.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Clave', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Trigger', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $presets as $key => $preset ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $key ); ?></code></td>
								<td><?php echo esc_html( (string) ( $preset['name'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $preset['trigger_type'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) count( (array) ( $preset['actions'] ?? array() ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php else : ?>
			<h2><?php esc_html_e( 'Workflows activos', 'atora-lms' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th>#</th><th><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Trigger', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Prioridad', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $automations ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Sin workflows registrados.', 'atora-lms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $automations as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) absint( $row->id ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) sanitize_text_field( $row->name ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) sanitize_key( $row->trigger_type ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) absint( $row->priority ?? 0 ) ); ?></td>
								<td><?php echo esc_html( ! empty( $row->active ) ? __( 'Activo', 'atora-lms' ) : __( 'Inactivo', 'atora-lms' ) ); ?></td>
								<td>
									<form method="post" style="margin:0;">
										<?php wp_nonce_field( 'atora_automation_admin_action', 'atora_automation_nonce' ); ?>
										<input type="hidden" name="atora_automation_action" value="toggle_workflow">
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) absint( $row->id ?? 0 ) ); ?>">
										<input type="hidden" name="active" value="<?php echo esc_attr( ! empty( $row->active ) ? '0' : '1' ); ?>">
										<button type="submit" class="button button-small"><?php echo esc_html( ! empty( $row->active ) ? __( 'Desactivar', 'atora-lms' ) : __( 'Activar', 'atora-lms' ) ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<?php if ( $has_log_table ) : ?>
<hr style="margin:2rem 0">
<div class="wrap atora-auto-log-wrap">
	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
		<h2 style="margin:0;font-size:18px"><?php esc_html_e( 'Log de ejecuciones', 'atora-lms' ); ?></h2>
		<div style="display:flex;gap:8px;flex-wrap:wrap;font-size:13px">
			<span style="padding:6px 12px;background:#eff6ff;border-radius:8px;color:#1d4ed8"><?php echo esc_html( sprintf( __( '%d ejecuciones en 24h', 'atora-lms' ), $kpis_24h['total'] ) ); ?></span>
			<span style="padding:6px 12px;background:#ecfdf5;border-radius:8px;color:#065f46"><?php echo esc_html( sprintf( __( '%d éxitos', 'atora-lms' ), $kpis_24h['success'] ) ); ?></span>
			<span style="padding:6px 12px;background:#fef2f2;border-radius:8px;color:#991b1b"><?php echo esc_html( sprintf( __( '%d fallos', 'atora-lms' ), $kpis_24h['failed'] ) ); ?></span>
		</div>
	</div>
	<form method="get" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
		<input type="hidden" name="page" value="<?php echo esc_attr( sanitize_key( (string) ( $_GET['page'] ?? '' ) ) ); ?>">
		<select name="log_status" class="regular-text" style="width:auto">
			<option value=""><?php esc_html_e( 'Todos los estados', 'atora-lms' ); ?></option>
			<option value="success"<?php selected( $log_filter_status, 'success' ); ?>><?php esc_html_e( 'Éxito', 'atora-lms' ); ?></option>
			<option value="failed"<?php selected( $log_filter_status, 'failed' ); ?>><?php esc_html_e( 'Fallidas', 'atora-lms' ); ?></option>
			<option value="skipped"<?php selected( $log_filter_status, 'skipped' ); ?>><?php esc_html_e( 'Omitidas', 'atora-lms' ); ?></option>
		</select>
		<input type="submit" class="button" value="<?php esc_attr_e( 'Filtrar', 'atora-lms' ); ?>">
		<button type="button" class="button" id="atora-auto-retry-failed" style="background:#fef3c7;border-color:#fcd34d;color:#92400e">
			<?php esc_html_e( 'Reintentar fallidas', 'atora-lms' ); ?>
		</button>
	</form>
	<?php if ( empty( $log_entries ) ) : ?>
		<p style="color:#64748b;font-size:13px"><?php esc_html_e( 'Sin ejecuciones para los filtros seleccionados.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="font-size:13px">
			<thead><tr>
				<th><?php esc_html_e( 'Automatización', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Trigger', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $log_entries as $entry ) :
					$status_val = sanitize_key( (string) ( $entry['status'] ?? '' ) );
					$status_css = array( 'success' => 'background:#d1fae5;color:#065f46', 'failed' => 'background:#fee2e2;color:#991b1b', 'skipped' => 'background:#e2e8f0;color:#475569' );
				?>
					<tr>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $entry['automation_name'] ?? '—' ) ) ); ?></td>
						<td><code><?php echo esc_html( sanitize_key( (string) ( $entry['trigger_type'] ?? '' ) ) ); ?></code></td>
						<td><code><?php echo esc_html( sanitize_key( (string) ( $entry['action_type'] ?? '' ) ) ); ?></code></td>
						<td><span style="padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600;<?php echo esc_attr( $status_css[ $status_val ] ?? '' ); ?>"><?php echo esc_html( strtoupper( $status_val ) ); ?></span></td>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $entry['executed_at'] ?? '' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
<script>
document.getElementById('atora-auto-retry-failed') && document.getElementById('atora-auto-retry-failed').addEventListener('click', function(){
	var btn = this; btn.disabled = true; btn.textContent = '<?php echo esc_js( __( 'Reintentando…', 'atora-lms' ) ); ?>';
	fetch(ajaxurl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},
		body:'action=atora_automation_retry_failed&_ajax_nonce=<?php echo esc_js( wp_create_nonce( 'atora_automation_admin' ) ); ?>'
	}).then(function(r){return r.json();}).then(function(d){
		if(d.success){alert(d.data.message||'<?php echo esc_js( __( 'Reintento iniciado.', 'atora-lms' ) ); ?>');window.location.reload();}
		else{alert('<?php echo esc_js( __( 'Error al reintentar.', 'atora-lms' ) ); ?>');btn.disabled=false;btn.textContent='<?php echo esc_js( __( 'Reintentar fallidas', 'atora-lms' ) ); ?>';}
	});
});
</script>
<?php endif; // $has_log_table ?>
