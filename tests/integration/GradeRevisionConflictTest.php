<?php
/**
 * Integración 6.31.0: dos docentes guardan la misma entrega desde SpeedGrader
 * web. El segundo, que vio una revisión vieja, recibe el conflicto y no pisa la
 * nota del primero.
 */

declare( strict_types = 1 );

final class GradeRevisionConflictTest extends WP_UnitTestCase {

	private int $admin = 0;
	private int $submission = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$student     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$course      = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		$lesson      = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'meta_input' => array( '_clms_course_id' => $course ) ) );
		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $student ) );
		update_post_meta( $this->submission, '_clms_submission_user_id', $student );
		update_post_meta( $this->submission, '_clms_submission_lesson_id', $lesson );
		update_post_meta( $this->submission, '_clms_submission_course_id', $course );
		update_post_meta( $this->submission, '_clms_submission_status', 'submitted' );
		wp_set_current_user( $this->admin );
	}

	private function web_save( string $grade, ?int $expected ) {
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $this->submission ),
			'feedback'       => 'Nota ' . $grade,
			'clms_sg_submit' => 'publish',
			'grade'          => $grade,
		);
		if ( null !== $expected ) {
			$_POST['expected_revision'] = (string) $expected;
		}
		$grading = new CLMS_Grading();
		$save    = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$save->setAccessible( true );
		$out   = $save->invoke( $grading, $this->submission, $this->admin );
		$_POST = array();
		return $out;
	}

	public function test_second_teacher_with_stale_revision_gets_conflict_and_does_not_overwrite(): void {
		$seen = ATORA_Grading_Save_Service::revision( $this->submission );
		$this->assertSame( 0, $seen );

		$first = $this->web_save( '80', $seen );
		$this->assertIsArray( $first );
		$this->assertSame( 1, $first['revision'] );

		$second = $this->web_save( '60', $seen );
		$this->assertWPError( $second );
		$this->assertSame( 'atora_grade_revision_conflict', $second->get_error_code() );
		$this->assertSame( 'Otro docente guardó esta entrega; recarga para ver su versión.', $second->get_error_message() );
		$this->assertSame( 409, $second->get_error_data()['status'] );
		$this->assertSame( 1, $second->get_error_data()['current_revision'] );
		$this->assertEquals( 80, get_post_meta( $this->submission, '_clms_submission_grade', true ), 'La nota del primero queda.' );
	}

	public function test_every_save_bumps_the_revision_and_old_forms_still_save(): void {
		$this->web_save( '70', null );
		$this->web_save( '75', null );
		$this->assertSame( 2, ATORA_Grading_Save_Service::revision( $this->submission ) );
		$this->assertIsArray( $this->web_save( '90', 2 ) );
		$this->assertSame( 3, ATORA_Grading_Save_Service::revision( $this->submission ) );
	}
}
