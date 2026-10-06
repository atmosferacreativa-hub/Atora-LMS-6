<?php
/**
 * Integración 6.31.0: tarea grupal en la app. El estudiante ve su grupo y quién
 * entregó, entrega por el grupo, y la nota de la maestra llega a todos los
 * integrantes con su estado, su rúbrica y los ajustes individuales.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class GroupAssignmentMobileTest extends WP_UnitTestCase {

	private int $a = 0;
	private int $b = 0;
	private int $admin = 0;
	private int $wp_course = 0;
	private int $wp_lesson = 0;
	private int $lesson = 0;
	private int $group = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		global $wpdb;
		foreach ( array( 'atora_assignment_submissions', 'clms_groups', 'clms_group_members', 'clms_group_submissions', 'clms_group_grade_overrides', 'atora_messages', 'atora_message_participants', 'atora_message_threads' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->a     = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana' ) );
		$this->b     = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Beto' ) );
		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'meta_input' => array( '_clms_course_groups_enabled' => '1' ) ) );
		$this->wp_lesson = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => 'Proyecto grupal',
			'meta_input'  => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea', '_clms_evaluation_mode' => 'group' ),
		) );
		$this->lesson = (int) LMS_Course_Service::get_lesson_by_wp_post( $this->wp_lesson )['id'];
		$course       = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		foreach ( array( $this->a, $this->b ) as $student ) {
			LMS_Enrollment_Service::enroll( $student, $course );
			CLMS_Helper::enroll_user_in_course( $student, $this->wp_course );
		}
		$service     = new \ATORA\Groups\Group_Service();
		$this->group = $service->create_group( $this->wp_course, 'Equipo Azul', $this->admin );
		$service->set_members( $this->group, array( $this->a, $this->b ), $this->admin );
	}

	private function get_assignment( int $user_id ): array {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', "/atora-mobile/v1/assignments/{$this->lesson}" );
		$request->set_url_params( array( 'lesson_id' => $this->lesson ) );
		$response = ATORA_Mobile_REST_Controller::assignment( $request );
		$this->assertNotWPError( $response );
		return $response->get_data();
	}

	public function test_group_task_from_the_phone_end_to_end(): void {
		$before = $this->get_assignment( $this->a );
		$this->assertTrue( $before['assignment']['group_mode'] );
		$this->assertSame( 'Equipo Azul', $before['assignment']['group']['name'] );
		$this->assertEqualsCanonicalizing( array( 'Ana', 'Beto' ), wp_list_pluck( $before['assignment']['group']['members'], 'name' ) );
		$this->assertNull( $before['assignment']['group']['submitted_by'] );
		$this->assertTrue( $before['assignment']['can_submit'], 'Ya no es "no disponible".' );

		wp_set_current_user( $this->a );
		$request = new WP_REST_Request( 'POST', "/atora-mobile/v1/assignments/{$this->lesson}/submissions" );
		$request->set_url_params( array( 'lesson_id' => $this->lesson ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'client_event_id' => 'grupo-evt-1', 'body_text' => 'Entrega del equipo' ) ) );
		$created = ATORA_Mobile_REST_Controller::create_assignment_submission( $request );
		$this->assertNotWPError( $created );

		$for_b = $this->get_assignment( $this->b );
		$this->assertSame( 'Ana', $for_b['assignment']['group']['submitted_by']['name'], 'Beto ve quién entregó.' );
		$this->assertCount( 1, $for_b['submissions'] );
		$this->assertSame( 'Entrega del equipo', $for_b['submissions'][0]['body_text'] );

		$master = (int) get_posts( array( 'post_type' => 'clms_submission', 'post_status' => array( 'publish', 'private' ), 'fields' => 'ids', 'meta_key' => '_clms_submission_group_master', 'meta_value' => '1' ) )[0];
		( new \ATORA\Groups\Group_Service() )->set_override( $this->group, $this->wp_lesson, $this->b, 60, 'Aportó menos', $this->admin );

		wp_set_current_user( $this->admin );
		$saved = ( new ATORA_Grading_Save_Service( new CLMS_Grading() ) )->save( $master, $this->admin, array( 'clms_sg_submit' => 'save_draft', 'grade' => '85', 'feedback' => 'Borrador', 'expected_revision' => ATORA_Grading_Save_Service::revision( $master ) ) );
		$this->assertIsArray( $saved );
		$this->assertNull( $this->get_assignment( $this->a )['submissions'][0]['grade'], 'Borrador: nadie ve la nota.' );

		( new ATORA_Grading_Save_Service( new CLMS_Grading() ) )->save( $master, $this->admin, array( 'clms_sg_submit' => 'publish', 'grade' => '85', 'feedback' => 'Muy bien', 'expected_revision' => ATORA_Grading_Save_Service::revision( $master ) ) );
		$this->assertEquals( 85, $this->get_assignment( $this->a )['submissions'][0]['grade'] );
		$this->assertEquals( 60, $this->get_assignment( $this->b )['submissions'][0]['grade'], 'Ajuste individual respetado.' );
	}
}
