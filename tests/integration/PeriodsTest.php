<?php
/**
 * Integración: Períodos — base.
 */

declare( strict_types = 1 );

use ATORA\LMS\Tenant_Context;

require_once __DIR__ . '/LmsRowsCleanup.php';

final class PeriodsTest extends WP_UnitTestCase {
	use LmsRowsCleanup;

	private int $inst_a = 0;
	private int $inst_b = 0;
	private int $previous_default = 0;

	private int $admin_a = 0;
	private int $control_a = 0;
	private int $instructor_a = 0;
	private int $assistant_admin_a = 0;

	private int $student_a = 0;
	private int $student_b = 0;

	private int $wp_course_a1 = 0;
	private int $wp_course_a2 = 0;
	private int $wp_course_b1 = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( class_exists( 'CLMS_DB_Migration' ) ) {
			( new CLMS_DB_Migration() )->run();
		}
		if ( class_exists( 'ATORA_Periods_Service' ) ) {
			ATORA_Periods_Service::ensure_schema();
		}

		global $wpdb;
		$this->previous_default = (int) get_option( 'atora_default_institution', 0 );
		$this->remove_institutions();
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'periodos-a', 'name' => 'Inst A', 'status' => 'active' ) );
		$this->inst_a = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'periodos-b', 'name' => 'Inst B', 'status' => 'active' ) );
		$this->inst_b = (int) $wpdb->insert_id;
		update_option( 'atora_default_institution', $this->inst_a, false );

		$this->admin_a = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Admin A' ) );
		$this->control_a = self::factory()->user->create( array( 'role' => 'lms_academic_control', 'display_name' => 'Control A' ) );
		$this->instructor_a = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Instructor A' ) );
		$this->assistant_admin_a = self::factory()->user->create( array( 'role' => 'lms_admin_assistant', 'display_name' => 'Auxiliar A' ) );
		$this->student_a = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Alumno A' ) );
		$this->student_b = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Alumno B' ) );

		foreach ( array( $this->admin_a, $this->control_a, $this->instructor_a, $this->assistant_admin_a, $this->student_a ) as $u ) {
			$role = $u === $this->control_a ? 'teacher' : ( $u === $this->admin_a ? 'admin' : 'member' );
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_a, 'user_id' => $u, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $u, '_atora_active_institution', $this->inst_a );
		}
		$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_b, 'user_id' => $this->student_b, 'role' => 'member', 'status' => 'active' ) );
		update_user_meta( $this->student_b, '_atora_active_institution', $this->inst_b );

		$this->wp_course_a1 = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->instructor_a, 'post_title' => 'Curso A1' ) );
		$this->wp_course_a2 = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->instructor_a, 'post_title' => 'Curso A2' ) );
		$course_a1 = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course_a1 )['id'];
		$course_a2 = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course_a2 )['id'];
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student_a, $course_a1 );
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student_a, $course_a2 );
		CLMS_Helper::enroll_user_in_course( $this->student_a, $this->wp_course_a1 );
		CLMS_Helper::enroll_user_in_course( $this->student_a, $this->wp_course_a2 );

		update_option( 'atora_default_institution', $this->inst_b, false );
		$this->wp_course_b1 = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->admin_a, 'post_title' => 'Curso B1' ) );
		\ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course_b1 );
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $this->inst_b ), array( 'wp_post_id' => $this->wp_course_b1 ) );
		update_option( 'atora_default_institution', $this->inst_a, false );
		ATORA_Teacher_Scope::reset_cache();
		Tenant_Context::reset_cache();
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( array( 'atora_period_courses', 'atora_periods' ) as $t ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
		}
		$this->cleanup_lms_rows( array( $this->wp_course_a1, $this->wp_course_a2, $this->wp_course_b1 ), array( $this->student_a, $this->student_b ) );
		$this->remove_institutions();
		update_option( 'atora_default_institution', $this->previous_default, false );
		update_option( 'timezone_string', '' );
		Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		parent::tearDown();
	}

	private function remove_institutions(): void {
		global $wpdb;
		$ids = array_map( 'absint', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}atora_institutions WHERE slug IN ('periodos-a','periodos-b')" ) );
		if ( $ids ) {
			$in = implode( ',', $ids );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institution_members WHERE institution_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}
	}

	private function rest_me_courses( int $user_id ): array {
		wp_set_current_user( $user_id );
		Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );
		$req = new WP_REST_Request( 'GET', '/clms/v1/me/courses' );
		$res = rest_ensure_response( rest_get_server()->dispatch( $req ) );
		return (array) $res->get_data();
	}

	private function render_panel_my_courses( int $user_id ): string {
		wp_set_current_user( $user_id );
		Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return (string) do_shortcode( '[clms_my_courses]' );
	}

	public function test_only_atora_close_periods_can_close_and_only_their_institution(): void {
		wp_set_current_user( $this->admin_a );
		Tenant_Context::reset_cache();
		$period = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'P1', '2026-01-01', '2026-02-01' );
		$this->assertIsArray( $period );
		$period_id = absint( $period['id'] ?? 0 );
		$this->assertGreaterThan( 0, $period_id );

		$this->assertTrue( user_can( $this->admin_a, ATORA_Periods_Service::CAP_CLOSE_PERIODS ) );
		$this->assertTrue( user_can( $this->control_a, ATORA_Periods_Service::CAP_CLOSE_PERIODS ) );
		$this->assertFalse( user_can( $this->instructor_a, ATORA_Periods_Service::CAP_CLOSE_PERIODS ) );
		$this->assertFalse( user_can( $this->assistant_admin_a, ATORA_Periods_Service::CAP_CLOSE_PERIODS ) );

		$this->assertWPError( ATORA_Periods_Service::close_period( $this->instructor_a, $period_id, 'visible' ) );
		$this->assertWPError( ATORA_Periods_Service::close_period( $this->assistant_admin_a, $period_id, 'visible' ) );
		$this->assertIsArray( ATORA_Periods_Service::close_period( $this->control_a, $period_id, 'visible' ) );

		// Control académico en otra institución no puede cerrar.
		update_user_meta( $this->control_a, '_atora_active_institution', $this->inst_b );
		Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		$period2 = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'P2', '2026-03-01', '2026-04-01' );
		$pid2 = absint( is_array( $period2 ) ? ( $period2['id'] ?? 0 ) : 0 );
		$this->assertGreaterThan( 0, $pid2 );
		$this->assertWPError( ATORA_Periods_Service::close_period( $this->control_a, $pid2, 'visible' ) );
		update_user_meta( $this->control_a, '_atora_active_institution', $this->inst_a );
	}

	public function test_visibility_in_api_and_panel_and_period_order(): void {
		wp_set_current_user( $this->admin_a );
		Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();

		$p_old = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'Periodo viejo', '2026-01-01', '2026-02-01' );
		$p_new = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'Periodo nuevo', '2026-03-01', '2026-04-01' );
		$pid_old = absint( is_array( $p_old ) ? ( $p_old['id'] ?? 0 ) : 0 );
		$pid_new = absint( is_array( $p_new ) ? ( $p_new['id'] ?? 0 ) : 0 );
		$this->assertGreaterThan( 0, $pid_old );
		$this->assertGreaterThan( 0, $pid_new );
		$this->assertTrue( (bool) ATORA_Periods_Service::assign_course( $this->admin_a, $pid_old, $this->wp_course_a1 ) );
		$this->assertTrue( (bool) ATORA_Periods_Service::assign_course( $this->admin_a, $pid_new, $this->wp_course_a2 ) );

		// Cierra el viejo como oculto.
		$this->assertIsArray( ATORA_Periods_Service::close_period( $this->admin_a, $pid_old, 'hidden' ) );
		// Cierra el nuevo como visible.
		$this->assertIsArray( ATORA_Periods_Service::close_period( $this->admin_a, $pid_new, 'visible' ) );

		$data = $this->rest_me_courses( $this->student_a );
		$this->assertSame( array(), $data['active'], 'Los dos cursos están en períodos cerrados.' );
		$this->assertNotEmpty( $data['past'] );
		$this->assertSame( $pid_new, (int) ( $data['past'][0]['period']['id'] ?? 0 ) );
		$this->assertSame( 'Periodo nuevo', (string) ( $data['past'][0]['period']['name'] ?? '' ) );
		$this->assertCount( 1, (array) ( $data['past'][0]['courses'] ?? array() ) );
		$this->assertSame( $this->wp_course_a2, (int) ( $data['past'][0]['courses'][0]['id'] ?? 0 ) );
		$this->assertArrayHasKey( 'cover_url', (array) $data['past'][0]['courses'][0] );
		$this->assertArrayHasKey( 'color', (array) $data['past'][0]['courses'][0] );

		// Periodo viejo (hidden) no aparece.
		$ids = array_map( static fn( $g ) => (int) ( $g['period']['id'] ?? 0 ), (array) $data['past'] );
		$this->assertFalse( in_array( $pid_old, $ids, true ) );

		$html = $this->render_panel_my_courses( $this->student_a );
		$this->assertStringContainsString( 'Curso A2', $html );
		$this->assertStringNotContainsString( 'Curso A1', $html );
	}

	public function test_visibility_until_before_and_after_date_in_api_and_panel(): void {
		wp_set_current_user( $this->admin_a );
		Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		$p = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'Periodo until', '2026-05-01', '2026-06-01' );
		$pid = absint( is_array( $p ) ? ( $p['id'] ?? 0 ) : 0 );
		$this->assertGreaterThan( 0, $pid );
		$this->assertTrue( (bool) ATORA_Periods_Service::assign_course( $this->admin_a, $pid, $this->wp_course_a1 ) );

		$future = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
		$this->assertIsArray( ATORA_Periods_Service::close_period( $this->admin_a, $pid, 'until', $future ) );
		$data = $this->rest_me_courses( $this->student_a );
		$this->assertNotEmpty( $data['past'] );
		$this->assertSame( $this->wp_course_a1, (int) ( $data['past'][0]['courses'][0]['id'] ?? 0 ) );
		$html = $this->render_panel_my_courses( $this->student_a );
		$this->assertStringContainsString( 'Curso A1', $html );

		// Fuerza visible_until en el pasado.
		global $wpdb;
		$past = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$wpdb->update( $wpdb->prefix . 'atora_periods', array( 'visible_until' => $past ), array( 'id' => $pid ) ); // phpcs:ignore WordPress.DB
		$data2 = $this->rest_me_courses( $this->student_a );
		$this->assertSame( array(), (array) $data2['past'] );
		$html2 = $this->render_panel_my_courses( $this->student_a );
		$this->assertStringNotContainsString( 'Curso A1', $html2 );
	}

	public function test_student_other_institution_sees_nothing(): void {
		// Alumno B está matriculado en curso A, pero su institución activa es B: no debe ver nada.
		$course_a1 = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course_a1 )['id'];
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student_b, $course_a1 );
		CLMS_Helper::enroll_user_in_course( $this->student_b, $this->wp_course_a1 );

		$data = $this->rest_me_courses( $this->student_b );
		$this->assertSame( array(), (array) $data['active'] );
		$this->assertSame( array(), (array) $data['past'] );
		$html = $this->render_panel_my_courses( $this->student_b );
		$this->assertStringNotContainsString( 'Curso A1', $html );
	}

	public function test_single_institution_install_without_membership_still_lists_courses(): void {
		// Un WordPress por institución: el estudiante no tiene membresía. Antes de los períodos veía sus cursos.
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'atora_institution_members', array( 'user_id' => $this->student_a ) );
		delete_user_meta( $this->student_a, '_atora_active_institution' );

		wp_set_current_user( $this->admin_a );
		$p   = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'Semestre 1', '2026-01-01', '2026-06-01' );
		$pid = absint( $p['id'] );
		ATORA_Periods_Service::assign_course( $this->admin_a, $pid, $this->wp_course_a1 );
		ATORA_Periods_Service::close_period( $this->admin_a, $pid, 'visible' );

		$data = $this->rest_me_courses( $this->student_a );
		$this->assertSame( array( $this->wp_course_a2 ), array_map( static fn( $c ) => (int) $c['id'], $data['active'] ) );
		$this->assertSame( $this->wp_course_a1, (int) $data['past'][0]['courses'][0]['id'] );
		$html = $this->render_panel_my_courses( $this->student_a );
		$this->assertStringContainsString( 'Curso A1', $html );
		$this->assertStringContainsString( 'Curso A2', $html );
	}

	public function test_api_keeps_courses_and_total_for_older_clients(): void {
		wp_set_current_user( $this->admin_a );
		$p   = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'Oculto', '2026-01-01', '2026-02-01' );
		$pid = absint( $p['id'] );
		ATORA_Periods_Service::assign_course( $this->admin_a, $pid, $this->wp_course_a1 );
		ATORA_Periods_Service::close_period( $this->admin_a, $pid, 'hidden' );

		$data = $this->rest_me_courses( $this->student_a );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( array( $this->wp_course_a2 ), array_map( static fn( $c ) => (int) $c['id'], $data['courses'] ) );
		$this->assertArrayHasKey( 'progress', $data['courses'][0] );
	}

	public function test_lms_manager_without_enrollments_sees_all_courses_in_panel(): void {
		$html = $this->render_panel_my_courses( $this->admin_a );
		$this->assertStringContainsString( 'Curso A1', $html );
		$this->assertStringContainsString( 'Curso B1', $html );
	}

	public function test_visible_until_uses_the_academy_date_not_utc(): void {
		wp_set_current_user( $this->admin_a );
		$p   = ATORA_Periods_Service::create_period( $this->admin_a, $this->inst_a, 'Hasta', '2026-01-01', '2026-02-01' );
		$pid = absint( $p['id'] );
		ATORA_Periods_Service::assign_course( $this->admin_a, $pid, $this->wp_course_a1 );

		// Zona elegida para que la fecha local difiera de la UTC a cualquier hora en que corra la prueba.
		if ( (int) gmdate( 'G' ) < 12 ) {
			update_option( 'timezone_string', 'Etc/GMT+12' ); // UTC−12: localmente aún es ayer.
			$until  = wp_date( 'Y-m-d' );                     // «hasta hoy» en la academia.
			$expect = true;
		} else {
			update_option( 'timezone_string', 'Pacific/Kiritimati' ); // UTC+14: localmente ya es mañana.
			$until  = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );   // «hasta ayer» en la academia.
			$expect = false;
		}
		$this->assertNotSame( gmdate( 'Y-m-d' ), wp_date( 'Y-m-d' ) );
		ATORA_Periods_Service::close_period( $this->admin_a, $pid, 'until', $until );
		$ids = array();
		foreach ( (array) $this->rest_me_courses( $this->student_a )['past'] as $group ) {
			foreach ( $group['courses'] as $course ) {
				$ids[] = (int) $course['id'];
			}
		}
		$this->assertSame( $expect, in_array( $this->wp_course_a1, $ids, true ) );
	}
}

