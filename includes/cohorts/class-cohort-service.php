<?php
/**
 * Servicio de cohortes/grupos académicos.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Cohort_Service {

	const POST_TYPE = 'lm_cohort';

	const META_COURSE_IDS     = '_clms_cohort_course_ids';
	const META_PROGRAM_IDS    = '_clms_cohort_program_ids';
	const META_TEACHER_IDS    = '_clms_cohort_teacher_ids';
	const META_STUDENT_IDS    = '_clms_cohort_student_ids';
	const META_COMPANY        = '_clms_cohort_company';
	const META_START_DATE     = '_clms_cohort_start_date';
	const META_END_DATE       = '_clms_cohort_end_date';
	const META_CAPACITY       = '_clms_cohort_capacity';
	const META_STATUS         = '_clms_cohort_status';
	const META_NOTES          = '_clms_cohort_notes';
	const META_STUDENT_STATUS = '_clms_cohort_student_status_map';

	/**
	 * Fuente de lectura de cohortes.
	 *
	 * legacy = wp_postmeta (default)
	 * tables = tablas atora_cohorts/*
	 */
	const OPT_SOURCE = 'atora_cohort_source';

	private function source(): string {
		return (string) get_option( self::OPT_SOURCE, 'legacy' );
	}

	private function is_tables(): bool {
		return 'tables' === $this->source();
	}

	private function table_service_enabled(): bool {
		return $this->is_tables() && class_exists( '\ATORA\LMS\Cohort_Table_Service' );
	}

	/**
	 * Estados válidos de cohorte.
	 *
	 * @return array<string,string>
	 */
	public function get_status_labels() {
		return array(
			'proximo'    => __( 'Próximo', 'atora-lms' ),
			'activo'     => __( 'Activo', 'atora-lms' ),
			'en_cierre'  => __( 'En cierre', 'atora-lms' ),
			'finalizado' => __( 'Finalizado', 'atora-lms' ),
			'archivado'  => __( 'Archivado', 'atora-lms' ),
			'cancelado'  => __( 'Cancelado', 'atora-lms' ),
		);
	}

	/**
	 * Estados válidos del estudiante en su formación.
	 *
	 * @return array<string,string>
	 */
	public function get_student_status_labels() {
		return array(
			'activo'      => __( 'Activo', 'atora-lms' ),
			'en_riesgo'   => __( 'En riesgo', 'atora-lms' ),
			'en_mejora'   => __( 'En mejora', 'atora-lms' ),
			'completado'  => __( 'Completado', 'atora-lms' ),
			'certificado' => __( 'Certificado', 'atora-lms' ),
			'archivado'   => __( 'Archivado', 'atora-lms' ),
			'egresado'    => __( 'Egresado', 'atora-lms' ),
			'suspendido'  => __( 'Suspendido', 'atora-lms' ),
			'vencido'     => __( 'Vencido', 'atora-lms' ),
		);
	}

	/**
	 * Normaliza estado cohorte.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	public function normalize_cohort_status( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = $this->get_status_labels();
		return isset( $labels[ $status ] ) ? $status : 'proximo';
	}

	/**
	 * Normaliza estado de estudiante.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	public function normalize_student_status( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = $this->get_student_status_labels();
		return isset( $labels[ $status ] ) ? $status : 'activo';
	}

	/**
	 * Cursos asociados a cohorte.
	 *
	 * @param int $cohort_id Cohorte.
	 * @return array<int,int>
	 */
	public function get_cohort_course_ids( $cohort_id ) {
		if ( $this->table_service_enabled() ) {
			return \ATORA\LMS\Cohort_Table_Service::get_wp_course_ids( absint( $cohort_id ) );
		}
		return $this->normalize_ids( get_post_meta( absint( $cohort_id ), self::META_COURSE_IDS, true ) );
	}

	/**
	 * Programas asociados a cohorte.
	 *
	 * @param int $cohort_id Cohorte.
	 * @return array<int,int>
	 */
	public function get_cohort_program_ids( $cohort_id ) {
		return $this->normalize_ids( get_post_meta( absint( $cohort_id ), self::META_PROGRAM_IDS, true ) );
	}

	/**
	 * Profesores asociados a cohorte.
	 *
	 * @param int $cohort_id Cohorte.
	 * @return array<int,int>
	 */
	public function get_cohort_teacher_ids( $cohort_id ) {
		if ( $this->table_service_enabled() ) {
			return \ATORA\LMS\Cohort_Table_Service::get_member_ids( absint( $cohort_id ), 'teacher' );
		}
		return $this->normalize_ids( get_post_meta( absint( $cohort_id ), self::META_TEACHER_IDS, true ) );
	}

	/**
	 * Estudiantes asociados a cohorte.
	 *
	 * @param int $cohort_id Cohorte.
	 * @return array<int,int>
	 */
	public function get_cohort_student_ids( $cohort_id ) {
		if ( $this->table_service_enabled() ) {
			return \ATORA\LMS\Cohort_Table_Service::get_member_ids( absint( $cohort_id ), 'student' );
		}
		return $this->normalize_ids( get_post_meta( absint( $cohort_id ), self::META_STUDENT_IDS, true ) );
	}

	/**
	 * Estado por estudiante en cohorte.
	 *
	 * @param int $cohort_id Cohorte.
	 * @return array<int,string>
	 */
	public function get_cohort_student_status_map( $cohort_id ) {
		$cohort_id = absint( $cohort_id );
		if ( $this->table_service_enabled() ) {
			$raw = \ATORA\LMS\Cohort_Table_Service::get_student_status_map( $cohort_id );
			$raw = is_array( $raw ) ? $raw : array();
			$map = array();
			foreach ( $this->get_cohort_student_ids( $cohort_id ) as $student_id ) {
				$student_id = absint( $student_id );
				if ( ! $student_id ) { continue; }
				$map[ $student_id ] = $this->normalize_student_status( $raw[ $student_id ] ?? 'activo' );
			}
			return $map;
		}
		$raw       = get_post_meta( $cohort_id, self::META_STUDENT_STATUS, true );
		$raw       = is_array( $raw ) ? $raw : array();
		$students  = $this->get_cohort_student_ids( $cohort_id );
		$map       = array();

		foreach ( $students as $student_id ) {
			$student_id = absint( $student_id );
			if ( ! $student_id ) {
				continue;
			}
			$status = isset( $raw[ $student_id ] ) ? $this->normalize_student_status( $raw[ $student_id ] ) : 'activo';
			$map[ $student_id ] = $status;
		}

		return $map;
	}

	/**
	 * Guarda estudiantes asociados.
	 *
	 * @param int   $cohort_id   Cohorte.
	 * @param array $student_ids Estudiantes.
	 * @return void
	 */
	public function set_cohort_student_ids( $cohort_id, $student_ids ) {
		$cohort_id   = absint( $cohort_id );
		if ( $this->table_service_enabled() ) {
			// En modo tablas, la edición/mutación se gestiona por servicios tabulares.
			// No escribir en postmeta legacy durante la transición.
			return;
		}
		$student_ids = $this->normalize_ids( $student_ids );
		update_post_meta( $cohort_id, self::META_STUDENT_IDS, $student_ids );

		$map = $this->get_cohort_student_status_map( $cohort_id );
		$new_map = array();
		foreach ( $student_ids as $student_id ) {
			$new_map[ $student_id ] = isset( $map[ $student_id ] ) ? $map[ $student_id ] : 'activo';
		}
		update_post_meta( $cohort_id, self::META_STUDENT_STATUS, $new_map );
	}

	/**
	 * Añade estudiante evitando duplicados.
	 *
	 * @param int $cohort_id  Cohorte.
	 * @param int $student_id Estudiante.
	 * @return bool
	 */
	public function add_student_to_cohort( $cohort_id, $student_id ) {
		$cohort_id  = absint( $cohort_id );
		$student_id = absint( $student_id );
		if ( ! $cohort_id || ! $student_id ) {
			return false;
		}
		if ( $this->table_service_enabled() ) {
			return false;
		}

		$students = $this->get_cohort_student_ids( $cohort_id );
		if ( in_array( $student_id, $students, true ) ) {
			return true;
		}

		$students[] = $student_id;
		$this->set_cohort_student_ids( $cohort_id, $students );
		return true;
	}

	/**
	 * Quita estudiante de cohorte.
	 *
	 * @param int $cohort_id  Cohorte.
	 * @param int $student_id Estudiante.
	 * @return bool
	 */
	public function remove_student_from_cohort( $cohort_id, $student_id ) {
		$cohort_id  = absint( $cohort_id );
		$student_id = absint( $student_id );
		if ( ! $cohort_id || ! $student_id ) {
			return false;
		}
		if ( $this->table_service_enabled() ) {
			return false;
		}

		$students = $this->get_cohort_student_ids( $cohort_id );
		$students = array_values( array_diff( $students, array( $student_id ) ) );
		$this->set_cohort_student_ids( $cohort_id, $students );
		return true;
	}

	/**
	 * Guarda estado de estudiante dentro de cohorte.
	 *
	 * @param int    $cohort_id  Cohorte.
	 * @param int    $student_id Estudiante.
	 * @param string $status     Estado.
	 * @return bool
	 */
	public function set_student_status_in_cohort( $cohort_id, $student_id, $status ) {
		$cohort_id  = absint( $cohort_id );
		$student_id = absint( $student_id );
		if ( ! $cohort_id || ! $student_id ) {
			return false;
		}
		if ( $this->table_service_enabled() ) {
			return false;
		}

		$students = $this->get_cohort_student_ids( $cohort_id );
		if ( ! in_array( $student_id, $students, true ) ) {
			return false;
		}

		$map = $this->get_cohort_student_status_map( $cohort_id );
		$map[ $student_id ] = $this->normalize_student_status( $status );
		update_post_meta( $cohort_id, self::META_STUDENT_STATUS, $map );
		return true;
	}

	/**
	 * Sincroniza estados de estudiantes cuando cambia estado de cohorte.
	 *
	 * @param int    $cohort_id   Cohorte.
	 * @param string $cohort_state Estado cohorte.
	 * @return void
	 */
	public function sync_student_states_for_cohort_state( $cohort_id, $cohort_state ) {
		$cohort_id    = absint( $cohort_id );
		$cohort_state = $this->normalize_cohort_status( $cohort_state );
		if ( ! $cohort_id ) {
			return;
		}
		if ( $this->table_service_enabled() ) {
			return;
		}

		$map = $this->get_cohort_student_status_map( $cohort_id );
		if ( empty( $map ) ) {
			return;
		}

		foreach ( $map as $student_id => $status ) {
			$status = $this->normalize_student_status( $status );
			if ( 'finalizado' === $cohort_state && in_array( $status, array( 'activo', 'en_riesgo', 'en_mejora', 'completado' ), true ) ) {
				$map[ $student_id ] = 'egresado';
			} elseif ( in_array( $cohort_state, array( 'archivado', 'cancelado' ), true ) && ! in_array( $status, array( 'certificado', 'egresado' ), true ) ) {
				$map[ $student_id ] = 'archivado';
			}
		}

		update_post_meta( $cohort_id, self::META_STUDENT_STATUS, $map );
	}

	/**
	 * Cohortes visibles según permisos.
	 *
	 * @param int   $user_id       Usuario.
	 * @param array $status_filter Estados opcionales.
	 * @param int   $limit         Límite.
	 * @return array<int,int>
	 */
	public function get_visible_cohort_ids( $user_id = 0, $status_filter = array(), $limit = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$limit = absint( $limit );

		if ( $this->table_service_enabled() ) {
			return \ATORA\LMS\Cohort_Table_Service::get_visible_wp_post_ids( $user_id, is_array( $status_filter ) ? $status_filter : array(), $limit );
		}

		$posts = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => ( $limit > 0 ? $limit : -1 ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		$posts = is_array( $posts ) ? array_values( array_filter( array_map( 'absint', $posts ) ) ) : array();
		if ( empty( $posts ) ) {
			return array();
		}

		$status_filter = is_array( $status_filter ) ? array_map( 'sanitize_key', $status_filter ) : array();
		$status_filter = array_values( array_filter( $status_filter ) );

		if ( current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) ) {
			if ( empty( $status_filter ) ) {
				return $posts;
			}
			return array_values(
				array_filter(
					$posts,
					function( $cohort_id ) use ( $status_filter ) {
						$status = $this->normalize_cohort_status( get_post_meta( $cohort_id, self::META_STATUS, true ) );
						return in_array( $status, $status_filter, true );
					}
				)
			);
		}

		$visible = array();
		foreach ( $posts as $cohort_id ) {
			$teacher_ids = $this->get_cohort_teacher_ids( $cohort_id );
			$is_owner    = absint( get_post_field( 'post_author', $cohort_id ) ) === $user_id;
			$is_teacher  = in_array( $user_id, $teacher_ids, true );
			if ( ! $is_owner && ! $is_teacher ) {
				continue;
			}
			if ( ! empty( $status_filter ) ) {
				$status = $this->normalize_cohort_status( get_post_meta( $cohort_id, self::META_STATUS, true ) );
				if ( ! in_array( $status, $status_filter, true ) ) {
					continue;
				}
			}
			$visible[] = $cohort_id;
		}

		return $visible;
	}

	/**
	 * Reporte operativo por cohorte.
	 *
	 * @param int $cohort_id Cohorte.
	 * @return array<string,mixed>
	 */
	public function get_cohort_report( $cohort_id ) {
		$cohort_id = absint( $cohort_id );
		if ( ! $cohort_id || self::POST_TYPE !== get_post_type( $cohort_id ) ) {
			return array();
		}

		$course_ids  = $this->get_cohort_course_ids( $cohort_id );
		$student_ids = $this->get_cohort_student_ids( $cohort_id );
		$status_map  = $this->get_cohort_student_status_map( $cohort_id );
		$status_srv  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		$cert_srv    = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;

		$active_count     = 0;
		$risk_count       = 0;
		$completed_count  = 0;
		$certified_count  = 0;
		$progress_samples = array();

		foreach ( $student_ids as $student_id ) {
			$student_id = absint( $student_id );
			if ( ! $student_id ) {
				continue;
			}

			$student_state = isset( $status_map[ $student_id ] ) ? $status_map[ $student_id ] : 'activo';
			if ( in_array( $student_state, array( 'activo', 'en_mejora' ), true ) ) {
				$active_count++;
			}
			if ( 'en_riesgo' === $student_state ) {
				$risk_count++;
			}
			if ( in_array( $student_state, array( 'completado', 'egresado' ), true ) ) {
				$completed_count++;
			}
			if ( 'certificado' === $student_state ) {
				$certified_count++;
			}

			foreach ( $course_ids as $course_id ) {
				$course_id = absint( $course_id );
				if ( ! $course_id ) {
					continue;
				}
				$status = ( $status_srv && method_exists( $status_srv, 'get_student_course_status' ) )
					? (array) $status_srv->get_student_course_status( $student_id, $course_id )
					: array();
				if ( isset( $status['progress_percent'] ) && is_numeric( $status['progress_percent'] ) ) {
					$progress_samples[] = absint( $status['progress_percent'] );
				}

				if ( $cert_srv && method_exists( $cert_srv, 'get_certificate_status_for_student_course' ) ) {
					$cert = (array) $cert_srv->get_certificate_status_for_student_course( $student_id, $course_id );
					$cert_status = sanitize_key( (string) ( $cert['status'] ?? '' ) );
					if ( in_array( $cert_status, array( 'valid', 'issued' ), true ) ) {
						$certified_count++;
					}
				}
			}
		}

		$pending_evaluations = $this->count_pending_evaluations( $course_ids, $student_ids );
		$progress_avg = ! empty( $progress_samples ) ? (int) round( array_sum( $progress_samples ) / count( $progress_samples ) ) : 0;

		return array(
			'cohort_id'             => $cohort_id,
			'status'                => $this->normalize_cohort_status( get_post_meta( $cohort_id, self::META_STATUS, true ) ),
			'progress_average'      => $progress_avg,
			'students_total'        => count( $student_ids ),
			'students_active'       => $active_count,
			'students_risk'         => $risk_count,
			'students_completed'    => $completed_count,
			'certificates_issued'   => $certified_count,
			'pending_evaluations'   => $pending_evaluations,
			'is_ready_for_closure'  => ( $pending_evaluations <= 0 && $active_count <= 0 && count( $student_ids ) > 0 ),
		);
	}

	/**
	 * Snapshot para dashboard/admin.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,mixed>
	 */
	public function get_operational_snapshot( $user_id = 0 ) {
		$cohort_ids = $this->get_visible_cohort_ids( $user_id, array(), 0 );
		$counts = array(
			'proximo'    => 0,
			'activo'     => 0,
			'en_cierre'  => 0,
			'finalizado' => 0,
			'archivado'  => 0,
			'cancelado'  => 0,
		);
		$alerts = 0;

		foreach ( $cohort_ids as $cohort_id ) {
			$status = $this->normalize_cohort_status( get_post_meta( $cohort_id, self::META_STATUS, true ) );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
			if ( $this->has_configuration_alerts( $cohort_id ) ) {
				$alerts++;
			}
		}

		return array(
			'total'         => count( $cohort_ids ),
			'proximo'       => absint( $counts['proximo'] ),
			'activo'        => absint( $counts['activo'] ),
			'en_cierre'     => absint( $counts['en_cierre'] ),
			'finalizado'    => absint( $counts['finalizado'] ),
			'archivado'     => absint( $counts['archivado'] ),
			'cancelado'     => absint( $counts['cancelado'] ),
			'alerts'        => absint( $alerts ),
		);
	}

	/**
	 * Detecta alertas de configuración mínima.
	 *
	 * @param int $cohort_id Cohorte.
	 * @return bool
	 */
	public function has_configuration_alerts( $cohort_id ) {
		$cohort_id = absint( $cohort_id );
		if ( ! $cohort_id ) {
			return false;
		}
		$has_courses  = ! empty( $this->get_cohort_course_ids( $cohort_id ) );
		$has_teachers = ! empty( $this->get_cohort_teacher_ids( $cohort_id ) );
		$has_students = ! empty( $this->get_cohort_student_ids( $cohort_id ) );
		return ! ( $has_courses && $has_teachers && $has_students );
	}

	/**
	 * Opciones para filtros de cohorte (admin/speedgrade).
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,string>
	 */
	public function get_cohort_options_for_user( $user_id = 0 ) {
		$cohort_ids = $this->get_visible_cohort_ids( $user_id, array( 'proximo', 'activo', 'en_cierre', 'finalizado' ), 120 );
		$options = array();
		foreach ( $cohort_ids as $cohort_id ) {
			$title = get_the_title( $cohort_id );
			if ( '' === trim( (string) $title ) ) {
				$title = '#' . absint( $cohort_id );
			}
			$options[ absint( $cohort_id ) ] = sanitize_text_field( (string) $title );
		}
		return $options;
	}

	/**
	 * Conteo de evaluaciones pendientes para cohorte.
	 *
	 * @param array<int,int> $course_ids  Cursos.
	 * @param array<int,int> $student_ids Estudiantes.
	 * @return int
	 */
	protected function count_pending_evaluations( $course_ids, $student_ids ) {
		$course_ids  = $this->normalize_ids( $course_ids );
		$student_ids = $this->normalize_ids( $student_ids );
		if ( empty( $course_ids ) || empty( $student_ids ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'clms_submission',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => '_clms_submission_course_id',
						'value'   => $course_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_clms_submission_user_id',
						'value'   => $student_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_clms_submission_status',
						'value'   => array( 'submitted', 'in_review', 'needs_revision', 'updated' ),
						'compare' => 'IN',
					),
				),
			)
		);

		return absint( $query->found_posts );
	}

	/**
	 * Normaliza arrays de IDs.
	 *
	 * @param mixed $values Valores.
	 * @return array<int,int>
	 */
	protected function normalize_ids( $values ) {
		$values = is_array( $values ) ? $values : array();
		$values = array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
		return $values;
	}
}
