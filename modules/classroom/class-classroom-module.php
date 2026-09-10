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
		add_action( 'admin_post_atora_classroom_import_coursework', array( __CLASS__, 'handle_import_coursework' ) );
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

		$detail_wp_course_id = isset( $_GET['wp_course_id'] ) ? absint( wp_unslash( $_GET['wp_course_id'] ) ) : 0;

		$notice = isset( $_GET['notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['notice'] ) ) : '';
		$message = isset( $_GET['message'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['message'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Google Classroom', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Epic 6 (MVP): mapear cursos, sincronizar roster (solo altas) e importar tareas (coursework).', 'atora-lms' ) . '</p>';

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

		if ( $detail_wp_course_id ) {
			self::render_course_detail( $detail_wp_course_id, $service, $user_id, (bool) $token, (bool) $classroom_enabled );
			echo '</div>';
			return;
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

				$detail_url = add_query_arg(
					array(
						'page'         => 'atora-classroom',
						'wp_course_id' => $wp_course_id,
					),
					admin_url( 'admin.php' )
				);

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
				echo '<a class="button button-small" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Tareas', 'atora-lms' ) . '</a> ';
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

	private static function render_course_detail( int $wp_course_id, Classroom_Service $service, int $actor_user_id, bool $has_token, bool $classroom_enabled ): void {
		$map = $service->get_mapping_by_wp_course( $wp_course_id );
		if ( empty( $map ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Curso no mapeado.', 'atora-lms' ) . '</p></div>';
			return;
		}

		$gc_course_id = sanitize_text_field( (string) ( $map['gc_course_id'] ?? '' ) );
		$gc_name      = sanitize_text_field( (string) ( $map['gc_course_name'] ?? '' ) );

		$back_url = add_query_arg( array( 'page' => 'atora-classroom' ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back_url ) . '">← ' . esc_html__( 'Volver', 'atora-lms' ) . '</a></p>';

		echo '<h2 style="margin-top:0">' . esc_html__( 'Importar tareas (Coursework)', 'atora-lms' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Importa tareas de Classroom como lecciones en el curso WP. Idempotente: no duplica si ya fue importado.', 'atora-lms' ) . '</p>';

		echo '<div style="padding: 12px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; max-width: 1100px;">';
		echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html__( 'Curso (WP):', 'atora-lms' ) . '</strong> ' . esc_html( get_the_title( $wp_course_id ) ?: ( '#' . $wp_course_id ) ) . ' <span class="description">#' . esc_html( (string) $wp_course_id ) . '</span></p>';
		echo '<p style="margin:0;"><strong>' . esc_html__( 'Classroom:', 'atora-lms' ) . '</strong> ' . esc_html( $gc_name ?: $gc_course_id ) . ' <span class="description">' . esc_html( $gc_course_id ) . '</span></p>';
		echo '</div>';

		if ( ! $classroom_enabled ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Classroom no está habilitado en Google → activa el toggle y reconecta para añadir scopes.', 'atora-lms' ) . '</p></div>';
			return;
		}
		if ( ! $has_token ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Conecta Google primero para listar tareas.', 'atora-lms' ) . '</p></div>';
			return;
		}

		$imported = $service->get_coursework_map_for_course( $wp_course_id );
		$coursework = $service->list_coursework( $gc_course_id, $actor_user_id );

		if ( empty( $coursework ) ) {
			echo '<p class="description" style="margin-top:12px;">' . esc_html__( 'No se encontraron tareas en Classroom (o faltan permisos/scopes).', 'atora-lms' ) . '</p>';
			return;
		}

		$form_action = admin_url( 'admin-post.php' );
		echo '<form method="post" action="' . esc_url( $form_action ) . '" style="margin-top:14px; max-width: 1200px;">';
		echo '<input type="hidden" name="action" value="atora_classroom_import_coursework">';
		echo '<input type="hidden" name="wp_course_id" value="' . esc_attr( (string) $wp_course_id ) . '">';
		wp_nonce_field( 'atora_classroom_import_coursework_' . $wp_course_id );

		echo '<p style="margin:0 0 10px 0;">';
		echo '<label><strong>' . esc_html__( 'Crear como', 'atora-lms' ) . '</strong></label> ';
		echo '<select name="post_status">';
		echo '<option value="draft">' . esc_html__( 'Borrador (recomendado)', 'atora-lms' ) . '</option>';
		echo '<option value="publish">' . esc_html__( 'Publicado', 'atora-lms' ) . '</option>';
		echo '</select>';
		echo ' <span class="description">' . esc_html__( 'El contenido se actualiza si ya existe el mapeo.', 'atora-lms' ) . '</span>';
		echo '</p>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th style="width: 32px;"></th>';
		echo '<th>' . esc_html__( 'Título', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Vence', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Importado', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $coursework as $cw ) {
			$gc_id = sanitize_text_field( (string) ( $cw['gc_coursework_id'] ?? '' ) );
			if ( '' === $gc_id ) {
				continue;
			}
			$title = sanitize_text_field( (string) ( $cw['title'] ?? '' ) );
			$due_date = sanitize_text_field( (string) ( $cw['due_date'] ?? '' ) );
			$due_time = sanitize_text_field( (string) ( $cw['due_time'] ?? '' ) );
			$state = sanitize_key( (string) ( $cw['state'] ?? '' ) );

			$imp = isset( $imported[ $gc_id ] ) ? (array) $imported[ $gc_id ] : array();
			$wp_lesson_id = absint( $imp['wp_lesson_id'] ?? 0 );

			$due_label = $due_date ? $due_date : '—';
			if ( $due_date && $due_time ) {
				$due_label .= ' ' . $due_time;
			}

			$lesson_link = $wp_lesson_id ? get_edit_post_link( $wp_lesson_id, '' ) : '';

			echo '<tr>';
			echo '<td><input type="checkbox" name="gc_coursework_ids[]" value="' . esc_attr( $gc_id ) . '"></td>';
			echo '<td><strong>' . esc_html( $title ?: $gc_id ) . '</strong><br><span class="description">' . esc_html( $gc_id ) . '</span></td>';
			echo '<td><span class="description">' . esc_html( $due_label ) . '</span></td>';
			echo '<td><span class="description">' . esc_html( $state ?: '—' ) . '</span></td>';
			echo '<td>';
			if ( $wp_lesson_id && $lesson_link ) {
				echo '<a href="' . esc_url( $lesson_link ) . '">' . esc_html__( 'Lección', 'atora-lms' ) . '</a> <span class="description">#' . esc_html( (string) $wp_lesson_id ) . '</span>';
			} else {
				echo '<span class="description">—</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p style="margin-top:10px">';
		echo '<button type="submit" class="button button-primary" onclick="return confirm(\'' . esc_js( __( '¿Importar las tareas seleccionadas?', 'atora-lms' ) ) . '\')">' . esc_html__( 'Importar seleccionadas', 'atora-lms' ) . '</button>';
		echo '</p>';
		echo '</form>';
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

	public static function handle_import_coursework(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		$wp_course_id = isset( $_POST['wp_course_id'] ) ? absint( wp_unslash( $_POST['wp_course_id'] ) ) : 0;
		if ( ! $wp_course_id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'atora-classroom', 'notice' => 'error', 'message' => rawurlencode( __( 'wp_course_id requerido.', 'atora-lms' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		check_admin_referer( 'atora_classroom_import_coursework_' . $wp_course_id );

		$ids = isset( $_POST['gc_coursework_ids'] ) && is_array( $_POST['gc_coursework_ids'] ) ? (array) $_POST['gc_coursework_ids'] : array();
		$ids = array_values( array_unique( array_filter( array_map( static fn( $v ) => sanitize_text_field( (string) wp_unslash( $v ) ), $ids ) ) ) );

		if ( empty( $ids ) ) {
			wp_safe_redirect( add_query_arg( array(
				'page'         => 'atora-classroom',
				'wp_course_id' => $wp_course_id,
				'notice'       => 'error',
				'message'      => rawurlencode( __( 'Selecciona al menos una tarea.', 'atora-lms' ) ),
			), admin_url( 'admin.php' ) ) );
			exit;
		}

		$post_status = isset( $_POST['post_status'] ) ? sanitize_key( (string) wp_unslash( $_POST['post_status'] ) ) : 'draft';
		$post_status = in_array( $post_status, array( 'draft', 'publish' ), true ) ? $post_status : 'draft';

		$service = self::service();
		$actor   = get_current_user_id();

		$created = 0;
		$updated = 0;
		$errors  = 0;

		foreach ( $ids as $gc_coursework_id ) {
			$res = $service->import_coursework_as_lesson( $wp_course_id, $gc_coursework_id, $actor, $post_status );
			if ( is_wp_error( $res ) ) {
				$errors++;
				continue;
			}
			if ( ! empty( $res['created'] ) ) {
				$created++;
			} else {
				$updated++;
			}
		}

		$msg = sprintf(
			/* translators: 1: created 2: updated 3: errors */
			__( 'Import completado: nuevos=%1$d, actualizados=%2$d, errores=%3$d.', 'atora-lms' ),
			$created,
			$updated,
			$errors
		);

		wp_safe_redirect( add_query_arg( array(
			'page'         => 'atora-classroom',
			'wp_course_id' => $wp_course_id,
			'notice'       => $errors ? 'error' : 'ok',
			'message'      => rawurlencode( $msg ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
