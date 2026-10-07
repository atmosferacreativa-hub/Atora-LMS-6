<?php
/**
 * API móvil — IA (6.32.0).
 *
 * - `POST /ai/assistant` (estudiante): pregunta sobre una lección o un curso.
 * - `POST /teacher/submissions/{id}/ai-suggestion` y `GET /teacher/ai-suggestions/{job_id}`
 *   (docente): sugerencia de calificación asíncrona.
 *
 * Cada ruta responde 404 si su función no está activada o no hay proveedor
 * (y `/discovery` no la declara). Sin caché, como toda la API.
 *
 * @package ATORA_LMS
 * @since 6.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_AI_Controller {

	const REPLAY_TTL = 600;

	public static function register_routes(): void {
		$ns = ATORA_Mobile_REST_Controller::REST_NAMESPACE;
		register_rest_route( $ns, '/ai/assistant', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'assistant' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
		) );
		register_rest_route( $ns, '/teacher/submissions/(?P<submission_id>\d+)/ai-suggestion', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'request_suggestion' ),
			'permission_callback' => array( 'ATORA_Mobile_Teacher_Controller', 'authorize' ),
		) );
		register_rest_route( $ns, '/teacher/ai-suggestions/(?P<job_id>[a-f0-9-]{36})', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'suggestion' ),
			'permission_callback' => array( 'ATORA_Mobile_Teacher_Controller', 'authorize' ),
		) );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'atora_mobile_not_found', __( 'No encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
	}

	/** POST /ai/assistant { lesson_id | course_id, message, history[], client_event_id } */
	public static function assistant( WP_REST_Request $request ) {
		if ( ! ATORA_AI_Usage_Service::available( ATORA_AI_Usage_Service::ASSISTANT ) ) {
			return self::not_found();
		}
		$user_id = get_current_user_id();
		$params  = (array) $request->get_json_params();
		$lesson  = absint( $params['lesson_id'] ?? 0 );
		$course  = absint( $params['course_id'] ?? 0 );
		$wp_less = 0;
		if ( $lesson > 0 ) {
			$row = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson );
			if ( ! $row || 'published' !== (string) ( $row['status'] ?? '' ) ) {
				return self::not_found();
			}
			$course  = absint( $row['course_id'] );
			$wp_less = absint( $row['wp_post_id'] ?? 0 );
		}
		if ( $course <= 0 ) {
			return self::not_found();
		}
		$auth = ATORA_Mobile_REST_Controller::authorize_course_id( $user_id, $course );
		if ( is_wp_error( $auth ) ) {
			return self::not_found();
		}
		$table_course = \ATORA\LMS\LMS_Course_Service::get( $course );
		$wp_course    = absint( $table_course['wp_post_id'] ?? 0 );

		$event = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		if ( '' === $event || strlen( $event ) > 64 ) {
			return new WP_Error( 'atora_mobile_client_event_required', __( 'Falta client_event_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		// El mismo envío (reintento) devuelve la misma respuesta sin volver a cobrar.
		$replay_key = 'atora_ai_q_' . md5( $user_id . '|' . $event );
		$replay     = get_transient( $replay_key );
		if ( is_array( $replay ) ) {
			return new WP_REST_Response( $replay + array( 'replayed' => true ), 200 );
		}

		$message = sanitize_textarea_field( (string) ( $params['message'] ?? '' ) );
		$history = array();
		foreach ( (array) ( $params['history'] ?? array() ) as $turn ) {
			if ( is_array( $turn ) && in_array( $turn['role'] ?? '', array( 'user', 'assistant' ), true ) ) {
				$history[] = array( 'role' => $turn['role'], 'content' => sanitize_textarea_field( (string) ( $turn['content'] ?? '' ) ) );
			}
		}
		$result = ( new ATORA_AI_Assistant_Service() )->ask_lesson( $user_id, $wp_course, $wp_less, $message, $history );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$out = array( 'reply' => $result['reply'], 'disclaimer' => __( 'Las respuestas las genera una IA y pueden contener errores. No compartas datos personales.', 'atora-lms' ) );
		set_transient( $replay_key, $out, self::REPLAY_TTL );
		return new WP_REST_Response( $out + array( 'replayed' => false ), 200 );
	}

	/** POST /teacher/submissions/{id}/ai-suggestion → 202 { job_id } */
	public static function request_suggestion( WP_REST_Request $request ) {
		$user_id       = get_current_user_id();
		$submission_id = absint( $request['submission_id'] );
		if ( ! ATORA_AI_Usage_Service::available( ATORA_AI_Usage_Service::SUGGESTION ) || ! ATORA_Teacher_Scope::can_grade_submission( $user_id, $submission_id ) ) {
			return self::not_found();
		}
		$job = ATORA_AI_Grading_Suggestion_Service::request( $submission_id, $user_id );
		return is_wp_error( $job ) ? $job : new WP_REST_Response( $job, 202 );
	}

	/** GET /teacher/ai-suggestions/{job_id} → { status, suggestion?, error? } */
	public static function suggestion( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$job     = ATORA_AI_Grading_Suggestion_Service::job( (string) $request['job_id'] );
		if ( ! ATORA_AI_Usage_Service::available( ATORA_AI_Usage_Service::SUGGESTION ) || ! $job || ! ATORA_Teacher_Scope::can_grade_submission( $user_id, (int) $job['submission_id'] ) ) {
			return self::not_found();
		}
		// 6.33.0: sin cola que lo tome a tiempo, lo resuelve esta consulta.
		if ( 'pending' === $job['status'] ) {
			ATORA_AI_Grading_Suggestion_Service::run_if_stalled( (string) $job['id'] );
			$job = ATORA_AI_Grading_Suggestion_Service::job( (string) $job['id'] );
		}
		return new WP_REST_Response( array(
			'job_id'        => (string) $job['id'],
			'submission_id' => (int) $job['submission_id'],
			'status'        => (string) $job['status'],
			'suggestion'    => 'done' === $job['status'] ? $job['result'] : null,
			'error'         => 'failed' === $job['status'] ? (string) $job['error'] : null,
		), 200 );
	}
}
