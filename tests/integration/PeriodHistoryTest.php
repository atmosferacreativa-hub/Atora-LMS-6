<?php
/**
 * Integración 6.41.0: historial académico por período (web y app) y aviso de inicio de período.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/LmsRowsCleanup.php';

final class PeriodHistoryTest extends WP_UnitTestCase {

	use LmsRowsCleanup;

	private int $teacher = 0;
	private int $student = 0;
	private int $other = 0;
	/** @var int[] */
	private array $courses = array();
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		ATORA_Periods_Service::ensure_schema();
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana Estudiante' ) );
		$this->other   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( 'Álgebra', 'Biología', 'Cálculo' ) as $title ) {
			$wp = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher, 'post_title' => $title ) );
			$id = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp )['id'];
			\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student, $id );
			CLMS_Helper::enroll_user_in_course( $this->student, $wp );
			$this->courses[ $title ] = $wp;
		}
		$this->token = ATORA_Mobile_Token_Service::issue( $this->student, 'test' )['access_token'];
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_period_courses" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_periods" );
		$this->cleanup_lms_rows( array_values( $this->courses ), array( $this->student, $this->other ) );
		update_option( 'timezone_string', '' );
		parent::tearDown();
	}

	private function period( string $name, string $starts, string $ends, string $status, string $visibility, array $courses ): int {
		global $wpdb;
		$inst = ATORA_Teacher_Scope::course_institution( reset( $courses ) );
		$wpdb->insert( ATORA_Periods_Service::table_periods(), array( 'institution_id' => $inst, 'name' => $name, 'starts_on' => $starts, 'ends_on' => $ends, 'status' => $status, 'past_visibility' => $visibility ) );
		$id = (int) $wpdb->insert_id;
		foreach ( $courses as $wp ) {
			$wpdb->insert( ATORA_Periods_Service::table_links(), array( 'institution_id' => $inst, 'period_id' => $id, 'wp_course_id' => $wp ) );
		}
		return $id;
	}

	public function test_history_groups_newest_period_first_and_keeps_hidden_courses(): void {
		$this->period( '2025-2', '2025-07-01', '2025-12-15', 'closed', 'hidden', array( $this->courses['Álgebra'] ) );
		$this->period( '2026-1', '2026-01-10', '2026-06-30', 'closed', 'visible', array( $this->courses['Biología'] ) );

		$data   = ATORA_Period_History::data( $this->student );
		$labels = array_map( static fn( $g ) => $g['period'] ? $g['period']['name'] : 'sin período', $data['groups'] );
		$this->assertSame( array( 'sin período', '2026-1', '2025-2' ), $labels );
		$this->assertSame( 'Cálculo', $data['groups'][0]['courses'][0]['title'] );
		$this->assertSame( 'Álgebra', $data['groups'][2]['courses'][0]['title'], 'El curso de un período oculto sigue en el historial (registro de notas).' );
		$this->assertArrayHasKey( 'final_grade', $data['groups'][1]['courses'][0] );
		$this->assertSame( 'Ana Estudiante', $data['student'] );
	}

	public function test_pdf_is_generated(): void {
		$this->period( '2026-1', '2026-01-10', '2026-06-30', 'closed', 'visible', array( $this->courses['Biología'] ) );
		$pdf = ATORA_Period_History::pdf( $this->student );
		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertStringContainsString( 'Biología', ATORA_Period_History::html( ATORA_Period_History::data( $this->student ) ) );
	}

	public function test_app_gets_history_and_a_signed_link_only_for_the_same_user(): void {
		$request = new WP_REST_Request( 'GET', '/atora-mobile/v1/me/history' );
		$request->set_header( 'Authorization', 'Bearer ' . $this->token );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 3, $data['groups'][0]['courses'] );
		parse_str( (string) wp_parse_url( $data['document_url'], PHP_URL_QUERY ), $query );

		$doc = new WP_REST_Request( 'GET', '/atora-mobile/v1/me/history/document' );
		$doc->set_header( 'Authorization', 'Bearer ' . $this->token );
		$doc->set_query_params( array( 'expires' => $query['expires'], 'sig' => 'falsa' ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $doc )->get_status(), 'Firma inválida.' );

		$other = new WP_REST_Request( 'GET', '/atora-mobile/v1/me/history/document' );
		$other->set_header( 'Authorization', 'Bearer ' . ATORA_Mobile_Token_Service::issue( $this->other, 'test' )['access_token'] );
		$other->set_query_params( array( 'expires' => $query['expires'], 'sig' => $query['sig'] ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $other )->get_status(), 'Otro usuario con el enlace ajeno.' );

		$expired = time() - 10;
		$old     = new WP_REST_Request( 'GET', '/atora-mobile/v1/me/history/document' );
		$old->set_header( 'Authorization', 'Bearer ' . $this->token );
		$old->set_query_params( array( 'expires' => $expired, 'sig' => ATORA_Period_History::signature( $this->student, $expired ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $old )->get_status(), 'Enlace vencido.' );

		$ok = new WP_REST_Request( 'GET', '/atora-mobile/v1/me/history/document' );
		$ok->set_header( 'Authorization', 'Bearer ' . $this->token );
		$ok->set_query_params( array( 'expires' => $query['expires'], 'sig' => $query['sig'] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $ok )->get_status() );
		remove_all_filters( 'rest_pre_serve_request' );
	}

	private function period_notices(): array {
		$notifications = clms_core( 'CLMS_Notifications' );
		return array_values( array_filter( (array) $notifications->get_notifications( $this->student ), static fn( $n ) => str_starts_with( (string) ( $n['title'] ?? '' ), 'Comienza ' ) ) );
	}

	public function test_period_start_notice_once_per_student_with_their_courses(): void {
		update_option( 'timezone_string', 'America/Caracas' );
		$today = wp_date( 'Y-m-d' );
		$this->period( 'Semestre actual', $today, '2099-12-31', 'open', 'visible', array( $this->courses['Álgebra'], $this->courses['Cálculo'] ) );
		$this->period( 'Semestre futuro', '2099-01-01', '2099-06-30', 'open', 'visible', array( $this->courses['Biología'] ) );

		// El buzón vive en tablas propias que no se revierten entre pruebas: se cuenta lo nuevo.
		$before = array_map( static fn( $n ) => (string) ( $n['id'] ?? '' ), $this->period_notices() );
		$this->assertSame( 1, ATORA_Period_Notices::run() );
		$this->assertSame( 0, ATORA_Period_Notices::run(), 'No se repite.' );
		$notices = array_values( array_filter( $this->period_notices(), static fn( $n ) => ! in_array( (string) ( $n['id'] ?? '' ), $before, true ) ) );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'Comienza Semestre actual', $notices[0]['title'] );
		$this->assertStringContainsString( 'Álgebra, Cálculo', (string) ( $notices[0]['message'] ?? $notices[0]['body'] ?? '' ) );
	}

	public function test_schema_upgrade_from_version_1_adds_the_notice_column(): void {
		global $wpdb;
		$table = ATORA_Periods_Service::table_periods();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN start_notified_at" ); // phpcs:ignore WordPress.DB
		update_option( 'atora_periods_schema_version', '1', false );
		ATORA_Periods_Service::ensure_schema();
		$this->assertNotEmpty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'start_notified_at'" ) ); // phpcs:ignore WordPress.DB
	}
}
