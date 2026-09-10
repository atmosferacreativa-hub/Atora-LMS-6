<?php
/**
 * Groups + Submissions — hardening de fetch + adjuntos compartidos.
 *
 * @package ATORA_LMS\Tests\Groups
 */

declare( strict_types = 1 );

namespace {
	// Stubs mínimos para CLMS_Helper usados por los traits.
	if ( ! class_exists( 'CLMS_Helper' ) ) {
		final class CLMS_Helper {
			public static function user_can_manage_lms( $post_id = 0 ): bool {
				unset( $post_id );
				return false;
			}
			public static function get_course_id_from_lesson( int $lesson_id ): int {
				return absint( get_post_meta( $lesson_id, '_clms_lesson_course_id', true ) );
			}
			public static function get_lesson_course_id( int $lesson_id ): int {
				return self::get_course_id_from_lesson( $lesson_id );
			}
		}
	}
}

namespace ATORA\Tests\Groups {

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class GroupSubmissionFetchTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_filters();
		atora_test_reset_post_meta();
		atora_test_reset_posts();
	}

	/** @test */
	public function it_fetches_shadow_for_current_group_master_and_ignores_old_group(): void {
		require_once __DIR__ . '/../../modules/groups/class-group-service.php';
		require_once __DIR__ . '/../../includes/submission/trait-submission-files-assets.php';
		require_once __DIR__ . '/../../includes/submission/trait-submission-storage-review.php';

		$lesson_id = 100;
		$course_id = 300;
		$user_id   = 10;
		$group_id  = 2;
		$master_id = 200;
		$shadow_id = 202;

		atora_test_set_post( $lesson_id, array( 'post_type' => 'lm_lesson' ) );
		atora_test_set_post_meta( $lesson_id, '_clms_evaluation_mode', 'group' );
		atora_test_set_post_meta( $lesson_id, '_clms_lesson_course_id', $course_id );

		add_filter(
			'atora/groups/user_group_id',
			static function( $value, $uid, $cid, $lid ) use ( $user_id, $course_id, $lesson_id, $group_id ) {
				if ( absint( $uid ) === $user_id && absint( $cid ) === $course_id && absint( $lid ) === $lesson_id ) {
					return $group_id;
				}
				return $value;
			},
			10,
			4
		);

		// Stub get_posts() para resolver master + shadow correctos.
		Functions\when( 'get_posts' )->alias(
			static function( array $args ) use ( $master_id, $shadow_id ) {
				$meta_query = $args['meta_query'] ?? array();
				$keys = array();
				foreach ( (array) $meta_query as $q ) {
					if ( is_array( $q ) && isset( $q['key'] ) ) {
						$keys[ (string) $q['key'] ] = $q;
					}
				}

				// master lookup.
				if ( isset( $keys['_clms_submission_group_master'], $keys['_clms_submission_group_id'], $keys['_clms_submission_lesson_id'] ) ) {
					return array( $master_id );
				}

				// shadow lookup by Group_Service::find_shadow_submission_id().
				if ( isset( $keys['_clms_submission_is_shadow'], $keys['_clms_submission_group_master_id'] ) ) {
					return array( $shadow_id );
				}

				return array();
			}
		);

		// Seed shadow meta to be returned by get_user_submission_for_grading().
		atora_test_set_post( $shadow_id, array( 'post_type' => 'clms_submission', 'post_status' => 'private' ) );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_user_id', $user_id );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_lesson_id', $lesson_id );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_group_id', $group_id );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_group_master_id', $master_id );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_status', 'submitted' );

		// Dummy minimal para exponer get_user_submission_for_grading().
		$dummy = new class {
			use \CLMS_Submission_Files_Assets_Trait;
			use \CLMS_Submission_Storage_Review_Trait;
			public const CPT = 'clms_submission';
			protected int $max_files = 5;
			protected int $max_file_size = 1048576;
			protected array $allowed_mimes = array( 'pdf' => 'application/pdf' );
		};

		$out = $dummy->get_user_submission_for_grading( $user_id, $lesson_id );
		$this->assertSame( $shadow_id, absint( $out['submission_id'] ?? 0 ) );
	}

	/** @test */
	public function it_allows_group_member_to_view_attachment_owned_by_other_member(): void {
		require_once __DIR__ . '/../../includes/submission/trait-submission-files-assets.php';
		require_once __DIR__ . '/../../includes/submission/trait-submission-storage-review.php';

		$user_id      = 10;
		$lesson_id    = 100;
		$course_id    = 300;
		$group_id     = 2;
		$master_id    = 200;
		$shadow_id    = 202;
		$attachment_id = 501;

		atora_test_set_post( $lesson_id, array( 'post_type' => 'lm_lesson' ) );
		atora_test_set_post_meta( $lesson_id, '_clms_lesson_course_id', $course_id );

		// Master submission meta referenced by attachment.
		atora_test_set_post( $master_id, array( 'post_type' => 'clms_submission', 'post_status' => 'publish' ) );
		atora_test_set_post_meta( $master_id, '_clms_submission_group_master', '1' );
		atora_test_set_post_meta( $master_id, '_clms_submission_group_id', $group_id );
		atora_test_set_post_meta( $master_id, '_clms_submission_lesson_id', $lesson_id );
		atora_test_set_post_meta( $master_id, '_clms_submission_course_id', $course_id );

		// Shadow submission linking the student to the master.
		atora_test_set_post( $shadow_id, array( 'post_type' => 'clms_submission', 'post_status' => 'private' ) );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_is_shadow', '1' );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_user_id', $user_id );
		atora_test_set_post_meta( $shadow_id, '_clms_submission_group_master_id', $master_id );

		Functions\when( 'get_posts' )->alias(
			static function( array $args ) use ( $shadow_id ) {
				$meta_query = $args['meta_query'] ?? array();
				$keys = array();
				foreach ( (array) $meta_query as $q ) {
					if ( is_array( $q ) && isset( $q['key'] ) ) {
						$keys[ (string) $q['key'] ] = $q;
					}
				}
				if ( isset( $keys['_clms_submission_is_shadow'], $keys['_clms_submission_group_master_id'], $keys['_clms_submission_user_id'] ) ) {
					return array( $shadow_id );
				}
				return array();
			}
		);

		// Attachment owned by someone else, but linked to master submission.
		atora_test_set_post( $attachment_id, array( 'post_type' => 'attachment', 'post_author' => 999 ) );
		atora_test_set_post_meta( $attachment_id, '_clms_submission_owner', 999 );
		atora_test_set_post_meta( $attachment_id, '_clms_submission_id', $master_id );

		$dummy = new class {
			use \CLMS_Submission_Files_Assets_Trait;
			use \CLMS_Submission_Storage_Review_Trait;
			public const CPT = 'clms_submission';
			protected int $max_files = 5;
			protected int $max_file_size = 1048576;
			protected array $allowed_mimes = array( 'pdf' => 'application/pdf' );

			public function can_view_attachment_public( int $user_id, int $file_id ): bool {
				return $this->user_can_view_attachment( $user_id, $file_id );
			}
		};

		$this->assertTrue( $dummy->can_view_attachment_public( $user_id, $attachment_id ) );
	}
}
}
