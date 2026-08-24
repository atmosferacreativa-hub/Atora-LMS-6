<?php
/**
 * Planes de seguimiento — vista de docente (PT-4, sprint 6.6.0).
 *
 * Todo en una sola pantalla: calendario mensual + asistente de 4
 * pasos (modal) + panel lateral de ocurrencia — sin navegación entre
 * pestañas separadas, per §UX de la OT.
 *
 * @package ATORA_LMS\CRM_V2
 * @since   6.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$teacher_id = get_current_user_id();
$sections   = class_exists( '\ATORA\LMS\Section_Service' ) ? \ATORA\LMS\Section_Service::get_sections_by_teacher( $teacher_id ) : array();
$stages     = class_exists( '\ATORA\CRM_V2\Services\Student_Followup_Service' ) ? \ATORA\CRM_V2\Services\Student_Followup_Service::get_stages() : array();
?>
<div class="wrap atora-followup-wrap">
	<div id="crm-toast" class="crm-toast" aria-live="polite" aria-atomic="true" hidden></div>

	<div class="atora-fu-header">
		<div>
			<h1><?php esc_html_e( 'Planes de seguimiento', 'atora-lms' ); ?></h1>
			<p class="atora-fu-subtitle"><?php esc_html_e( 'Tu ritmo de contacto con estudiantes en riesgo, en un calendario.', 'atora-lms' ); ?></p>
		</div>
		<button type="button" class="button button-primary atora-fu-new-plan" id="atora-fu-open-wizard">
			<?php esc_html_e( '+ Nuevo plan', 'atora-lms' ); ?>
		</button>
	</div>

	<?php if ( empty( $sections ) ) : ?>
		<div class="atora-fu-empty-state">
			<p><strong><?php esc_html_e( 'Todavía no tenés secciones asignadas.', 'atora-lms' ); ?></strong></p>
			<p><?php esc_html_e( 'Un coordinador tiene que asignarte al menos una sección para poder crear un plan de seguimiento.', 'atora-lms' ); ?></p>
		</div>
	<?php else : ?>
		<div id="atora-fu-mount" class="atora-fu-calendar-mount"></div>
	<?php endif; ?>

	<!-- Panel lateral de ocurrencia (PT-4.4) -->
	<div id="atora-fu-panel" class="atora-fu-panel" hidden aria-hidden="true">
		<div class="atora-fu-panel-backdrop" id="atora-fu-panel-backdrop"></div>
		<div class="atora-fu-panel-sheet" role="dialog" aria-modal="true" aria-labelledby="atora-fu-panel-title">
			<button type="button" class="atora-fu-panel-close" id="atora-fu-panel-close" aria-label="<?php esc_attr_e( 'Cerrar', 'atora-lms' ); ?>">&times;</button>
			<h2 id="atora-fu-panel-title"></h2>
			<p class="atora-fu-panel-date"></p>
			<div class="atora-fu-panel-actions">
				<button type="button" class="button" id="atora-fu-panel-postpone"><?php esc_html_e( 'Posponer un día', 'atora-lms' ); ?></button>
				<button type="button" class="button" id="atora-fu-panel-skip"><?php esc_html_e( 'Saltar esta vez', 'atora-lms' ); ?></button>
				<button type="button" class="button button-primary" id="atora-fu-panel-contact-all"><?php esc_html_e( 'Marcar a todos contactados', 'atora-lms' ); ?></button>
			</div>
			<div class="atora-fu-panel-students" id="atora-fu-panel-students"></div>
		</div>
	</div>

	<!-- Asistente de 4 pasos (PT-4.2), en modal -->
	<div id="atora-fu-wizard" class="atora-fu-wizard" hidden aria-hidden="true">
		<div class="atora-fu-panel-backdrop" id="atora-fu-wizard-backdrop"></div>
		<div class="atora-fu-wizard-sheet" role="dialog" aria-modal="true" aria-labelledby="atora-fu-wizard-title">
			<button type="button" class="atora-fu-panel-close" id="atora-fu-wizard-close" aria-label="<?php esc_attr_e( 'Cerrar', 'atora-lms' ); ?>">&times;</button>
			<h2 id="atora-fu-wizard-title"><?php esc_html_e( 'Nuevo plan de seguimiento', 'atora-lms' ); ?></h2>
			<div class="atora-fu-wizard-steps" aria-hidden="true">
				<span class="atora-fu-step-dot is-active" data-step="1"></span>
				<span class="atora-fu-step-dot" data-step="2"></span>
				<span class="atora-fu-step-dot" data-step="3"></span>
				<span class="atora-fu-step-dot" data-step="4"></span>
			</div>

			<!-- Paso 1: plantilla -->
			<section class="atora-fu-wizard-step" data-step="1">
				<h3><?php esc_html_e( '1. Elegí un punto de partida', 'atora-lms' ); ?></h3>
				<div class="atora-fu-template-grid" id="atora-fu-template-grid"></div>
				<button type="button" class="button-link atora-fu-blank-option" id="atora-fu-blank-template">
					<?php esc_html_e( 'Empezar en blanco', 'atora-lms' ); ?>
				</button>
			</section>

			<!-- Paso 2: vista previa -->
			<section class="atora-fu-wizard-step" data-step="2" hidden>
				<h3><?php esc_html_e( '2. Así se ve hoy', 'atora-lms' ); ?></h3>
				<div class="atora-fu-preview-box" id="atora-fu-preview-box">
					<p><?php esc_html_e( 'Elegí secciones y etapas en el paso siguiente para ver la vista previa.', 'atora-lms' ); ?></p>
				</div>
			</section>

			<!-- Paso 3: ajustar -->
			<section class="atora-fu-wizard-step" data-step="3" hidden>
				<h3><?php esc_html_e( '3. Ajustá a tu gusto', 'atora-lms' ); ?></h3>

				<label class="atora-fu-field-label"><?php esc_html_e( 'Secciones', 'atora-lms' ); ?></label>
				<div class="atora-fu-chip-group" id="atora-fu-section-chips">
					<?php foreach ( $sections as $section ) : ?>
						<label class="atora-fu-chip">
							<input type="checkbox" name="section_ids[]" value="<?php echo esc_attr( (string) $section['id'] ); ?>" />
							<span><?php echo esc_html( (string) $section['title'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<label class="atora-fu-field-label"><?php esc_html_e( 'Estudiantes en', 'atora-lms' ); ?></label>
				<div class="atora-fu-chip-group" id="atora-fu-stage-chips">
					<?php foreach ( $stages as $stage_key => $stage ) : ?>
						<label class="atora-fu-chip">
							<input type="checkbox" name="stage_filter[]" value="<?php echo esc_attr( $stage_key ); ?>" />
							<span><?php echo esc_html( (string) $stage['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<label class="atora-fu-field-label"><?php esc_html_e( '¿Con qué frecuencia?', 'atora-lms' ); ?></label>
				<div class="atora-fu-chip-group" id="atora-fu-frequency-chips">
					<label class="atora-fu-chip"><input type="radio" name="frequency" value="WEEKLY;BYDAY=MO" checked /><span><?php esc_html_e( 'Cada lunes', 'atora-lms' ); ?></span></label>
					<label class="atora-fu-chip"><input type="radio" name="frequency" value="WEEKLY;BYDAY=WE" /><span><?php esc_html_e( 'Cada miércoles', 'atora-lms' ); ?></span></label>
					<label class="atora-fu-chip"><input type="radio" name="frequency" value="DAILY;INTERVAL=3" /><span><?php esc_html_e( 'Cada 3 días', 'atora-lms' ); ?></span></label>
				</div>
			</section>

			<!-- Paso 4: nombrar y aplicar -->
			<section class="atora-fu-wizard-step" data-step="4" hidden>
				<h3><?php esc_html_e( '4. Ponele un nombre', 'atora-lms' ); ?></h3>
				<input type="text" id="atora-fu-plan-name" class="atora-fu-text-input" placeholder="<?php esc_attr_e( 'Ej: Seguimiento Metodología, lunes', 'atora-lms' ); ?>" maxlength="190" />
				<label class="atora-fu-chip atora-fu-chip--block">
					<input type="checkbox" id="atora-fu-save-as-template" />
					<span><?php esc_html_e( 'Guardar también como plantilla propia', 'atora-lms' ); ?></span>
				</label>
			</section>

			<div class="atora-fu-wizard-nav">
				<button type="button" class="button" id="atora-fu-wizard-back" hidden><?php esc_html_e( 'Atrás', 'atora-lms' ); ?></button>
				<button type="button" class="button button-primary" id="atora-fu-wizard-next"><?php esc_html_e( 'Siguiente', 'atora-lms' ); ?></button>
				<button type="button" class="button button-primary" id="atora-fu-wizard-apply" hidden><?php esc_html_e( 'Aplicar plan', 'atora-lms' ); ?></button>
			</div>
		</div>
	</div>
</div>
