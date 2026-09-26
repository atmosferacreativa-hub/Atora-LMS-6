<?php
/**
 * Sync de calificaciones: CPT clms_submission → tabla atora_quiz_submissions.
 *
 * Objetivo:
 * - Solo actualiza la fila vinculada por wp_post_id (= submission_id).
 * - Distingue nota 0 de nota vacía ('' → NULL; 0 → 0.00).
 * - No crea filas nuevas (no afecta entregas legacy que no viven en la tabla).
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Table_Quiz_Submission_Sync {
	/**
	 * Registra hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'clms_submission_graded', array( __CLASS__, 'on_submission_graded' ), 15, 5 );
	}

	/**
	 * Hook clms_submission_graded.
	 *
	 * Firma esperada:
	 * (submission_id, user_id, status, grade, feedback)
	 *
	 * @param int    $submission_id ID del CPT clms_submission.
	 * @param int    $student_id    Usuario propietario (no se usa para el update).
	 * @param string $status        Estado académico.
	 * @param mixed  $grade         Nota (puede ser '', '0', 0, 85...).
	 * @param string $feedback      Feedback (no se sincroniza por seguridad).
	 */
	public static function on_submission_graded( $submission_id, $student_id, $status = '', $grade = '', $feedback = '' ): void {
		unset( $student_id, $feedback );
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return;
		}

		// Defensa: este hook debería correr solo para submissions.
		if ( function_exists( 'get_post_type' ) && 'clms_submission' !== (string) get_post_type( $submission_id ) ) {
			return;
		}

		$ok = self::sync( $submission_id, (string) $status, $grade );
		if ( ! $ok ) {
			do_action( 'atora/lms/table_quiz_submission_sync_failed', $submission_id );
		}
	}

	/**
	 * Sincroniza la nota a la tabla atora_quiz_submissions.
	 *
	 * @param int    $submission_id Submission WP post ID.
	 * @param string $status        Estado.
	 * @param mixed  $grade         Nota.
	 * @return bool True si el sync fue exitoso o no-aplicable.
	 */
	public static function sync( int $submission_id, string $status, $grade ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return true;
		}

		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return true;
		}

		$table = $wpdb->prefix . 'atora_quiz_submissions';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists !== $table ) {
			return true;
		}

		$row_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE wp_post_id = %d LIMIT 1", $submission_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! $row_id ) {
			// No hay fila para este submission → probablemente entrega legacy.
			return true;
		}

		$status = sanitize_key( $status );
		$grade_is_empty = '' === (string) ( $grade ?? '' );
		if ( '' === $status && ! $grade_is_empty ) {
			$status = 'graded';
		}

		if ( $grade_is_empty ) {
			// Importante: no castear '' a 0. Si el docente borró la nota,
			// persistir NULL en la tabla.
			$sql = '' !== $status
				? $wpdb->prepare( "UPDATE {$table} SET grade = NULL, status = %s WHERE wp_post_id = %d", $status, $submission_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->prepare( "UPDATE {$table} SET grade = NULL WHERE wp_post_id = %d", $submission_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false === $result ) {
				self::log_db_failure( $submission_id, $wpdb->last_error ?? '' );
				return false;
			}
			return true;
		}

		$data   = array(
			'grade' => (float) $grade,
		);
		$format = array( '%f' );

		if ( '' !== $status ) {
			$data['status'] = $status;
			$format[] = '%s';
		}
		if ( 'graded' === $status ) {
			$data['graded_at'] = current_time( 'mysql' );
			$format[] = '%s';
		}

		$result = $wpdb->update(
			$table,
			$data,
			array( 'wp_post_id' => $submission_id ),
			$format,
			array( '%d' )
		);
		if ( false === $result ) {
			self::log_db_failure( $submission_id, $wpdb->last_error ?? '' );
			return false;
		}

		return true;
	}

	private static function log_db_failure( int $submission_id, string $last_error ): void {
		if ( defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE ) {
			error_log( sprintf( '[ATORA LMS] atora_quiz_submissions sync failed for submission_id=%d: %s', $submission_id, $last_error ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[ATORA LMS] atora_quiz_submissions sync failed for submission_id=%d: %s', $submission_id, $last_error ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

