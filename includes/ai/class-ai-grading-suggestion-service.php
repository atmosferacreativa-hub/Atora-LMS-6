<?php
/**
 * Sugerencia de calificación con IA (6.32.0), para la app del docente y
 * SpeedGrader web (el mismo servicio).
 *
 * - Solo entregas de tareas abiertas; nunca quizzes.
 * - Asíncrona: `request()` crea un trabajo en cola (Action Scheduler o cron);
 *   `run()` lo resuelve; `job()` devuelve el estado.
 * - Resultado: por criterio de la rúbrica, puntaje sugerido (recortado a su
 *   rango, decimales permitidos), nivel o banda con `CLMS_Rubric_Level_Bands` y
 *   justificación breve; devolución general sugerida; e **indicio de IA**
 *   (`ai_likelihood` bajo/medio/alto + explicación), que solo ve el docente,
 *   nunca baja nada solo y siempre lleva su aviso.
 * - Se guarda como **sugerencia** (meta propia), separada de la calificación.
 *   Calificar sigue pasando solo por `ATORA_Grading_Save_Service`.
 * - Al proveedor no se envía nada que identifique al estudiante.
 *
 * @package ATORA_LMS
 * @since 6.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_AI_Grading_Suggestion_Service {

	const META       = '_atora_ai_grading_suggestion';
	const RUN_HOOK   = 'atora_ai_suggestion_run';
	const DISCLAIMER = 'Indicio no concluyente. Verifica con el estudiante antes de decidir.';
	const LIKELIHOOD = array( 'bajo', 'medio', 'alto' );

	public static function boot(): void {
		add_action( self::RUN_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_post_atora_ai_suggestion_request', array( __CLASS__, 'handle_web_request' ) );
	}

	/** SpeedGrader web: pedir la sugerencia (el mismo servicio que la app). */
	public static function handle_web_request(): void {
		$submission_id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		check_admin_referer( 'atora_ai_suggestion_' . $submission_id );
		$user_id = get_current_user_id();
		if ( ! ATORA_AI_Usage_Service::available( ATORA_AI_Usage_Service::SUGGESTION ) || ! ATORA_Teacher_Scope::can_grade_submission( $user_id, $submission_id ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		$job    = self::request( $submission_id, $user_id );
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : admin_url();
		if ( is_wp_error( $job ) ) {
			wp_safe_redirect( add_query_arg( 'ai_error', rawurlencode( $job->get_error_message() ), remove_query_arg( 'ai_job', $return ) ) . '#atora-ai-suggestion' );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'ai_job', $job['job_id'], $return ) . '#atora-ai-suggestion' );
		exit;
	}

	private static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_ai_jobs';
	}

	/** ¿Entrega de una tarea abierta (no un quiz)? */
	public static function is_open_task( int $submission_id ): bool {
		$lesson = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		if ( ! $lesson || ! class_exists( 'ATORA_Mobile_REST_Controller' ) || ! ATORA_Mobile_REST_Controller::lesson_has_assignment( $lesson ) ) {
			return false;
		}
		global $wpdb;
		$linked = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}atora_quiz_submissions WHERE wp_post_id = %d LIMIT 1", $submission_id ) ); // phpcs:ignore WordPress.DB
		return ! $linked;
	}

	/**
	 * Pide una sugerencia: crea el trabajo y lo encola.
	 *
	 * @return array|WP_Error {job_id, status}
	 */
	public static function request( int $submission_id, int $teacher_id ) {
		if ( ! self::is_open_task( $submission_id ) ) {
			return new WP_Error( 'atora_ai_not_task', __( 'La sugerencia solo está disponible para entregas de tareas.', 'atora-lms' ), array( 'status' => 422 ) );
		}
		$allowed = ATORA_AI_Usage_Service::check( $teacher_id, ATORA_AI_Usage_Service::SUGGESTION );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		global $wpdb;
		$job_id = wp_generate_uuid4();
		$ok     = $wpdb->insert( self::jobs_table(), array( // phpcs:ignore WordPress.DB
			'id'            => $job_id,
			'submission_id' => $submission_id,
			'user_id'       => $teacher_id,
			'status'        => 'pending',
			'created_at'    => current_time( 'mysql', true ),
			'updated_at'    => current_time( 'mysql', true ),
		) );
		if ( ! $ok ) {
			return new WP_Error( 'atora_db_error', __( 'No se pudo crear la sugerencia. Intenta de nuevo.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::RUN_HOOK, array( $job_id ), 'atora-ai' );
		} else {
			wp_schedule_single_event( time(), self::RUN_HOOK, array( $job_id ) );
		}
		return array( 'job_id' => $job_id, 'status' => 'pending' );
	}

	public static function job( string $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' WHERE id = %s', $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$row['result'] = $row['result'] ? json_decode( (string) $row['result'], true ) : null;
		return $row;
	}

	private static function finish( string $job_id, string $status, ?array $result, string $error = '' ): void {
		global $wpdb;
		$wpdb->update( self::jobs_table(), array( // phpcs:ignore WordPress.DB
			'status'     => $status,
			'result'     => null === $result ? null : wp_json_encode( $result ),
			'error'      => substr( $error, 0, 250 ),
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => $job_id ) );
	}

	/** Resuelve un trabajo (lo llama la cola). */
	public static function run( string $job_id ): void {
		$job = self::job( $job_id );
		if ( ! $job || 'pending' !== $job['status'] ) {
			return;
		}
		$submission_id = (int) $job['submission_id'];
		$teacher_id    = (int) $job['user_id'];
		$criteria      = self::criteria( $submission_id );
		$manager       = function_exists( 'clms_core' ) ? clms_core( 'CLMS_AI_Manager' ) : null;
		if ( ! $manager || ! method_exists( $manager, 'chat_with_meta' ) ) {
			self::finish( $job_id, 'failed', null, 'El servicio de IA no está disponible.' );
			return;
		}
		$result = $manager->chat_with_meta(
			array( array( 'role' => 'user', 'content' => self::prompt( $submission_id, $criteria ) ) ),
			array(
				'max_tokens'    => 900,
				'temperature'   => 0.2,
				'timeout'       => 60,
				'source'        => 'grading_suggestion',
				'feature'       => ATORA_AI_Usage_Service::SUGGESTION,
				'user_id'       => $teacher_id,
				// Si el estudiante escribió su nombre en la entrega, tampoco sale.
				'subject_users' => array( absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			$limited = in_array( $result->get_error_code(), array( 'atora_ai_limit', 'atora_ai_budget' ), true );
			self::finish( $job_id, 'failed', null, $limited ? $result->get_error_message() : __( 'La IA no pudo generar la sugerencia. Intenta más tarde.', 'atora-lms' ) );
			return;
		}
		$parsed = self::parse( (string) ( $result['text'] ?? '' ), $criteria );
		if ( is_wp_error( $parsed ) ) {
			self::finish( $job_id, 'failed', null, $parsed->get_error_message() );
			return;
		}
		$suggestion = $parsed + array(
			'job_id'       => $job_id,
			'provider'     => (string) ( $result['provider'] ?? '' ),
			'model'        => (string) ( $result['model'] ?? '' ),
			'requested_by' => $teacher_id,
			'created_at'   => gmdate( 'c' ),
			'disclaimer'   => self::DISCLAIMER,
		);
		// Sugerencia separada de la calificación: no cambia nota, estado ni avisos.
		update_post_meta( $submission_id, self::META, $suggestion );
		self::finish( $job_id, 'done', $suggestion );
	}

	/** Criterios de la rúbrica de la entrega (los mismos que ve el docente al calificar). */
	public static function criteria( int $submission_id ): array {
		$lesson    = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$rubric_id = $lesson && class_exists( '\\ATORA\\LMS\\Rubric_Service' ) ? (int) \ATORA\LMS\Rubric_Service::get_rubric_id_for_lesson( $lesson ) : 0;
		$snapshot  = array();
		if ( $rubric_id && class_exists( '\\ATORA\\LMS\\Rubric_Service' ) ) {
			$eval = \ATORA\LMS\Rubric_Service::get_evaluation( $submission_id );
			if ( is_array( $eval ) ) {
				$decoded  = json_decode( (string) ( $eval['snapshot_json'] ?? '' ), true );
				$snapshot = is_array( $decoded['rubric'] ?? null ) ? $decoded['rubric'] : array();
			}
		}
		$out = array();
		foreach ( ATORA_Mobile_Teacher_Controller::criteria( $rubric_id, $snapshot ) as $i => $criterion ) {
			$out[] = array(
				'index'       => (int) $i,
				'name'        => sanitize_text_field( (string) ( $criterion['name'] ?? $criterion['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $criterion['description'] ?? '' ) ),
				'max_points'  => absint( $criterion['max_points'] ?? 0 ),
				'levels'      => array_values( array_map( static fn( $lv ) => array( 'label' => sanitize_text_field( (string) ( $lv['label'] ?? '' ) ), 'points' => (float) ( $lv['points'] ?? 0 ) ), (array) ( $criterion['levels'] ?? array() ) ) ),
			);
		}
		return $out;
	}

	/** Texto de la entrega: el último intento y los adjuntos de texto. Nada que identifique al estudiante. */
	public static function submission_text( int $submission_id ): string {
		$attempts = class_exists( 'ATORA_Web_Submission_History' ) ? ATORA_Web_Submission_History::attempts_for_post( $submission_id ) : array();
		$last     = $attempts ? end( $attempts ) : null;
		$text     = $last ? (string) $last['body_text'] : (string) get_post_meta( $submission_id, '_clms_submission_comment', true );
		foreach ( (array) get_post_meta( $submission_id, '_clms_submission_files', true ) as $att_id ) {
			$path = get_attached_file( absint( $att_id ) );
			if ( $path && is_readable( $path ) && 0 === strpos( (string) get_post_mime_type( absint( $att_id ) ), 'text/' ) ) {
				$text .= "\n\n" . (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return trim( wp_strip_all_tags( $text ) );
	}

	public static function prompt( int $submission_id, array $criteria ): string {
		$lesson   = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$title    = (string) get_the_title( $lesson );
		$consigna = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $lesson ) ) );
		$rubric   = array();
		foreach ( $criteria as $c ) {
			$levels   = implode( '; ', array_map( static fn( $lv ) => $lv['label'] . ' = ' . $lv['points'], $c['levels'] ) );
			$rubric[] = sprintf( '- [%d] %s (0 a %d puntos)%s%s', $c['index'], $c['name'], $c['max_points'], $c['description'] ? ': ' . $c['description'] : '', $levels ? ". Niveles: {$levels}" : '' );
		}
		$rubric_text = implode( "\n", $rubric );
		$text        = self::submission_text( $submission_id );
		return <<<PROMPT
Eres un evaluador académico. Sugiere una calificación para la entrega de un estudiante según la rúbrica. El docente revisará y decidirá; tú solo sugieres.

Tarea: {$title}
Consigna:
{$consigna}

RÚBRICA (puntaje decimal permitido, dentro del rango de cada criterio):
{$rubric_text}

ENTREGA DEL ESTUDIANTE:
{$text}

Responde SOLO con JSON con este formato exacto:
{"criteria":[{"index":<número del criterio>,"score":<número>,"justification":"<una o dos frases>"}],"feedback":"<devolución general constructiva en 3-5 frases, en español>","ai_likelihood":"bajo|medio|alto","ai_likelihood_note":"<una línea: por qué sospechas o no que el texto lo generó una IA>"}
PROMPT;
	}

	/** Interpreta la respuesta: puntajes recortados a su rango y nivel con la regla de la web. @return array|WP_Error */
	public static function parse( string $raw, array $criteria ) {
		$raw = trim( $raw );
		if ( preg_match( '/```(?:json)?([\s\S]*?)```/i', $raw, $m ) ) {
			$raw = trim( $m[1] );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) && preg_match( '/\{[\s\S]+\}/', $raw, $m ) ) {
			$data = json_decode( $m[0], true );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'atora_ai_parse', __( 'La IA devolvió un formato inesperado.', 'atora-lms' ) );
		}
		$by_index = array();
		foreach ( (array) ( $data['criteria'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['index'] ) ) {
				$by_index[ (int) $row['index'] ] = $row;
			}
		}
		$out = array();
		foreach ( $criteria as $c ) {
			$row   = $by_index[ $c['index'] ] ?? null;
			$score = $row && is_numeric( $row['score'] ?? null ) ? round( max( 0.0, min( (float) $c['max_points'], (float) $row['score'] ) ), 2 ) : null;
			$out[] = array(
				'index'         => $c['index'],
				'name'          => $c['name'],
				'max_points'    => $c['max_points'],
				'score'         => $score,
				'level'         => null !== $score && class_exists( 'CLMS_Rubric_Level_Bands' ) ? CLMS_Rubric_Level_Bands::level_for( $c['levels'], $c['max_points'], $score ) : null,
				'justification' => sanitize_textarea_field( (string) ( $row['justification'] ?? '' ) ),
			);
		}
		$likelihood = strtolower( sanitize_text_field( (string) ( $data['ai_likelihood'] ?? '' ) ) );
		$likelihood = array( 'low' => 'bajo', 'medium' => 'medio', 'high' => 'alto' )[ $likelihood ] ?? $likelihood;
		return array(
			'criteria'           => $out,
			'feedback'           => sanitize_textarea_field( (string) ( $data['feedback'] ?? '' ) ),
			'ai_likelihood'      => in_array( $likelihood, self::LIKELIHOOD, true ) ? $likelihood : 'bajo',
			'ai_likelihood_note' => sanitize_text_field( (string) ( $data['ai_likelihood_note'] ?? '' ) ),
		);
	}

	public static function suggestion( int $submission_id ): ?array {
		$saved = get_post_meta( $submission_id, self::META, true );
		return is_array( $saved ) ? $saved : null;
	}

	/**
	 * Para la auditoría al guardar: si había sugerencia y cuánto difiere lo guardado.
	 *
	 * @param array $rubric_scores Puntajes guardados [index => {score}].
	 */
	public static function audit( int $submission_id, array $rubric_scores ): array {
		$suggestion = self::suggestion( $submission_id );
		if ( ! $suggestion ) {
			return array( 'present' => false );
		}
		$diff = array();
		foreach ( (array) $suggestion['criteria'] as $row ) {
			$saved     = $rubric_scores[ $row['index'] ]['score'] ?? '';
			$suggested = $row['score'];
			$diff[]    = array(
				'index'     => (int) $row['index'],
				'suggested' => $suggested,
				'saved'     => '' === (string) $saved ? null : (float) $saved,
				'diff'      => null !== $suggested && '' !== (string) $saved ? round( (float) $saved - (float) $suggested, 2 ) : null,
			);
		}
		return array( 'present' => true, 'job_id' => (string) ( $suggestion['job_id'] ?? '' ), 'model' => (string) ( $suggestion['model'] ?? '' ), 'criteria' => $diff );
	}
}
