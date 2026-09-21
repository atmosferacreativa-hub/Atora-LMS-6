<?php

namespace ATORA\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CRM_Export_Admin_Trait {
	/**
	 * Normaliza el dataset de exportación permitido.
	 *
	 * @param string $dataset Dataset solicitado.
	 * @return string
	 */
	public static function normalize_export_dataset( string $dataset ): string {
		$dataset          = sanitize_key( $dataset );
		$allowed_datasets = array( 'contacts', 'message_log', 'email_queue' );

		if ( ! in_array( $dataset, $allowed_datasets, true ) ) {
			return 'contacts';
		}

		return $dataset;
	}

	/**
	 * Devuelve columnas CSV para el dataset indicado.
	 *
	 * @param string $dataset Dataset solicitado.
	 * @return array<int,string>
	 */
	public static function get_export_csv_columns( string $dataset ): array {
		$dataset = self::normalize_export_dataset( $dataset );

		if ( 'message_log' === $dataset ) {
			return array( 'LogID', 'QueueID', 'UserID', 'Channel', 'Status', 'TemplateKey', 'EventType', 'EventData', 'CreatedAt' );
		}

		if ( 'email_queue' === $dataset ) {
			return array( 'ID', 'UserID', 'RecipientEmail', 'RecipientName', 'Subject', 'Status', 'EmailIdentity', 'ScheduledAt', 'SentAt', 'OpenedAt', 'ClickedAt', 'CreatedAt' );
		}

		return array( 'ID', 'Name', 'Email', 'Phone', 'Country', 'City', 'Status', 'Source', 'Created' );
	}

	/**
	 * Obtiene filas CSV sanitizadas para el dataset indicado.
	 *
	 * No valida permisos ni nonce; esa responsabilidad permanece en el handler AJAX.
	 *
	 * @param string $dataset Dataset solicitado.
	 * @return array<int,array<int,scalar>>
	 */
	public static function get_export_csv_rows( string $dataset ): array {
		global $wpdb;

		$dataset = self::normalize_export_dataset( $dataset );

		if ( 'message_log' === $dataset ) {
			$queue_table = "{$wpdb->prefix}atora_message_queue";
			$log_table   = "{$wpdb->prefix}atora_message_log";
			if ( self::table_exists( $queue_table ) && self::table_exists( $log_table ) ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"SELECT ml.id, ml.queue_id, mq.user_id, mq.channel, mq.status, mq.template_key, ml.event_type, ml.event_data, ml.created_at
					FROM {$log_table} ml
					LEFT JOIN {$queue_table} mq ON mq.id = ml.queue_id
					ORDER BY ml.created_at DESC, ml.id DESC
					LIMIT 10000",
					ARRAY_A
				);
			} else {
				$rows = array();
			}

			$formatted_rows = array();
			foreach ( $rows as $row ) {
				$formatted_rows[] = array(
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

			return $formatted_rows;
		}

		if ( 'email_queue' === $dataset ) {
			$email_table = "{$wpdb->prefix}atora_email_queue";
			if ( self::table_exists( $email_table ) ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"SELECT id, user_id, recipient_email, recipient_name, subject, status, scheduled_at, sent_at, opened_at, clicked_at, metadata, created_at
					FROM {$email_table}
					ORDER BY created_at DESC
					LIMIT 10000",
					ARRAY_A
				);
			} else {
				$rows = array();
			}

			$formatted_rows = array();
			foreach ( $rows as $row ) {
				$metadata = json_decode( (string) ( $row['metadata'] ?? '' ), true );
				$identity = is_array( $metadata ) ? sanitize_key( (string) ( $metadata['email_identity'] ?? 'academia' ) ) : 'academia';
				if ( '' === $identity ) {
					$identity = 'academia';
				}

				$formatted_rows[] = array(
					(int) ( $row['id'] ?? 0 ),
					(int) ( $row['user_id'] ?? 0 ),
					sanitize_email( (string) ( $row['recipient_email'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['recipient_name'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['status'] ?? '' ) ),
					$identity,
					sanitize_text_field( (string) ( $row['scheduled_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['sent_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['opened_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['clicked_at'] ?? '' ) ),
					sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				);
			}

			return $formatted_rows;
		}

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( self::table_exists( $contacts_table ) ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id, name, email, phone, country, city, status, source, created_at
				FROM {$contacts_table}
				ORDER BY created_at DESC
				LIMIT 5000",
				ARRAY_A
			);
		} else {
			$rows = array();
		}

		$formatted_rows = array();
		foreach ( $rows as $row ) {
			$formatted_rows[] = array(
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
		}

		return $formatted_rows;
	}

	/**
	 * Construye filename para export CSV.
	 *
	 * @param string $dataset Dataset solicitado.
	 * @return string
	 */
	private static function get_export_csv_filename( string $dataset ): string {
		$dataset = self::normalize_export_dataset( $dataset );
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
	 * Devuelve cabeceras HTTP para descarga CSV del dataset.
	 *
	 * @param string $dataset Dataset solicitado.
	 * @return array<string,string>
	 */
	public static function get_export_csv_response_headers( string $dataset ): array {
		$dataset = self::normalize_export_dataset( $dataset );

		return array(
			'Content-Type'        => 'text/csv',
			'Content-Disposition' => 'attachment; filename="' . self::get_export_csv_filename( $dataset ) . '"',
		);
	}

	/**
	 * Renderiza contenido CSV (header + filas) para el dataset.
	 *
	 * @param string $dataset Dataset solicitado.
	 * @return string
	 */
	public static function get_export_csv_content( string $dataset ): string {
		$dataset = self::normalize_export_dataset( $dataset );
		$columns = self::get_export_csv_columns( $dataset );
		$rows    = self::get_export_csv_rows( $dataset );

		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $stream ) {
			return '';
		}

		fputcsv( $stream, $columns, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $stream, $row, ',', '"', '\\' );
		}

		rewind( $stream );
		$content = (string) stream_get_contents( $stream );
		fclose( $stream );

		return $content;
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
		if ( ! self::can_manage_crm( get_current_user_id() ) ) { wp_die(); }
		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_export_contacts' ) ) {
			if ( ! \CLMS_Access::can_export_contacts() ) { wp_die(); }
		}
		$dataset = isset( $_REQUEST['dataset'] ) ? self::normalize_export_dataset( (string) wp_unslash( $_REQUEST['dataset'] ) ) : 'contacts';
		$headers = self::get_export_csv_response_headers( $dataset );
		foreach ( $headers as $header_name => $header_value ) {
			header( $header_name . ': ' . $header_value );
		}
		echo self::get_export_csv_content( $dataset ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( self::should_exit_after_export_ajax() ) {
			exit;
		}
	}

	/** @return void */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-crm' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'clms-dashboard',
			__( 'CRM', 'atora-lms' ),
			__( 'CRM', 'atora-lms' ),
			'read',
			'atora-crm',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'crm/views/admin.php';
				if ( file_exists( $view ) ) { require $view; }
			}
		);
	}

}
