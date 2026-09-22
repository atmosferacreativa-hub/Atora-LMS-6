<?php
/**
 * Forms_Builder — <select> options can be strings or {value,label} arrays.
 *
 * @package ATORA_LMS\Tests\Analytics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Analytics;

use PHPUnit\Framework\TestCase;

final class FormsSelectOptionsTest extends TestCase {

	/** @test */
	public function render_field_does_not_print_array_when_options_are_arrays(): void {
		require_once __DIR__ . '/../../modules/analytics/class-forms-builder.php';

		$field = array(
			'type'     => 'select',
			'name'     => 'motivo',
			'label'    => 'Motivo',
			'required' => true,
			'options'  => array(
				array( 'value' => 'a', 'label' => 'Opción A' ),
				array( 'label' => 'Opción B', 'value' => 'b' ),
			),
		);

		$html = $this->invoke_render_field( $field );
		$this->assertStringNotContainsString( '>Array<', $html );
		$this->assertStringContainsString( 'value="a"', $html );
		$this->assertStringContainsString( '>Opción A<', $html );
	}

	/** @test */
	public function handle_submit_rejects_select_values_not_in_schema_options(): void {
		$out = $this->run_submit_scenario( array(
			'ATORA_TEST_FIELDS_JSON' => wp_json_encode( array( 'motivo' => 'invalido' ) ),
			'ATORA_TEST_SCHEMA'      => wp_json_encode( array(
				'fields' => array(
					array(
						'type'     => 'select',
						'name'     => 'motivo',
						'label'    => 'Motivo',
						'required' => true,
						'options'  => array(
							array( 'value' => 'a', 'label' => 'A' ),
							array( 'value' => 'b', 'label' => 'B' ),
						),
					),
				),
			) ),
		) );

		$this->assertIsArray( $out );
		$this->assertFalse( (bool) ( $out['success'] ?? true ) );
		$this->assertStringNotContainsString( 'Array', (string) ( $out['data']['message'] ?? '' ) );
	}

	private function invoke_render_field( array $field ): string {
		$ref = new \ReflectionClass( \ATORA\Analytics\Forms_Builder::class );
		$m = $ref->getMethod( 'render_field' );
		$m->setAccessible( true );
		return (string) $m->invoke( null, $field );
	}

	/**
	 * @param array<string,string> $env
	 * @return array<string,mixed>
	 */
	private function run_submit_scenario( array $env ): array {
		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$script  = __DIR__ . '/fixtures/run-handle-submit-response.php';

		$process = proc_open(
			array( $php_bin, $script ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			null,
			array_merge( $_ENV ?? array(), $env )
		);

		if ( ! is_resource( $process ) ) {
			$this->fail( 'no se pudo lanzar el proceso PHP hijo' );
		}

		$stdout = stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$decoded = json_decode( (string) $stdout, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
