<?php
/**
 * Integración: Foros (base).
 */

declare( strict_types = 1 );

use ATORA\Forums\Forums_Installer;

final class ForumsBaseTest extends WP_UnitTestCase {
	private int $teacher = 0;
	private int $assistant = 0;
	private int $assistant_no_delegate = 0;
	private int $student = 0;
	private int $student2 = 0;
	private int $stranger = 0;
	private int $wp_course = 0;
	private int $wp_course_draft = 0;
	private int $course = 0;
	private array $tokens = array();
	private int $forum_id = 0;
	private int $admin_a = 0;
	private int $admin_b = 0;
	private int $inst_a = 0;
	private int $inst_b = 0;
	private int $wp_course_b = 0;
	private int $forum_b = 0;
	private int $previous_default = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( class_exists( 'CLMS_DB_Migration' ) ) {
			( new CLMS_DB_Migration() )->run();
		}
		Forums_Installer::ensure_tables();
		do_action( 'rest_api_init', rest_get_server() );
		global $wpdb;
		// Las tablas propias sobreviven entre archivos: solo se borra lo de esta prueba.
		foreach ( array( 'atora_forums', 'atora_forum_topics', 'atora_forum_replies', 'atora_forum_reports', 'atora_sections', 'atora_section_teachers' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->remove_institutions();
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'foros-a', 'name' => 'Inst A', 'status' => 'active' ) );
		$this->inst_a = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'foros-b', 'name' => 'Inst B', 'status' => 'active' ) );
		$this->inst_b = (int) $wpdb->insert_id;
		$this->previous_default = (int) get_option( 'atora_default_institution', 0 );
		update_option( 'atora_default_institution', $this->inst_a, false );

		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->teacher = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Docente Foros' ) );
		$this->assistant = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant', 'display_name' => 'Asistente Foros' ) );
		$this->assistant_no_delegate = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant', 'display_name' => 'Asistente sin delegación' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana Foros' ) );
		$this->student2 = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Beto Foros' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'No Matriculado' ) );
		$this->admin_a = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Admin A' ) );
		$this->admin_b = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Admin B' ) );

		foreach ( array( $this->teacher, $this->assistant, $this->assistant_no_delegate, $this->student, $this->student2, $this->stranger, $this->admin_a, $this->admin_b ) as $u ) {
			$role = $u === $this->teacher ? 'teacher' : ( in_array( $u, array( $this->assistant, $this->assistant_no_delegate ), true ) ? 'assistant' : ( in_array( $u, array( $this->admin_a, $this->admin_b ), true ) ? 'admin' : 'member' ) );
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_a, 'user_id' => $u, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $u, '_atora_active_institution', $this->inst_a );
		}
		$wpdb->update( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_b ), array( 'user_id' => $this->admin_b ) );
		update_user_meta( $this->admin_a, '_atora_active_institution', $this->inst_a );
		update_user_meta( $this->admin_b, '_atora_active_institution', $this->inst_b );

		// El docente es el autor: la delegación la reconoce el sistema cuando la da el autor del curso.
		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher, 'post_title' => 'Curso Foros' ) );
		$this->wp_course_draft = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'draft', 'post_author' => $author, 'post_title' => 'Curso No Publicado' ) );
		$this->course = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student, $this->course );
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student2, $this->course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );
		CLMS_Helper::enroll_user_in_course( $this->student2, $this->wp_course );
		$draft = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course_draft );
		if ( $draft ) {
			\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student, (int) $draft['id'] );
		}
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course_draft );

		$section = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->wp_course, 'title' => 'Sección Foros' ) );
		\ATORA\LMS\Section_Service::add_teacher( $section, $this->teacher );

		// Curso en otra institución.
		update_option( 'atora_default_institution', $this->inst_b, false );
		$this->wp_course_b = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Curso Inst B' ) );
		$section_b = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->wp_course_b, 'title' => 'Sección B' ) );
		\ATORA\LMS\Section_Service::add_teacher( $section_b, $this->admin_b );
		\ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course_b );
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $this->inst_b ), array( 'wp_post_id' => $this->wp_course_b ) );
		ATORA_Teacher_Scope::reset_cache();
		$forum_b = \ATORA\Forums\Forums_Service::forum_for_scope( 'course', $this->wp_course_b );
		$this->forum_b = (int) ( is_array( $forum_b ) ? $forum_b['id'] : 0 );
		$this->assertGreaterThan( 0, $this->forum_b );
		update_option( 'atora_default_institution', $this->inst_a, false );

		// Delegación para el asistente.
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		wp_set_current_user( $this->teacher );
		$this->assertGreaterThan( 0, ATORA_Delegation_Service::grant( $this->teacher, $this->assistant, array(
			'scope'        => 'course',
			'wp_course_id' => $this->wp_course,
			'perms'        => array( 'content' => true, 'grade' => true ),
		) ) );
		wp_set_current_user( 0 );

		foreach ( array( $this->teacher, $this->assistant, $this->assistant_no_delegate, $this->student, $this->student2, $this->stranger, $this->admin_a, $this->admin_b ) as $u ) {
			$this->tokens[ $u ] = ATORA_Mobile_Token_Service::issue( $u, 'test' )['access_token'];
		}

		// El foro del curso de esta prueba (la lista del usuario puede traer foros de filas que quedaron de otras pruebas).
		$forum          = \ATORA\Forums\Forums_Service::forum_for_scope( 'course', $this->wp_course );
		$this->forum_id = (int) ( is_array( $forum ) ? $forum['id'] : 0 );
		$this->assertGreaterThan( 0, $this->forum_id );
	}

	private function call( int $user, string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/atora-mobile/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		foreach ( $query as $k => $v ) {
			$request->set_param( $k, $v );
		}
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( array( 'atora_forums', 'atora_forum_topics', 'atora_forum_replies', 'atora_forum_reports' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_instructor_delegations WHERE assistant_id = " . (int) $this->assistant ); // phpcs:ignore WordPress.DB
		$this->remove_institutions();
		update_option( 'atora_default_institution', $this->previous_default, false );
		parent::tearDown();
	}

	private function remove_institutions(): void {
		global $wpdb;
		$ids = array_map( 'absint', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}atora_institutions WHERE slug IN ('foros-a','foros-b')" ) );
		if ( $ids ) {
			$in = implode( ',', $ids );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institution_members WHERE institution_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}
	}

	public function test_student_not_enrolled_cannot_read_or_write(): void {
		$this->assertSame( 403, $this->call( $this->stranger, 'GET', "/forums/{$this->forum_id}/topics" )->get_status() );
		$this->assertSame( 403, $this->call( $this->stranger, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Hola', 'body' => 'x', 'client_event_id' => 'e1' ) )->get_status() );
	}

	public function test_student_creates_topic_and_other_student_replies(): void {
		$topic = $this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Pregunta', 'body' => '¿Cómo funciona?', 'client_event_id' => 't1' ) );
		$this->assertSame( 201, $topic->get_status() );
		$topic_id = (int) $topic->get_data()['topic']['id'];
		$this->assertGreaterThan( 0, $topic_id );

		$reply = $this->call( $this->student2, 'POST', "/forums/topics/{$topic_id}/replies", array( 'body' => 'Así...', 'client_event_id' => 'r1' ) );
		$this->assertSame( 201, $reply->get_status() );

		$detail = $this->call( $this->student, 'GET', "/forums/topics/{$topic_id}" );
		$this->assertSame( 200, $detail->get_status() );
		$this->assertSame( 1, count( (array) $detail->get_data()['replies'] ) );
	}

	public function test_teacher_and_delegated_assistant_can_moderate_but_student_and_undelegated_assistant_cannot(): void {
		$topic = $this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Moderación', 'body' => '...', 'client_event_id' => 'tmod' ) );
		$topic_id = (int) $topic->get_data()['topic']['id'];

		$this->assertSame( 403, $this->call( $this->student, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'close' ) )->get_status() );
		$this->assertSame( 403, $this->call( $this->assistant_no_delegate, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'close' ) )->get_status() );
		$this->assertSame( 200, $this->call( $this->assistant, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'close' ) )->get_status() );
		$this->assertSame( 200, $this->call( $this->teacher, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'pin' ) )->get_status() );

		// El instructor no está matriculado como estudiante, pero lee y responde en el foro que modera.
		$this->assertSame( 200, $this->call( $this->teacher, 'GET', "/forums/{$this->forum_id}/topics" )->get_status() );
		$this->assertSame( 200, $this->call( $this->teacher, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'open' ) )->get_status() );
		$this->assertSame( 201, $this->call( $this->teacher, 'POST', "/forums/topics/{$topic_id}/replies", array( 'body' => 'Respuesta del docente', 'client_event_id' => 'tr1' ) )->get_status() );
	}

	public function test_hidden_topic_is_not_listed_for_students(): void {
		$topic = $this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Oculto', 'body' => '...', 'client_event_id' => 'thide' ) );
		$topic_id = (int) $topic->get_data()['topic']['id'];
		$this->assertSame( 200, $this->call( $this->teacher, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'hide' ) )->get_status() );
		$list = $this->call( $this->student, 'GET', "/forums/{$this->forum_id}/topics" )->get_data()['items'];
		$this->assertSame( array(), array_values( array_filter( $list, static fn( $t ) => (int) $t['id'] === $topic_id ) ) );
	}

	public function test_closed_topic_does_not_accept_replies(): void {
		$topic = $this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Cerrado', 'body' => '...', 'client_event_id' => 'tclose' ) );
		$topic_id = (int) $topic->get_data()['topic']['id'];
		$this->call( $this->teacher, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'close' ) );
		$this->assertSame( 409, $this->call( $this->student2, 'POST', "/forums/topics/{$topic_id}/replies", array( 'body' => '...', 'client_event_id' => 'rx' ) )->get_status() );
	}

	public function test_accepted_reply_only_by_topic_author_or_moderator(): void {
		$topic = $this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Aceptada', 'body' => '...', 'client_event_id' => 'tacc' ) );
		$topic_id = (int) $topic->get_data()['topic']['id'];
		$reply = $this->call( $this->student2, 'POST', "/forums/topics/{$topic_id}/replies", array( 'body' => 'resp', 'client_event_id' => 'racc' ) );
		$reply_id = (int) $reply->get_data()['reply']['id'];

		$this->assertSame( 403, $this->call( $this->student2, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'accept_reply', 'reply_id' => $reply_id ) )->get_status() );
		$this->assertSame( 200, $this->call( $this->student, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'accept_reply', 'reply_id' => $reply_id ) )->get_status() );
	}

	public function test_retry_client_event_id_does_not_duplicate(): void {
		$first = $this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Idem', 'body' => '...', 'client_event_id' => 'tidem' ) );
		$topic_id = (int) $first->get_data()['topic']['id'];
		$again = $this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Idem', 'body' => '...', 'client_event_id' => 'tidem' ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertTrue( (bool) $again->get_data()['replayed'] );
		$this->assertSame( $topic_id, (int) $again->get_data()['topic']['id'] );

		$r1 = $this->call( $this->student2, 'POST', "/forums/topics/{$topic_id}/replies", array( 'body' => '...', 'client_event_id' => 'ridem' ) );
		$reply_id = (int) $r1->get_data()['reply']['id'];
		$r2 = $this->call( $this->student2, 'POST', "/forums/topics/{$topic_id}/replies", array( 'body' => '...', 'client_event_id' => 'ridem' ) );
		$this->assertSame( 200, $r2->get_status() );
		$this->assertSame( $reply_id, (int) $r2->get_data()['reply']['id'] );
	}

	public function test_similar_returns_related_topics(): void {
		$this->call( $this->student, 'POST', "/forums/{$this->forum_id}/topics", array( 'title' => 'Cómo entregar tarea', 'body' => '...', 'client_event_id' => 'tsim1' ) );
		$sim = $this->call( $this->student2, 'GET', "/forums/{$this->forum_id}/similar", array(), array( 'q' => 'entregar' ) );
		$this->assertSame( 200, $sim->get_status() );
		$this->assertNotEmpty( $sim->get_data()['items'] );
	}

	public function test_unpublished_course_forum_is_read_only(): void {
		$req = new WP_REST_Request( 'GET', '/atora-mobile/v1/forums' );
		$req->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $this->student ] );
		rest_ensure_response( rest_get_server()->dispatch( $req ) );
		$forum = \ATORA\Forums\Forums_Service::forum_for_scope( 'course', $this->wp_course_draft );
		$this->assertNotWPError( $forum );
		$forum_id = (int) $forum['id'];
		$this->assertSame( 409, $this->call( $this->student, 'POST', "/forums/{$forum_id}/topics", array( 'title' => 'x', 'body' => 'y', 'client_event_id' => 'draft1' ) )->get_status() );
	}

	public function test_admin_cannot_moderate_other_institution_forums(): void {
		$topic = $this->call( $this->admin_b, 'POST', "/forums/{$this->forum_b}/topics", array( 'title' => 'B', 'body' => 'B', 'client_event_id' => 'b1' ) );
		$this->assertSame( 201, $topic->get_status() );
		$topic_id = (int) $topic->get_data()['topic']['id'];
		$this->assertSame( 403, $this->call( $this->admin_a, 'POST', "/forums/topics/{$topic_id}/moderate", array( 'action' => 'hide' ) )->get_status() );
	}
}

