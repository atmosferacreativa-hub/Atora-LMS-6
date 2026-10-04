<?php
/**
 * Integración 6.28.1: la API móvil nunca se guarda en cachés de página.
 * En el demo, LiteSpeed Cache entregaba el /dashboard de un usuario a
 * cualquiera, incluso con un token inventado.
 */

declare( strict_types = 1 );

final class MobileNoCacheTest extends WP_UnitTestCase {

	private int $nocache_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		add_action( 'litespeed_control_set_nocache', array( $this, 'count_nocache' ) );
		do_action( 'rest_api_init', rest_get_server() );
	}

	public function count_nocache(): void {
		++$this->nocache_calls;
	}

	private function dispatch( string $route, array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
	}

	public function test_mobile_responses_are_private_including_auth_errors(): void {
		foreach ( array( '/atora-mobile/v1/discovery' => null, '/atora-mobile/v1/dashboard' => 'Bearer inventado' ) as $route => $auth ) {
			$response = $this->dispatch( $route, $auth ? array( 'Authorization' => $auth ) : array() );
			$headers  = $response->get_headers();
			$this->assertSame( 'no-cache', $headers['X-LiteSpeed-Cache-Control'] ?? null, $route );
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] ?? '', $route );
			$this->assertStringContainsString( 'private', $headers['Cache-Control'] ?? '', $route );
		}
		$this->assertSame( 401, $this->dispatch( '/atora-mobile/v1/dashboard', array( 'Authorization' => 'Bearer inventado' ) )->get_status() );
		$this->assertGreaterThanOrEqual( 2, $this->nocache_calls );
	}

	public function test_other_namespaces_are_untouched(): void {
		$response = $this->dispatch( '/wp/v2/types' );
		$this->assertArrayNotHasKey( 'X-LiteSpeed-Cache-Control', $response->get_headers() );
		$this->assertSame( 0, $this->nocache_calls );
	}
}
