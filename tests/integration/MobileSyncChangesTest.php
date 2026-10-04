<?php
/**
 * Integración 6.28.0: /sync/changes (registro de cambios) y posición de reproducción.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Content_Changes;
use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class MobileSyncChangesTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $wp_mine = 0;
	private int $wp_other = 0;
	private int $mine = 0;
	private int $other = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		// El DDL de force_install() confirma la transacción del test: se limpian filas de corridas previas.
		global $wpdb;
		foreach ( array( 'atora_lessons', 'atora_courses', 'atora_enrollments', 'atora_content_changes', 'atora_lesson_positions' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		delete_option( LMS_Content_Changes::PURGED_OPTION );

		$this->student  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->wp_mine  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Mío' ) );
		$this->wp_other = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Ajeno' ) );
		$this->mine     = absint( LMS_Course_Service::get_by_wp_post( $this->wp_mine )['id'] );
		$this->other    = absint( LMS_Course_Service::get_by_wp_post( $this->wp_other )['id'] );
		LMS_Enrollment_Service::enroll( $this->student, $this->mine );
	}

	private function lesson( int $wp_course, string $title ): int {
		$wp_id = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => $title,
			'meta_input'  => array( '_clms_course_id' => $wp_course ),
		) );
		return absint( LMS_Course_Service::get_lesson_by_wp_post( $wp_id )['id'] );
	}

	private function wp_lesson( int $lesson_id ): int {
		return absint( LMS_Course_Service::get_lesson( $lesson_id )['wp_post_id'] );
	}

	private function sync( string $cursor = '', int $page = LMS_Content_Changes::PAGE_SIZE ): array {
		return LMS_Content_Changes::for_user( $this->student, array( $this->mine ), $cursor, $page );
	}

	private function request( string $method, string $route, array $params = array(), array $url = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_url_params( $url );
		return $request;
	}

	public function test_full_then_incremental_only_returns_what_changed(): void {
		$a = $this->lesson( $this->wp_mine, 'A' );
		$this->lesson( $this->wp_mine, 'B' );

		$full = $this->sync();
		$this->assertTrue( $full['full'] );
		$this->assertSame( array( 'course', 'lesson', 'lesson' ), array_column( $full['items'], 'type' ) );

		// Solo cambia un recurso de A: sin tocar columnas de la tabla, igual sube la revisión.
		$rev = absint( LMS_Course_Service::get_lesson( $a )['revision'] );
		update_post_meta( $this->wp_lesson( $a ), '_clms_lesson_resources', array( array( 'title' => 'Guía', 'url' => 'https://example.org/guia.pdf' ) ) );
		wp_update_post( array( 'ID' => $this->wp_lesson( $a ) ) );

		$inc = $this->sync( $full['next_cursor'] );
		$this->assertFalse( $inc['full'] );
		$this->assertCount( 1, $inc['items'] );
		$this->assertSame( array( 'type' => 'lesson', 'id' => $a, 'course_id' => $this->mine, 'revision' => $rev + 1, 'removed' => false ), $inc['items'][0] );

		$this->assertSame( array(), $this->sync( $inc['next_cursor'] )['items'], 'Sin cambios nuevos, nada.' );
	}

	public function test_other_courses_changes_are_not_visible(): void {
		$cursor = $this->sync()['next_cursor'];
		$foreign = $this->lesson( $this->wp_other, 'Ajena' );
		wp_update_post( array( 'ID' => $this->wp_lesson( $foreign ), 'post_title' => 'Ajena editada' ) );
		wp_update_post( array( 'ID' => $this->wp_other, 'post_title' => 'Ajeno editado' ) );

		$this->assertSame( array(), $this->sync( $cursor )['items'] );
	}

	public function test_several_saves_collapse_to_last_change_and_removed_is_reported(): void {
		$lesson = $this->lesson( $this->wp_mine, 'Uno' );
		$cursor = $this->sync()['next_cursor'];
		wp_update_post( array( 'ID' => $this->wp_lesson( $lesson ), 'post_title' => 'Dos' ) );
		wp_update_post( array( 'ID' => $this->wp_lesson( $lesson ), 'post_title' => 'Tres' ) );
		wp_update_post( array( 'ID' => $this->wp_lesson( $lesson ), 'post_status' => 'draft' ) );

		$items = $this->sync( $cursor )['items'];
		$this->assertCount( 1, $items );
		$this->assertTrue( $items[0]['removed'] );
	}

	public function test_incremental_pages_when_over_page_size(): void {
		$cursor = $this->sync()['next_cursor'];
		$ids    = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->lesson( $this->wp_mine, "L{$i}" );
		}
		wp_update_post( array( 'ID' => $this->wp_mine, 'post_title' => 'Mío editado' ) );

		$seen = array();
		$pages = 0;
		do {
			$page   = $this->sync( $cursor, 2 );
			$cursor = $page['next_cursor'];
			$seen   = array_merge( $seen, $page['items'] );
			++$pages;
		} while ( $page['has_more'] && $pages < 10 );

		$this->assertSame( 3, $pages );
		$lesson_ids = array_column( array_filter( $seen, static fn( $i ) => 'lesson' === $i['type'] ), 'id' );
		$this->assertSame( $ids, array_values( $lesson_ids ) );
		$this->assertContains( 'course', array_column( $seen, 'type' ) );
	}

	public function test_full_state_paginates_and_ends_with_incremental_cursor(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->lesson( $this->wp_mine, "L{$i}" );
		}
		$first = $this->sync( '', 2 );
		$this->assertTrue( $first['has_more'] );
		$second = $this->sync( $first['next_cursor'], 2 );
		$third  = $this->sync( $second['next_cursor'], 2 );
		$this->assertFalse( $third['has_more'] );
		$this->assertCount( 5, array_merge( $first['items'], $second['items'], $third['items'] ) );
		$this->assertFalse( $this->sync( $third['next_cursor'] )['full'], 'Tras el estado completo sigue la vía incremental.' );
	}

	public function test_cursor_older_than_retention_asks_for_reset(): void {
		$old = $this->sync()['next_cursor'];
		$this->lesson( $this->wp_mine, 'Nueva' );
		update_option( LMS_Content_Changes::PURGED_OPTION, PHP_INT_MAX >> 1 );

		$result = $this->sync( $old );
		$this->assertTrue( $result['reset'] );
		$this->assertTrue( $result['full'] );
		$this->assertTrue( $this->sync( 'basura' )['reset'], 'Un cursor ilegible también reinicia.' );
	}

	public function test_enrollment_events_are_per_user(): void {
		$cursor   = $this->sync()['next_cursor'];
		$somebody = self::factory()->user->create();
		LMS_Enrollment_Service::enroll( $somebody, $this->other );
		LMS_Enrollment_Service::enroll( $this->student, $this->other );

		$items = LMS_Content_Changes::for_user( $this->student, array( $this->mine, $this->other ), $cursor )['items'];
		$enrollments = array_values( array_filter( $items, static fn( $i ) => 'enrollment' === $i['type'] ) );
		$this->assertCount( 1, $enrollments );
		$this->assertSame( $this->other, $enrollments[0]['course_id'] );
		$this->assertTrue( $enrollments[0]['active'] );
	}

	public function test_purge_keeps_recent_rows(): void {
		global $wpdb;
		$this->lesson( $this->wp_mine, 'Reciente' );
		$wpdb->insert( $wpdb->prefix . 'atora_content_changes', array( 'object_type' => 'lesson', 'object_id' => 1, 'course_id' => $this->mine, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ) );
		$old_id = (int) $wpdb->insert_id;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_content_changes SET id = 1 WHERE id = %d", $old_id ) );

		$this->assertSame( 1, LMS_Content_Changes::purge() );
		$this->assertSame( 1, absint( get_option( LMS_Content_Changes::PURGED_OPTION ) ) );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_content_changes" ) );
	}

	public function test_position_latest_mark_wins_and_resend_is_idempotent(): void {
		$lesson = $this->lesson( $this->wp_mine, 'Video' );
		wp_set_current_user( $this->student );
		$put = function ( int $seconds, string $at, string $event ) use ( $lesson ) {
			$res = ATORA_Mobile_REST_Controller::save_position( $this->request( 'PUT', "/lessons/{$lesson}/position", array(
				'position_seconds'   => $seconds,
				'duration_seconds'   => 600,
				'client_event_id'    => $event,
				'client_recorded_at' => $at,
			), array( 'lesson_id' => $lesson ) ) );
			return is_wp_error( $res ) ? $res : $res->get_data();
		};

		$this->assertTrue( $put( 300, '2026-01-01T10:00:00Z', 'evt_newer_0001' )['applied'] );
		$late = $put( 500, '2026-01-01T09:00:00Z', 'evt_older_0001' );
		$this->assertFalse( $late['applied'], 'Una marca más antigua que llega tarde no pisa a una más nueva.' );
		$this->assertSame( 300, $late['position']['position_seconds'] );

		$again = $put( 300, '2026-01-01T10:00:00Z', 'evt_newer_0001' );
		$this->assertTrue( $again['replayed'] );
		$this->assertFalse( $again['applied'] );

		$this->assertTrue( $put( 60, '2026-01-01T10:05:00Z', 'evt_review_001' )['applied'], 'Volver atrás a repasar gana si es más reciente.' );
		$this->assertSame( 60, ATORA_Mobile_Position_Service::resume_seconds( $this->student, $lesson ) );

		$outsider = self::factory()->user->create();
		wp_set_current_user( $outsider );
		$denied = $put( 10, '2026-01-01T11:00:00Z', 'evt_outsider_1' );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 404, $denied->get_error_data()['status'] );
	}
}
