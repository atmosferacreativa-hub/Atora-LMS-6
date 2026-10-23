<?php
/**
 * Bloque 5: export CSV debe incluir columnas de custom fields.
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use ATORA\CRM_V2\Services\CSV_Export_Service;
use PHPUnit\Framework\TestCase;

class FakeWpdbExportCustomFields {
	public string $prefix = 'wp_';
	public string $usermeta = 'wp_usermeta';

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}

	public function get_var( $sql ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			if ( preg_match( "/LIKE\s+([^\s']+)/", $sql, $m ) ) {
				return str_replace( '\\', '', (string) $m[1] );
			}
			return '';
		}
		return null;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, 'atora_crm_contact_fields' ) ) {
			return array(
				array( 'field_key' => 'favorite_color', 'label' => 'Color', 'field_type' => 'text' ),
			);
		}
		return array();
	}
}

final class CSVExportIncludesCustomFieldsTest extends TestCase {
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
	public function columns_include_cf_prefix(): void {
		global $wpdb;
		$wpdb = new FakeWpdbExportCustomFields();

		$cols = CSV_Export_Service::get_columns( 'contacts' );
		$this->assertContains( 'CF:favorite_color', $cols );
	}
}

