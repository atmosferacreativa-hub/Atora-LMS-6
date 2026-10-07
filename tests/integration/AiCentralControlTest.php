<?php
/**
 * Integración 6.32.1: control central de la IA.
 *
 * - Una llamada desde un módulo cualquiera queda registrada en `atora_ai_usage`
 *   con su función y cuenta para el tope mensual de la academia.
 * - Al alcanzarse el tope, todos los módulos se detienen con un error claro
 *   (429 `atora_ai_budget`) y no sale nada hacia el proveedor.
 * - Ningún prompt de ningún módulo lleva el nombre ni el correo del estudiante
 *   de prueba; "{{nombre}}" se reemplaza después de recibir la respuesta.
 *
 * Proveedor simulado; cualquier salida HTTP a un dominio de IA se bloquea y se
 * anota (la prueba falla si ocurre).
 */

declare( strict_types = 1 );

final class AiCentralControlTest extends WP_UnitTestCase {

	private const NAME  = 'Valentina';
	private const LAST  = 'Pérez';

	private int $student = 0;
	private int $admin = 0;
	private int $course = 0;
	private int $lesson = 0;
	private int $submission = 0;
	private string $email = '';
	/** @var string[] URLs de IA que habrían salido a la red. */
	private array $leaks = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
		delete_option( ATORA_AI_Usage_Service::LIMITS_OPTION );
		delete_option( ATORA_AI_Usage_Service::ALERT_OPTION );
		// Los módulos solo llaman a la IA si hay una clave; esta nunca sale (proveedor simulado y red bloqueada).
		update_option( 'clms_openai_api_key', 'sk-prueba-no-real' );
		update_option( 'clms_ai_settings', array( 'provider' => 'openai', 'openai_api_key' => 'sk-prueba-no-real' ) );
		ATORA_AI_Fake_Provider::enable();
		add_filter( 'pre_http_request', array( $this, 'block_ai_http' ), 1, 3 );

		$this->email   = 'valentina.perez.' . wp_generate_password( 6, false ) . '@escuela.test';
		$this->student = self::factory()->user->create( array(
			'role'         => 'subscriber',
			'user_login'   => 'vperez' . wp_generate_password( 4, false, false ),
			'display_name' => self::NAME . ' ' . self::LAST,
			'first_name'   => self::NAME,
			'last_name'    => self::LAST,
			'user_email'   => $this->email,
		) );
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Admin Prueba' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Biología' ) );
		$this->lesson  = self::factory()->post->create( array(
			'post_type'    => 'lm_lesson',
			'post_status'  => 'publish',
			'post_title'   => 'Fotosíntesis',
			'post_content' => 'La fotosíntesis convierte la luz en energía química.',
			'meta_input'   => array( '_clms_course_id' => $this->course, 'lm_activity_type' => 'tarea' ),
		) );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->course );
		// El estudiante firma su entrega con nombre y correo: tampoco deben salir.
		$text = str_repeat( 'Estoy frustrado y confundido con este tema, no entiendo nada y siento que no avanzo. ', 8 )
			. 'Atentamente, ' . self::NAME . ' ' . self::LAST . ' (' . $this->email . ').';
		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student, 'post_content' => $text ) );
		update_post_meta( $this->submission, '_clms_submission_user_id', $this->student );
		update_post_meta( $this->submission, '_clms_submission_lesson_id', $this->lesson );
		update_post_meta( $this->submission, '_clms_submission_course_id', $this->course );
		update_post_meta( $this->submission, '_clms_submission_comment', $text );
		update_post_meta( $this->submission, '_clms_submission_status', 'submitted' );
		wp_set_current_user( $this->admin );
		// Crear la entrega ya dispara el análisis de sentimiento (hook): cada prueba empieza en limpio.
		ATORA_AI_Fake_Provider::$log  = array();
		ATORA_AI_Fake_Provider::$last = null;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'block_ai_http' ), 1 );
		ATORA_AI_Fake_Provider::disable();
		delete_option( 'clms_openai_api_key' );
		delete_option( 'clms_ai_settings' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
		parent::tearDown();
	}

	/** @return false|array|WP_Error */
	public function block_ai_http( $pre, $args, $url ) {
		if ( preg_match( '#api\.openai\.com|api\.anthropic\.com|generativelanguage\.googleapis\.com|api\.deepseek\.com#', (string) $url ) ) {
			$this->leaks[] = (string) $url;
			return new WP_Error( 'blocked_in_tests', 'Salida a un proveedor real bloqueada en pruebas.' );
		}
		return $pre;
	}

	private function invoke( object $object, string $method, array $args ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $object, $args );
	}

	private function module( string $class ): object {
		$instance = function_exists( 'clms_core' ) ? clms_core( $class ) : null;
		return $instance ?: new $class();
	}

	private function rows( string $feature = '' ): array {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}atora_ai_usage" . ( $feature ? $wpdb->prepare( ' WHERE feature = %s', $feature ) : '' ) . ' ORDER BY id';
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private function messaging_copy(): string {
		return (string) $this->invoke( $this->module( 'CLMS_Messaging' ), 'generate_ai_followup_copy', array( array(
			'rule'          => 'reminder',
			'student_id'    => $this->student,
			'course_title'  => 'Biología',
			'lesson_title'  => 'Fotosíntesis',
			'days_inactive' => 9,
		) ) );
	}

	public function test_a_module_call_is_recorded_with_its_feature_and_counts_for_the_cap(): void {
		ATORA_AI_Fake_Provider::$override = array(
			'messaging' => array( 'text' => 'Hola {{nombre}}, retoma Fotosíntesis hoy con un bloque corto.', 'usage' => array( 'prompt_tokens' => 300, 'completion_tokens' => 40 ) ),
		);
		$copy = $this->messaging_copy();

		$this->assertSame( 'Hola Valentina, retoma Fotosíntesis hoy con un bloque corto.', $copy, 'El marcador se reemplaza después de recibir la respuesta.' );
		$sent = wp_json_encode( ATORA_AI_Fake_Provider::$last, JSON_UNESCAPED_UNICODE );
		$this->assertStringContainsString( '{{nombre}}', $sent );
		$this->assertStringNotContainsString( self::NAME, $sent );

		$rows = $this->rows( 'messaging' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['result'] );
		$this->assertSame( '300', $rows[0]['tokens_in'] );
		$this->assertGreaterThan( 0, (float) $rows[0]['cost'] );
		$this->assertEqualsWithDelta( (float) $rows[0]['cost'], ATORA_AI_Usage_Service::month_cost(), 0.000001, 'Cuenta para el tope mensual.' );
		$this->assertSame( array(), $this->leaks );
	}

	public function test_when_the_cap_is_reached_every_module_stops_with_a_clear_error(): void {
		ATORA_AI_Usage_Service::record( 0, 'other', 'openai', 'gpt-4o-mini', array( 'prompt_tokens' => 10000, 'completion_tokens' => 1000 ), 'ok' );
		ATORA_AI_Usage_Service::set_limits( array( 'monthly_cost_cap' => 0.001 ) );
		$manager = clms_core( 'CLMS_AI_Manager' );
		$this->assertNotNull( $manager );

		$calls = array(
			'chat (exámenes)'       => static fn() => $manager->chat( array( array( 'role' => 'user', 'content' => 'Genera 3 preguntas.' ) ), array( 'feature' => 'exams' ) ),
			'chat_with_meta'        => static fn() => $manager->chat_with_meta( array( array( 'role' => 'user', 'content' => 'Hola' ) ), array( 'feature' => 'quick_wins' ) ),
			'embeddings'            => static fn() => $manager->create_embeddings( array( 'texto' ) ),
			'búsqueda'              => static fn() => $manager->create_query_embedding( 'texto' ),
			'transcripción'         => static fn() => $manager->transcribe_audio( __FILE__ ),
			'post_json (revisión)'  => static fn() => $manager->post_json( 'https://api.openai.com/v1/responses', array(), array( 'input' => 'x' ), 10, array( 'feature' => 'ai_review' ) ),
			'copiloto docente'      => fn() => $this->module( 'CLMS_AI_Copilots' )->run_text( 'teacher', 'teacher_assistant', array( array( 'role' => 'user', 'content' => 'Hola' ) ), array( 'feature' => 'teacher_assistant' ) ),
		);
		foreach ( $calls as $label => $call ) {
			$result = $call();
			$this->assertWPError( $result, $label );
			$this->assertSame( 'atora_ai_budget', $result->get_error_code(), $label );
			$this->assertStringContainsString( 'tope mensual de uso de IA', $result->get_error_message(), $label );
			$this->assertSame( 429, $result->get_error_data()['status'], $label );
		}

		// Los módulos que degradan sin IA (texto de respaldo) tampoco envían nada.
		$this->assertSame( '', $this->messaging_copy() );
		$this->module( 'CLMS_Sentiment' )->analyze_submission( $this->submission );
		$this->invoke( $this->module( 'CLMS_AI_Alerts' ), 'send_inactivity_alert', array( array( 'user_id' => $this->student, 'user_name' => self::NAME, 'user_email' => $this->email, 'days_inactive' => 9 ), $this->course, true ) );

		$this->assertSame( array(), ATORA_AI_Fake_Provider::$log, 'Con el tope alcanzado no sale ninguna petición.' );
		$limited = array_unique( array_column( array_filter( $this->rows(), static fn( $r ) => 'limit' === $r['result'] ), 'feature' ) );
		foreach ( array( 'exams', 'quick_wins', 'embeddings', 'knowledge_base', 'transcription', 'ai_review', 'teacher_assistant', 'messaging', 'sentiment', 'alerts' ) as $feature ) {
			$this->assertContains( $feature, $limited, "Frenada y registrada: {$feature}" );
		}
		$this->assertSame( array(), $this->leaks );
	}

	public function test_no_module_prompt_contains_the_student_name_or_email(): void {
		// Mensajería: recordatorio, refuerzo y recomendación.
		foreach ( array( 'reminder', 'reinforcement', 'upsell' ) as $rule ) {
			$this->invoke( $this->module( 'CLMS_Messaging' ), 'generate_ai_followup_copy', array( array( 'rule' => $rule, 'student_id' => $this->student, 'course_title' => 'Biología', 'lesson_title' => 'Fotosíntesis', 'days_inactive' => 9, 'average' => 41.5, 'related_title' => 'Química' ) ) );
		}
		// Alertas: inactividad y promedio bajo (sin enviar el correo).
		$alerts = $this->module( 'CLMS_AI_Alerts' );
		$this->invoke( $alerts, 'send_inactivity_alert', array( array( 'user_id' => $this->student, 'user_name' => self::NAME . ' ' . self::LAST, 'user_email' => $this->email, 'days_inactive' => 9 ), $this->course, true ) );
		$this->invoke( $alerts, 'send_low_grade_alert', array( array( 'user_id' => $this->student, 'user_name' => self::NAME . ' ' . self::LAST, 'user_email' => $this->email, 'average' => 41.5 ), $this->course, true ) );
		// Sentimiento de una entrega firmada por el estudiante.
		$this->module( 'CLMS_Sentiment' )->analyze_submission( $this->submission );
		// Corrección con IA y revisión de SpeedGrader de la misma entrega.
		$this->module( 'CLMS_AI_Grading' )->evaluate( (string) get_post_field( 'post_content', $this->submission ), 'Claridad y argumentos.', $this->lesson, $this->submission );
		$this->module( 'CLMS_AI' )->generate_submission_review( $this->submission );
		// Sugerencia de calificación.
		ATORA_AI_Usage_Service::set_features( array( 'assistant' => true, 'grading_suggestion' => true ) );
		$job = ATORA_AI_Grading_Suggestion_Service::request( $this->submission, $this->admin );
		if ( is_array( $job ) ) {
			ATORA_AI_Grading_Suggestion_Service::run( $job['job_id'] );
		}
		// Asistente: el estudiante escribe su propio nombre y correo.
		wp_set_current_user( $this->student );
		( new ATORA_AI_Assistant_Service() )->ask_lesson( $this->student, $this->course, $this->lesson, 'Hola, soy ' . self::NAME . ' ' . self::LAST . ', mi correo es ' . $this->email . '. ¿Qué es la fotosíntesis?', array() );
		wp_set_current_user( $this->admin );

		$features = array_unique( array_map( static fn( $entry ) => (string) ( $entry['options']['feature'] ?? $entry['options']['source'] ?? $entry['kind'] ), ATORA_AI_Fake_Provider::$log ) );
		foreach ( array( 'messaging', 'alerts', 'sentiment', 'ai_grading', 'ai_review', 'grading_suggestion', 'assistant' ) as $feature ) {
			$this->assertContains( $feature, $features, "El módulo envió su prompt: {$feature}" );
		}
		foreach ( ATORA_AI_Fake_Provider::$log as $entry ) {
			$sent = wp_json_encode( array( $entry['payload'], $entry['options'] ), JSON_UNESCAPED_UNICODE );
			$what = (string) ( $entry['options']['feature'] ?? $entry['kind'] );
			$this->assertStringNotContainsString( self::NAME, $sent, "Nombre en el prompt de {$what}" );
			$this->assertStringNotContainsString( self::LAST, $sent, "Apellido en el prompt de {$what}" );
			$this->assertStringNotContainsString( $this->email, $sent, "Correo en el prompt de {$what}" );
			$this->assertStringNotContainsString( 'escuela.test', $sent, "Correo en el prompt de {$what}" );
		}
		$this->assertSame( array(), $this->leaks, 'Nada salió hacia un proveedor real.' );
	}

	public function test_fake_provider_never_runs_in_production(): void {
		$this->assertSame( 'development', wp_get_environment_type() );
		$this->assertTrue( ATORA_AI_Fake_Provider::enabled() );
		$this->assertFalse( ATORA_AI_Fake_Provider::allowed_in( 'production' ), 'Ni la constante ni enable() lo activan en producción.' );
		foreach ( array( 'local', 'development', 'staging' ) as $environment ) {
			$this->assertTrue( ATORA_AI_Fake_Provider::allowed_in( $environment ), $environment );
		}
	}
}
