<?php
/**
 * Servicio de exportación CSV (streaming) — CRM v2.
 *
 * Port del export legacy de CRM v1, pero sin cargar todo en memoria:
 * se escribe en batches directo al stream.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CSV_Export_Service {
	/**
	 * Datasets soportados.
	 */
	private const DATASETS = array( 'contacts', 'message_log', 'email_queue' );

	/**
	 * Batch por defecto (streaming).
	 */
	private const DEFAULT_BATCH = 500;

	/**
	 * @param string $dataset
	 * @return string
	 */
	public static function normalize_dataset( string $dataset ): string {
		$dataset = sanitize_key( $dataset );
		return in_array( $dataset, self::DATASETS, true ) ? $dataset : 'contacts';
	}

	/**
	 * @param string $dataset
	 * @return array<int,string>
	 */
	public static function get_columns( string $dataset ): array {
		$dataset = self::normalize_dataset( $dataset );

		if ( 'message_log' === $dataset ) {
			return array( 'LogID', 'QueueID', 'UserID', 'Channel', 'Status', 'TemplateKey', 'EventType', 'EventData', 'CreatedAt' );
		}

		if ( 'email_queue' === $dataset ) {
			return array( 'ID', 'UserID', 'RecipientEmail', 'RecipientName', 'Subject', 'Status', 'EmailIdentity', 'ScheduledAt', 'SentAt', 'OpenedAt', 'ClickedAt', 'CreatedAt' );
		}

		$columns = array( 'ID', 'Name', 'Email', 'Phone', 'Country', 'City', 'Status', 'Source', 'Created' );
		foreach ( self::get_contact_custom_field_keys() as $key ) {
			$columns[] = 'CF:' . $key;
		}
		return $columns;
	}

	/**
	 * Exporta CSV al stream indicado (streaming por batches).
	 *
	 * @param string   $dataset Dataset a exportar.
	 * @param resource $stream  Handle abierto (p.ej. php://output o php://temp).
	 * @param int      $batch   Batch size.
	 * @return void
	 */
	public static function write_csv( string $dataset, $stream, int $batch = self::DEFAULT_BATCH ): void {
		$dataset = self::normalize_dataset( $dataset );
		$batch   = max( 50, min( 2000, absint( $batch ) ?: self::DEFAULT_BATCH ) );

		if ( ! is_resource( $stream ) ) {
			return;
		}

		fputcsv( $stream, self::get_columns( $dataset ), ',', '"', '\\' );

		$offset = 0;
		while ( true ) {
			$rows = self::fetch_rows( $dataset, $batch, $offset );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				fputcsv( $stream, $row, ',', '"', '\\' );
			}

			if ( count( $rows ) < $batch ) {
				break;
			}
			$offset += $batch;
		}
	}

	/**
	 * @param string $dataset
	 * @return string
	 */
	public static function get_filename( string $dataset ): string {
		$dataset = self::normalize_dataset( $dataset );
		$date    = gmdate( 'Y-m-d' );

		if ( 'message_log' === $dataset ) {
			return "atora-crm-message-log-{$date}.csv";
		}
		if ( 'email_queue' === $dataset ) {
			return "atora-crm-email-queue-{$date}.csv";
		}
		return "atora-crm-contacts-{$date}.csv";
	}

	/**
	 * @param string $dataset
	 * @return array<string,string>
	 */
	public static function get_response_headers( string $dataset ): array {
		$dataset = self::normalize_dataset( $dataset );
		return array(
			'Content-Type'        => 'text/csv',
			'Content-Disposition' => 'attachment; filename="' . self::get_filename( $dataset ) . '"',
		);
	}

	/**
	 * Determina si el handler AJAX debe finalizar ejecución al terminar el export.
	 *
	 * Mantiene comportamiento actual (exit=true) y permite desactivarlo en pruebas.
	 *
	 * @return bool
	 */
	private static function should_exit_after_export_ajax(): bool {
		/**
		 * Controla si `ajax_export_csv()` finaliza con `exit`.
		 *
		 * @param bool $should_exit Si debe finalizar.
		 */
		return (bool) apply_filters( 'atora_crm_export_should_exit', true );
	}

	/** @return void */
	public static function ajax_export_csv(): void {
		check_ajax_referer( 'atora_crm_export' );
		$core = clms_core( 'CLMS_Contacts_Core_Service' );
		if ( ! $core || ! method_exists( $core, 'can_manage_crm' ) || ! $core->can_manage_crm( get_current_user_id() ) ) {
			wp_die();
		}
		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_export_contacts' ) ) {
			if ( ! \CLMS_Access::can_export_contacts() ) {
				wp_die();
			}
		}

		$dataset = isset( $_REQUEST['dataset'] ) ? self::normalize_dataset( (string) wp_unslash( $_REQUEST['dataset'] ) ) : 'contacts';
		Audit_Service::log( 'contacts_exported', 'export', 0, array( 'dataset' => $dataset ) );
		foreach ( self::get_response_headers( $dataset ) as $header_name => $header_value ) {
			header( $header_name . ': ' . $header_value );
		}

		$stream = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( is_resource( $stream ) ) {
			self::write_csv( $dataset, $stream );
			fclose( $stream );
		}

		if ( self::should_exit_after_export_ajax() ) {
			exit;
		}
	}

	/**
	 * @param string $dataset
	 * @param int    $limit
	 * @param int    $offset
	 * @return array<int,array<int,scalar>>
	 */
	private static function fetch_rows( string $dataset, int $limit, int $offset ): array {
		global $wpdb;
		$dataset = self::normalize_dataset( $dataset );
		$limit   = max( 1, $limit );
		$offset  = max( 0, $offset );

		if ( 'message_log' === $dataset ) {
			$queue_table = $wpdb->prefix . 'atora_message_queue';
			$log_table   = $wpdb->prefix . 'atora_message_log';
			if ( ! DB_Service::table_exists( $queue_table ) || ! DB_Service::table_exists( $log_table ) ) {
				return array();
			}

			$sql  = "SELECT ml.id, ml.queue_id, mq.user_id, mq.channel, mq.status, mq.template_key, ml.event_type, ml.event_data, ml.created_at
				FROM {$log_table} ml
				LEFT JOIN {$queue_table} mq ON mq.id = ml.queue_id
				ORDER BY ml.created_at DESC, ml.id DESC
				LIMIT %d OFFSET %d";
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out  = array();
			foreach ( $rows as $row ) {
				$out[] = array(
					(int) ( $row['id'] ?? 0 ),
					(int) ( $row['queue_id'] ?? 0 ),
					(int) ( $row['user_id'] ?? 0 ),
					sanitize_text_field( (string) ( $row['channel'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['status'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['template_key'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['event_type'] ?? '' ) ),
					is_scalar( $row['event_data'] ?? '' ) ? (string) $row['event_data'] : '',
					sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				);
			}
			return $out;
		}

		if ( 'email_queue' === $dataset ) {
			$email_table = $wpdb->prefix . 'atora_email_queue';
			if ( ! DB_Service::table_exists( $email_table ) ) {
				return array();
			}

			$sql  = "SELECT id, user_id, recipient_email, recipient_name, subject, status, identity_key, scheduled_at, sent_at, opened_at, clicked_at, created_at
				FROM {$email_table}
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d";
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out  = array();
			foreach ( $rows as $row ) {
				$out[] = array(
					(int) ( $row['id'] ?? 0 ),
					(int) ( $row['user_id'] ?? 0 ),
					sanitize_email( (string) ( $row['recipient_email'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['recipient_name'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['status'] ?? '' ) ),
					sanitize_key( (string) ( $row['identity_key'] ?? 'academia' ) ) ?: 'academia',
					sanitize_text_field( (string) ( $row['scheduled_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['sent_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['opened_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['clicked_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				);
			}
			return $out;
		}

		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $contacts_table ) ) {
			return array();
		}

		$institution_id = self::resolve_institution_id();
		if ( $institution_id < 0 ) {
			return array();
		}

		$custom_keys = self::get_contact_custom_field_keys();
		$custom_map  = self::get_custom_values_map_for_contacts( array(), $custom_keys );
		$custom_values_table = $wpdb->prefix . 'atora_crm_contact_field_values';
		if ( ! empty( $custom_keys ) && DB_Service::table_exists( $custom_values_table ) ) {
			// map se carga más abajo cuando sepamos IDs.
			$custom_map = array();
		}
		$where_inst = $institution_id > 0 ? 'WHERE institution_id = %d' : '';
		$sql  = "SELECT id, name, email, phone, country, city, status, source, created_at
			FROM {$contacts_table}
			{$where_inst}
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d";
		$args = $institution_id > 0 ? array( $institution_id, $limit, $offset ) : array( $limit, $offset );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$contact_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
		if ( ! empty( $custom_keys ) && ! empty( $contact_ids ) ) {
			$custom_map = self::get_custom_values_map_for_contacts( $contact_ids, $custom_keys );
		}
		$out  = array();
		foreach ( $rows as $row ) {
			$contact_id = absint( $row['id'] ?? 0 );
			$line = array(
				(int) ( $row['id'] ?? 0 ),
				sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				sanitize_email( (string) ( $row['email'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['phone'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['country'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['city'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['status'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['source'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			);

			foreach ( $custom_keys as $key ) {
				$line[] = sanitize_text_field( (string) ( $custom_map[ $contact_id ][ $key ] ?? '' ) );
			}

			$out[] = $line;
		}
		return $out;
	}

	/**
	 * @return array<int,string>
	 */
	private static function get_contact_custom_field_keys(): array {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$keys = array();
		if ( class_exists( '\\ATORA\\CRM_V2\\Services\\Contact_Service' ) ) {
			foreach ( (array) Contact_Service::get_custom_fields() as $field ) {
				$key = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
				if ( '' !== $key ) {
					$keys[] = $key;
				}
			}
		}
		$cached = array_values( array_unique( $keys ) );
		return $cached;
	}

	/**
	 * @param array<int,int>    $contact_ids
	 * @param array<int,string> $custom_keys
	 * @return array<int,array<string,string>>
	 */
	private static function get_custom_values_map_for_contacts( array $contact_ids, array $custom_keys ): array {
		global $wpdb;

		$contact_ids = array_values( array_filter( array_map( 'absint', $contact_ids ) ) );
		$custom_keys = array_values( array_filter( array_map( 'sanitize_key', $custom_keys ) ) );
		if ( empty( $contact_ids ) || empty( $custom_keys ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'atora_crm_contact_field_values';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$sql = "SELECT contact_id, field_key, value FROM {$table} WHERE contact_id IN ({$in})";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$contact_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$map = array();
		foreach ( $rows as $row ) {
			$cid = absint( $row['contact_id'] ?? 0 );
			$key = sanitize_key( (string) ( $row['field_key'] ?? '' ) );
			if ( ! $cid || '' === $key || ! in_array( $key, $custom_keys, true ) ) {
				continue;
			}
			if ( ! isset( $map[ $cid ] ) ) {
				$map[ $cid ] = array();
			}
			$map[ $cid ][ $key ] = (string) ( $row['value'] ?? '' );
		}

		return $map;
	}

	private static function resolve_institution_id(): int {
		global $wpdb;
		$institutions_table = $wpdb->prefix . 'atora_institutions';
		$like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $institutions_table ) : $institutions_table;
		$tenancy_configured = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $institutions_table;

		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			$resolved = \ATORA\LMS\Tenant_Context::current_institution_id();
			if ( is_wp_error( $resolved ) ) {
				if ( ! $tenancy_configured ) {
					return 0;
				}
				$single = self::single_institution_fallback_id();
				return $single > 0 ? $single : -1;
			}
			$id = absint( $resolved );
			if ( $id > 0 ) {
				return $id;
			}
			if ( ! $tenancy_configured ) {
				return 0;
			}
			$single = self::single_institution_fallback_id();
			return $single > 0 ? $single : -1;
		}

		return 0;
	}

	private static function single_institution_fallback_id(): int {
		global $wpdb;
		if ( ! method_exists( $wpdb, 'get_col' ) ) {
			return 0;
		}
		$table = $wpdb->prefix . 'atora_institutions';
		$like  = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : $table;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $table ) {
			return 0;
		}
		$ids = array_values( array_filter( array_map( 'absint', (array) $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 2" ) ) ) );
		return 1 === count( $ids ) ? absint( $ids[0] ) : 0;
	}
}

