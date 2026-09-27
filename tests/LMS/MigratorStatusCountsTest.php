<?php
/**
 * LMS_Migrator::get_status() — conteo de migrados debe ignorar vínculos rotos.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace {
	// `LMS_Migrator::reconcile()` usa esc_sql(); en los tests no hay WP completo.
	if ( ! function_exists( 'esc_sql' ) ) {
		function esc_sql( $s ): string {
			return (string) $s;
		}
	}
}

namespace ATORA\Tests\LMS {
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class MigratorStatusCountsTest extends TestCase {

	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $this->install_wpdb_fixture();

		Functions\when( 'wp_count_posts' )->alias(
			static function( string $post_type ) {
				return match ( $post_type ) {
					'lm_course' => (object) array(
						'publish' => 2,
						'draft'   => 1,
						'private' => 1,
						'trash'   => 9,
					),
					'lm_lesson' => (object) array(
						'publish' => 2,
						'draft'   => 2,
						'private' => 0,
						'trash'   => 9,
					),
					default => (object) array(),
				};
			}
		);
	}

	protected function tearDown(): void {
		$this->restore_wpdb( $this->original_wpdb );
		parent::tearDown();
	}

	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public string $posts = 'wp_posts';
			public string $usermeta = 'wp_usermeta';
			public array $queries = array();
			public string $fixture_case = 'normal';

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $match ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) {
						return '?';
					}
					$value = $args[ $i++ ];
					return '%s' === $match[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				$this->queries[] = (string) $sql;

				// Nuevo comportamiento esperado: cuenta CPTs migrables existentes.
				// Este fixture simula DOS filas de tabla apuntando al mismo wp_post_id:
				// con COUNT(*) contaría 3 (inflado), con COUNT(DISTINCT p.ID) cuenta 2.
				if ( false !== strpos( $sql, 'FROM wp_atora_courses c' ) && false !== strpos( $sql, 'INNER JOIN wp_posts p' ) ) {
					if ( 'empty' === $this->fixture_case ) {
						return 0;
					}
					$distinct = false !== strpos( $sql, 'COUNT(DISTINCT p.ID)' );
					return $distinct ? 2 : 3;
				}
				if ( false !== strpos( $sql, 'FROM wp_atora_lessons l' ) && false !== strpos( $sql, 'INNER JOIN wp_posts p' ) ) {
					if ( 'empty' === $this->fixture_case ) {
						return 0;
					}
					$distinct = false !== strpos( $sql, 'COUNT(DISTINCT p.ID)' );
					return $distinct ? 1 : 2;
				}

				// Comportamiento viejo (regresión a evitar): contaba cualquier fila
				// con wp_post_id > 0, incluyendo links rotos, posts en trash o de otro tipo.
				if ( false !== strpos( $sql, 'FROM wp_atora_courses WHERE wp_post_id > 0' ) ) {
					return 5;
				}
				if ( false !== strpos( $sql, 'FROM wp_atora_lessons WHERE wp_post_id > 0' ) ) {
					return 4;
				}

				if ( false !== strpos( $sql, 'FROM wp_atora_enrollments' ) ) {
					return 0;
				}

				// reconcile(): por default sin divergencias en este fixture.
				return 0;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { $this->queries[] = (string) $sql; return 1; }
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
	public function test_get_status_counts_only_rows_linked_to_existing_migratable_cpt(): void {
		$status = \ATORA\LMS\LMS_Migrator::get_status();

			$this->assertSame( 4, $status['cpt_courses'] );
			$this->assertSame( 4, $status['cpt_lessons'] );

			$this->assertSame( 2, $status['migrated_courses'], 'debe excluir links rotos, posts en trash y tipos erróneos' );
			$this->assertSame( 1, $status['migrated_lessons'], 'debe excluir links rotos, posts en trash y tipos erróneos' );

			$this->assertSame( 50.0, $status['courses_pct'] );
			$this->assertSame( 25.0, $status['lessons_pct'] );

		global $wpdb;
		$all_queries = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( 'INNER JOIN wp_posts p', $all_queries );
		$this->assertStringContainsString( 'COUNT(DISTINCT p.ID)', $all_queries, 'usa DISTINCT para que duplicados en tabla no inflen el conteo' );
		$this->assertStringNotContainsString( 'FROM wp_atora_courses WHERE wp_post_id > 0', $all_queries );
		$this->assertStringNotContainsString( 'FROM wp_atora_lessons WHERE wp_post_id > 0', $all_queries );
	}

	/** @test */
	public function test_get_status_defaults_to_100_percent_when_no_migratable_cpts_exist(): void {
		Functions\when( 'wp_count_posts' )->alias(
			static fn( string $post_type ) => (object) array(
				'publish' => 0,
				'draft'   => 0,
				'private' => 0,
				'trash'   => 99,
			)
		);

		global $wpdb;
		$wpdb->fixture_case = 'empty';

		$status = \ATORA\LMS\LMS_Migrator::get_status();
		$this->assertSame( 0, $status['cpt_courses'] );
		$this->assertSame( 0, $status['cpt_lessons'] );
		$this->assertSame( 0, $status['migrated_courses'] );
		$this->assertSame( 0, $status['migrated_lessons'] );
		$this->assertSame( 100.0, $status['courses_pct'] );
		$this->assertSame( 100.0, $status['lessons_pct'] );
	}
}
}
