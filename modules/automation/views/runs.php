<?php
/**
 * Pestaña Recorridos (6.38.0): quién está en qué flujo, en qué paso, por qué terminó; reintento de los fallidos.
 *
 * @package ATORA_LMS\Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$run_status = isset( $_GET['run_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['run_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$statuses   = array(
	''          => __( 'Todos', 'atora-lms' ),
	'waiting'   => __( 'En espera', 'atora-lms' ),
	'running'   => __( 'Ejecutando', 'atora-lms' ),
	'completed' => __( 'Terminados', 'atora-lms' ),
	'exited'    => __( 'Salieron (objetivo)', 'atora-lms' ),
	'failed'    => __( 'Fallidos', 'atora-lms' ),
	'blocked'   => __( 'Bloqueados (bucle)', 'atora-lms' ),
);
$where = isset( $statuses[ $run_status ] ) && '' !== $run_status ? $wpdb->prepare( 'WHERE r.status = %s', $run_status ) : '';
$runs  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
	"SELECT r.*, a.name AS flow_name,
	        (SELECT COUNT(*) FROM {$wpdb->prefix}atora_automation_run_steps s WHERE s.run_id = r.id AND s.status = 'done') AS steps_done
	 FROM {$wpdb->prefix}atora_automation_runs r
	 LEFT JOIN {$wpdb->prefix}atora_automations a ON a.id = r.automation_id
	 {$where} ORDER BY r.id DESC LIMIT 200"
);
?>
<h2><?php esc_html_e( 'Recorridos', 'atora-lms' ); ?></h2>
<form method="get" style="margin-bottom:8px;">
	<input type="hidden" name="page" value="atora-automations">
	<input type="hidden" name="tab" value="runs">
	<label for="atora-run-status" class="screen-reader-text"><?php esc_html_e( 'Estado', 'atora-lms' ); ?></label>
	<select id="atora-run-status" name="run_status" onchange="this.form.submit()">
		<?php foreach ( $statuses as $value => $label ) : ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $run_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
</form>
<?php if ( ! $runs ) : ?>
	<p><?php esc_html_e( 'Sin recorridos.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Flujo (versión)', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Persona', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Paso', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Pasos hechos', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Próximo / fin', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Motivo', 'atora-lms' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $runs as $r ) : $person = (int) $r->user_id ? get_userdata( (int) $r->user_id ) : null; ?>
				<tr>
					<td><?php echo (int) $r->id; ?><?php echo (int) $r->is_test ? ' · ' . esc_html__( 'prueba', 'atora-lms' ) : ''; ?></td>
					<td><?php echo esc_html( (string) $r->flow_name . ' (v' . (int) $r->version . ')' ); ?></td>
					<td><?php echo esc_html( $person ? $person->display_name : sprintf( /* translators: %d: contact */ __( 'Contacto %d', 'atora-lms' ), (int) $r->contact_id ) ); ?></td>
					<td><?php echo esc_html( $statuses[ $r->status ] ?? $r->status ); ?></td>
					<td><code><?php echo esc_html( (string) $r->current_step ); ?></code></td>
					<td><?php echo (int) $r->steps_done; ?></td>
					<td><?php echo esc_html( (string) ( $r->finished_at ?: $r->wake_at ) ); ?> UTC</td>
					<td><?php echo esc_html( (string) $r->exit_reason ); ?></td>
					<td>
						<?php if ( 'failed' === $r->status ) : ?>
							<form method="post" style="margin:0;">
								<?php wp_nonce_field( 'atora_automation_admin_action', 'atora_automation_nonce' ); ?>
								<input type="hidden" name="atora_automation_action" value="retry_run">
								<input type="hidden" name="run_id" value="<?php echo (int) $r->id; ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Reintentar', 'atora-lms' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
