<?php
/**
 * Emisión, verificación, revocación y sustitución de credenciales.
 *
 * @package ATORA_LMS
 * @since 6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Credential_Service {
	const REVOCATION_CAPABILITY = 'manage_options';
	private static $hooks_registered = false;

	public function __construct() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'clms_certificate_issued', array( $this, 'capture_legacy_issuance' ), 15, 3 );
		add_action( 'clms_program_certificate_issued', array( $this, 'capture_program_issuance' ), 15, 3 );
	}

	public function issue( array $payload, $actor_id = 0 ) {
		global $wpdb;
		$user_id     = absint( $payload['user_id'] ?? 0 );
		$target_id   = absint( $payload['target_id'] ?? 0 );
		$target_type = sanitize_key( (string) ( $payload['target_type'] ?? 'course' ) );
		if ( ! $user_id || ! $target_id || ! in_array( $target_type, array( 'course', 'program' ), true ) ) {
			return new WP_Error( 'atora_credential_invalid_target', __( 'Alumno y objetivo académico son obligatorios.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'atora_credentials';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND target_type = %s AND target_id = %d AND status IN ('valid','revocation_pending') ORDER BY id DESC LIMIT 1", $user_id, $target_type, $target_id ), ARRAY_A );
		if ( is_array( $existing ) ) {
			return $existing;
		}

		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf( '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ), mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
		$snapshot = $this->build_snapshot( $payload );
		$now = current_time( 'mysql', true );
		$row = array(
			'credential_uuid'        => $uuid,
			'user_id'                => $user_id,
			'target_type'            => $target_type,
			'target_id'              => $target_id,
			'cycle_id'               => absint( $payload['cycle_id'] ?? 0 ),
			'cert_code'              => sanitize_text_field( (string) ( $payload['cert_code'] ?? '' ) ),
			'verification_token_hash'=> hash( 'sha256', strtolower( $uuid ) ),
			'status'                 => CLMS_Credential_Policy::STATUS_VALID,
			'snapshot_json'          => wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'snapshot_hash'          => CLMS_Credential_Policy::snapshot_hash( $snapshot ),
			'template_version'       => sanitize_text_field( (string) ( $payload['template_version'] ?? '1' ) ),
			'issued_by'              => absint( $actor_id ?: get_current_user_id() ),
			'issued_at'              => $now,
			'created_at'             => $now,
			'updated_at'             => $now,
		);
		if ( false === $wpdb->insert( $table, $row ) ) {
			return new WP_Error( 'atora_credential_issue_failed', __( 'No fue posible emitir la credencial.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$row['id'] = (int) $wpdb->insert_id;
		$this->append_event( $row['id'], 'issued', $row['issued_by'], array( 'snapshot_hash' => $row['snapshot_hash'] ) );
		do_action( 'atora_credential_issued', $row );
		return $row;
	}

	public function verify( $token ) {
		global $wpdb;
		$token = strtolower( sanitize_text_field( (string) $token ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $token ) ) {
			return new WP_Error( 'atora_credential_not_found', __( 'Credencial no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$table = $wpdb->prefix . 'atora_credentials';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE verification_token_hash = %s LIMIT 1", hash( 'sha256', $token ) ), ARRAY_A );
		if ( ! is_array( $row ) || ! hash_equals( strtolower( (string) $row['credential_uuid'] ), $token ) ) {
			return new WP_Error( 'atora_credential_not_found', __( 'Credencial no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$snapshot = json_decode( (string) $row['snapshot_json'], true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$integrity = hash_equals( (string) $row['snapshot_hash'], CLMS_Credential_Policy::snapshot_hash( $snapshot ) );
		return array(
			'credential_id' => (string) $row['credential_uuid'],
			'certificate_code' => (string) $row['cert_code'],
			'status' => (string) $row['status'],
			'holder_name' => sanitize_text_field( (string) ( $snapshot['holder_name'] ?? '' ) ),
			'achievement_name' => sanitize_text_field( (string) ( $snapshot['achievement_name'] ?? '' ) ),
			'issuer_name' => sanitize_text_field( (string) ( $snapshot['issuer_name'] ?? get_bloginfo( 'name' ) ) ),
			'issued_at' => (string) $row['issued_at'],
			'expires_at' => (string) ( $row['expires_at'] ?? '' ),
			'integrity' => $integrity ? 'verified' : 'failed',
			'superseded_by' => (string) ( $row['superseded_by_uuid'] ?? '' ),
		);
	}

	public function find_for_target( $user_id, $target_type, $target_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_credentials WHERE user_id = %d AND target_type = %s AND target_id = %d ORDER BY id DESC LIMIT 1", absint( $user_id ), sanitize_key( (string) $target_type ), absint( $target_id ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function request_revocation( $credential_id, $category, $reason, $actor_id ) {
		global $wpdb;
		$credential = $this->get_by_id( $credential_id );
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		$category = sanitize_key( (string) $category );
		if ( ! $credential || ! CLMS_Credential_Policy::can_request_revocation( $credential['status'] ) ) {
			return new WP_Error( 'atora_revocation_invalid_state', __( 'La credencial no admite una solicitud de revocación.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		if ( strlen( $reason ) < 20 || ! in_array( $category, array( 'academic_error', 'misconduct', 'identity_error', 'administrative' ), true ) ) {
			return new WP_Error( 'atora_revocation_invalid_reason', __( 'Indica una categoría válida y una justificación de al menos 20 caracteres.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_credentials SET status = %s, updated_at = %s WHERE id = %d AND status = %s", CLMS_Credential_Policy::STATUS_REVOCATION_PENDING, $now, absint( $credential_id ), CLMS_Credential_Policy::STATUS_VALID ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'atora_revocation_conflict', __( 'La credencial cambió mientras se procesaba la solicitud.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		$wpdb->insert( $wpdb->prefix . 'atora_credential_revocations', array( 'credential_id' => absint( $credential_id ), 'category' => $category, 'reason' => $reason, 'status' => 'requested', 'requested_by' => absint( $actor_id ), 'requested_at' => $now ) );
		$request_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );
		$this->append_event( absint( $credential_id ), 'revocation_requested', $actor_id, array( 'request_id' => $request_id, 'category' => $category ) );
		return array( 'request_id' => $request_id, 'status' => 'requested' );
	}

	public function decide_revocation( $request_id, $decision, $actor_id ) {
		global $wpdb;
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_credential_revocations WHERE id = %d LIMIT 1", absint( $request_id ) ), ARRAY_A );
		$decision = sanitize_key( (string) $decision );
		if ( ! $request || 'requested' !== $request['status'] || ! CLMS_Credential_Policy::is_decision( $decision ) ) {
			return new WP_Error( 'atora_revocation_invalid_request', __( 'Solicitud de revocación inválida o ya resuelta.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		if ( ! CLMS_Credential_Policy::can_decide( $request['requested_by'], $actor_id ) ) {
			return new WP_Error( 'atora_revocation_separation_required', __( 'Quien solicita una revocación no puede aprobarla ni rechazarla.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$now = current_time( 'mysql', true );
		$new_status = CLMS_Credential_Policy::DECISION_APPROVED === $decision ? CLMS_Credential_Policy::STATUS_REVOKED : CLMS_Credential_Policy::STATUS_VALID;
		$wpdb->query( 'START TRANSACTION' );
		$resolved = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_credential_revocations SET status = %s, decided_by = %d, decided_at = %s WHERE id = %d AND status = 'requested'", $decision, absint( $actor_id ), $now, absint( $request_id ) ) );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_credentials SET status = %s, revoked_at = %s, updated_at = %s WHERE id = %d AND status = %s", $new_status, CLMS_Credential_Policy::STATUS_REVOKED === $new_status ? $now : null, $now, absint( $request['credential_id'] ), CLMS_Credential_Policy::STATUS_REVOCATION_PENDING ) );
		if ( 1 !== $resolved || 1 !== $changed ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'atora_revocation_conflict', __( 'La solicitud cambió mientras se procesaba.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		$this->append_event( absint( $request['credential_id'] ), 'revocation_' . $decision, $actor_id, array( 'request_id' => absint( $request_id ) ) );
		return array( 'credential_id' => absint( $request['credential_id'] ), 'status' => $new_status );
	}

	public function reissue( $credential_id, array $overrides, $actor_id ) {
		global $wpdb;
		$old = $this->get_by_id( $credential_id );
		if ( ! $old || ! CLMS_Credential_Policy::can_reissue( $old['status'] ) ) {
			return new WP_Error( 'atora_reissue_invalid_state', __( 'La credencial no puede sustituirse.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		$snapshot = json_decode( (string) $old['snapshot_json'], true );
		$payload = array_merge( is_array( $snapshot ) ? $snapshot : array(), $overrides, array( 'user_id' => $old['user_id'], 'target_type' => $old['target_type'], 'target_id' => $old['target_id'], 'cycle_id' => $old['cycle_id'], 'cert_code' => '' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_credentials SET status = %s, updated_at = %s WHERE id = %d", CLMS_Credential_Policy::STATUS_SUPERSEDED, current_time( 'mysql', true ), absint( $credential_id ) ) );
		$new = $this->issue( $payload, $actor_id );
		if ( is_wp_error( $new ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_credentials SET status = %s WHERE id = %d", $old['status'], absint( $credential_id ) ) );
			return $new;
		}
		$wpdb->update( $wpdb->prefix . 'atora_credentials', array( 'superseded_by_uuid' => $new['credential_uuid'] ), array( 'id' => absint( $credential_id ) ) );
		$this->append_event( absint( $credential_id ), 'superseded', $actor_id, array( 'superseded_by' => $new['credential_uuid'] ) );
		return $new;
	}

	public function capture_legacy_issuance( $record, $user_id, $course_id ): void {
		if ( ! is_array( $record ) || 'program' === sanitize_key( (string) ( $record['target_type'] ?? '' ) ) || ! absint( $course_id ) ) {
			return;
		}
		$this->issue_from_legacy( $record, $user_id, 'course', $course_id );
	}

	public function capture_program_issuance( $record, $user_id, $program_id ): void {
		if ( is_array( $record ) ) {
			$this->issue_from_legacy( $record, $user_id, 'program', $program_id );
		}
	}

	private function issue_from_legacy( array $record, $user_id, $type, $target_id ): void {
		$user = get_userdata( absint( $user_id ) );
		$title = get_the_title( absint( $target_id ) );
		$issued = $this->issue( array( 'user_id' => $user_id, 'target_type' => $type, 'target_id' => $target_id, 'cert_code' => (string) ( $record['certificate_code'] ?? '' ), 'holder_name' => $user ? $user->display_name : '', 'achievement_name' => $title, 'issuer_name' => (string) ( $record['academy'] ?? get_bloginfo( 'name' ) ), 'grade' => $record['final_grade'] ?? $record['final_average'] ?? null, 'competencies' => $record['competencies_certified'] ?? array(), 'evidence' => array( 'completed' => absint( $record['completed_evidences_count'] ?? 0 ), 'required' => absint( $record['required_evidences_count'] ?? 0 ) ), 'legacy_verification_hash' => (string) ( $record['verification_hash'] ?? '' ) ), get_current_user_id() );
		if ( is_array( $issued ) && ! empty( $issued['credential_uuid'] ) ) {
			$record['credential_uuid'] = (string) $issued['credential_uuid'];
			$meta_key = 'program' === $type ? CLMS_Program_Certificate_Service::CERT_META_PREFIX . absint( $target_id ) : CLMS_Certificates::CERT_META_PREFIX . absint( $target_id );
			update_user_meta( absint( $user_id ), $meta_key, $record );
		}
	}

	private function build_snapshot( array $payload ): array {
		return CLMS_Credential_Policy::canonicalize( array( 'holder_name' => sanitize_text_field( (string) ( $payload['holder_name'] ?? '' ) ), 'achievement_name' => sanitize_text_field( (string) ( $payload['achievement_name'] ?? '' ) ), 'issuer_name' => sanitize_text_field( (string) ( $payload['issuer_name'] ?? get_bloginfo( 'name' ) ) ), 'target_type' => sanitize_key( (string) ( $payload['target_type'] ?? '' ) ), 'target_id' => absint( $payload['target_id'] ?? 0 ), 'cycle_id' => absint( $payload['cycle_id'] ?? 0 ), 'grade' => isset( $payload['grade'] ) ? (float) $payload['grade'] : null, 'competencies' => array_values( array_map( 'sanitize_text_field', (array) ( $payload['competencies'] ?? array() ) ) ), 'evidence' => is_array( $payload['evidence'] ?? null ) ? $payload['evidence'] : array(), 'issued_basis' => 'institutional_result' ) );
	}

	private function get_by_id( $credential_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_credentials WHERE id = %d LIMIT 1", absint( $credential_id ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private function append_event( $credential_id, $action, $actor_id, array $details ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_credential_events';
		$previous = (string) $wpdb->get_var( $wpdb->prepare( "SELECT event_hash FROM {$table} WHERE credential_id = %d ORDER BY id DESC LIMIT 1", absint( $credential_id ) ) );
		$created = current_time( 'mysql', true );
		$details_json = wp_json_encode( CLMS_Credential_Policy::canonicalize( $details ) );
		$event_hash = hash( 'sha256', $credential_id . '|' . sanitize_key( $action ) . '|' . absint( $actor_id ) . '|' . $created . '|' . $previous . '|' . $details_json );
		$wpdb->insert( $table, array( 'credential_id' => absint( $credential_id ), 'action' => sanitize_key( $action ), 'actor_id' => absint( $actor_id ), 'details_json' => $details_json, 'previous_hash' => $previous, 'event_hash' => $event_hash, 'created_at' => $created ) );
	}
}
