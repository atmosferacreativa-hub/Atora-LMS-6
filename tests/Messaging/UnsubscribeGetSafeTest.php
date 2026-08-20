<?php
/**
 * Baja por enlace — GET ya no modifica estado — PT-1 (sprint 6.5.4).
 *
 * Hallazgo confirmado: maybe_handle_unsubscribe_link() ejecutaba la
 * baja inmediatamente al detectar un token válido en $_GET — un
 * escáner antispam, un preview de cliente de correo o cualquier bot
 * que visitara el enlace daba de baja al usuario sin que hubiera
 * tocado nada. Ahora el GET solo muestra confirmación; el cambio real
 * requiere el POST del formulario de esa página, con el mismo token.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class UnsubscribeGetSafeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		atora_test_reset_user_meta();
		parent::tearDown();
	}

	private function resolve( ?string $get_token, bool $is_post_confirm, ?string $post_token ): ?array {
		$ref = new \ReflectionMethod( \ATORA\Messaging\Preferences_Shortcode::class, 'resolve_unsubscribe_request' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $get_token, $is_post_confirm, $post_token );
	}

	private function prefs( int $user_id ): array {
		return \ATORA\Messaging\Preferences::get( $user_id );
	}

	/** @test */
	public function test_get_with_valid_token_only_confirms_does_not_change_preferences(): void {
		$token = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 10, 'academico' );

		$before = $this->prefs( 10 );
		$outcome = $this->resolve( $token, false, null );
		$after = $this->prefs( 10 );

		$this->assertSame( 'confirm', $outcome['action'] );
		$this->assertSame( $token, $outcome['token'] );
		$this->assertSame( $before, $after, 'un GET nunca debe cambiar las preferencias' );
	}

	/** @test */
	public function test_repeated_get_never_changes_state(): void {
		// Simula un escáner visitando el enlace varias veces.
		$token = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 11, 'all' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->resolve( $token, false, null );
		}

		$prefs = $this->prefs( 11 );
		$this->assertTrue( $prefs['categories']['academico'] ?? false, 'seguir con todo activo — el GET repetido no debe haber dado de baja nada' );
	}

	/** @test */
	public function test_post_with_confirm_executes_the_unsubscribe(): void {
		$token = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 12, 'academico' );

		$outcome = $this->resolve( $token, true, $token );

		$this->assertSame( 'done', $outcome['action'] );
		$prefs = $this->prefs( 12 );
		$this->assertFalse( $prefs['categories']['academico'] );
	}

	/** @test */
	public function test_post_with_all_category_unsubscribes_everything(): void {
		$token = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 13, 'all' );

		$this->resolve( $token, true, $token );

		$prefs = $this->prefs( 13 );
		foreach ( $prefs['categories'] as $active ) {
			$this->assertFalse( $active );
		}
	}

	/** @test */
	public function test_invalid_token_never_changes_state_on_get_or_post(): void {
		$get_outcome = $this->resolve( 'token-invalido', false, null );
		$this->assertSame( 'error', $get_outcome['action'] );

		$post_outcome = $this->resolve( null, true, 'token-invalido' );
		$this->assertSame( 'error', $post_outcome['action'] );
	}

	/** @test */
	public function test_expired_token_reports_error(): void {
		// generate_unsubscribe_token() fuerza un mínimo de 1 día de TTL
		// (max(1, $ttl_days)), así que un token ya vencido se construye
		// a mano con el mismo esquema de firma (HMAC sobre
		// wp_salt('auth'), estable en el bootstrap de test).
		$payload  = '14|academico|' . ( time() - 3600 );
		$sig      = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		$expired_token = rtrim( strtr( base64_encode( $payload . '|' . $sig ), '+/', '-_' ), '=' );

		$outcome = $this->resolve( $expired_token, false, null );

		$this->assertSame( 'error', $outcome['action'] );
	}

	/** @test */
	public function test_no_get_or_post_returns_null(): void {
		$this->assertNull( $this->resolve( null, false, null ) );
	}

	/** @test */
	public function test_post_reuses_the_same_token_from_the_link(): void {
		// PT-1.3: no se genera un segundo token — el POST usa
		// literalmente el mismo que llegó por la URL.
		$token = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 15, 'recordatorios' );

		$confirm = $this->resolve( $token, false, null );
		$this->assertSame( $token, $confirm['token'] );

		// El formulario de confirmación manda ese mismo token de vuelta por POST.
		$done = $this->resolve( null, true, $confirm['token'] );
		$this->assertSame( 'done', $done['action'] );
	}
}
