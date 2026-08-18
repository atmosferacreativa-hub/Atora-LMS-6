<?php
/**
 * Emisión de certificados por programa.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Program_Certificate_Service {

	const CERT_META_PREFIX = '_clms_program_certificate_record_';

	/**
	 * Intenta emitir certificados de programa cuando se completa un curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso completado.
	 * @return array<int,array<string,mixed>>
	 */
	public function maybe_issue_from_course_completion( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$program_ids = CLMS_Helper::get_course_program_ids( $course_id );
		if ( empty( $program_ids ) ) {
			return array();
		}

		$issued = array();
		foreach ( $program_ids as $program_id ) {
			$record = $this->maybe_issue_program_certificate( $user_id, absint( $program_id ) );
			if ( ! empty( $record ) ) {
				$issued[] = $record;
			}
		}

		return $issued;
	}

	/**
	 * Emite certificado de programa si cumple reglas.
	 *
	 * @param int $user_id    Estudiante.
	 * @param int $program_id Programa.
	 * @return array<string,mixed>|false
	 */
	public function maybe_issue_program_certificate( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		if ( ! $user_id || ! $program_id || ! class_exists( 'CLMS_Helper' ) ) {
			return false;
		}
		if ( ! CLMS_Helper::user_is_enrolled_in_program( $user_id, $program_id ) && ! CLMS_Helper::user_can_manage_lms( $program_id ) ) {
			return false;
		}

		$existing = $this->get_program_certificate_record( $user_id, $program_id );
		if ( ! empty( $existing ) ) {
			return $existing;
		}

		$context = $this->build_program_context( $user_id, $program_id );

		$rules_service = clms_core('CLMS_Certificate_Rules');
		if ( ! $rules_service || ! method_exists( $rules_service, 'evaluate_program' ) ) {
			return false;
		}

		$eligibility = (array) $rules_service->evaluate_program( $user_id, $program_id, $context );
		if ( empty( $eligibility['eligible'] ) ) {
			return false;
		}

		$record = array(
			'certificate_code'  => $this->generate_program_certificate_code( $user_id, $program_id ),
			'verification_code' => wp_generate_password( 20, false, false ),
			'verification_hash' => '',
			'issued_at'         => current_time( 'mysql' ),
			'issue_date'        => current_time( 'Y-m-d' ),
			'status'            => 'valid',
			'type'              => 'program',
			'student_id'        => $user_id,
			'program_id'        => $program_id,
			'target_type'       => 'program',
			'target_id'         => $program_id,
			'student_name'      => $this->get_frozen_student_name( $user_id ),
			'target_title'      => get_the_title( $program_id ),
			'final_average'     => absint( $eligibility['final_average'] ?? 0 ),
			'progress_percent'  => absint( $eligibility['progress_percent'] ?? 0 ),
			'academy'           => get_bloginfo( 'name' ),
			'template'          => 'program-default',
			'template_id'       => 'program-default',
			'revoked_at'        => '',
			'revocation_reason' => '',
			'issued_by'         => get_current_user_id() ? absint( get_current_user_id() ) : 0,
			'email_sent_at'     => '',
		);
		$record['verification_hash'] = hash_hmac( 'sha256', (string) $record['verification_code'], wp_salt( 'auth' ) );

		update_user_meta( $user_id, self::CERT_META_PREFIX . $program_id, $record );
		do_action( 'clms_program_certificate_issued', $record, $user_id, $program_id );
		do_action( 'clms_certificate_issued', $record, $user_id, 0 );

		return $record;
	}

	/**
	 * Obtiene certificado de programa.
	 *
	 * @param int $user_id    Estudiante.
	 * @param int $program_id Programa.
	 * @return array<string,mixed>
	 */
	public function get_program_certificate_record( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		if ( ! $user_id || ! $program_id ) {
			return array();
		}

		$record = get_user_meta( $user_id, self::CERT_META_PREFIX . $program_id, true );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Calcula estado agregado del programa.
	 *
	 * @param int $user_id    Estudiante.
	 * @param int $program_id Programa.
	 * @return array<string,mixed>
	 */
	public function build_program_context( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );

		$course_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_program_courses( $program_id ) : array();
		$course_ids = is_array( $course_ids ) ? array_values( array_filter( array_map( 'absint', $course_ids ) ) ) : array();

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;

		$completed = 0;
		$total     = count( $course_ids );
		$progress  = 0;
		$average   = 0;
		$hours     = 0;

		if ( ! empty( $course_ids ) && $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
			$sum_progress = 0;
			$sum_average  = 0;
			$count_avg    = 0;

			foreach ( $course_ids as $course_id ) {
				$summary = (array) $grading->get_course_grade_summary( $user_id, $course_id );
				$item_progress = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
				$item_average  = isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;

				$sum_progress += $item_progress;
				if ( $item_average > 0 ) {
					$sum_average += $item_average;
					++$count_avg;
				}

				if ( $item_progress >= 100 ) {
					++$completed;
				}

				$hours += absint( get_post_meta( $course_id, '_clms_course_hours', true ) );
			}

			$progress = (int) round( $sum_progress / max( 1, $total ) );
			$average  = $count_avg > 0 ? (int) round( $sum_average / $count_avg ) : 0;
		}

		return array(
			'total_courses'     => $total,
			'completed_courses' => $completed,
			'progress_percent'  => max( 0, min( 100, $progress ) ),
			'final_average'     => max( 0, min( 100, $average ) ),
			'academic_hours'    => max( 0, $hours ),
		);
	}

	protected function get_frozen_student_name( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( $user && ! empty( $user->display_name ) ) {
			return sanitize_text_field( (string) $user->display_name );
		}
		return '';
	}

	protected function generate_program_certificate_code( $user_id, $program_id ) {
		$counter = absint( get_option( 'clms_certificate_code_counter', 0 ) );
		$counter = max( 0, $counter ) + 1;
		update_option( 'clms_certificate_code_counter', $counter, false );

		return sprintf( 'ATORA-PROG-%s-%06d', gmdate( 'Y' ), $counter );
	}
}
