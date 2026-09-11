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
		register_rest_route( self::REST_NAMESPACE, '/courses/(?P<course_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'course' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'course_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/lessons/(?P<lesson_id>\d+)/complete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'complete_lesson' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
			'args'                => array( 'lesson_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
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
		return new WP_REST_Response( array(
			'product'          => 'ATORA LMS',
			'api'              => self::REST_NAMESPACE,
			'api_version'      => 1,
			'lms_version'      => defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '',
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url( '/' ),
			'authentication'   => 'opaque_bearer',
			'access_ttl'       => ATORA_Mobile_Token_Service::ACCESS_TTL,
			'refresh_ttl'      => ATORA_Mobile_Token_Service::REFRESH_TTL,
			'features'         => array( 'profile', 'dashboard', 'courses', 'progress', 'lesson_completion' ),
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

	public static function course( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$course_id = absint( $request['course_id'] );
		$enrollment = \ATORA\LMS\LMS_Enrollment_Service::get_enrollment( $user_id, $course_id );
		if ( ! $enrollment ) {
			return new WP_Error( 'atora_mobile_course_forbidden', __( 'No tienes acceso a este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
		if ( ! $course || 'published' !== (string) $course['status'] ) {
			return new WP_Error( 'atora_mobile_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$lessons   = \ATORA\LMS\LMS_Course_Service::get_lessons( $course_id );
		$completed = self::completed_lesson_ids( $user_id, $course_id );
		foreach ( $lessons as &$lesson ) {
			$lesson['completed'] = in_array( (int) $lesson['id'], $completed, true );
			unset( $lesson['video_url'] );
		}
		unset( $lesson );
		return new WP_REST_Response( array(
			'course'     => self::safe_course( $course ),
			'progress'   => \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $course_id ),
			'curriculum' => $lessons,
		), 200 );
	}

	public static function complete_lesson( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$lesson_id = absint( $request['lesson_id'] );
		$lesson   = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id );
		if ( ! $lesson ) {
			return new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$course_id = absint( $lesson['course_id'] );
		if ( ! \ATORA\LMS\LMS_Enrollment_Service::get_enrollment( $user_id, $course_id ) ) {
			return new WP_Error( 'atora_mobile_lesson_forbidden', __( 'No tienes acceso a esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$ok = \ATORA\LMS\LMS_Enrollment_Service::complete_lesson( $user_id, $lesson_id );
		return new WP_REST_Response( array(
			'completed' => $ok,
			'progress'  => \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $course_id ),
		), $ok ? 200 : 400 );
	}

	private static function prepare_enrollments( int $user_id ): array {
		$rows = \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id );
		$items = array();
		foreach ( $rows as $row ) {
			$course_id = absint( $row['course_id'] ?? 0 );
			$course    = \ATORA\LMS\LMS_Course_Service::get( $course_id );
			if ( ! $course || 'published' !== (string) ( $course['status'] ?? '' ) ) {
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

	private static function safe_course( array $course ): array {
		return array(
			'id'             => absint( $course['id'] ?? 0 ),
			'title'          => sanitize_text_field( (string) ( $course['title'] ?? '' ) ),
			'excerpt'        => sanitize_textarea_field( (string) ( $course['excerpt'] ?? '' ) ),
			'thumbnail_url'  => esc_url_raw( (string) ( $course['thumbnail_url'] ?? '' ) ),
			'duration_hours' => (float) ( $course['duration_hours'] ?? 0 ),
			'level'          => sanitize_key( (string) ( $course['level'] ?? '' ) ),
			'language'       => sanitize_key( (string) ( $course['language'] ?? 'es' ) ),
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
