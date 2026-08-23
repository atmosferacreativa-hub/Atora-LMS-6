<?php
/**
 * CRM (v1 y v2) — resolución de Telegram vía atora_telegram_links, no
 * usermeta — PT-3 (sprint 6.5.9, MEDIUM).
 *
 * Hallazgo confirmado: tres puntos del CRM seguían leyendo
 * atora_telegram_chat_id de usermeta directamente en vez de consultar
 * atora_telegram_links (fuente de verdad desde 6.5.7):
 * CRM_Events_Messaging_Trait::find_user_id_by_telegram_chat() (SQL
 * directo contra wp_usermeta), CRM_V2_Pipeline_Trait::contact_can_receive_telegram()
 * y CRM_V2::contact_can_receive_telegram() (get_user_meta()). Durante
 * un conflicto de chat_id que la tabla ya resuelve correctamente (a
 * nadie, por su UNIQUE KEY), usermeta podía seguir teniendo la
 * asignación ambigua para ambos usuarios, así que el CRM podía
 * atribuir un mensaje entrante al usuario equivocado.
 *
 * Fix: los tres puntos delegan ahora en
 * Telegram_Bot::get_user_by_chat()/get_chat_id_for_user() (elevados a
 * público en este mismo sprint), que consultan exclusivamente
 * atora_telegram_links.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

/**
 * Doble de prueba: compone solo el trait de mensajería/eventos del CRM
 * v1, evitando el resto de dependencias de CRM (contactos, REST,
 * admin-sync) no relevantes para esta lógica.
 */
class Test_Crm_Events_Messaging_Host {
	use \ATORA\CRM\CRM_Events_Messaging_Trait;

	public static function find_user_id_by_telegram_chat_public( string $chat_id ): int {
		return self::find_user_id_by_telegram_chat( $chat_id );
	}
}

class TelegramCrmSourceOfTruthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		\atora_test_reset_user_meta();
		parent::tearDown();
	}

	/**
	 * @param array<int,array{user_id:int,chat_id:string}> $links Filas de atora_telegram_links.
	 */
	private function install_wpdb_fixture( array $links ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $links ) {
			public string $prefix = 'wp_';
			private array $links;

			public function __construct( array $links ) { $this->links = $links; }

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false === strpos( $sql, 'atora_telegram_links' ) ) {
					return null;
				}
				if ( preg_match( "/chat_id = '([^']*)'/", $sql, $m ) ) {
					foreach ( $this->links as $row ) {
						if ( $row['chat_id'] === $m[1] ) {
							return $row['user_id'];
						}
					}
					return null;
				}
				if ( preg_match( '/user_id = (\d+)/', $sql, $m ) ) {
					foreach ( $this->links as $row ) {
						if ( $row['user_id'] === (int) $m[1] ) {
							return $row['chat_id'];
						}
					}
					return null;
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
	 * Escenario del conflicto ya detectado correctamente por la
	 * migración de 6.5.8: dos usuarios reclamaron el mismo chat_id en
	 * usermeta (histórico), pero atora_telegram_links (fuente de
	 * verdad) no le asignó el chat a NINGUNO de los dos, por su
	 * UNIQUE KEY. Un mensaje entrante de ese chat no debe atribuirse a
	 * ninguno de los dos usuarios en conflicto.
	 *
	 * @test
	 */
	public function test_conflicting_chat_id_is_attributed_to_neither_user(): void {
		// usermeta todavía tiene la asignación ambigua histórica.
		update_user_meta( 501, 'atora_telegram_chat_id', '777000' );
		update_user_meta( 502, 'atora_telegram_chat_id', '777000' );

		// La tabla, fuente de verdad, no tiene entrada para ese chat —
		// la migración de 6.5.8 correctamente no asignó un ganador
		// arbitrario.
		$original = $this->install_wpdb_fixture( array() );

		$resolved = Test_Crm_Events_Messaging_Host::find_user_id_by_telegram_chat_public( '777000' );

		$this->assertSame( 0, $resolved, 'un chat_id en conflicto no debe atribuirse a ninguno de los usuarios en disputa' );

		$this->restore_wpdb( $original );
	}

	/**
	 * Caso sin conflicto: chat_id correctamente presente en
	 * atora_telegram_links — el CRM debe resolverlo exactamente igual
	 * que antes (sin regresión funcional).
	 *
	 * @test
	 */
	public function test_unambiguous_chat_id_resolves_normally(): void {
		update_user_meta( 601, 'atora_telegram_chat_id', '777111' );

		$original = $this->install_wpdb_fixture( array(
			array( 'user_id' => 601, 'chat_id' => '777111' ),
		) );

		$resolved = Test_Crm_Events_Messaging_Host::find_user_id_by_telegram_chat_public( '777111' );

		$this->assertSame( 601, $resolved );

		$this->restore_wpdb( $original );
	}

	/**
	 * CRM_V2::contact_can_receive_telegram() — mismo hallazgo, mismo
	 * fix, dirección inversa (user_id -> chat_id). Debe reflejar la
	 * tabla, no usermeta.
	 *
	 * @test
	 */
	public function test_crm_v2_telegram_eligibility_follows_the_table_not_usermeta(): void {
		update_user_meta( 701, 'atora_telegram_chat_id', '777222' ); // usermeta desactualizada.
		update_user_meta( 701, 'atora_consent_telegram', true );

		// La tabla no tiene vínculo para este usuario (p.ej. se
		// desvinculó, o quedó fuera por un conflicto) — la elegibilidad
		// debe seguir a la tabla, no al meta obsoleto.
		$original = $this->install_wpdb_fixture( array() );

		$ref = new \ReflectionMethod( \ATORA\CRM_V2\CRM_V2::class, 'contact_can_receive_telegram' );
		$ref->setAccessible( true );
		$eligible = $ref->invoke( null, array( 'user_id' => 701 ) );

		$this->assertFalse( $eligible, 'sin vínculo en la tabla, el contacto no debe considerarse elegible para Telegram aunque usermeta tenga un valor' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_crm_v2_telegram_eligibility_true_when_table_has_link_and_consent(): void {
		update_user_meta( 702, 'atora_consent_telegram', true );

		$original = $this->install_wpdb_fixture( array(
			array( 'user_id' => 702, 'chat_id' => '777333' ),
		) );

		$ref = new \ReflectionMethod( \ATORA\CRM_V2\CRM_V2::class, 'contact_can_receive_telegram' );
		$ref->setAccessible( true );
		$eligible = $ref->invoke( null, array( 'user_id' => 702 ) );

		$this->assertTrue( $eligible );

		$this->restore_wpdb( $original );
	}
}
