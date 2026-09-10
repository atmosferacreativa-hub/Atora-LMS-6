<?php
/**
 * Microsoft — vincular/desvincular cuenta (Microsoft_Identity::
 * rest_link_account()/rest_unlink_account()).
 *
 * @package ATORA_LMS\Tests\Microsoft
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Microsoft;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class LinkUnlinkAuthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/microsoft/class-microsoft-module.php';
		require_once __DIR__ . '/../../modules/microsoft/class-microsoft-identity.php';

		atora_test_reset_options();
		atora_test_reset_user_meta();
		$GLOBALS['__atora_test_current_user_id'] = 0;

		Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-json/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( static function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
	}

	private function enable_microsoft_sso(): void {
		update_option( 'atora_microsoft_options', array(
			'enabled'       => 1,
			'client_id'     => 'abc',
			'client_secret' => 'secret',
		) );
	}

	/** @test */
	public function link_account_rejects_when_sso_not_configured(): void {
		// Sin habilitar (get_option devuelve default array()).
		$request = new \WP_REST_Request();
		$response = \ATORA\Microsoft\Microsoft_Identity::rest_link_account( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/** @test */
	public function link_account_returns_a_redirect_url_with_link_flag_when_enabled(): void {
		$this->enable_microsoft_sso();

		$request  = new \WP_REST_Request();
		$response = \ATORA\Microsoft\Microsoft_Identity::rest_link_account( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'link=1', $data['redirect'] );
		$this->assertStringContainsString( 'microsoft/oauth/start', $data['redirect'] );
	}

	/** @test */
	public function unlink_account_returns_401_when_no_user_is_logged_in(): void {
		$GLOBALS['__atora_test_current_user_id'] = 0;

		$request  = new \WP_REST_Request();
		$response = \ATORA\Microsoft\Microsoft_Identity::rest_unlink_account( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/** @test */
	public function unlink_account_clears_only_the_current_users_oid_meta(): void {
		$GLOBALS['__atora_test_current_user_id'] = 42;
		update_user_meta( 42, '_atora_microsoft_oid', 'oid-42' );
		update_user_meta( 99, '_atora_microsoft_oid', 'oid-99' ); // otro usuario, no debe tocarse

		$request  = new \WP_REST_Request();
		$response = \ATORA\Microsoft\Microsoft_Identity::rest_unlink_account( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), get_user_meta( 42, '_atora_microsoft_oid' ) );
		$this->assertNotEmpty( get_user_meta( 99, '_atora_microsoft_oid' ), 'no debe afectar el vínculo de otro usuario' );
	}
}
