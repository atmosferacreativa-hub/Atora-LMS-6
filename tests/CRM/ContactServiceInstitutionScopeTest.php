<?php
/**
 * Bloque 5: aislamiento por institución en list_contacts.
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

class FakeWpdbInstitutionScope {
	public string $prefix = 'wp_';
	public string $usermeta = 'wp_usermeta';
	public string $last_prepared = '';

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		$this->last_prepared = preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
		return $this->last_prepared;
	}

	public function esc_like( string $s ): string {
		return addcslashes( $s, '_%\\' );
	}

	public function get_var( $sql ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			if ( preg_match( "/LIKE\s+([^\s']+)/", $sql, $m ) ) {
				return str_replace( '\\', '', (string) $m[1] );
			}
			return '';
		}
		// COUNT(*) etc.
		return 0;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$this->last_prepared = (string) $sql;
		return array();
	}
}

final class ContactServiceInstitutionScopeTest extends TestCase {
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
	public function list_contacts_applies_institution_filter(): void {
		global $wpdb;
		$fake = new FakeWpdbInstitutionScope();
		$wpdb = $fake;

		Contact_Service::list_contacts( array( 'limit' => 1, 'offset' => 0 ) );

		$this->assertStringContainsString( 'c.institution_id = 7', (string) $fake->last_prepared );
	}
}

}

