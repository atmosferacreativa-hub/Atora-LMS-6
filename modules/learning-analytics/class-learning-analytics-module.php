<?php
/**
 * Learning Analytics & Risk — dashboard + REST + cron.
 *
 * @package ATORA_LMS
 * @since   6.15.0
 */

namespace ATORA\LearningAnalytics;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Learning_Analytics_Module {

	const CRON_HOOK = 'atora_learning_analytics_daily_cron';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', self::CRON_HOOK );
		}

		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'admin_post_atora_learning_analytics_scan', array( __CLASS__, 'handle_scan_now' ) );
			add_action( 'admin_post_atora_learning_analytics_export', array( __CLASS__, 'handle_export_csv' ) );
			add_action( 'admin_post_atora_learning_analytics_export_json', array( __CLASS__, 'handle_export_json' ) );
		}
	}

	public static function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Analítica de riesgo', 'atora-lms' ),
			__( 'Analítica de riesgo', 'atora-lms' ),
			'read',
			'atora-learning-analytics',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$teacher_id = isset( $_GET['teacher_id'] ) ? absint( wp_unslash( $_GET['teacher_id'] ) ) : 0;
		$cohort_id  = isset( $_GET['cohort_id'] ) ? absint( wp_unslash( $_GET['cohort_id'] ) ) : 0;
		$student_id = isset( $_GET['student_id'] ) ? absint( wp_unslash( $_GET['student_id'] ) ) : 0;

		$courses = array();
		if ( class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
			$args = array(
				'status' => 'published',
				'limit'  => 200,
				'offset' => 0,
			);
			if ( ! current_user_can( 'manage_options' ) ) {
				$args['instructor_id'] = get_current_user_id();
			}
			$list = \ATORA\LMS\LMS_Course_Service::get_all( $args );
			$courses = is_array( $list ) ? (array) ( $list['items'] ?? array() ) : array();
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Analítica de riesgo', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Scoring por estudiante/curso basado en progreso, pendientes, notas, inactividad y engagement (lecturas/mensajes).', 'atora-lms' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="atora-learning-analytics">';
		echo '<p style="margin:0 0 8px 0"><strong>' . esc_html__( 'Filtros', 'atora-lms' ) . '</strong></p>';
		echo '<label class="description">' . esc_html__( 'Usa al menos uno: curso, cohorte o docente. Si defines curso, los demás filtros se ignoran.', 'atora-lms' ) . '</label><br><br>';
		echo '<label><strong>' . esc_html__( 'Curso (course_id)', 'atora-lms' ) . '</strong></label><br>';
		echo '<select name="course_id" style="min-width:360px;max-width:100%">';
		echo '<option value="0">' . esc_html__( 'Selecciona un curso…', 'atora-lms' ) . '</option>';
		foreach ( (array) $courses as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$cid   = absint( $c['id'] ?? 0 );
			$title = sanitize_text_field( (string) ( $c['title'] ?? '' ) );
			if ( $cid <= 0 ) { continue; }
			$label = $title ? ( $title . ' (#' . $cid . ')' ) : ( '#' . $cid );
			echo '<option value="' . esc_attr( (string) $cid ) . '"' . selected( $course_id, $cid, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<span class="description">' . esc_html__( 'Recomendado para ver detalle por curso.', 'atora-lms' ) . '</span><br><br>';

		echo '<label><strong>' . esc_html__( 'Cohorte (cohort_id)', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="cohort_id" value="' . esc_attr( (string) $cohort_id ) . '" min="1" style="width:180px"> ';
		echo '<span class="description">' . esc_html__( 'Lista estudiantes de una cohorte (multi-curso).', 'atora-lms' ) . '</span><br><br>';

		echo '<label><strong>' . esc_html__( 'Docente (teacher_id)', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="teacher_id" value="' . esc_attr( (string) $teacher_id ) . '" min="1" style="width:180px"> ';
		echo '<span class="description">' . esc_html__( 'Lista cursos asociados a un docente.', 'atora-lms' ) . '</span><br><br>';

		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Cargar', 'atora-lms' ) . '</button>';
		echo '</form>';

		$service = new Learning_Analytics_Service();

		if ( ! $course_id && ! $teacher_id && ! $cohort_id ) {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Tip: como admin puedes filtrar por cohorte/docente o indicar un curso. Evita “todo” sin filtros para no cargar el sistema.', 'atora-lms' ) . '</p></div>';
			} else {
				echo '<p class="description">' . esc_html__( 'Indica un course_id, cohort_id o teacher_id para ver el dashboard.', 'atora-lms' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		$course_ids  = array();
		$student_ids = array();

		if ( $course_id ) {
			$course_ids = array( $course_id );
		} elseif ( $cohort_id ) {
			$course_ids  = $service->get_course_ids_for_cohort( $cohort_id );
			$student_ids = $service->get_student_ids_for_cohort( $cohort_id );
		} elseif ( $teacher_id ) {
			$course_ids = $service->get_course_ids_for_teacher( $teacher_id );
		}

		if ( empty( $course_ids ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No hay cursos para estos filtros.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$viewer_id = get_current_user_id();
		$allowed_course_ids = array();
		foreach ( $course_ids as $cid ) {
			$cid = absint( $cid );
			if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
				$allowed_course_ids[] = $cid;
			}
		}
		$allowed_course_ids = array_values( array_unique( $allowed_course_ids ) );

		if ( empty( $allowed_course_ids ) ) {
			wp_die( esc_html__( 'No tienes acceso a estos cursos.', 'atora-lms' ) );
		}

		$course_ids = $allowed_course_ids;
		$primary_course_id = ( 1 === count( $course_ids ) ) ? absint( $course_ids[0] ) : 0;

		$scan_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'atora_learning_analytics_scan',
					'course_id'  => $course_id,
					'teacher_id' => $teacher_id,
					'cohort_id'  => $cohort_id,
				),
				admin_url( 'admin-post.php' )
			),
			'atora_learning_analytics_scan_' . md5( (string) $course_id . '|' . (string) $teacher_id . '|' . (string) $cohort_id )
		);
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'atora_learning_analytics_export',
					'course_id'  => $course_id,
					'teacher_id' => $teacher_id,
					'cohort_id'  => $cohort_id,
				),
				admin_url( 'admin-post.php' )
			),
			'atora_learning_analytics_export_' . md5( (string) $course_id . '|' . (string) $teacher_id . '|' . (string) $cohort_id )
		);
		$export_json_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'atora_learning_analytics_export_json',
					'course_id'  => $course_id,
					'teacher_id' => $teacher_id,
					'cohort_id'  => $cohort_id,
				),
				admin_url( 'admin-post.php' )
			),
			'atora_learning_analytics_export_json_' . md5( (string) $course_id . '|' . (string) $teacher_id . '|' . (string) $cohort_id )
		);

		echo '<p style="margin: 10px 0">';
		echo '<a class="button button-secondary" href="' . esc_url( $scan_url ) . '">' . esc_html__( 'Refrescar ahora', 'atora-lms' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Exportar CSV', 'atora-lms' ) . '</a>';
		echo ' <a class="button" href="' . esc_url( $export_json_url ) . '">' . esc_html__( 'Exportar JSON', 'atora-lms' ) . '</a>';
		echo '</p>';

		$rows = $primary_course_id
			? $service->list_course_students( $primary_course_id )
			: $service->list_students(
				array(
					'course_ids'  => $course_ids,
					'student_ids' => $student_ids,
					'limit'       => 1000,
				)
			);

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No hay snapshots aún. Usa “Refrescar ahora”.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width: 1150px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Estudiante', 'atora-lms' ) . '</th>';
		if ( ! $primary_course_id ) {
			echo '<th>' . esc_html__( 'Curso', 'atora-lms' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Riesgo', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Señales', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción sugerida', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Detalle', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$signals = is_array( $row['signals'] ?? null ) ? (array) $row['signals'] : array();
			$reasons = isset( $signals['risk_reasons'] ) && is_array( $signals['risk_reasons'] ) ? array_values( array_map( 'sanitize_text_field', $signals['risk_reasons'] ) ) : array();
			$last    = sanitize_text_field( (string) ( $signals['last_access_at'] ?? '' ) );
			$row_course_id = absint( $row['course_id'] ?? 0 );

			$signals_line = sprintf(
				/* translators: 1: progress, 2: average, 3: pending, 4: missed, 5: reads, 6: messages, 7: last access */
				__( 'Progreso: %1$s | Promedio: %2$s | Pendientes: %3$s | Perdidas: %4$s | Lecturas (14d): %5$s | Mensajes (14d): %6$s | Último acceso: %7$s', 'atora-lms' ),
				absint( $signals['progress_percent'] ?? 0 ) . '%',
				( null !== ( $signals['final_average'] ?? null ) ? absint( $signals['final_average'] ) . '%' : '—' ),
				absint( $signals['pending_activities'] ?? 0 ),
				absint( $signals['missed_submissions'] ?? 0 ),
				absint( $signals['lesson_reads_14d'] ?? 0 ),
				absint( $signals['messages_14d'] ?? 0 ),
				$last ? $last : '—'
			);

			$detail_url = add_query_arg(
				array(
					'page'       => 'atora-learning-analytics',
					'course_id'  => $row_course_id,
					'student_id' => absint( $row['user_id'] ?? 0 ),
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['student_name'] ?? '' ) ) . '</strong><br><span class="description">' . esc_html( (string) ( $row['student_email'] ?? '' ) ) . '</span></td>';
			if ( ! $primary_course_id ) {
				$course_title = (string) ( $row['course_title'] ?? '' );
				echo '<td><strong>' . esc_html( $course_title ? $course_title : (string) $row_course_id ) . '</strong><br><span class="description">#' . esc_html( (string) $row_course_id ) . '</span></td>';
			}
			echo '<td>' . esc_html( sanitize_key( (string) ( $row['risk_level'] ?? 'unknown' ) ) ) . ( $reasons ? '<br><span class="description">' . esc_html( implode( ' ', array_slice( $reasons, 0, 2 ) ) ) . '</span>' : '' ) . '</td>';
			$delta = isset( $row['risk_score_delta'] ) && null !== $row['risk_score_delta'] ? (int) $row['risk_score_delta'] : null;
			$trend = sanitize_key( (string) ( $row['risk_trend'] ?? '' ) );
			$score_label = (string) absint( $row['risk_score'] ?? 0 );
			if ( null !== $delta ) {
				$score_label .= ' (' . ( $delta > 0 ? '+' : '' ) . (string) $delta . ')';
			}
			echo '<td>' . esc_html( $score_label ) . ( $trend ? '<br><span class="description">' . esc_html( $trend ) . '</span>' : '' ) . '</td>';
			echo '<td><span class="description">' . esc_html( $signals_line ) . '</span></td>';
			echo '<td>' . esc_html( sanitize_text_field( (string) ( $signals['recommended_action'] ?? '' ) ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Ver', 'atora-lms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $student_id && $course_id ) {
			self::render_student_detail( $student_id, $course_id, $service );
		}
		echo '</div>';
	}

	private static function render_student_detail( int $student_id, int $course_id, Learning_Analytics_Service $service ): void {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );
		if ( ! $student_id || ! $course_id ) {
			return;
		}

		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Academic_Status_Service' ) : null;
		if ( ! $status_service || ! method_exists( $status_service, 'get_student_course_status' ) ) {
			return;
		}

		$status = (array) $status_service->get_student_course_status( $student_id, $course_id );
		$reasons = isset( $status['risk_reasons'] ) && is_array( $status['risk_reasons'] ) ? array_values( array_map( 'sanitize_text_field', $status['risk_reasons'] ) ) : array();

		$user = get_userdata( $student_id );
		$name = $user ? ( $user->display_name ? $user->display_name : $user->user_login ) : (string) $student_id;

		echo '<hr style="margin:18px 0">';
		echo '<h2>' . esc_html__( 'Detalle de estudiante', 'atora-lms' ) . '</h2>';
		echo '<p><strong>' . esc_html( $name ) . '</strong> <span class="description">#' . esc_html( (string) $student_id ) . ' — ' . esc_html( (string) get_the_title( $course_id ) ) . '</span></p>';

		echo '<table class="widefat striped" style="max-width: 900px">';
		echo '<tbody>';
		echo '<tr><th style="width:240px">' . esc_html__( 'Nivel de riesgo', 'atora-lms' ) . '</th><td>' . esc_html( sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Motivos', 'atora-lms' ) . '</th><td>' . esc_html( $reasons ? implode( ' | ', $reasons ) : '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Acción recomendada', 'atora-lms' ) . '</th><td>' . esc_html( sanitize_text_field( (string) ( $status['recommended_action'] ?? '' ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Progreso', 'atora-lms' ) . '</th><td>' . esc_html( (string) absint( $status['progress_percent'] ?? 0 ) ) . '%</td></tr>';
		echo '<tr><th>' . esc_html__( 'Promedio', 'atora-lms' ) . '</th><td>' . esc_html( null !== ( $status['final_average'] ?? null ) ? (string) absint( $status['final_average'] ) . '%' : '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Pendientes', 'atora-lms' ) . '</th><td>' . esc_html( (string) absint( $status['pending_activities'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Último acceso', 'atora-lms' ) . '</th><td>' . esc_html( sanitize_text_field( (string) ( $status['last_access_at'] ?? '' ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Próximo paso', 'atora-lms' ) . '</th><td>' . esc_html( sanitize_text_field( (string) ( $status['next_step'] ?? '' ) ) ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h3 style="margin-top:16px">' . esc_html__( 'Acciones rápidas', 'atora-lms' ) . '</h3>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page' => 'clms-speedgrader', 'course_id' => $course_id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Abrir SpeedGrade del curso', 'atora-lms' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'clms-gradebook', 'course_id' => $course_id, 'student_id' => $student_id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Ver Gradebook', 'atora-lms' ) . '</a>';
		echo '</p>';

		echo '<h3 style="margin-top:16px">' . esc_html__( 'Timeline (MVP)', 'atora-lms' ) . '</h3>';
		$recent = $service->get_recent_submissions( $student_id, $course_id, 8 );
		if ( empty( $recent ) ) {
			echo '<p class="description">' . esc_html__( 'No hay submissions recientes para este estudiante en el curso.', 'atora-lms' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width: 1050px">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Lección', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Nota', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Fecha', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Acción', 'atora-lms' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $recent as $item ) {
				$lesson_id    = absint( $item['lesson_id'] ?? 0 );
				$lesson_title = sanitize_text_field( (string) ( $item['lesson_title'] ?? '' ) );
				$status       = sanitize_key( (string) ( $item['status'] ?? '' ) );
				$grade        = isset( $item['grade'] ) ? $item['grade'] : null;
				$created      = sanitize_text_field( (string) ( $item['created_at'] ?? '' ) );
				$sg_url       = (string) ( $item['speedgrade_url'] ?? '' );

				$lesson_link = $lesson_id ? get_edit_post_link( $lesson_id, '' ) : '';
				$lesson_html = $lesson_link
					? '<a href="' . esc_url( $lesson_link ) . '">' . esc_html( $lesson_title ? $lesson_title : (string) $lesson_id ) . '</a>'
					: esc_html( $lesson_title ? $lesson_title : (string) $lesson_id );

				echo '<tr>';
				echo '<td>' . $lesson_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . esc_html( $status ? $status : 'submitted' ) . '</td>';
				echo '<td>' . esc_html( null !== $grade ? (string) absint( $grade ) : '—' ) . '</td>';
				echo '<td><span class="description">' . esc_html( $created ? $created : '—' ) . '</span></td>';
				echo '<td>' . ( $sg_url ? '<a class="button button-small" href="' . esc_url( $sg_url ) . '">' . esc_html__( 'SpeedGrade', 'atora-lms' ) . '</a>' : '—' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3 style="margin-top:16px">' . esc_html__( 'Entregas perdidas', 'atora-lms' ) . '</h3>';
		$missed = array();
		if ( class_exists( '\ATORA\EarlyWarning\Early_Warning_Service' ) ) {
			$ew = new \ATORA\EarlyWarning\Early_Warning_Service();
			if ( method_exists( $ew, 'list_missed_submissions_for_student' ) ) {
				$missed = (array) $ew->list_missed_submissions_for_student( $course_id, $student_id );
			}
		}

		if ( empty( $missed ) ) {
			echo '<p class="description">' . esc_html__( 'No se detectan entregas perdidas (según due_date).', 'atora-lms' ) . '</p>';
		} else {
			echo '<ul style="list-style:disc;padding-left:22px;max-width: 1050px">';
			foreach ( $missed as $m ) {
				$lesson_id = absint( $m['lesson_id'] ?? 0 );
				$title     = sanitize_text_field( (string) ( $m['lesson_title'] ?? '' ) );
				$day       = sanitize_text_field( (string) ( $m['deadline_date'] ?? '' ) );
				$link      = $lesson_id ? get_edit_post_link( $lesson_id, '' ) : '';

				$label = $title ? $title : (string) $lesson_id;
				$line  = $day ? sprintf( '%s — %s', $label, $day ) : $label;

				echo '<li>';
				if ( $link ) {
					echo '<a href="' . esc_url( $link ) . '">' . esc_html( $line ) . '</a>';
				} else {
					echo esc_html( $line );
				}
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	public static function handle_scan_now(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$teacher_id = isset( $_GET['teacher_id'] ) ? absint( wp_unslash( $_GET['teacher_id'] ) ) : 0;
		$cohort_id  = isset( $_GET['cohort_id'] ) ? absint( wp_unslash( $_GET['cohort_id'] ) ) : 0;

		check_admin_referer( 'atora_learning_analytics_scan_' . md5( (string) $course_id . '|' . (string) $teacher_id . '|' . (string) $cohort_id ) );

		$service = new Learning_Analytics_Service();
		$viewer_id = get_current_user_id();

		$course_ids = array();
		if ( $course_id ) {
			$course_ids = array( $course_id );
		} elseif ( $cohort_id ) {
			$course_ids = $service->get_course_ids_for_cohort( $cohort_id );
		} elseif ( $teacher_id ) {
			$course_ids = $service->get_course_ids_for_teacher( $teacher_id );
		}

		$allowed_course_ids = array();
		foreach ( $course_ids as $cid ) {
			$cid = absint( $cid );
			if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
				$allowed_course_ids[] = $cid;
			}
		}

		foreach ( array_values( array_unique( $allowed_course_ids ) ) as $cid ) {
			$service->scan_course( $cid, true );
		}

		$redirect = add_query_arg(
			array(
				'page'      => 'atora-learning-analytics',
				'course_id' => $course_id,
				'teacher_id'=> $teacher_id,
				'cohort_id' => $cohort_id,
				'scanned'   => 1,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_export_csv(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$teacher_id = isset( $_GET['teacher_id'] ) ? absint( wp_unslash( $_GET['teacher_id'] ) ) : 0;
		$cohort_id  = isset( $_GET['cohort_id'] ) ? absint( wp_unslash( $_GET['cohort_id'] ) ) : 0;

		check_admin_referer( 'atora_learning_analytics_export_' . md5( (string) $course_id . '|' . (string) $teacher_id . '|' . (string) $cohort_id ) );

		$service = new Learning_Analytics_Service();
		$viewer_id = get_current_user_id();

		if ( $course_id ) {
			if ( ! $service->viewer_can_access_course( $viewer_id, $course_id ) ) {
				wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) );
			}
			$csv = $service->export_course_csv( $course_id );
		} else {
			$course_ids  = array();
			$student_ids = array();

			if ( $cohort_id ) {
				$course_ids  = $service->get_course_ids_for_cohort( $cohort_id );
				$student_ids = $service->get_student_ids_for_cohort( $cohort_id );
			} elseif ( $teacher_id ) {
				$course_ids = $service->get_course_ids_for_teacher( $teacher_id );
			}

			$allowed_course_ids = array();
			foreach ( $course_ids as $cid ) {
				$cid = absint( $cid );
				if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
					$allowed_course_ids[] = $cid;
				}
			}
			$allowed_course_ids = array_values( array_unique( $allowed_course_ids ) );

			$rows = $service->list_students(
				array(
					'course_ids'  => $allowed_course_ids,
					'student_ids' => $student_ids,
					'limit'       => 2000,
				)
			);

			if ( empty( $rows ) ) {
				wp_die( esc_html__( 'No hay datos para exportar.', 'atora-lms' ) );
			}

			$content = $service->build_bi_csv(
				$rows,
				array(
					'include_course' => true,
				)
			);
			if ( '' === (string) $content ) {
				wp_die( esc_html__( 'No se pudo generar el CSV.', 'atora-lms' ) );
			}

			$csv = array(
				'filename' => sprintf( 'atora-learning-analytics-%s-%s.csv', $cohort_id ? ( 'cohort-' . $cohort_id ) : ( 'teacher-' . $teacher_id ), gmdate( 'Ymd-His' ) ),
				'content'  => (string) $content,
			);
		}

		if ( empty( $csv['content'] ) || empty( $csv['filename'] ) ) {
			wp_die( esc_html__( 'No hay datos para exportar.', 'atora-lms' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( (string) $csv['filename'] ) );
		echo (string) $csv['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function handle_export_json(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id  = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$teacher_id = isset( $_GET['teacher_id'] ) ? absint( wp_unslash( $_GET['teacher_id'] ) ) : 0;
		$cohort_id  = isset( $_GET['cohort_id'] ) ? absint( wp_unslash( $_GET['cohort_id'] ) ) : 0;

		check_admin_referer( 'atora_learning_analytics_export_json_' . md5( (string) $course_id . '|' . (string) $teacher_id . '|' . (string) $cohort_id ) );

		$service   = new Learning_Analytics_Service();
		$viewer_id = get_current_user_id();

		$include_course = false;

		if ( $course_id ) {
			if ( ! $service->viewer_can_access_course( $viewer_id, $course_id ) ) {
				wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) );
			}
			$rows = $service->list_course_students( $course_id );
		} else {
			$include_course = true;

			$course_ids  = array();
			$student_ids = array();
			if ( $cohort_id ) {
				$course_ids  = $service->get_course_ids_for_cohort( $cohort_id );
				$student_ids = $service->get_student_ids_for_cohort( $cohort_id );
			} elseif ( $teacher_id ) {
				$course_ids = $service->get_course_ids_for_teacher( $teacher_id );
			}

			$allowed_course_ids = array();
			foreach ( $course_ids as $cid ) {
				$cid = absint( $cid );
				if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
					$allowed_course_ids[] = $cid;
				}
			}
			$allowed_course_ids = array_values( array_unique( $allowed_course_ids ) );

			$rows = $service->list_students(
				array(
					'course_ids'  => $allowed_course_ids,
					'student_ids' => $student_ids,
					'limit'       => 5000,
				)
			);
		}

		if ( empty( $rows ) ) {
			wp_die( esc_html__( 'No hay datos para exportar.', 'atora-lms' ) );
		}

		$payload = array(
			'schema_version' => 1,
			'generated_at'   => gmdate( 'c' ),
			'filters'        => array(
				'course_id'  => $course_id,
				'teacher_id' => $teacher_id,
				'cohort_id'  => $cohort_id,
			),
			'rows'           => $service->build_bi_rows( $rows, array( 'include_course' => $include_course ) ),
		);

		$filename = $course_id
			? sprintf( 'atora-learning-analytics-course-%d-%s.json', $course_id, gmdate( 'Ymd-His' ) )
			: sprintf( 'atora-learning-analytics-%s-%s.json', $cohort_id ? ( 'cohort-' . $cohort_id ) : ( 'teacher-' . $teacher_id ), gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );
		echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function run_daily(): void {
		$service = new Learning_Analytics_Service();
		$service->scan_all_courses_daily();
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			'atora/v1',
			'/learning-analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_list' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view' ),
					'args'                => array(
						'course_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/learning-analytics/teacher',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_list_by_teacher' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view_teacher' ),
					'args'                => array(
						'teacher_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'limit' => array(
							'type'     => 'integer',
							'required' => false,
							'default'  => 1000,
						),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/learning-analytics/cohort',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_list_by_cohort' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view_cohort' ),
					'args'                => array(
						'cohort_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'limit' => array(
							'type'     => 'integer',
							'required' => false,
							'default'  => 1000,
						),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/learning-analytics/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_scan' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view' ),
					'args'                => array(
						'course_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'notify' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/learning-analytics/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_export' ),
					'permission_callback' => array( __CLASS__, 'rest_can_export' ),
					'args'                => array(
						'course_id' => array(
							'type'     => 'integer',
							'required' => false,
						),
						'teacher_id' => array(
							'type'     => 'integer',
							'required' => false,
						),
						'cohort_id' => array(
							'type'     => 'integer',
							'required' => false,
						),
						'limit' => array(
							'type'     => 'integer',
							'required' => false,
							'default'  => 5000,
						),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/learning-analytics/teacher/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_scan_teacher' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view_teacher' ),
					'args'                => array(
						'teacher_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'notify' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/learning-analytics/cohort/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_scan_cohort' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view_cohort' ),
					'args'                => array(
						'cohort_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'notify' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						),
					),
				),
			)
		);
	}

	public static function rest_can_view( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$course_id = absint( $request->get_param( 'course_id' ) );
		$service = new Learning_Analytics_Service();
		return $service->viewer_can_access_course( get_current_user_id(), $course_id );
	}

	public static function rest_can_export( WP_REST_Request $request ): bool {
		$course_id  = absint( $request->get_param( 'course_id' ) );
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );
		$cohort_id  = absint( $request->get_param( 'cohort_id' ) );

		if ( $course_id ) {
			return self::rest_can_view( $request );
		}

		if ( $teacher_id ) {
			return self::rest_can_view_teacher( $request );
		}

		if ( $cohort_id ) {
			return self::rest_can_view_cohort( $request );
		}

		return false;
	}

	public static function rest_can_view_teacher( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$teacher_id = absint( $request->get_param( 'teacher_id' ) );
		if ( ! $teacher_id ) {
			return false;
		}

		$viewer_id = get_current_user_id();
		if ( $viewer_id === $teacher_id ) {
			return true;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$service = new Learning_Analytics_Service();
		$course_ids = $service->get_course_ids_for_teacher( $teacher_id );
		foreach ( $course_ids as $cid ) {
			if ( $service->viewer_can_access_course( $viewer_id, absint( $cid ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function rest_can_view_cohort( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$cohort_id = absint( $request->get_param( 'cohort_id' ) );
		if ( ! $cohort_id ) {
			return false;
		}

		$viewer_id = get_current_user_id();
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( class_exists( 'CLMS_Cohort_Service' ) ) {
			$cohort_service = new \CLMS_Cohort_Service();
			$teacher_ids = (array) $cohort_service->get_cohort_teacher_ids( $cohort_id );
			$teacher_ids = array_values( array_filter( array_map( 'absint', $teacher_ids ) ) );
			if ( in_array( $viewer_id, $teacher_ids, true ) ) {
				return true;
			}
		}

		$service = new Learning_Analytics_Service();
		$course_ids = $service->get_course_ids_for_cohort( $cohort_id );
		foreach ( $course_ids as $cid ) {
			if ( $service->viewer_can_access_course( $viewer_id, absint( $cid ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function rest_list( WP_REST_Request $request ): WP_REST_Response {
		$course_id = absint( $request->get_param( 'course_id' ) );
		$service   = new Learning_Analytics_Service();
		$rows      = $service->list_course_students( $course_id );

		return new WP_REST_Response(
			array(
				'course_id' => $course_id,
				'count'     => count( $rows ),
				'rows'      => $rows,
			),
			200
		);
	}

	public static function rest_scan( WP_REST_Request $request ): WP_REST_Response {
		$course_id = absint( $request->get_param( 'course_id' ) );
		$notify    = (bool) $request->get_param( 'notify' );

		$service = new Learning_Analytics_Service();
		$upserted = $service->scan_course( $course_id, $notify );

		return new WP_REST_Response(
			array(
				'course_id' => $course_id,
				'upserted'  => $upserted,
			),
			200
		);
	}

	public static function rest_export( WP_REST_Request $request ): WP_REST_Response {
		$course_id  = absint( $request->get_param( 'course_id' ) );
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );
		$cohort_id  = absint( $request->get_param( 'cohort_id' ) );
		$limit      = absint( $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 5000;
		}

		$service   = new Learning_Analytics_Service();
		$viewer_id = get_current_user_id();

		$include_course = false;
		$rows           = array();

		if ( $course_id ) {
			if ( ! $service->viewer_can_access_course( $viewer_id, $course_id ) ) {
				return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
			}
			$rows = $service->list_course_students( $course_id );
		} else {
			$include_course = true;
			$course_ids  = array();
			$student_ids = array();

			if ( $cohort_id ) {
				$course_ids  = $service->get_course_ids_for_cohort( $cohort_id );
				$student_ids = $service->get_student_ids_for_cohort( $cohort_id );
			} elseif ( $teacher_id ) {
				$course_ids = $service->get_course_ids_for_teacher( $teacher_id );
			}

			$allowed_course_ids = array();
			foreach ( $course_ids as $cid ) {
				$cid = absint( $cid );
				if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
					$allowed_course_ids[] = $cid;
				}
			}
			$allowed_course_ids = array_values( array_unique( $allowed_course_ids ) );

			$rows = $service->list_students(
				array(
					'course_ids'  => $allowed_course_ids,
					'student_ids' => $student_ids,
					'limit'       => $limit,
				)
			);
		}

		$payload = array(
			'schema_version' => 1,
			'generated_at'   => gmdate( 'c' ),
			'filters'        => array(
				'course_id'  => $course_id,
				'teacher_id' => $teacher_id,
				'cohort_id'  => $cohort_id,
				'limit'      => $limit,
			),
			'count'          => count( $rows ),
			'rows'           => $service->build_bi_rows( $rows, array( 'include_course' => $include_course ) ),
		);

		return new WP_REST_Response( $payload, 200 );
	}

	public static function rest_list_by_teacher( WP_REST_Request $request ): WP_REST_Response {
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );
		$limit      = absint( $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 1000;
		}

		$service = new Learning_Analytics_Service();
		$course_ids = $service->get_course_ids_for_teacher( $teacher_id );

		$viewer_id = get_current_user_id();
		$allowed = array();
		foreach ( $course_ids as $cid ) {
			$cid = absint( $cid );
			if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
				$allowed[] = $cid;
			}
		}

		$rows = $service->list_students(
			array(
				'course_ids' => array_values( array_unique( $allowed ) ),
				'limit'      => $limit,
			)
		);

		return new WP_REST_Response(
			array(
				'teacher_id' => $teacher_id,
				'count'      => count( $rows ),
				'rows'       => $rows,
			),
			200
		);
	}

	public static function rest_list_by_cohort( WP_REST_Request $request ): WP_REST_Response {
		$cohort_id = absint( $request->get_param( 'cohort_id' ) );
		$limit     = absint( $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 1000;
		}

		$service = new Learning_Analytics_Service();
		$course_ids  = $service->get_course_ids_for_cohort( $cohort_id );
		$student_ids = $service->get_student_ids_for_cohort( $cohort_id );

		$viewer_id = get_current_user_id();
		$allowed = array();
		foreach ( $course_ids as $cid ) {
			$cid = absint( $cid );
			if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
				$allowed[] = $cid;
			}
		}

		$rows = $service->list_students(
			array(
				'course_ids'  => array_values( array_unique( $allowed ) ),
				'student_ids' => $student_ids,
				'limit'       => $limit,
			)
		);

		return new WP_REST_Response(
			array(
				'cohort_id' => $cohort_id,
				'count'     => count( $rows ),
				'rows'      => $rows,
			),
			200
		);
	}

	public static function rest_scan_teacher( WP_REST_Request $request ): WP_REST_Response {
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );
		$notify     = (bool) $request->get_param( 'notify' );

		$service = new Learning_Analytics_Service();
		$course_ids = $service->get_course_ids_for_teacher( $teacher_id );

		$viewer_id = get_current_user_id();
		$allowed = array();
		foreach ( $course_ids as $cid ) {
			$cid = absint( $cid );
			if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
				$allowed[] = $cid;
			}
		}

		$upserted = 0;
		foreach ( array_values( array_unique( $allowed ) ) as $cid ) {
			$upserted += $service->scan_course( absint( $cid ), $notify );
		}

		return new WP_REST_Response(
			array(
				'teacher_id' => $teacher_id,
				'courses'    => count( array_values( array_unique( $allowed ) ) ),
				'upserted'   => absint( $upserted ),
			),
			200
		);
	}

	public static function rest_scan_cohort( WP_REST_Request $request ): WP_REST_Response {
		$cohort_id = absint( $request->get_param( 'cohort_id' ) );
		$notify    = (bool) $request->get_param( 'notify' );

		$service = new Learning_Analytics_Service();
		$course_ids = $service->get_course_ids_for_cohort( $cohort_id );

		$viewer_id = get_current_user_id();
		$allowed = array();
		foreach ( $course_ids as $cid ) {
			$cid = absint( $cid );
			if ( $cid && $service->viewer_can_access_course( $viewer_id, $cid ) ) {
				$allowed[] = $cid;
			}
		}

		$upserted = 0;
		foreach ( array_values( array_unique( $allowed ) ) as $cid ) {
			$upserted += $service->scan_course( absint( $cid ), $notify );
		}

		return new WP_REST_Response(
			array(
				'cohort_id' => $cohort_id,
				'courses'   => count( array_values( array_unique( $allowed ) ) ),
				'upserted'  => absint( $upserted ),
			),
			200
		);
	}
}
