<?php

namespace ATORA\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/delegation/class-delegation-caps.php';

final class Test_Delegation_Caps extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_map_meta_cap_no_post_id_keeps_caps(): void {
		$out = \ATORA_Delegation_Caps::map_delegated_caps( array( 'edit_lm_courses' ), 'edit_post', 10, array() );
		$this->assertSame( array( 'edit_lm_courses' ), $out );
	}

	public function test_map_meta_cap_delegated_rewrites_others_caps(): void {
		$post = (object) array( 'ID' => 123, 'post_type' => 'lm_course' );

		Functions\when( 'get_post' )->alias( function( $id ) use ( $post ) {
			return ( absint( $id ) === 123 ) ? $post : null;
		} );

		if ( ! class_exists( '\\ATORA_Delegation_Service' ) ) {
			eval( 'class ATORA_Delegation_Service { public static function covers( int $user_id, $post_or_course, string $perm = \"content\" ): bool { return true; } }' );
		}

		$out = \ATORA_Delegation_Caps::map_delegated_caps(
			array( 'edit_others_lm_courses', 'edit_published_lm_courses' ),
			'edit_post',
			10,
			array( 123 )
		);

		$this->assertSame( array( 'edit_lm_courses', 'edit_published_lm_courses' ), $out );
	}
}

