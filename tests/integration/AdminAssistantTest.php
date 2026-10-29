<?php
/**
 * Integración 6.41.0: rol Auxiliar administrativo.
 *
 * - Ve nota final y estado de los estudiantes en solo lectura, con «no aprobado · debe repetir» (y el filtro).
 * - No corrige notas finales, no gestiona matrículas, no califica, no entra a la API del docente ni ve contenido.
 * - Tiene las capacidades de pedidos de la tienda (no la configuración).
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class AdminAssistantTest extends WP_UnitTestCase {

	private int $finance = 0;
	private int $passed = 0;
	private int $failed = 0;
	private int $wp_course = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		CLMS_Access::add_roles_and_caps();
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$teacher       = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->finance = self::factory()->user->create( array( 'role' => 'lms_admin_assistant' ) );
		$this->passed  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Aprobada' ) );
		$this->failed  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Reprobado' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $teacher ) );
		update_post_meta( $this->wp_course, '_clms_passing_grade', 70 );
		$lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_author' => $teacher, 'meta_input' => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea' ) ) );
		$course = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		foreach ( array( $this->passed => 90, $this->failed => 40 ) as $student => $grade ) {
			LMS_Enrollment_Service::enroll( $student, $course );
			CLMS_Helper::enroll_user_in_course( $student, $this->wp_course );
			$sub = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $student ) );
			foreach ( array( '_clms_submission_user_id' => $student, '_clms_submission_lesson_id' => $lesson, '_clms_submission_course_id' => $this->wp_course, '_clms_submission_status' => 'graded', '_clms_submission_grade' => $grade ) as $key => $value ) {
				update_post_meta( $sub, $key, $value );
			}
			update_user_meta( $student, '_clms_completed_lessons', array( $lesson ) );
			foreach ( LMS_Course_Service::get_lessons( $course ) as $row ) {
				LMS_Enrollment_Service::complete_lesson( $student, (int) $row['id'] );
			}
			foreach ( array( 'CLMS_Assessment_Engine', 'CLMS_Grading' ) as $service ) {
				$object = clms_core( $service );
				if ( $object && method_exists( $object, 'invalidate_cache_for_user_course' ) ) {
					$object->invalidate_cache_for_user_course( $student, $this->wp_course );
				}
			}
		}
	}

	public function test_reads_final_grades_and_who_must_repeat(): void {
		$rows = ATORA_Finance_Academic_Admin::rows( $this->finance, $this->wp_course );
		$by   = array_column( $rows, null, 'name' );
		$this->assertSame( 90.0, $by['Aprobada']['final_grade'] );
		$this->assertSame( 40.0, $by['Reprobado']['final_grade'] );
		$this->assertSame( 100, CLMS_Student_Grades_Service::course_summary( $this->failed, $this->wp_course )['progress'] );
		$this->assertSame( 'approved', $by['Aprobada']['status'] );
		$this->assertSame( 'not_approved', $by['Reprobado']['status'] );
		$this->assertSame( array( 'Reprobado' ), array_column( ATORA_Finance_Academic_Admin::rows( $this->finance, $this->wp_course, true ), 'name' ), 'Filtro: solo quienes deben repetir.' );
		$this->assertSame( 'No aprobado · debe repetir', ATORA_Finance_Academic_Admin::label( 'not_approved' ) );
	}

	public function test_cannot_change_grades_enrollments_or_content(): void {
		$this->assertWPError( ATORA_Final_Grade_Service::adjust( $this->finance, $this->failed, $this->wp_course, 70, 'Pago al día' ) );
		$this->assertFalse( ATORA_Final_Grade_Service::can_adjust( $this->finance, $this->wp_course ) );
		wp_set_current_user( $this->finance );
		$this->assertFalse( CLMS_Access::can_manage_course_context( $this->wp_course ), 'No gestiona matrículas.' );
		$this->assertFalse( user_can( $this->finance, 'clms_grade_submissions' ) );
		$this->assertFalse( user_can( $this->finance, 'edit_lm_lessons' ) );
		$this->assertFalse( user_can( $this->finance, 'manage_woocommerce' ), 'Sin configuración de la tienda.' );
		$this->assertTrue( user_can( $this->finance, 'edit_shop_orders' ), 'Pedidos y reembolsos.' );
		$this->assertArrayHasKey( 'lms_admin_assistant', CLMS_Access::filter_editable_roles( wp_roles()->roles ) );

		$token   = ATORA_Mobile_Token_Service::issue( $this->finance, 'test' )['access_token'];
		$request = new WP_REST_Request( 'GET', '/atora-mobile/v1/teacher/today' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		wp_set_current_user( 0 );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), 'Sin API del docente.' );
		$this->assertSame( array(), ATORA_Finance_Academic_Admin::rows( self::factory()->user->create( array( 'role' => 'lms_instructor' ) ), $this->wp_course ), 'Un instructor no usa esta página.' );
	}
}
