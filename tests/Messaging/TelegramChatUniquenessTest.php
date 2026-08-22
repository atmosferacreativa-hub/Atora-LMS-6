<?php
/**
 * Telegram_Bot::ajax_link_account() — un chat_id no puede pertenecer
 * a dos usuarios — PT-4 (sprint 6.5.5), endurecido con unicidad real
 * a nivel de BD en PT-5 (sprint 6.5.7), y con re-vinculación segura
 * (nunca DELETE-then-INSERT) en PT-3 (sprint 6.5.8).
 *
 * Hallazgo confirmado en 6.5.5: update_user_meta() sobrescribía
 * silenciosamente cualquier vínculo previo de OTRO usuario a ese
 * mismo chat_id. 6.5.7 movió la fuente de verdad a
 * atora_telegram_links (UNIQUE KEY sobre user_id Y chat_id), pero
 * re-vincular seguía siendo DELETE del vínculo viejo + INSERT del
 * nuevo — si el INSERT perdía la carrera por UNIQUE(chat_id), el
 * usuario se quedaba SIN vínculo (el viejo ya se había borrado). 6.5.8
 * lo corrige: cuando el usuario ya tiene un vínculo, se usa UPDATE
 * sobre esa misma fila — si el UPDATE falla por la UNIQUE KEY, la fila
 * original queda intacta con su chat_id anterior.
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
	 * @return array{all_links?:array<int,array{user_id:int,chat_id:string}>, current_user_chat?:string|null}|null
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

	private function chat_owners( array $result, string $chat_id ): array {
		$owners = array();
		foreach ( $result['all_links'] ?? array() as $row ) {
			if ( ( $row['chat_id'] ?? null ) === $chat_id ) {
				$owners[] = (int) $row['user_id'];
			}
		}
		return $owners;
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
		$this->assertSame( array( 10 ), $this->chat_owners( $result, '555000' ) );
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

		$this->assertNotNull( $result );
		$this->assertSame( array( 11 ), $this->chat_owners( $result, '555001' ), 'camino idempotente: el vínculo existente de 11 debe seguir intacto, sin duplicarse' );
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
		$this->assertSame( array( 12 ), $this->chat_owners( $result, '555002' ), 'el chat debe seguir siendo de 12 — nunca transferido silenciosamente a 13' );
	}

	/**
	 * Backstop real de concurrencia (PT-5, 6.5.7): dos solicitudes
	 * "simultáneas" para el mismo chat_id, ninguna con vínculo previo —
	 * el INSERT del que pierde la carrera debe rechazarse, nunca un
	 * éxito silencioso ni un estado corrupto.
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
		$this->assertSame( array(), $this->chat_owners( $result, '555003' ), 'si el INSERT pierde la carrera (UNIQUE KEY), el usuario 14 no debe quedar vinculado' );
	}

	/**
	 * PT-3 (6.5.8) — caso confirmado del hallazgo: el usuario 15 ya
	 * tiene un chat vinculado (chat_old) e intenta re-vincular a un
	 * chat que, por una carrera de concurrencia, otra solicitud acaba
	 * de reclamar justo antes del UPDATE de este proceso (el chequeo
	 * previo de aplicación no la detectó todavía). El UPDATE debe
	 * fallar por la UNIQUE KEY, y el vínculo ORIGINAL de 15 (chat_old)
	 * debe seguir intacto — la versión anterior a 6.5.8 hacía
	 * DELETE-then-INSERT, así que el DELETE ya habría borrado
	 * chat_old antes de que el INSERT fallara, dejando a 15 sin ningún
	 * vínculo.
	 *
	 * @test
	 */
	public function test_relink_conflict_preserves_the_users_existing_binding(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_CURRENT_USER'           => '15',
			'ATORA_TEST_CHAT_ID'                => '555004', // el chat NUEVO que 15 intenta reclamar.
			'ATORA_TEST_EXISTING_OWNER'         => '',        // el chequeo previo de aplicación no ve conflicto todavía.
			'ATORA_TEST_CURRENT_USER_OLD_CHAT'  => '555005',  // el vínculo actual de 15, antes del intento.
			'ATORA_TEST_SIMULATE_RACE'          => '1',       // el UPDATE en sí pierde la carrera por UNIQUE(chat_id).
		) );

		$this->assertNotNull( $result );
		$this->assertSame( '555005', $result['current_user_chat'] ?? null, '15 debe conservar su vínculo anterior (555005), no quedar sin ninguno' );
	}

	/**
	 * PT-3 (6.5.8): re-vincular a un chat_id realmente libre SÍ debe
	 * reemplazar el vínculo anterior del mismo usuario (una sola fila
	 * por usuario, gracias a UNIQUE(user_id)).
	 *
	 * @test
	 */
	public function test_relink_to_a_free_chat_replaces_the_old_binding(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_CURRENT_USER'          => '17',
			'ATORA_TEST_CHAT_ID'               => '555006', // chat nuevo, libre.
			'ATORA_TEST_EXISTING_OWNER'        => '',
			'ATORA_TEST_CURRENT_USER_OLD_CHAT' => '555007',  // vínculo anterior de 17.
		) );

		$this->assertNotNull( $result );
		$this->assertSame( '555006', $result['current_user_chat'] ?? null, '17 debe quedar vinculado al chat nuevo' );
		$this->assertSame( array(), $this->chat_owners( $result, '555007' ), 'el chat viejo ya no debe pertenecer a nadie' );
		$this->assertCount( 1, $result['all_links'] ?? array(), 'debe seguir habiendo una sola fila para el usuario 17 (UNIQUE(user_id))' );
	}
}
