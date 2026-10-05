<?php
/**
 * 6.29.3 — lectura de un puntaje sobre las bandas de SpeedGrader (helper compartido).
 *
 * @package ATORA_LMS\Tests\SpeedGrade
 */

declare( strict_types = 1 );

namespace ATORA\Tests\SpeedGrade;

use PHPUnit\Framework\TestCase;

final class RubricLevelDescribeTest extends TestCase {

	private const LEVELS = array(
		array( 'label' => 'Suficiente', 'points' => 3 ),
		array( 'label' => 'Bueno', 'points' => 4 ),
	);

	protected function setUp(): void {
		require_once __DIR__ . '/../../includes/grading/renderers/class-rubric-panel-renderer.php';
	}

	/** @test */
	public function decimal_between_two_levels_reads_between_not_the_lower_level(): void {
		$this->assertSame( 'entre Suficiente y Bueno', \CLMS_Rubric_Level_Bands::level_for( self::LEVELS, 4, 3.5 ) );
	}

	/** @test */
	public function exact_score_reads_its_level_and_below_first_reads_below(): void {
		$this->assertSame( 'Suficiente', \CLMS_Rubric_Level_Bands::level_for( self::LEVELS, 4, 3.0 ) );
		$this->assertSame( 'Bueno', \CLMS_Rubric_Level_Bands::level_for( self::LEVELS, 4, 4.0 ) );
		$this->assertSame( 'por debajo de Suficiente', \CLMS_Rubric_Level_Bands::level_for( self::LEVELS, 4, 2.99 ) );
	}

	/** @test */
	public function same_bands_as_the_speedgrader_panel(): void {
		$this->assertSame(
			\CLMS_Rubric_Level_Bands::build( self::LEVELS, 4 ),
			\CLMS_Rubric_Panel_Renderer::build_level_bands( self::LEVELS, 4 )
		);
	}
}
