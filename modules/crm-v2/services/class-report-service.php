<?php
/**
 * Servicio de reportes CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Report_Service {
	/**
	 * Resumen ejecutivo.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_overview(): array {
		$leads_count    = Contact_Service::count_contacts( array( 'status' => 'lead' ) );
		$student_count  = Contact_Service::count_contacts( array( 'status' => 'student' ) );
		$deal_summary   = Deal_Service::get_summary();
		$risk_students  = Student_Followup_Service::count_risk_students();
		$overdue_tasks  = Task_Service::count_overdue();
		$upcoming_tasks = Task_Service::count_upcoming();

		return array(
			'leads_count'      => $leads_count,
			'student_count'    => $student_count,
			'deals_open'       => absint( $deal_summary['open'] ?? 0 ),
			'deals_won'        => absint( $deal_summary['won'] ?? 0 ),
			'deals_lost'       => absint( $deal_summary['lost'] ?? 0 ),
			'pipeline_value'   => (float) ( $deal_summary['pipeline_value'] ?? 0 ),
			'risk_students'    => $risk_students,
			'overdue_tasks'    => $overdue_tasks,
			'upcoming_tasks'   => $upcoming_tasks,
		);
	}

	/**
	 * Reporte comercial.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_sales_report(): array {
		global $wpdb;

		$deals_table      = $wpdb->prefix . 'atora_crm_deals';
		$campaigns_table  = $wpdb->prefix . 'atora_crm_campaigns';
		$recipient_table  = $wpdb->prefix . 'atora_crm_campaign_recipients';

		$stages = Deal_Service::get_stages();
		$stage_counts = array();
		foreach ( array_keys( $stages ) as $stage_key ) {
			$stage_counts[ $stage_key ] = 0;
		}

		if ( DB_Service::table_exists( $deals_table ) ) {
			$rows = (array) $wpdb->get_results( "SELECT stage, COUNT(*) AS total FROM {$deals_table} GROUP BY stage", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $rows as $row ) {
				$key = sanitize_key( (string) ( $row['stage'] ?? '' ) );
				if ( isset( $stage_counts[ $key ] ) ) {
					$stage_counts[ $key ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		$campaign_rows = array();
		if ( DB_Service::table_exists( $campaigns_table ) ) {
			$campaign_rows = (array) $wpdb->get_results(
				"SELECT id, name, status, execution_mode, created_at, launched_at FROM {$campaigns_table} ORDER BY created_at DESC LIMIT 30",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$campaign_stats = array();
		if ( DB_Service::table_exists( $recipient_table ) ) {
			foreach ( $campaign_rows as $campaign_row ) {
				$campaign_id = absint( $campaign_row['id'] ?? 0 );
				if ( ! $campaign_id ) {
					continue;
				}
				$stats = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT status, COUNT(*) AS total FROM {$recipient_table} WHERE campaign_id = %d GROUP BY status",
						$campaign_id
					),
					ARRAY_A
				); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$campaign_stats[ $campaign_id ] = $stats;
			}
		}

		return array(
			'stage_counts'   => $stage_counts,
			'campaigns'      => $campaign_rows,
			'campaign_stats' => $campaign_stats,
			'stages'         => $stages,
		);
	}

	/**
	 * Reporte académico.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_academic_report(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_student_followups';
		$rows  = array();
		if ( DB_Service::table_exists( $table ) ) {
			$rows = (array) $wpdb->get_results( "SELECT stage, COUNT(*) AS total FROM {$table} GROUP BY stage", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$stage_counts = array();
		foreach ( Student_Followup_Service::get_stages() as $stage_key => $stage_data ) {
			$stage_counts[ $stage_key ] = 0;
		}
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) ( $row['stage'] ?? '' ) );
			if ( isset( $stage_counts[ $key ] ) ) {
				$stage_counts[ $key ] = absint( $row['total'] ?? 0 );
			}
		}

		$tasks = Task_Service::list_tasks( array( 'status' => 'pending', 'limit' => 200 ) );
		$teaching_pending = 0;
		foreach ( (array) ( $tasks['items'] ?? array() ) as $task ) {
			if ( 'grading' === sanitize_key( (string) ( $task['task_type'] ?? '' ) ) || 'tutoring' === sanitize_key( (string) ( $task['task_type'] ?? '' ) ) ) {
				$teaching_pending++;
			}
		}

		return array(
			'stage_counts'      => $stage_counts,
			'risk_students'     => Student_Followup_Service::count_risk_students(),
			'teaching_pending'  => $teaching_pending,
			'total_followups'   => array_sum( $stage_counts ),
			'stages'            => Student_Followup_Service::get_stages(),
		);
	}

	/**
	 * Dataset consolidado para dashboards.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_dashboard_data(): array {
		global $wpdb;

		$deals_table      = $wpdb->prefix . 'atora_crm_deals';
		$contacts_table   = $wpdb->prefix . 'atora_contacts';
		$campaigns_table  = $wpdb->prefix . 'atora_crm_campaigns';
		$recipients_table = $wpdb->prefix . 'atora_crm_campaign_recipients';
		$followups_table  = $wpdb->prefix . 'atora_crm_student_followups';
		$tasks_table      = $wpdb->prefix . 'atora_crm_tasks';
		$posts_table      = $wpdb->posts;

		$pipeline_funnel = array(
			'labels' => array(),
			'counts' => array(),
		);
		foreach ( Deal_Service::get_stages() as $stage_key => $stage ) {
			$pipeline_funnel['labels'][] = (string) ( $stage['label'] ?? $stage_key );
			$pipeline_funnel['counts'][] = 0;
		}
		if ( DB_Service::table_exists( $deals_table ) ) {
			$stage_index = array_flip( array_keys( Deal_Service::get_stages() ) );
			$rows = (array) $wpdb->get_results( "SELECT stage, COUNT(*) AS total FROM {$deals_table} GROUP BY stage", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $rows as $row ) {
				$key = sanitize_key( (string) ( $row['stage'] ?? '' ) );
				if ( isset( $stage_index[ $key ] ) ) {
					$pipeline_funnel['counts'][ $stage_index[ $key ] ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		$contacts_by_week = array(
			'labels'       => array(),
			'new_leads'    => array(),
			'new_students' => array(),
		);
		if ( DB_Service::table_exists( $contacts_table ) ) {
			$contact_rows = (array) $wpdb->get_results(
				"SELECT YEARWEEK(created_at, 1) AS yw, status, COUNT(*) AS total
				 FROM {$contacts_table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 WEEK)
				 GROUP BY YEARWEEK(created_at, 1), status
				 ORDER BY yw ASC",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$weekly = array();
			foreach ( $contact_rows as $row ) {
				$week_key = sanitize_text_field( (string) ( $row['yw'] ?? '' ) );
				if ( '' === $week_key ) {
					continue;
				}
				if ( ! isset( $weekly[ $week_key ] ) ) {
					$weekly[ $week_key ] = array( 'label' => 'Sem ' . substr( $week_key, -2 ), 'new_leads' => 0, 'new_students' => 0 );
				}
				$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
				if ( in_array( $status, array( 'lead', 'prospect' ), true ) ) {
					$weekly[ $week_key ]['new_leads'] += absint( $row['total'] ?? 0 );
				}
				if ( in_array( $status, array( 'student', 'alumni' ), true ) ) {
					$weekly[ $week_key ]['new_students'] += absint( $row['total'] ?? 0 );
				}
			}
			foreach ( $weekly as $week ) {
				$contacts_by_week['labels'][]       = $week['label'];
				$contacts_by_week['new_leads'][]    = $week['new_leads'];
				$contacts_by_week['new_students'][] = $week['new_students'];
			}
		}

		$campaign_performance = array(
			'labels'  => array(),
			'sent'    => array(),
			'opened'  => array(),
			'clicked' => array(),
		);
		if ( DB_Service::table_exists( $campaigns_table ) && DB_Service::table_exists( $recipients_table ) ) {
			$campaign_rows = (array) $wpdb->get_results(
				"SELECT c.id, c.name,
				        COUNT(r.id) AS total,
				        SUM(r.status IN ('queued','sent')) AS sent,
				        SUM(r.opened_at IS NOT NULL) AS opened,
				        SUM(r.clicked_at IS NOT NULL) AS clicked
				 FROM {$campaigns_table} c
				 LEFT JOIN {$recipients_table} r ON r.campaign_id = c.id
				 GROUP BY c.id, c.name
				 ORDER BY c.created_at DESC
				 LIMIT 5",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$campaign_rows = array_reverse( $campaign_rows );
			foreach ( $campaign_rows as $row ) {
				$campaign_performance['labels'][]  = sanitize_text_field( (string) ( $row['name'] ?? __( 'Campaña', 'atora-lms' ) ) );
				$campaign_performance['sent'][]    = absint( $row['sent'] ?? $row['total'] ?? 0 );
				$campaign_performance['opened'][]  = absint( $row['opened'] ?? 0 );
				$campaign_performance['clicked'][] = absint( $row['clicked'] ?? 0 );
			}
		}

		$academic_stages = array(
			'labels' => array(),
			'counts' => array(),
		);
		$stage_counts = self::get_academic_report()['stage_counts'] ?? array();
		foreach ( Student_Followup_Service::get_stages() as $stage_key => $stage ) {
			$academic_stages['labels'][] = (string) ( $stage['label'] ?? $stage_key );
			$academic_stages['counts'][] = absint( $stage_counts[ $stage_key ] ?? 0 );
		}

		$risk_distribution = array(
			'high'   => 0,
			'medium' => 0,
			'normal' => 0,
		);
		if ( DB_Service::table_exists( $followups_table ) ) {
			$risk_rows = (array) $wpdb->get_results( "SELECT risk_level, COUNT(*) AS total FROM {$followups_table} GROUP BY risk_level", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $risk_rows as $row ) {
				$risk = sanitize_key( (string) ( $row['risk_level'] ?? 'normal' ) );
				if ( 'critical' === $risk ) {
					$risk_distribution['high'] += absint( $row['total'] ?? 0 );
				} elseif ( isset( $risk_distribution[ $risk ] ) ) {
					$risk_distribution[ $risk ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		$conversion_trend = array(
			'labels' => array(),
			'rate'   => array(),
		);
		if ( DB_Service::table_exists( $deals_table ) ) {
			$trend_rows = (array) $wpdb->get_results(
				"SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
				        SUM(stage IN ('won','enrolled')) AS won_total,
				        SUM(stage = 'lost') AS lost_total
				 FROM {$deals_table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MONTH)
				 GROUP BY DATE_FORMAT(created_at, '%Y-%m')
				 ORDER BY ym ASC",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $trend_rows as $row ) {
				$ym   = sanitize_text_field( (string) ( $row['ym'] ?? '' ) );
				$won  = absint( $row['won_total'] ?? 0 );
				$lost = absint( $row['lost_total'] ?? 0 );
				$conversion_trend['labels'][] = $ym;
				$conversion_trend['rate'][]   = ( $won + $lost ) > 0 ? round( ( $won / ( $won + $lost ) ) * 100, 1 ) : 0;
			}
		}

		$courses_risk = array(
			'labels'     => array(),
			'risk_count' => array(),
			'total'      => array(),
		);
		if ( DB_Service::table_exists( $followups_table ) ) {
			$course_rows = (array) $wpdb->get_results(
				"SELECT f.course_id,
				        COUNT(*) AS total,
				        SUM(f.risk_level IN ('high','critical')) AS risk_count,
				        MAX(p.post_title) AS course_title
				 FROM {$followups_table} f
				 LEFT JOIN {$posts_table} p ON p.ID = f.course_id
				 WHERE f.course_id > 0
				 GROUP BY f.course_id
				 ORDER BY risk_count DESC, total DESC
				 LIMIT 6",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $course_rows as $row ) {
				$courses_risk['labels'][]     = sanitize_text_field( (string) ( $row['course_title'] ?? __( 'Curso', 'atora-lms' ) ) );
				$courses_risk['risk_count'][] = absint( $row['risk_count'] ?? 0 );
				$courses_risk['total'][]      = absint( $row['total'] ?? 0 );
			}
		}

		$teaching_activity_by_week = array(
			'labels'   => array(),
			'grading'  => array(),
			'tutoring' => array(),
		);
		if ( DB_Service::table_exists( $tasks_table ) ) {
			$task_rows = (array) $wpdb->get_results(
				"SELECT YEARWEEK(created_at, 1) AS yw, task_type, COUNT(*) AS total
				 FROM {$tasks_table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 WEEK)
				   AND task_type IN ('grading','tutoring')
				 GROUP BY YEARWEEK(created_at, 1), task_type
				 ORDER BY yw ASC",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$weeks = array();
			foreach ( $task_rows as $row ) {
				$week_key = sanitize_text_field( (string) ( $row['yw'] ?? '' ) );
				if ( ! isset( $weeks[ $week_key ] ) ) {
					$weeks[ $week_key ] = array( 'label' => 'Sem ' . substr( $week_key, -2 ), 'grading' => 0, 'tutoring' => 0 );
				}
				$type = sanitize_key( (string) ( $row['task_type'] ?? '' ) );
				if ( isset( $weeks[ $week_key ][ $type ] ) ) {
					$weeks[ $week_key ][ $type ] = absint( $row['total'] ?? 0 );
				}
			}
			foreach ( $weeks as $week ) {
				$teaching_activity_by_week['labels'][]   = $week['label'];
				$teaching_activity_by_week['grading'][]  = $week['grading'];
				$teaching_activity_by_week['tutoring'][] = $week['tutoring'];
			}
		}

		// TODO: implementar snapshot semanal para datos históricos precisos.
		$risk_evolution = array(
			'labels' => array( __( 'Sem -3', 'atora-lms' ), __( 'Sem -2', 'atora-lms' ), __( 'Sem -1', 'atora-lms' ), __( 'Esta sem', 'atora-lms' ) ),
			'high'   => array(),
			'medium' => array(),
		);
		if ( DB_Service::table_exists( $followups_table ) ) {
			for ( $i = 3; $i >= 0; $i-- ) {
				$from = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $i + 1 ) . ' week', current_time( 'timestamp', true ) ) );
				$to   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $i . ' week', current_time( 'timestamp', true ) ) );
				$counts = (array) $wpdb->get_row(
					$wpdb->prepare(
						"SELECT SUM(risk_level IN ('high','critical')) AS high_total,
						        SUM(risk_level = 'medium') AS medium_total
						 FROM {$followups_table}
						 WHERE updated_at BETWEEN %s AND %s",
						$from,
						$to
					),
					ARRAY_A
				); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$risk_evolution['high'][]   = absint( $counts['high_total'] ?? 0 );
				$risk_evolution['medium'][] = absint( $counts['medium_total'] ?? 0 );
			}
		}

		return array(
			'pipeline_funnel'          => $pipeline_funnel,
			'contacts_by_week'         => $contacts_by_week,
			'campaign_performance'     => $campaign_performance,
			'academic_stages'          => $academic_stages,
			'risk_distribution'        => $risk_distribution,
			'conversion_trend'         => $conversion_trend,
			'courses_risk'             => $courses_risk,
			'teaching_activity_by_week'=> $teaching_activity_by_week,
			'risk_evolution'           => $risk_evolution,
		);
	}
}
