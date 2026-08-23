<?php
/**
 * Invalidación de verificación al cambiar el teléfono — PT-4 (sprint 6.5.1).
 *
 * Hallazgo confirmado: invalidate_phone_verification() existía pero su
 * único llamador en todo el repositorio era el propio test que la
 * ejercita. trait-frontend-access-profile.php y trait-crm-contacts.php
 * escribían atora_phone directo, sin invalidar la verificación previa.
 * Al buscar "atora_phone" en todo el árbol (regla 4.2, "no solo los
 * dos ya identificados") apareció un tercer punto:
 * trait-admin-menu-main-pages-academic.php (edición admin del perfil
 * académico del estudiante).
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

class PhoneVerificationInvalidationTest extends TestCase {

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

	private function verify( int $user_id, string $phone ): void {
		update_user_meta( $user_id, 'atora_phone', $phone );
		update_user_meta( $user_id, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );
		update_user_meta(
			$user_id,
			\ATORA\Messaging\Preferences::META_PHONE_VERIFIED_HASH,
			wp_hash( preg_replace( '/\D+/', '', $phone ) )
		);
	}

	/** @test */
	public function test_update_phone_invalidates_when_number_actually_changes(): void {
		$this->verify( 1, '+58 412 1234567' );
		$this->assertTrue( \ATORA\Messaging\Preferences::is_phone_verified( 1 ) );

		\ATORA\Messaging\Preferences::update_phone( 1, '+58 424 9876543' );

		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 1 ) );
		$this->assertSame( '+58 424 9876543', get_user_meta( 1, 'atora_phone', true ) );
	}

	/** @test */
	public function test_update_phone_does_not_invalidate_on_no_real_change(): void {
		$this->verify( 2, '+58 412 1234567' );

		// Mismo número, distinto formato de espacios — no debería
		// invalidar (comparación normalizada, solo dígitos).
		\ATORA\Messaging\Preferences::update_phone( 2, '+58-412-1234567' );

		$this->assertTrue( \ATORA\Messaging\Preferences::is_phone_verified( 2 ) );
	}

	/** @test */
	public function test_verify_phone_code_stores_hash_tied_to_current_number(): void {
		update_user_meta( 3, 'atora_phone', '+58 412 1234567' );
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 3, '123456' );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( \ATORA\Messaging\Preferences::is_phone_verified( 3 ) );

		// Cambia el número sin pasar por update_phone() (simulando que
		// algo más lo tocó) — is_phone_verified() debe detectarlo por la
		// huella, no solo por el booleano.
		update_user_meta( 3, 'atora_phone', '+58 424 0000000' );
		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 3 ) );
	}

	/** @test */
	public function test_legacy_verified_record_without_hash_is_no_longer_treated_as_verified(): void {
		// PT-3.1/3.2 (6.5.2): la rama de compatibilidad de PT-4.1
		// (6.5.1) se retiró — confirmado que ninguna instalación real
		// operó bajo el esquema anterior a 6.5.1, así que no había
		// nadie a quien "gradfatherear". verified=1 sin huella ya no
		// basta.
		update_user_meta( 4, 'atora_phone', '+58 412 1234567' );
		update_user_meta( 4, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );

		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 4 ) );
	}

	/** @test */
	public function test_can_receive_whatsapp_reflects_invalidation_after_phone_change(): void {
		$this->verify( 5, '+58 412 1234567' );
		update_user_meta( 5, 'atora_consent_whatsapp', 1 );
		$this->assertTrue( \ATORA\Messaging\Preferences::can_receive_whatsapp( 5 ) );

		\ATORA\Messaging\Preferences::update_phone( 5, '+58 424 9999999' );

		$this->assertFalse( \ATORA\Messaging\Preferences::can_receive_whatsapp( 5 ) );
	}

	/**
	 * Tripwire de regresión: los tres puntos que antes escribían
	 * atora_phone directo deben seguir delegando en
	 * Preferences::update_phone(), no volver a un update_user_meta()
	 * suelto. No es una prueba de comportamiento (esas ya están arriba,
	 * sobre update_phone() en sí) — es una guarda barata contra que
	 * alguien revierta la delegación sin darse cuenta.
	 *
	 * @test
	 * @dataProvider phone_write_sites
	 */
	public function test_phone_write_site_delegates_to_update_phone( string $relative_path ): void {
		$contents = file_get_contents( __DIR__ . '/../../' . $relative_path );
		$this->assertIsString( $contents );
		$this->assertStringContainsString(
			'Preferences::update_phone(',
			$contents,
			"{$relative_path} debería escribir atora_phone vía Preferences::update_phone()"
		);
	}

	public static function phone_write_sites(): array {
		return array(
			array( 'includes/frontend/trait-frontend-access-profile.php' ),
			array( 'modules/crm/trait-crm-contacts.php' ),
			array( 'includes/admin-menu/trait-admin-menu-main-pages-academic.php' ),
		);
	}
}
