<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Progress {

	const SHORTCODE_LESSON_PANEL = 'clms_lesson_panel';
	const LESSON_FEEDBACK_LOG_META = '_clms_lesson_feedback_log';

	/**
	 * TTL del transient de datos de entrega.
	 */
	const SUBMISSION_CACHE_TTL = 300;

	/**
	 * Evita registrar assets inline más de una vez.
	 *
	 * @var bool
	 */
	protected static $assets_enqueued = false;

	public function __construct() {
		add_shortcode( self::SHORTCODE_LESSON_PANEL, array( $this, 'render_lesson_panel_shortcode' ) );

		add_action( 'clms_submission_saved', array( $this, 'on_submission_saved' ), 10, 4 );
		add_action( 'clms_submission_graded', array( $this, 'on_submission_graded' ), 10, 5 );

		add_action( 'init', array( $this, 'handle_complete_lesson' ) );
		add_action( 'init', array( $this, 'handle_read_evidence' ) );
		add_action( 'init', array( $this, 'handle_lesson_feedback' ) );
	}

	/* ---------------------------------------------------------------
	   HOOKS
	--------------------------------------------------------------- */

	/**
	 * Invalida caché tras guardar entrega.
	 *
	 * Firma:
	 * (submission_id, user_id, lesson_id, course_id)
	 *
	 * @param int $submission_id Entrega.
	 * @param int $user_id       Usuario.
	 * @param int $lesson_id     Lección.
	 * @param int $course_id     Curso.
	 * @return void
	 */
	public function on_submission_saved( $submission_id, $user_id, $lesson_id, $course_id = 0 ) {
		unset( $submission_id, $course_id );

		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		delete_transient( $this->get_submission_cache_key( $user_id, $lesson_id ) );
	}

	/**
	 * Invalida caché tras calificación.
	 *
	 * Firma:
	 * (submission_id, student_id, status, grade, feedback)
	 *
	 * @param int    $submission_id Entrega.
	 * @param int    $student_id    Estudiante.
	 * @param string $status        Estado.
	 * @param mixed  $grade         Nota.
	 * @param string $feedback      Feedback.
	 * @return void
	 */
	public function on_submission_graded( $submission_id, $student_id, $status = '', $grade = '', $feedback = '' ) {
		unset( $status, $grade, $feedback );

		$submission_id = absint( $submission_id );
		$student_id    = absint( $student_id );

		if ( ! $submission_id ) {
			return;
		}

		$user_id   = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( ! $user_id ) {
			$user_id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
		}
		if ( ! $user_id ) {
			$user_id = $student_id;
		}
		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		delete_transient( $this->get_submission_cache_key( $user_id, $lesson_id ) );
	}

	/**
	 * Marca lección como completada y avanza a la siguiente si existe.
	 *
	 * @return void
	 */
	public function handle_complete_lesson() {
		if ( is_admin() ) {
			return;
		}

		if ( empty( $_GET['clms_action'] ) || 'complete_lesson' !== sanitize_key( wp_unslash( $_GET['clms_action'] ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( wp_unslash( $_GET['lesson_id'] ) ) : 0;
		$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'clms_complete_' . $lesson_id ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return;
		}
		$this->mark_lesson_completed( $user_id, $lesson_id );
		$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		$lessons   = $course_id ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lessons   = is_array( $lessons ) ? array_map( 'absint', $lessons ) : array();

		$current_index = array_search( $lesson_id, $lessons, true );
		$next_lesson   = ( false !== $current_index && isset( $lessons[ $current_index + 1 ] ) ) ? absint( $lessons[ $current_index + 1 ] ) : 0;

		if ( $next_lesson && CLMS_Helper::user_can_access_lesson( $user_id, $next_lesson ) ) {
			$next_url = get_permalink( $next_lesson );
			if ( $next_url ) {
				wp_safe_redirect( $next_url );
				exit;
			}
		}

		$current_url = get_permalink( $lesson_id );
		if ( $current_url ) {
			wp_safe_redirect( $current_url );
			exit;
		}
	}

	/**
	 * Registra evidencia de lectura (check visto y/o comentario) para lecciones sin evaluación.
	 *
	 * @return void
	 */
	public function handle_read_evidence() {
		if ( is_admin() || empty( $_POST['clms_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['clms_action'] ) );
		if ( 'mark_read_evidence' !== $action ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		$nonce     = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$comment   = isset( $_POST['clms_read_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_read_comment'] ) ) : '';

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $nonce, 'clms_mark_read_evidence_' . $lesson_id ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return;
		}

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( ! $evidence_service || ! method_exists( $evidence_service, 'save_read_evidence' ) ) {
			return;
		}

		$record = (array) $evidence_service->save_read_evidence( $user_id, $lesson_id, $comment );
		$config = method_exists( $evidence_service, 'get_activity_evidence_config' )
			? (array) $evidence_service->get_activity_evidence_config( $lesson_id )
			: array();
		$read_requirement = isset( $config['read_requirement'] ) ? sanitize_key( (string) $config['read_requirement'] ) : 'seen_or_comment';
		$comment_text = trim( (string) ( $record['comment'] ?? '' ) );
		$is_approved = true;
		if ( 'comment' === $read_requirement ) {
			$is_approved = '' !== $comment_text;
		}

		if ( $is_approved ) {
			$this->mark_lesson_completed( $user_id, $lesson_id );
		}

		$redirect = get_permalink( $lesson_id );
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}
		$redirect = add_query_arg( 'clms_read_evidence', $is_approved ? 'updated' : 'pending_comment', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Guarda comentario de estudiante sobre la clase (post-evaluación).
	 *
	 * @return void
	 */
	public function handle_lesson_feedback() {
		if ( is_admin() ) {
			return;
		}

		if ( 'POST' !== strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}

		$action = isset( $_POST['clms_action'] ) ? sanitize_key( wp_unslash( $_POST['clms_action'] ) ) : '';
		if ( 'save_lesson_feedback' !== $action ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_save_lesson_feedback_' . $lesson_id ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return;
		}

		$comment = isset( $_POST['clms_lesson_feedback_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_lesson_feedback_comment'] ) ) : '';
		self::save_student_lesson_feedback_record( $user_id, $lesson_id, $comment );

		$submission = clms_core('CLMS_Submission');
		if ( $submission && method_exists( $submission, 'get_user_submission_for_grading' ) ) {
			$submission_data = (array) $submission->get_user_submission_for_grading( $user_id, $lesson_id );
			$submission_id   = absint( $submission_data['submission_id'] ?? 0 );
			if ( $submission_id ) {
				if ( '' !== $comment ) {
					update_post_meta( $submission_id, '_clms_student_class_comment', $comment );
				} else {
					delete_post_meta( $submission_id, '_clms_student_class_comment' );
				}
			}
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = get_permalink( $lesson_id );
		}
		$redirect = remove_query_arg( 'clms_lesson_feedback', $redirect );
		$redirect = add_query_arg( 'clms_lesson_feedback', 'updated', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Obtiene registro de comentario del estudiante para una lección.
	 *
	 * @param int $user_id Usuario.
	 * @param int $lesson_id Lección.
	 * @return array<string,string>
	 */
	public static function get_student_lesson_feedback_record( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$log = get_user_meta( $user_id, self::LESSON_FEEDBACK_LOG_META, true );
		$log = is_array( $log ) ? $log : array();
		$entry = isset( $log[ $lesson_id ] ) && is_array( $log[ $lesson_id ] ) ? $log[ $lesson_id ] : array();

		return array(
			'comment'    => isset( $entry['comment'] ) ? sanitize_textarea_field( (string) $entry['comment'] ) : '',
			'updated_at' => isset( $entry['updated_at'] ) ? sanitize_text_field( (string) $entry['updated_at'] ) : '',
		);
	}

	/**
	 * Persiste comentario de estudiante para una lección.
	 *
	 * @param int $user_id Usuario.
	 * @param int $lesson_id Lección.
	 * @param string $comment Comentario.
	 * @return array<string,string>
	 */
	public static function save_student_lesson_feedback_record( $user_id, $lesson_id, $comment ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$comment   = sanitize_textarea_field( (string) $comment );
		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$log = get_user_meta( $user_id, self::LESSON_FEEDBACK_LOG_META, true );
		$log = is_array( $log ) ? $log : array();

		if ( '' === $comment ) {
			unset( $log[ $lesson_id ] );
			update_user_meta( $user_id, self::LESSON_FEEDBACK_LOG_META, $log );
			return array(
				'comment'    => '',
				'updated_at' => '',
			);
		}

		$record = array(
			'comment'    => $comment,
			'updated_at' => current_time( 'mysql' ),
		);
		$log[ $lesson_id ] = $record;
		update_user_meta( $user_id, self::LESSON_FEEDBACK_LOG_META, $log );
		return $record;
	}

	/* ---------------------------------------------------------------
	   SHORTCODE
	--------------------------------------------------------------- */

	/**
	 * Shortcode:
	 * [clms_lesson_panel]
	 * [clms_lesson_panel lesson_id="123"]
	 * [clms_lesson_panel show_quiz="1" show_submission="1" show_progress="1"]
	 *
	 * @param array $atts Attribs.
	 * @return string
	 */
	public function render_lesson_panel_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="clms-ui clms-lesson-panel-wrap"><p>Debes iniciar sesión para ver esta lección.</p></div>';
		}

		$atts = shortcode_atts(
			array(
				'lesson_id'        => 0,
				'show_quiz'        => 1,
				'show_submission'  => 1,
				'show_progress'    => 1,
				'show_course_link' => 1,
			),
			(array) $atts,
			self::SHORTCODE_LESSON_PANEL
		);

		$lesson_id = absint( $atts['lesson_id'] );

		if ( ! $lesson_id && is_singular( 'lm_lesson' ) ) {
			$lesson_id = get_the_ID();
		}

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return '<div class="clms-ui clms-lesson-panel-wrap"><p>No se encontró una lección válida.</p></div>';
		}

		$user_id   = get_current_user_id();
		$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );

		if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return '<div class="clms-ui clms-lesson-panel-wrap"><p>No tienes acceso a esta lección.</p></div>';
		}

		$quiz_result        = $this->get_quiz_result( $user_id, $lesson_id );
		$submission_data    = $this->get_submission_data( $user_id, $lesson_id );
		$grading            = $this->get_grading_instance();
		$course_summary     = array();
		$progress_percent   = 0;
		$final_average      = 0;
		$quiz_average       = 0;
		$assignment_average = 0;

		if ( $grading && $course_id && method_exists( $grading, 'get_course_grade_summary' ) ) {
			$course_summary     = $grading->get_course_grade_summary( $user_id, $course_id );
			$progress_percent   = isset( $course_summary['progress_percent'] ) ? absint( $course_summary['progress_percent'] ) : 0;
			$final_average      = isset( $course_summary['final_average'] ) ? absint( $course_summary['final_average'] ) : 0;
			$quiz_average       = isset( $course_summary['quiz_average'] ) ? absint( $course_summary['quiz_average'] ) : 0;
			$assignment_average = isset( $course_summary['assignment_average'] ) ? absint( $course_summary['assignment_average'] ) : 0;
		}

		$show_quiz        = ! empty( $atts['show_quiz'] );
		$show_submission  = ! empty( $atts['show_submission'] );
		$show_progress    = ! empty( $atts['show_progress'] );
		$show_course_link = ! empty( $atts['show_course_link'] );

		$activity_type = $this->normalize_activity_type( CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_activity_type', '_clms_activity_mode' ), 'lectura' ) );
		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		$evidence_config  = ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) )
			? (array) $evidence_service->get_activity_evidence_config( $lesson_id )
			: array();
		$read_record      = ( $evidence_service && method_exists( $evidence_service, 'get_read_evidence_record' ) )
			? (array) $evidence_service->get_read_evidence_record( $user_id, $lesson_id )
			: array();
		$evidence_type         = sanitize_key( (string) ( $evidence_config['evidence_type'] ?? '' ) );
		$read_evidence_enabled = false;
		if ( ! $this->lesson_has_quiz( $lesson_id ) ) {
			if ( 'read_only' === $evidence_type ) {
				$read_evidence_enabled = true;
			} elseif ( '' === $evidence_type || 'practice' === $evidence_type ) {
				$read_evidence_enabled = 'lectura' === $activity_type;
			}
		}
		$course_title  = $course_id ? get_the_title( $course_id ) : '';
		$course_link   = $course_id ? get_permalink( $course_id ) : '';

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="clms-ui clms-lesson-panel-wrap">
			<div class="clms-lesson-panel-card">

				<div class="clms-lesson-panel-header">
					<div>
						<h3 class="clms-lesson-panel-title">Actividad de la lección</h3>
						<div class="clms-lesson-panel-meta">
							<?php if ( $course_id && $show_course_link && $course_link ) : ?>
								<span><strong>Curso:</strong> <a href="<?php echo esc_url( $course_link ); ?>"><?php echo esc_html( $course_title ); ?></a></span>
							<?php endif; ?>

							<span><strong>Tipo:</strong> <?php echo esc_html( $this->get_activity_label( $activity_type ) ); ?></span>
						</div>
					</div>
					<?php if ( $course_id && $show_course_link && $course_link ) : ?>
						<a class="clms-lesson-panel-link" href="<?php echo esc_url( $course_link ); ?>"><?php esc_html_e( 'Ver curso', 'atora-lms' ); ?></a>
					<?php endif; ?>
				</div>

				<?php if ( $show_progress ) : ?>
					<div class="clms-progress-grid">
						<div class="clms-progress-card">
							<h4>Progreso</h4>
							<div class="clms-progress-value"><?php echo esc_html( $progress_percent ); ?>%</div>
						</div>

						<div class="clms-progress-card">
							<h4>Promedio final</h4>
							<div class="clms-progress-value"><?php echo esc_html( $final_average ); ?>%</div>
						</div>

						<div class="clms-progress-card">
							<h4>Promedio quizzes</h4>
							<div class="clms-progress-value"><?php echo esc_html( $quiz_average ); ?>%</div>
						</div>

						<div class="clms-progress-card">
							<h4>Promedio tareas</h4>
							<div class="clms-progress-value"><?php echo esc_html( $assignment_average ); ?>%</div>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $show_quiz && $this->lesson_has_quiz( $lesson_id ) ) : ?>
					<div class="clms-box">
						<h4>Evaluación</h4>

						<?php if ( $quiz_result > 0 ) : ?>
							<div class="clms-message clms-message-success">
								Ya respondiste esta evaluación. Resultado: <strong><?php echo esc_html( $quiz_result ); ?>%</strong>
							</div>
						<?php else : ?>
							<div class="clms-message">
								Esta lección incluye una evaluación.
							</div>
							<?php echo do_shortcode( '[clms_quiz lesson_id="' . absint( $lesson_id ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $read_evidence_enabled ) : ?>
					<div class="clms-box">
						<h4><?php esc_html_e( 'Evidencia de lectura', 'atora-lms' ); ?></h4>
							<?php if ( isset( $_GET['clms_read_evidence'] ) && 'updated' === sanitize_key( wp_unslash( $_GET['clms_read_evidence'] ) ) ) : ?>
								<div class="clms-message clms-message-success">
									<?php esc_html_e( 'Se registró tu evidencia de lectura correctamente.', 'atora-lms' ); ?>
								</div>
							<?php endif; ?>
							<?php if ( isset( $_GET['clms_read_evidence'] ) && 'pending_comment' === sanitize_key( wp_unslash( $_GET['clms_read_evidence'] ) ) ) : ?>
								<div class="clms-message">
									<?php esc_html_e( 'Tu visto se guardó, pero esta lección exige comentario para contar como completada.', 'atora-lms' ); ?>
								</div>
							<?php endif; ?>
						<?php if ( ! empty( $read_record['seen'] ) ) : ?>
							<div class="clms-message">
								<strong><?php esc_html_e( 'Último registro:', 'atora-lms' ); ?></strong>
								<?php echo esc_html( $this->format_datetime( (string) ( $read_record['updated_at'] ?? '' ) ) ); ?>
							</div>
						<?php endif; ?>
						<form method="post">
							<?php wp_nonce_field( 'clms_mark_read_evidence_' . $lesson_id ); ?>
							<input type="hidden" name="clms_action" value="mark_read_evidence">
							<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>">
							<p>
								<label for="clms-read-comment-<?php echo esc_attr( (string) $lesson_id ); ?>">
									<?php esc_html_e( 'Comentario de lectura (opcional)', 'atora-lms' ); ?>
								</label><br>
								<textarea id="clms-read-comment-<?php echo esc_attr( (string) $lesson_id ); ?>" name="clms_read_comment" rows="3" class="widefat" placeholder="<?php echo esc_attr__( 'Comparte una idea clave que te llevas de esta lección.', 'atora-lms' ); ?>"><?php echo esc_textarea( (string) ( $read_record['comment'] ?? '' ) ); ?></textarea>
							</p>
							<p>
								<button type="submit" class="button button-secondary"><?php esc_html_e( 'Marcar como visto', 'atora-lms' ); ?></button>
							</p>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( $show_submission ) : ?>
					<div class="clms-box">
						<h4>Entrega de tarea</h4>

						<?php if ( ! empty( $submission_data ) ) : ?>
							<div class="clms-message <?php echo esc_attr( $this->get_submission_message_class( $submission_data ) ); ?>">
								<strong>Estado:</strong> <?php echo esc_html( $this->get_submission_status_label( $submission_data ) ); ?>
								<?php if ( ! empty( $submission_data['submitted_at'] ) ) : ?>
									<br><strong>Enviada:</strong> <?php echo esc_html( $this->format_datetime( $submission_data['submitted_at'] ) ); ?>
								<?php endif; ?>
							</div>

							<?php if ( ! empty( $submission_data['comment'] ) || ! empty( $submission_data['files'] ) ) : ?>
								<div class="clms-submission-summary">
									<h5>Mi entrega</h5>

									<?php if ( ! empty( $submission_data['comment'] ) ) : ?>
										<p><?php echo esc_html( $submission_data['comment'] ); ?></p>
									<?php endif; ?>

									<?php if ( ! empty( $submission_data['files'] ) && is_array( $submission_data['files'] ) ) : ?>
										<ul class="clms-file-list">
											<?php foreach ( $submission_data['files'] as $file ) : ?>
												<?php if ( empty( $file['url'] ) ) { continue; } ?>
												<li>
													<a href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" rel="noopener noreferrer">
														<?php echo esc_html( ! empty( $file['label'] ) ? $file['label'] : 'Ver archivo' ); ?>
													</a>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<?php if ( 'graded' === ( isset( $submission_data['status'] ) ? $submission_data['status'] : '' ) ) : ?>
								<div class="clms-message clms-message-success">
									<strong>Calificación:</strong> <?php echo esc_html( isset( $submission_data['grade'] ) && '' !== (string) $submission_data['grade'] ? absint( $submission_data['grade'] ) : 0 ); ?>/100
									<?php if ( ! empty( $submission_data['feedback'] ) ) : ?>
										<br><strong>Retroalimentación:</strong> <?php echo esc_html( $submission_data['feedback'] ); ?>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						<?php else : ?>
							<div class="clms-message clms-message-error">
								Aún no has enviado una entrega.
							</div>
						<?php endif; ?>

						<?php echo do_shortcode( '[clms_submission_form lesson_id="' . absint( $lesson_id ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>

			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/* ---------------------------------------------------------------
	   DATOS
	--------------------------------------------------------------- */

	protected function get_submission_cache_key( $user_id, $lesson_id ) {
		return 'clms_sub_' . absint( $user_id ) . '_' . absint( $lesson_id );
	}

	protected function get_quiz_result( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return 0;
		}

		$key   = 'clms_quiz_attempt_' . $lesson_id;
		$value = get_user_meta( $user_id, $key, true );

		if ( is_array( $value ) && isset( $value['score'] ) ) {
			return max( 0, min( 100, absint( $value['score'] ) ) );
		}

		return '' !== (string) $value ? max( 0, min( 100, absint( $value ) ) ) : 0;
	}

	protected function lesson_has_quiz( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return false;
		}

		$enabled = (string) get_post_meta( $lesson_id, '_clms_quiz_enabled', true );
		$has_eval = sanitize_key( (string) get_post_meta( $lesson_id, '_lm_quiz_has_eval', true ) );
		$quiz_active = in_array( $enabled, array( '1', 'yes', 'true' ), true ) || 'yes' === $has_eval;
		if ( ! $quiz_active ) {
			return false;
		}

		$questions = get_post_meta( $lesson_id, '_clms_quiz_questions', true );

		if ( is_array( $questions ) && ! empty( $questions ) ) {
			return true;
		}

		$bank_size = absint( get_post_meta( $lesson_id, '_clms_ai_question_bank_size', true ) );
		if ( $bank_size > 0 ) {
			return true;
		}

		$content = (string) get_post_field( 'post_content', $lesson_id );
		return '' !== trim( $content ) && has_shortcode( $content, 'clms_quiz' );
	}

	protected function get_submission_data( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$cache_key = $this->get_submission_cache_key( $user_id, $lesson_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$data              = array();
		$submission_module = clms_core('CLMS_Submission');

		if ( $submission_module && method_exists( $submission_module, 'get_user_submission_for_grading' ) ) {
			$data = $submission_module->get_user_submission_for_grading( $user_id, $lesson_id );
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( ! empty( $data['submission_id'] ) ) {
			$submission_id = absint( $data['submission_id'] );

			$data['submitted_at'] = (string) get_post_meta( $submission_id, '_clms_submission_submitted_at', true );
			if ( '' === $data['submitted_at'] ) {
				$data['submitted_at'] = get_post_time( 'Y-m-d H:i:s', false, $submission_id );
			}

			$data['comment'] = (string) get_post_meta( $submission_id, '_clms_submission_comment', true );

			$file_ids = get_post_meta( $submission_id, '_clms_submission_files', true );
			$file_ids = is_array( $file_ids ) ? array_values( array_filter( array_map( 'absint', $file_ids ) ) ) : array();

			$files = array();

			foreach ( $file_ids as $file_id ) {
				$url = wp_get_attachment_url( $file_id );
				if ( ! $url ) {
					continue;
				}

				$path  = get_attached_file( $file_id );
				$label = $path ? basename( $path ) : 'Archivo ' . $file_id;

				$files[] = array(
					'id'    => $file_id,
					'url'   => $url,
					'label' => $label,
				);
			}

			$data['files'] = $files;
		}

		set_transient( $cache_key, $data, self::SUBMISSION_CACHE_TTL );

		return $data;
	}

	/* ---------------------------------------------------------------
	   HELPERS
	--------------------------------------------------------------- */

	protected function get_activity_label( $activity_type ) {
		switch ( $this->normalize_activity_type( $activity_type ) ) {
			case 'quiz':
				return 'Evaluación';
			case 'tarea':
				return 'Tarea';
			default:
				return 'Lección';
		}
	}

	protected function normalize_activity_type( $activity_type ) {
		$activity_type = sanitize_key( (string) $activity_type );
		$aliases = array(
			'quiz'       => 'quiz',
			'evaluacion' => 'quiz',
			'evaluation' => 'quiz',
			'tarea'      => 'tarea',
			'task'       => 'tarea',
			'assignment' => 'tarea',
			'lectura'    => 'lectura',
			'reading'    => 'lectura',
		);
		return isset( $aliases[ $activity_type ] ) ? $aliases[ $activity_type ] : 'lectura';
	}

	protected function mark_lesson_completed( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();

		if ( ! in_array( $lesson_id, $completed, true ) ) {
			$completed[] = $lesson_id;
			$completed = array_values( array_unique( $completed ) );
			update_user_meta( $user_id, '_clms_completed_lessons', $completed );
			do_action( 'clms_lesson_completed', $user_id, $lesson_id );
		}

		$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		$lessons   = $course_id ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lessons   = is_array( $lessons ) ? array_map( 'absint', $lessons ) : array();
		$done_in_course = $course_id && ! empty( $lessons )
			? count( array_intersect( $lessons, $completed ) )
			: 0;

		if ( $course_id && ! empty( $lessons ) && $done_in_course >= count( $lessons ) ) {
			do_action( 'clms_course_completed', $user_id, $course_id );
		}
	}

	protected function get_submission_status_label( $submission_data ) {
		$status = isset( $submission_data['status'] ) ? (string) $submission_data['status'] : '';

		switch ( $status ) {
			case 'graded':
				return 'Calificada';
			case 'in_review':
				return 'En revisión';
			case 'submitted':
				return 'Enviada';
			default:
				return 'Sin entrega';
		}
	}

	protected function get_submission_message_class( $submission_data ) {
		$status = isset( $submission_data['status'] ) ? (string) $submission_data['status'] : '';

		switch ( $status ) {
			case 'graded':
				return 'clms-message-success';
			case 'in_review':
			case 'submitted':
				return 'clms-message';
			default:
				return 'clms-message-error';
		}
	}

	protected function format_datetime( $datetime ) {
		$datetime = (string) $datetime;

		if ( ! $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( ! $timestamp ) {
			return $datetime;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/* ---------------------------------------------------------------
	   DEPENDENCIAS
	--------------------------------------------------------------- */

	protected function get_grading_instance() {
		$grading = clms_core('CLMS_Grading');

		return $grading ? $grading : null;
	}

	/**
	 * Método público opcional para otros módulos.
	 *
	 * @param int $user_id User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return array
	 */
	public function get_submission_data_for_progress( $user_id, $lesson_id ) {
		return $this->get_submission_data( $user_id, $lesson_id );
	}

	/* ---------------------------------------------------------------
	   ASSETS
	--------------------------------------------------------------- */

	protected function enqueue_assets() {
		if ( ! wp_style_is( 'clms-progress', 'registered' ) ) {
			wp_register_style( 'clms-progress', false, array( 'clms-ui' ), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
		}

		wp_enqueue_style( 'clms-progress' );

		if ( self::$assets_enqueued ) {
			return;
		}

		wp_add_inline_style( 'clms-progress', $this->get_inline_css() );

		self::$assets_enqueued = true;
	}

	protected function get_inline_css() {
		return '
.clms-lesson-panel-wrap{margin:24px 0}
.clms-lesson-panel-card{display:grid;gap:20px;padding:22px;border:1px solid #e5e7eb;border-radius:20px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.06)}
.clms-lesson-panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
.clms-lesson-panel-title{margin:0 0 8px;font-size:22px;line-height:1.2;color:#111827}
.clms-lesson-panel-meta{display:flex;flex-wrap:wrap;gap:10px 16px;font-size:14px;line-height:1.5;color:#4b5563}
.clms-lesson-panel-link{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border-radius:12px;background:#111827;color:#fff;text-decoration:none;font-weight:700;white-space:nowrap}
.clms-progress-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.clms-progress-card,.clms-box{border:1px solid #e5e7eb;border-radius:16px;background:#fff}
.clms-progress-card{padding:16px}
.clms-progress-card h4,.clms-box h4,.clms-submission-summary h5{margin:0 0 10px;color:#111827}
.clms-progress-value{font-size:32px;line-height:1;font-weight:800;color:#111827}
.clms-box{padding:18px}
.clms-message{padding:14px 16px;border-radius:14px;background:#f8fafc;color:#334155;line-height:1.6}
.clms-message-success{background:#f0fdf4;color:#166534}
.clms-message-error{background:#fff7ed;color:#9a3412}
.clms-submission-summary{display:grid;gap:12px;padding:14px 0}
.clms-file-list{margin:0;padding-left:18px;display:grid;gap:8px}
.clms-file-list a{overflow-wrap:anywhere}
@media (max-width:960px){
	.clms-progress-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:640px){
	.clms-lesson-panel-card{padding:18px;border-radius:18px}
	.clms-lesson-panel-header{grid-template-columns:1fr;display:grid}
	.clms-lesson-panel-title{font-size:20px}
	.clms-lesson-panel-meta{display:grid;gap:8px}
	.clms-lesson-panel-link{width:100%}
	.clms-progress-grid{grid-template-columns:1fr}
	.clms-progress-value{font-size:28px}
	.clms-box{padding:16px}
}';
	}
}
