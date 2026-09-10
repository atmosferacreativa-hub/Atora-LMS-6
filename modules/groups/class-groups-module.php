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
			add_action( 'admin_post_atora_groups_create', array( __CLASS__, 'handle_create_group' ) );
			add_action( 'admin_post_atora_groups_save_members', array( __CLASS__, 'handle_save_members' ) );
			add_action( 'admin_post_atora_groups_autogenerate', array( __CLASS__, 'handle_autogenerate' ) );
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
		echo '<p class="description">' . esc_html__( 'Gestiona grupos por curso. Para actividades grupales, el docente activa "Trabajo en grupo" en la lección y usa los grupos del curso.', 'atora-lms' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="atora-groups">';
		echo '<label><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="course_id" value="' . esc_attr( (string) $course_id ) . '" min="1" style="width:180px">';
		echo '<button class="button button-primary" type="submit" style="margin-left:8px">' . esc_html__( 'Cargar', 'atora-lms' ) . '</button>';
		echo '</form>';

		if ( ! $course_id ) {
			echo '</div>';
			return;
		}

		$export_url = admin_url( 'admin-post.php?action=atora_groups_export&course_id=' . $course_id );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Exportar CSV (grupal + individual)', 'atora-lms' ) . '</a></p>';

		if ( ! class_exists( '\CLMS_Helper' ) || ! method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No se pudo cargar la lista de estudiantes del curso.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$students = array();
		foreach ( (array) \CLMS_Helper::get_enrolled_student_ids( $course_id ) as $sid ) {
			$sid = absint( $sid );
			if ( ! $sid ) {
				continue;
			}
			$u = get_userdata( $sid );
			if ( ! $u ) {
				continue;
			}
			$students[ $sid ] = (string) ( $u->display_name ?? $u->user_login ?? (string) $sid );
		}
		asort( $students );

		$service = new Group_Service();
		$groups  = $service->list_groups( $course_id );

		// Create group form.
		$create_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=atora_groups_create&course_id=' . $course_id ),
			'atora_groups_create_' . $course_id
		);
		echo '<h2 style="margin-top:18px">' . esc_html__( 'Crear grupo', 'atora-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $create_url ) . '" style="margin:10px 0;display:flex;gap:8px;align-items:center;max-width:760px">';
		echo '<input type="text" name="group_name" placeholder="' . esc_attr__( 'Nombre del grupo', 'atora-lms' ) . '" style="min-width:280px" required>';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Crear', 'atora-lms' ) . '</button>';
		echo '</form>';

		// Autogenerate groups (safe): only when no groups exist yet.
		echo '<h2 style="margin-top:18px">' . esc_html__( 'Autogenerar (preset)', 'atora-lms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Crea grupos automáticamente solo si el curso aún no tiene grupos.', 'atora-lms' ) . '</p>';
		$auto_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=atora_groups_autogenerate&course_id=' . $course_id ),
			'atora_groups_autogenerate_' . $course_id
		);
		echo '<form method="post" action="' . esc_url( $auto_url ) . '" style="margin:10px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap;max-width:900px">';
		echo '<label><strong>' . esc_html__( 'Tamaño del grupo', 'atora-lms' ) . '</strong> ';
		echo '<input type="number" name="group_size" value="3" min="2" max="10" style="width:90px;margin-left:6px"></label>';
		echo '<label><strong>' . esc_html__( 'Estrategia', 'atora-lms' ) . '</strong> ';
		echo '<select name="strategy" style="margin-left:6px">';
		echo '<option value="random">' . esc_html__( 'Aleatorio', 'atora-lms' ) . '</option>';
		echo '<option value="sequential">' . esc_html__( 'Ordenado (A–Z)', 'atora-lms' ) . '</option>';
		echo '</select></label>';
		echo '<button class="button" type="submit">' . esc_html__( 'Crear y asignar', 'atora-lms' ) . '</button>';
		echo '</form>';

		echo '<h2 style="margin-top:18px">' . esc_html__( 'Grupos del curso', 'atora-lms' ) . '</h2>';

		if ( empty( $groups ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Aún no hay grupos para este curso.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:1100px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Grupo', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Miembros', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Editar', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( (array) $groups as $group ) {
			$group_id  = absint( $group['id'] ?? 0 );
			$name      = (string) ( $group['name'] ?? '' );
			$locked_at = (string) ( $group['locked_at'] ?? '' );
			$member_ids = $service->get_group_member_ids( $group_id );
			$member_labels = array();
			foreach ( (array) $member_ids as $mid ) {
				$mid = absint( $mid );
				if ( $mid && isset( $students[ $mid ] ) ) {
					$member_labels[] = $students[ $mid ];
				}
			}

			$save_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=atora_groups_save_members&course_id=' . $course_id . '&group_id=' . $group_id ),
				'atora_groups_save_members_' . $course_id . '_' . $group_id
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( $name ? $name : (string) $group_id ) . '</strong><br><span class="description">ID ' . esc_html( (string) $group_id ) . '</span></td>';
			echo '<td>' . ( $locked_at ? '<span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__( 'Bloqueado (con entregas)', 'atora-lms' ) : esc_html__( 'Abierto', 'atora-lms' ) ) . '</td>';
			echo '<td>' . esc_html( implode( ' · ', $member_labels ) ) . '</td>';
			echo '<td>';
			echo '<details><summary>' . esc_html__( 'Editar miembros', 'atora-lms' ) . '</summary>';
			echo '<form method="post" action="' . esc_url( $save_url ) . '" style="margin-top:10px">';
			echo '<div style="max-height:240px;overflow:auto;border:1px solid #e5e7eb;padding:10px;background:#fff">';
			foreach ( $students as $sid => $label ) {
				$checked = in_array( absint( $sid ), $member_ids, true ) ? ' checked' : '';
				echo '<label style="display:block;margin:4px 0">';
				echo '<input type="checkbox" name="members[]" value="' . esc_attr( (string) $sid ) . '"' . $checked . '> ';
				echo esc_html( $label ) . ' <span class="description">(' . esc_html( (string) $sid ) . ')</span>';
				echo '</label>';
			}
			echo '</div>';

			if ( $locked_at ) {
				echo '<p class="description" style="margin:8px 0">' . esc_html__( 'Este grupo está bloqueado por entregas. Por defecto no se puede editar.', 'atora-lms' ) . '</p>';
				echo '<label style="display:block;margin:6px 0"><input type="checkbox" name="force_add" value="1"> ' . esc_html__( 'Forzar: permitir agregar miembros (no remover)', 'atora-lms' ) . '</label>';
			}

			echo '<p style="margin:8px 0"><button class="button button-primary" type="submit">' . esc_html__( 'Guardar', 'atora-lms' ) . '</button></p>';
			echo '</form>';
			echo '</details>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '</div>';
	}

	public static function handle_create_group(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		if ( ! $course_id ) {
			wp_die( esc_html__( 'course_id inválido.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_groups_create_' . $course_id );

		$name = isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';
		if ( '' === trim( $name ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=atora-groups&course_id=' . $course_id . '&error=missing_name' ) );
			exit;
		}

		$service  = new Group_Service();
		$group_id = $service->create_group( $course_id, $name, get_current_user_id() );

		wp_safe_redirect( admin_url( 'admin.php?page=atora-groups&course_id=' . $course_id . '&created=' . absint( $group_id ) ) );
		exit;
	}

	public static function handle_save_members(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$group_id  = isset( $_GET['group_id'] ) ? absint( wp_unslash( $_GET['group_id'] ) ) : 0;
		if ( ! $course_id || ! $group_id ) {
			wp_die( esc_html__( 'Parámetros inválidos.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_groups_save_members_' . $course_id . '_' . $group_id );

		$members   = isset( $_POST['members'] ) ? (array) wp_unslash( $_POST['members'] ) : array();
		$members   = array_values( array_unique( array_filter( array_map( 'absint', $members ) ) ) );
		$force_add = isset( $_POST['force_add'] ) ? ( '1' === (string) wp_unslash( $_POST['force_add'] ) ) : false;

		$service = new Group_Service();
		$result  = $service->set_members_with_options(
			$group_id,
			$members,
			get_current_user_id(),
			array( 'force_add' => (bool) $force_add )
		);

		$flag = is_wp_error( $result ) ? ( 'error=' . rawurlencode( $result->get_error_code() ) ) : 'saved=1';
		wp_safe_redirect( admin_url( 'admin.php?page=atora-groups&course_id=' . $course_id . '&' . $flag ) );
		exit;
	}

	public static function handle_autogenerate(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		if ( ! $course_id ) {
			wp_die( esc_html__( 'course_id inválido.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_groups_autogenerate_' . $course_id );

		if ( ! class_exists( '\CLMS_Helper' ) || ! method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=atora-groups&course_id=' . $course_id . '&error=no_students' ) );
			exit;
		}

		$group_size = isset( $_POST['group_size'] ) ? absint( wp_unslash( $_POST['group_size'] ) ) : 3;
		$group_size = max( 2, min( 10, $group_size ) );
		$strategy   = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'random';

		$service = new Group_Service();
		$existing = $service->list_groups( $course_id );
		if ( ! empty( $existing ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=atora-groups&course_id=' . $course_id . '&error=already_has_groups' ) );
			exit;
		}

		$students = array_values( array_filter( array_map( 'absint', (array) \CLMS_Helper::get_enrolled_student_ids( $course_id ) ) ) );
		if ( empty( $students ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=atora-groups&course_id=' . $course_id . '&error=no_students' ) );
			exit;
		}

		if ( 'random' === $strategy ) {
			shuffle( $students );
		} else {
			sort( $students );
		}

		$chunks = array_chunk( $students, $group_size );
		$i = 1;
		foreach ( $chunks as $chunk ) {
			$group_id = $service->create_group( $course_id, sprintf( __( 'Grupo %d', 'atora-lms' ), $i ), get_current_user_id() );
			if ( $group_id ) {
				$service->set_members_with_options( $group_id, $chunk, get_current_user_id(), array() );
			}
			$i++;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=atora-groups&course_id=' . $course_id . '&autogen=1' ) );
		exit;
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
		$force_add = (bool) $r->get_param( 'force_add' );
		$service   = new Group_Service();
		$result    = $service->set_members_with_options( $group_id, $members, get_current_user_id(), array( 'force_add' => $force_add ) );
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
