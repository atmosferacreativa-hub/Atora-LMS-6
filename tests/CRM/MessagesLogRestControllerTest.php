<?php
/**
 * Messages_Log_REST_Controller (CRM v2) — port de /crm/messages/log legacy.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class FakeWpdbMessagesLog {
	public string $prefix   = 'wp_';
	public string $usermeta = 'wp_usermeta';

	/** @var array<int,string> */
	public array $executed = array();

	public int $next_count = 0;
	/** @var array<int,array<string,mixed>> */
	public array $next_items = array();
	/** @var array<int,array<string,mixed>> */
	public array $next_channel_rows = array();
	/** @var array<int,array<string,mixed>> */
	public array $next_status_rows = array();
	/** @var array<int,array<string,mixed>> */
	public array $next_event_rows = array();

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
		$this->executed[] = (string) $sql;
		$sql              = (string) $sql;
		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			if ( preg_match( "/LIKE\s+([^\s']+)/", $sql, $m ) ) {
				return (string) $m[1];
			}
			return '';
		}
		if ( str_contains( $sql, 'COUNT(*)' ) ) {
			return $this->next_count;
		}
		return null;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$this->executed[] = (string) $sql;
		$sql              = (string) $sql;

		if ( str_contains( $sql, 'GROUP BY mq.channel' ) ) {
			return $this->next_channel_rows;
		}
		if ( str_contains( $sql, 'GROUP BY mq.status' ) ) {
			return $this->next_status_rows;
		}
		if ( str_contains( $sql, 'GROUP BY ml.event_type' ) ) {
			return $this->next_event_rows;
		}
		if ( str_contains( $sql, 'SELECT ml.id' ) ) {
			return $this->next_items;
		}

		return array();
	}
}

class MessagesLogRestControllerTest extends TestCase {
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
		atora_test_reset_user_caps();
		$GLOBALS['__atora_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		atora_test_reset_user_caps();
		parent::tearDown();
	}

	private function swap_wpdb(): FakeWpdbMessagesLog {
		global $wpdb;
		$fake = new FakeWpdbMessagesLog();
		$wpdb = $fake;
		return $fake;
	}

	/** @test */
	public function test_list_log_filters_by_channel_and_user_id(): void {
		atora_test_set_user_cap( 1, 'clms_access_crm_view' );
		$fake = $this->swap_wpdb();
		$fake->next_count = 2;
		$fake->next_items = array(
			array(
				'id'              => 10,
				'queue_id'         => 11,
				'event_type'       => 'sent',
				'event_data'       => '{}',
				'created_at'       => '2026-01-01 00:00:00',
				'user_id'          => 123,
				'channel'          => 'whatsapp',
				'template_key'     => 't1',
				'status'           => 'sent',
				'recipient_name'   => 'A',
				'recipient_phone'  => '+1',
			),
		);

		$r = new \WP_REST_Request(
			array(
				'channel'  => 'whatsapp',
				'user_id'  => 123,
				'per_page' => 50,
				'page'     => 1,
			)
		);
		$resp = \ATORA\CRM_V2\Rest\Messages_Log_REST_Controller::list_log( $r );
		$data = $resp->get_data();

		$this->assertTrue( (bool) ( $data['success'] ?? false ) );
		$this->assertSame( 2, (int) ( $data['total'] ?? 0 ) );
		$this->assertSame( 123, (int) ( $data['items'][0]['user_id'] ?? 0 ) );

		$sql = implode( "\n", $fake->executed );
		$this->assertStringContainsString( 'mq.channel = whatsapp', $sql );
		$this->assertStringContainsString( 'mq.user_id IN (123)', $sql );
	}

	/** @test */
	public function test_invalid_channel_is_ignored_in_where_clause(): void {
		atora_test_set_user_cap( 1, 'clms_access_crm_view' );
		$fake = $this->swap_wpdb();
		$fake->next_count = 0;
		$fake->next_items = array();

		$r = new \WP_REST_Request( array( 'channel' => 'not-a-channel' ) );
		\ATORA\CRM_V2\Rest\Messages_Log_REST_Controller::list_log( $r );

		$sql = implode( "\n", $fake->executed );
		$this->assertStringNotContainsString( 'mq.channel = not-a-channel', $sql );
		$this->assertStringNotContainsString( 'AND mq.channel =', $sql, 'channel inválido no debe agregar filtro' );
	}

	/** @test */
	public function test_summary_formats_bucket_maps(): void {
		atora_test_set_user_cap( 1, 'clms_access_crm_view' );
		$fake                  = $this->swap_wpdb();
		$fake->next_count       = 3;
		$fake->next_channel_rows = array(
			array( 'bucket' => 'whatsapp', 'total' => 2 ),
			array( 'bucket' => 'email', 'total' => 1 ),
		);
		$fake->next_status_rows = array(
			array( 'bucket' => 'sent', 'total' => 3 ),
		);
		$fake->next_event_rows = array(
			array( 'bucket' => 'delivered', 'total' => 1 ),
		);

		$r    = new \WP_REST_Request();
		$resp = \ATORA\CRM_V2\Rest\Messages_Log_REST_Controller::get_summary( $r );
		$data = $resp->get_data();

		$this->assertTrue( (bool) ( $data['success'] ?? false ) );
		$this->assertSame( 3, (int) ( $data['total'] ?? 0 ) );
		$this->assertSame( 2, (int) ( $data['by_channel']['whatsapp'] ?? 0 ) );
		$this->assertSame( 3, (int) ( $data['by_status']['sent'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $data['by_event_type']['delivered'] ?? 0 ) );
	}
}

