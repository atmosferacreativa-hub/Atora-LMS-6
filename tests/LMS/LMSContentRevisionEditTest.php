<?php
/**
 * revision — control de concurrencia para ediciones de cursos/lecciones.
 *
 * Las ediciones válidas deben incrementar revision exactamente una vez.
 * Conflictos (revision esperada distinta) y fallos de persistencia no
 * deben incrementar.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

final class LMSContentRevisionEditTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_caps();
		atora_test_reset_post_types();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		atora_test_reset_user_caps();
		atora_test_reset_post_types();
		parent::tearDown();
	}

	private function install_wpdb_fixture( int $course_id, int $course_revision, int $lesson_id, int $lesson_revision, $update_return ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $course_id, $course_revision, $lesson_id, $lesson_revision, $update_return ) {
			public string $prefix = 'wp_';
			public array $last_update_data = array();
			public array $last_update_where = array();
			public string $last_update_table = '';
			private int $course_id;
			private int $course_revision;
			private int $lesson_id;
			private int $lesson_revision;
			private $update_return;

			public function __construct( int $course_id, int $course_revision, int $lesson_id, int $lesson_revision, $update_return ) {
				$this->course_id = $course_id;
				$this->course_revision = $course_revision;
				$this->lesson_id = $lesson_id;
				$this->lesson_revision = $lesson_revision;
				$this->update_return = $update_return;
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( (string) $sql, 'atora_courses' ) && preg_match( '/id = (\d+)/', (string) $sql, $m ) && (int) $m[1] === $this->course_id ) {
					return array( 'id' => $this->course_id, 'revision' => $this->course_revision, 'instructor_id' => 0, 'status' => 'published', 'wp_post_id' => 0 );
				}
				if ( false !== strpos( (string) $sql, 'atora_lessons' ) && preg_match( '/id = (\d+)/', (string) $sql, $m ) && (int) $m[1] === $this->lesson_id ) {
					return array( 'id' => $this->lesson_id, 'revision' => $this->lesson_revision, 'course_id' => 1, 'wp_post_id' => 0 );
				}
				return null;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->last_update_table = (string) $table;
				$this->last_update_data = is_array( $data ) ? $data : array();
				$this->last_update_where = is_array( $where ) ? $where : array();
				return $this->update_return;
			}

			public function get_var( $sql ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
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
	public function test_course_update_increments_revision_exactly_once(): void {
		$original = $this->install_wpdb_fixture( 55, 7, 66, 3, 1 );

		$result = \ATORA\LMS\LMS_Course_Service::update( 55, array( 'title' => 'Curso editado', 'revision' => 7 ) );
		$this->assertTrue( true === $result );

		global $wpdb;
		$this->assertSame( 'wp_atora_courses', $wpdb->last_update_table );
		$this->assertSame( 8, $wpdb->last_update_data['revision'] ?? null );
		$this->assertSame( array( 'id' => 55, 'revision' => 7 ), $wpdb->last_update_where );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_course_update_rejects_revision_conflict_without_writing(): void {
		$original = $this->install_wpdb_fixture( 55, 7, 66, 3, 1 );

		$result = \ATORA\LMS\LMS_Course_Service::update( 55, array( 'title' => 'Curso editado', 'revision' => 6 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'atora_course_revision_conflict', $result->get_error_code() );

		global $wpdb;
		$this->assertSame( array(), $wpdb->last_update_data );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_course_update_does_not_succeed_on_persistence_failure(): void {
		$original = $this->install_wpdb_fixture( 55, 7, 66, 3, false );

		$result = \ATORA\LMS\LMS_Course_Service::update( 55, array( 'title' => 'Curso editado', 'revision' => 7 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'atora_course_update_failed', $result->get_error_code() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_lesson_update_increments_revision_exactly_once(): void {
		$original = $this->install_wpdb_fixture( 55, 7, 66, 3, 1 );

		$result = \ATORA\LMS\LMS_Course_Service::update_lesson( 66, array( 'title' => 'Lección editada', 'revision' => 3 ) );
		$this->assertTrue( true === $result );

		global $wpdb;
		$this->assertSame( 'wp_atora_lessons', $wpdb->last_update_table );
		$this->assertSame( 4, $wpdb->last_update_data['revision'] ?? null );
		$this->assertSame( array( 'id' => 66, 'revision' => 3 ), $wpdb->last_update_where );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_lesson_update_rejects_revision_conflict_without_writing(): void {
		$original = $this->install_wpdb_fixture( 55, 7, 66, 3, 1 );

		$result = \ATORA\LMS\LMS_Course_Service::update_lesson( 66, array( 'title' => 'Lección editada', 'revision' => 2 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'atora_lesson_revision_conflict', $result->get_error_code() );

		global $wpdb;
		$this->assertSame( array(), $wpdb->last_update_data );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_lesson_update_does_not_succeed_on_persistence_failure(): void {
		$original = $this->install_wpdb_fixture( 55, 7, 66, 3, false );

		$result = \ATORA\LMS\LMS_Course_Service::update_lesson( 66, array( 'title' => 'Lección editada', 'revision' => 3 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'atora_lesson_update_failed', $result->get_error_code() );

		$this->restore_wpdb( $original );
	}
}

