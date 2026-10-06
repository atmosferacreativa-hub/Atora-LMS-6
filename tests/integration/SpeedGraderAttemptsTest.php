<?php
/**
 * Integración 6.31.0: SpeedGrader muestra todos los intentos con su fecha (y la
 * del dispositivo si existe), permite ver cada uno y califica el elegido; por
 * defecto, el último.
 */

declare( strict_types = 1 );

final class SpeedGraderAttemptsTest extends WP_UnitTestCase {

	private int $admin = 0;
	private int $submission = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_assignment_submissions" );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$student     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$course      = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		$lesson      = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'meta_input' => array( '_clms_course_id' => $course ) ) );
		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $student ) );
		update_post_meta( $this->submission, '_clms_submission_user_id', $student );
		update_post_meta( $this->submission, '_clms_submission_lesson_id', $lesson );
		update_post_meta( $this->submission, '_clms_submission_course_id', $course );
		update_post_meta( $this->submission, '_clms_submission_status', 'submitted' );

		update_post_meta( $this->submission, '_clms_submission_comment', 'Texto del primer intento' );
		ATORA_Web_Submission_History::record( $this->submission, 'web-1', '2026-10-01 10:00:00' );
		update_post_meta( $this->submission, '_clms_submission_comment', 'Texto del segundo intento' );
		ATORA_Web_Submission_History::record( $this->submission, 'web-2', '2026-10-02 10:00:00' );
		wp_set_current_user( $this->admin );
	}

	private function context_html( ?int $attempt = null ): array {
		$_GET = null === $attempt ? array() : array( 'attempt' => $attempt );
		$grading = new CLMS_Grading();
		$context = $grading->get_submission_context( $this->submission, $this->admin );
		$render  = new ReflectionMethod( $grading, 'render_speedgrade_attempts' );
		$render->setAccessible( true );
		$html = (string) $render->invoke( $grading, $context );
		$_GET = array();
		return array( $context, $html );
	}

	public function test_lists_every_attempt_and_defaults_to_the_last(): void {
		list( $context, $html ) = $this->context_html();
		$this->assertCount( 2, $context['attempts'] );
		$this->assertSame( 2, $context['selected_attempt'] );
		$this->assertStringContainsString( 'Intento 1', $html );
		$this->assertStringContainsString( 'Intento 2', $html );
		$this->assertStringContainsString( 'Texto del segundo intento', $html );
		$this->assertStringNotContainsString( 'Texto del primer intento', $html );

		list( $context, $html ) = $this->context_html( 1 );
		$this->assertSame( 1, $context['selected_attempt'] );
		$this->assertStringContainsString( 'Texto del primer intento', $html, 'Se puede ver cada intento.' );
	}

	public function test_the_chosen_attempt_is_graded_and_audited(): void {
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $this->submission ),
			'clms_sg_submit' => 'publish',
			'grade'          => '77',
			'attempt'        => '1',
		);
		$grading = new CLMS_Grading();
		$save    = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$save->setAccessible( true );
		$out   = $save->invoke( $grading, $this->submission, $this->admin );
		$_POST = array();

		$this->assertSame( 1, $out['attempt'] );
		$this->assertSame( '1', get_post_meta( $this->submission, '_clms_submission_graded_attempt', true ) );
		list( $context ) = $this->context_html();
		$this->assertSame( 1, $context['selected_attempt'], 'Al volver se abre el intento calificado.' );
	}
}
