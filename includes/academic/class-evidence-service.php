<?php
/**
 * Servicio de evidencias académicas por actividad/lección.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Evidence_Service {

	const META_TYPE               = '_clms_evidence_type';
	const META_REQUIRED_CERT      = '_clms_evidence_required_for_certificate';
	const META_COMPETENCY_IDS     = '_clms_evidence_competency_ids';
	const META_MINIMUM_GRADE      = '_clms_evidence_minimum_grade';
	const META_ALLOW_RESUBMISSION = '_clms_evidence_allow_resubmission';
	const META_READ_REQUIREMENT   = '_clms_evidence_read_requirement';
	const USER_META_READ_LOG      = '_clms_read_evidence_log';

	/**
	 * Configuración de evidencia por actividad/lección.
	 *
	 * @param int $activity_id Actividad (lección).
	 * @return array<string,mixed>
	 */
	public function get_activity_evidence_config( $activity_id ) {
		$activity_id = absint( $activity_id );
		if ( ! $activity_id ) {
			return $this->get_default_config();
		}

		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $activity_id ) ) : 0;
		$type      = sanitize_key( (string) get_post_meta( $activity_id, self::META_TYPE, true ) );
		$required  = '1' === (string) get_post_meta( $activity_id, self::META_REQUIRED_CERT, true );
		$min_grade = absint( get_post_meta( $activity_id, self::META_MINIMUM_GRADE, true ) );
		$resub     = get_post_meta( $activity_id, self::META_ALLOW_RESUBMISSION, true );
		$resub     = '' === (string) $resub ? true : ( '1' === (string) $resub );
		$raw_ids   = get_post_meta( $activity_id, self::META_COMPETENCY_IDS, true );
		$ids       = is_array( $raw_ids ) ? $raw_ids : array();
		$ids       = array_values( array_filter( array_map( 'sanitize_key', $ids ) ) );

		if ( empty( $ids ) ) {
			$legacy = (string) get_post_meta( $activity_id, '_clms_lesson_competencies', true );
			$ids    = $this->map_legacy_competencies_to_ids( $legacy, $course_id );
		}

		$read_requirement = sanitize_key( (string) get_post_meta( $activity_id, self::META_READ_REQUIREMENT, true ) );
		$allowed_read_requirements = array( 'seen', 'comment', 'seen_or_comment' );
		if ( ! in_array( $read_requirement, $allowed_read_requirements, true ) ) {
			$read_requirement = 'seen_or_comment';
		}

		$allowed_types = array( 'practice', 'assignment', 'partial_exam', 'final_exam', 'certifiable_evidence', 'required', 'read_only' );
		if ( '' === $type ) {
			$activity_mode = sanitize_key( (string) get_post_meta( $activity_id, 'lm_activity_type', true ) );
			if ( in_array( $activity_mode, array( 'quiz', 'tarea' ), true ) ) {
				$type = 'assignment';
			} elseif ( in_array( $activity_mode, array( 'lectura', 'reading' ), true ) ) {
				$type = 'read_only';
			} else {
				$type = 'practice';
			}
		}
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'practice';
		}

		return array(
			'activity_id'                  => $activity_id,
			'course_id'                    => $course_id,
			'evidence_type'                => $type,
			'is_required_for_certificate'  => $required,
			'competency_ids'               => $ids,
			'minimum_grade'                => max( 0, min( 100, $min_grade ) ),
			'allow_resubmission'           => (bool) $resub,
			'read_requirement'             => $read_requirement,
		);
	}

	/**
	 * Determina si la actividad es evidencia obligatoria.
	 *
	 * @param int $activity_id Actividad.
	 * @return bool
	 */
	public function is_required_evidence( $activity_id ) {
		$config = $this->get_activity_evidence_config( $activity_id );
		return ! empty( $config['is_required_for_certificate'] );
	}

	/**
	 * Evidencias obligatorias del curso.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_course_required_evidences( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$lesson_ids = (array) CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$list = array();
		foreach ( $lesson_ids as $lesson_id ) {
			$config = $this->get_activity_evidence_config( $lesson_id );
			if ( empty( $config['is_required_for_certificate'] ) ) {
				continue;
			}
			$config['activity_title'] = get_the_title( $lesson_id );
			$list[] = $config;
		}

		return $list;
	}

	/**
	 * Estado de evidencias del estudiante por curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_student_evidence_status( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$lesson_ids = (array) CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$list = array();
		foreach ( $lesson_ids as $lesson_id ) {
			$config = $this->get_activity_evidence_config( $lesson_id );
			$activity_mode = sanitize_key( (string) get_post_meta( $lesson_id, 'lm_activity_type', true ) );
			$is_evidence = ! empty( $config['is_required_for_certificate'] ) || 'practice' !== $config['evidence_type'] || in_array( $activity_mode, array( 'tarea', 'quiz' ), true );
			if ( ! $is_evidence ) {
				continue;
			}

			$submission = $this->get_latest_submission_for_user_activity( $user_id, $lesson_id );
			$status     = isset( $submission['status'] ) ? sanitize_key( (string) $submission['status'] ) : '';
			$grade      = isset( $submission['grade'] ) && '' !== (string) $submission['grade'] && is_numeric( $submission['grade'] )
				? max( 0, min( 100, absint( $submission['grade'] ) ) )
				: null;
			$min_grade  = absint( $config['minimum_grade'] ?? 0 );

			$read_record = array();
			if ( 'read_only' === $config['evidence_type'] ) {
				$read_record = $this->get_read_evidence_record( $user_id, $lesson_id );
			}

			$approved = false;
			if ( 'read_only' === $config['evidence_type'] ) {
				$approved = $this->is_read_evidence_approved(
					$read_record,
					isset( $config['read_requirement'] ) ? (string) $config['read_requirement'] : 'seen_or_comment'
				);
			} elseif ( ! empty( $config['is_required_for_certificate'] ) ) {
				$approved = in_array( $status, array( 'graded' ), true ) && null !== $grade && $grade >= $min_grade;
			} else {
				$approved = in_array( $status, array( 'graded', 'submitted', 'in_review' ), true );
			}

			$list[] = array(
				'activity_id'                  => $lesson_id,
				'activity_title'               => get_the_title( $lesson_id ),
				'evidence_type'                => sanitize_key( (string) ( $config['evidence_type'] ?? 'practice' ) ),
				'is_required_for_certificate'  => ! empty( $config['is_required_for_certificate'] ),
				'competency_ids'               => isset( $config['competency_ids'] ) && is_array( $config['competency_ids'] ) ? $config['competency_ids'] : array(),
				'minimum_grade'                => $min_grade,
				'allow_resubmission'           => ! empty( $config['allow_resubmission'] ),
				'submission_id'                => absint( $submission['submission_id'] ?? 0 ),
				'status'                       => $status,
				'grade'                        => $grade,
				'read_seen'                    => ! empty( $read_record['seen'] ),
				'read_comment'                 => sanitize_text_field( (string) ( $read_record['comment'] ?? '' ) ),
				'read_requirement'             => sanitize_key( (string) ( $config['read_requirement'] ?? 'seen_or_comment' ) ),
				'approved'                     => $approved,
			);
		}

		return $list;
	}

	/**
	 * Config base.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_default_config() {
		return array(
			'activity_id'                 => 0,
			'course_id'                   => 0,
			'evidence_type'               => 'practice',
			'is_required_for_certificate' => false,
			'competency_ids'              => array(),
			'minimum_grade'               => 0,
			'allow_resubmission'          => true,
			'read_requirement'            => 'seen_or_comment',
		);
	}

	/**
	 * Registra evidencia de lectura para una lección.
	 *
	 * @param int    $user_id   Usuario.
	 * @param int    $lesson_id Lección.
	 * @param string $comment   Comentario.
	 * @return array<string,mixed>
	 */
	public function save_read_evidence( $user_id, $lesson_id, $comment = '' ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$comment   = sanitize_textarea_field( (string) $comment );
		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$log = get_user_meta( $user_id, self::USER_META_READ_LOG, true );
		$log = is_array( $log ) ? $log : array();
		$key = (string) $lesson_id;
		$current = isset( $log[ $key ] ) && is_array( $log[ $key ] ) ? $log[ $key ] : array();
		$current['seen']       = true;
		$current['comment']    = $comment;
		$current['updated_at'] = current_time( 'mysql' );
		$log[ $key ]           = $current;
		update_user_meta( $user_id, self::USER_META_READ_LOG, $log );

		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) ) : 0;
		do_action( 'clms_read_evidence_saved', $user_id, $lesson_id, $course_id, $current );

		return $current;
	}

	/**
	 * Obtiene el registro de lectura para una lección.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $lesson_id Lección.
	 * @return array<string,mixed>
	 */
	public function get_read_evidence_record( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$log = get_user_meta( $user_id, self::USER_META_READ_LOG, true );
		$log = is_array( $log ) ? $log : array();
		$key = (string) $lesson_id;
		$row = isset( $log[ $key ] ) && is_array( $log[ $key ] ) ? $log[ $key ] : array();
		return array(
			'seen'       => ! empty( $row['seen'] ),
			'comment'    => sanitize_textarea_field( (string) ( $row['comment'] ?? '' ) ),
			'updated_at' => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
		);
	}

	/**
	 * Determina si una evidencia de lectura cuenta como aprobada.
	 *
	 * @param array  $record      Registro.
	 * @param string $requirement Regla.
	 * @return bool
	 */
	protected function is_read_evidence_approved( $record, $requirement ) {
		$record      = is_array( $record ) ? $record : array();
		$requirement = sanitize_key( (string) $requirement );
		$seen        = ! empty( $record['seen'] );
		$comment     = trim( (string) ( $record['comment'] ?? '' ) );
		$has_comment = '' !== $comment;

		if ( 'seen' === $requirement ) {
			return $seen;
		}
		if ( 'comment' === $requirement ) {
			return $has_comment;
		}
		return $seen || $has_comment;
	}

	/**
	 * Mapea texto legacy de competencias de lección a IDs de curso.
	 *
	 * @param string $legacy    Texto.
	 * @param int    $course_id Curso.
	 * @return array<int,string>
	 */
	protected function map_legacy_competencies_to_ids( $legacy, $course_id ) {
		$legacy    = (string) $legacy;
		$course_id = absint( $course_id );
		if ( '' === trim( $legacy ) ) {
			return array();
		}

		$competency_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		$course_competencies = ( $competency_service && method_exists( $competency_service, 'get_course_competencies' ) )
			? (array) $competency_service->get_course_competencies( $course_id )
			: array();
		$title_map = array();
		foreach ( $course_competencies as $competency ) {
			$title = isset( $competency['title'] ) ? sanitize_text_field( (string) $competency['title'] ) : '';
			$id    = isset( $competency['id'] ) ? sanitize_key( (string) $competency['id'] ) : '';
			if ( '' === $title || '' === $id ) {
				continue;
			}
			$title_map[ sanitize_title( $title ) ] = $id;
		}

		$lines = preg_split( '/\r\n|\r|\n/', $legacy );
		$ids   = array();
		foreach ( (array) $lines as $line ) {
			$value = sanitize_text_field( (string) $line );
			if ( '' === $value ) {
				continue;
			}
			$slug = sanitize_title( $value );
			$ids[] = isset( $title_map[ $slug ] ) ? $title_map[ $slug ] : sanitize_key( $slug );
		}

		return array_values( array_filter( array_unique( $ids ) ) );
	}

	/**
	 * Última entrega de un estudiante para actividad.
	 *
	 * @param int $user_id    Estudiante.
	 * @param int $lesson_id  Lección.
	 * @return array<string,mixed>
	 */
	protected function get_latest_submission_for_user_activity( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$submission = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Submission') : null;
		if ( $submission && method_exists( $submission, 'get_user_submission_for_grading' ) ) {
			$item = (array) $submission->get_user_submission_for_grading( $user_id, $lesson_id );
			if ( ! empty( $item ) ) {
				return array(
					'submission_id' => absint( $item['submission_id'] ?? 0 ),
					'status'        => sanitize_key( (string) ( $item['status'] ?? '' ) ),
					'grade'         => $item['grade'] ?? '',
				);
			}
		}

		$ids = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_lesson_id',
						'value' => $lesson_id,
						'type'  => 'NUMERIC',
					),
				),
				'no_found_rows' => true,
			)
		);

		if ( empty( $ids[0] ) ) {
			return array();
		}

		$submission_id = absint( $ids[0] );
		return array(
			'submission_id' => $submission_id,
			'status'        => sanitize_key( (string) get_post_meta( $submission_id, '_clms_submission_status', true ) ),
			'grade'         => get_post_meta( $submission_id, '_clms_submission_grade', true ),
		);
	}
}
