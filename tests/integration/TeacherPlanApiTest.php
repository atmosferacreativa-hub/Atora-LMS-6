<?php
/**
 * Integración 6.40.0: planificación híbrida desde la app del docente.
 *
 * - El docente crea (reintento con el mismo client_event_id no duplica), guarda con revisión (409 si cambió),
 *   retoma, ve la vista previa y publica; volver a publicar no duplica lecciones.
 * - El asistente con delegación prepara pero no publica; otro docente no ve el plan.
 * - Subida de audio desde el teléfono: se valida por contenido, un reintento devuelve el mismo adjunto,
 *   un archivo disfrazado se rechaza y no queda en la biblioteca.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;

final class TeacherPlanApiTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $assistant = 0;
	private int $stranger = 0;
	private int $student = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		global $wpdb;
		foreach ( array( 'atora_sections', 'atora_section_teachers', 'atora_section_students', 'atora_hybrid_plans' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher   = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->assistant = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant' ) );
		$this->stranger  = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->student   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher ) );
		$this->course    = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];

		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug = 'plan-inst'" );
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'plan-inst', 'name' => 'Plan', 'status' => 'active' ) );
		$inst = (int) $wpdb->insert_id;
		foreach ( array( $this->teacher => 'teacher', $this->assistant => 'assistant' ) as $user => $role ) {
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst, 'user_id' => $user, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $user, '_atora_active_institution', $inst );
		}
		\ATORA\LMS\Tenant_Context::reset_cache();
		wp_set_current_user( $this->teacher );
		$this->assertGreaterThan( 0, ATORA_Delegation_Service::grant( $this->teacher, $this->assistant, array( 'scope' => 'course', 'wp_course_id' => $this->wp_course, 'perms' => array( 'content' => true ) ) ) );
		wp_set_current_user( 0 );

		foreach ( array( $this->teacher, $this->assistant, $this->stranger, $this->student ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	protected function tearDown(): void {
		global $wpdb;
		$inst = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}atora_institutions WHERE slug = 'plan-inst'" );
		if ( $inst ) {
			$wpdb->delete( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst ) );
			$wpdb->delete( $wpdb->prefix . 'atora_institutions', array( 'id' => $inst ) );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_instructor_delegations WHERE assistant_id = " . (int) $this->assistant ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_hybrid_plans" );
		parent::tearDown();
	}

	private function call( int $user, string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/atora-mobile/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	private function upload( int $user, string $client, string $source, string $name ): WP_REST_Response {
		$tmp = get_temp_dir() . wp_unique_filename( get_temp_dir(), 'up-' . $name );
		copy( $source, $tmp );
		$request = new WP_REST_Request( 'POST', '/atora-mobile/v1/teacher/media' );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		$request->set_param( 'client_upload_id', $client );
		$request->set_file_params( array( 'file' => array( 'name' => $name, 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0, 'type' => '' ) ) );
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	private function lesson_count(): int {
		return count( get_posts( array( 'post_type' => 'lm_lesson', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_clms_course_id', 'meta_value' => $this->wp_course ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public function test_teacher_creates_saves_resumes_previews_and_publishes_once(): void {
		$body    = array( 'course_id' => $this->course, 'client_event_id' => 'plan-1', 'data' => array( 'title' => 'Semana 1' ) );
		$created = $this->call( $this->teacher, 'POST', '/teacher/plans', $body );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$plan = $created->get_data();
		$this->assertSame( $this->course, $plan['course_id'] );
		$this->assertTrue( $plan['can_publish'] );

		$again = $this->call( $this->teacher, 'POST', '/teacher/plans', $body );
		$this->assertSame( $plan['id'], $again->get_data()['id'], 'Reintento: el mismo plan.' );
		$this->assertTrue( $again->get_data()['replayed'] );

		$data  = array( 'title' => 'Semana 1', 'objective' => 'Comprender fracciones', 'remote' => array( 'before' => 'Escuchar el audio.' ), 'evidence' => array( 'enabled' => true, 'title' => 'Ejercicios', 'instructions' => 'Resolver 5.' ) );
		$saved = $this->call( $this->teacher, 'POST', '/teacher/plans/' . $plan['id'], array( 'data' => $data, 'revision' => 1 ) );
		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 2, $saved->get_data()['revision'] );
		$this->assertSame( 409, $this->call( $this->teacher, 'POST', '/teacher/plans/' . $plan['id'], array( 'data' => $data, 'revision' => 1 ) )->get_status(), 'Revisión vieja.' );

		$resumed = $this->call( $this->teacher, 'GET', '/teacher/plans/' . $plan['id'] )->get_data();
		$this->assertSame( 'Comprender fracciones', $resumed['data']['objective'] );
		$list = $this->call( $this->teacher, 'GET', '/teacher/plans', array(), array( 'course' => $this->course ) )->get_data();
		$this->assertSame( array( $plan['id'] ), array_column( $list['plans'], 'id' ) );
		$this->assertTrue( $list['can_publish'] );

		$preview = $this->call( $this->teacher, 'GET', '/teacher/plans/' . $plan['id'] . '/preview' );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertNotEmpty( $preview->get_data()['items'] );

		$before    = $this->lesson_count();
		$published = $this->call( $this->teacher, 'POST', '/teacher/plans/' . $plan['id'] . '/publish', array( 'revision' => 2 ) );
		$this->assertSame( 200, $published->get_status(), wp_json_encode( $published->get_data() ) );
		$this->assertSame( 'published', $published->get_data()['plan']['status'] );
		$created_lessons = $this->lesson_count() - $before;
		$this->assertGreaterThan( 0, $created_lessons );

		$this->call( $this->teacher, 'POST', '/teacher/plans/' . $plan['id'] . '/publish', array( 'revision' => 2 ) );
		$this->assertSame( $before + $created_lessons, $this->lesson_count(), 'Volver a publicar no duplica lecciones.' );
	}

	public function test_assistant_publishes_like_instructor_and_stranger_is_blocked(): void {
		$created = $this->call( $this->assistant, 'POST', '/teacher/plans', array( 'course_id' => $this->course, 'data' => array( 'title' => 'Semana 2', 'remote' => array( 'before' => 'Leer.' ) ) ) );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$plan = $created->get_data();
		// 6.41.0: con delegación, publica como el instructor.
		$this->assertTrue( $plan['can_publish'] );

		$this->assertSame( 404, $this->call( $this->stranger, 'GET', '/teacher/plans/' . $plan['id'] )->get_status() );
		$this->assertSame( 404, $this->call( $this->stranger, 'POST', '/teacher/plans', array( 'course_id' => $this->course, 'data' => array() ) )->get_status() );
		$this->assertSame( 403, $this->call( $this->student, 'GET', '/teacher/plans', array(), array( 'course' => $this->course ) )->get_status() );

		$published = $this->call( $this->assistant, 'POST', '/teacher/plans/' . $plan['id'] . '/publish', array( 'revision' => 1 ) );
		$this->assertSame( 200, $published->get_status(), wp_json_encode( $published->get_data() ) );
		$this->assertGreaterThan( 0, $this->lesson_count() );
	}

	public function test_audio_upload_is_validated_and_idempotent(): void {
		$fixtures = dirname( __DIR__ ) . '/fixtures/audio/';
		$first    = $this->upload( $this->teacher, 'up-1', $fixtures . 'tono.mp3', 'clase.mp3' );
		$this->assertSame( 201, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$media = $first->get_data();
		$this->assertSame( 'audio', $media['type'] );
		$this->assertGreaterThan( 0, (int) $media['duration_seconds'] );

		$retry = $this->upload( $this->teacher, 'up-1', $fixtures . 'tono.mp3', 'clase.mp3' );
		$this->assertSame( 200, $retry->get_status() );
		$this->assertSame( $media['id'], $retry->get_data()['id'], 'Reintento: el mismo adjunto.' );

		$fake = get_temp_dir() . 'disfrazado.txt';
		file_put_contents( $fake, str_repeat( 'no es audio ', 200 ) );
		$attachments = count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
		$this->assertSame( 400, $this->upload( $this->teacher, 'up-2', $fake, 'falso.mp3' )->get_status() );
		$this->assertSame( 400, $this->upload( $this->teacher, 'up-3', $fixtures . 'tono.wav', 'clase.wav' )->get_status() );
		$this->assertSame( $attachments, count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) ) ), 'Lo rechazado no queda en la biblioteca.' );

		$this->assertSame( 403, $this->upload( $this->student, 'up-4', $fixtures . 'tono.mp3', 'clase.mp3' )->get_status() );

		// El audio subido entra al plan como recurso.
		$plan = $this->call( $this->teacher, 'POST', '/teacher/plans', array( 'course_id' => $this->course, 'data' => array( 'title' => 'Con audio', 'resources' => array( array( 'type' => 'audio', 'file_id' => $media['id'], 'title' => 'Explicación', 'transcript' => 'Hola' ) ) ) ) )->get_data();
		$this->assertSame( $media['id'], (int) $plan['data']['resources'][0]['file_id'] );
	}
}
