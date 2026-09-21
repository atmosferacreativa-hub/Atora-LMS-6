<?php
/**
 * ATORA LMS — Build info (version sello).
 *
 * @package ATORA_LMS
 * @since 6.26.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Build_Info {

	private const BUILD_INFO_FILE = 'build-info.json';

	/**
	 * Devuelve el sello de versión. Si hay un checkout (".git" legible),
	 * prefiere Git para obtener el commit, y usa build-info.json como fallback.
	 *
	 * @return array{version:string,commit:string,commit_short:string,branch:string,tag:string,built_at:string,dirty:bool,origin:string}
	 */
	public static function get(): array {
		$version = defined( 'ATORA_LMS_VERSION' ) ? (string) ATORA_LMS_VERSION : '';
		$plugin_dir = defined( 'ATORA_LMS_DIR' ) ? (string) ATORA_LMS_DIR : plugin_dir_path( __FILE__ );

		$from_build = self::read_build_info( $plugin_dir );
		$from_git   = self::read_git_info( $plugin_dir );

		$origin = $from_git ? 'git' : 'build';
		$commit = $from_git['commit'] ?? (string) ( $from_build['commit'] ?? '' );
		$commit_short = $from_git['commit_short'] ?? (string) ( $from_build['commit_short'] ?? ( '' !== $commit ? substr( $commit, 0, 7 ) : '' ) );
		$branch = $from_git['branch'] ?? (string) ( $from_build['branch'] ?? '' );
		$tag    = (string) ( $from_build['tag'] ?? '' );
		$built_at = (string) ( $from_build['built_at'] ?? '' );

		// "dirty" significa: el árbol de archivos no coincide con el sello de build.
		// En un checkout montado, build-info.json puede estar obsoleto: eso es
		// justamente el dato que queremos ver (no mentir).
		$dirty = (bool) ( $from_build['dirty'] ?? false );
		if ( 'git' === $origin && '' !== $commit ) {
			$dirty = isset( $from_build['commit_short'] ) && (string) $from_build['commit_short'] !== (string) $commit_short;
		}

		return array(
			'version'      => $version,
			'commit'       => (string) $commit,
			'commit_short' => (string) $commit_short,
			'branch'       => (string) $branch,
			'tag'          => (string) $tag,
			'built_at'     => (string) $built_at,
			'dirty'        => (bool) $dirty,
			'origin'       => (string) $origin,
		);
	}

	private static function read_build_info( string $plugin_dir ): array {
		$file = trailingslashit( $plugin_dir ) . self::BUILD_INFO_FILE;
		if ( ! file_exists( $file ) ) {
			return array();
		}

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === trim( (string) $raw ) ) {
			return array();
		}

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Lee commit y branch desde .git sin ejecutar comandos.
	 *
	 * @param string $plugin_dir
	 * @return array{commit?:string,commit_short?:string,branch?:string}|array{}
	 */
	private static function read_git_info( string $plugin_dir ): array {
		$git_dir = trailingslashit( $plugin_dir ) . '.git';
		$head_file = $git_dir . '/HEAD';
		if ( ! is_dir( $git_dir ) || ! is_readable( $head_file ) ) {
			return array();
		}

		$head = trim( (string) file_get_contents( $head_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $head ) {
			return array();
		}

		$commit = '';
		$branch = '';
		if ( str_starts_with( $head, 'ref:' ) ) {
			$ref = trim( (string) substr( $head, 4 ) );
			$branch = str_starts_with( $ref, 'refs/heads/' ) ? substr( $ref, strlen( 'refs/heads/' ) ) : $ref;
			$ref_file = $git_dir . '/' . $ref;
			if ( is_readable( $ref_file ) ) {
				$commit = trim( (string) file_get_contents( $ref_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		} else {
			// Detached HEAD contiene el hash directamente.
			$commit = $head;
		}

		$commit = preg_match( '/^[0-9a-f]{40}$/i', $commit ) ? $commit : '';
		if ( '' === $commit ) {
			return array();
		}

		return array(
			'commit'       => $commit,
			'commit_short' => substr( $commit, 0, 7 ),
			'branch'       => (string) $branch,
		);
	}
}

