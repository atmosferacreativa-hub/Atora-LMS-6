<?php
/**
 * Bloque 5: merge debe conservar conversaciones (atora_conversations).
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM {
	if ( ! class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
		require_once __DIR__ . '/../../modules/tenancy/class-tenant-context.php';
	}

use ATORA\CRM_V2\Services\Contact_Service;
use PHPUnit\Framework\TestCase;

class FakeWpdbMergeConversations {
	public string $prefix = 'wp_';
	public string $usermeta = 'wp_usermeta';
	public int $insert_id = 1;
	/** @var array<int,string> */
	public array $updated_tables = array();

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
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
		if ( str_contains( $sql, 'FROM wp_atora_institutions' ) ) {
			return 1;
		}
		if ( str_contains( $sql, 'SELECT institution_id' ) && str_contains( $sql, 'wp_atora_contacts' ) ) {
			return 7;
		}
		if ( preg_match( '/SELECT (phone|whatsapp|name|user_id) FROM wp_atora_contacts WHERE id = 10/', $sql ) ) {
			return '';
		}
		return 0;
	}

	public function get_row( $sql, $output = OBJECT ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, 'SELECT * FROM wp_atora_contacts WHERE id = 11' ) ) {
			return array( 'phone' => '+58 412 5550000', 'whatsapp' => '', 'name' => 'Old', 'user_id' => 0 );
		}
		return null;
	}

	public function query( $sql ): int { return 1; }

	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		$this->updated_tables[] = (string) $table;
		return 1;
	}

	public function delete( $table, $where, $where_format = null ): int { return 1; }

	public function insert( $table, $data, $format = null ): int { $this->insert_id++; return 1; }
}

final class ContactMergeMovesConversationsTest extends TestCase {
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
	public function merge_updates_conversations_table(): void {
		global $wpdb;
		$fake = new FakeWpdbMergeConversations();
		$wpdb = $fake;

		$result = \ATORA\LMS\Tenant_Context::with( 7, static fn() => Contact_Service::merge_contacts( 10, array( 11 ) ) );
		$this->assertTrue( (bool) ( $result['success'] ?? false ) );
		$this->assertContains( 'wp_atora_conversations', $fake->updated_tables );
	}
}

}

