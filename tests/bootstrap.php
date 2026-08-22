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
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
	}
}
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
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $id ) {
		return (object) array( 'ID' => $id, 'display_name' => 'Test User ' . $id, 'user_email' => 'user' . $id . '@example.test' );
	}
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
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$id  = is_object( $post ) ? absint( $post->ID ?? 0 ) : absint( $post );
		$row = $GLOBALS['__atora_test_posts'][ $id ] ?? null;
		return $row->post_title ?? '';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? absint( $post->ID ?? 0 ) : absint( $post );
		return 'https://example.test/?p=' . $id;
	}
}
if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( string $field, $user_id = 0 ) {
		return 'display_name' === $field ? ( 'User ' . absint( $user_id ) ) : '';
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, $post = 0 ) {
		$id   = is_object( $post ) ? absint( $post->ID ?? 0 ) : absint( $post );
		$row  = $GLOBALS['__atora_test_posts'][ $id ] ?? null;
		return $row[ $field ] ?? '';
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
// PT-1 (6.5.7): WP_Query mínima sobre el mismo store que
// atora_test_set_post()/get_post() — filtra por post_type/post_status
// (con soporte de array de estados)/author/post__in, suficiente para
// probar get_courses()/get_programs()/get_lessons() sin una BD real.
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = array();
		public int $found_posts = 0;
		public int $max_num_pages = 1;

		public function __construct( array $args = array() ) {
			$all       = $GLOBALS['__atora_test_posts'] ?? array();
			$post_type = $args['post_type'] ?? '';
			$status    = $args['post_status'] ?? 'publish';
			$statuses  = is_array( $status ) ? $status : array( $status );
			$author    = isset( $args['author'] ) ? absint( $args['author'] ) : 0;
			$post_in   = isset( $args['post__in'] ) && is_array( $args['post__in'] )
				? array_map( 'absint', $args['post__in'] )
				: null;

			$matched = array();
			foreach ( $all as $id => $row ) {
				if ( ( $row->post_type ?? '' ) !== $post_type ) { continue; }
				if ( ! in_array( $row->post_status ?? 'publish', $statuses, true ) ) { continue; }
				if ( $author && absint( $row->post_author ?? 0 ) !== $author ) { continue; }
				if ( null !== $post_in && ! in_array( absint( $id ), $post_in, true ) ) { continue; }
				$matched[] = $row;
			}

			$this->posts       = $matched;
			$this->found_posts = count( $matched );
			$this->max_num_pages = 1;
		}
	}
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
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ): bool {
		$GLOBALS['__atora_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		unset( $GLOBALS['__atora_test_post_meta'][ $post_id ][ $key ] );
		return true;
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

// PT-1 (6.5.5): nonces — un set de nonces "válidos" configurable por
// test, para poder probar el orden de validación de handle_submit().
$GLOBALS['__atora_test_valid_nonces'] = array();
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ): string {
		$nonce = 'test-nonce-' . md5( (string) $action );
		$GLOBALS['__atora_test_valid_nonces'][ $nonce ] = (string) $action;
		return $nonce;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ): bool {
		$nonce = (string) $nonce;
		return isset( $GLOBALS['__atora_test_valid_nonces'][ $nonce ] )
			&& $GLOBALS['__atora_test_valid_nonces'][ $nonce ] === (string) $action;
	}
}
if ( ! function_exists( 'atora_test_reset_nonces' ) ) {
	function atora_test_reset_nonces(): void { $GLOBALS['__atora_test_valid_nonces'] = array(); }
}

// PT-3 (6.5.5): passwords de enlace de acceso — hash simulado estable
// (no bcrypt real, no hace falta para probar la lógica de rate limit).
if ( ! function_exists( 'wp_hash_password' ) ) {
	function wp_hash_password( string $password ): string { return 'hashed:' . $password; }
}
if ( ! function_exists( 'wp_check_password' ) ) {
	function wp_check_password( string $password, string $hash, $user_id = '' ): bool { return $hash === 'hashed:' . $password; }
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) { return 1; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $args = 1 ): bool { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $priority = 10, $args = 1 ): bool { return true; }
}

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

$client_ip_file = __DIR__ . '/../includes/class-atora-client-ip.php';
if ( file_exists( $client_ip_file ) ) {
	require_once $client_ip_file;
}

$rate_limiter_file = __DIR__ . '/../includes/class-atora-rate-limiter.php';
if ( file_exists( $rate_limiter_file ) ) {
	require_once $rate_limiter_file;
}

$forms_builder_file = __DIR__ . '/../modules/analytics/class-forms-builder.php';
if ( file_exists( $forms_builder_file ) ) {
	require_once $forms_builder_file;
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

$legacy_rest_permissions_file = __DIR__ . '/../includes/rest/class-rest-permissions.php';
if ( file_exists( $legacy_rest_permissions_file ) ) {
	require_once $legacy_rest_permissions_file;
}

$clms_access_file = __DIR__ . '/../includes/class-access.php';
if ( file_exists( $clms_access_file ) ) {
	require_once $clms_access_file;
}

$legacy_rest_academics_controller_file = __DIR__ . '/../includes/rest/class-rest-academics-controller.php';
if ( file_exists( $legacy_rest_academics_controller_file ) ) {
	require_once $legacy_rest_academics_controller_file;
}

$campaign_builder_rest_file = __DIR__ . '/../modules/crm-v2/rest/class-campaign-builder-rest-controller.php';
if ( file_exists( $campaign_builder_rest_file ) ) {
	require_once $campaign_builder_rest_file;
}

$grading_engine_file = __DIR__ . '/../includes/class-clms-grading-engine.php';
if ( file_exists( $grading_engine_file ) ) {
	require_once $grading_engine_file;
}

$legacy_rest_grading_controller_file = __DIR__ . '/../includes/rest/class-rest-grading-controller.php';
if ( file_exists( $legacy_rest_grading_controller_file ) ) {
	require_once $legacy_rest_grading_controller_file;
}

$enrollment_manager_file = __DIR__ . '/../includes/class-enrollment-manager.php';
if ( file_exists( $enrollment_manager_file ) ) {
	require_once $enrollment_manager_file;
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
