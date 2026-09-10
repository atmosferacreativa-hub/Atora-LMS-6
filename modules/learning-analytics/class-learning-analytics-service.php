<?php
/**
 * Learning Analytics — snapshots de riesgo por estudiante/curso.
 *
 * @package ATORA_LMS
 * @since   6.15.0
 */

namespace ATORA\LearningAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Learning_Analytics_Service {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_student_analytics';
	}

	/**
	 * Cursos donde un docente participa (autor o listado en meta de docentes).
	 *
	 * @param int $teacher_id
	 * @return array<int,int>
	 */
	public function get_course_ids_for_teacher( int $teacher_id ): array {
		$teacher_id = absint( $teacher_id );
		if ( ! $teacher_id ) {
			return array();
		}

		$course_ids = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'author'         => $teacher_id,
				'no_found_rows'  => true,
			)
		);

		$course_ids = array_values( array_filter( array_map( 'absint', (array) $course_ids ) ) );

		$meta_courses = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_clms_course_teacher_ids',
						'value'   => (string) $teacher_id,
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( (array) $meta_courses as $cid ) {
			$cid = absint( $cid );
			if ( $cid ) {
				$course_ids[] = $cid;
			}
		}

		$course_ids = array_values( array_unique( array_filter( $course_ids ) ) );
		sort( $course_ids );
		return $course_ids;
	}

	/**
	 * Cursos asociados a cohorte.
	 *
	 * @param int $cohort_id
	 * @return array<int,int>
	 */
	public function get_course_ids_for_cohort( int $cohort_id ): array {
		$cohort_id = absint( $cohort_id );
		if ( ! $cohort_id || ! class_exists( 'CLMS_Cohort_Service' ) ) {
			return array();
		}
		$service = new \CLMS_Cohort_Service();
		$ids     = $service->get_cohort_course_ids( $cohort_id );
		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Estudiantes asociados a cohorte.
	 *
	 * @param int $cohort_id
	 * @return array<int,int>
	 */
	public function get_student_ids_for_cohort( int $cohort_id ): array {
		$cohort_id = absint( $cohort_id );
		if ( ! $cohort_id || ! class_exists( 'CLMS_Cohort_Service' ) ) {
			return array();
		}
		$service = new \CLMS_Cohort_Service();
		$ids     = $service->get_cohort_student_ids( $cohort_id );
		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Valida acceso a un curso para viewer (admin/coordinación/docente).
	 *
	 * @param int $viewer_id
	 * @param int $course_id
	 * @return bool
	 */
	public function viewer_can_access_course( int $viewer_id, int $course_id ): bool {
		$viewer_id = absint( $viewer_id );
		$course_id = absint( $course_id );
		if ( ! $viewer_id || ! $course_id ) {
			return false;
		}

		if ( user_can( $viewer_id, 'manage_options' ) ) {
			return true;
		}

		$author = absint( get_post_field( 'post_author', $course_id ) );
		if ( $author && $author === $viewer_id ) {
			return true;
		}

		$teacher_ids = array();
		$raw = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\s*,\s*/', trim( $raw ) );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $tid ) {
				$tid = absint( $tid );
				if ( $tid ) {
					$teacher_ids[] = $tid;
				}
			}
		}

		return in_array( $viewer_id, array_values( array_unique( $teacher_ids ) ), true );
	}

	/**
	 * Calcula score 0–100 a partir de un status académico.
	 *
	 * @param array<string,mixed> $status
	 * @return int
	 */
	public static function calculate_risk_score_from_status( array $status ): int {
		$risk_level = sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) );
		$pending    = absint( $status['pending_activities'] ?? 0 );
		$progress   = absint( $status['progress_percent'] ?? 0 );
		$average    = isset( $status['final_average'] ) && is_numeric( $status['final_average'] ) ? absint( $status['final_average'] ) : null;
		$last_access_raw = sanitize_text_field( (string) ( $status['last_access_at'] ?? '' ) );

		$score = 0;
		if ( 'high' === $risk_level ) {
			$score = 90;
		} elseif ( 'medium' === $risk_level ) {
			$score = 60;
		} elseif ( 'normal' === $risk_level ) {
			$score = 20;
		} else {
			$score = 0;
		}

		if ( null !== $average ) {
			if ( $average > 0 && $average < 50 ) {
				$score = max( $score, 95 );
			} elseif ( $average >= 50 && $average < 70 ) {
				$score = max( $score, 70 );
			}
		}

		if ( $pending >= 5 ) {
			$score += 15;
		} elseif ( $pending >= 3 ) {
			$score += 10;
		} elseif ( $pending >= 2 ) {
			$score += 5;
		}

		if ( $progress > 0 && $progress < 30 ) {
			$score += 10;
		} elseif ( $progress >= 30 && $progress < 50 ) {
			$score += 5;
		}

		$last_access_ts = $last_access_raw ? strtotime( $last_access_raw ) : 0;
		if ( $last_access_ts ) {
			$age = time() - $last_access_ts;
			if ( $age > ( 14 * DAY_IN_SECONDS ) ) {
				$score += 20;
			} elseif ( $age > ( 7 * DAY_IN_SECONDS ) ) {
				$score += 10;
			}
		}

		$score = max( 0, min( 100, $score ) );
		return absint( $score );
	}

	/**
	 * Refresca snapshots de un curso.
	 *
	 * @param int  $course_id
	 * @param bool $notify Enviar notificaciones internas (MVP) cuando hay riesgo alto.
	 * @return int Cantidad de filas upserted.
	 */
	public function scan_course( int $course_id, bool $notify = true ): int {
		global $wpdb;

		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return 0;
		}

		if ( ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			return 0;
		}

		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Academic_Status_Service' ) : null;
		if ( ! $status_service || ! method_exists( $status_service, 'get_student_course_status' ) ) {
			return 0;
		}

		$student_ids = (array) \CLMS_Helper::get_enrolled_student_ids( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', $student_ids ) ) );
		if ( empty( $student_ids ) ) {
			return 0;
		}

		$rows = 0;
		foreach ( $student_ids as $student_id ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, risk_score, risk_level, last_notified_at, last_alert_type FROM {$this->table()} WHERE course_id = %d AND user_id = %d LIMIT 1",
					$course_id,
					$student_id
				),
				ARRAY_A
			);
			$existing = is_array( $existing ) ? $existing : array();
			$existing_id = absint( $existing['id'] ?? 0 );
			$prev_score  = isset( $existing['risk_score'] ) ? absint( $existing['risk_score'] ) : null;
			$prev_level  = sanitize_key( (string) ( $existing['risk_level'] ?? '' ) );

			$status = (array) $status_service->get_student_course_status(
				$student_id,
				$course_id,
				array(
					'skip_improvement_plan' => true,
				)
			);

			$risk_level = sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) );
			$risk_score = self::calculate_risk_score_from_status( $status );
			$risk_score_prev  = ( null !== $prev_score ) ? absint( $prev_score ) : null;
			$risk_score_delta = ( null !== $prev_score ) ? (int) ( $risk_score - absint( $prev_score ) ) : null;
			$risk_trend       = null === $prev_score ? 'new' : ( ( $risk_score_delta ?? 0 ) > 0 ? 'up' : ( ( $risk_score_delta ?? 0 ) < 0 ? 'down' : 'flat' ) );

			$threshold_days = absint( get_option( 'clms_inactivity_days_threshold', 14 ) );
			if ( $threshold_days <= 0 ) {
				$threshold_days = 14;
			}
			$threshold_days = absint( apply_filters( 'clms_inactivity_days_threshold', $threshold_days ) );
			if ( $threshold_days <= 0 ) {
				$threshold_days = 14;
			}

			$signals    = array(
				'progress_percent'   => absint( $status['progress_percent'] ?? 0 ),
				'final_average'      => ( isset( $status['final_average'] ) && is_numeric( $status['final_average'] ) ) ? absint( $status['final_average'] ) : null,
				'pending_activities' => absint( $status['pending_activities'] ?? 0 ),
				'last_access_at'     => sanitize_text_field( (string) ( $status['last_access_at'] ?? '' ) ),
				'course_time_seconds'=> absint( $status['course_time_seconds'] ?? 0 ),
				'risk_reasons'       => isset( $status['risk_reasons'] ) && is_array( $status['risk_reasons'] ) ? array_values( array_map( 'sanitize_text_field', $status['risk_reasons'] ) ) : array(),
				'recommended_action' => sanitize_text_field( (string) ( $status['recommended_action'] ?? '' ) ),
				'risk_score_prev'    => $risk_score_prev,
				'risk_score_delta'   => $risk_score_delta,
				'risk_trend'         => $risk_trend,
			);

			$alert_type = '';
			$last_access_raw = (string) ( $signals['last_access_at'] ?? '' );
			$last_access_ts = $last_access_raw ? strtotime( $last_access_raw ) : 0;
			$is_inactive = false;
			if ( $last_access_ts ) {
				$is_inactive = ( time() - $last_access_ts ) >= ( $threshold_days * DAY_IN_SECONDS );
			} else {
				$is_inactive = true;
			}

			if ( 'high' === $risk_level ) {
				$alert_type = 'high_risk';
			} elseif ( $is_inactive && $risk_score >= 60 ) {
				$alert_type = 'inactivity';
			} elseif ( null !== $risk_score_delta && $risk_score_delta >= 20 && $risk_score >= 60 ) {
				$alert_type = 'risk_spike';
			} elseif ( '' !== $prev_level && $prev_level !== $risk_level && ( 'medium' === $risk_level || 'high' === $risk_level ) ) {
				$alert_type = 'risk_level_change';
			}

			$payload = array(
				'course_id'        => $course_id,
				'user_id'          => $student_id,
				'risk_level'       => $risk_level ? $risk_level : 'unknown',
				'risk_score'       => $risk_score,
				'risk_score_prev'  => $risk_score_prev,
				'risk_score_delta' => $risk_score_delta,
				'risk_trend'       => $risk_trend,
				'last_alert_type'  => $alert_type ? $alert_type : null,
				'signals_json'     => wp_json_encode( $signals ),
				'last_activity_at' => $signals['last_access_at'],
				'updated_at'       => current_time( 'mysql' ),
			);

			if ( $existing_id ) {
				$wpdb->update( $this->table(), $payload, array( 'id' => $existing_id ) );
			} else {
				$payload['created_at'] = current_time( 'mysql' );
				$wpdb->insert( $this->table(), $payload );
			}

			++$rows;

			$row_id = $existing_id ? $existing_id : absint( $wpdb->insert_id );
			if ( $notify && '' !== $alert_type ) {
				$this->notify_teachers_if_needed( $course_id, $student_id, $signals, $row_id, $alert_type );
			}
		}

		return absint( $rows );
	}

	/**
	 * Scan diario (todos los cursos publicados/privados).
	 *
	 * @return int Cursos procesados.
	 */
	public function scan_all_courses_daily(): int {
		$courses = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$count = 0;
		foreach ( (array) $courses as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}
			$this->scan_course( $course_id, true );
			++$count;
		}

		return absint( $count );
	}

	/**
	 * Lista snapshots para un curso.
	 *
	 * @param int $course_id
	 * @return array<int,array<string,mixed>>
	 */
	public function list_course_students( int $course_id ): array {
		global $wpdb;
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT id, user_id, risk_level, risk_score, risk_score_prev, risk_score_delta, risk_trend, last_alert_type, signals_json, last_activity_at, last_notified_at, created_at, updated_at
			 FROM {$this->table()}
			 WHERE course_id = %d
			 ORDER BY risk_score DESC, updated_at DESC
			 LIMIT 1000",
			$course_id
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as &$row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$row['course_id'] = $course_id;
			$row['signals'] = $row['signals_json'] ? json_decode( (string) $row['signals_json'], true ) : array();
			unset( $row['signals_json'] );

			$u = $user_id ? get_userdata( $user_id ) : null;
			$row['student_name']  = $u ? (string) ( $u->display_name ?? '' ) : '';
			$row['student_email'] = $u ? (string) ( $u->user_email ?? '' ) : '';
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Lista snapshots con filtros (múltiples cursos opcionales).
	 *
	 * @param array<string,mixed> $filters course_ids[], student_ids[], limit
	 * @return array<int,array<string,mixed>>
	 */
	public function list_students( array $filters ): array {
		global $wpdb;

		$filters = is_array( $filters ) ? $filters : array();
		$course_ids  = isset( $filters['course_ids'] ) && is_array( $filters['course_ids'] ) ? $filters['course_ids'] : array();
		$student_ids = isset( $filters['student_ids'] ) && is_array( $filters['student_ids'] ) ? $filters['student_ids'] : array();
		$limit       = isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 1000;

		$course_ids  = array_values( array_filter( array_map( 'absint', $course_ids ) ) );
		$student_ids = array_values( array_filter( array_map( 'absint', $student_ids ) ) );
		if ( $limit <= 0 ) {
			$limit = 1000;
		}

		$where = array();
		$args  = array();

		if ( ! empty( $course_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
			$where[] = "course_id IN ({$placeholders})";
			foreach ( $course_ids as $cid ) {
				$args[] = absint( $cid );
			}
		}

		if ( ! empty( $student_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
			$where[] = "user_id IN ({$placeholders})";
			foreach ( $student_ids as $sid ) {
				$args[] = absint( $sid );
			}
		}

		if ( empty( $where ) ) {
			return array();
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT id, course_id, user_id, risk_level, risk_score, risk_score_prev, risk_score_delta, risk_trend, last_alert_type, signals_json, last_activity_at, last_notified_at, created_at, updated_at
			 FROM {$this->table()}
			 WHERE {$where_sql}
			 ORDER BY risk_score DESC, updated_at DESC
			 LIMIT %d",
			array_merge( $args, array( $limit ) )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as &$row ) {
			$user_id   = absint( $row['user_id'] ?? 0 );
			$course_id = absint( $row['course_id'] ?? 0 );

			$row['signals'] = $row['signals_json'] ? json_decode( (string) $row['signals_json'], true ) : array();
			unset( $row['signals_json'] );

			$u = $user_id ? get_userdata( $user_id ) : null;
			$row['student_name']  = $u ? (string) ( $u->display_name ?? '' ) : '';
			$row['student_email'] = $u ? (string) ( $u->user_email ?? '' ) : '';

			$row['course_title'] = $course_id ? (string) get_the_title( $course_id ) : '';
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Export CSV sencillo (BI-friendly) basado en snapshots.
	 *
	 * @param int $course_id
	 * @return array<string,string>|array
	 */
	public function export_course_csv( int $course_id ): array {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$rows = $this->list_course_students( $course_id );
		if ( empty( $rows ) ) {
			return array();
		}

		$csv = $this->build_bi_csv(
			$rows,
			array(
				'include_course' => false,
			)
		);

		if ( ! is_string( $csv ) || '' === $csv ) {
			return array();
		}

		return array(
			'filename' => sprintf( 'atora-learning-analytics-course-%d-%s.csv', $course_id, gmdate( 'Ymd-His' ) ),
			'content'  => $csv,
		);
	}

	/**
	 * Export BI-friendly: filas normalizadas para BI (JSON).
	 *
	 * @param array<int,array<string,mixed>> $rows Snapshots (list_course_students o list_students).
	 * @param array<string,mixed> $opts include_course bool
	 * @return array<int,array<string,mixed>>
	 */
	public function build_bi_rows( array $rows, array $opts = array() ): array {
		$opts = is_array( $opts ) ? $opts : array();
		$include_course = ! empty( $opts['include_course'] );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$signals = is_array( $row['signals'] ?? null ) ? (array) $row['signals'] : array();
			$reasons = isset( $signals['risk_reasons'] ) && is_array( $signals['risk_reasons'] ) ? array_values( array_map( 'sanitize_text_field', $signals['risk_reasons'] ) ) : array();

			$item = array(
				'user_id'            => absint( $row['user_id'] ?? 0 ),
				'student_name'       => sanitize_text_field( (string) ( $row['student_name'] ?? '' ) ),
				'student_email'      => sanitize_email( (string) ( $row['student_email'] ?? '' ) ),
				'risk_level'         => sanitize_key( (string) ( $row['risk_level'] ?? 'unknown' ) ),
				'risk_score'         => absint( $row['risk_score'] ?? 0 ),
				'risk_score_prev'    => isset( $row['risk_score_prev'] ) ? ( null !== $row['risk_score_prev'] ? absint( $row['risk_score_prev'] ) : null ) : null,
				'risk_score_delta'   => isset( $row['risk_score_delta'] ) ? ( null !== $row['risk_score_delta'] ? (int) $row['risk_score_delta'] : null ) : null,
				'risk_trend'         => sanitize_key( (string) ( $row['risk_trend'] ?? '' ) ),
				'alert_type'         => sanitize_key( (string) ( $row['last_alert_type'] ?? '' ) ),
				'progress_percent'   => absint( $signals['progress_percent'] ?? 0 ),
				'final_average'      => ( null !== ( $signals['final_average'] ?? null ) ? absint( $signals['final_average'] ) : null ),
				'pending_activities' => absint( $signals['pending_activities'] ?? 0 ),
				'last_access_at'     => sanitize_text_field( (string) ( $signals['last_access_at'] ?? '' ) ),
				'course_time_seconds'=> absint( $signals['course_time_seconds'] ?? 0 ),
				'risk_reasons'       => $reasons,
				'recommended_action' => sanitize_text_field( (string) ( $signals['recommended_action'] ?? '' ) ),
				'updated_at'         => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
			);

			if ( $include_course ) {
				$item = array_merge(
					array(
						'course_id'    => absint( $row['course_id'] ?? 0 ),
						'course_title' => sanitize_text_field( (string) ( $row['course_title'] ?? '' ) ),
					),
					$item
				);
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * CSV BI-friendly con esquema fijo.
	 *
	 * @param array<int,array<string,mixed>> $rows Snapshots (list_course_students o list_students).
	 * @param array<string,mixed> $opts include_course bool
	 * @return string
	 */
	public function build_bi_csv( array $rows, array $opts = array() ): string {
		$opts = is_array( $opts ) ? $opts : array();
		$include_course = ! empty( $opts['include_course'] );

		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $stream ) {
			return '';
		}

		$headers = $include_course
			? array(
				__( 'Curso', 'atora-lms' ),
				__( 'Course ID', 'atora-lms' ),
				__( 'Nombre', 'atora-lms' ),
				__( 'Email', 'atora-lms' ),
				__( 'Riesgo', 'atora-lms' ),
				__( 'Score', 'atora-lms' ),
				__( 'Trend', 'atora-lms' ),
				__( 'Delta', 'atora-lms' ),
				__( 'Alert type', 'atora-lms' ),
				__( 'Progreso', 'atora-lms' ),
				__( 'Promedio', 'atora-lms' ),
				__( 'Pendientes', 'atora-lms' ),
				__( 'Último acceso', 'atora-lms' ),
				__( 'Tiempo (s)', 'atora-lms' ),
				__( 'Motivos', 'atora-lms' ),
				__( 'Acción recomendada', 'atora-lms' ),
				__( 'Actualizado', 'atora-lms' ),
			)
			: array(
				__( 'Nombre', 'atora-lms' ),
				__( 'Email', 'atora-lms' ),
				__( 'Riesgo', 'atora-lms' ),
				__( 'Score', 'atora-lms' ),
				__( 'Trend', 'atora-lms' ),
				__( 'Delta', 'atora-lms' ),
				__( 'Alert type', 'atora-lms' ),
				__( 'Progreso', 'atora-lms' ),
				__( 'Promedio', 'atora-lms' ),
				__( 'Pendientes', 'atora-lms' ),
				__( 'Último acceso', 'atora-lms' ),
				__( 'Tiempo (s)', 'atora-lms' ),
				__( 'Motivos', 'atora-lms' ),
				__( 'Acción recomendada', 'atora-lms' ),
				__( 'Actualizado', 'atora-lms' ),
			);

		fputcsv( $stream, $headers ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( $this->build_bi_rows( $rows, array( 'include_course' => $include_course ) ) as $item ) {
			$reasons = isset( $item['risk_reasons'] ) && is_array( $item['risk_reasons'] ) ? implode( ' | ', array_map( 'sanitize_text_field', $item['risk_reasons'] ) ) : '';

			$base = array(
				$item['student_name'],
				$item['student_email'],
				$item['risk_level'],
				absint( $item['risk_score'] ),
				sanitize_key( (string) ( $item['risk_trend'] ?? '' ) ),
				( null !== ( $item['risk_score_delta'] ?? null ) ? (int) $item['risk_score_delta'] : '' ),
				sanitize_key( (string) ( $item['alert_type'] ?? '' ) ),
				absint( $item['progress_percent'] ) . '%',
				( null !== ( $item['final_average'] ?? null ) ? absint( $item['final_average'] ) . '%' : '—' ),
				absint( $item['pending_activities'] ),
				(string) ( $item['last_access_at'] ?? '' ),
				absint( $item['course_time_seconds'] ?? 0 ),
				$reasons,
				(string) ( $item['recommended_action'] ?? '' ),
				(string) ( $item['updated_at'] ?? '' ),
			);

			$row = $include_course
				? array_merge(
					array(
						(string) ( $item['course_title'] ?? '' ),
						absint( $item['course_id'] ?? 0 ),
					),
					$base
				)
				: $base;

			fputcsv( $stream, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_string( $csv ) ? $csv : '';
	}

	/**
	 * Timeline (MVP): submissions recientes (incluye masters grupales submitted_by).
	 *
	 * @param int $student_id
	 * @param int $course_id
	 * @param int $limit
	 * @return array<int,array<string,mixed>>
	 */
	public function get_recent_submissions( int $student_id, int $course_id, int $limit = 10 ): array {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );
		$limit      = absint( $limit );
		if ( ! $student_id || ! $course_id ) {
			return array();
		}
		if ( $limit <= 0 ) {
			$limit = 10;
		}

		$submission_post_type = class_exists( 'CLMS_Submission' ) ? \CLMS_Submission::CPT : 'clms_submission';

		$q = new \WP_Query(
			array(
				'post_type'      => $submission_post_type,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => min( 50, $limit ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_course_id',
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => '_clms_submission_user_id',
							'value' => $student_id,
							'type'  => 'NUMERIC',
						),
						array(
							'key'   => '_clms_submission_submitted_by',
							'value' => $student_id,
							'type'  => 'NUMERIC',
						),
					),
				),
			)
		);

		$ids = is_array( $q->posts ) ? array_values( array_filter( array_map( 'absint', $q->posts ) ) ) : array();
		if ( empty( $ids ) ) {
			return array();
		}

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Grading' ) : null;

		$out = array();
		foreach ( $ids as $submission_id ) {
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$status    = sanitize_key( (string) get_post_meta( $submission_id, '_clms_submission_status', true ) );
			$grade     = get_post_meta( $submission_id, '_clms_submission_grade', true );
			$grade     = ( '' === (string) $grade ) ? null : ( is_numeric( $grade ) ? (int) round( (float) $grade ) : null );

			$speedgrade_url = '';
			if ( $grading && method_exists( $grading, 'get_speedgrade_url' ) ) {
				$speedgrade_url = (string) $grading->get_speedgrade_url( $submission_id, admin_url( 'admin.php?page=atora-learning-analytics&course_id=' . $course_id . '&student_id=' . $student_id ) );
			}

			$out[] = array(
				'submission_id'  => $submission_id,
				'lesson_id'      => $lesson_id,
				'lesson_title'   => $lesson_id ? (string) get_the_title( $lesson_id ) : '',
				'status'         => $status ? $status : 'submitted',
				'grade'          => $grade,
				'created_at'     => (string) get_post_field( 'post_date', $submission_id ),
				'speedgrade_url' => $speedgrade_url,
			);
		}

		return $out;
	}

	private function notify_teachers_if_needed( int $course_id, int $student_id, array $signals, int $row_id, string $alert_type = 'high_risk' ): void {
		global $wpdb;

		$row_id    = absint( $row_id );
		$course_id = absint( $course_id );
		$student_id = absint( $student_id );
		$alert_type = sanitize_key( $alert_type );
		if ( ! $row_id || ! $course_id || ! $student_id ) {
			return;
		}

		$last_notified = $wpdb->get_var( $wpdb->prepare( "SELECT last_notified_at FROM {$this->table()} WHERE id = %d", $row_id ) );
		if ( $last_notified ) {
			$ts = strtotime( (string) $last_notified );
			if ( $ts && ( time() - $ts ) < DAY_IN_SECONDS ) {
				return;
			}
		}

		$teacher_ids = array();
		$raw = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\s*,\s*/', trim( $raw ) );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $tid ) {
				$tid = absint( $tid );
				if ( $tid ) {
					$teacher_ids[] = $tid;
				}
			}
		}
		$author = absint( get_post_field( 'post_author', $course_id ) );
		if ( $author && ! in_array( $author, $teacher_ids, true ) ) {
			$teacher_ids[] = $author;
		}

		$student = get_userdata( $student_id );
		$student_name = $student ? (string) ( $student->display_name ?? '' ) : __( 'Estudiante', 'atora-lms' );

		$notifications = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}

		$reasons = isset( $signals['risk_reasons'] ) && is_array( $signals['risk_reasons'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $signals['risk_reasons'] ) ) ) : array();
		$summary = $reasons ? implode( ' ', array_slice( $reasons, 0, 2 ) ) : __( 'Revisar progreso y pendientes.', 'atora-lms' );
		$course_title = (string) get_the_title( $course_id );

		$delta = isset( $signals['risk_score_delta'] ) && null !== $signals['risk_score_delta'] ? (int) $signals['risk_score_delta'] : null;
		$delta_label = null !== $delta ? ( ( $delta > 0 ? '+' : '' ) . (string) $delta ) : '';

		$title = __( 'Alerta: estudiante en riesgo', 'atora-lms' );
		if ( 'high_risk' === $alert_type ) {
			$title = __( 'Alerta: riesgo alto', 'atora-lms' );
		} elseif ( 'inactivity' === $alert_type ) {
			$title = __( 'Alerta: inactividad', 'atora-lms' );
		} elseif ( 'risk_spike' === $alert_type ) {
			$title = __( 'Alerta: aumento de riesgo', 'atora-lms' );
		} elseif ( 'risk_level_change' === $alert_type ) {
			$title = __( 'Alerta: cambio de nivel de riesgo', 'atora-lms' );
		}

		$message = sprintf(
			/* translators: 1: student name, 2: course title, 3: delta, 4: summary */
			__( '%1$s requiere atención en %2$s. %3$s%4$s', 'atora-lms' ),
			$student_name,
			$course_title ? $course_title : ( '#' . $course_id ),
			$delta_label ? ( 'Δ ' . $delta_label . '. ' ) : '',
			$summary
		);

		foreach ( array_values( array_unique( $teacher_ids ) ) as $teacher_id ) {
			$teacher_id = absint( $teacher_id );
			if ( ! $teacher_id ) {
				continue;
			}

			$notifications->add_notification(
				$teacher_id,
				array(
					'type'      => 'learning_analytics',
					'title'     => $title,
					'message'   => $message,
					'link'      => admin_url( 'admin.php?page=atora-learning-analytics&course_id=' . $course_id ),
					'course_id' => $course_id,
					'user_id'   => $student_id,
				)
			);
		}

		$wpdb->update(
			$this->table(),
			array(
				'last_notified_at' => current_time( 'mysql' ),
				'last_alert_type'  => $alert_type ? $alert_type : null,
			),
			array( 'id' => $row_id )
		);
	}
}
