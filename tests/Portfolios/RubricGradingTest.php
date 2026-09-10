<?php
/**
 * Portfolios — Portfolios_Service::save_final_assessment(): normaliza
 * los puntajes por criterio (clamp 0..max_points), calcula el % sobre el
 * total de la rúbrica, y descarta puntajes de criterios inexistentes.
 *
 * @package ATORA_LMS\Tests\Portfolios
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Portfolios;

use PHPUnit\Framework\TestCase;

final class FakeWpdbPortfolioAssessment {
	public string $prefix = 'wp_';
	public array $insert_calls = array();
	public array $update_calls = array();

	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_var( $sql ) { return 1; } // la tabla "existe"
	public function get_row( $sql, $output = null ) { return null; }
	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		$this->update_calls[] = array( 'data' => $data, 'where' => $where );
		return 1;
	}
	public function insert( $table, $data, $format = null ): int {
		$this->insert_calls[] = $data;
		return 1;
	}
}

final class RubricGradingTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../includes/class-rubric.php';
		require_once __DIR__ . '/../../modules/portfolios/class-portfolios-service.php';

		atora_test_reset_post_meta();
		atora_test_reset_post_types();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbPortfolioAssessment();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	private function seed_rubric( int $rubric_id, array $criteria ): void {
		atora_test_set_post_type( $rubric_id, 'clms_rubric' );
		atora_test_set_post_meta( $rubric_id, '_clms_rubric_criteria', $criteria );
	}

	/** @test */
	public function scores_are_clamped_to_each_criterion_max_points(): void {
		$rubric_id = 10;
		$this->seed_rubric( $rubric_id, array(
			array( 'name' => 'Contenido', 'max_points' => 10 ),
			array( 'name' => 'Forma', 'max_points' => 10 ),
		) );

		$service = new \ATORA\Portfolios\Portfolios_Service();
		// Criterio 0: 15 (se pasa del máximo 10) -> clamp a 10.
		// Criterio 1: -3 no es válido (no numérico negativo permitido por diseño de clamp) -> pero es numérico, se clampa a 0.
		$service->save_final_assessment( 500, $rubric_id, array( '0' => 15, '1' => -3 ), 'ok', 1 );

		global $wpdb;
		$this->assertNotEmpty( $wpdb->insert_calls );
		$scores = json_decode( (string) $wpdb->insert_calls[0]['scores_json'], true );

		$this->assertSame( 10, $scores['0'], 'no debe exceder max_points del criterio' );
		$this->assertSame( 0, $scores['1'], 'no debe bajar de 0' );
	}

	/** @test */
	public function missing_or_non_numeric_scores_default_to_zero(): void {
		$rubric_id = 11;
		$this->seed_rubric( $rubric_id, array(
			array( 'name' => 'A', 'max_points' => 10 ),
			array( 'name' => 'B', 'max_points' => 10 ),
		) );

		$service = new \ATORA\Portfolios\Portfolios_Service();
		// Solo se envía el criterio 0; el 1 falta y debe quedar en 0.
		$service->save_final_assessment( 501, $rubric_id, array( '0' => 'no-es-numero' ), 'ok', 1 );

		global $wpdb;
		$scores = json_decode( (string) $wpdb->insert_calls[0]['scores_json'], true );
		$this->assertSame( 0, $scores['0'], 'valor no numérico se descarta a 0' );
		$this->assertSame( 0, $scores['1'], 'criterio ausente queda en 0' );
	}

	/** @test */
	public function total_percent_is_computed_against_the_full_rubric_points(): void {
		$rubric_id = 12;
		$this->seed_rubric( $rubric_id, array(
			array( 'name' => 'A', 'max_points' => 50 ),
			array( 'name' => 'B', 'max_points' => 50 ),
		) );

		$service = new \ATORA\Portfolios\Portfolios_Service();
		// 25/50 + 50/50 = 75/100 puntos -> 75%.
		$service->save_final_assessment( 502, $rubric_id, array( '0' => 25, '1' => 50 ), 'ok', 1 );

		global $wpdb;
		$this->assertSame( 75, (int) $wpdb->insert_calls[0]['total_percent'] );
	}

	/** @test */
	public function unmarks_previous_final_assessments_before_inserting_the_new_one(): void {
		$rubric_id = 13;
		$this->seed_rubric( $rubric_id, array( array( 'name' => 'A', 'max_points' => 10 ) ) );

		$service = new \ATORA\Portfolios\Portfolios_Service();
		$service->save_final_assessment( 503, $rubric_id, array( '0' => 10 ), 'ok', 1 );

		global $wpdb;
		$this->assertNotEmpty( $wpdb->update_calls, 'debe desmarcar is_final=1 de evaluaciones previas antes de insertar la nueva' );
		$this->assertSame( 0, $wpdb->update_calls[0]['data']['is_final'] );
	}

	/** @test */
	public function returns_empty_when_target_is_not_a_rubric_post(): void {
		$rubric_id = 14;
		atora_test_set_post_type( $rubric_id, 'lm_lesson' ); // no es clms_rubric

		$service = new \ATORA\Portfolios\Portfolios_Service();
		$result  = $service->save_final_assessment( 504, $rubric_id, array( '0' => 10 ), 'ok', 1 );

		$this->assertSame( array(), $result );
		global $wpdb;
		$this->assertEmpty( $wpdb->insert_calls );
	}
}
