<?php
/**
 * Servicio de auditoría del Gradebook.
 * Registra quién cambió qué, cuándo, por qué y desde qué fuente.
 * No guarda datos sensibles innecesarios.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Audit_Service {

	const META_KEY   = '_clms_gradebook_audit_log';
	const MAX_EVENTS = 50;

	/**
	 * Registra un evento de cambio de nota o estado en el Gradebook.
	 *
	 * @param int   $submission_id  ID de la entrega.
	 * @param array $event          Datos del evento.
	 *        Keys esperadas (todas opcionales):
	 *          actor_id    int    → ID del usuario que actúa.
	 *          action      string → Tipo de acción (gradebook_edit, speedgrade_publish, ai_grade, etc.)
	 *          source      string → Fuente (manual, speedgrade, rubric, ai, system...)
	 *          grade_prev  mixed  → Nota anterior.
	 *          grade_new   mixed  → Nota nueva.
	 *          status_prev string → Estado anterior.
	 *          status_new  string → Estado nuevo.
	 *          motive      string → Motivo del cambio (opcional, libre).
	 *          override    bool   → Si fue un override.
	 * @return void
	 */
	public static function log( int $submission_id, array $event ): void {
		if ( ! $submission_id || 'clms_submission' !== get_post_type( $submission_id ) ) {
			return;
		}

		$actor_id = absint( $event['actor_id'] ?? get_current_user_id() );

		$entry = array(
			'recorded_at' => current_time( 'mysql' ),
			'actor_id'    => $actor_id,
			'action'      => sanitize_key( (string) ( $event['action'] ?? 'grade_change' ) ),
			'source'      => class_exists( 'CLMS_Gradebook_Bridge_Service' )
				? CLMS_Gradebook_Bridge_Service::normalize_source( (string) ( $event['source'] ?? 'manual' ) )
				: sanitize_key( (string) ( $event['source'] ?? 'manual' ) ),
			'grade_prev'  => '' !== (string) ( $event['grade_prev'] ?? '' ) ? absint( $event['grade_prev'] ) : '',
			'grade_new'   => '' !== (string) ( $event['grade_new'] ?? '' ) ? absint( $event['grade_new'] ) : '',
			'status_prev' => class_exists( 'CLMS_Academic_Status_Map' )
				? CLMS_Academic_Status_Map::normalize( (string) ( $event['status_prev'] ?? '' ) )
				: sanitize_key( (string) ( $event['status_prev'] ?? '' ) ),
			'status_new'  => class_exists( 'CLMS_Academic_Status_Map' )
				? CLMS_Academic_Status_Map::normalize( (string) ( $event['status_new'] ?? '' ) )
				: sanitize_key( (string) ( $event['status_new'] ?? '' ) ),
			'motive'      => sanitize_text_field( (string) ( $event['motive'] ?? '' ) ),
			'override'    => ! empty( $event['override'] ),
		);

		$log   = (array) get_post_meta( $submission_id, self::META_KEY, true );
		$log[] = $entry;

		if ( count( $log ) > self::MAX_EVENTS ) {
			$log = array_slice( $log, -self::MAX_EVENTS );
		}

		update_post_meta( $submission_id, self::META_KEY, $log );

		do_action( 'clms_gradebook_audit_logged', $submission_id, $entry );
	}

	/**
	 * Obtiene el log de auditoría de una entrega.
	 *
	 * @param int $submission_id ID de la entrega.
	 * @param int $limit         Máximo de eventos a devolver (0 = todos).
	 * @return array
	 */
	public static function get_log( int $submission_id, int $limit = 0 ): array {
		if ( ! $submission_id ) {
			return array();
		}

		$log = get_post_meta( $submission_id, self::META_KEY, true );
		$log = is_array( $log ) ? array_values( $log ) : array();

		if ( $limit > 0 ) {
			$log = array_slice( $log, -$limit );
		}

		return array_reverse( $log ); // Más reciente primero.
	}

	/**
	 * Hook en clms_submission_graded: registra el evento de calificación automáticamente.
	 *
	 * @param int    $submission_id ID de la entrega.
	 * @param int    $student_id    ID del estudiante.
	 * @param string $status        Estado nuevo.
	 * @param mixed  $grade         Nota nueva.
	 * @param string $feedback      Feedback.
	 * @return void
	 */
	public static function on_submission_graded( $submission_id, $student_id, $status = '', $grade = '', $feedback = '' ): void {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return;
		}

		$source = (string) get_post_meta( $submission_id, '_clms_submission_grade_source', true );
		if ( '' === $source ) {
			$source = (string) get_post_meta( $submission_id, '_clms_grade_source', true );
		}

		self::log(
			$submission_id,
			array(
				'action'     => 'grade_published',
				'source'     => $source ?: 'system',
				'grade_new'  => $grade,
				'status_new' => $status,
				'override'   => (bool) get_post_meta( $submission_id, '_clms_grade_manual_override', true ),
			)
		);
	}

	/**
	 * Hook en clms_gradebook_batch_update_complete: registra ediciones manuales del Gradebook.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $results   Resultados del lote.
	 * @param int   $actor_id  ID del actor.
	 * @return void
	 */
	public static function on_gradebook_batch_complete( $course_id, $results, $actor_id ): void {
		$results  = is_array( $results ) ? $results : array();
		$actor_id = absint( $actor_id );

		foreach ( $results as $result ) {
			$result        = is_array( $result ) ? $result : array();
			$submission_id = absint( $result['submission_id'] ?? 0 );
			if ( ! $submission_id || empty( $result['success'] ) ) {
				continue;
			}

			self::log(
				$submission_id,
				array(
					'actor_id'   => $actor_id,
					'action'     => 'gradebook_batch_edit',
					'source'     => 'manual',
					'grade_new'  => $result['grade'] ?? '',
					'status_new' => (string) get_post_meta( $submission_id, '_clms_submission_status', true ),
					'override'   => true,
				)
			);
		}
	}

	/**
	 * Registra hooks de auditoría.
	 */
	public static function register_hooks(): void {
		add_action( 'clms_submission_graded', array( __CLASS__, 'on_submission_graded' ), 99, 5 );
		add_action( 'clms_gradebook_batch_update_complete', array( __CLASS__, 'on_gradebook_batch_complete' ), 20, 3 );
	}
}
