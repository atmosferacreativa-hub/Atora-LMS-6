<?php
/**
 * WP-CLI: `wp atora version`
 *
 * @package ATORA_LMS
 * @since 6.26.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

final class ATORA_Version_CLI {

	public static function init(): void {
		\WP_CLI::add_command( 'atora version', array( __CLASS__, 'command' ) );
	}

	/**
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public static function command( array $args, array $assoc_args ): void {
		if ( ! class_exists( 'ATORA_Build_Info' ) ) {
			\WP_CLI::error( 'Build info no disponible.' );
			return;
		}

		$info = ATORA_Build_Info::get();
		$dirty = $info['dirty'] ?? null;
		$dirty_label = null === $dirty ? 'unknown' : ( $dirty ? 'dirty' : 'clean' );
		$dirty_suffix = 'unknown' === $dirty_label ? ', dirty: ?' : ( ', ' . $dirty_label );
		$build_stale = ! empty( $info['build_stale'] ) ? ', build_stale' : '';
		$line = sprintf(
			'%s %s (%s%s%s)',
			$info['version'] ?? '',
			$info['commit_short'] ?? '',
			$info['origin'] ?? '',
			$dirty_suffix,
			$build_stale
		);

		\WP_CLI::log( $line );
		\WP_CLI::log( wp_json_encode( $info ) );
	}
}
