<?php
/**
 * Admin menu — contadores de contenido (B.3).
 *
 * @package ATORA_LMS\Tests\AdminMenu
 */

declare( strict_types = 1 );

namespace ATORA\Tests\AdminMenu;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CountPostsByTypeTest extends TestCase {

	/** @test */
	public function test_count_posts_by_type_can_sum_explicit_statuses_without_counting_trash_or_drafts(): void {
		Functions\when( 'wp_count_posts' )->alias(
			static function( string $post_type ) {
				$map = array(
					'lm_program'      => (object) array(
						'publish'    => 1,
						'private'    => 2,
						'draft'      => 1,
						'trash'      => 3,
						'auto-draft' => 7,
					),
					'lm_course'       => (object) array(
						'publish' => 10,
						'draft'   => 4,
						'trash'   => 1,
					),
					'lm_cohort'       => (object) array( 'publish' => 7 ),
					'lm_lesson'       => (object) array( 'publish' => 99, 'draft' => 1 ),
					'clms_submission' => (object) array( 'publish' => 5, 'trash' => 2 ),
				);
				return $map[ $post_type ] ?? null;
			}
		);

		$harness = new class {
			use \CLMS_Admin_Menu_Navigation_Trait;

			public function count( string $post_type, ?array $statuses = null ): int {
				return (int) $this->count_posts_by_type( $post_type, $statuses );
			}

			public function role_metrics( string $role_context, int $user_id ): array {
				return (array) $this->get_role_summary_metrics( $role_context, $user_id );
			}

			public function analytics_cards( string $role_context, int $user_id ): array {
				return (array) $this->get_analytics_cards( $role_context, $user_id );
			}
		};

		// Explicit statuses: only "publicables" (publish+private).
		$this->assertSame( 3, $harness->count( 'lm_program', array( 'publish', 'private' ) ) );
		$this->assertSame( 1, $harness->count( 'lm_program', array( 'publish' ) ) );
		$this->assertSame( 10, $harness->count( 'lm_course', array( 'publish', 'private' ) ) );

		// Back-compat: without explicit statuses it sums everything wp_count_posts() returns.
		$this->assertSame( 14, $harness->count( 'lm_program' ) );

		$metrics = $harness->role_metrics( 'admin', 1 );
		$by_label = array();
		foreach ( $metrics as $item ) {
			$by_label[ (string) ( $item['label'] ?? '' ) ] = (int) ( $item['value'] ?? 0 );
		}
		$this->assertSame( 3, $by_label['Programas'] ?? null );
		$this->assertSame( 10, $by_label['Cursos'] ?? null );

		$cards = $harness->analytics_cards( 'admin', 1 );
		$by_label = array();
		foreach ( $cards as $item ) {
			$by_label[ (string) ( $item['label'] ?? '' ) ] = (int) ( $item['value'] ?? 0 );
		}
		$this->assertSame( 3, $by_label['Programas'] ?? null );
		$this->assertSame( 10, $by_label['Cursos'] ?? null );
	}
}

