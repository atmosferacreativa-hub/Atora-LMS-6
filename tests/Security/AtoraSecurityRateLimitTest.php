<?php
/**
 * ATORA_Security::rate_limit() — migrado al limiter atómico central — PT-5.5 (sprint 6.5.8).
 *
 * Hallazgo confirmado: este helper genérico (usado por
 * CLMS_2FA_Manager::ajax_verify()/ajax_resend() para proteger el
 * flujo de autenticación de dos factores) seguía usando
 * get_transient()/set_transient() — lectura-incremento-escritura no
 * atómico, en un control anti-fuerza-bruta de autenticación.
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

class AtoraSecurityRateLimitTest extends TestCase {

	private function install_wpdb_fixture( bool $simulate_failure = false ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $simulate_failure ) {
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
				}
				return 1;
			}

			public function get_var( $sql ) {
				if ( $this->fail ) { return null; }
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
	public function test_allows_up_to_max_then_blocks(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertTrue( \ATORA_Security::rate_limit( '2fa_verify_abc', 10, 300 ), "intento {$i} no debería bloquearse todavía" );
		}
		$this->assertFalse( \ATORA_Security::rate_limit( '2fa_verify_abc', 10, 300 ) );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_fails_closed_when_backend_unavailable(): void {
		$original = $this->install_wpdb_fixture( true );

		$this->assertFalse( \ATORA_Security::rate_limit( '2fa_resend_xyz', 5, 600 ), 'sin backend disponible, debe bloquear (fail-closed), no permitir' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_different_keys_are_independent(): void {
		$original = $this->install_wpdb_fixture();

		for ( $i = 0; $i < 5; $i++ ) {
			\ATORA_Security::rate_limit( '2fa_resend_key_a', 5, 600 );
		}
		$this->assertFalse( \ATORA_Security::rate_limit( '2fa_resend_key_a', 5, 600 ) );
		$this->assertTrue( \ATORA_Security::rate_limit( '2fa_resend_key_b', 5, 600 ), 'una clave distinta no debe compartir cupo' );

		$this->restore_wpdb( $original );
	}
}
