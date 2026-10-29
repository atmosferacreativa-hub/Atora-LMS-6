<?php
/**
 * Nota final ajustada (6.41.0).
 *
 * Control académico puede corregir la **nota final** de un estudiante en un curso (nunca las parciales: entregas,
 * rúbricas, intentos), con motivo obligatorio. Cada corrección es una fila nueva: el historial guarda la nota anterior
 * (calculada o ajustada) y quién la cambió. La nota ajustada vigente reemplaza a la calculada en el resumen del
 * curso, que es lo que leen el estudiante, el boletín, los certificados y los informes. Quitar el ajuste vuelve a la
 * calculada.
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Final_Grade_Service {

	const CAP = 'atora_adjust_final_grades';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_final_grade_adjustments';
	}

	/** ¿Puede corregir notas finales de este curso? (capacidad y misma institución). */
	public static function can_adjust( int $user_id, int $wp_course_id ): bool {
		return $user_id > 0 && $wp_course_id > 0
			&& user_can( $user_id, self::CAP )
			&& 'lm_course' === get_post_type( $wp_course_id )
			&& ( ! class_exists( 'ATORA_Teacher_Scope' ) || ATORA_Teacher_Scope::in_tenant( $user_id, $wp_course_id ) );
	}

	/** Ajuste vigente (o null). */
	public static function current( int $user_id, int $wp_course_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wp_course_id = %d AND user_id = %d AND active = 1 ORDER BY id DESC LIMIT 1', $wp_course_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row || null === $row['grade'] ) {
			return null;
		}
		return self::shape( $row );
	}

	/** Historial, del más nuevo al más viejo. */
	public static function history( int $user_id, int $wp_course_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wp_course_id = %d AND user_id = %d ORDER BY id DESC LIMIT 100', $wp_course_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'shape' ), $rows );
	}

	/**
	 * Corregir (grade 0–100) o quitar el ajuste (grade null).
	 *
	 * @param float|null $grade
	 * @return array|WP_Error El ajuste vigente después del cambio (o null al quitarlo) y el historial.
	 */
	public static function adjust( int $actor_id, int $user_id, int $wp_course_id, $grade, string $reason ) {
		if ( ! self::can_adjust( $actor_id, $wp_course_id ) ) {
			return new WP_Error( 'atora_final_grade_forbidden', __( 'No tienes permiso para corregir notas finales en este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( ! self::enrolled( $user_id, $wp_course_id ) ) {
			return new WP_Error( 'atora_final_grade_student', __( 'El estudiante no está matriculado en el curso.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$reason = sanitize_textarea_field( $reason );
		if ( mb_strlen( trim( $reason ) ) < 5 ) {
			return new WP_Error( 'atora_final_grade_reason', __( 'Escribe el motivo de la corrección.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( null !== $grade && ( ! is_numeric( $grade ) || (float) $grade < 0 || (float) $grade > 100 ) ) {
			return new WP_Error( 'atora_final_grade_value', __( 'La nota final va de 0 a 100.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$current = self::current( $user_id, $wp_course_id );
		if ( null === $grade && ! $current ) {
			return new WP_Error( 'atora_final_grade_none', __( 'No hay una corrección vigente que quitar.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		$calculated = self::calculated( $user_id, $wp_course_id );
		$previous   = $current ? $current['grade'] : $calculated;

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET active = 0 WHERE wp_course_id = %d AND user_id = %d AND active = 1', $wp_course_id, $user_id ) ); // phpcs:ignore WordPress.DB
		$ok = $wpdb->insert( self::table(), array(
			'institution_id' => class_exists( 'ATORA_Teacher_Scope' ) ? ATORA_Teacher_Scope::course_institution( $wp_course_id ) : 0,
			'wp_course_id'   => $wp_course_id,
			'user_id'        => $user_id,
			'grade'          => null === $grade ? null : round( (float) $grade, 2 ),
			'previous_grade' => $previous,
			'calculated'     => $calculated,
			'reason'         => mb_substr( $reason, 0, 500 ),
			'actor_id'       => $actor_id,
			'active'         => null === $grade ? 0 : 1,
		) );
		if ( ! $ok ) {
			return new WP_Error( 'atora_db_error', __( 'No se pudo guardar la corrección. Intenta de nuevo.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		self::flush( $user_id, $wp_course_id );
		do_action( 'atora/final_grade/adjusted', $user_id, $wp_course_id, $grade, $previous, $actor_id );
		return array(
			'current'    => self::current( $user_id, $wp_course_id ),
			'calculated' => $calculated,
			'history'    => self::history( $user_id, $wp_course_id ),
		);
	}

	/**
	 * Aplica el ajuste vigente al resumen del curso (lo llaman el motor y el cálculo de respaldo).
	 *
	 * @param array $summary Resumen con `final_average`.
	 */
	public static function apply( array $summary, int $user_id, int $wp_course_id ): array {
		if ( ! self::table_exists() ) {
			return $summary;
		}
		$adjusted = self::current( $user_id, $wp_course_id );
		if ( ! $adjusted ) {
			return $summary;
		}
		$summary['final_calculated'] = $summary['final_average'] ?? null;
		$summary['final_average']    = (int) round( (float) $adjusted['grade'] );
		$summary['final_adjusted']   = $adjusted;
		return $summary;
	}

	/** Nota final calculada (sin el ajuste). */
	public static function calculated( int $user_id, int $wp_course_id ): ?float {
		$engine = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Assessment_Engine' ) : null;
		if ( ! $engine || ! method_exists( $engine, 'build_course_gradebook' ) ) {
			return null;
		}
		self::$skip = true;
		try {
			$book = $engine->build_course_gradebook( $user_id, $wp_course_id );
		} finally {
			self::$skip = false;
		}
		$value = $book['summary']['final_average'] ?? null;
		return is_numeric( $value ) ? (float) $value : null;
	}

	/** Mientras se calcula la nota sin ajuste, `apply()` no actúa. */
	public static bool $skip = false;

	private static function enrolled( int $user_id, int $wp_course_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$ids = class_exists( 'CLMS_Helper' ) ? array_map( 'absint', (array) CLMS_Helper::get_enrolled_student_ids( $wp_course_id ) ) : array();
		return in_array( $user_id, $ids, true );
	}

	private static function flush( int $user_id, int $wp_course_id ): void {
		foreach ( array( 'CLMS_Assessment_Engine', 'CLMS_Grading' ) as $service ) {
			$object = function_exists( 'clms_core' ) ? clms_core( $service ) : null;
			if ( $object && method_exists( $object, 'invalidate_cache_for_user_course' ) ) {
				$object->invalidate_cache_for_user_course( $user_id, $wp_course_id );
			}
		}
	}

	private static function table_exists(): bool {
		static $exists = null;
		if ( null === $exists ) {
			global $wpdb;
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table();
		}
		return $exists;
	}

	private static function shape( array $row ): array {
		$actor = get_userdata( (int) $row['actor_id'] );
		return array(
			'grade'          => null === $row['grade'] ? null : (float) $row['grade'],
			'previous_grade' => null === $row['previous_grade'] ? null : (float) $row['previous_grade'],
			'calculated'     => null === $row['calculated'] ? null : (float) $row['calculated'],
			'reason'         => (string) $row['reason'],
			'by'             => $actor ? (string) $actor->display_name : '',
			'at'             => (string) $row['created_at'],
			'removed'        => null === $row['grade'],
		);
	}
}
