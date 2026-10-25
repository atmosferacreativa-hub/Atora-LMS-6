<?php

declare( strict_types = 1 );

final class ScormLaunchAvailabilityTest extends WP_UnitTestCase {
	private function dispatch_start_attempt( int $lesson_id, int $library_item_id ): WP_REST_Response {
		do_action( 'rest_api_init' );
		$req = new WP_REST_Request( 'POST', '/atora/v1/scorm/attempts/start' );
		$req->set_param( 'lesson_id', $lesson_id );
		$req->set_param( 'library_item_id', $library_item_id );
		return rest_get_server()->dispatch( $req );
	}

	public function test_library_item_status_is_enforced_for_students_but_preview_is_allowed(): void {
		$out = ATORA_E2E_Seed_CLI::seed( '', 'atora-e2e-2026' );
		$lesson_id = (int) ( $out['lessons']['scorm'] ?? 0 );
		$this->assertGreaterThan( 0, $lesson_id );
		$item_id = (int) ( $out['scorm_resources'][0]['library_item_id'] ?? 0 );
		$this->assertGreaterThan( 0, $item_id );
		$student_id = (int) ( $out['student']['id'] ?? 0 );
		$teacher_id = (int) ( $out['teacher']['id'] ?? 0 );
		$this->assertGreaterThan( 0, $student_id );
		$this->assertGreaterThan( 0, $teacher_id );

		global $wpdb;
		$table_items = $wpdb->prefix . 'atora_library_items';
		$table_attempts = $wpdb->prefix . 'atora_scorm_attempts';

		// 1) Draft: estudiante -> 404.
		$wpdb->update( $table_items, array( 'status' => 'draft' ), array( 'id' => $item_id ), array( '%s' ), array( '%d' ) );
		wp_set_current_user( $student_id );
		$r = $this->dispatch_start_attempt( $lesson_id, $item_id );
		$this->assertSame( 404, $r->get_status() );
		$this->assertSame( 'atora_scorm_not_available', (string) ( $r->get_data()['code'] ?? '' ) );

		// 2) Draft: docente/admin puede previsualizar.
		wp_set_current_user( $teacher_id );
		$r = $this->dispatch_start_attempt( $lesson_id, $item_id );
		$this->assertSame( 201, $r->get_status() );

		// 3) Published: estudiante abre (crea intento nuevo).
		$wpdb->update( $table_items, array( 'status' => 'published' ), array( 'id' => $item_id ), array( '%s' ), array( '%d' ) );
		wp_set_current_user( $student_id );
		$r = $this->dispatch_start_attempt( $lesson_id, $item_id );
		$this->assertSame( 201, $r->get_status() );
		$attempt_id = (int) ( $r->get_data()['attempt_id'] ?? 0 );
		$this->assertGreaterThan( 0, $attempt_id );

		// 4) Archived: con intento abierto -> reanuda el mismo intento.
		$wpdb->update( $table_items, array( 'status' => 'archived' ), array( 'id' => $item_id ), array( '%s' ), array( '%d' ) );
		$r = $this->dispatch_start_attempt( $lesson_id, $item_id );
		$this->assertSame( 201, $r->get_status() );
		$this->assertSame( $attempt_id, (int) ( $r->get_data()['attempt_id'] ?? 0 ) );

		// 5) Archived: sin intento abierto -> 404.
		$wpdb->update( $table_attempts, array( 'is_complete' => 1 ), array( 'id' => $attempt_id ), array( '%d' ), array( '%d' ) );
		$r = $this->dispatch_start_attempt( $lesson_id, $item_id );
		$this->assertSame( 404, $r->get_status() );
		$this->assertSame( 'atora_scorm_not_available', (string) ( $r->get_data()['code'] ?? '' ) );
	}
}

