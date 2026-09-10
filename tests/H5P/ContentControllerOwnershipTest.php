<?php
/**
 * H5P — ownership por-ítem en H5P_Content_Controller (S1.3).
 *
 * Un instructor no debe poder leer/editar/borrar contenido H5P de otro
 * instructor adivinando el id; `manage_options` conserva acceso total.
 *
 * @package ATORA_LMS\Tests\H5P
 */

declare( strict_types = 1 );

namespace ATORA\H5P\REST {
	// H5P_Content_Controller vive en el namespace ATORA\H5P\REST y llama
	// a current_user_can() sin calificar — PHP resuelve primero contra
	// ESTE namespace antes de caer al global. Declarar el override aquí
	// (en vez de mockear la función global compartida por 380+ tests, o
	// de pelear con Patchwork que no puede redefinir una función ya
	// declarada como literal en bootstrap.php) simula la semántica real
	// de map_meta_cap de WordPress para un CPT capability_type=post,
	// SIN tocar ni arriesgar ningún otro test de la suite.
	if ( ! function_exists( __NAMESPACE__ . '\\current_user_can' ) ) {
		function current_user_can( string $cap, ...$args ): bool {
			if ( in_array( $cap, array( 'edit_post', 'delete_post' ), true ) && isset( $args[0] ) ) {
				$post_id = absint( $args[0] );
				$post    = \get_post( $post_id );
				if ( ! $post ) {
					return false;
				}
				$user_id = \get_current_user_id();
				if ( \user_can( $user_id, 'manage_options' ) ) {
					return true;
				}
				$is_owner   = absint( $post->post_author ?? 0 ) === absint( $user_id );
				$others_cap = 'edit_post' === $cap ? 'edit_others_posts' : 'delete_others_posts';
				$own_cap    = 'edit_post' === $cap ? 'edit_posts' : 'delete_posts';
				return $is_owner ? \user_can( $user_id, $own_cap ) : \user_can( $user_id, $others_cap );
			}
			return \user_can( \get_current_user_id(), $cap );
		}
	}
}

namespace ATORA\Tests\H5P {

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ContentControllerOwnershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/h5p/class-h5p-content-manager.php';
		require_once __DIR__ . '/../../modules/h5p/rest/class-h5p-content-controller.php';

		atora_test_reset_posts();
		atora_test_reset_user_caps();
	}

	private function make_request( int $id ): \WP_REST_Request {
		$r = new \WP_REST_Request();
		$r->set_param( 'id', $id );
		return $r;
	}

	/** @test */
	public function get_item_returns_forbidden_for_non_owner(): void {
		atora_test_set_post( 501, array( 'post_type' => 'h5p_content', 'post_author' => 1 ) );
		$GLOBALS['__atora_test_current_user_id'] = 2; // instructor B
		atora_test_set_user_cap( 2, 'edit_posts', true ); // tiene capability propia, no "others"

		$controller = new \ATORA\H5P\REST\H5P_Content_Controller( new \ATORA\H5P\H5P_Content_Manager() );
		$result     = $controller->get_item( $this->make_request( 501 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'atora_h5p_forbidden', $result->get_error_code() );
	}

	/** @test */
	public function update_item_returns_forbidden_for_non_owner(): void {
		atora_test_set_post( 501, array( 'post_type' => 'h5p_content', 'post_author' => 1 ) );
		$GLOBALS['__atora_test_current_user_id'] = 2;
		atora_test_set_user_cap( 2, 'edit_posts', true );

		$controller = new \ATORA\H5P\REST\H5P_Content_Controller( new \ATORA\H5P\H5P_Content_Manager() );
		$result     = $controller->update_item( $this->make_request( 501 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'atora_h5p_forbidden', $result->get_error_code() );
	}

	/** @test */
	public function delete_item_returns_forbidden_for_non_owner(): void {
		atora_test_set_post( 501, array( 'post_type' => 'h5p_content', 'post_author' => 1 ) );
		$GLOBALS['__atora_test_current_user_id'] = 2;
		atora_test_set_user_cap( 2, 'delete_posts', true );

		$controller = new \ATORA\H5P\REST\H5P_Content_Controller( new \ATORA\H5P\H5P_Content_Manager() );
		$result     = $controller->delete_item( $this->make_request( 501 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'atora_h5p_forbidden', $result->get_error_code() );
	}

	/** @test */
	public function manage_options_bypasses_ownership_check_on_delete(): void {
		Functions\when( 'wp_delete_post' )->justReturn( (object) array( 'ID' => 501 ) );

		atora_test_set_post( 501, array( 'post_type' => 'h5p_content', 'post_author' => 1 ) );
		$GLOBALS['__atora_test_current_user_id'] = 99; // admin, no es el autor
		atora_test_set_user_cap( 99, 'manage_options', true );

		$controller = new \ATORA\H5P\REST\H5P_Content_Controller( new \ATORA\H5P\H5P_Content_Manager() );
		$result     = $controller->delete_item( $this->make_request( 501 ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}

	/** @test */
	public function author_can_delete_their_own_content(): void {
		Functions\when( 'wp_delete_post' )->justReturn( (object) array( 'ID' => 501 ) );

		atora_test_set_post( 501, array( 'post_type' => 'h5p_content', 'post_author' => 1 ) );
		$GLOBALS['__atora_test_current_user_id'] = 1; // el propio autor
		atora_test_set_user_cap( 1, 'delete_posts', true );

		$controller = new \ATORA\H5P\REST\H5P_Content_Controller( new \ATORA\H5P\H5P_Content_Manager() );
		$result     = $controller->delete_item( $this->make_request( 501 ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}
}
}
