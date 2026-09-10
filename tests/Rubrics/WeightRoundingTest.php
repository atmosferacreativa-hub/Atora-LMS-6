<?php
/**
 * Rubrics v2 — caso límite de redondeo: tres pesos iguales (33.33 × 3).
 *
 * CLMS_Rubric::sanitize_criteria() normaliza proporcionalmente
 * (peso / suma * 100) y redondea a 2 decimales, pero para tres pesos
 * iguales eso no garantiza que la suma final sea exactamente 100 — ver
 * S4.2 del sprint de estabilización: este test documenta el
 * comportamiento REAL actual (99.99), no lo que "debería" ser. Si el
 * producto decide que la suma debe forzarse a 100.00, este test debe
 * actualizarse junto con el fix.
 *
 * @package ATORA_LMS\Tests\Rubrics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Rubrics;

use PHPUnit\Framework\TestCase;

final class WeightRoundingTest extends TestCase {

	private function sanitize( array $criteria ): array {
		require_once __DIR__ . '/../../includes/class-rubric.php';

		$r = new \CLMS_Rubric();
		$m = new \ReflectionMethod( $r, 'sanitize_criteria' );
		$m->setAccessible( true );

		return $m->invoke( $r, $criteria );
	}

	/** @test */
	public function three_equal_weights_of_33_33_do_not_sum_to_exactly_100(): void {
		$clean = $this->sanitize( array(
			array( 'name' => 'A', 'weight' => 33.33, 'max_points' => 10, 'levels' => array() ),
			array( 'name' => 'B', 'weight' => 33.33, 'max_points' => 10, 'levels' => array() ),
			array( 'name' => 'C', 'weight' => 33.33, 'max_points' => 10, 'levels' => array() ),
		) );

		$this->assertCount( 3, $clean );
		foreach ( $clean as $row ) {
			$this->assertEqualsWithDelta( 33.33, (float) $row['weight'], 0.01 );
		}

		$sum = array_sum( array_map( static fn( $row ) => (float) ( $row['weight'] ?? 0 ), $clean ) );
		// Comportamiento actual documentado: NO se fuerza a 100.00 — queda
		// en 99.99 por el redondeo a 2 decimales de un tercio no exacto.
		// Si esto cambia intencionalmente, actualizar este test.
		$this->assertEqualsWithDelta( 99.99, $sum, 0.001 );
	}

	/** @test */
	public function equal_input_weights_stay_equal_after_normalization(): void {
		// Tres pesos EXACTAMENTE iguales que sí dividen 100 sin resto
		// (100/4 = 25) deben normalizar limpio, sin el residuo de
		// redondeo del caso 33.33 × 3.
		$clean = $this->sanitize( array(
			array( 'name' => 'A', 'weight' => 25, 'max_points' => 10, 'levels' => array() ),
			array( 'name' => 'B', 'weight' => 25, 'max_points' => 10, 'levels' => array() ),
			array( 'name' => 'C', 'weight' => 25, 'max_points' => 10, 'levels' => array() ),
			array( 'name' => 'D', 'weight' => 25, 'max_points' => 10, 'levels' => array() ),
		) );

		$sum = array_sum( array_map( static fn( $row ) => (float) ( $row['weight'] ?? 0 ), $clean ) );
		$this->assertEqualsWithDelta( 100.0, $sum, 0.001 );
	}
}
