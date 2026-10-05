<?php
/**
 * Integración 6.29.5: una nota de cero no es "sin notas".
 * Antes, el promedio decidía qué combinar con `> 0`: quiz 0 + tarea 100 daba 100.
 */

declare( strict_types = 1 );

final class GradeZeroAverageTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $course = 0;
	private int $quiz_lesson = 0;
	private int $task_lesson = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::bump_version( 'gradebook' );
		}
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso ceros' ) );
		$this->quiz_lesson = $this->lesson( 'Quiz', 'quiz' );
		$this->task_lesson = $this->lesson( 'Tarea', 'tarea' );
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
			CLMS_Helper::enroll_user_in_course( $this->student, $this->course );
		}
	}

	private function lesson( string $title, string $type ): int {
		return self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => $title,
			'meta_input'  => array( '_clms_course_id' => $this->course, 'lm_activity_type' => $type ),
		) );
	}

	private function quiz( int $score ): void {
		update_user_meta( $this->student, 'clms_quiz_attempt_' . $this->quiz_lesson, array( 'score' => $score, 'best_score' => $score ) );
	}

	private function task( int $grade ): void {
		$id = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student ) );
		update_post_meta( $id, '_clms_submission_user_id', $this->student );
		update_post_meta( $id, '_clms_submission_lesson_id', $this->task_lesson );
		update_post_meta( $id, '_clms_submission_course_id', $this->course );
		update_post_meta( $id, '_clms_submission_status', 'graded' );
		update_post_meta( $id, '_clms_submission_grade', $grade );
	}

	/** @return array{engine:mixed, fallback:mixed} */
	private function finals(): array {
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::bump_version( 'gradebook' );
		}
		$engine = clms_core( 'CLMS_Assessment_Engine' )->build_course_gradebook( $this->student, $this->course )['summary'];

		// Cálculo propio de CLMS_Grading (cuando no hay motor de evaluación).
		$grading  = new CLMS_Grading();
		$fallback = new ReflectionMethod( $grading, 'build_summary_from_lessons' );
		$fallback->setAccessible( true );
		$own = $fallback->invoke( $grading, $this->student, $this->course );

		return array( 'engine' => $engine['final_average'], 'fallback' => $own['final_average'] );
	}

	public function test_quiz_zero_and_task_hundred_average_fifty(): void {
		$this->quiz( 0 );
		$this->task( 100 );
		$this->assertSame( array( 'engine' => 50, 'fallback' => 50 ), $this->finals() );
	}

	public function test_only_quizzes_at_zero_is_zero_not_ungraded(): void {
		$this->quiz( 0 );
		$this->assertSame( array( 'engine' => 0, 'fallback' => 0 ), $this->finals() );
	}

	public function test_no_grades_is_null(): void {
		$this->assertSame( array( 'engine' => null, 'fallback' => null ), $this->finals() );
	}

	public function test_unattempted_quiz_does_not_count_as_zero(): void {
		$this->task( 80 );
		$this->assertSame( array( 'engine' => 80, 'fallback' => 80 ), $this->finals() );
	}

	public function test_mobile_grades_report_null_and_zero_apart(): void {
		$wp_course = $this->course;
		$summary   = CLMS_Student_Grades_Service::course_summary( $this->student, $wp_course );
		$this->assertNull( $summary['final_grade'], 'Sin notas: null.' );

		$this->quiz( 0 );
		// bump_version() usa time(): dentro del mismo segundo no invalida; se borra la caché del estudiante.
		clms_core( 'CLMS_Assessment_Engine' )->invalidate_cache_for_user_course( $this->student, $wp_course );
		CLMS_Cache::delete( 'gradebook', array( 'summary', $this->student, $wp_course ) );
		$summary = CLMS_Student_Grades_Service::course_summary( $this->student, $wp_course );
		$this->assertSame( 0.0, $summary['final_grade'], 'Nota cero: 0, no "sin notas".' );
	}

	public function test_zero_average_is_high_risk_and_no_grades_is_not(): void {
		$status = clms_core( 'CLMS_Academic_Status_Service' );
		$risk   = new ReflectionMethod( $status, 'get_risk_level' );
		$risk->setAccessible( true );
		$this->assertSame( 'high', $risk->invoke( $status, array( 'final_average' => 0, 'progress_percent' => 80 ), 0 ) );
		$this->assertNotSame( 'high', $risk->invoke( $status, array( 'final_average' => null, 'progress_percent' => 80 ), 0 ) );

		$grading = new CLMS_Grading();
		$course_risk = new ReflectionMethod( $grading, 'get_course_risk_level' );
		$course_risk->setAccessible( true );
		$this->assertNotSame( 'en_riesgo', $course_risk->invoke( $grading, array( 'final_average' => null, 'progress_percent' => 80 ), 0 ), 'Sin notas no es "en riesgo".' );
		$this->assertSame( 'en_riesgo', $course_risk->invoke( $grading, array( 'final_average' => 0, 'progress_percent' => 80 ), 0 ) );
	}
}
