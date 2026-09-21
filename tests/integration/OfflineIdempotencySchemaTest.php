<?php
/**
 * Integración: esquema de idempotencia offline (client_event_id).
 */

declare( strict_types = 1 );

final class OfflineIdempotencySchemaTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
	}

	public function test_lesson_progress_unique_allows_null_and_blocks_duplicate_event(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_lesson_progress';

		// Dos filas con NULL deben convivir bajo el índice único.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'user_id'           => 10,
				'lesson_id'         => 100,
				'course_id'         => 200,
				'wp_lesson_id'      => 0,
				'status'            => 'completed',
				'client_event_id'   => null,
				'client_completed_at' => null,
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'user_id'           => 10,
				'lesson_id'         => 101,
				'course_id'         => 200,
				'wp_lesson_id'      => 0,
				'status'            => 'completed',
				'client_event_id'   => null,
				'client_completed_at' => null,
			)
		);

		$event = 'evt_abc';
		$ok1 = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'user_id'         => 11,
				'lesson_id'       => 110,
				'course_id'       => 210,
				'wp_lesson_id'    => 0,
				'status'          => 'completed',
				'client_event_id' => $event,
			),
			array( '%d','%d','%d','%d','%s','%s' )
		);
		$this->assertNotFalse( $ok1 );

		$ok2 = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'user_id'         => 11,
				'lesson_id'       => 111,
				'course_id'       => 210,
				'wp_lesson_id'    => 0,
				'status'          => 'completed',
				'client_event_id' => $event,
			),
			array( '%d','%d','%d','%d','%s','%s' )
		);
		$this->assertFalse( $ok2, 'Debe rechazar el mismo client_event_id para el mismo user_id.' );
	}

	public function test_quiz_submissions_unique_allows_null_and_blocks_duplicate_event(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_quiz_submissions';

		// Dos filas con NULL deben convivir.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'wp_post_id'      => 1000,
				'user_id'         => 20,
				'quiz_id'         => 1,
				'lesson_id'       => 1,
				'course_id'       => 1,
				'client_event_id' => null,
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'wp_post_id'      => 1001,
				'user_id'         => 20,
				'quiz_id'         => 1,
				'lesson_id'       => 1,
				'course_id'       => 1,
				'client_event_id' => null,
			)
		);

		$event = 'evt_quiz_1';
		$ok1 = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'wp_post_id'      => 1002,
				'user_id'         => 21,
				'quiz_id'         => 2,
				'lesson_id'       => 2,
				'course_id'       => 2,
				'client_event_id' => $event,
			),
			array( '%d','%d','%d','%d','%d','%s' )
		);
		$this->assertNotFalse( $ok1 );

		$ok2 = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'wp_post_id'      => 1003,
				'user_id'         => 21,
				'quiz_id'         => 2,
				'lesson_id'       => 2,
				'course_id'       => 2,
				'client_event_id' => $event,
			),
			array( '%d','%d','%d','%d','%d','%s' )
		);
		$this->assertFalse( $ok2, 'Debe rechazar el mismo client_event_id para el mismo user_id.' );
	}
}

