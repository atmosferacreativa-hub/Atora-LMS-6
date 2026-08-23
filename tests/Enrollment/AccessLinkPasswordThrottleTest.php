<?php
/**
 * CLMS_Enrollment_Manager_Access_Enrollment_Trait::redeem_access_link() —
 * fuerza bruta contra la contraseña del enlace de acceso — PT-3 (sprint 6.5.5).
 *
 * Hallazgo confirmado: wp_check_password() se usaba correctamente,
 * pero sin ningún límite de intentos — un usuario autenticado con el
 * link/token podía probar contraseñas indefinidamente hasta acertar.
 * Se agrega un contador por usuario+token (nunca solo por IP): 5
 * intentos fallidos por 15 minutos, reseteado en el primer acierto.
 *
 * PT-5 (6.5.8): migrado de get_transient()/set_transient() (no
 * atómico) a ATORA_Rate_Limiter (peek/consume/reset) — mismo
 * comportamiento observable, contador ahora respaldado por la tabla
 * atora_rate_limit_counters en vez de transients.
 *
 * PT-4 (6.5.9): el par peek()-luego-consume() de 6.5.8 tenía una
 * ventana de carrera real (lectura y escritura separadas, no
 * atómicas) — varias solicitudes concurrentes podían leer el mismo
 * contador antes de que ninguna lo incrementara, permitiendo más
 * intentos efectivos que el límite nominal. Se invierte el orden:
 * consume() reserva el cupo ANTES de evaluar la contraseña; el
 * comportamiento secuencial observable no cambia (siguen siendo 5
 * intentos, el 6º rechazado, reset en el acierto), pero ahora también
 * es atómico bajo concurrencia real — ver
 * test_concurrent_wrong_passwords_never_exceed_the_nominal_limit(),
 * que dispara 10 procesos del SO reales en paralelo.
 *
 * @package ATORA_LMS\Tests\Enrollment
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Enrollment;

use PHPUnit\Framework\TestCase;

/**
 * Doble de prueba: compone solo el trait bajo test, evitando la
 * dependencia de ensure_db()/install_db() de las otras traits de
 * CLMS_Enrollment_Manager (no relevantes para esta lógica).
 */
class Test_Access_Link_Host {
	use \CLMS_Enrollment_Manager_Access_Enrollment_Trait;

	const LINK_FREE     = 'free';
	const LINK_PASSWORD = 'password';
	const LINK_REGISTER = 'register';

	public function table() { return 'wp_test_access_links'; }
}

class AccessLinkPasswordThrottleTest extends TestCase {

	/**
	 * $wpdb en memoria: una fila de enlace de tipo password (con un
	 * hash conocido vía el wp_check_password() stub del bootstrap) más
	 * una simulación completa de atora_rate_limit_counters (la misma
	 * que respalda ATORA_Rate_Limiter::peek()/consume()/reset()).
	 */
	private function install_wpdb_fixture( string $correct_password ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $correct_password ) {
			public string $prefix  = 'wp_';
			public object $row;
			public array  $counters = array(); // "scope|identifier_hash|window_start" => attempts

			public function __construct( string $correct_password ) {
				$this->row = (object) array(
					'id'              => 1,
					'token'           => 'tok-abc',
					'course_id'       => 7,
					'access_mode'     => 'password',
					'access_password' => wp_hash_password( $correct_password ),
					'used_count'      => 0,
				);
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( $sql, 'test_access_links' ) ) {
					return $this->row;
				}
				return null;
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

	/** @test */
	public function test_first_five_wrong_attempts_are_allowed_through_to_the_password_check(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$result = $host->redeem_access_link( 'tok-abc', 100, 'wrong-guess-' . $i );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'wrong_password', $result->get_error_code(), "intento {$i} debe evaluarse como contraseña incorrecta, no como bloqueo" );
		}

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_sixth_attempt_is_blocked_by_the_lockout(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$host->redeem_access_link( 'tok-abc', 101, 'wrong-guess-' . $i );
		}

		$result = $host->redeem_access_link( 'tok-abc', 101, 'wrong-guess-6' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_attempts', $result->get_error_code(), 'el 6º intento debe rechazarse por el límite, sin siquiera evaluar la contraseña' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_correct_password_resets_the_counter(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		$host->redeem_access_link( 'tok-abc', 103, 'wrong-guess-1' );
		$host->redeem_access_link( 'tok-abc', 103, 'wrong-guess-2' );

		// Acierto — CLMS_Helper no existe en el entorno de test, así que
		// la matrícula en sí falla más adelante en el flujo, pero el
		// contador de intentos ya debe haberse limpiado antes de eso.
		$host->redeem_access_link( 'tok-abc', 103, 'correct-horse-battery-staple' );

		global $wpdb;
		$this->assertSame( array(), $wpdb->counters, 'un acierto debe limpiar el contador de intentos fallidos' );

		// Y por lo tanto vuelve a tener las 5 oportunidades completas.
		for ( $i = 1; $i <= 5; $i++ ) {
			$result = $host->redeem_access_link( 'tok-abc', 103, 'wrong-again-' . $i );
			$this->assertSame( 'wrong_password', $result->get_error_code() );
		}

		$this->restore_wpdb( $original );
	}

	/**
	 * TTL: pasada la ventana de 15 minutos, el contador de la ventana
	 * vieja ya no aplica — vuelve a evaluarse la contraseña normalmente
	 * (ventana fija, igual que el resto de los limitadores atómicos de
	 * este proyecto).
	 *
	 * @test
	 */
	public function test_lockout_resets_after_the_window_expires(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$host->redeem_access_link( 'tok-abc', 104, 'wrong-guess-' . $i );
		}
		$this->assertSame( 'too_many_attempts', $host->redeem_access_link( 'tok-abc', 104, 'wrong-again' )->get_error_code() );

		// Simula el paso a una ventana nueva: se mueve manualmente la
		// clave de ventana de las filas existentes a una muy antigua —
		// equivalente a que hayan pasado más de 15 minutos.
		global $wpdb;
		$moved = array();
		foreach ( $wpdb->counters as $key => $attempts ) {
			$parts = explode( '|', $key );
			$moved[ $parts[0] . '|' . $parts[1] . '|0' ] = $attempts;
		}
		$wpdb->counters = $moved;

		$result = $host->redeem_access_link( 'tok-abc', 104, 'wrong-guess-again' );
		$this->assertSame( 'wrong_password', $result->get_error_code(), 'tras pasar a una ventana nueva, debe volver a evaluarse la contraseña normalmente' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_user_a_lockout_does_not_affect_user_b(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$host->redeem_access_link( 'tok-abc', 201, 'wrong-guess-' . $i );
		}
		$this->assertSame( 'too_many_attempts', $host->redeem_access_link( 'tok-abc', 201, 'x' )->get_error_code() );

		// Usuario B, mismo token, primer intento — no debe heredar el bloqueo de A.
		$result_b = $host->redeem_access_link( 'tok-abc', 202, 'wrong-guess-b' );
		$this->assertSame( 'wrong_password', $result_b->get_error_code(), 'el bloqueo de un usuario no debe afectar a otro usuario distinto' );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-8 (6.5.8): fail-closed — si la tabla de rate limit no existe
	 * (backend no disponible), el intento debe rechazarse, nunca
	 * permitirse sin límite.
	 *
	 * @test
	 */
	public function test_fails_closed_when_rate_limit_table_is_unavailable(): void {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public object $row;
			public function __construct() {
				$this->row = (object) array(
					'id' => 1, 'token' => 'tok-abc', 'course_id' => 7,
					'access_mode' => 'password', 'access_password' => wp_hash_password( 'x' ), 'used_count' => 0,
				);
			}
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function get_row( $sql, $output = 'ARRAY_A' ) { return $this->row; }
			public function get_var( $sql ) { return null; } // SHOW TABLES nunca encuentra la tabla.
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ) { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		$host = new Test_Access_Link_Host();
		$result = $host->redeem_access_link( 'tok-abc', 300, 'anything' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_attempts', $result->get_error_code(), 'sin la tabla de rate limit disponible, debe fallar cerrado (bloquear), no permitir sin límite' );

		$wpdb = $original;
	}

	/**
	 * PT-4 (6.5.9), fila 4 de la matriz manual del OT: 10 procesos del
	 * SO reales, disparados en simultáneo (arrancados TODOS antes de
	 * esperar a ninguno), contra el mismo user_id|token con la misma
	 * contraseña incorrecta. El número de intentos REALMENTE evaluados
	 * por wp_check_password() (distinguible por el código de error
	 * 'wrong_password' vs 'too_many_attempts' — ver
	 * fixtures/run-access-link-redeem.php) no debe superar el límite
	 * nominal de 5, incluso bajo esta concurrencia real. Un test
	 * secuencial (un bucle for dentro de un solo proceso PHP, como los
	 * de arriba) no puede demostrar esto — nunca hay dos ejecuciones
	 * compitiendo de verdad por la misma fila del contador.
	 *
	 * @test
	 */
	public function test_concurrent_wrong_passwords_never_exceed_the_nominal_limit(): void {
		$state_file = sys_get_temp_dir() . '/atora_test_al_state_' . bin2hex( random_bytes( 8 ) ) . '.json';
		$tmp_files  = array( $state_file );

		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$script  = __DIR__ . '/fixtures/run-access-link-redeem.php';

		$processes    = array();
		$pipes_all    = array();
		$result_files = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$result_file    = sys_get_temp_dir() . '/atora_test_al_result_' . bin2hex( random_bytes( 8 ) ) . '.txt';
			$result_files[] = $result_file;
			$tmp_files[]    = $result_file;

			$env = array_merge( $_ENV ?? array(), array(
				'ATORA_TEST_STATE_FILE'  => $state_file,
				'ATORA_TEST_RESULT_FILE' => $result_file,
				'ATORA_TEST_USER_ID'     => '900',
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

		// Recién ahora se espera — todos los 10 procesos ya estaban
		// corriendo en paralelo.
		foreach ( $processes as $i => $process ) {
			stream_get_contents( $pipes_all[ $i ][1] );
			stream_get_contents( $pipes_all[ $i ][2] );
			fclose( $pipes_all[ $i ][1] );
			fclose( $pipes_all[ $i ][2] );
			proc_close( $process );
		}

		$evaluated = 0; // 'wrong_password' == wp_check_password() se llegó a evaluar.
		$blocked   = 0; // 'too_many_attempts' == rechazado por el límite antes de evaluar.
		foreach ( $result_files as $rf ) {
			$code = file_exists( $rf ) ? trim( (string) file_get_contents( $rf ) ) : '';
			if ( 'wrong_password' === $code ) {
				++$evaluated;
			} elseif ( 'too_many_attempts' === $code ) {
				++$blocked;
			}
		}

		foreach ( $tmp_files as $f ) {
			if ( file_exists( $f ) ) {
				@unlink( $f );
			}
		}

		$this->assertLessThanOrEqual(
			5,
			$evaluated,
			'con 10 procesos reales compitiendo, wp_check_password() no debe evaluarse más de 5 veces — de lo contrario la carrera sigue abierta'
		);
		$this->assertSame( 10, $evaluated + $blocked, 'cada uno de los 10 procesos debe terminar en un resultado reconocido (evaluado o bloqueado)' );
	}
}
