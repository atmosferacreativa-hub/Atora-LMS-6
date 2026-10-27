<?php
/**
 * Integración 6.39.0: clases en vivo (Zoom, Meet, Teams) con proveedores simulados. Ninguna llamada sale: todo HTTP
 * se responde con `pre_http_request`.
 *
 * Una reunión por clase (reprogramar actualiza), hora en UTC con la zona de la clase, ventana del enlace, webhook de
 * Zoom (firma real, vigencia, una vez, validación de URL, fin de reunión entre muchas lecciones, grabación), Teams
 * por Graph (crear, reprogramar, asistencia, grabación), grabación a decisión del docente, recordatorios 24 h y 1 h,
 * tarjeta de la lección, API de la app y un solo evento de calendario.
 */

declare( strict_types = 1 );

use ATORA\LiveStreaming\Live_Streaming;
use ATORA\LiveStreaming\Providers\Provider_Teams;
use ATORA\LiveStreaming\Providers\Provider_Zoom;

final class LiveClassesTest extends WP_UnitTestCase {

	/** @var array<int,array{method:string,url:string,body:mixed}> */
	private array $calls = array();
	/** @var array<string,array{0:int,1:array}> respuestas por fragmento de URL */
	private array $routes = array();

	private int $course;
	private int $teacher;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( ! class_exists( Live_Streaming::class ) ) {
			$dir = dirname( __DIR__, 2 ) . '/modules/live-streaming/';
			foreach ( array( 'class-live-session-repository.php', 'providers/class-provider-interface.php', 'providers/class-provider-zoom.php', 'providers/class-provider-teams.php', 'class-live-settings.php', 'class-live-streaming.php' ) as $file ) {
				require_once $dir . $file;
			}
			Live_Streaming::init();
		}
		global $wpdb;
		foreach ( array( 'atora_live_sessions', 'atora_attendance', 'atora_email_queue', 'atora_calendar_events' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		update_option( 'atora_live_streaming_options', array(
			'zoom_account_id' => 'acc', 'zoom_client_id' => 'cid', 'zoom_client_secret' => 'csecret', 'zoom_webhook_secret' => 'whsecret',
			'teams_tenant_id' => 'tenant', 'teams_client_id' => 'tcid', 'teams_client_secret' => 'tsecret', 'teams_organizer' => 'clases@academia.test',
		) );
		delete_transient( 'atora_zoom_access_token' );
		$this->calls  = array();
		$this->routes = array(
			'zoom.us/oauth/token'                  => array( 200, array( 'access_token' => 'zoom-token', 'expires_in' => 3600 ) ),
			'api.zoom.us/v2/users/me/meetings'     => array( 201, array( 'id' => 98765, 'join_url' => 'https://zoom.test/j/98765', 'start_url' => 'https://zoom.test/s/98765', 'password' => 'x' ) ),
			'api.zoom.us/v2/meetings/98765/recordings' => array( 200, array( 'share_url' => 'https://zoom.test/rec/share/abc', 'recording_play_passcode' => 'p4ss' ) ),
			'api.zoom.us/v2/meetings/98765'        => array( 204, array() ),
			'api.zoom.us/v2/past_meetings'         => array( 200, array( 'participants' => array() ) ),
			'login.microsoftonline.com'            => array( 200, array( 'access_token' => 'graph-token', 'expires_in' => 3600 ) ),
		);
		add_filter( 'pre_http_request', array( $this, 'fake' ), 10, 3 );

		$this->teacher = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake' ), 10 );
		parent::tearDown();
	}

	public function fake( $pre, array $args, string $url ) {
		$this->calls[] = array( 'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ), 'url' => $url, 'body' => $args['body'] ?? null );
		$best = null;
		foreach ( $this->routes as $fragment => $response ) {
			if ( false !== strpos( $url, $fragment ) && ( null === $best || strlen( $fragment ) > strlen( $best ) ) ) {
				$best = $fragment;
			}
		}
		$out = null === $best ? array( 404, array() ) : $this->routes[ $best ];
		return array( 'headers' => array(), 'body' => wp_json_encode( $out[1] ), 'response' => array( 'code' => $out[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}

	private function calls_to( string $method, string $fragment ): int {
		return count( array_filter( $this->calls, static fn( $c ) => $c['method'] === $method && false !== strpos( $c['url'], $fragment ) ) );
	}

	private function lesson(): int {
		$lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_title' => 'Clase de prueba' ) );
		update_post_meta( $lesson, '_clms_course_id', $this->course );
		return $lesson;
	}

	private function save( int $lesson, string $provider, string $local, string $mode = 'none', int $duration = 60 ) {
		return Live_Streaming::save_session( $lesson, array(
			'provider' => $provider, 'start_datetime' => $local, 'duration_minutes' => $duration, 'timezone' => 'America/Caracas', 'recording_mode' => $mode,
			'custom_url' => 'custom' === $provider ? 'https://meet.example.test/abc' : '',
		), $this->teacher );
	}

	private function local_in( int $seconds ): string {
		return wp_date( 'Y-m-d H:i', time() + $seconds, new DateTimeZone( 'America/Caracas' ) );
	}

	private function session_row( int $lesson ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_live_sessions WHERE lesson_id = %d", $lesson ), ARRAY_A );
	}

	// ── Una reunión por clase y hora UTC ────────────────────────────────────

	public function test_one_meeting_per_class_and_reschedule_updates_it(): void {
		$lesson = $this->lesson();
		$this->save( $lesson, 'zoom', '2026-11-03 18:00' );
		$this->save( $lesson, 'zoom', '2026-11-03 18:00' );
		$this->assertSame( 1, $this->calls_to( 'POST', 'users/me/meetings' ), 'Guardar otra vez no crea otra reunión.' );

		$this->save( $lesson, 'zoom', '2026-11-04 19:30', 'provider' );
		$this->assertSame( 1, $this->calls_to( 'POST', 'users/me/meetings' ) );
		$this->assertSame( 1, $this->calls_to( 'PATCH', 'meetings/98765' ), 'Reprogramar actualiza la reunión existente.' );
		$patch = json_decode( (string) end( $this->calls )['body'], true );
		$this->assertSame( '2026-11-04T23:30:00Z', $patch['start_time'], '19:30 en Caracas = 23:30 UTC.' );
		$this->assertSame( 'cloud', $patch['settings']['auto_recording'], 'Con enlace automático, Zoom graba en la nube.' );
		$this->assertSame( '98765', (string) get_post_meta( $lesson, '_atora_live_session', true )['meeting_id'] );
		$this->assertSame( '2026-11-04 23:30:00', $this->session_row( $lesson )['start_datetime'], 'La tabla guarda UTC de verdad.' );
	}

	public function test_window_opens_ten_minutes_before_and_closes_after_end(): void {
		$lesson = $this->lesson();
		$this->save( $lesson, 'zoom', $this->local_in( 3600 ) );
		$student = self::factory()->user->create();
		$s       = ATORA_Live_Session_Service::get( $lesson );

		$this->assertSame( 'scheduled', ATORA_Live_Session_Service::state( $s, $s['start_utc'] - 601 ) );
		$this->assertSame( 'open', ATORA_Live_Session_Service::state( $s, $s['start_utc'] - 600 ) );
		$this->assertSame( 'open', ATORA_Live_Session_Service::state( $s, $s['end_utc'] + 900 ) );
		$this->assertSame( 'ended', ATORA_Live_Session_Service::state( $s, $s['end_utc'] + 901 ) );

		$this->assertSame( '', ATORA_Live_Session_Service::join_info( $lesson, $student, $s['start_utc'] - 3600 )['join_url'], 'Antes de abrir, sin enlace.' );
		$this->assertSame( 'https://zoom.test/j/98765', ATORA_Live_Session_Service::join_info( $lesson, $student, $s['start_utc'] )['join_url'] );
		$this->assertSame( 'https://zoom.test/s/98765', ATORA_Live_Session_Service::join_info( $lesson, $this->teacher, $s['start_utc'] )['join_url'], 'El docente recibe el enlace de anfitrión.' );
		$this->assertSame( '', ATORA_Live_Session_Service::join_info( $lesson, $student, $s['end_utc'] + 3600 )['join_url'], 'Terminada, sin enlace.' );
	}

	public function test_manual_live_class_is_read_too(): void {
		$lesson = $this->lesson();
		update_post_meta( $lesson, '_clms_live_class_provider', 'google_meet' );
		update_post_meta( $lesson, '_clms_live_class_url', 'https://meet.google.com/abc-defg-hij' );
		update_post_meta( $lesson, '_clms_live_class_starts_at', '2026-11-03T18:00' );
		update_post_meta( $lesson, '_clms_live_class_ends_at', '2026-11-03T19:30' );
		update_post_meta( $lesson, '_clms_live_class_timezone', 'America/Caracas' );
		$s = ATORA_Live_Session_Service::get( $lesson );
		$this->assertSame( array( 'meet', 'manual', 90 ), array( $s['provider'], $s['source'], $s['duration_minutes'] ) );
		$this->assertSame( gmmktime( 22, 0, 0, 11, 3, 2026 ), $s['start_utc'], 'La hora manual también se convierte con su zona.' );
	}

	// ── Webhook de Zoom ─────────────────────────────────────────────────────

	private function zoom_request( array $payload, ?int $ts = null, ?string $secret = 'whsecret', bool $body_only = false ): WP_REST_Request {
		$ts   = $ts ?? time();
		$body = wp_json_encode( $payload );
		$sig  = 'v0=' . hash_hmac( 'sha256', $body_only ? $body : 'v0:' . $ts . ':' . $body, (string) $secret );
		$r    = new WP_REST_Request( 'POST', '/atora/v1/webhooks/zoom' );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_header( 'x-zm-request-timestamp', (string) $ts );
		$r->set_header( 'x-zm-signature', $sig );
		$r->set_body( $body );
		return $r;
	}

	public function test_zoom_webhook_signature_freshness_and_url_validation(): void {
		$event = array( 'event' => 'meeting.started', 'event_ts' => 1, 'payload' => array( 'object' => array( 'id' => 1, 'uuid' => 'u1' ) ) );
		$this->assertSame( 200, Provider_Zoom::handle_webhook( $this->zoom_request( $event ) )->get_status() );
		$this->assertSame( 403, Provider_Zoom::handle_webhook( $this->zoom_request( $event, null, 'whsecret', true ) )->get_status(), 'La firma solo del cuerpo (la de antes) se rechaza.' );
		$this->assertSame( 403, Provider_Zoom::handle_webhook( $this->zoom_request( $event, time() - 600 ) )->get_status(), 'Una firma vieja se rechaza.' );
		$this->assertSame( 403, Provider_Zoom::handle_webhook( $this->zoom_request( $event, null, 'otro' ) )->get_status() );
		$this->assertSame( 'duplicate', Provider_Zoom::handle_webhook( $this->zoom_request( $event ) )->get_data()['status'], 'El mismo evento, una vez.' );

		$check = Provider_Zoom::handle_webhook( $this->zoom_request( array( 'event' => 'endpoint.url_validation', 'payload' => array( 'plainToken' => 'abc123' ) ) ) );
		$this->assertSame( array( 'plainToken' => 'abc123', 'encryptedToken' => hash_hmac( 'sha256', 'abc123', 'whsecret' ) ), $check->get_data() );
	}

	public function test_zoom_meeting_ended_is_found_among_many_lessons_and_recording_follows_teacher_choice(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$other = $this->lesson();
			update_post_meta( $other, '_atora_live_session', array( 'provider' => 'custom', 'start_datetime' => '2026-01-01 10:00', 'join_url' => 'https://x.test' ) );
		}
		$lesson  = $this->lesson();
		$student = self::factory()->user->create( array( 'user_email' => 'alumna@example.test' ) );
		$this->save( $lesson, 'zoom', '2026-11-03 18:00', 'provider' );
		$this->routes['api.zoom.us/v2/past_meetings'] = array( 200, array( 'participants' => array(
			array( 'user_email' => 'alumna@example.test', 'name' => 'Alumna', 'duration' => 55, 'join_time' => '2026-11-03T22:00:00Z', 'leave_time' => '2026-11-03T22:55:00Z' ),
		) ) );

		Provider_Zoom::handle_webhook( $this->zoom_request( array( 'event' => 'meeting.ended', 'event_ts' => 2, 'payload' => array( 'object' => array( 'id' => 98765, 'uuid' => 'm1', 'duration' => 60 ) ) ) ) );
		global $wpdb;
		$att = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_attendance WHERE user_id = %d", $student ) );
		$this->assertSame( 'presente', $att->status ?? null, 'La lección se encontró aunque haya más de 5 en vivo.' );
		$this->assertSame( 'ended', $this->session_row( $lesson )['status'] );

		Provider_Zoom::handle_webhook( $this->zoom_request( array( 'event' => 'recording.completed', 'event_ts' => 3, 'payload' => array( 'object' => array( 'id' => 98765, 'uuid' => 'm1', 'share_url' => 'https://zoom.test/rec/share/xyz', 'recording_play_passcode' => 'k3y' ) ) ) ) );
		$rec = ATORA_Live_Session_Service::recording( $lesson );
		$this->assertSame( 'https://zoom.test/rec/share/xyz', $rec['url'] );
		$this->assertStringContainsString( 'k3y', $rec['note'] );

		// Sin grabación: el proveedor no publica nada.
		$quiet = $this->lesson();
		$this->routes['api.zoom.us/v2/users/me/meetings'] = array( 201, array( 'id' => 11111, 'join_url' => 'https://zoom.test/j/11111', 'start_url' => '' ) );
		$this->save( $quiet, 'zoom', '2026-11-05 18:00', 'none' );
		Provider_Zoom::handle_webhook( $this->zoom_request( array( 'event' => 'recording.completed', 'event_ts' => 4, 'payload' => array( 'object' => array( 'id' => 11111, 'uuid' => 'm2', 'share_url' => 'https://zoom.test/rec/share/no' ) ) ) ) );
		$this->assertNull( ATORA_Live_Session_Service::recording( $quiet ), 'Si el docente eligió sin grabación, no se publica.' );
	}

	// ── Grabación a decisión del docente ────────────────────────────────────

	public function test_teacher_controls_recording(): void {
		$lesson = $this->lesson();
		$this->save( $lesson, 'zoom', '2026-11-03 18:00', 'provider' );

		$this->assertWPError( ATORA_Live_Session_Service::set_recording( $lesson, array( 'url' => 'javascript:alert(1)' ) ) );
		$this->assertTrue( ATORA_Live_Session_Service::set_recording( $lesson, array( 'url' => 'https://drive.example.test/clase.mp4' ), $this->teacher ) );
		$this->assertFalse( Live_Streaming::store_provider_recording( $lesson, 'zoom', 'https://zoom.test/rec/otra' ), 'El proveedor no pisa lo que puso el docente.' );
		$this->assertSame( 'https://drive.example.test/clase.mp4', ATORA_Live_Session_Service::recording( $lesson )['url'] );

		ATORA_Live_Session_Service::clear_recording( $lesson );
		$this->assertNull( ATORA_Live_Session_Service::recording( $lesson ) );
		$this->assertFalse( Live_Streaming::store_provider_recording( $lesson, 'zoom', 'https://zoom.test/rec/otra' ), 'Si el docente la quitó, no vuelve sola.' );

		$file = get_temp_dir() . 'clase-' . wp_generate_password( 6, false ) . '.mp4';
		copy( dirname( __DIR__ ) . '/fixtures/video/clase.mp4', $file ); // MP4 real de 1 s (ffmpeg).
		$video = self::factory()->attachment->create_object( $file, $lesson, array( 'post_mime_type' => 'video/mp4' ) );
		update_attached_file( $video, $file );
		$this->assertTrue( ATORA_Live_Session_Service::set_recording( $lesson, array( 'attachment_id' => $video ), $this->teacher ) );
		$this->assertSame( 'video', ATORA_Live_Session_Service::recording( $lesson )['type'] );

		$fake = get_temp_dir() . 'falso-' . wp_generate_password( 6, false ) . '.mp4';
		file_put_contents( $fake, '<?php echo 1;' );
		$bad = self::factory()->attachment->create_object( $fake, $lesson, array( 'post_mime_type' => 'video/mp4' ) );
		update_attached_file( $bad, $fake );
		$this->assertWPError( ATORA_Live_Session_Service::set_recording( $lesson, array( 'attachment_id' => $bad ), $this->teacher ), 'Un archivo que no es video se rechaza aunque se llame .mp4.' );

		$student = self::factory()->user->create();
		wp_set_current_user( $student );
		$r = new WP_REST_Request( 'POST', '/atora/v1/live/' . $lesson . '/recording' );
		$r->set_param( 'lesson_id', $lesson );
		$r->set_param( 'url', 'https://evil.test/x' );
		$this->assertSame( 403, Live_Streaming::rest_set_recording( $r )->get_error_data()['status'], 'Un estudiante no cambia la grabación.' );
	}

	// ── Teams ───────────────────────────────────────────────────────────────

	public function test_teams_create_reschedule_attendance_and_recording(): void {
		$base = 'graph.microsoft.com/v1.0/users/clases%40academia.test/onlineMeetings';
		$this->routes[ $base ]                                   = array( 201, array( 'id' => 'MSO_1', 'joinWebUrl' => 'https://teams.test/l/meetup-join/1' ) );
		$this->routes[ $base . '/MSO_1' ]                        = array( 200, array() );
		$this->routes[ $base . '/MSO_1/attendanceReports' ]      = array( 200, array( 'value' => array( array( 'id' => 'R1', 'meetingEndDateTime' => '2026-11-03T23:00:00Z' ) ) ) );
		$this->routes[ $base . '/MSO_1/attendanceReports/R1/attendanceRecords' ] = array( 200, array( 'value' => array(
			array( 'emailAddress' => 'teams@example.test', 'totalAttendanceInSeconds' => 3300, 'identity' => array( 'id' => 'aad-1', 'displayName' => 'Ana' ), 'attendanceIntervals' => array( array( 'joinDateTime' => '2026-11-03T22:00:00Z', 'leaveDateTime' => '2026-11-03T22:55:00Z' ) ) ),
			array( 'emailAddress' => '', 'totalAttendanceInSeconds' => 600, 'identity' => array( 'id' => 'guest-1', 'displayName' => 'Invitado' ), 'attendanceIntervals' => array() ),
		) ) );
		$this->routes[ $base . '/MSO_1/recordings' ] = array( 200, array( 'value' => array( array( 'id' => 'rec1' ) ) ) );

		$lesson  = $this->lesson();
		$student = self::factory()->user->create( array( 'user_email' => 'teams@example.test' ) );
		$this->save( $lesson, 'teams', '2026-11-03 18:00', 'provider' );
		$create = json_decode( (string) array_values( array_filter( $this->calls, static fn( $c ) => 'POST' === $c['method'] && false !== strpos( $c['url'], 'onlineMeetings' ) ) )[0]['body'], true );
		$this->assertSame( '2026-11-03T22:00:00Z', $create['startDateTime'] );
		$this->assertTrue( $create['recordAutomatically'] );
		$this->assertSame( 'https://teams.test/l/meetup-join/1', get_post_meta( $lesson, '_atora_live_session', true )['join_url'] );
		$auth = array_values( array_filter( $this->calls, static fn( $c ) => false !== strpos( $c['url'], 'login.microsoftonline.com/tenant/' ) ) );
		$this->assertNotEmpty( $auth, 'Usa el inquilino de la academia.' );

		$this->save( $lesson, 'teams', '2026-11-03 19:00', 'provider' );
		$this->assertSame( 1, $this->calls_to( 'PATCH', 'onlineMeetings/MSO_1' ), 'Reprogramar actualiza la reunión de Teams.' );

		// Reprogramada a las 19:00 de Caracas (23:00 UTC): termina 00:00 UTC y cierra 15 min después.
		$this->assertSame( 0, Live_Streaming::finalize_sessions( gmmktime( 23, 30, 0, 11, 3, 2026 ) ), 'A la hora vieja de cierre, la clase reprogramada sigue abierta.' );
		$this->assertSame( 1, Live_Streaming::finalize_sessions( gmmktime( 0, 30, 0, 11, 4, 2026 ) ) );
		global $wpdb;
		$this->assertSame( 'presente', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_attendance WHERE user_id = %d", $student ) ) );
		$this->assertSame( 'ausente', (string) $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}atora_attendance WHERE display_name = 'Invitado'" ), 'El invitado sin cuenta queda registrado con su tiempo real.' );
		Live_Streaming::finalize_sessions( gmmktime( 0, 40, 0, 11, 4, 2026 ) );
		$this->assertSame( 'https://teams.test/l/meetup-join/1', ATORA_Live_Session_Service::recording( $lesson )['url'] ?? null );
	}

	public function test_finalize_waits_for_the_end_of_class(): void {
		$lesson = $this->lesson();
		$this->save( $lesson, 'zoom', '2026-11-03 18:00', 'none', 90 );
		$start = gmmktime( 22, 0, 0, 11, 3, 2026 );
		$this->assertSame( 0, Live_Streaming::finalize_sessions( $start + 60 * 60 ), 'Durante la clase no se cierra (Meet la cerraba al empezar).' );
		$this->assertSame( 'scheduled', $this->session_row( $lesson )['status'] );
	}

	// ── Recordatorios, lección, app, calendario ─────────────────────────────

	public function test_reminders_24h_and_1h_and_reset_on_reschedule(): void {
		$lesson  = $this->lesson();
		$student = self::factory()->user->create();
		CLMS_Helper::enroll_user_in_course( $student, $this->course );
		$this->save( $lesson, 'custom', $this->local_in( 23 * HOUR_IN_SECONDS ) );

		global $wpdb;
		$count = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_queue WHERE user_id = %d AND JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.template' ) ) = 'atora_live_reminder'", $student ) );
		Live_Streaming::process_reminders();
		Live_Streaming::process_reminders();
		$this->assertSame( 1, $count(), 'El de 24 h sale una vez.' );

		$s = ATORA_Live_Session_Service::get( $lesson );
		Live_Streaming::process_reminders( $s['start_utc'] - 50 * MINUTE_IN_SECONDS );
		$this->assertSame( 2, $count(), 'El de 1 h también sale (antes nunca, tras el de 24 h).' );

		$this->save( $lesson, 'custom', $this->local_in( 23 * HOUR_IN_SECONDS + 7200 ) );
		Live_Streaming::process_reminders();
		$this->assertSame( 3, $count(), 'Reprogramada: vuelven a salir.' );
		$subject = (string) $wpdb->get_var( $wpdb->prepare( "SELECT subject FROM {$wpdb->prefix}atora_email_queue WHERE user_id = %d ORDER BY id DESC LIMIT 1", $student ) );
		$this->assertStringContainsString( 'Clase de prueba', $subject );
	}

	public function test_lesson_card_app_payload_and_single_calendar_event(): void {
		$lesson  = $this->lesson();
		$student = self::factory()->user->create();
		$this->save( $lesson, 'zoom', $this->local_in( 5 * MINUTE_IN_SECONDS ), 'upload' );
		$this->save( $lesson, 'zoom', $this->local_in( 5 * MINUTE_IN_SECONDS ), 'upload' );

		global $wpdb;
		if ( class_exists( 'ATORA\\Calendar\\Calendar' ) ) {
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_calendar_events WHERE lesson_id = %d", $lesson ) ), 'Un solo evento de calendario por clase.' );
		}

		$info = ATORA_Live_Session_Service::join_info( $lesson, $student );
		$this->assertSame( array( 'open', 'zoom', 'Zoom' ), array( $info['status'], $info['provider'], $info['provider_label'] ) );
		$this->assertSame( 'https://zoom.test/j/98765', $info['join_url'] );
		$this->assertSame( 'upload', $info['recording_mode'] );

		$r = new WP_REST_Request( 'GET', '/atora/v1/live/' . $lesson . '/join-url' );
		$r->set_param( 'lesson_id', $lesson );
		wp_set_current_user( $this->teacher );
		$data = Live_Streaming::rest_get_join_url( $r )->get_data();
		$this->assertSame( array( 'active', 'open' ), array( $data['status'], $data['state'] ) );
	}

	private function render_card( int $lesson ): string {
		$this->assertTrue( class_exists( 'CLMS_UI_Lesson_Sections' ), 'La tarjeta de la lección debe estar cargada.' );
		$ref = new ReflectionProperty( 'CLMS_UI_Lesson_Sections', 'cache' );
		$ref->setValue( null, array() );
		ob_start();
		CLMS_UI_Lesson_Sections::render_live_class( array(), CLMS_UI_Template_Context::make( $lesson, 'lesson' ), array() );
		return (string) ob_get_clean();
	}

	public function test_lesson_card_shows_state_local_time_join_button_and_recording(): void {
		wp_set_current_user( $this->teacher );
		update_user_meta( $this->teacher, 'atora_timezone', 'America/Caracas' );

		$later = $this->lesson();
		$this->save( $later, 'zoom', '2026-12-01 18:00' );
		$html = $this->render_card( $later );
		$this->assertStringContainsString( 'data-live-status="scheduled"', $html );
		$this->assertStringContainsString( '6:00', $html, 'La hora se muestra en la zona de quien mira (18:00 Caracas), no en UTC.' );
		$this->assertStringNotContainsString( 'zoom.test/j/', $html, 'Sin enlace antes de abrir.' );

		$now = $this->lesson();
		$this->save( $now, 'zoom', $this->local_in( 5 * MINUTE_IN_SECONDS ) );
		$html = $this->render_card( $now );
		$this->assertStringContainsString( 'https://zoom.test/s/98765', $html, 'Abierta: el docente ve el enlace de anfitrión.' );

		$past = $this->lesson();
		$this->save( $past, 'zoom', '2026-01-10 18:00', 'provider' );
		Live_Streaming::store_provider_recording( $past, 'zoom', 'https://zoom.test/rec/share/abc', 'Código: p4ss' );
		$html = $this->render_card( $past );
		$this->assertStringContainsString( 'data-live-status="ended"', $html );
		$this->assertStringContainsString( 'https://zoom.test/rec/share/abc', $html );
		$this->assertStringContainsString( 'Código: p4ss', $html );
		$this->assertStringNotContainsString( 'zoom.test/j/', $html, 'Terminada: ya no se ofrece unirse.' );
	}

	public function test_e2e_seed_has_an_open_live_class_with_recording(): void {
		$this->assertTrue( class_exists( 'ATORA_E2E_Seed_CLI' ) );
		$out     = ATORA_E2E_Seed_CLI::seed( '', 'atora-e2e-2026' );
		$lesson  = (int) $out['live_lesson'];
		$student = (int) $out['student']['id'];
		$info    = ATORA_Live_Session_Service::join_info( $lesson, $student, time() + 2 * HOUR_IN_SECONDS );
		$this->assertSame( 'open', $info['status'], 'Sigue abierta durante toda la suite de la app.' );
		$this->assertSame( 'https://example.org/clase-en-vivo-e2e', $info['join_url'] );
		$this->assertSame( 'https://example.org/grabacion-e2e', $info['recording']['url'] );
	}

	public function test_settings_keep_secrets_when_left_blank(): void {
		\ATORA\LiveStreaming\Live_Settings::save( array( 'zoom_account_id' => 'nuevo', 'zoom_client_secret' => '', 'teams_client_secret' => '', 'clear_teams_client_secret' => '1' ) );
		$opts = get_option( 'atora_live_streaming_options' );
		$this->assertSame( array( 'nuevo', 'csecret', '' ), array( $opts['zoom_account_id'], $opts['zoom_client_secret'], $opts['teams_client_secret'] ) );
		$this->assertFalse( Provider_Teams::configured() );
	}
}
