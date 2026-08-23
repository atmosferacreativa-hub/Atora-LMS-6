<?php
/**
 * Worker de concurrencia real para redeem_access_link() — PT-4
 * (sprint 6.5.9).
 *
 * Proceso del SO aparte (lanzado por AccessLinkPasswordThrottleTest
 * vía proc_open(), todos arrancados antes de esperar a ninguno).
 * Llama a redeem_access_link() UNA vez con una contraseña incorrecta
 * y escribe el código de error resultante a su propio archivo de
 * resultado. 'wrong_password' significa que wp_check_password() SÍ
 * se evaluó (el consume() del limiter dejó pasar el intento);
 * 'too_many_attempts' significa que se rechazó por el límite ANTES de
 * evaluar la contraseña. Contar cuántos procesos devuelven
 * 'wrong_password' es, por construcción del propio código bajo
 * prueba, exactamente "cuántas veces se evaluó wp_check_password()".
 *
 * El contador de rate limit compartido vive en un archivo real
 * protegido con flock(LOCK_EX) entre procesos reales — no una
 * simulación en memoria dentro de un único proceso.
 *
 * Variables de entorno: ATORA_TEST_STATE_FILE, ATORA_TEST_RESULT_FILE,
 * ATORA_TEST_USER_ID.
 *
 * @package ATORA_LMS\Tests\Enrollment
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../bootstrap.php';

$state_file  = (string) getenv( 'ATORA_TEST_STATE_FILE' );
$result_file = (string) getenv( 'ATORA_TEST_RESULT_FILE' );
$user_id     = (int) getenv( 'ATORA_TEST_USER_ID' );

global $wpdb;
$wpdb = new class( $state_file ) {
	public string $prefix = 'wp_';
	private string $state_file;
	private object $row;

	public function __construct( string $state_file ) {
		$this->state_file = $state_file;
		$this->row         = (object) array(
			'id'              => 1,
			'token'           => 'tok-concurrency',
			'course_id'       => 7,
			'access_mode'     => 'password',
			'access_password' => wp_hash_password( 'the-real-password' ),
			'used_count'      => 0,
		);
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
		if ( false !== strpos( $sql, 'test_access_links' ) ) {
			return $this->row;
		}
		return null;
	}

	private function with_locked_state( callable $fn ) {
		$fp = fopen( $this->state_file, 'c+' );
		if ( ! $fp ) {
			return $fn( array() );
		}
		flock( $fp, LOCK_EX );
		$raw  = stream_get_contents( $fp );
		$data = $raw ? ( json_decode( $raw, true ) ?: array() ) : array();

		$result = $fn( $data );

		ftruncate( $fp, 0 );
		rewind( $fp );
		fwrite( $fp, wp_json_encode( $data ) );
		fflush( $fp );
		flock( $fp, LOCK_UN );
		fclose( $fp );

		return $result;
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'SELECT attempts FROM' )
			&& preg_match( "/scope = '([^']*)' AND identifier_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
			$key = $m[1] . '|' . $m[2] . '|' . $m[3];
			return $this->with_locked_state( function ( &$data ) use ( $key ) {
				return $data[ $key ] ?? null;
			} );
		}
		return null;
	}

	public function query( $sql ) {
		if ( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' )
			&& preg_match( "/VALUES \('([^']*)', '([^']*)', (\d+), 1\)/", $sql, $m ) ) {
			$key = $m[1] . '|' . $m[2] . '|' . $m[3];
			$this->with_locked_state( function ( &$data ) use ( $key ) {
				$data[ $key ] = ( $data[ $key ] ?? 0 ) + 1;
			} );
		}
		if ( false !== strpos( $sql, 'DELETE FROM' )
			&& preg_match( "/scope = '([^']*)' AND identifier_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
			$key = $m[1] . '|' . $m[2] . '|' . $m[3];
			$this->with_locked_state( function ( &$data ) use ( $key ) {
				unset( $data[ $key ] );
			} );
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

/**
 * Mismo doble de prueba minimalista que AccessLinkPasswordThrottleTest
 * (definido acá también, no importado desde el archivo de test, para
 * no arrastrar una dependencia de PHPUnit a este script standalone).
 */
class Access_Link_Redeem_Worker_Host {
	use \CLMS_Enrollment_Manager_Access_Enrollment_Trait;

	const LINK_FREE     = 'free';
	const LINK_PASSWORD = 'password';
	const LINK_REGISTER = 'register';

	public function table() { return 'wp_test_access_links'; }
}

$host   = new Access_Link_Redeem_Worker_Host();
$result = $host->redeem_access_link( 'tok-concurrency', $user_id, 'wrong-guess' );

$code = is_wp_error( $result ) ? $result->get_error_code() : 'success';
file_put_contents( $result_file, $code );
