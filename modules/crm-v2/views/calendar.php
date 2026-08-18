<?php
/**
 * Vista: Calendario y tareas CRM v2 — Fase 2
 *
 * Reemplaza la vista anterior (lista HTML con coloreo JS).
 * El calendario se monta en el div #crm-cal-mount via FullCalendar 6.
 * El formulario de nueva tarea opera sin recargar (REST).
 *
 * Variables esperadas:
 *   $task_types      — array<string,string> tipos de tarea
 *   $upcoming_campaigns — array de campañas próximas (para la sección lateral)
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$task_types         = is_array( $task_types ?? null ) ? $task_types : array();
$upcoming_campaigns = is_array( $upcoming_campaigns ?? null ) ? $upcoming_campaigns : array();

require __DIR__ . '/partials/header.php';
?>

<?php /* ─────────────── TOAST ─────────────── */ ?>
<div id="crm-cal-toast" class="crm-toast" aria-live="polite" aria-atomic="true" hidden></div>

<?php /* ─────────────── Layout 2 columnas ─────────────── */ ?>
<div class="crm-cal-layout">

	<?php /* ══════════════ COLUMNA PRINCIPAL — CALENDARIO ══════════════ */ ?>
	<div class="crm-cal-main">

		<?php /* Barra de controles de vista */ ?>
		<div class="crm-cal-toolbar">
			<div class="crm-cal-toolbar__left">
				<button class="button crm-cal-nav" id="crm-cal-prev" title="Mes anterior">&#8249;</button>
				<button class="button crm-cal-nav" id="crm-cal-next" title="Mes siguiente">&#8250;</button>
				<button class="button crm-cal-nav" id="crm-cal-today"><?php esc_html_e( 'Hoy', 'atora-lms' ); ?></button>
				<span id="crm-cal-title" class="crm-cal-title"></span>
			</div>
			<div class="crm-cal-toolbar__right">
				<div class="crm-cal-view-btns" role="group" aria-label="<?php esc_attr_e( 'Vista del calendario', 'atora-lms' ); ?>">
					<button class="button crm-cal-view-btn is-active" data-view="dayGridMonth">
						<?php esc_html_e( 'Mes', 'atora-lms' ); ?>
					</button>
					<button class="button crm-cal-view-btn" data-view="timeGridWeek">
						<?php esc_html_e( 'Semana', 'atora-lms' ); ?>
					</button>
					<button class="button crm-cal-view-btn" data-view="listMonth">
						<?php esc_html_e( 'Lista', 'atora-lms' ); ?>
					</button>
				</div>

				<?php /* Filtros de tipo de evento */ ?>
				<label class="crm-cal-filter">
					<input type="checkbox" id="crm-cal-filter-tasks" checked>
					<span class="crm-cal-filter__dot" style="background:#2563eb"></span>
					<?php esc_html_e( 'Tareas', 'atora-lms' ); ?>
				</label>
				<label class="crm-cal-filter">
					<input type="checkbox" id="crm-cal-filter-campaigns" checked>
					<span class="crm-cal-filter__dot" style="background:#db2777"></span>
					<?php esc_html_e( 'Campañas', 'atora-lms' ); ?>
				</label>
			</div>
		</div>

		<?php /* Montura del calendario */ ?>
		<div id="crm-cal-mount" class="crm-cal-mount"></div>

		<?php /* Leyenda de tipos */ ?>
		<div class="crm-cal-legend">
			<?php
			$legend = array(
				'commercial_call' => array( '#059669', __( 'Llamada comercial', 'atora-lms' ) ),
				'followup_email'  => array( '#2563eb', __( 'Email seguimiento', 'atora-lms' ) ),
				'tutoring'        => array( '#7c3aed', __( 'Tutoría', 'atora-lms' ) ),
				'grading'         => array( '#d97706', __( 'Evaluación', 'atora-lms' ) ),
				'payment'         => array( '#dc2626', __( 'Pago pendiente', 'atora-lms' ) ),
				'academic_event'  => array( '#0891b2', __( 'Evento académico', 'atora-lms' ) ),
				'campaign'        => array( '#db2777', __( 'Campaña', 'atora-lms' ) ),
			);
			foreach ( $legend as $item ) :
			?>
				<span class="crm-cal-legend__item">
					<span class="crm-cal-legend__dot" style="background:<?php echo esc_attr( $item[0] ); ?>"></span>
					<?php echo esc_html( $item[1] ); ?>
				</span>
			<?php endforeach; ?>
		</div>
	</div>

	<?php /* ══════════════ COLUMNA LATERAL ══════════════ */ ?>
	<aside class="crm-cal-sidebar">

		<?php /* ─── Formulario de nueva tarea ─── */ ?>
		<section class="atora-crm-v2-panel crm-cal-new-task">
			<h3><?php esc_html_e( 'Nueva tarea', 'atora-lms' ); ?></h3>
			<div class="crm-cal-form" id="crm-task-form">
				<label>
					<?php esc_html_e( 'Título', 'atora-lms' ); ?>
					<input type="text" id="crm-task-title" required placeholder="<?php esc_attr_e( 'Ej: Llamar a Juan García', 'atora-lms' ); ?>">
				</label>
				<label>
					<?php esc_html_e( 'Tipo', 'atora-lms' ); ?>
					<select id="crm-task-type">
						<?php foreach ( $task_types as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="crm-cal-form__row">
					<label>
						<?php esc_html_e( 'Prioridad', 'atora-lms' ); ?>
						<select id="crm-task-priority">
							<option value="low"><?php esc_html_e( 'Baja', 'atora-lms' ); ?></option>
							<option value="medium" selected><?php esc_html_e( 'Media', 'atora-lms' ); ?></option>
							<option value="high"><?php esc_html_e( 'Alta', 'atora-lms' ); ?></option>
							<option value="urgent"><?php esc_html_e( 'Urgente', 'atora-lms' ); ?></option>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Fecha y hora', 'atora-lms' ); ?>
						<input type="datetime-local" id="crm-task-due">
					</label>
				</div>
				<label>
					<?php esc_html_e( 'Notas (opcional)', 'atora-lms' ); ?>
					<textarea id="crm-task-notes" rows="3"
						placeholder="<?php esc_attr_e( 'Contexto para la persona responsable...', 'atora-lms' ); ?>"></textarea>
				</label>
				<button type="button" class="button button-primary" id="crm-task-submit" style="width:100%">
					<?php esc_html_e( 'Crear tarea', 'atora-lms' ); ?>
				</button>
			</div>
		</section>

		<?php /* ─── Campañas próximas ─── */ ?>
		<section class="atora-crm-v2-panel crm-cal-upcoming">
			<div class="atora-crm-v2-panel__head">
				<h3><?php esc_html_e( 'Campañas programadas', 'atora-lms' ); ?></h3>
				<a class="atora-crm-v2-link"
					href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-campaigns' ) ); ?>">
					<?php esc_html_e( 'Ver todas', 'atora-lms' ); ?>
				</a>
			</div>

			<?php if ( empty( $upcoming_campaigns ) ) : ?>
				<p class="atora-crm-v2-empty atora-crm-v2-empty--mini">
					<?php esc_html_e( 'No hay campañas programadas.', 'atora-lms' ); ?>
				</p>
			<?php else : ?>
				<ul class="crm-cal-campaign-list">
					<?php foreach ( array_slice( $upcoming_campaigns, 0, 8 ) as $campaign ) :
						$scheduled_at = sanitize_text_field( (string) ( $campaign['scheduled_at'] ?? '' ) );
						$label        = '' !== $scheduled_at
							? get_date_from_gmt( $scheduled_at, 'd M, H:i' )
							: '—';
					?>
						<li class="crm-cal-campaign-item">
							<span class="crm-cal-campaign-dot"></span>
							<div>
								<strong><?php echo esc_html( (string) ( $campaign['name'] ?? '' ) ); ?></strong>
								<small><?php echo esc_html( $label ); ?></small>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

	</aside>
</div>

<?php /* ─────────────── POPOVER de evento ─────────────── */ ?>
<div id="crm-cal-popover" class="crm-cal-popover" hidden role="dialog" aria-modal="true" aria-label="Detalle de evento">
	<button class="crm-cal-popover__close" id="crm-cal-popover-close" aria-label="Cerrar">&times;</button>
	<div id="crm-cal-popover-body" class="crm-cal-popover__body">
		<p class="atora-crm-v2-muted"><?php esc_html_e( 'Cargando...', 'atora-lms' ); ?></p>
	</div>
	<div class="crm-cal-popover__actions" id="crm-cal-popover-actions"></div>
</div>
<div id="crm-cal-overlay" class="crm-cal-overlay" hidden></div>

<?php require __DIR__ . '/partials/footer.php'; ?>
