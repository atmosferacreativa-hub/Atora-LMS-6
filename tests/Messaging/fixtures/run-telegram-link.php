<?php
/**
 * Runner aislado para Telegram_Bot::ajax_link_account() — PT-4 (6.5.5), PT-5 (6.5.7).
 *
 * ajax_link_account() termina la ejecución vía wp_send_json_error()/
 * wp_send_json_success() (exit()) — se ejecuta en un proceso PHP
 * aparte (ver TelegramChatUniquenessTest, que lo lanza con
 * proc_open()) y escribe su resultado en un archivo de salida en vez
 * de devolverlo por stdout, para que el proceso PHPUnit padre pueda
 * inspeccionarlo después de que el hijo termine.
 *
 * PT-5 (6.5.7): el fixture de $wpdb ahora simula de verdad
 * atora_telegram_links con sus dos UNIQUE KEY (user_id, chat_id) —
 * insert() rechaza (devuelve false) cualquier fila que choque con
 * cualquiera de las dos, igual que MySQL — para poder probar el
 * backstop de concurrencia real, no solo el chequeo de aplicación.
 *
 * Variables de entorno: ATORA_TEST_OUT (archivo de resultado),
 * ATORA_TEST_CURRENT_USER, ATORA_TEST_CHAT_ID,
 * ATORA_TEST_EXISTING_OWNER (user_id ya vinculado a ese chat_id, o
 * vacío si ninguno), ATORA_TEST_SIMULATE_RACE=1 (simula que otra
 * solicitud ganó la carrera justo antes del INSERT de este proceso).
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../bootstrap.php';

$out_path        = (string) getenv( 'ATORA_TEST_OUT' );
$current_user    = (int) getenv( 'ATORA_TEST_CURRENT_USER' );
$chat_id         = (string) getenv( 'ATORA_TEST_CHAT_ID' );
$existing_owner  = getenv( 'ATORA_TEST_EXISTING_OWNER' );
$existing_owner  = ( false !== $existing_owner && '' !== $existing_owner ) ? (int) $existing_owner : null;
$simulate_race   = '1' === getenv( 'ATORA_TEST_SIMULATE_RACE' );

$GLOBALS['__atora_test_current_user_id'] = $current_user;

global $wpdb;
$wpdb = new class( $existing_owner, $chat_id, $simulate_race ) {
	public string $prefix   = 'wp_';
	public string $usermeta = 'wp_usermeta';
	public array  $links    = array(); // id => array(user_id, chat_id)
	private int   $next_id  = 1;
	private bool  $simulate_race;

	public function __construct( ?int $existing_owner, string $chat_id, bool $simulate_race ) {
		if ( null !== $existing_owner ) {
			$this->links[ $this->next_id++ ] = array( 'user_id' => $existing_owner, 'chat_id' => $chat_id );
		}
		$this->simulate_race = $simulate_race;
	}

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
			if ( ! isset( $args[ $i ] ) ) { return '?'; }
			$value = $args[ $i++ ];
			return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
		}, $sql );
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'atora_telegram_links' ) && preg_match( "/chat_id = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->links as $row ) {
				if ( $row['chat_id'] === $m[1] ) { return $row['user_id']; }
			}
			return null;
		}
		return null;
	}

	public function insert( $table, $data, $format = null ): int {
		if ( false === strpos( (string) $table, 'atora_telegram_links' ) ) {
			return 1; // otras tablas (no relevantes acá) siempre "OK".
		}

		if ( $this->simulate_race ) {
			// Simula que OTRA solicitud reclamó este chat_id justo antes
			// de este INSERT — el backstop de UNIQUE KEY de MySQL.
			$this->simulate_race = false; // solo la primera vez.
			return 0;
		}

		foreach ( $this->links as $row ) {
			if ( $row['user_id'] === $data['user_id'] || $row['chat_id'] === $data['chat_id'] ) {
				return 0; // UNIQUE KEY violation — igual que $wpdb->insert() real.
			}
		}

		$this->links[ $this->next_id++ ] = array( 'user_id' => $data['user_id'], 'chat_id' => $data['chat_id'] );
		return 1;
	}

	public function delete( $table, $where, $where_format = null ): int {
		if ( false === strpos( (string) $table, 'atora_telegram_links' ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( $this->links as $id => $row ) {
			if ( isset( $where['user_id'] ) && $row['user_id'] === $where['user_id'] ) {
				unset( $this->links[ $id ] );
				$removed++;
			}
		}
		return $removed;
	}

	public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
	public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
	public function get_col( $sql ) { return array(); }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
	public function query( $sql ) { return 1; }
	public function esc_like( string $s ): string { return $s; }
	public function get_charset_collate(): string { return ''; }
};

$code = strtoupper( bin2hex( random_bytes( 5 ) ) );
set_transient( 'atora_tg_link_' . $code, $chat_id, 600 );

$_POST['code'] = $code;

// ajax_link_account() termina en exit() — no hay forma de leer un
// valor de retorno. Un shutdown function corre justo antes de que el
// proceso termine y vuelca el estado real de la tabla simulada a un
// archivo que el proceso PHPUnit padre lee después.
register_shutdown_function( static function () use ( $out_path, $chat_id ) {
	global $wpdb;
	$result = array();
	foreach ( $wpdb->links as $row ) {
		if ( $row['chat_id'] === $chat_id ) {
			$result[] = (int) $row['user_id'];
		}
	}
	file_put_contents( $out_path, wp_json_encode( array( 'linked_user_ids' => $result ) ) );
} );

\ATORA\Messaging\Telegram_Bot::ajax_link_account();
