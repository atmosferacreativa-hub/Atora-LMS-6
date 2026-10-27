<?php
/**
 * Editor de flujos (6.38.0): lienzo con pasos y ramas, validación antes de activar, modo prueba con un contacto y
 * versionado (quien ya está dentro sigue su versión).
 *
 * Sin JavaScript se puede editar la definición como JSON (sección "Avanzado").
 *
 * @package ATORA_LMS\Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ATORA\Automation\Automation_Flows as Flows;
use ATORA\Automation\Automation_Triggers as Triggers;

global $wpdb;

$flow_id   = isset( $_GET['flow'] ) ? absint( $_GET['flow'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$notice    = null;
$sim_path  = null;
$test_runs = null;
$posted    = null;

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['atora_flow_op'] ) ) {
	check_admin_referer( 'atora_flow_editor', 'atora_flow_nonce' );
	$op     = sanitize_key( (string) wp_unslash( $_POST['atora_flow_op'] ) );
	$def    = json_decode( (string) wp_unslash( $_POST['definition'] ?? '' ), true );
	$entry  = json_decode( (string) wp_unslash( $_POST['conditions'] ?? '' ), true );
	$posted = array(
		'id'           => $flow_id,
		'name'         => (string) wp_unslash( $_POST['name'] ?? '' ),
		'description'  => (string) wp_unslash( $_POST['description'] ?? '' ),
		'trigger_type' => (string) wp_unslash( $_POST['trigger_type'] ?? '' ),
		'priority'     => absint( $_POST['priority'] ?? 10 ),
		'active'       => ! empty( $_POST['active'] ),
		'definition'   => is_array( $def ) ? $def : array(),
		'conditions'   => is_array( $entry ) ? $entry : array(),
	);
	if ( ! is_array( $def ) ) {
		$notice = array( 'error', __( 'La definición del flujo no es un JSON válido.', 'atora-lms' ) );
	} elseif ( 'save' === $op ) {
		$result = Flows::save( $posted );
		if ( is_wp_error( $result ) ) {
			$notice = array( 'error', __( 'No se activó: corrige estos puntos.', 'atora-lms' ) . ' ' . $result->get_error_message() );
		} else {
			$flow_id = (int) $result;
			$errors  = Flows::validate( array( 'version' => 2 ) + $posted['definition'] );
			$notice  = $errors
				? array( 'warning', __( 'Guardado como borrador. Para activarlo:', 'atora-lms' ) . ' ' . implode( ' ', $errors ) )
				: array( 'success', __( 'Flujo guardado. Quien ya estaba dentro sigue con la versión anterior.', 'atora-lms' ) );
			$posted  = null;
		}
	} elseif ( in_array( $op, array( 'simulate', 'test_run' ), true ) ) {
		$who  = trim( (string) wp_unslash( $_POST['test_contact'] ?? '' ) );
		$user = is_numeric( $who ) ? get_userdata( (int) $who ) : get_user_by( 'email', $who );
		$ctx  = json_decode( (string) wp_unslash( $_POST['test_context'] ?? '' ), true );
		$ctx  = is_array( $ctx ) ? $ctx : array();
		if ( ! $user ) {
			$notice = array( 'error', __( 'No se encontró ese usuario (ID o correo).', 'atora-lms' ) );
		} elseif ( 'simulate' === $op ) {
			$sim_path = Flows::simulate( array( 'version' => 2 ) + $posted['definition'], (int) $user->ID, 0, $ctx );
		} elseif ( ! $flow_id ) {
			$notice = array( 'error', __( 'Guarda el flujo antes de probarlo de verdad.', 'atora-lms' ) );
		} else {
			$trigger   = Triggers::canonical( (string) $wpdb->get_var( $wpdb->prepare( "SELECT trigger_type FROM {$wpdb->prefix}atora_automations WHERE id = %d", $flow_id ) ) );
			$test_runs = Flows::start( $trigger, (int) $user->ID, 0, $ctx, true, $flow_id );
			foreach ( $test_runs as $run_id ) {
				Flows::advance( $run_id );
			}
			$notice = array( 'success', __( 'Prueba ejecutada con ese contacto (las acciones se hicieron de verdad). Mira el resultado abajo.', 'atora-lms' ) );
		}
	}
}

$row  = $flow_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_automations WHERE id = %d", $flow_id ) ) : null;
$def  = $posted['definition'] ?? ( $row ? Flows::definition_from_row( $row ) : array(
	'version' => 2,
	'start'   => 'paso1',
	'steps'   => array( array( 'id' => 'paso1', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'welcome_account' ), 'next' => null ) ),
) );
$entry_conditions = $posted['conditions'] ?? ( $row ? (array) json_decode( (string) $row->conditions, true ) : array() );
$values           = array(
	'name'         => $posted['name'] ?? ( $row->name ?? '' ),
	'description'  => $posted['description'] ?? ( $row->description ?? '' ),
	'trigger_type' => Triggers::canonical( (string) ( $posted['trigger_type'] ?? ( $row->trigger_type ?? 'course_enrolled' ) ) ),
	'priority'     => $posted['priority'] ?? ( $row->priority ?? 10 ),
	'active'       => $posted['active'] ?? ! empty( $row->active ),
);
$versions = $flow_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$wpdb->prefix}atora_automation_versions WHERE automation_id = %d", $flow_id ) ) : 0;

$labels = array(
	'actions' => array(
		'send_email'            => __( 'Enviar correo', 'atora-lms' ),
		'inbox_message'         => __( 'Mensaje en el buzón', 'atora-lms' ),
		'teacher_task'          => __( 'Tarea al docente', 'atora-lms' ),
		'add_tag'               => __( 'Agregar etiqueta', 'atora-lms' ),
		'remove_tag'            => __( 'Quitar etiqueta', 'atora-lms' ),
		'move_pipeline_stage'   => __( 'Cambiar etapa del CRM', 'atora-lms' ),
		'webhook'               => __( 'Webhook', 'atora-lms' ),
		'send_whatsapp'         => __( 'Enviar WhatsApp', 'atora-lms' ),
		'send_telegram'         => __( 'Enviar Telegram', 'atora-lms' ),
		'enroll_course'         => __( 'Matricular en un curso', 'atora-lms' ),
		'start_email_sequence'  => __( 'Iniciar secuencia de correos', 'atora-lms' ),
		'set_custom_field'      => __( 'Cambiar campo del contacto', 'atora-lms' ),
		'internal_notification' => __( 'Aviso interno (buzón)', 'atora-lms' ),
	),
	'fields'  => array(
		'grade'            => __( 'Nota (del disparador)', 'atora-lms' ),
		'tag'              => __( 'Etiqueta (del disparador)', 'atora-lms' ),
		'course_id'        => __( 'Curso (del disparador)', 'atora-lms' ),
		'has_tag'          => __( 'Tiene la etiqueta', 'atora-lms' ),
		'risk_level'       => __( 'Nivel de riesgo (normal, medium, high)', 'atora-lms' ),
		'course_progress'  => __( 'Avance en el curso (%)', 'atora-lms' ),
		'course_completed' => __( 'Curso terminado', 'atora-lms' ),
		'submitted_lesson' => __( 'Entregó la tarea', 'atora-lms' ),
	),
	'ops'     => array(
		'equals'       => __( 'es igual a', 'atora-lms' ),
		'not_equals'   => __( 'es distinto de', 'atora-lms' ),
		'greater_than' => __( 'es mayor que', 'atora-lms' ),
		'less_than'    => __( 'es menor que', 'atora-lms' ),
		'contains'     => __( 'contiene', 'atora-lms' ),
		'is_true'      => __( 'es verdadero', 'atora-lms' ),
		'is_false'     => __( 'es falso', 'atora-lms' ),
	),
	'ui'      => array(
		'action'     => __( 'Acción', 'atora-lms' ),
		'wait'       => __( 'Espera', 'atora-lms' ),
		'condition'  => __( 'Condición', 'atora-lms' ),
		'end'        => __( 'Fin', 'atora-lms' ),
		'yes'        => __( 'Sí', 'atora-lms' ),
		'no'         => __( 'No', 'atora-lms' ),
		'goesTo'     => __( 'continúa en', 'atora-lms' ),
		'start'      => __( 'Inicio', 'atora-lms' ),
		'remove'     => __( 'Quitar', 'atora-lms' ),
		'setStart'   => __( 'Empezar aquí', 'atora-lms' ),
		'next'       => __( 'Después', 'atora-lms' ),
		'minutes'    => __( 'Minutos', 'atora-lms' ),
		'until'      => __( 'Hasta la fecha (UTC)', 'atora-lms' ),
		'untilField' => __( 'O hasta el campo del disparador', 'atora-lms' ),
		'offset'     => __( 'con desfase (min)', 'atora-lms' ),
		'addRule'    => __( 'Agregar regla', 'atora-lms' ),
		'all'        => __( 'Todas', 'atora-lms' ),
		'any'        => __( 'Alguna', 'atora-lms' ),
		'value'      => __( 'Valor', 'atora-lms' ),
		'field'      => __( 'Campo', 'atora-lms' ),
		'operator'   => __( 'Comparación', 'atora-lms' ),
		'unreached'  => __( 'Pasos que no se alcanzan desde el inicio', 'atora-lms' ),
	),
);
$templates   = class_exists( '\\ATORA\\EmailEngine\\Email_Templates' ) ? \ATORA\EmailEngine\Email_Templates::get_catalog() : array();
$editor_data = array(
	'definition' => $def,
	'entry'      => $entry_conditions,
	'labels'     => $labels,
	'templates'  => array_map( static fn( $t ) => (string) ( $t['name'] ?? '' ), $templates ),
);

wp_enqueue_style( 'atora-automation-flow', plugins_url( 'assets/css/atora-automation-flow.css', ATORA_LMS_FILE ), array(), ATORA_LMS_VERSION );
wp_enqueue_script( 'atora-automation-flow', plugins_url( 'assets/js/atora-automation-flow.js', ATORA_LMS_FILE ), array(), ATORA_LMS_VERSION, true );
wp_add_inline_script( 'atora-automation-flow', 'window.atoraFlowEditor = ' . wp_json_encode( $editor_data ) . ';', 'before' );

if ( $notice ) {
	printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
}
?>
<h2>
	<?php echo $flow_id ? esc_html__( 'Editar flujo', 'atora-lms' ) : esc_html__( 'Nuevo flujo', 'atora-lms' ); ?>
	<?php if ( $versions ) : ?>
		<small class="atora-flow-version"><?php echo esc_html( sprintf( /* translators: %d: version */ __( 'versión %d', 'atora-lms' ), $versions ) ); ?></small>
	<?php endif; ?>
</h2>

<form method="post" id="atora-flow-form" class="atora-flow-form" action="<?php echo esc_url( add_query_arg( array( 'page' => 'atora-automations', 'tab' => 'editor', 'flow' => $flow_id ?: false ), admin_url( 'admin.php' ) ) ); ?>">
	<?php wp_nonce_field( 'atora_flow_editor', 'atora_flow_nonce' ); ?>
	<div class="atora-flow-meta">
		<p><label for="atora-flow-name"><strong><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></strong></label><br>
			<input id="atora-flow-name" type="text" name="name" class="regular-text" required value="<?php echo esc_attr( (string) $values['name'] ); ?>"></p>
		<p><label for="atora-flow-trigger"><strong><?php esc_html_e( 'Disparador', 'atora-lms' ); ?></strong></label><br>
			<select id="atora-flow-trigger" name="trigger_type">
				<?php foreach ( Triggers::catalog() as $key => $trigger ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['trigger_type'], $key ); ?>><?php echo esc_html( $trigger['label'] ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="atora-flow-priority"><strong><?php esc_html_e( 'Prioridad', 'atora-lms' ); ?></strong></label><br>
			<input id="atora-flow-priority" type="number" min="1" max="99" name="priority" value="<?php echo esc_attr( (string) $values['priority'] ); ?>" style="width:80px"></p>
		<p><label><input type="checkbox" name="active" value="1" <?php checked( (bool) $values['active'] ); ?>> <?php esc_html_e( 'Activo (se valida antes de activar)', 'atora-lms' ); ?></label></p>
	</div>
	<p><label for="atora-flow-description"><strong><?php esc_html_e( 'Descripción', 'atora-lms' ); ?></strong></label><br>
		<textarea id="atora-flow-description" name="description" rows="2" class="large-text"><?php echo esc_textarea( (string) $values['description'] ); ?></textarea></p>

	<div class="atora-flow-columns">
		<section class="atora-flow-canvas-wrap" aria-labelledby="atora-flow-canvas-title">
			<h3 id="atora-flow-canvas-title"><?php esc_html_e( 'Lienzo', 'atora-lms' ); ?></h3>
			<div id="atora-flow-canvas" class="atora-flow-canvas" aria-live="polite"><noscript><?php esc_html_e( 'El lienzo necesita JavaScript; puedes editar el JSON en «Avanzado».', 'atora-lms' ); ?></noscript></div>
		</section>
		<section class="atora-flow-steps-wrap" aria-labelledby="atora-flow-steps-title">
			<h3 id="atora-flow-steps-title"><?php esc_html_e( 'Pasos', 'atora-lms' ); ?></h3>
			<div id="atora-flow-steps"></div>
			<p class="atora-flow-add">
				<button type="button" class="button" data-add="action"><?php esc_html_e( '+ Acción', 'atora-lms' ); ?></button>
				<button type="button" class="button" data-add="wait"><?php esc_html_e( '+ Espera', 'atora-lms' ); ?></button>
				<button type="button" class="button" data-add="condition"><?php esc_html_e( '+ Condición', 'atora-lms' ); ?></button>
			</p>
			<fieldset class="atora-flow-box">
				<legend><?php esc_html_e( 'Quién entra', 'atora-lms' ); ?></legend>
				<label><input type="checkbox" id="atora-flow-reentry"> <?php esc_html_e( 'Puede volver a entrar (al terminar el recorrido anterior)', 'atora-lms' ); ?></label>
				<p class="description"><?php esc_html_e( 'Condiciones de entrada (opcional):', 'atora-lms' ); ?></p>
				<div id="atora-flow-entry"></div>
			</fieldset>
			<fieldset class="atora-flow-box">
				<legend><?php esc_html_e( 'Objetivo (sale del flujo al cumplirse)', 'atora-lms' ); ?></legend>
				<div id="atora-flow-goal"></div>
			</fieldset>
		</section>
	</div>

	<details class="atora-flow-advanced">
		<summary><?php esc_html_e( 'Avanzado: definición en JSON', 'atora-lms' ); ?></summary>
		<p><label for="atora-flow-definition"><?php esc_html_e( 'Pasos', 'atora-lms' ); ?></label>
			<textarea id="atora-flow-definition" name="definition" rows="10" class="large-text code"><?php echo esc_textarea( (string) wp_json_encode( $def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea></p>
		<p><label for="atora-flow-conditions"><?php esc_html_e( 'Condiciones de entrada', 'atora-lms' ); ?></label>
			<textarea id="atora-flow-conditions" name="conditions" rows="3" class="large-text code"><?php echo esc_textarea( (string) wp_json_encode( $entry_conditions ? $entry_conditions : new stdClass(), JSON_UNESCAPED_UNICODE ) ); ?></textarea></p>
	</details>

	<p><button type="submit" class="button button-primary" name="atora_flow_op" value="save"><?php esc_html_e( 'Guardar flujo', 'atora-lms' ); ?></button></p>

	<fieldset class="atora-flow-box">
		<legend><?php esc_html_e( 'Modo prueba con un contacto', 'atora-lms' ); ?></legend>
		<p><label for="atora-flow-test-contact"><?php esc_html_e( 'Usuario (ID o correo)', 'atora-lms' ); ?></label><br>
			<input id="atora-flow-test-contact" type="text" name="test_contact" class="regular-text"></p>
		<p><label for="atora-flow-test-context"><?php esc_html_e( 'Datos del disparador (JSON, opcional)', 'atora-lms' ); ?></label><br>
			<input id="atora-flow-test-context" type="text" name="test_context" class="large-text code" placeholder='{"course_id": 12, "grade": 45}'></p>
		<p>
			<button type="submit" class="button" name="atora_flow_op" value="simulate" formnovalidate><?php esc_html_e( 'Simular (no ejecuta nada)', 'atora-lms' ); ?></button>
			<button type="submit" class="button" name="atora_flow_op" value="test_run" formnovalidate><?php esc_html_e( 'Ejecutar de verdad con este contacto', 'atora-lms' ); ?></button>
		</p>
		<?php if ( is_array( $sim_path ) ) : ?>
			<ol class="atora-flow-path">
				<?php foreach ( $sim_path as $hop ) : ?>
					<li><code><?php echo esc_html( $hop['step'] ); ?></code> — <?php echo esc_html( $hop['type'] . ': ' . $hop['detail'] ); ?></li>
				<?php endforeach; ?>
				<?php if ( ! $sim_path ) : ?>
					<li><?php esc_html_e( 'El flujo no tiene pasos alcanzables.', 'atora-lms' ); ?></li>
				<?php endif; ?>
			</ol>
		<?php endif; ?>
		<?php if ( is_array( $test_runs ) ) : ?>
			<ul>
				<?php foreach ( $test_runs as $run_id ) : $test_row = $wpdb->get_row( $wpdb->prepare( "SELECT status, current_step, exit_reason FROM {$wpdb->prefix}atora_automation_runs WHERE id = %d", $run_id ) ); ?>
					<li><?php echo esc_html( sprintf( /* translators: 1: run 2: status 3: step 4: reason */ __( 'Recorrido %1$d: %2$s (paso %3$s) %4$s', 'atora-lms' ), $run_id, $test_row->status ?? '', $test_row->current_step ?? '', $test_row->exit_reason ?? '' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</fieldset>
</form>
