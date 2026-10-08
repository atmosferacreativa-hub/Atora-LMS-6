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
		$this->assertIsArray( $this->web_save( '70', 0 ) );
		$second = $this->web_save( '75', 0 );
		$this->assertWPError( $second );
		$this->assertSame( 409, $second->get_error_data()['status'] );
		$this->assertSame( '70', get_post_meta( $this->submission, '_clms_submission_grade', true ) );
	}

	/**
	 * 6.33.1 (E.2): el bloqueo cubre todo el guardado y la revisión se escribe al
	 * final. Otra conexión (otro proceso) que mira a mitad del guardado —cuando ya
	 * se publicó la nota— encuentra el bloqueo tomado, y la revisión sigue siendo
	 * la vieja: quien lee con el bloqueo espera y nunca ve una mezcla.
	 */
	public function test_lock_covers_the_whole_save_and_revision_is_written_last(): void {
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock  = 'atora_grade_rev_' . $this->submission;
		$seen  = array();
		$probe = function ( $submission_id ) use ( $other, $lock, &$seen ) {
			if ( (int) $submission_id !== $this->submission ) {
				return;
			}
			wp_cache_delete( $this->submission, 'post_meta' );
			$seen[] = array(
				'free'     => (string) $other->get_var( $other->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) ),
				'grade'    => (string) get_post_meta( $this->submission, '_clms_submission_grade', true ),
				'revision' => ATORA_Grading_Save_Service::revision( $this->submission ),
			);
		};
		// A mitad del guardado: después de publicar la nota (avisos), antes de la auditoría y de la revisión.
		add_action( 'clms_submission_graded', $probe, 1 );
		add_action( 'clms_submission_grade_draft_saved', $probe, 1 );
		$saved = $this->web_save( '88', 0 );
		remove_action( 'clms_submission_graded', $probe, 1 );
		remove_action( 'clms_submission_grade_draft_saved', $probe, 1 );

		$this->assertIsArray( $saved );
		$this->assertNotEmpty( $seen, 'El guardado pasó por la publicación.' );
		$this->assertSame( '0', $seen[0]['free'], 'A mitad del guardado el bloqueo está tomado: otro proceso no puede leer ni guardar.' );
		$this->assertSame( '88', $seen[0]['grade'] );
		$this->assertSame( 0, $seen[0]['revision'], 'La revisión nueva todavía no se escribió.' );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT IS_FREE_LOCK(%s)', $lock ) ), 'Al terminar, el bloqueo se libera.' );
		$this->assertSame( 1, ATORA_Grading_Save_Service::revision( $this->submission ) );

		// Una lectura consistente desde otro proceso, mientras alguien guarda, espera al final.
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		$started = microtime( true );
		$busy    = $this->web_save( '90', 1 );
		$this->assertWPError( $busy, 'Con el bloqueo tomado por otro proceso, no se guarda a medias.' );
		$this->assertSame( 'atora_grade_busy', $busy->get_error_code() );
		$this->assertGreaterThan( 9, microtime( true ) - $started, 'Esperó el bloqueo.' );
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		$this->assertSame( '88', get_post_meta( $this->submission, '_clms_submission_grade', true ) );
	}

	/** 6.33.2 (orden 1.0.1, punto 7): sin el bloqueo, la lectura no se hace: 409 reintentable. */
	public function test_consistent_read_without_the_lock_is_a_retryable_409(): void {
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock  = 'atora_grade_rev_' . $this->submission;
		$wait  = static fn() => 1;
		add_filter( 'atora_grade_lock_wait', $wait );
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		$read = false;
		$out  = ATORA_Grading_Save_Service::read_consistent( $this->submission, static function () use ( &$read ) {
			$read = true;
			return array( 'grade' => 'a medias' );
		} );
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		remove_filter( 'atora_grade_lock_wait', $wait );
		$this->assertWPError( $out, 'No lee sin el bloqueo.' );
		$this->assertFalse( $read );
		$this->assertSame( 'atora_grade_busy', $out->get_error_code() );
		$this->assertSame( 409, $out->get_error_data()['status'] );
		$this->assertTrue( $out->get_error_data()['retryable'] );

		$this->assertSame( array( 'grade' => 'ok' ), ATORA_Grading_Save_Service::read_consistent( $this->submission, static fn() => array( 'grade' => 'ok' ) ), 'Libre: lee.' );
	}
}
