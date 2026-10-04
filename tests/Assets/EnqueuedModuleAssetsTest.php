<?php

declare( strict_types = 1 );

namespace ATORA\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Los assets que encolan el registro extendido y los popups deben existir en disco
 * (6.27.2: faltaban y daban 404).
 */
final class EnqueuedModuleAssetsTest extends TestCase {
	private const SOURCES = array(
		'modules/security/class-extended-registration.php',
		'modules/analytics/class-popups.php',
	);

	public function test_every_enqueued_module_asset_exists(): void {
		$root    = dirname( __DIR__, 2 );
		$checked = 0;
		$missing = array();

		foreach ( self::SOURCES as $source ) {
			$code = (string) file_get_contents( $root . '/' . $source );
			preg_match_all( "/ATORA_LMS_MODULES_URL\s*\.\s*'([^']+)'/", $code, $matches );
			foreach ( $matches[1] as $relative ) {
				$checked++;
				if ( ! is_file( $root . '/modules/' . $relative ) ) {
					$missing[] = $source . ' → modules/' . $relative;
				}
			}
		}

		$this->assertGreaterThanOrEqual( 3, $checked, 'Se esperaban al menos los tres assets encolados.' );
		$this->assertSame( array(), $missing );
	}
}
