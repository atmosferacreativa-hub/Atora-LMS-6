<?php
/**
 * Integración 6.36.0: el estado académico (informes, gradebook, CRM, analíticas) y la API
 * docente / SpeedGrader (`ATORA_Student_Risk_Service`) dan el mismo nivel y los mismos motivos
 * para el mismo estudiante y curso.
 */

declare( strict_types = 1 );

final class AcademicRiskConsistencyTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_early_warning" );
	}

	private function missed( int $student, int $course, int $count ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_early_warning', array(
			'course_id'    => $course,
			'user_id'      => $student,
			'warning_type' => 'missed_submission',
			'data'         => wp_json_encode( array( 'count' => $count ) ),
			'severity'     => 70,
			'status'       => 'open',
		) );
	}

	private function assert_same_everywhere( int $student, int $course ): array {
		$status = (array) clms_core( 'CLMS_Academic_Status_Service' )->get_student_course_status( $student, $course );
		$web    = ATORA_Student_Risk_Service::for_student( $student, $course );
		$api    = ATORA_Student_Risk_Service::for_student( $student, $course, $status );

		$this->assertSame( ATORA_Academic_Risk_Rule::legacy_level( (string) $status['risk_level'] ), $web['level'], 'Estado académico y riesgo docente: mismo nivel.' );
		$this->assertSame( $status['risk_reasons'], $web['reasons'], 'Mismos motivos.' );
		$this->assertSame( $status['risk_reason_codes'], $web['codes'] );
		$this->assertSame( $web, $api, 'Con o sin el estado precalculado, el mismo resultado.' );
		return $status;
	}

	public function test_missed_submissions_count_in_academic_status_too(): void {
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		update_user_meta( $student, CLMS_Student_Activity_Tracker::USER_META_LAST_ACCESS, current_time( 'mysql' ) );

		$status = $this->assert_same_everywhere( $student, $course );
		$this->assertSame( 'normal', $status['risk_level'] );

		// Antes de 6.36.0 las entregas vencidas solo subían el riesgo en la API docente.
		$this->missed( $student, $course, 2 );
		$status = $this->assert_same_everywhere( $student, $course );
		$this->assertSame( 'high', $status['risk_level'] );
		$this->assertContains( 'missed_submissions', $status['risk_reason_codes'] );
	}

	public function test_inactivity_is_the_same_everywhere(): void {
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		update_user_meta( $student, CLMS_Student_Activity_Tracker::USER_META_LAST_ACCESS, wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );

		$status = $this->assert_same_everywhere( $student, $course );
		$this->assertContains( 'inactive', $status['risk_reason_codes'] );
	}

	public function test_app_requests_count_as_activity(): void {
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$old     = wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		update_user_meta( $student, CLMS_Student_Activity_Tracker::USER_META_LAST_ACCESS, $old );

		$token   = ATORA_Mobile_Token_Service::issue( $student, 'test' )['access_token'];
		$request = new WP_REST_Request( 'GET', '/atora-mobile/v1/me' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$this->assertTrue( ATORA_Mobile_REST_Controller::authorize( $request ) );

		$last = (string) get_user_meta( $student, CLMS_Student_Activity_Tracker::USER_META_LAST_ACCESS, true );
		$this->assertNotSame( $old, $last, 'Antes de 6.36.0 la app no registraba actividad.' );
		$this->assertLessThan( 120, abs( time() - (int) ATORA_Academic_Risk_Rule::local_mysql_to_ts( $last ) ) );

		// Con límite de escritura: una segunda petición inmediata no reescribe.
		update_user_meta( $student, CLMS_Student_Activity_Tracker::USER_META_LAST_ACCESS, $last );
		CLMS_Student_Activity_Tracker::record_app_access( $student );
		$this->assertSame( $last, (string) get_user_meta( $student, CLMS_Student_Activity_Tracker::USER_META_LAST_ACCESS, true ) );
	}
}
