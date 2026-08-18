<?php
/**
 * LMS Write Facade — F2 (Doble escritura simétrica)
 *
 * Punto de entrada único para todas las escrituras de matrícula, progreso,
 * calificación, certificado y caducidad. Estrategia D-005 = B:
 *
 *   1. Escribe en las tablas `atora_*` (autoritativas).
 *   2. Si `atora_lms_dualwrite = true`, refleja en usermeta legacy con la
 *      guarda estática `$syncing` levantada para evitar bucles.
 *
 * La dirección legacy → tablas sigue siendo responsabilidad de
 * `ATORA_LMS_Compatibility_Layer` (que ahora también usa esta guarda).
 *
 * @package ATORA_LMS\LMS
 * @since   6.0.7
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LMS_Write_Facade {

	/**
	 * Guarda de reentrada. Cuando está en `true`, los listeners del compat
	 * layer NO deben procesar el evento para evitar el bucle:
	 *   legacy → acción → sync tabla → mirror legacy → acción → …
	 */
	public static bool $syncing = false;

	private const FLAG = 'atora_lms_dualwrite';

	// ── Flag ──────────────────────────────────────────────────────────────────

	public static function dualwrite_enabled(): bool {
		return (bool) get_option( self::FLAG, false );
	}

	// ── Cargar dependencias ───────────────────────────────────────────────────

	private static function ensure_loaded(): bool {
		if ( class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) { return true; }
		$dir = __DIR__;
		foreach ( array( 'class-lms-course-service.php', 'class-lms-enrollment-service.php' ) as $file ) {
			$path = $dir . '/' . $file;
			if ( file_exists( $path ) ) { require_once $path; }
		}
		return class_exists( '\ATORA\LMS\LMS_Enrollment_Service' );
	}

	// ── Helpers: lookup wp_post_id ────────────────────────────────────────────

	private static function wp_course_id( int $atora_course_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_courses WHERE id = %d LIMIT 1",
			$atora_course_id
		) );
	}

	private static function wp_lesson_id( int $atora_lesson_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_lessons WHERE id = %d LIMIT 1",
			$atora_lesson_id
		) );
	}

	private static function wp_program_id( int $atora_program_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_programs WHERE id = %d LIMIT 1",
			$atora_program_id
		) );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// Escrituras de tabla (entry points nuevos: REST, MCP, etc.)
	// Cada uno escribe en tabla + espeja en legacy si el flag está activo.
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Matricula a un usuario en un curso (tabla + espejo legacy si dualwrite ON).
	 */
	public static function enroll( int $user_id, int $atora_course_id ): int {
		if ( ! self::ensure_loaded() ) { return 0; }

		self::$syncing = true;
		$enroll_id = LMS_Enrollment_Service::enroll( $user_id, $atora_course_id );
		self::$syncing = false;

		if ( $enroll_id && self::dualwrite_enabled() ) {
			$wp_id = self::wp_course_id( $atora_course_id );
			if ( $wp_id ) { self::mirror_enroll_to_legacy( $user_id, $wp_id ); }
		}
		return $enroll_id;
	}

	/**
	 * Desmatricula (marca unenrolled en tabla + espejo legacy).
	 */
	public static function unenroll( int $user_id, int $atora_course_id ): bool {
		global $wpdb;

		self::$syncing = true;
		$ok = (bool) $wpdb->update(
			$wpdb->prefix . 'atora_enrollments',
			array( 'status' => 'unenrolled' ),
			array( 'user_id' => $user_id, 'course_id' => $atora_course_id ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		self::$syncing = false;

		if ( $ok && self::dualwrite_enabled() ) {
			$wp_id = self::wp_course_id( $atora_course_id );
			if ( $wp_id ) { self::mirror_unenroll_from_legacy( $user_id, $wp_id ); }
		}

		do_action( 'atora/lms/unenrolled', $user_id, $atora_course_id );
		return $ok;
	}

	/**
	 * Actualiza la caducidad de acceso a un curso.
	 */
	public static function set_access_expiry( int $user_id, int $atora_course_id, ?string $expires_at_gmt ): bool {
		global $wpdb;
		$ok = (bool) $wpdb->update(
			$wpdb->prefix . 'atora_enrollments',
			array( 'expires_at' => $expires_at_gmt ),
			array( 'user_id' => $user_id, 'course_id' => $atora_course_id ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		do_action( 'atora/lms/access_expiry_set', $user_id, $atora_course_id, $expires_at_gmt );
		return $ok;
	}

	/**
	 * Marca una lección como completada (tabla + espejo legacy).
	 */
	public static function complete_lesson( int $user_id, int $atora_lesson_id ): bool {
		if ( ! self::ensure_loaded() ) { return false; }

		self::$syncing = true;
		$ok = LMS_Enrollment_Service::complete_lesson( $user_id, $atora_lesson_id );
		self::$syncing = false;

		if ( $ok && self::dualwrite_enabled() ) {
			$wp_id = self::wp_lesson_id( $atora_lesson_id );
			if ( $wp_id ) { self::mirror_lesson_complete_to_legacy( $user_id, $wp_id ); }
		}
		return $ok;
	}

	/**
	 * Registra o actualiza la calificación final de un curso.
	 */
	public static function set_grade( int $user_id, int $atora_course_id, float $grade, ?array $detail = null ): bool {
		global $wpdb;

		$wp_id    = self::wp_course_id( $atora_course_id );
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atora_gradebook WHERE user_id = %d AND course_id = %d LIMIT 1",
			$user_id, $atora_course_id
		) );

		if ( $existing ) {
			$ok = (bool) $wpdb->update(
				$wpdb->prefix . 'atora_gradebook',
				array( 'final_grade' => $grade, 'grade_json' => $detail ? wp_json_encode( $detail ) : null, 'calculated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $existing ),
				array( '%f', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$ok = (bool) $wpdb->insert(
				$wpdb->prefix . 'atora_gradebook',
				array(
					'user_id'      => $user_id,
					'course_id'    => $atora_course_id,
					'wp_course_id' => $wp_id,
					'final_grade'  => $grade,
					'grade_json'   => $detail ? wp_json_encode( $detail ) : null,
				)
			);
		}

		if ( $ok && self::dualwrite_enabled() && $wp_id ) {
			self::mirror_grade_to_legacy( $user_id, $wp_id, $grade, $detail );
		}

		do_action( 'atora/lms/grade_set', $user_id, $atora_course_id, $grade );
		return $ok;
	}

	/**
	 * Emite un certificado de curso.
	 */
	public static function issue_certificate( int $user_id, int $atora_course_id, string $cert_code, array $data = array() ): bool {
		global $wpdb;

		$wp_id    = self::wp_course_id( $atora_course_id );
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atora_certificates WHERE user_id = %d AND target_type = 'course' AND wp_target_id = %d LIMIT 1",
			$user_id, $wp_id
		) );
		if ( $existing ) { return true; }

		$issued_at = sanitize_text_field( (string) ( $data['issued_at'] ?? current_time( 'mysql', true ) ) );
		$ok = (bool) $wpdb->insert(
			$wpdb->prefix . 'atora_certificates',
			array(
				'user_id'      => $user_id,
				'course_id'    => $atora_course_id,
				'wp_target_id' => $wp_id,
				'target_type'  => 'course',
				'cert_code'    => sanitize_text_field( $cert_code ),
				'status'       => 'valid',
				'issued_at'    => $issued_at,
				'meta_json'    => ! empty( $data ) ? wp_json_encode( $data ) : null,
			)
		);

		if ( $ok && self::dualwrite_enabled() && $wp_id ) {
			self::mirror_certificate_to_legacy( $user_id, $wp_id, $cert_code, $data );
		}

		do_action( 'atora/lms/certificate_issued', $user_id, $atora_course_id, $cert_code );
		return $ok;
	}

	/**
	 * Matricula a un usuario en un programa.
	 */
	public static function enroll_program( int $user_id, int $atora_program_id ): int {
		global $wpdb;

		$wp_id    = self::wp_program_id( $atora_program_id );
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atora_program_enrollments WHERE user_id = %d AND program_id = %d LIMIT 1",
			$user_id, $atora_program_id
		) );
		if ( $existing ) { return $existing; }

		$ok = $wpdb->insert(
			$wpdb->prefix . 'atora_program_enrollments',
			array(
				'user_id'       => $user_id,
				'program_id'    => $atora_program_id,
				'wp_program_id' => $wp_id,
				'status'        => 'active',
				'enrolled_at'   => current_time( 'mysql', true ),
			)
		);
		if ( ! $ok ) { return 0; }

		$id = (int) $wpdb->insert_id;

		if ( self::dualwrite_enabled() && $wp_id ) {
			self::mirror_program_enroll_to_legacy( $user_id, $wp_id );
		}

		do_action( 'atora/lms/program_enrolled', $user_id, $atora_program_id, $id );
		return $id;
	}

	/**
	 * Desmatricula a un usuario de un programa.
	 */
	public static function unenroll_program( int $user_id, int $atora_program_id ): bool {
		global $wpdb;

		$ok = (bool) $wpdb->update(
			$wpdb->prefix . 'atora_program_enrollments',
			array( 'status' => 'unenrolled' ),
			array( 'user_id' => $user_id, 'program_id' => $atora_program_id ),
			array( '%s' ),
			array( '%d', '%d' )
		);

		if ( $ok && self::dualwrite_enabled() ) {
			$wp_id = self::wp_program_id( $atora_program_id );
			if ( $wp_id ) { self::mirror_program_unenroll_from_legacy( $user_id, $wp_id ); }
		}

		do_action( 'atora/lms/program_unenrolled', $user_id, $atora_program_id );
		return $ok;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// Mirrors: tabla → legacy (llamados desde el compat layer o desde arriba)
	// Escriben directo en usermeta/postmeta SIN disparar acciones clms_*
	// para no cerrar el bucle. Se asumen llamadas con $syncing = true.
	// ══════════════════════════════════════════════════════════════════════════

	public static function mirror_enroll_to_legacy( int $user_id, int $wp_course_id ): void {
		$enrolled = array_filter( array_map( 'absint', (array) get_user_meta( $user_id, '_clms_enrolled_courses', true ) ) );
		if ( ! in_array( $wp_course_id, $enrolled, true ) ) {
			$enrolled[] = $wp_course_id;
			update_user_meta( $user_id, '_clms_enrolled_courses', array_values( array_unique( $enrolled ) ) );
		}

		$users = array_filter( array_map( 'absint', (array) get_post_meta( $wp_course_id, '_clms_enrolled_users', true ) ) );
		if ( ! in_array( $user_id, $users, true ) ) {
			$users[] = $user_id;
			update_post_meta( $wp_course_id, '_clms_enrolled_users', array_values( array_unique( $users ) ) );
		}

		$dates = get_user_meta( $user_id, '_clms_enrollment_dates', true );
		$dates = is_array( $dates ) ? $dates : array();
		if ( empty( $dates[ $wp_course_id ] ) ) {
			$dates[ $wp_course_id ] = current_time( 'mysql' );
			update_user_meta( $user_id, '_clms_enrollment_dates', $dates );
		}
	}

	public static function mirror_unenroll_from_legacy( int $user_id, int $wp_course_id ): void {
		$enrolled = array_filter( array_map( 'absint', (array) get_user_meta( $user_id, '_clms_enrolled_courses', true ) ) );
		update_user_meta( $user_id, '_clms_enrolled_courses', array_values( array_diff( $enrolled, array( $wp_course_id ) ) ) );

		$users = array_filter( array_map( 'absint', (array) get_post_meta( $wp_course_id, '_clms_enrolled_users', true ) ) );
		update_post_meta( $wp_course_id, '_clms_enrolled_users', array_values( array_diff( $users, array( $user_id ) ) ) );
	}

	public static function mirror_lesson_complete_to_legacy( int $user_id, int $wp_lesson_id ): void {
		$completed = array_filter( array_map( 'absint', (array) get_user_meta( $user_id, '_clms_completed_lessons', true ) ) );
		if ( ! in_array( $wp_lesson_id, $completed, true ) ) {
			$completed[] = $wp_lesson_id;
			update_user_meta( $user_id, '_clms_completed_lessons', array_values( array_unique( $completed ) ) );
		}
	}

	public static function mirror_grade_to_legacy( int $user_id, int $wp_course_id, float $grade, ?array $detail = null ): void {
		$data = array_merge( is_array( $detail ) ? $detail : array(), array( 'final_average' => $grade ) );
		update_user_meta( $user_id, '_clms_gradebook_course_' . $wp_course_id, $data );
	}

	public static function mirror_certificate_to_legacy( int $user_id, int $wp_course_id, string $cert_code, array $data = array() ): void {
		$record = array_merge( $data, array(
			'certificate_code' => $cert_code,
			'issued_at'        => $data['issued_at'] ?? current_time( 'mysql' ),
		) );
		update_user_meta( $user_id, '_clms_certificate_record_' . $wp_course_id, $record );
	}

	public static function mirror_program_enroll_to_legacy( int $user_id, int $wp_program_id ): void {
		$programs = array_filter( array_map( 'absint', (array) get_user_meta( $user_id, '_clms_enrolled_programs', true ) ) );
		if ( ! in_array( $wp_program_id, $programs, true ) ) {
			$programs[] = $wp_program_id;
			update_user_meta( $user_id, '_clms_enrolled_programs', array_values( array_unique( $programs ) ) );
		}
	}

	public static function mirror_program_unenroll_from_legacy( int $user_id, int $wp_program_id ): void {
		$programs = array_filter( array_map( 'absint', (array) get_user_meta( $user_id, '_clms_enrolled_programs', true ) ) );
		update_user_meta( $user_id, '_clms_enrolled_programs', array_values( array_diff( $programs, array( $wp_program_id ) ) ) );
	}

	/**
	 * Espeja la caducidad de acceso a un curso en el usermeta legacy.
	 *
	 * El stack legacy almacena la fecha en hora local del sitio dentro de un
	 * array `_clms_course_access_expiry[$wp_course_id]`. `null` = acceso perpetuo:
	 * elimina la entrada del mapa.
	 *
	 * @param int         $user_id       Usuario.
	 * @param int         $wp_course_id  wp_posts.ID del curso.
	 * @param string|null $expires_at_gmt Fecha GMT 'Y-m-d H:i:s', o null para acceso perpetuo.
	 */
	public static function mirror_access_expiry_to_legacy( int $user_id, int $wp_course_id, ?string $expires_at_gmt ): void {
		$expirations = get_user_meta( $user_id, '_clms_course_access_expiry', true );
		$expirations = is_array( $expirations ) ? $expirations : array();

		if ( null === $expires_at_gmt || '' === $expires_at_gmt ) {
			unset( $expirations[ $wp_course_id ] );
		} else {
			// Convertir a hora local del sitio (formato que escribe CLMS_Helper::set_user_course_access_expiration).
			$expirations[ $wp_course_id ] = get_date_from_gmt( $expires_at_gmt );
		}

		update_user_meta( $user_id, '_clms_course_access_expiry', $expirations );
	}
}
