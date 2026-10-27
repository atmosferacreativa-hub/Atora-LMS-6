<?php
/**
 * Integración 6.37.0: REST `atora/v1/hybrid-plans` respeta el alcance (no basta con iniciar sesión).
 */

declare( strict_types = 1 );

final class HybridPlanRestTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		// force_install confirma la transacción (DDL): limpiar filas de otras pruebas.
		foreach ( array( 'atora_sections', 'atora_section_teachers' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init' );
	}

	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/atora/v1/hybrid-plans' . $route );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	public function test_teacher_flow_and_stranger_is_blocked(): void {
		$teacher = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $teacher ) );
		wp_set_current_user( $teacher );

		$courses = $this->call( 'GET', '/courses' )->get_data();
		$this->assertContains( $course, wp_list_pluck( $courses['courses'], 'id' ) );
		$this->assertFalse( $courses['ai_available'] );

		$created = $this->call( 'POST', '', array( 'course_id' => $course, 'data' => array( 'title' => 'Semana 1', 'remote' => array( 'before' => 'Leer.' ) ) ) );
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];

		$this->assertSame( 200, $this->call( 'GET', '/' . $id . '/preview' )->get_status() );
		$this->assertSame( 409, $this->call( 'PUT', '/' . $id, array( 'data' => array( 'title' => 'x' ), 'revision' => 7 ) )->get_status() );

		$stranger = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		wp_set_current_user( $stranger );
		$this->assertSame( 404, $this->call( 'GET', '/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/' . $id . '/publish', array( 'revision' => 1 ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '', array( 'course_id' => $course, 'data' => array() ) )->get_status() );

		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $student );
		$this->assertContains( $this->call( 'GET', '/courses' )->get_status(), array( 401, 403 ) );
	}
}
