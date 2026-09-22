<?php
/**
 * Build info — lee commit desde packed-refs y soporta ".git" como archivo (worktree).
 *
 * @package ATORA_LMS\Tests\Build
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Build;

use PHPUnit\Framework\TestCase;

final class BuildInfoGitPackedRefsTest extends TestCase {

	/** @test */
	public function resolves_commit_from_packed_refs_when_ref_file_missing(): void {
		require_once __DIR__ . '/../../includes/class-build-info.php';

		$root = sys_get_temp_dir() . '/atora-buildinfo-' . wp_generate_uuid4();
		mkdir( $root, 0777, true );
		mkdir( $root . '/.git', 0777, true );

		file_put_contents( $root . '/.git/HEAD', "ref: refs/heads/main\n" );
		file_put_contents( $root . '/.git/packed-refs', "# pack-refs with: peeled fully-peeled\n0123456789012345678901234567890123456789 refs/heads/main\n" );

		// Archivo plugin requerido para el escaneo mtime (no importa su contenido aquí).
		file_put_contents( $root . '/atora_lms.php', "<?php\n" );
		touch( $root . '/atora_lms.php', time() - 10 );
		file_put_contents( $root . '/.git/index', '' );
		touch( $root . '/.git/index', time() );

		$info = $this->invoke_read_git_info( $root . '/' );
		$this->assertSame( '0123456789012345678901234567890123456789', $info['commit'] );
		$this->assertSame( '0123456', $info['commit_short'] );
		$this->assertSame( 'main', $info['branch'] );
	}

	/** @test */
	public function supports_git_as_file_pointing_to_gitdir(): void {
		require_once __DIR__ . '/../../includes/class-build-info.php';

		$root = sys_get_temp_dir() . '/atora-buildinfo-wt-' . wp_generate_uuid4();
		$gitdir = $root . '/actual-git-dir';
		mkdir( $root, 0777, true );
		mkdir( $gitdir, 0777, true );

		file_put_contents( $root . '/.git', "gitdir: actual-git-dir\n" );
		file_put_contents( $gitdir . '/HEAD', "0123456789012345678901234567890123456789\n" );
		file_put_contents( $gitdir . '/index', '' );

		file_put_contents( $root . '/atora_lms.php', "<?php\n" );

		$info = $this->invoke_read_git_info( $root . '/' );
		$this->assertSame( '0123456789012345678901234567890123456789', $info['commit'] );
	}

	private function invoke_read_git_info( string $plugin_dir ): array {
		$ref = new \ReflectionClass( \ATORA_Build_Info::class );
		$m = $ref->getMethod( 'read_git_info' );
		$m->setAccessible( true );
		return (array) $m->invoke( null, $plugin_dir );
	}
}

