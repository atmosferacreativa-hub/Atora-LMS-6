<?php
/**
 * ATORA LMS — Groups / Group Assessment
 *
 * @package ATORA_LMS
 * @since   6.13.3
 */

namespace ATORA\Groups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Groups_Module {

	public static function init(): void {
		add_filter( 'atora/groups/user_group_id', array( __CLASS__, 'resolve_user_group_id' ), 10, 4 );

		add_action( 'atora/groups/master_submission_saved', array( __CLASS__, 'on_master_submission_saved' ), 10, 5 );
		add_action( 'clms_submission_graded', array( __CLASS__, 'on_submission_graded' ), 20, 5 );

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'admin_post_atora_groups_export', array( __CLASS__, 'handle_export' ) );
		}
	}

	/**
	 * Resolve group_id for (user, course) with optional lesson scope.
	 *
	 * @param int $group_id
	 * @param int $user_id
	 * @param int $course_id
	 * @param int $lesson_id
	 * @return int
	 */
	public static function resolve_user_group_id( int $group_id, int $user_id, int $course_id, int $lesson_id = 0 ): int {
		$service = new Group_Service();
		$found   = $service->get_user_group_id( $user_id, $course_id, $lesson_id );
		return $found ? $found : $group_id;
	}

	/**
	 * Ensures per-student shadow submissions exist when a group master submission is saved.
	 *
	 * @param int $group_id
	 * @param int $master_submission_id
	 * @param int $lesson_id
	 * @param int $course_id
	 * @param int $actor_user_id
	 * @return void
	 */
	public static function on_master_submission_saved( int $group_id, int $master_submission_id, int $lesson_id, int $course_id, int $actor_user_id ): void {
		$group_id            = absint( $group_id );
		$master_submission_id = absint( $master_submission_id );
		$lesson_id           = absint( $lesson_id );
		$course_id           = absint( $course_id );
		$actor_user_id       = absint( $actor_user_id );

		if ( ! $group_id || ! $master_submission_id || ! $lesson_id || ! $course_id ) {
			return;
		}

		$service    = new Group_Service();
		$member_ids = $service->get_group_member_ids( $group_id );
		if ( empty( $member_ids ) ) {
			return;
		}

		$service->lock_group_if_needed( $group_id, $actor_user_id );

		foreach ( $member_ids as $member_id ) {
			$member_id = absint( $member_id );
			if ( ! $member_id ) {
				continue;
			}
			$shadow_id = $service->ensure_shadow_submission(
				$member_id,
				$lesson_id,
				$course_id,
				$group_id,
				$master_submission_id
			);
			if ( $shadow_id ) {
				$service->sync_shadow_grade_from_master( $shadow_id, $master_submission_id );
			}
		}

		$service->record_group_submission( $group_id, $lesson_id, $master_submission_id, $actor_user_id );
	}

	/**
	 * Propagates grades from master submission to member shadow submissions, applying per-student overrides.
	 */
	public static function on_submission_graded( int $submission_id, int $student_id, string $status = '', $grade = '', string $feedback = '' ): void {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return;
		}

		$is_master = (string) get_post_meta( $submission_id, '_clms_submission_group_master', true );
		if ( '1' !== $is_master ) {
			return;
		}

		$group_id  = absint( get_post_meta( $submission_id, '_clms_submission_group_id', true ) );
		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $group_id || ! $lesson_id || ! $course_id ) {
			return;
		}

		$service    = new Group_Service();
		$member_ids = $service->get_group_member_ids( $group_id );
		if ( empty( $member_ids ) ) {
			return;
		}

		$base_grade = ( '' !== (string) $grade && is_numeric( $grade ) ) ? max( 0, min( 100, (int) round( (float) $grade ) ) ) : '';

		foreach ( $member_ids as $member_id ) {
			$member_id = absint( $member_id );
			if ( ! $member_id ) {
				continue;
			}
			$shadow_id = $service->find_shadow_submission_id( $member_id, $lesson_id, $group_id, $submission_id );
			if ( ! $shadow_id ) {
				$shadow_id = $service->ensure_shadow_submission( $member_id, $lesson_id, $course_id, $group_id, $submission_id );
			}

			if ( ! $shadow_id ) {
				continue;
			}

			$final_grade = $service->apply_override_if_any( $group_id, $lesson_id, $member_id, $base_grade );
			$service->publish_shadow_grade( $shadow_id, $final_grade, $feedback );
		}
	}

	// ── Admin ────────────────────────────────────────────────────────────────

	public static function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Grupos', 'atora-lms' ),
			__( 'Grupos', 'atora-lms' ),
			'edit_posts',
			'atora-groups',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;

		echo '<div class="wrap"><h1>' . esc_html__( 'Grupos', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Gestiona grupos por curso y exporta reportes de evaluación grupal.', 'atora-lms' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="atora-groups">';
		echo '<label><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="course_id" value="' . esc_attr( (string) $course_id ) . '" min="1" style="width:180px">';
		echo '<button class="button button-primary" type="submit" style="margin-left:8px">' . esc_html__( 'Cargar', 'atora-lms' ) . '</button>';
		echo '</form>';

		if ( $course_id ) {
			$export_url = admin_url( 'admin-post.php?action=atora_groups_export&course_id=' . $course_id );
			echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Exportar CSV (grupal + individual)', 'atora-lms' ) . '</a></p>';
		}

		echo '</div>';
	}

	public static function handle_export(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		if ( ! $course_id ) {
			wp_die( esc_html__( 'course_id inválido.', 'atora-lms' ) );
		}

		$service = new Group_Report_Service();
		$csv     = $service->export_course_csv( $course_id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="atora-grupos-curso-' . $course_id . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ── REST ────────────────────────────────────────────────────────────────

	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/groups', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list_groups' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$course_id = absint( $r->get_param( 'course_id' ) );
				return self::can_manage_course( $course_id );
			},
			'args'                => array(
				'course_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
			),
		) );

		register_rest_route( 'atora/v1', '/groups', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_create_group' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$course_id = absint( $r->get_param( 'course_id' ) );
				return self::can_manage_course( $course_id );
			},
		) );

		register_rest_route( 'atora/v1', '/groups/(?P<group_id>\\d+)/members', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_set_members' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$group_id = absint( $r->get_param( 'group_id' ) );
				$service  = new Group_Service();
				$course_id = $service->get_group_course_id( $group_id );
				return self::can_manage_course( $course_id );
			},
		) );

		register_rest_route( 'atora/v1', '/groups/(?P<group_id>\\d+)/overrides', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_set_override' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$group_id = absint( $r->get_param( 'group_id' ) );
				$service  = new Group_Service();
				$course_id = $service->get_group_course_id( $group_id );
				return self::can_manage_course( $course_id );
			},
		) );
	}

	private static function can_manage_course( int $course_id ): bool {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return false;
		}
		if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
			return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
		}
		return current_user_can( 'edit_posts' );
	}

	public static function rest_list_groups( \WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		$service   = new Group_Service();
		return rest_ensure_response( $service->list_groups( $course_id ) );
	}

	public static function rest_create_group( \WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		$name      = sanitize_text_field( (string) $r->get_param( 'name' ) );
		$service   = new Group_Service();
		$group_id  = $service->create_group( $course_id, $name, get_current_user_id() );
		if ( ! $group_id ) {
			return new \WP_Error( 'create_failed', __( 'No se pudo crear el grupo.', 'atora-lms' ) );
		}
		return rest_ensure_response( array( 'group_id' => $group_id ) );
	}

	public static function rest_set_members( \WP_REST_Request $r ) {
		$group_id  = absint( $r->get_param( 'group_id' ) );
		$members   = (array) $r->get_param( 'members' );
		$members   = array_values( array_unique( array_filter( array_map( 'absint', $members ) ) ) );
		$service   = new Group_Service();
		$result    = $service->set_members( $group_id, $members, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function rest_set_override( \WP_REST_Request $r ) {
		$group_id   = absint( $r->get_param( 'group_id' ) );
		$lesson_id  = absint( $r->get_param( 'lesson_id' ) );
		$student_id = absint( $r->get_param( 'student_id' ) );
		$grade      = $r->get_param( 'override_grade' );
		$reason     = sanitize_textarea_field( (string) $r->get_param( 'reason' ) );

		$service = new Group_Service();
		$result  = $service->set_override( $group_id, $lesson_id, $student_id, $grade, $reason, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
