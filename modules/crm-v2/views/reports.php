<?php
/**
 * Reportes CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sales_report    = is_array( $sales_report ?? null ) ? $sales_report : array();
$academic_report = is_array( $academic_report ?? null ) ? $academic_report : array();
$sales_stages    = (array) ( $sales_report['stages'] ?? array() );
$sales_counts    = (array) ( $sales_report['stage_counts'] ?? array() );
$academic_stages = (array) ( $academic_report['stages'] ?? array() );
$academic_counts = (array) ( $academic_report['stage_counts'] ?? array() );

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h2><?php esc_html_e( 'Reportes comerciales y académicos', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'KPIs accionables para conversión, retención y operación docente.', 'atora-lms' ); ?></p>
	</div>

	<div class="atora-crm-v2-kpis">
		<article>
			<strong><?php echo esc_html( number_format_i18n( absint( $academic_report['risk_students'] ?? 0 ) ) ); ?></strong>
			<span><?php esc_html_e( 'Estudiantes en riesgo', 'atora-lms' ); ?></span>
		</article>
		<article>
			<strong><?php echo esc_html( number_format_i18n( absint( $academic_report['teaching_pending'] ?? 0 ) ) ); ?></strong>
			<span><?php esc_html_e( 'Tareas docentes pendientes', 'atora-lms' ); ?></span>
		</article>
		<article>
			<strong><?php echo esc_html( number_format_i18n( absint( $overview['deals_open'] ?? 0 ) ) ); ?></strong>
			<span><?php esc_html_e( 'Oportunidades abiertas', 'atora-lms' ); ?></span>
		</article>
		<article>
			<strong><?php echo esc_html( '$' . number_format_i18n( (float) ( $overview['pipeline_value'] ?? 0 ), 2 ) ); ?></strong>
			<span><?php esc_html_e( 'Valor potencial del pipeline', 'atora-lms' ); ?></span>
		</article>
	</div>
</section>

<section class="atora-crm-v2-panel">
	<h3><?php esc_html_e( 'Distribución del pipeline comercial', 'atora-lms' ); ?></h3>
	<table class="atora-crm-v2-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Etapa', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Contactos', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $sales_stages as $stage_key => $stage_data ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $stage_data['label'] ?? $stage_key ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( absint( $sales_counts[ $stage_key ] ?? 0 ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>

<section class="atora-crm-v2-panel">
	<h3><?php esc_html_e( 'Distribución de acompañamiento académico', 'atora-lms' ); ?></h3>
	<table class="atora-crm-v2-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Etapa', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estudiantes', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $academic_stages as $stage_key => $stage_data ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $stage_data['label'] ?? $stage_key ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( absint( $academic_counts[ $stage_key ] ?? 0 ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
