<?php
/**
 * Telegram_Bot::ajax_link_account() — un chat_id no puede pertenecer
 * a dos usuarios — PT-4 (sprint 6.5.5), endurecido con unicidad real
 * a nivel de BD en PT-5 (sprint 6.5.7).
 *
 * Hallazgo confirmado en 6.5.5: update_user_meta() sobrescribía
 * silenciosamente cualquier vínculo previo de OTRO usuario a ese
 * mismo chat_id — usermeta no tiene restricción UNIQUE nativa. 6.5.5
 * agregó un chequeo de aplicación + un candado de transient de mejor
 * esfuerzo (no una garantía real bajo concurrencia). 6.5.7 mueve la
 * fuente de verdad a atora_telegram_links, con UNIQUE KEY sobre
 * user_id Y sobre chat_id — el fixture de $wpdb simula esa
 * restricción de verdad (insert() rechaza cualquier fila que
 * choque), incluyendo el caso de "otra solicitud ganó la carrera"
 * justo antes del INSERT de este proceso.
 *
 * ajax_link_account() termina en exit() (wp_send_json_*), así que
 * cada escenario corre en un proceso PHP aparte vía proc_open()
 * sobre fixtures/run-telegram-link.php, que vuelca el estado final de
 * la tabla simulada a un archivo para que este test lo revise después.
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

		// El shutdown function vuelca el estado final de la tabla — como
		// el chat ya pertenecía a 11 y el flujo idempotente sale antes
		// de tocar la tabla de nuevo, debe seguir habiendo exactamente
		// una fila, y sigue siendo la de 11 (no se duplicó ni se perdió).
		$this->assertNotNull( $result );
		$this->assertSame( array( 11 ), $result['linked_user_ids'] ?? null, 'camino idempotente: el vínculo existente de 11 debe seguir intacto, sin duplicarse' );
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
		$this->assertSame( array( 12 ), $result['linked_user_ids'] ?? null, 'el chat debe seguir siendo de 12 — nunca transferido silenciosamente a 13' );
	}

	/**
	 * Backstop real de concurrencia (PT-5, 6.5.7): dos solicitudes
	 * "simultáneas" para el mismo chat_id — el chequeo de aplicación
	 * (get_user_by_chat) pasa para ambas porque ninguna ve todavía la
	 * fila de la otra, pero el INSERT final solo puede tener éxito para
	 * UNA, por la UNIQUE KEY de la tabla. La solicitud que pierde la
	 * carrera debe recibir un error claro, nunca un éxito silencioso ni
	 * un estado corrupto.
	 *
	 * @test
	 */
	public function test_losing_a_concurrent_link_race_is_rejected_not_silently_accepted(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_CURRENT_USER'    => '14',
			'ATORA_TEST_CHAT_ID'         => '555003',
			'ATORA_TEST_EXISTING_OWNER'  => '',
			'ATORA_TEST_SIMULATE_RACE'   => '1',
		) );

		$this->assertNotNull( $result );
		$this->assertSame( array(), $result['linked_user_ids'] ?? null, 'si el INSERT pierde la carrera (UNIQUE KEY), el usuario 14 no debe quedar vinculado' );
	}
}
