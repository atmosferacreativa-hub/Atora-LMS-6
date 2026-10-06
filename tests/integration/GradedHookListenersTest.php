<?php
/**
 * Integración 6.30.2: los listeners de `clms_submission_graded` leen sus
 * argumentos en el orden real del hook: (submission_id, student_id, status,
 * grade, feedback).
 *
 * Antes, la caché del panel y la del motor de evaluación tomaban el id del
 * estudiante como lección y el estado como usuario (no se borraba nada), y la
 * analítica registraba lección y usuario cruzados.
 */

declare( strict_types = 1 );

final class GradedHookListenersTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $course = 0;
	private int $lesson = 0;
	private int $submission = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		delete_option( CLMS_Analytics::OPTION_EVENT_LOG );

		$this->student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		$this->lesson  = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'meta_input'  => array( '_clms_course_id' => $this->course, 'lm_activity_type' => 'tarea' ),
		) );
		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student ) );
		update_post_meta( $this->submission, '_clms_submission_user_id', $this->student );
		update_post_meta( $this->submission, '_clms_submission_lesson_id', $this->lesson );
		update_post_meta( $this->submission, '_clms_submission_course_id', $this->course );
		update_post_meta( $this->submission, '_clms_submission_status', 'graded' );
		update_post_meta( $this->submission, '_clms_submission_grade', 80 );
	}

	private function graded(): void {
		do_action( 'clms_submission_graded', $this->submission, $this->student, 'graded', 80, 'Bien' );
	}

	public function test_dashboard_cache_of_the_student_is_cleared(): void {
		CLMS_Cache::set( 'dashboard', array( $this->student ), array( 'stale' => true ), HOUR_IN_SECONDS );
		$this->graded();
		$this->assertNull( CLMS_Cache::get( 'dashboard', array( $this->student ) ), 'El panel del estudiante se recalcula tras la nota.' );
	}

	public function test_gradebook_cache_of_the_student_course_is_cleared(): void {
		CLMS_Cache::set( 'gradebook', array( 'entries', $this->student, $this->course ), array( 'stale' => true ), HOUR_IN_SECONDS );
		$this->graded();
		$this->assertNull( CLMS_Cache::get( 'gradebook', array( 'entries', $this->student, $this->course ) ), 'El libro de notas del curso se recalcula tras la nota.' );
	}

	public function test_analytics_records_lesson_and_student_in_place(): void {
		$this->graded();
		$events = array_values( array_filter(
			(array) get_option( CLMS_Analytics::OPTION_EVENT_LOG, array() ),
			static fn( $event ) => 'submission_graded' === ( $event['type'] ?? '' )
		) );
		$this->assertNotEmpty( $events );
		$context = $events[0]['context'];
		$this->assertSame( $this->submission, (int) $context['submission_id'] );
		$this->assertSame( $this->lesson, (int) $context['lesson_id'] );
		$this->assertSame( $this->student, (int) $context['user_id'] );
		$this->assertSame( $this->course, (int) $context['course_id'] );
		$this->assertSame( 'graded', $context['status'] );
		$this->assertSame( 80, (int) $context['grade'] );
	}
}
