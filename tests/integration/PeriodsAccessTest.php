<?php
/**
 * Integración 6.41.0: el cierre del período decide el acceso del estudiante.
 *
 * «Visible» deja repasar sin límite; «visible hasta» hasta esa fecha; «oculto» cierra el acceso (curso, lecciones,
 * API móvil y lista de la app). Docentes del curso y administración siguen entrando.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/LmsRowsCleanup.php';

use ATORA\LMS\Tenant_Context;

final class PeriodsAccessTest extends WP_UnitTestCase {

	use LmsRowsCleanup;

	private int $admin = 0;
	private int $teacher = 0;
	private int $student = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private int $lesson = 0;
	private int $period = 0;
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		CLMS_Access::add_roles_and_caps();
		ATORA_Periods_Service::ensure_schema();
		Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->teacher   = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->student   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher, 'post_title' => 'Curso del semestre' ) );
		$this->lesson    = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_author' => $this->teacher, 'meta_input' => array( '_clms_course_id' => $this->wp_course ) ) );
		$this->course    = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student, $this->course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );

		$inst         = ATORA_Teacher_Scope::course_institution( $this->wp_course );
		$this->period = $this->create_period( $inst );
		ATORA_Periods_Service::assign_course( $this->admin, $this->period, $this->wp_course );
		$this->token = ATORA_Mobile_Token_Service::issue( $this->student, 'test' )['access_token'];
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_period_courses" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_periods" );
		$this->cleanup_lms_rows( array( $this->wp_course ), array( $this->student ) );
		update_option( 'timezone_string', '' );
		parent::tearDown();
	}

	/** Crea el período directamente: en una instalación de una institución el admin puede no tener membresía. */
	private function create_period( int $inst ): int {
		global $wpdb;
		$wpdb->insert( ATORA_Periods_Service::table_periods(), array( 'institution_id' => $inst, 'name' => 'Semestre 2026-1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'open' ) );
		$id = (int) $wpdb->insert_id;
		$wpdb->insert( ATORA_Periods_Service::table_links(), array( 'institution_id' => $inst, 'period_id' => $id, 'wp_course_id' => $this->wp_course ) );
		return $id;
	}

	private function close( string $visibility, ?string $until = null ): void {
		global $wpdb;
		$wpdb->update( ATORA_Periods_Service::table_periods(), array( 'status' => 'closed', 'past_visibility' => $visibility, 'visible_until' => $until ), array( 'id' => $this->period ) );
		$this->reset_mobile_cache();
	}

	private function reset_mobile_cache(): void {
		$prop = new ReflectionProperty( 'ATORA_Mobile_REST_Controller', 'enrollment_index_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	private function mobile_courses(): array {
		$this->reset_mobile_cache();
		$request = new WP_REST_Request( 'GET', '/atora-mobile/v1/courses' );
		$request->set_header( 'Authorization', 'Bearer ' . $this->token );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		return (array) ( $data['items'] ?? $data );
	}

	private function student_has_access(): bool {
		$this->reset_mobile_cache();
		$web    = ATORA_Course_Access_Service::can_access( $this->student, $this->wp_course );
		$lesson = CLMS_Helper::user_can_access_lesson( $this->student, $this->lesson );
		$mobile = ATORA_Mobile_REST_Controller::authorize_course_id( $this->student, $this->course );
		$this->assertSame( $web, $lesson, 'Curso y lección dicen lo mismo.' );
		$this->assertSame( $web, true === $mobile, 'Web y API móvil dicen lo mismo.' );
		return $web;
	}

	public function test_open_period_keeps_access_and_the_app_gets_the_period(): void {
		$this->assertTrue( $this->student_has_access() );
		$items = $this->mobile_courses();
		$this->assertCount( 1, $items );
		$this->assertSame( 'Semestre 2026-1', $items[0]['period']['name'] );
		$this->assertSame( 'open', $items[0]['period']['status'] );
		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/i', (string) $items[0]['color'] );
	}

	public function test_closed_visible_lets_the_student_review(): void {
		$this->close( 'visible' );
		$this->assertTrue( $this->student_has_access() );
		$this->assertSame( 'closed', $this->mobile_courses()[0]['period']['status'] );
	}

	public function test_closed_hidden_closes_access_everywhere_for_the_student(): void {
		$this->close( 'hidden' );
		$this->assertFalse( $this->student_has_access() );
		$error = ATORA_Mobile_REST_Controller::authorize_course_id( $this->student, $this->course );
		$this->assertWPError( $error );
		$this->assertSame( 'atora_period_closed', $error->get_error_code() );
		$this->assertSame( array(), $this->mobile_courses(), 'Tampoco aparece en la lista de la app.' );
		$this->assertFalse( (bool) CLMS_Drip::get_availability_state( $this->student, $this->lesson )['available'] );
	}

	public function test_teacher_and_admin_keep_access_to_a_hidden_course(): void {
		$this->close( 'hidden' );
		$this->assertFalse( ATORA_Periods_Service::access_closed( $this->teacher, $this->wp_course ), 'Docente del curso.' );
		$this->assertFalse( ATORA_Periods_Service::access_closed( $this->admin, $this->wp_course ), 'Administración.' );
		$this->assertTrue( ATORA_Periods_Service::access_closed( $this->student, $this->wp_course ) );
	}

	public function test_visible_until_closes_access_after_the_academy_date(): void {
		update_option( 'timezone_string', 'America/Caracas' );
		$this->close( 'until', wp_date( 'Y-m-d' ) );
		$this->assertTrue( $this->student_has_access(), 'Hasta hoy: todavía entra.' );
		$this->close( 'until', wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		$this->assertFalse( $this->student_has_access(), 'Venció ayer: ya no entra.' );
	}
}
