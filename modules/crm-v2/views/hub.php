<?php
/**
 * Hub CRM v2 — Fase 8: operativo con KPIs en tiempo real.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tasks_items = (array) ( $tasks['items'] ?? array() );
$sales       = is_array( $sales ?? null ) ? $sales : array();
$academic    = is_array( $academic ?? null ) ? $academic : array();
$hub_options = (array) ( $hub_options ?? array() );

require __DIR__ . '/partials/header.php';
?>
<?php if ( 'hub_selector' === (string) ( $screen ?? '' ) ) : ?>
<section class="atora-crm-v2-app__grid atora-crm-v2-app__grid--hub">
	<?php foreach ( $hub_options as $hub_option ) : ?>
		<article class="atora-crm-v2-card atora-crm-v2-card--blue">
			<h2><?php echo esc_html( (string) ( $hub_option['label'] ?? '' ) ); ?></h2>
			<p><?php echo esc_html( (string) ( $hub_option['description'] ?? '' ) ); ?></p>
			<a class="atora-crm-v2-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . sanitize_key( (string) ( $hub_option['slug'] ?? '' ) ) ) ); ?>"><?php esc_html_e( 'Abrir hub', 'atora-lms' ); ?></a>
		</article>
	<?php endforeach; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
<?php return; ?>
<?php endif; ?>

<div id="crm-toast" class="crm-toast" aria-live="polite" hidden></div>

<!-- ── KPIs en tiempo real ── -->
<section class="atora-crm-v2-panel crm-hub-kpis" id="crm-hub-kpis-section">
	<div class="crm-hub-kpi-grid" id="crm-hub-kpi-grid">
		<div class="crm-hub-kpi-card crm-hub-kpi-card--loading">
			<span class="crm-hub-kpi-label"><?php esc_html_e( 'Pipeline comercial', 'atora-lms' ); ?></span>
			<strong class="crm-hub-kpi-val" id="kpi-pipeline-open">—</strong>
			<small><?php esc_html_e( 'oportunidades abiertas', 'atora-lms' ); ?></small>
		</div>
		<div class="crm-hub-kpi-card">
			<span class="crm-hub-kpi-label"><?php esc_html_e( 'Estudiantes en riesgo', 'atora-lms' ); ?></span>
			<strong class="crm-hub-kpi-val" id="kpi-risk-students">—</strong>
			<small><?php esc_html_e( 'requieren atención', 'atora-lms' ); ?></small>
		</div>
		<div class="crm-hub-kpi-card">
			<span class="crm-hub-kpi-label"><?php esc_html_e( 'Automatizaciones activas', 'atora-lms' ); ?></span>
			<strong class="crm-hub-kpi-val" id="kpi-automations">—</strong>
			<small id="kpi-automations-24h" style="display:block">—</small>
		</div>
		<div class="crm-hub-kpi-card">
			<span class="crm-hub-kpi-label"><?php esc_html_e( 'Secuencias activas', 'atora-lms' ); ?></span>
			<strong class="crm-hub-kpi-val" id="kpi-sequences">—</strong>
			<small><?php esc_html_e( 'enrolments activos', 'atora-lms' ); ?></small>
		</div>
		<div class="crm-hub-kpi-card">
			<span class="crm-hub-kpi-label"><?php esc_html_e( 'Desuscriptos', 'atora-lms' ); ?></span>
			<strong class="crm-hub-kpi-val" id="kpi-suppressed">—</strong>
			<a class="crm-hub-kpi-link" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-email-engine&tab=suppression' ) ); ?>"><?php esc_html_e( 'Gestionar', 'atora-lms' ); ?></a>
		</div>
		<div class="crm-hub-kpi-card">
			<span class="crm-hub-kpi-label"><?php esc_html_e( 'Carritos abandonados', 'atora-lms' ); ?></span>
			<strong class="crm-hub-kpi-val crm-hub-kpi-val--warn" id="kpi-abandoned">—</strong>
			<small><?php esc_html_e( 'activos (últimas 72h)', 'atora-lms' ); ?></small>
		</div>
		<div class="crm-hub-kpi-card">
			<span class="crm-hub-kpi-label"><?php esc_html_e( 'Tareas vencidas', 'atora-lms' ); ?></span>
			<strong class="crm-hub-kpi-val crm-hub-kpi-val--warn" id="kpi-overdue">—</strong>
			<a class="crm-hub-kpi-link" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-calendar' ) ); ?>"><?php esc_html_e( 'Ver calendario', 'atora-lms' ); ?></a>
		</div>
	</div>
</section>

<!-- ── Cards de acceso rápido ── -->
<section class="atora-crm-v2-app__grid atora-crm-v2-app__grid--hub">
	<article class="atora-crm-v2-card atora-crm-v2-card--emerald">
		<h2><?php esc_html_e( 'Pipeline comercial', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Mueve leads por etapas y prioriza oportunidades de cierre.', 'atora-lms' ); ?></p>
		<ul>
			<li><?php echo esc_html( sprintf( __( '%d oportunidades abiertas', 'atora-lms' ), absint( $sales['open'] ?? 0 ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( '%d cierres ganados', 'atora-lms' ), absint( $sales['won'] ?? 0 ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Valor pipeline: %s', 'atora-lms' ), '$' . number_format_i18n( (float) ( $sales['pipeline_value'] ?? 0 ), 2 ) ) ); ?></li>
		</ul>
		<a class="atora-crm-v2-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-pipeline-sales' ) ); ?>"><?php esc_html_e( 'Abrir pipeline', 'atora-lms' ); ?></a>
	</article>

	<article class="atora-crm-v2-card atora-crm-v2-card--blue">
		<h2><?php esc_html_e( 'Acompañamiento académico', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Visualiza estudiantes en riesgo y activa soporte a tiempo.', 'atora-lms' ); ?></p>
		<ul>
			<li><?php echo esc_html( sprintf( __( '%d estudiantes en riesgo', 'atora-lms' ), absint( $academic['risk_students'] ?? 0 ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( '%d tareas vencidas', 'atora-lms' ), absint( $academic['overdue_tasks'] ?? 0 ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( '%d seguimientos próximos', 'atora-lms' ), absint( $academic['upcoming_tasks'] ?? 0 ) ) ); ?></li>
		</ul>
		<a class="atora-crm-v2-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-pipeline-academic' ) ); ?>"><?php esc_html_e( 'Ver tablero académico', 'atora-lms' ); ?></a>
	</article>

	<article class="atora-crm-v2-card atora-crm-v2-card--slate">
		<h2><?php esc_html_e( 'Secuencias y campañas', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Gestiona secuencias drip activas y lanza campañas de email.', 'atora-lms' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Drip con condiciones de apertura/no apertura.', 'atora-lms' ); ?></li>
			<li><?php esc_html_e( 'Suppression list integrada con desuscripción.', 'atora-lms' ); ?></li>
			<li><?php esc_html_e( 'A/B testing en asunto o cuerpo.', 'atora-lms' ); ?></li>
		</ul>
		<a class="atora-crm-v2-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-sequences' ) ); ?>"><?php esc_html_e( 'Ir a secuencias', 'atora-lms' ); ?></a>
	</article>
</section>

<!-- ── Tareas operativas ── -->
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h3><?php esc_html_e( 'Próximas tareas operativas', 'atora-lms' ); ?></h3>
		<a class="atora-crm-v2-link" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-calendar' ) ); ?>"><?php esc_html_e( 'Calendario completo', 'atora-lms' ); ?></a>
	</div>

	<?php if ( empty( $tasks_items ) ) : ?>
		<p class="atora-crm-v2-empty"><?php esc_html_e( 'No hay tareas pendientes.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table class="atora-crm-v2-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tarea', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Prioridad', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Vence', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Contacto', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $tasks_items, 0, 8 ) as $task ) : ?>
					<tr>
						<td>
							<?php if ( ! empty( $task['contact_id'] ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . absint( $task['contact_id'] ) ) ); ?>">
									<?php echo esc_html( (string) ( $task['title'] ?? '' ) ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( (string) ( $task['title'] ?? '' ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $task['task_type'] ?? '' ) ); ?></td>
						<td><span class="atora-crm-v2-pill is-<?php echo esc_attr( sanitize_key( (string) ( $task['priority'] ?? 'medium' ) ) ); ?>"><?php echo esc_html( ucfirst( (string) ( $task['priority'] ?? 'medium' ) ) ); ?></span></td>
						<td><?php echo esc_html( (string) ( $task['due_at'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $task['contact_name'] ?? '—' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<script>
(function(){
var cfg=window.atoraCrmV2||{};
var REST=(cfg.restBase||'').replace(/\/+$/,'');
var NONCE=cfg.nonce||'';
function get(path){
  return fetch(REST+'/'+path.replace(/^\/+/,''),{credentials:'same-origin',headers:{'X-WP-Nonce':NONCE}})
    .then(function(r){return r.json();}).catch(function(){return {};});
}
function set(id,val){var e=document.getElementById(id);if(e)e.textContent=val;}

get('pipeline/sales/board').then(function(d){
  var open=0;
  if(d&&d.board&&d.board.items){Object.values(d.board.items).forEach(function(arr){open+=arr.length;});}
  set('kpi-pipeline-open',open);
}).catch(function(){set('kpi-pipeline-open','—');});

get('pipeline/academic/board').then(function(d){
  var risk=0;
  var riskStages=['at_risk','needs_support','low_progress'];
  if(d&&d.board&&d.board.items){riskStages.forEach(function(s){risk+=(d.board.items[s]||[]).length;});}
  set('kpi-risk-students',risk);
}).catch(function(){set('kpi-risk-students','—');});

get('sequences').then(function(d){
  var active=(d.sequences||[]).filter(function(s){return s.status==='active';});
  var enrollments=active.reduce(function(acc,s){return acc+(s.active_enrollments||0);},0);
  set('kpi-sequences',enrollments);
}).catch(function(){set('kpi-sequences','—');});

get('suppression?limit=1').then(function(d){
  set('kpi-suppressed',d.total||0);
}).catch(function(){set('kpi-suppressed','—');});

get('calendar/events?types=tasks&start='+new Date(Date.now()-7*86400000).toISOString()+'&end='+new Date().toISOString()).then(function(d){
  var overdue=(d.events||[]).filter(function(e){return e.extendedProps&&e.extendedProps.status==='pending';});
  set('kpi-overdue',overdue.length);
}).catch(function(){set('kpi-overdue','—');});

// KPI: carritos abandonados (Fase V S17: usando endpoint /summary)
fetch('/wp-json/atora-crm/v2/abandoned-carts/summary',{credentials:'same-origin',headers:{'X-WP-Nonce':NONCE}})
  .then(function(r){return r.json();})
  .then(function(d){var s=d.summary||{};set('kpi-abandoned',s.active||0);}).catch(function(){set('kpi-abandoned','—');});

document.querySelectorAll('.crm-hub-kpi-card--loading').forEach(function(c){c.classList.remove('crm-hub-kpi-card--loading');});
})();
</script>

<style>
.crm-hub-kpis{margin-bottom:16px}
.crm-hub-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.crm-hub-kpi-card{background:#fff;border:.5px solid #e2e8f0;border-radius:12px;padding:12px 14px;display:grid;gap:3px}
.crm-hub-kpi-card--loading{opacity:.5}
.crm-hub-kpi-label{font-size:12px;color:#64748b;font-weight:500}
.crm-hub-kpi-val{font-size:24px;font-weight:500;color:#0f172a;line-height:1.2}
.crm-hub-kpi-val--warn{color:#b45309}
.crm-hub-kpi-card small{font-size:11px;color:#94a3b8}
.crm-hub-kpi-link{font-size:11px;color:#1d4ed8}
</style>

<?php require __DIR__ . '/partials/footer.php'; ?>
