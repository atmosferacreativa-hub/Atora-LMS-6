<?php
/**
 * Sequence_Service — Gestión de secuencias de email drip (Fase 6)
 *
 * @package ATORA_LMS\CRM_V2\Services
 * @since   5.28.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sequence_Service {

	// ── Secuencias ──────────────────────────────────────────────────────────

	public static function create( array $data ): int {
		global $wpdb;
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) { return 0; }

		$ok = $wpdb->insert(
			$wpdb->prefix . 'atora_email_sequences',
			array(
				'name'        => $name,
				'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'status'      => 'draft',
				'created_by'  => absint( get_current_user_id() ),
			),
			array( '%s', '%s', '%s', '%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get_all( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_email_sequences';
		if ( ! self::table_exists( $table ) ) { return array(); }

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*,
				 (SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_sequence_steps st WHERE st.sequence_id = s.id) AS step_count,
				 (SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'active') AS active_enrollments
				 FROM {$table} s ORDER BY s.created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_sequence' ), $rows );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_email_sequences';
		if ( ! self::table_exists( $table ) ) { return null; }

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		if ( empty( $row ) ) { return null; }
		$seq          = self::format_sequence( $row );
		$seq['steps'] = self::get_steps( $id );
		return $seq;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_email_sequences';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		if ( empty( $row ) || ! in_array( $row['status'], array( 'draft', 'paused' ), true ) ) { return false; }

		$update = array();
		$format = array();
		if ( isset( $data['name'] ) )        { $update['name']        = sanitize_text_field( (string) $data['name'] );        $format[] = '%s'; }
		if ( isset( $data['description'] ) ) { $update['description'] = sanitize_textarea_field( (string) $data['description'] ); $format[] = '%s'; }
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'draft', 'active', 'paused' ), true ) ) {
			$update['status'] = sanitize_key( (string) $data['status'] ); $format[] = '%s';
		}
		if ( empty( $update ) ) { return false; }
		return false !== $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
	}

	// ── Pasos ────────────────────────────────────────────────────────────────

	public static function get_steps( int $sequence_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_email_sequence_steps';
		if ( ! self::table_exists( $table ) ) { return array(); }

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sequence_id = %d ORDER BY step_order ASC",
				$sequence_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_step' ), $rows );
	}

	public static function save_step( int $sequence_id, array $data, int $step_id = 0 ) {
		global $wpdb;
		if ( ! $sequence_id ) { return false; }
		$table = $wpdb->prefix . 'atora_email_sequence_steps';

		$row = array(
			'sequence_id'    => $sequence_id,
			'step_order'     => absint( $data['step_order'] ?? 1 ),
			'delay_hours'    => absint( $data['delay_hours'] ?? 24 ),
			'send_condition' => in_array( $data['send_condition'] ?? 'always', array( 'always', 'opened', 'not_opened' ), true )
				? sanitize_key( (string) $data['send_condition'] ) : 'always',
			'template_key'   => sanitize_key( (string) ( $data['template_key'] ?? 'crm_campaign' ) ),
			'subject'        => sanitize_text_field( (string) ( $data['subject'] ?? '' ) ),
			'message'        => sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ),
			'cta_url'        => esc_url_raw( (string) ( $data['cta_url'] ?? '' ) ),
			'identity'       => sanitize_key( (string) ( $data['identity'] ?? 'academia' ) ),
		);
		$format = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $step_id > 0 ) {
			$ok = $wpdb->update( $table, $row, array( 'id' => $step_id ), $format, array( '%d' ) );
			return false !== $ok ? $step_id : false;
		}
		$ok = $wpdb->insert( $table, $row, $format );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function delete_step( int $step_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $wpdb->prefix . 'atora_email_sequence_steps', array( 'id' => $step_id ), array( '%d' ) );
	}

	// ── Enrollments ──────────────────────────────────────────────────────────

	public static function enroll_contact( int $sequence_id, int $contact_id ) {
		global $wpdb;
		$enr_table = $wpdb->prefix . 'atora_email_sequence_enrollments';
		$seq_table = $wpdb->prefix . 'atora_email_sequences';
		if ( ! $sequence_id || ! $contact_id ) { return 0; }
		if ( ! self::table_exists( $enr_table ) ) { return 0; }

		$seq_status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$seq_table} WHERE id = %d LIMIT 1", $sequence_id )
		);
		if ( 'active' !== $seq_status ) { return 0; }

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$enr_table} WHERE sequence_id = %d AND contact_id = %d LIMIT 1",
				$sequence_id, $contact_id
			),
			ARRAY_A
		);

		if ( $existing && 'active' === $existing['status'] ) { return (int) $existing['id']; }

		$first_step  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT delay_hours FROM {$wpdb->prefix}atora_email_sequence_steps
				 WHERE sequence_id = %d ORDER BY step_order ASC LIMIT 1",
				$sequence_id
			),
			ARRAY_A
		);
		$next_run_at = gmdate( 'Y-m-d H:i:s', time() + absint( $first_step['delay_hours'] ?? 0 ) * HOUR_IN_SECONDS );

		if ( $existing ) {
			$wpdb->update( $enr_table, array( 'current_step' => 1, 'status' => 'active', 'next_run_at' => $next_run_at ), array( 'id' => (int) $existing['id'] ), array( '%d', '%s', '%s' ), array( '%d' ) );
			return (int) $existing['id'];
		}

		$ok = $wpdb->insert(
			$enr_table,
			array( 'sequence_id' => $sequence_id, 'contact_id' => $contact_id, 'current_step' => 1, 'status' => 'active', 'next_run_at' => $next_run_at ),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get_contact_enrollments( int $contact_id ): array {
		global $wpdb;
		$enr = $wpdb->prefix . 'atora_email_sequence_enrollments';
		$seq = $wpdb->prefix . 'atora_email_sequences';
		if ( ! self::table_exists( $enr ) ) { return array(); }

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, s.name AS sequence_name, s.status AS sequence_status
				 FROM {$enr} e LEFT JOIN {$seq} s ON s.id = e.sequence_id
				 WHERE e.contact_id = %d AND e.status = 'active' ORDER BY e.enrolled_at DESC",
				$contact_id
			),
			ARRAY_A
		);
	}

	public static function stop_enrollment( int $sequence_id, int $contact_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_email_sequence_enrollments';
		if ( ! self::table_exists( $table ) ) { return false; }
		return false !== $wpdb->update(
			$table,
			array( 'status' => 'stopped' ),
			array( 'sequence_id' => $sequence_id, 'contact_id' => $contact_id, 'status' => 'active' ),
			array( '%s' ), array( '%d', '%d', '%s' )
		);
	}

	// ── Suppression ──────────────────────────────────────────────────────────

	public static function suppress( string $email, string $reason = 'unsubscribe', int $contact_id = 0 ): bool {
		global $wpdb;
		$email  = sanitize_email( $email );
		$reason = in_array( $reason, array( 'unsubscribe', 'bounce', 'spam', 'manual' ), true ) ? $reason : 'manual';
		if ( '' === $email || ! is_email( $email ) ) { return false; }

		$table = $wpdb->prefix . 'atora_email_suppression';
		if ( ! self::table_exists( $table ) ) { return false; }

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email ) );
		if ( $existing ) {
			$wpdb->update( $table, array( 'reason' => $reason ), array( 'email' => $email ), array( '%s' ), array( '%s' ) );
			return true;
		}
		return false !== $wpdb->insert( $table, array( 'email' => $email, 'reason' => $reason, 'contact_id' => $contact_id ), array( '%s', '%s', '%d' ) );
	}

	public static function is_suppressed( string $email ): bool {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( '' === $email ) { return false; }
		$table = $wpdb->prefix . 'atora_email_suppression';
		if ( ! self::table_exists( $table ) ) { return false; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s LIMIT 1", $email ) ) > 0;
	}

	public static function unsuppress( string $email ): bool {
		global $wpdb;
		$email = sanitize_email( $email );
		$table = $wpdb->prefix . 'atora_email_suppression';
		if ( ! self::table_exists( $table ) ) { return false; }
		return false !== $wpdb->delete( $table, array( 'email' => $email ), array( '%s' ) );
	}

	public static function get_suppressed( int $limit = 50, int $offset = 0, string $search = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_email_suppression';
		if ( ! self::table_exists( $table ) ) { return array( 'items' => array(), 'total' => 0 ); }

		$where  = '';
		$params = array();
		if ( '' !== $search ) {
			$where    = 'WHERE email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_email( $search ) ) . '%';
		}

		$params_c = $params;
		$total    = ! empty( $params_c )
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params_c ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$params[] = $limit;
		$params[] = $offset;
		$items    = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...$params ),
			ARRAY_A
		);
		return array( 'items' => $items, 'total' => $total );
	}

	// ── Cron ────────────────────────────────────────────────────────────────

	public static function process_due_steps(): void {
		global $wpdb;
		$enr_table = $wpdb->prefix . 'atora_email_sequence_enrollments';
		if ( ! self::table_exists( $enr_table ) ) { return; }

		$lock_key = 'atora_seq_cron_lock';
		if ( get_transient( $lock_key ) ) { return; }
		set_transient( $lock_key, 1, 55 * MINUTE_IN_SECONDS );

		$due = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT e.*, c.email AS contact_email, c.user_id AS contact_user_id
				 FROM {$enr_table} e
				 LEFT JOIN {$wpdb->prefix}atora_contacts c ON c.id = e.contact_id
				 WHERE e.status = 'active' AND e.next_run_at <= %s
				 ORDER BY e.next_run_at ASC LIMIT 50",
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		foreach ( $due as $enrollment ) {
			self::process_step( $enrollment );
		}

		delete_transient( $lock_key );
	}

	private static function process_step( array $enrollment ): void {
		global $wpdb;
		$sequence_id  = absint( $enrollment['sequence_id'] );
		$contact_id   = absint( $enrollment['contact_id'] );
		$current_step = absint( $enrollment['current_step'] );
		$enr_id       = absint( $enrollment['id'] );
		$email        = sanitize_email( (string) ( $enrollment['contact_email'] ?? '' ) );
		$user_id      = absint( $enrollment['contact_user_id'] ?? 0 );

		$enr_table  = $wpdb->prefix . 'atora_email_sequence_enrollments';
		$step_table = $wpdb->prefix . 'atora_email_sequence_steps';

		$step = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$step_table} WHERE sequence_id = %d AND step_order = %d LIMIT 1",
				$sequence_id, $current_step
			),
			ARRAY_A
		);

		if ( empty( $step ) ) {
			$wpdb->update( $enr_table, array( 'status' => 'completed' ), array( 'id' => $enr_id ), array( '%s' ), array( '%d' ) );
			return;
		}

		if ( $email && ! self::is_suppressed( $email ) ) {
			if ( class_exists( '\ATORA\CRM_V2\Services\CRM_Email_Service' ) ) {
				CRM_Email_Service::enqueue_campaign_email_result(
					$email, $user_id,
					sanitize_text_field( (string) ( $step['subject'] ?? '' ) ),
					(string) ( $step['message'] ?? '' ),
					esc_url_raw( (string) ( $step['cta_url'] ?? '' ) ),
					array( 'identity' => sanitize_key( (string) ( $step['identity'] ?? 'academia' ) ), 'source' => 'sequence', 'contact_id' => $contact_id )
				);
			}
		}

		$next_step = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT step_order FROM {$step_table} WHERE sequence_id = %d AND step_order > %d ORDER BY step_order ASC LIMIT 1",
				$sequence_id, $current_step
			)
		);

		if ( ! $next_step ) {
			$wpdb->update( $enr_table, array( 'status' => 'completed', 'next_run_at' => null ), array( 'id' => $enr_id ), array( '%s', '%s' ), array( '%d' ) );
			return;
		}

		$next_delay  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT delay_hours FROM {$step_table} WHERE sequence_id = %d AND step_order = %d LIMIT 1", $sequence_id, $next_step ) );
		$next_run_at = gmdate( 'Y-m-d H:i:s', time() + max( 1, $next_delay ) * HOUR_IN_SECONDS );
		$wpdb->update( $enr_table, array( 'current_step' => $next_step, 'next_run_at' => $next_run_at ), array( 'id' => $enr_id ), array( '%d', '%s' ), array( '%d' ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function format_sequence( array $row ): array {
		return array(
			'id'                 => absint( $row['id'] ),
			'name'               => sanitize_text_field( (string) ( $row['name']        ?? '' ) ),
			'description'        => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
			'status'             => sanitize_key( (string) ( $row['status']       ?? 'draft' ) ),
			'created_by'         => absint( $row['created_by']         ?? 0 ),
			'step_count'         => absint( $row['step_count']         ?? 0 ),
			'active_enrollments' => absint( $row['active_enrollments'] ?? 0 ),
			'created_at'         => sanitize_text_field( (string) ( $row['created_at']  ?? '' ) ),
		);
	}

	private static function format_step( array $row ): array {
		return array(
			'id'             => absint( $row['id'] ),
			'sequence_id'    => absint( $row['sequence_id'] ),
			'step_order'     => absint( $row['step_order'] ),
			'delay_hours'    => absint( $row['delay_hours'] ),
			'send_condition' => sanitize_key( (string) ( $row['send_condition'] ?? 'always' ) ),
			'template_key'   => sanitize_key( (string) ( $row['template_key']   ?? 'crm_campaign' ) ),
			'subject'        => sanitize_text_field( (string) ( $row['subject']  ?? '' ) ),
			'message'        => (string) ( $row['message'] ?? '' ),
			'cta_url'        => esc_url_raw( (string) ( $row['cta_url']          ?? '' ) ),
			'identity'       => sanitize_key( (string) ( $row['identity']        ?? 'academia' ) ),
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}
}
