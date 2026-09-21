<?php
/**
 * Delegation caps: map_meta_cap rewrite.
 *
 * @package ATORA_LMS\Tests\Delegation
 */

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/../includes/delegation/class-delegation-caps.php';

	if ( ! class_exists( 'ATORA_Delegation_Service' ) ) {
		final class ATORA_Delegation_Service {
			public static function covers( int $user_id, $post_or_course, string $perm = 'content' ): bool {
				unset( $user_id, $post_or_course, $perm );
				return true;
			}
		}
	}
}

namespace ATORA\Tests\Delegation {

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DelegationCapsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_filters();
		atora_test_reset_posts();
	}

	/** @test */
	public function it_keeps_caps_when_no_post_id(): void {
		$out = \ATORA_Delegation_Caps::map_delegated_caps( array( 'edit_lm_courses' ), 'edit_post', 10, array() );
		$this->assertSame( array( 'edit_lm_courses' ), $out );
	}

	/** @test */
	public function it_rewrites_others_caps_when_delegated(): void {
		$post = (object) array( 'ID' => 123, 'post_type' => 'lm_course' );

		Functions\when( 'get_post' )->alias( static function( $id ) use ( $post ) {
			return ( absint( $id ) === 123 ) ? $post : null;
		} );

		$out = \ATORA_Delegation_Caps::map_delegated_caps(
			array( 'edit_others_lm_courses', 'edit_published_lm_courses' ),
			'edit_post',
			10,
			array( 123 )
		);

		$this->assertSame( array( 'edit_lm_courses', 'edit_published_lm_courses' ), $out );
	}
}

