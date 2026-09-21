<?php
/**
 * Tenant context resolution (X-01).
 *
 * @package ATORA_LMS\Tests\Tenancy
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Tenancy;

use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_options();
		atora_test_reset_user_meta();

		require_once __DIR__ . '/../../modules/tenancy/class-tenant-context.php';
		\ATORA\LMS\Tenant_Context::reset_cache();

		// Fresh stub per test.
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';

			/** @var array<int,true> */
			public array $institutions = array();

			/** @var array<int, array<int,true>> user_id => [institution_id => true] */
			public array $memberships = array();

			public function esc_like( string $s ): string { return addcslashes( $s, '_%\\' ); }

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_var( $sql ) {
				$sql = (string) $sql;

				if ( preg_match( '/SHOW TABLES LIKE\\s+([^\\s]+)/i', $sql, $m ) ) {
					return str_replace( '\\', '', (string) $m[1] );
				}

				// Institution exists.
				if ( preg_match( '/FROM\\s+wp_atora_institutions\\s+WHERE\\s+id\\s*=\\s*(\\d+)/i', $sql, $m ) ) {
					$id = absint( $m[1] );
					return isset( $this->institutions[ $id ] ) ? 1 : null;
				}

				// Membership exists.
				if ( preg_match( '/FROM\\s+wp_atora_institution_members\\s+WHERE\\s+institution_id\\s*=\\s*(\\d+)\\s+AND\\s+user_id\\s*=\\s*(\\d+)/i', $sql, $m ) ) {
					$inst = absint( $m[1] );
					$uid  = absint( $m[2] );
					return isset( $this->memberships[ $uid ][ $inst ] ) ? 1 : null;
				}

				// INFORMATION_SCHEMA lookups: just claim columns exist so code can proceed.
				if ( false !== stripos( $sql, 'INFORMATION_SCHEMA' ) ) {
					return 1;
				}

				return null;
			}

			public function get_col( $sql ): array {
				$sql = (string) $sql;
				if ( preg_match( '/FROM\\s+wp_atora_institution_members\\s+WHERE\\s+user_id\\s*=\\s*(\\d+)/i', $sql, $m ) ) {
					$uid = absint( $m[1] );
					$ids = array_keys( $this->memberships[ $uid ] ?? array() );
					sort( $ids );
					return $ids;
				}
				return array();
			}
		};
	}

	/** @test */
	public function it_errors_when_unresolved_and_no_default(): void {
		$GLOBALS['__atora_test_current_user_id'] = 0;
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->institutions = array();

		$out = \ATORA\LMS\Tenant_Context::current_institution_id();
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'atora_tenancy_unresolved', $out->get_error_code() );
	}

	/** @test */
	public function it_uses_explicit_context_with_and_restores_after(): void {
		$GLOBALS['__atora_test_current_user_id'] = 0;
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->institutions = array( 5 => true );

		$inside = \ATORA\LMS\Tenant_Context::with( 5, static function() {
			return \ATORA\LMS\Tenant_Context::current_institution_id();
		} );
		$this->assertSame( 5, $inside );

		$after = \ATORA\LMS\Tenant_Context::current_institution_id();
		$this->assertInstanceOf( \WP_Error::class, $after );
	}

	/** @test */
	public function it_prefers_user_meta_selected_institution_when_member(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->institutions = array( 7 => true );
		$wpdb->memberships  = array( 10 => array( 7 => true ) );

		$GLOBALS['__atora_test_user_meta'][10]['_atora_active_institution'] = 7;

		$out = \ATORA\LMS\Tenant_Context::current_institution_id();
		$this->assertSame( 7, $out );
	}

	/** @test */
	public function it_errors_when_user_meta_selected_institution_not_member(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->institutions = array( 9 => true );
		$wpdb->memberships  = array( 10 => array( 7 => true ) );

		$GLOBALS['__atora_test_user_meta'][10]['_atora_active_institution'] = 9;

		$out = \ATORA\LMS\Tenant_Context::current_institution_id();
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'atora_tenancy_institution_forbidden', $out->get_error_code() );
	}

	/** @test */
	public function it_errors_when_user_has_multiple_memberships_without_selection(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->institutions = array( 7 => true, 8 => true );
		$wpdb->memberships  = array( 10 => array( 7 => true, 8 => true ) );

		$out = \ATORA\LMS\Tenant_Context::current_institution_id();
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'atora_tenancy_ambiguous', $out->get_error_code() );
	}

	/** @test */
	public function it_uses_unique_membership_when_only_one(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->institutions = array( 7 => true );
		$wpdb->memberships  = array( 10 => array( 7 => true ) );

		$out = \ATORA\LMS\Tenant_Context::current_institution_id();
		$this->assertSame( 7, $out );
	}

	/** @test */
	public function it_falls_back_to_default_option_when_no_membership(): void {
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->institutions = array( 3 => true );
		$wpdb->memberships  = array( 10 => array() );

		update_option( 'atora_default_institution', 3 );

		$out = \ATORA\LMS\Tenant_Context::current_institution_id();
		$this->assertSame( 3, $out );
	}
}
