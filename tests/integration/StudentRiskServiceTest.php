<?php
/**
 * Integración 6.31.0: `ATORA_Student_Risk_Service` combina early-warning con el
 * resumen de notas y explica el nivel con motivos legibles.
 */

declare( strict_types = 1 );

final class StudentRiskServiceTest extends WP_UnitTestCase {

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

	public function test_rule_levels_and_reasons(): void {
		$this->assertSame( array( 'bajo', array() ), $this->pick( ATORA_Student_Risk_Service::evaluate( 0, null, 0 ) ), 'Sin notas no es riesgo.' );
		$this->assertSame( array( 'bajo', array() ), $this->pick( ATORA_Student_Risk_Service::evaluate( 0, 85, 1 ) ) );
		$this->assertSame( array( 'medio', array( '1 entrega vencida' ) ), $this->pick( ATORA_Student_Risk_Service::evaluate( 1, 80, 0 ) ) );
		$this->assertSame( array( 'medio', array( 'nota acumulada 65/100' ) ), $this->pick( ATORA_Student_Risk_Service::evaluate( 0, 65, 0 ) ) );
		$this->assertSame( array( 'alto', array( '2 entregas vencidas', 'nota acumulada 48/100' ) ), $this->pick( ATORA_Student_Risk_Service::evaluate( 2, 48, 0 ) ) );
		$this->assertSame( array( 'alto', array( 'nota acumulada 0/100' ) ), $this->pick( ATORA_Student_Risk_Service::evaluate( 0, 0, 0 ) ), 'Un cero sí es riesgo.' );
		$this->assertSame( array( 'medio', array( '3 actividades pendientes' ) ), $this->pick( ATORA_Student_Risk_Service::evaluate( 0, null, 3 ) ) );
		$this->assertSame( 'Riesgo alto', ATORA_Student_Risk_Service::evaluate( 2, null, 0 )['label'] );
	}

	public function test_reads_open_early_warning_for_the_student_and_course(): void {
		global $wpdb;
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_early_warning', array(
			'course_id'    => $course,
			'user_id'      => $student,
			'warning_type' => 'missed_submission',
			'data'         => wp_json_encode( array( 'count' => 2, 'missed' => array( array( 'lesson_id' => 1 ), array( 'lesson_id' => 2 ) ) ) ),
			'severity'     => 70,
			'status'       => 'open',
		) );
		$risk = ATORA_Student_Risk_Service::for_student( $student, $course, array( 'final_average' => null, 'pending_activities' => 0 ) );
		$this->assertSame( 'alto', $risk['level'] );
		$this->assertSame( array( '2 entregas vencidas' ), $risk['reasons'] );

		$wpdb->update( $wpdb->prefix . 'atora_early_warning', array( 'status' => 'resolved' ), array( 'user_id' => $student ) );
		$this->assertSame( 'bajo', ATORA_Student_Risk_Service::for_student( $student, $course, array( 'final_average' => 90 ) )['level'], 'Una alerta resuelta ya no cuenta.' );
	}

	private function pick( array $risk ): array {
		return array( $risk['level'], $risk['reasons'] );
	}
}
