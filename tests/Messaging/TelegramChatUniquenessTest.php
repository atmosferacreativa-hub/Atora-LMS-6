<?php
/**
 * Telegram_Bot::ajax_link_account() — un chat_id no puede pertenecer
 * a dos usuarios — PT-4 (sprint 6.5.5).
 *
 * Hallazgo confirmado: update_user_meta($user_id, 'atora_telegram_chat_id', $chat_id)
 * sobrescribía silenciosamente cualquier vínculo previo de OTRO
 * usuario a ese mismo chat_id — usermeta no tiene una restricción
 * UNIQUE nativa sobre meta_value, así que nada lo impedía a nivel de
 * aplicación tampoco, hasta este sprint.
 *
 * ajax_link_account() termina en exit() (wp_send_json_*), así que
 * cada escenario corre en un proceso PHP aparte vía proc_open()
 * sobre fixtures/run-telegram-link.php, que vuelca el estado final de
 * usermeta a un archivo para que este test lo revise después.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class TelegramChatUniquenessTest extends TestCase {

	private ?string $out = null;

	protected function tearDown(): void {
		if ( $this->out && file_exists( $this->out ) ) {
			@unlink( $this->out );
		}
		$this->out = null;
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $env
	 * @return array{linked_user_ids?:array<int,int>}|null
	 */
	private function run_scenario( array $env ): ?array {
		$this->out = sys_get_temp_dir() . '/atora_test_tg_link_' . bin2hex( random_bytes( 8 ) ) . '.json';
		@unlink( $this->out );

		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$script  = __DIR__ . '/fixtures/run-telegram-link.php';

		$full_env = array_merge( $_ENV ?? array(), array( 'ATORA_TEST_OUT' => $this->out ), $env );

		$process = proc_open(
			array( $php_bin, $script ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			null,
			$full_env
		);

		if ( ! is_resource( $process ) ) {
			$this->fail( 'no se pudo lanzar el proceso PHP hijo' );
		}

		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		if ( ! file_exists( $this->out ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $this->out ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Caso base: chat_id sin vincular previamente — el usuario que
	 * envía el código correcto se vincula normalmente.
	 *
	 * @test
	 */
	public function test_unclaimed_chat_id_links_normally(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_CURRENT_USER'   => '10',
			'ATORA_TEST_CHAT_ID'        => '555000',
			'ATORA_TEST_EXISTING_OWNER' => '',
		) );

		$this->assertNotNull( $result );
		$this->assertSame( array( 10 ), $result['linked_user_ids'] ?? null );
	}

	/**
	 * Caso 1/OT: el mismo usuario que ya tiene el chat vinculado vuelve
	 * a intentarlo — debe ser idempotente (sigue vinculado solo a él).
	 *
	 * @test
	 */
	public function test_same_user_relinking_is_idempotent(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_CURRENT_USER'   => '11',
			'ATORA_TEST_CHAT_ID'        => '555001',
			'ATORA_TEST_EXISTING_OWNER' => '11',
		) );

		// El shutdown function del runner solo vuelca lo que quedó en
		// usermeta — como ya pertenecía a 11 y el flujo idempotente
		// sale antes de tocar usermeta de nuevo, no debe haber una
		// escritura nueva (ni, desde luego, un segundo usuario).
		$this->assertNotNull( $result );
		$this->assertSame( array(), $result['linked_user_ids'] ?? null, 'camino idempotente: no debe volver a escribir usermeta' );
	}

	/**
	 * Caso 2/OT: el chat ya pertenece a OTRO usuario — debe rechazarse,
	 * nunca transferirse silenciosamente.
	 *
	 * @test
	 */
	public function test_chat_owned_by_different_user_is_rejected(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_CURRENT_USER'   => '13',
			'ATORA_TEST_CHAT_ID'        => '555002',
			'ATORA_TEST_EXISTING_OWNER' => '12',
		) );

		$this->assertNotNull( $result );
		$this->assertSame( array(), $result['linked_user_ids'] ?? null, 'el usuario 13 no debe quedar vinculado a un chat que ya es de otro usuario' );
	}
}
