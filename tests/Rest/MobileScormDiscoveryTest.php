<?php

declare( strict_types = 1 );

namespace {
	if ( ! function_exists( 'plugin_dir_path' ) ) {
		function plugin_dir_path( string $file ): string { return rtrim( dirname( $file ), '/' ) . '/'; }
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( string $path = '' ): string { return 'https://example.test/' . ltrim( $path, '/' ); }
	}
}

namespace ATORA\Tests\Rest {
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/scorm/class-scorm-attempt-service.php';
	require_once __DIR__ . '/../../includes/scorm/class-scorm-launch-service.php';
	require_once __DIR__ . '/../../includes/mobile/class-mobile-token-service.php';
	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	final class MobileScormDiscoveryTest extends TestCase {
		public function test_discovery_declares_scorm_capability(): void {
			$data = \ATORA_Mobile_REST_Controller::discovery()->get_data();
			$this->assertTrue( $data['capabilities']['scorm'] );
			$this->assertContains( 'scorm', $data['features'] );
		}
	}
}

