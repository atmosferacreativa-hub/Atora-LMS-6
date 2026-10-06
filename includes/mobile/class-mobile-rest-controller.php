<?php
/**
 * Mobile API v1: descubrimiento, autenticación y experiencia estudiantil.
 *
 * @package ATORA_LMS
 * @since 6.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_REST_Controller {
	const REST_NAMESPACE = 'atora-mobile/v1';
	const LOGIN_LIMIT     = 8;
	const LOGIN_WINDOW    = 15 * MINUTE_IN_SECONDS;

	/**
	 * Cache por-request (proceso) de matrículas móviles normalizadas.
	 *
	 * @var array<int, array<int, array{course_id:int,status:string,source:string}>>
	 */
	private static array $enrollment_index_cache = array();

	public static function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/discovery', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'discovery' ),
			'permission_callback' => array( __CLASS__, 'allow_public_discovery' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/auth/login', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'login' ),
			'permission_callback' => array( __CLASS__, 'allow_public_auth' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/auth/refresh', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'refresh' ),
			'permission_callback' => array( __CLASS__, 'allow_public_auth' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/auth/logout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'logout' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/me', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'me' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/dashboard', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'dashboard' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/courses', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'courses' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/programs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'programs' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/programs/(?P<program_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'program' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'program_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/courses/(?P<course_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'course' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'course_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/lessons/(?P<lesson_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'lesson' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'lesson_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/lessons/(?P<lesson_id>\d+)/quiz', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'quiz' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit_quiz' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			),
			'args' => array( 'lesson_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/lessons/(?P<lesson_id>\d+)/complete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'complete_lesson' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'lesson_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );

		// 6.27.0: entregas de tareas (docs/CONTRATO-ENTREGAS-MOVIL.md).
		register_rest_route( self::REST_NAMESPACE, '/assignments/(?P<lesson_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'assignment' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'lesson_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/assignments/(?P<lesson_id>\d+)/submissions', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_assignment_submission' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'lesson_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		// 6.29.0: notas, devoluciones y certificados del estudiante.
		register_rest_route( self::REST_NAMESPACE, '/grades', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'grades' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/courses/(?P<course_id>\\d+)/grades', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'course_grades' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/certificates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'certificates' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/certificates/(?P<target_type>course|program)/(?P<target_id>\\d+)/document', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'certificate_document' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );

		// 6.28.0: sincronización incremental y posición de reproducción.
		register_rest_route( self::REST_NAMESPACE, '/sync/changes', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'sync_changes' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/lessons/(?P<lesson_id>\d+)/position', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'save_position' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/uploads/sessions', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_upload_session' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/uploads/(?P<upload_token>tok_[a-f0-9]{48})', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'upload_chunk' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/uploads/(?P<upload_token>tok_[a-f0-9]{48})/complete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'complete_upload' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
	}

	/**
	 * 6.28.1: ninguna respuesta de la API móvil se guarda en cachés de página
	 * (LiteSpeed Cache, proxys). Son respuestas por usuario: guardadas, una
	 * caché entregaba el panel de un usuario a cualquiera, con o sin token.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response
	 */
	public static function no_cache( $response, $server = null, $request = null ) {
		$route = $request instanceof WP_REST_Request ? (string) $request->get_route() : '';
		if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
			return $response;
		}
		if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			$response->header( 'CDN-Cache-Control', 'no-store' );
			$response->header( 'Vary', 'Authorization' );
		}
		do_action( 'litespeed_control_set_nocache', 'atora-mobile: respuesta por usuario' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		return $response;
	}

	public static function allow_public_discovery(): bool {
		return true;
	}

	public static function allow_public_auth(): bool {
		return true;
	}

	public static function authorize( WP_REST_Request $request ) {
		$token = ATORA_Mobile_Token_Service::bearer_from_request( $request );
		if ( '' === $token ) {
			return new WP_Error( 'atora_mobile_auth_required', __( 'Se requiere autenticación móvil.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		$session = ATORA_Mobile_Token_Service::validate( $token, 'access' );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		wp_set_current_user( (int) $session['user_id'] );
		$request->set_param( '_atora_mobile_session_id', (string) $session['session_id'] );
		return true;
	}

	public static function discovery(): WP_REST_Response {
		$deployment_profile = class_exists( '\\ATORA\\LMS\\Deployment_Profile_Service' )
			? \ATORA\LMS\Deployment_Profile_Service::current_profile()
			: 'small';
		$seats_used = class_exists( '\\ATORA\\LMS\\Deployment_Profile_Service' )
			? \ATORA\LMS\Deployment_Profile_Service::seats_used_total()
			: 0;
		$build = class_exists( 'ATORA_Build_Info' ) ? ATORA_Build_Info::get() : array();

		return new WP_REST_Response( array(
			'product'          => 'ATORA LMS',
			'api'              => self::REST_NAMESPACE,
			'api_version'      => 1,
			'lms_version'      => defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '',
			'build'            => $build,
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url( '/' ),
			'deployment_profile' => $deployment_profile,
			'seats_used'       => absint( $seats_used ),
			'authentication'   => 'opaque_bearer',
			'access_ttl'       => ATORA_Mobile_Token_Service::ACCESS_TTL,
			'refresh_ttl'      => ATORA_Mobile_Token_Service::REFRESH_TTL,
			'features'         => array( 'profile', 'dashboard', 'courses', 'progress', 'lesson_completion', 'quizzes', 'assignments', 'sync_changes', 'playback_position', 'resource_downloads', 'multi_video', 'grades', 'certificates', 'messages', 'agenda', 'today', 'push_notifications' ),
			'capabilities'     => array(
				'assignments'        => true,
				'sync_changes'       => class_exists( '\\ATORA\\LMS\\LMS_Content_Changes' ),
				'playback_position'  => class_exists( 'ATORA_Mobile_Position_Service' ),
				'resource_downloads' => class_exists( 'ATORA_Download_Info' ),
				'multi_video'        => class_exists( 'ATORA_Lesson_Videos' ),
				'grades'             => class_exists( 'CLMS_Student_Grades_Service' ),
				'certificates'       => class_exists( 'CLMS_Certificates' ) || ( function_exists( 'clms_core' ) && (bool) clms_core( 'CLMS_Certificates' ) ),
				// 6.30.0: Fase 3 — buzón, agenda, Hoy y notificaciones al teléfono.
				'messages'           => class_exists( 'ATORA_Mobile_Messages_Controller' ) && class_exists( 'ATORA_Inbox_Store' ),
				'agenda'             => class_exists( 'CLMS_Agenda_Service' ),
				'today'              => class_exists( 'ATORA_Mobile_Organize_Controller' ),
				'push_notifications' => class_exists( 'ATORA_Mobile_Push_Service' ),
			),
		), 200 );
	}

	public static function login( WP_REST_Request $request ) {
		if ( ! self::transport_is_secure() ) {
			return new WP_Error( 'atora_mobile_https_required', __( 'La API móvil exige HTTPS.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$params   = (array) $request->get_json_params();
		$login    = sanitize_text_field( (string) ( $params['login'] ?? '' ) );
		$password = (string) ( $params['password'] ?? '' );
		$device   = sanitize_text_field( (string) ( $params['device_name'] ?? '' ) );

		if ( '' === $login || '' === $password ) {
			return new WP_Error( 'atora_mobile_credentials_required', __( 'Usuario y contraseña son obligatorios.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$throttle = self::login_throttle( $login );
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		$user = wp_authenticate( $login, $password );
		if ( is_wp_error( $user ) ) {
			self::record_login_failure( $login );
			return new WP_Error( 'atora_mobile_invalid_credentials', __( 'Credenciales incorrectas.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		self::clear_login_failures( $login );
		wp_set_current_user( (int) $user->ID );

		return new WP_REST_Response( array(
			'session' => ATORA_Mobile_Token_Service::issue( (int) $user->ID, $device ),
			'user'    => self::prepare_user( $user ),
		), 200 );
	}

	public static function refresh( WP_REST_Request $request ) {
		if ( ! self::transport_is_secure() ) {
			return new WP_Error( 'atora_mobile_https_required', __( 'La API móvil exige HTTPS.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$params = (array) $request->get_json_params();
		$token  = trim( (string) ( $params['refresh_token'] ?? '' ) );
		$device = sanitize_text_field( (string) ( $params['device_name'] ?? '' ) );
		if ( '' === $token ) {
			return new WP_Error( 'atora_mobile_refresh_required', __( 'El refresh token es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$session = ATORA_Mobile_Token_Service::rotate( $token, $device );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return new WP_REST_Response( array( 'session' => $session ), 200 );
	}

	public static function logout( WP_REST_Request $request ): WP_REST_Response {
		$token   = ATORA_Mobile_Token_Service::bearer_from_request( $request );
		$revoked = ATORA_Mobile_Token_Service::revoke_token( $token );
		wp_set_current_user( 0 );
		return new WP_REST_Response( array( 'revoked' => $revoked ), 200 );
	}

	public static function me(): WP_REST_Response {
		return new WP_REST_Response( array( 'user' => self::prepare_user( wp_get_current_user() ) ), 200 );
	}

	public static function dashboard(): WP_REST_Response {
		$user_id = get_current_user_id();
		$courses = self::prepare_enrollments( $user_id );
		$pending = 0;
		foreach ( $courses as $course ) {
			$pending += max( 0, (int) $course['total_lessons'] - (int) $course['completed_lessons'] );
		}
		return new WP_REST_Response( array(
			'user'               => self::prepare_user( wp_get_current_user() ),
			'pending_activities' => $pending,
			'courses'            => $courses,
			'generated_at'       => gmdate( 'c' ),
		), 200 );
	}

	public static function courses(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => self::prepare_enrollments( get_current_user_id() ) ), 200 );
	}

	public static function programs(): WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( self::enrolled_wp_program_ids( $user_id ) as $wp_program_id ) {
			$post = get_post( $wp_program_id );
			if ( ! $post || 'lm_program' !== (string) $post->post_type || 'publish' !== (string) $post->post_status ) {
				continue;
			}
			$items[] = self::safe_program_post( $post );
		}
		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	public static function program( WP_REST_Request $request ) {
		$user_id       = get_current_user_id();
		$wp_program_id = absint( $request['program_id'] );
		if ( $wp_program_id <= 0 || 'lm_program' !== get_post_type( $wp_program_id ) ) {
			return new WP_Error( 'atora_mobile_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $wp_program_id, self::enrolled_wp_program_ids( $user_id ), true ) ) {
			return new WP_Error( 'atora_mobile_program_forbidden', __( 'No tienes acceso a este programa.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$post = get_post( $wp_program_id );
		if ( ! $post || 'publish' !== (string) $post->post_status ) {
			return new WP_Error( 'atora_mobile_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$course_ids = array();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_program_courses' ) ) {
			$wp_course_ids = (array) \CLMS_Helper::get_program_courses( $wp_program_id );
			foreach ( $wp_course_ids as $wp_course_id ) {
				$wp_course_id = absint( $wp_course_id );
				if ( $wp_course_id <= 0 ) { continue; }
				$course = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course_id );
				$course_id = absint( is_array( $course ) ? ( $course['id'] ?? 0 ) : 0 );
				if ( $course_id > 0 ) {
					$course_ids[] = $course_id;
				}
			}
		}

		$courses = array();
		foreach ( array_values( array_unique( $course_ids ) ) as $course_id ) {
			$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
			if ( ! $course || 'published' !== (string) ( $course['status'] ?? '' ) ) {
				continue;
			}
			$progress = \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $course_id );
			$courses[] = array_merge(
				self::safe_course( $course ),
				array(
					'progress'          => absint( $progress['progress_pct'] ?? 0 ),
					'total_lessons'     => absint( $progress['total_lessons'] ?? 0 ),
					'completed_lessons' => absint( $progress['completed_lessons'] ?? 0 ),
					'is_complete'       => ! empty( $progress['is_complete'] ),
					'grade'             => null,
					'last_activity'     => '',
				)
			);
		}

		return new WP_REST_Response( array(
			'program' => self::safe_program_post( $post ),
			'courses' => $courses,
		), 200 );
	}

	public static function course( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$course_id = absint( $request['course_id'] );
		$auth = self::authorize_course_id( $user_id, $course_id );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
		if ( ! $course || 'published' !== (string) $course['status'] ) {
			return new WP_Error( 'atora_mobile_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$lessons   = \ATORA\LMS\LMS_Course_Service::get_lessons( $course_id );
		$completed = self::completed_lesson_ids( $user_id, $course_id );
		$cover     = self::course_cover( $course );
		foreach ( $lessons as &$lesson ) {
			$lesson['completed'] = in_array( (int) $lesson['id'], $completed, true );
			$wp_lesson_id        = absint( $lesson['wp_post_id'] ?? 0 );
			$video_url           = self::resolve_lesson_video_url( $lesson, $wp_lesson_id );
			$lesson['has_video'] = '' !== $video_url;
			// 6.28.2: cantidad de videos que ve el estudiante (la app la muestra si es mayor que 1).
			$lesson['video_count'] = class_exists( 'ATORA_Lesson_Videos' ) && $wp_lesson_id > 0 ? count( ATORA_Lesson_Videos::visible( $wp_lesson_id ) ) : (int) $lesson['has_video'];
			// 6.27.1: miniatura para el currículo (nunca vacía si el curso tiene portada).
			$lesson['video_thumbnail_url'] = class_exists( 'ATORA_Video_Thumbnail_Resolver' )
				? ATORA_Video_Thumbnail_Resolver::resolve( $wp_lesson_id, $video_url, $cover, absint( $course['wp_post_id'] ?? 0 ) )
				: $cover;
			unset( $lesson['video_url'] );
		}
		unset( $lesson );
		return new WP_REST_Response( array(
			'course'     => self::safe_course( $course ),
			'progress'   => \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $course_id ),
			'curriculum' => $lessons,
		), 200 );
	}

	public static function lesson( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$lesson_id = absint( $request['lesson_id'] );
		$lesson    = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id );
		if ( ! $lesson || 'published' !== (string) ( $lesson['status'] ?? '' ) ) {
			return new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$course_id = absint( $lesson['course_id'] );
		$auth = self::authorize_course_id( $user_id, $course_id );
		if ( is_wp_error( $auth ) ) { return $auth; }

		$wp_post_id   = absint( $lesson['wp_post_id'] ?? 0 );
		$raw_content  = $wp_post_id ? (string) get_post_field( 'post_content', $wp_post_id ) : '';
		$content_html = wp_kses_post( apply_filters( 'the_content', $raw_content ) );
		$video_url    = self::resolve_lesson_video_url( $lesson, $wp_post_id );

		$video_embed = self::google_drive_embed_url( $video_url, $raw_content );
		$videos = self::lesson_videos( $user_id, $lesson_id, $wp_post_id, $course_id );
		$video_download = '' === $video_embed && class_exists( 'ATORA_Download_Info' )
			? ATORA_Download_Info::for_video( $video_url )
			: array( 'video_downloadable' => false, 'video_bytes' => null );
		$resources   = $wp_post_id ? self::normalize_lesson_resources( $wp_post_id ) : array();
		$quiz_available = ( $wp_post_id && '1' === (string) get_post_meta( $wp_post_id, '_clms_quiz_enabled', true ) )
			|| self::has_table_quiz( $lesson_id );

		return new WP_REST_Response( array(
			'lesson' => array(
				'id'           => $lesson_id,
				'course_id'    => $course_id,
				'revision'     => absint( $lesson['revision'] ?? 1 ),
				'title'        => sanitize_text_field( (string) ( $lesson['title'] ?? '' ) ),
				'type'         => sanitize_key( (string) ( $lesson['type'] ?? 'text' ) ),
				'duration_min' => absint( $lesson['duration_min'] ?? 0 ),
				'video_url'       => $video_url,
				'video_embed_url' => $video_embed,
				'video_thumbnail_url' => self::video_thumbnail( $wp_post_id, $video_url, $course_id ),
				'video_provider'  => '' !== $video_embed ? 'google_drive' : ( '' !== $video_url ? 'direct' : '' ),
				// 6.28.0: solo MP4 directo de la academia se puede descargar.
				'video_downloadable' => (bool) $video_download['video_downloadable'],
				'video_bytes'        => $video_download['video_bytes'],
				// Compatibilidad (0.4.0): datos del primer video.
				'resume_position_seconds' => $videos ? $videos[0]['resume_position_seconds'] : ( class_exists( 'ATORA_Mobile_Position_Service' ) ? ATORA_Mobile_Position_Service::resume_seconds( $user_id, $lesson_id ) : 0 ),
				// 6.28.2: todos los videos de la lección, en el orden del editor y con el límite de la web.
				'videos'                  => $videos,
				'content_html'    => $content_html,
				'content_text' => sanitize_textarea_field( wp_strip_all_tags( $content_html ) ),
				'completed'    => in_array( $lesson_id, self::completed_lesson_ids( $user_id, $course_id ), true ),
				'quiz_available'=> $quiz_available,
				'assignment_available' => $wp_post_id > 0 && self::lesson_has_assignment( $wp_post_id ),
				'resources'      => $resources,
			),
		), 200 );
	}

	/**
	 * 6.28.2: los videos de la lección para la app (misma lista que la web).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function lesson_videos( int $user_id, int $lesson_id, int $wp_post_id, int $course_id ): array {
		if ( $wp_post_id <= 0 || ! class_exists( 'ATORA_Lesson_Videos' ) ) {
			return array();
		}
		$course    = \ATORA\LMS\LMS_Course_Service::get( $course_id );
		$cover     = is_array( $course ) ? self::course_cover( $course ) : '';
		$wp_course = absint( is_array( $course ) ? ( $course['wp_post_id'] ?? 0 ) : 0 );
		$items     = array();
		foreach ( ATORA_Lesson_Videos::visible( $wp_post_id ) as $i => $video ) {
			$url      = esc_url_raw( trim( (string) ( $video['url'] ?? '' ) ) );
			$embed    = self::google_drive_embed_url( $url, '' );
			$provider = '' !== $embed ? 'google_drive' : 'direct';
			if ( class_exists( 'ATORA_Video_Thumbnail_Resolver' ) ) {
				if ( '' !== ATORA_Video_Thumbnail_Resolver::youtube_id( $url ) ) {
					$provider = 'youtube';
				} elseif ( '' !== ATORA_Video_Thumbnail_Resolver::vimeo_id( $url ) ) {
					$provider = 'vimeo';
				}
			}
			$download = 'direct' === $provider && class_exists( 'ATORA_Download_Info' )
				? ATORA_Download_Info::for_video( $url )
				: array( 'video_downloadable' => false, 'video_bytes' => null );
			$items[] = array(
				'key'                     => (string) $video['key'],
				'title'                   => sanitize_text_field( (string) ( $video['title'] ?? '' ) ),
				'description'             => sanitize_textarea_field( wp_strip_all_tags( (string) ( $video['description'] ?? '' ) ) ),
				'source'                  => sanitize_key( (string) ( $video['source'] ?? '' ) ),
				'url'                     => $url,
				'embed_url'               => $embed,
				'provider'                => $provider,
				'thumbnail_url'           => class_exists( 'ATORA_Video_Thumbnail_Resolver' ) ? ATORA_Video_Thumbnail_Resolver::resolve( $wp_post_id, $url, $cover, $wp_course ) : $cover,
				'downloadable'            => (bool) $download['video_downloadable'],
				'bytes'                   => $download['video_bytes'],
				'resume_position_seconds' => class_exists( 'ATORA_Mobile_Position_Service' )
					? ATORA_Mobile_Position_Service::resume_seconds( $user_id, $lesson_id, (string) $video['key'], 0 === $i )
					: 0,
			);
		}
		return $items;
	}

	/**
	 * Video principal de la lección: el de la tabla y, si falta, el primero del
	 * editor (`_clms_lesson_extra_videos`) o `_clms_lesson_video_url`.
	 */
	private static function resolve_lesson_video_url( array $lesson, int $wp_post_id ): string {
		$video_url = esc_url_raw( (string) ( $lesson['video_url'] ?? '' ) );

		// Compatibilidad con las claves usadas por el editor de lecciones.
		if ( $wp_post_id > 0 && '' === $video_url ) {
			$extra_videos = get_post_meta( $wp_post_id, '_clms_lesson_extra_videos', true );
			if ( is_string( $extra_videos ) && '' !== trim( $extra_videos ) ) {
				$decoded = json_decode( $extra_videos, true );
				if ( is_array( $decoded ) ) {
					$extra_videos = $decoded;
				} elseif ( function_exists( 'maybe_unserialize' ) ) {
					$extra_videos = maybe_unserialize( $extra_videos );
				}
			}
			if ( is_array( $extra_videos ) ) {
				foreach ( $extra_videos as $extra_video ) {
					$candidate = '';
					if ( is_array( $extra_video ) ) {
						$candidate = (string) ( $extra_video['url'] ?? $extra_video['src'] ?? '' );
					} elseif ( is_string( $extra_video ) ) {
						$candidate = $extra_video;
					}
					$candidate = esc_url_raw( trim( $candidate ) );
					if ( '' !== $candidate ) {
						$video_url = $candidate;
						break;
					}
				}
			}
			if ( '' === $video_url ) {
				$video_url = esc_url_raw( (string) get_post_meta( $wp_post_id, '_clms_lesson_video_url', true ) );
			}
		}

		return $video_url;
	}

	private static function video_thumbnail( int $wp_post_id, string $video_url, int $course_id ): string {
		if ( ! class_exists( 'ATORA_Video_Thumbnail_Resolver' ) ) {
			return '';
		}
		$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
		return ATORA_Video_Thumbnail_Resolver::resolve(
			$wp_post_id,
			$video_url,
			is_array( $course ) ? self::course_cover( $course ) : '',
			absint( is_array( $course ) ? ( $course['wp_post_id'] ?? 0 ) : 0 )
		);
	}

	public static function quiz( WP_REST_Request $request ) {
		$context = self::quiz_context( absint( $request['lesson_id'] ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$table_quiz = self::get_table_quiz_payload( get_current_user_id(), absint( $context['lesson_id'] ) );
		if ( $table_quiz ) {
			return new WP_REST_Response( array( 'quiz' => $table_quiz ), 200 );
		}
		if ( ! class_exists( 'CLMS_Quiz' ) ) {
			return new WP_Error( 'atora_mobile_quiz_unavailable', __( 'El motor de evaluaciones no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$engine = new CLMS_Quiz();
		$result = $engine->get_quiz_rest( get_current_user_id(), $context['wp_post_id'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'quiz' => $result ), 200 );
	}

	public static function submit_quiz( WP_REST_Request $request ) {
		$context = self::quiz_context( absint( $request['lesson_id'] ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$table_quiz_row = self::get_table_quiz_row( absint( $context['lesson_id'] ) );
		if ( $table_quiz_row ) {
			$params  = (array) $request->get_json_params();
			$answers = isset( $params['answers'] ) && is_array( $params['answers'] ) ? array_values( $params['answers'] ) : array();
			$token   = sanitize_text_field( (string) ( $params['token'] ?? '' ) );
			$user_id = get_current_user_id();
			$lesson_id = absint( $context['lesson_id'] );
			$course_id = absint( $context['course_id'] );

			$lock_key = self::acquire_table_quiz_lock( $user_id, $lesson_id );
			if ( is_wp_error( $lock_key ) ) {
				return $lock_key;
			}

			try {
				$settings = json_decode( (string) ( $table_quiz_row['settings_json'] ?? '{}' ), true );
				$settings = is_array( $settings ) ? $settings : array();
				$time_limit_seconds = absint( $settings['time_limit_seconds'] ?? 0 );
					$attempts_allowed   = absint( $settings['attempts'] ?? 1 );
					if ( $attempts_allowed <= 0 ) { $attempts_allowed = 1; }

					$quiz_id = absint( $table_quiz_row['id'] ?? 0 );
					$stats   = self::table_quiz_stats( $user_id, $lesson_id, $quiz_id );
					$attempts_used = absint( $stats['attempts_used'] ?? 0 );
					$best_before   = absint( $stats['best_score'] ?? 0 );
					$attempt       = $attempts_used + 1;
					if ( $attempt > $attempts_allowed ) {
						return new WP_Error( 'atora_mobile_quiz_attempts_exceeded', __( 'Ya no tienes más intentos disponibles.', 'atora-lms' ), array( 'status' => 409 ) );
					}

					$validation = self::validate_table_quiz_token( $user_id, $lesson_id, $token, $time_limit_seconds );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
					if ( ! $validation ) {
						return new WP_Error( 'atora_mobile_quiz_token_invalid', __( 'La evaluación venció. Vuelve a abrirla para continuar.', 'atora-lms' ), array( 'status' => 409 ) );
					}
					self::consume_table_quiz_token( $user_id, $lesson_id );
				$result  = self::grade_table_quiz(
					$user_id,
					$lesson_id,
					$course_id,
					$table_quiz_row,
					$answers,
					$attempt,
					$best_before,
					$attempts_allowed
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return new WP_REST_Response( array( 'result' => $result ), 200 );
			} finally {
				self::release_table_quiz_lock( (string) $lock_key );
			}
		}
		if ( ! class_exists( 'CLMS_Quiz' ) ) {
			return new WP_Error( 'atora_mobile_quiz_unavailable', __( 'El motor de evaluaciones no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$params  = (array) $request->get_json_params();
		$answers = isset( $params['answers'] ) && is_array( $params['answers'] ) ? array_slice( $params['answers'], 0, CLMS_Quiz::MAX_ANSWER_ITEMS, true ) : array();
		$token   = sanitize_text_field( (string) ( $params['token'] ?? '' ) );
		$engine  = new CLMS_Quiz();
		$result  = $engine->grade_mobile_quiz_rest( get_current_user_id(), $context['wp_post_id'], $answers, $token );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'result' => $result ), 200 );
	}

	private static function quiz_context( int $lesson_id ) {
		$lesson = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id );
		if ( ! $lesson || 'published' !== (string) ( $lesson['status'] ?? '' ) ) {
			return new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$course_id = absint( $lesson['course_id'] ?? 0 );
		$auth = self::authorize_course_id( get_current_user_id(), $course_id );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$wp_post_id     = absint( $lesson['wp_post_id'] ?? 0 );
		$table_quiz_row = self::get_table_quiz_row( $lesson_id );
		$has_table_quiz = (bool) $table_quiz_row;
		if ( ! $wp_post_id || 'lm_lesson' !== get_post_type( $wp_post_id ) ) {
			if ( ! $has_table_quiz ) {
				return new WP_Error( 'atora_mobile_quiz_not_found', __( 'Esta lección no contiene una evaluación móvil.', 'atora-lms' ), array( 'status' => 404 ) );
			}

			// Table-only lessons still need a legacy lm_lesson identity for:
			// - Drip availability enforcement
			// - Gradebook/SpeedGrader linkage (_clms_submission_lesson_id/course_id)
			// A permanent CPT-less mode requires native tables support for Drip
			// and grading UIs, which is out of scope for this contract fix.
			return new WP_Error(
				'atora_mobile_quiz_requires_wp_identity',
				__( 'Esta evaluación requiere identidad WordPress (lección) para registrar y bloquear por Drip.', 'atora-lms' ),
				array( 'status' => 409 )
			);
		}

		// Quizzes en tablas requieren identidad WP coherente para asegurar:
		// - Drip (depende de lm_lesson y su relación con lm_course)
		// - Gradebook/SpeedGrader (submission enlazado con _clms_submission_*).
		if ( $has_table_quiz ) {
			$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
			$wp_course_id = absint( is_array( $course ) ? ( $course['wp_post_id'] ?? 0 ) : 0 );
			if ( ! $wp_course_id || ! \ATORA\LMS\LMS_Course_Service::legacy_wp_course_post_is_public( $wp_course_id ) ) {
				return new WP_Error(
					'atora_mobile_quiz_requires_wp_course_identity',
					__( 'Esta evaluación requiere identidad WordPress (curso) para registrarse correctamente.', 'atora-lms' ),
					array( 'status' => 409 )
				);
			}

			// Resolver el course_id de la lección WP con la misma precedencia
			// que CLMS_Helper::get_lesson_course_id(), pero sin escribir metadata
			// en GET/POST (no llamamos al helper).
			$relation_keys = array(
				'_clms_course_id',
				'lm_course_id',
				'_clms_lesson_course_id',
				'course_id',
				'_lesson_course_id',
				'lesson_course_id',
			);
			$non_empty_values = array();
			foreach ( $relation_keys as $meta_key ) {
				$value_raw = get_post_meta( $wp_post_id, (string) $meta_key, true );
				if ( '' === (string) $value_raw || null === $value_raw ) {
					continue;
				}
				$value = absint( $value_raw );
				if ( $value > 0 ) {
					$non_empty_values[ (string) $meta_key ] = $value;
				}
			}

			$unique = array_values( array_unique( array_values( $non_empty_values ) ) );
			if ( count( $unique ) > 1 ) {
				return new WP_Error(
					'atora_mobile_quiz_identity_conflict',
					__( 'La lección contiene claves de relación curso–lección contradictorias.', 'atora-lms' ),
					array( 'status' => 409 )
				);
			}

			$lesson_course_wp = 0;
			foreach ( $relation_keys as $meta_key ) {
				if ( isset( $non_empty_values[ (string) $meta_key ] ) ) {
					$lesson_course_wp = absint( $non_empty_values[ (string) $meta_key ] );
					break;
				}
			}

			if ( ! $lesson_course_wp || $lesson_course_wp !== $wp_course_id ) {
				return new WP_Error(
					'atora_mobile_quiz_identity_mismatch',
					__( 'La identidad WordPress (lección/curso) no coincide con las tablas.', 'atora-lms' ),
					array( 'status' => 409 )
				);
			}

			$quiz_course_id = absint( is_array( $table_quiz_row ) ? ( $table_quiz_row['course_id'] ?? 0 ) : 0 );
			if ( ! $quiz_course_id || $quiz_course_id !== $course_id ) {
				return new WP_Error(
					'atora_mobile_quiz_identity_mismatch',
					__( 'La identidad WordPress (lección/curso) no coincide con las tablas.', 'atora-lms' ),
					array( 'status' => 409 )
				);
			}
		}

		// Drip: si la lección aún no está disponible, bloquear también quizzes móviles.
		// Importante: en mobile podemos estar matriculados solo en tablas; por eso
		// NO delegamos esta decisión a CLMS_Helper::user_can_access_lesson().
		if ( class_exists( 'CLMS_Drip' ) && is_callable( array( 'CLMS_Drip', 'is_lesson_available' ) ) ) {
			$available = (bool) \CLMS_Drip::is_lesson_available( get_current_user_id(), $wp_post_id );
			if ( ! $available ) {
				return new WP_Error( 'clms_lesson_locked', __( 'La lección aún no está disponible.', 'atora-lms' ), array( 'status' => 403 ) );
			}
		}
		return array( 'lesson_id' => $lesson_id, 'course_id' => $course_id, 'wp_post_id' => $wp_post_id );
	}

	private static function has_table_quiz( int $lesson_id ): bool {
		$row = self::get_table_quiz_row( $lesson_id );
		return (bool) $row;
	}

	private static function get_table_quiz_row( int $lesson_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_quizzes';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lesson_id = %d LIMIT 1", $lesson_id ), ARRAY_A );
		return $row && is_array( $row ) ? $row : null;
	}

	private static function get_table_quiz_payload( int $user_id, int $lesson_id ): ?array {
		$row = self::get_table_quiz_row( $lesson_id );
		if ( ! $row ) { return null; }

		$questions = json_decode( (string) ( $row['questions_json'] ?? '[]' ), true );
		$questions = is_array( $questions ) ? $questions : array();
		if ( empty( $questions ) ) { return null; }

		$public_questions = array();
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) ) { continue; }
			$public_questions[] = array(
				'id'       => absint( $q['id'] ?? 0 ),
				'type'     => sanitize_key( (string) ( $q['type'] ?? 'single' ) ),
				'question' => sanitize_text_field( (string) ( $q['question'] ?? '' ) ),
				'options'  => array_values( array_map( 'sanitize_text_field', (array) ( $q['options'] ?? array() ) ) ),
				'weight'   => absint( $q['weight'] ?? 1 ),
			);
		}

		$settings = json_decode( (string) ( $row['settings_json'] ?? '{}' ), true );
		$settings = is_array( $settings ) ? $settings : array();
		$time_limit = absint( $settings['time_limit_seconds'] ?? 0 );
		$attempts   = absint( $settings['attempts'] ?? 1 );
		if ( $attempts <= 0 ) { $attempts = 1; }
		$quiz_id    = absint( $row['id'] ?? 0 );
		$stats      = self::table_quiz_stats( absint( $user_id ), $lesson_id, $quiz_id );
		$best_score = isset( $stats['best_score'] ) ? absint( $stats['best_score'] ) : null;
		$attempts_used = absint( $stats['attempts_used'] ?? 0 );
		$can_submit = $attempts_used < $attempts;
		$token = '';
		$token_issued_at = 0;
		$remaining_seconds = $time_limit > 0 ? $time_limit : 0;
		if ( $can_submit ) {
			$stored = get_transient( self::table_quiz_token_key( $user_id, $lesson_id ) );
			if ( is_array( $stored ) ) {
				$token          = (string) ( $stored['token'] ?? '' );
				$token_issued_at = absint( $stored['issued_at'] ?? 0 );
			} elseif ( is_string( $stored ) ) {
				$token = (string) $stored;
			}

			if ( '' === $token ) {
				$token = self::issue_table_quiz_token( absint( $user_id ), $lesson_id, $time_limit );
				$token_issued_at = time();
			} elseif ( $time_limit > 0 && $token_issued_at <= 0 ) {
				// Normaliza transients legacy (token sin timestamp) para que el límite sea verificable.
				$token_issued_at = time();
				set_transient(
					self::table_quiz_token_key( $user_id, $lesson_id ),
					array( 'token' => (string) $token, 'issued_at' => $token_issued_at ),
					max( HOUR_IN_SECONDS, $time_limit + HOUR_IN_SECONDS )
				);
			}

			if ( $time_limit > 0 && $token_issued_at > 0 ) {
				$elapsed = time() - $token_issued_at;
				$remaining_seconds = max( 0, $time_limit - max( 0, (int) $elapsed ) );
			} else {
				$remaining_seconds = $time_limit > 0 ? $time_limit : 0;
			}
		}

		return array(
			'lesson_id'         => $lesson_id,
			'token'             => $token,
			'questions'         => $public_questions,
			'can_submit'        => $can_submit,
			'remaining_seconds' => $remaining_seconds,
			'token_issued_at'   => $token_issued_at,
			'attempts'          => $attempts,
			'best_score'        => $best_score,
			'retry_context'     => (object) array( 'source' => 'atora_table' ),
		);
	}

	private static function table_quiz_token_key( int $user_id, int $lesson_id ): string {
		return 'atora_table_quiz_token_' . absint( $user_id ) . '_' . absint( $lesson_id );
	}

	private static function table_quiz_lock_key( int $user_id, int $lesson_id ): string {
		return 'atora_table_quiz_lock_' . absint( $user_id ) . '_' . absint( $lesson_id );
	}

	private static function acquire_table_quiz_lock( int $user_id, int $lesson_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new WP_Error( 'atora_mobile_quiz_lock_unavailable', __( 'No se puede procesar la evaluación (lock no disponible).', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$lock_key = self::table_quiz_lock_key( $user_id, $lesson_id );
		$got      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_key ) );
		if ( 1 === (int) $got ) {
			return $lock_key;
		}
		return new WP_Error( 'atora_mobile_quiz_submission_locked', __( 'Tu evaluación ya se está procesando.', 'atora-lms' ), array( 'status' => 429 ) );
	}

	private static function release_table_quiz_lock( string $lock_key ): void {
		$lock_key = trim( (string) $lock_key );
		if ( '' === $lock_key ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
	}

	private static function issue_table_quiz_token( int $user_id, int $lesson_id, int $time_limit_seconds = 0 ): string {
		$token = function_exists( 'wp_generate_password' )
			? wp_generate_password( 20, false )
			: substr( sha1( (string) ( microtime( true ) . rand() ) ), 0, 20 );
		set_transient(
			self::table_quiz_token_key( $user_id, $lesson_id ),
			array(
				'token'     => (string) $token,
				'issued_at' => time(),
			),
			max( HOUR_IN_SECONDS, $time_limit_seconds + HOUR_IN_SECONDS )
		);
		return (string) $token;
	}

	private static function validate_table_quiz_token( int $user_id, int $lesson_id, string $token, int $time_limit_seconds = 0 ) {
		$token = trim( (string) $token );
		if ( '' === $token ) { return false; }
		$stored = get_transient( self::table_quiz_token_key( $user_id, $lesson_id ) );
		$stored_token = '';
		$issued_at    = 0;
		if ( is_array( $stored ) ) {
			$stored_token = (string) ( $stored['token'] ?? '' );
			$issued_at    = absint( $stored['issued_at'] ?? 0 );
		} else {
			$stored_token = (string) $stored;
		}
		if ( '' === $stored_token || ! hash_equals( $stored_token, $token ) ) {
			return false;
		}
		if ( $time_limit_seconds > 0 && $issued_at > 0 ) {
			if ( ( time() - $issued_at ) >= $time_limit_seconds ) {
				delete_transient( self::table_quiz_token_key( $user_id, $lesson_id ) );
				return new WP_Error( 'atora_mobile_quiz_time_expired', __( 'Se agotó el tiempo de la evaluación. Vuelve a abrirla para reintentar.', 'atora-lms' ), array( 'status' => 409 ) );
			}
		}
		return true;
	}

	private static function consume_table_quiz_token( int $user_id, int $lesson_id ): void {
		delete_transient( self::table_quiz_token_key( $user_id, $lesson_id ) );
	}

	private static function table_quiz_stats( int $user_id, int $lesson_id, int $quiz_id ): array {
		global $wpdb;
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$quiz_id   = absint( $quiz_id );
		if ( ! $user_id || ! $lesson_id || ! $quiz_id ) {
			return array( 'attempts_used' => 0, 'best_score' => null );
		}
		$table  = $wpdb->prefix . 'atora_quiz_submissions';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return array( 'attempts_used' => 0, 'best_score' => null );
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS attempts_used, MAX(grade) AS best_score FROM {$table} WHERE user_id = %d AND lesson_id = %d AND quiz_id = %d",
				$user_id,
				$lesson_id,
				$quiz_id
			),
			ARRAY_A
		);
		return array(
			'attempts_used' => absint( $row['attempts_used'] ?? 0 ),
			'best_score'    => null !== ( $row['best_score'] ?? null ) ? absint( (int) $row['best_score'] ) : null,
		);
	}

	private static function grade_table_quiz( int $user_id, int $lesson_id, int $course_id, array $row, array $answers, int $attempt, int $best_before, int $attempts_allowed ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$course_id = absint( $course_id );
		$attempt   = max( 1, absint( $attempt ) );
		$best_before = max( 0, absint( $best_before ) );
		$attempts_allowed = max( 1, absint( $attempts_allowed ) );

		$questions = json_decode( (string) ( $row['questions_json'] ?? '[]' ), true );
		$questions = is_array( $questions ) ? $questions : array();

		$total = 0;
		$score = 0;
		$graded_answers = array();

		foreach ( array_values( $questions ) as $index => $q ) {
			if ( ! is_array( $q ) ) { continue; }
			$weight = absint( $q['weight'] ?? 1 );
			$total += $weight;

			$expected = $q['answer'] ?? null;
			$given    = $answers[ $index ] ?? null;
			$correct  = false;

			$type = sanitize_key( (string) ( $q['type'] ?? 'single' ) );
			if ( 'multiple' === $type ) {
				$expected_arr = is_array( $expected ) ? array_values( $expected ) : array();
				$given_arr    = is_array( $given ) ? array_values( $given ) : array();
				sort( $expected_arr );
				sort( $given_arr );
				$correct = $expected_arr === $given_arr;
			} else {
				$correct = (string) $expected !== '' && (string) $expected === (string) $given;
			}

			if ( $correct ) {
				$score += $weight;
			}

			$graded_answers[] = array(
				'id'      => absint( $q['id'] ?? 0 ),
				'given'   => $given,
				'correct' => $correct,
				'weight'  => $weight,
			);
		}

		$pct = $total > 0 ? (int) round( min( 100, max( 0, ( $score / $total ) * 100 ) ) ) : 0;

		$persist = self::persist_table_quiz_submission( $user_id, $lesson_id, $course_id, absint( $row['id'] ?? 0 ), $pct, $graded_answers );
		if ( is_wp_error( $persist ) ) {
			return $persist;
		}
		$best = max( $best_before, $pct );
		$can_retry = $attempt < $attempts_allowed;

		return array(
			'score'          => $pct,
			'best_score'     => $best,
			'attempt'        => $attempt,
			'student_message'=> $pct >= 70 ? __( '¡Bien! Tu resultado quedó registrado.', 'atora-lms' ) : __( 'Tu resultado quedó registrado. Puedes intentar nuevamente para mejorar.', 'atora-lms' ),
			'can_retry'      => $can_retry,
		);
	}

	private static function persist_table_quiz_submission( int $user_id, int $lesson_id, int $course_id, int $quiz_id, int $pct, array $graded_answers ) {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_quiz_submissions';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return new WP_Error( 'atora_mobile_quiz_persist_unavailable', __( 'No se pudo guardar la calificación (tabla no disponible).', 'atora-lms' ), array( 'status' => 503 ) );
		}

		$lesson = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id );
		$wp_lesson_id = absint( is_array( $lesson ) ? ( $lesson['wp_post_id'] ?? 0 ) : 0 );
		$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
		$wp_course_id = absint( is_array( $course ) ? ( $course['wp_post_id'] ?? 0 ) : 0 );

		$submission_id = wp_insert_post(
			array(
				'post_type'   => 'clms_submission',
				'post_status' => 'publish',
				'post_author' => $user_id,
				'post_title'  => sprintf( 'Evaluación: %s', $wp_lesson_id ? get_the_title( $wp_lesson_id ) : __( 'Lección', 'atora-lms' ) ),
			),
			true
		);
		if ( is_wp_error( $submission_id ) || ! $submission_id ) {
			return new WP_Error( 'atora_mobile_quiz_persist_failed', __( 'No se pudo guardar la calificación (error al crear el submission).', 'atora-lms' ), array( 'status' => 500 ) );
		}

		update_post_meta( $submission_id, '_clms_submission_user_id', $user_id );
		if ( $wp_lesson_id ) { update_post_meta( $submission_id, '_clms_submission_lesson_id', $wp_lesson_id ); }
		if ( $wp_course_id ) { update_post_meta( $submission_id, '_clms_submission_course_id', $wp_course_id ); }
		update_post_meta( $submission_id, '_clms_submission_status', 'graded' );
		update_post_meta( $submission_id, '_clms_submission_grade', $pct );
		update_post_meta( $submission_id, '_clms_submission_submitted_at', current_time( 'mysql' ) );

		$ok = $wpdb->insert(
			$table,
			array(
				'wp_post_id'    => absint( $submission_id ),
				'user_id'       => $user_id,
				'quiz_id'       => $quiz_id,
				'lesson_id'     => $lesson_id,
				'course_id'     => $course_id,
				'wp_lesson_id'  => $wp_lesson_id,
				'wp_course_id'  => $wp_course_id,
				'status'        => 'graded',
				'grade'         => (float) $pct,
				'submitted_at'  => current_time( 'mysql' ),
				'graded_at'     => current_time( 'mysql' ),
				'meta_json'     => wp_json_encode( array( 'source' => 'mobile_table', 'answers' => $graded_answers ), JSON_UNESCAPED_UNICODE ),
			),
			array( '%d','%d','%d','%d','%d','%d','%d','%s','%f','%s','%s','%s' )
		);
		if ( ! $ok ) {
			self::rollback_table_quiz_submission_post( absint( $submission_id ) );
			return new WP_Error( 'atora_mobile_quiz_persist_failed', __( 'No se pudo guardar la calificación (error al persistir).', 'atora-lms' ), array( 'status' => 500 ) );
		}
		return true;
	}

	private static function rollback_table_quiz_submission_post( int $submission_id ): void {
		if ( $submission_id <= 0 ) {
			return;
		}

		// Limpieza defensiva: asegurar que no queda un submission "graded" huérfano.
		$meta_keys = array(
			'_clms_submission_user_id',
			'_clms_submission_lesson_id',
			'_clms_submission_course_id',
			'_clms_submission_status',
			'_clms_submission_grade',
			'_clms_submission_submitted_at',
		);
		foreach ( $meta_keys as $key ) {
			if ( function_exists( 'delete_post_meta' ) ) {
				delete_post_meta( $submission_id, (string) $key );
			}
		}

		if ( function_exists( 'wp_delete_post' ) ) {
			wp_delete_post( $submission_id, true );
		}
	}

	// ── Entregas de tareas (6.27.0) ─────────────────────────────────────────

	public static function assignment( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$context = self::assignment_lesson_context( $user_id, absint( $request['lesson_id'] ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$service     = ATORA_Mobile_Assignment_Service::instance();
		$policy      = $service->policy();
		$group       = $context['group'];
		// 6.31.0: en una tarea grupal los intentos son los de la entrega del grupo.
		$submissions = $group && $group['master_id'] ? ATORA_Mobile_Assignment_Service::list_post_submissions( $group['master_id'] ) : ( $group ? array() : $service->list_submissions( $user_id, $context['lesson_id'] ) );

		// La nota vive en el post clms_submission, que corresponde al intento más reciente.
		$engine = ATORA_Mobile_Assignment_Service::submission_engine();
		$review = $engine ? $engine->get_student_review_view( $user_id, $context['wp_lesson_id'] ) : array( 'submission_id' => 0 );
		// En grupo, la nota del integrante vive en su copia; el intento apunta a la maestra.
		$graded_post = $group ? (int) $group['master_id'] : (int) $review['submission_id'];
		foreach ( $submissions as $i => $submission ) {
			$is_current = 0 === $i && $review['submission_id'] > 0 && (int) $submission['wp_post_id'] === $graded_post;
			$submissions[ $i ]['grade']    = $is_current ? $review['grade'] : null;
			$submissions[ $i ]['feedback'] = $is_current ? $review['feedback'] : null;
			$submissions[ $i ]['review_status'] = $is_current ? (string) $review['status'] : '';
			// 6.29.0: devolución con rúbrica solo con la nota liberada; si no, "en revisión".
			$submissions[ $i ]['rubric'] = $is_current ? ( $review['rubric'] ?? null ) : null;
			$submissions[ $i ]['in_review'] = $is_current && null === $review['grade'] && in_array( (string) $review['status'], array( 'submitted', 'in_review', 'graded' ), true );
			unset( $submissions[ $i ]['wp_post_id'] );
		}

		$attempts_used = $group ? max( count( $submissions ), (int) $group['attempts'] ) : count( $submissions );
		$extensions    = array_keys( (array) $policy['allowed_mimes'] );

		return new WP_REST_Response( array(
			'assignment'  => array(
				'lesson_id'          => $context['lesson_id'],
				'course_id'          => $context['course_id'],
				'title'              => $context['title'],
				'instructions_html'  => $context['instructions_html'],
				'due_at'             => $context['due_ts'] > 0 ? gmdate( 'Y-m-d H:i:s', $context['due_ts'] ) : null,
				'allow_resubmission' => $context['allow_resubmission'],
				'attempts_allowed'   => $context['allow_resubmission'] ? null : 1,
				'attempts_used'      => $attempts_used,
				'group_mode'         => $context['group_mode'],
				'group'              => $group ? array(
					'id'           => $group['id'],
					'name'         => $group['name'],
					'members'      => $group['members'],
					'submitted_by' => $group['submitted_by'],
					'submitted_at' => $group['submitted_at'],
				) : null,
				'can_submit'         => ( ! $context['group_mode'] || ! empty( $group['id'] ) ) && ( $context['allow_resubmission'] || 0 === $attempts_used ),
				'accepted_files'     => array(
					'extensions' => $extensions,
					'mime_types' => array_values( array_unique( array_values( (array) $policy['allowed_mimes'] ) ) ),
					'max_bytes'  => (int) $policy['max_file_size'],
					'max_files'  => (int) $policy['max_files'],
				),
			),
			'submissions' => $submissions,
		), 200 );
	}

	public static function create_assignment_submission( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$context = self::assignment_lesson_context( $user_id, absint( $request['lesson_id'] ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$result = ATORA_Mobile_Assignment_Service::instance()->create_submission( $user_id, $context, (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$submission = $result['submission'];
		unset( $submission['wp_post_id'] );
		return new WP_REST_Response( array( 'submission' => $submission, 'replayed' => $result['replayed'] ), 200 );
	}

	public static function create_upload_session( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$params  = (array) $request->get_json_params();
		// La subida se ata a una lección con tarea en un curso del estudiante.
		$context = self::assignment_lesson_context( $user_id, absint( $params['lesson_id'] ?? 0 ) );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$upload = ATORA_Mobile_Assignment_Service::instance()->create_upload_session( $user_id, $params );
		return is_wp_error( $upload ) ? $upload : new WP_REST_Response( array( 'upload' => $upload ), 200 );
	}

	public static function upload_chunk( WP_REST_Request $request ) {
		$result = ATORA_Mobile_Assignment_Service::instance()->put_chunk(
			get_current_user_id(),
			(string) $request['upload_token'],
			(string) $request->get_header( 'content_range' ),
			(string) $request->get_body()
		);
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	public static function complete_upload( WP_REST_Request $request ) {
		$result = ATORA_Mobile_Assignment_Service::instance()->complete_upload( get_current_user_id(), (string) $request['upload_token'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * Lección con tarea en un curso al que el estudiante tiene acceso.
	 *
	 * @return array{lesson_id:int, wp_lesson_id:int, course_id:int, title:string, instructions_html:string, due_ts:int, allow_resubmission:bool, group_mode:bool}|WP_Error
	 */
	private static function assignment_lesson_context( int $user_id, int $lesson_id ) {
		$lesson = $lesson_id > 0 ? \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id ) : null;
		if ( ! $lesson || 'published' !== (string) ( $lesson['status'] ?? '' ) ) {
			return new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$course_id = absint( $lesson['course_id'] );
		$auth      = self::authorize_course_id( $user_id, $course_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$wp_lesson_id = absint( $lesson['wp_post_id'] ?? 0 );
		if ( $wp_lesson_id <= 0 || ! self::lesson_has_assignment( $wp_lesson_id ) ) {
			return new WP_Error( 'atora_mobile_assignment_not_found', __( 'Esta lección no tiene una tarea.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$allow_resubmission = true;
		$evidence_service   = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Evidence_Service' ) : null;
		if ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
			$config             = (array) $evidence_service->get_activity_evidence_config( $wp_lesson_id );
			$allow_resubmission = array_key_exists( 'allow_resubmission', $config ) ? ! empty( $config['allow_resubmission'] ) : true;
		}

		$raw_content = (string) get_post_field( 'post_content', $wp_lesson_id );
		$group_mode  = 'group' === sanitize_key( (string) get_post_meta( $wp_lesson_id, '_clms_evaluation_mode', true ) );

		return array(
			'lesson_id'          => $lesson_id,
			'wp_lesson_id'       => $wp_lesson_id,
			'course_id'          => $course_id,
			'title'              => sanitize_text_field( (string) ( $lesson['title'] ?? '' ) ),
			'instructions_html'  => wp_kses_post( apply_filters( 'the_content', $raw_content ) ),
			'due_ts'             => self::assignment_due_ts( $wp_lesson_id ),
			'allow_resubmission' => $allow_resubmission,
			'group_mode'         => $group_mode,
			'group'              => $group_mode ? self::assignment_group( $user_id, $wp_lesson_id ) : null,
		);
	}

	/**
	 * 6.31.0: grupo del estudiante en una tarea grupal, con quién entregó.
	 *
	 * @return array{id:int,name:string,members:array,master_id:int,attempts:int,submitted_by:?array,submitted_at:?string}|null
	 */
	public static function assignment_group( int $user_id, int $wp_lesson_id ): ?array {
		$wp_course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_lesson_course_id( $wp_lesson_id ) ) : 0;
		$group_id     = absint( apply_filters( 'atora/groups/user_group_id', 0, $user_id, $wp_course_id, $wp_lesson_id ) );
		if ( ! $group_id || ! class_exists( '\\ATORA\\Groups\\Group_Service' ) ) {
			return null;
		}
		$service = new \ATORA\Groups\Group_Service();
		$row     = (array) $service->get_group( $group_id );
		$members = array();
		foreach ( $service->get_group_member_ids( $group_id ) as $member_id ) {
			$member    = get_userdata( (int) $member_id );
			$members[] = array( 'id' => (int) $member_id, 'name' => $member ? (string) $member->display_name : '' );
		}
		$master = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_clms_submission_group_master', 'value' => '1' ),
				array( 'key' => '_clms_submission_group_id', 'value' => $group_id, 'type' => 'NUMERIC' ),
				array( 'key' => '_clms_submission_lesson_id', 'value' => $wp_lesson_id, 'type' => 'NUMERIC' ),
			),
		) );
		$master_id = $master ? (int) $master[0] : 0;
		$by        = $master_id ? absint( get_post_meta( $master_id, '_clms_submission_submitted_by', true ) ) : 0;
		$by_user   = $by ? get_userdata( $by ) : null;
		$at        = $master_id ? (string) get_post_meta( $master_id, '_clms_submission_submitted_at', true ) : '';
		global $wpdb;
		$attempts = $master_id ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(attempt) FROM {$wpdb->prefix}atora_assignment_submissions WHERE wp_post_id = %d", $master_id ) ) ) : 0; // phpcs:ignore WordPress.DB
		return array(
			'id'           => $group_id,
			'name'         => (string) ( $row['name'] ?? '' ),
			'members'      => $members,
			'master_id'    => $master_id,
			'attempts'     => $master_id ? max( 1, $attempts ) : 0,
			'submitted_by' => $by_user ? array( 'id' => $by, 'name' => (string) $by_user->display_name ) : null,
			'submitted_at' => '' !== $at ? gmdate( 'c', strtotime( get_gmt_from_date( $at ) . ' UTC' ) ) : null,
		);
	}

	/** Misma detección que la web: tipo de actividad "tarea" (o sus alias). */
	private static function lesson_has_assignment( int $wp_lesson_id ): bool {
		$raw = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_post_meta_first' )
			? CLMS_Helper::get_post_meta_first( $wp_lesson_id, array( 'lm_activity_type', '_clms_activity_mode' ), '' )
			: get_post_meta( $wp_lesson_id, 'lm_activity_type', true );
		return in_array( sanitize_key( (string) $raw ), array( 'tarea', 'task', 'assignment' ), true );
	}

	/**
	 * Fecha límite (no la de tolerancia) en la zona horaria del sitio, como timestamp UTC.
	 */
	public static function assignment_due_ts( int $wp_lesson_id ): int {
		$has_helper = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_post_meta_first' );
		$date = trim( (string) ( $has_helper ? CLMS_Helper::get_post_meta_first( $wp_lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' ) : get_post_meta( $wp_lesson_id, 'lm_due_date', true ) ) );
		$time = trim( (string) ( $has_helper ? CLMS_Helper::get_post_meta_first( $wp_lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' ) : get_post_meta( $wp_lesson_id, 'lm_due_time', true ) ) );
		if ( '' === $date ) {
			return 0;
		}
		try {
			$due = new DateTimeImmutable( $date . ' ' . ( '' !== $time ? $time : '23:59' ), wp_timezone() );
			return $due->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/** 6.29.0: cursos matriculados y visibles del usuario (tabla) con su post. @return array<int, array> course_id => curso */
	public static function visible_courses( int $user_id ): array {
		$courses = array();
		foreach ( self::enrollment_index( $user_id ) as $row ) {
			$course_id = absint( $row['course_id'] ?? 0 );
			$course    = $course_id ? \ATORA\LMS\LMS_Course_Service::get( $course_id ) : null;
			if ( $course && self::course_is_student_visible( $course ) && absint( $course['wp_post_id'] ?? 0 ) > 0 ) {
				$courses[ $course_id ] = $course;
			}
		}
		return $courses;
	}

	/**
	 * 6.29.0: resumen de notas por curso matriculado y por programa. Mismo
	 * servicio y misma regla de visibilidad que el panel web.
	 */
	public static function grades( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! class_exists( 'CLMS_Student_Grades_Service' ) ) {
			return new WP_Error( 'atora_mobile_grades_unavailable', __( 'Las notas no están disponibles.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$by_wp   = array();
		$courses = array();
		foreach ( self::visible_courses( $user_id ) as $course_id => $course ) {
			$wp_course_id     = absint( $course['wp_post_id'] );
			$summary          = CLMS_Student_Grades_Service::course_summary( $user_id, $wp_course_id );
			$by_wp[ $wp_course_id ] = $summary;
			unset( $summary['wp_course_id'] );
			// 6.29.1: para que la app avise de una nota nueva (solo notas liberadas).
			$graded = array_filter(
				CLMS_Student_Grades_Service::course_activities( $user_id, $wp_course_id ),
				static fn( array $item ): bool => null !== $item['grade']
			);
			$dates  = array_filter( array_column( $graded, 'graded_at' ) );
			$summary['graded_count']   = count( $graded );
			$summary['last_graded_at'] = $dates ? max( $dates ) : null;
			$courses[] = array( 'course_id' => $course_id ) + $summary;
		}
		return new WP_REST_Response( array(
			'courses'      => $courses,
			'programs'     => CLMS_Student_Grades_Service::program_summaries( $user_id, $by_wp ),
			'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		), 200 );
	}

	/** 6.29.0: nota por actividad de un curso matriculado (las no liberadas no aparecen). */
	public static function course_grades( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$course_id = absint( $request['course_id'] );
		$auth      = self::authorize_course_id( $user_id, $course_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$course       = \ATORA\LMS\LMS_Course_Service::get( $course_id );
		$wp_course_id = absint( $course['wp_post_id'] ?? 0 );
		if ( ! $wp_course_id || ! class_exists( 'CLMS_Student_Grades_Service' ) ) {
			return new WP_Error( 'atora_mobile_grades_unavailable', __( 'Las notas no están disponibles.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$activities = array();
		foreach ( CLMS_Student_Grades_Service::course_activities( $user_id, $wp_course_id ) as $item ) {
			$lesson = \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( absint( $item['wp_lesson_id'] ) );
			if ( ! $lesson || 'published' !== (string) ( $lesson['status'] ?? '' ) ) {
				continue;
			}
			unset( $item['wp_lesson_id'] );
			$activities[] = array( 'lesson_id' => absint( $lesson['id'] ) ) + $item;
		}
		$summary = CLMS_Student_Grades_Service::course_summary( $user_id, $wp_course_id );
		unset( $summary['wp_course_id'] );
		return new WP_REST_Response( array(
			'course'     => array( 'course_id' => $course_id ) + $summary,
			'activities' => $activities,
		), 200 );
	}

	/** 6.29.0: firma de un enlace de certificado, atada a usuario, objeto y vencimiento. */
	public static function certificate_signature( int $user_id, string $type, int $target_id, int $expires ): string {
		return hash_hmac( 'sha256', $user_id . '|' . $type . '|' . $target_id . '|' . $expires, wp_salt( 'auth' ) . 'atora-mobile-certificate' );
	}

	const CERTIFICATE_LINK_TTL = 15 * MINUTE_IN_SECONDS;

	private static function certificate_link( int $user_id, string $type, int $target_id ): array {
		$expires = time() + self::CERTIFICATE_LINK_TTL;
		$url     = add_query_arg(
			array(
				'expires' => $expires,
				'sig'     => self::certificate_signature( $user_id, $type, $target_id, $expires ),
			),
			rest_url( self::REST_NAMESPACE . '/certificates/' . $type . '/' . $target_id . '/document' )
		);
		return array( 'download_url' => esc_url_raw( $url ), 'download_expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires ) );
	}

	/** 6.29.0: certificados obtenidos (emitidos o ya disponibles) con enlace firmado y temporal. */
	public static function certificates( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$certs   = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Certificates' ) : null;
		if ( ! $certs || ! method_exists( $certs, 'get_certificate_status_for_student_course' ) ) {
			return new WP_Error( 'atora_mobile_certificates_unavailable', __( 'Los certificados no están disponibles.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$items = array();
		foreach ( self::visible_courses( $user_id ) as $course_id => $course ) {
			$wp_course_id = absint( $course['wp_post_id'] );
			$status       = (array) $certs->get_certificate_status_for_student_course( $user_id, $wp_course_id );
			$state        = sanitize_key( (string) ( $status['status'] ?? 'pending' ) );
			if ( 'pending' === $state ) {
				continue;
			}
			$record  = (array) ( $status['record'] ?? array() );
			$revoked = 'revoked' === $state;
			$items[] = array(
				'type'             => 'course',
				'id'               => $wp_course_id,
				'course_id'        => $course_id,
				'title'            => sanitize_text_field( (string) ( $course['title'] ?? '' ) ),
				'status'           => $revoked ? 'revoked' : ( empty( $record ) ? 'available' : 'issued' ),
				'issued_at'        => sanitize_text_field( (string) ( $record['issued_at'] ?? '' ) ),
				'certificate_code' => sanitize_text_field( (string) ( $record['certificate_code'] ?? '' ) ),
			) + ( $revoked ? array( 'download_url' => '', 'download_expires_at' => null ) : self::certificate_link( $user_id, 'course', $wp_course_id ) );
		}
		$programs = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Program_Certificate_Service' ) : null;
		if ( $programs && method_exists( $programs, 'get_program_certificate_record' ) && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_programs' ) ) {
			foreach ( array_filter( array_map( 'absint', (array) CLMS_Helper::get_user_enrolled_programs( $user_id ) ) ) as $program_id ) {
				$record = (array) $programs->get_program_certificate_record( $user_id, $program_id );
				if ( empty( $record ) ) {
					continue;
				}
				$revoked = 'revoked' === sanitize_key( (string) ( $record['status'] ?? '' ) );
				$items[] = array(
					'type'             => 'program',
					'id'               => $program_id,
					'course_id'        => null,
					'title'            => sanitize_text_field( (string) get_the_title( $program_id ) ),
					'status'           => $revoked ? 'revoked' : 'issued',
					'issued_at'        => sanitize_text_field( (string) ( $record['issued_at'] ?? '' ) ),
					'certificate_code' => sanitize_text_field( (string) ( $record['certificate_code'] ?? '' ) ),
				) + ( $revoked ? array( 'download_url' => '', 'download_expires_at' => null ) : self::certificate_link( $user_id, 'program', $program_id ) );
			}
		}
		return new WP_REST_Response( array( 'certificates' => $items ), 200 );
	}

	/**
	 * 6.29.0: documento del certificado (HTML provisional). Exige el token del
	 * usuario y una firma vigente emitida para ese mismo usuario: otro usuario o
	 * un enlace vencido reciben 403.
	 */
	public static function certificate_document( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$type      = 'program' === (string) $request['target_type'] ? 'program' : 'course';
		$target_id = absint( $request['target_id'] );
		$expires   = absint( $request->get_param( 'expires' ) );
		$signature = (string) $request->get_param( 'sig' );
		$expected  = self::certificate_signature( $user_id, $type, $target_id, $expires );
		if ( $expires < time() || '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'atora_mobile_certificate_link', __( 'El enlace del certificado venció o no es válido.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$certs = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Certificates' ) : null;
		if ( ! $certs || ! method_exists( $certs, 'resolve_certificate_for_user' ) ) {
			return new WP_Error( 'atora_mobile_certificates_unavailable', __( 'Los certificados no están disponibles.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$resolved = $certs->resolve_certificate_for_user( $user_id, $type, $target_id );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$html = $certs->render_certificate_document( $user_id, $resolved );
		// Se entrega el documento tal cual (no JSON) para guardarlo y verlo sin conexión.
		add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $html ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			}
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- documento ya escapado al generarse.
			return true;
		} );
		return new WP_REST_Response( null, 200 );
	}

	/** 6.28.0: cambios desde el cursor, solo de los cursos matriculados del usuario. */
	public static function sync_changes( WP_REST_Request $request ) {
		if ( ! class_exists( '\\ATORA\\LMS\\LMS_Content_Changes' ) ) {
			return new WP_Error( 'atora_mobile_sync_unavailable', __( 'La sincronización no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$user_id = get_current_user_id();
		$courses = array();
		foreach ( self::enrollment_index( $user_id ) as $row ) {
			$course_id = absint( $row['course_id'] ?? 0 );
			$course    = $course_id ? \ATORA\LMS\LMS_Course_Service::get( $course_id ) : null;
			if ( $course && self::course_is_student_visible( $course ) ) {
				$courses[] = $course_id;
			}
		}
		$courses = array_values( array_unique( $courses ) );
		$result  = \ATORA\LMS\LMS_Content_Changes::for_user( $user_id, $courses, sanitize_text_field( (string) $request->get_param( 'cursor' ) ) );
		// Matrículas vigentes: cubre también caducidades y matrículas legacy, que no generan evento.
		$result['enrolled_course_ids'] = $courses;
		return new WP_REST_Response( $result, 200 );
	}

	/** 6.28.0: posición de reproducción; gana la marca más reciente según client_recorded_at. */
	public static function save_position( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$lesson_id = absint( $request['lesson_id'] );
		$lesson    = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id );
		$not_found = new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		if ( ! $lesson || 'published' !== (string) ( $lesson['status'] ?? '' ) ) {
			return $not_found;
		}
		$course_id = absint( $lesson['course_id'] );
		// Sin matrícula, la lección no existe para este usuario.
		if ( is_wp_error( self::authorize_course_id( $user_id, $course_id ) ) ) {
			return $not_found;
		}

		$event_id = (string) $request->get_param( 'client_event_id' );
		$recorded = ATORA_Mobile_Position_Service::parse_recorded_at( (string) $request->get_param( 'client_recorded_at' ) );
		$position = $request->get_param( 'position_seconds' );
		$duration = $request->get_param( 'duration_seconds' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $event_id ) || null === $recorded
			|| ! is_numeric( $position ) || (float) $position < 0
			|| ( null !== $duration && ( ! is_numeric( $duration ) || (float) $duration < 0 ) ) ) {
			return new WP_Error( 'atora_mobile_position_invalid', __( 'Posición no válida.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		// 6.28.2: video_key opcional; sin él (apps anteriores) es el primer video.
		$video_key = (string) ( $request->get_param( 'video_key' ) ?? '' );
		if ( '' !== $video_key ) {
			$keys = class_exists( 'ATORA_Lesson_Videos' ) ? array_column( ATORA_Lesson_Videos::visible( absint( $lesson['wp_post_id'] ?? 0 ) ), 'key' ) : array();
			if ( ! in_array( $video_key, $keys, true ) ) {
				return new WP_Error( 'atora_mobile_video_not_found', __( 'El video no pertenece a esta lección.', 'atora-lms' ), array( 'status' => 404 ) );
			}
		}

		$result = ATORA_Mobile_Position_Service::save( $user_id, $lesson_id, $course_id, (int) floor( (float) $position ), (int) floor( (float) $duration ), $event_id, $recorded, $video_key );
		if ( is_wp_error( $result ) ) {
			return $result; // 503: la app reintenta.
		}
		return new WP_REST_Response( $result, 200 );
	}

	public static function complete_lesson( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$lesson_id = absint( $request['lesson_id'] );
		$lesson   = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id );
		if ( ! $lesson ) {
			return new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$course_id = absint( $lesson['course_id'] );
		$auth = self::authorize_course_id( $user_id, $course_id );
		if ( is_wp_error( $auth ) ) { return $auth; }
		$ok = \ATORA\LMS\LMS_Enrollment_Service::complete_lesson( $user_id, $lesson_id );
		return new WP_REST_Response( array(
			'completed' => $ok,
			'progress'  => \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $course_id ),
		), $ok ? 200 : 400 );
	}

	/**
	 * Extrae y normaliza únicamente videos de Google Drive autorizados.
	 * Nunca devuelve el iframe original ni acepta hosts arbitrarios.
	 */
		private static function google_drive_embed_url( string $video_url, string $raw_content ): string {
			$candidates = array();

			if ( '' !== $video_url ) {
				$candidates[] = $video_url;
			}

			$decoded_content = html_entity_decode( $raw_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( preg_match_all( '#(?:https?:)?//(?:drive|docs)\\.google\\.com/[^"\'<>\\s]+#i', $decoded_content, $matches ) ) {
				$candidates = array_merge( $candidates, (array) $matches[0] );
			}

			foreach ( $candidates as $candidate ) {
				$url = esc_url_raw( trim( (string) $candidate ) );
				if ( str_starts_with( $url, '//' ) ) {
					$url = 'https:' . $url;
				}
				$parts = wp_parse_url( $url );
				$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
				$host  = preg_replace( '/^www\\./', '', $host );
				if ( ! in_array( $host, array( 'drive.google.com', 'docs.google.com' ), true ) ) {
					continue;
			}

			$file_id = '';
			$path    = (string) ( $parts['path'] ?? '' );
			if ( preg_match( '#/file/d/([a-zA-Z0-9_-]+)#', $path, $id_match ) ) {
				$file_id = (string) $id_match[1];
			} elseif ( ! empty( $parts['query'] ) ) {
				parse_str( (string) $parts['query'], $query );
				$file_id = sanitize_text_field( (string) ( $query['id'] ?? '' ) );
			}

			if ( preg_match( '/^[a-zA-Z0-9_-]{10,}$/', $file_id ) ) {
				return 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/preview';
			}
		}

		return '';
	}

	private static function prepare_enrollments( int $user_id ): array {
		$rows = self::enrollment_index( $user_id );
		$items = array();
		foreach ( $rows as $row ) {
			$course_id = absint( $row['course_id'] ?? 0 );
			$course    = \ATORA\LMS\LMS_Course_Service::get( $course_id );
			if ( ! $course || ! self::course_is_student_visible( $course ) ) {
				continue;
			}
			$progress = \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $course_id );
			$items[] = array_merge(
				self::safe_course( $course ),
				array(
					'enrollment_status' => sanitize_key( (string) ( $row['status'] ?? 'active' ) ),
					'grade'             => isset( $row['grade'] ) ? $row['grade'] : null,
					'total_lessons'     => absint( $progress['total_lessons'] ?? 0 ),
					'completed_lessons' => absint( $progress['completed_lessons'] ?? 0 ),
					'progress'          => absint( $progress['progress_pct'] ?? 0 ),
					'is_complete'       => ! empty( $progress['is_complete'] ),
					'last_activity'     => sanitize_text_field( (string) ( $row['last_activity'] ?? '' ) ),
				)
			);
		}
		return $items;
	}

	/**
	 * Normaliza recursos de lección (guías/archivos/enlaces) para consumo móvil.
	 *
	 * @return array<int, array{
	 *   type:string,
	 *   title:string,
	 *   description:string,
	 *   url:string,
	 *   download_url:string,
	 *   file_id:int,
	 *   mime:string,
	 *   thumb_url:string
	 * }>
	 */
	private static function normalize_lesson_resources( int $wp_lesson_id ): array {
		$raw = get_post_meta( $wp_lesson_id, '_clms_lesson_resources', true );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} elseif ( function_exists( 'maybe_unserialize' ) ) {
				$raw = maybe_unserialize( $raw );
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items = array();

		foreach ( $raw as $resource ) {
			if ( ! is_array( $resource ) ) { continue; }

			$file_id   = absint( $resource['file_id'] ?? 0 );
			$thumb_id  = absint( $resource['thumb_id'] ?? 0 );
			$title     = sanitize_text_field( (string) ( $resource['title'] ?? '' ) );
			$desc      = sanitize_textarea_field( (string) ( $resource['description'] ?? '' ) );
			$url       = esc_url_raw( (string) ( $resource['url'] ?? '' ) );
			$type      = sanitize_key( (string) ( $resource['type'] ?? '' ) );
			$mime      = '';
			$thumb_url = '';

			$resolved_url = '';

			if ( $file_id > 0 ) {
				$resolved_url = (string) wp_get_attachment_url( $file_id );
				$mime         = sanitize_text_field( (string) get_post_mime_type( $file_id ) );
				if ( '' === $title ) {
					$title = sanitize_text_field( (string) get_the_title( $file_id ) );
				}
			} elseif ( '' !== $url ) {
				$resolved_url = $url;
			}

			if ( $thumb_id > 0 ) {
				$thumb_url = (string) wp_get_attachment_url( $thumb_id );
			}

			$resolved_url = esc_url_raw( trim( $resolved_url ) );
			$thumb_url    = esc_url_raw( trim( $thumb_url ) );

			if ( '' === $resolved_url ) { continue; }

			$final_type = $type;
			if ( '' === $final_type ) {
				$final_type = $file_id > 0 ? 'file' : 'link';
			}

			$download = class_exists( 'ATORA_Download_Info' )
				? ATORA_Download_Info::for_resource( $file_id, $resolved_url )
				: array( 'downloadable' => false, 'bytes' => null, 'updated_at' => null );

			$items[] = array(
				'type'         => $final_type,
				'title'        => $title ?: ( $file_id > 0 ? __( 'Archivo', 'atora-lms' ) : __( 'Enlace', 'atora-lms' ) ),
				'description'  => $desc,
				'url'          => $resolved_url,
				'download_url' => $resolved_url,
				'file_id'      => $file_id,
				'mime'         => $mime,
				'thumb_url'    => $thumb_url,
				// 6.28.0: descargable solo si es de la propia academia; tamaño y fecha del adjunto.
				'downloadable' => (bool) $download['downloadable'],
				'bytes'        => $download['bytes'],
				'updated_at'   => $download['updated_at'],
			);
		}

		return array_values( $items );
	}

	/**
	 * Índice canónico de matrículas móviles.
	 *
	 * - Incluye `active` y `completed` de tablas.
	 * - Incluye matrículas legacy a través del helper canónico/router (y mapea wp_post_id -> course_id).
	 * - Deduplica por `course_id`.
	 *
	 * @return array<int, array{course_id:int,status:string,source:string}>
	 */
	private static function enrollment_index( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return array(); }

		if ( isset( self::$enrollment_index_cache[ $user_id ] ) ) {
			return self::$enrollment_index_cache[ $user_id ];
		}

			$by_course = array();
			$ordered   = array();
			$now       = current_time( 'mysql', true );

			// 1) Tablas: active + completed.
			if ( class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
				foreach ( array( 'active', 'completed' ) as $status ) {
					$rows = (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id, $status );
					foreach ( $rows as $row ) {
						if ( ! is_array( $row ) ) { continue; }

						$expires_at = sanitize_text_field( (string) ( $row['expires_at'] ?? '' ) );
						if ( '' !== $expires_at && $expires_at < $now ) {
							continue;
						}
						$course_id = absint( $row['course_id'] ?? 0 );
						if ( $course_id <= 0 ) { continue; }

					$row_status = sanitize_key( (string) ( $row['status'] ?? $status ) );
					if ( '' === $row_status ) { $row_status = $status; }

					// Preferir active si hay doble estado para el mismo curso.
					if ( isset( $by_course[ $course_id ] ) && 'active' === ( $by_course[ $course_id ]['status'] ?? '' ) ) {
						continue;
					}

					$by_course[ $course_id ] = array_merge(
						$row,
						array( 'course_id' => $course_id, 'status' => $row_status, 'source' => 'tables' )
					);
					$ordered[] = $course_id;
				}
			}
		}

		// 2) Legacy/router: wp_post_id (lm_course) -> course_id de tablas.
		if ( class_exists( 'CLMS_Helper' )
			&& method_exists( 'CLMS_Helper', 'get_user_enrolled_courses' )
			&& class_exists( '\\ATORA\\LMS\\LMS_Course_Service' )
			&& method_exists( '\\ATORA\\LMS\\LMS_Course_Service', 'get_by_wp_post' ) ) {
			$wp_course_ids = (array) \CLMS_Helper::get_user_enrolled_courses( $user_id );
			foreach ( $wp_course_ids as $wp_course_id ) {
				$wp_course_id = absint( $wp_course_id );
				if ( $wp_course_id <= 0 ) { continue; }

				$course = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course_id );
				$course_id = absint( is_array( $course ) ? ( $course['id'] ?? 0 ) : 0 );
				if ( $course_id <= 0 ) { continue; }

				if ( isset( $by_course[ $course_id ] ) ) {
					continue;
				}

				$is_completed = false;
				if ( method_exists( 'CLMS_Helper', 'is_course_completed' ) ) {
					$is_completed = (bool) \CLMS_Helper::is_course_completed( $user_id, $wp_course_id );
				}

				$by_course[ $course_id ] = array(
					'course_id' => $course_id,
					'status'    => $is_completed ? 'completed' : 'active',
					'source'    => 'legacy',
				);
				$ordered[] = $course_id;
			}
		}

		// 3) Salida ordenada y deduplicada.
		$out = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $ordered ) ) ) ) as $course_id ) {
			if ( isset( $by_course[ $course_id ] ) ) {
				$out[] = $by_course[ $course_id ];
			}
		}

		self::$enrollment_index_cache[ $user_id ] = $out;
		return $out;
	}

	private static function authorize_course_id( int $user_id, int $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'atora_mobile_auth_required', __( 'Se requiere autenticación móvil.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		if ( $course_id <= 0 ) {
			return new WP_Error( 'atora_mobile_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$index = self::enrollment_index( $user_id );
		foreach ( $index as $row ) {
			if ( absint( $row['course_id'] ?? 0 ) === $course_id ) {
				$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
				if ( ! $course || ! self::course_is_student_visible( $course ) ) {
					return new WP_Error( 'atora_mobile_course_forbidden', __( 'No tienes acceso a este curso.', 'atora-lms' ), array( 'status' => 403 ) );
				}
				return true;
			}
		}

		// Fallback defensivo: si el curso tiene wp_post_id válido y PUBLICADO, confirmar matrícula legacy del mismo usuario.
		if ( class_exists( '\\ATORA\\LMS\\LMS_Course_Service' )
			&& method_exists( '\\ATORA\\LMS\\LMS_Course_Service', 'get' )
			&& class_exists( 'CLMS_Helper' )
			&& method_exists( 'CLMS_Helper', 'user_is_enrolled_in_course' ) ) {
			$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
			$wp_course_id = absint( is_array( $course ) ? ( $course['wp_post_id'] ?? 0 ) : 0 );
			if ( $wp_course_id > 0
				&& \ATORA\LMS\LMS_Course_Service::legacy_wp_course_post_is_public( $wp_course_id )
				&& \CLMS_Helper::user_is_enrolled_in_course( $user_id, $wp_course_id ) ) {
				return true;
			}
		}

		return new WP_Error( 'atora_mobile_course_forbidden', __( 'No tienes acceso a este curso.', 'atora-lms' ), array( 'status' => 403 ) );
	}

	/**
	 * Visibilidad estudiantil del curso tabular.
	 *
	 * Regla: status='published' y, si `wp_post_id > 0`, el CPT debe existir y
	 * estar publicado (no trash/draft/private/missing).
	 */
	private static function course_is_student_visible( array $course ): bool {
		if ( 'published' !== (string) ( $course['status'] ?? '' ) ) {
			return false;
		}
		$wp_course_id = absint( $course['wp_post_id'] ?? 0 );
		if ( $wp_course_id <= 0 ) {
			return true;
		}
		if ( ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' )
			|| ! method_exists( '\\ATORA\\LMS\\LMS_Course_Service', 'legacy_wp_course_post_is_public' ) ) {
			return false;
		}
		return \ATORA\LMS\LMS_Course_Service::legacy_wp_course_post_is_public( $wp_course_id );
	}

	/**
	 * 6.30.0: enlace interno de la app para una lección (post de WordPress):
	 * lección, tarea o quiz, con los ids de la API (tablas). Null si no es visible.
	 *
	 * @return array{type:string, id:int, course_id:int}|null
	 */
	public static function lesson_link( int $wp_lesson_id ): ?array {
		if ( $wp_lesson_id <= 0 || ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
			return null;
		}
		$lesson = \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $wp_lesson_id );
		if ( ! $lesson || 'published' !== (string) ( $lesson['status'] ?? '' ) ) {
			return null;
		}
		$id   = absint( $lesson['id'] );
		$type = 'lesson';
		if ( self::lesson_has_assignment( $wp_lesson_id ) ) {
			$type = 'assignment';
		} elseif ( '1' === (string) get_post_meta( $wp_lesson_id, '_clms_quiz_enabled', true ) || self::has_table_quiz( $id ) ) {
			$type = 'quiz';
		}
		return array( 'type' => $type, 'id' => $id, 'course_id' => absint( $lesson['course_id'] ) );
	}

	/** 6.30.0: id de la tabla para un curso (post de WordPress), o 0. */
	public static function table_course_id( int $wp_course_id ): int {
		if ( $wp_course_id <= 0 || ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
			return 0;
		}
		$course = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course_id );
		return $course ? absint( $course['id'] ) : 0;
	}

	/** 6.27.3: imagen destacada en vivo; la columna de la tabla solo como respaldo. */
	private static function course_cover( array $course ): string {
		return class_exists( 'ATORA_Course_Cover_Resolver' )
			? ATORA_Course_Cover_Resolver::resolve( $course )
			: esc_url_raw( (string) ( $course['thumbnail_url'] ?? '' ) );
	}

	private static function safe_course( array $course ): array {
		return array(
			'id'             => absint( $course['id'] ?? 0 ),
			'revision'       => absint( $course['revision'] ?? 1 ),
			'title'          => sanitize_text_field( (string) ( $course['title'] ?? '' ) ),
			'excerpt'        => sanitize_textarea_field( (string) ( $course['excerpt'] ?? '' ) ),
			'thumbnail_url'  => self::course_cover( $course ),
			'duration_hours' => (float) ( $course['duration_hours'] ?? 0 ),
			'level'          => sanitize_key( (string) ( $course['level'] ?? '' ) ),
			'language'       => sanitize_key( (string) ( $course['language'] ?? 'es' ) ),
		);
	}

	private static function enrolled_wp_program_ids( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return array(); }

		// 1) Tabla canónica si existe (migrator/cutover).
		if ( class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) && method_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service', 'get_enrolled_wp_program_ids' ) ) {
			$ids = (array) \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_program_ids( $user_id );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			if ( ! empty( $ids ) ) {
				return $ids;
			}
		}

		// 2) Legacy/usermeta.
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_programs' ) ) {
			$ids = (array) \CLMS_Helper::get_user_enrolled_programs( $user_id );
			return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}

		$raw = get_user_meta( $user_id, '_clms_enrolled_programs', true );
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
	}

	private static function safe_program_post( WP_Post $post ): array {
		$thumb = get_the_post_thumbnail_url( $post->ID, 'full' );
		$excerpt = has_excerpt( $post->ID ) ? (string) $post->post_excerpt : wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 28 );
		return array(
			'id'            => absint( $post->ID ),
			'title'         => sanitize_text_field( get_the_title( $post->ID ) ),
			'excerpt'       => sanitize_textarea_field( $excerpt ),
			'thumbnail_url' => esc_url_raw( $thumb ? $thumb : '' ),
			'subtitle'      => (string) get_post_meta( $post->ID, '_clms_program_subtitle', true ),
			'duration'      => (string) get_post_meta( $post->ID, '_clms_program_duration', true ),
			'difficulty'    => (string) get_post_meta( $post->ID, '_clms_program_difficulty', true ),
			'modality'      => (string) get_post_meta( $post->ID, '_clms_program_modality', true ),
		);
	}

	private static function prepare_user( $user ): array {
		return array(
			'id'           => absint( $user->ID ?? 0 ),
			'display_name' => sanitize_text_field( (string) ( $user->display_name ?? '' ) ),
			'email'        => sanitize_email( (string) ( $user->user_email ?? '' ) ),
			'avatar_url'   => esc_url_raw( get_avatar_url( absint( $user->ID ?? 0 ), array( 'size' => 192 ) ) ),
			'roles'        => array_values( array_map( 'sanitize_key', (array) ( $user->roles ?? array() ) ) ),
		);
	}

	private static function completed_lesson_ids( int $user_id, int $course_id ): array {
		global $wpdb;
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT lesson_id FROM {$wpdb->prefix}atora_lesson_progress WHERE user_id = %d AND course_id = %d AND status = 'completed'",
			$user_id,
			$course_id
		) ) );
	}

	private static function transport_is_secure(): bool {
		if ( is_ssl() ) {
			return true;
		}
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			return true;
		}
		return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
	}

	private static function throttle_key( string $login ): string {
		$ip_hash = class_exists( 'ATORA_Client_IP' ) ? ATORA_Client_IP::get_hashed() : 'unknown';
		return 'atora_mobile_login_' . md5( strtolower( $login ) . '|' . $ip_hash );
	}

	private static function login_throttle( string $login ) {
		$count = absint( get_transient( self::throttle_key( $login ) ) );
		if ( $count >= self::LOGIN_LIMIT ) {
			return new WP_Error( 'atora_mobile_rate_limited', __( 'Demasiados intentos. Espera antes de volver a intentarlo.', 'atora-lms' ), array( 'status' => 429 ) );
		}
		return true;
	}

	private static function record_login_failure( string $login ): void {
		$key = self::throttle_key( $login );
		set_transient( $key, absint( get_transient( $key ) ) + 1, self::LOGIN_WINDOW );
	}

	private static function clear_login_failures( string $login ): void {
		delete_transient( self::throttle_key( $login ) );
	}
}
