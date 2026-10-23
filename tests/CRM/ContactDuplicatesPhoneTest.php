<?php
/**
 * Bloque 5: duplicados por teléfono (y match_type explícito).
 */

declare( strict_types = 1 );

namespace ATORA\LMS {
	if ( ! class_exists( Tenant_Context::class ) ) {
		final class Tenant_Context {
			public static function current_institution_id() {
				return 7;
			}
		}
	}
}

namespace ATORA\Tests\CRM {

use ATORA\CRM_V2\Services\Contact_Service;
use PHPUnit\Framework\TestCase;

class FakeWpdbDuplicatesPhone {
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
		if ( str_contains( $sql, 'email_key' ) ) {
			return array(
				array( 'email_key' => 'dup@example.test', 'ids' => '1,2', 'total' => 2 ),
			);
		}
		if ( str_contains( $sql, 'phone_key' ) && str_contains( $sql, 'phone <>' ) ) {
			return array(
				array( 'phone_key' => '584125550000', 'ids' => '3,4', 'total' => 2 ),
			);
		}
		if ( preg_match( '/SELECT \* FROM wp_atora_contacts WHERE id IN \(([^)]+)\)/', $sql ) ) {
			return array(
				array( 'id' => 1, 'email' => 'dup@example.test', 'institution_id' => 7 ),
				array( 'id' => 2, 'email' => 'dup@example.test', 'institution_id' => 7 ),
			);
		}
		return array(
			array( 'id' => 3, 'phone' => '+58 412 5550000', 'institution_id' => 7 ),
			array( 'id' => 4, 'phone' => '+58 412 5550000', 'institution_id' => 7 ),
		);
	}
}

final class ContactDuplicatesPhoneTest extends TestCase {
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
		$GLOBALS['__atora_test_user_caps'][1]['manage_options'] = true;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		unset( $GLOBALS['__atora_test_user_caps'][1]['manage_options'] );
		parent::tearDown();
	}

	/** @test */
	public function find_duplicates_includes_phone_matches(): void {
		global $wpdb;
		$wpdb = new FakeWpdbDuplicatesPhone();

		$dups = Contact_Service::find_duplicates( 10 );
		$this->assertNotEmpty( $dups );

		$types = array_map( static fn( $d ) => (string) ( $d['match_type'] ?? '' ), $dups );
		$this->assertContains( 'phone', $types, 'debe incluir duplicados por phone' );
		$this->assertContains( 'email', $types, 'debe conservar duplicados por email' );
	}
}

}

