<?php
/**
 * Inbound WhatsApp/Telegram (hooks) portados a Contacts Core.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class FakeWpdbInboundWhatsapp {
	public string $prefix   = 'wp_';
	public string $usermeta = 'wp_usermeta';

	/** @var array<int,string> */
	public array $inserted_tables = array();

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
		return null;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$sql = (string) $sql;
		if ( str_contains( $sql, "FROM {$this->usermeta}" ) && str_contains( $sql, "meta_key = 'atora_phone'" ) ) {
			return array(
				array( 'user_id' => 11, 'meta_value' => '+58 412 5550000' ),
				array( 'user_id' => 12, 'meta_value' => '+58 412 5550000' ),
			);
		}
		return array();
	}

	public function insert( $table, $data, $format = null ): int {
		$this->inserted_tables[] = (string) $table;
		return 1;
	}
}

class InboundWhatsAppResolutionTest extends TestCase {
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
	public function test_ambiguous_user_phone_skips_inbound_and_logs_issue(): void {
		global $wpdb;
		$fake = new FakeWpdbInboundWhatsapp();
		$wpdb = $fake;

		$svc = new \CLMS_Contacts_Core_Service();
		$svc->on_whatsapp_message_received(
			array(
				'from'      => '+58 412 5550000',
				'id'        => 'wamid.abc',
				'timestamp' => '1700000000',
				'type'      => 'text',
				'text'      => array( 'body' => 'hola' ),
			),
			array( 'contacts' => array( array( 'profile' => array( 'name' => 'Ana' ) ) ) )
		);

		$this->assertNotEmpty( $fake->inserted_tables, 'debe loguear al menos una incidencia en atora_message_log' );
		$this->assertContains( 'wp_atora_message_log', $fake->inserted_tables );
		$this->assertNotContains( 'wp_atora_conversation_messages', $fake->inserted_tables, 'con colisión de teléfono no debe insertar mensajes' );
	}
}

