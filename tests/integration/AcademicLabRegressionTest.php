<?php
/** B.1/B.4/B.5: real WordPress regression coverage, with distinct WP/table IDs. */
declare( strict_types = 1 );

final class AcademicLabRegressionTest extends WP_UnitTestCase {

	private int $admin;
	private int $student;
	private int $course;
	private int $lesson;
	private int $submission;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		\ATORA\V5_Installer::force_install();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Estudiante de prueba' ) );
		wp_set_current_user( $this->admin );
		$this->course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->admin ) );
		$this->lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_author' => $this->admin ) );
		update_post_meta( $this->lesson, '_clms_course_id', $this->course );
		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student ) );
		update_post_meta( $this->submission, '_clms_submission_user_id', $this->student );
		update_post_meta( $this->submission, '_clms_submission_course_id', $this->course );
		update_post_meta( $this->submission, '_clms_submission_lesson_id', $this->lesson );
		update_option( 'atora_lms_dualwrite', false );
	}

	protected function tearDown(): void {
		$_GET = $_POST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function save_grade(): void {
		$grading = clms_core( 'CLMS_Grading' );
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $this->submission ),
			'status' => 'graded', 'grade' => '87', 'feedback' => 'Revisado', 'clms_sg_submit' => 'publish',
		);
		$method = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$method->setAccessible( true );
		$result = $method->invoke( $grading, $this->submission, $this->admin );
		$this->assertIsArray( $result );
		$this->assertSame( 'graded', get_post_meta( $this->submission, '_clms_submission_status', true ) );
	}

	public function test_table_enrollment_grade_appears_in_gradebook_without_legacy_roster(): void {
		global $wpdb;
		$table_course = \ATORA\LMS\LMS_Course_Service::create_from_legacy( array( 'wp_post_id' => $this->course, 'title' => 'Curso de tablas', 'slug' => 'lab-' . $this->course ) );
		$this->assertGreaterThan( 0, $table_course );
		$this->assertNotSame( $this->course, $table_course );
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student, $table_course );
		delete_post_meta( $this->course, '_clms_enrolled_users' );
		update_option( 'atora_lms_read_source', 'tables' );
		$this->save_grade();
		$grid = ( new CLMS_Gradebook_Service() )->build_grid( $this->course );
		$this->assertCount( 1, $grid['rows'] );
		$this->assertSame( $this->student, $grid['rows'][0]['student_id'] );
		$this->assertSame( 87, $grid['rows'][0]['cells'][ $this->lesson ]['grade'] );
		// Completed students remain visible, withdrawn students do not return via stale legacy metadata.
		$wpdb->update( $wpdb->prefix . 'atora_enrollments', array( 'status' => 'completed' ), array( 'course_id' => $table_course, 'user_id' => $this->student ) );
		$this->assertSame( array( $this->student ), CLMS_Helper::get_enrolled_student_ids( $this->course ) );
		$wpdb->update( $wpdb->prefix . 'atora_enrollments', array( 'status' => 'unenrolled' ), array( 'course_id' => $table_course, 'user_id' => $this->student ) );
		update_post_meta( $this->course, '_clms_enrolled_users', array( $this->student ) );
		$this->assertSame( array(), CLMS_Helper::get_enrolled_student_ids( $this->course ) );
	}

	public function test_legacy_gradebook_still_displays_published_grade(): void {
		update_option( 'atora_lms_read_source', 'legacy' );
		update_post_meta( $this->course, '_clms_enrolled_users', array( $this->student ) );
		$this->save_grade();
		$grid = ( new CLMS_Gradebook_Service() )->build_grid( $this->course );
		$this->assertCount( 1, $grid['rows'] );
		$this->assertSame( 87, $grid['rows'][0]['cells'][ $this->lesson ]['grade'] );
	}

	public function test_speedgrader_context_resolves_legacy_student_and_author(): void {
		delete_post_meta( $this->submission, '_clms_submission_user_id' );
		$grading = clms_core( 'CLMS_Grading' );
		foreach ( array( true, false ) as $legacy_meta ) {
			if ( $legacy_meta ) {
				update_post_meta( $this->submission, '_clms_submission_student_id', $this->student );
			} else {
				delete_post_meta( $this->submission, '_clms_submission_student_id' );
			}
			$context = $grading->get_submission_context( $this->submission, $this->admin );
			$this->assertSame( $this->student, $context['student_id'] );
			$this->assertSame( 'Estudiante de prueba', $context['student_name'] );
		}
	}

	public function test_missing_student_has_explicit_label_and_does_not_leak_to_student(): void {
		update_post_meta( $this->submission, '_clms_submission_user_id', 99999999 );
		$grading = clms_core( 'CLMS_Grading' );
		$context = $grading->get_submission_context( $this->submission, $this->admin );
		$this->assertNotSame( '', trim( $context['student_name'] ) );
		$this->assertStringContainsString( '99999999', $context['student_name'] );
		wp_set_current_user( $this->student );
		$this->assertSame( array(), $grading->get_submission_context( $this->submission, $this->student ) );
	}

	public function test_short_speedgrader_url_resolves_before_catalog_redirects(): void {
		$this->go_to( home_url( '/?submission_id=' . $this->submission ) );
		$_GET = array( 'submission_id' => (string) $this->submission );
		$grading = clms_core( 'CLMS_Grading' );
		$this->assertSame( $this->submission, $grading->get_speedgrade_request_submission_id() );
		$this->assertSame( 0, has_action( 'template_redirect', array( $grading, 'maybe_render_speedgrade_screen' ) ) );
		$_GET = array( 'submission_id' => (string) $this->course );
		$this->assertSame( 0, $grading->get_speedgrade_request_submission_id() );
		$_GET = array( 'submission_id' => array( $this->submission ) );
		$this->assertSame( 0, $grading->get_speedgrade_request_submission_id() );
	}

	public function test_canonical_speedgrader_url_preserves_submission_and_return(): void {
		$grading = clms_core( 'CLMS_Grading' );
		parse_str( (string) wp_parse_url( $grading->get_speedgrade_url( $this->submission, admin_url( 'admin.php?page=clms-gradebook' ) ), PHP_URL_QUERY ), $query );
		$this->assertSame( '1', $query['clms_speedgrade'] );
		$this->assertSame( (string) $this->submission, $query['submission_id'] );
		$this->assertArrayHasKey( 'clms_return', $query );
	}
}
