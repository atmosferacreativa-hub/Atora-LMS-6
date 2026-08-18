<?php
/**
 * Company_Service — Gestión de empresas CRM (Fase 9)
 *
 * @package ATORA_LMS\CRM_V2\Services
 * @since   5.28.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Company_Service {

	public static function create( array $data ): int {
		global $wpdb;
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) { return 0; }
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'atora_companies',
			array(
				'name'            => $name,
				'industry'        => sanitize_text_field( (string) ( $data['industry']       ?? '' ) ),
				'email'           => sanitize_email(      (string) ( $data['email']          ?? '' ) ),
				'phone'           => sanitize_text_field( (string) ( $data['phone']          ?? '' ) ),
				'website'         => esc_url_raw(         (string) ( $data['website']        ?? '' ) ),
				'linkedin_url'    => esc_url_raw(         (string) ( $data['linkedin_url']   ?? '' ) ),
				'country'         => sanitize_key(        (string) ( $data['country']        ?? '' ) ),
				'city'            => sanitize_text_field( (string) ( $data['city']           ?? '' ) ),
				'state'           => sanitize_text_field( (string) ( $data['state']          ?? '' ) ),
				'address'         => sanitize_text_field( (string) ( $data['address']        ?? '' ) ),
				'employees_count' => absint(                        $data['employees_count'] ?? 0 ),
				'description'     => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'owner_id'        => absint(                        $data['owner_id']        ?? 0 ),
				'created_by'      => absint( get_current_user_id() ),
			),
			array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%d' )
		);
		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	public static function get( int $company_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_companies WHERE id = %d LIMIT 1", $company_id ),
			ARRAY_A
		);
		return $row ? self::format( $row ) : null;
	}

	public static function get_all( int $limit = 50, int $offset = 0, string $search = '' ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'atora_companies';
		$where  = '';
		$params = array();
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where  = 'WHERE name LIKE %s OR email LIKE %s';
			$params = array( $like, $like );
		}
		$total = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$list_params = array_merge( $params, array( $limit, $offset ) );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY name ASC LIMIT %d OFFSET %d", ...$list_params ),
			ARRAY_A
		);
		return array( 'items' => array_map( array( __CLASS__, 'format' ), $rows ), 'total' => $total );
	}

	public static function update( int $company_id, array $data ): bool {
		global $wpdb;
		$update = array();
		$format = array();
		foreach ( array( 'name', 'industry', 'email', 'phone', 'website', 'linkedin_url', 'country', 'city', 'state', 'address', 'description' ) as $f ) {
			if ( isset( $data[ $f ] ) ) { $update[ $f ] = sanitize_text_field( (string) $data[ $f ] ); $format[] = '%s'; }
		}
		if ( isset( $data['employees_count'] ) ) { $update['employees_count'] = absint( $data['employees_count'] ); $format[] = '%d'; }
		if ( isset( $data['owner_id'] ) )         { $update['owner_id']        = absint( $data['owner_id'] );        $format[] = '%d'; }
		if ( empty( $update ) ) { return false; }
		return false !== $wpdb->update( $wpdb->prefix . 'atora_companies', $update, array( 'id' => $company_id ), $format, array( '%d' ) );
	}

	public static function assign_contact( int $company_id, int $contact_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contacts';
		$col_exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='company_id'", $table )
		);
		if ( ! $col_exists ) { return false; }
		return false !== $wpdb->update( $table, array( 'company_id' => $company_id ), array( 'id' => $contact_id ), array( '%d' ), array( '%d' ) );
	}

	public static function get_contacts( int $company_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, name, email, phone, status FROM {$wpdb->prefix}atora_contacts WHERE company_id = %d ORDER BY name ASC", $company_id ),
			ARRAY_A
		);
		return array_map( function( $r ) {
			return array(
				'id'     => absint( $r['id'] ),
				'name'   => sanitize_text_field( (string) $r['name'] ),
				'email'  => sanitize_email( (string) $r['email'] ),
				'phone'  => sanitize_text_field( (string) $r['phone'] ),
				'status' => sanitize_key( (string) $r['status'] ),
			);
		}, $rows );
	}

	public static function get_ltv( int $company_id ): float {
		global $wpdb;
		$col_check = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='life_time_value'", $wpdb->prefix . 'atora_contacts' )
		);
		if ( ! $col_check ) { return 0.0; }
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT SUM(life_time_value) FROM {$wpdb->prefix}atora_contacts WHERE company_id = %d", $company_id )
		);
	}

	private static function format( array $row ): array {
		return array(
			'id'              => absint( $row['id'] ),
			'name'            => sanitize_text_field( (string) ( $row['name']            ?? '' ) ),
			'industry'        => sanitize_text_field( (string) ( $row['industry']        ?? '' ) ),
			'email'           => sanitize_email(      (string) ( $row['email']           ?? '' ) ),
			'phone'           => sanitize_text_field( (string) ( $row['phone']           ?? '' ) ),
			'website'         => esc_url_raw(         (string) ( $row['website']         ?? '' ) ),
			'linkedin_url'    => esc_url_raw(         (string) ( $row['linkedin_url']    ?? '' ) ),
			'country'         => sanitize_key(        (string) ( $row['country']         ?? '' ) ),
			'city'            => sanitize_text_field( (string) ( $row['city']            ?? '' ) ),
			'employees_count' => absint(                        $row['employees_count']  ?? 0 ),
			'description'     => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
			'owner_id'        => absint(                        $row['owner_id']         ?? 0 ),
			'created_at'      => sanitize_text_field( (string) ( $row['created_at']      ?? '' ) ),
		);
	}
}
