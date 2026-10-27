<?php
/** Public endpoint shells must participate in WordPress academy branding. */
declare( strict_types = 1 );

final class AcademyPublicPageTest extends WP_UnitTestCase {

	public function test_shell_loads_identity_hooks_and_preserves_endpoint_forms(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/frontend/class-academy-public-page.php';
		$head = static function () {
			echo '<style id="academy-test">:root{--atora-accent:#bc3456;--atora-font-body:Georgia}</style>';
		};
		$classes = static function ( $classes ) {
			$classes[] = 'academy-test-body';
			return $classes;
		};
		$footer = static function () {
			echo '<!-- academy-footer -->';
		};
		add_action( 'wp_head', $head );
		add_filter( 'body_class', $classes );
		add_action( 'wp_footer', $footer );
		try {
			foreach ( array(
				ATORA_Account_Deletion::content( array( 'state' => 'form' ) ),
				ATORA_Certificate_Verify::content( array( 'state' => 'form' ) ),
			) as $content ) {
				ob_start();
				CLMS_Academy_Public_Page::render( 'Public endpoint', $content );
				$html = ob_get_clean();
				$this->assertStringContainsString( 'id="academy-test"', $html );
				$this->assertStringContainsString( 'academy-test-body', $html );
				$this->assertStringContainsString( 'atora-public-page', $html );
				$this->assertStringContainsString( '<!-- academy-footer -->', $html );
				$this->assertStringContainsString( $content, $html, 'Keep the endpoint form, action and nonce markup intact.' );
				$this->assertStringContainsString( 'noindex,nofollow', $html );
				$this->assertStringContainsString( esc_html( get_bloginfo( 'name' ) ), $html );
			}
		} finally {
			remove_action( 'wp_head', $head );
			remove_filter( 'body_class', $classes );
			remove_action( 'wp_footer', $footer );
		}
	}
}
