<?php
/**
 * Integración 6.32.0: sugerencia de calificación con IA (proveedor simulado).
 *
 * - Solo docentes con la entrega en su alcance; el estudiante recibe 403.
 * - La sugerencia nunca cambia la nota ni avisa al estudiante; se guarda aparte.
 * - Puntajes fuera de rango se recortan; el indicio nunca aparece en rutas del estudiante.
 * - El prompt no lleva nombre ni correo del estudiante.
 * - Función desactivada: rutas 404 y /discovery no la declara.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class AiGradingSuggestionTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $stranger = 0;
	private int $student = 0;
	private int $student2 = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private int $wp_lesson = 0;
	private int $lesson = 0;
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		update_option( 'atora_rubric_source', 'tables', false );
		global $wpdb;
		foreach ( array( 'atora_messages', 'atora_message_participants', 'atora_message_threads', 'atora_assignment_submissions', 'atora_sections', 'atora_section_teachers', 'atora_section_students', 'atora_early_warning' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_jobs" );
		delete_option( ATORA_AI_Usage_Service::LIMITS_OPTION );
		ATORA_AI_Usage_Service::set_features( array( 'assistant' => true, 'grading_suggestion' => true ) );
		ATORA_AI_Fake_Provider::enable();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher  = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Docente API' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Otro docente' ) );
		$this->student  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana Ramírez', 'user_email' => 'ana.ramirez.' . wp_generate_password( 6, false ) . '@escuela.test' ) );
		$this->student2 = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Beto API' ) );
		$author         = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$rubric = self::factory()->post->create( array( 'post_type' => 'clms_rubric', 'post_status' => 'publish', 'post_title' => 'Rúbrica API' ) );
		update_post_meta( $rubric, '_clms_rubric_scale_type', '0_100' );
		update_post_meta( $rubric, '_clms_rubric_criteria', array(
			array( 'name' => 'Claridad', 'max_points' => 10, 'weight' => 50, 'levels' => array( array( 'label' => 'Inicial', 'points' => 4 ), array( 'label' => 'Logrado', 'points' => 8 ), array( 'label' => 'Excelente', 'points' => 10 ) ) ),
			array( 'name' => 'Estructura', 'max_points' => 10, 'weight' => 50, 'levels' => array( array( 'label' => 'Inicial', 'points' => 4 ), array( 'label' => 'Logrado', 'points' => 8 ), array( 'label' => 'Excelente', 'points' => 10 ) ) ),
		) );
		\ATORA\LMS\Rubrics_CLI::migrate( array(), array( 'yes' => true, 'batch' => 50 ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Curso API' ) );
		$this->wp_lesson = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_author' => $author,
			'post_title'  => 'Ensayo',
			'meta_input'  => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea', '_clms_rubric_id' => $rubric ),
		) );
		$this->course = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		$this->lesson = (int) LMS_Course_Service::get_lesson_by_wp_post( $this->wp_lesson )['id'];
		foreach ( array( $this->student, $this->student2 ) as $student ) {
			LMS_Enrollment_Service::enroll( $student, $this->course );
			CLMS_Helper::enroll_user_in_course( $student, $this->wp_course );
		}
		$section = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->wp_course, 'title' => 'Sección 1' ) );
		\ATORA\LMS\Section_Service::add_teacher( $section, $this->teacher );
		foreach ( array( $this->teacher, $this->stranger, $this->student, $this->student2 ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	private function submission( int $student ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $student ) );
		update_post_meta( $id, '_clms_submission_user_id', $student );
		update_post_meta( $id, '_clms_submission_lesson_id', $this->wp_lesson );
		update_post_meta( $id, '_clms_submission_course_id', $this->wp_course );
		update_post_meta( $id, '_clms_submission_comment', 'Mi ensayo' );
		update_post_meta( $id, '_clms_submission_status', 'submitted' );
		return $id;
	}

	private function call( int $user, string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/atora-mobile/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	protected function tearDown(): void {
		ATORA_AI_Fake_Provider::disable();
		// force_install() hace DDL (commit implícito): lo migrado sobrevive a la reversión y
		// RubricsCliMigrationTest, que corre después, espera las tablas de rúbricas vacías.
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'atora_rubric' ) . '%' ) ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_jobs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
		parent::tearDown();
	}

	/** Pide la sugerencia y ejecuta el trabajo como lo haría la cola. */
	private function suggest( int $sub ): array {
		$queued = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/ai-suggestion" );
		$this->assertSame( 202, $queued->get_status() );
		$job = $queued->get_data()['job_id'];
		$this->assertSame( 'pending', $this->call( $this->teacher, 'GET', "/teacher/ai-suggestions/{$job}" )->get_data()['status'] );
		ATORA_AI_Grading_Suggestion_Service::run( $job );
		$done = $this->call( $this->teacher, 'GET', "/teacher/ai-suggestions/{$job}" );
		$this->assertSame( 200, $done->get_status() );
		$this->assertSame( 'done', $done->get_data()['status'] );
		return $done->get_data();
	}

	public function test_suggestion_is_clamped_private_and_never_grades(): void {
		$sub   = $this->submission( $this->student );
		$inbox = count( ATORA_Inbox_Store::received( $this->student ) );
		reset_phpmailer_instance();

		$this->assertTrue( $this->call( $this->teacher, 'GET', "/teacher/submissions/{$sub}" )->get_data()['submission']['ai_suggestion_available'] );
		$data = $this->suggest( $sub );
		$s    = $data['suggestion'];
		$this->assertEquals( 8.5, $s['criteria'][0]['score'], 'Decimales conservados.' );
		$this->assertEquals( 10, $s['criteria'][1]['score'], '11 sobre 10 se recorta al máximo.' );
		$this->assertNotSame( '', $s['criteria'][0]['justification'] );
		$this->assertNotEmpty( $s['criteria'][0]['level'] );
		$this->assertNotSame( '', $s['feedback'] );
		$this->assertSame( 'medio', $s['ai_likelihood'] );
		$this->assertSame( 'Indicio no concluyente. Verifica con el estudiante antes de decidir.', $s['disclaimer'] );
		$this->assertSame( 'simulado', $s['model'] );
		$this->assertSame( $this->teacher, (int) $s['requested_by'] );

		// Nada cambia para el estudiante.
		$this->assertSame( 'submitted', get_post_meta( $sub, '_clms_submission_status', true ) );
		$this->assertSame( '', (string) get_post_meta( $sub, '_clms_submission_grade', true ) );
		$this->assertCount( $inbox, ATORA_Inbox_Store::received( $this->student ), 'Sin aviso al estudiante.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent, 'Sin correo al estudiante.' );

		// El prompt no identifica al estudiante.
		$sent  = wp_json_encode( ATORA_AI_Fake_Provider::$last, JSON_UNESCAPED_UNICODE );
		$email = get_userdata( $this->student )->user_email;
		$this->assertStringContainsString( 'Mi ensayo', $sent );
		$this->assertStringNotContainsString( 'Ana', $sent );
		$this->assertStringNotContainsString( 'Ramírez', $sent );
		$this->assertStringNotContainsString( $email, $sent );

		// Uso registrado al docente.
		global $wpdb;
		$this->assertSame( 'grading_suggestion', $wpdb->get_var( $wpdb->prepare( "SELECT feature FROM {$wpdb->prefix}atora_ai_usage WHERE user_id = %d", $this->teacher ) ) );

		// El indicio no aparece en ninguna ruta del estudiante.
		foreach ( array( "/assignments/{$this->lesson}", "/courses/{$this->course}/grades", '/grades', "/lessons/{$this->lesson}" ) as $route ) {
			$body = wp_json_encode( $this->call( $this->student, 'GET', $route )->get_data(), JSON_UNESCAPED_UNICODE );
			$this->assertStringNotContainsString( 'ai_likelihood', $body, $route );
			$this->assertStringNotContainsString( 'Indicio', $body, $route );
			$this->assertStringNotContainsString( 'simulado', $body, $route );
		}
	}

	public function test_saving_after_suggestion_goes_through_the_save_service_and_is_audited(): void {
		$sub  = $this->submission( $this->student );
		$this->suggest( $sub );
		$body = array(
			'client_event_id'   => 'evt-ia-1',
			'publish'           => true,
			'expected_revision' => 0,
			'feedback'          => 'Revisado',
			'scores'            => array( array( 'index' => 0, 'score' => 9, 'feedback' => '' ), array( 'index' => 1, 'score' => 10, 'feedback' => '' ) ),
		);
		$this->assertSame( 200, $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/grade", $body )->get_status() );
		$snap  = json_decode( (string) \ATORA\LMS\Rubric_Service::get_evaluation( $sub )['snapshot_json'], true );
		$audit = $snap['ai_suggestion'];
		$this->assertTrue( $audit['present'] );
		$this->assertEquals( 0.5, $audit['criteria'][0]['diff'], 'Guardado 9 vs sugerido 8.5.' );
		$this->assertEquals( 0, $audit['criteria'][1]['diff'] );

		$plain = $this->submission( $this->student2 );
		$body['client_event_id'] = 'evt-ia-2';
		$this->assertSame( 200, $this->call( $this->teacher, 'POST', "/teacher/submissions/{$plain}/grade", $body )->get_status() );
		$snap = json_decode( (string) \ATORA\LMS\Rubric_Service::get_evaluation( $plain )['snapshot_json'], true );
		$this->assertFalse( $snap['ai_suggestion']['present'], 'Guardado sin sugerencia.' );
	}

	public function test_student_403_stranger_404_and_limit_429(): void {
		$sub = $this->submission( $this->student );
		$this->assertSame( 403, $this->call( $this->student, 'POST', "/teacher/submissions/{$sub}/ai-suggestion" )->get_status() );
		$this->assertSame( 403, $this->call( $this->student, 'GET', '/teacher/ai-suggestions/' . wp_generate_uuid4() )->get_status() );
		$this->assertSame( 404, $this->call( $this->stranger, 'POST', "/teacher/submissions/{$sub}/ai-suggestion" )->get_status() );

		$job = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/ai-suggestion" )->get_data()['job_id'];
		$this->assertSame( 404, $this->call( $this->stranger, 'GET', "/teacher/ai-suggestions/{$job}" )->get_status() );
		ATORA_AI_Grading_Suggestion_Service::run( $job );

		ATORA_AI_Usage_Service::set_limits( array( 'teacher_daily' => 1 ) );
		$limited = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/ai-suggestion" );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertArrayHasKey( 'reset_at', $limited->get_data()['data'] );
	}

	public function test_disabled_feature_hides_routes(): void {
		$sub = $this->submission( $this->student );
		ATORA_AI_Usage_Service::set_features( array( 'assistant' => true, 'grading_suggestion' => false ) );
		$this->assertSame( 404, $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/ai-suggestion" )->get_status() );
		$this->assertFalse( $this->call( $this->teacher, 'GET', "/teacher/submissions/{$sub}" )->get_data()['submission']['ai_suggestion_available'] );
		$caps = ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities'];
		$this->assertFalse( $caps['ai_grading_suggestion'] );
		$this->assertTrue( $caps['ai_assistant'] );

		ATORA_AI_Fake_Provider::disable();
		if ( ! ATORA_AI_Usage_Service::provider_configured() ) {
			$this->assertFalse( ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities']['ai_assistant'], 'Sin proveedor, nada se declara.' );
		}
	}
}
