<?php
/**
 * LMS_Migrator — no confiar ciegamente en "la fila ya existe" — PT-3.1 (sprint 6.5.3).
 *
 * Condición de bloqueo de la OT resuelta antes de tocar código (regla
 * 7): confirmado con el responsable del proyecto que sí hay
 * instalaciones reales (o no se sabe con certeza) donde el REST del
 * LMS de tablas pudo haber aceptado escrituras durante una ventana de
 * migración activa — se aplican 3.1 y 3.2 completos.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class MigrationWindowDiscrepancyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_posts();
		atora_test_reset_post_meta();
		atora_test_reset_post_types();
	}

	protected function tearDown(): void {
		atora_test_reset_posts();
		atora_test_reset_post_meta();
		atora_test_reset_post_types();
		parent::tearDown();
	}

	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public array  $inserted = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/'([^']+)'/", $sql, $m ) ) {
					return $m[1];
				}
				return null;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { $this->inserted[] = array( 'table' => $table, 'data' => $data ); return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
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

	private function invoke( int $post_id, int $row_instructor_id ): void {
		$ref = new \ReflectionMethod( \ATORA\LMS\LMS_Migrator::class, 'log_migration_discrepancy_if_instructor_mismatch' );
		$ref->setAccessible( true );
		$ref->invoke( null, $post_id, $row_instructor_id );
	}

	/** @test */
	public function test_logs_discrepancy_when_instructor_mismatches(): void {
		atora_test_set_post( 800, array( 'post_author' => 7 ) );
		// Sin _clms_instructor_ids — cae al post_author (7).
		$original = $this->install_wpdb_fixture();

		$this->invoke( 800, 999 ); // la fila dice instructor 999, el CPT dice 7

		global $wpdb;
		$this->assertCount( 1, $wpdb->inserted );
		$this->assertSame( 'wp_atora_lms_parity_log', $wpdb->inserted[0]['table'] );
		$this->assertSame( 'migrator_instructor_mismatch', $wpdb->inserted[0]['data']['reader'] );
		$this->assertStringContainsString( 'instructor_id=7', $wpdb->inserted[0]['data']['legacy_summary'] );
		$this->assertStringContainsString( 'instructor_id=999', $wpdb->inserted[0]['data']['table_summary'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_no_log_when_instructor_matches(): void {
		atora_test_set_post( 800, array( 'post_author' => 7 ) );
		$original = $this->install_wpdb_fixture();

		$this->invoke( 800, 7 ); // coincide

		global $wpdb;
		$this->assertCount( 0, $wpdb->inserted, 'sin discrepancia, no debe escribir nada' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_prefers_explicit_instructor_meta_over_post_author(): void {
		atora_test_set_post( 800, array( 'post_author' => 7 ) );
		atora_test_set_post_meta( 800, '_clms_instructor_ids', array( 42 ) );
		$original = $this->install_wpdb_fixture();

		// La fila coincide con el instructor_ids explícito (42), no con post_author (7).
		$this->invoke( 800, 42 );

		global $wpdb;
		$this->assertCount( 0, $wpdb->inserted );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_does_not_overwrite_anything_only_logs(): void {
		// El propio diseño de log_migration_discrepancy_if_instructor_mismatch()
		// no llama a ningún update()/insert() sobre atora_courses — la
		// única escritura posible es el insert() al log de paridad.
		atora_test_set_post( 800, array( 'post_author' => 7 ) );
		$original = $this->install_wpdb_fixture();

		$this->invoke( 800, 999 );

		global $wpdb;
		foreach ( $wpdb->inserted as $call ) {
			$this->assertStringNotContainsString( 'atora_courses', $call['table'], 'nunca debe escribir en atora_courses — solo registrar' );
		}

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_silently_returns_when_post_missing(): void {
		// wp_post_id ya no corresponde a ningún post (borrado) — no
		// hay CPT contra el cual comparar, no debe fallar.
		$original = $this->install_wpdb_fixture();

		$this->invoke( 9999, 1 );

		global $wpdb;
		$this->assertCount( 0, $wpdb->inserted );

		$this->restore_wpdb( $original );
	}
}
