<?php

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/mobile/class-mobile-db-errors.php';
require_once __DIR__ . '/../../includes/mobile/class-mobile-assignment-store.php';
require_once __DIR__ . '/../../includes/mobile/class-mobile-assignment-service.php';

/** Almacén en memoria con la misma unicidad (user_id, client_event_id) que la tabla. */
final class InMemoryAssignmentStore implements \ATORA_Mobile_Assignment_Store {
	public array $submissions = array();
	public array $uploads     = array();
	/** 6.28.2: simula un error de base de datos en las escrituras de actualización. */
	public bool $fail_writes  = false;
	private int $next_sub     = 1;
	private int $next_upload  = 1;

	public function find_submission_by_event( int $user_id, string $client_event_id ): ?array {
		foreach ( $this->submissions as $row ) {
			if ( (int) $row['user_id'] === $user_id && $row['client_event_id'] === $client_event_id ) {
				return $row;
			}
		}
		return null;
	}

	public function max_attempt( int $user_id, int $lesson_id ): int {
		$max = 0;
		foreach ( $this->submissions as $row ) {
			if ( (int) $row['user_id'] === $user_id && (int) $row['lesson_id'] === $lesson_id ) {
				$max = max( $max, (int) $row['attempt'] );
			}
		}
		return $max;
	}

	public function list_submissions( int $user_id, int $lesson_id ): array {
		$rows = array_values( array_filter( $this->submissions, static function ( $row ) use ( $user_id, $lesson_id ) {
			return (int) $row['user_id'] === $user_id && (int) $row['lesson_id'] === $lesson_id && 'processing' !== $row['status'];
		} ) );
		usort( $rows, static function ( $a, $b ) { return $b['attempt'] <=> $a['attempt']; } );
		return $rows;
	}

	public function insert_submission( array $row ): int {
		if ( $this->find_submission_by_event( (int) $row['user_id'], (string) $row['client_event_id'] ) ) {
			return 0;
		}
		$row['id'] = $this->next_sub++;
		$this->submissions[ $row['id'] ] = $row;
		return $row['id'];
	}

	public function update_submission( int $id, array $fields ): bool {
		if ( $this->fail_writes ) {
			return false;
		}
		$this->submissions[ $id ] = array_merge( $this->submissions[ $id ], $fields );
		return true;
	}

	public function delete_submission( int $id ): void {
		unset( $this->submissions[ $id ] );
	}

	public function insert_upload( array $row ): int {
		$row['id'] = $this->next_upload++;
		$this->uploads[ $row['id'] ] = $row;
		return $row['id'];
	}

	public function find_upload( string $upload_token ): ?array {
		foreach ( $this->uploads as $row ) {
			if ( $row['upload_token'] === $upload_token ) {
				return $row;
			}
		}
		return null;
	}

	public function update_upload( int $id, array $fields ): bool {
		if ( $this->fail_writes ) {
			return false;
		}
		$this->uploads[ $id ] = array_merge( $this->uploads[ $id ], $fields );
		return true;
	}

	public function advance_upload( int $id, int $from, int $to ): ?bool {
		if ( $this->fail_writes ) {
			return null;
		}
		if ( 'open' !== $this->uploads[ $id ]['status'] || (int) $this->uploads[ $id ]['received_bytes'] !== $from ) {
			return false;
		}
		$this->uploads[ $id ]['received_bytes'] = $to;
		return true;
	}

	public function expired_uploads( string $now_utc, int $limit ): array {
		return array_values( array_filter( $this->uploads, static function ( $row ) use ( $now_utc ) {
			return 'attached' !== $row['status'] && $row['expires_at'] < $now_utc;
		} ) );
	}

	public function delete_upload( int $id ): void {
		unset( $this->uploads[ $id ] );
	}
}

final class MobileAssignmentServiceTest extends TestCase {
	private const DUE = 1790000000; // Fecha límite de la tarea.
	private const PDF = "%PDF-1.4\n%test pdf body\n";

	private InMemoryAssignmentStore $store;
	private string $dir;
	private int $now;
	private bool $rate_ok;
	private array $bridge_calls;
	private $bridge_result;

	protected function setUp(): void {
		parent::setUp();
		$this->store        = new InMemoryAssignmentStore();
		$this->dir          = sys_get_temp_dir() . '/atora-mobile-uploads-' . bin2hex( random_bytes( 4 ) );
		$this->now          = self::DUE - 3600;
		$this->rate_ok      = true;
		$this->bridge_calls = array();
		$this->bridge_result = null;
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/{,.}*', GLOB_BRACE ) ?: array() as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	private function service(): \ATORA_Mobile_Assignment_Service {
		return new \ATORA_Mobile_Assignment_Service(
			$this->store,
			$this->dir,
			array(
				'allowed_mimes' => array( 'pdf' => 'application/pdf', 'png' => 'image/png' ),
				'max_file_size' => 1024 * 1024,
				'max_files'     => 2,
			),
			fn (): int => $this->now,
			fn (): bool => $this->rate_ok,
			// Tipo real por contenido: solo reconoce PDF por su firma.
			static fn ( string $path ): string => str_starts_with( (string) file_get_contents( $path ), '%PDF' ) ? 'pdf' : '',
			function ( int $user_id, array $lesson, array $row, array $uploads ) {
				$this->bridge_calls[] = array( $user_id, $row['attempt'], count( $uploads ) );
				return $this->bridge_result ?? array( 'wp_post_id' => 900, 'attachment_ids' => array_fill( 0, count( $uploads ), 77 ) );
			}
		);
	}

	private function lesson( array $overrides = array() ): array {
		return array_merge( array(
			'lesson_id'          => 12,
			'wp_lesson_id'       => 5001,
			'course_id'          => 29,
			'due_ts'             => self::DUE,
			'allow_resubmission' => true,
			'group_mode'         => false,
		), $overrides );
	}

	private function uploaded_token( \ATORA_Mobile_Assignment_Service $service, int $user_id, string $content = self::PDF, string $name = 'tarea.pdf' ): string {
		$session = $service->create_upload_session( $user_id, array( 'filename' => $name, 'total_bytes' => strlen( $content ) ) );
		$this->assertIsArray( $session );
		$len = strlen( $content );
		$put = $service->put_chunk( $user_id, $session['upload_token'], 'bytes 0-' . ( $len - 1 ) . '/' . $len, $content );
		$this->assertIsArray( $put );
		$this->assertIsArray( $service->complete_upload( $user_id, $session['upload_token'] ) );
		return $session['upload_token'];
	}

	private static function http_status( $result ): int {
		return $result instanceof \WP_Error ? (int) ( $result->get_error_data()['status'] ?? 0 ) : 200;
	}

	public function test_db_error_while_advancing_a_chunk_is_503_not_out_of_order(): void {
		$service = $this->service();
		$session = $service->create_upload_session( 10, array( 'filename' => 'tarea.pdf', 'total_bytes' => strlen( self::PDF ) ) );
		$this->store->fail_writes = true;
		$len = strlen( self::PDF );
		$put = $service->put_chunk( 10, $session['upload_token'], 'bytes 0-' . ( $len - 1 ) . '/' . $len, self::PDF );
		$this->assertSame( 503, self::http_status( $put ) );
		$this->assertSame( 0, (int) $this->store->find_upload( $session['upload_token'] )['received_bytes'] );

		$this->store->fail_writes = false;
		$this->assertIsArray( $service->put_chunk( 10, $session['upload_token'], 'bytes 0-' . ( $len - 1 ) . '/' . $len, self::PDF ), 'El reintento del mismo fragmento entra.' );
	}

	public function test_db_error_closing_an_upload_is_503_and_retry_closes_it(): void {
		$service = $this->service();
		$session = $service->create_upload_session( 10, array( 'filename' => 'tarea.pdf', 'total_bytes' => strlen( self::PDF ) ) );
		$len = strlen( self::PDF );
		$service->put_chunk( 10, $session['upload_token'], 'bytes 0-' . ( $len - 1 ) . '/' . $len, self::PDF );
		$this->store->fail_writes = true;
		$this->assertSame( 503, self::http_status( $service->complete_upload( 10, $session['upload_token'] ) ) );
		$this->store->fail_writes = false;
		$this->assertSame( 'complete', $service->complete_upload( 10, $session['upload_token'] )['status'] );
	}

	public function test_db_error_finishing_a_submission_is_503_not_200(): void {
		$service = $this->service();
		$this->store->fail_writes = true;
		$result = $service->create_submission( 10, $this->lesson(), array( 'client_event_id' => 'evt-dbfail-01', 'body_text' => 'Hola' ) );
		$this->assertSame( 503, self::http_status( $result ) );
	}

	public function test_replaying_the_same_event_returns_the_same_submission_without_duplicating(): void {
		$service = $this->service();
		$params  = array( 'client_event_id' => 'evt-aaaa-0001', 'body_text' => 'Mi respuesta' );

		$first  = $service->create_submission( 10, $this->lesson(), $params );
		$second = $service->create_submission( 10, $this->lesson(), $params );

		$this->assertFalse( $first['replayed'] );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( $first['submission']['id'], $second['submission']['id'] );
		$this->assertCount( 1, $this->store->submissions );
		$this->assertCount( 1, $this->bridge_calls, 'El post de SpeedGrader solo se escribe una vez.' );
	}

	public function test_each_attempt_is_a_new_row_and_earlier_rows_are_not_overwritten(): void {
		$service = $this->service();
		$service->create_submission( 10, $this->lesson(), array( 'client_event_id' => 'evt-aaaa-0001', 'body_text' => 'Intento uno' ) );
		$before = $this->store->submissions[1];
		$service->create_submission( 10, $this->lesson(), array( 'client_event_id' => 'evt-aaaa-0002', 'body_text' => 'Intento dos' ) );

		$this->assertCount( 2, $this->store->submissions );
		$this->assertSame( $before, $this->store->submissions[1] );
		$this->assertSame( 2, $this->store->submissions[2]['attempt'] );
		$this->assertSame( array( 2, 1 ), array_column( $service->list_submissions( 10, 12 ), 'attempt' ) );
	}

	public function test_event_id_reused_for_another_lesson_is_rejected(): void {
		$service = $this->service();
		$service->create_submission( 10, $this->lesson(), array( 'client_event_id' => 'evt-aaaa-0001', 'body_text' => 'x' ) );
		$result = $service->create_submission( 10, $this->lesson( array( 'lesson_id' => 13 ) ), array( 'client_event_id' => 'evt-aaaa-0001', 'body_text' => 'x' ) );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_is_late_uses_server_reception_time_not_the_client_time(): void {
		$service   = $this->service();
		$this->now = self::DUE + 600; // Llega 10 minutos tarde...
		$late      = $service->create_submission( 10, $this->lesson(), array(
			'client_event_id'     => 'evt-late-0001',
			'body_text'           => 'hecha sin conexión',
			'client_submitted_at' => gmdate( 'c', self::DUE - 86400 ), // ...aunque se hizo un día antes.
		) );
		$this->assertTrue( $late['submission']['is_late'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::DUE - 86400 ), $late['submission']['client_submitted_at'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::DUE + 600 ), $late['submission']['server_received_at'] );

		$this->now = self::DUE - 60; // Llega a tiempo aunque el reloj del dispositivo diga otra cosa.
		$on_time   = $service->create_submission( 10, $this->lesson(), array(
			'client_event_id'     => 'evt-late-0002',
			'body_text'           => 'reloj adelantado',
			'client_submitted_at' => gmdate( 'c', self::DUE + 86400 ),
		) );
		$this->assertFalse( $on_time['submission']['is_late'] );
	}

	public function test_upload_token_of_another_user_behaves_as_not_found(): void {
		$service = $this->service();
		$token   = $this->uploaded_token( $service, 10 );

		$this->assertSame( 404, $service->put_chunk( 11, $token, 'bytes 0-0/1', 'x' )->get_error_data()['status'] );
		$this->assertSame( 404, $service->complete_upload( 11, $token )->get_error_data()['status'] );
		$stolen = $service->create_submission( 11, $this->lesson(), array( 'client_event_id' => 'evt-steal-001', 'files' => array( array( 'upload_token' => $token ) ) ) );
		$this->assertSame( 404, $stolen->get_error_data()['status'] );
		$this->assertCount( 0, $this->store->submissions );
	}

	public function test_file_whose_real_content_is_not_allowed_is_rejected_and_deleted(): void {
		$service = $this->service();
		$fake    = "MZ\x90\x00 not really a pdf";
		$session = $service->create_upload_session( 10, array( 'filename' => 'tarea.pdf', 'total_bytes' => strlen( $fake ) ) );
		$service->put_chunk( 10, $session['upload_token'], 'bytes 0-' . ( strlen( $fake ) - 1 ) . '/' . strlen( $fake ), $fake );
		$path = $this->store->find_upload( $session['upload_token'] )['storage_path'];

		$result = $service->complete_upload( 10, $session['upload_token'] );

		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 'rejected', $this->store->find_upload( $session['upload_token'] )['status'] );
	}

	public function test_declared_type_outside_the_web_list_is_rejected(): void {
		$result = $this->service()->create_upload_session( 10, array( 'filename' => 'virus.exe', 'total_bytes' => 10 ) );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertCount( 0, $this->store->uploads );
	}

	public function test_chunks_must_arrive_in_order_and_report_received_bytes(): void {
		$service = $this->service();
		$content = str_repeat( 'A', 70000 ) . str_repeat( 'B', 70000 );
		$content = '%PDF' . substr( $content, 4 );
		$session = $service->create_upload_session( 10, array( 'filename' => 'tarea.pdf', 'total_bytes' => strlen( $content ), 'chunk_size' => 70000 ) );
		$token   = $session['upload_token'];

		$out_of_order = $service->put_chunk( 10, $token, 'bytes 70000-139999/140000', substr( $content, 70000 ) );
		$this->assertSame( 409, $out_of_order->get_error_data()['status'] );
		$this->assertSame( 0, $out_of_order->get_error_data()['received_bytes'] );

		$this->assertSame( 70000, $service->put_chunk( 10, $token, 'bytes 0-69999/140000', substr( $content, 0, 70000 ) )['received_bytes'] );
		$repeat = $service->put_chunk( 10, $token, 'bytes 0-69999/140000', substr( $content, 0, 70000 ) );
		$this->assertSame( 70000, $repeat->get_error_data()['received_bytes'], 'Un fragmento repetido no se duplica.' );
		$this->assertSame( 409, $service->complete_upload( 10, $token )->get_error_data()['status'], 'No se cierra incompleta.' );

		$service->put_chunk( 10, $token, 'bytes 70000-139999/140000', substr( $content, 70000 ) );
		$this->assertSame( 'complete', $service->complete_upload( 10, $token )['status'] );
		$this->assertSame( $content, file_get_contents( $this->store->find_upload( $token )['storage_path'] ) );
	}

	public function test_expired_session_is_rejected_and_cleaned_up(): void {
		$service = $this->service();
		$session = $service->create_upload_session( 10, array( 'filename' => 'tarea.pdf', 'total_bytes' => 4 ) );
		$path    = $this->store->find_upload( $session['upload_token'] )['storage_path'];

		$this->now += \ATORA_Mobile_Assignment_Service::UPLOAD_TTL + 1;

		$this->assertSame( 410, $service->put_chunk( 10, $session['upload_token'], 'bytes 0-3/4', '%PDF' )->get_error_data()['status'] );
		$this->assertSame( 1, $service->cleanup_expired() );
		$this->assertFileDoesNotExist( $path );
		$this->assertNull( $this->store->find_upload( $session['upload_token'] ) );
	}

	public function test_submission_with_file_links_upload_and_it_cannot_be_reused(): void {
		$service = $this->service();
		$token   = $this->uploaded_token( $service, 10 );

		$result = $service->create_submission( 10, $this->lesson(), array( 'client_event_id' => 'evt-file-0001', 'files' => array( array( 'upload_token' => $token ) ) ) );
		$this->assertSame( 'tarea.pdf', $result['submission']['files'][0]['filename'] );
		$this->assertSame( 'attached', $this->store->find_upload( $token )['status'] );
		$this->assertSame( 900, $this->store->submissions[1]['wp_post_id'] );

		$reuse = $service->create_submission( 10, $this->lesson(), array( 'client_event_id' => 'evt-file-0002', 'files' => array( array( 'upload_token' => $token ) ) ) );
		$this->assertSame( 409, $reuse->get_error_data()['status'] );
	}

	public function test_failed_speedgrader_bridge_discards_the_row_so_a_retry_succeeds(): void {
		$service             = $this->service();
		$this->bridge_result = new \WP_Error( 'atora_mobile_submission_bridge', 'fail', array( 'status' => 500 ) );
		$params              = array( 'client_event_id' => 'evt-retry-001', 'body_text' => 'x' );

		$this->assertSame( 500, $service->create_submission( 10, $this->lesson(), $params )->get_error_data()['status'] );
		$this->assertCount( 0, $this->store->submissions );

		$this->bridge_result = null;
		$this->assertFalse( $service->create_submission( 10, $this->lesson(), $params )['replayed'] );
		$this->assertCount( 1, $this->store->submissions );
	}

	public function test_group_lessons_closed_resubmission_and_rate_limit_are_definitive_errors(): void {
		$service = $this->service();
		$this->assertSame( 422, $service->create_submission( 10, $this->lesson( array( 'group_mode' => true ) ), array( 'client_event_id' => 'evt-group-001', 'body_text' => 'x' ) )->get_error_data()['status'] );

		$closed = $this->lesson( array( 'allow_resubmission' => false ) );
		$service->create_submission( 10, $closed, array( 'client_event_id' => 'evt-once-0001', 'body_text' => 'x' ) );
		$this->assertSame( 409, $service->create_submission( 10, $closed, array( 'client_event_id' => 'evt-once-0002', 'body_text' => 'y' ) )->get_error_data()['status'] );

		$this->rate_ok = false;
		$this->assertSame( 429, $service->create_submission( 10, $this->lesson(), array( 'client_event_id' => 'evt-rate-0001', 'body_text' => 'x' ) )->get_error_data()['status'] );
		$this->assertSame( 429, $service->create_upload_session( 10, array( 'filename' => 'a.pdf', 'total_bytes' => 4 ) )->get_error_data()['status'] );
	}

	public function test_partial_chunks_are_stored_outside_web_execution(): void {
		$service = $this->service();
		$session = $service->create_upload_session( 10, array( 'filename' => 'tarea.pdf', 'total_bytes' => 4 ) );
		$path    = $this->store->find_upload( $session['upload_token'] )['storage_path'];

		$this->assertStringEndsWith( '.part', $path );
		$this->assertStringStartsWith( $this->dir . '/', $path );
		$this->assertStringContainsString( 'denied', (string) file_get_contents( $this->dir . '/.htaccess' ) );
	}
}
