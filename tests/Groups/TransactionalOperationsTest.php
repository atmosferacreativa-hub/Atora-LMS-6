<?php
/**
 * Groups — Group_Service::set_members_with_options() es transaccional
 * (S2.1): un fallo a mitad de camino hace rollback y no deja miembros ni
 * auditoría a medias.
 *
 * @package ATORA_LMS\Tests\Groups
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Groups;

use PHPUnit\Framework\TestCase;

final class FakeWpdbTransactional {
	public string $prefix = 'wp_';
	public array $queries = array();
	public array $insert_calls = array();
	public array $delete_calls = array();
	public $locked_at = null;
	public array $member_ids = array();
	public int $course_id = 5;

	/** Falla el N-ésimo insert() en la tabla de miembros (1-indexado). 0 = nunca falla. */
	public int $fail_member_insert_at = 0;
	private int $member_insert_count = 0;

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
			return array(); // sin otros grupos del mismo curso para este test
		}
		return $this->member_ids;
	}

	public function insert( $table, $data, $format = null ) {
		$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
		if ( false !== strpos( $table, 'clms_group_members' ) ) {
			$this->member_insert_count++;
			if ( $this->fail_member_insert_at && $this->member_insert_count === $this->fail_member_insert_at ) {
				return false;
			}
		}
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
		$this->queries[] = trim( (string) $sql );
		return 1;
	}
}

final class TransactionalOperationsTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/groups/class-group-service.php';

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbTransactional();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function successful_add_commits_and_never_rolls_back(): void {
		global $wpdb;
		$wpdb->member_ids = array();

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_members_with_options( 10, array( 999 ), 1, array() );

		$this->assertIsArray( $result );
		$this->assertContains( 'START TRANSACTION', $wpdb->queries );
		$this->assertContains( 'COMMIT', $wpdb->queries );
		$this->assertNotContains( 'ROLLBACK', $wpdb->queries );

		$member_inserts = array_filter( $wpdb->insert_calls, static fn( $c ) => false !== strpos( $c['table'], 'clms_group_members' ) );
		$this->assertCount( 1, $member_inserts );
	}

	/** @test */
	public function failed_member_insert_rolls_back_and_returns_wp_error(): void {
		global $wpdb;
		$wpdb->member_ids            = array();
		$wpdb->fail_member_insert_at = 1; // el único insert de este test falla

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_members_with_options( 10, array( 999 ), 1, array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'group_members_write_failed', $result->get_error_code() );
		$this->assertContains( 'START TRANSACTION', $wpdb->queries );
		$this->assertContains( 'ROLLBACK', $wpdb->queries );
		$this->assertNotContains( 'COMMIT', $wpdb->queries );
	}

	/** @test */
	public function failed_insert_on_second_member_still_rolls_back_the_whole_batch(): void {
		global $wpdb;
		$wpdb->member_ids            = array();
		$wpdb->fail_member_insert_at = 2; // el segundo de dos inserts falla

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_members_with_options( 10, array( 111, 222 ), 1, array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'ROLLBACK', $wpdb->queries );
		$this->assertNotContains( 'COMMIT', $wpdb->queries );
		// Aunque el primer insert() "tuvo éxito" en el fake antes de la
		// falla del segundo, la ausencia de COMMIT es lo que garantiza que
		// InnoDB descarta ambas inserciones — no se re-verifica el insert
		// individual porque eso es responsabilidad de MySQL, no del fake.
	}
}
