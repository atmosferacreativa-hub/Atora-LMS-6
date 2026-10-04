<?php

declare( strict_types = 1 );

namespace {
	// Mismo stub que VideoThumbnailResolverTest: imagen destacada desde $GLOBALS['__vt']['featured'].
	if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
		function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ) { return $GLOBALS['__vt']['featured'][ (int) $post ] ?? false; }
	}
}

namespace ATORA\Tests\Media {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/media/class-course-cover-resolver.php';
	require_once __DIR__ . '/../../includes/media/class-video-thumbnail-resolver.php';

	/** Tabla `atora_courses` en memoria: filas por id, actualizaciones por wp_post_id. */
	final class CourseCoversWpdb {
		public string $prefix = 'wp_';
		/** @var array<int, array{id:int, wp_post_id:int, thumbnail_url:string}> */
		public array $rows = array();
		public int $updates = 0;

		public function prepare( string $sql, ...$args ): string { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
		public function get_var( $sql ) { return str_starts_with( (string) $sql, 'SHOW TABLES' ) ? 'wp_atora_courses' : null; }
		public function get_results( $sql, $output = ARRAY_A ): array {
			return array_values( array_filter( $this->rows, static fn( array $r ): bool => $r['wp_post_id'] > 0 ) );
		}
		public function update( $table, $data, $where, $format = null, $where_format = null ): int {
			$changed = 0;
			foreach ( $this->rows as &$row ) {
				if ( $row['wp_post_id'] === (int) $where['wp_post_id'] ) {
					$row = array_merge( $row, $data );
					++$changed;
				}
			}
			unset( $row );
			++$this->updates;
			return $changed;
		}
	}

	final class CourseCoverResolverTest extends TestCase {
		private const COURSE    = 29;
		private const WP_COURSE = 428;
		private const OLD       = 'https://academia.test/uploads/vieja.jpg';
		private const NEW       = 'https://academia.test/uploads/nueva-1024x576.jpg';

		private $original_wpdb;
		private CourseCoversWpdb $db;

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['__vt'] = array( 'featured' => array(), 'attachments' => array() );
			$GLOBALS['__atora_test_post_meta'] = array();
			atora_test_reset_post_types();
			atora_test_reset_options();
			atora_test_set_post_type( self::WP_COURSE, 'lm_course' );
			$this->original_wpdb = $GLOBALS['wpdb'];
			$this->db            = new CourseCoversWpdb();
			$GLOBALS['wpdb']     = $this->db;
		}

		protected function tearDown(): void {
			$GLOBALS['wpdb'] = $this->original_wpdb;
			parent::tearDown();
		}

		private function row( int $id, int $wp_post_id, string $thumb ): void {
			$this->db->rows[ $id ] = array( 'id' => $id, 'wp_post_id' => $wp_post_id, 'thumbnail_url' => $thumb );
		}

		private function featured( int $wp_post_id, ?string $url ): void {
			$GLOBALS['__vt']['featured'][ $wp_post_id ] = $url ?? false;
		}

		public function test_course_created_after_migration_uses_featured_image(): void {
			$this->row( self::COURSE, self::WP_COURSE, '' );
			$this->featured( self::WP_COURSE, self::NEW );
			$this->assertSame( self::NEW, \ATORA_Course_Cover_Resolver::resolve( $this->db->rows[ self::COURSE ] ) );
		}

		public function test_changing_featured_image_updates_api_and_table(): void {
			$this->row( self::COURSE, self::WP_COURSE, self::OLD );
			$this->featured( self::WP_COURSE, self::NEW );

			\ATORA_Course_Cover_Resolver::on_thumbnail_meta( 1, self::WP_COURSE, '_thumbnail_id' );

			$this->assertSame( self::NEW, $this->db->rows[ self::COURSE ]['thumbnail_url'] );
			$this->assertSame( self::NEW, \ATORA_Course_Cover_Resolver::resolve( $this->db->rows[ self::COURSE ] ) );
		}

		public function test_removing_featured_image_falls_back_to_column_or_empty(): void {
			$this->row( self::COURSE, self::WP_COURSE, self::OLD );
			$this->featured( self::WP_COURSE, null );
			$this->assertSame( self::OLD, \ATORA_Course_Cover_Resolver::resolve( $this->db->rows[ self::COURSE ] ), 'Sin destacada: la columna.' );

			\ATORA_Course_Cover_Resolver::on_thumbnail_meta( array( 1 ), self::WP_COURSE, '_thumbnail_id' );
			$this->assertSame( '', $this->db->rows[ self::COURSE ]['thumbnail_url'] );
			$this->assertSame( '', \ATORA_Course_Cover_Resolver::resolve( $this->db->rows[ self::COURSE ] ) );
		}

		public function test_other_meta_keys_and_post_types_are_ignored(): void {
			$this->row( self::COURSE, self::WP_COURSE, self::OLD );
			$this->featured( self::WP_COURSE, self::NEW );
			atora_test_set_post_type( 700, 'lm_lesson' );

			\ATORA_Course_Cover_Resolver::on_thumbnail_meta( 1, self::WP_COURSE, '_clms_course_price' );
			\ATORA_Course_Cover_Resolver::on_thumbnail_meta( 1, 700, '_thumbnail_id' );

			$this->assertSame( 0, $this->db->updates );
		}

		public function test_repair_fills_only_courses_that_differ_and_is_repeatable(): void {
			$this->row( 1, 101, '' );                 // creado tras migrar: se repara
			$this->row( 2, 102, self::OLD );          // imagen cambiada: se repara
			$this->row( 3, 103, self::NEW );          // ya correcto: no se toca
			$this->row( 4, 104, self::OLD );          // sin destacada: no se toca
			$this->row( 5, 0, '' );                   // sin post: fuera del recorrido
			$this->featured( 101, self::NEW );
			$this->featured( 102, self::NEW );
			$this->featured( 103, self::NEW );

			$dry = \ATORA_Course_Cover_Resolver::repair_all( false );
			$this->assertSame( array( 'scanned' => 4, 'repaired' => 2 ), $dry );
			$this->assertSame( 0, $this->db->updates, 'El dry-run no escribe.' );

			$this->assertSame( array( 'scanned' => 4, 'repaired' => 2 ), \ATORA_Course_Cover_Resolver::repair_all() );
			$this->assertSame( 2, $this->db->updates );
			$this->assertSame( self::NEW, $this->db->rows[1]['thumbnail_url'] );
			$this->assertSame( self::NEW, $this->db->rows[2]['thumbnail_url'] );
			$this->assertSame( self::OLD, $this->db->rows[4]['thumbnail_url'] );

			$this->assertSame( array( 'scanned' => 4, 'repaired' => 0 ), \ATORA_Course_Cover_Resolver::repair_all(), 'Repetir no cambia nada.' );
			$this->assertSame( 2, $this->db->updates );
		}

		public function test_upgrade_repair_runs_once(): void {
			$this->row( 1, 101, '' );
			$this->featured( 101, self::NEW );

			\ATORA_Course_Cover_Resolver::maybe_repair();
			$this->assertSame( self::NEW, $this->db->rows[1]['thumbnail_url'] );
			$this->assertSame( 1, get_option( \ATORA_Course_Cover_Resolver::REPAIR_OPTION )['repaired'] );

			$this->db->rows[1]['thumbnail_url'] = '';
			\ATORA_Course_Cover_Resolver::maybe_repair();
			$this->assertSame( '', $this->db->rows[1]['thumbnail_url'], 'La segunda carga no vuelve a recorrer.' );
		}

		public function test_video_thumbnail_last_step_uses_course_cover(): void {
			$this->featured( self::WP_COURSE, self::NEW );
			$this->assertSame( self::NEW, \ATORA_Video_Thumbnail_Resolver::resolve( 0, '', '', self::WP_COURSE ) );
		}
	}
}
