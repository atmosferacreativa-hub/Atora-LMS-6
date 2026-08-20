<?php
/**
 * ATORA_API_Key_Service — rate limiting de API keys MCP — PT-6 (sprint 6.5.1).
 *
 * Hallazgo confirmado: el bucket de rate limit usaba
 * substr($raw_key, 0, 8) sobre una key con prefijo fijo "atora_" (6
 * caracteres) — solo 2 caracteres hex aleatorios de verdad, 256
 * combinaciones posibles, colisión trivial entre keys distintas.
 * Separadamente, sanitize_key() sobre "read,write" completo colapsaba
 * la coma y el límite real caía siempre al default de 100.
 *
 * @package ATORA_LMS\Tests\MCP
 */

declare( strict_types = 1 );

namespace ATORA\Tests\MCP;

use PHPUnit\Framework\TestCase;

class ApiKeyRateLimitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_transients();
	}

	protected function tearDown(): void {
		\atora_test_reset_transients();
		parent::tearDown();
	}

	/**
	 * $wpdb con una o varias keys en memoria, indexadas por su hash
	 * sha256 (igual que hace validate() para buscarlas).
	 */
	private function install_wpdb_fixture( array $keys_by_raw_value ): object {
		global $wpdb;
		$original = $wpdb;

		$rows_by_hash = array();
		foreach ( $keys_by_raw_value as $id => $spec ) {
			$hash = hash( 'sha256', $spec['raw'] );
			$rows_by_hash[ $hash ] = array(
				'id'         => $id,
				'user_id'    => $spec['user_id'] ?? 1,
				'key_prefix' => substr( $spec['raw'], 0, 8 ),
				'scopes'     => $spec['scopes'] ?? 'read',
				'is_active'  => 1,
				'expires_at' => null,
			);
		}

		$wpdb = new class( $rows_by_hash ) {
			public string $prefix = 'wp_';
			private array $rows_by_hash;

			public function __construct( array $rows_by_hash ) { $this->rows_by_hash = $rows_by_hash; }

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				foreach ( $this->rows_by_hash as $hash => $row ) {
					if ( false !== strpos( $sql, $hash ) ) {
						return $row;
					}
				}
				return null;
			}

			public function get_var( $sql ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ): int { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { return 1; }
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
	public function test_two_keys_do_not_share_rate_limit_bucket(): void {
		// Dos keys con los mismos 8 primeros caracteres a propósito
		// (mismo prefijo fijo "atora_" + primeros 2 hex iguales) —
		// exactamente la colisión que permitía el bug de PT-6.1.
		$key_a = 'atora_aa1111111111111111111111111111111111';
		$key_b = 'atora_aa2222222222222222222222222222222222';

		$original = $this->install_wpdb_fixture( array(
			101 => array( 'raw' => $key_a, 'scopes' => 'read' ),
			102 => array( 'raw' => $key_b, 'scopes' => 'read' ),
		) );

		// Agotar el límite de lectura (100/min) para la key A.
		for ( $i = 0; $i < 100; $i++ ) {
			$row = \ATORA_API_Key_Service::validate( $key_a, 'read' );
			$this->assertNotNull( $row, "la key A no debería bloquearse antes del límite (intento {$i})" );
		}
		$this->assertNull( \ATORA_API_Key_Service::validate( $key_a, 'read' ), 'la key A debe bloquearse al superar su límite' );

		// La key B, con el mismo prefijo de 8 caracteres, no debe verse afectada.
		$this->assertNotNull(
			\ATORA_API_Key_Service::validate( $key_b, 'read' ),
			'una key distinta con el mismo key_prefix no debe compartir bucket de rate limit'
		);

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_write_operation_uses_write_limit_not_default(): void {
		$key = 'atora_bb3333333333333333333333333333333333';
		$original = $this->install_wpdb_fixture( array(
			201 => array( 'raw' => $key, 'scopes' => 'read,write' ),
		) );

		// El límite de 'write' es 20/min — el bug anterior caía siempre
		// al default de 100 sin importar el scope real.
		for ( $i = 0; $i < 20; $i++ ) {
			$row = \ATORA_API_Key_Service::validate( $key, 'write' );
			$this->assertNotNull( $row, "no debería bloquearse antes del límite de write (intento {$i})" );
		}
		$this->assertNull(
			\ATORA_API_Key_Service::validate( $key, 'write' ),
			'una operación de escritura debe topar en 20/min, no en el default de 100'
		);

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_read_and_write_operations_have_independent_buckets_for_same_key(): void {
		$key = 'atora_cc4444444444444444444444444444444444';
		$original = $this->install_wpdb_fixture( array(
			301 => array( 'raw' => $key, 'scopes' => 'read,write' ),
		) );

		for ( $i = 0; $i < 20; $i++ ) {
			\ATORA_API_Key_Service::validate( $key, 'write' );
		}
		$this->assertNull( \ATORA_API_Key_Service::validate( $key, 'write' ), 'write agotado' );

		// El bucket de 'read' de la misma key es independiente (bucket
		// por key+minuto, el límite se calcula por operación).
		$this->assertNotNull( \ATORA_API_Key_Service::validate( $key, 'read' ), 'read no debería estar afectado por el consumo de write' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_scope_read_only_key_still_gets_default_limit_for_write_attempt(): void {
		// Una key sin scope 'write' de todos modos recibe un límite
		// numérico coherente (no un error) — has_scope() es quien la
		// rechaza después, en dispatch_tool(), no validate().
		$key = 'atora_dd5555555555555555555555555555555555';
		$original = $this->install_wpdb_fixture( array(
			401 => array( 'raw' => $key, 'scopes' => 'read' ),
		) );

		$row = \ATORA_API_Key_Service::validate( $key, 'write' );
		$this->assertNotNull( $row );
		$this->assertFalse( \ATORA_API_Key_Service::has_scope( $row, 'write' ), 'has_scope() sigue siendo quien autoriza, no validate()' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_mcp_authenticate_derives_operation_from_route(): void {
		// Extremo a extremo por la puerta real: ATORA_MCP_Module::
		// authenticate() es el permission_callback de verdad — confirma
		// que deriva 'write' de la ruta de un tool de escritura
		// (add_tag está en $write_tools de tool_scope()) y aplica el
		// límite de 20/min, no el de 100 por defecto.
		$key = 'atora_ff7777777777777777777777777777777777';
		$original = $this->install_wpdb_fixture( array(
			601 => array( 'raw' => $key, 'scopes' => 'read,write' ),
		) );

		$request = new \WP_REST_Request(
			array(),
			array( 'Authorization' => 'Bearer ' . $key ),
			'/atora/mcp/v1/tools/add_tag'
		);

		for ( $i = 0; $i < 20; $i++ ) {
			$result = \ATORA_MCP_Module::authenticate( $request );
			$this->assertTrue( $result, "intento {$i} no debería bloquearse todavía" );
		}
		$blocked = \ATORA_MCP_Module::authenticate( $request );
		$this->assertInstanceOf( \WP_Error::class, $blocked, 'el intento 21 de un tool de escritura debe bloquearse en el límite de write (20), no en el de 100' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_all_scope_no_longer_gets_its_own_higher_limit(): void {
		// PT-4 (6.5.4): 'all' dejó de tener su propio número — antes
		// una key 'all' aguantaba 200 escrituras/min (diez veces el
		// límite de una key 'write' pura). El límite depende siempre
		// de la operación real, nunca del scope declarado.
		$key = 'atora_ee6666666666666666666666666666666666';
		$original = $this->install_wpdb_fixture( array(
			501 => array( 'raw' => $key, 'scopes' => 'all' ),
		) );

		for ( $i = 0; $i < 20; $i++ ) {
			$row = \ATORA_API_Key_Service::validate( $key, 'write' );
			$this->assertNotNull( $row, "no debería bloquearse antes del límite de write (intento {$i})" );
		}
		$this->assertNull(
			\ATORA_API_Key_Service::validate( $key, 'write' ),
			"una key con scope 'all' debe topar en el límite de write (20/min) igual que una key 'write' pura"
		);

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_all_scope_gets_read_limit_for_read_operations(): void {
		$key = 'atora_gg8888888888888888888888888888888888';
		$original = $this->install_wpdb_fixture( array(
			502 => array( 'raw' => $key, 'scopes' => 'all' ),
		) );

		for ( $i = 0; $i < 100; $i++ ) {
			$row = \ATORA_API_Key_Service::validate( $key, 'read' );
			$this->assertNotNull( $row, "no debería bloquearse antes del límite de read (intento {$i})" );
		}
		$this->assertNull( \ATORA_API_Key_Service::validate( $key, 'read' ) );

		$this->restore_wpdb( $original );
	}
}
