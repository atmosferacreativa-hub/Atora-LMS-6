<?php
/**
 * Google Classroom — Classroom_Service::sync_roster_by_email(): matchea
 * el roster de Classroom por email contra usuarios WP e inscribe a los
 * que no estaban ya inscritos; cuenta correctamente enrolled/
 * already_enrolled/missing.
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

namespace ATORA\Classroom {
	// Classroom_Service vive en este namespace y llama a get_user_by()
	// sin calificar. get_user_by() ya está declarada como literal en
	// bootstrap.php, así que Patchwork no puede redefinirla vía
	// Functions\when() (ver DefinedTooEarly) — se resuelve igual que el
	// current_user_can() de los tests de H5P: un override local a ESTE
	// namespace, sin tocar ni arriesgar la función global compartida.
	if ( ! function_exists( __NAMESPACE__ . '\\get_user_by' ) ) {
		function get_user_by( string $field, $value ) {
			return $GLOBALS['__atora_test_roster_user_by_email'] ?? false;
		}
	}
}

namespace ATORA\Tests\Classroom {

require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FakeWpdbRoster {
	public string $prefix = 'wp_';
	public ?array $course_map_row = null;
	public array $update_calls = array();
	public array $insert_calls = array();

	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_row( $sql, $output = null ) { return $this->course_map_row; }
	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		$this->update_calls[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		return 1;
	}
	public function insert( $table, $data, $format = null ): int {
		$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}
}

final class RosterSyncTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/classroom/class-classroom-service.php';

		\atora_test_reset_clms_helper_stub();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbRoster();
		$wpdb->course_map_row = array( 'id' => 1, 'wp_course_id' => 100, 'gc_course_id' => 'gc-course-1' );

		Functions\when( 'add_query_arg' )->alias( static fn( $query, $url ) => $url );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		\atora_test_reset_clms_helper_stub();
		parent::tearDown();
	}

	private function mock_roster_response( array $emails ): void {
		$students = array_map( static fn( $email ) => array( 'profile' => array( 'emailAddress' => $email ) ), $emails );
		$body     = json_encode( array( 'students' => $students ) );

		Functions\when( 'wp_remote_request' )->justReturn( array( 'body' => $body ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $res ) => $res['body'] ?? '' );
	}

	/** @test */
	public function enrolls_a_matched_user_who_was_not_already_enrolled(): void {
		$this->mock_roster_response( array( 'alumno@example.test' ) );
		$GLOBALS['__atora_test_roster_user_by_email'] = new \WP_User( 7, 'alumno@example.test' );

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->sync_roster_by_email( 100, 1, false );

		$this->assertSame( 1, $result['enrolled'] );
		$this->assertSame( 0, $result['already_enrolled'] );
		$this->assertSame( 0, $result['missing'] );

		$calls = \atora_test_get_enroll_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( 7, $calls[0]['user_id'] );
		$this->assertSame( 100, $calls[0]['course_id'] );
	}

	/** @test */
	public function does_not_re_enroll_a_user_already_enrolled(): void {
		$this->mock_roster_response( array( 'ya-inscrito@example.test' ) );
		$GLOBALS['__atora_test_roster_user_by_email'] = new \WP_User( 8, 'ya-inscrito@example.test' );
		\atora_test_set_course_enrollment( 8, 100, true );

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->sync_roster_by_email( 100, 1, false );

		$this->assertSame( 0, $result['enrolled'] );
		$this->assertSame( 1, $result['already_enrolled'] );
		$this->assertEmpty( \atora_test_get_enroll_calls(), 'no debe reinscribir a alguien que ya está inscrito' );
	}

	/** @test */
	public function email_without_matching_wp_user_counts_as_missing_when_not_creating_users(): void {
		$this->mock_roster_response( array( 'noexiste@example.test' ) );
		$GLOBALS['__atora_test_roster_user_by_email'] = false;

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->sync_roster_by_email( 100, 1, false );

		$this->assertSame( 0, $result['enrolled'] );
		$this->assertSame( 1, $result['missing'] );
		$this->assertSame( array( 'noexiste@example.test' ), $result['missing_emails'] );
	}

	/** @test */
	public function returns_zeroed_result_when_course_has_no_mapping(): void {
		global $wpdb;
		$wpdb->course_map_row = null;

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->sync_roster_by_email( 100, 1, false );

		$this->assertSame( 0, $result['enrolled'] );
		$this->assertSame( 0, $result['already_enrolled'] );
		$this->assertSame( 0, $result['missing'] );
	}

	/** @test */
	public function duplicate_emails_across_pages_are_deduplicated(): void {
		$this->mock_roster_response( array( 'dup@example.test', 'dup@example.test', 'DUP@example.test' ) );
		$GLOBALS['__atora_test_roster_user_by_email'] = new \WP_User( 9, 'dup@example.test' );

		$service = new \ATORA\Classroom\Classroom_Service();
		$result  = $service->sync_roster_by_email( 100, 1, false );

		$this->assertSame( 1, $result['enrolled'], 'el mismo email repetido (con distinto casing) solo debe procesarse una vez' );
	}
}
}
