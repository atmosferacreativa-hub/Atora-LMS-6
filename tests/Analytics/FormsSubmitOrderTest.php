<?php
/**
 * Forms_Builder::handle_submit() — orden de validación — PT-1 (sprint 6.5.5).
 *
 * Hallazgo confirmado: en 6.5.4, is_throttled($form_id) corría como
 * primera instrucción de handle_submit(), antes de confirmar que
 * form_id > 0, que el post existiera, que fuera realmente un
 * atora_form, o que el nonce fuera válido — una petición anónima con
 * miles de form_id fabricados podía generar filas de contador
 * ilimitadas (DoS de bajo costo) sin pasar nunca la validación.
 *
 * handle_submit() termina la ejecución (wp_send_json_error/success →
 * exit()) igual que en WordPress real, lo cual no es capturable con
 * try/catch ni compatible con @runInSeparateProcess de PHPUnit (el
 * exit() impide que PHPUnit serialice el resultado del proceso hijo).
 * Cada escenario se ejecuta en un proceso PHP totalmente aparte vía
 * proc_open() sobre tests/Analytics/fixtures/run-handle-submit.php —
 * este test (proceso normal de PHPUnit) solo revisa, después, si ese
 * proceso hijo llegó a escribir el archivo marcador (equivalente a
 * "se generó algún INSERT/consulta contra atora_form_throttle o
 * atora_form_entries").
 *
 * @package ATORA_LMS\Tests\Analytics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Analytics;

use PHPUnit\Framework\TestCase;

class FormsSubmitOrderTest extends TestCase {

	private ?string $marker = null;

	protected function tearDown(): void {
		if ( $this->marker && file_exists( $this->marker ) ) {
			@unlink( $this->marker );
		}
		$this->marker = null;
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $env Variables ATORA_TEST_* adicionales.
	 * @return bool true si el proceso hijo escribió el marcador.
	 */
	private function run_scenario( array $env ): bool {
		$this->marker = sys_get_temp_dir() . '/atora_test_throttle_marker_' . bin2hex( random_bytes( 8 ) ) . '.txt';
		@unlink( $this->marker );

		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$script  = __DIR__ . '/fixtures/run-handle-submit.php';

		$full_env = array_merge(
			$_ENV ?? array(),
			array( 'ATORA_TEST_MARKER' => $this->marker ),
			$env
		);

		$process = proc_open(
			array( $php_bin, $script ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			null,
			$full_env
		);

		if ( ! is_resource( $process ) ) {
			$this->fail( 'no se pudo lanzar el proceso PHP hijo para el test aislado' );
		}

		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		return file_exists( $this->marker );
	}

	/**
	 * Caso 1/7 del OT: form_id inexistente + nonce inválido → no debe
	 * crearse ningún estado de rate-limit (ni ninguna otra escritura).
	 *
	 * @test
	 */
	public function test_nonexistent_form_id_creates_no_throttle_state(): void {
		$wrote = $this->run_scenario( array(
			'ATORA_TEST_FORM_ID'   => '999999',
			'ATORA_TEST_NONCE'     => 'not-a-real-nonce',
			'ATORA_TEST_POST_TYPE' => '0',
		) );

		$this->assertFalse( $wrote, 'un form_id inexistente no debe generar ninguna escritura persistente' );
	}

	/**
	 * Caso 2/7 del OT: formulario válido pero nonce inválido → no debe
	 * consumirse cupo de throttle.
	 *
	 * @test
	 */
	public function test_valid_form_with_invalid_nonce_consumes_no_quota(): void {
		$wrote = $this->run_scenario( array(
			'ATORA_TEST_FORM_ID'   => '42',
			'ATORA_TEST_NONCE'     => 'wrong-nonce',
			'ATORA_TEST_POST_TYPE' => '1',
			'ATORA_TEST_SCHEMA'    => wp_json_encode( array( 'fields' => array() ) ),
		) );

		$this->assertFalse( $wrote, 'un nonce inválido no debe consumir cupo de rate-limit ni escribir nada' );
	}

	/**
	 * Control positivo: form_id válido + nonce válido SÍ debe llegar al
	 * throttle (y por lo tanto escribir en atora_form_throttle) —
	 * confirma que el runner/fixture no está "pasando por accidente".
	 *
	 * @test
	 */
	public function test_valid_form_and_nonce_does_reach_throttle_state(): void {
		$wrote = $this->run_scenario( array(
			'ATORA_TEST_FORM_ID'    => '43',
			'ATORA_TEST_POST_TYPE'  => '1',
			'ATORA_TEST_SCHEMA'     => wp_json_encode( array( 'fields' => array() ) ),
			'ATORA_TEST_VALID_NONCE' => '1',
		) );

		$this->assertTrue( $wrote, 'con form_id y nonce válidos, el flujo sí debe llegar al throttle/guardado' );
	}
}
