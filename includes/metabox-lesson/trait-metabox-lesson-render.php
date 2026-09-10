<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Lesson_Render_Trait {
	public function render_relation( $post ) {
		if ( ! $this->check_post( $post ) ) {
			return;
		}
		$this->shared_assets( $post );

		$course_id = $this->get_meta_int(
			$post->ID,
			array(
				CLMS_Helper::COURSE_META_KEY,
				CLMS_Helper::COURSE_META_KEY_LEGACY,
				self::COURSE_META_FALLBACK_1,
				self::COURSE_META_FALLBACK_2,
			)
		);

		$activity_type    = $this->normalize_activity_type( $this->get_meta( $post->ID, array( 'lm_activity_type', '_clms_activity_mode' ), 'lectura' ) );
		$session_type     = $this->get_meta( $post->ID, array( 'lm_session_type' ), 'asincrono' );
		$show_in_calendar = $this->get_meta( $post->ID, array( 'lm_show_in_calendar' ), '0' );
		$live_provider    = $this->get_meta( $post->ID, array( self::LIVE_CLASS_PROVIDER ), 'zoom' );
		$live_url         = $this->get_meta( $post->ID, array( self::LIVE_CLASS_URL ), '' );
		$live_starts_at   = $this->get_meta( $post->ID, array( self::LIVE_CLASS_STARTS_AT ), '' );
		$live_ends_at     = $this->get_meta( $post->ID, array( self::LIVE_CLASS_ENDS_AT ), '' );
		$live_timezone    = $this->get_meta( $post->ID, array( self::LIVE_CLASS_TIMEZONE ), '' );
		$live_notes       = $this->get_meta( $post->ID, array( self::LIVE_CLASS_NOTES ), '' );
		$lesson_module    = $this->get_meta( $post->ID, array( self::LESSON_MODULE_META ), '' );
		$lesson_order     = (int) get_post_field( 'menu_order', $post->ID );
		$due_date         = $this->get_meta( $post->ID, array( 'lm_due_date', '_clms_due_date' ), '' );
		$due_time         = $this->get_meta( $post->ID, array( 'lm_due_time', '_clms_due_time' ), '' );
		$late_date        = $this->get_meta( $post->ID, array( 'lm_late_date', '_clms_due_date_late' ), '' );
		$late_time        = $this->get_meta( $post->ID, array( 'lm_late_time' ), '' );
		$prerequisite_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_prerequisite_ids( $post->ID ) : array();
		$program_titles   = array();
		if ( '' === trim( $live_timezone ) ) {
			$live_timezone = (string) wp_timezone_string();
			if ( '' === trim( $live_timezone ) ) {
				$offset        = (float) get_option( 'gmt_offset', 0 );
				$offset_hours  = (int) $offset;
				$offset_mins   = (int) round( abs( $offset - $offset_hours ) * 60 );
				$live_timezone = sprintf( 'UTC%+03d:%02d', $offset_hours, $offset_mins );
			}
		}

		if ( $course_id && class_exists( 'CLMS_Helper' ) ) {
			foreach ( CLMS_Helper::get_course_program_ids( $course_id ) as $program_id ) {
				$title = get_the_title( $program_id );

				if ( '' !== trim( $title ) ) {
					$program_titles[] = $title;
				}
			}
		}

		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$lessons = get_posts( array(
			'post_type'      => 'lm_lesson',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => array(
				'title' => 'ASC',
				'date'  => 'ASC',
			),
			'post__not_in'   => array( $post->ID ),
		) );
		?>
		<p class="clms-f">
			<label for="clms_course_id"><?php esc_html_e( 'Curso', 'atora-lms' ); ?></label>
			<select name="clms_course_id" id="clms_course_id">
				<option value="0"><?php esc_html_e( '— Selecciona un curso —', 'atora-lms' ); ?></option>
				<?php foreach ( $courses as $course ) : ?>
					<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $course_id, $course->ID ); ?>>
						<?php echo esc_html( $course->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="clms-f">
			<label for="_clms_lesson_module"><?php esc_html_e( 'Módulo', 'atora-lms' ); ?></label>
			<input type="text" name="<?php echo esc_attr( self::LESSON_MODULE_META ); ?>" id="_clms_lesson_module" value="<?php echo esc_attr( $lesson_module ); ?>" placeholder="<?php echo esc_attr__( 'Ej. Módulo 1 · Fundamentos', 'atora-lms' ); ?>">
			<span class="clms-help"><?php esc_html_e( 'Etiqueta editorial para agrupar lecciones dentro del curso.', 'atora-lms' ); ?></span>
		</p>

		<p class="clms-f">
			<label for="clms_lesson_menu_order"><?php esc_html_e( 'Orden', 'atora-lms' ); ?></label>
			<input type="number" min="0" step="1" name="clms_lesson_menu_order" id="clms_lesson_menu_order" value="<?php echo esc_attr( $lesson_order ); ?>">
			<span class="clms-help"><?php esc_html_e( 'Se usa para ordenar la lección dentro del curso y en listados administrativos.', 'atora-lms' ); ?></span>
		</p>

		<?php if ( ! empty( $program_titles ) ) : ?>
			<p class="clms-f">
				<label><?php esc_html_e( 'Programa heredado', 'atora-lms' ); ?></label>
				<span class="clms-help"><?php echo esc_html( implode( ', ', $program_titles ) ); ?></span>
			</p>
		<?php endif; ?>

		<p class="clms-f">
			<label for="clms_lesson_prerequisite_ids"><?php esc_html_e( 'Prerrequisitos de lección', 'atora-lms' ); ?></label>
			<select name="clms_lesson_prerequisite_ids[]" id="clms_lesson_prerequisite_ids" multiple>
				<?php foreach ( $lessons as $lesson ) : ?>
					<option value="<?php echo esc_attr( $lesson->ID ); ?>" <?php selected( in_array( $lesson->ID, $prerequisite_ids, true ), true ); ?>>
						<?php echo esc_html( $lesson->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="clms-help"><?php esc_html_e( 'Se guarda la dependencia académica sin bloquear todavía el acceso actual.', 'atora-lms' ); ?></span>
		</p>

		<p class="clms-f">
			<label for="lm_activity_type"><?php esc_html_e( 'Tipo de actividad', 'atora-lms' ); ?></label>
			<select name="lm_activity_type" id="lm_activity_type">
				<option value="lectura" <?php selected( $activity_type, 'lectura' ); ?>><?php esc_html_e( 'Lectura', 'atora-lms' ); ?></option>
				<option value="tarea"   <?php selected( $activity_type, 'tarea' ); ?>><?php esc_html_e( 'Tarea', 'atora-lms' ); ?></option>
				<option value="quiz"    <?php selected( $activity_type, 'quiz' ); ?>><?php esc_html_e( 'Evaluación', 'atora-lms' ); ?></option>
			</select>
		</p>

		<?php
		$delivery_task_title    = (string) get_post_meta( $post->ID, 'lm_task_title', true );
		$delivery_mode          = sanitize_key( (string) get_post_meta( $post->ID, '_clms_delivery_mode', true ) );
		$allowed_delivery_modes = array( 'read_only', 'quiz', 'file', 'text', 'both' );

		if ( ! in_array( $delivery_mode, $allowed_delivery_modes, true ) ) {
			if ( 'quiz' === $activity_type ) {
				$delivery_mode = 'quiz';
			} elseif ( '' !== trim( $delivery_task_title ) ) {
				$delivery_mode = 'both';
			} else {
				$delivery_mode = 'read_only';
			}
		}
		?>
		<p class="clms-f">
			<label for="clms_delivery_mode"><?php esc_html_e( 'Tipo de entrega del estudiante', 'atora-lms' ); ?></label>
			<select name="_clms_delivery_mode" id="clms_delivery_mode">
				<option value="read_only" <?php selected( $delivery_mode, 'read_only' ); ?>><?php esc_html_e( 'Solo lectura', 'atora-lms' ); ?></option>
				<option value="quiz" <?php selected( $delivery_mode, 'quiz' ); ?>><?php esc_html_e( 'Quiz', 'atora-lms' ); ?></option>
				<option value="file" <?php selected( $delivery_mode, 'file' ); ?>><?php esc_html_e( 'Entregable (archivo)', 'atora-lms' ); ?></option>
				<option value="text" <?php selected( $delivery_mode, 'text' ); ?>><?php esc_html_e( 'Campo de escritura (texto)', 'atora-lms' ); ?></option>
				<?php if ( 'both' === $delivery_mode ) : ?>
					<option value="both" selected><?php esc_html_e( 'Entregable + texto (legado)', 'atora-lms' ); ?></option>
				<?php endif; ?>
			</select>
			<span class="clms-help"><?php esc_html_e( 'Define qué puede enviar el estudiante: solo lectura (sin entrega), resolver un quiz, subir un archivo (Word, PDF, imágenes...) o escribir una respuesta en texto.', 'atora-lms' ); ?></span>
		</p>

		<p class="clms-f">
			<label for="lm_session_type"><?php esc_html_e( 'Tipo de sesión', 'atora-lms' ); ?></label>
			<select name="lm_session_type" id="lm_session_type">
				<option value="asincrono" <?php selected( $session_type, 'asincrono' ); ?>><?php esc_html_e( 'Asíncrono', 'atora-lms' ); ?></option>
				<option value="sincrono"  <?php selected( $session_type, 'sincrono' ); ?>><?php esc_html_e( 'Síncrono', 'atora-lms' ); ?></option>
				<option value="hibrido"   <?php selected( $session_type, 'hibrido' ); ?>><?php esc_html_e( 'Híbrido', 'atora-lms' ); ?></option>
			</select>
		</p>

		<div class="clms-grid-2">
			<p class="clms-f">
				<label for="_clms_live_class_provider"><?php esc_html_e( 'Proveedor clase en vivo', 'atora-lms' ); ?></label>
				<select name="_clms_live_class_provider" id="_clms_live_class_provider">
					<option value="zoom" <?php selected( $live_provider, 'zoom' ); ?>><?php esc_html_e( 'Zoom', 'atora-lms' ); ?></option>
					<option value="google_meet" <?php selected( $live_provider, 'google_meet' ); ?>><?php esc_html_e( 'Google Meet', 'atora-lms' ); ?></option>
					<option value="youtube_live" <?php selected( $live_provider, 'youtube_live' ); ?>><?php esc_html_e( 'YouTube Live', 'atora-lms' ); ?></option>
					<option value="custom" <?php selected( $live_provider, 'custom' ); ?>><?php esc_html_e( 'Otro enlace', 'atora-lms' ); ?></option>
				</select>
			</p>
			<p class="clms-f">
				<label for="_clms_live_class_timezone"><?php esc_html_e( 'Zona horaria de la clase', 'atora-lms' ); ?></label>
				<input type="text" name="_clms_live_class_timezone" id="_clms_live_class_timezone" value="<?php echo esc_attr( $live_timezone ); ?>" placeholder="<?php echo esc_attr__( 'Ej. America/Caracas', 'atora-lms' ); ?>">
			</p>
			<p class="clms-f full">
				<label for="_clms_live_class_url"><?php esc_html_e( 'Enlace de clase en vivo', 'atora-lms' ); ?></label>
				<input type="url" name="_clms_live_class_url" id="_clms_live_class_url" value="<?php echo esc_attr( $live_url ); ?>" placeholder="https://">
				<span class="clms-help"><?php esc_html_e( 'Si esta lección es síncrona o híbrida, los estudiantes verán este acceso en la vista de la lección.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f">
				<label for="_clms_live_class_starts_at"><?php esc_html_e( 'Inicio de la clase', 'atora-lms' ); ?></label>
				<input type="datetime-local" name="_clms_live_class_starts_at" id="_clms_live_class_starts_at" value="<?php echo esc_attr( $live_starts_at ); ?>">
			</p>
			<p class="clms-f">
				<label for="_clms_live_class_ends_at"><?php esc_html_e( 'Fin de la clase', 'atora-lms' ); ?></label>
				<input type="datetime-local" name="_clms_live_class_ends_at" id="_clms_live_class_ends_at" value="<?php echo esc_attr( $live_ends_at ); ?>">
			</p>
			<p class="clms-f full">
				<label for="_clms_live_class_notes"><?php esc_html_e( 'Notas para estudiantes', 'atora-lms' ); ?></label>
				<textarea name="_clms_live_class_notes" id="_clms_live_class_notes" rows="3" placeholder="<?php echo esc_attr__( 'Ej. Conéctate 10 minutos antes. Trae preguntas listas.', 'atora-lms' ); ?>"><?php echo esc_textarea( $live_notes ); ?></textarea>
			</p>
		</div>

		<p class="clms-f">
			<label for="lm_show_in_calendar"><?php esc_html_e( 'Mostrar en calendario', 'atora-lms' ); ?></label>
			<select name="lm_show_in_calendar" id="lm_show_in_calendar">
				<option value="0" <?php selected( $show_in_calendar, '0' ); ?>><?php esc_html_e( 'No', 'atora-lms' ); ?></option>
				<option value="1" <?php selected( $show_in_calendar, '1' ); ?>><?php esc_html_e( 'Sí', 'atora-lms' ); ?></option>
			</select>
		</p>

		<p style="border-top:1px solid #f0f0f0;margin:14px -12px 14px;"></p>

		<?php
		// Rúbrica de evaluación
		$rubric_id           = absint( get_post_meta( $post->ID, '_clms_rubric_id', true ) );
		$rubrics             = class_exists( 'CLMS_Rubric' ) ? CLMS_Rubric::get_all_rubrics() : array();
		$lesson_competencies = (string) get_post_meta( $post->ID, '_clms_lesson_competencies', true );
		$evidence_type       = sanitize_key( (string) get_post_meta( $post->ID, '_clms_evidence_type', true ) );
		$evidence_required   = '1' === (string) get_post_meta( $post->ID, '_clms_evidence_required_for_certificate', true );
		$evidence_competency_ids = get_post_meta( $post->ID, '_clms_evidence_competency_ids', true );
		$evidence_competency_ids = is_array( $evidence_competency_ids ) ? $evidence_competency_ids : array();
		$evidence_competency_ids_text = implode( "\n", array_map( 'sanitize_key', $evidence_competency_ids ) );
		$evidence_min_grade  = absint( get_post_meta( $post->ID, '_clms_evidence_minimum_grade', true ) );
		$evidence_allow_resubmission = get_post_meta( $post->ID, '_clms_evidence_allow_resubmission', true );
		$evidence_allow_resubmission = '' === (string) $evidence_allow_resubmission ? '1' : ( '1' === (string) $evidence_allow_resubmission ? '1' : '0' );
		$evidence_read_requirement = sanitize_key( (string) get_post_meta( $post->ID, '_clms_evidence_read_requirement', true ) );
		if ( ! in_array( $evidence_read_requirement, array( 'seen', 'comment', 'seen_or_comment' ), true ) ) {
			$evidence_read_requirement = 'seen_or_comment';
		}
		$allowed_evidence_types = array( 'practice', 'assignment', 'partial_exam', 'final_exam', 'certifiable_evidence', 'required', 'read_only' );
		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $post->ID ) ) : 0;
		$competency_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		$course_competencies = ( $competency_service && method_exists( $competency_service, 'get_course_competencies' ) && $course_id )
			? (array) $competency_service->get_course_competencies( $course_id )
			: array();
		$selected_lesson_competency_titles = array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					preg_split( '/\r\n|\r|\n/', (string) $lesson_competencies )
				)
			)
		);
		if ( ! in_array( $evidence_type, $allowed_evidence_types, true ) ) {
			$evidence_type = 'practice';
		}
		?>
		<p class="clms-f">
			<label for="clms_rubric_id"><?php esc_html_e( 'Rúbrica de evaluación', 'atora-lms' ); ?></label>
			<select name="_clms_rubric_id" id="clms_rubric_id">
				<option value="0"><?php esc_html_e( '— Sin rúbrica —', 'atora-lms' ); ?></option>
				<?php foreach ( $rubrics as $rid => $rtitle ) : ?>
					<option value="<?php echo esc_attr( $rid ); ?>" <?php selected( $rubric_id, $rid ); ?>><?php echo esc_html( $rtitle ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) :
				$total_pts = CLMS_Rubric::get_total_points( $rubric_id );
				$num_crit  = count( CLMS_Rubric::get_criteria( $rubric_id ) );
				$criteria_label = sprintf(
					_n( '%d criterio', '%d criterios', $num_crit, 'atora-lms' ),
					$num_crit
				);
				$rubric_summary = sprintf(
					__( '%1$s · %2$s pts totales', 'atora-lms' ),
					$criteria_label,
					$total_pts
				);
			?>
				<span class="clms-help"><?php echo esc_html( $rubric_summary ); ?></span>
			<?php endif; ?>
		</p>
		<p class="clms-f">
			<label for="clms_lesson_competencies"><?php esc_html_e( 'Competencias de la actividad', 'atora-lms' ); ?></label>
			<textarea name="_clms_lesson_competencies" id="clms_lesson_competencies" rows="3" placeholder="<?php echo esc_attr__( 'Una competencia por línea', 'atora-lms' ); ?>"><?php echo esc_textarea( $lesson_competencies ); ?></textarea>
			<span class="clms-help"><?php esc_html_e( 'Estas competencias se usan para seguimiento por competencia en paneles y reportes.', 'atora-lms' ); ?></span>
		</p>
		<?php if ( ! empty( $course_competencies ) ) : ?>
			<div class="clms-f">
				<label><?php esc_html_e( 'Seleccionar desde competencias del curso', 'atora-lms' ); ?></label>
				<div style="display:grid;gap:6px;max-height:160px;overflow:auto;padding:8px;border:1px solid #dcdcde;border-radius:8px;background:#fff">
					<?php foreach ( $course_competencies as $course_competency ) : ?>
						<?php
						$course_competency = is_array( $course_competency ) ? $course_competency : array();
						$title = sanitize_text_field( (string) ( $course_competency['title'] ?? '' ) );
						if ( '' === $title ) {
							continue;
						}
						?>
						<label style="display:flex;gap:8px;align-items:flex-start">
							<input
								type="checkbox"
								name="_clms_lesson_competency_titles[]"
								value="<?php echo esc_attr( $title ); ?>"
								<?php checked( in_array( $title, $selected_lesson_competency_titles, true ) ); ?>
							>
							<span><?php echo esc_html( $title ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<span class="clms-help"><?php esc_html_e( 'Este selector ayuda a evitar errores de escritura. También puedes seguir usando el campo manual.', 'atora-lms' ); ?></span>
			</div>
		<?php endif; ?>
		<p class="clms-f">
			<label for="clms_evidence_type"><?php esc_html_e( 'Tipo de evidencia', 'atora-lms' ); ?></label>
			<select name="_clms_evidence_type" id="clms_evidence_type">
				<option value="practice" <?php selected( $evidence_type, 'practice' ); ?>><?php esc_html_e( 'Práctica', 'atora-lms' ); ?></option>
				<option value="read_only" <?php selected( $evidence_type, 'read_only' ); ?>><?php esc_html_e( 'Solo lectura', 'atora-lms' ); ?></option>
				<option value="assignment" <?php selected( $evidence_type, 'assignment' ); ?>><?php esc_html_e( 'Tarea', 'atora-lms' ); ?></option>
				<option value="partial_exam" <?php selected( $evidence_type, 'partial_exam' ); ?>><?php esc_html_e( 'Evaluación parcial', 'atora-lms' ); ?></option>
				<option value="final_exam" <?php selected( $evidence_type, 'final_exam' ); ?>><?php esc_html_e( 'Evaluación final', 'atora-lms' ); ?></option>
				<option value="certifiable_evidence" <?php selected( $evidence_type, 'certifiable_evidence' ); ?>><?php esc_html_e( 'Evidencia certificable', 'atora-lms' ); ?></option>
				<option value="required" <?php selected( $evidence_type, 'required' ); ?>><?php esc_html_e( 'Requisito obligatorio', 'atora-lms' ); ?></option>
			</select>
		</p>
		<p class="clms-f">
			<label for="clms_evidence_read_requirement"><?php esc_html_e( 'Requisito de lectura', 'atora-lms' ); ?></label>
			<select name="_clms_evidence_read_requirement" id="clms_evidence_read_requirement">
				<option value="seen" <?php selected( $evidence_read_requirement, 'seen' ); ?>><?php esc_html_e( 'Marcar visto', 'atora-lms' ); ?></option>
				<option value="comment" <?php selected( $evidence_read_requirement, 'comment' ); ?>><?php esc_html_e( 'Comentario', 'atora-lms' ); ?></option>
				<option value="seen_or_comment" <?php selected( $evidence_read_requirement, 'seen_or_comment' ); ?>><?php esc_html_e( 'Visto o comentario', 'atora-lms' ); ?></option>
			</select>
		</p>
		<p class="clms-f">
			<label>
				<input type="checkbox" name="_clms_evidence_required_for_certificate" value="1" <?php checked( $evidence_required ); ?>>
				<?php esc_html_e( 'Esta evidencia es obligatoria para certificar', 'atora-lms' ); ?>
			</label>
		</p>
		<p class="clms-f">
			<label for="clms_evidence_competency_ids"><?php esc_html_e( 'IDs de competencias vinculadas', 'atora-lms' ); ?></label>
			<textarea name="_clms_evidence_competency_ids" id="clms_evidence_competency_ids" rows="3" placeholder="<?php echo esc_attr__( 'Un ID por línea (ej. comunicacion_visual_aplicada)', 'atora-lms' ); ?>"><?php echo esc_textarea( $evidence_competency_ids_text ); ?></textarea>
			<span class="clms-help"><?php esc_html_e( 'Opcional. Si se deja vacío, se usan las competencias de la actividad.', 'atora-lms' ); ?></span>
		</p>
		<?php if ( ! empty( $course_competencies ) ) : ?>
			<div class="clms-f">
				<label><?php esc_html_e( 'Vincular competencias a la evidencia', 'atora-lms' ); ?></label>
				<div style="display:grid;gap:6px;max-height:160px;overflow:auto;padding:8px;border:1px solid #dcdcde;border-radius:8px;background:#fff">
					<?php foreach ( $course_competencies as $course_competency ) : ?>
						<?php
						$course_competency = is_array( $course_competency ) ? $course_competency : array();
						$comp_id = sanitize_key( (string) ( $course_competency['id'] ?? '' ) );
						$title   = sanitize_text_field( (string) ( $course_competency['title'] ?? '' ) );
						if ( '' === $comp_id || '' === $title ) {
							continue;
						}
						?>
						<label style="display:flex;gap:8px;align-items:flex-start">
							<input
								type="checkbox"
								name="_clms_evidence_competency_ids_selected[]"
								value="<?php echo esc_attr( $comp_id ); ?>"
								<?php checked( in_array( $comp_id, $evidence_competency_ids, true ) ); ?>
							>
							<span><?php echo esc_html( $title ); ?> <code><?php echo esc_html( $comp_id ); ?></code></span>
						</label>
					<?php endforeach; ?>
				</div>
				<span class="clms-help"><?php esc_html_e( 'Puedes combinar selección visual y IDs manuales. Se guardarán de forma normalizada.', 'atora-lms' ); ?></span>
			</div>
		<?php endif; ?>
		<p class="clms-f">
			<label for="clms_evidence_minimum_grade"><?php esc_html_e( 'Nota mínima para aprobar evidencia', 'atora-lms' ); ?></label>
			<input type="number" min="0" max="100" step="1" name="_clms_evidence_minimum_grade" id="clms_evidence_minimum_grade" value="<?php echo esc_attr( $evidence_min_grade ); ?>">
		</p>
		<p class="clms-f">
			<label for="clms_evidence_allow_resubmission"><?php esc_html_e( 'Permitir reenvío', 'atora-lms' ); ?></label>
			<select name="_clms_evidence_allow_resubmission" id="clms_evidence_allow_resubmission">
				<option value="1" <?php selected( $evidence_allow_resubmission, '1' ); ?>><?php esc_html_e( 'Sí', 'atora-lms' ); ?></option>
				<option value="0" <?php selected( $evidence_allow_resubmission, '0' ); ?>><?php esc_html_e( 'No', 'atora-lms' ); ?></option>
			</select>
		</p>

		<p style="border-top:1px solid #f0f0f0;margin:14px -12px 14px;"></p>

		<p class="clms-f">
			<label for="lm_due_date"><?php esc_html_e( 'Fecha de entrega', 'atora-lms' ); ?></label>
			<input type="date" name="lm_due_date" id="lm_due_date" value="<?php echo esc_attr( $due_date ); ?>">
		</p>
		<p class="clms-f">
			<label for="lm_due_time"><?php esc_html_e( 'Hora de entrega', 'atora-lms' ); ?></label>
			<input type="time" name="lm_due_time" id="lm_due_time" value="<?php echo esc_attr( $due_time ); ?>">
		</p>
		<p class="clms-f">
			<label for="lm_late_date"><?php esc_html_e( 'Fecha límite con retraso', 'atora-lms' ); ?></label>
			<input type="date" name="lm_late_date" id="lm_late_date" value="<?php echo esc_attr( $late_date ); ?>">
		</p>
		<p class="clms-f">
			<label for="lm_late_time"><?php esc_html_e( 'Hora límite con retraso', 'atora-lms' ); ?></label>
			<input type="time" name="lm_late_time" id="lm_late_time" value="<?php echo esc_attr( $late_time ); ?>">
		</p>
		<?php
	}

	// ── METABOX 2: Normal/high — Portada y video ─────────────────────────────

	public function render_visual( $post ) {
		if ( ! $this->check_post( $post ) ) {
			return;
		}
		$this->shared_assets( $post );

		$lesson_subtitle  = $this->get_meta( $post->ID, array( self::LESSON_SUBTITLE_META ), '' );
		$lesson_public_snippet = $this->get_meta( $post->ID, array( self::LESSON_PUBLIC_SNIPPET ), '' );
		$lesson_cover_id  = $this->get_meta_int( $post->ID, array( self::LESSON_COVER_IMAGE_ID ) );
		$lesson_cover_url = $lesson_cover_id ? wp_get_attachment_image_url( $lesson_cover_id, 'medium_large' ) : '';
		$video_count      = $this->get_meta_int( $post->ID, array( self::LESSON_VIDEO_COUNT ) );
		$extra_videos     = get_post_meta( $post->ID, self::LESSON_EXTRA_VIDEOS, true );
		$extra_videos     = is_array( $extra_videos ) ? array_values( $extra_videos ) : array();
		$ui_limit_videos  = $this->get_meta_int( $post->ID, array( self::LESSON_UI_LIMIT_VIDEOS ), 0 );
		$ui_limit_resources = $this->get_meta_int( $post->ID, array( self::LESSON_UI_LIMIT_RESOURCES ), 0 );
		$ui_limit_tips    = $this->get_meta_int( $post->ID, array( self::LESSON_UI_LIMIT_TIPS ), 0 );

		// Migrar datos de campos legacy (video principal + tips de lección) al primer elemento del array
		if ( empty( $extra_videos[0]['url'] ) ) {
			$legacy_url    = $this->get_meta( $post->ID, array( self::LESSON_VIDEO_URL ), '' );
			$legacy_source = $this->get_meta( $post->ID, array( self::LESSON_VIDEO_SOURCE ), 'youtube' );
			if ( $legacy_url ) {
				$extra_videos[0] = array(
					'source'      => $legacy_source,
					'url'         => $legacy_url,
					'description' => '',
					'tip_1'       => $this->get_meta( $post->ID, array( self::LESSON_TIP_1 ), '' ),
					'tip_2'       => $this->get_meta( $post->ID, array( self::LESSON_TIP_2 ), '' ),
					'tip_3'       => $this->get_meta( $post->ID, array( self::LESSON_TIP_3 ), '' ),
				);
			}
		}

		// Mínimo 1 video
		if ( $video_count < 1 ) {
			$video_count = 1;
		}

		$source_opts = array(
			'youtube' => __( 'YouTube', 'atora-lms' ),
			'vimeo'   => __( 'Vimeo', 'atora-lms' ),
			'bunny'   => __( 'Bunny.net', 'atora-lms' ),
			'drive'   => __( 'Google Drive', 'atora-lms' ),
			'url'     => __( 'URL directa (MP4)', 'atora-lms' ),
		);
		?>
		<div class="clms-grid-2">

			<!-- Columna izquierda: subtítulo -->
				<div>
					<p class="clms-f">
						<label for="_clms_lesson_subtitle"><?php esc_html_e( 'Subtítulo / resumen corto', 'atora-lms' ); ?></label>
						<textarea name="_clms_lesson_subtitle" id="_clms_lesson_subtitle" rows="4"><?php echo esc_textarea( $lesson_subtitle ); ?></textarea>
						<span class="clms-help"><?php esc_html_e( 'Aparece en el hero, cards y tienda.', 'atora-lms' ); ?></span>
					</p>
					<p class="clms-f">
						<label for="_clms_lesson_public_snippet"><?php esc_html_e( 'Extracto público (modo ventas)', 'atora-lms' ); ?></label>
						<textarea name="_clms_lesson_public_snippet" id="_clms_lesson_public_snippet" rows="4"><?php echo esc_textarea( $lesson_public_snippet ); ?></textarea>
						<span class="clms-help"><?php esc_html_e( 'Solo se usa en el asistente de ventas y landings públicas.', 'atora-lms' ); ?></span>
					</p>
				</div>

			<!-- Columna derecha: miniatura -->
			<div>
				<p class="clms-f">
					<label><?php esc_html_e( 'Miniatura de la lección', 'atora-lms' ); ?></label>
					<input type="hidden" name="_clms_lesson_cover_image_id" id="_clms_lesson_cover_image_id" value="<?php echo esc_attr( $lesson_cover_id ); ?>">
					<div class="clms-actions">
						<button type="button" class="button" id="clms_pick_lesson_cover"><?php esc_html_e( 'Seleccionar imagen', 'atora-lms' ); ?></button>
						<button type="button" class="button" id="clms_clear_lesson_cover"><?php esc_html_e( 'Quitar', 'atora-lms' ); ?></button>
					</div>
					<div class="clms-cover-preview" id="clms_cover_preview">
						<?php if ( $lesson_cover_url ) : ?>
							<img src="<?php echo esc_url( $lesson_cover_url ); ?>" alt="">
						<?php endif; ?>
					</div>
					<span class="clms-help"><?php esc_html_e( '1600×900 para hero · 1200×675 para cards.', 'atora-lms' ); ?></span>
				</p>
			</div>

			<!-- Fila completa: videos -->
			<div class="full">
				<p class="clms-section-title"><?php esc_html_e( 'Videos', 'atora-lms' ); ?></p>
				<p class="clms-f" style="max-width:180px">
					<label for="_clms_lesson_video_count"><?php esc_html_e( 'Cantidad de videos', 'atora-lms' ); ?></label>
					<input type="number" min="1" step="1" name="<?php echo esc_attr( self::LESSON_VIDEO_COUNT ); ?>" id="_clms_lesson_video_count" value="<?php echo esc_attr( $video_count ); ?>">
				</p>

				<p class="clms-section-title"><?php esc_html_e( 'Límites de vista (opcional)', 'atora-lms' ); ?></p>
				<div class="clms-grid-2">
					<p class="clms-f">
						<label for="_clms_lesson_ui_limit_videos"><?php esc_html_e( 'Máximo de videos visibles', 'atora-lms' ); ?></label>
						<input type="number" min="0" step="1" name="<?php echo esc_attr( self::LESSON_UI_LIMIT_VIDEOS ); ?>" id="_clms_lesson_ui_limit_videos" value="<?php echo esc_attr( $ui_limit_videos ); ?>">
						<span class="clms-help"><?php esc_html_e( '0 = sin límite. Útil para clases fragmentadas.', 'atora-lms' ); ?></span>
					</p>
					<p class="clms-f">
						<label for="_clms_lesson_ui_limit_tips"><?php esc_html_e( 'Máximo de puntos clave visibles', 'atora-lms' ); ?></label>
						<input type="number" min="0" step="1" name="<?php echo esc_attr( self::LESSON_UI_LIMIT_TIPS ); ?>" id="_clms_lesson_ui_limit_tips" value="<?php echo esc_attr( $ui_limit_tips ); ?>">
						<span class="clms-help"><?php esc_html_e( '0 = sin límite. Se aplica a tips del video y del legacy.', 'atora-lms' ); ?></span>
					</p>
					<p class="clms-f">
						<label for="_clms_lesson_ui_limit_resources"><?php esc_html_e( 'Máximo de recursos visibles', 'atora-lms' ); ?></label>
						<input type="number" min="0" step="1" name="<?php echo esc_attr( self::LESSON_UI_LIMIT_RESOURCES ); ?>" id="_clms_lesson_ui_limit_resources" value="<?php echo esc_attr( $ui_limit_resources ); ?>">
						<span class="clms-help"><?php esc_html_e( '0 = sin límite. Se mostrarán primero los recursos en orden.', 'atora-lms' ); ?></span>
					</p>
				</div>
				<p class="clms-help"><?php esc_html_e( 'Nota: estos límites solo controlan lo que se muestra al alumno, no eliminan contenido.', 'atora-lms' ); ?></p>

				<!-- Tarjetas de video: PHP pre-rellena las guardadas; JS añade/quita según el contador -->
				<div id="clms_video_cards_wrap">
					<?php for ( $vi = 0; $vi < $video_count; $vi++ ) :
						$vd          = isset( $extra_videos[ $vi ] ) && is_array( $extra_videos[ $vi ] ) ? $extra_videos[ $vi ] : array();
						$vd_source   = isset( $vd['source'] ) ? $vd['source'] : 'youtube';
						$vd_url      = isset( $vd['url'] ) ? $vd['url'] : '';
						$vd_desc     = isset( $vd['description'] ) ? $vd['description'] : '';
						$vd_tip1     = isset( $vd['tip_1'] ) ? $vd['tip_1'] : '';
						$vd_tip2     = isset( $vd['tip_2'] ) ? $vd['tip_2'] : '';
						$vd_tip3     = isset( $vd['tip_3'] ) ? $vd['tip_3'] : '';
						$vd_thumb_id  = isset( $vd['thumb_id'] ) ? absint( $vd['thumb_id'] ) : 0;
						$vd_thumb_url = $vd_thumb_id ? wp_get_attachment_image_url( $vd_thumb_id, 'medium' ) : '';
						if ( ! $vd_thumb_url && 'youtube' === $vd_source && $vd_url ) {
							if ( preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $vd_url, $ym ) ) {
								$vd_thumb_url = 'https://img.youtube.com/vi/' . $ym[1] . '/mqdefault.jpg';
							}
						}
						$vd_embed  = '';
						if ( $vd_url ) {
							if ( 'url' === $vd_source ) {
								$vd_embed = '<video controls style="width:100%;border-radius:6px;background:#000"><source src="' . esc_url( $vd_url ) . '"></video>';
							} else {
								$es = '';
								if ( 'youtube' === $vd_source && preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $vd_url, $m ) ) {
									$es = 'https://www.youtube-nocookie.com/embed/' . $m[1];
								} elseif ( 'vimeo' === $vd_source && preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $vd_url, $m ) ) {
									$es = 'https://player.vimeo.com/video/' . $m[1];
								} elseif ( 'bunny' === $vd_source ) {
									$es = $vd_url;
								} elseif ( 'drive' === $vd_source ) {
									if ( preg_match( '#/file/d/([a-zA-Z0-9_-]+)#', $vd_url, $m ) ) {
										$es = 'https://drive.google.com/file/d/' . $m[1] . '/preview';
									} elseif ( preg_match( '#[?&]id=([a-zA-Z0-9_-]+)#', $vd_url, $m ) ) {
										$es = 'https://drive.google.com/file/d/' . $m[1] . '/preview';
									}
								}
								if ( $es ) {
									$vd_embed = '<div class="clms-video-ratio"><iframe src="' . esc_url( $es ) . '" allowfullscreen loading="lazy"></iframe></div>';
								}
							}
						}
						?>
						<details class="clms-video-card" open>
							<summary class="clms-video-card-title"><?php echo esc_html( sprintf( __( 'Video %s', 'atora-lms' ), $vi + 1 ) ); ?></summary>
							<div class="clms-video-card-body clms-grid-2">
								<p class="clms-f">
									<label><?php esc_html_e( 'Fuente', 'atora-lms' ); ?></label>
									<select name="_clms_lesson_extra_videos[<?php echo esc_attr( $vi ); ?>][source]">
										<?php foreach ( $source_opts as $sv => $sl ) : ?>
											<option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $vd_source, $sv ); ?>><?php echo esc_html( $sl ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<p class="clms-f">
									<label><?php esc_html_e( 'URL', 'atora-lms' ); ?></label>
									<input type="url" name="_clms_lesson_extra_videos[<?php echo esc_attr( $vi ); ?>][url]" value="<?php echo esc_attr( $vd_url ); ?>" placeholder="https://...">
									<span class="clms-help"><?php esc_html_e( 'YouTube / Vimeo: URL completa. Bunny.net: URL del iframe. MP4: URL directa.', 'atora-lms' ); ?></span>
								</p>
									<div class="clms-f full">
										<label><?php esc_html_e( 'Descripción del video', 'atora-lms' ); ?></label>
										<?php
										$video_desc_editor_id = 'clms_video_desc_' . $vi;
										wp_editor(
											$vd_desc,
											$video_desc_editor_id,
											array(
												'textarea_name' => '_clms_lesson_extra_videos[' . $vi . '][description]',
												'textarea_rows' => 7,
												'editor_class'  => 'clms-video-desc-editor',
												'media_buttons' => false,
												'teeny'         => true,
												'tinymce'       => array(
													'wpautop'  => true,
													'toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,undo,redo',
													'toolbar2' => '',
													'menubar'  => false,
												),
												'quicktags'     => array(
													'buttons' => 'strong,em,link,ul,li,close',
												),
											)
										);
										?>
										<span class="clms-help"><?php esc_html_e( 'Acepta HTML: <strong>, <em>, <a href="...">, <ul><li>, <p>.', 'atora-lms' ); ?></span>
									</div>
								<p class="clms-f">
									<label><?php esc_html_e( 'Tip 1', 'atora-lms' ); ?></label>
									<input type="text" name="_clms_lesson_extra_videos[<?php echo esc_attr( $vi ); ?>][tip_1]" value="<?php echo esc_attr( $vd_tip1 ); ?>">
								</p>
								<p class="clms-f">
									<label><?php esc_html_e( 'Tip 2', 'atora-lms' ); ?></label>
									<input type="text" name="_clms_lesson_extra_videos[<?php echo esc_attr( $vi ); ?>][tip_2]" value="<?php echo esc_attr( $vd_tip2 ); ?>">
								</p>
								<p class="clms-f">
									<label><?php esc_html_e( 'Tip 3', 'atora-lms' ); ?></label>
									<input type="text" name="_clms_lesson_extra_videos[<?php echo esc_attr( $vi ); ?>][tip_3]" value="<?php echo esc_attr( $vd_tip3 ); ?>">
								</p>
								<p class="clms-f full">
									<label><?php esc_html_e( 'Miniatura del video', 'atora-lms' ); ?></label>
									<input type="hidden" class="clms-video-thumb-id" name="_clms_lesson_extra_videos[<?php echo esc_attr( $vi ); ?>][thumb_id]" value="<?php echo esc_attr( $vd_thumb_id ); ?>">
									<div class="clms-video-thumb-wrap">
										<img class="clms-video-thumb-img" src="<?php echo esc_url( $vd_thumb_url ); ?>" alt=""<?php echo $vd_thumb_url ? '' : ' style="display:none"'; ?>>
										<div>
											<span class="clms-help"><?php esc_html_e( 'YouTube: se detecta automáticamente. Para otras fuentes puedes subirla manualmente.', 'atora-lms' ); ?></span>
											<div class="clms-actions">
												<button type="button" class="button clms-pick-video-thumb"><?php esc_html_e( 'Seleccionar imagen', 'atora-lms' ); ?></button>
												<button type="button" class="button clms-clear-video-thumb"><?php esc_html_e( 'Quitar', 'atora-lms' ); ?></button>
											</div>
										</div>
									</div>
								</p>
								<div class="full clms-card-preview"<?php echo $vd_embed ? '' : ' style="display:none"'; ?>>
									<?php echo $vd_embed; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</div>
							</div>
						</details>
					<?php endfor; ?>
				</div>
			</div>

		</div>
		<?php
	}

	// ── METABOX 3: Normal — Recursos de apoyo ────────────────────────────────

	public function render_resources( $post ) {
		if ( ! $this->check_post( $post ) ) {
			return;
		}
		$this->shared_assets( $post );

		$resources = get_post_meta( $post->ID, self::LESSON_SUPPORT_RESOURCES, true );
		$resources = is_array( $resources ) ? array_values( $resources ) : array();
		if ( empty( $resources ) ) {
			$resources = array( array() );
		}

		$blank = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
		?>
		<div id="clms_resources_wrap" class="clms-resources-wrap">
			<?php foreach ( $resources as $index => $resource ) :
				$resource      = is_array( $resource ) ? $resource : array();
				$r_title       = isset( $resource['title'] ) ? $resource['title'] : '';
				$r_description = isset( $resource['description'] ) ? $resource['description'] : '';
				$r_type        = isset( $resource['type'] ) ? $resource['type'] : 'pdf';
				$r_file_id     = isset( $resource['file_id'] ) ? absint( $resource['file_id'] ) : 0;
				$r_url         = isset( $resource['url'] ) ? $resource['url'] : '';
				$r_thumb_id    = isset( $resource['thumb_id'] ) ? absint( $resource['thumb_id'] ) : 0;
				$r_file_label  = $r_file_id ? get_the_title( $r_file_id ) : '';
				$r_thumb_url   = $r_thumb_id ? wp_get_attachment_image_url( $r_thumb_id, 'thumbnail' ) : '';
				?>
				<div class="clms-resource-row">
					<div class="clms-resource-grid">
						<p class="clms-f">
							<label><?php esc_html_e( 'Título', 'atora-lms' ); ?></label>
							<input type="text" name="clms_lesson_resources[<?php echo esc_attr( $index ); ?>][title]" value="<?php echo esc_attr( $r_title ); ?>">
						</p>
						<p class="clms-f">
							<label><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></label>
							<select name="clms_lesson_resources[<?php echo esc_attr( $index ); ?>][type]">
								<option value="pdf"          <?php selected( $r_type, 'pdf' ); ?>><?php esc_html_e( 'PDF', 'atora-lms' ); ?></option>
								<option value="guia"         <?php selected( $r_type, 'guia' ); ?>><?php esc_html_e( 'Guía', 'atora-lms' ); ?></option>
								<option value="presentacion" <?php selected( $r_type, 'presentacion' ); ?>><?php esc_html_e( 'Presentación', 'atora-lms' ); ?></option>
								<option value="audio"        <?php selected( $r_type, 'audio' ); ?>><?php esc_html_e( 'Audio', 'atora-lms' ); ?></option>
								<option value="video"        <?php selected( $r_type, 'video' ); ?>><?php esc_html_e( 'Video', 'atora-lms' ); ?></option>
								<option value="link"         <?php selected( $r_type, 'link' ); ?>><?php esc_html_e( 'Enlace', 'atora-lms' ); ?></option>
								<option value="archivo"      <?php selected( $r_type, 'archivo' ); ?>><?php esc_html_e( 'Archivo', 'atora-lms' ); ?></option>
							</select>
						</p>
						<p class="clms-f full">
							<label><?php esc_html_e( 'Descripción', 'atora-lms' ); ?></label>
							<textarea name="clms_lesson_resources[<?php echo esc_attr( $index ); ?>][description]" rows="2"><?php echo esc_textarea( $r_description ); ?></textarea>
						</p>
						<p class="clms-f">
							<label><?php esc_html_e( 'Archivo', 'atora-lms' ); ?></label>
							<div class="clms-dropzone" data-clms-dropzone="resource-file" tabindex="0" role="button" aria-label="<?php echo esc_attr__( 'Arrastra el archivo aquí', 'atora-lms' ); ?>">
								<span class="clms-dropzone-title"><?php esc_html_e( 'Arrastra el archivo aquí', 'atora-lms' ); ?></span>
								<span class="clms-dropzone-sub"><?php esc_html_e( 'o haz clic para seleccionar en la biblioteca', 'atora-lms' ); ?></span>
							</div>
							<input type="hidden" class="clms-resource-file-id" name="clms_lesson_resources[<?php echo esc_attr( $index ); ?>][file_id]" value="<?php echo esc_attr( $r_file_id ); ?>">
							<input type="text" class="clms-resource-file-label" value="<?php echo esc_attr( $r_file_label ); ?>" readonly>
							<div class="clms-actions">
								<button type="button" class="button clms-pick-resource-file"><?php esc_html_e( 'Seleccionar archivo', 'atora-lms' ); ?></button>
								<button type="button" class="button clms-clear-resource-file"><?php esc_html_e( 'Quitar', 'atora-lms' ); ?></button>
							</div>
						</p>
						<p class="clms-f">
							<label><?php esc_html_e( 'URL externa', 'atora-lms' ); ?></label>
							<input type="url" name="clms_lesson_resources[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $r_url ); ?>">
							<span class="clms-help"><?php esc_html_e( 'Si el recurso vive fuera de WordPress.', 'atora-lms' ); ?></span>
						</p>
						<p class="clms-f full">
							<label><?php esc_html_e( 'Miniatura / arte del recurso', 'atora-lms' ); ?></label>
							<input type="hidden" class="clms-resource-thumb-id" name="clms_lesson_resources[<?php echo esc_attr( $index ); ?>][thumb_id]" value="<?php echo esc_attr( $r_thumb_id ); ?>">
							<div class="clms-actions">
								<button type="button" class="button clms-pick-resource-thumb"><?php esc_html_e( 'Seleccionar miniatura', 'atora-lms' ); ?></button>
								<button type="button" class="button clms-clear-resource-thumb"><?php esc_html_e( 'Quitar', 'atora-lms' ); ?></button>
								<button type="button" class="button-link-delete clms-remove-resource-row"><?php esc_html_e( 'Eliminar recurso', 'atora-lms' ); ?></button>
							</div>
							<div class="clms-resource-preview">
								<img class="clms-resource-thumb-preview" src="<?php echo esc_url( $r_thumb_url ? $r_thumb_url : $blank ); ?>" alt="">
							</div>
						</p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<p style="margin-top:10px">
			<button type="button" class="button button-secondary" id="clms_add_resource"><?php esc_html_e( '+ Añadir recurso', 'atora-lms' ); ?></button>
		</p>
		<?php
	}

	// ── METABOX 4: Normal — Evaluaciones y banco AI ──────────────────────────

	public function render_evaluation( $post ) {
		if ( ! $this->check_post( $post ) ) {
			return;
		}
		$this->shared_assets( $post );

		$activity_type          = $this->normalize_activity_type( $this->get_meta( $post->ID, array( 'lm_activity_type', '_clms_activity_mode' ), 'lectura' ) );
		$quiz_enabled_raw       = strtolower( trim( (string) $this->get_meta( $post->ID, array( self::QUIZ_ENABLED_META_KEY ), '0' ) ) );
		$quiz_enabled_bool      = in_array( $quiz_enabled_raw, array( '1', 'yes', 'true' ), true );
		$quiz_enabled           = $quiz_enabled_bool ? '1' : '0';
		$peer_review_enabled    = $this->get_meta( $post->ID, array( '_clms_peer_review_enabled' ), '0' );
		$peer_reviews_per_student = absint( $this->get_meta( $post->ID, array( '_clms_peer_reviews_per_student' ), '2' ) );
		$assessment_mode        = $this->get_meta( $post->ID, array( '_clms_evaluation_mode' ), ( '1' === $peer_review_enabled ? 'peer_review' : 'manual' ) );
		$ai_confidence_threshold = $this->get_meta( $post->ID, array( '_clms_ai_confidence_threshold' ), '0.75' );
		$ai_confidence_thresholds = get_post_meta( $post->ID, '_clms_ai_confidence_thresholds', true );
		$ai_confidence_thresholds = is_array( $ai_confidence_thresholds ) ? $ai_confidence_thresholds : array();
		$ai_confidence_reading    = isset( $ai_confidence_thresholds['lectura'] ) ? (float) $ai_confidence_thresholds['lectura'] : (float) $ai_confidence_threshold;
		$ai_confidence_task       = isset( $ai_confidence_thresholds['tarea'] ) ? (float) $ai_confidence_thresholds['tarea'] : (float) $ai_confidence_threshold;
		$ai_confidence_quiz       = isset( $ai_confidence_thresholds['quiz'] ) ? (float) $ai_confidence_thresholds['quiz'] : (float) $ai_confidence_threshold;
		$quiz_time_limit        = $this->get_meta( $post->ID, array( '_lm_quiz_time_limit' ), '0' );
		$quiz_max_attempts      = $this->get_meta( $post->ID, array( '_lm_quiz_max_attempts' ), '0' );
		$quiz_show_results      = $this->get_meta( $post->ID, array( '_lm_quiz_show_results' ), 'yes' );
		$quiz_enable_dates      = $this->get_meta( $post->ID, array( '_lm_quiz_enable_dates' ), 'no' );
		$grade_scale            = $this->get_meta( $post->ID, array( '_lm_grade_scale' ), '0_20' );
		$passing_threshold      = $this->get_meta( $post->ID, array( '_lm_passing_threshold', '_clms_quiz_passing_score' ), '' );
		$min_score              = $this->get_meta( $post->ID, array( 'lm_min_score' ), '' );
		$max_score              = $this->get_meta( $post->ID, array( 'lm_max_score' ), '' );
		$quiz_base_text         = $this->get_meta( $post->ID, array( '_lm_quiz_base_text' ), '' );
		$extra_material         = $this->get_meta( $post->ID, array( 'lm_extra_material' ), '' );
		$quiz_randomize         = $this->get_meta( $post->ID, array( '_lm_quiz_randomize' ), 'no' );
		$quiz_num_questions     = $this->get_meta( $post->ID, array( '_lm_quiz_num_questions' ), '0' );

		$task_title       = $this->get_meta( $post->ID, array( 'lm_task_title' ), '' );
		$task_description = $this->get_meta( $post->ID, array( 'lm_task_description', '_clms_task_instructions' ), '' );

		$ai_source_mode   = $this->get_meta( $post->ID, array( self::AI_SOURCE_MODE_META ), 'text' );
		$ai_guide_file_id = $this->get_meta_int( $post->ID, array( self::AI_GUIDE_ATTACHMENT ) );
		$ai_source_label  = $this->get_meta( $post->ID, array( self::AI_GUIDE_SOURCE_LABEL ), '' );
		$ai_generated_at  = $this->get_meta( $post->ID, array( self::AI_GENERATED_AT_META ), '' );
		$ai_bank_size     = $this->get_meta_int( $post->ID, array( self::AI_BANK_SIZE_META ) );

			$quiz_questions = get_post_meta( $post->ID, self::QUIZ_QUESTIONS_META_KEY, true );
			$quiz_questions = is_array( $quiz_questions ) ? array_values( $quiz_questions ) : array();
			$quiz_count     = count( $quiz_questions );
			$show_quiz_context = ( 'quiz' === $activity_type ) || $quiz_enabled_bool || $quiz_count > 0;

		$guide_url   = $ai_guide_file_id ? wp_get_attachment_url( $ai_guide_file_id ) : '';
		$guide_title = $ai_guide_file_id ? get_the_title( $ai_guide_file_id ) : '';

		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_clear_ai_quiz_bank&post_id=' . absint( $post->ID ) ),
			'clms_clear_ai_quiz_bank_' . $post->ID
		);
		?>

		<div class="clms-eval-module clms-eval-task-section">
			<p class="clms-section-title" style="margin-top:0"><?php esc_html_e( 'Tarea / asignación del estudiante', 'atora-lms' ); ?></p>
			<div class="clms-grid-2">
				<p class="clms-f">
					<label for="lm_task_title"><?php esc_html_e( 'Título de tarea', 'atora-lms' ); ?></label>
					<input type="text" name="lm_task_title" id="lm_task_title" value="<?php echo esc_attr( $task_title ); ?>">
					<span class="clms-help"><?php esc_html_e( 'Deja vacío si esta lección no tiene tarea.', 'atora-lms' ); ?></span>
				</p>
				<div></div>
				<p class="clms-f full">
					<label for="lm_task_description"><?php esc_html_e( 'Instrucciones', 'atora-lms' ); ?></label>
					<textarea name="lm_task_description" id="lm_task_description" rows="5"><?php echo esc_textarea( $task_description ); ?></textarea>
					<span class="clms-help"><?php esc_html_e( 'Estas instrucciones se muestran al estudiante junto al formulario de entrega.', 'atora-lms' ); ?></span>
				</p>
			</div>
		</div>

		<div class="clms-eval-module clms-eval-quiz-section">
		<p class="clms-section-title" style="margin-top:0"><?php esc_html_e( 'Motor de evaluación', 'atora-lms' ); ?></p>
		<div class="clms-eval-banner">
			<strong><?php esc_html_e( 'Configuración unificada', 'atora-lms' ); ?></strong>
			<span><?php esc_html_e( 'Aquí solo ves campos que gobiernan el flujo real. Los metadatos legacy se sincronizan automáticamente para evitar confusiones.', 'atora-lms' ); ?></span>
		</div>
		<div class="clms-grid-2">
			<p class="clms-f">
				<label for="_clms_quiz_enabled"><?php esc_html_e( 'Quiz habilitado para estudiantes', 'atora-lms' ); ?></label>
				<select name="_clms_quiz_enabled" id="_clms_quiz_enabled">
					<option value="0" <?php selected( $quiz_enabled, '0' ); ?>><?php esc_html_e( 'No', 'atora-lms' ); ?></option>
					<option value="1" <?php selected( $quiz_enabled, '1' ); ?>><?php esc_html_e( 'Sí', 'atora-lms' ); ?></option>
				</select>
				<span class="clms-help"><?php esc_html_e( 'Si está desactivado, el estudiante no verá el formulario del quiz.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f">
				<label for="_clms_evaluation_mode"><?php esc_html_e( 'Modo de evaluación', 'atora-lms' ); ?></label>
				<select name="_clms_evaluation_mode" id="_clms_evaluation_mode">
					<option value="manual" <?php selected( $assessment_mode, 'manual' ); ?>><?php esc_html_e( 'Manual', 'atora-lms' ); ?></option>
					<option value="group" <?php selected( $assessment_mode, 'group' ); ?>><?php esc_html_e( 'Trabajo en grupo', 'atora-lms' ); ?></option>
					<option value="ai_assisted" <?php selected( $assessment_mode, 'ai_assisted' ); ?>><?php esc_html_e( 'IA asistida', 'atora-lms' ); ?></option>
					<option value="ai_auto_grade" <?php selected( $assessment_mode, 'ai_auto_grade' ); ?>><?php esc_html_e( 'IA automática', 'atora-lms' ); ?></option>
					<option value="peer_review" <?php selected( $assessment_mode, 'peer_review' ); ?>><?php esc_html_e( 'Revisión entre pares', 'atora-lms' ); ?></option>
					<option value="hybrid" <?php selected( $assessment_mode, 'hybrid' ); ?>><?php esc_html_e( 'Híbrido', 'atora-lms' ); ?></option>
				</select>
				<span class="clms-help" id="clms-eval-mode-summary" aria-live="polite"></span>
			</p>
			<p class="clms-f clms-eval-ai-only">
				<label for="_clms_ai_confidence_threshold"><?php esc_html_e( 'Umbral de confianza IA', 'atora-lms' ); ?></label>
				<input type="number" min="0.50" max="0.99" step="0.01" name="_clms_ai_confidence_threshold" id="_clms_ai_confidence_threshold" value="<?php echo esc_attr( $ai_confidence_threshold ); ?>">
				<span class="clms-help"><?php esc_html_e( 'Si la confianza es menor, la nota automática se escala a revisión manual.', 'atora-lms' ); ?></span>
			</p>
			<div class="clms-f full clms-eval-ai-only">
				<label><?php esc_html_e( 'Umbrales IA por tipo de actividad', 'atora-lms' ); ?></label>
				<div class="clms-grid-3">
					<p class="clms-f">
						<label for="_clms_ai_confidence_threshold_lectura"><?php esc_html_e( 'Lectura', 'atora-lms' ); ?></label>
						<input type="number" min="0.50" max="0.99" step="0.01" name="_clms_ai_confidence_thresholds[lectura]" id="_clms_ai_confidence_threshold_lectura" value="<?php echo esc_attr( $ai_confidence_reading ); ?>">
					</p>
					<p class="clms-f">
						<label for="_clms_ai_confidence_threshold_tarea"><?php esc_html_e( 'Tarea', 'atora-lms' ); ?></label>
						<input type="number" min="0.50" max="0.99" step="0.01" name="_clms_ai_confidence_thresholds[tarea]" id="_clms_ai_confidence_threshold_tarea" value="<?php echo esc_attr( $ai_confidence_task ); ?>">
					</p>
					<p class="clms-f">
						<label for="_clms_ai_confidence_threshold_quiz"><?php esc_html_e( 'Quiz', 'atora-lms' ); ?></label>
						<input type="number" min="0.50" max="0.99" step="0.01" name="_clms_ai_confidence_thresholds[quiz]" id="_clms_ai_confidence_threshold_quiz" value="<?php echo esc_attr( $ai_confidence_quiz ); ?>">
					</p>
				</div>
				<span class="clms-help"><?php esc_html_e( 'Se usa el umbral del tipo de actividad cuando exista; si no, ATORA conserva el umbral general de la lección.', 'atora-lms' ); ?></span>
			</div>
			<p class="clms-f clms-eval-peer-only">
				<label for="_clms_peer_review_enabled"><?php esc_html_e( 'Revisión entre pares', 'atora-lms' ); ?></label>
				<select name="_clms_peer_review_enabled" id="_clms_peer_review_enabled">
					<option value="0" <?php selected( $peer_review_enabled, '0' ); ?>><?php esc_html_e( 'No', 'atora-lms' ); ?></option>
					<option value="1" <?php selected( $peer_review_enabled, '1' ); ?>><?php esc_html_e( 'Sí', 'atora-lms' ); ?></option>
				</select>
			</p>
			<p class="clms-f clms-eval-peer-only">
				<label for="_clms_peer_reviews_per_student"><?php esc_html_e( 'Revisiones por alumno', 'atora-lms' ); ?></label>
				<input type="number" name="_clms_peer_reviews_per_student" id="_clms_peer_reviews_per_student"
					min="1" max="5" step="1"
					value="<?php echo esc_attr( $peer_reviews_per_student ); ?>">
				<span class="clms-help"><?php esc_html_e( 'Cantidad de revisores asignados por estudiante en esta lección.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f full clms-eval-quiz-inactive-note<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? ' clms-is-hidden' : ''; ?>">
				<span class="clms-help clms-help-highlight"><?php esc_html_e( 'Para configurar la experiencia del quiz, usa tipo de actividad "Evaluación" y habilita el quiz.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_quiz_num_questions"><?php esc_html_e( 'Preguntas aleatorias', 'atora-lms' ); ?></label>
				<input type="number" min="0" step="1" name="_lm_quiz_num_questions" id="_lm_quiz_num_questions" value="<?php echo esc_attr( $quiz_num_questions ); ?>">
				<span class="clms-help"><?php esc_html_e( '0 = usar todas las del banco.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_quiz_randomize"><?php esc_html_e( 'Aleatorizar preguntas', 'atora-lms' ); ?></label>
				<select name="_lm_quiz_randomize" id="_lm_quiz_randomize">
					<option value="no"  <?php selected( $quiz_randomize, 'no' ); ?>><?php esc_html_e( 'No', 'atora-lms' ); ?></option>
					<option value="yes" <?php selected( $quiz_randomize, 'yes' ); ?>><?php esc_html_e( 'Sí', 'atora-lms' ); ?></option>
				</select>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_quiz_time_limit"><?php esc_html_e( 'Tiempo límite (min.)', 'atora-lms' ); ?></label>
				<input type="number" min="0" step="1" name="_lm_quiz_time_limit" id="_lm_quiz_time_limit" value="<?php echo esc_attr( $quiz_time_limit ); ?>">
				<span class="clms-help"><?php esc_html_e( '0 = sin límite de tiempo.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_quiz_max_attempts"><?php esc_html_e( 'Intentos máximos', 'atora-lms' ); ?></label>
				<input type="number" min="0" step="1" name="_lm_quiz_max_attempts" id="_lm_quiz_max_attempts" value="<?php echo esc_attr( $quiz_max_attempts ); ?>">
				<span class="clms-help"><?php esc_html_e( '0 = sin límite.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_quiz_show_results"><?php esc_html_e( 'Mostrar detalle de resultados al alumno', 'atora-lms' ); ?></label>
				<select name="_lm_quiz_show_results" id="_lm_quiz_show_results">
					<option value="yes" <?php selected( $quiz_show_results, 'yes' ); ?>><?php esc_html_e( 'Sí', 'atora-lms' ); ?></option>
					<option value="no"  <?php selected( $quiz_show_results, 'no' ); ?>><?php esc_html_e( 'No', 'atora-lms' ); ?></option>
				</select>
				<span class="clms-help"><?php esc_html_e( 'Controla la vista de retroalimentación detallada por pregunta al finalizar el intento.', 'atora-lms' ); ?></span>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_quiz_enable_dates"><?php esc_html_e( 'Restringir por fechas', 'atora-lms' ); ?></label>
				<select name="_lm_quiz_enable_dates" id="_lm_quiz_enable_dates">
					<option value="no"  <?php selected( $quiz_enable_dates, 'no' ); ?>><?php esc_html_e( 'No', 'atora-lms' ); ?></option>
					<option value="yes" <?php selected( $quiz_enable_dates, 'yes' ); ?>><?php esc_html_e( 'Sí', 'atora-lms' ); ?></option>
				</select>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_grade_scale"><?php esc_html_e( 'Escala', 'atora-lms' ); ?></label>
				<select name="_lm_grade_scale" id="_lm_grade_scale">
					<option value="0_10"    <?php selected( $grade_scale, '0_10' ); ?>><?php esc_html_e( '0 a 10', 'atora-lms' ); ?></option>
					<option value="0_20"    <?php selected( $grade_scale, '0_20' ); ?>><?php esc_html_e( '0 a 20', 'atora-lms' ); ?></option>
					<option value="0_100"   <?php selected( $grade_scale, '0_100' ); ?>><?php esc_html_e( '0 a 100', 'atora-lms' ); ?></option>
					<option value="a_f"     <?php selected( $grade_scale, 'a_f' ); ?>><?php esc_html_e( 'A – F (letra)', 'atora-lms' ); ?></option>
					<option value="logros"  <?php selected( $grade_scale, 'logros' ); ?>><?php esc_html_e( 'Logros (I / EP / S / D)', 'atora-lms' ); ?></option>
				</select>
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_passing_threshold"><?php esc_html_e( 'Nota mínima aprobatoria', 'atora-lms' ); ?></label>
				<input type="number" min="0" max="100" step="1" name="_lm_passing_threshold" id="_lm_passing_threshold" value="<?php echo esc_attr( $passing_threshold ); ?>">
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="lm_min_score"><?php esc_html_e( 'Puntaje mínimo', 'atora-lms' ); ?></label>
				<input type="number" min="0" step="1" name="lm_min_score" id="lm_min_score" value="<?php echo esc_attr( $min_score ); ?>">
			</p>
			<p class="clms-f clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="lm_max_score"><?php esc_html_e( 'Puntaje máximo', 'atora-lms' ); ?></label>
				<input type="number" min="0" step="1" name="lm_max_score" id="lm_max_score" value="<?php echo esc_attr( $max_score ); ?>">
			</p>
			<p class="clms-f full clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="_lm_quiz_base_text"><?php esc_html_e( 'Texto base para evaluación', 'atora-lms' ); ?></label>
				<textarea name="_lm_quiz_base_text" id="_lm_quiz_base_text" rows="5"><?php echo esc_textarea( $quiz_base_text ); ?></textarea>
			</p>
			<p class="clms-f full clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) ? '' : ' clms-is-hidden'; ?>">
				<label for="lm_extra_material"><?php esc_html_e( 'Material extra', 'atora-lms' ); ?></label>
				<textarea name="lm_extra_material" id="lm_extra_material" rows="4"><?php echo esc_textarea( $extra_material ); ?></textarea>
			</p>
		</div>
		</div>

		<?php
		// ── Panel de Transcripción ──────────────────────────────────────────
		$tr_status     = class_exists( 'CLMS_Transcription' ) ? CLMS_Transcription::get_status( $post->ID ) : 'not_requested';
		$tr_updated    = get_post_meta( $post->ID, '_clms_transcription_updated_at', true );
		$tr_words      = (int) get_post_meta( $post->ID, '_clms_transcription_words', true );
		$tr_provider   = get_post_meta( $post->ID, '_clms_transcription_provider', true );
		$tr_lang       = get_post_meta( $post->ID, '_clms_transcription_lang', true );
		$tr_error      = get_post_meta( $post->ID, '_clms_transcription_error', true );
		$tr_text       = get_post_meta( $post->ID, '_clms_transcription_text', true );
		$tr_preview    = $tr_text ? wp_trim_words( $tr_text, 60 ) : '';

		$tr_status_labels = array(
			'not_requested' => __( '—', 'atora-lms' ),
			'pending'       => __( 'En cola…', 'atora-lms' ),
			'processing'    => __( 'Procesando…', 'atora-lms' ),
			'completed'     => __( 'Completada', 'atora-lms' ),
			'failed'        => __( 'Fallida', 'atora-lms' ),
		);
		$tr_status_label = isset( $tr_status_labels[ $tr_status ] ) ? $tr_status_labels[ $tr_status ] : $tr_status;
		$tr_nonce        = wp_create_nonce( 'clms_transcription_' . $post->ID );
		$video_source    = '';
		$extra_v         = get_post_meta( $post->ID, '_clms_lesson_extra_videos', true );
		if ( is_array( $extra_v ) && ! empty( $extra_v[0]['source'] ) ) {
			$video_source = $extra_v[0]['source'];
		} else {
			$video_source = get_post_meta( $post->ID, '_clms_lesson_video_source', true ) ?: 'youtube';
		}
			$whisper_configured = false;
			if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_whisper_api_key' ) ) {
				$whisper_configured = '' !== trim( (string) CLMS_AI_Settings_Service::get_whisper_api_key() );
			} elseif ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_whisper_key' ) ) {
				$whisper_configured = '' !== trim( (string) CLMS_Settings::get_whisper_key() );
			} else {
				$ai_options = (array) get_option( 'clms_ai_settings', array() );
			$whisper_configured = '' !== trim( (string) ( $ai_options['whisper_api_key'] ?? '' ) );
		}
		?>
		<p class="clms-section-title"><?php esc_html_e( 'Transcripción de video', 'atora-lms' ); ?></p>

		<div class="clms-ai-status" id="clms-transcription-panel" data-lesson-id="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( $tr_nonce ); ?>">

			<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
				<span class="clms-tr-badge clms-tr-badge--<?php echo esc_attr( $tr_status ); ?>"><?php echo esc_html( $tr_status_label ); ?></span>
				<?php if ( $tr_provider ) : ?>
					<span class="clms-help" style="margin:0"><?php echo esc_html( 'youtube_captions' === $tr_provider ? __( 'YouTube Captions', 'atora-lms' ) : __( 'OpenAI Whisper', 'atora-lms' ) ); ?></span>
				<?php endif; ?>
				<?php if ( $tr_words ) : ?>
					<span class="clms-help" style="margin:0"><?php echo esc_html( sprintf( __( '%s palabras', 'atora-lms' ), number_format_i18n( $tr_words ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( $tr_lang ) : ?>
					<span class="clms-help" style="margin:0"><?php echo esc_html( strtoupper( $tr_lang ) ); ?></span>
				<?php endif; ?>
				<?php if ( $tr_updated ) : ?>
					<span class="clms-help" style="margin:0"><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $tr_updated ) ) ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $tr_error ) : ?>
				<p style="color:#d63638;font-size:12px;margin:0 0 8px"><?php echo esc_html( $tr_error ); ?></p>
			<?php endif; ?>

			<?php if ( $tr_preview ) : ?>
				<details style="margin-bottom:10px">
					<summary style="cursor:pointer;font-size:12px;color:var(--clms-muted-2,#646970)"><?php esc_html_e( 'Vista previa del texto', 'atora-lms' ); ?></summary>
					<p class="clms-tr-preview-block"><?php echo esc_html( $tr_preview ); ?>…</p>
				</details>
			<?php endif; ?>

			<div class="clms-actions" id="clms-transcription-actions" style="flex-wrap:wrap;gap:6px">
				<?php if ( 'youtube' === $video_source ) : ?>
					<button type="button" class="button" data-clms-tr-provider="youtube_captions">
						<?php echo 'completed' === $tr_status ? esc_html__( 'Re-transcribir (YouTube Captions)', 'atora-lms' ) : esc_html__( 'Transcribir (YouTube Captions)', 'atora-lms' ); ?>
					</button>
				<?php endif; ?>

					<button type="button" class="button" data-clms-tr-provider="whisper" <?php disabled( ! $whisper_configured ); ?>>
						<?php echo 'completed' === $tr_status ? esc_html__( 'Re-transcribir con Whisper', 'atora-lms' ) : esc_html__( 'Transcribir con Whisper (OpenAI)', 'atora-lms' ); ?>
					</button>
				</div>
				<?php if ( ! $whisper_configured ) : ?>
					<p class="clms-help" style="margin:8px 0 0"><?php esc_html_e( 'Whisper no está configurado. Añade una API key en Configuración de Atora > APIs e IA.', 'atora-lms' ); ?></p>
				<?php endif; ?>

			<p id="clms-transcription-msg" style="font-size:12px;margin:8px 0 0;display:none"></p>
		</div>

		<style>
		/* Badge layout only — colors come from admin.css */
		.clms-tr-badge{display:inline-block;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
		</style>
		<script>
		(function(){
			var panel    = document.getElementById('clms-transcription-panel');
			var msg      = document.getElementById('clms-transcription-msg');
			var lessonId = panel ? panel.getAttribute('data-lesson-id') : '';
			var nonce    = panel ? panel.getAttribute('data-nonce') : '';
			var pollTimer= null;

			function setMsg(text, color){
				if(!msg) return;
				msg.style.display = text ? 'block' : 'none';
				msg.style.color   = color || '#646970';
				msg.textContent   = text;
			}

			function statusLabel(s){
				return (clmsI18n.trStatus && clmsI18n.trStatus[s]) ? clmsI18n.trStatus[s] : s;
			}

			function poll(){
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=clms_transcription_status&lesson_id='+lessonId+'&nonce='+encodeURIComponent(nonce))
					.then(function(r){return r.json();})
					.then(function(json){
						if(!json||!json.success||!json.data) return;
						var d = json.data;
						var badge = panel.querySelector('.clms-tr-badge');
						if(badge){
							badge.className='clms-tr-badge clms-tr-badge--'+d.status;
							badge.textContent=statusLabel(d.status);
						}
						if(d.status==='completed'||d.status==='failed'){
							clearInterval(pollTimer);
							if(d.status==='completed'){
								setMsg(formatText(clmsI18n.trReady, d.words),'#1a7f37');
							} else {
								setMsg(d.error ? formatText(clmsI18n.trError, d.error) : clmsI18n.trFailed,'#d63638');
							}
							var btns = panel.querySelectorAll('[data-clms-tr-provider]');
							btns.forEach(function(b){b.disabled=false;});
						}
					})
					.catch(function(){});
			}

			function trigger(provider){
				var btns = panel.querySelectorAll('[data-clms-tr-provider]');
				btns.forEach(function(b){b.disabled=true;});
				setMsg(clmsI18n.trStarting,'#646970');

				var body = new URLSearchParams();
				body.append('action',    'clms_transcription_trigger');
				body.append('lesson_id', lessonId);
				body.append('provider',  provider);
				body.append('nonce',     nonce);

				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
					credentials: 'same-origin',
					body: body.toString()
				})
				.then(function(r){return r.json();})
				.then(function(json){
					if(!json||!json.success){
						var errMsg = (json&&json.data&&json.data.message) ? json.data.message : clmsI18n.trStartError;
						setMsg(errMsg,'#d63638');
						btns.forEach(function(b){b.disabled=false;});
						return;
					}
					setMsg(clmsI18n.trQueued,'#996800');
					pollTimer = setInterval(poll, 3000);
				})
				.catch(function(){
					setMsg(clmsI18n.trConnectionError,'#d63638');
					btns.forEach(function(b){b.disabled=false;});
				});
			}

			if(panel){
				panel.querySelectorAll('[data-clms-tr-provider]').forEach(function(btn){
					btn.addEventListener('click', function(){
						trigger(btn.getAttribute('data-clms-tr-provider'));
					});
				});

				// Reanudar polling si estaba procesando
				var currentStatus = (panel.querySelector('.clms-tr-badge')||{}).className||'';
				if(currentStatus.indexOf('pending')>-1||currentStatus.indexOf('processing')>-1){
					pollTimer = setInterval(poll, 3000);
				}
			}
		})();
		</script>

			<p class="clms-section-title clms-eval-quiz-only<?php echo $show_quiz_context ? '' : ' clms-is-hidden'; ?>"><?php esc_html_e( 'AI / Banco de preguntas', 'atora-lms' ); ?></p>
			<div class="clms-grid-2 clms-eval-quiz-only<?php echo $show_quiz_context ? '' : ' clms-is-hidden'; ?>">
			<p class="clms-f">
				<label for="_clms_ai_source_mode"><?php esc_html_e( 'Modo fuente AI', 'atora-lms' ); ?></label>
				<select name="_clms_ai_source_mode" id="_clms_ai_source_mode">
					<option value="text"  <?php selected( $ai_source_mode, 'text' ); ?>><?php esc_html_e( 'Texto', 'atora-lms' ); ?></option>
					<option value="file"  <?php selected( $ai_source_mode, 'file' ); ?>><?php esc_html_e( 'Archivo', 'atora-lms' ); ?></option>
					<option value="mixed" <?php selected( $ai_source_mode, 'mixed' ); ?>><?php esc_html_e( 'Mixto', 'atora-lms' ); ?></option>
				</select>
			</p>
			<p class="clms-f">
				<label for="_clms_ai_question_bank_size"><?php esc_html_e( 'Tamaño del banco', 'atora-lms' ); ?></label>
				<input type="number" min="0" step="1" name="_clms_ai_question_bank_size" id="_clms_ai_question_bank_size" value="<?php echo esc_attr( $ai_bank_size ); ?>">
			</p>
			<p class="clms-f">
				<label><?php esc_html_e( 'Archivo guía', 'atora-lms' ); ?></label>
				<div class="clms-dropzone" id="clms_ai_guide_drop" tabindex="0" role="button" aria-label="<?php echo esc_attr__( 'Arrastra el archivo guía aquí', 'atora-lms' ); ?>">
					<span class="clms-dropzone-title"><?php esc_html_e( 'Arrastra el archivo guía aquí', 'atora-lms' ); ?></span>
					<span class="clms-dropzone-sub"><?php esc_html_e( 'o haz clic para seleccionar en la biblioteca', 'atora-lms' ); ?></span>
				</div>
				<input type="hidden" name="_clms_ai_guide_attachment_id" id="_clms_ai_guide_attachment_id" value="<?php echo esc_attr( $ai_guide_file_id ); ?>">
				<input type="text" id="clms_ai_guide_file_label" value="<?php echo esc_attr( $guide_title ? $guide_title : $ai_source_label ); ?>" readonly>
				<div class="clms-actions">
					<button type="button" class="button" id="clms_ai_pick_file"><?php esc_html_e( 'Seleccionar archivo', 'atora-lms' ); ?></button>
					<button type="button" class="button" id="clms_ai_clear_file"><?php esc_html_e( 'Quitar', 'atora-lms' ); ?></button>
				</div>
			</p>
			<p class="clms-f">
				<label for="_clms_ai_guide_source_label"><?php esc_html_e( 'Etiqueta fuente AI', 'atora-lms' ); ?></label>
				<input type="text" name="_clms_ai_guide_source_label" id="_clms_ai_guide_source_label" value="<?php echo esc_attr( $ai_source_label ); ?>">
			</p>
		</div>

				<div class="clms-ai-status clms-eval-quiz-only<?php echo $show_quiz_context ? '' : ' clms-is-hidden'; ?>">
				<p style="margin:0 0 4px"><strong><?php esc_html_e( 'Preguntas guardadas:', 'atora-lms' ); ?></strong> <span id="clms-ai-bank-count"><?php echo esc_html( $quiz_count ); ?></span></p>
			<?php if ( $ai_generated_at ) : ?>
				<p style="margin:0 0 4px"><strong><?php esc_html_e( 'Última generación:', 'atora-lms' ); ?></strong> <?php echo esc_html( $ai_generated_at ); ?></p>
			<?php endif; ?>
			<?php if ( $guide_url ) : ?>
				<p style="margin:0 0 8px"><strong><?php esc_html_e( 'Archivo:', 'atora-lms' ); ?></strong> <a href="<?php echo esc_url( $guide_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $guide_title ? $guide_title : basename( $guide_url ) ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="clms-ai-status clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) || $quiz_count > 0 ? '' : ' clms-is-hidden'; ?>">
				<p style="margin:0 0 8px"><strong><?php esc_html_e( 'Selector de banco AI', 'atora-lms' ); ?></strong></p>
				<?php if ( $quiz_count > 0 ) : ?>
					<input type="hidden" name="_clms_ai_question_selector_present" value="1">
					<div style="display:grid;gap:8px;max-height:260px;overflow:auto;padding-right:4px">
						<?php foreach ( $quiz_questions as $question_index => $question_item ) : ?>
							<?php
							$question_preview = $this->get_quiz_question_preview( $question_item );
							if ( '' === $question_preview ) {
								continue;
							}
							?>
							<label style="display:flex;align-items:flex-start;gap:8px;border:1px solid #dbeafe;border-radius:10px;padding:8px 10px;background:#fff">
								<input type="checkbox" name="_clms_ai_question_keep[]" value="<?php echo esc_attr( $question_index ); ?>" checked>
								<span style="line-height:1.4">
									<strong><?php echo esc_html( ( $question_index + 1 ) . '.' ); ?></strong>
									<?php echo esc_html( $question_preview ); ?>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="clms-help" style="margin:8px 0 0"><?php esc_html_e( 'Desmarca las preguntas que quieras quitar del banco al guardar la lección.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<p class="clms-help" style="margin:0"><?php esc_html_e( 'Aún no hay preguntas guardadas. Genera el banco con IA o agrega una manualmente abajo.', 'atora-lms' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="clms-grid-2 clms-eval-quiz-only<?php echo ( 'quiz' === $activity_type && $quiz_enabled_bool ) || $quiz_count > 0 ? '' : ' clms-is-hidden'; ?>">
				<p class="clms-f full" style="margin-top:0">
					<label for="_clms_ai_new_question_text"><?php esc_html_e( 'Agregar pregunta manual', 'atora-lms' ); ?></label>
					<input type="text" id="_clms_ai_new_question_text" name="_clms_ai_new_question_text" value="">
					<span class="clms-help"><?php esc_html_e( 'Si completas este bloque, la nueva pregunta se añadirá al banco al guardar.', 'atora-lms' ); ?></span>
				</p>
				<p class="clms-f">
					<label for="_clms_ai_new_question_type"><?php esc_html_e( 'Tipo de pregunta', 'atora-lms' ); ?></label>
					<select id="_clms_ai_new_question_type" name="_clms_ai_new_question_type">
						<option value="single"><?php esc_html_e( 'Selección única', 'atora-lms' ); ?></option>
						<option value="multiple"><?php esc_html_e( 'Selección múltiple', 'atora-lms' ); ?></option>
						<option value="true_false"><?php esc_html_e( 'Verdadero/Falso', 'atora-lms' ); ?></option>
						<option value="text"><?php esc_html_e( 'Texto corto', 'atora-lms' ); ?></option>
						<option value="textarea"><?php esc_html_e( 'Texto largo', 'atora-lms' ); ?></option>
						<option value="number"><?php esc_html_e( 'Número', 'atora-lms' ); ?></option>
					</select>
				</p>
				<p class="clms-f">
					<label for="_clms_ai_new_question_weight"><?php esc_html_e( 'Peso', 'atora-lms' ); ?></label>
					<input type="number" min="1" step="1" id="_clms_ai_new_question_weight" name="_clms_ai_new_question_weight" value="1">
				</p>
				<p class="clms-f full">
					<label for="_clms_ai_new_question_options"><?php esc_html_e( 'Opciones (una por línea)', 'atora-lms' ); ?></label>
					<textarea id="_clms_ai_new_question_options" name="_clms_ai_new_question_options" rows="4"></textarea>
					<span class="clms-help"><?php esc_html_e( 'Para Verdadero/Falso puedes dejarlo vacío; ATORA agregará las opciones automáticamente.', 'atora-lms' ); ?></span>
				</p>
				<p class="clms-f full">
					<label for="_clms_ai_new_question_correct"><?php esc_html_e( 'Respuesta correcta', 'atora-lms' ); ?></label>
					<input type="text" id="_clms_ai_new_question_correct" name="_clms_ai_new_question_correct" value="">
					<span class="clms-help"><?php esc_html_e( 'En selección múltiple separa respuestas por coma (ej: A, C).', 'atora-lms' ); ?></span>
				</p>
				<p class="clms-f full">
					<label for="_clms_ai_new_question_feedback"><?php esc_html_e( 'Feedback opcional', 'atora-lms' ); ?></label>
					<textarea id="_clms_ai_new_question_feedback" name="_clms_ai_new_question_feedback" rows="3"></textarea>
				</p>
			</div>

					<p class="clms-eval-quiz-only<?php echo $show_quiz_context ? '' : ' clms-is-hidden'; ?>">
					<button type="button" id="clms-ai-generate-btn" class="button button-primary">
						<?php esc_html_e( 'Generar banco AI ahora', 'atora-lms' ); ?>
				</button>
				<?php if ( $quiz_count > 0 ) : ?>
					&nbsp;<button type="button" id="clms-ai-regenerate-btn" class="button button-secondary"><?php esc_html_e( 'Regenerar banco AI', 'atora-lms' ); ?></button>
				<?php endif; ?>
				<?php if ( $quiz_count > 0 ) : ?>
					&nbsp;<a class="button button-secondary" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Vaciar banco', 'atora-lms' ); ?></a>
				<?php endif; ?>
				<span id="clms-ai-generate-msg" style="margin-left:10px;color:#666;font-style:italic"></span>
			</p>

		<script>
			(function(){
				var btn           = document.getElementById('clms-ai-generate-btn');
				var regenerateBtn = document.getElementById('clms-ai-regenerate-btn');
				var msg           = document.getElementById('clms-ai-generate-msg');
				var counter       = document.getElementById('clms-ai-bank-count');
				if (!btn) return;

				function runGenerate(replaceExisting){
					var lessonId = <?php echo absint( $post->ID ); ?>;
					var fileId   = document.getElementById('_clms_ai_guide_attachment_id')
						? parseInt(document.getElementById('_clms_ai_guide_attachment_id').value, 10) || 0
						: 0;
					var count    = document.getElementById('_clms_ai_question_bank_size')
						? parseInt(document.getElementById('_clms_ai_question_bank_size').value, 10) || 10
						: 10;

					btn.disabled = true;
					if (regenerateBtn) { regenerateBtn.disabled = true; }
					if (msg) msg.textContent = clmsI18n.bankGenerating;

					var data = new FormData();
					data.append('action',     'clms_generate_quiz');
					data.append('nonce',      '<?php echo esc_js( wp_create_nonce( 'clms_generate_quiz_nonce' ) ); ?>');
					data.append('lesson_id',  lessonId);
					data.append('file_id',    fileId);
					data.append('count',      Math.max(1, Math.min(20, count || 10)));
					data.append('difficulty', 'mixed');
					data.append('replace_existing', replaceExisting ? '1' : '0');

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: data
					})
					.then(function(r){ return r.json(); })
					.then(function(res){
						btn.disabled = false;
						if (regenerateBtn) { regenerateBtn.disabled = false; }
						if (res.success) {
							if (msg) msg.textContent = res.data.message || clmsI18n.bankGenerated;
							if (counter && typeof res.data.bank_count !== 'undefined') {
								counter.textContent = parseInt(res.data.bank_count, 10) || 0;
							} else if (counter && res.data.questions_count) {
								counter.textContent = parseInt(counter.textContent, 10) + res.data.questions_count;
							}
						} else {
							if (msg) msg.textContent = (res.data && res.data.message) ? res.data.message : clmsI18n.bankError;
						}
					})
					.catch(function(){
						btn.disabled = false;
						if (regenerateBtn) { regenerateBtn.disabled = false; }
						if (msg) msg.textContent = clmsI18n.bankNetworkError;
					});
				}

				btn.addEventListener('click', function(){
					runGenerate(false);
				});
				if (regenerateBtn) {
					regenerateBtn.addEventListener('click', function(){
						runGenerate(true);
					});
				}
			})();
			</script>
			<?php
		}

	// ── Guardado ────────────────────────────────────────────────────────────────

}
