<?php
/**
 * LMS_REST_Controller::enroll() — dualwrite debe espejar matrícula en legacy.
 *
 * Hallazgo: la ruta REST llamaba directo a LMS_Enrollment_Service::enroll(),
 * saltándose LMS_Write_Facade (responsable de reflejar en usermeta legacy cuando
 * atora_lms_dualwrite está activo).
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

final class EnrollRestDualwriteTest extends TestCase {

	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_options();
		atora_test_reset_user_meta();
		atora_test_reset_post_meta();
		atora_test_reset_user_caps();
		atora_test_reset_users();
		atora_test_reset_missing_users();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		global $wpdb;
		if ( isset( $this->original_wpdb ) ) {
			$wpdb = $this->original_wpdb;
		}
		parent::tearDown();
	}

	private function as_admin( int $user_id ): void {
		atora_test_set_user( $user_id );
		$GLOBALS['__atora_test_current_user_id'] = $user_id;
		atora_test_set_user_cap( $user_id, 'manage_options' );
	}

	private function install_wpdb_fixture( array $course_wp_ids = array() ): object {
		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new class( $course_wp_ids ) {
			public string $prefix = 'wp_';
			public int $insert_id = 1000;
			public array $course_wp_ids;
			public array $enrollments = array();
			public array $insert_calls = array();

			public function __construct( array $course_wp_ids ) {
				$this->course_wp_ids = $course_wp_ids;
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( (string) $sql, 'FROM wp_atora_courses WHERE id =' ) && preg_match( '/id = (\d+)/', (string) $sql, $m ) ) {
					$course_id = (int) $m[1];
					return $this->course_wp_ids[ $course_id ] ?? null;
				}
				return null;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( (string) $sql, 'FROM wp_atora_enrollments' )
					&& preg_match( '/user_id = (\d+) AND course_id = (\d+)/', (string) $sql, $m ) ) {
					$key = $m[1] . ':' . $m[2];
					return $this->enrollments[ $key ] ?? null;
				}
				return null;
			}

			public function insert( $table, $data, $format = null ): int {
				$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
				if ( false !== strpos( (string) $table, 'atora_enrollments' ) ) {
					$this->insert_id++;
					$data['id'] = $this->insert_id;
					$key = (int) ( $data['user_id'] ?? 0 ) . ':' . (int) ( $data['course_id'] ?? 0 );
					$this->enrollments[ $key ] = array(
						'id'     => (int) $data['id'],
						'status' => (string) ( $data['status'] ?? 'active' ),
						'order_id' => (int) ( $data['order_id'] ?? 0 ),
					);
					return 1;
				}
				return 0;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ): int {
				if ( false !== strpos( (string) $table, 'atora_enrollments' ) && isset( $where['id'] ) ) {
					foreach ( $this->enrollments as $k => $row ) {
						if ( (int) $row['id'] === (int) $where['id'] ) {
							$this->enrollments[ $k ] = array_merge( $row, $data );
							break;
						}
					}
				}
				return 1;
			}

			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};
		return $this->original_wpdb;
	}

	/** @test */
	public function test_enroll_dualwrite_true_mirrors_to_legacy_and_is_idempotent(): void {
		$this->as_admin( 10 );
		atora_test_set_user( 999, array( 'user_email' => 'student@example.test', 'display_name' => 'Student' ) );
		$this->install_wpdb_fixture( array( 55 => 777 ) );
		update_option( 'atora_lms_dualwrite', true );

		$request = new \WP_REST_Request( array( 'user_id' => 999, 'order_id' => 1234 ) );
		$request->set_param( 'course_id', 55 );

		$response = \ATORA\LMS\LMS_REST_Controller::enroll( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertTrue( (bool) ( $data['success'] ?? false ) );
		$enrollment_id = (int) ( $data['enrollment_id'] ?? 0 );
		$this->assertGreaterThan( 0, $enrollment_id );

		global $wpdb;
		$this->assertCount( 1, $wpdb->enrollments );
		$enroll_row = array_values( $wpdb->enrollments )[0];
		$this->assertSame( 1234, (int) ( $enroll_row['order_id'] ?? 0 ), 'REST order_id debe preservarse hasta tabla' );
		$this->assertSame( array( 777 ), get_user_meta( 999, '_clms_enrolled_courses', true ) );

		// Repetir la llamada no debe duplicar ni en tabla ni en usermeta.
		$response2 = \ATORA\LMS\LMS_REST_Controller::enroll( $request );
		$this->assertSame( 200, $response2->get_status() );
		$data2 = (array) $response2->get_data();
		$this->assertSame( $enrollment_id, (int) ( $data2['enrollment_id'] ?? 0 ) );
		$this->assertCount( 1, $wpdb->enrollments );
		$this->assertSame( array( 777 ), get_user_meta( 999, '_clms_enrolled_courses', true ) );
	}

	/** @test */
	public function test_enroll_dualwrite_false_does_not_mirror_to_legacy(): void {
		$this->as_admin( 10 );
		atora_test_set_user( 999, array( 'user_email' => 'student@example.test', 'display_name' => 'Student' ) );
		$this->install_wpdb_fixture( array( 55 => 777 ) );
		update_option( 'atora_lms_dualwrite', false );

		$request = new \WP_REST_Request( array( 'user_id' => 999, 'order_id' => 55 ) );
		$request->set_param( 'course_id', 55 );

		$response = \ATORA\LMS\LMS_REST_Controller::enroll( $request );
		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$this->assertCount( 1, $wpdb->enrollments );
		$enroll_row = array_values( $wpdb->enrollments )[0];
		$this->assertSame( 55, (int) ( $enroll_row['order_id'] ?? 0 ) );
		$this->assertSame( '', get_user_meta( 999, '_clms_enrolled_courses', true ) );
	}

	/** @test */
	public function test_enroll_rejects_missing_user_id_and_does_not_write(): void {
		$this->as_admin( 10 );
		$this->install_wpdb_fixture( array( 55 => 777 ) );
		update_option( 'atora_lms_dualwrite', true );
		atora_test_mark_user_missing( 999 );

		$request = new \WP_REST_Request( array( 'user_id' => 999, 'order_id' => 1234 ) );
		$request->set_param( 'course_id', 55 );

		$response = \ATORA\LMS\LMS_REST_Controller::enroll( $request );
		$this->assertSame( 400, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertFalse( (bool) ( $data['success'] ?? true ) );

		global $wpdb;
		$this->assertCount( 0, $wpdb->enrollments, 'No debe insertar matrícula si user_id no existe' );
		$this->assertSame( '', get_user_meta( 999, '_clms_enrolled_courses', true ), 'No debe tocar usermeta legacy si user_id no existe' );
	}
}
