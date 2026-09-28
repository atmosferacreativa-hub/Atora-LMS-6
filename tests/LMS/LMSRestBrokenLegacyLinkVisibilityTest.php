<?php
/**
 * LMS_REST_Controller — cursos publicados con vínculo legacy roto.
 *
 * Regla: si `wp_post_id > 0`, el CPT lm_course debe existir y estar
 * `publish` para que un estudiante lo vea o lo pueda leer vía REST.
 *
 * Los administradores deben poder ver/leer el registro para diagnosticar.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

final class LMSRestBrokenLegacyLinkVisibilityTest extends TestCase {

	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_posts();
		atora_test_reset_post_types();
		atora_test_reset_user_caps();
		atora_test_reset_options();
		$GLOBALS['__atora_test_current_user_id'] = 10;
		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public array $courses = array();
			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}
			public function esc_like( string $s ): string { return $s; }
			public function get_var( $sql ) {
				if ( is_string( $sql ) && str_contains( $sql, 'SELECT COUNT(*)' ) && str_contains( $sql, 'atora_courses' ) ) {
					return count( $this->courses );
				}
				return null;
			}
			public function get_results( $sql, $output = ARRAY_A ): array {
				if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_courses' ) && str_contains( $sql, 'SELECT *' ) ) {
					ksort( $this->courses );
					return array_values( $this->courses );
				}
				return array();
			}
			public function get_row( $sql, $output = ARRAY_A ) {
				if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_courses' ) && preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
					$id = (int) $m[1];
					return $this->courses[ $id ] ?? null;
				}
				return null;
			}
			public function get_col( $sql ) { return array(); }
			public function insert( ...$a ) { return 1; }
			public function update( ...$a ) { return 1; }
			public function delete( ...$a ) { return 1; }
			public function query( $sql ) { return 1; }
			public function get_charset_collate(): string { return ''; }
		};
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	private function seed_courses_fixture(): void {
		// 1) roto: post en trash.
		atora_test_set_post( 123, array( 'post_type' => 'lm_course', 'post_status' => 'trash' ) );
		// 2) roto: post ausente.
		// 3) válido: CPT publicado.
		atora_test_set_post( 555, array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );

		global $wpdb;
		$wpdb->courses = array(
			1 => array( 'id' => 1, 'wp_post_id' => 123, 'title' => 'Trash', 'slug' => 'trash', 'excerpt' => '', 'status' => 'published', 'instructor_id' => 0, 'thumbnail_url' => '', 'settings_json' => '{}', 'meta_json' => '{}', 'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00' ),
			2 => array( 'id' => 2, 'wp_post_id' => 999, 'title' => 'Missing', 'slug' => 'missing', 'excerpt' => '', 'status' => 'published', 'instructor_id' => 0, 'thumbnail_url' => '', 'settings_json' => '{}', 'meta_json' => '{}', 'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00' ),
			3 => array( 'id' => 3, 'wp_post_id' => 555, 'title' => 'Published', 'slug' => 'published', 'excerpt' => '', 'status' => 'published', 'instructor_id' => 0, 'thumbnail_url' => '', 'settings_json' => '{}', 'meta_json' => '{}', 'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00' ),
			4 => array( 'id' => 4, 'wp_post_id' => 0,   'title' => 'Native', 'slug' => 'native', 'excerpt' => '', 'status' => 'published', 'instructor_id' => 0, 'thumbnail_url' => '', 'settings_json' => '{}', 'meta_json' => '{}', 'published_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00' ),
		);
	}

	/** @test */
	public function test_list_courses_student_filters_broken_wp_links(): void {
		$this->seed_courses_fixture();
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$resp = \ATORA\LMS\LMS_REST_Controller::list_courses( new \WP_REST_Request() );
		$this->assertSame( 200, $resp->get_status() );
		$data = (array) $resp->get_data();
		$items = (array) ( $data['items'] ?? array() );
		$this->assertSame( array( 3, 4 ), array_map( static fn( $c ) => (int) ( $c['id'] ?? 0 ), $items ) );
	}

	/** @test */
	public function test_get_course_student_404_when_link_is_trash_or_missing(): void {
		$this->seed_courses_fixture();
		$GLOBALS['__atora_test_current_user_id'] = 10;

		$r1 = new \WP_REST_Request();
		$r1->set_param( 'course_id', 1 );
		$resp1 = \ATORA\LMS\LMS_REST_Controller::get_course( $r1 );
		$this->assertSame( 404, $resp1->get_status() );

		$r2 = new \WP_REST_Request();
		$r2->set_param( 'course_id', 2 );
		$resp2 = \ATORA\LMS\LMS_REST_Controller::get_course( $r2 );
		$this->assertSame( 404, $resp2->get_status() );

		$r3 = new \WP_REST_Request();
		$r3->set_param( 'course_id', 3 );
		$resp3 = \ATORA\LMS\LMS_REST_Controller::get_course( $r3 );
		$this->assertSame( 200, $resp3->get_status() );

		$r4 = new \WP_REST_Request();
		$r4->set_param( 'course_id', 4 );
		$resp4 = \ATORA\LMS\LMS_REST_Controller::get_course( $r4 );
		$this->assertSame( 200, $resp4->get_status() );
	}

	/** @test */
	public function test_admin_can_list_and_read_broken_linked_courses_for_diagnosis(): void {
		$this->seed_courses_fixture();
		$GLOBALS['__atora_test_current_user_id'] = 1;
		atora_test_set_user_cap( 1, 'manage_options' );

		$resp = \ATORA\LMS\LMS_REST_Controller::list_courses( new \WP_REST_Request() );
		$this->assertSame( 200, $resp->get_status() );
		$data = (array) $resp->get_data();
		$items = (array) ( $data['items'] ?? array() );
		$this->assertSame( array( 1, 2, 3, 4 ), array_map( static fn( $c ) => (int) ( $c['id'] ?? 0 ), $items ) );

		$req = new \WP_REST_Request();
		$req->set_param( 'course_id', 1 );
		$this->assertSame( 200, \ATORA\LMS\LMS_REST_Controller::get_course( $req )->get_status() );
		$req2 = new \WP_REST_Request();
		$req2->set_param( 'course_id', 2 );
		$this->assertSame( 200, \ATORA\LMS\LMS_REST_Controller::get_course( $req2 )->get_status() );
	}
}
