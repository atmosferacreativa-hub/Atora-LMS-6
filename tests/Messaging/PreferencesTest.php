<?php
/**
 * ATORA\Messaging\Preferences — PT-4 (sprint 6.4.0).
 *
 * PT-5 (6.5.9): verify_phone_code()/request_phone_verification() ahora
 * pasan por ATORA_Rate_Limiter (antes usermeta) — cada test necesita
 * un $wpdb en memoria que respalde atora_rate_limit_counters, aunque
 * el test en sí no verifique el límite explícitamente.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class PreferencesTest extends TestCase {

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
	public function test_defaults_have_all_three_categories_enabled(): void {
		$prefs = \ATORA\Messaging\Preferences::get( 1 );
		$this->assertTrue( $prefs['categories']['academico'] );
		$this->assertTrue( $prefs['categories']['recordatorios'] );
		$this->assertTrue( $prefs['categories']['institucional'] );
	}

	/** @test */
	public function test_defaults_are_instant_frequency_no_dnd(): void {
		$prefs = \ATORA\Messaging\Preferences::get( 1 );
		$this->assertSame( 'instant', $prefs['frequency']['academico'] );
		$this->assertSame( '', $prefs['dnd_start'] );
		$this->assertSame( '', $prefs['dnd_end'] );
	}

	/** @test */
	public function test_save_and_get_roundtrip(): void {
		\ATORA\Messaging\Preferences::save( 5, array(
			'categories' => array( 'academico' => true, 'recordatorios' => false, 'institucional' => true ),
			'frequency'  => array( 'academico' => 'digest' ),
			'dnd_start'  => '22:00',
			'dnd_end'    => '07:00',
		) );

		$prefs = \ATORA\Messaging\Preferences::get( 5 );
		$this->assertFalse( $prefs['categories']['recordatorios'] );
		$this->assertTrue( $prefs['categories']['institucional'] );
		$this->assertSame( 'digest', $prefs['frequency']['academico'] );
		$this->assertSame( 'instant', $prefs['frequency']['recordatorios'], 'no tocado, debe seguir en default' );
		$this->assertSame( '22:00', $prefs['dnd_start'] );
		$this->assertSame( '07:00', $prefs['dnd_end'] );
	}

	/** @test */
	public function test_invalid_dnd_time_is_rejected(): void {
		\ATORA\Messaging\Preferences::save( 5, array( 'dnd_start' => 'no-es-una-hora' ) );
		$prefs = \ATORA\Messaging\Preferences::get( 5 );
		$this->assertSame( '', $prefs['dnd_start'] );
	}

	/** @test */
	public function test_unsubscribe_all_disables_all_categories_only(): void {
		\ATORA\Messaging\Preferences::save( 7, array( 'frequency' => array( 'academico' => 'digest' ) ) );
		\ATORA\Messaging\Preferences::unsubscribe_all( 7 );

		$prefs = \ATORA\Messaging\Preferences::get( 7 );
		foreach ( $prefs['categories'] as $enabled ) {
			$this->assertFalse( $enabled );
		}
		// La frecuencia guardada antes no se pierde ni se toca.
		$this->assertSame( 'digest', $prefs['frequency']['academico'] );
	}

	/** @test */
	public function test_whatsapp_inactive_without_verification(): void {
		update_user_meta( 3, 'atora_consent_whatsapp', true );
		update_user_meta( 3, 'atora_phone', '+584121234567' );
		// Sin atora_phone_verified.
		$this->assertFalse( \ATORA\Messaging\Preferences::is_whatsapp_active( 3 ) );
	}

	/** @test */
	public function test_whatsapp_active_only_with_consent_phone_and_verification(): void {
		update_user_meta( 3, 'atora_consent_whatsapp', true );
		update_user_meta( 3, 'atora_phone', '+584121234567' );
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );

		$this->assertTrue( \ATORA\Messaging\Preferences::is_whatsapp_active( 3 ) );
	}

	/** @test */
	public function test_verify_phone_code_wrong_code_fails(): void {
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 9, '000000' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'codigo_incorrecto', $result['reason'] );
		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 9 ) );
	}

	/** @test */
	public function test_verify_phone_code_correct_code_succeeds(): void {
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 9, '123456' );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( \ATORA\Messaging\Preferences::is_phone_verified( 9 ) );
	}

	/** @test */
	public function test_verify_phone_code_expired_fails(): void {
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() - 1 );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 9, '123456' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'codigo_expirado', $result['reason'] );
	}

	/** @test */
	public function test_request_verification_without_phone_fails(): void {
		$result = \ATORA\Messaging\Preferences::request_phone_verification( 11 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'sin_telefono', $result['reason'] );
	}

	/** @test */
	public function test_request_verification_rate_limited_after_3_attempts(): void {
		update_user_meta( 11, 'atora_phone', '+584121234567' );

		// Sin la clase WhatsApp cargada en el bootstrap de test, cada
		// solicitud falla en el envío — pero el intento ya se registró
		// antes de llegar ahí, que es lo que se está probando aquí.
		\ATORA\Messaging\Preferences::request_phone_verification( 11 );
		\ATORA\Messaging\Preferences::request_phone_verification( 11 );
		\ATORA\Messaging\Preferences::request_phone_verification( 11 );
		$fourth = \ATORA\Messaging\Preferences::request_phone_verification( 11 );

		$this->assertFalse( $fourth['ok'] );
		$this->assertSame( 'limite_intentos', $fourth['reason'] );
	}

	/** @test */
	public function test_invalidate_phone_verification_clears_verified_flag(): void {
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );
		\ATORA\Messaging\Preferences::invalidate_phone_verification( 3 );
		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 3 ) );
	}

	/** @test */
	public function test_unsubscribe_token_roundtrip(): void {
		$token  = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 42, 'academico' );
		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( $token );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 42, $result['user_id'] );
		$this->assertSame( 'academico', $result['category'] );
	}

	/** @test */
	public function test_unsubscribe_token_tampered_fails(): void {
		$token = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 42, 'academico' );
		$tampered = substr( $token, 0, -2 ) . 'xx';

		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( $tampered );
		$this->assertFalse( $result['ok'] );
	}

	/** @test */
	public function test_unsubscribe_token_expired_fails(): void {
		$token  = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 42, 'all', 0 );
		// ttl_days=0 se normaliza a 1 día mínimo internamente, así que
		// generamos uno ya vencido manipulando el reloj no es viable sin
		// mocks — en su lugar, verificamos que un token con expiración
		// pasada explícita (construido a mano con la misma firma) falla.
		$expires = time() - 10;
		$payload = '42|all|' . $expires;
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		$expired_token = rtrim( strtr( base64_encode( $payload . '|' . $sig ), '+/', '-_' ), '=' );

		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( $expired_token );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'token_expirado', $result['reason'] );
	}

	/** @test */
	public function test_unsubscribe_token_garbage_input_fails_cleanly(): void {
		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( 'no-es-un-token-valido!!!' );
		$this->assertFalse( $result['ok'] );
	}
}
