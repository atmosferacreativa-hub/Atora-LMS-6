<?php
/**
 * Integración 6.41.0: rol Control académico.
 *
 * - Corrige la nota final con motivo obligatorio; la ve el estudiante (resumen del curso) y queda historial con la
 *   anterior. Quitar la corrección vuelve a la calculada. Las parciales no cambian.
 * - No califica entregas, no edita lecciones, no entra a la API del docente. Un instructor no corrige notas finales.
 * - Con dos instituciones, solo actúa sobre cursos de la suya. Lee informes y gestiona matrículas de su institución.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/LmsRowsCleanup.php';

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class AcademicControlTest extends WP_UnitTestCase {

	use LmsRowsCleanup;

	private int $control = 0;
	private int $teacher = 0;
	private int $student = 0;
	private int $wp_course = 0;
	private int $wp_lesson = 0;
	private int $submission = 0;
	private array $extra = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		CLMS_Access::add_roles_and_caps();
		global $wpdb;
		foreach ( array( 'atora_final_grade_adjustments', 'atora_sections', 'atora_section_teachers', 'atora_section_students' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->control = self::factory()->user->create( array( 'role' => 'lms_academic_control', 'display_name' => 'Registro' ) );
		$this->teacher = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher ) );
		$this->wp_lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_author' => $this->teacher, 'meta_input' => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea' ) ) );
		$course = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		LMS_Enrollment_Service::enroll( $this->student, $course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );

		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student ) );
		foreach ( array( '_clms_submission_user_id' => $this->student, '_clms_submission_lesson_id' => $this->wp_lesson, '_clms_submission_course_id' => $this->wp_course, '_clms_submission_status' => 'graded', '_clms_submission_grade' => 60 ) as $key => $value ) {
			update_post_meta( $this->submission, $key, $value );
		}
		$this->flush();
	}

	protected function tearDown(): void {
		$this->cleanup_lms_rows( array_merge( array( $this->wp_course ), $this->extra ), array( $this->student ) );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_final_grade_adjustments" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug IN ('ctrl-a','ctrl-b')" );
		parent::tearDown();
	}

	private function flush(): void {
		foreach ( array( 'CLMS_Assessment_Engine', 'CLMS_Grading' ) as $service ) {
			$object = clms_core( $service );
			if ( $object && method_exists( $object, 'invalidate_cache_for_user_course' ) ) {
				$object->invalidate_cache_for_user_course( $this->student, $this->wp_course );
			}
		}
	}

	private function final_grade() {
		return CLMS_Student_Grades_Service::course_summary( $this->student, $this->wp_course );
	}

	public function test_corrects_final_grade_with_reason_and_history(): void {
		$this->assertSame( 60.0, $this->final_grade()['final_grade'], 'Calculada desde la entrega.' );

		$this->assertSame( 400, ATORA_Final_Grade_Service::adjust( $this->control, $this->student, $this->wp_course, 75, '' )->get_error_data()['status'], 'Motivo obligatorio.' );
		$this->assertSame( 400, ATORA_Final_Grade_Service::adjust( $this->control, $this->student, $this->wp_course, 120, 'Revisión de acta' )->get_error_data()['status'], 'Fuera de rango.' );
		$this->assertSame( 403, ATORA_Final_Grade_Service::adjust( $this->teacher, $this->student, $this->wp_course, 75, 'Revisión de acta' )->get_error_data()['status'], 'El instructor no corrige la nota final.' );

		$result = ATORA_Final_Grade_Service::adjust( $this->control, $this->student, $this->wp_course, 75, 'Revisión de acta 12' );
		$this->assertIsArray( $result );
		$this->assertSame( 75.0, $this->final_grade()['final_grade'], 'El estudiante ve la corregida.' );
		$this->assertTrue( $this->final_grade()['adjusted'] );
		$this->assertSame( 60.0, ATORA_Final_Grade_Service::calculated( $this->student, $this->wp_course ), 'La calculada no cambia.' );
		$this->assertSame( '60', (string) get_post_meta( $this->submission, '_clms_submission_grade', true ), 'La parcial no se toca.' );
		$this->assertSame( 60.0, $result['history'][0]['previous_grade'] );
		$this->assertSame( 'Registro', $result['history'][0]['by'] );

		ATORA_Final_Grade_Service::adjust( $this->control, $this->student, $this->wp_course, 80, 'Segunda revisión' );
		$this->assertSame( 75.0, ATORA_Final_Grade_Service::history( $this->student, $this->wp_course )[0]['previous_grade'], 'La anterior era la corregida.' );

		ATORA_Final_Grade_Service::adjust( $this->control, $this->student, $this->wp_course, null, 'Se anula la corrección' );
		$this->assertSame( 60.0, $this->final_grade()['final_grade'], 'Vuelve a la calculada.' );
		$this->assertFalse( $this->final_grade()['adjusted'] );
		$this->assertCount( 3, ATORA_Final_Grade_Service::history( $this->student, $this->wp_course ) );
		$this->assertSame( 409, ATORA_Final_Grade_Service::adjust( $this->control, $this->student, $this->wp_course, null, 'Nada que quitar' )->get_error_data()['status'] );
	}

	public function test_does_not_grade_nor_edit_content_and_has_no_teacher_api(): void {
		$this->assertFalse( ATORA_Teacher_Scope::can_grade_submission( $this->control, $this->submission ) );
		$this->assertFalse( user_can( $this->control, 'edit_lm_lessons' ) );
		$this->assertFalse( user_can( $this->control, 'clms_grade_submissions' ) );
		$this->assertArrayHasKey( 'lms_academic_control', CLMS_Access::filter_editable_roles( wp_roles()->roles ) );

		$token   = ATORA_Mobile_Token_Service::issue( $this->control, 'test' )['access_token'];
		$request = new WP_REST_Request( 'GET', '/atora-mobile/v1/teacher/today' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		wp_set_current_user( 0 );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_reads_reports_and_manages_enrollments_of_its_institution_only(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'ctrl-a', 'name' => 'A', 'status' => 'active' ) );
		$inst_a = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'ctrl-b', 'name' => 'B', 'status' => 'active' ) );
		$inst_b = (int) $wpdb->insert_id;
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $inst_a ), array( 'wp_post_id' => $this->wp_course ) );
		$other = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher ) );
		LMS_Course_Service::get_by_wp_post( $other );
		$this->extra[] = $other;
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $inst_b ), array( 'wp_post_id' => $other ) );
		$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst_a, 'user_id' => $this->control, 'role' => 'member', 'status' => 'active' ) );
		update_user_meta( $this->control, '_atora_active_institution', $inst_a );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();

		$this->assertTrue( ATORA_Teacher_Scope::views_institution( $this->control, $this->wp_course ) );
		$this->assertFalse( ATORA_Teacher_Scope::views_institution( $this->control, $other ), 'Otra institución.' );
		$this->assertTrue( ATORA_Final_Grade_Service::can_adjust( $this->control, $this->wp_course ) );
		$this->assertFalse( ATORA_Final_Grade_Service::can_adjust( $this->control, $other ) );

		wp_set_current_user( $this->control );
		$this->assertTrue( CLMS_Access::can_manage_course_context( $this->wp_course ) );
		$this->assertFalse( CLMS_Access::can_manage_course_context( $other ) );
		$listed = array_keys( ATORA_Final_Grade_Admin::courses( $this->control ) );
		$this->assertContains( $this->wp_course, $listed );
		$this->assertNotContains( $other, $listed, 'La página no lista cursos de otra institución.' );
		$wpdb->delete( $wpdb->prefix . 'atora_institution_members', array( 'user_id' => $this->control ) );
	}
}
