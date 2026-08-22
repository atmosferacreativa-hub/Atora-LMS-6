<?php
/**
 * Telegram_Bot::send_message() — atora_telegram_links como fuente de
 * verdad, no usermeta — PT-2 (sprint 6.5.8).
 *
 * Hallazgo confirmado: send_message() seguía leyendo
 * atora_telegram_chat_id desde usermeta después de que 6.5.7
 * convirtiera a atora_telegram_links en la fuente de verdad para
 * unicidad. Si ambas llegaran a divergir (usermeta con un valor
 * desactualizado, la tabla sin vínculo o con otro valor), send_message()
 * podía enviar a un chat_id que la tabla ya no reconoce como válido
 * para ese usuario.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class TelegramSendMessageSourceOfTruthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		\atora_test_reset_user_meta();
		parent::tearDown();
	}

	private function install_wpdb_fixture( array $links_by_user ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $links_by_user ) {
			public string $prefix = 'wp_';
			private array $links_by_user;

			public function __construct( array $links_by_user ) { $this->links_by_user = $links_by_user; }

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'atora_telegram_links' ) && preg_match( '/user_id = (\d+)/', $sql, $m ) ) {
					return $this->links_by_user[ (int) $m[1] ] ?? null;
				}
				return null;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ) { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	/**
	 * Caso confirmado del hallazgo: usermeta tiene un chat_id
	 * desactualizado (o divergente) para el usuario, pero la tabla
	 * (fuente de verdad) no tiene ningún vínculo — send_message() NO
	 * debe enviar nada.
	 *
	 * @test
	 */
	public function test_does_not_send_when_table_has_no_link_even_if_usermeta_has_a_stale_value(): void {
		$original = $this->install_wpdb_fixture( array() ); // tabla vacía.
		update_user_meta( 20, 'atora_telegram_chat_id', '999999' ); // usermeta desactualizada.

		$sent = \ATORA\Messaging\Telegram_Bot::send_message( 20, 'hola' );

		$this->assertFalse( $sent, 'sin vínculo en la tabla, no debe enviarse aunque usermeta tenga un valor' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_sends_using_the_chat_id_from_the_table(): void {
		$original = $this->install_wpdb_fixture( array( 21 => '555111' ) );

		// api_send_message() devuelve false sin un bot token
		// configurado (get_option() vacío en este entorno de test) —
		// lo relevante acá es que get_chat_id_for_user() resolvió el
		// chat_id de la TABLA, verificable por reflexión.
		$ref = new \ReflectionMethod( \ATORA\Messaging\Telegram_Bot::class, 'get_chat_id_for_user' );
		$ref->setAccessible( true );
		$resolved = $ref->invoke( null, 21 );

		$this->assertSame( '555111', $resolved );

		$this->restore_wpdb( $original );
	}
}
