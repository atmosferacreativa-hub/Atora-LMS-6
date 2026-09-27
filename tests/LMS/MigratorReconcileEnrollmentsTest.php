<?php
/**
 * LMS_Migrator::reconcile() — reconciliación de matrículas.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace {
	// LMS_Migrator::reconcile() usa esc_sql(); en los tests no hay WP completo.
	if ( ! function_exists( 'esc_sql' ) ) {
		function esc_sql( $s ): string {
			return (string) $s;
		}
	}

	if ( ! function_exists( 'maybe_unserialize' ) ) {
		function maybe_unserialize( $value ) {
			if ( ! is_string( $value ) ) {
				return $value;
			}
			$trim = trim( $value );
			if ( '' === $trim ) {
				return $value;
			}
			try {
				$un = @unserialize( $trim, array( 'allowed_classes' => false ) );
				return false === $un && 'b:0;' !== $trim ? $value : $un;
			} catch ( \Throwable $e ) {
				return $value;
			}
		}
	}
}
namespace ATORA\Tests\LMS {

	use PHPUnit\Framework\TestCase;

	final class MigratorReconcileEnrollmentsTest extends TestCase {
		private object $original_wpdb;

		protected function setUp(): void {
			parent::setUp();
			$this->original_wpdb = $this->install_wpdb_fixture();
		}

		protected function tearDown(): void {
			$this->restore_wpdb( $this->original_wpdb );
			parent::tearDown();
		}

		private function install_wpdb_fixture(): object {
			global $wpdb;
			$original = $wpdb;

			$wpdb = new class {
				public string $prefix   = 'wp_';
				public string $posts    = 'wp_posts';
				public string $users    = 'wp_users';
				public string $usermeta = 'wp_usermeta';

				/** @var array<int,int> */
				public array $valid_wp_course_ids = array();
				/** @var array<int,array{id:int,wp_post_id:int}> */
				public array $atora_courses_rows = array();
				/** @var array<int,array{user_id:int,meta_value:mixed}> */
				public array $enrolled_courses_usermeta_rows = array();
				/** @var array<int,array{user_id:int,course_id:int}> */
				public array $atora_enrollments_rows = array();
				/** @var array<int,true> */
				public array $existing_user_ids = array( 1 => true );

				public function prepare( string $sql, ...$args ): string {
					$i = 0;
					return preg_replace_callback( '/%[ds]/', function( $match ) use ( &$i, $args ) {
						if ( ! isset( $args[ $i ] ) ) {
							return '?';
						}
						$value = $args[ $i++ ];
						return '%s' === $match[0] ? "'" . (string) $value . "'" : (string) $value;
					}, $sql );
				}

				public function get_var( $sql ) {
					$sql = (string) $sql;

					// Por default, reconcile() no debe reportar divergencias fuera de matrículas.
					if ( false !== strpos( $sql, "post_type = 'lm_course'" ) || false !== strpos( $sql, "post_type = 'lm_lesson'" ) ) {
						return 0;
					}
					if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
						return '';
					}
					if ( false !== strpos( $sql, 'FROM wp_atora_enrollments' ) && false !== strpos( $sql, 'COUNT' ) ) {
						return 0;
					}
					return 0;
				}

				public function get_results( $sql, $output = OBJECT ) {
					$sql = (string) $sql;

					if ( false !== strpos( $sql, 'SELECT id, wp_post_id FROM wp_atora_courses' ) ) {
						return array_map( static function( array $row ) {
							return (object) array(
								'id'        => $row['id'],
								'wp_post_id' => $row['wp_post_id'],
							);
						}, $this->atora_courses_rows );
					}

					if ( false !== strpos( $sql, "FROM wp_usermeta WHERE meta_key = '_clms_enrolled_courses'" ) ) {
						return array_map( static function( array $row ) {
							return (object) array(
								'user_id'    => $row['user_id'],
								'meta_value' => $row['meta_value'],
							);
						}, $this->enrolled_courses_usermeta_rows );
					}

					if ( false !== strpos( $sql, 'SELECT user_id, course_id FROM wp_atora_enrollments' ) ) {
						return array_map( static function( array $row ) {
							return (object) array(
								'user_id'   => $row['user_id'],
								'course_id' => $row['course_id'],
							);
						}, $this->atora_enrollments_rows );
					}

					// Progreso/programas/etc: vacío por default.
					return array();
				}

				public function get_col( $sql ) {
					$sql = (string) $sql;
					if ( false !== strpos( $sql, "FROM wp_posts WHERE post_type = 'lm_course'" ) ) {
						return $this->valid_wp_course_ids;
					}
					if ( false !== strpos( $sql, 'FROM wp_atora_enrollments e' )
						&& false !== strpos( $sql, 'LEFT JOIN wp_users u' )
						&& false !== strpos( $sql, 'u.ID IS NULL' )
					) {
						$missing = array();
						foreach ( $this->atora_enrollments_rows as $row ) {
							$uid = (int) $row['user_id'];
							if ( ! isset( $this->existing_user_ids[ $uid ] ) ) {
								$missing[ $uid ] = true;
							}
						}
						return array_keys( $missing );
					}
					return array();
				}

				public function insert( ...$a ) { return 1; }
				public function update( ...$a ) { return 1; }
				public function delete( ...$a ) { return 1; }
				public function query( $sql ) { return 1; }
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
		public function test_reconcile_ignores_native_courses_when_comparing_against_usermeta(): void {
			global $wpdb;
			$wpdb->atora_courses_rows = array(
				array( 'id' => 10, 'wp_post_id' => 0 ), // curso nativo
			);
			$wpdb->atora_enrollments_rows = array(
				array( 'user_id' => 1, 'course_id' => 10 ),
			);

			$r = \ATORA\LMS\LMS_Migrator::reconcile();

			$this->assertSame( 0, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 0, $r['enrollments_missing_in_table'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_user'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_course_id'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_broken_wp_link'] );
		}

		/** @test */
		public function test_reconcile_counts_valid_cpt_without_table_mirror_as_blocked_by_course(): void {
			global $wpdb;
			$wpdb->valid_wp_course_ids = array( 123 );
			$wpdb->enrolled_courses_usermeta_rows = array(
				array( 'user_id' => 1, 'meta_value' => serialize( array( 123 ) ) ),
			);
			// No hay fila en wp_atora_courses para wp_post_id=123.

			$r = \ATORA\LMS\LMS_Migrator::reconcile();

			$this->assertSame( 1, $r['enrollments_blocked_by_course'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_broken_wp_link'] );
			$this->assertSame( 0, $r['enrollments_missing_in_table'] );
			$this->assertSame( 0, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_user'] );
		}

		/** @test */
		public function test_reconcile_tracks_broken_wp_links_as_blocked(): void {
			global $wpdb;
			$wpdb->atora_courses_rows = array(
				array( 'id' => 20, 'wp_post_id' => 777 ), // vínculo roto: el post no existe/migrable
			);
			$wpdb->atora_enrollments_rows = array(
				array( 'user_id' => 1, 'course_id' => 20 ),
			);
			$wpdb->valid_wp_course_ids = array();

			$r = \ATORA\LMS\LMS_Migrator::reconcile();

			$this->assertSame( 1, $r['enrollments_blocked_by_broken_wp_link'] );
			$this->assertSame( 0, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_user'] );
		}

		/** @test */
		public function test_reconcile_tracks_enrollments_with_missing_course_id_as_blocked(): void {
			global $wpdb;
			$wpdb->atora_courses_rows = array();
			$wpdb->atora_enrollments_rows = array(
				array( 'user_id' => 1, 'course_id' => 999 ),
			);

			$r = \ATORA\LMS\LMS_Migrator::reconcile();

			$this->assertSame( 1, $r['enrollments_blocked_by_missing_course_id'] );
			$this->assertSame( 0, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_user'] );
		}

		/** @test */
		public function test_reconcile_counts_table_enrollment_missing_in_legacy_then_clears_when_legacy_mirror_is_added(): void {
			global $wpdb;
			$wpdb->valid_wp_course_ids = array( 123 );
			$wpdb->atora_courses_rows = array(
				array( 'id' => 10, 'wp_post_id' => 123 ),
			);
			$wpdb->atora_enrollments_rows = array(
				array( 'user_id' => 1, 'course_id' => 10 ),
			);

			$r = \ATORA\LMS\LMS_Migrator::reconcile();
			$this->assertSame( 1, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_user'] );

			$wpdb->enrolled_courses_usermeta_rows = array(
				array( 'user_id' => 1, 'meta_value' => serialize( array( 123 ) ) ),
			);

			$r = \ATORA\LMS\LMS_Migrator::reconcile();
			$this->assertSame( 0, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_user'] );
		}

		/** @test */
		public function test_reconcile_moves_enrollment_for_missing_user_to_blocked_by_missing_user(): void {
			global $wpdb;
			$wpdb->valid_wp_course_ids = array( 123 );
			$wpdb->atora_courses_rows = array(
				array( 'id' => 10, 'wp_post_id' => 123 ),
			);
			$wpdb->atora_enrollments_rows = array(
				array( 'user_id' => 999, 'course_id' => 10 ),
			);
			$wpdb->existing_user_ids = array( 1 => true );

			$r = \ATORA\LMS\LMS_Migrator::reconcile();
			$this->assertSame( 0, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 1, $r['enrollments_blocked_by_missing_user'] );
		}

		/** @test */
		public function test_reconcile_keeps_existing_user_without_usermeta_as_missing_in_usermeta(): void {
			global $wpdb;
			$wpdb->valid_wp_course_ids = array( 123 );
			$wpdb->atora_courses_rows = array(
				array( 'id' => 10, 'wp_post_id' => 123 ),
			);
			$wpdb->atora_enrollments_rows = array(
				array( 'user_id' => 77, 'course_id' => 10 ),
			);
			$wpdb->existing_user_ids = array( 77 => true );

			$r = \ATORA\LMS\LMS_Migrator::reconcile();
			$this->assertSame( 1, $r['enrollments_missing_in_usermeta'] );
			$this->assertSame( 0, $r['enrollments_blocked_by_missing_user'] );
		}
	}
}
