<?php
/**
 * Integración 6.30.1: un borrador de calificación no llega al estudiante.
 *
 * Antes, "Guardar borrador" en SpeedGrader disparaba `clms_submission_graded`:
 * el estudiante recibía un aviso y un mensaje con la nota, y en tareas grupales
 * la copia de cada integrante quedaba publicada (`graded`).
 */

declare( strict_types = 1 );

final class GradeDraftNoLeakTest extends WP_UnitTestCase {

	private int $admin = 0;
	private int $student = 0;
	private int $course = 0;
	private int $lesson = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		global $wpdb;
		foreach ( array( 'atora_messages', 'atora_message_participants', 'atora_message_threads', 'clms_groups', 'clms_group_members', 'clms_group_submissions', 'clms_group_grade_overrides' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}

		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso borrador' ) );
		$this->lesson  = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => 'Tarea borrador',
			'meta_input'  => array( '_clms_course_id' => $this->course, 'lm_activity_type' => 'tarea' ),
		) );
		if ( method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
			CLMS_Helper::enroll_user_in_course( $this->student, $this->course );
		}
		wp_set_current_user( $this->admin );
	}

	private function submission( int $student_id ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $student_id ?: $this->admin ) );
		update_post_meta( $id, '_clms_submission_user_id', $student_id );
		update_post_meta( $id, '_clms_submission_lesson_id', $this->lesson );
		update_post_meta( $id, '_clms_submission_course_id', $this->course );
		update_post_meta( $id, '_clms_submission_status', 'submitted' );
		return $id;
	}

	/** Guarda por SpeedGrader web, igual que el formulario. */
	private function speedgrade( int $submission_id, string $action, string $grade ) {
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $submission_id ),
			'expected_revision' => (string) ATORA_Grading_Save_Service::revision( $submission_id ), // 6.31.1: obligatoria
			'status'         => 'in_review',
			'feedback'       => 'Buen trabajo',
			'clms_sg_submit' => $action,
			'grade'          => $grade,
		);
		$grading = new CLMS_Grading();
		$save    = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$save->setAccessible( true );
		$out = $save->invoke( $grading, $submission_id, $this->admin );
		$_POST = array();
		return $out;
	}

	/** Avisos y mensajes del estudiante sobre una entrega (deja fuera, p. ej., el de matrícula). */
	private function inbox( int $user_id ): array {
		return array_values( array_filter(
			ATORA_Inbox_Store::received( $user_id ),
			static fn( $row ) => (int) ( $row['submission_id'] ?? 0 ) > 0
		) );
	}

	private function average( int $user_id ) {
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::bump_version( 'gradebook' );
		}
		return clms_core( 'CLMS_Assessment_Engine' )->build_course_gradebook( $user_id, $this->course )['summary']['final_average'] ?? null;
	}

	public function test_draft_reaches_nobody_and_publish_does(): void {
		$graded = 0;
		$drafts = 0;
		add_action( 'clms_submission_graded', static function () use ( &$graded ) { ++$graded; } );
		add_action( 'clms_submission_grade_draft_saved', static function () use ( &$drafts ) { ++$drafts; } );

		$submission_id = $this->submission( $this->student );
		$before        = $this->average( $this->student );

		$out = $this->speedgrade( $submission_id, 'save_draft', '85' );
		$this->assertIsArray( $out );
		$this->assertSame( 'in_review', get_post_meta( $submission_id, '_clms_submission_status', true ) );
		$this->assertSame( array(), $this->inbox( $this->student ), 'Ni aviso ni mensaje con la nota del borrador.' );
		$this->assertSame( $before, $this->average( $this->student ), 'El borrador no cambia el promedio.' );
		$this->assertSame( 0, $graded, 'Un borrador no dispara clms_submission_graded.' );
		$this->assertSame( 1, $drafts, 'Un borrador dispara clms_submission_grade_draft_saved.' );

		$this->speedgrade( $submission_id, 'publish', '85' );
		$this->assertSame( 'graded', get_post_meta( $submission_id, '_clms_submission_status', true ) );
		$this->assertSame( 1, $graded );
		$this->assertNotSame( array(), $this->inbox( $this->student ), 'Al publicar el estudiante recibe el aviso.' );
		$this->assertEquals( 85, $this->average( $this->student ) );
	}

	public function test_group_copies_inherit_draft_state_rubric_and_overrides(): void {
		$other   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$service = new \ATORA\Groups\Group_Service();
		$group   = $service->create_group( $this->course, 'Grupo A', $this->admin );
		$this->assertGreaterThan( 0, $group );
		$service->set_members( $group, array( $this->student, $other ), $this->admin );
		$service->set_override( $group, $this->lesson, $other, 60, 'Aportó menos', $this->admin );

		$master = $this->submission( 0 );
		update_post_meta( $master, '_clms_submission_group_master', '1' );
		update_post_meta( $master, '_clms_submission_group_id', $group );
		update_post_meta( $master, '_clms_submission_submitted_by', $this->student );
		do_action( 'atora/groups/master_submission_saved', $group, $master, $this->lesson, $this->course, $this->student );

		$rubric = array( array( 'name' => 'C1', 'max_points' => 10, 'score' => 8.5, 'feedback' => 'Bien' ) );
		$engine = clms_core( 'CLMS_Assessment_Engine' );

		$engine->publish_submission_grade( $master, array( 'grade' => 85, 'status' => 'in_review', 'feedback' => 'Borrador', 'rubric_scores' => $rubric ) );
		foreach ( array( $this->student, $other ) as $member ) {
			$shadow = $service->find_shadow_submission_id( $member, $this->lesson, $group, $master );
			$this->assertGreaterThan( 0, $shadow );
			$this->assertSame( 'in_review', get_post_meta( $shadow, '_clms_submission_status', true ), 'La copia hereda el borrador.' );
			$this->assertSame( array(), $this->inbox( $member ), 'Ningún integrante recibe la nota del borrador.' );
		}

		$engine->publish_submission_grade( $master, array( 'grade' => 85, 'status' => 'graded', 'feedback' => 'Publicada', 'rubric_scores' => $rubric ) );
		$expected = array( $this->student => 85, $other => 60 );
		foreach ( $expected as $member => $grade ) {
			$shadow = $service->find_shadow_submission_id( $member, $this->lesson, $group, $master );
			$this->assertSame( 'graded', get_post_meta( $shadow, '_clms_submission_status', true ) );
			$this->assertEquals( $grade, get_post_meta( $shadow, '_clms_submission_grade', true ), 'Ajuste individual respetado.' );
			$this->assertEquals( 8.5, get_post_meta( $shadow, '_clms_submission_rubric_scores', true )[0]['score'] ?? null, 'La copia lleva los puntajes por criterio.' );
			$this->assertNotSame( array(), $this->inbox( $member ) );
		}
	}
}
