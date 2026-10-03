<?php

declare( strict_types = 1 );

namespace ATORA\Tests\Frontend;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/frontend/class-frontend-urls.php';

final class FrontendUrlsLoginTest extends TestCase {
	private array $pages_by_path = array();

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_options();
		$GLOBALS['__atora_test_posts'] = array();
		$this->pages_by_path           = array();

		Functions\when( 'add_query_arg' )->alias( static function ( $key, $value, $url ) {
			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
		} );
		Functions\when( 'wp_login_url' )->alias( static function ( $redirect = '' ) {
			return 'https://example.test/wp-login.php' . ( '' !== $redirect ? '?redirect_to=' . rawurlencode( $redirect ) : '' );
		} );
		Functions\when( 'get_page_by_path' )->alias( fn ( $slug ) => $this->pages_by_path[ $slug ] ?? null );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.test' . $path );
	}

	private function add_page( int $id, string $slug, string $status = 'publish' ): void {
		$page = (object) array( 'ID' => $id, 'post_name' => $slug, 'post_status' => $status );
		$GLOBALS['__atora_test_posts'][ $id ] = $page;
		$this->pages_by_path[ $slug ]         = $page;
	}

	public function test_falls_back_to_wp_login_without_account_page(): void {
		$url = \CLMS_Frontend_URLs::login_url( 'https://example.test/cursos/foto/' );

		$this->assertSame( 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( 'https://example.test/cursos/foto/' ), $url );
	}

	public function test_uses_mapped_account_page_and_keeps_redirect(): void {
		$this->add_page( 6827, 'cuenta' );
		update_option( 'clms_frontend_pages', array( 'profile' => 6827 ) );

		$url = \CLMS_Frontend_URLs::login_url( 'https://example.test/cursos/foto/' );

		$this->assertSame( 'https://example.test/?p=6827&redirect_to=' . rawurlencode( 'https://example.test/cursos/foto/' ), $url );
	}

	public function test_uses_account_slug_when_option_missing(): void {
		$this->add_page( 42, 'cuenta' );

		$this->assertSame( 'https://example.test/?p=42', \CLMS_Frontend_URLs::login_url() );
	}

	public function test_ignores_unpublished_account_page(): void {
		$this->add_page( 42, 'cuenta', 'draft' );
		update_option( 'clms_frontend_pages', array( 'profile' => 42 ) );

		$this->assertSame( 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( 'https://example.test/' ), \CLMS_Frontend_URLs::login_url() );
	}
}
