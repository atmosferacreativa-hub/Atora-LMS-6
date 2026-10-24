<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScormGradebookIntegrationTest extends TestCase {
	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'];
		atora_test_reset_options();
		atora_test_reset_post_meta();
		atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function test_gradebook_cell_is_filled_only_when_scorm_attempt_is_graded(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-gradebook-integration.php';

		// La lección tiene un recurso SCORM.
		update_post_meta( 55, '_clms_lesson_resources', array( array( 'type' => 'scorm', 'library_item_id' => 1, 'library_version_id' => 1 ) ) );

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public array $rows = array();
			public function __construct() {
				$this->rows[] = array( 'user_id' => 10, 'lesson_id' => 55, 'score_percent' => 70.5, 'grade_status' => 'in_review' );
			}
			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}
			public function get_row( $sql, $output = ARRAY_A ) {
				if ( false !== strpos( (string) $sql, 'FROM wp_atora_scorm_attempts' ) ) {
					return $this->rows[0];
				}
				return null;
			}
		};

		$cell = array( 'grade' => '', 'missing' => true, 'status' => 'missing' );
		$out = CLMS_SCORM_Gradebook_Integration::filter_gradebook_cell( $cell, 10, 1, 55, array(), array() );
		$this->assertSame( '', $out['grade'] );

		$GLOBALS['wpdb']->rows[0]['grade_status'] = 'graded';
		$out2 = CLMS_SCORM_Gradebook_Integration::filter_gradebook_cell( $cell, 10, 1, 55, array(), array() );
		$this->assertSame( 71, (int) $out2['grade'] );
		$this->assertSame( 'scorm', (string) $out2['source'] );
		$this->assertSame( 'graded', (string) $out2['status'] );
		$this->assertFalse( (bool) $out2['missing'] );
	}
}

