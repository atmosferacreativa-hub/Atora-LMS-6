<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_REST_Gradebook_Controller {

	/** @var CLMS_REST_Permissions */
	protected $permissions;

	/** @var CLMS_Gradebook_Service */
	protected $gradebook_service;

	/** @var CLMS_Gradebook_Schema_Service */
	protected $schema_service;

	/** @var CLMS_Gradebook_Save_Service */
	protected $save_service;

	/** @var CLMS_Gradebook_Export_Service|null */
	protected $export_service;

	public function __construct( CLMS_REST_Permissions $permissions ) {
		$this->permissions      = $permissions;
		$this->gradebook_service = class_exists( 'CLMS_Gradebook_Service' ) ? new CLMS_Gradebook_Service() : null;
		$this->schema_service    = class_exists( 'CLMS_Gradebook_Schema_Service' ) ? new CLMS_Gradebook_Schema_Service() : null;
		$this->save_service      = class_exists( 'CLMS_Gradebook_Save_Service' ) ? new CLMS_Gradebook_Save_Service() : null;
		$this->export_service    = class_exists( 'CLMS_Gradebook_Export_Service' ) ? new CLMS_Gradebook_Export_Service() : null;
	}

	/**
	 * GET /gradebook/{course_id}
	 */
	public function get_gradebook_grid( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_gradebook_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->gradebook_service || ! method_exists( $this->gradebook_service, 'build_grid' ) ) {
			return new WP_Error( 'clms_gradebook_service_unavailable', __( 'El servicio de gradebook no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$args = $this->sanitize_grid_args( $request );
		$grid = $this->gradebook_service->build_grid( $course_id, $args );

		$response = array(
			'course_id' => $course_id,
			'grid'      => is_array( $grid ) ? $grid : array(),
		);

		$response = apply_filters( 'clms_gradebook_rest_grid_response', $response, $course_id, $args, $request );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * GET /gradebook/schema/{course_id}
	 */
	public function get_gradebook_schema( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_gradebook_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->schema_service || ! method_exists( $this->schema_service, 'get_schema' ) ) {
			return new WP_Error( 'clms_gradebook_schema_unavailable', __( 'El esquema del gradebook no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$args     = $this->sanitize_grid_args( $request );
		$schema   = $this->schema_service->get_schema( $course_id, $args );
		$response = array(
			'course_id' => $course_id,
			'schema'    => is_array( $schema ) ? $schema : array(),
		);

		$response = apply_filters( 'clms_gradebook_rest_schema_response', $response, $course_id, $args, $request );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * GET /gradebook/summary/{course_id}
	 */
	public function get_gradebook_summary( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_gradebook_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->gradebook_service || ! method_exists( $this->gradebook_service, 'build_grid' ) ) {
			return new WP_Error( 'clms_gradebook_service_unavailable', __( 'El servicio de gradebook no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$args = $this->sanitize_grid_args( $request );
		$grid = (array) $this->gradebook_service->build_grid( $course_id, $args );

		$rows    = is_array( $grid['rows'] ?? null ) ? $grid['rows'] : array();
		$columns = is_array( $grid['columns'] ?? null ) ? $grid['columns'] : array();
		$pending = 0;
		$total   = 0;

		foreach ( $rows as $row ) {
			$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			foreach ( $cells as $cell ) {
				$cell = is_array( $cell ) ? $cell : array();
				$status = sanitize_key( (string) ( $cell['status'] ?? '' ) );
				if ( in_array( $status, array( 'submitted', 'in_review', 'needs_revision', 'returned' ), true ) ) {
					++$pending;
				}
				if ( '' !== (string) ( $cell['grade'] ?? '' ) ) {
					$total += absint( $cell['grade'] );
				}
			}
		}

		$avg = 0;
		$graded_cells = max( 1, $this->count_graded_cells( $rows ) );
		if ( $total > 0 ) {
			$avg = (float) round( $total / $graded_cells, 2 );
		}

		return new WP_REST_Response(
			array(
				'course_id'          => $course_id,
				'students'           => count( $rows ),
				'activities'         => count( $columns ),
				'pending_submissions' => $pending,
				'average_grade'      => $avg,
			),
			200
		);
	}

	/**
	 * GET /gradebook/export/{course_id}
	 * Devuelve el CSV del gradebook como string en el response body.
	 */
	public function export_csv( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_gradebook_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->gradebook_service || ! $this->export_service ) {
			return new WP_Error( 'clms_gradebook_export_unavailable', __( 'El servicio de exportación no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$args = $this->sanitize_grid_args( $request );
		$grid = $this->gradebook_service->build_grid( $course_id, $args );
		$csv  = $this->export_service->build_csv_string( $grid, array( 'course_id' => $course_id ) );

		// P5 (6.12.0): punto de instrumentación real para el log de
		// auditoría institucional — esta es la ruta que de verdad usa el
		// front-end para exportar, a diferencia de stream_csv() (sin
		// llamadores activos hoy).
		do_action( 'atora/gradebook/exported', get_current_user_id(), $course_id, count( $grid['rows'] ?? array() ) );

		return new WP_REST_Response(
			array(
				'course_id' => $course_id,
				'csv'       => $csv,
				'rows'      => count( $grid['rows'] ?? array() ),
			),
			200
		);
	}

	/**
	 * POST /gradebook/batch-update
	 * Guarda un lote de notas desde la grilla.
	 */
	public function batch_update( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_gradebook_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->save_service ) {
			return new WP_Error( 'clms_gradebook_save_unavailable', __( 'El servicio de guardado no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$raw_updates = $request->get_param( 'updates' );
		if ( ! is_array( $raw_updates ) || empty( $raw_updates ) ) {
			return new WP_Error( 'clms_gradebook_no_updates', __( 'No hay actualizaciones para procesar.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$updates = array();
		foreach ( $raw_updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}
			$updates[] = array(
				'student_id'    => absint( $update['student_id'] ?? 0 ),
				'lesson_id'     => absint( $update['lesson_id'] ?? 0 ),
				'submission_id' => absint( $update['submission_id'] ?? 0 ),
				'grade'         => isset( $update['grade'] ) && '' !== (string) $update['grade'] ? absint( $update['grade'] ) : '',
				'feedback'      => isset( $update['feedback'] ) ? wp_kses_post( (string) $update['feedback'] ) : '',
			);
		}

		$result = $this->save_service->batch_update( $course_id, $updates, get_current_user_id() );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /gradebook/scheme/{course_id}
	 * Guarda el esquema de grupos y ponderaciones.
	 */
	public function save_gradebook_scheme( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_gradebook_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->schema_service || ! method_exists( $this->schema_service, 'save_scheme' ) ) {
			return new WP_Error( 'clms_gradebook_schema_unavailable', __( 'El servicio de esquema no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$groups = $request->get_param( 'groups' );
		if ( ! is_array( $groups ) ) {
			return new WP_Error( 'clms_gradebook_invalid_groups', __( 'Grupos no válidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$groups_clean = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$groups_clean[] = array(
				'key'         => sanitize_key( (string) ( $group['key'] ?? '' ) ),
				'label'       => sanitize_text_field( (string) ( $group['label'] ?? '' ) ),
				'weight'      => max( 0.0, min( 100.0, (float) ( $group['weight'] ?? 0 ) ) ),
				'drop_lowest' => max( 0, absint( $group['drop_lowest'] ?? 0 ) ),
			);
		}

		$result = $this->schema_service->save_scheme( $course_id, $groups_clean );

		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'clms_gradebook_save_scheme_failed', $result['error'] ?? __( 'Error al guardar el esquema.', 'atora-lms' ), array( 'status' => 422 ) );
		}

		$schema   = $this->schema_service->get_schema( $course_id );
		$response = array(
			'course_id'   => $course_id,
			'schema'      => $schema,
			'weights_sum' => $result['weights_sum'] ?? 0.0,
			'warning'     => $result['warning'] ?? '',
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * GET /gradebook/cell-detail
	 * Obtiene detalle de una celda: resumen de entrega, rúbrica, feedback e historial.
	 */
	public function get_cell_detail( WP_REST_Request $request ) {
		$submission_id = absint( $request->get_param( 'submission_id' ) );
		$lesson_id     = absint( $request->get_param( 'lesson_id' ) );
		$student_id    = absint( $request->get_param( 'student_id' ) );
		$course_id     = absint( $request->get_param( 'course_id' ) );

		if ( ! $submission_id && ( ! $lesson_id || ! $student_id ) ) {
			return new WP_Error( 'clms_gradebook_cell_missing_params', __( 'Parámetros insuficientes.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$assessment = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Assessment_Engine') : null;

		if ( ! $submission_id && $lesson_id && $student_id && $assessment && method_exists( $assessment, 'get_student_lesson_submission_id' ) ) {
			$submission_id = absint( $assessment->get_student_lesson_submission_id( $student_id, $lesson_id ) );
		}

		if ( ! $submission_id ) {
			return new WP_REST_Response( array( 'found' => false, 'lesson_id' => $lesson_id, 'student_id' => $student_id ), 200 );
		}

		$grade_record = ( $assessment && method_exists( $assessment, 'get_submission_grade_record' ) )
			? (array) $assessment->get_submission_grade_record( $submission_id )
			: array();
		$audit_log    = ( $assessment && method_exists( $assessment, 'get_submission_audit_log' ) )
			? (array) $assessment->get_submission_audit_log( $submission_id )
			: array();

		$rubric_id = $lesson_id ? absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ) : 0;

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		$speedgrade_url = '';
		if ( $grading && method_exists( $grading, 'get_speedgrade_url' ) ) {
			$return = $course_id
				? admin_url( 'admin.php?page=clms-gradebook&course_id=' . $course_id )
				: admin_url( 'admin.php?page=clms-gradebook' );
			$speedgrade_url = (string) $grading->get_speedgrade_url( $submission_id, $return );
		}

		$raw_status = sanitize_key( (string) ( $grade_record['status'] ?? get_post_meta( $submission_id, '_clms_submission_status', true ) ) );
		$status_normalized = class_exists( 'CLMS_Academic_Status_Map' )
			? CLMS_Academic_Status_Map::normalize( $raw_status )
			: $raw_status;
		$status_label = class_exists( 'CLMS_Academic_Status_Map' )
			? CLMS_Academic_Status_Map::label( $raw_status )
			: $raw_status;

		// Audit log propio del Gradebook + log de Assessment Engine.
		$gradebook_audit = class_exists( 'CLMS_Gradebook_Audit_Service' )
			? CLMS_Gradebook_Audit_Service::get_log( $submission_id, 5 )
			: array();
		$engine_audit    = array_slice( is_array( $audit_log ) ? $audit_log : array(), -3 );

		$detail = array(
			'found'          => true,
			'submission_id'  => $submission_id,
			'lesson_id'      => $lesson_id ?: absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ),
			'student_id'     => $student_id ?: absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) ),
			'grade'          => $grade_record['grade'] ?? '',
			'status'         => $status_normalized,
			'status_label'   => $status_label,
			'feedback'       => wp_kses_post( (string) ( $grade_record['feedback'] ?? get_post_meta( $submission_id, '_clms_submission_feedback', true ) ) ),
			'rubric_id'      => $rubric_id,
			'source'         => sanitize_key( (string) ( $grade_record['grade_source'] ?? '' ) ),
			'manual_override' => ! empty( $grade_record['manual_override'] ),
			'audit_log'      => array_merge( $gradebook_audit, $engine_audit ),
			'speedgrade_url' => $speedgrade_url,
		);

		$detail = apply_filters( 'clms_gradebook_cell_detail', $detail, $submission_id, $request );

		return new WP_REST_Response( $detail, 200 );
	}

	/**
	 * Permission callback para guardar el esquema.
	 */
	public function can_manage_gradebook( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );

		// Usa ATORA_Security si está disponible.
		if ( class_exists( 'ATORA_Security' ) ) {
			if ( ! ATORA_Security::can_manage_gradebook() ) {
				return new WP_Error( 'rest_forbidden', __( 'No tienes permisos para modificar el gradebook.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			if ( $course_id && ! current_user_can( 'manage_options' ) ) {
				if ( $this->permissions && method_exists( $this->permissions, 'current_user_can_manage_post_resource' ) ) {
					if ( ! $this->permissions->current_user_can_manage_post_resource( $course_id ) ) {
						return new WP_Error( 'rest_forbidden', __( 'No puedes modificar este curso.', 'atora-lms' ), array( 'status' => 403 ) );
					}
				}
			}
			return true;
		}

		// Fallback sin ATORA_Security.
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( ! class_exists( 'CLMS_Access' ) || ! CLMS_Access::can_grade_submissions() ) {
				return new WP_Error( 'rest_forbidden', __( 'No tienes permisos para modificar el gradebook.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			if ( $course_id && $this->permissions && method_exists( $this->permissions, 'current_user_can_manage_post_resource' ) ) {
				if ( ! $this->permissions->current_user_can_manage_post_resource( $course_id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'No puedes modificar este curso.', 'atora-lms' ), array( 'status' => 403 ) );
				}
			}
		}
		return true;
	}

	/**
	 * Permission callback para endpoints de gradebook.
	 */
	public function can_view_gradebook( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_gradebook_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$can_grade = class_exists( 'ATORA_Security' )
			? ATORA_Security::can_manage_gradebook()
			: ( class_exists( 'CLMS_Access' ) && CLMS_Access::can_grade_submissions() );

		if ( ! $can_grade ) {
			return new WP_Error( 'rest_forbidden', __( 'No tienes permisos para ver el gradebook.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( $this->permissions && method_exists( $this->permissions, 'current_user_can_manage_post_resource' ) ) {
			if ( ! $this->permissions->current_user_can_manage_post_resource( $course_id ) ) {
				return new WP_Error( 'rest_forbidden', __( 'No puedes acceder a este curso.', 'atora-lms' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Sanitiza argumentos de consulta del grid.
	 */
	protected function sanitize_grid_args( WP_REST_Request $request ) {
		return array(
			'student_search'  => sanitize_text_field( (string) $request->get_param( 'student_search' ) ),
			'activity_search' => sanitize_text_field( (string) $request->get_param( 'activity_search' ) ),
			'status'          => sanitize_key( (string) $request->get_param( 'status' ) ),
			'group'           => sanitize_key( (string) $request->get_param( 'group' ) ),
			'cohort_id'       => absint( $request->get_param( 'cohort_id' ) ),
		);
	}

	/**
	 * Cuenta celdas con nota registrada.
	 */
	protected function count_graded_cells( $rows ) {
		$rows  = is_array( $rows ) ? $rows : array();
		$count = 0;

		foreach ( $rows as $row ) {
			$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			foreach ( $cells as $cell ) {
				if ( '' !== (string) ( $cell['grade'] ?? '' ) ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
