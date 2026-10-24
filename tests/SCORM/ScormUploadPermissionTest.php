<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScormUploadPermissionTest extends TestCase {
	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'];
		atora_test_reset_user_caps();
		atora_test_reset_options();
		update_option( 'atora_default_institution', 1 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function test_teacher_without_cap_cannot_upload_scorm_versions(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/library/class-academic-library-policy.php';
		require_once dirname( __DIR__, 2 ) . '/includes/library/class-academic-library-service.php';

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = ARRAY_A ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'FROM wp_atora_library_items' ) ) {
					return array(
						'id' => 99,
						'institution_id' => 1,
						'status' => 'draft',
						'resource_type' => 'scorm',
					);
				}
				return null;
			}

			public function get_var( $sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && false !== strpos( $sql, 'atora_institutions' ) ) {
					return 'wp_atora_institutions';
				}
				if ( false !== strpos( $sql, 'SELECT 1 FROM wp_atora_institutions' ) ) {
					return 1;
				}
				return '';
			}
			public function esc_like( string $text ): string { return $text; }
			public function insert( $table, $data, $format = null ): int { return 0; }
			public function update( $table, $data, $where, $format = null, $where_format = null ): int { return 0; }
			public function query( $sql ): int { return 0; }
		};

		$actor_id = 200;
		atora_test_set_user_cap( $actor_id, 'clms_access_admin', true );
		// No atora_upload_scorm y no manage_options.

		$service = new CLMS_Academic_Library_Service();
		$out = $service->add_version( 99, array( 'attachment_id' => 1 ), $actor_id );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'atora_scorm_forbidden_upload', $out->get_error_code() );
		$this->assertSame( 403, (int) ( $out->get_error_data()['status'] ?? 0 ) );
	}
}

