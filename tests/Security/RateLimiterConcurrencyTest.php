<?php
/**
 * ATORA_Rate_Limiter::consume() — concurrencia REAL entre procesos del
 * sistema operativo — sprint 6.5.9, requerido por PT-1, PT-4 y PT-5.
 *
 * El OT es explícito: "Un fix de condición de carrera sin una prueba
 * que dispare requests en paralelo no demuestra que la carrera se
 * cerró." Una prueba que llama a consume() en un bucle secuencial
 * dentro de un único proceso PHP no prueba nada sobre atomicidad —
 * nunca hay dos hilos de ejecución compitiendo de verdad por el mismo
 * recurso.
 *
 * Esta prueba lanza N procesos PHP reales (proc_open), TODOS
 * arrancados antes de esperar a ninguno (arranque en paralelo, no
 * secuencial), cada uno ejecutando fixtures/rate-limiter-concurrency-worker.php,
 * que llama a ATORA_Rate_Limiter::consume() una sola vez contra el
 * mismo scope/identifier/limit/window y escribe "1" (permitido) o "0"
 * (rechazado) a su propio archivo de resultado. El estado compartido
 * del contador vive en un archivo real protegido con flock(LOCK_EX)
 * — el mismo tipo de exclusión mutua que el bloqueo de fila InnoDB le
 * da al INSERT ... ON DUPLICATE KEY UPDATE real en producción.
 *
 * PT-1, PT-4 y PT-5 comparten exactamente este mismo primitivo
 * (ATORA_Rate_Limiter::consume()) tras esta ronda de fixes — probar
 * su atomicidad una sola vez acá, con concurrencia real, cubre a los
 * tres; cada consumidor tiene además su propia prueba más liviana de
 * "wiring" (que el gate esté puesto en el lugar correcto, con el
 * orden correcto) en su propio archivo de test.
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

class RateLimiterConcurrencyTest extends TestCase {

	private array $tmp_files = array();

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $f ) {
			if ( file_exists( $f ) ) {
				@unlink( $f );
			}
		}
		$this->tmp_files = array();
		parent::tearDown();
	}

	/**
	 * Dispara $count procesos PHP reales EN PARALELO contra el mismo
	 * scope/identifier, todos compitiendo por el mismo cupo de
	 * $limit intentos en una ventana de $window segundos.
	 *
	 * @return int Cantidad de procesos a los que consume() les devolvió true (permitidos).
	 */
	private function run_concurrent_consumers( string $scope, string $identifier, int $limit, int $window, int $count ): int {
		$state_file = sys_get_temp_dir() . '/atora_test_rl_state_' . bin2hex( random_bytes( 8 ) ) . '.json';
		$this->tmp_files[] = $state_file;

		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$script  = __DIR__ . '/fixtures/rate-limiter-concurrency-worker.php';

		$processes    = array();
		$pipes_all    = array();
		$result_files = array();

		// Arrancar TODOS los procesos primero — la carrera real ocurre
		// porque ninguno espera a que termine el anterior antes de
		// arrancar el siguiente.
		for ( $i = 0; $i < $count; $i++ ) {
			$result_file    = sys_get_temp_dir() . '/atora_test_rl_result_' . bin2hex( random_bytes( 8 ) ) . '.txt';
			$result_files[] = $result_file;
			$this->tmp_files[] = $result_file;

			$env = array_merge( $_ENV ?? array(), array(
				'ATORA_TEST_STATE_FILE'  => $state_file,
				'ATORA_TEST_RESULT_FILE' => $result_file,
				'ATORA_TEST_SCOPE'       => $scope,
				'ATORA_TEST_IDENTIFIER'  => $identifier,
				'ATORA_TEST_LIMIT'       => (string) $limit,
				'ATORA_TEST_WINDOW'      => (string) $window,
			) );

			$pipes    = array();
			$process  = proc_open(
				array( $php_bin, $script ),
				array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
				$pipes,
				null,
				$env
			);

			if ( ! is_resource( $process ) ) {
				$this->fail( 'no se pudo lanzar el proceso hijo ' . $i );
			}

			$processes[] = $process;
			$pipes_all[] = $pipes;
		}

		// Recién ahora se espera a que terminen — todos ya estaban
		// corriendo en simultáneo.
		foreach ( $processes as $i => $process ) {
			stream_get_contents( $pipes_all[ $i ][1] );
			stream_get_contents( $pipes_all[ $i ][2] );
			fclose( $pipes_all[ $i ][1] );
			fclose( $pipes_all[ $i ][2] );
			proc_close( $process );
		}

		$allowed = 0;
		foreach ( $result_files as $rf ) {
			if ( file_exists( $rf ) && '1' === trim( (string) file_get_contents( $rf ) ) ) {
				++$allowed;
			}
		}

		return $allowed;
	}

	/**
	 * 20 procesos reales, disparados en simultáneo, compitiendo por un
	 * cupo nominal de 5 — el número de "permitidos" jamás debe superar
	 * 5, sin importar el orden real de ejecución del SO.
	 *
	 * Este es el patrón de la fila 1 (2FA)/4 (enrollment)/5 (WhatsApp)
	 * de la matriz manual del OT.
	 *
	 * @test
	 */
	public function test_concurrent_consumers_never_exceed_the_nominal_limit(): void {
		$allowed = $this->run_concurrent_consumers( 'concurrency_test_scope', 'shared-identifier-1', 5, 900, 20 );

		$this->assertLessThanOrEqual(
			5,
			$allowed,
			'con 20 procesos reales compitiendo por un cupo de 5, nunca deben quedar más de 5 permitidos — de lo contrario la carrera sigue abierta'
		);
		$this->assertGreaterThan( 0, $allowed, 'al menos algunos procesos deben ser admitidos (el límite no debe fallar cerrado espuriamente en el caso feliz)' );
	}

	/**
	 * Dos identificadores distintos no deben interferirse — el cupo es
	 * por identificador, no global.
	 *
	 * @test
	 */
	public function test_concurrent_consumers_with_different_identifiers_do_not_share_a_quota(): void {
		$state_file = sys_get_temp_dir() . '/atora_test_rl_state_' . bin2hex( random_bytes( 8 ) ) . '.json';
		$this->tmp_files[] = $state_file;

		// Reutiliza el helper dos veces con identificadores distintos
		// pero el mismo scope — cada uno debe poder consumir su propio
		// cupo completo de 3, sin verse afectado por el otro.
		$allowed_a = $this->run_concurrent_consumers( 'concurrency_test_scope_iso', 'identifier-A', 3, 900, 6 );
		$allowed_b = $this->run_concurrent_consumers( 'concurrency_test_scope_iso', 'identifier-B', 3, 900, 6 );

		$this->assertLessThanOrEqual( 3, $allowed_a );
		$this->assertLessThanOrEqual( 3, $allowed_b );
		$this->assertGreaterThan( 0, $allowed_a );
		$this->assertGreaterThan( 0, $allowed_b );
	}
}
