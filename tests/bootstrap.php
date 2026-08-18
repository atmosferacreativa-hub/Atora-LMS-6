<?php
/**
 * PHPUnit Bootstrap — ATORA LMS v6.0.0
 *
 * Usa Brain\Monkey para mockear funciones de WordPress sin necesitar
 * una instalación completa. Compatible con PHPUnit 10+ y PHP 8.1+.
 *
 * Instalación:
 *   composer install
 *   ./vendor/bin/phpunit --testdox
 *
 * @package ATORA_LMS\Tests
 */

declare( strict_types = 1 );

// Autoloader de Composer
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	echo "ERROR: Ejecuta 'composer install' antes de correr los tests.\n";
	exit( 1 );
}
require_once $autoload;

// Brain\Monkey para stubs de WordPress
\Brain\Monkey\setUp();

// Definir constantes WP mínimas
if ( ! defined( 'ABSPATH' ) )         { define( 'ABSPATH', '/tmp/wp/' ); }
if ( ! defined( 'ATORA_LMS_VERSION' ) ) { define( 'ATORA_LMS_VERSION', '6.0.0' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'OBJECT' ) )          { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

// Stub de $wpdb global
global $wpdb;
if ( ! $wpdb ) {
	$wpdb = new class {
		public string $prefix      = 'wp_';
		public string $users       = 'wp_users';
		public string $usermeta    = 'wp_usermeta';
		public int    $insert_id   = 1;
		public string $last_error  = '';

		public function prepare( string $sql, ...$args ): string {
			// Sustituir %d y %s de forma simplificada para tests
			$i = 0;
			return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
				return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
			}, $sql );
		}
		public function get_var( $sql )             { return null; }
		public function get_row( $sql, $output = OBJECT ) { return null; }
		public function get_results( $sql, $output = OBJECT ) { return array(); }
		public function get_col( $sql )             { return array(); }
		public function insert( $table, $data, $format = null ): int { $this->insert_id = 42; return 1; }
		public function update( $table, $data, $where, $format = null, $where_format = null ): int { return 1; }
		public function delete( $table, $where, $where_format = null ): int { return 1; }
		public function query( $sql ): int          { return 1; }
		public function esc_like( string $s ): string { return addcslashes( $s, '_%\\' ); }
		public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	};
}

// Stubs de funciones WP más usadas
if ( ! function_exists( 'absint' ) )           { function absint( $v ): int { return abs( (int) $v ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_email' ) )   { function sanitize_email( $s ): string { return filter_var( (string) $s, FILTER_SANITIZE_EMAIL ) ?: ''; } }
if ( ! function_exists( 'sanitize_key' ) )     { function sanitize_key( $s ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'esc_url_raw' ) )      { function esc_url_raw( $s ): string { return filter_var( (string) $s, FILTER_SANITIZE_URL ) ?: ''; } }
if ( ! function_exists( 'wp_json_encode' ) )   { function wp_json_encode( $d ): string { return (string) json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) )     { function current_time( string $t, bool $gmt = false ): string { return date( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id(): int { return 1; } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
			mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
			mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
		);
	}
}
if ( ! function_exists( 'do_action' ) )        { function do_action( string $hook, ...$args ): void {} }
if ( ! function_exists( 'apply_filters' ) )    { function apply_filters( string $hook, $value, ...$args ) { return $value; } }
$GLOBALS['__atora_test_options'] = array();
if ( ! function_exists( 'get_option' ) )       {
	function get_option( string $k, $default = false ) {
		return array_key_exists( $k, $GLOBALS['__atora_test_options'] ) ? $GLOBALS['__atora_test_options'][ $k ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) )    {
	function update_option( string $k, $v, $autoload = null ): bool {
		$GLOBALS['__atora_test_options'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'atora_test_reset_options' ) ) {
	function atora_test_reset_options(): void { $GLOBALS['__atora_test_options'] = array(); }
}
if ( ! function_exists( 'get_user_meta' ) )    { function get_user_meta( int $id, string $k = '', bool $single = false ) { return $single ? '' : array(); } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( int $id, string $k, $v ): bool { return true; } }
if ( ! function_exists( 'wp_cache_get' ) )     { function wp_cache_get( string $k, string $g = '' ) { return false; } }
if ( ! function_exists( 'wp_cache_set' ) )     { function wp_cache_set( string $k, $v, string $g = '', int $ttl = 0 ): bool { return true; } }
if ( ! function_exists( 'wp_cache_delete' ) )  { function wp_cache_delete( string $k, string $g = '' ): bool { return true; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, int $dec = 0 ): string { return number_format( (float) $n, $dec ); } }
if ( ! function_exists( '__' ) )               { function __( string $s, string $d = '' ): string { return $s; } }
if ( ! function_exists( 'esc_html' ) )         { function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) )         { function esc_attr( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_send_json_success' ) ) { function wp_send_json_success( $d = null ): void { exit( json_encode( array( 'success' => true, 'data' => $d ) ) ); } }
if ( ! function_exists( 'wp_send_json_error' ) )   { function wp_send_json_error( $d = null, int $status = 0 ): void { exit( json_encode( array( 'success' => false, 'data' => $d ) ) ); } }

// Cargar servicios bajo test
$services_dir = __DIR__ . '/../modules/crm-v2/services/';
foreach ( array(
	'class-db-service.php',
	'class-contact-service.php',
	'class-scoring-service.php',
	'class-company-service.php',
	'class-list-service.php',
	'class-campaign-service.php',
) as $svc ) {
	if ( file_exists( $services_dir . $svc ) ) {
		require_once $services_dir . $svc;
	}
}

$lms_dir = __DIR__ . '/../modules/lms/';
foreach ( array(
	'class-lms-course-service.php',
	'class-lms-enrollment-service.php',
	'class-lms-read-router.php',
	'class-lms-parity.php',
) as $lms ) {
	if ( file_exists( $lms_dir . $lms ) ) {
		require_once $lms_dir . $lms;
	}
}

$messaging_router_file = __DIR__ . '/../modules/messaging/class-messaging-router.php';
if ( file_exists( $messaging_router_file ) ) {
	require_once $messaging_router_file;
}

$inactivity_service_file = __DIR__ . '/../includes/academic/class-student-inactivity-reminder-service.php';
if ( file_exists( $inactivity_service_file ) ) {
	require_once $inactivity_service_file;
}

foreach ( array( 'class-module-registry.php', 'class-install-profiles.php', 'class-profile-labels.php' ) as $mod_file ) {
	$path = __DIR__ . '/../includes/modularity/' . $mod_file;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}
