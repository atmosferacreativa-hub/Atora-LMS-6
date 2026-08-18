<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Assistant_Context_Trait {
	// ── Context builder ───────────────────────────────────────────────────────

	/**
	 * Construye el contexto situacional del profesor para el prompt del sistema.
	 */
	protected function build_context( $lesson_id, $course_id, $user_id ) {
		$ctx = array(
			'lesson_id'       => 0,
			'lesson_title'    => '',
			'lesson_content'  => '',
			'lesson_subtitle' => '',
			'transcription'   => '',
			'rubric_text'     => '',
			'course_id'       => 0,
			'course_title'    => '',
			'lesson_count'    => 0,
			'enrolled_count'  => 0,
			'pending_reviews' => 0,
			'grade_avg'       => '',
			'user_name'       => '',
		);

		$user = get_user_by( 'id', $user_id );
		if ( $user ) {
			$ctx['user_name'] = $user->display_name ?: $user->user_login;
		}

		if ( $lesson_id && 'lm_lesson' === get_post_type( $lesson_id ) ) {
			$ctx['lesson_id']    = $lesson_id;
			$ctx['lesson_title'] = get_the_title( $lesson_id );

			$post = get_post( $lesson_id );
			if ( $post ) {
				$raw_content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
				// Recortar para no saturar el contexto
				$ctx['lesson_content'] = mb_substr( $raw_content, 0, 2000 );
			}

			$ctx['lesson_subtitle'] = (string) get_post_meta( $lesson_id, '_clms_lesson_subtitle', true );

			// Transcripción
			if ( class_exists( 'CLMS_Transcription' ) ) {
				$tr = CLMS_Transcription::get_text( $lesson_id );
				if ( $tr ) {
					$words = explode( ' ', $tr );
					// Máximo 1500 palabras de la transcripción en el contexto
					$ctx['transcription'] = implode( ' ', array_slice( $words, 0, 1500 ) );
				}
			}

			// Rúbrica
			$rubric_id = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );
			if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
				$ctx['rubric_text'] = CLMS_Rubric::format_criteria_for_prompt( $rubric_id );
			}

			if ( ! $course_id ) {
				$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_id_from_lesson( $lesson_id ) : 0;
			}
		}

		if ( $course_id && 'lm_course' === get_post_type( $course_id ) ) {
			$ctx['course_id']    = $course_id;
			$ctx['course_title'] = get_the_title( $course_id );

			$lessons = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
			$ctx['lesson_count'] = is_array( $lessons ) ? count( $lessons ) : 0;

			// Matriculados
			$enrolled = get_post_meta( $course_id, '_clms_enrolled_users', true );
			$ctx['enrolled_count'] = is_array( $enrolled ) ? count( $enrolled ) : 0;

			// Entregas pendientes del curso
			if ( $ctx['lesson_count'] > 0 && is_array( $lessons ) ) {
				$pending = get_posts( array(
					'post_type'      => 'clms_submission',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_clms_submission_lesson_id',
							'value'   => array_map( 'absint', $lessons ),
							'compare' => 'IN',
						),
						array(
							'key'     => '_clms_submission_status',
							'value'   => array( 'submitted', 'in_review' ),
							'compare' => 'IN',
						),
					),
				) );
				$ctx['pending_reviews'] = count( $pending );
			}
		}

		return $ctx;
	}

	/**
	 * Genera el prompt de sistema con todo el contexto.
	 *
	 * @param array  $context Contexto construido por build_context().
	 * @param string $query   Mensaje/consulta actual del usuario (para RAG).
	 */
	protected function build_system_prompt( $context, $query = '' ) {
		$lines = array();

		$lines[] = 'Eres el Asistente ATORA, un asistente educativo experto que ayuda a profesores a diseñar, mejorar y gestionar sus cursos.';
		$lines[] = 'Siempre respondes en español, con un tono profesional, cálido y pedagógico.';
		$lines[] = 'Tus respuestas son concretas, accionables y listas para usar.';
		$lines[] = 'Cuando el contenido del curso esté disponible en el contexto, úsalo para dar respuestas precisas y relevantes al material real del curso.';
		$lines[] = '';

		if ( $context['user_name'] ) {
			$lines[] = 'Profesor/a: ' . $context['user_name'];
		}

		if ( $context['course_title'] ) {
			$lines[] = 'Curso activo: ' . $context['course_title'];
			$lines[] = 'Lecciones en el curso: ' . $context['lesson_count'];
			$lines[] = 'Estudiantes matriculados: ' . $context['enrolled_count'];
			if ( $context['pending_reviews'] > 0 ) {
				$lines[] = 'Entregas pendientes de revisión: ' . $context['pending_reviews'];
			}
		}

		if ( $context['lesson_title'] ) {
			$lines[] = '';
			$lines[] = '--- LECCIÓN ACTIVA ---';
			$lines[] = 'Título: ' . $context['lesson_title'];
			if ( $context['lesson_subtitle'] ) {
				$lines[] = 'Subtítulo: ' . $context['lesson_subtitle'];
			}
			if ( $context['lesson_content'] ) {
				$lines[] = 'Contenido de la lección (fragmento):';
				$lines[] = $context['lesson_content'];
			}
		}

		if ( $context['transcription'] ) {
			$lines[] = '';
			$lines[] = '--- TRANSCRIPCIÓN DEL VIDEO (fragmento) ---';
			$lines[] = $context['transcription'];
		}

		if ( $context['rubric_text'] ) {
			$lines[] = '';
			$lines[] = '--- RÚBRICA ASIGNADA ---';
			$lines[] = $context['rubric_text'];
		}

		// ── RAG: fragmentos relevantes del Knowledge Base del curso ──────────────
		if ( $query && $context['course_id'] && class_exists( 'CLMS_AI_Knowledge_Base' ) ) {
			$kb = CLMS_AI_Knowledge_Base::instance();

			// Solo buscar en la KB si hay contenido indexado
			if ( $kb->has_index( $context['course_id'] ) ) {
				// En modo lección, buscar también en el contenido de esa lección
				$search_query = $query;
				if ( $context['lesson_title'] ) {
					$search_query = $context['lesson_title'] . ' ' . $query;
				}

				$kb_context = $kb->get_context_for_query( $context['course_id'], $search_query, 3 );

				if ( $kb_context ) {
					$lines[] = '';
					$lines[] = '--- CONTENIDO DEL CURSO (fragmentos relevantes a la consulta) ---';
					$lines[] = $kb_context;
					$lines[] = '--- FIN DEL CONTENIDO ---';
				}
			}
		}

		return implode( "\n", $lines );
	}

}
