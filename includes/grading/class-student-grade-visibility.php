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

	/**
	 * 6.30.1: hook de un guardado que el estudiante todavía no ve (borrador, en
	 * revisión, enviado a moderación). Mismos argumentos que `clms_submission_graded`:
	 * (submission_id, student_id, status, grade, feedback).
	 */
	const DRAFT_HOOK = 'clms_submission_grade_draft_saved';

	/** Estados de un guardado sin publicar: no avisan ni cuentan para el estudiante. */
	public static function is_draft_status( $status ): bool {
		return in_array( sanitize_key( (string) $status ), array( 'submitted', 'in_review', 'pending' ), true );
	}

	/**
	 * Hook que corresponde a un guardado de calificación: `clms_submission_graded`
	 * solo cuando el estudiante lo ve (publicada o devuelta para corregir).
	 */
	public static function grade_saved_hook( $status ): string {
		return self::is_draft_status( $status ) ? self::DRAFT_HOOK : 'clms_submission_graded';
	}

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
