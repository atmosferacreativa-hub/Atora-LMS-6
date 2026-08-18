<?php
/**
 * Vista: Contactos y Ficha 360 — CRM v2 Fase 4
 *
 * Layout 3 columnas:
 *   Izquierda  — lista de contactos con búsqueda live (JS, sin reload)
 *   Centro     — ficha 360 del contacto: identidad, cursos, deals, notas, timeline
 *   Derecha    — panel de acciones ejecutables (email, tarea, nota, etapa, tag)
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ATORA\CRM_V2\Services\Deal_Service;
use ATORA\CRM_V2\Services\Student_Followup_Service;
use ATORA\CRM_V2\Services\Task_Service;

$contacts_data = is_array( $contacts ?? null ) ? $contacts : array();
$contact_items = (array) ( $contacts_data['items'] ?? array() );
$contact_360   = is_array( $contact_360 ?? null ) ? $contact_360 : array();
$detail        = (array) ( $contact_360['contact'] ?? array() );
$contact_id    = absint( $detail['id'] ?? 0 );

$sales_stages    = Deal_Service::get_stages();
$academic_stages = Student_Followup_Service::get_stages();
$task_types      = Task_Service::get_task_types();

require __DIR__ . '/partials/header.php';
?>

<div id="crm-toast" class="crm-toast" aria-live="polite" aria-atomic="true" hidden></div>

<div class="atora-crm-v2-contact-wrap">

	<?php /* ══════════ COLUMNA IZQUIERDA — Lista de contactos ══════════ */ ?>
	<div class="atora-crm-v2-contact-list" id="crm-contact-list">

		<?php /* Búsqueda live */ ?>
		<div class="atora-crm-v2-toolbar crm-contact-search-bar">
			<input type="text"
				id="crm-contact-search"
				class="crm-search-input"
				placeholder="<?php esc_attr_e( 'Buscar contacto…', 'atora-lms' ); ?>"
				autocomplete="off">
			<select id="crm-status-filter" class="crm-filter-select">
				<option value=""><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
				<option value="lead"><?php     esc_html_e( 'Lead', 'atora-lms' ); ?></option>
				<option value="prospect"><?php esc_html_e( 'Prospecto', 'atora-lms' ); ?></option>
				<option value="student"><?php  esc_html_e( 'Estudiante', 'atora-lms' ); ?></option>
				<option value="alumni"><?php   esc_html_e( 'Egresado', 'atora-lms' ); ?></option>
			</select>
		</div>

		<div id="crm-contact-list-body">
			<?php if ( empty( $contact_items ) ) : ?>
				<p class="atora-crm-v2-empty atora-crm-v2-empty--mini">
					<?php esc_html_e( 'No hay contactos.', 'atora-lms' ); ?>
				</p>
			<?php else : ?>
				<?php foreach ( $contact_items as $item ) : ?>
					<?php
					$item_id     = absint( $item['id'] ?? 0 );
					$item_name   = sanitize_text_field( (string) ( $item['name']   ?: __( 'Sin nombre', 'atora-lms' ) ) );
					$item_email  = sanitize_email( (string) ( $item['email']  ?? '' ) );
					$item_status = sanitize_key( (string) ( $item['status'] ?? '' ) );
					$is_active   = ( $item_id === $contact_id );
					?>
					<a class="crm-contact-row<?php echo $is_active ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . $item_id ) ); ?>"
						data-contact-id="<?php echo esc_attr( (string) $item_id ); ?>">
						<span class="crm-contact-row__avatar"><?php echo esc_html( strtoupper( substr( $item_name, 0, 1 ) ) ); ?></span>
						<span class="crm-contact-row__info">
							<strong><?php echo esc_html( $item_name ); ?></strong>
							<small><?php echo esc_html( $item_email ?: '—' ); ?></small>
						</span>
						<span class="crm-contact-row__status crm-status--<?php echo esc_attr( $item_status ); ?>">
							<?php echo esc_html( strtoupper( $item_status ) ); ?>
						</span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

	</div>

	<?php /* ══════════ COLUMNA CENTRAL — Ficha 360 ══════════ */ ?>
	<main class="atora-crm-v2-contact-detail" id="crm-contact-detail">
		<?php if ( empty( $detail ) ) : ?>
			<div class="atora-crm-v2-empty crm-empty-state">
				<p><?php esc_html_e( 'Selecciona un contacto de la lista para ver su perfil completo.', 'atora-lms' ); ?></p>
			</div>
		<?php else : ?>

			<?php /* Cabecera del contacto */ ?>
			<div class="crm-360-header">
				<div class="crm-360-avatar">
					<?php echo esc_html( strtoupper( substr( sanitize_text_field( (string) ( $detail['name'] ?? 'X' ) ), 0, 2 ) ) ); ?>
				</div>
				<div class="crm-360-identity">
					<h2><?php echo esc_html( sanitize_text_field( (string) ( $detail['name'] ?? '' ) ) ); ?></h2>
					<div class="crm-360-channels">
						<?php if ( ! empty( $detail['email'] ) ) : ?>
							<span><?php echo esc_html( sanitize_email( (string) $detail['email'] ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $detail['phone'] ) ) : ?>
							<span><?php echo esc_html( sanitize_text_field( (string) $detail['phone'] ) ); ?></span>
						<?php endif; ?>
					</div>
					<span class="atora-crm-v2-pill crm-status--<?php echo esc_attr( sanitize_key( (string) ( $detail['status'] ?? '' ) ) ); ?>">
						<?php echo esc_html( strtoupper( sanitize_key( (string) ( $detail['status'] ?? '' ) ) ) ); ?>
					</span>
				</div>
				<?php if ( ! empty( $contact_360['recommended_action'] ) ) : ?>
					<div class="crm-360-recommendation">
						<span><?php esc_html_e( 'Acción recomendada', 'atora-lms' ); ?></span>
						<p><?php echo esc_html( (string) $contact_360['recommended_action'] ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<?php /* Tags */ ?>
			<div class="crm-360-section">
				<h4><?php esc_html_e( 'Etiquetas', 'atora-lms' ); ?></h4>
				<div class="crm-360-tags" id="crm-contact-tags" data-contact-id="<?php echo esc_attr( (string) $contact_id ); ?>">
					<?php
					$tags = (array) ( $contact_360['tags'] ?? array() );
					if ( empty( $tags ) ) :
					?>
						<span class="atora-crm-v2-muted"><?php esc_html_e( 'Sin etiquetas', 'atora-lms' ); ?></span>
					<?php else : ?>
						<?php foreach ( $tags as $tag ) : ?>
							<span class="crm-tag-pill"><?php echo esc_html( sanitize_text_field( (string) $tag ) ); ?></span>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<?php /* Cursos */ ?>
			<?php if ( ! empty( $contact_360['courses'] ) ) : ?>
				<div class="crm-360-section">
					<h4><?php esc_html_e( 'Cursos inscritos', 'atora-lms' ); ?></h4>
					<ul class="crm-360-courses">
						<?php foreach ( (array) $contact_360['courses'] as $course ) : ?>
							<li class="crm-360-course">
								<span class="crm-360-course__name"><?php echo esc_html( (string) ( $course['course_title'] ?? '' ) ); ?></span>
								<span class="crm-360-course__meta">
									<?php echo esc_html( absint( $course['progress_percent'] ?? 0 ) . '% · ' . sanitize_text_field( (string) ( $course['risk_level'] ?? 'normal' ) ) ); ?>
								</span>
								<div class="crm-360-progress" title="<?php echo esc_attr( absint( $course['progress_percent'] ?? 0 ) . '%' ); ?>">
									<div class="crm-360-progress__fill" style="width:<?php echo esc_attr( absint( $course['progress_percent'] ?? 0 ) . '%' ); ?>"></div>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php /* Deals */ ?>
			<?php if ( ! empty( $contact_360['deals'] ) ) : ?>
				<div class="crm-360-section">
					<h4><?php esc_html_e( 'Pipeline comercial', 'atora-lms' ); ?></h4>
					<ul class="atora-crm-v2-list">
						<?php foreach ( (array) $contact_360['deals'] as $deal ) :
							$deal_stage_key   = sanitize_key( (string) ( $deal['stage'] ?? '' ) );
							$deal_stage_label = sanitize_text_field( (string) ( $sales_stages[ $deal_stage_key ]['label'] ?? $deal_stage_key ) );
						?>
							<li>
								<span class="crm-360-deal-stage"><?php echo esc_html( $deal_stage_label ); ?></span>
								<?php if ( ! empty( $deal['value'] ) ) : ?>
									<span class="atora-crm-v2-muted">$<?php echo esc_html( number_format_i18n( (float) $deal['value'], 2 ) ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php /* Notas */ ?>
			<div class="crm-360-section" id="crm-notes-section">
				<h4><?php esc_html_e( 'Notas', 'atora-lms' ); ?></h4>
				<?php if ( empty( $contact_360['notes'] ) ) : ?>
					<p class="atora-crm-v2-empty atora-crm-v2-empty--mini"><?php esc_html_e( 'Sin notas.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<ul class="crm-360-notes-list" id="crm-notes-list">
						<?php foreach ( array_slice( (array) $contact_360['notes'], 0, 5 ) as $note ) : ?>
							<li class="crm-360-note">
								<p><?php echo esc_html( (string) ( $note['note_text'] ?? '' ) ); ?></p>
								<small><?php echo esc_html( (string) ( $note['created_at'] ?? '' ) ); ?></small>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<?php /* Timeline */ ?>
			<div class="crm-360-section">
				<div class="crm-360-section__head">
					<h4><?php esc_html_e( 'Timeline', 'atora-lms' ); ?></h4>
					<select id="crm-timeline-filter" class="crm-filter-select--sm">
						<option value=""><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
						<option value="email_sent"><?php     esc_html_e( 'Emails', 'atora-lms' ); ?></option>
						<option value="internal_note"><?php  esc_html_e( 'Notas', 'atora-lms' ); ?></option>
						<option value="stage_changed"><?php  esc_html_e( 'Cambios de etapa', 'atora-lms' ); ?></option>
						<option value="tag_added"><?php      esc_html_e( 'Tags', 'atora-lms' ); ?></option>
						<option value="task_completed"><?php esc_html_e( 'Tareas', 'atora-lms' ); ?></option>
					</select>
				</div>
				<ul class="crm-360-timeline" id="crm-timeline-list" data-contact-id="<?php echo esc_attr( (string) $contact_id ); ?>">
					<?php
					$timeline = (array) ( $contact_360['timeline'] ?? array() );
					if ( empty( $timeline ) ) :
					?>
						<li class="atora-crm-v2-empty atora-crm-v2-empty--mini">
							<?php esc_html_e( 'Sin actividad registrada.', 'atora-lms' ); ?>
						</li>
					<?php else : ?>
						<?php foreach ( array_slice( $timeline, 0, 15 ) as $entry ) :
							$entry      = (array) $entry;
							$entry_type = sanitize_key( (string) ( $entry['activity_type'] ?? '' ) );
							$entry_date = sanitize_text_field( (string) ( $entry['created_at'] ?? '' ) );
						?>
							<li class="crm-360-timeline__item crm-tl--<?php echo esc_attr( $entry_type ); ?>">
								<span class="crm-360-timeline__dot"></span>
								<div>
									<strong><?php echo esc_html( $entry_type ); ?></strong>
									<small><?php echo esc_html( $entry_date ); ?></small>
								</div>
							</li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</div>

		<?php endif; ?>
	</main>

	<?php /* ══════════ COLUMNA DERECHA — Panel de acciones ══════════ */ ?>
	<aside class="atora-crm-v2-contact-actions-panel"
		id="crm-actions-panel"
		<?php if ( ! $contact_id ) : ?>hidden<?php endif; ?>
		data-contact-id="<?php echo esc_attr( (string) $contact_id ); ?>">

		<?php if ( $contact_id ) : ?>

			<h3><?php esc_html_e( 'Acciones rápidas', 'atora-lms' ); ?></h3>

			<?php /* ── Enviar email ── */ ?>
			<?php if ( current_user_can( 'crm_send_email' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' ) ) : ?>
			<details class="crm-action-block" id="crm-block-email">
				<summary class="crm-action-block__trigger">
					<?php esc_html_e( 'Enviar email', 'atora-lms' ); ?>
				</summary>
				<div class="crm-action-block__body">
					<select id="crm-email-identity" class="crm-field">
						<option value="academia"><?php esc_html_e( 'Academia', 'atora-lms' ); ?></option>
						<option value="teacher"><?php  esc_html_e( 'Docencia', 'atora-lms' ); ?></option>
						<option value="admin"><?php    esc_html_e( 'Comercial', 'atora-lms' ); ?></option>
					</select>
					<input type="text" id="crm-email-subject" class="crm-field"
						placeholder="<?php esc_attr_e( 'Asunto', 'atora-lms' ); ?>">
					<textarea id="crm-email-message" class="crm-field" rows="4"
						placeholder="<?php esc_attr_e( 'Mensaje…', 'atora-lms' ); ?>"></textarea>
					<input type="url" id="crm-email-cta" class="crm-field"
						placeholder="<?php esc_attr_e( 'URL de acción (opcional)', 'atora-lms' ); ?>">
					<button type="button" class="button button-primary crm-action-btn" data-action="send_email">
						<?php esc_html_e( 'Enviar', 'atora-lms' ); ?>
					</button>
				</div>
			</details>
			<?php endif; // crm_send_email ?>

			<?php /* ── Crear tarea ── */ ?>
			<details class="crm-action-block" id="crm-block-task">
				<summary class="crm-action-block__trigger">
					<?php esc_html_e( 'Crear tarea', 'atora-lms' ); ?>
				</summary>
				<div class="crm-action-block__body">
					<input type="text" id="crm-task-title" class="crm-field"
						placeholder="<?php esc_attr_e( 'Título de la tarea', 'atora-lms' ); ?>">
					<select id="crm-task-type" class="crm-field">
						<?php foreach ( $task_types as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="crm-task-priority" class="crm-field">
						<option value="low"><?php    esc_html_e( 'Baja', 'atora-lms' ); ?></option>
						<option value="medium" selected><?php esc_html_e( 'Media', 'atora-lms' ); ?></option>
						<option value="high"><?php   esc_html_e( 'Alta', 'atora-lms' ); ?></option>
						<option value="urgent"><?php esc_html_e( 'Urgente', 'atora-lms' ); ?></option>
					</select>
					<input type="datetime-local" id="crm-task-due" class="crm-field">
					<textarea id="crm-task-notes" class="crm-field" rows="2"
						placeholder="<?php esc_attr_e( 'Notas (opcional)', 'atora-lms' ); ?>"></textarea>
					<button type="button" class="button button-primary crm-action-btn" data-action="create_task">
						<?php esc_html_e( 'Crear tarea', 'atora-lms' ); ?>
					</button>
				</div>
			</details>

			<?php /* ── Añadir nota ── */ ?>
			<details class="crm-action-block" id="crm-block-note">
				<summary class="crm-action-block__trigger">
					<?php esc_html_e( 'Añadir nota', 'atora-lms' ); ?>
				</summary>
				<div class="crm-action-block__body">
					<textarea id="crm-note-text" class="crm-field" rows="4"
						placeholder="<?php esc_attr_e( 'Escribe una nota interna…', 'atora-lms' ); ?>"></textarea>
					<button type="button" class="button button-primary crm-action-btn" data-action="save_note">
						<?php esc_html_e( 'Guardar nota', 'atora-lms' ); ?>
					</button>
				</div>
			</details>

			<?php /* ── Mover de etapa ── */ ?>
			<details class="crm-action-block" id="crm-block-stage">
				<summary class="crm-action-block__trigger">
					<?php esc_html_e( 'Mover de etapa', 'atora-lms' ); ?>
				</summary>
				<div class="crm-action-block__body">
					<select id="crm-stage-pipeline" class="crm-field">
						<option value="sales"><?php    esc_html_e( 'Pipeline de ventas', 'atora-lms' ); ?></option>
						<option value="academic"><?php esc_html_e( 'Pipeline académico', 'atora-lms' ); ?></option>
					</select>
					<select id="crm-stage-target" class="crm-field">
						<optgroup label="<?php esc_attr_e( 'Ventas', 'atora-lms' ); ?>" data-pipeline="sales">
							<?php foreach ( $sales_stages as $k => $s ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" data-pipeline="sales">
									<?php echo esc_html( $s['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</optgroup>
						<optgroup label="<?php esc_attr_e( 'Académico', 'atora-lms' ); ?>" data-pipeline="academic" hidden>
							<?php foreach ( $academic_stages as $k => $s ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" data-pipeline="academic" hidden>
									<?php echo esc_html( $s['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</optgroup>
					</select>
					<button type="button" class="button button-primary crm-action-btn" data-action="move_stage">
						<?php esc_html_e( 'Mover', 'atora-lms' ); ?>
					</button>
				</div>
			</details>

			<?php /* ── Añadir tag ── */ ?>
			<details class="crm-action-block" id="crm-block-tag">
				<summary class="crm-action-block__trigger">
					<?php esc_html_e( 'Añadir etiqueta', 'atora-lms' ); ?>
				</summary>
				<div class="crm-action-block__body">
					<input type="text" id="crm-tag-input" class="crm-field"
						placeholder="<?php esc_attr_e( '#tag-nombre', 'atora-lms' ); ?>"
						list="crm-tag-datalist" autocomplete="off">
					<datalist id="crm-tag-datalist"></datalist>
					<button type="button" class="button button-primary crm-action-btn" data-action="add_tag">
						<?php esc_html_e( 'Añadir tag', 'atora-lms' ); ?>
					</button>
				</div>
			</details>

			
			<?php /* ─── IA: Resumir contacto (Fase II S5) ─── */ ?>
			<?php if ( $contact_id ) : ?>
			<details class="crm-360-action-block" id="crm-ai-summary-block">
				<summary><?php esc_html_e( '🤖 Resumir contacto (IA)', 'atora-lms' ); ?></summary>
				<div style="padding:10px 0 4px">
					<button type="button"
						class="button crm-action-btn"
						data-action="ai_summary"
						data-contact-id="<?php echo esc_attr( (string) $contact_id ); ?>"
						id="btn-ai-summary">
						<?php esc_html_e( 'Resumir contacto', 'atora-lms' ); ?>
					</button>
					<div id="crm-ai-summary-output" style="margin-top:10px;font-size:13px;color:#334155;background:#f8fafc;border:.5px solid #e2e8f0;border-radius:8px;padding:10px;display:none;white-space:pre-wrap;line-height:1.6"></div>
					<?php if ( class_exists( '\ATORA\CRM_V2\Services\Scoring_Service' ) ) :
						$cs = \ATORA\CRM_V2\Services\Scoring_Service::calculate_score( $contact_id );
						$cs_label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label( $cs );
					?>
					<p style="margin:8px 0 0;font-size:12px;color:#64748b">
						<?php echo esc_html( sprintf( __( 'Score de conversión: %d/100 — %s', 'atora-lms' ), $cs, $cs_label ) ); ?>
					</p>
					<?php endif; ?>
				</div>
			</details>
			<?php endif; ?>

			<?php /* ─── Secuencias activas (Fase 8) ─── */ ?>
			<?php if ( $contact_id ) :
				$active_enrollments = array();
				if ( class_exists( '\ATORA\CRM_V2\Services\Sequence_Service' ) ) {
					$active_enrollments = \ATORA\CRM_V2\Services\Sequence_Service::get_contact_enrollments( $contact_id );
				}
				if ( ! empty( $active_enrollments ) ) :
			?>
			<div class="crm-360-section" id="crm-360-sequences">
				<h4><?php esc_html_e( 'Secuencias activas', 'atora-lms' ); ?></h4>
				<ul class="crm-360-sequences-list">
					<?php foreach ( $active_enrollments as $enrollment ) :
						$seq_name = sanitize_text_field( (string) ( $enrollment['sequence_name'] ?? '' ) );
						$step     = absint( $enrollment['current_step'] ?? 1 );
						$next     = sanitize_text_field( (string) ( $enrollment['next_run_at'] ?? '' ) );
					?>
						<li class="crm-360-seq-item">
							<span class="crm-360-seq-name"><?php echo esc_html( $seq_name ); ?></span>
							<span class="crm-360-seq-step"><?php echo esc_html( sprintf( __( 'Paso %d', 'atora-lms' ), $step ) ); ?></span>
							<?php if ( $next ) : ?>
								<small><?php echo esc_html( sprintf( __( 'Próximo: %s', 'atora-lms' ), $next ) ); ?></small>
							<?php endif; ?>
							<button type="button"
								class="button crm-action-btn"
								data-action="stop_sequence"
								data-sequence-id="<?php echo esc_attr( (string) absint( $enrollment['sequence_id'] ?? 0 ) ); ?>"
								data-contact-id="<?php echo esc_attr( (string) $contact_id ); ?>">
								<?php esc_html_e( 'Detener', 'atora-lms' ); ?>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<?php endif; ?>

<?php /* Tareas pendientes del contacto */ ?>
			<?php if ( ! empty( $contact_360['tasks'] ) ) : ?>
				<div class="crm-360-section crm-360-section--tasks" id="crm-contact-tasks">
					<h4><?php esc_html_e( 'Tareas pendientes', 'atora-lms' ); ?></h4>
					<ul class="atora-crm-v2-checklist" id="crm-tasks-list">
						<?php foreach ( (array) $contact_360['tasks'] as $task ) :
							$task_id    = absint( $task['id'] ?? 0 );
							$task_title = sanitize_text_field( (string) ( $task['title'] ?? '' ) );
							$task_due   = sanitize_text_field( (string) ( $task['due_at'] ?? '' ) );
							$task_done  = ( 'completed' === sanitize_key( (string) ( $task['status'] ?? '' ) ) );
						?>
							<li class="crm-task-item<?php echo $task_done ? ' is-done' : ''; ?>"
								data-task-id="<?php echo esc_attr( (string) $task_id ); ?>">
								<label>
									<input type="checkbox"
										class="crm-task-complete-cb"
										<?php checked( $task_done ); ?>
										data-task-id="<?php echo esc_attr( (string) $task_id ); ?>">
									<span><?php echo esc_html( $task_title ); ?></span>
								</label>
								<?php if ( $task_due ) : ?>
									<small><?php echo esc_html( $task_due ); ?></small>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

		<?php /* ─── Ruta recomendada (Fase III S10) ─── */ ?>
		<?php if ( $contact_id ) :
			$s10_user_id = 0;
			global $wpdb;
			$s10_user_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1", $contact_id )
			);
			$s10_path = null;
			if ( $s10_user_id && class_exists( 'CLMS_Learning_Path' ) ) {
				// Obtener primer curso matriculado
				$s10_course_id = 0;
				if ( class_exists( 'CLMS_Helper' ) ) {
					$s10_courses = ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $s10_user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $s10_user_id ) );
					$s10_course_id = is_array( $s10_courses ) && ! empty( $s10_courses ) ? absint( reset( $s10_courses ) ) : 0;
				}
				if ( $s10_course_id ) {
					$lp = new CLMS_Learning_Path();
					$s10_path = $lp->generate( $s10_user_id, $s10_course_id );
				}
			}
			if ( $s10_path && ! empty( $s10_path['next_lessons'] ) ) :
		?>
		<div class="crm-360-section" id="crm-360-learning-path">
			<h4><?php esc_html_e( 'Ruta recomendada', 'atora-lms' ); ?></h4>
			<ol style="list-style:none;padding:0;margin:0;display:grid;gap:7px">
			<?php foreach ( array_slice( (array) $s10_path['next_lessons'], 0, 3 ) as $i => $lesson ) :
				$title  = sanitize_text_field( (string) ( $lesson['title']  ?? '' ) );
				$reason = sanitize_text_field( (string) ( $lesson['reason'] ?? '' ) );
				$url    = esc_url_raw( (string) ( $lesson['url'] ?? '' ) );
			?>
				<li style="padding:8px 10px;background:#f8fafc;border:.5px solid #e2e8f0;border-radius:9px;font-size:12px">
					<span style="font-weight:600;color:#0f172a"><?php echo esc_html( ($i+1) . '. ' . $title ); ?></span>
					<?php if ( $reason ) : ?>
						<br><span style="color:#64748b"><?php echo esc_html( $reason ); ?></span>
					<?php endif; ?>
					<?php if ( $url ) : ?>
						<br><a href="<?php echo esc_url( $url ); ?>" style="font-size:11px;color:#1d4ed8" target="_blank"><?php esc_html_e( 'Ir a la lección', 'atora-lms' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
			</ol>
			<?php if ( ! empty( $s10_path['summary'] ) ) : ?>
				<p style="font-size:11px;color:#64748b;margin:8px 0 0"><?php echo esc_html( (string) $s10_path['summary'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
			endif;
		endif;
		?>

		<?php endif; // $contact_id ?>
	</aside>

</div>


<style>
.crm-360-sequences-list{list-style:none;display:grid;gap:7px}
.crm-360-seq-item{display:flex;align-items:center;gap:8px;padding:7px 10px;background:#f8fafc;border:.5px solid #e2e8f0;border-radius:9px;flex-wrap:wrap;font-size:12px}
.crm-360-seq-name{font-weight:500;color:#0f172a;flex:1}
.crm-360-seq-step{font-size:11px;padding:2px 7px;background:#eff6ff;color:#1d4ed8;border-radius:999px}
.crm-360-seq-item small{font-size:11px;color:#94a3b8;width:100%}
</style>

<?php require __DIR__ . '/partials/footer.php'; ?>
