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

	/**
	 * Endurece el esquema tras migración: institution_id NOT NULL sin DEFAULT.
	 *
	 * Evita que institution_id=0 se comporte como "sin resolver" y a la vez
	 * coincida con filas no migradas. Solo se aplica cuando ya no existen filas
	 * con institution_id=0.
	 *
	 * @return array<string,bool>
	 */
	public static function finalize_institution_columns(): array {
		global $wpdb;

		$out = array();
		$tables = array(
			'atora_programs'            => 'BIGINT UNSIGNED NOT NULL',
			'atora_courses'             => 'BIGINT UNSIGNED NOT NULL',
			'atora_enrollments'         => 'BIGINT UNSIGNED NOT NULL',
			'atora_program_enrollments' => 'BIGINT UNSIGNED NOT NULL',
			'clms_invitations'          => 'BIGINT UNSIGNED NOT NULL',
		);

		foreach ( $tables as $short => $definition ) {
			$table = $wpdb->prefix . $short;
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}

			$zeros = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE institution_id = 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $zeros > 0 ) {
				$out[ $short ] = false;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} MODIFY institution_id {$definition}" );
			$out[ $short ] = true;
		}

		return $out;
	}

	/**
	 * Reporte simple de asientos por institución.
	 *
	 * @return array<int, array{id:int,slug:string,name:string,seats_licensed:int,seats_used:int}>
	 */
	public static function seats_report(): array {
		global $wpdb;

		$inst_table = $wpdb->prefix . 'atora_institutions';
		$mem_table  = $wpdb->prefix . 'atora_institution_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $inst_table ) ) ) !== $inst_table ) {
			return array();
		}
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mem_table ) ) ) !== $mem_table ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			"SELECT i.id, i.slug, i.name, i.seats_licensed,
			        SUM(m.role = 'student' AND m.status = 'active') AS seats_used
			 FROM {$inst_table} i
			 LEFT JOIN {$mem_table} m ON m.institution_id = i.id
			 GROUP BY i.id
			 ORDER BY i.id ASC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$out[] = array(
				'id'            => absint( $row['id'] ?? 0 ),
				'slug'          => sanitize_key( (string) ( $row['slug'] ?? '' ) ),
				'name'          => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'seats_licensed' => absint( $row['seats_licensed'] ?? 0 ),
				'seats_used'    => absint( $row['seats_used'] ?? 0 ),
			);
		}

		return $out;
	}
}
