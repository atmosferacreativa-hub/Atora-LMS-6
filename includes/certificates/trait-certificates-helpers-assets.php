<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Certificates_Helpers_Assets_Trait {
	protected function find_certificate_by_verification_code( $code ) {
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code ) {
			return array();
		}

		$index = get_option( self::VERIFY_INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		if ( empty( $index[ $code ] ) || ! is_array( $index[ $code ] ) ) {
			return array();
		}
		$user_id     = isset( $index[ $code ]['user_id'] ) ? absint( $index[ $code ]['user_id'] ) : 0;
		$target_type = isset( $index[ $code ]['target_type'] ) ? sanitize_key( (string) $index[ $code ]['target_type'] ) : 'course';
		$target_id   = isset( $index[ $code ]['target_id'] ) ? absint( $index[ $code ]['target_id'] ) : 0;

		$course_id = 0;
		$program_id = 0;
		$record = array();

		if ( 'program' === $target_type ) {
			$program_id = $target_id;
			$program_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Program_Certificate_Service') : null;
			if ( $program_service && method_exists( $program_service, 'get_program_certificate_record' ) ) {
				$record = (array) $program_service->get_program_certificate_record( $user_id, $program_id );
			}
		} else {
			$course_id = $target_id > 0 ? $target_id : ( isset( $index[ $code ]['course_id'] ) ? absint( $index[ $code ]['course_id'] ) : 0 );
			$record    = $this->get_certificate_record( $user_id, $course_id );
		}

		if ( empty( $record ) || empty( $record['verification_code'] ) || ! hash_equals( (string) $record['verification_code'], $code ) ) {
			return array();
		}

		return array(
			'user_id'     => $user_id,
			'course_id'   => $course_id,
			'program_id'  => $program_id,
			'target_type' => $target_type,
			'target_id'   => $target_id,
			'record'      => $record,
		);
	}

	protected function index_certificate_verification_code( $code, $user_id, $target_id, $target_type = 'course' ) {
		$code        = sanitize_text_field( (string) $code );
		$user_id     = absint( $user_id );
		$target_id   = absint( $target_id );
		$target_type = sanitize_key( (string) $target_type );
		if ( '' === $code || ! $user_id || ! $target_id ) {
			return;
		}
		if ( ! in_array( $target_type, array( 'course', 'program' ), true ) ) {
			$target_type = 'course';
		}
		$index = get_option( self::VERIFY_INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		$index[ $code ] = array(
			'user_id'     => $user_id,
			'target_id'   => $target_id,
			'target_type' => $target_type,
			'course_id'   => 'course' === $target_type ? $target_id : 0,
			'program_id'  => 'program' === $target_type ? $target_id : 0,
			'updated_at'  => current_time( 'mysql' ),
		);
		update_option( self::VERIFY_INDEX_OPTION, $index, false );
	}

	protected function get_frozen_student_name( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( $user && ! empty( $user->display_name ) ) {
			return sanitize_text_field( (string) $user->display_name );
		}
		return '';
	}

	/**
	 * Nombre visible del certificado de curso (permite override por meta de curso).
	 *
	 * @param int $course_id Curso.
	 * @return string
	 */
	protected function get_certificate_target_title( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return '';
		}

		$custom = sanitize_text_field( (string) get_post_meta( $course_id, '_clms_course_certificate', true ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		return sanitize_text_field( (string) get_the_title( $course_id ) );
	}

	protected function get_rules_service() {
		if ( class_exists( 'CLMS_Helper' ) ) {
			$service = clms_core('CLMS_Certificate_Rules');
			if ( $service ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * Servicio de presentación de certificados.
	 *
	 * @return CLMS_Certificate_Presentation_Service|null
	 */
	protected function get_presentation_service() {
		if ( class_exists( 'CLMS_Helper' ) ) {
			$service = clms_core('CLMS_Certificate_Presentation_Service');
			if ( $service ) {
				return $service;
			}
		}

		return class_exists( 'CLMS_Certificate_Presentation_Service' ) ? new CLMS_Certificate_Presentation_Service() : null;
	}

	/**
	 * Obtiene registro de certificado de programa de forma segura.
	 *
	 * @param int $user_id    Estudiante.
	 * @param int $program_id Programa.
	 * @return array<string,mixed>
	 */
	protected function get_program_certificate_record_safe( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		if ( ! $user_id || ! $program_id ) {
			return array();
		}

		$service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Program_Certificate_Service') : null;
		if ( $service && method_exists( $service, 'get_program_certificate_record' ) ) {
			$record = $service->get_program_certificate_record( $user_id, $program_id );
			return is_array( $record ) ? $record : array();
		}

		return array();
	}

	/**
	 * Guarda registro de certificado de programa de forma segura.
	 *
	 * @param int   $user_id    Estudiante.
	 * @param int   $program_id Programa.
	 * @param array $record     Registro.
	 * @return void
	 */
	protected function save_program_certificate_record_safe( $user_id, $program_id, $record ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		$record     = is_array( $record ) ? $record : array();
		if ( ! $user_id || ! $program_id || empty( $record ) ) {
			return;
		}

		update_user_meta( $user_id, CLMS_Program_Certificate_Service::CERT_META_PREFIX . $program_id, $record );
	}

	/**
	 * Resumen académico enriquecido para credencial.
	 *
	 * @param int   $course_id    Curso.
	 * @param array $eligibility  Resultado reglas.
	 * @return array<string,mixed>
	 */
	protected function build_certificate_academic_summary( $course_id, $eligibility ) {
		$course_id   = absint( $course_id );
		$eligibility = is_array( $eligibility ) ? $eligibility : array();

		$required_competencies = isset( $eligibility['required_competencies'] ) && is_array( $eligibility['required_competencies'] )
			? array_values( array_filter( array_map( 'sanitize_key', $eligibility['required_competencies'] ) ) )
			: array();
		$completed_competencies = isset( $eligibility['completed_competencies'] ) && is_array( $eligibility['completed_competencies'] )
			? array_values( array_filter( array_map( 'sanitize_key', $eligibility['completed_competencies'] ) ) )
			: array();

		$presentation = $this->get_presentation_service();
		$competencies_certified = $presentation && method_exists( $presentation, 'get_course_competency_labels' )
			? $presentation->get_course_competency_labels( $course_id, $completed_competencies, 8 )
			: array();

		$competencies_required_labels = $presentation && method_exists( $presentation, 'get_course_competency_labels' )
			? $presentation->get_course_competency_labels( $course_id, $required_competencies, 8 )
			: array();

		$hours = absint( get_post_meta( $course_id, '_clms_course_hours', true ) );
		$required_evidences_count = isset( $eligibility['required_evidences'] ) && is_array( $eligibility['required_evidences'] ) ? count( $eligibility['required_evidences'] ) : 0;
		$completed_evidences_count = isset( $eligibility['completed_evidences'] ) && is_array( $eligibility['completed_evidences'] ) ? count( $eligibility['completed_evidences'] ) : 0;

		return array(
			'academic_hours'               => $hours,
			'required_evidences_count'     => absint( $required_evidences_count ),
			'completed_evidences_count'    => absint( $completed_evidences_count ),
			'required_competencies_count'  => count( $required_competencies ),
			'completed_competencies_count' => count( $completed_competencies ),
			'competencies_certified'       => array_values( array_map( 'sanitize_text_field', $competencies_certified ) ),
			'competencies_required_labels' => array_values( array_map( 'sanitize_text_field', $competencies_required_labels ) ),
		);
	}

	/**
	 * Obtiene resumen de grading.
	 */
	protected function get_course_summary( $user_id, $course_id ) {
		$grading = $this->get_grading_instance();

		if ( $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
			return $grading->get_course_grade_summary( $user_id, $course_id );
		}

		return array(
			'completed_lessons' => 0,
			'total_lessons'     => 0,
			'progress_percent'  => 0,
			'final_average'     => 0,
		);
	}

	/**
	 * Intenta reutilizar la instancia cargada.
	 */
	protected function get_grading_instance() {
		if ( function_exists( 'clms_core' ) ) {
			$loader = clms_core();

			if ( $loader ) {
				if ( method_exists( $loader, 'get_module' ) ) {
					$grading = $loader->get_module( 'CLMS_Grading' );
					if ( $grading ) {
						return $grading;
					}
				}

				if ( method_exists( $loader, 'get' ) ) {
					$grading = $loader->get( 'CLMS_Grading' );
					if ( $grading ) {
						return $grading;
					}
				}
			}
		}

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' ) ) {
			$grading = clms_core('CLMS_Grading');
			if ( $grading ) {
				return $grading;
			}
		}

		return null;
	}

	/**
	 * Cursos inscritos del usuario.
	 */
	protected function get_user_enrolled_courses( $user_id ) {
		$user_id = absint( $user_id );

		$grading = $this->get_grading_instance();

		if ( $grading && method_exists( $grading, 'get_user_enrolled_courses' ) ) {
			return $grading->get_user_enrolled_courses( $user_id );
		}

		$courses = get_user_meta( $user_id, '_clms_enrolled_courses', true );

		if ( ! is_array( $courses ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'absint', $courses ) ) );
	}

	/**
	 * Valida inscripción.
	 */
	protected function user_is_enrolled_in_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		$grading = $this->get_grading_instance();

		if ( $grading && method_exists( $grading, 'user_is_enrolled_in_course' ) ) {
			return $grading->user_is_enrolled_in_course( $user_id, $course_id );
		}

		$user_courses = get_user_meta( $user_id, '_clms_enrolled_courses', true );
		if ( is_array( $user_courses ) && in_array( $course_id, array_map( 'absint', $user_courses ), true ) ) {
			return true;
		}

		$course_users = get_post_meta( $course_id, '_clms_enrolled_users', true );
		if ( is_array( $course_users ) && in_array( $user_id, array_map( 'absint', $course_users ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * CSS frontend mínimo.
	 */
	protected function enqueue_assets() {
		wp_register_style( 'clms-certificates', false, array( 'clms-ui' ), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
		wp_enqueue_style( 'clms-certificates' );

		wp_add_inline_style(
			'clms-certificates',
			"
			.clms-certificate-wrap{margin:24px 0}
			.clms-certificate-list{
				display:grid;
				gap:16px;
				grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
			}
			.clms-certificate-card{
				background:#fff;
				border:1px solid #e5e7eb;
				border-radius:16px;
				padding:20px;
				display:grid;
				gap:16px;
				transition:box-shadow .18s;
			}
			.clms-certificate-card:hover{
				box-shadow:0 8px 24px rgba(0,0,0,.07);
			}
			.clms-certificate-card--eligible{
				border-color:#bbf7d0;
				background:linear-gradient(135deg,#f0fdf4,#fff);
			}
			.clms-certificate-card__header{
				display:flex;
				align-items:flex-start;
				gap:14px;
			}
			.clms-certificate-icon{
				width:36px;
				height:36px;
				flex-shrink:0;
				color:#6b7280;
			}
			.clms-certificate-card--eligible .clms-certificate-icon{
				color:#16a34a;
			}
			.clms-certificate-title{
				margin:0 0 4px;
				font-size:16px;
				font-weight:700;
				line-height:1.3;
				color:#111827;
			}
			.clms-certificate-status{
				display:inline-block;
				font-size:12px;
				font-weight:700;
				padding:2px 8px;
				border-radius:99px;
			}
			.clms-certificate-status--ok{
				background:#dcfce7;
				color:#166534;
			}
			.clms-certificate-status--no{
				background:#f3f4f6;
				color:#6b7280;
			}
			.clms-certificate-stats{
				display:flex;
				gap:16px;
			}
			.clms-certificate-stat{
				display:grid;
				gap:2px;
				text-align:center;
				flex:1;
				padding:10px;
				background:#f9fafb;
				border-radius:10px;
			}
			.clms-certificate-stat__val{
				font-size:22px;
				font-weight:800;
				color:#111827;
				line-height:1;
			}
			.clms-certificate-stat__lbl{
				font-size:11px;
				color:#6b7280;
				font-weight:600;
				text-transform:uppercase;
				letter-spacing:.04em;
			}
			.clms-certificate-btn{
				display:inline-flex;
				align-items:center;
				padding:10px 18px;
				border-radius:10px;
				background:#111827;
				color:#fff;
				text-decoration:none;
				font-size:14px;
				font-weight:600;
				transition:opacity .15s;
			}
			.clms-certificate-btn:hover{opacity:.85;color:#fff}
			.clms-certificate-btn--ghost{
				background:#eef2ff;
				color:#3730a3;
				margin-left:8px;
			}
			.clms-certificate-btn--ghost:hover{color:#3730a3;opacity:.9}
			.clms-certificate-progress{display:grid;gap:8px}
			.clms-certificate-progress__bar{
				height:6px;
				background:#e5e7eb;
				border-radius:99px;
				overflow:hidden;
			}
			.clms-certificate-progress__fill{
				height:100%;
				background:#4353ff;
				border-radius:99px;
				transition:width .5s ease;
			}
			.clms-certificate-hint{
				margin:0;
				font-size:12px;
				color:#6b7280;
			}
			"
		);
	}
}
