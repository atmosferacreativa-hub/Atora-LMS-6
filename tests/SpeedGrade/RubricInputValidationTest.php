<?php
/**
 * SpeedGrade — validación de puntajes por criterio y derivación de nota final.
 *
 * @package ATORA_LMS\Tests\SpeedGrade
 */

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/../../includes/grading/trait-grading-speedgrade.php';

	if ( ! function_exists( 'get_the_title' ) ) {
		function get_the_title( $id ): string { return 'Rúbrica'; }
	}
	if ( ! function_exists( 'get_post_field' ) ) {
		function get_post_field( string $field, int $post_id ) { return 1; }
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $key, $default = false ) { return $default; }
	}

	if ( ! class_exists( 'CLMS_Grading_Status_Service' ) ) {
		// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
		class CLMS_Grading_Status_Service {
			public function normalize_status( $s ): string { return (string) $s; }
		}
	}
	if ( ! class_exists( 'CLMS_Grading_Feedback_Service' ) ) {
		// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
		class CLMS_Grading_Feedback_Service {
			public function sanitize_feedback( $s ): string { return (string) $s; }
			public function sanitize_rubric_comment( $s ): string { return (string) $s; }
		}
	}
	if ( ! class_exists( 'CLMS_SpeedGrade_Actions' ) ) {
		// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
		class CLMS_SpeedGrade_Actions {
			public static function normalize_submit_action( $s ): string { return (string) $s; }
		}
	}
	if ( ! class_exists( 'CLMS_SpeedGrade_Moderation_Policy' ) ) {
		// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
		class CLMS_SpeedGrade_Moderation_Policy {
			public static function direct_publish_allowed( bool $institutional, string $status ): bool { return true; }
		}
	}
}

namespace ATORA\LMS {
	// Stub mínimo para que el trait ejecute la ruta de rúbricas sin depender del resto del LMS.
	if ( ! class_exists( '\ATORA\LMS\Rubric_Service' ) ) {
		// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
		class Rubric_Service {
			public static function get_rubric_id_for_lesson( int $lesson_id ): int { return 101; }
			public static function get( int $rubric_id ) { return null; }
			public static function get_evaluation( int $submission_id ) { return null; }
			public static function get_criteria( int $rubric_id, int $revision ): array { return array(); }
			public static function record_evaluation( array $payload ): void {}
		}
	}
}

namespace ATORA\Tests\SpeedGrade {

	use PHPUnit\Framework\TestCase;

	// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
	final class DummySpeedGrade {
		use \CLMS_Grading_SpeedGrade_Trait;

		public const SPEEDGRADE_NONCE  = 'clms_speedgrade_nonce';
		public const SPEEDGRADE_ACTION = 'clms_speedgrade';
		public const SPEEDGRADE_VAR    = 'clms_speedgrade';
		public const SPEEDGRADE_RETURN = 'clms_speedgrade_return';

		public function current_user_can_grade_submission( int $submission_id, int $user_id ): bool { return true; }
	}

	final class RubricInputValidationTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			require_once __DIR__ . '/../../includes/class-rubric.php';

			atora_test_reset_post_meta();
			atora_test_reset_post_types();
			atora_test_reset_posts();
			atora_test_reset_nonces();

		// Sembrar rúbrica en postmeta (vía CLMS_Rubric::get_criteria()).
		$rubric_id = 101;
		atora_test_set_post_type( $rubric_id, 'clms_rubric' );
		atora_test_set_post_meta( $rubric_id, '_clms_rubric_criteria', array(
			array( 'name' => 'Claridad', 'max_points' => 20 ),
			array( 'name' => 'Coherencia', 'max_points' => 10 ),
		) );

		// Entrega con lesson_id (para resolver rubric_id) y datos mínimos.
		atora_test_set_post_meta( 500, '_clms_submission_lesson_id', 900 );
		atora_test_set_post_meta( 500, '_clms_submission_course_id', 777 );
		atora_test_set_post_meta( 500, '_clms_submission_user_id', 42 );

			$_POST = array(
				DummySpeedGrade::SPEEDGRADE_NONCE => wp_create_nonce( DummySpeedGrade::SPEEDGRADE_ACTION . '_' . 500 ),
				'status'                          => 'in_review',
				'feedback'                        => '',
				'clms_sg_submit'                   => 'save_draft',
				'grade'                           => '',
		);
	}

	/** @test */
	public function rejects_out_of_range_scores_on_save(): void {
		$_POST['rubric_scores'] = array( '0' => '25', '1' => '10' ); // Claridad máx=20, 25 debe fallar.

		$sg = new DummySpeedGrade();
		$result = $this->invoke_handle_speedgrade_save( $sg, 500, 1 );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_rubric_score_range', $result->get_error_code() );
	}

		/** @test */
		public function does_not_derive_final_grade_from_rubric_when_grade_is_blank(): void {
			$_POST['rubric_scores'] = array( '0' => '15', '1' => '7.5' ); // 22.5/30 => 75% (referencia)

			$sg = new DummySpeedGrade();
			$result = $this->invoke_handle_speedgrade_save( $sg, 500, 1 );

			$this->assertFalse( is_wp_error( $result ) );
			$this->assertSame( '', (string) get_post_meta( 500, '_clms_submission_grade', true ) );
		}

		private function invoke_handle_speedgrade_save( DummySpeedGrade $sg, int $submission_id, int $user_id ) {
			$ref = new \ReflectionClass( $sg );
			$m = $ref->getMethod( 'handle_speedgrade_save' );
			$m->setAccessible( true );
			return $m->invoke( $sg, $submission_id, $user_id );
		}
	}
}
