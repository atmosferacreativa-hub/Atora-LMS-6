<?php
/**
 * Flags manuales (mark_email_opened/mark_message_read) portados a Contacts Core.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class FakeWpdbManualFlags {
	public string $prefix = 'wp_';
	public string $usermeta = 'wp_usermeta';

	/** @var array<string,mixed>|null */
	public ?array $last_update_data = null;
	public string $last_update_table = '';

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
		}
		return null;
	}

	public function get_row( $sql, $output = OBJECT ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, 'FROM wp_atora_email_queue' ) ) {
			return array( 'id' => 5, 'opened_at' => '2026-01-01 00:00:00' );
		}
		if ( str_contains( $sql, 'FROM wp_atora_message_queue' ) ) {
			return array( 'id' => 7, 'read_at' => '2026-01-02 00:00:00' );
		}
		return null;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		$this->last_update_table = (string) $table;
		$this->last_update_data  = is_array( $data ) ? $data : null;
		return 1;
	}
}

class ManualFlagsTest extends TestCase {
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
	public function test_mark_email_opened_preserves_existing_opened_at(): void {
		global $wpdb;
		$fake = new FakeWpdbManualFlags();
		$wpdb = $fake;

		$svc = new \CLMS_Contacts_Core_Service();
		$this->assertTrue( $svc->mark_email_opened( 5 ) );
		$this->assertSame( 'wp_atora_email_queue', $fake->last_update_table );
		$this->assertSame( '2026-01-01 00:00:00', (string) ( $fake->last_update_data['opened_at'] ?? '' ) );
	}

	/** @test */
	public function test_mark_message_read_preserves_read_at_and_sets_status(): void {
		global $wpdb;
		$fake = new FakeWpdbManualFlags();
		$wpdb = $fake;

		$svc = new \CLMS_Contacts_Core_Service();
		$this->assertTrue( $svc->mark_message_read( 7 ) );
		$this->assertSame( 'wp_atora_message_queue', $fake->last_update_table );
		$this->assertSame( 'read', (string) ( $fake->last_update_data['status'] ?? '' ) );
		$this->assertSame( '2026-01-02 00:00:00', (string) ( $fake->last_update_data['read_at'] ?? '' ) );
	}
}

