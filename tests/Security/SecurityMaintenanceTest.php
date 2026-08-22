<?php
/**
 * ATORA_Security_Maintenance::run() — limpieza periódica de tablas de
 * rate limit — PT-4 (sprint 6.5.7).
 *
 * Hallazgo confirmado: atora_form_throttle y atora_api_rate_limit
 * (creadas en 6.5.5) nunca se purgaban — cada IP/API-key nueva agrega
 * filas para siempre. Este test es la evidencia obligatoria de que el
 * DELETE realmente corre sobre AMBAS tablas (más la nueva
 * atora_rate_limit_counters), dejando una fila "vieja" fuera y una
 * "actual" intacta.
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

class SecurityMaintenanceTest extends TestCase {

	/**
	 * $wpdb que registra, tabla por tabla, cada DELETE ejecutado — para
	 * poder afirmar "sí, se ejecutó una consulta DELETE sobre esta
	 * tabla", no solo inferirlo.
	 */
	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public array  $deletes_by_table = array(); // tabla => [sql, sql, ...]
			public array  $rows = array(
				// simula una fila vieja y una actual en cada tabla.
				'atora_form_throttle'       => array( 'old' => array( 'window_start' => 100 ), 'current' => array( 'window_start' => 0 ) ),
				'atora_api_rate_limit'      => array( 'old' => array( 'minute_key' => '202001010000' ), 'current' => array( 'minute_key' => '' ) ),
				'atora_rate_limit_counters' => array( 'old' => array( 'window_start' => 100 ), 'current' => array( 'window_start' => 0 ) ),
			);

			public function __construct() {
				// La fila "actual" debe caer fuera del umbral de retención — se fija dinámicamente al construir.
				$this->rows['atora_form_throttle']['current']['window_start']       = time();
				$this->rows['atora_rate_limit_counters']['current']['window_start'] = time();
				$this->rows['atora_api_rate_limit']['current']['minute_key']        = gmdate( 'YmdHi' );
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/'%?wp_([a-z_]+)%?'/", $sql, $m ) ) {
					$suffix = rtrim( $m[1], '%' );
					return isset( $this->rows[ $suffix ] ) ? ( 'wp_' . $suffix ) : null;
				}
				return null;
			}

			public function query( $sql ) {
				foreach ( array_keys( $this->rows ) as $suffix ) {
					if ( false !== strpos( $sql, 'wp_' . $suffix ) && false !== strpos( $sql, 'DELETE FROM' ) ) {
						$this->deletes_by_table[ $suffix ][] = $sql;

						$deleted = 0;
						if ( false !== strpos( $sql, 'window_start' ) && preg_match( '/window_start < (\d+)/', $sql, $m ) ) {
							$threshold = (int) $m[1];
							if ( $this->rows[ $suffix ]['old']['window_start'] < $threshold ) {
								unset( $this->rows[ $suffix ]['old'] );
								$deleted++;
							}
						} elseif ( false !== strpos( $sql, 'minute_key' ) && preg_match( "/minute_key < '([^']*)'/", $sql, $m ) ) {
							$threshold = $m[1];
							if ( $this->rows[ $suffix ]['old']['minute_key'] < $threshold ) {
								unset( $this->rows[ $suffix ]['old'] );
								$deleted++;
							}
						}
						return $deleted;
					}
				}
				return 1;
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

	/**
	 * Evidencia obligatoria: el DELETE se ejecuta de verdad sobre las
	 * tres tablas, la fila vieja desaparece y la actual permanece.
	 *
	 * @test
	 */
	public function test_run_deletes_expired_rows_from_all_three_tables(): void {
		$original = $this->install_wpdb_fixture();

		$result = \ATORA_Security_Maintenance::run();

		global $wpdb;

		$this->assertArrayHasKey( 'atora_form_throttle', $wpdb->deletes_by_table, 'debe haberse ejecutado un DELETE sobre atora_form_throttle' );
		$this->assertArrayHasKey( 'atora_api_rate_limit', $wpdb->deletes_by_table, 'debe haberse ejecutado un DELETE sobre atora_api_rate_limit' );
		$this->assertArrayHasKey( 'atora_rate_limit_counters', $wpdb->deletes_by_table, 'debe haberse ejecutado un DELETE sobre atora_rate_limit_counters' );

		$this->assertArrayNotHasKey( 'old', $wpdb->rows['atora_form_throttle'], 'la fila vieja de form_throttle debe haberse borrado' );
		$this->assertArrayHasKey( 'current', $wpdb->rows['atora_form_throttle'], 'la fila actual de form_throttle debe permanecer' );

		$this->assertArrayNotHasKey( 'old', $wpdb->rows['atora_api_rate_limit'], 'la fila vieja de api_rate_limit debe haberse borrado' );
		$this->assertArrayHasKey( 'current', $wpdb->rows['atora_api_rate_limit'], 'la fila actual de api_rate_limit debe permanecer' );

		$this->assertArrayNotHasKey( 'old', $wpdb->rows['atora_rate_limit_counters'], 'la fila vieja de rate_limit_counters debe haberse borrado' );
		$this->assertArrayHasKey( 'current', $wpdb->rows['atora_rate_limit_counters'], 'la fila actual de rate_limit_counters debe permanecer' );

		$this->assertSame( 1, $result['form_throttle'] );
		$this->assertSame( 1, $result['api_rate_limit'] );
		$this->assertSame( 1, $result['generic_rate_limit'] );

		$this->restore_wpdb( $original );
	}
}
