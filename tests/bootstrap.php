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
$GLOBALS['__atora_test_current_user_id'] = 1;
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return (int) ( $GLOBALS['__atora_test_current_user_id'] ?? 1 ); }
}
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
$GLOBALS['__atora_test_user_meta'] = array();
if ( ! function_exists( 'get_user_meta' ) )    {
	function get_user_meta( int $id, string $k = '', bool $single = false ) {
		if ( '' === $k ) { return $GLOBALS['__atora_test_user_meta'][ $id ] ?? array(); }
		$has = isset( $GLOBALS['__atora_test_user_meta'][ $id ][ $k ] );
		if ( ! $single ) { return $has ? array( $GLOBALS['__atora_test_user_meta'][ $id ][ $k ] ) : array(); }
		return $has ? $GLOBALS['__atora_test_user_meta'][ $id ][ $k ] : '';
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( int $id, string $k, $v ): bool {
		$GLOBALS['__atora_test_user_meta'][ $id ][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( int $id, string $k ): bool {
		unset( $GLOBALS['__atora_test_user_meta'][ $id ][ $k ] );
		return true;
	}
}
if ( ! function_exists( 'atora_test_reset_user_meta' ) ) {
	function atora_test_reset_user_meta(): void { $GLOBALS['__atora_test_user_meta'] = array(); }
}
$GLOBALS['__atora_test_user_caps'] = array();
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, string $capability ): bool {
		$user_id = is_object( $user_id ) ? absint( $user_id->ID ?? 0 ) : absint( $user_id );
		return ! empty( $GLOBALS['__atora_test_user_caps'][ $user_id ][ $capability ] );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$args ): bool {
		return user_can( get_current_user_id(), $capability );
	}
}
if ( ! function_exists( 'is_super_admin' ) ) {
	function is_super_admin( $user_id = 0 ): bool { return false; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool { return get_current_user_id() > 0; }
}
$GLOBALS['__atora_test_post_types'] = array();
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = 0 ) {
		$id = is_object( $post ) ? absint( $post->ID ?? 0 ) : absint( $post );
		return $GLOBALS['__atora_test_post_types'][ $id ] ?? false;
	}
}
if ( ! function_exists( 'atora_test_set_post_type' ) ) {
	function atora_test_set_post_type( int $post_id, string $post_type ): void {
		$GLOBALS['__atora_test_post_types'][ $post_id ] = $post_type;
	}
}
if ( ! function_exists( 'atora_test_reset_post_types' ) ) {
	function atora_test_reset_post_types(): void { $GLOBALS['__atora_test_post_types'] = array(); }
}
$GLOBALS['__atora_test_posts'] = array();
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id = 0 ) {
		$id = absint( $post_id );
		return $GLOBALS['__atora_test_posts'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'atora_test_set_post' ) ) {
	function atora_test_set_post( int $post_id, array $fields = array() ): void {
		$defaults = array(
			'ID'             => $post_id,
			'post_type'      => 'lm_course',
			'post_author'    => 0,
			'post_date_gmt'  => '2026-01-01 00:00:00',
			'post_title'     => '',
			'post_name'      => '',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'publish',
		);
		$GLOBALS['__atora_test_posts'][ $post_id ] = (object) array_merge( $defaults, $fields );
		$GLOBALS['__atora_test_post_types'][ $post_id ] = $fields['post_type'] ?? 'lm_course';
	}
}
if ( ! function_exists( 'atora_test_reset_posts' ) ) {
	function atora_test_reset_posts(): void { $GLOBALS['__atora_test_posts'] = array(); }
}
$GLOBALS['__atora_test_post_meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		if ( '' === $key ) { return $GLOBALS['__atora_test_post_meta'][ $post_id ] ?? array(); }
		$has = isset( $GLOBALS['__atora_test_post_meta'][ $post_id ][ $key ] );
		if ( ! $single ) { return $has ? array( $GLOBALS['__atora_test_post_meta'][ $post_id ][ $key ] ) : array(); }
		return $has ? $GLOBALS['__atora_test_post_meta'][ $post_id ][ $key ] : '';
	}
}
if ( ! function_exists( 'atora_test_set_post_meta' ) ) {
	function atora_test_set_post_meta( int $post_id, string $key, $value ): void {
		$GLOBALS['__atora_test_post_meta'][ $post_id ][ $key ] = $value;
	}
}
if ( ! function_exists( 'atora_test_reset_post_meta' ) ) {
	function atora_test_reset_post_meta(): void { $GLOBALS['__atora_test_post_meta'] = array(); }
}
if ( ! function_exists( 'atora_test_set_user_cap' ) ) {
	function atora_test_set_user_cap( int $user_id, string $capability, bool $has = true ): void {
		$GLOBALS['__atora_test_user_caps'][ $user_id ][ $capability ] = $has;
	}
}
if ( ! function_exists( 'atora_test_reset_user_caps' ) ) {
	function atora_test_reset_user_caps(): void { $GLOBALS['__atora_test_user_caps'] = array(); }
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) { return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) { return false; }
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( string $s ): string { return '<p>' . $s . '</p>'; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $s ): string { return trim( strip_tags( $s ) ); }
}

/** Stub mínimo de WP_REST_Response — solo lo que usan los controllers CRM bajo test. */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private int $status;
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data, 200 );
	}
}
/** Stub mínimo de WP_REST_Request — parámetros planos, sin rutas/sanitización de WP real. */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params;
		private array $headers;
		private string $route;
		public function __construct( array $params = array(), array $headers = array(), string $route = '' ) {
			$this->params  = $params;
			$this->headers = $headers;
			$this->route   = $route;
		}
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
		public function get_json_params(): array { return $this->params; }
		public function get_header( string $name ) { return $this->headers[ $name ] ?? null; }
		public function get_route(): string { return $this->route; }
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;
		public function __construct( string $code = '', string $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = is_array( $data ) ? $data : array( $data );
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'wp_hash' ) )  { function wp_hash( string $data ): string { return hash_hmac( 'sha256', $data, 'test-salt' ); } }
if ( ! function_exists( 'wp_salt' ) )  { function wp_salt( string $scheme = 'auth' ): string { return 'test-salt-' . $scheme; } }
if ( ! function_exists( 'wp_rand' ) )  { function wp_rand( int $min = 0, int $max = 0 ): int { return random_int( $min, $max ?: PHP_INT_MAX ); } }
$GLOBALS['__atora_test_transients'] = array();
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) { return $GLOBALS['__atora_test_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['__atora_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool { unset( $GLOBALS['__atora_test_transients'][ $key ] ); return true; }
}
if ( ! function_exists( 'atora_test_reset_transients' ) ) {
	function atora_test_reset_transients(): void { $GLOBALS['__atora_test_transients'] = array(); }
}
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
	'class-lms-rest-controller.php',
	'class-lms-migrator.php',
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

$preferences_file = __DIR__ . '/../modules/messaging/class-messaging-preferences.php';
if ( file_exists( $preferences_file ) ) {
	require_once $preferences_file;
}

$preferences_shortcode_file = __DIR__ . '/../modules/messaging/class-messaging-preferences-shortcode.php';
if ( file_exists( $preferences_shortcode_file ) ) {
	require_once $preferences_shortcode_file;
}

$digest_store_file = __DIR__ . '/../modules/messaging/class-messaging-digest-store.php';
if ( file_exists( $digest_store_file ) ) {
	require_once $digest_store_file;
}

$telegram_bot_file = __DIR__ . '/../modules/messaging/class-telegram-bot.php';
if ( file_exists( $telegram_bot_file ) ) {
	require_once $telegram_bot_file;
}

$crm_access_trait_file = __DIR__ . '/../modules/crm/trait-crm-access.php';
if ( file_exists( $crm_access_trait_file ) ) {
	require_once $crm_access_trait_file;
}

$crm_v2_rest_controller_file = __DIR__ . '/../modules/crm-v2/rest/class-crm-rest-controller.php';
if ( file_exists( $crm_v2_rest_controller_file ) ) {
	require_once $crm_v2_rest_controller_file;
}

$crm_email_service_file = __DIR__ . '/../modules/crm-v2/services/class-crm-email-service.php';
if ( file_exists( $crm_email_service_file ) ) {
	require_once $crm_email_service_file;
}

$crm_inbox_rest_controller_file = __DIR__ . '/../modules/crm-v2/rest/class-inbox-rest-controller.php';
if ( file_exists( $crm_inbox_rest_controller_file ) ) {
	require_once $crm_inbox_rest_controller_file;
}

$crm_v2_file = __DIR__ . '/../modules/crm-v2/class-crm-v2.php';
if ( file_exists( $crm_v2_file ) ) {
	require_once $crm_v2_file;
}

$mcp_api_key_service_file = __DIR__ . '/../modules/mcp/class-api-key-service.php';
if ( file_exists( $mcp_api_key_service_file ) ) {
	require_once $mcp_api_key_service_file;
}

$mcp_module_file = __DIR__ . '/../modules/mcp/class-mcp-module.php';
if ( file_exists( $mcp_module_file ) ) {
	require_once $mcp_module_file;
}

$v5_installer_file = __DIR__ . '/../modules/class-v5-installer.php';
if ( file_exists( $v5_installer_file ) ) {
	require_once $v5_installer_file;
}

foreach ( array( 'class-module-registry.php', 'class-install-profiles.php', 'class-profile-labels.php' ) as $mod_file ) {
	$path = __DIR__ . '/../includes/modularity/' . $mod_file;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}
