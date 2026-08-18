<?php
/**
 * Section_Service — Secciones académicas (Etapa 2 / atora-cohort-sections)
 *
 * Una sección = un curso + un profesor lead + un grupo de alumnos + horario.
 * Las tablas atora_sections, atora_section_teachers y atora_section_students
 * se crean via V5_Installer (modules/class-v5-installer.php).
 *
 * Decisiones aplicadas:
 *   DC-1: tablas atora_* (wp_course_id almacena WP post ID de lm_course)
 *   DC-2: varios profesores con rol (lead|assistant|guest); un lead obligatorio
 *   DC-3: add_student() garantiza matrícula vía API canónica; remove_student() NO desmatricula
 *   DC-4: proyección por Section_Projector
 *
 * @package ATORA_LMS\LMS
 * @since   6.1.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Section_Service {

	// ── Tabla ────────────────────────────────────────────────────────────────

	const TABLE_SECTIONS         = 'atora_sections';
	const TABLE_SECTION_TEACHERS = 'atora_section_teachers';
	const TABLE_SECTION_STUDENTS = 'atora_section_students';

	// ── Valores permitidos ────────────────────────────────────────────────────

	const STATUS_SCHEDULED = 'scheduled';
	const STATUS_ACTIVE    = 'active';
	const STATUS_CLOSED    = 'closed';
	const STATUS_CANCELLED = 'cancelled';

	const ROLE_LEAD      = 'lead';
	const ROLE_ASSISTANT = 'assistant';
	const ROLE_GUEST     = 'guest';

	public static function get_allowed_statuses(): array {
		return array( self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_CLOSED, self::STATUS_CANCELLED );
	}

	public static function get_allowed_teacher_roles(): array {
		return array( self::ROLE_LEAD, self::ROLE_ASSISTANT, self::ROLE_GUEST );
	}

	public static function get_allowed_student_statuses(): array {
		return array( 'active', 'inactive', 'completed', 'withdrawn' );
	}

	// ── CRUD ──────────────────────────────────────────────────────────────────

	/**
	 * Crea una sección.
	 *
	 * @param array $data {
	 *   wp_course_id (int, required),
	 *   cohort_id    (int|null),
	 *   title        (string),
	 *   schedule_json (string|null),
	 *   capacity     (int),
	 *   status       (string),
	 *   start_date   (string YYYY-MM-DD|null),
	 *   end_date     (string YYYY-MM-DD|null),
	 *   meta_json    (string|null),
	 * }
	 * @return int|false Section ID o false en error.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$wp_course_id = absint( $data['wp_course_id'] ?? 0 );
		if ( ! $wp_course_id ) {
			return false;
		}

		$status = sanitize_key( (string) ( $data['status'] ?? self::STATUS_ACTIVE ) );
		if ( ! in_array( $status, self::get_allowed_statuses(), true ) ) {
			$status = self::STATUS_ACTIVE;
		}

		$row = array(
			'wp_course_id' => $wp_course_id,
			'cohort_id'    => ! empty( $data['cohort_id'] ) ? absint( $data['cohort_id'] ) : null,
			'title'        => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'schedule_json'=> isset( $data['schedule_json'] ) ? (string) $data['schedule_json'] : null,
			'capacity'     => absint( $data['capacity'] ?? 0 ),
			'status'       => $status,
			'start_date'   => self::sanitize_date( $data['start_date'] ?? '' ),
			'end_date'     => self::sanitize_date( $data['end_date'] ?? '' ),
			'meta_json'    => isset( $data['meta_json'] ) ? (string) $data['meta_json'] : null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $wpdb->prefix . self::TABLE_SECTIONS, $row );
		if ( false === $ok ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Actualiza campos de una sección existente.
	 *
	 * @param int   $section_id
	 * @param array $data Campos a actualizar (mismos que create).
	 * @return bool
	 */
	public static function update( int $section_id, array $data ): bool {
		global $wpdb;

		if ( ! $section_id ) {
			return false;
		}

		$allowed_fields = array(
			'wp_course_id', 'cohort_id', 'title', 'schedule_json',
			'capacity', 'status', 'start_date', 'end_date', 'meta_json',
		);
		$row = array();

		foreach ( $allowed_fields as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			switch ( $field ) {
				case 'wp_course_id':
				case 'capacity':
					$row[ $field ] = absint( $data[ $field ] );
					break;
				case 'cohort_id':
					$row[ $field ] = ! empty( $data[ $field ] ) ? absint( $data[ $field ] ) : null;
					break;
				case 'status':
					$v = sanitize_key( (string) $data[ $field ] );
					$row[ $field ] = in_array( $v, self::get_allowed_statuses(), true ) ? $v : self::STATUS_ACTIVE;
					break;
				case 'start_date':
				case 'end_date':
					$row[ $field ] = self::sanitize_date( $data[ $field ] );
					break;
				case 'title':
					$row[ $field ] = sanitize_text_field( (string) $data[ $field ] );
					break;
				default:
					$row[ $field ] = (string) $data[ $field ];
					break;
			}
		}

		if ( empty( $row ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update(
			$wpdb->prefix . self::TABLE_SECTIONS,
			$row,
			array( 'id' => $section_id )
		);

		return false !== $ok;
	}

	/**
	 * Lee una sección por ID.
	 *
	 * @param int $section_id
	 * @return array|null
	 */
	public static function get( int $section_id ): ?array {
		global $wpdb;

		if ( ! $section_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE_SECTIONS . " WHERE id = %d LIMIT 1",
				$section_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Elimina una sección y sus teachers/students asociados.
	 *
	 * @param int $section_id
	 * @return bool
	 */
	public static function delete( int $section_id ): bool {
		global $wpdb;

		if ( ! $section_id ) {
			return false;
		}

		// Eliminar teachers y students primero (no hay FK constraints en WP)
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . self::TABLE_SECTION_TEACHERS, array( 'section_id' => $section_id ) );
		$wpdb->delete( $wpdb->prefix . self::TABLE_SECTION_STUDENTS, array( 'section_id' => $section_id ) );
		$ok = $wpdb->delete( $wpdb->prefix . self::TABLE_SECTIONS, array( 'id' => $section_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return false !== $ok && $ok > 0;
	}

	// ── Asignación de profesores ──────────────────────────────────────────────

	/**
	 * Añade o actualiza un profesor en la sección.
	 *
	 * @param int    $section_id
	 * @param int    $user_id
	 * @param string $role lead|assistant|guest
	 * @return bool
	 */
	public static function add_teacher( int $section_id, int $user_id, string $role = self::ROLE_LEAD ): bool {
		global $wpdb;

		if ( ! $section_id || ! $user_id ) {
			return false;
		}

		$role = sanitize_key( $role );
		if ( ! in_array( $role, self::get_allowed_teacher_roles(), true ) ) {
			$role = self::ROLE_LEAD;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}" . self::TABLE_SECTION_TEACHERS . " WHERE section_id = %d AND user_id = %d LIMIT 1",
				$section_id,
				$user_id
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->update(
				$wpdb->prefix . self::TABLE_SECTION_TEACHERS,
				array( 'role' => $role ),
				array( 'section_id' => $section_id, 'user_id' => $user_id )
			);
			return false !== $ok;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$wpdb->prefix . self::TABLE_SECTION_TEACHERS,
			array(
				'section_id' => $section_id,
				'user_id'    => $user_id,
				'role'       => $role,
			)
		);

		return false !== $ok;
	}

	/**
	 * Elimina un profesor de la sección.
	 *
	 * @param int $section_id
	 * @param int $user_id
	 * @return bool
	 */
	public static function remove_teacher( int $section_id, int $user_id ): bool {
		global $wpdb;

		if ( ! $section_id || ! $user_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->delete(
			$wpdb->prefix . self::TABLE_SECTION_TEACHERS,
			array( 'section_id' => $section_id, 'user_id' => $user_id )
		);

		return false !== $ok;
	}

	/**
	 * Devuelve todos los profesores de una sección.
	 *
	 * @param int $section_id
	 * @return array<array{user_id: int, role: string}>
	 */
	public static function get_teachers( int $section_id ): array {
		global $wpdb;

		if ( ! $section_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_id, role FROM {$wpdb->prefix}" . self::TABLE_SECTION_TEACHERS . " WHERE section_id = %d ORDER BY role ASC, id ASC",
				$section_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static function( $r ) {
			return array( 'user_id' => (int) $r['user_id'], 'role' => (string) $r['role'] );
		}, $rows );
	}

	/**
	 * Devuelve el user_id del lead de la sección, o null si no tiene.
	 *
	 * @param int $section_id
	 * @return int|null
	 */
	public static function get_lead_teacher( int $section_id ): ?int {
		global $wpdb;

		if ( ! $section_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_id FROM {$wpdb->prefix}" . self::TABLE_SECTION_TEACHERS . " WHERE section_id = %d AND role = 'lead' LIMIT 1",
				$section_id
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * PT-3.4 (6.4.0): coordinador de la sección, si hay uno asignado.
	 * Reutiliza la misma tabla pivote de docentes (`atora_section_teachers`)
	 * con `role = 'coordinator'` — no requiere una tabla ni columna
	 * nueva. Sin filas de ese role para la sección, devuelve null: por
	 * diseño, ninguna sección tiene coordinador asignado por defecto,
	 * así que ninguna notificación de coordinador se envía hasta que un
	 * admin asigne uno explícitamente.
	 *
	 * @param int $section_id
	 * @return int|null
	 */
	public static function get_coordinator( int $section_id ): ?int {
		global $wpdb;

		if ( ! $section_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_id FROM {$wpdb->prefix}" . self::TABLE_SECTION_TEACHERS . " WHERE section_id = %d AND role = 'coordinator' LIMIT 1",
				$section_id
			)
		);

		return $id ? (int) $id : null;
	}

	// ── Asignación de estudiantes ─────────────────────────────────────────────

	/**
	 * Añade un alumno a la sección y garantiza su matrícula en el curso (DC-3).
	 *
	 * @param int    $section_id
	 * @param int    $user_id
	 * @param string $status Estado inicial del alumno en la sección.
	 * @return bool True si el alumno quedó en la sección (independientemente de si ya estaba).
	 */
	public static function add_student( int $section_id, int $user_id, string $status = 'active' ): bool {
		global $wpdb;

		if ( ! $section_id || ! $user_id ) {
			return false;
		}

		$section = self::get( $section_id );
		if ( ! $section ) {
			return false;
		}

		$wp_course_id = (int) $section['wp_course_id'];

		// DC-3: garantizar matrícula vía API canónica (nunca escribir atora_enrollments directamente)
		if ( $wp_course_id && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
			CLMS_Helper::enroll_user_in_course( $user_id, $wp_course_id );
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::get_allowed_student_statuses(), true ) ) {
			$status = 'active';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}" . self::TABLE_SECTION_STUDENTS . " WHERE section_id = %d AND user_id = %d LIMIT 1",
				$section_id,
				$user_id
			)
		);

		if ( $existing ) {
			return true; // ya estaba
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$wpdb->prefix . self::TABLE_SECTION_STUDENTS,
			array(
				'section_id' => $section_id,
				'user_id'    => $user_id,
				'status'     => $status,
			)
		);

		return false !== $ok;
	}

	/**
	 * Quita un alumno de la sección (NO desmatricula del curso — DC-3).
	 *
	 * @param int $section_id
	 * @param int $user_id
	 * @return bool
	 */
	public static function remove_student( int $section_id, int $user_id ): bool {
		global $wpdb;

		if ( ! $section_id || ! $user_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->delete(
			$wpdb->prefix . self::TABLE_SECTION_STUDENTS,
			array( 'section_id' => $section_id, 'user_id' => $user_id )
		);

		return false !== $ok;
	}

	/**
	 * Actualiza el estado de un alumno dentro de la sección.
	 *
	 * @param int    $section_id
	 * @param int    $user_id
	 * @param string $status
	 * @return bool
	 */
	public static function update_student_status( int $section_id, int $user_id, string $status ): bool {
		global $wpdb;

		if ( ! $section_id || ! $user_id ) {
			return false;
		}

		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::get_allowed_student_statuses(), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update(
			$wpdb->prefix . self::TABLE_SECTION_STUDENTS,
			array( 'status' => $status ),
			array( 'section_id' => $section_id, 'user_id' => $user_id )
		);

		return false !== $ok;
	}

	// ── Consultas ─────────────────────────────────────────────────────────────

	/**
	 * Roster de alumnos de una sección.
	 *
	 * @param int $section_id
	 * @return array<array{user_id: int, status: string}>
	 */
	public static function get_section_roster( int $section_id ): array {
		global $wpdb;

		if ( ! $section_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_id, status FROM {$wpdb->prefix}" . self::TABLE_SECTION_STUDENTS . " WHERE section_id = %d ORDER BY id ASC",
				$section_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static function( $r ) {
			return array( 'user_id' => (int) $r['user_id'], 'status' => (string) $r['status'] );
		}, $rows );
	}

	/**
	 * Secciones de un curso (agrupadas por cohort_id opcional).
	 *
	 * @param int $wp_course_id WP post ID del curso.
	 * @return array<int, array> Indexed by section ID.
	 */
	public static function get_sections_by_course( int $wp_course_id ): array {
		global $wpdb;

		if ( ! $wp_course_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE_SECTIONS . " WHERE wp_course_id = %d ORDER BY cohort_id ASC, title ASC",
				$wp_course_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row['id'] ] = $row;
		}
		return $result;
	}

	/**
	 * Secciones donde el usuario es profesor (cualquier rol).
	 *
	 * @param int $user_id
	 * @return array<array> Lista de secciones con campo 'teacher_role'.
	 */
	public static function get_sections_by_teacher( int $user_id ): array {
		global $wpdb;

		if ( ! $user_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT s.*, t.role AS teacher_role
				 FROM {$wpdb->prefix}" . self::TABLE_SECTIONS . " s
				 INNER JOIN {$wpdb->prefix}" . self::TABLE_SECTION_TEACHERS . " t ON t.section_id = s.id
				 WHERE t.user_id = %d
				 ORDER BY s.wp_course_id ASC, s.title ASC",
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Secciones del profesor agrupadas por wp_course_id.
	 *
	 * @param int $user_id
	 * @return array<int, array[]> {wp_course_id => [section, …]}
	 */
	public static function get_teacher_sections_grouped_by_course( int $user_id ): array {
		$sections = self::get_sections_by_teacher( $user_id );
		$grouped  = array();

		foreach ( $sections as $section ) {
			$course_id = (int) $section['wp_course_id'];
			if ( ! isset( $grouped[ $course_id ] ) ) {
				$grouped[ $course_id ] = array();
			}
			$grouped[ $course_id ][] = $section;
		}

		return $grouped;
	}

	/**
	 * Secciones de un alumno en un curso específico.
	 *
	 * @param int $user_id
	 * @param int $wp_course_id
	 * @return array<array>
	 */
	public static function get_student_sections_for_course( int $user_id, int $wp_course_id ): array {
		global $wpdb;

		if ( ! $user_id || ! $wp_course_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT s.*
				 FROM {$wpdb->prefix}" . self::TABLE_SECTIONS . " s
				 INNER JOIN {$wpdb->prefix}" . self::TABLE_SECTION_STUDENTS . " ss ON ss.section_id = s.id
				 WHERE ss.user_id = %d AND s.wp_course_id = %d
				 ORDER BY s.id ASC",
				$user_id,
				$wp_course_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Devuelve el user_id del profesor efectivo de un alumno en un curso (DC-2).
	 *
	 * Algoritmo: lead de la primera sección activa del alumno → fallback meta curso.
	 *
	 * @param int $user_id
	 * @param int $wp_course_id
	 * @return int|null
	 */
	public static function get_effective_instructor( int $user_id, int $wp_course_id ): ?int {
		$sections = self::get_student_sections_for_course( $user_id, $wp_course_id );

		foreach ( $sections as $section ) {
			$lead = self::get_lead_teacher( (int) $section['id'] );
			if ( $lead ) {
				return $lead;
			}
		}

		// Fallback legacy: _clms_course_teacher_ids del post del curso (DC-2)
		$teacher_ids = get_post_meta( $wp_course_id, '_clms_course_teacher_ids', true );
		$teacher_ids = is_array( $teacher_ids ) ? array_values( array_filter( array_map( 'absint', $teacher_ids ) ) ) : array();

		return ! empty( $teacher_ids ) ? $teacher_ids[0] : null;
	}

	/**
	 * PT-3.4 (6.4.0): coordinador efectivo de un alumno en un curso —
	 * mismo algoritmo que get_effective_instructor() pero para
	 * get_coordinator(). Sin fallback legacy (el concepto de
	 * coordinador es nuevo, no había nada previo a este sprint).
	 *
	 * @param int $user_id
	 * @param int $wp_course_id
	 * @return int|null
	 */
	public static function get_effective_coordinator( int $user_id, int $wp_course_id ): ?int {
		$sections = self::get_student_sections_for_course( $user_id, $wp_course_id );

		foreach ( $sections as $section ) {
			$coordinator = self::get_coordinator( (int) $section['id'] );
			if ( $coordinator ) {
				return $coordinator;
			}
		}

		return null;
	}

	/**
	 * Secciones asociadas a una cohorte.
	 *
	 * @param int $cohort_id WP post ID de la cohorte.
	 * @return array<array>
	 */
	public static function get_sections_by_cohort( int $cohort_id ): array {
		global $wpdb;

		if ( ! $cohort_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE_SECTIONS . " WHERE cohort_id = %d ORDER BY wp_course_id ASC, title ASC",
				$cohort_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * IDs de alumnos de una sección (para filtro de gradebook).
	 *
	 * @param int $section_id
	 * @return int[]
	 */
	public static function get_section_student_ids( int $section_id ): array {
		$roster = self::get_section_roster( $section_id );
		return array_values( array_map( static fn( $r ) => (int) $r['user_id'], $roster ) );
	}

	// ── Helper ────────────────────────────────────────────────────────────────

	private static function sanitize_date( $value ): ?string {
		$value = sanitize_text_field( (string) $value );
		return ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) ? $value : null;
	}
}
