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

		$video_embed = self::google_drive_embed_url( $video_url, $raw_content );
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
				'video_provider'  => '' !== $video_embed ? 'google_drive' : ( '' !== $video_url ? 'direct' : '' ),
				'content_html'    => $content_html,
				'content_text' => sanitize_textarea_field( wp_strip_all_tags( $content_html ) ),
				'completed'    => in_array( $lesson_id, self::completed_lesson_ids( $user_id, $course_id ), true ),
				'quiz_available'=> $quiz_available,
				'resources'      => $resources,
			),
		), 200 );
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
		$wp_post_id = absint( $lesson['wp_post_id'] ?? 0 );
		if ( ! $wp_post_id || 'lm_lesson' !== get_post_type( $wp_post_id ) ) {
			return new WP_Error( 'atora_mobile_quiz_not_found', __( 'Esta lección no contiene una evaluación móvil.', 'atora-lms' ), array( 'status' => 404 ) );
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
				$token = self::issue_table_quiz_token( absint( $user_id ), $lesson_id );
				$token_issued_at = time();
			} elseif ( $time_limit > 0 && $token_issued_at <= 0 ) {
				// Normaliza transients legacy (token sin timestamp) para que el límite sea verificable.
				$token_issued_at = time();
				set_transient(
					self::table_quiz_token_key( $user_id, $lesson_id ),
					array( 'token' => (string) $token, 'issued_at' => $token_issued_at ),
					HOUR_IN_SECONDS
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

	private static function issue_table_quiz_token( int $user_id, int $lesson_id ): string {
		$token = function_exists( 'wp_generate_password' )
			? wp_generate_password( 20, false )
			: substr( sha1( (string) ( microtime( true ) . rand() ) ), 0, 20 );
		set_transient(
			self::table_quiz_token_key( $user_id, $lesson_id ),
			array(
				'token'     => (string) $token,
				'issued_at' => time(),
			),
			HOUR_IN_SECONDS
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
			if ( ( time() - $issued_at ) > $time_limit_seconds ) {
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

			$items[] = array(
				'type'         => $final_type,
				'title'        => $title ?: ( $file_id > 0 ? __( 'Archivo', 'atora-lms' ) : __( 'Enlace', 'atora-lms' ) ),
				'description'  => $desc,
				'url'          => $resolved_url,
				'download_url' => $resolved_url,
				'file_id'      => $file_id,
				'mime'         => $mime,
				'thumb_url'    => $thumb_url,
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
			'revision'       => absint( $course['revision'] ?? 1 ),
			'title'          => sanitize_text_field( (string) ( $course['title'] ?? '' ) ),
			'excerpt'        => sanitize_textarea_field( (string) ( $course['excerpt'] ?? '' ) ),
			'thumbnail_url'  => esc_url_raw( (string) ( $course['thumbnail_url'] ?? '' ) ),
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
