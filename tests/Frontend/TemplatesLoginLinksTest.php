<?php

declare( strict_types = 1 );

namespace ATORA\Tests\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Las plantillas del frontend deben enlazar el login vía
 * CLMS_Frontend_URLs::login_url() (→ /cuenta/ si existe), no wp_login_url().
 */
final class TemplatesLoginLinksTest extends TestCase {
	public function test_templates_do_not_call_wp_login_url_directly(): void {
		$root      = dirname( __DIR__, 2 ) . '/templates';
		$offenders = array();

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $i => $line ) {
				if ( str_contains( $line, 'wp_login_url(' ) ) {
					$offenders[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $i + 1 );
				}
			}
		}

		$this->assertSame( array(), $offenders );
	}
}
