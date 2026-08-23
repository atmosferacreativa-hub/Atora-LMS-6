<?php
/**
 * ATORA\Security\Two_FA_Manager::verify_token()/verify_backup_code() —
 * PT-1 (sprint 6.5.9, HIGH).
 *
 * Hallazgo confirmado: handle_2fa_form() (el formulario POST que el
 * flujo de login realmente usa) no aplicaba ningún límite de
 * intentos antes de llamar a verify_token()/verify_backup_code() —
 * solo el AJAX equivalente (ajax_verify()) tenía un límite, y era por
 * IP, no por usuario. Un atacante que ya tiene la contraseña podía
 * automatizar el segundo factor (6 dígitos, 1.000.000 de
 * combinaciones) contra el formulario sin tocar el AJAX protegido.
 *
 * El fix agrega el gate dentro de verify_token()/verify_backup_code()
 * mismos — el único punto de convergencia real entre
 * handle_2fa_form() y ajax_verify() (ambos, y solo ellos, llaman a
 * estos métodos) — así que un solo cupo por pending_user_id se
 * comparte automáticamente entre los dos endpoints y entre código
 * normal/de respaldo, sin importar cuál se invoque primero.
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TwoFaRateLimitTest extends TestCase {

	/**
	 * $wpdb en memoria: una fila de token 2FA válida más la misma
	 * simulación de atora_rate_limit_counters usada por el resto de
	 * los limiters de este proyecto (peek/consume/reset comparten esa
	 * tabla).
	 */
	private function install_wpdb_fixture( int $user_id, string $valid_code ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $user_id, $valid_code ) {
			public string $prefix   = 'wp_';
			public array  $counters = array(); // "scope|identifier_hash|window_start" => attempts
			private int    $user_id;
			private string $valid_code;
			public bool    $token_verified = false;

			public function __construct( int $user_id, string $valid_code ) {
				$this->user_id    = $user_id;
				$this->valid_code = $valid_code;
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( $sql, 'atora_2fa_tokens' )
					&& false !== strpos( $sql, "'" . $this->valid_code . "'" )
					&& ! $this->token_verified ) {
					return (object) array( 'id' => 1 );
				}
				return null;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				if ( isset( $data['verified'] ) ) {
					$this->token_verified = true;
				}
				return 1;
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

			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	private function call_verify_backup_code( int $user_id, string $code ): bool {
		$m = new ReflectionMethod( '\ATORA\Security\Two_FA_Manager', 'verify_backup_code' );
		$m->setAccessible( true );
		return $m->invoke( null, $user_id, $code );
	}

	protected function setUp(): void {
		\atora_test_reset_user_meta();
		parent::setUp();
	}

	/**
	 * Escenario 1 de la matriz manual del OT: 6 intentos consecutivos
	 * de código incorrecto vía el mismo camino que usa el formulario
	 * POST (verify_token()) — el 6º debe rechazarse SIN evaluar el
	 * código (aunque sea el código correcto).
	 *
	 * @test
	 */
	public function test_sixth_wrong_form_attempt_is_rejected_before_evaluating_the_code(): void {
		$original = $this->install_wpdb_fixture( 300, '123456' );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertFalse(
				\ATORA\Security\Two_FA_Manager::verify_token( 300, '000000' ),
				"intento {$i} con código incorrecto debe rechazarse por código, no por límite"
			);
		}

		// El 6º intento usa el código REALMENTE correcto — si el gate
		// funciona, se rechaza de todos modos porque el cupo ya se
		// agotó, sin llegar a evaluarlo.
		$this->assertFalse(
			\ATORA\Security\Two_FA_Manager::verify_token( 300, '123456' ),
			'el 6º intento debe rechazarse por el límite, incluso con el código correcto'
		);

		$this->restore_wpdb( $original );
	}

	/**
	 * El cupo se comparte entre verify_token() (código normal) y
	 * verify_backup_code() (código de respaldo) — un atacante no puede
	 * "resetear" su cupo alternando entre ambos, porque ambos usan el
	 * mismo scope/identificador (pending_user_id).
	 *
	 * @test
	 */
	public function test_normal_and_backup_code_share_the_same_quota(): void {
		$original = $this->install_wpdb_fixture( 301, '654321' );

		\ATORA\Security\Two_FA_Manager::verify_token( 301, 'wrong-1' );
		\ATORA\Security\Two_FA_Manager::verify_token( 301, 'wrong-2' );
		$this->assertFalse( $this->call_verify_backup_code( 301, 'WRONGCODE1' ) );
		$this->assertFalse( $this->call_verify_backup_code( 301, 'WRONGCODE2' ) );
		\ATORA\Security\Two_FA_Manager::verify_token( 301, 'wrong-5' );

		// 5 intentos ya consumidos entre ambos caminos — el 6º, sea
		// cual sea el camino, debe rechazarse por el límite.
		$this->assertFalse(
			$this->call_verify_backup_code( 301, 'ANYTHING' ),
			'el límite debe aplicarse aunque los 5 intentos previos hayan sido por el código normal'
		);

		$this->restore_wpdb( $original );
	}

	/**
	 * El código de respaldo pasa por el mismo gate que el código
	 * normal — 6 intentos de respaldo incorrectos también se
	 * bloquean, no solo los del código de 6 dígitos.
	 *
	 * @test
	 */
	public function test_backup_code_is_subject_to_the_same_limit(): void {
		$original = $this->install_wpdb_fixture( 302, '999999' );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertFalse( $this->call_verify_backup_code( 302, 'WRONG-' . $i ) );
		}

		$this->assertFalse(
			$this->call_verify_backup_code( 302, 'WRONG-6' ),
			'el 6º intento de código de respaldo debe rechazarse por el límite'
		);

		$this->restore_wpdb( $original );
	}

	/**
	 * Un login exitoso limpia el contador — el usuario no queda
	 * penalizado para su próximo intento de login legítimo.
	 *
	 * @test
	 */
	public function test_successful_verification_resets_the_counter(): void {
		$original = $this->install_wpdb_fixture( 303, '111222' );

		\ATORA\Security\Two_FA_Manager::verify_token( 303, 'wrong-1' );
		\ATORA\Security\Two_FA_Manager::verify_token( 303, 'wrong-2' );
		$this->assertTrue( \ATORA\Security\Two_FA_Manager::verify_token( 303, '111222' ) );

		global $wpdb;
		$this->assertSame( array(), $wpdb->counters, 'un acierto debe limpiar el contador de intentos fallidos' );

		$this->restore_wpdb( $original );
	}

	/**
	 * Fail-closed: sin ATORA_Rate_Limiter disponible, ningún código se
	 * evalúa — nunca se permite sin límite.
	 *
	 * @test
	 */
	public function test_fails_closed_without_a_valid_pending_user_id(): void {
		$original = $this->install_wpdb_fixture( 0, '123456' );

		$this->assertFalse(
			\ATORA\Security\Two_FA_Manager::verify_token( 0, '123456' ),
			'un user_id inválido (0) debe fallar cerrado, nunca evaluarse'
		);

		$this->restore_wpdb( $original );
	}
}
