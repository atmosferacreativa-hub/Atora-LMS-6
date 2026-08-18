<?php
/**
 * Messaging_Router::under_recipient_cap() — PT-3.7 (sprint 6.4.0).
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class FakeWpdbCap {
	public string $prefix = 'wp_';
	public $next_count = 0;
	public array $executed = array();

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}
	public function get_var( $sql ) {
		$this->executed[] = $sql;
		return $this->next_count;
	}
}

class RecipientCapTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
		atora_test_reset_options();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		atora_test_reset_options();
		parent::tearDown();
	}

	private function swap_wpdb(): FakeWpdbCap {
		global $wpdb;
		$fake = new FakeWpdbCap();
		$wpdb = $fake;
		return $fake;
	}

	/** @test */
	public function test_default_cap_is_5(): void {
		$fake = $this->swap_wpdb();
		$fake->next_count = 4;
		$this->assertTrue( \ATORA\Messaging\Messaging_Router::under_recipient_cap( 1 ) );

		$fake->next_count = 5;
		$this->assertFalse( \ATORA\Messaging\Messaging_Router::under_recipient_cap( 1 ) );
	}

	/** @test */
	public function test_zero_option_means_no_cap(): void {
		update_option( \ATORA\Messaging\Messaging_Router::OPT_RECIPIENT_CAP_24H, 0 );
		$fake = $this->swap_wpdb();
		$fake->next_count = 9999; // no debería ni consultarse

		$this->assertTrue( \ATORA\Messaging\Messaging_Router::under_recipient_cap( 1 ) );
		$this->assertSame( array(), $fake->executed, 'con tope 0 no debe ni consultar la BD' );
	}

	/** @test */
	public function test_custom_cap_respected(): void {
		update_option( \ATORA\Messaging\Messaging_Router::OPT_RECIPIENT_CAP_24H, 2 );
		$fake = $this->swap_wpdb();

		$fake->next_count = 1;
		$this->assertTrue( \ATORA\Messaging\Messaging_Router::under_recipient_cap( 1 ) );

		$fake->next_count = 2;
		$this->assertFalse( \ATORA\Messaging\Messaging_Router::under_recipient_cap( 1 ) );
	}

	/** @test */
	public function test_query_windows_by_24h_default(): void {
		$fake = $this->swap_wpdb();
		$fake->next_count = 0;
		\ATORA\Messaging\Messaging_Router::under_recipient_cap( 1 );

		$this->assertNotEmpty( $fake->executed );
		$this->assertStringContainsString( 'atora_message_queue', $fake->executed[0] );
		$this->assertStringContainsString( 'scheduled_at', $fake->executed[0] );
	}
}
