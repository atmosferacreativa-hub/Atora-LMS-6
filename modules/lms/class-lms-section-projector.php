<?php
/**
 * Section_Projector — Proyecta lm_cohort existentes a secciones (DC-4)
 *
 * Idempotente: detecta secciones ya proyectadas por meta_json.source.
 * No destructivo: la cohorte original no se modifica.
 * Si una cohorte tiene N profesores: el primero queda como 'lead' y los
 * demás como 'assistant'; la sección se marca needs_review=true.
 *
 * @package ATORA_LMS\LMS
 * @since   6.1.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Section_Projector {

	/**
	 * Proyecta una cohorte a secciones (una sección por cada curso).
	 *
	 * @param int $cohort_id WP post ID de la cohorte.
	 * @return array{created: int, skipped: int, errors: int, needs_review: int, details: string[]}
	 */
	public static function project_from_cohort( int $cohort_id ): array {
		$stats = array(
			'created'      => 0,
			'skipped'      => 0,
			'errors'       => 0,
			'needs_review' => 0,
			'details'      => array(),
		);

		if ( ! $cohort_id || 'lm_cohort' !== get_post_type( $cohort_id ) ) {
			$stats['details'][] = "Cohorte {$cohort_id} no encontrada o tipo incorrecto.";
			return $stats;
		}

		if ( ! class_exists( 'CLMS_Cohort_Service' ) ) {
			$stats['details'][] = 'CLMS_Cohort_Service no disponible.';
			return $stats;
		}

		$service     = new \CLMS_Cohort_Service();
		$course_ids  = $service->get_cohort_course_ids( $cohort_id );
		$teacher_ids = $service->get_cohort_teacher_ids( $cohort_id );
		$student_ids = $service->get_cohort_student_ids( $cohort_id );

		if ( empty( $course_ids ) ) {
			$stats['details'][] = "Cohorte {$cohort_id}: sin cursos asociados.";
			return $stats;
		}

		$source_prefix = "projected_from_cohort_{$cohort_id}";
		$needs_review  = count( $teacher_ids ) > 1;

		foreach ( $course_ids as $wp_course_id ) {
			$wp_course_id = absint( $wp_course_id );
			if ( ! $wp_course_id ) {
				continue;
			}

			// ── Idempotencia: ¿ya existe una sección proyectada de esta cohorte+curso?
			$existing_section_id = self::find_projected_section( $cohort_id, $wp_course_id );
			if ( $existing_section_id ) {
				$stats['skipped']++;
				$stats['details'][] = "Cohorte {$cohort_id} / curso {$wp_course_id}: ya proyectada (sección {$existing_section_id}).";
				continue;
			}

			// ── Nombre de la sección
			$cohort_title = get_the_title( $cohort_id );
			$course_title = get_the_title( $wp_course_id );
			$section_title = sanitize_text_field( ( $cohort_title ?: "Cohorte {$cohort_id}" ) . ' — ' . ( $course_title ?: "Curso {$wp_course_id}" ) );

			// ── meta_json
			$cohort_status = get_post_meta( $cohort_id, \CLMS_Cohort_Service::META_STATUS, true );
			$meta = array(
				'source'       => $source_prefix . "_course_{$wp_course_id}",
				'needs_review' => $needs_review,
				'projected_at' => current_time( 'mysql', true ),
			);

			// ── Crear sección
			$section_id = Section_Service::create( array(
				'wp_course_id' => $wp_course_id,
				'cohort_id'    => $cohort_id,
				'title'        => $section_title,
				'capacity'     => absint( get_post_meta( $cohort_id, \CLMS_Cohort_Service::META_CAPACITY, true ) ),
				'status'       => self::map_cohort_status( (string) $cohort_status ),
				'start_date'   => (string) get_post_meta( $cohort_id, \CLMS_Cohort_Service::META_START_DATE, true ),
				'end_date'     => (string) get_post_meta( $cohort_id, \CLMS_Cohort_Service::META_END_DATE, true ),
				'meta_json'    => wp_json_encode( $meta ),
			) );

			if ( ! $section_id ) {
				$stats['errors']++;
				$stats['details'][] = "Cohorte {$cohort_id} / curso {$wp_course_id}: error al crear sección.";
				continue;
			}

			// ── Asignar profesores
			if ( ! empty( $teacher_ids ) ) {
				$first = true;
				foreach ( $teacher_ids as $tid ) {
					$tid  = absint( $tid );
					$role = $first ? Section_Service::ROLE_LEAD : Section_Service::ROLE_ASSISTANT;
					Section_Service::add_teacher( $section_id, $tid, $role );
					$first = false;
				}
			}

			// ── Asignar estudiantes (DC-3: cada add_student garantiza matrícula)
			foreach ( $student_ids as $sid ) {
				$sid = absint( $sid );
				if ( $sid ) {
					Section_Service::add_student( $section_id, $sid );
				}
			}

			$stats['created']++;
			if ( $needs_review ) {
				$stats['needs_review']++;
			}
			$stats['details'][] = "Cohorte {$cohort_id} / curso {$wp_course_id}: sección {$section_id} creada" . ( $needs_review ? ' [needs_review]' : '' ) . '.';
		}

		return $stats;
	}

	/**
	 * Proyecta todas las cohortes existentes.
	 *
	 * @return array{total_cohorts: int, total_created: int, total_skipped: int, total_errors: int, total_needs_review: int, per_cohort: array}
	 */
	public static function project_all(): array {
		$cohorts = get_posts( array(
			'post_type'      => 'lm_cohort',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$cohorts = is_array( $cohorts ) ? array_values( array_filter( array_map( 'absint', $cohorts ) ) ) : array();

		$aggregate = array(
			'total_cohorts'      => count( $cohorts ),
			'total_created'      => 0,
			'total_skipped'      => 0,
			'total_errors'       => 0,
			'total_needs_review' => 0,
			'per_cohort'         => array(),
		);

		foreach ( $cohorts as $cohort_id ) {
			$result = self::project_from_cohort( $cohort_id );
			$aggregate['total_created']      += $result['created'];
			$aggregate['total_skipped']      += $result['skipped'];
			$aggregate['total_errors']       += $result['errors'];
			$aggregate['total_needs_review'] += $result['needs_review'];
			$aggregate['per_cohort'][ $cohort_id ] = $result;
		}

		return $aggregate;
	}

	/**
	 * Informe de secciones que requieren revisión manual.
	 *
	 * @return array<array{section_id: int, wp_course_id: int, cohort_id: int, title: string}>
	 */
	public static function get_needs_review_report(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, wp_course_id, cohort_id, title, meta_json
			 FROM {$wpdb->prefix}" . Section_Service::TABLE_SECTIONS . "
			 WHERE meta_json LIKE '%\"needs_review\":true%'
			 ORDER BY cohort_id ASC, wp_course_id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static function( $r ) {
			return array(
				'section_id'   => (int) $r['id'],
				'wp_course_id' => (int) $r['wp_course_id'],
				'cohort_id'    => (int) $r['cohort_id'],
				'title'        => (string) $r['title'],
			);
		}, $rows );
	}

	// ── Helper ────────────────────────────────────────────────────────────────

	/**
	 * Busca una sección ya proyectada para una cohorte+curso.
	 * Usa el campo meta_json.source como fingerprint de idempotencia.
	 *
	 * @param int $cohort_id
	 * @param int $wp_course_id
	 * @return int|null Section ID o null si no existe.
	 */
	private static function find_projected_section( int $cohort_id, int $wp_course_id ): ?int {
		global $wpdb;

		$source_pattern = '%projected_from_cohort_' . (int) $cohort_id . '_course_' . (int) $wp_course_id . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}" . Section_Service::TABLE_SECTIONS . "
				 WHERE cohort_id = %d AND wp_course_id = %d AND meta_json LIKE %s
				 LIMIT 1",
				$cohort_id,
				$wp_course_id,
				$source_pattern
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Mapea el estado de cohorte al estado de sección.
	 *
	 * @param string $cohort_status
	 * @return string
	 */
	private static function map_cohort_status( string $cohort_status ): string {
		$map = array(
			'proximo'    => Section_Service::STATUS_SCHEDULED,
			'activo'     => Section_Service::STATUS_ACTIVE,
			'en_cierre'  => Section_Service::STATUS_ACTIVE,
			'finalizado' => Section_Service::STATUS_CLOSED,
			'archivado'  => Section_Service::STATUS_CLOSED,
			'cancelado'  => Section_Service::STATUS_CANCELLED,
		);

		return $map[ $cohort_status ] ?? Section_Service::STATUS_ACTIVE;
	}
}
