<?php
/**
 * 06-parity-tables — sprint 6.5.10.
 *
 * LMS_Parity::ensure_table()'s confirmed-installed short-circuit
 * (avoids two SHOW TABLES queries on every single request once both
 * tables are known to exist) and log_read()/log_if_diff()'s
 * fail-safe behavior when the backing table is genuinely unavailable
 * (PT-3).
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ParityTableLifecycleTest extends TestCase {

	protected function tearDown(): void {
		delete_option( \ATORA\LMS\LMS_Parity::OPT_TABLES_CONFIRMED );
		parent::tearDown();
	}

	/**
	 * Con la option de confirmación ya en true, ensure_table() no debe
	 * emitir ninguna consulta SHOW TABLES — el $wpdb de prueba lanza
	 * una excepción si get_var()/query() se llaman, para demostrar que
	 * el short-circuit realmente evita tocar la base de datos.
	 *
	 * @test
	 */
	public function test_ensure_table_short_circuits_once_confirmed(): void {
		update_option( \ATORA\LMS\LMS_Parity::OPT_TABLES_CONFIRMED, 1 );

		global $wpdb;
		$original = $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public function get_charset_collate() { return ''; }
			public function get_var( $sql ) {
				throw new \RuntimeException( 'ensure_table() no debería consultar la BD una vez confirmado: ' . $sql );
			}
		};

		// No debe lanzar — confirma el short-circuit temprano.
		\ATORA\LMS\LMS_Parity::ensure_table();
		$this->assertTrue( true, 'ensure_table() retornó sin tocar $wpdb' );

		$wpdb = $original;
	}

	/**
	 * PT-3 (6.5.10): un fallo real al escribir (tabla ausente, por
	 * ejemplo) no debe propagar ninguna excepción hacia el lector
	 * académico que llamó al shadow-check — la regla explícita de esta
	 * clase ("ninguna excepción aquí puede afectar la respuesta real")
	 * debe sostenerse incluso si algo dentro de $wpdb->query() termina
	 * lanzando.
	 *
	 * @test
	 */
	public function test_log_read_never_throws_even_if_wpdb_throws(): void {
		global $wpdb;
		$original = $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public function prepare( $sql, ...$args ) { return $sql; }
			public function query( $sql ) {
				throw new \RuntimeException( 'tabla no disponible' );
			}
		};

		$ref = new ReflectionMethod( '\ATORA\LMS\LMS_Parity', 'log_read' );
		$ref->setAccessible( true );

		try {
			$ref->invoke( null, 'enrolled_courses', 123 );
			$this->assertTrue( true, 'log_read() no propagó la excepción de $wpdb' );
		} catch ( \Throwable $e ) {
			$this->fail( 'log_read() debe atrapar cualquier fallo de escritura, no propagarlo: ' . $e->getMessage() );
		}

		$wpdb = $original;
	}

	/**
	 * Mismo criterio que el test anterior, para la escritura de
	 * divergencias (log_if_diff()).
	 *
	 * @test
	 */
	public function test_log_if_diff_never_throws_even_if_wpdb_throws(): void {
		global $wpdb;
		$original = $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public function insert( $table, $data, $format = null ) {
				throw new \RuntimeException( 'tabla no disponible' );
			}
		};

		$ref = new ReflectionMethod( '\ATORA\LMS\LMS_Parity', 'log_if_diff' );
		$ref->setAccessible( true );

		try {
			$ref->invoke( null, 'enrolled_courses', 123, 0, 'digest-a', 'digest-b', 'legacy', 'table' );
			$this->assertTrue( true, 'log_if_diff() no propagó la excepción de $wpdb' );
		} catch ( \Throwable $e ) {
			$this->fail( 'log_if_diff() debe atrapar cualquier fallo de escritura, no propagarlo: ' . $e->getMessage() );
		}

		$wpdb = $original;
	}
}
