<?php
/**
 * Rubrics v2 — weight normalization.
 *
 * @package ATORA_LMS\Tests\Rubrics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Rubrics;

use PHPUnit\Framework\TestCase;

final class RubricWeightsNormalizationTest extends TestCase {

	/** @test */
	public function it_normalizes_weights_to_100(): void {
		require_once __DIR__ . '/../../includes/class-rubric.php';

		$r = new \CLMS_Rubric();
		$m = new \ReflectionMethod( $r, 'sanitize_criteria' );
		$m->setAccessible( true );

		$clean = $m->invoke( $r, array(
			array(
				'name'        => 'Contenido',
				'description' => '',
				'weight'      => 40,
				'max_points'  => 10,
				'levels'      => array(),
			),
			array(
				'name'        => 'Forma',
				'description' => '',
				'weight'      => 30,
				'max_points'  => 10,
				'levels'      => array(),
			),
			array(
				'name'        => 'Originalidad',
				'description' => '',
				'weight'      => 30,
				'max_points'  => 10,
				'levels'      => array(),
			),
		) );

		$this->assertCount( 3, $clean );
		$sum = array_sum( array_map( static fn( $row ) => (float) ( $row['weight'] ?? 0 ), $clean ) );
		$this->assertEqualsWithDelta( 100.0, $sum, 0.01 );
	}

	/** @test */
	public function it_sets_equal_weights_when_missing(): void {
		require_once __DIR__ . '/../../includes/class-rubric.php';

		$r = new \CLMS_Rubric();
		$m = new \ReflectionMethod( $r, 'sanitize_criteria' );
		$m->setAccessible( true );

		$clean = $m->invoke( $r, array(
			array(
				'name'       => 'A',
				'max_points' => 10,
				'levels'     => array(),
			),
			array(
				'name'       => 'B',
				'max_points' => 10,
				'levels'     => array(),
			),
		) );

		$this->assertCount( 2, $clean );
		$this->assertEqualsWithDelta( 50.0, (float) $clean[0]['weight'], 0.01 );
		$this->assertEqualsWithDelta( 50.0, (float) $clean[1]['weight'], 0.01 );
	}
}

