<?php
/**
 * App del docente — planificación híbrida y subida de archivos (6.40.0).
 *
 * Rutas móviles sobre `ATORA_Hybrid_Plan_Service` (mismas reglas que la web: el asistente con delegación prepara pero
 * no publica ni cambia la evidencia; revisión con 409; publicar nunca duplica lecciones). La app trabaja con ids de
 * curso de tabla; aquí se traducen.
 *
 * `POST /teacher/media`: sube un archivo del teléfono a la biblioteca (audio MP3/M4A/OGG validado por contenido, PDF o
 * imagen). Idempotente por `client_upload_id`: reintentar tras un corte no crea otro adjunto.
 *
 * @package ATORA_LMS
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Teacher_Plan_Controller {

	const DOC_TYPES  = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' );
	const DOC_MAX_MB = 25;

	public static function register_routes(): void {
		$ns   = ATORA_Mobile_REST_Controller::REST_NAMESPACE;
		$auth = array( 'ATORA_Mobile_Teacher_Controller', 'authorize' );
		$map  = array(
			'/teacher/plans'                              => array( array( 'GET', 'index' ), array( 'POST', 'create' ) ),
			'/teacher/plans/(?P<id>\d+)'                  => array( array( 'GET', 'show' ), array( 'POST', 'update' ) ),
			'/teacher/plans/(?P<id>\d+)/duplicate'        => array( array( 'POST', 'duplicate' ) ),
			'/teacher/plans/(?P<id>\d+)/preview'          => array( array( 'GET', 'preview' ) ),
			'/teacher/plans/(?P<id>\d+)/publish'          => array( array( 'POST', 'publish' ) ),
			'/teacher/media'                              => array( array( 'POST', 'upload' ) ),
		);
		foreach ( $map as $route => $methods ) {
			$specs = array();
			foreach ( $methods as $m ) {
				$specs[] = array( 'methods' => $m[0], 'callback' => array( __CLASS__, $m[1] ), 'permission_callback' => $auth );
			}
			register_rest_route( $ns, $route, $specs );
		}
	}

	private static function table_course( int $wp_course ): int {
		return class_exists( 'ATORA_Mobile_REST_Controller' ) ? ATORA_Mobile_REST_Controller::table_course_id( $wp_course ) : 0;
	}

	/** Plan con el id de curso de tabla y lo que el usuario puede hacer. */
	private static function out( array $plan ): array {
		$user              = get_current_user_id();
		$wp                = (int) ( $plan['wp_course_id'] ?? 0 );
		$plan['course_id'] = self::table_course( $wp );
		$plan['can_publish'] = ATORA_Hybrid_Plan_Service::can_publish( $user, $wp );
		return $plan;
	}

	private static function respond( $result, int $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, $status );
	}

	private static function course_param( $table ): int {
		return ATORA_Mobile_Teacher_Content_Controller::course_for_teacher( get_current_user_id(), absint( $table ) );
	}

	public static function index( WP_REST_Request $request ) {
		$user = get_current_user_id();
		$wp   = self::course_param( $request->get_param( 'course' ) );
		if ( ! $wp ) {
			return new WP_Error( 'atora_mobile_not_found', __( 'No encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$plans = array_map( array( __CLASS__, 'out' ), (array) ATORA_Hybrid_Plan_Service::list_for_course( $user, $wp ) );
		return new WP_REST_Response( array(
			'plans'        => $plans,
			'can_edit'     => ATORA_Hybrid_Plan_Service::can_edit( $user, $wp ),
			'can_publish'  => ATORA_Hybrid_Plan_Service::can_publish( $user, $wp ),
			'ai_available' => ATORA_Hybrid_Plan_Service::ai_available(),
			'empty'        => ATORA_Hybrid_Plan_Service::empty_data(),
		), 200 );
	}

	public static function create( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$wp     = self::course_param( $params['course_id'] ?? 0 );
		if ( ! $wp ) {
			return new WP_Error( 'atora_mobile_not_found', __( 'No encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		// Idempotente por client_event_id: un reintento tras un corte no crea otro plan.
		$event = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		$key   = '' !== $event ? '_atora_plan_created_' . md5( $event ) : '';
		if ( '' !== $key ) {
			$existing = absint( get_user_meta( get_current_user_id(), $key, true ) );
			if ( $existing ) {
				$plan = ATORA_Hybrid_Plan_Service::get_for_user( get_current_user_id(), $existing );
				if ( ! is_wp_error( $plan ) ) {
					return new WP_REST_Response( self::out( $plan ) + array( 'replayed' => true ), 200 );
				}
			}
		}
		$plan = ATORA_Hybrid_Plan_Service::create( get_current_user_id(), $wp, $params['data'] ?? array() );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( '' !== $key ) {
			update_user_meta( get_current_user_id(), $key, (int) $plan['id'] );
		}
		return new WP_REST_Response( self::out( $plan ) + array( 'replayed' => false ), 201 );
	}

	public static function show( WP_REST_Request $request ) {
		$plan = ATORA_Hybrid_Plan_Service::get_for_user( get_current_user_id(), absint( $request['id'] ) );
		return is_wp_error( $plan ) ? $plan : new WP_REST_Response( self::out( $plan ), 200 );
	}

	public static function update( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$plan   = ATORA_Hybrid_Plan_Service::update( get_current_user_id(), absint( $request['id'] ), $params['data'] ?? array(), absint( $params['revision'] ?? 0 ) );
		return is_wp_error( $plan ) ? $plan : new WP_REST_Response( self::out( $plan ), 200 );
	}

	public static function duplicate( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$target = isset( $params['course_id'] ) ? self::course_param( $params['course_id'] ) : 0;
		$plan   = ATORA_Hybrid_Plan_Service::duplicate( get_current_user_id(), absint( $request['id'] ), $target );
		return is_wp_error( $plan ) ? $plan : new WP_REST_Response( self::out( $plan ), 201 );
	}

	public static function preview( WP_REST_Request $request ) {
		return self::respond( ATORA_Hybrid_Plan_Service::preview( get_current_user_id(), absint( $request['id'] ) ) );
	}

	public static function publish( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$result = ATORA_Hybrid_Plan_Service::publish( get_current_user_id(), absint( $request['id'] ), absint( $params['revision'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['plan'] ) && is_array( $result['plan'] ) ) {
			$result['plan'] = self::out( $result['plan'] );
		}
		return new WP_REST_Response( $result, 200 );
	}

	// ── POST /teacher/media ────────────────────────────────────────────────

	/** Sube un archivo del teléfono. Campo `file` (multipart) y `client_upload_id`. */
	public static function upload( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! user_can( $user_id, 'upload_files' ) || ! user_can( $user_id, 'edit_lm_lessons' ) ) {
			return new WP_Error( 'atora_mobile_upload_forbidden', __( 'Tu rol no permite subir archivos.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$client = sanitize_text_field( (string) $request->get_param( 'client_upload_id' ) );
		if ( '' === $client || strlen( $client ) > 64 ) {
			return new WP_Error( 'atora_mobile_client_event_required', __( 'Falta client_upload_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$marker   = md5( $user_id . '|' . $client );
		$existing = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'author'         => $user_id,
			'meta_key'       => '_atora_client_upload', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $marker, // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		if ( $existing ) {
			return new WP_REST_Response( self::media_out( (int) $existing[0] ) + array( 'replayed' => true ), 200 );
		}

		$files = $request->get_file_params();
		$file  = is_array( $files['file'] ?? null ) ? $files['file'] : null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'atora_mobile_upload_missing', __( 'No llegó el archivo. Inténtalo de nuevo.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$ext   = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		$audio = class_exists( 'ATORA_Lesson_Audio' ) && isset( ATORA_Lesson_Audio::FORMATS[ $ext ] );
		if ( ! $audio && ! isset( self::DOC_TYPES[ $ext ] ) ) {
			return new WP_Error( 'atora_mobile_upload_format', __( 'Formato no admitido. Sube audio (MP3, M4A u OGG), PDF o imagen (JPG, PNG).', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$max = $audio ? ATORA_Lesson_Audio::max_bytes() : self::DOC_MAX_MB * MB_IN_BYTES;
		if ( (int) ( $file['size'] ?? filesize( $file['tmp_name'] ) ) > $max ) {
			/* translators: %d: MB */
			return new WP_Error( 'atora_mobile_upload_size', sprintf( __( 'El archivo supera el máximo de %d MB.', 'atora-lms' ), (int) round( $max / MB_IN_BYTES ) ), array( 'status' => 413 ) );
		}
		if ( ! $audio ) {
			$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], self::DOC_TYPES );
			if ( empty( $check['ext'] ) ) {
				return new WP_Error( 'atora_mobile_upload_format', __( 'El contenido del archivo no corresponde a su tipo.', 'atora-lms' ), array( 'status' => 400 ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$allow = static function ( $mimes ) use ( $audio ) {
			return $audio ? array_merge( (array) $mimes, array( 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg' ) ) : $mimes;
		};
		add_filter( 'upload_mimes', $allow );
		$id = media_handle_sideload( array( 'name' => sanitize_file_name( (string) $file['name'] ), 'tmp_name' => (string) $file['tmp_name'] ), 0, null, array( 'post_author' => $user_id ) );
		remove_filter( 'upload_mimes', $allow );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'atora_mobile_upload_failed', $id->get_error_message(), array( 'status' => 400 ) );
		}
		if ( $audio ) {
			$valid = ATORA_Lesson_Audio::validate_attachment( (int) $id );
			if ( is_wp_error( $valid ) ) {
				wp_delete_attachment( (int) $id, true );
				$valid->add_data( array( 'status' => 400 ) );
				return $valid;
			}
		}
		update_post_meta( (int) $id, '_atora_client_upload', $marker );
		return new WP_REST_Response( self::media_out( (int) $id ) + array( 'replayed' => false ), 201 );
	}

	private static function media_out( int $id ): array {
		$path = (string) get_attached_file( $id );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$type = class_exists( 'ATORA_Lesson_Audio' ) && isset( ATORA_Lesson_Audio::FORMATS[ $ext ] ) ? 'audio' : ( 'pdf' === $ext ? 'pdf' : 'archivo' );
		return array(
			'id'               => $id,
			'type'             => $type,
			'title'            => (string) get_the_title( $id ),
			'mime'             => (string) get_post_mime_type( $id ),
			'bytes'            => '' !== $path && is_readable( $path ) ? (int) filesize( $path ) : null,
			'duration_seconds' => 'audio' === $type ? ATORA_Lesson_Audio::duration_seconds( $id ) : null,
			'url'              => (string) wp_get_attachment_url( $id ),
		);
	}
}
