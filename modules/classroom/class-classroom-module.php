<?php
/**
 * Epic 6 — Google Classroom: módulo (admin UI + REST + acciones).
 *
 * MVP: mapear curso WP ↔ Classroom y sincronizar roster por email.
 *
 * @package ATORA_LMS
 * @since   6.18.0
 */
namespace ATORA\Classroom;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Classroom_Module {

	public static function init(): void {
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_menu' ) );
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		add_action( 'admin_post_atora_classroom_map_course', array( __CLASS__, 'handle_map_course' ) );
		add_action( 'admin_post_atora_classroom_delete_mapping', array( __CLASS__, 'handle_delete_mapping' ) );
		add_action( 'admin_post_atora_classroom_sync_roster', array( __CLASS__, 'handle_sync_roster' ) );
	}

	private static function service(): Classroom_Service {
		return new Classroom_Service();
	}

	public static function register_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Google Classroom', 'atora-lms' ),
			__( 'Google Classroom', 'atora-lms' ),
			'manage_options',
			'atora-classroom',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		$service = self::service();
		$user_id = get_current_user_id();
		$token   = $service->get_access_token( $user_id );

		$google_opts = class_exists( '\ATORA\Google\Google_Module' ) ? \ATORA\Google\Google_Module::get_options() : array();
		$classroom_enabled = ! empty( $google_opts['classroom_enabled'] );

		$notice = isset( $_GET['notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['notice'] ) ) : '';
		$message = isset( $_GET['message'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['message'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Google Classroom', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Epic 6 (MVP): mapear cursos y sincronizar roster (inscripción) por email.', 'atora-lms' ) . '</p>';

		if ( $notice ) {
			$cls = 'notice notice-info';
			if ( 'ok' === $notice ) {
				$cls = 'notice notice-success';
			} elseif ( 'error' === $notice ) {
				$cls = 'notice notice-error';
			}
			echo '<div class="' . esc_attr( $cls ) . ' inline"><p>' . esc_html( $message ? $message : $notice ) . '</p></div>';
		}

		if ( ! $classroom_enabled ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Classroom no está habilitado en los ajustes de Google. Actívalo y reconecta para añadir los scopes.', 'atora-lms' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=atora-google' ) ) . '">' . esc_html__( 'Ir a ajustes de Google', 'atora-lms' ) . '</a>';
			echo '</p></div>';
		}

		if ( ! $token ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Necesitas conectar Google primero (OAuth) para usar Classroom.', 'atora-lms' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=atora-google' ) ) . '">' . esc_html__( 'Conectar Google', 'atora-lms' ) . '</a>';
			echo '</p></div>';
		}

		$mappings = $service->list_mappings( 200 );
		echo '<h2 style="margin-top:18px">' . esc_html__( 'Mapeos', 'atora-lms' ) . '</h2>';

		if ( empty( $mappings ) ) {
			echo '<p class="description">' . esc_html__( 'Aún no hay mapeos.', 'atora-lms' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width: 1200px">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Curso (WP)', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Classroom', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Último roster sync', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Acciones', 'atora-lms' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $mappings as $m ) {
				$wp_course_id = absint( $m['wp_course_id'] ?? 0 );
				$gc_course_id = sanitize_text_field( (string) ( $m['gc_course_id'] ?? '' ) );
				$gc_name      = sanitize_text_field( (string) ( $m['gc_course_name'] ?? '' ) );
				$last_sync    = sanitize_text_field( (string) ( $m['last_roster_sync_at'] ?? '' ) );

				$sync_url = wp_nonce_url(
					add_query_arg(
						array(
							'action'       => 'atora_classroom_sync_roster',
							'wp_course_id' => $wp_course_id,
						),
						admin_url( 'admin-post.php' )
					),
					'atora_classroom_sync_roster_' . $wp_course_id
				);

				$del_url = wp_nonce_url(
					add_query_arg(
						array(
							'action'      => 'atora_classroom_delete_mapping',
							'mapping_id'  => absint( $m['id'] ?? 0 ),
						),
						admin_url( 'admin-post.php' )
					),
					'atora_classroom_delete_mapping_' . absint( $m['id'] ?? 0 )
				);

				echo '<tr>';
				echo '<td><strong>' . esc_html( get_the_title( $wp_course_id ) ?: ( '#' . $wp_course_id ) ) . '</strong><br><span class="description">#' . esc_html( (string) $wp_course_id ) . '</span></td>';
				echo '<td><strong>' . esc_html( $gc_name ?: $gc_course_id ) . '</strong><br><span class="description">' . esc_html( $gc_course_id ) . '</span></td>';
				echo '<td><span class="description">' . esc_html( $last_sync ?: '—' ) . '</span></td>';
				echo '<td>';
				echo '<a class="button button-small" href="' . esc_url( $sync_url ) . '">' . esc_html__( 'Sync roster', 'atora-lms' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url( $del_url ) . '" onclick="return confirm(\'' . esc_js( __( '¿Eliminar este mapeo?', 'atora-lms' ) ) . '\')">' . esc_html__( 'Eliminar', 'atora-lms' ) . '</a>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h2 style="margin-top:18px">' . esc_html__( 'Nuevo mapeo', 'atora-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width: 900px;">';
		echo '<input type="hidden" name="action" value="atora_classroom_map_course">';
		wp_nonce_field( 'atora_classroom_map_course' );

		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$classroom_courses = $token && $classroom_enabled ? $service->list_courses_for_user( $user_id ) : array();

		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="atora_wp_course_id">' . esc_html__( 'Curso (WP)', 'atora-lms' ) . '</label></th><td>';
		echo '<select id="atora_wp_course_id" name="wp_course_id" style="min-width: 360px; max-width: 100%;">';
		echo '<option value="0">' . esc_html__( 'Selecciona…', 'atora-lms' ) . '</option>';
		foreach ( (array) $courses as $c ) {
			if ( ! $c instanceof \WP_Post ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) absint( $c->ID ) ) . '">' . esc_html( (string) $c->post_title ) . ' (#' . esc_html( (string) absint( $c->ID ) ) . ')</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th><label for="atora_gc_course_id">' . esc_html__( 'Curso (Classroom)', 'atora-lms' ) . '</label></th><td>';
		if ( ! empty( $classroom_courses ) ) {
			echo '<select id="atora_gc_course_id" name="gc_course_id" style="min-width: 420px; max-width: 100%;">';
			echo '<option value="">' . esc_html__( 'Selecciona…', 'atora-lms' ) . '</option>';
			foreach ( (array) $classroom_courses as $cc ) {
				$id = sanitize_text_field( (string) ( $cc['gc_course_id'] ?? '' ) );
				if ( '' === $id ) {
					continue;
				}
				$label = sanitize_text_field( (string) ( $cc['name'] ?? '' ) );
				$section = sanitize_text_field( (string) ( $cc['section'] ?? '' ) );
				$txt = $label ? $label : $id;
				if ( $section ) {
					$txt .= ' — ' . $section;
				}
				$txt .= ' (' . $id . ')';
				echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $txt ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Lista obtenida desde Classroom (teacherId=me).', 'atora-lms' ) . '</p>';
		} else {
			echo '<input type="text" id="atora_gc_course_id" name="gc_course_id" value="" class="regular-text" placeholder="' . esc_attr__( 'Ej: 1234567890', 'atora-lms' ) . '">';
			echo '<p class="description">' . esc_html__( 'Si no aparece la lista, conecta Google + habilita Classroom (scopes) y vuelve a cargar.', 'atora-lms' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th><label for="atora_gc_course_name">' . esc_html__( 'Nombre (opcional)', 'atora-lms' ) . '</label></th><td>';
		echo '<input type="text" id="atora_gc_course_name" name="gc_course_name" value="" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Se autocompleta en el futuro; en MVP es opcional.', 'atora-lms' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p style="margin-top:12px">';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Guardar mapeo', 'atora-lms' ) . '</button>';
		echo '</p>';
		echo '</form>';

		echo '</div>';
	}

	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/classroom/courses', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list_courses' ),
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		) );
	}

	public static function rest_list_courses( WP_REST_Request $r ) {
		unset( $r );
		$service = self::service();
		$user_id = get_current_user_id();
		$courses = $service->list_courses_for_user( $user_id );
		return rest_ensure_response( array( 'courses' => $courses ) );
	}

	public static function handle_map_course(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		check_admin_referer( 'atora_classroom_map_course' );

		$wp_course_id = isset( $_POST['wp_course_id'] ) ? absint( wp_unslash( $_POST['wp_course_id'] ) ) : 0;
		$gc_course_id = isset( $_POST['gc_course_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['gc_course_id'] ) ) : '';
		$gc_course_name = isset( $_POST['gc_course_name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['gc_course_name'] ) ) : '';

		if ( ! $wp_course_id || '' === trim( $gc_course_id ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'atora-classroom', 'notice' => 'error', 'message' => rawurlencode( __( 'wp_course_id y gc_course_id son requeridos.', 'atora-lms' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$ok = self::service()->upsert_mapping( $wp_course_id, $gc_course_id, $gc_course_name, get_current_user_id() );
		$args = array(
			'page'    => 'atora-classroom',
			'notice'  => $ok ? 'ok' : 'error',
			'message' => rawurlencode( $ok ? __( 'Mapeo guardado.', 'atora-lms' ) : __( 'No se pudo guardar el mapeo.', 'atora-lms' ) ),
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete_mapping(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		$mapping_id = isset( $_GET['mapping_id'] ) ? absint( wp_unslash( $_GET['mapping_id'] ) ) : 0;
		if ( ! $mapping_id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'atora-classroom', 'notice' => 'error', 'message' => rawurlencode( __( 'mapping_id requerido.', 'atora-lms' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		check_admin_referer( 'atora_classroom_delete_mapping_' . $mapping_id );
		$ok = self::service()->delete_mapping( $mapping_id );

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'atora-classroom',
			'notice'  => $ok ? 'ok' : 'error',
			'message' => rawurlencode( $ok ? __( 'Mapeo eliminado.', 'atora-lms' ) : __( 'No se pudo eliminar el mapeo.', 'atora-lms' ) ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_sync_roster(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		$wp_course_id = isset( $_GET['wp_course_id'] ) ? absint( wp_unslash( $_GET['wp_course_id'] ) ) : 0;
		if ( ! $wp_course_id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'atora-classroom', 'notice' => 'error', 'message' => rawurlencode( __( 'wp_course_id requerido.', 'atora-lms' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		check_admin_referer( 'atora_classroom_sync_roster_' . $wp_course_id );

		$service = self::service();
		$res = $service->sync_roster_by_email( $wp_course_id, get_current_user_id(), false );

		$msg = sprintf(
			/* translators: 1: enrolled 2: already 3: missing */
			__( 'Roster sync: inscritos=%1$d, ya inscritos=%2$d, faltantes=%3$d.', 'atora-lms' ),
			(int) ( $res['enrolled'] ?? 0 ),
			(int) ( $res['already_enrolled'] ?? 0 ),
			(int) ( $res['missing'] ?? 0 )
		);

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'atora-classroom',
			'notice'  => 'ok',
			'message' => rawurlencode( $msg ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}

