<?php
/**
 * Hub académico CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kpis          = is_array( $kpis ?? null ) ? $kpis : array();
$mini_board    = is_array( $mini_board ?? null ) ? $mini_board : array();
$top_risk      = (array) ( $top_risk ?? array() );
$grading_tasks = (array) ( $grading_tasks['items'] ?? array() );

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-kpi-grid">
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Estudiantes en riesgo', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $kpis['risk_students'] ?? 0 ) ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Pendientes de calificación', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $kpis['grading_pending'] ?? 0 ) ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Tasa de finalización', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( (float) ( $kpis['completion_rate'] ?? 0 ), 1 ) . '%' ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Seguimientos vencidos', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $kpis['stale_followups'] ?? 0 ) ) ); ?></div></article>
</section>

<section class="atora-crm-hub-sections">
	<div class="atora-crm-v2-panel">
		<div class="atora-crm-v2-panel__head">
			<h2><?php esc_html_e( 'Mini board académico', 'atora-lms' ); ?></h2>
			<a class="atora-crm-v2-link" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-pipeline-academic' ) ); ?>"><?php esc_html_e( 'Ver tablero completo', 'atora-lms' ); ?></a>
		</div>
		<div class="atora-crm-mini-board">
			<?php foreach ( (array) ( $mini_board['stages'] ?? array() ) as $stage_key => $stage_data ) : ?>
				<div class="atora-crm-mini-board__col">
					<div class="atora-crm-mini-board__col-title"><?php echo esc_html( (string) ( $stage_data['label'] ?? $stage_key ) ); ?></div>
					<?php foreach ( (array) ( $mini_board['items'][ $stage_key ] ?? array() ) as $followup ) : ?>
						<?php $student = get_userdata( absint( $followup['user_id'] ?? 0 ) ); ?>
						<article class="atora-crm-mini-card">
							<div class="atora-crm-mini-card__name"><?php echo esc_html( $student instanceof WP_User ? $student->display_name : __( 'Estudiante', 'atora-lms' ) ); ?></div>
							<div class="atora-crm-mini-card__meta"><?php echo esc_html( (string) ( $followup['course_title'] ?? '' ) ); ?></div>
							<div class="atora-crm-mini-card__meta"><?php echo esc_html( sprintf( __( '%d%% progreso', 'atora-lms' ), absint( $followup['progress_percent'] ?? 0 ) ) ); ?></div>
							<span class="atora-crm-badge atora-crm-badge--<?php echo esc_attr( sanitize_key( (string) ( $followup['risk_level'] ?? 'normal' ) ) ); ?>"><?php echo esc_html( strtoupper( (string) ( $followup['risk_level'] ?? 'normal' ) ) ); ?></span>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<aside class="atora-crm-v2-panel atora-crm-v2-panel--side">
		<h3><?php esc_html_e( 'Prioridad académica', 'atora-lms' ); ?></h3>
		<h4><?php esc_html_e( 'Top riesgo', 'atora-lms' ); ?></h4>
		<ul class="atora-crm-v2-list">
			<?php foreach ( $top_risk as $followup ) : ?>
				<?php $student = get_userdata( absint( $followup['user_id'] ?? 0 ) ); ?>
				<li>
					<strong><?php echo esc_html( $student instanceof WP_User ? $student->display_name : __( 'Estudiante', 'atora-lms' ) ); ?></strong>
					<span><?php echo esc_html( (string) ( $followup['course_title'] ?? '' ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<h4><?php esc_html_e( 'Tareas docentes vencidas', 'atora-lms' ); ?></h4>
		<ul class="atora-crm-v2-list">
			<?php foreach ( $grading_tasks as $task ) : ?>
				<li>
					<strong><?php echo esc_html( (string) ( $task['title'] ?? '' ) ); ?></strong>
					<span><?php echo esc_html( (string) ( $task['due_at'] ?? '—' ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</aside>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
