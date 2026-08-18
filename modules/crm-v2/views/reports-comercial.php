<?php
/**
 * Reportes comerciales CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$overview = is_array( $overview ?? null ) ? $overview : array();

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-kpi-grid">
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Leads', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $overview['leads_count'] ?? 0 ) ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Deals abiertos', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( number_format_i18n( absint( $overview['deals_open'] ?? 0 ) ) ); ?></div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Tasa conversión', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value" data-report-kpi="conversion">—</div></article>
	<article class="atora-crm-kpi-card"><div class="atora-crm-kpi-card__label"><?php esc_html_e( 'Valor pipeline', 'atora-lms' ); ?></div><div class="atora-crm-kpi-card__value"><?php echo esc_html( '$' . number_format_i18n( (float) ( $overview['pipeline_value'] ?? 0 ), 2 ) ); ?></div></article>
</section>

<section class="atora-crm-report-grid">
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Funnel de pipeline', 'atora-lms' ); ?></h3><canvas id="crm-report-funnel"></canvas></article>
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Tendencia de conversión', 'atora-lms' ); ?></h3><canvas id="crm-report-conversion"></canvas></article>
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Rendimiento de campañas', 'atora-lms' ); ?></h3><canvas id="crm-report-campaigns"></canvas></article>
	<article class="atora-crm-v2-panel"><h3><?php esc_html_e( 'Contactos por semana', 'atora-lms' ); ?></h3><canvas id="crm-report-contacts"></canvas></article>
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
	const conv = data.conversion_trend || { labels: [], rate: [] };
	const convNode = document.querySelector('[data-report-kpi="conversion"]');
	if (convNode && Array.isArray(conv.rate) && conv.rate.length) convNode.textContent = `${conv.rate[conv.rate.length - 1]}%`;
	new Chart(document.getElementById('crm-report-funnel'), { type:'bar', data:{ labels:(data.pipeline_funnel||{}).labels||[], datasets:[{ label:'Etapas', data:(data.pipeline_funnel||{}).counts||[], backgroundColor:COLORS.blue }] }, options:{ indexAxis:'y', responsive:true } });
	new Chart(document.getElementById('crm-report-conversion'), { type:'line', data:{ labels:conv.labels||[], datasets:[{ label:'Tasa %', data:conv.rate||[], borderColor:COLORS.green, backgroundColor:COLORS.teal }] }, options:{ responsive:true } });
	new Chart(document.getElementById('crm-report-campaigns'), { type:'bar', data:{ labels:(data.campaign_performance||{}).labels||[], datasets:[{ label:'Enviados', data:(data.campaign_performance||{}).sent||[], backgroundColor:COLORS.blue },{ label:'Abiertos', data:(data.campaign_performance||{}).opened||[], backgroundColor:COLORS.green },{ label:'Clics', data:(data.campaign_performance||{}).clicked||[], backgroundColor:COLORS.amber }] }, options:{ responsive:true } });
	new Chart(document.getElementById('crm-report-contacts'), { type:'bar', data:{ labels:(data.contacts_by_week||{}).labels||[], datasets:[{ label:'Leads', data:(data.contacts_by_week||{}).new_leads||[], backgroundColor:COLORS.purple },{ label:'Estudiantes', data:(data.contacts_by_week||{}).new_students||[], backgroundColor:COLORS.teal }] }, options:{ responsive:true, scales:{ x:{ stacked:false }, y:{ stacked:false } } } });
	document.getElementById('btn-export-csv')?.addEventListener('click', () => {
		const rows = [['dataset','label','value']];
		Object.entries(data).forEach(([key, dataset]) => {
			if (dataset && Array.isArray(dataset.labels)) {
				(dataset.labels || []).forEach((label, index) => rows.push([key, label, (dataset.counts || dataset.rate || dataset.sent || dataset.new_leads || dataset.risk_count || [])[index] ?? '']));
			}
		});
		const csv = rows.map(row => row.map(v => `"${String(v).replaceAll('"','""')}"`).join(',')).join('\n');
		const a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type:'text/csv;charset=utf-8;' }));
		a.download = 'atora-crm-reportes-comercial.csv';
		a.click();
	});
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
