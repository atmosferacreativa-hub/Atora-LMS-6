<?php
/**
 * Planes de seguimiento — vista de docente/vendedor (PT-4, sprint
 * 6.6.0; generalizada a dos dominios en PT-5, sprint 6.7.0).
 *
 * Todo en una sola pantalla: calendario mensual + asistente de 4
 * pasos (modal) + panel lateral de ocurrencia — sin navegación entre
 * pestañas separadas, per §UX de la OT. El selector de dominio
 * (académico/comercial, PT-5.1) es la única adición de navegación,
 * y solo aparece para quien de verdad tiene planes de ambos tipos —
 * un simple par de pestañas, no una reestructuración.
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
$deal_stages = class_exists( '\ATORA\CRM_V2\Services\Deal_Service' ) ? \ATORA\CRM_V2\Services\Deal_Service::get_stages() : array();

// Mismas capacidades que can_access_academic_calendar() /
// can_access_followup_plans() en trait-admin-menu-navigation.php —
// duplicado acá porque esta vista no tiene acceso a $this (se incluye
// como plantilla suelta, mismo patrón ya usado por el resto del
// archivo con get_current_user_id()/Section_Service directos).
$can_academic   = current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) || current_user_can( 'clms_manage_courses' ) || current_user_can( 'clms_manage_lessons' ) || current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'clms_grade_submissions' ) || current_user_can( 'edit_posts' );
$can_commercial = current_user_can( 'clms_access_crm_view' ) || current_user_can( 'manage_options' );

$default_domain = $can_academic ? 'academic' : 'commercial';
?>
<div class="wrap atora-followup-wrap" id="atora-fu-app" data-can-academic="<?php echo esc_attr( $can_academic ? '1' : '0' ); ?>" data-can-commercial="<?php echo esc_attr( $can_commercial ? '1' : '0' ); ?>" data-default-domain="<?php echo esc_attr( $default_domain ); ?>">
	<div id="crm-toast" class="crm-toast" aria-live="polite" aria-atomic="true" hidden></div>

	<div class="atora-fu-header">
		<div>
			<h1><?php esc_html_e( 'Planes de seguimiento', 'atora-lms' ); ?></h1>
			<p class="atora-fu-subtitle" id="atora-fu-subtitle"><?php esc_html_e( 'Tu ritmo de contacto con estudiantes en riesgo, en un calendario.', 'atora-lms' ); ?></p>
		</div>
		<button type="button" class="button button-primary atora-fu-new-plan" id="atora-fu-open-wizard">
			<?php esc_html_e( '+ Nuevo plan', 'atora-lms' ); ?>
		</button>
	</div>

	<?php if ( $can_academic && $can_commercial ) : ?>
		<!-- PT-5.1: selector de dominio — solo visible para quien tiene ambos roles. -->
		<div class="atora-fu-domain-tabs" role="tablist" id="atora-fu-domain-tabs">
			<button type="button" class="atora-fu-domain-tab is-active" role="tab" data-domain="academic" aria-selected="true">
				<?php esc_html_e( 'Estudiantes', 'atora-lms' ); ?>
			</button>
			<button type="button" class="atora-fu-domain-tab" role="tab" data-domain="commercial" aria-selected="false">
				<?php esc_html_e( 'Ventas', 'atora-lms' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<?php if ( $can_academic && empty( $sections ) && ! $can_commercial ) : ?>
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
					<p><?php esc_html_e( 'Elegí a quién seguir y con qué etapas en el paso siguiente para ver la vista previa.', 'atora-lms' ); ?></p>
				</div>
			</section>

			<!-- Paso 3: ajustar -->
			<section class="atora-fu-wizard-step" data-step="3" hidden>
				<h3><?php esc_html_e( '3. Ajustá a tu gusto', 'atora-lms' ); ?></h3>

				<!-- Académico: secciones + etapas de Student_Followup_Service -->
				<div id="atora-fu-academic-scope" class="atora-fu-domain-block">
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
				</div>

				<!-- Comercial, modo por-etapa: alcance implícito = mi cartera, solo elige etapas. -->
				<div id="atora-fu-commercial-scope" class="atora-fu-domain-block" hidden>
					<label class="atora-fu-field-label"><?php esc_html_e( 'Contactos en', 'atora-lms' ); ?></label>
					<div class="atora-fu-chip-group" id="atora-fu-deal-stage-chips">
						<?php foreach ( $deal_stages as $stage_key => $stage ) : ?>
							<label class="atora-fu-chip">
								<input type="checkbox" name="deal_stage_filter[]" value="<?php echo esc_attr( $stage_key ); ?>" />
								<span><?php echo esc_html( (string) $stage['label'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="atora-fu-scope-hint" id="atora-fu-commercial-scope-hint"><?php esc_html_e( 'Se aplica a toda tu cartera — no hace falta elegir a nadie más.', 'atora-lms' ); ?></p>

					<label class="atora-fu-chip atora-fu-chip--block">
						<input type="checkbox" id="atora-fu-exclude-sequence" />
						<span><?php esc_html_e( 'No mostrar a quien ya está en una secuencia automática', 'atora-lms' ); ?></span>
					</label>
				</div>

				<!-- Comercial, "Cuenta clave": selección manual, sin etapas. -->
				<div id="atora-fu-commercial-manual" class="atora-fu-domain-block" hidden>
					<label class="atora-fu-field-label"><?php esc_html_e( 'Elegí a quién seguir', 'atora-lms' ); ?></label>
					<input type="text" id="atora-fu-contact-search" class="atora-fu-text-input" placeholder="<?php esc_attr_e( 'Buscar contacto por nombre o email…', 'atora-lms' ); ?>" autocomplete="off" />
					<div class="atora-fu-contact-search-results" id="atora-fu-contact-search-results" hidden></div>
					<div class="atora-fu-chip-group" id="atora-fu-selected-contacts"></div>
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
