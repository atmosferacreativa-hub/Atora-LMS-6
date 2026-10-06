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

	/** 6.31.1: sin revisión no se guarda (web ni API); cada guardado la sube. */
	public function test_revision_is_required_and_every_save_bumps_it(): void {
		$missing = $this->web_save( '70', null );
		$this->assertWPError( $missing );
		$this->assertSame( 'atora_grade_revision_required', $missing->get_error_code() );
		$this->assertSame( 400, $missing->get_error_data()['status'] );
		$this->assertSame( 0, ATORA_Grading_Save_Service::revision( $this->submission ) );

		$this->assertIsArray( $this->web_save( '70', 0 ) );
		$this->assertIsArray( $this->web_save( '75', 1 ) );
		$this->assertSame( 2, ATORA_Grading_Save_Service::revision( $this->submission ) );
	}

	/** 6.31.1: una moderación que falla después del reclamo no consume la revisión. */
	public function test_failed_moderation_does_not_consume_the_revision(): void {
		$this->assertIsArray( $this->web_save( '70', 0 ) );
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $this->submission ),
			'clms_sg_submit'          => 'approve_moderation',
			'grade'                   => '88',
			'expected_revision'       => '1',
			'moderation_lock_version' => '999',
		);
		$grading = new CLMS_Grading();
		$save    = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$save->setAccessible( true );
		$out   = $save->invoke( $grading, $this->submission, $this->admin );
		$_POST = array();
		$this->assertWPError( $out, 'La moderación rechaza la decisión.' );
		$this->assertSame( 1, ATORA_Grading_Save_Service::revision( $this->submission ), 'La revisión no cambia.' );
		$this->assertIsArray( $this->web_save( '72', 1 ), 'Un reintento con la misma revisión funciona.' );
	}

	/** 6.31.1: un error de base de datos tras el reclamo no consume la revisión. */
	public function test_db_error_after_claim_does_not_consume_the_revision(): void {
		$this->assertIsArray( $this->web_save( '70', 0 ) );
		$break = static function ( $query ) {
			// Falla la escritura del estado de la entrega (después del reclamo).
			return false !== strpos( $query, '_clms_submission_status' ) && preg_match( '/^\s*(UPDATE|INSERT)/i', $query ) ? 'SELECT * FROM atora_tabla_inexistente' : $query;
		};
		add_filter( 'query', $break );
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $this->submission ),
			'clms_sg_submit'    => 'save_draft',
			'grade'             => '55',
			'expected_revision' => '1',
		);
		$grading = new CLMS_Grading();
		$save    = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$save->setAccessible( true );
		$out   = $save->invoke( $grading, $this->submission, $this->admin );
		$_POST = array();
		remove_filter( 'query', $break );
		$this->assertWPError( $out );
		$this->assertSame( 'atora_db_error', $out->get_error_code() );
		$this->assertSame( 1, ATORA_Grading_Save_Service::revision( $this->submission ), 'La revisión no cambia.' );
		$this->assertIsArray( $this->web_save( '72', 1 ), 'Un reintento con la misma revisión funciona.' );
	}

	/** 6.31.1: dos primeros guardados (sin meta, revisión 0): gana uno, el otro 409. */
	public function test_first_save_without_meta_is_atomic(): void {
		$this->assertSame( '', get_post_meta( $this->submission, ATORA_Grading_Save_Service::REVISION_META, true ) );
		$this->assertSame( 1, ATORA_Grading_Save_Service::claim_revision( $this->submission, 0 ) );
		$second = ATORA_Grading_Save_Service::claim_revision( $this->submission, 0 );
		$this->assertWPError( $second );
		$this->assertSame( 409, $second->get_error_data()['status'] );
	}
}
