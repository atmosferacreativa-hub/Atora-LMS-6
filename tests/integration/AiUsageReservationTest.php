<?php
/**
 * Integración 6.33.1 (E.5): los límites de IA reservan el consumo de forma atómica.
 *
 * - Lo que está en curso (reservado) cuenta como usado: 10 llamadas a la vez con
 *   límite 3 → pasan exactamente 3.
 * - La reserva termina en ok (sigue contando) o error (libera el cupo).
 * - Una reserva sin cerrar caduca a los 5 minutos.
 * - El tope mensual reserva el costo estimado.
 * - El gestor de IA reserva antes de llamar y cierra después.
 */

declare( strict_types = 1 );

final class AiUsageReservationTest extends WP_UnitTestCase {

	private int $student = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
		delete_option( ATORA_AI_Usage_Service::LIMITS_OPTION );
		ATORA_AI_Usage_Service::set_limits( array( 'student_daily' => 3 ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	protected function tearDown(): void {
		ATORA_AI_Fake_Provider::disable();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_ai_usage" );
		delete_option( ATORA_AI_Usage_Service::LIMITS_OPTION );
		parent::tearDown();
	}

	public function test_ten_in_flight_with_limit_three_pass_exactly_three(): void {
		$passed = array();
		$denied = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$r = ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT );
			if ( is_wp_error( $r ) ) {
				$this->assertSame( 'atora_ai_limit', $r->get_error_code() );
				$this->assertSame( 429, $r->get_error_data()['status'] );
				++$denied;
			} else {
				$passed[] = $r;
			}
		}
		$this->assertCount( 3, $passed, 'En curso cuenta como usado.' );
		$this->assertSame( 7, $denied );
		$this->assertSame( 3, ATORA_AI_Usage_Service::used_today( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) );
		$this->assertTrue( is_wp_error( ATORA_AI_Usage_Service::check( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) ), 'La comprobación previa también cuenta lo que está en curso.' );

		// Un error libera el cupo; un ok lo conserva.
		ATORA_AI_Usage_Service::finish( $passed[0], 'openai', 'm', array(), 'error' );
		ATORA_AI_Usage_Service::finish( $passed[1], 'openai', 'm', array( 'prompt_tokens' => 10 ), 'ok', 0.01 );
		$this->assertSame( 2, ATORA_AI_Usage_Service::used_today( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) );
		$again = ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT );
		$this->assertIsInt( $again );
		$this->assertTrue( is_wp_error( ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) ) );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT result, tokens_in, cost FROM {$wpdb->prefix}atora_ai_usage WHERE id = %d", $passed[1] ), ARRAY_A );
		$this->assertSame( array( 'result' => 'ok', 'tokens_in' => '10', 'cost' => '0.010000' ), array( 'result' => $row['result'], 'tokens_in' => $row['tokens_in'], 'cost' => number_format( (float) $row['cost'], 6, '.', '' ) ) );
	}

	public function test_unfinished_reservation_expires_after_five_minutes(): void {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT );
		}
		$this->assertTrue( is_wp_error( ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) ) );
		// Una quedó colgada (el proceso murió): pasados 5 minutos ya no cuenta.
		$wpdb->update( $wpdb->prefix . 'atora_ai_usage', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 301 ) ), array( 'id' => $ids[0] ) );
		$this->assertSame( 2, ATORA_AI_Usage_Service::used_today( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) );
		$this->assertIsInt( ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) );
		$this->assertSame( 'expired', $wpdb->get_var( $wpdb->prepare( "SELECT result FROM {$wpdb->prefix}atora_ai_usage WHERE id = %d", $ids[0] ) ) );
	}

	public function test_monthly_cap_reserves_the_estimated_cost(): void {
		ATORA_AI_Usage_Service::set_limits( array( 'student_daily' => 100, 'monthly_cost_cap' => 1.0 ) );
		$estimate = static fn() => 0.4;
		add_filter( 'atora_ai_reservation_estimate', $estimate );
		$a = ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT );
		$b = ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT );
		$this->assertIsInt( $a );
		$this->assertIsInt( $b );
		$this->assertEqualsWithDelta( 0.8, ATORA_AI_Usage_Service::month_cost(), 0.0001, 'Lo reservado cuenta para el tope.' );
		$c = ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT );
		$this->assertTrue( is_wp_error( $c ) );
		$this->assertSame( 'atora_ai_budget', $c->get_error_code() );
		// Al cerrar, cuenta el costo real.
		ATORA_AI_Usage_Service::finish( $a, 'openai', 'm', array(), 'ok', 0.05 );
		ATORA_AI_Usage_Service::finish( $b, 'openai', 'm', array(), 'error' );
		$this->assertEqualsWithDelta( 0.05, ATORA_AI_Usage_Service::month_cost(), 0.0001 );
		$this->assertIsInt( ATORA_AI_Usage_Service::reserve( $this->student, ATORA_AI_Usage_Service::ASSISTANT ) );
		remove_filter( 'atora_ai_reservation_estimate', $estimate );
	}

	public function test_manager_reserves_before_calling_and_closes_after(): void {
		global $wpdb;
		ATORA_AI_Fake_Provider::enable();
		$seen = null;
		$spy  = static function ( $pre ) use ( &$seen, $wpdb ) {
			$seen = $wpdb->get_col( "SELECT result FROM {$wpdb->prefix}atora_ai_usage" );
			return $pre;
		};
		add_filter( 'atora_ai_pre_chat', $spy, 5 );
		$out = clms_core( 'CLMS_AI_Manager' )->chat_with_meta( array( array( 'role' => 'user', 'content' => 'Hola' ) ), array( 'feature' => 'assistant', 'user_id' => $this->student ) );
		remove_filter( 'atora_ai_pre_chat', $spy, 5 );
		$this->assertFalse( is_wp_error( $out ) );
		$this->assertSame( array( 'reserved' ), $seen, 'Durante la llamada, el uso ya está reservado.' );
		$this->assertSame( array( 'ok' ), $wpdb->get_col( "SELECT result FROM {$wpdb->prefix}atora_ai_usage" ), 'Una sola fila, cerrada en ok.' );
	}
}
