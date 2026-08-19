<?php
/**
 * wp_post_id — puente de identidad con el LMS legado — PT-1 (sprint 6.5.3).
 *
 * Hallazgo confirmado: create_course() protegía instructor_id pero no
 * wp_post_id; update_course() no protegía ninguno de los dos. Ambos
 * campos ahora se filtran del CRUD genérico — wp_post_id
 * incondicionalmente (ni siquiera manage_options lo escribe por ahí),
 * instructor_id condicionado a edit_others_lm_courses/manage_options.
 * link_to_legacy_post() es la única vía de escritura restante.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class LMSWpPostIdBindingTest extends TestCase {

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

	private function install_wpdb_fixture( int $course_id = 0, int $owner_id = 0, array $linked_wp_post_ids = array() ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $course_id, $owner_id, $linked_wp_post_ids ) {
			public string $prefix = 'wp_';
			public int    $insert_id = 1;
			public array  $last_insert_data = array();
			public array  $last_update_data = array();
			private int $course_id;
			private int $owner_id;
			private array $linked_wp_post_ids; // wp_post_id => course_id ya vinculado

			public function __construct( int $course_id, int $owner_id, array $linked ) {
				$this->course_id = $course_id;
				$this->owner_id  = $owner_id;
				$this->linked_wp_post_ids = $linked;
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( $sql, 'atora_courses' ) && preg_match( '/id = (\d+)/', $sql, $m ) && (int) $m[1] === $this->course_id ) {
					return array( 'id' => $this->course_id, 'instructor_id' => $this->owner_id, 'status' => 'published', 'wp_post_id' => 0 );
				}
				return null;
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'wp_post_id = ' ) && preg_match( '/wp_post_id = (\d+)/', $sql, $m ) ) {
					$wp_post_id = (int) $m[1];
					return $this->linked_wp_post_ids[ $wp_post_id ] ?? null;
				}
				return null;
			}

			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { $this->insert_id++; $this->last_insert_data = $data; return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { $this->last_update_data = $data; return 1; }
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

	// ── create_course() ──────────────────────────────────────────────────────

	/** @test */
	public function test_create_course_strips_wp_post_id_for_plain_instructor(): void {
		$GLOBALS['__atora_test_current_user_id'] = 5;
		atora_test_set_user_cap( 5, 'clms_manage_courses' );
		$original = $this->install_wpdb_fixture();

		$request = new \WP_REST_Request( array( 'title' => 'Curso', 'wp_post_id' => 777 ) );
		\ATORA\LMS\LMS_REST_Controller::create_course( $request );

		global $wpdb;
		$this->assertArrayNotHasKey( 'wp_post_id', $wpdb->last_insert_data, 'wp_post_id nunca debe llegar al INSERT vía CRUD genérico' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_create_course_strips_wp_post_id_even_for_manage_options(): void {
		// PT-1.3: ni siquiera manage_options lo escribe por el CRUD genérico.
		$GLOBALS['__atora_test_current_user_id'] = 1;
		atora_test_set_user_cap( 1, 'manage_options' );
		$original = $this->install_wpdb_fixture();

		$request = new \WP_REST_Request( array( 'title' => 'Curso', 'wp_post_id' => 777 ) );
		\ATORA\LMS\LMS_REST_Controller::create_course( $request );

		global $wpdb;
		$this->assertArrayNotHasKey( 'wp_post_id', $wpdb->last_insert_data );

		$this->restore_wpdb( $original );
	}

	// ── update_course() ──────────────────────────────────────────────────────

	/** @test */
	public function test_update_course_wp_post_id_never_changes_for_owner(): void {
		$GLOBALS['__atora_test_current_user_id'] = 2;
		atora_test_set_user_cap( 2, 'clms_manage_courses' );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request( array( 'title' => 'Actualizado', 'wp_post_id' => 999 ) );
		$request->set_param( 'course_id', 55 );
		\ATORA\LMS\LMS_REST_Controller::update_course( $request );

		global $wpdb;
		$this->assertArrayNotHasKey( 'wp_post_id', $wpdb->last_update_data );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_update_course_instructor_id_stripped_for_plain_owner(): void {
		$GLOBALS['__atora_test_current_user_id'] = 2;
		atora_test_set_user_cap( 2, 'clms_manage_courses' );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request( array( 'title' => 'Actualizado', 'instructor_id' => 999 ) );
		$request->set_param( 'course_id', 55 );
		\ATORA\LMS\LMS_REST_Controller::update_course( $request );

		global $wpdb;
		$this->assertArrayNotHasKey( 'instructor_id', $wpdb->last_update_data, 'un instructor no debe poder transferir el curso a otro instructor_id' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_update_course_instructor_id_allowed_with_edit_others_lm_courses(): void {
		$GLOBALS['__atora_test_current_user_id'] = 3;
		atora_test_set_user_cap( 3, 'edit_others_lm_courses' );
		$original = $this->install_wpdb_fixture( 55, 2 );

		$request = new \WP_REST_Request( array( 'title' => 'Actualizado', 'instructor_id' => 999 ) );
		$request->set_param( 'course_id', 55 );
		\ATORA\LMS\LMS_REST_Controller::update_course( $request );

		global $wpdb;
		$this->assertSame( 999, $wpdb->last_update_data['instructor_id'] ?? null );
		$this->assertArrayNotHasKey( 'wp_post_id', $wpdb->last_update_data, 'wp_post_id sigue sin ser editable aunque instructor_id sí' );

		$this->restore_wpdb( $original );
	}

	// ── link_to_legacy_post() ────────────────────────────────────────────────

	/** @test */
	public function test_link_to_legacy_post_succeeds_for_valid_lm_course(): void {
		atora_test_set_post_type( 800, 'lm_course' );
		atora_test_set_user_cap( 1, 'edit_post' );
		$GLOBALS['__atora_test_current_user_id'] = 1;
		$original = $this->install_wpdb_fixture();

		$result = \ATORA\LMS\LMS_Course_Service::link_to_legacy_post( 55, 800 );

		$this->assertTrue( $result['ok'] );

		global $wpdb;
		$this->assertSame( 800, $wpdb->last_update_data['wp_post_id'] ?? null );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_link_to_legacy_post_fails_for_wrong_post_type(): void {
		atora_test_set_post_type( 800, 'post' ); // no es lm_course
		atora_test_set_user_cap( 1, 'edit_post' );
		$GLOBALS['__atora_test_current_user_id'] = 1;
		$original = $this->install_wpdb_fixture();

		$result = \ATORA\LMS\LMS_Course_Service::link_to_legacy_post( 55, 800 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_es_lm_course', $result['reason'] );

		global $wpdb;
		$this->assertSame( array(), $wpdb->last_update_data, 'no debe escribir nada si falla la validación' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_link_to_legacy_post_fails_without_edit_post_permission(): void {
		atora_test_set_post_type( 800, 'lm_course' );
		// Sin la cap 'edit_post' para el usuario actual.
		$GLOBALS['__atora_test_current_user_id'] = 1;
		$original = $this->install_wpdb_fixture();

		$result = \ATORA\LMS\LMS_Course_Service::link_to_legacy_post( 55, 800 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'sin_permiso_sobre_el_post', $result['reason'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_link_to_legacy_post_fails_when_already_linked_to_another_course(): void {
		atora_test_set_post_type( 800, 'lm_course' );
		atora_test_set_user_cap( 1, 'edit_post' );
		$GLOBALS['__atora_test_current_user_id'] = 1;
		// wp_post_id 800 ya está vinculado al curso 999 (distinto de 55).
		$original = $this->install_wpdb_fixture( 0, 0, array( 800 => 999 ) );

		$result = \ATORA\LMS\LMS_Course_Service::link_to_legacy_post( 55, 800 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ya_vinculado_a_otro_curso', $result['reason'] );

		global $wpdb;
		$this->assertSame( array(), $wpdb->last_update_data );

		$this->restore_wpdb( $original );
	}
}
