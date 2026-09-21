<?php
/**
 * WordPress integration test bootstrap.
 *
 * Requiere WordPress + tests/phpunit instalados (WP_UnitTestCase).
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Stub mínimo de WP_CLI para ejercitar Tenancy_CLI dentro de WP_UnitTestCase.
	 */
	final class WP_CLI {
		public static array $logs = array();

		public static function add_command( $name, $callable ): void {
			// No-op en tests.
		}

		public static function log( $message ): void {
			self::$logs[] = (string) $message;
		}

		public static function success( $message ): void {
			self::$logs[] = 'SUCCESS: ' . (string) $message;
		}

		public static function error( $message ): void {
			throw new RuntimeException( (string) $message );
		}

		public static function confirm( $message, $assoc_args = array() ): void {
			throw new RuntimeException( 'WP_CLI::confirm llamado en test: ' . (string) $message );
		}
	}
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP tests not found. Set WP_TESTS_DIR (current: {$_tests_dir}).\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function () {
	$plugin = dirname( __DIR__, 2 ) . '/atora_lms.php';
	require_once $plugin;
} );

require $_tests_dir . '/includes/bootstrap.php';

