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
			'features'         => array( 'profile', 'dashboard', 'courses', 'progress', 'lesson_completion', 'quizzes' ),
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
		$auth = self::authorize_course_id( $user_id, $course_id );
		if ( is_wp_error( $auth ) ) { return $auth; }
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
		$video_url    = esc_url_raw( (string) ( $lesson['video_url'] ?? '' ) );

		// Compatibilidad con las claves usadas por el editor de lecciones.
		if ( $wp_post_id > 0 && '' === $video_url ) {
			$extra_videos = get_post_meta( $wp_post_id, '_clms_lesson_extra_videos', true );
			if ( is_array( $extra_videos ) ) {
				foreach ( $extra_videos as $extra_video ) {
					$candidate = is_array( $extra_video ) ? esc_url_raw( (string) ( $extra_video['url'] ?? '' ) ) : '';
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

		$video_embed = self::google_drive_embed_url( $video_url, $raw_content );

		return new WP_REST_Response( array(
			'lesson' => array(
				'id'           => $lesson_id,
				'course_id'    => $course_id,
				'title'        => sanitize_text_field( (string) ( $lesson['title'] ?? '' ) ),
				'type'         => sanitize_key( (string) ( $lesson['type'] ?? 'text' ) ),
				'duration_min' => absint( $lesson['duration_min'] ?? 0 ),
				'video_url'       => $video_url,
				'video_embed_url' => $video_embed,
				'video_provider'  => '' !== $video_embed ? 'google_drive' : ( '' !== $video_url ? 'direct' : '' ),
				'content_html'    => $content_html,
				'content_text' => sanitize_textarea_field( wp_strip_all_tags( $content_html ) ),
				'completed'    => in_array( $lesson_id, self::completed_lesson_ids( $user_id, $course_id ), true ),
				'quiz_available'=> $wp_post_id && '1' === (string) get_post_meta( $wp_post_id, '_clms_quiz_enabled', true ),
			),
		), 200 );
	}

	public static function quiz( WP_REST_Request $request ) {
		$context = self::quiz_context( absint( $request['lesson_id'] ) );
		if ( is_wp_error( $context ) ) {
			return $context;
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
		$wp_post_id = absint( $lesson['wp_post_id'] ?? 0 );
		if ( ! $wp_post_id || 'lm_lesson' !== get_post_type( $wp_post_id ) ) {
			return new WP_Error( 'atora_mobile_quiz_not_found', __( 'Esta lección no contiene una evaluación móvil.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		return array( 'lesson_id' => $lesson_id, 'course_id' => $course_id, 'wp_post_id' => $wp_post_id );
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
			if ( preg_match_all( '#https?://(?:drive|docs)\\.google\\.com/[^"\'<>\\s]+#i', $decoded_content, $matches ) ) {
				$candidates = array_merge( $candidates, (array) $matches[0] );
			}

		foreach ( $candidates as $candidate ) {
			$url   = esc_url_raw( trim( (string) $candidate ) );
			$parts = wp_parse_url( $url );
			$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
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

		// 1) Tablas: active + completed.
		if ( class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
			foreach ( array( 'active', 'completed' ) as $status ) {
				$rows = (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id, $status );
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) { continue; }
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
				return true;
			}
		}

		// Fallback defensivo: si el curso tiene wp_post_id válido, confirmar matrícula legacy del mismo usuario.
		if ( class_exists( '\\ATORA\\LMS\\LMS_Course_Service' )
			&& method_exists( '\\ATORA\\LMS\\LMS_Course_Service', 'get' )
			&& class_exists( 'CLMS_Helper' )
			&& method_exists( 'CLMS_Helper', 'user_is_enrolled_in_course' ) ) {
			$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
			$wp_course_id = absint( is_array( $course ) ? ( $course['wp_post_id'] ?? 0 ) : 0 );
			if ( $wp_course_id > 0 && \CLMS_Helper::user_is_enrolled_in_course( $user_id, $wp_course_id ) ) {
				return true;
			}
		}

		return new WP_Error( 'atora_mobile_course_forbidden', __( 'No tienes acceso a este curso.', 'atora-lms' ), array( 'status' => 403 ) );
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
