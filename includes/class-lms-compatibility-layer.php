<?php
/**
 * LMS Compatibility Layer — F2 (Doble escritura simétrica)
 *
 * Escucha acciones de AMBAS direcciones y sincroniza:
 *
 *   legacy → tablas  (clms_user_enrolled, clms_lesson_completed, …)
 *   tablas → legacy  (atora/lms/enrolled, atora/lms/lesson_completed, …)
 *                    ← solo si `atora_lms_dualwrite = true`
 *
 * La guarda estática `LMS_Write_Facade::$syncing` evita el bucle:
 * una escritura espejada NO vuelve a disparar el listener contrario.
 *
 * @package ATORA_LMS\LMS
 * @since   6.0.7
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_LMS_Compatibility_Layer {

	// ── Hooks ────────────────────────────────────────────────────────────────

	public static function init(): void {
		// ── legacy → tablas (rutas existentes) ────────────────────────────────
		add_action( 'clms_user_enrolled',                          array( __CLASS__, 'sync_enrollment_to_table' ),    20, 2 );
		add_action( 'clms_course_completed',                       array( __CLASS__, 'sync_completion_to_table' ),    20, 2 );
		add_action( 'clms_lesson_completed',                       array( __CLASS__, 'sync_lesson_progress' ),        20, 2 );
		add_action( 'clms_user_course_access_expiration_updated',  array( __CLASS__, 'sync_access_expiry_to_table' ), 20, 3 );

		// ── legacy → tablas (F2.1 — agujeros cerrados) ────────────────────────
		add_action( 'clms_user_unenrolled',                        array( __CLASS__, 'sync_unenrollment_to_table' ),       20, 2 );
		add_action( 'clms_user_enrolled_in_program',               array( __CLASS__, 'sync_program_enrollment_to_table' ), 20, 2 );
		add_action( 'clms_user_unenrolled_from_program',           array( __CLASS__, 'sync_program_unenrollment_to_table' ), 20, 2 );

		// ── tablas → legacy (F2.2 — solo si dualwrite ON) ─────────────────────
		add_action( 'atora/lms/enrolled',           array( __CLASS__, 'mirror_enrollment_to_legacy' ),      20, 2 );
		add_action( 'atora/lms/lesson_completed',   array( __CLASS__, 'mirror_lesson_to_legacy' ),          20, 3 );
		add_action( 'atora/lms/course_completed',   array( __CLASS__, 'mirror_course_complete_to_legacy' ), 20, 2 );
		add_action( 'atora/lms/program_enrolled',   array( __CLASS__, 'mirror_program_enroll_to_legacy' ),  20, 2 );
		add_action( 'atora/lms/program_unenrolled', array( __CLASS__, 'mirror_program_unenroll_to_legacy' ),20, 2 );
		add_action( 'atora/lms/access_expiry_set',  array( __CLASS__, 'mirror_access_expiry_to_legacy' ),   20, 3 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// legacy → tablas
	// ══════════════════════════════════════════════════════════════════════════

	public static function sync_enrollment_to_table( int $user_id, int $wp_course_id ): void {
		if ( ! self::guard_ok() ) { return; }
		if ( ! self::ensure_services() ) { return; }

		global $wpdb;
		$atora_course_id = self::resolve_atora_course( $wp_course_id );

		if ( ! $atora_course_id ) {
			\ATORA\LMS\LMS_Migrator::migrate_courses( 1, 0 );
			$atora_course_id = self::resolve_atora_course( $wp_course_id );
		}
		if ( ! $atora_course_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $user_id, $atora_course_id );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function sync_unenrollment_to_table( int $user_id, int $wp_course_id ): void {
		if ( ! self::guard_ok() ) { return; }

		global $wpdb;
		$atora_course_id = self::resolve_atora_course( $wp_course_id );
		if ( ! $atora_course_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		$wpdb->update(
			$wpdb->prefix . 'atora_enrollments',
			array( 'status' => 'unenrolled' ),
			array( 'user_id' => $user_id, 'course_id' => $atora_course_id ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function sync_completion_to_table( int $user_id, int $wp_course_id ): void {
		if ( ! self::guard_ok() ) { return; }

		global $wpdb;
		$atora_course_id = self::resolve_atora_course( $wp_course_id );
		if ( ! $atora_course_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		$wpdb->update(
			$wpdb->prefix . 'atora_enrollments',
			array( 'status' => 'completed', 'progress_pct' => 100, 'completed_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id, 'course_id' => $atora_course_id ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function sync_lesson_progress( int $user_id, int $wp_lesson_id ): void {
		if ( ! self::guard_ok() ) { return; }
		if ( ! self::ensure_services() ) { return; }

		global $wpdb;
		$atora_lesson_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_lessons WHERE wp_post_id = %d LIMIT 1", $wp_lesson_id )
		);
		if ( ! $atora_lesson_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		\ATORA\LMS\LMS_Enrollment_Service::complete_lesson( $user_id, $atora_lesson_id );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function sync_access_expiry_to_table( int $user_id, int $wp_course_id, string $expires_at ): void {
		if ( ! self::guard_ok() ) { return; }

		global $wpdb;
		$atora_course_id = self::resolve_atora_course( $wp_course_id );
		if ( ! $atora_course_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		$wpdb->update(
			$wpdb->prefix . 'atora_enrollments',
			array( 'expires_at' => $expires_at ),
			array( 'user_id' => $user_id, 'course_id' => $atora_course_id ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function sync_program_enrollment_to_table( int $user_id, int $wp_program_id ): void {
		if ( ! self::guard_ok() ) { return; }

		global $wpdb;
		$atora_program_id = self::resolve_atora_program( $wp_program_id );
		if ( ! $atora_program_id ) { return; }

		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atora_program_enrollments WHERE user_id = %d AND program_id = %d LIMIT 1",
			$user_id, $atora_program_id
		) );
		if ( $exists ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		$wpdb->insert(
			$wpdb->prefix . 'atora_program_enrollments',
			array(
				'user_id'       => $user_id,
				'program_id'    => $atora_program_id,
				'wp_program_id' => $wp_program_id,
				'status'        => 'active',
				'enrolled_at'   => current_time( 'mysql', true ),
			)
		);
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function sync_program_unenrollment_to_table( int $user_id, int $wp_program_id ): void {
		if ( ! self::guard_ok() ) { return; }

		global $wpdb;
		$atora_program_id = self::resolve_atora_program( $wp_program_id );
		if ( ! $atora_program_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		$wpdb->update(
			$wpdb->prefix . 'atora_program_enrollments',
			array( 'status' => 'unenrolled' ),
			array( 'user_id' => $user_id, 'program_id' => $atora_program_id ),
			array( '%s' ),
			array( '%d', '%d' )
		);
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// tablas → legacy  (F2.2 — solo si dualwrite ON y guarda desactivada)
	// ══════════════════════════════════════════════════════════════════════════

	public static function mirror_enrollment_to_legacy( int $user_id, int $atora_course_id ): void {
		if ( ! self::mirror_ok() ) { return; }

		global $wpdb;
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_courses WHERE id = %d LIMIT 1",
			$atora_course_id
		) );
		if ( ! $wp_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		\ATORA\LMS\LMS_Write_Facade::mirror_enroll_to_legacy( $user_id, $wp_id );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function mirror_lesson_to_legacy( int $user_id, int $atora_lesson_id, int $atora_course_id ): void {
		if ( ! self::mirror_ok() ) { return; }

		global $wpdb;
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_lessons WHERE id = %d LIMIT 1",
			$atora_lesson_id
		) );
		if ( ! $wp_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		\ATORA\LMS\LMS_Write_Facade::mirror_lesson_complete_to_legacy( $user_id, $wp_id );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function mirror_course_complete_to_legacy( int $user_id, int $atora_course_id ): void {
		if ( ! self::mirror_ok() ) { return; }

		global $wpdb;
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_courses WHERE id = %d LIMIT 1",
			$atora_course_id
		) );
		if ( ! $wp_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		do_action( 'clms_course_completed', $user_id, $wp_id );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function mirror_program_enroll_to_legacy( int $user_id, int $atora_program_id ): void {
		if ( ! self::mirror_ok() ) { return; }

		global $wpdb;
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_programs WHERE id = %d LIMIT 1",
			$atora_program_id
		) );
		if ( ! $wp_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		\ATORA\LMS\LMS_Write_Facade::mirror_program_enroll_to_legacy( $user_id, $wp_id );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	public static function mirror_program_unenroll_to_legacy( int $user_id, int $atora_program_id ): void {
		if ( ! self::mirror_ok() ) { return; }

		global $wpdb;
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_programs WHERE id = %d LIMIT 1",
			$atora_program_id
		) );
		if ( ! $wp_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		\ATORA\LMS\LMS_Write_Facade::mirror_program_unenroll_from_legacy( $user_id, $wp_id );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	/**
	 * Espeja la caducidad de acceso a un curso en usermeta legacy (F2.3a).
	 * `$atora_course_id` y `$expires_at_gmt` son los args 1 y 3 de `atora/lms/access_expiry_set`.
	 */
	public static function mirror_access_expiry_to_legacy( int $user_id, int $atora_course_id, ?string $expires_at_gmt ): void {
		if ( ! self::mirror_ok() ) { return; }

		global $wpdb;
		$wp_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}atora_courses WHERE id = %d LIMIT 1",
			$atora_course_id
		) );
		if ( ! $wp_id ) { return; }

		\ATORA\LMS\LMS_Write_Facade::$syncing = true;
		\ATORA\LMS\LMS_Write_Facade::mirror_access_expiry_to_legacy( $user_id, $wp_id, $expires_at_gmt );
		\ATORA\LMS\LMS_Write_Facade::$syncing = false;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// Lectura híbrida (sin cambios funcionales)
	// ══════════════════════════════════════════════════════════════════════════

	public static function get_enrolled_courses( int $user_id ): array {
		global $wpdb;

		$atora_table = $wpdb->prefix . 'atora_enrollments';
		$table_ok    = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $atora_table ) ) ) === $atora_table;

		$from_table = array();
		if ( $table_ok ) {
			$course_ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT e.course_id FROM {$atora_table} e WHERE e.user_id = %d AND e.status IN ('active','completed')",
					$user_id
				)
			);
			if ( ! empty( $course_ids ) ) {
				$in     = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
				$wp_ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare( "SELECT wp_post_id FROM {$wpdb->prefix}atora_courses WHERE id IN ({$in}) AND wp_post_id > 0", ...$course_ids )
				);
				$from_table = array_map( 'absint', $wp_ids );
			}
		}

		$from_meta = (array) get_user_meta( $user_id, '_clms_enrolled_courses', true );
		$from_meta = array_map( 'absint', array_filter( $from_meta ) );

		return array_values( array_unique( array_merge( $from_table, $from_meta ) ) );
	}

	// ── Helpers privados ──────────────────────────────────────────────────────

	/** Devuelve false si la guarda de reentrada está activa. */
	private static function guard_ok(): bool {
		return ! \ATORA\LMS\LMS_Write_Facade::$syncing;
	}

	/** Devuelve true si dualwrite está ON Y la guarda está libre. */
	private static function mirror_ok(): bool {
		return ! \ATORA\LMS\LMS_Write_Facade::$syncing
			&& \ATORA\LMS\LMS_Write_Facade::dualwrite_enabled();
	}

	private static function ensure_services(): bool {
		if ( class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) { return true; }
		$dir = ATORA_LMS_MODULES_DIR . 'lms/';
		foreach ( array( 'class-lms-course-service.php', 'class-lms-enrollment-service.php', 'class-lms-migrator.php' ) as $f ) {
			if ( file_exists( $dir . $f ) ) { require_once $dir . $f; }
		}
		return class_exists( '\ATORA\LMS\LMS_Enrollment_Service' );
	}

	private static function resolve_atora_course( int $wp_course_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1",
			$wp_course_id
		) );
	}

	private static function resolve_atora_program( int $wp_program_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atora_programs WHERE wp_post_id = %d LIMIT 1",
			$wp_program_id
		) );
	}
}
