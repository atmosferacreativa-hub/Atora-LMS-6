<?php
/**
 * ATORA_Rate_Limiter::consume() — contador atómico reutilizable — PT-6 (sprint 6.5.7).
 *
 * Extraído para que Student_Assistant::check_rate_limit() (y
 * cualquier límite sensible futuro) deje de usar el patrón
 * get_transient()+set_transient() (lectura-incremento-escritura no
 * atómico) que hasta este sprint seguía usando para el throttle del
 * asistente de IA — coste real por respuesta, por lo que una condición
 * de carrera ahí no es solo un problema de exactitud sino de costo.
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase {

	/**
	 * $wpdb en memoria que simula atora_rate_limit_counters: INSERT
	 * ... ON DUPLICATE KEY UPDATE incrementa de forma atómica por
	 * (scope, identifier_hash, window_start).
	 */
	private function install_wpdb_fixture( bool $simulate_query_failure = false ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $simulate_query_failure ) {
			public string $prefix = 'wp_';
			public array  $rows   = array();
			private bool  $fail;

			public function __construct( bool $fail ) { $this->fail = $fail; }

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function query( $sql ) {
				if ( $this->fail ) { return false; }
				if ( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' )
					&& preg_match( "/VALUES \('([^']*)', '([^']*)', (\d+), 1\)/", $sql, $m ) ) {
					$key = $m[1] . '|' . $m[2] . '|' . $m[3];
					$this->rows[ $key ] = ( $this->rows[ $key ] ?? 0 ) + 1;
					return 1;
				}
				if ( false !== strpos( $sql, 'DELETE FROM' ) && preg_match( '/window_start < (\d+)/', $sql, $m ) ) {
					$threshold = (int) $m[1];
					$deleted   = 0;
					foreach ( array_keys( $this->rows ) as $key ) {
						$parts = explode( '|', $key );
						if ( (int) $parts[2] < $threshold ) {
							unset( $this->rows[ $key ] );
							$deleted++;
						}
					}
					return $deleted;
				}
				return 1;
			}

			public function get_var( $sql ) {
				if ( $this->fail ) { return null; }
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
					return $this->prefix . 'atora_rate_limit_counters';
				}
				if ( false !== strpos( $sql, 'SELECT attempts FROM' )
					&& preg_match( "/scope = '([^']*)' AND identifier_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
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

	/** @test */
	public function test_allows_up_to_the_limit_then_blocks(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 1; $i <= 15; $i++ ) {
			$this->assertTrue(
				\ATORA_Rate_Limiter::consume( 'student_assistant', 'user_1', 15, 300 ),
				"intento {$i} no debería bloquearse todavía"
			);
		}

		$this->assertFalse(
			\ATORA_Rate_Limiter::consume( 'student_assistant', 'user_1', 15, 300 ),
			'el 16º intento debe bloquearse'
		);

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_different_identifiers_have_independent_counters(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 0; $i < 15; $i++ ) {
			\ATORA_Rate_Limiter::consume( 'student_assistant', 'user_1', 15, 300 );
		}
		$this->assertFalse( \ATORA_Rate_Limiter::consume( 'student_assistant', 'user_1', 15, 300 ) );

		$this->assertTrue(
			\ATORA_Rate_Limiter::consume( 'student_assistant', 'user_2', 15, 300 ),
			'un identificador distinto no debe verse afectado por el consumo de otro'
		);

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_different_scopes_have_independent_counters_for_same_identifier(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 0; $i < 15; $i++ ) {
			\ATORA_Rate_Limiter::consume( 'student_assistant', 'shared_id', 15, 300 );
		}
		$this->assertFalse( \ATORA_Rate_Limiter::consume( 'student_assistant', 'shared_id', 15, 300 ) );

		$this->assertTrue(
			\ATORA_Rate_Limiter::consume( 'other_scope', 'shared_id', 15, 300 ),
			'un scope distinto no debe compartir cupo aunque el identificador coincida'
		);

		$this->restore_wpdb( $original );
	}

	/**
	 * Simula llamadas concurrentes: el incremento atómico del fixture
	 * no debe perder ninguno de los 50 incrementos.
	 *
	 * @test
	 */
	public function test_concurrent_style_calls_do_not_lose_increments(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 0; $i < 50; $i++ ) {
			\ATORA_Rate_Limiter::consume( 'student_assistant', 'user_3', 1000, 300 );
		}

		global $wpdb;
		$key = array_key_first( $wpdb->rows );
		$this->assertSame( 50, $wpdb->rows[ $key ], 'los 50 incrementos deben reflejarse exactamente' );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-8 (6.5.7): política fail-closed — si la consulta falla (tabla
	 * ausente, migración incompleta), una operación costosa/sensible NO
	 * debe permitirse por defecto.
	 *
	 * @test
	 */
	public function test_fails_closed_by_default_when_query_fails(): void {
		$original = $this->install_wpdb_fixture( true );

		$this->assertFalse(
			\ATORA_Rate_Limiter::consume( 'student_assistant', 'user_4', 15, 300 ),
			'fail_open=false (default) — ante un fallo de la tabla de rate limit, debe bloquear, no permitir'
		);

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_can_opt_into_fail_open_for_non_sensitive_scopes(): void {
		$original = $this->install_wpdb_fixture( true );

		$this->assertTrue(
			\ATORA_Rate_Limiter::consume( 'low_stakes_scope', 'user_5', 15, 300, true ),
			'con fail_open=true explícito, un fallo de la tabla no debe bloquear'
		);

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_purge_expired_deletes_only_old_windows(): void {
		$original = $this->install_wpdb_fixture();

		\ATORA_Rate_Limiter::consume( 'student_assistant', 'old_row', 15, 300 );
		global $wpdb;
		// Reescribe la ventana de la fila "vieja" para simular antigüedad.
		$old_key = array_key_first( $wpdb->rows );
		$parts   = explode( '|', $old_key );
		unset( $wpdb->rows[ $old_key ] );
		$wpdb->rows[ $parts[0] . '|' . $parts[1] . '|' . '100' ] = 1; // window_start = 100 (muy vieja).

		\ATORA_Rate_Limiter::consume( 'student_assistant', 'current_row', 15, 300 );

		$deleted = \ATORA_Rate_Limiter::purge_expired( 60 ); // umbral: ahora - 60s.

		$this->assertSame( 1, $deleted, 'debe borrar solo la fila vieja' );
		$this->assertCount( 1, $wpdb->rows, 'la fila actual debe permanecer' );

		$this->restore_wpdb( $original );
	}
}
