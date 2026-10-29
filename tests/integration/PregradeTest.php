<?php
/**
 * Integración 6.41.0: pre-calificación del instructor asistente.
 *
 * - El asistente con delegación «publica» y queda como pre-calificación: el estudiante no ve la nota.
 * - La cola del instructor «Por validar» la muestra; el asistente no puede validarla ni devolverla.
 * - El instructor la valida publicando (el estudiante ve la nota; la auditoría guarda quién propuso y quién validó)
 *   o la devuelve con comentario (sin comentario, 400).
 * - El instructor asistente aparece en el desplegable de roles.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/LmsRowsCleanup.php';

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class PregradeTest extends WP_UnitTestCase {

	use LmsRowsCleanup;

	private int $teacher = 0;
	private int $assistant = 0;
	private int $student = 0;
	private int $wp_course = 0;
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
		global $wpdb;
		foreach ( array( 'atora_sections', 'atora_section_teachers', 'atora_section_students', 'atora_assignment_submissions' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher   = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Instructora' ) );
		$this->assistant = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant', 'display_name' => 'Asistente Uno' ) );
		$this->student   = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher ) );
		$this->wp_lesson = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_author' => $this->teacher,
			'post_title'  => 'Ensayo',
			'meta_input'  => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea' ),
		) );
		$course       = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		$this->lesson = (int) LMS_Course_Service::get_lesson_by_wp_post( $this->wp_lesson )['id'];
		LMS_Enrollment_Service::enroll( $this->student, $course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );

		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug = 'pregrade-inst'" );
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'pregrade-inst', 'name' => 'Pre', 'status' => 'active' ) );
		$inst = (int) $wpdb->insert_id;
		foreach ( array( $this->teacher => 'teacher', $this->assistant => 'assistant' ) as $user => $role ) {
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst, 'user_id' => $user, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $user, '_atora_active_institution', $inst );
		}
		\ATORA\LMS\Tenant_Context::reset_cache();
		wp_set_current_user( $this->teacher );
		$this->assertGreaterThan( 0, ATORA_Delegation_Service::grant( $this->teacher, $this->assistant, array( 'scope' => 'course', 'wp_course_id' => $this->wp_course, 'perms' => array( 'content' => true ) ) ) );
		wp_set_current_user( 0 );

		foreach ( array( $this->teacher, $this->assistant, $this->student ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	protected function tearDown(): void {
		$this->cleanup_lms_rows( array( $this->wp_course ), array( $this->student ) );
		global $wpdb;
		$inst = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}atora_institutions WHERE slug = 'pregrade-inst'" );
		if ( $inst ) {
			$wpdb->delete( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst ) );
			$wpdb->delete( $wpdb->prefix . 'atora_institutions', array( 'id' => $inst ) );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_instructor_delegations WHERE assistant_id = " . (int) $this->assistant ); // phpcs:ignore WordPress.DB
		parent::tearDown();
	}

	private function submission(): int {
		$id = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student ) );
		update_post_meta( $id, '_clms_submission_user_id', $this->student );
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
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	private function visible_grade( int $submission ) {
		return CLMS_Student_Grade_Visibility::for_submission_post( $submission )['grade'];
	}

	private function grade( int $user, int $sub, string $event, int $revision, int $grade = 80 ): WP_REST_Response {
		return $this->call( $user, 'POST', "/teacher/submissions/{$sub}/grade", array( 'client_event_id' => $event, 'publish' => true, 'feedback' => 'Bien', 'grade' => $grade, 'expected_revision' => $revision ) );
	}

	private function actions( int $sub ): array {
		return array_column( CLMS_Gradebook_Audit_Service::get_log( $sub ), 'action' );
	}

	public function test_assistant_publish_becomes_pregrade_and_instructor_validates(): void {
		$sub = $this->submission();

		$proposed = $this->grade( $this->assistant, $sub, 'a-1', 0 );
		$this->assertSame( 200, $proposed->get_status(), wp_json_encode( $proposed->get_data() ) );
		$this->assertTrue( $proposed->get_data()['result']['pregrade'] );
		$this->assertSame( 'in_review', get_post_meta( $sub, '_clms_submission_status', true ), 'No se publica.' );
		$this->assertNull( $this->visible_grade( $sub ), 'El estudiante no ve la pre-calificación.' );
		$detail = $proposed->get_data()['submission'];
		$this->assertTrue( $detail['pregrade']['pending'] );
		$this->assertSame( 'Asistente Uno', $detail['pregrade']['by']['name'] );
		$this->assertFalse( $detail['can_publish'], 'El asistente no publica.' );

		$queue = $this->call( $this->teacher, 'GET', '/teacher/submissions', array(), array( 'status' => 'to_validate' ) )->get_data();
		$this->assertSame( array( $sub ), array_column( $queue['items'], 'id' ), 'Cola «Por validar».' );
		$this->assertSame( 'Asistente Uno', $queue['items'][0]['pregrade']['by']['name'] );

		$this->assertSame( 403, $this->call( $this->assistant, 'POST', "/teacher/submissions/{$sub}/pregrade/return", array( 'comment' => 'x' ) )->get_status(), 'El asistente no devuelve su propia pre-calificación.' );

		$validated = $this->grade( $this->teacher, $sub, 't-1', 1, 85 );
		$this->assertSame( 200, $validated->get_status(), wp_json_encode( $validated->get_data() ) );
		$this->assertFalse( $validated->get_data()['result']['pregrade'] );
		$this->assertSame( 'graded', get_post_meta( $sub, '_clms_submission_status', true ) );
		$this->assertSame( '85', (string) $this->visible_grade( $sub ), 'Validada: el estudiante ve la nota.' );
		$this->assertNull( $validated->get_data()['submission']['pregrade'] );
		$this->assertContains( 'pregrade_proposed', $this->actions( $sub ) );
		$this->assertContains( 'pregrade_validated', $this->actions( $sub ) );
		$this->assertSame( array(), $this->call( $this->teacher, 'GET', '/teacher/submissions', array(), array( 'status' => 'to_validate' ) )->get_data()['items'] );
	}

	public function test_instructor_returns_pregrade_with_comment(): void {
		$sub = $this->submission();
		$this->grade( $this->assistant, $sub, 'a-2', 0 );

		$this->assertSame( 400, $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/pregrade/return", array( 'comment' => ' ' ) )->get_status(), 'Sin comentario.' );
		$returned = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/pregrade/return", array( 'comment' => 'Revisa el criterio 2.' ) );
		$this->assertSame( 200, $returned->get_status(), wp_json_encode( $returned->get_data() ) );
		$info = $returned->get_data()['submission']['pregrade'];
		$this->assertFalse( $info['pending'] );
		$this->assertSame( 'Revisa el criterio 2.', $info['returned']['comment'] );
		$this->assertNull( $this->visible_grade( $sub ) );
		$this->assertContains( 'pregrade_returned', $this->actions( $sub ) );
		$this->assertSame( 409, $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/pregrade/return", array( 'comment' => 'otra' ) )->get_status(), 'Ya no hay nada por validar.' );
	}

	public function test_instructor_assistant_is_in_the_roles_dropdown(): void {
		$this->assertArrayHasKey( 'lms_instructor_assistant', CLMS_Access::filter_editable_roles( wp_roles()->roles ) );
	}
}
