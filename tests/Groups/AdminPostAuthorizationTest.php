<?php
/**
 * Groups — autorización y CSRF en handlers admin-post (S1.0/S1.1/S1.2).
 *
 * Cubre: los 4 handlers deben rechazar a un usuario con `edit_posts` pero
 * sin gestión real del curso, exportar debe exigir nonce, y guardar
 * miembros debe rechazar un group_id que no pertenezca al course_id del
 * request.
 *
 * @package ATORA_LMS\Tests\Groups
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Groups;

use PHPUnit\Framework\TestCase;

/**
 * $wpdb mínimo: solo lo que necesitan las rutas de RECHAZO de este test
 * (ninguna llega a escribir en la DB real, porque wp_die() corta antes).
 * get_var() se usa por Group_Service::get_group_course_id() en el caso
 * S1.2 (group_id de otro curso).
 */
final class FakeWpdbAdminPostAuth {
	public string $prefix = 'wp_';
	public int $group_course_id = 0;

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function () use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}
	public function get_var( $sql ) { return $this->group_course_id ?: null; }
	public function get_col( $sql ) { return array(); }
}

final class AdminPostAuthorizationTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
		require_once __DIR__ . '/../../modules/groups/class-groups-module.php';
		require_once __DIR__ . '/../../modules/groups/class-group-service.php';

		$GLOBALS['__atora_test_user_caps']    = array();
		$GLOBALS['__atora_test_valid_nonces'] = array();
		$GLOBALS['__atora_test_wp_redirects'] = array();
		\atora_test_reset_clms_helper_stub();
		atora_test_set_user_cap( get_current_user_id(), 'edit_posts', true );

		$_GET = $_POST = $_REQUEST = array();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbAdminPostAuth();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		$_GET = $_POST = $_REQUEST = array();
		// Este flag es leído por el CLMS_Helper stub global (compartido
		// con otros archivos de test vía class_exists()) — si se deja en
		// `true`, contamina cualquier test posterior en la MISMA corrida
		// de PHPUnit que dependa de CLMS_Helper::user_can_manage_lms().
		\atora_test_reset_clms_helper_stub();
		parent::tearDown();
	}

	private function set_nonce( string $action, bool $valid = true ): void {
		$nonce = wp_create_nonce( $action );
		$_REQUEST['_wpnonce'] = $valid ? $nonce : 'invalid-nonce';
	}

	/** @test */
	public function create_group_rejects_user_without_course_management(): void {
		$_GET['course_id'] = '5';
		$this->set_nonce( 'atora_groups_create_5' );
		atora_test_set_can_manage_course( false ); // edit_posts sí, gestión del curso no

		$this->expectException( \ATORA_Test_WPDieException::class );
		\ATORA\Groups\Groups_Module::handle_create_group();
	}

	/** @test */
	public function create_group_rejects_invalid_nonce_before_authorization(): void {
		$_GET['course_id'] = '5';
		$this->set_nonce( 'atora_groups_create_5', false );
		atora_test_set_can_manage_course( true ); // aunque SÍ gestione el curso, el nonce manda primero

		$this->expectException( \ATORA_Test_WPDieException::class );
		\ATORA\Groups\Groups_Module::handle_create_group();
	}

	/** @test */
	public function autogenerate_rejects_user_without_course_management(): void {
		$_GET['course_id'] = '5';
		$this->set_nonce( 'atora_groups_autogenerate_5' );
		atora_test_set_can_manage_course( false );

		$this->expectException( \ATORA_Test_WPDieException::class );
		\ATORA\Groups\Groups_Module::handle_autogenerate();
	}

	/** @test */
	public function export_rejects_missing_or_invalid_nonce(): void {
		$_GET['course_id'] = '5';
		$this->set_nonce( 'atora_groups_export_5', false );
		atora_test_set_can_manage_course( true );

		$this->expectException( \ATORA_Test_WPDieException::class );
		\ATORA\Groups\Groups_Module::handle_export();
	}

	/** @test */
	public function export_rejects_user_without_course_management(): void {
		$_GET['course_id'] = '5';
		$this->set_nonce( 'atora_groups_export_5' );
		atora_test_set_can_manage_course( false );

		$this->expectException( \ATORA_Test_WPDieException::class );
		\ATORA\Groups\Groups_Module::handle_export();
	}

	/** @test */
	public function save_members_rejects_user_without_course_management(): void {
		$_GET['course_id'] = '5';
		$_GET['group_id']  = '20';
		$this->set_nonce( 'atora_groups_save_members_5_20' );
		atora_test_set_can_manage_course( false );

		$this->expectException( \ATORA_Test_WPDieException::class );
		\ATORA\Groups\Groups_Module::handle_save_members();
	}

	/** @test */
	public function save_members_rejects_group_from_a_different_course(): void {
		// El atacante gestiona legítimamente el curso 5, pero el group_id
		// 20 pertenece al curso 999 — S1.2: no debe poder tocarlo aunque
		// pase el check de can_manage_course(course_id_del_request).
		$_GET['course_id'] = '5';
		$_GET['group_id']  = '20';
		$this->set_nonce( 'atora_groups_save_members_5_20' );
		atora_test_set_can_manage_course( true );

		global $wpdb;
		$wpdb->group_course_id = 999;

		$this->expectException( \ATORA_Test_WPDieException::class );
		\ATORA\Groups\Groups_Module::handle_save_members();
	}
}
