<?php
/**
 * Integración: SpeedGrader registra evaluación con revisión en tablas.
 */

declare( strict_types = 1 );

final class SpeedGraderRubricEvaluationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();

		update_option( 'atora_rubric_source', 'tables', false );
	}

	public function test_speedgrader_save_writes_rubric_evaluation_with_revision(): void {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$instructor_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$student_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$rubric_post_id = self::factory()->post->create( array(
			'post_type'   => 'clms_rubric',
			'post_status' => 'publish',
			'post_title'  => 'Rúbrica',
		) );
		update_post_meta( $rubric_post_id, '_clms_rubric_scale_type', '0_100' );
		update_post_meta( $rubric_post_id, '_clms_rubric_is_holistic', '0' );
		update_post_meta( $rubric_post_id, '_clms_rubric_criteria', array(
			array(
				'name'        => 'C1',
				'description' => 'D1',
				'max_points'  => 10,
				'weight'      => 100,
				'levels'      => array(),
			),
		) );

		\ATORA\LMS\Rubrics_CLI::migrate( array(), array( 'yes' => true, 'batch' => 50 ) );

		$lesson_id = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_author' => $instructor_id,
			'post_title'  => 'Lección',
		) );
		update_post_meta( $lesson_id, '_clms_rubric_id', $rubric_post_id );

		$submission_id = self::factory()->post->create( array(
			'post_type'   => 'clms_submission',
			'post_status' => 'publish',
			'post_author' => $student_id,
			'post_title'  => 'Entrega',
		) );
		update_post_meta( $submission_id, '_clms_submission_lesson_id', $lesson_id );
		update_post_meta( $submission_id, '_clms_submission_user_id', $student_id );

		$grading = new CLMS_Grading();

		$nonce = wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $submission_id );
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => $nonce,
			'status'       => 'graded',
			'feedback'     => 'OK',
			'clms_sg_submit'=> 'save_draft',
			'rubric_scores'=> array( '10' ),
			'rubric_feedback' => array( 'Bien' ),
		);

		$m = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$m->setAccessible( true );
		$out = $m->invoke( $grading, $submission_id, $admin_id );

		$this->assertIsArray( $out );
		$this->assertSame( $submission_id, (int) ( $out['submission_id'] ?? 0 ) );

		$eval_table = $wpdb->prefix . 'atora_rubric_evaluations';
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$eval_table} WHERE wp_submission_id = %d ORDER BY id DESC LIMIT 1", $submission_id ),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( $rubric_post_id, (int) ( $row['rubric_id'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $row['rubric_revision'] ?? 0 ) );

		$decoded = json_decode( (string) ( $row['snapshot_json'] ?? '' ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( $rubric_post_id, (int) ( $decoded['rubric']['rubric_id'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $decoded['rubric']['rubric_revision'] ?? 0 ) );
	}
}

