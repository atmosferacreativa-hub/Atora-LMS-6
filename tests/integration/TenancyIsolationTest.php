<?php
/**
 * Integración: aislamiento de inquilinos (tenancy) para servicios reconciliados.
 */

declare( strict_types = 1 );

final class TenancyIsolationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();

		require_once dirname( __DIR__, 2 ) . '/includes/gradebook/class-institutional-gradebook-service.php';
		require_once dirname( __DIR__, 2 ) . '/includes/library/class-academic-library-service.php';
	}

	private function create_institution( string $slug, string $name ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_institutions';

		$wpdb->insert(
			$table,
			array(
				'slug'          => $slug,
				'name'          => $name,
				'legal_name'    => $name,
				'status'        => 'active',
				'locale'        => 'es',
				'timezone'      => 'UTC',
				'logo_url'      => '',
				'contact_email' => 'test@example.test',
				'seats_licensed' => 0,
			),
			array( '%s','%s','%s','%s','%s','%s','%s','%s','%d' )
		);

		return (int) $wpdb->insert_id;
	}

	private function add_membership( int $user_id, int $institution_id, string $role = 'admin' ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_institution_members';

		$wpdb->insert(
			$table,
			array(
				'institution_id' => $institution_id,
				'user_id'        => $user_id,
				'role'           => $role,
				'status'         => 'active',
				'scope_json'     => null,
				'student_code'   => '',
			),
			array( '%d','%d','%s','%s','%s','%s' )
		);

		update_user_meta( $user_id, '_atora_active_institution', $institution_id );
	}

	public function test_gradebook_and_library_do_not_leak_between_institutions(): void {
		global $wpdb;

		$inst_a = $this->create_institution( 'inst-a', 'Inst A' );
		$inst_b = $this->create_institution( 'inst-b', 'Inst B' );

		$user_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user_b = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->add_membership( $user_a, $inst_a );
		$this->add_membership( $user_b, $inst_b );

		// Seed gradebook (período + escala + ciclo) en A y B.
		$periods = $wpdb->prefix . 'atora_academic_periods';
		$scales  = $wpdb->prefix . 'atora_grading_scales';
		$cycles  = $wpdb->prefix . 'atora_gradebook_cycles';

		$wpdb->insert(
			$periods,
			array(
				'institution_id' => $inst_a,
				'code'           => 'p-a',
				'name'           => 'Periodo A',
				'starts_at'      => '2026-01-01',
				'ends_at'        => '2026-06-30',
				'status'         => 'draft',
				'created_by'     => $user_a,
			),
			array( '%d','%s','%s','%s','%s','%s','%d' )
		);
		$period_a = (int) $wpdb->insert_id;

		$wpdb->insert(
			$scales,
			array(
				'institution_id' => $inst_a,
				'code'           => 's-a',
				'name'           => 'Escala A',
				'minimum'        => 0,
				'maximum'        => 20,
				'bands_json'     => '[]',
				'status'         => 'active',
				'version'        => 1,
				'created_by'     => $user_a,
			),
			array( '%d','%s','%s','%f','%f','%s','%s','%d','%d' )
		);
		$scale_a = (int) $wpdb->insert_id;

		$wpdb->insert(
			$cycles,
			array(
				'institution_id' => $inst_a,
				'period_id'      => $period_a,
				'course_id'      => 101,
				'scale_id'       => $scale_a,
				'status'         => 'draft',
				'lock_version'   => 1,
				'snapshot_hash'  => '',
				'published_by'   => 0,
				'closed_by'      => 0,
				'created_by'     => $user_a,
			),
			array( '%d','%d','%d','%d','%s','%d','%s','%d','%d','%d' )
		);

		$wpdb->insert(
			$periods,
			array(
				'institution_id' => $inst_b,
				'code'           => 'p-b',
				'name'           => 'Periodo B',
				'starts_at'      => '2026-01-01',
				'ends_at'        => '2026-06-30',
				'status'         => 'draft',
				'created_by'     => $user_b,
			),
			array( '%d','%s','%s','%s','%s','%s','%d' )
		);

		// Seed biblioteca.
		$items = $wpdb->prefix . 'atora_library_items';
		$wpdb->insert(
			$items,
			array(
				'institution_id'     => $inst_a,
				'slug'               => 'doc-a',
				'title'              => 'Doc A',
				'description'        => 'A',
				'resource_type'      => 'document',
				'status'             => 'published',
				'current_version_id' => 0,
				'created_by'         => $user_a,
			),
			array( '%d','%s','%s','%s','%s','%s','%d','%d' )
		);
		$item_a = (int) $wpdb->insert_id;

		$wpdb->insert(
			$items,
			array(
				'institution_id'     => $inst_b,
				'slug'               => 'doc-b',
				'title'              => 'Doc B',
				'description'        => 'B',
				'resource_type'      => 'document',
				'status'             => 'published',
				'current_version_id' => 0,
				'created_by'         => $user_b,
			),
			array( '%d','%s','%s','%s','%s','%s','%d','%d' )
		);
		$item_b = (int) $wpdb->insert_id;

		// User A: solo ve A.
		wp_set_current_user( $user_a );
		\ATORA\LMS\Tenant_Context::reset_cache();
		$gb = new CLMS_Institutional_Gradebook_Service();
		$ctx = $gb->get_context( 0, 0, 0 );
		$this->assertIsArray( $ctx );
		$this->assertCount( 1, $ctx['periods'] );
		$this->assertSame( $inst_a, (int) $ctx['periods'][0]['institution_id'] );

		$lib = new CLMS_Academic_Library_Service();
		$list = $lib->list_items( 'published', 0 );
		$this->assertIsArray( $list );
		$this->assertCount( 1, $list );
		$this->assertSame( $inst_a, (int) $list[0]['institution_id'] );

		$this->assertNotEmpty( $lib->get_item( $item_a ) );
		$this->assertSame( array(), $lib->get_item( $item_b ) );

		// User B: solo ve B.
		wp_set_current_user( $user_b );
		\ATORA\LMS\Tenant_Context::reset_cache();
		$ctx = $gb->get_context( 0, 0, 0 );
		$this->assertIsArray( $ctx );
		$this->assertCount( 1, $ctx['periods'] );
		$this->assertSame( $inst_b, (int) $ctx['periods'][0]['institution_id'] );

		$list = $lib->list_items( 'published', 0 );
		$this->assertIsArray( $list );
		$this->assertCount( 1, $list );
		$this->assertSame( $inst_b, (int) $list[0]['institution_id'] );
	}

	public function test_services_fail_closed_when_tenant_unresolved(): void {
		global $wpdb;

		// Sin membresía, sin default.
		delete_option( 'atora_default_institution' );

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		delete_user_meta( $user, '_atora_active_institution' );
		\ATORA\LMS\Tenant_Context::reset_cache();

		$gb = new CLMS_Institutional_Gradebook_Service();
		$out = $gb->get_context( 0, 0, 0 );
		$this->assertInstanceOf( WP_Error::class, $out );

		$lib = new CLMS_Academic_Library_Service();
		$out = $lib->list_items( 'published', 0 );
		$this->assertInstanceOf( WP_Error::class, $out );

		// Academy_Context::where_clause() debe devolver condición imposible al no resolver.
		require_once dirname( __DIR__, 2 ) . '/modules/crm-v2/class-academy-context.php';
		$clause = \ATORA\CRM_V2\Academy_Context::where_clause( 'x' );
		$this->assertSame( 'AND 1=0', $clause );
	}
}

