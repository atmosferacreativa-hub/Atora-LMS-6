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
		// The real save_post bridge may already have created the table row.
		$row = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->course );
		$table_course = $row ? (int) $row['id'] : \ATORA\LMS\LMS_Course_Service::create_from_legacy( array( 'wp_post_id' => $this->course, 'title' => 'Curso de tablas', 'slug' => 'lab-' . $this->course ) );
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

	public function test_unrelated_page_does_not_become_speedgrader(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $page ) );
		$_GET['submission_id'] = (string) $this->submission;
		$this->assertSame( 0, clms_core( 'CLMS_Grading' )->get_speedgrade_request_submission_id() );
	}

	public function test_short_url_dispatches_document_on_repeated_requests(): void {
		$this->go_to( home_url( '/?submission_id=' . $this->submission ) );
		$_GET = array( 'submission_id' => (string) $this->submission );
		$grading = new class extends CLMS_Grading {
			protected function get_speedgrade_document( $submission_id ) {
				throw new RuntimeException( 'document:' . $submission_id );
			}
		};
		for ( $request = 0; $request < 2; ++$request ) {
			try {
				$grading->maybe_render_speedgrade_screen();
				$this->fail( 'Short URL did not dispatch SpeedGrader' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'document:' . $this->submission, $e->getMessage() );
			}
		}
	}

	public function test_short_url_does_not_expose_markup_to_visitor_or_student(): void {
		$grading = clms_core( 'CLMS_Grading' );
		$method = new ReflectionMethod( $grading, 'get_speedgrade_markup' );
		$method->setAccessible( true );
		foreach ( array( 0, $this->student ) as $actor ) {
			wp_set_current_user( $actor );
			$html = $method->invoke( $grading, $this->submission );
			$this->assertStringNotContainsString( 'Estudiante de prueba', $html );
			$this->assertStringNotContainsString( 'name="clms_speedgrade_action"', $html );
		}
	}

	public function test_group_submitter_has_a_label(): void {
		delete_post_meta( $this->submission, '_clms_submission_user_id' );
		update_post_meta( $this->submission, '_clms_submission_group_master', '1' );
		update_post_meta( $this->submission, '_clms_submission_submitted_by', $this->student );
		$grading = clms_core( 'CLMS_Grading' );
		$context = $grading->get_submission_context( $this->submission, $this->admin );
		$this->assertSame( $this->student, $context['student_id'] );
		$this->assertSame( 'Estudiante de prueba', $context['student_name'] );
	}

	public function test_admin_counts_match_publish_private_inventory(): void {
		foreach ( array( 'lm_course', 'lm_program' ) as $type ) {
			foreach ( array( 'publish', 'private', 'draft', 'trash' ) as $status ) {
				self::factory()->post->create( array( 'post_type' => $type, 'post_status' => $status ) );
			}
		}
		$menu = new class extends CLMS_Admin_Menu {
			public function metrics() { return $this->get_role_summary_metrics( 'admin', get_current_user_id() ); }
		};
		$metrics = array_column( $menu->metrics(), 'value', 'label' );
		foreach ( array( 'Cursos' => 'lm_course', 'Programas' => 'lm_program' ) as $label => $type ) {
			$listed = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
			$this->assertSame( count( $listed ), (int) $metrics[ $label ] );
		}
	}
}
