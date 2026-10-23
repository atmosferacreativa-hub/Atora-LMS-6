<?php
/**
 * Licensing module hardening tests.
 *
 * @package ATORA_LMS\Tests\Licensing
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Licensing;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/licensing/class-licensing.php';

class LicensingModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_options();
		atora_test_reset_filters();
	}

	/** @test */
	public function test_no_server_means_no_outbound_update_check(): void {
		// Configuración: licencia válida, pero sin servidor configurado.
		update_option( \ATORA\Licensing\Licensing::OPT_STATUS, 'valid' );
		update_option( \ATORA\Licensing\Licensing::OPT_KEY, 'ATORA-XXXX-XXXX-XXXX' );
		update_option( \ATORA\Licensing\Licensing::OPT_SERVER, '' );

		Functions\expect( 'wp_remote_get' )->never();

		$update = array( 'keep' => true );
		$out    = \ATORA\Licensing\Licensing::check_for_update( $update, array( 'Version' => '6.33.2' ), 'atora_lms.php', array() );
		$this->assertSame( $update, $out );
	}

	/** @test */
	public function test_invalid_signature_is_rejected(): void {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->markTestSkipped( 'ext-sodium no disponible' );
		}

		$path = (string) tempnam( sys_get_temp_dir(), 'atora-lic-' );
		file_put_contents( $path, 'zip-bytes-placeholder' );

		$signature = base64_encode( random_bytes( 64 ) );
		$result    = \ATORA\Licensing\Licensing::verify_update_signature( $path, $signature );

		$this->assertTrue( is_wp_error( $result ), 'una firma inválida debe rechazarse' );
		@unlink( $path );
	}
}

