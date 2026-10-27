<?php
/**
 * 6.36.0 — Regla canónica de riesgo académico (`ATORA_Academic_Risk_Rule`).
 *
 * @package ATORA_LMS\Tests\Academic
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Academic;

use PHPUnit\Framework\TestCase;

final class AcademicRiskRuleTest extends TestCase {

	private const NOW = 1800000000;

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../includes/academic/class-academic-risk-rule.php';
	}

	private function evaluate( array $signals ): array {
		$result = \ATORA_Academic_Risk_Rule::evaluate( $signals + array( 'now' => self::NOW ) );
		return array( $result['level'], array_map( static fn( $r ) => $r['code'], $result['reasons'] ) );
	}

	private function active_days_ago( int $days ): int {
		return self::NOW - $days * DAY_IN_SECONDS;
	}

	public function test_no_grades_is_not_risk_and_zero_is(): void {
		$this->assertSame( array( 'normal', array() ), $this->evaluate( array( 'average' => null, 'last_activity_ts' => $this->active_days_ago( 1 ) ) ) );
		$this->assertSame( array( 'high', array( 'low_average' ) ), $this->evaluate( array( 'average' => 0, 'last_activity_ts' => $this->active_days_ago( 1 ) ) ) );
	}

	public function test_average_thresholds_are_the_same_for_every_consumer(): void {
		// Antes: 55 era "alto" en la API docente y "normal" en el estado académico.
		$this->assertSame( array( 'high', array( 'low_average' ) ), $this->evaluate( array( 'average' => 49, 'last_activity_ts' => self::NOW ) ) );
		$this->assertSame( array( 'medium', array( 'attention_average' ) ), $this->evaluate( array( 'average' => 55, 'last_activity_ts' => self::NOW ) ) );
		$this->assertSame( array( 'medium', array( 'attention_average' ) ), $this->evaluate( array( 'average' => 69, 'last_activity_ts' => self::NOW ) ) );
		$this->assertSame( array( 'normal', array() ), $this->evaluate( array( 'average' => 70, 'last_activity_ts' => self::NOW ) ) );
	}

	public function test_missed_and_pending(): void {
		$this->assertSame( array( 'medium', array( 'missed_submissions' ) ), $this->evaluate( array( 'missed' => 1, 'last_activity_ts' => self::NOW ) ) );
		$this->assertSame( array( 'high', array( 'missed_submissions' ) ), $this->evaluate( array( 'missed' => 2, 'last_activity_ts' => self::NOW ) ) );
		$this->assertSame( array( 'medium', array( 'pending_activities' ) ), $this->evaluate( array( 'pending' => 3, 'last_activity_ts' => self::NOW ) ) );
		$this->assertSame( array( 'normal', array() ), $this->evaluate( array( 'pending' => 2, 'last_activity_ts' => self::NOW ) ) );
	}

	public function test_new_enrollment_is_not_risk(): void {
		// Antes: progreso 0 % y sin accesos el primer día ya daban riesgo.
		$this->assertSame(
			array( 'normal', array() ),
			$this->evaluate( array( 'progress' => 0, 'total_lessons' => 8, 'enrolled_days' => 2, 'last_activity_ts' => null ) )
		);
	}

	public function test_low_progress_counts_after_grace_period_only(): void {
		$signals = array( 'progress' => 10, 'total_lessons' => 8, 'last_activity_ts' => self::NOW );
		$this->assertSame( array( 'normal', array() ), $this->evaluate( $signals + array( 'enrolled_days' => 13 ) ) );
		$this->assertSame( array( 'medium', array( 'low_progress' ) ), $this->evaluate( $signals + array( 'enrolled_days' => 14 ) ) );
		$this->assertSame( array( 'normal', array() ), $this->evaluate( array( 'progress' => 0, 'total_lessons' => 0, 'enrolled_days' => 60, 'last_activity_ts' => self::NOW ) ), 'Sin lecciones el progreso no cuenta.' );
	}

	public function test_inactivity(): void {
		$this->assertSame( array( 'normal', array() ), $this->evaluate( array( 'last_activity_ts' => $this->active_days_ago( 13 ) ) ) );
		$this->assertSame( array( 'medium', array( 'inactive' ) ), $this->evaluate( array( 'last_activity_ts' => $this->active_days_ago( 14 ) ) ) );
		$this->assertSame(
			array( 'high', array( 'inactive' ) ),
			$this->evaluate( array( 'pending' => 2, 'last_activity_ts' => $this->active_days_ago( 20 ) ) ),
			'Inactivo con pendientes: alto.'
		);
	}

	public function test_never_active_counts_only_with_known_enrollment_past_grace(): void {
		$this->assertSame( array( 'medium', array( 'inactive' ) ), $this->evaluate( array( 'enrolled_days' => 30, 'last_activity_ts' => null ) ) );
		$this->assertSame( array( 'normal', array() ), $this->evaluate( array( 'enrolled_days' => null, 'last_activity_ts' => null ) ), 'Sin datos de inscripción no se castiga.' );
	}

	public function test_inactivity_reason_explains_offline_sync(): void {
		$result = \ATORA_Academic_Risk_Rule::evaluate( array( 'now' => self::NOW, 'last_activity_ts' => $this->active_days_ago( 20 ) ) );
		$this->assertStringContainsString( '20 días', $result['reasons'][0]['text'] );
		$this->assertStringContainsString( 'sin conexión', $result['reasons'][0]['text'] );
	}

	public function test_legacy_levels(): void {
		$this->assertSame( 'alto', \ATORA_Academic_Risk_Rule::legacy_level( 'high' ) );
		$this->assertSame( 'medio', \ATORA_Academic_Risk_Rule::legacy_level( 'medium' ) );
		$this->assertSame( 'bajo', \ATORA_Academic_Risk_Rule::legacy_level( 'normal' ) );
	}
}
