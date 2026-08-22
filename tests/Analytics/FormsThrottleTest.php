<?php
/**
 * Forms_Builder — throttle por IP/formulario — PT-5 (sprint 6.5.4),
 * endurecido en PT-1 (sprint 6.5.5).
 *
 * 6.5.4: nonce y honeypot no bastan contra reenvíos del mismo nonce —
 * se agregó un tope por IP/formulario/ventana.
 *
 * 6.5.5: el contador pasó de get_transient()/set_transient() (lectura-
 * incremento-escritura no atómico) a una tabla dedicada
 * (atora_form_throttle) con INSERT ... ON DUPLICATE KEY UPDATE,
 * atómico por bloqueo de fila. La resolución de IP pasó a
 * ATORA_Client_IP::get() (proxy-aware) en vez de confiar directo en
 * X-Forwarded-For.
 *
 * @package ATORA_LMS\Tests\Analytics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Analytics;

use PHPUnit\Framework\TestCase;

class FormsThrottleTest extends TestCase {

	/**
	 * $wpdb en memoria que simula atora_form_throttle: INSERT ... ON
	 * DUPLICATE KEY UPDATE incrementa attempts de forma atómica por
	 * clave (form_id, ip_hash, window_start); SELECT attempts lee el
	 * valor consolidado — sin depender de un MySQL real.
	 */
	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public array  $rows   = array(); // "$form_id|$ip_hash|$window_start" => attempts

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function query( $sql ) {
				if ( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' )
					&& preg_match( "/VALUES \((\d+), '([^']*)', (\d+), 1\)/", $sql, $m ) ) {
					$key = $m[1] . '|' . $m[2] . '|' . $m[3];
					$this->rows[ $key ] = ( $this->rows[ $key ] ?? 0 ) + 1;
				}
				return 1;
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SELECT attempts' )
					&& preg_match( "/form_id = (\d+) AND ip_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
					$key = $m[1] . '|' . $m[2] . '|' . $m[3];
					return $this->rows[ $key ] ?? null;
				}
				return null;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_post_meta();
		atora_test_reset_filters();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
	}

	protected function tearDown(): void {
		atora_test_reset_post_meta();
		atora_test_reset_filters();
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
		parent::tearDown();
	}

	private function is_throttled( int $form_id ): bool {
		$ref = new \ReflectionMethod( \ATORA\Analytics\Forms_Builder::class, 'is_throttled' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $form_id );
	}

	/** @test */
	public function test_eleventh_submission_from_same_ip_is_throttled(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertFalse( $this->is_throttled( 1 ), "intento " . ( $i + 1 ) . " no debería bloquearse todavía" );
		}

		$this->assertTrue( $this->is_throttled( 1 ), 'el 11º envío en la ventana debe rechazarse' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_different_ips_have_independent_counters(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 0; $i < 10; $i++ ) {
			$this->is_throttled( 2 );
		}
		$this->assertTrue( $this->is_throttled( 2 ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertFalse( $this->is_throttled( 2 ), 'una IP distinta no debe verse afectada por el consumo de otra' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_different_forms_have_independent_counters_for_same_ip(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 0; $i < 10; $i++ ) {
			$this->is_throttled( 3 );
		}
		$this->assertTrue( $this->is_throttled( 3 ) );

		$this->assertFalse( $this->is_throttled( 4 ), 'un formulario distinto, misma IP, debe tener su propio contador' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_form_specific_throttle_limit_from_schema_is_respected(): void {
		$original = $this->install_wpdb_fixture();
		atora_test_set_post_meta( 5, 'atora_form_schema', wp_json_encode( array( 'throttle_per_15min' => 3 ) ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( $this->is_throttled( 5 ) );
		}
		$this->assertTrue( $this->is_throttled( 5 ), 'debe respetar el límite configurado en el schema, no el default de 10' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_no_ip_available_does_not_block(): void {
		$original = $this->install_wpdb_fixture();
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertFalse( $this->is_throttled( 6 ), 'sin IP determinable, no se bloquea (regla 5.3 — nada confiable que contar)' );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-1 (6.5.5) — caso 3/6 del OT: X-Forwarded-For desde un
	 * REMOTE_ADDR no confiable debe ignorarse — el atacante no puede
	 * rotar una cabecera falsa para evadir el throttle ni para
	 * envenenar el contador de otro cliente.
	 *
	 * @test
	 */
	public function test_spoofed_forwarded_header_from_untrusted_remote_is_ignored(): void {
		$original = $this->install_wpdb_fixture();

		$_SERVER['REMOTE_ADDR']         = '203.0.113.10'; // IP pública real del cliente, no un proxy de confianza.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		for ( $i = 0; $i < 10; $i++ ) {
			$this->is_throttled( 7 );
		}
		$this->assertTrue( $this->is_throttled( 7 ), 'debe bloquear según REMOTE_ADDR real, ignorando la cabecera forjada' );

		// Rotar la cabecera falsa en cada intento no debe evadir el límite —
		// la clave de conteo depende de REMOTE_ADDR, no de XFF.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
		$this->assertTrue( $this->is_throttled( 7 ), 'rotar X-Forwarded-For no debe evadir el throttle si REMOTE_ADDR no es un proxy confiable' );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-1 (6.5.5) — caso 4/6: una cabecera reenviada real, detrás de
	 * un proxy configurado como confiable, sí debe resolverse y usarse
	 * como clave de conteo.
	 *
	 * @test
	 */
	public function test_forwarded_header_behind_trusted_proxy_is_used(): void {
		$original = $this->install_wpdb_fixture();

		// PT-1 (6.5.8): los rangos privados ya no son confiables por
		// defecto ("PRIVATE IP ≠ TRUSTED PROXY") — se configura
		// explícitamente para este test, igual que tendría que hacerlo
		// un sitio real detrás de un proxy en una IP privada.
		add_filter( 'atora_client_ip_trusted_proxies', static function () { return array( '10.0.0.5' ); } );

		$_SERVER['REMOTE_ADDR']         = '10.0.0.5'; // proxy explícitamente confiable para este test.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';

		for ( $i = 0; $i < 10; $i++ ) {
			$this->is_throttled( 8 );
		}
		$this->assertTrue( $this->is_throttled( 8 ) );

		// Un cliente real distinto, detrás del mismo proxy, tiene su propio contador.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
		$this->assertFalse( $this->is_throttled( 8 ), 'la IP real del cliente detrás del proxy confiable debe usarse como clave' );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-1 (6.5.5) — caso 7/6: simula concurrencia — múltiples
	 * "peticiones" que incrementan la misma clave no deben perder
	 * incrementos (el fixture modela el UPDATE atómico por fila; si el
	 * conteo final coincide exactamente con el número de llamadas, no
	 * hubo una condición de carrera de lectura-incremento-escritura).
	 *
	 * @test
	 */
	public function test_concurrent_style_increments_are_not_lost(): void {
		$original = $this->install_wpdb_fixture();
		atora_test_set_post_meta( 9, 'atora_form_schema', wp_json_encode( array( 'throttle_per_15min' => 1000 ) ) );

		$blocked_count = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			if ( $this->is_throttled( 9 ) ) {
				++$blocked_count;
			}
		}

		global $wpdb;
		$key = array_key_first( $wpdb->rows );
		$this->assertSame( 50, $wpdb->rows[ $key ], 'los 50 incrementos deben reflejarse exactamente, sin pérdidas por carrera' );
		$this->assertSame( 0, $blocked_count, 'con límite 1000 ninguno de los 50 debe bloquearse' );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-6 (6.5.8) — hallazgo real: si el INSERT/SELECT del contador
	 * fallan (tabla ausente, migración incompleta), el envío debe
	 * rechazarse (fail-closed) — antes, get_var() devolviendo null se
	 * interpretaba como "0 intentos", dejando el formulario público SIN
	 * límite mientras el backend estuviera roto.
	 *
	 * @test
	 */
	public function test_fails_closed_when_throttle_backend_query_fails(): void {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( $sql ) { return false; } // simula un INSERT fallido.
			public function get_var( $sql ) { return null; }
			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		$this->assertTrue( $this->is_throttled( 99 ), 'sin backend de throttle disponible, debe rechazarse el envío (fail-closed), no permitirse sin límite' );

		$wpdb = $original;
	}
}
