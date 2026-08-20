<?php
/**
 * Runner aislado para Forms_Builder::handle_submit() — PT-1 (sprint 6.5.5).
 *
 * handle_submit() termina la ejecución vía wp_send_json_error()/
 * wp_send_json_success() (exit()), lo cual no es capturable con
 * try/catch dentro del mismo proceso PHPUnit. Este script se ejecuta
 * en un proceso PHP totalmente aparte (ver FormsSubmitOrderTest, que
 * lo lanza con proc_open()); el exit() simplemente termina este
 * proceso hijo, y el proceso PHPUnit padre continúa sin verse
 * afectado — solo necesita revisar el archivo "marcador" que este
 * script pudo haber escrito antes de salir.
 *
 * Parámetros por variable de entorno (ATORA_TEST_*): FORM_ID, NONCE,
 * MARKER, y opcionalmente POST_TYPE=1 / SCHEMA=<json> / VALID_NONCE=1.
 *
 * @package ATORA_LMS\Tests\Analytics
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../bootstrap.php';

$marker_path = (string) ( getenv( 'ATORA_TEST_MARKER' ) ?: '' );

global $wpdb;
$wpdb = new class( $marker_path ) {
	public string $prefix = 'wp_';
	private string $marker_path;

	public function __construct( string $marker_path ) { $this->marker_path = $marker_path; }

	private function record(): void {
		if ( '' !== $this->marker_path ) {
			file_put_contents( $this->marker_path, '1' );
		}
	}

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
			if ( ! isset( $args[ $i ] ) ) { return '?'; }
			$value = $args[ $i++ ];
			return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
		}, $sql );
	}

	public function query( $sql ) {
		if ( false !== strpos( $sql, 'atora_form_throttle' ) || false !== strpos( $sql, 'atora_form_entries' ) ) {
			$this->record();
		}
		return 1;
	}

	public function insert( $table, $data, $format = null ): int {
		if ( false !== strpos( (string) $table, 'atora_form' ) ) {
			$this->record();
		}
		return 1;
	}

	public function get_var( $sql ) { return null; }
	public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
	public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
	public function get_col( $sql ) { return array(); }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
	public function delete( $table, $where, $where_format = null ): int { return 1; }
	public function esc_like( string $s ): string { return $s; }
	public function get_charset_collate(): string { return ''; }
};

$form_id = (string) ( getenv( 'ATORA_TEST_FORM_ID' ) ?: '0' );

if ( '1' === getenv( 'ATORA_TEST_POST_TYPE' ) ) {
	atora_test_set_post_type( (int) $form_id, 'atora_form' );
}

$schema = getenv( 'ATORA_TEST_SCHEMA' );
if ( false !== $schema && '' !== $schema ) {
	atora_test_set_post_meta( (int) $form_id, 'atora_form_schema', $schema );
}

$nonce = (string) ( getenv( 'ATORA_TEST_NONCE' ) ?: 'invalid-nonce' );
if ( '1' === getenv( 'ATORA_TEST_VALID_NONCE' ) ) {
	$nonce = wp_create_nonce( 'atora_form_' . $form_id );
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
$_POST = array(
	'form_id'          => $form_id,
	'atora_form_nonce' => $nonce,
);

\ATORA\Analytics\Forms_Builder::handle_submit();
