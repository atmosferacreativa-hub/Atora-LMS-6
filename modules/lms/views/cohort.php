<?php
/**
 * Vista de cohorte — Fase II S6
 *
 * Tabla de estudiantes de un curso con filtros JS y acciones por fila.
 * Todos los datos vienen de atora_enrollments + atora_lesson_progress (sin usermeta/postmeta).
 *
 * Variables esperadas: $course_id (int), $course_title (string)
 *
 * @package ATORA_LMS\LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$course_id    = absint( $course_id ?? 0 );
$course_title = sanitize_text_field( (string) ( $course_title ?? __( 'Curso', 'atora-lms' ) ) );
$rest_nonce   = wp_create_nonce( 'wp_rest' );
$cohort_url   = rest_url( 'atora-lms/v1/courses/' . $course_id . '/cohort' );
$tag_url      = rest_url( 'atora-crm/v2/contacts/' );
?>
<div class="wrap atora-cohort-wrap" id="atora-cohort-app">
	<h1><?php echo esc_html( sprintf( __( 'Cohorte: %s', 'atora-lms' ), $course_title ) ); ?></h1>

	<!-- Filtros -->
	<div class="atora-cohort-filters" style="display:flex;gap:10px;margin:12px 0;flex-wrap:wrap">
		<button type="button" class="button atora-cohort-filter" data-filter="all" aria-pressed="true"><?php esc_html_e( 'Todos', 'atora-lms' ); ?></button>
		<button type="button" class="button atora-cohort-filter" data-filter="risk"><?php esc_html_e( '🔴 En riesgo (<30%)', 'atora-lms' ); ?></button>
		<button type="button" class="button atora-cohort-filter" data-filter="inactive"><?php esc_html_e( '⏱ Inactivo >7d', 'atora-lms' ); ?></button>
		<button type="button" class="button atora-cohort-filter" data-filter="low_grade"><?php esc_html_e( '📉 Nota <60%', 'atora-lms' ); ?></button>
		<input type="search" id="atora-cohort-search" placeholder="<?php esc_attr_e( 'Buscar estudiante…', 'atora-lms' ); ?>" style="margin-left:auto;padding:4px 10px;border-radius:6px;border:.5px solid #e2e8f0">
	</div>

	<!-- Tabla -->
	<div id="atora-cohort-loading" style="padding:2rem;text-align:center;color:#64748b"><?php esc_html_e( 'Cargando cohorte…', 'atora-lms' ); ?></div>
	<table class="widefat atora-table" id="atora-cohort-table" style="display:none">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Estudiante', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Lecciones', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Nota', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Última actividad', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Score', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Riesgo', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody id="atora-cohort-tbody"></tbody>
	</table>
	<p id="atora-cohort-empty" style="display:none;color:#64748b;padding:1rem"><?php esc_html_e( 'No hay estudiantes con ese filtro.', 'atora-lms' ); ?></p>
</div>

<style>
.atora-cohort-filter[aria-pressed="true"] { background:#1d4ed8;color:#fff;border-color:#1d4ed8; }
.atora-cohort-risk   td { background:#fff1f1 !important; }
.atora-cohort-inactive td { background:#fefce8 !important; }
.atora-badge-risk    { background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600; }
.atora-badge-normal  { background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600; }
.atora-badge-high    { background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600; }
.atora-badge-medium  { background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600; }
</style>

<script>
(function() {
	var NONCE      = <?php echo wp_json_encode( $rest_nonce ); ?>;
	var COHORT_URL = <?php echo wp_json_encode( $cohort_url ); ?>;
	var TAG_URL    = <?php echo wp_json_encode( $tag_url ); ?>;
	var allRows    = [];
	var activeFilter = 'all';
	var searchVal    = '';

	function daysSince(dateStr) {
		if (!dateStr) { return 9999; }
		return Math.floor((Date.now() - new Date(dateStr).getTime()) / 864e5);
	}

	function riskBadge(level) {
		var map = { critical:'risk', high:'high', medium:'medium', normal:'normal', low:'normal' };
		var cls = map[level] || 'normal';
		return '<span class="atora-badge-' + cls + '">' + (level || 'normal') + '</span>';
	}

	function progressBar(pct) {
		var color = pct < 30 ? '#e24b4a' : pct < 60 ? '#f59e0b' : '#1d9e75';
		return '<div style="background:#e2e8f0;border-radius:999px;height:6px;width:80px;display:inline-block;vertical-align:middle">' +
			'<div style="width:' + pct + '%;background:' + color + ';height:6px;border-radius:999px"></div></div> ' +
			'<span style="font-size:12px">' + pct + '%</span>';
	}

	function renderTable(rows) {
		var tbody = document.getElementById('atora-cohort-tbody');
		var table = document.getElementById('atora-cohort-table');
		var empty = document.getElementById('atora-cohort-empty');
		if (!rows.length) {
			table.style.display = 'none'; empty.style.display = 'block'; return;
		}
		table.style.display = ''; empty.style.display = 'none';
		tbody.innerHTML = rows.map(function(r) {
			var days     = daysSince(r.last_activity);
			var trClass  = r.progress_pct < 30 ? 'atora-cohort-risk' : days > 7 ? 'atora-cohort-inactive' : '';
			var grade    = r.grade !== null ? r.grade.toFixed(1) + '%' : '—';
			var lastAct  = r.last_activity ? r.last_activity.split('T')[0] : '—';
			var actBtn   = r.contact_id
				? '<button class="button button-small atora-cohort-tag-btn" data-cid="' + r.contact_id + '" title="Activar automatización">⚡ Activar</button>'
				: '';
			return '<tr class="' + trClass + '">' +
				'<td>' + (r.display_name || 'ID ' + r.user_id) + '</td>' +
				'<td>' + progressBar(r.progress_pct) + '</td>' +
				'<td>' + r.completed_lessons + '</td>' +
				'<td>' + grade + '</td>' +
				'<td>' + lastAct + (days < 9999 ? ' <small>(' + days + 'd)</small>' : '') + '</td>' +
				'<td>' + r.score + '</td>' +
				'<td>' + riskBadge(r.risk_level) + '</td>' +
				'<td>' + actBtn + '</td>' +
				'</tr>';
		}).join('');

		// Botones de activar automatización
		tbody.querySelectorAll('.atora-cohort-tag-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var cid = this.getAttribute('data-cid');
				btn.disabled = true; btn.textContent = '…';
				fetch(TAG_URL + cid + '/tag', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body: JSON.stringify({ tag: '#inactividad-detectada' })
				}).then(function(res) { return res.json(); })
				.then(function(d) {
					btn.textContent = d.success ? '✓ OK' : '✗ Error';
				}).catch(function() { btn.textContent = '✗'; });
			});
		});
	}

	function applyFilters() {
		var rows = allRows.filter(function(r) {
			var days = daysSince(r.last_activity);
			if (activeFilter === 'risk'      && r.progress_pct >= 30)  { return false; }
			if (activeFilter === 'inactive'  && days <= 7)              { return false; }
			if (activeFilter === 'low_grade' && (r.grade === null || r.grade >= 60)) { return false; }
			if (searchVal && (r.display_name || '').toLowerCase().indexOf(searchVal) < 0) { return false; }
			return true;
		});
		renderTable(rows);
	}

	// Cargar datos
	fetch(COHORT_URL + '?limit=200', { headers: { 'X-WP-Nonce': NONCE } })
		.then(function(res) { return res.json(); })
		.then(function(data) {
			document.getElementById('atora-cohort-loading').style.display = 'none';
			allRows = data.items || [];
			applyFilters();
		})
		.catch(function() {
			document.getElementById('atora-cohort-loading').textContent = 'Error al cargar los datos.';
		});

	// Filtros
	document.querySelectorAll('.atora-cohort-filter').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.atora-cohort-filter').forEach(function(b) { b.setAttribute('aria-pressed','false'); });
			btn.setAttribute('aria-pressed','true');
			activeFilter = btn.getAttribute('data-filter');
			applyFilters();
		});
	});

	// Búsqueda
	document.getElementById('atora-cohort-search').addEventListener('input', function() {
		searchVal = this.value.toLowerCase().trim();
		applyFilters();
	});
})();
</script>
