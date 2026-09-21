<?php
/**
 * Institution_Service — X-01
 *
 * Helpers para institución por defecto y backfills de institution_id.
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.3
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Institution_Service {

	public static function ensure_default_institution(): int {
		global $wpdb;

		$existing = absint( get_option( 'atora_default_institution', 0 ) );
		if ( $existing > 0 ) {
			return $existing;
		}

		$table = $wpdb->prefix . 'atora_institutions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}

		$slug = sanitize_title( (string) get_bloginfo( 'name' ) );
		if ( '' === $slug ) { $slug = 'default'; }

		$found = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug )
		);
		if ( $found > 0 ) {
			return $found;
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'slug'          => $slug,
				'name'          => (string) get_bloginfo( 'name' ),
				'legal_name'    => (string) get_bloginfo( 'name' ),
				'status'        => 'active',
				'locale'        => determine_locale(),
				'timezone'      => (string) wp_timezone_string(),
				'logo_url'      => '',
				'contact_email' => (string) get_option( 'admin_email', '' ),
				'seats_licensed'=> 0,
			),
			array( '%s','%s','%s','%s','%s','%s','%s','%s','%d' )
		);
		if ( ! $ok ) { return 0; }

		return (int) $wpdb->insert_id;
	}

	/**
	 * Backfill: rellena institution_id=default para filas existentes con 0.
	 *
	 * @return array<string,int>
	 */
	public static function backfill_institution_ids( int $institution_id ): array {
		global $wpdb;
		$institution_id = absint( $institution_id );
		if ( $institution_id <= 0 ) { return array(); }

		$out = array();
		$tables = array(
			'atora_programs',
			'atora_courses',
			'atora_enrollments',
			'atora_program_enrollments',
			'clms_invitations',
		);

		foreach ( $tables as $short ) {
			$table = $wpdb->prefix . $short;
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET institution_id = %d WHERE institution_id = 0", $institution_id ) );
			$out[ $short ] = max( 0, $affected );
		}

		return $out;
	}
}

