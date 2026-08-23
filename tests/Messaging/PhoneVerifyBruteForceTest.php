<?php
/**
 * Tope de intentos de validación del código de verificación — PT-5 (sprint 6.5.1).
 *
 * Hallazgo confirmado: verify_phone_code() no incrementaba ningún
 * contador ante código incorrecto — el límite de 3 solicitudes/hora
 * ya existía, pero nada limitaba cuántas veces se podía intentar
 * *adivinar* el código de 6 dígitos dentro de su ventana de 10
 * minutos.
 *
 * PT-5.3: verificado que /wp-admin/admin-ajax.php?action=
 * atora_verify_phone_code solo está registrado como wp_ajax_ (no
 * wp_ajax_nopriv_) en class-messaging-preferences-shortcode.php —
 * exige sesión autenticada, así que un tope por IP adicional no
 * aplica aquí (regla 5.3: "verificar eso primero").
 *
 * PT-5 (sprint 6.5.9): ambos contadores (intentos de validar el
 * código y solicitudes de código nuevo) migrados de
 * get_user_meta()/update_user_meta() (no atómico) a
 * ATORA_Rate_Limiter, consume-primero — mismo patrón que PT-4
 * (enrollment) y PT-1 (2FA). El límite observable sigue siendo 5
 * intentos de código (el 6º, no el 5º, rechazado — consume() reserva
 * el cupo ANTES de evaluar, así que los primeros 5 SIEMPRE se evalúan)
 * y 3 solicitudes/hora, sin cambios de política.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class PhoneVerifyBruteForceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		\atora_test_reset_user_meta();
		parent::tearDown();
	}

	/**
	 * $wpdb en memoria respaldando atora_rate_limit_counters — mismo
	 * patrón que AccessLinkPasswordThrottleTest (PT-4, 6.5.9).
	 */
	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix  = 'wp_';
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

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	private function issue_code( int $user_id, string $code = '123456' ): void {
		update_user_meta( $user_id, 'atora_phone', '+58 412 1234567' );
		update_user_meta( $user_id, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );
		update_user_meta( $user_id, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( $code ) );
	}

	/** @test */
	public function test_sixth_attempt_is_rejected_even_with_correct_code(): void {
		$original = $this->install_wpdb_fixture();
		$this->issue_code( 1, '123456' );

		for ( $i = 0; $i < 5; $i++ ) {
			$result = \ATORA\Messaging\Preferences::verify_phone_code( 1, '000000' );
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'codigo_incorrecto', $result['reason'], "intento {$i} debe evaluarse como código incorrecto, no como límite" );
		}

		// El código sigue siendo el correcto, pero ya se agotaron los 5 intentos.
		$result = \ATORA\Messaging\Preferences::verify_phone_code( 1, '123456' );
		$this->assertFalse( $result['ok'], 'el 6º intento debe rechazarse aunque el código sea el correcto' );
		$this->assertSame( 'demasiados_intentos', $result['reason'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_sixth_wrong_attempt_reports_too_many_attempts(): void {
		$original = $this->install_wpdb_fixture();
		$this->issue_code( 2, '123456' );

		for ( $i = 0; $i < 5; $i++ ) {
			\ATORA\Messaging\Preferences::verify_phone_code( 2, '000000' );
		}
		$result = \ATORA\Messaging\Preferences::verify_phone_code( 2, '000000' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'demasiados_intentos', $result['reason'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_correct_code_within_attempt_limit_still_succeeds(): void {
		$original = $this->install_wpdb_fixture();
		$this->issue_code( 3, '123456' );

		\ATORA\Messaging\Preferences::verify_phone_code( 3, '000000' );
		\ATORA\Messaging\Preferences::verify_phone_code( 3, '111111' );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 3, '123456' );
		$this->assertTrue( $result['ok'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_correct_code_resets_the_attempt_counter(): void {
		$original = $this->install_wpdb_fixture();
		$this->issue_code( 33, '123456' );

		\ATORA\Messaging\Preferences::verify_phone_code( 33, '000000' );
		\ATORA\Messaging\Preferences::verify_phone_code( 33, '111111' );
		\ATORA\Messaging\Preferences::verify_phone_code( 33, '123456' ); // acierto.

		global $wpdb;
		$this->assertSame( array(), $wpdb->counters, 'un acierto debe limpiar el contador de intentos de código' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_requesting_new_code_resets_the_attempt_counter(): void {
		$original = $this->install_wpdb_fixture();
		$this->issue_code( 4, '123456' );

		for ( $i = 0; $i < 4; $i++ ) {
			\ATORA\Messaging\Preferences::verify_phone_code( 4, '000000' );
		}

		// Pedir un código nuevo — el contador de intentos vuelve a cero.
		\ATORA\Messaging\Preferences::request_phone_verification( 4 );

		$new_hash = get_user_meta( 4, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, true );
		$this->assertNotSame( wp_hash( '123456' ), $new_hash, 'debe haberse generado un código distinto' );

		// El contador de intentos de código (no el de solicitudes) debe
		// haberse limpiado — vuelve a tener las 5 oportunidades completas.
		for ( $i = 0; $i < 5; $i++ ) {
			$result = \ATORA\Messaging\Preferences::verify_phone_code( 4, '000000' );
			$this->assertSame( 'codigo_incorrecto', $result['reason'], "intento {$i} tras el código nuevo no debe estar ya bloqueado" );
		}

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-5.1 (6.5.9): límite de 3 solicitudes/hora — la 4ª solicitud de
	 * código nuevo dentro de la misma hora debe rechazarse.
	 *
	 * @test
	 */
	public function test_fourth_code_request_within_the_hour_is_rejected(): void {
		$original = $this->install_wpdb_fixture();
		update_user_meta( 5, 'atora_phone', '+58 412 1234567' );

		for ( $i = 0; $i < 3; $i++ ) {
			$result = \ATORA\Messaging\Preferences::request_phone_verification( 5 );
			$this->assertNotSame( 'limite_intentos', $result['reason'] ?? null, "solicitud {$i} no debe rechazarse por límite todavía" );
		}

		$result = \ATORA\Messaging\Preferences::request_phone_verification( 5 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'limite_intentos', $result['reason'] );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-5 (6.5.9): concurrencia real — 10 procesos del SO reales,
	 * disparados en simultáneo (todos arrancados antes de esperar a
	 * ninguno), evaluando el mismo código incorrecto contra el mismo
	 * usuario. El número de intentos REALMENTE evaluados no debe
	 * superar el límite nominal de 5.
	 *
	 * @test
	 */
	public function test_concurrent_wrong_codes_never_exceed_the_nominal_limit(): void {
		$state_file = sys_get_temp_dir() . '/atora_test_wa_state_' . bin2hex( random_bytes( 8 ) ) . '.json';
		$tmp_files  = array( $state_file );

		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$script  = __DIR__ . '/fixtures/run-verify-phone-code.php';

		$processes    = array();
		$pipes_all    = array();
		$result_files = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$result_file    = sys_get_temp_dir() . '/atora_test_wa_result_' . bin2hex( random_bytes( 8 ) ) . '.txt';
			$result_files[] = $result_file;
			$tmp_files[]    = $result_file;

			$env = array_merge( $_ENV ?? array(), array(
				'ATORA_TEST_STATE_FILE'  => $state_file,
				'ATORA_TEST_RESULT_FILE' => $result_file,
				'ATORA_TEST_USER_ID'     => '950',
			) );

			$pipes   = array();
			$process = proc_open(
				array( $php_bin, $script ),
				array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
				$pipes,
				null,
				$env
			);

			if ( ! is_resource( $process ) ) {
				$this->fail( 'no se pudo lanzar el proceso hijo ' . $i );
			}

			$processes[] = $process;
			$pipes_all[] = $pipes;
		}

		foreach ( $processes as $i => $process ) {
			stream_get_contents( $pipes_all[ $i ][1] );
			stream_get_contents( $pipes_all[ $i ][2] );
			fclose( $pipes_all[ $i ][1] );
			fclose( $pipes_all[ $i ][2] );
			proc_close( $process );
		}

		$evaluated = 0; // 'codigo_incorrecto' == se evaluó.
		$blocked   = 0; // 'demasiados_intentos' == rechazado antes de evaluar.
		foreach ( $result_files as $rf ) {
			$reason = file_exists( $rf ) ? trim( (string) file_get_contents( $rf ) ) : '';
			if ( 'codigo_incorrecto' === $reason ) {
				++$evaluated;
			} elseif ( 'demasiados_intentos' === $reason ) {
				++$blocked;
			}
		}

		foreach ( $tmp_files as $f ) {
			if ( file_exists( $f ) ) {
				@unlink( $f );
			}
		}

		$this->assertLessThanOrEqual( 5, $evaluated, 'con 10 procesos reales compitiendo, el código no debe evaluarse más de 5 veces' );
		$this->assertSame( 10, $evaluated + $blocked, 'cada uno de los 10 procesos debe terminar en un resultado reconocido' );
	}
}
