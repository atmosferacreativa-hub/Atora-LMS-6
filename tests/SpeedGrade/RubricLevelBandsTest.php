<?php
/**
 * SpeedGrade — banding/tooltip logic for rubric levels (umbral + etiquetas).
 *
 * @package ATORA_LMS\Tests\SpeedGrade
 */

declare( strict_types = 1 );

namespace ATORA\Tests\SpeedGrade;

use PHPUnit\Framework\TestCase;

final class RubricLevelBandsTest extends TestCase {

	/** @test */
	public function bands_follow_threshold_rule_and_between_labels(): void {
		require_once __DIR__ . '/../../includes/grading/renderers/class-rubric-panel-renderer.php';

		$levels = array(
			array( 'label' => 'Inicial', 'points' => 2 ),
			array( 'label' => 'En desarrollo', 'points' => 5 ),
			array( 'label' => 'Competente', 'points' => 8 ),
			array( 'label' => 'Excelente', 'points' => 10 ),
		);
		$bands = \CLMS_Rubric_Panel_Renderer::build_level_bands( $levels, 10 );

		$this->assertSame( '≤2 Inicial · 3–5 En desarrollo · 6–8 Competente · 9–10 Excelente', \CLMS_Rubric_Panel_Renderer::levels_to_tooltip( $levels, 10 ) );

		$this->assertSame( array( null, 'por debajo de Inicial' ), $this->pick( $bands, 1 ) );
		$this->assertSame( array( 2.0, '' ), $this->pick( $bands, 2 ) );
		$this->assertSame( array( 2.0, 'entre Inicial y En desarrollo' ), $this->pick( $bands, 3 ) );
		$this->assertSame( array( 5.0, '' ), $this->pick( $bands, 5 ) );
		$this->assertSame( array( 5.0, 'entre En desarrollo y Competente' ), $this->pick( $bands, 7 ) );
		$this->assertSame( array( 8.0, '' ), $this->pick( $bands, 8 ) );
		$this->assertSame( array( 8.0, 'entre Competente y Excelente' ), $this->pick( $bands, 9 ) );
		$this->assertSame( array( 10.0, '' ), $this->pick( $bands, 10 ) );
	}

	/** @test */
	public function bands_sort_levels_by_points_not_schema_order_and_keep_first_on_ties(): void {
		require_once __DIR__ . '/../../includes/grading/renderers/class-rubric-panel-renderer.php';

		$levels = array(
			array( 'label' => 'B', 'points' => 5 ),
			array( 'label' => 'A', 'points' => 2 ),
			array( 'label' => 'B-dup', 'points' => 5 ),
			array( 'label' => 'C', 'points' => 8 ),
		);
		$bands = \CLMS_Rubric_Panel_Renderer::build_level_bands( $levels, 10 );

		$this->assertSame( array( 5.0, '' ), $this->pick( $bands, 5 ) );
	}

	/**
	 * @param array<int,array{min:float,max:float,label:string,active_points:mixed,between:string,below:string}> $bands
	 * @param float $value
	 * @return array{0:float|null,1:string}
	 */
	private function pick( array $bands, float $value ): array {
		foreach ( $bands as $b ) {
			if ( $value >= (float) $b['min'] && $value <= (float) $b['max'] ) {
				$ap = $b['active_points'];
				$hint = (string) ( $b['below'] ?? '' );
				if ( '' === $hint ) {
					$hint = (string) ( $b['between'] ?? '' );
				}
				if ( is_numeric( $ap ) && abs( (float) $ap - $value ) < 0.0001 ) {
					$hint = '';
				}
				return array( is_numeric( $ap ) ? (float) $ap : null, $hint );
			}
		}
		return array( null, '' );
	}
}
