<?php
/**
 * Retiro de la rama de compatibilidad de verificación de teléfono — PT-3 (sprint 6.5.2).
 *
 * Condición de bloqueo de la OT resuelta: confirmado que ninguna
 * instalación real de ATORA LMS operó bajo el esquema de verificación
 * anterior a 6.5.1 (sin atora_phone_verified_hash). Por eso el
 * paquete se redujo a retirar la rama de compatibilidad directamente
 * (equivalente en efecto a PT-3.1 — verified=1 con hash vacío ya no
 * cuenta como verificado — pero sin necesidad de programar un ciclo
 * de retiro, porque no protegía a ningún usuario real).
 *
 * PT-5 (6.5.9): verify_phone_code() ahora pasa por ATORA_Rate_Limiter
 * — necesita un $wpdb en memoria que respalde
 * atora_rate_limit_counters, aunque este test no verifique el límite
 * en sí.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class PhoneVerificationCompatRetiredTest extends TestCase {

	private ?object $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_meta();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new class {
			public string $prefix   = 'wp_';
			public array  $counters = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
					return $this->prefix . 'atora_rate_limit_counters';
				}
				if ( false !== strpos( $sql, 'SELECT attempts FROM' )
					&& preg_match( "/scope = '([^']*)' AND identifier_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
					$key = $m[1] . '|' . $m[2] . '|' . $m[3];
					return $this->counters[ $key ] ?? null;
				}
				return null;
			}

			public function query( $sql ) {
				if ( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' )
					&& preg_match( "/VALUES \('([^']*)', '([^']*)', (\d+), 1\)/", $sql, $m ) ) {
					$key = $m[1] . '|' . $m[2] . '|' . $m[3];
					$this->counters[ $key ] = ( $this->counters[ $key ] ?? 0 ) + 1;
					return 1;
				}
				if ( false !== strpos( $sql, 'DELETE FROM' )
					&& preg_match( "/scope = '([^']*)' AND identifier_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
					$key = $m[1] . '|' . $m[2] . '|' . $m[3];
					unset( $this->counters[ $key ] );
					return 1;
				}
				return 1;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};
	}

	protected function tearDown(): void {
		atora_test_reset_user_meta();
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function test_verified_flag_with_empty_hash_is_treated_as_unverified(): void {
		update_user_meta( 1, 'atora_phone', '+58 412 1234567' );
		update_user_meta( 1, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );
		// META_PHONE_VERIFIED_HASH deliberadamente vacío/ausente.

		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 1 ) );
	}

	/** @test */
	public function test_can_receive_whatsapp_false_with_empty_hash_even_with_consent(): void {
		update_user_meta( 2, 'atora_phone', '+58 412 1234567' );
		update_user_meta( 2, 'atora_consent_whatsapp', 1 );
		update_user_meta( 2, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );

		$this->assertFalse( \ATORA\Messaging\Preferences::can_receive_whatsapp( 2 ) );
	}

	/** @test */
	public function test_matching_hash_still_verifies_normally(): void {
		$phone = '+58 412 1234567';
		update_user_meta( 3, 'atora_phone', $phone );
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED_HASH, wp_hash( '+584121234567' ) );

		$this->assertTrue( \ATORA\Messaging\Preferences::is_phone_verified( 3 ) );
	}

	/** @test */
	public function test_new_verification_flow_never_produces_empty_hash(): void {
		// Un usuario nuevo, post-6.5.2, que pasa por el flujo normal
		// (verify_phone_code()) siempre termina con una huella —
		// confirma que la rama retirada ya no es alcanzable por el
		// camino normal, solo por datos manipulados/heredados a mano.
		update_user_meta( 4, 'atora_phone', '+58 412 1234567' );
		update_user_meta( 4, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );
		update_user_meta( 4, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );

		\ATORA\Messaging\Preferences::verify_phone_code( 4, '123456' );

		$hash = get_user_meta( 4, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED_HASH, true );
		$this->assertNotSame( '', $hash );
		$this->assertTrue( \ATORA\Messaging\Preferences::is_phone_verified( 4 ) );
	}
}
