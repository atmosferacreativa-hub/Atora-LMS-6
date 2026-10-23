<?php
/**
 * CSV_Export_Service — streaming/batches.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class FakeWpdbCsvExport {
	public string $prefix = 'wp_';
	public string $usermeta = 'wp_usermeta';

	/** @var array<int,string> */
	public array $executed = array();
	public int $select_calls = 0;

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}

	public function get_var( $sql ) {
		$this->executed[] = (string) $sql;
		$sql              = (string) $sql;
		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			if ( preg_match( "/LIKE\s+([^\s']+)/", $sql, $m ) ) {
				return (string) $m[1];
			}
			return '';
		}
		return null;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$this->executed[] = (string) $sql;
		$this->select_calls++;
		$sql = (string) $sql;

		// Simula batch 1 (offset 0) con 1 fila; offset >= 500 vacío.
		if ( str_contains( $sql, 'FROM wp_atora_contacts' ) ) {
			if ( str_contains( $sql, 'OFFSET 0' ) ) {
				$rows = array();
				for ( $i = 0; $i < 500; $i++ ) {
					$rows[] = array(
						'id'         => $i + 1,
						'name'       => 'Ana',
						'email'      => 'ana@example.test',
						'phone'      => '+1 555',
						'country'    => 'VE',
						'city'       => 'CCS',
						'status'     => 'lead',
						'source'     => 'manual',
						'created_at' => '2026-01-01 00:00:00',
					);
				}
				return $rows;
			}
			return array();
		}

		return array();
	}
}

class CsvExportServiceTest extends TestCase {
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function test_contacts_export_streams_in_batches(): void {
		global $wpdb;
		$fake = new FakeWpdbCsvExport();
		$wpdb = $fake;

		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$this->assertIsResource( $stream );

		\ATORA\CRM_V2\Services\CSV_Export_Service::write_csv( 'contacts', $stream, 500 );

		rewind( $stream );
		$content = (string) stream_get_contents( $stream );
		fclose( $stream );

		$this->assertStringContainsString( 'ID,Name,Email,Phone', $content );
		$this->assertStringContainsString( '1,Ana,ana@example.test', $content );
		$this->assertGreaterThanOrEqual( 2, $fake->select_calls, 'debe consultar en batches (al menos 2 selects: batch + batch vacío)' );
		$this->assertStringContainsString( 'LIMIT 500 OFFSET 0', implode( "\n", $fake->executed ) );
	}
}

