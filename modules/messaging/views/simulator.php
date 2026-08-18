<?php
/**
 * Mensajería → Simulador — PT-6.4 (sprint 6.4.0)
 *
 * "Elegir un tipo de evento y un usuario, y ver qué canal se
 * resolvería, con qué plantilla, y si pasaría el consentimiento —
 * sin enviar nada. Es la herramienta que evita el 80% de los tickets
 * de soporte." — la OT.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$types = array(
	'assignment_graded'   => __( 'Calificación publicada', 'atora-lms' ),
	'assignment_due_soon' => __( 'Tarea por vencer', 'atora-lms' ),
	'submission_received' => __( 'Entrega recibida (docente)', 'atora-lms' ),
	'student_inactive'    => __( 'Estudiante inactivo', 'atora-lms' ),
	'at_risk_flagged'     => __( 'Estudiante en riesgo (docente/coordinador)', 'atora-lms' ),
	'improvement_plan_assigned' => __( 'Plan de mejora asignado', 'atora-lms' ),
	'lesson_published'    => __( 'Lección publicada', 'atora-lms' ),
	'section_announcement' => __( 'Anuncio de sección', 'atora-lms' ),
);

$type_q = isset( $_GET['sim_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['sim_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$user_q = sanitize_text_field( (string) wp_unslash( $_GET['sim_user'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$user   = null;
$result = null;

if ( '' !== $user_q ) {
	$found = get_users( array( 'search' => '*' . $user_q . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ), 'number' => 1 ) );
	$user  = $found[0] ?? null;
}

if ( $user && isset( $types[ $type_q ] ) && class_exists( '\ATORA\Messaging\Messaging_Router' ) ) {
	$result = \ATORA\Messaging\Messaging_Router::simulate( (int) $user->ID, $type_q );
}
?>
<h2><?php esc_html_e( 'Simulador de envío', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Elige un tipo de aviso y un estudiante para ver exactamente qué pasaría — no se envía nada de verdad.', 'atora-lms' ); ?></p>

<form method="get" style="margin-bottom:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
	<input type="hidden" name="page" value="atora-messaging">
	<input type="hidden" name="tab" value="simulator">
	<select name="sim_type">
		<option value=""><?php esc_html_e( '— Tipo de aviso —', 'atora-lms' ); ?></option>
		<?php foreach ( $types as $key => $label ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type_q, $key ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<input type="text" name="sim_user" value="<?php echo esc_attr( $user_q ); ?>" placeholder="<?php esc_attr_e( 'Nombre, usuario o correo del destinatario…', 'atora-lms' ); ?>" style="min-width:240px">
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Simular', 'atora-lms' ); ?></button>
</form>

<?php if ( '' !== $user_q && ! $user ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'No se encontró ningún usuario con ese dato.', 'atora-lms' ); ?></p></div>
<?php elseif ( $result ) :
	$would_send = ! empty( $result['resolved_channel'] ) && $result['under_cap'] && ! $result['would_digest'];
	?>
	<div style="background:<?php echo $would_send ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $would_send ? '#86efac' : '#fca5a5'; ?>;border-radius:8px;padding:16px;max-width:640px">
		<h3 style="margin-top:0;color:<?php echo $would_send ? '#166534' : '#991b1b'; ?>">
			<?php
			if ( ! $result['academic_routing_enabled'] ) {
				esc_html_e( '⚠ El enrutamiento académico está apagado — nada de esto se enviaría hoy.', 'atora-lms' );
			} elseif ( $result['would_digest'] ) {
				esc_html_e( '📥 Se agruparía en el resumen, no se enviaría de inmediato.', 'atora-lms' );
			} elseif ( ! $result['resolved_channel'] ) {
				esc_html_e( '🚫 No se enviaría por ningún canal — sin consentimiento verificado en ninguno.', 'atora-lms' );
			} elseif ( ! $result['under_cap'] ) {
				esc_html_e( '⏸ Bloqueado por el tope de mensajes por destinatario (PT-3.7).', 'atora-lms' );
			} else {
				echo esc_html( sprintf( /* translators: %s: canal */ __( '✅ Se enviaría por %s.', 'atora-lms' ), strtoupper( (string) $result['resolved_channel'] ) ) );
			}
			?>
		</h3>

		<table class="widefat striped" style="background:#fff">
			<tbody>
				<tr><td><?php esc_html_e( 'Categoría', 'atora-lms' ); ?></td><td><?php echo esc_html( $result['category'] ?? __( 'transaccional (sin categoría)', 'atora-lms' ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Prioridad', 'atora-lms' ); ?></td><td><?php echo esc_html( $result['priority'] ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Canales candidatos (orden de la regla)', 'atora-lms' ); ?></td><td><?php echo esc_html( implode( ' → ', $result['candidate_channels'] ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Canales con consentimiento', 'atora-lms' ); ?></td><td><?php echo $result['consented_channels'] ? esc_html( implode( ', ', $result['consented_channels'] ) ) : esc_html__( 'ninguno', 'atora-lms' ); ?></td></tr>
				<tr>
					<td>WhatsApp</td>
					<td>
						<?php
						printf(
							'%s: %s · %s: %s · %s: %s',
							esc_html__( 'teléfono', 'atora-lms' ), $result['whatsapp']['phone'] ? '✅' : '❌',
							esc_html__( 'consentimiento', 'atora-lms' ), $result['whatsapp']['consent'] ? '✅' : '❌',
							esc_html__( 'verificado', 'atora-lms' ), $result['whatsapp']['verified'] ? '✅' : '❌'
						);
						?>
					</td>
				</tr>
				<tr><td><?php esc_html_e( 'Tope de destinatario (24h)', 'atora-lms' ); ?></td><td><?php echo $result['under_cap'] ? esc_html__( 'OK', 'atora-lms' ) : esc_html__( 'excedido', 'atora-lms' ); ?></td></tr>
			</tbody>
		</table>
		<p style="color:#64748b;font-size:12px;margin-top:10px"><?php esc_html_e( 'Esta simulación no envía ningún mensaje real.', 'atora-lms' ); ?></p>
	</div>
<?php endif; ?>
