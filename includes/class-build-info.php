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
	private const DIRTY_TRANSIENT_TTL = 60;

	/**
	 * Devuelve el sello de versión. Si hay un checkout (".git" legible),
	 * prefiere Git para obtener el commit, y usa build-info.json como fallback.
	 *
	 * @return array{version:string,commit:string,commit_short:string,branch:string,tag:string,built_at:string,dirty:bool|null,dirty_method:string,build_stale:bool,origin:string}
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
		$tag    = $from_git['tag'] ?? (string) ( $from_build['tag'] ?? '' );
		$built_at = (string) ( $from_build['built_at'] ?? '' );

		$build_stale = false;
		if ( 'git' === $origin && '' !== (string) ( $from_build['commit_short'] ?? '' ) && '' !== (string) $commit_short ) {
			$build_stale = (string) $from_build['commit_short'] !== (string) $commit_short;
		}

		$dirty        = null;
		$dirty_method = 'unknown';
		if ( 'build' === $origin ) {
			$dirty = isset( $from_build['dirty'] ) ? (bool) $from_build['dirty'] : null;
			$dirty_method = isset( $from_build['dirty'] ) ? 'build' : 'unknown';
		} elseif ( 'git' === $origin && '' !== $commit ) {
			$cached = self::get_dirty_cache( (string) $commit_short );
			if ( is_array( $cached ) ) {
				$dirty = $cached['dirty'];
				$dirty_method = (string) $cached['dirty_method'];
			} else {
				$dirty_probe = self::probe_dirty_by_mtime( $plugin_dir, (string) ( $from_git['git_dir'] ?? '' ) );
				if ( null !== $dirty_probe ) {
					$dirty = $dirty_probe;
					$dirty_method = 'mtime';
				}
				self::set_dirty_cache( (string) $commit_short, $dirty, $dirty_method );
			}
		}

		return array(
			'version'      => $version,
			'commit'       => (string) $commit,
			'commit_short' => (string) $commit_short,
			'branch'       => (string) $branch,
			'tag'          => (string) $tag,
			'built_at'     => (string) $built_at,
			'dirty'        => $dirty,
			'dirty_method' => (string) $dirty_method,
			'build_stale'  => (bool) $build_stale,
			'origin'       => (string) $origin,
		);
	}

	private static function read_build_info( string $plugin_dir ): array {
		$file = self::slash( $plugin_dir ) . self::BUILD_INFO_FILE;
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
		$git_dir = self::resolve_git_dir( $plugin_dir );
		if ( '' === $git_dir ) {
			return array();
		}

		$head_file = self::slash( $git_dir ) . 'HEAD';
		if ( ! is_readable( $head_file ) ) {
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
			$commit = self::read_ref_hash( $git_dir, $ref );
		} else {
			// Detached HEAD contiene el hash directamente.
			$commit = $head;
		}

		$commit = preg_match( '/^[0-9a-f]{40}$/i', $commit ) ? $commit : '';
		if ( '' === $commit ) {
			return array();
		}

		$tag = self::resolve_tag_for_commit( $git_dir, $commit );

		return array(
			'commit'       => $commit,
			'commit_short' => substr( $commit, 0, 7 ),
			'branch'       => (string) $branch,
			'tag'          => (string) $tag,
			'git_dir'      => (string) $git_dir,
		);
	}

	private static function resolve_git_dir( string $plugin_dir ): string {
		$path = self::slash( $plugin_dir ) . '.git';

		// Caso normal: .git es un directorio.
		if ( is_dir( $path ) ) {
			return $path;
		}

		// Worktree/submódulo: .git es un archivo con "gitdir: <ruta>".
		if ( is_file( $path ) && is_readable( $path ) ) {
			$raw = trim( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( str_starts_with( $raw, 'gitdir:' ) ) {
				$gitdir = trim( (string) substr( $raw, strlen( 'gitdir:' ) ) );
				if ( '' !== $gitdir ) {
					$resolved = $gitdir;
					if ( ! str_starts_with( $resolved, '/' ) ) {
						$resolved = self::slash( $plugin_dir ) . $resolved;
					}
					$resolved = rtrim( (string) $resolved, '/' ) . '/';
					if ( is_dir( $resolved ) ) {
						return $resolved;
					}
				}
			}
		}

		return '';
	}

	private static function read_ref_hash( string $git_dir, string $ref ): string {
		$ref = ltrim( $ref, '/' );
		$ref_file = self::slash( $git_dir ) . $ref;
		if ( is_readable( $ref_file ) ) {
			return trim( (string) file_get_contents( $ref_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		// Fallback: refs empaquetados en packed-refs.
		$packed = self::slash( $git_dir ) . 'packed-refs';
		if ( ! is_readable( $packed ) ) {
			return '';
		}
		$lines = file( $packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
		if ( ! is_array( $lines ) ) {
			return '';
		}
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || str_starts_with( $line, '^' ) ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $line, 2 );
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				continue;
			}
			if ( (string) $parts[1] === $ref ) {
				return (string) $parts[0];
			}
		}
		return '';
	}

	private static function resolve_tag_for_commit( string $git_dir, string $commit ): string {
		$tags_dir = self::slash( $git_dir ) . 'refs/tags';
		if ( is_dir( $tags_dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $tags_dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				/** @var \SplFileInfo $file */
				if ( ! $file->isFile() ) {
					continue;
				}
				$hash = trim( (string) file_get_contents( $file->getPathname() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( $hash === $commit ) {
					$rel = str_replace( $tags_dir . '/', '', $file->getPathname() );
					return (string) $rel;
				}
			}
		}

		$packed = self::slash( $git_dir ) . 'packed-refs';
		if ( ! is_readable( $packed ) ) {
			return '';
		}
		$lines = file( $packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file
		if ( ! is_array( $lines ) ) {
			return '';
		}
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || str_starts_with( $line, '^' ) ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $line, 2 );
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				continue;
			}
			if ( (string) $parts[0] === $commit && str_starts_with( (string) $parts[1], 'refs/tags/' ) ) {
				return substr( (string) $parts[1], strlen( 'refs/tags/' ) );
			}
		}

		return '';
	}

	private static function probe_dirty_by_mtime( string $plugin_dir, string $git_dir ): ?bool {
		if ( '' === $git_dir ) {
			return null;
		}
		$index = self::slash( $git_dir ) . 'index';
		if ( ! is_readable( $index ) ) {
			return null;
		}
		$index_mtime = @filemtime( $index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $index_mtime ) {
			return null;
		}

		$max_mtime = 0;
		$excludes = array(
			DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
			DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
			DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR,
			DIRECTORY_SEPARATOR . '.phpunit.cache' . DIRECTORY_SEPARATOR,
			DIRECTORY_SEPARATOR . '.phpunit.result.cache',
			DIRECTORY_SEPARATOR . self::BUILD_INFO_FILE,
		);

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $plugin_dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			/** @var \SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = $file->getPathname();
			if ( false !== strpos( $path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			foreach ( $excludes as $ex ) {
				if ( false !== strpos( $path, $ex ) ) {
					continue 2;
				}
			}
			$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'php', 'js', 'css', 'json', 'html' ), true ) ) {
				continue;
			}
			$mtime = $file->getMTime();
			if ( $mtime > $max_mtime ) {
				$max_mtime = $mtime;
			}
			if ( $max_mtime > (int) $index_mtime ) {
				return true;
			}
		}

		return false;
	}

	private static function get_dirty_cache( string $commit_short ): ?array {
		if ( '' === $commit_short || ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$key = 'atora_build_dirty_' . preg_replace( '/[^a-z0-9_]/i', '', $commit_short );
		$raw = get_transient( $key );
		if ( ! is_array( $raw ) || ! array_key_exists( 'dirty', $raw ) ) {
			return null;
		}
		return $raw;
	}

	private static function set_dirty_cache( string $commit_short, $dirty, string $dirty_method ): void {
		if ( '' === $commit_short || ! function_exists( 'set_transient' ) ) {
			return;
		}
		$key = 'atora_build_dirty_' . preg_replace( '/[^a-z0-9_]/i', '', $commit_short );
		set_transient( $key, array(
			'dirty'        => is_bool( $dirty ) ? $dirty : null,
			'dirty_method' => $dirty_method,
		), self::DIRTY_TRANSIENT_TTL );
	}

	private static function slash( string $path ): string {
		return rtrim( $path, '/' ) . '/';
	}
}
