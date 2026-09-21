<?php
/**
 * Integración: cohorte de 10.000 miembros (tope de diseño) y unicidad.
 */

declare( strict_types = 1 );

final class CohortScaleTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
	}

	private function create_institution_and_user(): array {
		global $wpdb;
		$inst_table = $wpdb->prefix . 'atora_institutions';
		$mem_table  = $wpdb->prefix . 'atora_institution_members';

		$wpdb->insert(
			$inst_table,
			array(
				'slug'          => 'inst-scale',
				'name'          => 'Inst Scale',
				'legal_name'    => 'Inst Scale',
				'status'        => 'active',
				'locale'        => 'es',
				'timezone'      => 'UTC',
				'logo_url'      => '',
				'contact_email' => 'test@example.test',
				'seats_licensed' => 0,
			),
			array( '%s','%s','%s','%s','%s','%s','%s','%s','%d' )
		);
		$institution_id = (int) $wpdb->insert_id;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$wpdb->insert(
			$mem_table,
			array(
				'institution_id' => $institution_id,
				'user_id'        => $user_id,
				'role'           => 'admin',
				'status'         => 'active',
				'scope_json'     => null,
				'student_code'   => '',
			),
			array( '%d','%d','%s','%s','%s','%s' )
		);
		update_user_meta( $user_id, '_atora_active_institution', $institution_id );
		wp_set_current_user( $user_id );
		\ATORA\LMS\Tenant_Context::reset_cache();

		return array( $institution_id, $user_id );
	}

	public function test_member_listing_handles_10000_and_unique_key_prevents_duplicates(): void {
		global $wpdb;

		list( $institution_id, $user_id ) = $this->create_institution_and_user();

		$wp_post_id = self::factory()->post->create( array( 'post_type' => 'lm_cohort', 'post_status' => 'publish', 'post_title' => 'Cohorte' ) );

		$cohort_table = $wpdb->prefix . 'atora_cohorts';
		$wpdb->insert(
			$cohort_table,
			array(
				'institution_id' => $institution_id,
				'program_id'     => 0,
				'wp_post_id'     => $wp_post_id,
				'code'           => 'c-1',
				'name'           => 'Cohorte',
				'status'         => 'active',
				'capacity'       => 0,
			),
			array( '%d','%d','%d','%s','%s','%s','%d' )
		);
		$cohort_id = (int) $wpdb->insert_id;

		$member_table = $wpdb->prefix . 'atora_cohort_members';

		// Insertar 10.000 miembros en lotes.
		$values = array();
		$args   = array();
		for ( $i = 1; $i <= 10000; $i++ ) {
			$values[] = '( %d, %d, %d, %s, %s )';
			$args[] = $cohort_id;
			$args[] = $institution_id;
			$args[] = 100000 + $i;
			$args[] = 'student';
			$args[] = 'active';

			if ( 0 === ( $i % 1000 ) ) {
				$sql = "INSERT INTO {$member_table} (cohort_id, institution_id, user_id, role, status) VALUES " . implode( ',', $values );
				$wpdb->query( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
				$values = array();
				$args   = array();
			}
		}
		if ( ! empty( $values ) ) {
			$sql = "INSERT INTO {$member_table} (cohort_id, institution_id, user_id, role, status) VALUES " . implode( ',', $values );
			$wpdb->query( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		$ids = \ATORA\LMS\Cohort_Table_Service::get_member_ids( $wp_post_id, 'student' );
		$this->assertCount( 10000, $ids );

		// Unicidad: dos altas concurrentes deben resultar en una sola fila (UNIQUE KEY).
		$dupe_user = 777;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$member_table} (cohort_id, institution_id, user_id, role, status)
				 VALUES (%d, %d, %d, %s, %s), (%d, %d, %d, %s, %s)",
				$cohort_id, $institution_id, $dupe_user, 'student', 'active',
				$cohort_id, $institution_id, $dupe_user, 'student', 'active'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$member_table} WHERE cohort_id = %d AND user_id = %d AND role = %s",
				$cohort_id,
				$dupe_user,
				'student'
			)
		);
		$this->assertSame( 1, $count );
	}
}

