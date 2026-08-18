<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_Cache — utilitario ligero de transients con versionado por scope.
 */
class CLMS_Cache {

	const VERSION_PREFIX = 'clms_cache_version_';
	const OBJECT_CACHE_DROPIN = 'object-cache.php';

	public function __construct() {
		add_action( 'save_post_lm_course', array( $this, 'handle_course_update' ), 10, 3 );
		add_action( 'save_post_lm_program', array( $this, 'handle_program_update' ), 10, 3 );
	}

	public function handle_course_update( $post_id, $post, $update ) {
		unset( $post );

		if ( ! $update || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		self::flush_course( absint( $post_id ) );
	}

	public function handle_program_update( $post_id, $post, $update ) {
		unset( $post );

		if ( ! $update || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		self::flush_program( absint( $post_id ) );
	}

	public static function get( $scope, array $parts = array(), $default = null ) {
		$key   = self::build_key( $scope, $parts );
		$value = get_transient( $key );

		return false === $value ? $default : $value;
	}

	public static function set( $scope, array $parts, $value, $ttl ) {
		$key = self::build_key( $scope, $parts );
		return set_transient( $key, $value, (int) $ttl );
	}

	public static function delete( $scope, array $parts = array() ) {
		$key = self::build_key( $scope, $parts );
		return delete_transient( $key );
	}

	public static function flush_student( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		self::delete( 'dashboard', array( $user_id ) );
		self::bump_version( 'gradebook' );
	}

	public static function flush_course( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return;
		}

		self::bump_version( 'dashboard' );
		self::bump_version( 'gradebook' );
		self::bump_version( 'analytics' );
	}

	public static function flush_program( $program_id ) {
		$program_id = absint( $program_id );
		if ( ! $program_id ) {
			return;
		}

		self::bump_version( 'dashboard' );
		self::bump_version( 'analytics' );
	}

	public static function get_version( $scope ) {
		$scope   = self::sanitize_scope( $scope );
		$version = get_option( self::VERSION_PREFIX . $scope, 1 );
		$version = is_numeric( $version ) ? (int) $version : 1;

		return $version > 0 ? $version : 1;
	}

	public static function bump_version( $scope ) {
		$scope = self::sanitize_scope( $scope );
		update_option( self::VERSION_PREFIX . $scope, time(), false );
	}

	public static function build_key( $scope, array $parts = array() ) {
		$scope  = self::sanitize_scope( $scope );
		$pieces = array( 'clms', $scope );

		foreach ( $parts as $part ) {
			$part = self::normalize_part( $part );
			if ( '' !== $part ) {
				$pieces[] = $part;
			}
		}

		$pieces[] = self::get_version( $scope );

		return implode( '_', $pieces );
	}

	protected static function sanitize_scope( $scope ) {
		$scope = sanitize_key( (string) $scope );
		return $scope ? $scope : 'core';
	}

	protected static function normalize_part( $value ) {
		if ( is_numeric( $value ) ) {
			return (string) absint( $value );
		}

		$value = sanitize_key( (string) $value );
		return $value;
	}

	public static function get_object_cache_status() {
		$enabled = function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : false;
		$driver  = 'none';

		if ( $enabled ) {
			if ( defined( 'WP_REDIS_VERSION' ) || defined( 'WP_REDIS_PATH' ) || class_exists( 'Redis' ) ) {
				$driver = 'redis';
			} elseif ( class_exists( 'Memcached' ) || class_exists( 'Memcache' ) ) {
				$driver = 'memcached';
			} else {
				$driver = 'external';
			}
		}

		$dropin_path = defined( 'WP_CONTENT_DIR' ) ? trailingslashit( WP_CONTENT_DIR ) . self::OBJECT_CACHE_DROPIN : '';

		return array(
			'enabled'     => (bool) $enabled,
			'driver'      => $driver,
			'dropin_path' => $dropin_path && file_exists( $dropin_path ) ? $dropin_path : '',
		);
	}
}
