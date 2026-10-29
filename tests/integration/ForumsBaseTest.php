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

		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->teacher = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Docente Foros' ) );
		$this->assistant = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant', 'display_name' => 'Asistente Foros' ) );
		$this->assistant_no_delegate = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant', 'display_name' => 'Asistente sin delegación' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana Foros' ) );
		$this->student2 = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Beto Foros' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'No Matriculado' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Curso Foros' ) );
		$this->wp_course_draft = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'draft', 'post_author' => $author, 'post_title' => 'Curso No Publicado' ) );
		$this->course = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student, $this->course );
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student2, $this->course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );
		CLMS_Helper::enroll_user_in_course( $this->student2, $this->wp_course );

		$section = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->wp_course, 'title' => 'Sección Foros' ) );
		\ATORA\LMS\Section_Service::add_teacher( $section, $this->teacher );

		// Delegación para el asistente.
		if ( class_exists( 'ATORA_Delegation_Service' ) ) {
			ATORA_Delegation_Service::grant( $this->teacher, $this->assistant, array(
				'scope'        => 'course',
				'wp_course_id' => $this->wp_course,
				'perms'        => array( 'content' => true, 'grade' => true ),
			) );
		}

		foreach ( array( $this->teacher, $this->assistant, $this->assistant_no_delegate, $this->student, $this->student2, $this->stranger ) as $u ) {
			$this->tokens[ $u ] = ATORA_Mobile_Token_Service::issue( $u, 'test' )['access_token'];
		}

		// Crear el foro de curso.
		$req = new WP_REST_Request( 'GET', '/atora-mobile/v1/forums' );
		$req->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $this->student ] );
		$res = rest_ensure_response( rest_get_server()->dispatch( $req ) );
		$res = apply_filters( 'rest_post_dispatch', $res, rest_get_server(), $req );
		$this->forum_id = (int) ( $res->get_data()['items'][0]['id'] ?? 0 );
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
		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
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
}

