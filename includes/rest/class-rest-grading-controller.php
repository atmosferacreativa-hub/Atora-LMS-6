<?php
/**
 * CLMS_REST_Grading_Controller — Endpoints de Grading Engine y Feedback Loop.
 *
 * Namespace: clms/v1
 *
 * Rutas registradas:
 *   GET  /grades/engine/{user_id}                  — nota final ponderada
 *   GET  /grades/breakdown/{user_id}               — desglose completo
 *   GET  /grades/scheme/{course_id}               — esquema del curso
 *   PUT  /grades/scheme/{course_id}               — guardar esquema (instructor)
 *   POST /grades/appeal                            — enviar apelación
 *   PUT  /grades/appeal/{appeal_id}/process       — procesar apelación (instructor)
 *   GET  /feedback/action-plan/{tracking_id}       — plan de acción
 *   POST /feedback/practice/generate              — generar práctica para un gap
 *   POST /feedback/progress/{tracking_id}/event   — registrar evento de progreso
 *   GET  /feedback/trackings/{user_id}            — listado de trackings del usuario
 *
 * @package CustomLMSCore
 * @since   4.22
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_REST_Grading_Controller {

	private $permissions;

	public function __construct( CLMS_REST_Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	// ── Grading Engine ────────────────────────────────────────────────────────

	/**
	 * GET /grades/engine/{user_id}?course_id=X
	 * Devuelve la nota final ponderada del usuario en un curso.
	 */
	public function get_final_grade( WP_REST_Request $request ) {
		$user_id   = absint( $request->get_param( 'user_id' ) );
		$course_id = absint( $request->get_param( 'course_id' ) );

		if ( ! $user_id || ! $course_id ) {
			return new WP_Error( 'missing_params', __( 'Se requieren user_id y course_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$engine = $this->resolve_grading_engine( $course_id, $user_id );
		if ( ! $engine ) {
			return new WP_Error( 'grading_unavailable', __( 'El motor de calificación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$grade  = $engine->calculate_final_grade( $user_id, $course_id );

		return rest_ensure_response( $grade );
	}

	/**
	 * GET /grades/breakdown/{user_id}?course_id=X
	 * Igual que get_final_grade pero resalta el breakdown para la UI.
	 */
	public function get_grade_breakdown( WP_REST_Request $request ) {
		$user_id   = absint( $request->get_param( 'user_id' ) );
		$course_id = absint( $request->get_param( 'course_id' ) );

		if ( ! $user_id || ! $course_id ) {
			return new WP_Error( 'missing_params', __( 'Se requieren user_id y course_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$engine = $this->resolve_grading_engine( $course_id, $user_id );
		if ( ! $engine ) {
			return new WP_Error( 'grading_unavailable', __( 'El motor de calificación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$grade  = $engine->calculate_final_grade( $user_id, $course_id );

		// La respuesta del breakdown pone el desglose al nivel raíz para la UI.
		$response = array(
			'student_id'    => $grade['student_id'],
			'course_id'     => $grade['course_id'],
			'numeric_score' => $grade['numeric_score'],
			'letter_grade'  => $grade['letter_grade'],
			'percentile'    => $grade['percentile'],
			'components'    => isset( $grade['breakdown']['components'] ) ? $grade['breakdown']['components'] : array(),
			'summary'       => isset( $grade['breakdown']['summary'] ) ? $grade['breakdown']['summary'] : array(),
			'recommendations' => isset( $grade['breakdown']['recommendations'] ) ? $grade['breakdown']['recommendations'] : array(),
			'total_weight_used' => $grade['total_weight_used'],
			'calculated_at' => $grade['calculated_at'],
		);

		return rest_ensure_response( $response );
	}

	/**
	 * GET /grades/scheme/{course_id}
	 */
	public function get_grading_scheme( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );

		if ( ! $course_id ) {
			return new WP_Error( 'missing_course', __( 'course_id requerido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$engine = $this->resolve_grading_engine();
		if ( ! $engine ) {
			return new WP_Error( 'grading_unavailable', __( 'El motor de calificación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$scheme = $engine->load_grading_scheme( $course_id );

		return rest_ensure_response( $scheme );
	}

	/**
	 * PUT /grades/scheme/{course_id}
	 */
	public function update_grading_scheme( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		$scheme    = $request->get_json_params();

		if ( ! $course_id || empty( $scheme ) ) {
			return new WP_Error( 'missing_params', __( 'course_id y cuerpo JSON requeridos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$engine = $this->resolve_grading_engine();
		if ( ! $engine ) {
			return new WP_Error( 'grading_unavailable', __( 'El motor de calificación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$saved  = $engine->configure_grading_scheme( $course_id, $scheme );

		if ( ! $saved ) {
			return new WP_Error( 'invalid_scheme', __( 'Esquema inválido. Verifica que los pesos de los componentes sumen 100.', 'atora-lms' ), array( 'status' => 422 ) );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'scheme'   => $engine->load_grading_scheme( $course_id ),
			'message'  => __( 'Esquema guardado correctamente.', 'atora-lms' ),
		) );
	}

	/**
	 * POST /grades/appeal
	 * Body: { submission_id, component, reason }
	 */
	public function submit_appeal( WP_REST_Request $request ) {
		$student_id    = get_current_user_id();
		$submission_id = absint( $request->get_param( 'submission_id' ) );
		$component     = sanitize_key( $request->get_param( 'component' ) );
		$reason        = sanitize_textarea_field( $request->get_param( 'reason' ) );

		if ( ! $submission_id || ! $component || ! $reason ) {
			return new WP_Error( 'missing_params', __( 'submission_id, component y reason son requeridos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$engine = $this->resolve_grading_engine();
		if ( ! $engine ) {
			return new WP_Error( 'grading_unavailable', __( 'El motor de calificación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$appeal = $engine->submit_grade_appeal( $student_id, $submission_id, $component, $reason );

		if ( ! $appeal ) {
			return new WP_Error( 'appeal_failed', __( 'No se pudo registrar la apelación.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $appeal, 201 );
	}

	/**
	 * PUT /grades/appeal/{appeal_id}/process
	 * Body: { decision, new_grade?, notes? }
	 */
	public function process_appeal( WP_REST_Request $request ) {
		$appeal_id = sanitize_text_field( $request->get_param( 'appeal_id' ) );
		$decision  = sanitize_key( $request->get_param( 'decision' ) );
		$new_grade = $request->get_param( 'new_grade' );
		$notes     = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		if ( ! $appeal_id || ! in_array( $decision, array( 'approved', 'rejected', 'needs_review' ), true ) ) {
			return new WP_Error( 'invalid_params', __( 'appeal_id y decision (approved|rejected|needs_review) requeridos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( 'approved' === $decision && null === $new_grade ) {
			return new WP_Error( 'missing_grade', __( 'new_grade es requerido cuando la decisión es approved.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$engine  = $this->resolve_grading_engine();
		if ( ! $engine ) {
			return new WP_Error( 'grading_unavailable', __( 'El motor de calificación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$success = $engine->process_appeal(
			$appeal_id,
			$decision,
			$new_grade !== null ? (float) $new_grade : null,
			$notes
		);

		if ( ! $success ) {
			return new WP_Error( 'process_failed', __( 'No se pudo procesar la apelación. Verifica que exista y esté pendiente.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'message'  => __( 'Apelación procesada.', 'atora-lms' ),
			'decision' => $decision,
		) );
	}

	// ── Feedback Loop ─────────────────────────────────────────────────────────

	/**
	 * GET /feedback/action-plan/{tracking_id}
	 */
	public function get_action_plan( WP_REST_Request $request ) {
		$tracking_id = sanitize_text_field( $request->get_param( 'tracking_id' ) );

		if ( ! $tracking_id ) {
			return new WP_Error( 'missing_params', __( 'tracking_id requerido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$loop = $this->resolve_feedback_loop();
		if ( ! $loop ) {
			return new WP_Error( 'feedback_loop_unavailable', __( 'El servicio de retroalimentación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$record = $loop->get_tracking_record( $tracking_id );

		if ( ! $record ) {
			return new WP_Error( 'not_found', __( 'Tracking no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$record['gaps_tracked'] = json_decode( $record['gaps_tracked'], true );
		$record['checkpoints']  = json_decode( $record['checkpoints'], true );
		$record['metrics']      = json_decode( $record['metrics'], true );

		return rest_ensure_response( $record );
	}

	/**
	 * POST /feedback/practice/generate
	 * Body: { gap_area, gap_label, current_level, difficulty? }
	 */
	public function generate_practice( WP_REST_Request $request ) {
		$gap_area      = sanitize_key( $request->get_param( 'gap_area' ) );
		$gap_label     = sanitize_text_field( $request->get_param( 'gap_label' ) );
		$current_level = (float) $request->get_param( 'current_level' );
		$difficulty    = sanitize_key( (string) $request->get_param( 'difficulty' ) );

		if ( ! $gap_area || ! $gap_label ) {
			return new WP_Error( 'missing_params', __( 'gap_area y gap_label requeridos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$gap = array(
			'area'          => $gap_area,
			'label'         => $gap_label,
			'current_level' => $current_level,
			'gap_size'      => 100 - $current_level,
		);

		if ( ! $difficulty ) {
			$difficulty = $current_level < 40 ? 'beginner' : ( $current_level < 65 ? 'intermediate' : 'advanced' );
		}

		$loop = $this->resolve_feedback_loop();
		if ( ! $loop ) {
			return new WP_Error( 'feedback_loop_unavailable', __( 'El servicio de retroalimentación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$items = $loop->ai_generate_practice_items( $gap, $difficulty );

		return rest_ensure_response( array(
			'gap_area'   => $gap_area,
			'difficulty' => $difficulty,
			'items'      => $items,
			'count'      => count( $items ),
		) );
	}

	/**
	 * POST /feedback/progress/{tracking_id}/event
	 * Body: { event_type, event_data? }
	 */
	public function record_progress_event( WP_REST_Request $request ) {
		$tracking_id = sanitize_text_field( $request->get_param( 'tracking_id' ) );
		$event_type  = sanitize_key( $request->get_param( 'event_type' ) );
		$event_data  = $request->get_param( 'event_data' );

		if ( ! $tracking_id || ! $event_type ) {
			return new WP_Error( 'missing_params', __( 'tracking_id y event_type requeridos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$allowed_events = array( 'resource_accessed', 'practice_completed', 'reassessment_taken' );
		if ( ! in_array( $event_type, $allowed_events, true ) ) {
			return new WP_Error( 'invalid_event', __( 'Tipo de evento no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$loop = $this->resolve_feedback_loop();
		if ( ! $loop ) {
			return new WP_Error( 'feedback_loop_unavailable', __( 'El servicio de retroalimentación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$updated = $loop->update_progress(
			$tracking_id,
			$event_type,
			is_array( $event_data ) ? $event_data : array()
		);

		if ( ! $updated ) {
			return new WP_Error( 'update_failed', __( 'No se pudo actualizar el progreso. Verifica el tracking_id.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$updated['metrics'] = json_decode( $updated['metrics'], true );

		return rest_ensure_response( $updated );
	}

	/**
	 * GET /feedback/trackings/{user_id}?course_id=X
	 */
	public function get_user_trackings( WP_REST_Request $request ) {
		$user_id   = absint( $request->get_param( 'user_id' ) );
		$course_id = absint( $request->get_param( 'course_id' ) );

		if ( ! $user_id || ! $course_id ) {
			return new WP_Error( 'missing_params', __( 'user_id y course_id requeridos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$loop = $this->resolve_feedback_loop();
		if ( ! $loop ) {
			return new WP_Error( 'feedback_loop_unavailable', __( 'El servicio de retroalimentación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$trackings = $loop->get_student_trackings( $user_id, $course_id );

		foreach ( $trackings as &$t ) {
			$t['gaps_tracked'] = json_decode( $t['gaps_tracked'], true );
			$t['metrics']      = json_decode( $t['metrics'], true );
		}
		unset( $t );

		return rest_ensure_response( $trackings );
	}

	// ── Permission helpers ────────────────────────────────────────────────────

	/**
	 * Puede ver el recurso del usuario: admin, instructor, o el propio usuario.
	 */
	public function can_view_grade_resource( WP_REST_Request $request ) {
		return $this->permissions->can_view_user_resource( $request );
	}

	/**
	 * Solo instructores/admins pueden gestionar esquemas y procesar apelaciones.
	 */
	public function can_manage_grading( WP_REST_Request $request ) {
		return $this->permissions->can_manage_content();
	}

	/**
	 * Cualquier usuario logueado puede enviar una apelación o registrar progreso.
	 */
	public function can_access_logged_in( WP_REST_Request $request ) {
		return $this->permissions->can_access_logged_in();
	}

	/**
	 * Resuelve Grading Engine desde loader para evitar instancias repetidas.
	 *
	 * @param int|null $course_id  Curso para fallback legacy.
	 * @param int|null $student_id Estudiante para fallback legacy.
	 * @return object|null
	 */
	protected function resolve_grading_engine( $course_id = null, $student_id = null ) {
		$engine = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading_Engine') : null;
		if ( $engine ) {
			return $engine;
		}
		return class_exists( 'CLMS_Grading_Engine' ) ? new CLMS_Grading_Engine( $course_id, $student_id ) : null;
	}

	/**
	 * Resuelve Feedback Loop desde loader para evitar hooks duplicados.
	 *
	 * @return object|null
	 */
	protected function resolve_feedback_loop() {
		$loop = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Feedback_Loop') : null;
		if ( $loop ) {
			return $loop;
		}
		return class_exists( 'CLMS_Feedback_Loop' ) ? new CLMS_Feedback_Loop() : null;
	}
}
