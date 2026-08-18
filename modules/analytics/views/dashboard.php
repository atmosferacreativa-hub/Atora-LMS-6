<?php
/**
 * Analytics Dashboard — Fase IV S12
 *
 * 4 secciones con Chart.js: Email performance, Engagement, Revenue, Cohortes.
 * Datos via REST /atora/v1/analytics/*.
 *
 * @package ATORA_LMS\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para ver Analytics.', 'atora-lms' ) );
}

$rest_nonce  = wp_create_nonce( 'wp_rest' );
$api_base    = rest_url( 'atora/v1' );
$export_url  = admin_url( 'admin-ajax.php?action=atora_analytics_export&_wpnonce=' . wp_create_nonce( 'atora_analytics_export' ) );
$period      = absint( $_GET['period'] ?? 30 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$period      = in_array( $period, array( 7, 30, 90 ), true ) ? $period : 30;
?>
<div class="wrap atora-analytics-wrap" id="atora-analytics-app" style="font-family:sans-serif">

	<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap">
		<h1 style="margin:0;font-size:22px;font-weight:700;color:#0f172a"><?php esc_html_e( 'Analytics ATORA LMS', 'atora-lms' ); ?></h1>
		<select id="atora-analytics-period" style="padding:6px 12px;border-radius:7px;border:.5px solid #e2e8f0;font-size:13px">
			<option value="7"  <?php selected( $period, 7 ); ?>><?php esc_html_e( 'Últimos 7 días', 'atora-lms' ); ?></option>
			<option value="30" <?php selected( $period, 30 ); ?>><?php esc_html_e( 'Últimos 30 días', 'atora-lms' ); ?></option>
			<option value="90" <?php selected( $period, 90 ); ?>><?php esc_html_e( 'Últimos 90 días', 'atora-lms' ); ?></option>
		</select>
		<a href="<?php echo esc_url( $export_url . '&period=' . $period ); ?>"
		   class="button"
		   style="padding:6px 16px;background:#1d4ed8;color:#fff;border-radius:7px;border:none;font-size:13px;font-weight:600;text-decoration:none">
			⬇ <?php esc_html_e( 'Exportar CSV', 'atora-lms' ); ?>
		</a>
		<span id="atora-analytics-status" style="font-size:12px;color:#64748b"></span>
	</div>

	<!-- KPI Cards -->
	<div id="atora-kpi-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px">
		<div class="atora-kpi" data-key="emails_sent"    style="<?php echo esc_attr( atora_kpi_style() ); ?>"><div class="atora-kpi-label"><?php esc_html_e( 'Emails enviados', 'atora-lms' ); ?></div><div class="atora-kpi-val">—</div></div>
		<div class="atora-kpi" data-key="open_rate"      style="<?php echo esc_attr( atora_kpi_style() ); ?>"><div class="atora-kpi-label"><?php esc_html_e( 'Tasa apertura', 'atora-lms' ); ?></div><div class="atora-kpi-val">—</div></div>
		<div class="atora-kpi" data-key="click_rate"     style="<?php echo esc_attr( atora_kpi_style() ); ?>"><div class="atora-kpi-label"><?php esc_html_e( 'Tasa clic', 'atora-lms' ); ?></div><div class="atora-kpi-val">—</div></div>
		<div class="atora-kpi" data-key="revenue_total"  style="<?php echo esc_attr( atora_kpi_style() ); ?>"><div class="atora-kpi-label"><?php esc_html_e( 'Revenue total', 'atora-lms' ); ?></div><div class="atora-kpi-val">—</div></div>
	</div>

	<!-- Charts grid -->
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:20px">

		<!-- a. Email Performance -->
		<div class="atora-analytics-card" style="<?php echo esc_attr( atora_card_style() ); ?>">
			<h3 style="<?php echo esc_attr( atora_card_title_style() ); ?>"><?php esc_html_e( 'Email Performance', 'atora-lms' ); ?></h3>
			<canvas id="chart-email" height="200"></canvas>
		</div>

		<!-- b. Engagement -->
		<div class="atora-analytics-card" style="<?php echo esc_attr( atora_card_style() ); ?>">
			<h3 style="<?php echo esc_attr( atora_card_title_style() ); ?>"><?php esc_html_e( 'Distribución de Engagement', 'atora-lms' ); ?></h3>
			<canvas id="chart-engagement" height="200"></canvas>
		</div>

		<!-- c. Revenue -->
		<div class="atora-analytics-card" style="<?php echo esc_attr( atora_card_style() ); ?>">
			<h3 style="<?php echo esc_attr( atora_card_title_style() ); ?>"><?php esc_html_e( 'Revenue Mensual', 'atora-lms' ); ?></h3>
			<canvas id="chart-revenue" height="200"></canvas>
		</div>

		<!-- d. Retención de cohortes -->
		<div class="atora-analytics-card" style="<?php echo esc_attr( atora_card_style() ); ?>">
			<h3 style="<?php echo esc_attr( atora_card_title_style() ); ?>"><?php esc_html_e( 'Retención de Cohortes', 'atora-lms' ); ?></h3>
			<div id="cohort-table-wrap" style="overflow-x:auto;font-size:12px"></div>
		</div>

	</div>
</div>

<?php
function atora_kpi_style(): string {
	return 'background:#f8fafc;border:.5px solid #e2e8f0;border-radius:12px;padding:14px 16px;text-align:center';
}
function atora_card_style(): string {
	return 'background:#fff;border:.5px solid #e2e8f0;border-radius:14px;padding:18px 20px;box-shadow:0 1px 4px rgba(0,0,0,.05)';
}
function atora_card_title_style(): string {
	return 'font-size:14px;font-weight:700;color:#0f172a;margin:0 0 14px';
}
?>

<style>
.atora-kpi-label { font-size:11px;font-weight:600;text-transform:uppercase;color:#64748b;margin-bottom:6px }
.atora-kpi-val   { font-size:22px;font-weight:800;color:#0f172a }
</style>

<script>
(function(){
'use strict';
var NONCE   = <?php echo wp_json_encode( $rest_nonce ); ?>;
var BASE    = <?php echo wp_json_encode( rtrim( $api_base, '/' ) ); ?>;
var PERIOD  = <?php echo (int) $period; ?>;
var charts  = {};

function api(path) {
	return fetch(BASE + path + '?period=' + PERIOD, { headers: { 'X-WP-Nonce': NONCE } })
		.then(function(r){ return r.json(); });
}

function kpi(key, val) {
	var el = document.querySelector('[data-key="' + key + '"] .atora-kpi-val');
	if (el) el.textContent = val;
}

function mkChart(id, type, labels, datasets, opts) {
	var ctx = document.getElementById(id);
	if (!ctx) { return; }
	if (charts[id]) { charts[id].destroy(); }
	charts[id] = new Chart(ctx, { type: type, data: { labels: labels, datasets: datasets }, options: Object.assign({ responsive: true, plugins: { legend: { position: 'bottom' } } }, opts || {}) });
}

var COLORS = { blue:'#3b82f6', green:'#1d9e75', amber:'#f59e0b', red:'#e24b4a', purple:'#7c3aed', teal:'#0d9488' };

/* a. Email Performance */
api('/analytics/emails').then(function(d) {
	var w = d.weekly || d;
	var labels   = (w.labels   || []).map(function(l){ return String(l); });
	var sent     = w.sent      || [];
	var opened   = w.opened    || [];
	var clicked  = w.clicked   || [];

	kpi('emails_sent', sent.reduce(function(a,b){ return a + Number(b); }, 0).toLocaleString());
	var totalSent   = sent.reduce(function(a,b){ return a+Number(b); },0);
	var totalOpened = opened.reduce(function(a,b){ return a+Number(b); },0);
	var totalClick  = clicked.reduce(function(a,b){ return a+Number(b); },0);
	kpi('open_rate',  totalSent ? (totalOpened/totalSent*100).toFixed(1)+'%' : '—');
	kpi('click_rate', totalSent ? (totalClick/totalSent*100).toFixed(1)+'%'  : '—');

	mkChart('chart-email', 'line', labels, [
		{ label:'Enviados', data: sent,    borderColor: COLORS.blue,  backgroundColor: 'rgba(59,130,246,.1)', fill: true },
		{ label:'Abiertos', data: opened,  borderColor: COLORS.green, backgroundColor: 'rgba(29,158,117,.1)', fill: true },
		{ label:'Clics',    data: clicked, borderColor: COLORS.amber, backgroundColor: 'rgba(245,158,11,.1)',  fill: true },
	]);
}).catch(function(){ document.getElementById('chart-email').closest('.atora-analytics-card').insertAdjacentHTML('beforeend','<p style="color:#e24b4a;font-size:12px">Error al cargar email metrics.</p>'); });

/* b. Engagement */
api('/analytics/engagement').then(function(d) {
	var dist = d.score_distribution || d;
	var labels = dist.labels  || ['Alta (>70)','Media (40-70)','Baja (<40)'];
	var counts = dist.counts  || [0,0,0];
	mkChart('chart-engagement', 'doughnut', labels, [
		{ label:'Contactos', data: counts, backgroundColor: [COLORS.green, COLORS.amber, COLORS.red] }
	]);
}).catch(function(){});

/* c. Revenue */
api('/analytics/revenue').then(function(d) {
	var rev = d.monthly || d;
	var labels  = rev.labels  || [];
	var revenue = rev.revenue || rev.amounts || [];
	var total   = revenue.reduce(function(a,b){ return a+Number(b); }, 0);
	kpi('revenue_total', '$' + total.toLocaleString('es', {minimumFractionDigits:0}));
	mkChart('chart-revenue', 'bar', labels, [
		{ label:'Revenue', data: revenue, backgroundColor: COLORS.blue, borderRadius: 6 }
	]);
}).catch(function(){});

/* d. Cohortes */
api('/analytics/cohorts').then(function(d) {
	var cohorts = d.cohorts || d.rows || [];
	var wrap = document.getElementById('cohort-table-wrap');
	if (!wrap) { return; }
	if (!cohorts.length) { wrap.innerHTML = '<p style="color:#64748b">Sin datos de cohortes aún.</p>'; return; }
	var cols = Object.keys(cohorts[0] || {});
	var html = '<table style="border-collapse:collapse;width:100%"><thead><tr>';
	cols.forEach(function(c){ html += '<th style="padding:6px 10px;background:#1e3a8a;color:#fff;text-align:left;font-size:11px">' + c + '</th>'; });
	html += '</tr></thead><tbody>';
	cohorts.forEach(function(row, i){
		html += '<tr style="background:' + (i%2 ? '#f8fafc' : '#fff') + '">';
		cols.forEach(function(c){
			var v = row[c];
			var style = 'padding:5px 10px;border-bottom:.5px solid #e2e8f0;font-size:11px';
			if (typeof v === 'number' && v < 30) { style += ';color:#e24b4a;font-weight:600'; }
			html += '<td style="' + style + '">' + (v !== null && v !== undefined ? v : '—') + '</td>';
		});
		html += '</tr>';
	});
	html += '</tbody></table>';
	wrap.innerHTML = html;
}).catch(function(){});

/* Period selector */
document.getElementById('atora-analytics-period').addEventListener('change', function(){
	window.location.href = window.location.pathname + '?page=atora-analytics&period=' + this.value;
});

})();
</script>
