<?php
/**
 * Integración 6.29.4: SpeedGrader muestra y reenvía el puntaje de rúbrica con decimales.
 * Antes, reabrir una entrega con 3,5 mostraba 3 (absint) y al guardar sin cambios la nota bajaba.
 */

declare( strict_types = 1 );

final class SpeedGraderDecimalScoreTest extends WP_UnitTestCase {

	private int $admin = 0;
	private int $submission = 0;
	private int $rubric = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->rubric = self::factory()->post->create( array( 'post_type' => 'clms_rubric', 'post_status' => 'publish', 'post_title' => 'Rúbrica 4' ) );
		update_post_meta( $this->rubric, '_clms_rubric_criteria', array(
			array(
				'name'       => 'Claridad',
				'max_points' => 4,
				'weight'     => 100,
				'levels'     => array(
					array( 'label' => 'Suficiente', 'points' => 3 ),
					array( 'label' => 'Bueno', 'points' => 4 ),
				),
			),
		) );
		$lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_title' => 'Tarea' ) );
		update_post_meta( $lesson, '_clms_rubric_id', $this->rubric );

		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $student ) );
		update_post_meta( $this->submission, '_clms_submission_lesson_id', $lesson );
		update_post_meta( $this->submission, '_clms_submission_user_id', $student );
		update_post_meta( $this->submission, '_clms_submission_status', 'graded' );
		update_post_meta( $this->submission, '_clms_submission_rubric_scores', array( array( 'score' => 3.5, 'feedback' => 'Clara' ) ) );
	}

	private function panel(): string {
		return CLMS_Rubric_Panel_Renderer::render( array(
			'rubric_id'     => $this->rubric,
			'rubric_scores' => (array) get_post_meta( $this->submission, '_clms_submission_rubric_scores', true ),
		) );
	}

	private function field_value( string $html, string $name ): string {
		$this->assertMatchesRegularExpression( '/name="' . preg_quote( $name, '/' ) . '"/', $html, "Campo {$name} en el panel." );
		preg_match( '/name="' . preg_quote( $name, '/' ) . '"[^>]*?value="([^"]*)"/s', $html, $m );
		return html_entity_decode( (string) ( $m[1] ?? '' ) );
	}

	public function test_panel_renders_saved_decimal_and_its_band(): void {
		$html = $this->panel();
		$this->assertSame( '3.5', $this->field_value( $html, 'rubric_scores[0]' ) );
		$this->assertMatchesRegularExpression( '/clms-sg-rubric-level-btn is-active"\s+data-points="3"/', $html, 'Entre 3 y 4 se resalta el nivel de abajo, como al escribir.' );
		$this->assertStringContainsString( 'entre Suficiente y Bueno', $html );
	}

	public function test_saving_the_panel_unchanged_keeps_the_decimal(): void {
		$html = $this->panel();
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $this->submission ),
			'expected_revision' => (string) ATORA_Grading_Save_Service::revision( $this->submission ), // 6.31.1: obligatoria
			'status'          => 'graded',
			'feedback'        => 'Comentario editado',
			'clms_sg_submit'  => 'save_draft',
			'rubric_scores'   => array( $this->field_value( $html, 'rubric_scores[0]' ) ),
			'rubric_feedback' => array( $this->field_value( $html, 'rubric_feedback[0]' ) ),
		);
		$grading = new CLMS_Grading();
		$save    = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$save->setAccessible( true );
		$out = $save->invoke( $grading, $this->submission, $this->admin );
		$this->assertNotInstanceOf( WP_Error::class, $out, is_wp_error( $out ) ? $out->get_error_message() : '' );

		$scores = (array) get_post_meta( $this->submission, '_clms_submission_rubric_scores', true );
		$this->assertEquals( 3.5, $scores[0]['score'] ?? null, 'Guardar sin cambios no baja 3,5 a 3.' );
		$this->assertSame( '3.5', $this->field_value( $this->panel(), 'rubric_scores[0]' ) );
	}
}
