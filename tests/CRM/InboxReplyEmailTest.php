<?php
/**
 * Inbox_REST_Controller::reply_email() — PT-2 (sprint 6.5.1).
 *
 * Regresión del hallazgo: con contact_id y conversation_id en 0, las
 * dos verificaciones de visibilidad se saltaban por completo y el
 * único control era is_email($to) — cualquier can_access podía
 * encolar correo institucional a cualquier dirección.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class InboxReplyEmailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_caps();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		atora_test_reset_user_caps();
		parent::tearDown();
	}

	/**
	 * $wpdb combinado: contactos y conversaciones fijos en memoria, más
	 * la tabla de cola de email siempre "existente" para que
	 * enqueue_inbox_reply() llegue al insert.
	 */
	private function install_wpdb_fixture( array $contacts = array(), array $conversations = array() ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $contacts, $conversations ) {
			public string $prefix    = 'wp_';
			public int    $insert_id = 1;
			private array $contacts;
			private array $conversations;

			public function __construct( array $contacts, array $conversations ) {
				$this->contacts      = $contacts;
				$this->conversations = $conversations;
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $match ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $match[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/'([^']+)'/", $sql, $m ) ) {
					return $m[1];
				}
				if ( false !== strpos( $sql, 'SELECT contact_id FROM' ) && false !== strpos( $sql, 'atora_conversations' ) && preg_match( '/id = (\d+)/', $sql, $m ) ) {
					$conv = $this->conversations[ (int) $m[1] ] ?? null;
					return $conv ? $conv['contact_id'] : null;
				}
				if ( false !== strpos( $sql, 'ct.email' ) && preg_match( '/c\.id = (\d+)/', $sql, $m ) ) {
					$conv = $this->conversations[ (int) $m[1] ] ?? null;
					if ( ! $conv ) { return null; }
					$contact = $this->contacts[ $conv['contact_id'] ] ?? null;
					return $contact ? $contact['email'] : null;
				}
				if ( false !== strpos( $sql, 'SELECT email FROM' ) && false !== strpos( $sql, 'atora_contacts' ) && preg_match( '/id = (\d+)/', $sql, $m ) ) {
					$contact = $this->contacts[ (int) $m[1] ] ?? null;
					return $contact ? $contact['email'] : null;
				}
				if ( false !== strpos( $sql, 'SELECT user_id FROM' ) && false !== strpos( $sql, 'atora_contacts' ) && preg_match( '/id = (\d+)/', $sql, $m ) ) {
					$contact = $this->contacts[ (int) $m[1] ] ?? null;
					return $contact ? $contact['user_id'] : null;
				}
				return null;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( $sql, 'contact_user_id' ) && preg_match( '/c\.id = (\d+)/', $sql, $m ) ) {
					$conv = $this->conversations[ (int) $m[1] ] ?? null;
					if ( ! $conv ) { return null; }
					$contact = $this->contacts[ $conv['contact_id'] ] ?? array();
					return array(
						'user_id'         => $conv['user_id'] ?? 0,
						'contact_id'      => $conv['contact_id'] ?? 0,
						'contact_user_id' => $contact['user_id'] ?? 0,
					);
				}
				return null;
			}

			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { $this->insert_id++; return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ): int { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	private function as_user_with_send_email( int $user_id ): void {
		$GLOBALS['__atora_test_current_user_id'] = $user_id;
		atora_test_set_user_cap( $user_id, 'clms_access_crm_view' );
		atora_test_set_user_cap( $user_id, 'crm_send_email' );
	}

	/** @test */
	public function test_can_send_email_false_without_crm_send_email_cap(): void {
		$this->as_user_with_send_email( 1 );
		atora_test_set_user_cap( 1, 'crm_send_email', false );
		$this->assertFalse( \ATORA\CRM_V2\Rest\Inbox_REST_Controller::can_send_email() );
	}

	/** @test */
	public function test_can_send_email_true_with_crm_send_email_cap(): void {
		$this->as_user_with_send_email( 1 );
		$this->assertTrue( \ATORA\CRM_V2\Rest\Inbox_REST_Controller::can_send_email() );
	}

	/** @test */
	public function test_reply_email_400_without_contact_or_conversation(): void {
		$this->as_user_with_send_email( 1 );
		$original = $this->install_wpdb_fixture();

		$request = new \WP_REST_Request( array( 'to' => 'atacante@fuera.test', 'message' => 'hola' ) );
		$response = \ATORA\CRM_V2\Rest\Inbox_REST_Controller::reply_email( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_reply_email_409_when_to_does_not_match_contact_email(): void {
		atora_test_set_user_cap( 1, 'manage_options' );
		$this->as_user_with_send_email( 1 );

		$original = $this->install_wpdb_fixture( array( 10 => array( 'user_id' => 0, 'email' => 'real@contacto.test' ) ) );

		$request = new \WP_REST_Request( array(
			'contact_id' => 10,
			'to'         => 'atacante@fuera.test',
			'message'    => 'hola',
		) );
		$response = \ATORA\CRM_V2\Rest\Inbox_REST_Controller::reply_email( $request );

		$this->assertSame( 409, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_reply_email_403_when_contact_not_visible(): void {
		$this->as_user_with_send_email( 2 ); // sin manage_options, sin alcance global

		$original = $this->install_wpdb_fixture( array( 10 => array( 'user_id' => 999, 'email' => 'real@contacto.test' ) ) );

		$request = new \WP_REST_Request( array( 'contact_id' => 10, 'message' => 'hola' ) );
		$response = \ATORA\CRM_V2\Rest\Inbox_REST_Controller::reply_email( $request );

		$this->assertSame( 403, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_reply_email_403_when_conversation_not_visible(): void {
		$this->as_user_with_send_email( 2 );

		$original = $this->install_wpdb_fixture(
			array( 10 => array( 'user_id' => 999, 'email' => 'real@contacto.test' ) ),
			array( 55 => array( 'contact_id' => 10, 'user_id' => 999 ) )
		);

		$request = new \WP_REST_Request( array( 'conversation_id' => 55, 'message' => 'hola' ) );
		$response = \ATORA\CRM_V2\Rest\Inbox_REST_Controller::reply_email( $request );

		$this->assertSame( 403, $response->get_status() );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_reply_email_succeeds_for_visible_contact_without_to_param(): void {
		atora_test_set_user_cap( 1, 'manage_options' );
		$this->as_user_with_send_email( 1 );

		$original = $this->install_wpdb_fixture( array( 10 => array( 'user_id' => 0, 'email' => 'real@contacto.test' ) ) );

		// El frontend legítimo ni siquiera necesita mandar `to` — se
		// resuelve del contacto.
		$request = new \WP_REST_Request( array( 'contact_id' => 10, 'message' => 'Hola, seguimos en contacto.' ) );
		$response = \ATORA\CRM_V2\Rest\Inbox_REST_Controller::reply_email( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_reply_email_succeeds_when_to_matches_contact_email(): void {
		atora_test_set_user_cap( 1, 'manage_options' );
		$this->as_user_with_send_email( 1 );

		$original = $this->install_wpdb_fixture( array( 10 => array( 'user_id' => 0, 'email' => 'real@contacto.test' ) ) );

		$request = new \WP_REST_Request( array(
			'contact_id' => 10,
			'to'         => 'REAL@contacto.test', // mayúsculas — debe comparar sin distinguir caso
			'message'    => 'Hola.',
		) );
		$response = \ATORA\CRM_V2\Rest\Inbox_REST_Controller::reply_email( $request );

		$this->assertSame( 200, $response->get_status() );

		$this->restore_wpdb( $original );
	}
}
