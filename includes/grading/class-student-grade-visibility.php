<?php
/**
 * Qué ve el estudiante de una entrega (6.29.0). Una sola regla para la web y la app:
 * o la nota está liberada y se muestra, o no se muestra en ningún lado (tampoco
 * cuenta en su promedio).
 *
 * - Nota: solo con estado `graded` (publicada en SpeedGrader o aprobada en
 *   moderación). Guardar como borrador deja `in_review`: no se ve.
 * - Comentario: con la nota liberada, o al devolverse para corregir
 *   (`needs_revision`, `returned`).
 *
 * @package ATORA_LMS
 * @since 6.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Student_Grade_Visibility {

	const RELEASED_STATUS = 'graded';

	public static function grade_visible( $status, $grade ): bool {
		return self::RELEASED_STATUS === sanitize_key( (string) $status ) && '' !== (string) $grade && null !== $grade;
	}

	public static function feedback_visible( $status, $grade ): bool {
		return self::grade_visible( $status, $grade )
			|| in_array( sanitize_key( (string) $status ), array( 'needs_revision', 'returned' ), true );
	}

	/** Lo que el estudiante puede ver de un post `clms_submission`. */
	public static function for_submission_post( int $submission_id ): array {
		$status   = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
		$grade    = get_post_meta( $submission_id, '_clms_submission_grade', true );
		$feedback = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );
		return array(
			'status'   => sanitize_key( $status ),
			'grade'    => self::grade_visible( $status, $grade ) ? $grade : null,
			'feedback' => self::feedback_visible( $status, $grade ) ? $feedback : null,
		);
	}
}
