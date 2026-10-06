<?php
/**
 * Integración 6.31.0: las entregas web escriben una fila por intento en
 * `atora_assignment_submissions` (solo añadir), y la migración de las entregas
 * web existentes no duplica.
 */

declare( strict_types = 1 );

final class WebSubmissionHistoryTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $course = 0;
	private int $lesson = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_assignment_submissions" );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		$this->lesson  = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'meta_input'  => array( '_clms_course_id' => $this->course, 'lm_activity_type' => 'tarea' ),
		) );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->course );
	}

	private function rows( int $user_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_assignment_submissions WHERE user_id = %d ORDER BY attempt ASC", $user_id ), ARRAY_A );
	}

	/** Envía el formulario web de la tarea, como el navegador. */
	private function web_submit( string $text ): void {
		wp_set_current_user( $this->student );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array(
			'clms_action'             => 'submit_assignment',
			'lesson_id'               => $this->lesson,
			'clms_submission_nonce'   => wp_create_nonce( 'clms_submit_assignment_' . $this->lesson ),
			'clms_submission_comment' => $text,
		);
		$stop = static function () {
			throw new RuntimeException( 'redirect' );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			clms_core( 'CLMS_Submission' )->handle_submission_request();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
		remove_filter( 'wp_redirect', $stop );
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	public function test_each_web_attempt_appends_a_row_and_the_post_stays(): void {
		$this->web_submit( 'Primer intento' );
		$this->web_submit( 'Segundo intento' );

		$rows = $this->rows( $this->student );
		$this->assertCount( 2, $rows, 'Una fila por intento web.' );
		$this->assertSame( array( '1', '2' ), wp_list_pluck( $rows, 'attempt' ) );
		$this->assertSame( array( 'Primer intento', 'Segundo intento' ), wp_list_pluck( $rows, 'body_text' ) );
		$this->assertSame( array( 'web', 'web' ), wp_list_pluck( $rows, 'source' ) );
		$this->assertSame( $rows[0]['wp_post_id'], $rows[1]['wp_post_id'], 'SpeedGrader sigue calificando el mismo post.' );
		$this->assertSame( 'clms_submission', get_post_type( (int) $rows[0]['wp_post_id'] ) );
		$this->assertSame( (int) \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $this->lesson )['id'], (int) $rows[0]['lesson_id'], 'Ids de tabla, como las filas móviles.' );
	}

	public function test_migration_adds_attempt_one_once_and_skips_copies_and_mobile(): void {
		$legacy = array();
		foreach ( array( 'a', 'b' ) as $label ) {
			$user = self::factory()->user->create();
			$post = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $user ) );
			update_post_meta( $post, '_clms_submission_user_id', $user );
			update_post_meta( $post, '_clms_submission_lesson_id', $this->lesson );
			update_post_meta( $post, '_clms_submission_course_id', $this->course );
			update_post_meta( $post, '_clms_submission_comment', 'Entrega ' . $label );
			update_post_meta( $post, '_clms_submission_submitted_at', '2026-09-01 10:00:00' );
			$legacy[ $user ] = $post;
		}
		$shadow = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'private' ) );
		update_post_meta( $shadow, '_clms_submission_is_shadow', '1' );
		update_post_meta( $shadow, '_clms_submission_lesson_id', $this->lesson );

		$first = ATORA_Web_Submission_History::migrate_batch();
		$this->assertGreaterThanOrEqual( 2, $first['migrated'], 'Puede haber entregas de otras pruebas en la base compartida.' );
		foreach ( $legacy as $user => $post ) {
			$rows = $this->rows( $user );
			$this->assertCount( 1, $rows );
			$this->assertSame( '1', $rows[0]['attempt'] );
			$this->assertSame( (string) $post, $rows[0]['wp_post_id'] );
			$this->assertSame( 'web-migrated-' . $post, $rows[0]['client_event_id'] );
		}

		$second = ATORA_Web_Submission_History::migrate_batch();
		$this->assertSame( 0, $second['migrated'], 'Correr dos veces no duplica.' );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_assignment_submissions WHERE wp_post_id = %d", $shadow ) ), 'Las copias grupales no se migran.' );
	}
}
