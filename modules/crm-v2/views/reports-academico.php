<?php
/**
 * Reportes académicos CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$academic_report = is_array( $academic_report ?? null ) ? $academic_report : array();

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-kpi-grid">
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Estudiantes en riesgo', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $academic_report['risk_students'] ?? 0 ) ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Tasa finalización', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value" data-report-kpi="completion">—</div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Tareas docentes', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $academic_report['teaching_pending'] ?? 0 ) ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Seguimientos activos', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $academic_report['total_followups'] ?? 0 ) ) ); ?></div></article>
</section>

<section class="atora-crm-report-grid">
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Distribución de etapas', 'atora-lms' ); ?></h3><canvas id="crm-report-academic-stages"></canvas></article>
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Cursos con más riesgo', 'atora-lms' ); ?></h3><canvas id="crm-report-courses-risk"></canvas></article>
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Actividad docente por semana', 'atora-lms' ); ?></h3><canvas id="crm-report-teaching"></canvas></article>
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Evolución del riesgo', 'atora-lms' ); ?></h3><canvas id="crm-report-risk-evolution"></canvas></article>
</section>

<div class="atora-crm-export-row">
	<button class="atora-crm-btn atora-crm-btn--outline" id="btn-export-csv"><?php esc_html_e( 'Exportar CSV', 'atora-lms' ); ?></button>
</div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
	const COLORS = { blue:'#378ADD', green:'#1D9E75', amber:'#BA7517', red:'#E24B4A', purple:'#7F77DD', teal:'#5DCAA5', muted:'rgba(136,135,128,0.3)' };
	const res = await fetch(`${atoraCrmV2.restBase}/reports/dashboard`, { headers: { 'X-WP-Nonce': atoraCrmV2.nonce } });
	const json = await res.json();
	const data = (json && json.data) || {};
	const risk = data.risk_distribution || {};
	const totalRisk = (risk.high || 0) + (risk.medium || 0) + (risk.normal || 0);
	const completionNode = document.querySelector('[data-report-kpi="completion"]');
	if (completionNode) completionNode.textContent = totalRisk ? `${Math.round(((risk.normal || 0) / totalRisk) * 100)}%` : '0%';
	new Chart(document.getElementById('crm-report-academic-stages'), { type:'doughnut', data:{ labels:(data.academic_stages||{}).labels||[], datasets:[{ label:'Etapas académicas', data:(data.academic_stages||{}).counts||[], backgroundColor:[COLORS.blue,COLORS.green,COLORS.amber,COLORS.red,COLORS.purple,COLORS.teal] }] } });
	new Chart(document.getElementById('crm-report-courses-risk'), { type:'bar', data:{ labels:(data.courses_risk||{}).labels||[], datasets:[{ label:'En riesgo', data:(data.courses_risk||{}).risk_count||[], backgroundColor:COLORS.red },{ label:'Total', data:(data.courses_risk||{}).total||[], backgroundColor:COLORS.muted }] }, options:{ indexAxis:'y', responsive:true } });
	new Chart(document.getElementById('crm-report-teaching'), { type:'line', data:{ labels:(data.teaching_activity_by_week||{}).labels||[], datasets:[{ label:'Calificación', data:(data.teaching_activity_by_week||{}).grading||[], borderColor:COLORS.blue },{ label:'Tutoría', data:(data.teaching_activity_by_week||{}).tutoring||[], borderColor:COLORS.green }] }, options:{ responsive:true } });
	new Chart(document.getElementById('crm-report-risk-evolution'), { type:'line', data:{ labels:(data.risk_evolution||{}).labels||[], datasets:[{ label:'High', data:(data.risk_evolution||{}).high||[], borderColor:COLORS.red, backgroundColor:'rgba(226,75,74,.2)', fill:true },{ label:'Medium', data:(data.risk_evolution||{}).medium||[], borderColor:COLORS.amber, backgroundColor:'rgba(186,117,23,.2)', fill:true }] }, options:{ responsive:true } });
	document.getElementById('btn-export-csv')?.addEventListener('click', () => {
		const rows = [['dataset','label','value']];
		Object.entries(data).forEach(([key, dataset]) => {
			if (dataset && Array.isArray(dataset.labels)) {
				(dataset.labels || []).forEach((label, index) => rows.push([key, label, (dataset.counts || dataset.risk_count || dataset.grading || dataset.high || [])[index] ?? '']));
			}
		});
		const csv = rows.map(row => row.map(v => `"${String(v).replaceAll('"','""')}"`).join(',')).join('\n');
		const a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type:'text/csv;charset=utf-8;' }));
		a.download = 'atora-crm-reportes-academico.csv';
		a.click();
	});
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
