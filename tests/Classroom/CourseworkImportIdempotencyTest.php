<?php
/**
 * Google Classroom — Classroom_Service::import_coursework_as_lesson()
 * debe ser idempotente: importar la MISMA tarea (gc_course_id +
 * gc_coursework_id) dos veces actualiza la lección existente en vez de
 * crear una lección duplicada.
 *
 * @package ATORA_LMS\Tests\Classroom
 */

declare( strict_types = 1 );

namespace ATORA\Calendar {
	if ( ! class_exists( __NAMESPACE__ . '\\Calendar_Sync' ) ) {
		final class Calendar_Sync {
			public static function get_valid_access_token( int $user_id, string $provider ): ?string {
				return 'fake-access-token';
			}
		}
	}
}

namespace ATORA\Tests\Classroom {

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FakeWpdbClassroom {
	public string $prefix = 'wp_';
	public ?array $course_map_row = null;
	public ?array $coursework_row = null;
	public array $insert_calls = array();
	public array $update_calls = array();

	public function prepare( string $sql, ...$args ): string { return $sql; }

	public function get_row( $sql, $output = null ) {
		if ( false !== strpos( $sql, 'coursework_map' ) ) {
			return $this->coursework_row;
		}
		if ( false !== strpos( $sql, 'course_map' ) ) {
			return $this->course_map_row;
		}
		return null;
	}

	public function insert( $table, $data, $format = null ): int {
		$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		$this->update_calls[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		return 1;
	}
}

final class CourseworkImportIdempotencyTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/classroom/class-classroom-service.php';

		atora_test_reset_posts();
		atora_test_reset_post_meta();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbClassroom();
		$wpdb->course_map_row = array(
			'id' => 1, 'wp_course_id' => 100, 'gc_course_id' => 'gc-course-1',
			'gc_course_name' => 'Curso GC', 'owner_user_id' => 1,
		);

		$coursework_payload = array(
			'title'       => 'Tarea 1',
			'description' => 'Descripción',
			'state'       => 'PUBLISHED',
			'maxPoints'   => 100,
		);

		Functions\when( 'wp_remote_request' )->justReturn( array( 'body' => json_encode( $coursework_payload ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $res ) => $res['body'] ?? '' );
		Functions\when( 'add_query_arg' )->alias( static fn( $query, $url ) => $url );
		Functions\when( 'wp_kses_post' )->alias( static fn( $s ) => (string) $s );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function first_import_creates_a_new_lesson(): void {
		global $wpdb;
		$wpdb->coursework_row = null; // sin mapeo previo

		Functions\when( 'wp_insert_post' )->justReturn( 9001 );

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->import_coursework_as_lesson( 100, 'cw-1', 1 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created'] );
		$this->assertSame( 9001, $result['wp_lesson_id'] );

		$coursework_inserts = array_filter( $wpdb->insert_calls, static fn( $c ) => false !== strpos( $c['table'], 'coursework_map' ) );
		$this->assertNotEmpty( $coursework_inserts, 'la primera importación inserta una fila nueva en el mapeo' );
	}

	/** @test */
	public function second_import_of_the_same_coursework_updates_instead_of_duplicating(): void {
		global $wpdb;
		// Ya existe un mapeo para esta misma (gc_course_id, gc_coursework_id).
		$wpdb->coursework_row = array( 'id' => 55, 'wp_lesson_id' => 9001 );
		atora_test_set_post_type( 9001, 'lm_lesson' );

		Functions\when( 'wp_update_post' )->justReturn( true );
		Functions\when( 'wp_insert_post' )->alias( function () {
			$this->fail( 'una importación repetida no debe crear un post nuevo' );
		} );

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->import_coursework_as_lesson( 100, 'cw-1', 1 );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['created'], 'la segunda importación de la misma tarea no debe marcarse como "creada"' );
		$this->assertSame( 9001, $result['wp_lesson_id'], 'debe reusar la MISMA lección, no crear otra' );

		$coursework_updates = array_filter( $wpdb->update_calls, static fn( $c ) => false !== strpos( $c['table'], 'coursework_map' ) );
		$this->assertNotEmpty( $coursework_updates, 'la segunda importación actualiza la fila de mapeo existente, no inserta una nueva' );

		$coursework_inserts = array_filter( $wpdb->insert_calls, static fn( $c ) => false !== strpos( $c['table'], 'coursework_map' ) );
		$this->assertEmpty( $coursework_inserts, 'no debe insertar una segunda fila de mapeo para la misma tarea' );
	}

	/** @test */
	public function import_fails_when_course_has_no_classroom_mapping(): void {
		global $wpdb;
		$wpdb->course_map_row = null;

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->import_coursework_as_lesson( 100, 'cw-1', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_mapped', $result->get_error_code() );
	}
}
}
