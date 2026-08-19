<?php
/**
 * LMS_REST_Controller — visibilidad de cursos draft/private — PT-2 (sprint 6.5.2).
 *
 * Hallazgo confirmado: GET /courses aceptaba status=all sin
 * restricción de rol, y GET /courses/{id} no verificaba estado antes
 * de devolver el curso y su curriculum completo — cualquier usuario
 * logueado podía leer cursos draft/private de terceros.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class LMSCourseVisibilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_caps();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		atora_test_reset_user_caps();
		parent::tearDown();
	}

	/**
	 * $wpdb con un curso fijo, más captura de los argumentos con los
	 * que get_all() termina consultando (para verificar que status/
	 * instructor_id quedaron forzados correctamente, no solo que la
	 * respuesta "se ve bien").
	 */
	private function install_wpdb_fixture( int $course_id, int $owner_id, string $status ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $course_id, $owner_id, $status ) {
			public string $prefix = 'wp_';
			public string $users  = 'wp_users';
			public array  $last_count_sql = array();
			private int $course_id;
			private int $owner_id;
			private string $status;

			public function __construct( int $course_id, int $owner_id, string $status ) {
				$this->course_id = $course_id;
				$this->owner_id  = $owner_id;
				$this->status    = $status;
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( $sql, 'atora_courses' ) && preg_match( '/id = (\d+)/', $sql, $m ) && (int) $m[1] === $this->course_id ) {
					return array(
						'id'            => $this->course_id,
						'instructor_id' => $this->owner_id,
						'title'         => 'Curso de prueba',
						'status'        => $this->status,
						'wp_post_id'    => 0,
					);
				}
				return null;
			}

			public function get_var( $sql ) {
				$this->last_count_sql[] = $sql;
				return 0;
			}
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
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

	// ── get_course() ─────────────────────────────────────────────────────────

	/** @test */
	public function test_get_course_published_visible_to_any_logged_in_user(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$original = $this->install_wpdb_fixture( 55, 2, 'published' );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::get_course( $request );

		$this->assertSame( 200, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_get_course_draft_404_for_non_owner(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$original = $this->install_wpdb_fixture( 55, 2, 'draft' );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::get_course( $request );

		$this->assertSame( 404, $response->get_status(), 'fail closed sin revelar que el curso existe' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_get_course_draft_200_for_owner(): void {
		$GLOBALS['__atora_test_current_user_id'] = 2;
		$original = $this->install_wpdb_fixture( 55, 2, 'draft' );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::get_course( $request );

		$this->assertSame( 200, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_get_course_private_404_without_read_private_cap(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$original = $this->install_wpdb_fixture( 55, 2, 'private' );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::get_course( $request );

		$this->assertSame( 404, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_get_course_private_200_with_read_private_cap(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		atora_test_set_user_cap( 10, 'read_private_lm_courses' );
		$original = $this->install_wpdb_fixture( 55, 2, 'private' );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::get_course( $request );

		$this->assertSame( 200, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_get_course_draft_200_with_edit_others_lm_courses(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		atora_test_set_user_cap( 10, 'edit_others_lm_courses' );
		$original = $this->install_wpdb_fixture( 55, 2, 'draft' );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::get_course( $request );

		$this->assertSame( 200, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_get_course_draft_200_with_manage_options(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		atora_test_set_user_cap( 10, 'manage_options' );
		$original = $this->install_wpdb_fixture( 55, 2, 'draft' );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::get_course( $request );

		$this->assertSame( 200, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	// ── list_courses() ───────────────────────────────────────────────────────

	/** @test */
	public function test_list_courses_status_all_forced_to_published_for_plain_user(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$original = $this->install_wpdb_fixture( 55, 2, 'published' );

		$request = new \WP_REST_Request( array( 'status' => 'all' ) );
		\ATORA\LMS\LMS_REST_Controller::list_courses( $request );

		global $wpdb;
		$sql = implode( ' ', $wpdb->last_count_sql );
		$this->assertStringNotContainsString( "status = 'all'", $sql );
		$this->assertStringContainsString( "status = published", $sql, 'debe haber forzado a published en el WHERE' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_list_courses_status_all_scopes_to_own_instructor_id(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		atora_test_set_user_cap( 10, 'clms_manage_courses' );
		$original = $this->install_wpdb_fixture( 55, 2, 'draft' );

		$request = new \WP_REST_Request( array( 'status' => 'all', 'instructor_id' => 999 ) );
		\ATORA\LMS\LMS_REST_Controller::list_courses( $request );

		global $wpdb;
		$sql = implode( ' ', $wpdb->last_count_sql );
		$this->assertStringContainsString( 'instructor_id = 10', $sql, 'debe forzar el instructor_id propio, ignorando el 999 del cliente' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_list_courses_status_all_unrestricted_with_edit_others_lm_courses(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		atora_test_set_user_cap( 10, 'edit_others_lm_courses' );
		$original = $this->install_wpdb_fixture( 55, 2, 'draft' );

		$request = new \WP_REST_Request( array( 'status' => 'all' ) );
		\ATORA\LMS\LMS_REST_Controller::list_courses( $request );

		global $wpdb;
		$sql = implode( ' ', $wpdb->last_count_sql );
		$this->assertStringNotContainsString( 'status =', $sql, "'all' no debe filtrar por status en absoluto" );
		$this->assertStringNotContainsString( 'instructor_id =', $sql );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_list_courses_default_status_unchanged(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$original = $this->install_wpdb_fixture( 55, 2, 'published' );

		$request = new \WP_REST_Request();
		$response = \ATORA\LMS\LMS_REST_Controller::list_courses( $request );

		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$sql = implode( ' ', $wpdb->last_count_sql );
		$this->assertStringContainsString( 'status = published', $sql );

		$this->restore_wpdb( $original );
	}
}
