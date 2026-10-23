<?php
/**
 * Bloque 5: import CSV con upsert real + custom field values.
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM {
	if ( ! class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
		require_once __DIR__ . '/../../modules/tenancy/class-tenant-context.php';
	}

use ATORA\CRM_V2\Services\CSV_Import_Service;
use PHPUnit\Framework\TestCase;

class FakeWpdbCsvImport {
	public string $prefix = 'wp_';
	public string $usermeta = 'wp_usermeta';
	public int $insert_id = 0;
	/** @var array<int,array<string,mixed>> */
	public array $updates = array();
	/** @var array<int,string> */
	public array $inserted_tables = array();

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}

	public function esc_like( string $s ): string { return addcslashes( $s, '_%\\' ); }

	public function get_var( $sql ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			if ( preg_match( "/LIKE\s+([^\s']+)/", $sql, $m ) ) {
				return str_replace( '\\', '', (string) $m[1] );
			}
			return '';
		}
		if ( str_contains( $sql, 'FROM wp_atora_institutions' ) ) {
			return 1;
		}

		// Find existing by email
		if ( str_contains( $sql, 'FROM wp_atora_contacts' ) && str_contains( $sql, 'LOWER(TRIM(email))' ) ) {
			return 99;
		}

		// save_contact_custom_value: exists?
		if ( str_contains( $sql, 'FROM wp_atora_crm_contact_field_values' ) && str_contains( $sql, 'SELECT id' ) ) {
			return 0;
		}

		return 0;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, 'atora_crm_contact_fields' ) ) {
			return array(
				array( 'field_key' => 'favorite_color', 'is_required' => 0 ),
			);
		}
		return array();
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		$this->updates[] = array( 'table' => (string) $table, 'data' => $data, 'where' => $where );
		return 1;
	}

	public function insert( $table, $data, $format = null ): int {
		$this->insert_id = 123;
		$this->inserted_tables[] = (string) $table;
		return 1;
	}
}

final class CSVImportUpsertTest extends TestCase {
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
	public function import_updates_existing_and_writes_custom_value(): void {
		global $wpdb;
		$fake = new FakeWpdbCsvImport();
		$wpdb = $fake;

		$csv = "Email,Name,CF:favorite_color\n" .
			"dup@example.test,Ana,Azul\n";

		$result = \ATORA\LMS\Tenant_Context::with( 7, static fn() => CSV_Import_Service::import_csv(
			$csv,
			array(),
			array( 'update_existing' => true )
		) );

		$this->assertSame( 0, $result['inserted'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertNotEmpty( $fake->updates, 'debe actualizar el contacto existente' );
		$this->assertContains( 'wp_atora_crm_contact_field_values', $fake->inserted_tables, 'debe insertar valores custom' );
	}
}

}

