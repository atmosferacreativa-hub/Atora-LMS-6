<?php
/**
 * Avatar Service — smoke test para compatibilidad con WordPress core.
 *
 * WordPress 6.8+/7.x accede a múltiples claves del array de args que retorna
 * `pre_get_avatar_data`. Si el filtro devuelve un array incompleto, PHP 8.1+
 * emite warnings `Undefined array key` desde `wp-includes/pluggable.php`.
 *
 * @package ATORA_LMS\Tests\Assets
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class AvatarServiceTest extends TestCase {

	/** @test */
	public function test_pre_get_avatar_data_includes_core_keys(): void {
		if ( ! class_exists( 'ATORA_Avatar_Service' ) ) {
			require_once __DIR__ . '/../../includes/avatar/class-avatar-service.php';
		}
		$this->assertTrue( class_exists( 'ATORA_Avatar_Service' ) );

		$args = \ATORA_Avatar_Service::pre_get_avatar_data( array( 'size' => 96 ), 0 );
		$this->assertIsArray( $args );

		foreach ( array( 'force_default', 'loading', 'fetchpriority', 'decoding', 'alt', 'height', 'width', 'default' ) as $key ) {
			$this->assertArrayHasKey( $key, $args, "Debe incluir la clave core: {$key}" );
		}
		$this->assertArrayHasKey( 'processed_args', $args );
		$this->assertIsArray( $args['processed_args'] );
	}
}

