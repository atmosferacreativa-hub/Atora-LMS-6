<?php
/**
 * Groups — override grade.
 *
 * @package ATORA_LMS\Tests\Groups
 */

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
}

namespace ATORA\Tests\Groups {

use PHPUnit\Framework\TestCase;

/**
 * $wpdb de prueba propio de este archivo (no comparte estado con el stub
 * global de bootstrap.php): expone member_ids/course_id configurables por
 * test para que Group_Service::set_override() encuentre exactamente la
 * relación académica que cada caso necesita.
 */
final class FakeWpdbGroupOverride {
	public string $prefix = 'wp_';
	public array $member_ids = array();
	public int $group_course_id = 0;

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function () use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}

	public function get_col( $sql ) {
		// Usado por Group_Service::get_group_member_ids().
		return $this->member_ids;
	}

	public function get_var( $sql ) {
		// Usado por Group_Service::get_group_course_id().
		return $this->group_course_id ?: null;
	}

	public function query( $sql ): int {
		return 1;
	}

	public function insert( $table, $data, $format = null ): int {
		return 1;
	}
}

final class GroupOverrideTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_post_meta();
		\atora_test_reset_clms_helper_stub();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbGroupOverride();

		require_once __DIR__ . '/../../modules/groups/class-group-service.php';
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * Configura el $wpdb fake para que set_override() encuentre: el
	 * estudiante como miembro del grupo, y el curso del grupo coincidiendo
	 * con el curso de la lección.
	 */
	private function seed_valid_relationship( int $course_id, int $lesson_id, int $student_id ): void {
		global $wpdb;
		$wpdb->member_ids      = array( $student_id );
		$wpdb->group_course_id = $course_id;
		atora_test_set_post_meta( $lesson_id, '_clms_lesson_course_id', $course_id );
	}

	/** @test */
	public function it_clamps_override_grade_to_0_100(): void {
		$this->seed_valid_relationship( 40, 20, 30 );

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_override( 10, 20, 30, 120, 'extra', 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['override_grade'] );
	}

	/** @test */
	public function it_accepts_null_override_to_clear(): void {
		$this->seed_valid_relationship( 40, 20, 30 );

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_override( 10, 20, 30, '', '', 1 );

		$this->assertIsArray( $result );
		$this->assertNull( $result['override_grade'] );
	}

	/** @test */
	public function it_rejects_override_when_student_is_not_a_group_member(): void {
		global $wpdb;
		$wpdb->member_ids      = array( 999 ); // otro estudiante, no el 30
		$wpdb->group_course_id = 40;
		atora_test_set_post_meta( 20, '_clms_lesson_course_id', 40 );

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_override( 10, 20, 30, 80, 'extra', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'student_not_in_group', $result->get_error_code() );
	}

	/** @test */
	public function it_rejects_override_when_lesson_belongs_to_another_course(): void {
		global $wpdb;
		$wpdb->member_ids      = array( 30 );
		$wpdb->group_course_id = 40; // curso del grupo
		atora_test_set_post_meta( 20, '_clms_lesson_course_id', 999 ); // lección de OTRO curso

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_override( 10, 20, 30, 80, 'extra', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lesson_not_in_course', $result->get_error_code() );
	}
}
}
