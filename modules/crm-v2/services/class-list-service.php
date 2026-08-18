<?php
/**
 * List_Service — Gestión de listas CRM con opt-in/out (Fase 9)
 *
 * @package ATORA_LMS\CRM_V2\Services
 * @since   5.28.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class List_Service {

	public static function get_all( string $type = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_crm_lists';
		if ( ! DB_Service::table_exists( $table ) ) { return array(); }
		$where  = '';
		$params = array();
		if ( '' !== $type ) {
			$where    = 'WHERE type = %s';
			$params[] = sanitize_key( $type );
		}
		$rows = ! empty( $params )
			? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY title ASC", ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	public static function create( array $data ): int {
		global $wpdb;
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) { return 0; }
		$ok = $wpdb->insert(
			$wpdb->prefix . 'atora_crm_lists',
			array(
				'title'       => $title,
				'slug'        => sanitize_title( $title ),
				'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'type'        => sanitize_key( (string) ( $data['type'] ?? 'marketing' ) ),
				'is_public'   => (int) ! empty( $data['is_public'] ),
				'created_by'  => absint( get_current_user_id() ),
			),
			array( '%s','%s','%s','%s','%d','%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function subscribe_contact( int $list_id, int $contact_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contact_list_pivot';
		if ( ! DB_Service::table_exists( $table ) ) { return false; }
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE contact_id = %d AND list_id = %d LIMIT 1", $contact_id, $list_id )
		);
		if ( $existing ) {
			$wpdb->update( $table, array( 'status' => 'subscribed' ), array( 'id' => (int) $existing ), array( '%s' ), array( '%d' ) );
			return true;
		}
		return false !== $wpdb->insert( $table, array( 'contact_id' => $contact_id, 'list_id' => $list_id, 'status' => 'subscribed' ), array( '%d','%d','%s' ) );
	}

	public static function unsubscribe_contact( int $list_id, int $contact_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contact_list_pivot';
		if ( ! DB_Service::table_exists( $table ) ) { return false; }
		return false !== $wpdb->update( $table, array( 'status' => 'unsubscribed' ), array( 'contact_id' => $contact_id, 'list_id' => $list_id ), array( '%s' ), array( '%d','%d' ) );
	}

	public static function get_contact_lists( int $contact_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contact_list_pivot';
		if ( ! DB_Service::table_exists( $table ) ) { return array(); }
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.status AS sub_status, p.joined_at FROM {$table} p LEFT JOIN {$wpdb->prefix}atora_crm_lists l ON l.id = p.list_id WHERE p.contact_id = %d AND p.status = 'subscribed' ORDER BY l.title ASC",
				$contact_id
			),
			ARRAY_A
		);
	}

	public static function is_subscribed( int $list_id, int $contact_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contact_list_pivot';
		if ( ! DB_Service::table_exists( $table ) ) { return false; }
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE contact_id = %d AND list_id = %d AND status = 'subscribed' LIMIT 1", $contact_id, $list_id )
		) > 0;
	}

	private static function format( array $row ): array {
		return array(
			'id'          => absint( $row['id'] ),
			'title'       => sanitize_text_field( (string) ( $row['title']       ?? '' ) ),
			'slug'        => sanitize_key(        (string) ( $row['slug']        ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
			'type'        => sanitize_key(        (string) ( $row['type']        ?? 'marketing' ) ),
			'is_public'   => (bool) ( $row['is_public'] ?? false ),
			'created_at'  => sanitize_text_field( (string) ( $row['created_at']  ?? '' ) ),
		);
	}
}
