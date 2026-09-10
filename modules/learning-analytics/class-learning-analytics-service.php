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
		global $wpdb;

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

		// Secciones (lead/assistant/coordinator): cursos asignados vía atora_sections.
		if ( $this->section_tables_exist() ) {
			$sections_table = $wpdb->prefix . 'atora_sections';
			$teachers_table = $wpdb->prefix . 'atora_section_teachers';

			$sec_course_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT s.wp_course_id
					 FROM {$teachers_table} st
					 INNER JOIN {$sections_table} s ON s.id = st.section_id
					 WHERE st.user_id = %d AND st.role IN ('lead','assistant','coordinator')",
					$teacher_id
				)
			);
			$sec_course_ids = array_values( array_filter( array_map( 'absint', (array) $sec_course_ids ) ) );
			foreach ( $sec_course_ids as $cid ) {
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
		global $wpdb;

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

		if ( in_array( $viewer_id, array_values( array_unique( $teacher_ids ) ), true ) ) {
			return true;
		}

		// Secciones (lead/assistant/coordinator): acceso por rol en tablas atora_sections.
		if ( ! $this->section_tables_exist() ) {
			return false;
		}

		$sections_table = $wpdb->prefix . 'atora_sections';
		$teachers_table = $wpdb->prefix . 'atora_section_teachers';

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1
				 FROM {$teachers_table} st
				 INNER JOIN {$sections_table} s ON s.id = st.section_id
				 WHERE s.wp_course_id = %d
				   AND st.user_id = %d
				   AND st.role IN ('lead','assistant','coordinator')
				 LIMIT 1",
				$course_id,
				$viewer_id
			)
		);

		return (bool) $found;
	}

	/**
	 * Calcula score 0–100 a partir de un status académico.
	 *
	 * @param array<string,mixed> $status
	 * @param array<string,mixed> $extra_signals
	 * @return int
	 */
	public static function calculate_risk_score_from_status( array $status, array $extra_signals = array() ): int {
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

		// Señales extra (mensajes/lecturas) — engagement reciente.
		$messages_14d    = absint( $extra_signals['messages_14d'] ?? 0 );
		$lesson_reads_14d = absint( $extra_signals['lesson_reads_14d'] ?? 0 );

		if ( 0 === $lesson_reads_14d && 0 === $messages_14d ) {
			$score += 10;
		} elseif ( 0 === $lesson_reads_14d ) {
			$score += 8;
		} elseif ( $lesson_reads_14d > 0 && $lesson_reads_14d < 2 ) {
			$score += 4;
		}

		if ( $messages_14d > 0 && $messages_14d < 2 ) {
			$score += 2;
		}

		// Engaged: pequeña reducción (sin “anular” riesgo académico).
		if ( $lesson_reads_14d >= 8 || $messages_14d >= 2 ) {
			$score -= 5;
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

			$existing_rows = $this->get_existing_rows_map( $course_id, $student_ids );
			$missed_map    = $this->get_missed_submissions_counts( $course_id, $student_ids );
			$msg_map       = $this->get_course_message_stats( $course_id, $student_ids, 14 );
			$read_map      = $this->get_course_read_stats_from_recent_events( $course_id, $student_ids, 14 );
			$h5p_map       = $this->get_course_h5p_stats( $course_id, $student_ids, 14 );

		$rows = 0;
		foreach ( $student_ids as $student_id ) {
			$existing = isset( $existing_rows[ $student_id ] ) && is_array( $existing_rows[ $student_id ] ) ? (array) $existing_rows[ $student_id ] : array();
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

			$messages_14d = absint( $msg_map[ $student_id ]['messages_14d'] ?? 0 );
			$last_message_at = sanitize_text_field( (string) ( $msg_map[ $student_id ]['last_message_at'] ?? '' ) );

				$lesson_reads_14d = absint( $read_map[ $student_id ]['lesson_reads_14d'] ?? 0 );
				$last_read_at     = sanitize_text_field( (string) ( $read_map[ $student_id ]['last_read_at'] ?? '' ) );

				$h5p_attempts_14d = absint( $h5p_map[ $student_id ]['h5p_attempts_14d'] ?? 0 );
				$last_h5p_at      = sanitize_text_field( (string) ( $h5p_map[ $student_id ]['last_h5p_at'] ?? '' ) );

				$risk_score = self::calculate_risk_score_from_status(
					$status,
					array(
						'messages_14d'     => $messages_14d,
						'lesson_reads_14d' => $lesson_reads_14d,
					)
				);
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

			$missed_count = absint( $missed_map[ $student_id ] ?? 0 );

			$reasons = isset( $status['risk_reasons'] ) && is_array( $status['risk_reasons'] ) ? array_values( array_map( 'sanitize_text_field', $status['risk_reasons'] ) ) : array();
			if ( 0 === $lesson_reads_14d ) {
				$reasons[] = __( 'Baja actividad de lectura (14 días).', 'atora-lms' );
			}
			if ( 0 === $messages_14d ) {
				$reasons[] = __( 'Sin mensajes recientes (14 días).', 'atora-lms' );
			}
			$reasons = array_values( array_unique( array_filter( $reasons ) ) );

			$signals    = array(
				'progress_percent'   => absint( $status['progress_percent'] ?? 0 ),
				'final_average'      => ( isset( $status['final_average'] ) && is_numeric( $status['final_average'] ) ) ? absint( $status['final_average'] ) : null,
				'pending_activities' => absint( $status['pending_activities'] ?? 0 ),
				'last_access_at'     => sanitize_text_field( (string) ( $status['last_access_at'] ?? '' ) ),
				'course_time_seconds'=> absint( $status['course_time_seconds'] ?? 0 ),
				'missed_submissions' => absint( $missed_count ),
					'messages_14d'       => $messages_14d,
					'last_message_at'    => $last_message_at,
					'lesson_reads_14d'   => $lesson_reads_14d,
					'last_read_at'       => $last_read_at,
					'h5p_attempts_14d'   => $h5p_attempts_14d,
					'last_h5p_at'        => $last_h5p_at,
					'risk_reasons'       => $reasons,
					'recommended_action' => sanitize_text_field( (string) ( $status['recommended_action'] ?? '' ) ),
					'risk_score_prev'    => $risk_score_prev,
					'risk_score_delta'   => $risk_score_delta,
					'risk_trend'         => $risk_trend,
			);

			if ( $missed_count > 0 && '' === (string) ( $signals['recommended_action'] ?? '' ) ) {
				$signals['recommended_action'] = __( 'Revisa las entregas vencidas y acuerda un plan de recuperación.', 'atora-lms' );
			}
			if ( 0 === $lesson_reads_14d && '' === (string) ( $signals['recommended_action'] ?? '' ) ) {
				$signals['recommended_action'] = __( 'Contacta al estudiante y sugiere retomar lecturas y actividades esta semana.', 'atora-lms' );
			}

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

	private function get_existing_rows_map( int $course_id, array $student_ids ): array {
		global $wpdb;

		$course_id = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', (array) $student_ids ) ) );
		if ( ! $course_id || empty( $student_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
		$args = array_merge( array( $course_id ), $student_ids );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT id, user_id, risk_score, risk_level, last_notified_at, last_alert_type
			 FROM {$this->table()}
			 WHERE course_id = %d AND user_id IN ({$placeholders})",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$map = array();
		foreach ( $rows as $row ) {
			$uid = absint( $row['user_id'] ?? 0 );
			if ( $uid ) {
				$map[ $uid ] = $row;
			}
		}

		return $map;
	}

	private function get_missed_submissions_counts( int $course_id, array $student_ids ): array {
		global $wpdb;

		$course_id = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', (array) $student_ids ) ) );
		if ( ! $course_id || empty( $student_ids ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'atora_early_warning';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( ! $exists ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
		$args = array_merge( array( $course_id, 'missed_submission' ), $student_ids );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT user_id, data
			 FROM {$table}
			 WHERE course_id = %d
			   AND warning_type = %s
			   AND status = 'open'
			   AND user_id IN ({$placeholders})",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$map = array();
		foreach ( $rows as $row ) {
			$uid = absint( $row['user_id'] ?? 0 );
			if ( ! $uid ) {
				continue;
			}
			$decoded = json_decode( (string) ( $row['data'] ?? '' ), true );
			$decoded = is_array( $decoded ) ? $decoded : array();
			$map[ $uid ] = absint( $decoded['count'] ?? 0 );
		}

		return $map;
	}

	private function get_course_message_stats( int $course_id, array $student_ids, int $days = 14 ): array {
		global $wpdb;

		$course_id   = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', (array) $student_ids ) ) );
		$days        = max( 1, absint( $days ) );

		if ( ! $course_id || empty( $student_ids ) ) {
			return array();
		}

		$msg_table  = $wpdb->prefix . 'atora_conversation_messages';
		$conv_table = $wpdb->prefix . 'atora_conversations';

		// Tablas opcionales (depende del módulo CRM).
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $conv_table ) );
		if ( ! $exists ) {
			return array();
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $msg_table ) );
		if ( ! $exists ) {
			return array();
		}

		$since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
		$args = array_merge( array( $course_id, $since ), $student_ids );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT c.user_id AS user_id, COUNT(m.id) AS cnt, MAX(m.created_at) AS last_at
			 FROM {$conv_table} c
			 INNER JOIN {$msg_table} m ON m.conversation_id = c.id
			 WHERE c.course_id = %d
			   AND m.created_at >= %s
			   AND m.direction IN ('inbound','outbound')
			   AND c.user_id IN ({$placeholders})
			 GROUP BY c.user_id",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$map = array();
		foreach ( $rows as $row ) {
			$uid = absint( $row['user_id'] ?? 0 );
			if ( ! $uid ) {
				continue;
			}
			$map[ $uid ] = array(
				'messages_14d'    => absint( $row['cnt'] ?? 0 ),
				'last_message_at' => sanitize_text_field( (string) ( $row['last_at'] ?? '' ) ),
			);
		}

		return $map;
	}

	private function get_course_h5p_stats( int $course_id, array $student_ids, int $days = 14 ): array {
		global $wpdb;

		$course_id   = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', (array) $student_ids ) ) );
		$days        = max( 1, absint( $days ) );

		if ( ! $course_id || empty( $student_ids ) ) {
			return array();
		}

		$table  = $wpdb->prefix . 'atora_h5p_tracking';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( ! $exists ) {
			return array();
		}

		$since        = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
		$args         = array_merge( array( $course_id, $since ), $student_ids );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT user_id, SUM(attempts) AS cnt, MAX(last_event_at) AS last_at
			 FROM {$table}
			 WHERE course_id = %d
			   AND last_event_at >= %s
			   AND user_id IN ({$placeholders})
			 GROUP BY user_id",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$map = array();
		foreach ( $rows as $row ) {
			$uid = absint( $row['user_id'] ?? 0 );
			if ( ! $uid ) {
				continue;
			}
			$map[ $uid ] = array(
				'h5p_attempts_14d' => absint( $row['cnt'] ?? 0 ),
				'last_h5p_at'      => sanitize_text_field( (string) ( $row['last_at'] ?? '' ) ),
			);
		}

		return $map;
	}

	private function get_course_read_stats_from_recent_events( int $course_id, array $student_ids, int $days = 14 ): array {
		global $wpdb;

		$course_id   = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', (array) $student_ids ) ) );
		$days        = max( 1, absint( $days ) );

		if ( ! $course_id || empty( $student_ids ) ) {
			return array();
		}

		$since_ts = current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS );
		$meta_key = class_exists( 'CLMS_Student_Activity_Tracker' )
			? \CLMS_Student_Activity_Tracker::USER_META_EVENTS
			: '_clms_recent_activity_events';

		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
		$args = array_merge( array( $meta_key ), $student_ids );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT user_id, meta_value
			 FROM {$wpdb->usermeta}
			 WHERE meta_key = %s
			   AND user_id IN ({$placeholders})",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$map = array();
		foreach ( $rows as $row ) {
			$uid = absint( $row['user_id'] ?? 0 );
			if ( ! $uid ) {
				continue;
			}
			$events = maybe_unserialize( (string) ( $row['meta_value'] ?? '' ) );
			$events = is_array( $events ) ? $events : array();

			$count = 0;
			$last  = 0;
			$last_at = '';
			foreach ( $events as $ev ) {
				if ( ! is_array( $ev ) ) {
					continue;
				}
				if ( $course_id !== absint( $ev['course_id'] ?? 0 ) ) {
					continue;
				}
				$post_type = sanitize_key( (string) ( $ev['post_type'] ?? '' ) );
				if ( 'lm_lesson' !== $post_type ) {
					continue;
				}
				$at = sanitize_text_field( (string) ( $ev['accessed_at'] ?? '' ) );
				$ts = $at ? strtotime( $at ) : 0;
				if ( ! $ts || $ts < $since_ts ) {
					continue;
				}
				++$count;
				if ( $ts > $last ) {
					$last = $ts;
					$last_at = $at;
				}
			}

			if ( $count > 0 || $last > 0 ) {
				$map[ $uid ] = array(
					'lesson_reads_14d' => absint( $count ),
					'last_read_at'     => $last_at ? $last_at : '',
				);
			}
		}

		return $map;
	}

	private function section_tables_exist(): bool {
		static $cache = null;
		if ( null !== $cache ) {
			return (bool) $cache;
		}

		global $wpdb;
		$sections_table = $wpdb->prefix . 'atora_sections';
		$teachers_table = $wpdb->prefix . 'atora_section_teachers';

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sections_table ) );
		if ( ! $exists ) {
			$cache = false;
			return false;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $teachers_table ) );
		$cache = (bool) $exists;
		return (bool) $cache;
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
				'missed_submissions' => absint( $signals['missed_submissions'] ?? 0 ),
				'last_access_at'     => sanitize_text_field( (string) ( $signals['last_access_at'] ?? '' ) ),
				'course_time_seconds'=> absint( $signals['course_time_seconds'] ?? 0 ),
					'lesson_reads_14d'   => absint( $signals['lesson_reads_14d'] ?? 0 ),
					'last_read_at'       => sanitize_text_field( (string) ( $signals['last_read_at'] ?? '' ) ),
					'messages_14d'       => absint( $signals['messages_14d'] ?? 0 ),
					'last_message_at'    => sanitize_text_field( (string) ( $signals['last_message_at'] ?? '' ) ),
					'h5p_attempts_14d'   => absint( $signals['h5p_attempts_14d'] ?? 0 ),
					'last_h5p_at'        => sanitize_text_field( (string) ( $signals['last_h5p_at'] ?? '' ) ),
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
				__( 'Entregas perdidas', 'atora-lms' ),
				__( 'Último acceso', 'atora-lms' ),
				__( 'Tiempo (s)', 'atora-lms' ),
				__( 'Lecturas (14d)', 'atora-lms' ),
				__( 'Última lectura', 'atora-lms' ),
					__( 'Mensajes (14d)', 'atora-lms' ),
					__( 'Último mensaje', 'atora-lms' ),
					__( 'H5P (14d)', 'atora-lms' ),
					__( 'Último H5P', 'atora-lms' ),
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
				__( 'Entregas perdidas', 'atora-lms' ),
				__( 'Último acceso', 'atora-lms' ),
				__( 'Tiempo (s)', 'atora-lms' ),
				__( 'Lecturas (14d)', 'atora-lms' ),
				__( 'Última lectura', 'atora-lms' ),
					__( 'Mensajes (14d)', 'atora-lms' ),
					__( 'Último mensaje', 'atora-lms' ),
					__( 'H5P (14d)', 'atora-lms' ),
					__( 'Último H5P', 'atora-lms' ),
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
				absint( $item['missed_submissions'] ?? 0 ),
				(string) ( $item['last_access_at'] ?? '' ),
				absint( $item['course_time_seconds'] ?? 0 ),
				absint( $item['lesson_reads_14d'] ?? 0 ),
				(string) ( $item['last_read_at'] ?? '' ),
					absint( $item['messages_14d'] ?? 0 ),
					(string) ( $item['last_message_at'] ?? '' ),
					absint( $item['h5p_attempts_14d'] ?? 0 ),
					(string) ( $item['last_h5p_at'] ?? '' ),
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
