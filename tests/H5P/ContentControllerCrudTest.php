<?php
/**
 * H5P — list_items() filtra por autor salvo manage_options (S1.3).
 *
 * @package ATORA_LMS\Tests\H5P
 */

declare( strict_types = 1 );

namespace ATORA\Tests\H5P;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FakeWpdbH5P {
	public string $prefix = 'wp_';
	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_row( $sql, $output = null ) { return null; }
}

final class ContentControllerCrudTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/h5p/class-h5p-content-manager.php';
		require_once __DIR__ . '/../../modules/h5p/rest/class-h5p-content-controller.php';

		atora_test_reset_posts();
		atora_test_reset_user_caps();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbH5P();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	private function make_list_request(): \WP_REST_Request {
		return new \WP_REST_Request( array( 'search' => '', 'per_page' => 20, 'page' => 1 ) );
	}

	/** @test */
	public function list_items_scopes_to_current_author_for_a_regular_instructor(): void {
		$GLOBALS['__atora_test_current_user_id'] = 7;
		atora_test_set_user_cap( 7, 'manage_options', false );

		$captured_args = null;
		Functions\when( 'get_posts' )->alias( function ( array $args ) use ( &$captured_args ) {
			$captured_args = $args;
			return array();
		} );

		$controller = new \ATORA\H5P\REST\H5P_Content_Controller( new \ATORA\H5P\H5P_Content_Manager() );
		$controller->list_items( $this->make_list_request() );

		$this->assertIsArray( $captured_args );
		$this->assertArrayHasKey( 'author', $captured_args, 'un instructor normal solo debe ver su propio contenido' );
		$this->assertSame( 7, $captured_args['author'] );
	}

	/** @test */
	public function list_items_does_not_scope_by_author_for_manage_options(): void {
		$GLOBALS['__atora_test_current_user_id'] = 99;
		atora_test_set_user_cap( 99, 'manage_options', true );

		$captured_args = null;
		Functions\when( 'get_posts' )->alias( function ( array $args ) use ( &$captured_args ) {
			$captured_args = $args;
			return array();
		} );

		$controller = new \ATORA\H5P\REST\H5P_Content_Controller( new \ATORA\H5P\H5P_Content_Manager() );
		$controller->list_items( $this->make_list_request() );

		$this->assertIsArray( $captured_args );
		$this->assertArrayNotHasKey( 'author', $captured_args, 'manage_options debe ver todo el contenido, sin filtrar por autor' );
	}
}
