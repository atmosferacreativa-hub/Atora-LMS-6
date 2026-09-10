<?php
/**
 * Groups — un estudiante no puede quedar en 2 grupos del mismo curso
 * (S2.0). Confirmado en la auditoría del sprint: ya mitigado en
 * Group_Service::set_members_with_options() vía
 * remove_user_from_other_groups_in_course() — este test lo deja cubierto
 * con una prueba real en vez de solo lectura de código.
 *
 * @package ATORA_LMS\Tests\Groups
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Groups;

use PHPUnit\Framework\TestCase;

final class FakeWpdbMembership {
	public string $prefix = 'wp_';
	public array $delete_calls = array();
	public array $insert_calls = array();
	public $locked_at = null;
	public array $member_ids = array(); // miembros actuales del grupo destino
	public int $course_id = 5;
	/** group_id => true, para simular "el usuario ya está en este otro grupo del mismo curso" */
	public array $other_group_ids_for_user = array();

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function () use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'locked_at' ) ) {
			return $this->locked_at;
		}
		if ( false !== strpos( $sql, 'SELECT course_id' ) ) {
			return $this->course_id;
		}
		return null;
	}

	public function get_col( $sql ) {
		if ( false !== strpos( $sql, 'INNER JOIN' ) ) {
			// remove_user_from_other_groups_in_course(): grupos del mismo
			// curso donde el usuario ya está, distintos del grupo destino.
			return array_keys( $this->other_group_ids_for_user );
		}
		return $this->member_ids;
	}

	public function insert( $table, $data, $format = null ) {
		$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}

	public function delete( $table, $where, $where_format = null ): int {
		$this->delete_calls[] = array( 'table' => $table, 'where' => $where );
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		return 1;
	}

	public function query( $sql ): int {
		return 1;
	}
}

final class MembershipUniquenessTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/groups/class-group-service.php';

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbMembership();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function adding_a_student_already_in_another_group_of_the_same_course_moves_them(): void {
		global $wpdb;
		$student_id     = 30;
		$other_group_id = 7;

		$wpdb->member_ids               = array(); // grupo destino (10) aún sin miembros
		$wpdb->other_group_ids_for_user = array( $other_group_id => true );

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_members_with_options( 10, array( $student_id ), 1, array() );

		$this->assertIsArray( $result );

		// Debe haberlo eliminado del grupo 7 (el otro grupo del mismo curso)...
		$this->assertNotEmpty( $wpdb->delete_calls );
		$removed_from_other_group = false;
		foreach ( $wpdb->delete_calls as $call ) {
			if ( (int) ( $call['where']['group_id'] ?? 0 ) === $other_group_id
				&& (int) ( $call['where']['user_id'] ?? 0 ) === $student_id ) {
				$removed_from_other_group = true;
			}
		}
		$this->assertTrue( $removed_from_other_group, 'debe eliminar la membresía del otro grupo del mismo curso' );

		// ...y agregado al grupo 10 (el destino).
		$member_inserts = array_filter( $wpdb->insert_calls, static fn( $c ) => false !== strpos( $c['table'], 'clms_group_members' ) );
		$this->assertNotEmpty( $member_inserts );
	}

	/** @test */
	public function adding_a_student_with_no_other_group_in_the_course_does_not_touch_other_groups(): void {
		global $wpdb;
		$wpdb->member_ids               = array();
		$wpdb->other_group_ids_for_user = array(); // no está en ningún otro grupo del curso

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_members_with_options( 10, array( 30 ), 1, array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $wpdb->delete_calls, 'no debe borrar nada si no había otro grupo del mismo curso' );
	}
}
