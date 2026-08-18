<?php
/**
 * CLMS_Student_Memory — Memoria persistente de aprendizaje por estudiante
 *
 * Rastrea automáticamente:
 *   - Temas consultados en el chat (frecuencia + dificultad percibida)
 *   - Resultados de quizzes por lección
 *   - Calificaciones de entregas
 *   - Sentimiento detectado en mensajes de chat
 *
 * La memoria se inyecta en el prompt del asistente para personalizar respuestas.
 * El profesor puede ver el perfil de aprendizaje de cada estudiante.
 *
 * Almacenamiento:
 *   - user_meta `_clms_memory_{course_id}` → array con el perfil completo
 *   - Capped en 200 interacciones por curso para evitar bloat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Student_Memory {

	const META_PREFIX   = '_clms_memory_';
	const MAX_TOPICS    = 50;   // máximo de temas únicos a recordar
	const MAX_MESSAGES  = 200;  // cap de interacciones totales
	const DECAY_DAYS    = 90;   // temas sin actividad por 90 días se archivan

	// Singleton
	private static $instance = null;
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Grabar actividad de quiz
		add_action( 'clms_quiz_attempt_saved',    array( $this, 'record_quiz_attempt' ), 10, 3 );
		// Grabar calificación de entrega.
		add_action( 'clms_submission_graded', array( $this, 'record_submission_grade' ), 10, 5 );
		// AJAX para el asistente: grabar mensaje de chat
		add_action( 'clms_chat_message_logged',   array( $this, 'record_chat_message' ), 10, 4 );
		// Panel del profesor: ver perfil de alumno
		add_action( 'wp_ajax_clms_get_student_memory', array( $this, 'ajax_get_memory' ) );
		// Shortcode para el perfil propio del estudiante
		add_shortcode( 'clms_my_learning_profile', array( $this, 'shortcode_profile' ) );
	}

	// ── Registro de eventos ───────────────────────────────────────────────────────

	/**
	 * Llamar desde class-quiz.php tras guardar un intento.
	 *
	 * @param int   $user_id
	 * @param int   $lesson_id
	 * @param array $attempt  { score, total, answers }
	 */
	public function record_quiz_attempt( $user_id, $lesson_id, $attempt ) {
		$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_id_from_lesson( $lesson_id ) : 0;
		if ( ! $course_id || ! $user_id ) { return; }

		$memory = $this->load( $user_id, $course_id );

		$pct = isset( $attempt['score'], $attempt['total'] ) && $attempt['total'] > 0
			? round( ( $attempt['score'] / $attempt['total'] ) * 100 )
			: 0;

		$lesson_title = get_the_title( $lesson_id );

		// Actualizar entrada de la lección
		$memory['lessons'][ $lesson_id ] = array_merge(
			$memory['lessons'][ $lesson_id ] ?? array(),
			array(
				'quiz_attempts'  => ( $memory['lessons'][ $lesson_id ]['quiz_attempts'] ?? 0 ) + 1,
				'quiz_last_pct'  => $pct,
				'quiz_best_pct'  => max( $pct, $memory['lessons'][ $lesson_id ]['quiz_best_pct'] ?? 0 ),
				'lesson_title'   => $lesson_title,
				'last_activity'  => current_time( 'mysql' ),
			)
		);

		// Si el puntaje es bajo, marcar como tema con dificultad
		if ( $pct < 60 ) {
			$this->flag_topic_difficulty( $memory, $lesson_title, 'quiz', $pct );
		}

		$memory['stats']['total_quiz_attempts'] = ( $memory['stats']['total_quiz_attempts'] ?? 0 ) + 1;
		$memory['stats']['last_activity']       = current_time( 'mysql' );

		$this->save( $user_id, $course_id, $memory );
	}

	/**
	 * Llamar desde clms_submission_graded tras calificar.
	 *
	 * @param int   $submission_id
	 * @param int   $student_id
	 * @param mixed $status_or_score Estado actual o score legacy.
	 * @param mixed $grade           Nota actual.
	 * @param mixed $feedback        Comentarios de evaluación.
	 */
	public function record_submission_grade( $submission_id, $student_id, $status_or_score = '', $grade = '', $feedback = '' ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return;
		}

		$student_id = absint( $student_id );
		if ( ! $student_id ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		}
		if ( ! $student_id ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
		}
		if ( ! $student_id ) {
			$this->notify_warning( 'missing_student_id', array( 'submission_id' => $submission_id ) );
			return;
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
			$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}
		if ( ! $course_id || ! $lesson_id ) {
			$this->notify_warning(
				'missing_course_or_lesson',
				array(
					'submission_id' => $submission_id,
					'student_id'    => $student_id,
				)
			);
			return;
		}

		$normalized_feedback = sanitize_textarea_field( (string) $feedback );
		$score              = null;

		if ( is_numeric( $grade ) ) {
			$score = (float) $grade;
		} elseif ( '' === (string) $grade && is_numeric( $status_or_score ) ) {
			// Compatibilidad legacy: tercer argumento era score.
			$score = (float) $status_or_score;
		}

		if ( null !== $score ) {
			$score = max( 0, min( 100, $score ) );
		}

		if ( null === $score && '' === $normalized_feedback ) {
			$this->notify_warning(
				'invalid_grade_payload',
				array(
					'submission_id' => $submission_id,
					'student_id'    => $student_id,
					'status'        => is_scalar( $status_or_score ) ? (string) $status_or_score : '',
				)
			);
			return;
		}

		$memory      = $this->load( $student_id, $course_id );
		$lesson_title = get_the_title( $lesson_id );
		$status_key   = sanitize_key( (string) $status_or_score );

		$lesson_data = array(
			'lesson_title'          => $lesson_title,
			'last_activity'         => current_time( 'mysql' ),
			'last_submission_status'=> $status_key,
		);

		if ( null !== $score ) {
			$lesson_data['submission_score'] = (float) $score;
		}
		if ( '' !== $normalized_feedback ) {
			$lesson_data['submission_feedback'] = $normalized_feedback;
		}

		$memory['lessons'][ $lesson_id ] = array_merge(
			$memory['lessons'][ $lesson_id ] ?? array(),
			$lesson_data
		);

		if ( null !== $score && $score < 60 ) {
			$this->flag_topic_difficulty( $memory, $lesson_title, 'submission', $score );
		}

		if ( null !== $score ) {
			$memory['stats']['total_submissions_graded'] = ( $memory['stats']['total_submissions_graded'] ?? 0 ) + 1;
		}
		$memory['stats']['last_activity'] = current_time( 'mysql' );

		$this->save( $student_id, $course_id, $memory );
	}

	/**
	 * Notifica warning interno para depuración o auditoría.
	 *
	 * @param string $code    Código interno.
	 * @param array  $context Contexto mínimo.
	 * @return void
	 */
	protected function notify_warning( $code, $context = array() ) {
		$code    = sanitize_key( (string) $code );
		$context = is_array( $context ) ? $context : array();

		do_action( 'clms_student_memory_warning', $code, $context );
	}

	/**
	 * Registrar un mensaje de chat y el tema detectado.
	 * Llamar desde class-student-assistant.php tras procesar el mensaje.
	 *
	 * @param int    $user_id
	 * @param int    $course_id
	 * @param string $message    Mensaje del estudiante.
	 * @param string $sentiment  'positive' | 'negative' | 'confused' | 'neutral'
	 */
	public function record_chat_message( $user_id, $course_id, $message, $sentiment = 'neutral' ) {
		if ( ! $user_id || ! $course_id ) { return; }

		$memory = $this->load( $user_id, $course_id );

		// Cap de mensajes
		if ( ! isset( $memory['messages'] ) ) { $memory['messages'] = array(); }
		$memory['messages'][] = array(
			'text'      => mb_substr( $message, 0, 200 ),
			'sentiment' => $sentiment,
			'at'        => current_time( 'mysql' ),
		);
		if ( count( $memory['messages'] ) > self::MAX_MESSAGES ) {
			array_shift( $memory['messages'] );
		}

		// Actualizar conteo de sentimiento
		$memory['sentiment_counts'][ $sentiment ] = ( $memory['sentiment_counts'][ $sentiment ] ?? 0 ) + 1;
		$memory['stats']['total_messages']        = ( $memory['stats']['total_messages'] ?? 0 ) + 1;
		$memory['stats']['last_activity']         = current_time( 'mysql' );

		// Si hay muchos mensajes negativos/confusos seguidos, marcar para alerta
		$recent = array_slice( $memory['messages'], -5 );
		$negative_count = count( array_filter( $recent, fn( $m ) => in_array( $m['sentiment'], array( 'negative', 'confused' ), true ) ) );
		$memory['flags']['needs_attention'] = $negative_count >= 3;

		$this->save( $user_id, $course_id, $memory );
	}

	// ── Lectura de memoria para el asistente ─────────────────────────────────────

	/**
	 * Devuelve un bloque de texto para incluir en el system prompt del asistente.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return string
	 */
	public static function get_context_for_prompt( $user_id, $course_id ) {
		$memory = self::instance()->load( $user_id, $course_id );
		if ( empty( $memory['lessons'] ) && empty( $memory['difficult_topics'] ) ) {
			return '';
		}

		$lines = array( '--- PERFIL DE APRENDIZAJE DEL ESTUDIANTE ---' );

		// Temas con dificultad
		if ( ! empty( $memory['difficult_topics'] ) ) {
			$lines[] = 'Temas donde el estudiante ha mostrado dificultad:';
			foreach ( array_slice( $memory['difficult_topics'], 0, 5 ) as $topic ) {
				$lines[] = '  • ' . esc_html( $topic['topic'] ) . ' (puntuación: ' . $topic['last_score'] . '%, intentos: ' . $topic['count'] . ')';
			}
		}

		// Progreso reciente por lección
		$lesson_lines = array();
		foreach ( $memory['lessons'] as $lid => $data ) {
			$parts = array();
			if ( isset( $data['quiz_best_pct'] ) )     { $parts[] = 'quiz: ' . $data['quiz_best_pct'] . '%'; }
			if ( isset( $data['submission_score'] ) )  { $parts[] = 'entrega: ' . $data['submission_score'] . '%'; }
			if ( $parts ) {
				$lesson_lines[] = '  • ' . esc_html( $data['lesson_title'] ?? "Lección $lid" ) . ' — ' . implode( ', ', $parts );
			}
		}
		if ( $lesson_lines ) {
			$lines[] = 'Progreso por lección:';
			$lines   = array_merge( $lines, array_slice( $lesson_lines, -5 ) ); // últimas 5
		}

		// Sentimiento predominante
		$counts = $memory['sentiment_counts'] ?? array();
		if ( $counts ) {
			arsort( $counts );
			$dominant = key( $counts );
			if ( 'confused' === $dominant || 'negative' === $dominant ) {
				$lines[] = 'El estudiante ha mostrado confusión o frustración en sus últimas interacciones. Sé extra paciente y claro.';
			}
		}

		$lines[] = '--- FIN DEL PERFIL ---';
		return implode( "\n", $lines );
	}

	/**
	 * Devuelve los temas difíciles del estudiante para usar en recomendaciones.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return array[]  [{ topic, count, last_score, type }]
	 */
	public static function get_difficult_topics( $user_id, $course_id ) {
		$memory = self::instance()->load( $user_id, $course_id );
		return $memory['difficult_topics'] ?? array();
	}

	/**
	 * ¿El estudiante necesita atención del profesor?
	 */
	public static function needs_attention( $user_id, $course_id ) {
		$memory = self::instance()->load( $user_id, $course_id );
		return ! empty( $memory['flags']['needs_attention'] );
	}

	// ── AJAX: perfil para el profesor ─────────────────────────────────────────────

	public function ajax_get_memory() {
		check_ajax_referer( 'clms_get_student_memory', 'nonce' );
		if ( ! CLMS_Helper::user_can_manage_lms() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		$student_id = absint( $_POST['student_id'] ?? 0 );
		$course_id  = absint( $_POST['course_id'] ?? 0 );

		if ( ! $student_id || ! $course_id ) {
			wp_send_json_error( array( 'message' => __( 'Parámetros inválidos.', 'atora-lms' ) ) );
		}

		$memory = $this->load( $student_id, $course_id );
		$student = get_userdata( $student_id );

		wp_send_json_success( array(
			'student_name'    => $student ? $student->display_name : "Usuario $student_id",
			'difficult_topics'=> $memory['difficult_topics'] ?? array(),
			'lessons'         => $memory['lessons'] ?? array(),
			'sentiment_counts'=> $memory['sentiment_counts'] ?? array(),
			'stats'           => $memory['stats'] ?? array(),
			'needs_attention' => ! empty( $memory['flags']['needs_attention'] ),
			'last_activity'   => $memory['stats']['last_activity'] ?? '',
		) );
	}

	// ── Shortcode: perfil del propio estudiante ────────────────────────────────────

	public function shortcode_profile( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>Debes iniciar sesión para ver tu perfil de aprendizaje.</p>';
		}

		$atts      = shortcode_atts( array( 'course_id' => 0 ), $atts );
		$user_id   = get_current_user_id();
		$course_id = absint( $atts['course_id'] );

		if ( ! $course_id ) {
			return '<p>Indica el ID del curso: <code>[clms_my_learning_profile course_id="123"]</code></p>';
		}

		$memory = $this->load( $user_id, $course_id );

		ob_start();
		?>
		<div class="clms-learning-profile" style="font-family:sans-serif;max-width:680px">
			<h3 style="margin:0 0 16px;font-size:18px">Mi perfil de aprendizaje</h3>

			<?php if ( ! empty( $memory['difficult_topics'] ) ) : ?>
			<div style="background:#fff8e1;border-left:4px solid #ffc107;padding:12px 16px;margin-bottom:16px;border-radius:4px">
				<strong>Temas para reforzar:</strong>
				<ul style="margin:8px 0 0;padding-left:20px">
					<?php foreach ( $memory['difficult_topics'] as $t ) : ?>
					<li><?php echo esc_html( $t['topic'] ); ?> <span style="color:#888;font-size:12px">(mejor puntaje: <?php echo (int) $t['last_score']; ?>%)</span></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $memory['lessons'] ) ) : ?>
			<table style="width:100%;border-collapse:collapse;font-size:14px">
				<thead>
					<tr style="background:#f5f5f5">
						<th style="text-align:left;padding:8px 12px;border-bottom:1px solid #ddd">Lección</th>
						<th style="padding:8px 12px;border-bottom:1px solid #ddd">Quiz</th>
						<th style="padding:8px 12px;border-bottom:1px solid #ddd">Entrega</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $memory['lessons'] as $lid => $d ) : ?>
					<tr style="border-bottom:1px solid #eee">
						<td style="padding:8px 12px"><?php echo esc_html( $d['lesson_title'] ?? "Lección $lid" ); ?></td>
						<td style="padding:8px 12px;text-align:center">
							<?php echo isset( $d['quiz_best_pct'] ) ? esc_html( $d['quiz_best_pct'] ) . '%' : '—'; ?>
						</td>
						<td style="padding:8px 12px;text-align:center">
							<?php echo isset( $d['submission_score'] ) ? esc_html( $d['submission_score'] ) . '%' : '—'; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( empty( $memory['lessons'] ) && empty( $memory['difficult_topics'] ) ) : ?>
			<p style="color:#888">Aún no hay datos de aprendizaje registrados para este curso.</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Helpers internos ──────────────────────────────────────────────────────────

	public function load( $user_id, $course_id ) {
		$raw = get_user_meta( $user_id, self::META_PREFIX . $course_id, true );
		return is_array( $raw ) ? $raw : array();
	}

	public function save( $user_id, $course_id, $memory ) {
		update_user_meta( $user_id, self::META_PREFIX . $course_id, $memory );
	}

	private function flag_topic_difficulty( &$memory, $topic_name, $type, $score ) {
		if ( ! isset( $memory['difficult_topics'] ) ) {
			$memory['difficult_topics'] = array();
		}

		// Buscar si ya existe
		foreach ( $memory['difficult_topics'] as &$t ) {
			if ( $t['topic'] === $topic_name ) {
				$t['count']++;
				$t['last_score'] = $score;
				$t['type']       = $type;
				$t['last_seen']  = current_time( 'mysql' );
				return;
			}
		}
		unset( $t );

		// Agregar nuevo
		$memory['difficult_topics'][] = array(
			'topic'      => $topic_name,
			'count'      => 1,
			'last_score' => $score,
			'type'       => $type,
			'last_seen'  => current_time( 'mysql' ),
		);

		// Limitar al máximo
		if ( count( $memory['difficult_topics'] ) > self::MAX_TOPICS ) {
			// Quitar el menos reciente
			usort( $memory['difficult_topics'], fn( $a, $b ) => strcmp( $b['last_seen'], $a['last_seen'] ) );
			$memory['difficult_topics'] = array_slice( $memory['difficult_topics'], 0, self::MAX_TOPICS );
		}
	}

	/**
	 * Borra la memoria de un estudiante en un curso.
	 * Útil para el panel de admin.
	 */
	public static function clear( $user_id, $course_id ) {
		delete_user_meta( absint( $user_id ), self::META_PREFIX . absint( $course_id ) );
	}

	/**
	 * Devuelve un resumen humano del perfil de aprendizaje para UI.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return array
	 */
	public static function get_memory_insights( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return array(
				'title'       => 'Tu aprendizaje esta semana',
				'focus_topics'=> array(),
				'reinforce_topics' => array(),
				'highlight_topics' => array(),
				'message'     => __( 'Cuando avances en el curso, aquí verás patrones y recomendaciones útiles.', 'atora-lms' ),
				'summary'     => 'Cuando avances en el curso, aquí verás patrones y recomendaciones útiles.',
				'recommendation' => 'Completa tu siguiente lección para activar sugerencias personalizadas.',
				'last_seen'   => '',
				'needs_attention' => false,
			);
		}

		$memory = self::instance()->load( $user_id, $course_id );
		$focus  = array();
		$reinforce = array();
		foreach ( array_slice( (array) ( $memory['difficult_topics'] ?? array() ), 0, 3 ) as $topic ) {
			if ( empty( $topic['topic'] ) ) {
				continue;
			}
			$focus[] = array(
				'label' => (string) $topic['topic'],
				'score' => isset( $topic['last_score'] ) ? (int) $topic['last_score'] : '',
			);
			$reinforce[] = (string) $topic['topic'];
		}

		$highlights = array();
		if ( ! empty( $memory['lessons'] ) && is_array( $memory['lessons'] ) ) {
			$scores = array();
			foreach ( $memory['lessons'] as $data ) {
				$title = isset( $data['lesson_title'] ) ? (string) $data['lesson_title'] : '';
				if ( ! $title ) {
					continue;
				}
				$score = 0;
				if ( isset( $data['quiz_best_pct'] ) ) {
					$score = max( $score, (int) $data['quiz_best_pct'] );
				}
				if ( isset( $data['submission_score'] ) ) {
					$score = max( $score, (int) $data['submission_score'] );
				}
				if ( $score >= 75 ) {
					$scores[ $title ] = $score;
				}
			}
			arsort( $scores );
			$highlights = array_slice( array_keys( $scores ), 0, 2 );
		}

		$reinforce = array_slice( $reinforce, 0, 2 );

		$last_activity = $memory['stats']['last_activity'] ?? '';
		$summary = '';
		$recommendation = '';
		if ( $reinforce ) {
			$summary = 'Hay temas donde podrías consolidar mejor el avance. Enfócate en uno hoy.';
			$recommendation = 'Elige uno de los temas para reforzar y repasa la lección relacionada.';
		} elseif ( $highlights ) {
			$summary = 'Vas con buen ritmo y ya muestras avances claros en varios temas.';
			$recommendation = 'Sigue avanzando con la siguiente lección para mantener el progreso.';
		} else {
			$summary = 'Tu progreso está tomando forma. Sigue avanzando para generar más señales.';
			$recommendation = 'Completa tu siguiente lección para activar más recomendaciones.';
		}

		$message = $summary;
		$cta = array();
		if ( $reinforce && ! empty( $memory['lessons'] ) && is_array( $memory['lessons'] ) ) {
			$target_topic = (string) $reinforce[0];
			foreach ( $memory['lessons'] as $lesson_id => $data ) {
				$lesson_title = isset( $data['lesson_title'] ) ? (string) $data['lesson_title'] : '';
				if ( '' === $lesson_title ) {
					continue;
				}
				if ( 0 === strcasecmp( $lesson_title, $target_topic ) ) {
					$lesson_id = absint( $lesson_id );
					if ( $lesson_id ) {
						$url = get_permalink( $lesson_id );
						if ( $url ) {
							$cta = array(
								'label' => 'Repasar lección',
								'url'   => $url,
							);
						}
					}
					break;
				}
			}
		}

		return array(
			'title'           => 'Tu aprendizaje esta semana',
			'focus_topics'    => $focus,
			'reinforce_topics' => $reinforce,
			'highlight_topics' => $highlights,
			'message'         => $message,
			'summary'         => $summary,
			'recommendation'  => $recommendation,
			'cta'             => $cta,
			'last_seen'       => $last_activity,
			'needs_attention' => ! empty( $memory['flags']['needs_attention'] ),
		);
	}
}
