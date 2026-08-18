<?php
/**
 * CLMS_Quick_Wins — Mejoras rápidas de experiencia del estudiante (v4.22).
 *
 * Agrupa tres funcionalidades independientes:
 *   1. Learning Streak — rastrea días consecutivos de actividad y expone
 *      el dato vía shortcode [clms_streak] y como métrica del dashboard.
 *   2. Grade Breakdown Template — shortcode [clms_grade_breakdown] que
 *      monta el componente JS sobre un div o renderiza tabla PHP fallback.
 *   3. AI Study Tips — genera 3 tips personalizados tras completar un quiz
 *      y los inyecta en la respuesta AJAX del quiz (filter).
 *
 * @package CustomLMSCore
 * @since   4.22
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Quick_Wins {

	public function __construct() {
		// Streak tracking.
		add_action( 'clms_lesson_completed',  array( $this, 'record_activity_day' ), 10, 2 );
		add_action( 'clms_quiz_submitted',     array( $this, 'record_activity_day_from_quiz' ), 10, 3 );
		add_shortcode( 'clms_streak',          array( $this, 'render_streak_shortcode' ) );
		add_filter( 'clms_dashboard_metrics',  array( $this, 'inject_streak_into_metrics' ), 10, 2 );

		// Grade Breakdown shortcode.
		add_shortcode( 'clms_grade_breakdown', array( $this, 'render_grade_breakdown_shortcode' ) );

		// AI Study Tips — inyecta los tips en la respuesta del quiz.
		add_filter( 'clms_quiz_result_response', array( $this, 'append_study_tips' ), 10, 3 );
	}

	// ── 1. Learning Streak ────────────────────────────────────────────────────

	/**
	 * Registra la fecha de hoy como día activo del estudiante.
	 * Se llama desde clms_lesson_completed (user_id, lesson_id).
	 */
	public function record_activity_day( $user_id, $lesson_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}
		$this->store_activity_date( $user_id, current_time( 'Y-m-d' ) );
	}

	/**
	 * Registra día activo desde el hook de quiz enviado
	 * (user_id, lesson_id, result_array).
	 */
	public function record_activity_day_from_quiz( $user_id, $lesson_id, $result ) {
		$this->record_activity_day( $user_id, $lesson_id );
	}

	/**
	 * Guarda la fecha en user_meta (_clms_active_days), deduplicando.
	 */
	private function store_activity_date( $user_id, $date ) {
		$days = get_user_meta( $user_id, '_clms_active_days', true );
		$days = is_array( $days ) ? $days : array();

		if ( in_array( $date, $days, true ) ) {
			return;
		}

		$days[] = $date;

		// Conservar solo los últimos 365 días para no inflar el meta.
		rsort( $days );
		$days = array_slice( $days, 0, 365 );

		update_user_meta( $user_id, '_clms_active_days', $days );

		// Invalidar caché de streak.
		delete_transient( 'clms_streak_' . $user_id );
	}

	/**
	 * Calcula la racha actual de días consecutivos.
	 *
	 * @param int $user_id
	 * @return int
	 */
	public function calculate_streak( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}

		$cached = get_transient( 'clms_streak_' . $user_id );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$days = get_user_meta( $user_id, '_clms_active_days', true );
		if ( ! is_array( $days ) || empty( $days ) ) {
			return 0;
		}

		rsort( $days );

		$today     = new DateTime( current_time( 'Y-m-d' ) );
		$yesterday = ( clone $today )->modify( '-1 day' );

		// La racha cuenta si hoy o ayer fue el último día activo.
		$last_day = new DateTime( $days[0] );

		if ( $last_day < $yesterday ) {
			set_transient( 'clms_streak_' . $user_id, 0, HOUR_IN_SECONDS );
			return 0;
		}

		$streak  = 0;
		$current = clone $today;

		foreach ( $days as $date_str ) {
			$day = new DateTime( $date_str );
			$diff = (int) $current->diff( $day )->days;

			if ( $diff === 0 ) {
				$streak++;
				$current->modify( '-1 day' );
			} elseif ( $diff === 1 ) {
				$streak++;
				$current = clone $day;
				$current->modify( '-1 day' );
			} else {
				break;
			}
		}

		set_transient( 'clms_streak_' . $user_id, $streak, HOUR_IN_SECONDS );

		return $streak;
	}

	/**
	 * Shortcode [clms_streak] — muestra la racha del usuario actual.
	 */
	public function render_streak_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$atts = shortcode_atts( array( 'user_id' => get_current_user_id() ), $atts, 'clms_streak' );
		$user_id = absint( $atts['user_id'] );
		$streak  = $this->calculate_streak( $user_id );

		ob_start();
		?>
		<div class="clms-streak" role="status" aria-label="<?php esc_attr_e( 'Racha de aprendizaje', 'atora-lms' ); ?>">
			<span class="clms-streak__icon" aria-hidden="true">🔥</span>
			<span class="clms-streak__count"><?php echo esc_html( $streak ); ?></span>
			<span class="clms-streak__label">
				<?php echo esc_html(
					1 === $streak
						? __( 'día seguido', 'atora-lms' )
						: __( 'días seguidos', 'atora-lms' )
				); ?>
			</span>
		</div>
		<style>
		.clms-streak{display:inline-flex;align-items:center;gap:.4rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:2rem;padding:.35rem .9rem;font-size:.875rem}
		.clms-streak__icon{font-size:1.1rem}
		.clms-streak__count{font-weight:700;color:#ea580c;font-size:1rem}
		.clms-streak__label{color:#9a3412}
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inyecta la racha en el array de métricas del dashboard.
	 * Requiere que CLMS_Dashboard aplique: apply_filters('clms_dashboard_metrics', $metrics, $user_id)
	 */
	public function inject_streak_into_metrics( $metrics, $user_id ) {
		$metrics['streak'] = $this->calculate_streak( absint( $user_id ) );
		return $metrics;
	}

	// ── 2. Grade Breakdown Shortcode ──────────────────────────────────────────

	/**
	 * Shortcode [clms_grade_breakdown course_id="" student_id=""]
	 *
	 * Monta el componente JS sobre un <div> con los atributos necesarios.
	 * Si JS no está disponible, renderiza una tabla PHP como fallback.
	 */
	public function render_grade_breakdown_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Debes iniciar sesión para ver tus calificaciones.', 'atora-lms' ) . '</p>';
		}

		$atts = shortcode_atts(
			array(
				'course_id'  => 0,
				'student_id' => get_current_user_id(),
			),
			$atts,
			'clms_grade_breakdown'
		);

		$student_id = absint( $atts['student_id'] );
		$course_id  = absint( $atts['course_id'] );

		if ( ! $course_id ) {
			// Intentar inferir el curso desde el contexto actual.
			$queried = get_queried_object_id();
			if ( $queried && 'lm_course' === get_post_type( $queried ) ) {
				$course_id = $queried;
			}
		}

		if ( ! $course_id || ! $student_id ) {
			return '<p class="clms-notice">' . esc_html__( 'Se necesita especificar el curso.', 'atora-lms' ) . '</p>';
		}

		// Encolar el script JS del componente.
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script(
				'atora-grade-breakdown',
				defined( 'ATORA_LMS_ASSETS_URL' ) ? ATORA_LMS_ASSETS_URL . 'js/grade-breakdown.js' : '',
				array(),
				defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '4.22',
				true
			);
		}

		$nonce = wp_create_nonce( 'wp_rest' );

		$inline_localize = '<script>
			window.ATORA = window.ATORA || {};
			window.ATORA.rest = window.ATORA.rest || {};
			window.ATORA.rest.nonce = window.ATORA.rest.nonce || ' . wp_json_encode( $nonce ) . ';
			window.ATORA.rest.base  = window.ATORA.rest.base  || ' . wp_json_encode( rest_url( 'clms/v1' ) ) . ';
		</script>';

		// Contenedor JS.
		$js_container = '<div
			data-atora-grade-breakdown
			data-user-id="' . esc_attr( $student_id ) . '"
			data-course-id="' . esc_attr( $course_id ) . '"
			class="atora-grade-breakdown-root"
			aria-live="polite">
		</div>';

		// Fallback PHP — visible si JS está deshabilitado.
		$php_fallback = $this->render_php_grade_table( $student_id, $course_id );

		return $inline_localize . $js_container .
			'<noscript>' . $php_fallback . '</noscript>';
	}

	/**
	 * Tabla PHP simple de calificaciones (funciona sin JS).
	 */
	private function render_php_grade_table( $student_id, $course_id ) {
		if ( ! class_exists( 'CLMS_Grading_Engine' ) ) {
			return '';
		}

		$engine = new CLMS_Grading_Engine( $course_id, $student_id );
		$grade  = $engine->calculate_final_grade( $student_id, $course_id );

		if ( empty( $grade ) ) {
			return '<p>' . esc_html__( 'Sin calificaciones disponibles.', 'atora-lms' ) . '</p>';
		}

		ob_start();
		?>
		<div class="clms-grade-fallback">
			<h3 class="clms-grade-fallback__title">
				<?php
				printf(
					/* translators: 1: numeric score, 2: letter grade */
					esc_html__( 'Nota final: %1$s (%2$s)', 'atora-lms' ),
					esc_html( $grade['numeric_score'] ),
					esc_html( $grade['letter_grade'] )
				);
				?>
			</h3>
			<?php if ( ! empty( $grade['breakdown']['components'] ) ) : ?>
			<table class="clms-grade-fallback__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Componente', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Score', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Peso', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $grade['breakdown']['components'] as $comp ) : ?>
					<tr>
						<td><?php echo esc_html( $comp['name'] ); ?></td>
						<td><?php echo esc_html( number_format( $comp['score'], 1 ) ); ?>%</td>
						<td><?php echo esc_html( $comp['weight'] ); ?>%</td>
						<td><?php echo esc_html( $comp['progress'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
			<?php if ( ! empty( $grade['breakdown']['recommendations']['message'] ) ) : ?>
			<p class="clms-grade-fallback__rec">
				<?php echo esc_html( $grade['breakdown']['recommendations']['message'] ); ?>
			</p>
			<?php endif; ?>
		</div>
		<style>
		.clms-grade-fallback__table{width:100%;border-collapse:collapse;font-size:.875rem}
		.clms-grade-fallback__table th,.clms-grade-fallback__table td{text-align:left;padding:.5rem .75rem;border-bottom:1px solid #e2e8f0}
		.clms-grade-fallback__table th{color:#64748b;font-weight:600}
		.clms-grade-fallback__rec{margin-top:.75rem;padding:.75rem;background:#f0fdf4;border-left:3px solid #16a34a;border-radius:.25rem;font-size:.875rem}
		</style>
		<?php
		return ob_get_clean();
	}

	// ── 3. AI Study Tips ──────────────────────────────────────────────────────

	/**
	 * Filtra la respuesta AJAX del quiz para inyectar 3 tips de IA.
	 * Hook: clms_quiz_result_response (response_array, user_id, lesson_id)
	 *
	 * El hook lo debe aplicar CLMS_Quiz::ajax_submit_quiz() antes de
	 * wp_send_json_success(). Si no existe aún, los tips se generan en
	 * segundo plano vía clms_quiz_submitted y se almacenan en transient.
	 */
	public function append_study_tips( $response, $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return $response;
		}

		$score = isset( $response['score'] ) ? (float) $response['score'] : 50;

		$tips = $this->get_or_generate_study_tips( $user_id, $lesson_id, $score );

		if ( ! empty( $tips ) ) {
			$response['study_tips'] = $tips;
		}

		return $response;
	}

	/**
	 * Hook directo en clms_quiz_submitted para pre-generar tips en background.
	 * Se llama también cuando no hay filter disponible.
	 */
	public function pre_generate_tips_on_quiz( $user_id, $lesson_id, $result ) {
		$score = isset( $result['score'] ) ? (float) $result['score'] : 50;
		$this->get_or_generate_study_tips( $user_id, $lesson_id, $score );
	}

	/**
	 * Recupera tips desde transient o los genera con IA.
	 *
	 * @return array<int, array{title: string, body: string}>
	 */
	private function get_or_generate_study_tips( $user_id, $lesson_id, $score ) {
		$cache_key = 'clms_tips_' . absint( $user_id ) . '_' . absint( $lesson_id );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$ai = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;

		if ( ! $ai ) {
			return array();
		}

		$lesson_title = get_the_title( absint( $lesson_id ) );
		$context      = $lesson_title ?: __( 'este tema', 'atora-lms' );

		if ( $score >= 90 ) {
			$tone = __( 'el estudiante obtuvo una puntuación excelente', 'atora-lms' );
		} elseif ( $score >= 70 ) {
			$tone = __( 'el estudiante obtuvo una puntuación buena pero puede mejorar', 'atora-lms' );
		} else {
			$tone = __( 'el estudiante necesita repasar el material', 'atora-lms' );
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => sprintf(
					'Un estudiante acaba de completar un quiz sobre "%1$s" (%2$s). Genera exactamente 3 consejos de estudio breves y accionables. Responde SOLO en JSON con este formato: [{"title":"...","body":"..."},{"title":"...","body":"..."},{"title":"...","body":"..."}]. En español. Sin texto adicional.',
					$context,
					$tone
				),
			),
		);

		$response = $ai->chat( $messages, array( 'max_tokens' => 400, 'temperature' => 0.5 ) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$clean   = trim( $response );
		$decoded = json_decode( $clean, true );

		if ( ! is_array( $decoded ) || count( $decoded ) !== 3 ) {
			return array();
		}

		$tips = array_map( function( $tip ) {
			return array(
				'title' => isset( $tip['title'] ) ? sanitize_text_field( $tip['title'] ) : '',
				'body'  => isset( $tip['body'] )  ? sanitize_text_field( $tip['body'] )  : '',
			);
		}, $decoded );

		// Cachear 2 horas — los tips son relevantes mientras el material esté fresco.
		set_transient( $cache_key, $tips, 2 * HOUR_IN_SECONDS );

		return $tips;
	}
}
