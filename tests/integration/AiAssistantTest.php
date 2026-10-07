<?php
/**
 * Integración 6.32.0: asistente del estudiante (`POST /ai/assistant`) con el
 * proveedor simulado. Nunca se llama a un proveedor real.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class AiAssistantTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $outsider = 0;
	private int $lesson = 0;
	private int $wp_lesson = 0;
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
		delete_option( ATORA_AI_Usage_Service::LIMITS_OPTION );
		ATORA_AI_Usage_Service::set_features( array( 'assistant' => true, 'grading_suggestion' => true ) );
		ATORA_AI_Fake_Provider::enable();
		do_action( 'rest_api_init', rest_get_server() );

		$this->student  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Valentina Pérez', 'user_email' => 'valentina.perez.' . wp_generate_password( 6, false ) . '@escuela.test' ) );
		$this->outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$wp_course      = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso IA' ) );
		$this->wp_lesson = self::factory()->post->create( array(
			'post_type'    => 'lm_lesson',
			'post_status'  => 'publish',
			'post_title'   => 'Fotosíntesis',
			'post_content' => 'La fotosíntesis convierte la luz en energía química.',
			'meta_input'   => array(
				'_clms_course_id'      => $wp_course,
				'_clms_quiz_enabled'   => 'yes',
				'_clms_quiz_questions' => array( array( 'type' => 'single', 'question' => '¿Qué produce la fotosíntesis?', 'options' => array( 'Glucosa', 'Sal' ), 'correct' => 'Glucosa' ) ),
			),
		) );
		$course       = (int) LMS_Course_Service::get_by_wp_post( $wp_course )['id'];
		$this->lesson = (int) LMS_Course_Service::get_lesson_by_wp_post( $this->wp_lesson )['id'];
		LMS_Enrollment_Service::enroll( $this->student, $course );
		CLMS_Helper::enroll_user_in_course( $this->student, $wp_course );
		foreach ( array( $this->student, $this->outsider ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	protected function tearDown(): void {
		ATORA_AI_Fake_Provider::disable();
		parent::tearDown();
	}

	private function ask( int $user, string $message, string $event ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/atora-mobile/v1/ai/assistant' );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'lesson_id' => $this->lesson, 'message' => $message, 'client_event_id' => $event, 'history' => array( array( 'role' => 'user', 'content' => 'Hola' ), array( 'role' => 'assistant', 'content' => 'Hola, ¿en qué te ayudo?' ) ) ) ) );
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	public function test_answers_with_the_lesson_and_records_usage_without_identifying_the_student(): void {
		$response = $this->ask( $this->student, '¿Qué es la fotosíntesis?', 'q-1' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( '', $response->get_data()['reply'] );
		$this->assertStringContainsString( 'IA', $response->get_data()['disclaimer'] );

		$sent  = wp_json_encode( ATORA_AI_Fake_Provider::$last, JSON_UNESCAPED_UNICODE );
		$email = get_userdata( $this->student )->user_email;
		$this->assertStringContainsString( 'La fotosíntesis convierte la luz', $sent, 'Contexto: el contenido de la lección.' );
		$this->assertStringContainsString( '¿Qué produce la fotosíntesis?', $sent, 'El enunciado del quiz va para reconocerlo.' );
		$this->assertStringContainsString( 'no resuelvas evaluaciones', $sent );
		$this->assertStringNotContainsString( 'Valentina', $sent, 'Sin el nombre del estudiante.' );
		$this->assertStringNotContainsString( $email, $sent, 'Sin el correo.' );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_ai_usage WHERE user_id = %d", $this->student ), ARRAY_A );
		$this->assertSame( 'assistant', $row['feature'] );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( '1200', $row['tokens_in'] );
		$this->assertGreaterThan( 0, (float) $row['cost'] );

		$this->assertTrue( $this->ask( $this->student, '¿Qué es la fotosíntesis?', 'q-1' )->get_data()['replayed'], 'El mismo envío no vuelve a cobrar.' );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_ai_usage WHERE user_id = %d", $this->student ) ) );
	}

	public function test_daily_limit_answers_429_with_reset_time(): void {
		ATORA_AI_Usage_Service::set_limits( array( 'student_daily' => 2 ) );
		$this->assertSame( 200, $this->ask( $this->student, 'Uno', 'q-a' )->get_status() );
		$this->assertSame( 200, $this->ask( $this->student, 'Dos', 'q-b' )->get_status() );
		$third = $this->ask( $this->student, 'Tres', 'q-c' );
		$this->assertSame( 429, $third->get_status() );
		$this->assertSame( 'atora_ai_limit', $third->get_data()['code'] );
		$this->assertStringContainsString( 'Se reinicia a las', $third->get_data()['message'] );
		$this->assertArrayHasKey( 'reset_at', $third->get_data()['data'] );
	}

	public function test_not_enrolled_is_404_and_disabled_feature_disappears(): void {
		$this->assertSame( 404, $this->ask( $this->outsider, 'Hola', 'q-x' )->get_status() );

		ATORA_AI_Usage_Service::set_features( array( 'assistant' => false, 'grading_suggestion' => false ) );
		$this->assertSame( 404, $this->ask( $this->student, 'Hola', 'q-y' )->get_status() );
		$caps = ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities'];
		$this->assertFalse( $caps['ai_assistant'] );
		$this->assertFalse( $caps['ai_grading_suggestion'] );

		ATORA_AI_Usage_Service::set_features( array( 'assistant' => true, 'grading_suggestion' => true ) );
		$caps = ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities'];
		$this->assertTrue( $caps['ai_assistant'] );
		$this->assertTrue( $caps['ai_grading_suggestion'] );

		// Módulo de IA apagado: no hay gestor que atienda; nada se declara.
		update_option( CLMS_Module_Registry::OPTION, array_values( array_diff( CLMS_Module_Registry::get_active_slugs(), array( 'ai' ) ) ) );
		CLMS_Module_Registry::flush_cache();
		$this->assertFalse( ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities']['ai_assistant'] );
		$this->assertSame( 404, $this->ask( $this->student, 'Hola', 'q-z' )->get_status() );
		delete_option( CLMS_Module_Registry::OPTION );
		CLMS_Module_Registry::flush_cache();
	}
}
