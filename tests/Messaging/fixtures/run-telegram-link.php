<?php
/**
 * Runner aislado para Telegram_Bot::ajax_link_account() — PT-4 (sprint 6.5.5).
 *
 * ajax_link_account() termina la ejecución vía wp_send_json_error()/
 * wp_send_json_success() (exit()) — se ejecuta en un proceso PHP
 * aparte (ver TelegramChatUniquenessTest, que lo lanza con
 * proc_open()) y escribe su resultado en un archivo de salida en vez
 * de devolverlo por stdout, para que el proceso PHPUnit padre pueda
 * inspeccionarlo después de que el hijo termine.
 *
 * Variables de entorno: ATORA_TEST_OUT (archivo de resultado),
 * ATORA_TEST_CURRENT_USER, ATORA_TEST_CHAT_ID,
 * ATORA_TEST_EXISTING_OWNER (user_id ya vinculado a ese chat_id, o
 * vacío si ninguno).
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

$GLOBALS['__atora_test_current_user_id'] = $current_user;

global $wpdb;
$wpdb = new class( $existing_owner, $out_path ) {
	public string $prefix   = 'wp_';
	public string $usermeta = 'wp_usermeta';
	private ?int $existing_owner;
	private string $out_path;
	public array $updated_meta = array();

	public function __construct( ?int $existing_owner, string $out_path ) {
		$this->existing_owner = $existing_owner;
		$this->out_path       = $out_path;
	}

	public function prepare( string $sql, ...$args ): string { return $sql; }

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'atora_telegram_chat_id' ) ) {
			return $this->existing_owner;
		}
		return null;
	}

	public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
	public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
	public function get_col( $sql ) { return array(); }
	public function insert( $table, $data, $format = null ): int { return 1; }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
	public function delete( $table, $where, $where_format = null ): int { return 1; }
	public function query( $sql ) { return 1; }
	public function esc_like( string $s ): string { return $s; }
	public function get_charset_collate(): string { return ''; }
};

$code = strtoupper( bin2hex( random_bytes( 5 ) ) );
set_transient( 'atora_tg_link_' . $code, $chat_id, 600 );

$_POST['code'] = $code;

// ajax_link_account() termina en exit() — no hay forma de leer un
// valor de retorno. Un shutdown function corre justo antes de que el
// proceso termine y vuelca el estado real de usermeta (el array en
// memoria que respalda get_user_meta()/update_user_meta() en el
// bootstrap de test) a un archivo que el proceso PHPUnit padre lee
// después.
register_shutdown_function( static function () use ( $out_path, $chat_id ) {
	$linked = $GLOBALS['__atora_test_user_meta'] ?? array();
	$result = array();
	foreach ( $linked as $uid => $meta ) {
		if ( ( $meta['atora_telegram_chat_id'] ?? null ) === $chat_id ) {
			$result[] = (int) $uid;
		}
	}
	file_put_contents( $out_path, wp_json_encode( array( 'linked_user_ids' => $result ) ) );
} );

\ATORA\Messaging\Telegram_Bot::ajax_link_account();
