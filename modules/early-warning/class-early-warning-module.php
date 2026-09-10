<?php
/**
 * ATORA LMS — Early Warning (docente)
 *
 * MVP: señales por entregas perdidas (lessons con due_date vencida sin submission).
 *
 * @package ATORA_LMS
 * @since   6.13.3
 */

namespace ATORA\EarlyWarning;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Early_Warning_Module {

	const CRON_HOOK = 'atora_early_warning_daily_cron';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', self::CRON_HOOK );
		}

		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'admin_post_atora_early_warning_scan', array( __CLASS__, 'handle_scan_now' ) );
			add_action( 'admin_post_atora_early_warning_export', array( __CLASS__, 'handle_export_csv' ) );
			add_action( 'admin_post_atora_early_warning_resolve', array( __CLASS__, 'handle_resolve' ) );
		}
	}

	public static function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Alertas tempranas', 'atora-lms' ),
			__( 'Alertas tempranas', 'atora-lms' ),
			'edit_posts',
			'atora-early-warning',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;

		echo '<div class="wrap"><h1>' . esc_html__( 'Alertas tempranas', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'MVP: entregas perdidas por estudiante en un curso.', 'atora-lms' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="atora-early-warning">';
		echo '<label><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="course_id" value="' . esc_attr( (string) $course_id ) . '" min="1" style="width:180px">';
		echo '<button class="button button-primary" type="submit" style="margin-left:8px">' . esc_html__( 'Cargar', 'atora-lms' ) . '</button>';
		echo '</form>';

		if ( ! $course_id ) {
			echo '<p class="description">' . esc_html__( 'Indica un course_id para ver las alertas.', 'atora-lms' ) . '</p>';
			echo '</div>';
			return;
		}

		$scan_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=atora_early_warning_scan&course_id=' . $course_id ),
			'atora_early_warning_scan_' . $course_id
		);
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=atora_early_warning_export&course_id=' . $course_id ),
			'atora_early_warning_export_' . $course_id
		);

		echo '<p style="margin: 10px 0">';
		echo '<a class="button button-secondary" href="' . esc_url( $scan_url ) . '">' . esc_html__( 'Escanear ahora', 'atora-lms' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Exportar CSV', 'atora-lms' ) . '</a>';
		echo '</p>';

		$service = new Early_Warning_Service();
		$rows    = $service->list_course_warnings( $course_id, 'open' );

		if ( empty( $rows ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'No hay alertas abiertas para este curso.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width: 1050px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Estudiante', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Entregas perdidas', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Detalle', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Severidad', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Última notificación', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acciones', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user_id      = absint( $row['user_id'] ?? 0 );
			$student_name = (string) ( $row['student_name'] ?? '' );
			$student_mail = (string) ( $row['student_email'] ?? '' );
			$type         = sanitize_key( (string) ( $row['warning_type'] ?? '' ) );
			$severity     = absint( $row['severity'] ?? 0 );
			$data         = is_array( $row['data'] ?? null ) ? (array) $row['data'] : array();
			$missed_count = absint( $data['count'] ?? 0 );
			$last_notify  = (string) ( $row['last_notified_at'] ?? '' );
			$missed_items = isset( $data['missed'] ) && is_array( $data['missed'] ) ? (array) $data['missed'] : array();

			$resolve_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=atora_early_warning_resolve&course_id=' . $course_id . '&user_id=' . $user_id . '&warning_type=' . rawurlencode( $type ) ),
				'atora_early_warning_resolve_' . $course_id . '_' . $user_id . '_' . $type
			);

			$details = array();
			foreach ( array_slice( $missed_items, 0, 5 ) as $m ) {
				$lesson_id = absint( $m['lesson_id'] ?? 0 );
				$ts        = absint( $m['deadline_ts'] ?? 0 );
				if ( ! $lesson_id ) {
					continue;
				}
				$title = (string) get_the_title( $lesson_id );
				$date  = $ts ? date_i18n( 'Y-m-d', $ts ) : '';
				$details[] = ( $title ? $title : ( '#' . $lesson_id ) ) . ( $date ? ( ' (' . $date . ')' ) : '' );
			}
			$details_label = $details ? implode( ' | ', array_map( 'sanitize_text_field', $details ) ) : '—';
			if ( $missed_count > 5 ) {
				$details_label .= ' …';
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $student_name ? $student_name : (string) $user_id ) . '</strong><br><span class="description">' . esc_html( $student_mail ) . '</span></td>';
			echo '<td>' . esc_html( (string) $missed_count ) . '</td>';
			echo '<td><span class="description">' . esc_html( $details_label ) . '</span></td>';
			echo '<td>' . esc_html( (string) $severity ) . '</td>';
			echo '<td>' . esc_html( $last_notify ? $last_notify : '—' ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $resolve_url ) . '">' . esc_html__( 'Marcar resuelto', 'atora-lms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:12px">' . esc_html__( 'REST: GET /atora/v1/early-warning?course_id=123', 'atora-lms' ) . '</p>';
		echo '</div>';
	}

	public static function handle_scan_now(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		if ( ! $course_id ) {
			wp_die( esc_html__( 'course_id inválido.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_early_warning_scan_' . $course_id );

		$service = new Early_Warning_Service();
		$service->scan_course( $course_id, true );

		wp_safe_redirect( admin_url( 'admin.php?page=atora-early-warning&course_id=' . $course_id . '&scanned=1' ) );
		exit;
	}

	public static function handle_export_csv(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		if ( ! $course_id ) {
			wp_die( esc_html__( 'course_id inválido.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_early_warning_export_' . $course_id );

		$service = new Early_Warning_Service();
		$rows    = $service->list_course_warnings( $course_id, 'open' );

		$csv  = "user_id,student_name,student_email,warning_type,missed_count,missed_lessons,severity,status,created_at,updated_at\n";
		foreach ( (array) $rows as $row ) {
			$data = is_array( $row['data'] ?? null ) ? (array) $row['data'] : array();
			$missed_items = isset( $data['missed'] ) && is_array( $data['missed'] ) ? (array) $data['missed'] : array();
			$missed_lessons = array();
			foreach ( $missed_items as $m ) {
				$lesson_id = absint( $m['lesson_id'] ?? 0 );
				if ( $lesson_id ) {
					$missed_lessons[] = (string) $lesson_id;
				}
			}
			$missed_lessons_str = implode( '|', array_values( array_unique( $missed_lessons ) ) );
			$csv .= sprintf(
				"%d,%s,%s,%s,%d,%s,%d,%s,%s,%s\n",
				absint( $row['user_id'] ?? 0 ),
				str_replace( '"', '""', '"' . \CLMS_Helper::csv_safe_field( $row['student_name'] ?? '' ) . '"' ),
				str_replace( '"', '""', '"' . \CLMS_Helper::csv_safe_field( $row['student_email'] ?? '' ) . '"' ),
				(string) ( $row['warning_type'] ?? '' ),
				absint( $data['count'] ?? 0 ),
				str_replace( '"', '""', '"' . $missed_lessons_str . '"' ),
				absint( $row['severity'] ?? 0 ),
				(string) ( $row['status'] ?? '' ),
				(string) ( $row['created_at'] ?? '' ),
				(string) ( $row['updated_at'] ?? '' )
			);
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="atora-early-warning-curso-' . $course_id . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function handle_resolve(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id    = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$user_id      = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$warning_type = isset( $_GET['warning_type'] ) ? sanitize_key( wp_unslash( $_GET['warning_type'] ) ) : '';

		if ( ! $course_id || ! $user_id || '' === $warning_type ) {
			wp_die( esc_html__( 'Parámetros inválidos.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_early_warning_resolve_' . $course_id . '_' . $user_id . '_' . $warning_type );

		$service = new Early_Warning_Service();
		$service->resolve_warning( $course_id, $user_id, $warning_type );

		wp_safe_redirect( admin_url( 'admin.php?page=atora-early-warning&course_id=' . $course_id . '&resolved=1' ) );
		exit;
	}

	// ── Cron ─────────────────────────────────────────────────────────────────

	public static function run_daily(): void {
		$service = new Early_Warning_Service();
		$service->scan_and_notify();
	}

	// ── REST ─────────────────────────────────────────────────────────────────

	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/early-warning', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$course_id = absint( $r->get_param( 'course_id' ) );
				if ( ! $course_id ) {
					return false;
				}
				if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
					return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
				}
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'course_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
			),
		) );

		register_rest_route( 'atora/v1', '/early-warning/scan', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_scan' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$course_id = absint( $r->get_param( 'course_id' ) );
				if ( ! $course_id ) {
					return false;
				}
				if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
					return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
				}
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'course_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
				'notify'    => array( 'sanitize_callback' => static fn( $v ) => (bool) $v, 'required' => false ),
			),
		) );

		register_rest_route( 'atora/v1', '/early-warning/resolve', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_resolve' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$course_id = absint( $r->get_param( 'course_id' ) );
				if ( ! $course_id ) {
					return false;
				}
				if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
					return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
				}
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'course_id'    => array( 'sanitize_callback' => 'absint', 'required' => true ),
				'user_id'      => array( 'sanitize_callback' => 'absint', 'required' => true ),
				'warning_type' => array( 'sanitize_callback' => 'sanitize_key', 'required' => true ),
			),
		) );
	}

	public static function rest_list( \WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		$service   = new Early_Warning_Service();
		return rest_ensure_response( $service->list_course_warnings( $course_id, 'open' ) );
	}

	public static function rest_scan( \WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		$notify    = (bool) $r->get_param( 'notify' );
		$service   = new Early_Warning_Service();
		$service->scan_course( $course_id, $notify );
		return rest_ensure_response( array(
			'success'   => true,
			'course_id' => $course_id,
			'warnings'  => $service->list_course_warnings( $course_id, 'open' ),
		) );
	}

	public static function rest_resolve( \WP_REST_Request $r ) {
		$course_id    = absint( $r->get_param( 'course_id' ) );
		$user_id      = absint( $r->get_param( 'user_id' ) );
		$warning_type = sanitize_key( (string) $r->get_param( 'warning_type' ) );
		$service      = new Early_Warning_Service();
		$ok           = $service->resolve_warning( $course_id, $user_id, $warning_type );
		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}
}
