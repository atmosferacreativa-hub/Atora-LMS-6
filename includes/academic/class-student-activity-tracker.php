<?php
/**
 * Tracking liviano de actividad de estudiantes (acceso y permanencia).
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Student_Activity_Tracker {

	const USER_META_SESSION             = '_clms_activity_session_context';
	const USER_META_LAST_ACCESS         = '_clms_last_access_at';
	const USER_META_TOTAL_SECONDS       = '_clms_total_time_seconds';
	const USER_META_EVENTS              = '_clms_recent_activity_events';
	const USER_META_COURSE_PREFIX       = '_clms_time_course_';
	const USER_META_COURSE_LAST_PREFIX  = '_clms_last_access_course_';
	const USER_META_LESSON_PREFIX       = '_clms_time_lesson_';
	const USER_META_LESSON_LAST_PREFIX  = '_clms_last_access_lesson_';
	const USER_META_PROGRAM_PREFIX      = '_clms_time_program_';
	const USER_META_PROGRAM_LAST_PREFIX = '_clms_last_access_program_';
	const MAX_EVENT_ROWS                = 60;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'bootstrap_front_session' ), 20 );
		add_action( 'wp_footer', array( $this, 'render_tracker_script' ), 100 );
		add_action( 'wp_ajax_clms_track_student_activity', array( $this, 'ajax_track_activity' ) );
	}

	/**
	 * Inicializa sesión de actividad cuando se abre curso/lección/programa.
	 *
	 * @return void
	 */
	public function bootstrap_front_session() {
		if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$post_id = absint( get_queried_object_id() );
		if ( ! $user_id || ! $post_id ) {
			return;
		}

		$post_type = sanitize_key( (string) get_post_type( $post_id ) );
		if ( ! in_array( $post_type, array( 'lm_course', 'lm_lesson', 'lm_program' ), true ) ) {
			return;
		}

		if ( 'lm_lesson' === $post_type && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_access_lesson' ) ) {
			if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $post_id ) ) {
				return;
			}
		}

		$course_id = 0;
		if ( 'lm_course' === $post_type ) {
			$course_id = $post_id;
		} elseif ( 'lm_lesson' === $post_type && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
			$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $post_id ) );
		}

		$now     = time();
		$session = array(
			'session_id' => sanitize_text_field( wp_generate_uuid4() ),
			'post_id'    => $post_id,
			'post_type'  => $post_type,
			'course_id'  => $course_id,
			'started_at' => $now,
			'last_ping'  => $now,
		);
		update_user_meta( $user_id, self::USER_META_SESSION, $session );
		$this->touch_access( $user_id, $session, 0 );
	}

	/**
	 * Renderiza script de heartbeat para permanencia.
	 *
	 * @return void
	 */
	public function render_tracker_script() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$session = get_user_meta( $user_id, self::USER_META_SESSION, true );
		$session = is_array( $session ) ? $session : array();
		$post_id = absint( get_queried_object_id() );
		if ( empty( $session['post_id'] ) || $post_id !== absint( $session['post_id'] ) ) {
			return;
		}

		$payload = array(
			'action'   => 'clms_track_student_activity',
			'_ajax_nonce' => wp_create_nonce( 'clms_track_student_activity' ),
			'post_id'  => $post_id,
		);
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		(function(){
			if (window.__clmsActivityTrackerStarted) { return; }
			window.__clmsActivityTrackerStarted = true;
			var payload = <?php echo wp_json_encode( $payload ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var ping = function() {
				try {
					var data = new FormData();
					Object.keys(payload).forEach(function(key){ data.append(key, payload[key]); });
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
				} catch (e) {}
			};
			var intervalId = setInterval(ping, 45000);
			ping();
			window.addEventListener('beforeunload', function(){
				try {
					if (navigator.sendBeacon) {
						var beacon = new FormData();
						Object.keys(payload).forEach(function(key){ beacon.append(key, payload[key]); });
						navigator.sendBeacon(ajaxUrl, beacon);
					} else {
						ping();
					}
				} catch (e) {}
				clearInterval(intervalId);
			});
		})();
		</script>
		<?php
	}

	/**
	 * Endpoint AJAX para acumular permanencia.
	 *
	 * @return void
	 */
	public function ajax_track_activity() {
		check_ajax_referer( 'clms_track_student_activity' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sesión no válida.', 'atora-lms' ) ), 403 );
		}

		$user_id = get_current_user_id();
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$session = get_user_meta( $user_id, self::USER_META_SESSION, true );
		$session = is_array( $session ) ? $session : array();

		if ( $post_id <= 0 || empty( $session['post_id'] ) || $post_id !== absint( $session['post_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Contexto de actividad inválido.', 'atora-lms' ) ), 400 );
		}

		$now      = time();
		$last_ping = isset( $session['last_ping'] ) ? absint( $session['last_ping'] ) : $now;
		$elapsed  = max( 0, $now - $last_ping );
		$elapsed  = min( 180, $elapsed );
		$session['last_ping'] = $now;
		update_user_meta( $user_id, self::USER_META_SESSION, $session );
		$this->touch_access( $user_id, $session, $elapsed );

		wp_send_json_success(
			array(
				'elapsed' => $elapsed,
			)
		);
	}

	/**
	 * Resumen de actividad de un estudiante.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso opcional.
	 * @return array<string,mixed>
	 */
	public function get_student_activity_summary( $user_id, $course_id = 0 ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id ) {
			return array(
				'last_access'             => '',
				'total_time_seconds'      => 0,
				'course_time_seconds'     => 0,
				'course_last_access'      => '',
				'recent_events'           => array(),
			);
		}

		$events = get_user_meta( $user_id, self::USER_META_EVENTS, true );
		$events = is_array( $events ) ? $events : array();

		return array(
			'last_access'         => sanitize_text_field( (string) get_user_meta( $user_id, self::USER_META_LAST_ACCESS, true ) ),
			'total_time_seconds'  => absint( get_user_meta( $user_id, self::USER_META_TOTAL_SECONDS, true ) ),
			'course_time_seconds' => $course_id ? absint( get_user_meta( $user_id, self::USER_META_COURSE_PREFIX . $course_id, true ) ) : 0,
			'course_last_access'  => $course_id ? sanitize_text_field( (string) get_user_meta( $user_id, self::USER_META_COURSE_LAST_PREFIX . $course_id, true ) ) : '',
			'recent_events'       => array_slice( $events, 0, 10 ),
		);
	}

	/**
	 * Actualiza métricas de acceso.
	 *
	 * @param int   $user_id  Usuario.
	 * @param array $session  Sesión.
	 * @param int   $elapsed  Segundos.
	 * @return void
	 */
	protected function touch_access( $user_id, $session, $elapsed ) {
		$user_id = absint( $user_id );
		$elapsed = max( 0, absint( $elapsed ) );
		$session = is_array( $session ) ? $session : array();
		if ( ! $user_id ) {
			return;
		}

		$now_mysql = current_time( 'mysql' );
		update_user_meta( $user_id, self::USER_META_LAST_ACCESS, $now_mysql );

		if ( $elapsed > 0 ) {
			$total_seconds = absint( get_user_meta( $user_id, self::USER_META_TOTAL_SECONDS, true ) );
			update_user_meta( $user_id, self::USER_META_TOTAL_SECONDS, $total_seconds + $elapsed );
		}

		$post_id   = absint( $session['post_id'] ?? 0 );
		$post_type = sanitize_key( (string) ( $session['post_type'] ?? '' ) );
		$course_id = absint( $session['course_id'] ?? 0 );

		if ( $course_id ) {
			update_user_meta( $user_id, self::USER_META_COURSE_LAST_PREFIX . $course_id, $now_mysql );
			if ( $elapsed > 0 ) {
				$course_seconds = absint( get_user_meta( $user_id, self::USER_META_COURSE_PREFIX . $course_id, true ) );
				update_user_meta( $user_id, self::USER_META_COURSE_PREFIX . $course_id, $course_seconds + $elapsed );
			}
		}
		if ( 'lm_lesson' === $post_type && $post_id ) {
			update_user_meta( $user_id, self::USER_META_LESSON_LAST_PREFIX . $post_id, $now_mysql );
			if ( $elapsed > 0 ) {
				$lesson_seconds = absint( get_user_meta( $user_id, self::USER_META_LESSON_PREFIX . $post_id, true ) );
				update_user_meta( $user_id, self::USER_META_LESSON_PREFIX . $post_id, $lesson_seconds + $elapsed );
			}
		}
		if ( 'lm_program' === $post_type && $post_id ) {
			update_user_meta( $user_id, self::USER_META_PROGRAM_LAST_PREFIX . $post_id, $now_mysql );
			if ( $elapsed > 0 ) {
				$program_seconds = absint( get_user_meta( $user_id, self::USER_META_PROGRAM_PREFIX . $post_id, true ) );
				update_user_meta( $user_id, self::USER_META_PROGRAM_PREFIX . $post_id, $program_seconds + $elapsed );
			}
		}

		$events   = get_user_meta( $user_id, self::USER_META_EVENTS, true );
		$events   = is_array( $events ) ? $events : array();
		$events[] = array(
			'post_id'    => $post_id,
			'post_type'  => $post_type,
			'course_id'  => $course_id,
			'elapsed'    => $elapsed,
			'accessed_at'=> $now_mysql,
		);
		if ( count( $events ) > self::MAX_EVENT_ROWS ) {
			$events = array_slice( $events, -self::MAX_EVENT_ROWS );
		}
		update_user_meta( $user_id, self::USER_META_EVENTS, array_values( $events ) );
	}
}

