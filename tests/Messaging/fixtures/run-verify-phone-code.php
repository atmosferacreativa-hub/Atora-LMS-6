<?php
/**
 * Worker de concurrencia real para
 * \ATORA\Messaging\Preferences::verify_phone_code() — PT-5 (sprint 6.5.9).
 *
 * Proceso del SO aparte (lanzado por PhoneVerifyBruteForceTest vía
 * proc_open(), todos arrancados antes de esperar a ninguno). Llama a
 * verify_phone_code() UNA vez con un código incorrecto y escribe el
 * "reason" resultante a su propio archivo de resultado.
 * 'codigo_incorrecto' significa que el código SÍ se evaluó (consume()
 * dejó pasar el intento); 'demasiados_intentos' significa que se
 * rechazó por el límite ANTES de evaluar el código.
 *
 * El contador de rate limit compartido vive en un archivo real
 * protegido con flock(LOCK_EX) entre procesos reales — no una
 * simulación en memoria dentro de un único proceso.
 *
 * Variables de entorno: ATORA_TEST_STATE_FILE, ATORA_TEST_RESULT_FILE,
 * ATORA_TEST_USER_ID.
 *
 * @package ATORA_LMS\Tests\Messaging
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

	public function __construct( string $state_file ) { $this->state_file = $state_file; }

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
			if ( ! isset( $args[ $i ] ) ) { return '?'; }
			$value = $args[ $i++ ];
			return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
		}, $sql );
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

	public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
	public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
	public function get_col( $sql ) { return array(); }
	public function insert( $table, $data, $format = null ): int { return 1; }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
	public function delete( $table, $where, $where_format = null ): int { return 1; }
	public function esc_like( string $s ): string { return $s; }
	public function get_charset_collate(): string { return ''; }
};

update_user_meta( $user_id, 'atora_phone', '+58 412 1234567' );
update_user_meta( $user_id, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );
update_user_meta( $user_id, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );

$result = \ATORA\Messaging\Preferences::verify_phone_code( $user_id, '000000' );

file_put_contents( $result_file, $result['reason'] ?? ( $result['ok'] ? 'ok' : 'unknown' ) );
