<?php
/**
 * LMS_Migrator::migrate_all() — reintento de lecciones faltantes para cursos ya migrados.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS {
	use PHPUnit\Framework\TestCase;

	final class MigratorRetryMissingLessonsTest extends TestCase {
		private object $original_wpdb;

		protected function setUp(): void {
			parent::setUp();
			$this->original_wpdb = $this->install_wpdb_fixture();

			foreach ( array( 200, 201 ) as $lesson_id ) {
				atora_test_set_post(
					$lesson_id,
					array(
						'post_title'   => 'Lesson ' . $lesson_id,
						'post_name'    => 'lesson-' . $lesson_id,
						'post_content' => 'content',
						'menu_order'   => 0,
						'post_status'  => 'publish',
					)
				);
				atora_test_set_post_type( $lesson_id, 'lm_lesson' );
				atora_test_set_post_meta( $lesson_id, '_clms_section', 'General' );
				atora_test_set_post_meta( $lesson_id, '_clms_lesson_type', 'text' );
				atora_test_set_post_meta( $lesson_id, '_clms_section_order', 0 );
				atora_test_set_post_meta( $lesson_id, '_clms_duration', 0 );
				atora_test_set_post_meta( $lesson_id, '_clms_free_preview', 0 );
				atora_test_set_post_meta( $lesson_id, '_clms_lesson_extra_videos', array() );
				atora_test_set_post_meta( $lesson_id, '_clms_lesson_video_url', '' );
				atora_test_set_post_meta( $lesson_id, '_clms_video_url', '' );
			}
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
				public string $postmeta = 'wp_postmeta';
				public string $usermeta = 'wp_usermeta';
				public string $users    = 'wp_users';
				public int $insert_id   = 0;
				public array $queries   = array();

				/** @var int[] */
				public array $missing_lessons = array( 200, 201 );
				/** @var array<int,int> */
				public array $menu_orders = array( 200 => 0, 201 => 0 );
				/** @var array<int,array> */
				public array $inserted_lessons = array();
				public int $updates = 0;
				public bool $course_link_is_valid = true;
				public bool $malformed_meta = false;
				public bool $mixed_meta = false;
				public bool $conflicting_meta = false;
				public bool $duplicate_meta_rows = false;

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

				public function get_col( $sql ) { return array(); }

				public function get_results( $sql, $output = OBJECT ) {
					$sql = (string) $sql;
					$this->queries[] = $sql;
					if ( false !== strpos( $sql, 'SELECT l.ID AS lesson_wp_id' ) ) {
						$has_course_validity_join = false !== strpos( $sql, 'INNER JOIN wp_posts p' )
							&& false !== strpos( $sql, "p.post_type = 'lm_course'" )
							&& false !== strpos( $sql, "p.post_status IN ('publish','draft','private')" );
						if ( $has_course_validity_join && ! $this->course_link_is_valid ) {
							return array();
						}
						if ( $this->malformed_meta && false !== strpos( $sql, "SUM(meta_value REGEXP '^[0-9]+$') = COUNT(*)" ) ) {
							return array();
						}
						if ( $this->mixed_meta && false !== strpos( $sql, "SUM(meta_value REGEXP '^[0-9]+$') = COUNT(*)" ) ) {
							return array();
						}
						if ( $this->conflicting_meta && false !== strpos( $sql, 'HAVING COUNT(DISTINCT meta_value) = 1' ) ) {
							return array();
						}

						$limit = 50;
						if ( preg_match( '/\\bLIMIT\\s+(\\d+)\\b/', $sql, $m ) ) {
							$limit = (int) $m[1];
						}

						$has_dedupe = false !== strpos( $sql, 'GROUP BY post_id' )
							&& false !== strpos( $sql, 'HAVING COUNT(DISTINCT meta_value) = 1' );
						$pool = $this->missing_lessons;
						if ( $this->duplicate_meta_rows && ! $has_dedupe ) {
							$pool = array( 200, 200, 201 );
						}

						$pending = array();
						foreach ( $pool as $lesson_id ) {
							if ( isset( $this->inserted_lessons[ $lesson_id ] ) ) {
								continue;
							}
							$pending[] = array(
								'lesson_wp_id'     => $lesson_id,
								'wp_course_id'     => 123,
								'atora_course_id'  => 10,
							);
							if ( count( $pending ) >= $limit ) {
								break;
							}
						}
						return $pending;
					}

					if ( false !== strpos( $sql, 'SELECT l.ID, l.menu_order' ) ) {
						$rows = array();
						foreach ( $this->missing_lessons as $lesson_id ) {
							$rows[] = array(
								'ID' => $lesson_id,
								'menu_order' => $this->menu_orders[ $lesson_id ] ?? 0,
							);
						}
						usort( $rows, static function( array $a, array $b ): int {
							$ao = (int) ( $a['menu_order'] ?? 0 );
							$bo = (int) ( $b['menu_order'] ?? 0 );
							if ( $ao === $bo ) {
								return (int) $a['ID'] <=> (int) $b['ID'];
							}
							return $ao <=> $bo;
						} );
						return $rows;
					}

					return array();
				}

				public function get_var( $sql ) {
					$sql = (string) $sql;
					$this->queries[] = $sql;
					if ( false !== strpos( $sql, 'FROM wp_atora_lessons WHERE wp_post_id' ) ) {
						if ( preg_match( '/wp_post_id\\s*=\\s*(\\d+)/', $sql, $m ) ) {
							$wp_post_id = (int) $m[1];
							return $this->inserted_lessons[ $wp_post_id ]['id'] ?? 0;
						}
						return 0;
					}
					return 0;
				}

				public function get_row( $sql, $output = OBJECT ) { return null; }

				public function insert( $table, $data, $format = null ): int {
					if ( false !== strpos( (string) $table, 'atora_lessons' ) ) {
						$this->insert_id++;
						$row = (array) $data;
						$row['id'] = $this->insert_id;
						$this->inserted_lessons[ (int) ( $row['wp_post_id'] ?? 0 ) ] = $row;
						return 1;
					}
					return 1;
				}

				public function update( $table, $data, $where, $format = null, $where_format = null ): int {
					$this->updates++;
					return 1;
				}

				public function delete( ...$a ) { return 1; }
				public function query( $sql ): int { return 1; }
				public function esc_like( string $s ): string { return $s; }
				public function get_charset_collate(): string { return ''; }
			};

			return $original;
		}

		private function restore_wpdb( object $original ): void {
			global $wpdb;
			$wpdb = $original;
			atora_test_reset_posts();
			atora_test_reset_post_meta();
			atora_test_reset_options();
		}

		/** @test */
		public function test_migrate_all_retries_missing_lessons_for_already_migrated_course_in_bounded_batches(): void {
			global $wpdb;

			$this->assertSame( 0, count( $wpdb->inserted_lessons ) );
			$r = \ATORA\LMS\LMS_Migrator::migrate_all( 1 );
			$this->assertSame( 1, (int) ( $r['lessons_retried'] ?? -1 ) );
			$this->assertSame( 1, count( $wpdb->inserted_lessons ), 'respeta batch=1' );
			$this->assertSame( 0, $wpdb->updates, 'no debe actualizar filas existentes' );

			$r = \ATORA\LMS\LMS_Migrator::migrate_all( 1 );
			$this->assertSame( 1, (int) ( $r['lessons_retried'] ?? -1 ) );
			$this->assertSame( 2, count( $wpdb->inserted_lessons ), 'segunda ejecución inserta la siguiente lección faltante' );
			$this->assertSame( 0, $wpdb->updates, 'no debe modificar filas ya insertadas' );

			$r = \ATORA\LMS\LMS_Migrator::migrate_all( 1 );
			$this->assertSame( 0, (int) ( $r['lessons_retried'] ?? -1 ) );
			$this->assertSame( 2, count( $wpdb->inserted_lessons ), 'tercera ejecución no duplica' );
			$this->assertSame( 0, $wpdb->updates, 'no debe actualizar en reintentos sin pendientes' );

			$this->assertArrayHasKey( 200, $wpdb->inserted_lessons );
			$this->assertArrayHasKey( 201, $wpdb->inserted_lessons );
			$this->assertSame( 10, (int) $wpdb->inserted_lessons[200]['course_id'] );
		}

		/** @test */
		public function test_retry_skips_lessons_when_course_wp_link_is_broken(): void {
			global $wpdb;
			$wpdb->course_link_is_valid = false;

			\ATORA\LMS\LMS_Migrator::migrate_all( 10 );

			$this->assertSame( 0, count( $wpdb->inserted_lessons ), 'no debe crear filas si el curso CPT no es válido' );
			$this->assertSame( 0, $wpdb->updates, 'no debe actualizar nada en este escenario' );
			$this->assertNotEmpty(
				array_filter( $wpdb->queries, fn( $q ) => false !== strpos( (string) $q, 'INNER JOIN wp_posts p' ) ),
				'la query del reintento debe validar el CPT del curso'
			);
		}

		/** @test */
		public function test_retry_ignores_malformed_course_id_meta(): void {
			global $wpdb;
			$wpdb->malformed_meta = true;

			\ATORA\LMS\LMS_Migrator::migrate_all( 10 );

			$this->assertSame( 0, count( $wpdb->inserted_lessons ) );
			$this->assertNotEmpty( array_filter( $wpdb->queries, fn( $q ) => false !== strpos( (string) $q, "SUM(meta_value REGEXP '^[0-9]+$') = COUNT(*)" ) ) );
		}

		/** @test */
		public function test_retry_skips_mixed_numeric_and_garbage_course_id_meta(): void {
			global $wpdb;
			$wpdb->mixed_meta = true;

			\ATORA\LMS\LMS_Migrator::migrate_all( 10 );

			$this->assertSame( 0, count( $wpdb->inserted_lessons ), 'si hay 428 + 428basura, no debe aceptar el ID numérico' );
			$this->assertNotEmpty( array_filter( $wpdb->queries, fn( $q ) => false !== strpos( (string) $q, "SUM(meta_value REGEXP '^[0-9]+$') = COUNT(*)" ) ) );
		}

		/** @test */
		public function test_retry_ignores_conflicting_course_id_meta(): void {
			global $wpdb;
			$wpdb->conflicting_meta = true;

			\ATORA\LMS\LMS_Migrator::migrate_all( 10 );

			$this->assertSame( 0, count( $wpdb->inserted_lessons ) );
			$this->assertNotEmpty( array_filter( $wpdb->queries, fn( $q ) => false !== strpos( (string) $q, 'HAVING COUNT(DISTINCT meta_value) = 1' ) ) );
		}

		/** @test */
		public function test_retry_deduplicates_lessons_before_applying_limit(): void {
			global $wpdb;
			$wpdb->duplicate_meta_rows = true;

			\ATORA\LMS\LMS_Migrator::migrate_all( 2 );

			$this->assertSame( 2, count( $wpdb->inserted_lessons ), 'batch=2 no debe desperdiciarse en duplicados de meta' );
		}

		/** @test */
		public function test_retry_preserves_relative_order_when_menu_order_is_zero(): void {
			global $wpdb;
			$wpdb->menu_orders = array( 200 => 0, 201 => 0 );

			\ATORA\LMS\LMS_Migrator::migrate_all( 10 );

			$this->assertSame( 2, count( $wpdb->inserted_lessons ) );
			$this->assertSame( 1, (int) ( $wpdb->inserted_lessons[200]['lesson_order'] ?? 0 ) );
			$this->assertSame( 2, (int) ( $wpdb->inserted_lessons[201]['lesson_order'] ?? 0 ) );
		}
	}
}
