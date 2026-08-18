<?php
/**
 * Hub comercial CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kpis             = is_array( $kpis ?? null ) ? $kpis : array();
$mini_board       = is_array( $mini_board ?? null ) ? $mini_board : array();
$urgent_tasks     = (array) ( $urgent_tasks['items'] ?? array() );
$recent_campaigns = (array) ( $recent_campaigns ?? array() );

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-kpi-grid">
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Leads activos hoy', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $kpis['leads_active'] ?? 0 ) ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Valor del pipeline', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( '$' . number_format_i18n( (float) ( $kpis['pipeline_value'] ?? 0 ), 2 ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Conversión 30 días', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( (float) ( $kpis['conversion_rate'] ?? 0 ), 1 ) . '%' ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Tareas vencidas', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $kpis['overdue_tasks'] ?? 0 ) ) ); ?></div></article>
</section>

<section class="atora-crm-hub-sections">
	<div class="atora-crm-v2-panel">
		<div class="atora-crm-v2-panel__head">
			<h2><?php esc_html_e( 'Mini pipeline comercial', 'atora-lms' ); ?></h2>
			<a class="atora-crm-v2-link" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-pipeline-sales' ) ); ?>"><?php esc_html_e( 'Ver pipeline completo', 'atora-lms' ); ?></a>
		</div>
		<div class="atora-crm-mini-board">
			<?php foreach ( (array) ( $mini_board['stages'] ?? array() ) as $stage_key => $stage_data ) : ?>
				<div class="atora-crm-mini-board__col">
					<div class="atora-crm-mini-board__col-title"><?php echo esc_html( (string) ( $stage_data['label'] ?? $stage_key ) ); ?></div>
					<?php foreach ( (array) ( $mini_board['items'][ $stage_key ] ?? array() ) as $deal ) : ?>
						<article class="atora-crm-mini-card">
							<div class="atora-crm-mini-card__name"><?php echo esc_html( (string) ( $deal['title'] ?? __( 'Lead sin nombre', 'atora-lms' ) ) ); ?></div>
							<div class="atora-crm-mini-card__meta"><?php echo esc_html( get_the_title( absint( $deal['course_id'] ?? 0 ) ) ?: __( 'Sin curso asociado', 'atora-lms' ) ); ?></div>
							<div class="atora-crm-mini-card__meta"><?php echo esc_html( strtoupper( (string) ( $deal['temperature'] ?? 'warm' ) ) ); ?></div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<aside class="atora-crm-v2-panel atora-crm-v2-panel--side">
		<h3><?php esc_html_e( 'Acciones rápidas', 'atora-lms' ); ?></h3>
		<form method="post" class="atora-crm-v2-form-stack">
			<?php wp_nonce_field( 'atora_crm_v2_ui_action', 'atora_crm_v2_nonce' ); ?>
			<input type="hidden" name="atora_crm_v2_form_action" value="create_lead_quick">
			<input type="text" name="lead_name" placeholder="<?php esc_attr_e( 'Nombre del lead', 'atora-lms' ); ?>">
			<input type="email" name="lead_email" placeholder="<?php esc_attr_e( 'Email', 'atora-lms' ); ?>">
			<input type="text" name="lead_interest" placeholder="<?php esc_attr_e( 'Curso de interés', 'atora-lms' ); ?>">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Crear lead rápido', 'atora-lms' ); ?></button>
		</form>

		<h4><?php esc_html_e( 'Tareas comerciales urgentes', 'atora-lms' ); ?></h4>
		<ul class="atora-crm-v2-list">
			<?php foreach ( $urgent_tasks as $task ) : ?>
				<li>
					<strong><?php echo esc_html( (string) ( $task['title'] ?? '' ) ); ?></strong>
					<span><?php echo esc_html( (string) ( $task['due_at'] ?? '—' ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<h4><?php esc_html_e( 'Campañas recientes', 'atora-lms' ); ?></h4>
		<ul class="atora-crm-v2-list">
			<?php foreach ( $recent_campaigns as $campaign ) : ?>
				<li>
					<strong><?php echo esc_html( (string) ( $campaign['name'] ?? '' ) ); ?></strong>
					<span><?php echo esc_html( strtoupper( (string) ( $campaign['status'] ?? 'draft' ) ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</aside>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
