<?php
/**
 * LMS_Parity::get_volume_stats() — volumen observado debe ser intersección.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

final class ParityVolumeStatsTest extends TestCase {
	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * @param int[] $active_user_ids
	 * @param int[] $read_user_ids
	 */
	private function swap_wpdb( array $active_user_ids, array $read_user_ids ): void {
		global $wpdb;
		$active = array_fill_keys( array_map( 'absint', $active_user_ids ), true );
		$reads  = array_fill_keys( array_map( 'absint', $read_user_ids ), true );

		$wpdb = new class( $active, $reads ) {
			public string $prefix = 'wp_';
			public string $posts = 'wp_posts';
			public array $queries = array();
			private array $active;
			private array $reads;

			public function __construct( array $active, array $reads ) {
				$this->active = $active;
				$this->reads  = $reads;
			}

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
				$sql = (string) $sql;
				$this->queries[] = $sql;

				// Active users: COUNT DISTINCT from enrollments.
				if ( str_contains( $sql, 'FROM wp_atora_enrollments' ) && ! str_contains( $sql, 'JOIN' ) ) {
					return count( $this->active );
				}

				// Observed users: intersection(active, reads) via join.
				if ( str_contains( $sql, 'FROM wp_atora_enrollments e' ) && str_contains( $sql, 'INNER JOIN wp_atora_lms_parity_reads r' ) ) {
					$intersection = array_intersect_key( $this->active, $this->reads );
					return count( $intersection );
				}

				return 0;
			}
		};
	}

	/** @test */
	public function test_disjoint_sets_fail_gate_even_when_same_size(): void {
		$this->swap_wpdb( array( 1, 2, 3 ), array( 4, 5, 6 ) );

		$stats = \ATORA\LMS\LMS_Parity::get_volume_stats();

		$this->assertSame( 3, $stats['active_users'] );
		$this->assertSame( 0, $stats['observed_users'] );
		$this->assertFalse( $stats['volume_ok'] );

		global $wpdb;
		$this->assertCount( 2, $wpdb->queries, 'sin N+1: solo dos queries (active + observed)' );
	}

	/** @test */
	public function test_partial_coverage_fails_gate(): void {
		$this->swap_wpdb( array( 1, 2, 3 ), array( 1 ) );

		$stats = \ATORA\LMS\LMS_Parity::get_volume_stats();

		$this->assertSame( 3, $stats['active_users'] );
		$this->assertSame( 1, $stats['observed_users'] );
		$this->assertFalse( $stats['volume_ok'] );
	}

	/** @test */
	public function test_full_coverage_passes_gate_and_ignores_non_active_reads(): void {
		$this->swap_wpdb( array( 1, 2, 3 ), array( 1, 2, 3, 999 ) );

		$stats = \ATORA\LMS\LMS_Parity::get_volume_stats();

		$this->assertSame( 3, $stats['active_users'] );
		$this->assertSame( 3, $stats['observed_users'], 'solo cuenta alumnos activos observados, no el total de reads' );
		$this->assertTrue( $stats['volume_ok'] );
	}
}
