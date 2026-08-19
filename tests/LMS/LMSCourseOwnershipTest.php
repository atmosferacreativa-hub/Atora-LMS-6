<?php
/**
 * LMS_REST_Controller — propiedad de recurso en rutas de curso — PT-1 (sprint 6.5.2).
 *
 * Hallazgo confirmado: update_course, course_stats, enroll y cohort
 * compartían el mismo permission_callback (can_manage_courses) que
 * solo verifica la capability genérica, nunca de quién es el curso —
 * un instructor con clms_manage_courses podía operar sobre cursos
 * ajenos. can_manage_this_course() porta el mismo patrón jerárquico
 * que CLMS_Access::can_manage_resource_context() ya usa para el LMS
 * legado de posts.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class LMSCourseOwnershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_caps();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		atora_test_reset_user_caps();
		parent::tearDown();
	}

	private function as_instructor( int $user_id ): void {
		$GLOBALS['__atora_test_current_user_id'] = $user_id;
		atora_test_set_user_cap( $user_id, 'clms_manage_courses' );
	}

	/**
	 * $wpdb con un curso fijo (id => instructor_id) — suficiente para
	 * ejercer can_manage_this_course() y dejar que el resto de las
	 * consultas (stats/enroll/cohort) caigan a valores por defecto
	 * seguros una vez pasado el gate.
	 */
	private function install_wpdb_fixture( int $course_id, int $owner_id ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $course_id, $owner_id ) {
			public string $prefix    = 'wp_';
			public string $users     = 'wp_users';
			public int    $insert_id = 1;
			public array  $last_insert_data = array();
			private int $course_id;
			private int $owner_id;

			public function __construct( int $course_id, int $owner_id ) {
				$this->course_id = $course_id;
				$this->owner_id  = $owner_id;
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
						'id'             => $this->course_id,
						'instructor_id'  => $this->owner_id,
						'title'          => 'Curso de prueba',
						'status'         => 'published',
						'wp_post_id'     => 0,
					);
				}
				return null;
			}

			public function get_var( $sql ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { $this->insert_id++; $this->last_insert_data = $data; return 1; }
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

	/** @test */
	public function test_update_course_403_for_non_owner_instructor(): void {
		$this->as_instructor( 1 ); // instructor A
		$original = $this->install_wpdb_fixture( 55, 2 ); // curso pertenece a B

		$request  = new \WP_REST_Request( array( 'title' => 'Hackeado' ), array(), '/atora-lms/v1/courses/55' );
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::update_course( $request );

		$this->assertSame( 403, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_course_stats_403_for_non_owner_instructor(): void {
		$this->as_instructor( 1 );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::course_stats( $request );

		$this->assertSame( 403, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_enroll_403_for_non_owner_instructor(): void {
		$this->as_instructor( 1 );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request( array( 'user_id' => 999 ) );
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::enroll( $request );

		$this->assertSame( 403, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_cohort_403_for_non_owner_instructor(): void {
		$this->as_instructor( 1 );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$response = \ATORA\LMS\LMS_REST_Controller::cohort( $request );

		$this->assertSame( 403, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_owner_instructor_gets_200_on_all_four_routes(): void {
		$this->as_instructor( 2 ); // instructor B, dueño real
		$original = $this->install_wpdb_fixture( 55, 2 );

		$update_req = new \WP_REST_Request( array( 'title' => 'Actualizado' ) );
		$update_req->set_param( 'course_id', 55 );
		$this->assertSame( 200, \ATORA\LMS\LMS_REST_Controller::update_course( $update_req )->get_status() );

		$stats_req = new \WP_REST_Request();
		$stats_req->set_param( 'course_id', 55 );
		$this->assertSame( 200, \ATORA\LMS\LMS_REST_Controller::course_stats( $stats_req )->get_status() );

		$enroll_req = new \WP_REST_Request( array( 'user_id' => 999 ) );
		$enroll_req->set_param( 'course_id', 55 );
		// El enroll en sí puede fallar por datos de la fixture (id 0),
		// pero nunca debe ser 403 — el gate de propiedad ya pasó.
		$this->assertNotSame( 403, \ATORA\LMS\LMS_REST_Controller::enroll( $enroll_req )->get_status() );

		$cohort_req = new \WP_REST_Request();
		$cohort_req->set_param( 'course_id', 55 );
		$this->assertSame( 200, \ATORA\LMS\LMS_REST_Controller::cohort( $cohort_req )->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_edit_others_lm_courses_bypasses_ownership_check(): void {
		// Regresión: coordinador/admin académico con la capability
		// ampliada sigue pudiendo operar sobre cursos ajenos.
		$this->as_instructor( 3 );
		atora_test_set_user_cap( 3, 'edit_others_lm_courses' );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$this->assertSame( 200, \ATORA\LMS\LMS_REST_Controller::cohort( $request )->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_manage_options_bypasses_ownership_check(): void {
		$GLOBALS['__atora_test_current_user_id'] = 4;
		atora_test_set_user_cap( 4, 'manage_options' );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 55 );
		$this->assertSame( 200, \ATORA\LMS\LMS_REST_Controller::cohort( $request )->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_nonexistent_course_fails_closed(): void {
		$this->as_instructor( 1 );
		$original = $this->install_wpdb_fixture( 55, 1 ); // solo existe el 55

		$request = new \WP_REST_Request();
		$request->set_param( 'course_id', 999 ); // curso inexistente
		$response = \ATORA\LMS\LMS_REST_Controller::cohort( $request );

		$this->assertSame( 403, $response->get_status(), 'un recurso inexistente no debe tratarse como "permitido"' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_create_course_forces_own_instructor_id_without_edit_others(): void {
		$this->as_instructor( 5 );
		$original = $this->install_wpdb_fixture( 0, 0 );

		$request = new \WP_REST_Request( array( 'title' => 'Curso nuevo', 'instructor_id' => 999 ) );
		\ATORA\LMS\LMS_REST_Controller::create_course( $request );

		global $wpdb;
		$this->assertSame( 5, $wpdb->last_insert_data['instructor_id'] ?? null, 'instructor_id enviado por el cliente (999) debe ignorarse y forzarse al usuario actual' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_create_course_respects_instructor_id_with_edit_others_lm_courses(): void {
		$this->as_instructor( 5 );
		atora_test_set_user_cap( 5, 'edit_others_lm_courses' );
		$original = $this->install_wpdb_fixture( 0, 0 );

		$request = new \WP_REST_Request( array( 'title' => 'Curso nuevo', 'instructor_id' => 999 ) );
		\ATORA\LMS\LMS_REST_Controller::create_course( $request );

		global $wpdb;
		$this->assertSame( 999, $wpdb->last_insert_data['instructor_id'] ?? null, 'con edit_others_lm_courses sí puede asignar el curso a otro instructor' );

		$this->restore_wpdb( $original );
	}
}
