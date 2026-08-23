<?php
/**
 * Worker de concurrencia real para ATORA_Rate_Limiter::consume() —
 * PT-1/PT-4/PT-5 (sprint 6.5.9).
 *
 * Cada invocación de este script es un PROCESO DEL SISTEMA OPERATIVO
 * totalmente aparte (lanzado por RateLimiterConcurrencyTest vía
 * proc_open(), todos arrancados ANTES de esperar a ninguno — carrera
 * real, no una simulación secuencial dentro del mismo proceso PHP).
 *
 * El estado compartido del contador vive en un archivo real,
 * protegido con flock(LOCK_EX) — el mismo tipo de exclusión mutua que
 * el bloqueo de fila de InnoDB le da a
 * "INSERT ... ON DUPLICATE KEY UPDATE" en producción. Si el algoritmo
 * de ATORA_Rate_Limiter::consume() tuviera una ventana de carrera
 * (lectura y escritura separadas, no atómicas), decenas de procesos
 * reales disparados en simultáneo la expondrían aun con este
 * respaldo — que dos procesos reales del SO se turnen genuinamente
 * para escribir el mismo archivo es la prueba de concurrencia que
 * esta ronda exige, no una función que se llama en bucle dentro de un
 * único proceso.
 *
 * Variables de entorno: ATORA_TEST_STATE_FILE, ATORA_TEST_RESULT_FILE,
 * ATORA_TEST_SCOPE, ATORA_TEST_IDENTIFIER, ATORA_TEST_LIMIT,
 * ATORA_TEST_WINDOW.
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../bootstrap.php';

$state_file  = (string) getenv( 'ATORA_TEST_STATE_FILE' );
$result_file = (string) getenv( 'ATORA_TEST_RESULT_FILE' );
$scope       = (string) getenv( 'ATORA_TEST_SCOPE' );
$identifier  = (string) getenv( 'ATORA_TEST_IDENTIFIER' );
$limit       = (int) getenv( 'ATORA_TEST_LIMIT' );
$window      = (int) getenv( 'ATORA_TEST_WINDOW' );

global $wpdb;
$wpdb = new class( $state_file ) {
	public string $prefix = 'wp_';
	private string $state_file;

	public function __construct( string $state_file ) { $this->state_file = $state_file; }

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
			if ( ! isset( $args[ $i ] ) ) { return '?'; }
			$value = $args[ $i++ ];
			return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
		}, $sql );
	}

	/**
	 * Sección crítica real: flock(LOCK_EX) sobre un archivo compartido
	 * entre procesos del SO — equivalente al bloqueo de fila que MySQL
	 * daría a la fila (scope, identifier_hash, window_start) real.
	 */
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

	public function query( $sql ) {
		if ( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' )
			&& preg_match( "/VALUES \('([^']*)', '([^']*)', (\d+), 1\)/", $sql, $m ) ) {
			$key = $m[1] . '|' . $m[2] . '|' . $m[3];
			$this->with_locked_state( function( &$data ) use ( $key ) {
				$data[ $key ] = ( $data[ $key ] ?? 0 ) + 1;
			} );
		}
		if ( false !== strpos( $sql, 'DELETE FROM' )
			&& preg_match( "/scope = '([^']*)' AND identifier_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
			$key = $m[1] . '|' . $m[2] . '|' . $m[3];
			$this->with_locked_state( function( &$data ) use ( $key ) {
				unset( $data[ $key ] );
			} );
		}
		return 1;
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'SELECT attempts FROM' )
			&& preg_match( "/scope = '([^']*)' AND identifier_hash = '([^']*)' AND window_start = (\d+)/", $sql, $m ) ) {
			$key = $m[1] . '|' . $m[2] . '|' . $m[3];
			return $this->with_locked_state( function( &$data ) use ( $key ) {
				return $data[ $key ] ?? null;
			} );
		}
		return null;
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

$allowed = \ATORA_Rate_Limiter::consume( $scope, $identifier, $limit, $window, false );

file_put_contents( $result_file, $allowed ? '1' : '0' );
