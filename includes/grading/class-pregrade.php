<?php
/**
 * Pre-calificación del instructor asistente (6.41.0).
 *
 * El asistente que llega al curso por delegación califica como el instructor, pero lo que «publica» queda como
 * borrador marcado: el estudiante no lo ve hasta que el instructor (o quien no es solo asistente) lo valida
 * publicándolo. El instructor también puede devolverlo al asistente con un comentario. Cada paso queda en la
 * auditoría de la entrega (quién propuso, quién validó o devolvió).
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Pregrade {

	const BY       = '_atora_pregrade_by';
	const AT       = '_atora_pregrade_at';
	const ACTION   = '_atora_pregrade_action';
	const RETURNED = '_atora_pregrade_returned';

	/** Acciones que publicarían algo para el estudiante. */
	const FINAL_ACTIONS = array( 'publish', 'approve_evidence', 'return_revision' );

	/** ¿Lo que guarda este usuario en este curso (post) queda como pre-calificación? */
	public static function applies( int $user_id, int $wp_course_id ): bool {
		return $user_id > 0 && $wp_course_id > 0
			&& class_exists( 'ATORA_Hybrid_Plan_Service' )
			&& ATORA_Hybrid_Plan_Service::is_assistant_only( $user_id, $wp_course_id );
	}

	public static function is_final_action( string $action ): bool {
		return in_array( $action, self::FINAL_ACTIONS, true );
	}

	public static function mark( int $submission_id, int $assistant_id, string $action ): void {
		update_post_meta( $submission_id, self::BY, $assistant_id );
		update_post_meta( $submission_id, self::AT, gmdate( 'c' ) );
		update_post_meta( $submission_id, self::ACTION, sanitize_key( $action ) );
		delete_post_meta( $submission_id, self::RETURNED );
		self::audit( $submission_id, $assistant_id, 'pregrade_proposed', '' );
	}

	/** El instructor publicó: la pre-calificación queda validada. */
	public static function resolve( int $submission_id, int $validator_id ): void {
		$by = absint( get_post_meta( $submission_id, self::BY, true ) );
		if ( ! $by ) {
			return;
		}
		self::clear( $submission_id );
		/* translators: %s: nombre del asistente */
		self::audit( $submission_id, $validator_id, 'pregrade_validated', sprintf( __( 'Propuesta de %s', 'atora-lms' ), self::name( $by ) ) );
	}

	/**
	 * Devolver al asistente con un comentario (no publica nada).
	 *
	 * @return true|WP_Error
	 */
	public static function return_to_assistant( int $submission_id, int $instructor_id, string $comment ) {
		$by = absint( get_post_meta( $submission_id, self::BY, true ) );
		if ( ! $by ) {
			return new WP_Error( 'atora_pregrade_none', __( 'Esta entrega no tiene una pre-calificación por validar.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		$comment = sanitize_textarea_field( $comment );
		if ( '' === trim( $comment ) ) {
			return new WP_Error( 'atora_pregrade_comment', __( 'Escribe qué debe revisar el asistente.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		self::clear( $submission_id );
		update_post_meta( $submission_id, self::RETURNED, array( 'by' => $instructor_id, 'at' => gmdate( 'c' ), 'comment' => $comment, 'assistant' => $by ) );
		self::audit( $submission_id, $instructor_id, 'pregrade_returned', $comment );
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( $notifications && method_exists( $notifications, 'add_notification' ) ) {
			$notifications->add_notification( $by, array(
				'type'       => 'grading_feedback',
				/* translators: %s: instructor */
				'title'      => sprintf( __( '%s devolvió tu pre-calificación', 'atora-lms' ), self::name( $instructor_id ) ),
				'message'    => $comment,
				'link'       => '',
				'dedupe_key' => 'pregrade_returned_' . $submission_id . '_' . time(),
			) );
		}
		return true;
	}

	/** Datos para la API y SpeedGrader. */
	public static function info( int $submission_id ): ?array {
		$by       = absint( get_post_meta( $submission_id, self::BY, true ) );
		$returned = get_post_meta( $submission_id, self::RETURNED, true );
		if ( ! $by && ! is_array( $returned ) ) {
			return null;
		}
		return array(
			'pending'  => $by > 0,
			'by'       => $by ? array( 'id' => $by, 'name' => self::name( $by ) ) : null,
			'at'       => $by ? (string) get_post_meta( $submission_id, self::AT, true ) : null,
			'action'   => $by ? (string) get_post_meta( $submission_id, self::ACTION, true ) : null,
			'returned' => is_array( $returned ) ? array( 'by' => self::name( absint( $returned['by'] ?? 0 ) ), 'at' => (string) ( $returned['at'] ?? '' ), 'comment' => (string) ( $returned['comment'] ?? '' ) ) : null,
		);
	}

	private static function clear( int $submission_id ): void {
		delete_post_meta( $submission_id, self::BY );
		delete_post_meta( $submission_id, self::AT );
		delete_post_meta( $submission_id, self::ACTION );
	}

	private static function name( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : '';
	}

	private static function audit( int $submission_id, int $actor_id, string $action, string $motive ): void {
		if ( class_exists( 'CLMS_Gradebook_Audit_Service' ) ) {
			CLMS_Gradebook_Audit_Service::log( $submission_id, array(
				'actor_id' => $actor_id,
				'action'   => $action,
				'source'   => 'manual',
				'motive'   => $motive,
			) );
		}
	}
}
