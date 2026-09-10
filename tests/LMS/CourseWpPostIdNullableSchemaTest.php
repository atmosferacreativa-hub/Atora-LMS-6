<?php
/**
 * atora_courses.wp_post_id — columna nullable — PT-2 (sprint 6.5.3).
 *
 * Hallazgo confirmado: wp_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0
 * con UNIQUE KEY solo permite una fila con 0 — un segundo curso
 * nativo sin CPT asociado fallaba al crearse. Migrado a
 * BIGINT UNSIGNED NULL DEFAULT NULL (MySQL sí permite múltiples NULL
 * bajo un índice único).
 *
 * PT-6.1 (6.5.4) generalizó el método específico de cursos a
 * V5_Installer::migrate_column_nullable( $table_suffix, $column ),
 * compartido con lecciones y programas — ver
 * tests/LMS/AllTablesWpPostIdNullableTest.php para la cobertura de
 * las tres tablas y de la verificación antes de marcar el esquema
 * como actualizado. Este archivo se mantiene con los mismos casos de
 * 6.5.3, adaptados a la nueva firma.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class CourseWpPostIdNullableSchemaTest extends TestCase {

	/**
	 * $wpdb que registra cada query() emitido, para verificar el
	 * ALTER TABLE + UPDATE de la migración sin necesitar un MySQL
	 * real (no disponible en este entorno de test).
	 */
	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public array  $queries = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $match ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $match[0] ? "'" . $value . "'" : (string) $value;
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
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { $this->queries[] = $sql; return 1; }
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
	 * PT-6.1 (6.5.4): la corrección específica de cursos se generalizó
	 * a migrate_column_nullable( $table_suffix, $column ), compartida
	 * con lecciones y programas — se invoca acá con los mismos
	 * argumentos que antes usaba la versión exclusiva de cursos.
	 */
	private function invoke_migration(): void {
		$ref = new \ReflectionMethod( \ATORA\V5_Installer::class, 'migrate_column_nullable' );
		$ref->setAccessible( true );
		$ref->invoke( null, 'atora_courses', 'wp_post_id' );
	}

	/** @test */
	public function test_migration_alters_column_to_nullable(): void {
		$original = $this->install_wpdb_fixture();

		$this->invoke_migration();

		global $wpdb;
		$has_alter = false;
		foreach ( $wpdb->queries as $q ) {
			if ( false !== strpos( $q, 'ALTER TABLE' ) && false !== strpos( $q, 'MODIFY COLUMN wp_post_id BIGINT UNSIGNED NULL DEFAULT NULL' ) ) {
				$has_alter = true;
			}
		}
		$this->assertTrue( $has_alter, 'debe emitir el ALTER TABLE que vuelve nullable la columna' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_migration_converts_existing_zero_to_null(): void {
		$original = $this->install_wpdb_fixture();

		$this->invoke_migration();

		global $wpdb;
		$has_update = false;
		foreach ( $wpdb->queries as $q ) {
			if ( false !== strpos( $q, 'UPDATE' ) && false !== strpos( $q, 'SET wp_post_id = NULL WHERE wp_post_id = 0' ) ) {
				$has_update = true;
			}
		}
		$this->assertTrue( $has_update, 'debe convertir las filas con 0 (sin vínculo legado) a NULL' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_migration_alter_runs_before_update(): void {
		// El ALTER debe ir antes que el UPDATE — si el UPDATE corre
		// primero, la columna sigue NOT NULL y el UPDATE...SET NULL
		// fallaría en un MySQL real.
		$original = $this->install_wpdb_fixture();

		$this->invoke_migration();

		global $wpdb;
		$alter_index  = null;
		$update_index = null;
		foreach ( $wpdb->queries as $i => $q ) {
			if ( false !== strpos( $q, 'ALTER TABLE' ) ) { $alter_index = $i; }
			if ( false !== strpos( $q, 'UPDATE' ) && false !== strpos( $q, 'wp_post_id' ) ) { $update_index = $i; }
		}
		$this->assertNotNull( $alter_index );
		$this->assertNotNull( $update_index );
		$this->assertLessThan( $update_index, $alter_index );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_migration_skips_silently_when_table_does_not_exist(): void {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public array  $queries = array();
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function get_var( $sql ) { return null; } // SHOW TABLES nunca encuentra la tabla
			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { $this->queries[] = $sql; return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		$this->invoke_migration();

		$this->assertSame( array(), $wpdb->queries, 'sin la tabla, no debe intentar ALTER/UPDATE' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_format_course_treats_null_wp_post_id_as_zero(): void {
		// Regresión: tras la migración, get_row() devuelve NULL en vez
		// de 0 para cursos sin vínculo — format_course() debe seguir
		// exponiendo 0 en la respuesta de la API, sin romper el
		// contrato externo.
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function get_row( $sql, $output = 'ARRAY_A' ) {
				return array(
					'id'            => 55,
					'wp_post_id'    => null,
					'instructor_id' => 2,
					'title'         => 'Curso nativo',
					'status'        => 'published',
				);
			}
			public function get_var( $sql ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		$course = \ATORA\LMS\LMS_Course_Service::get( 55 );

		$this->assertIsArray( $course );
		$this->assertSame( 0, $course['wp_post_id'] );

		$wpdb = $original;
	}
}
