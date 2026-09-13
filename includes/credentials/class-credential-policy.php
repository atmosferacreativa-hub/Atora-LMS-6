<?php
/**
 * Reglas puras del motor institucional de credenciales.
 *
 * @package ATORA_LMS
 * @since 6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Credential_Policy {
	const STATUS_VALID              = 'valid';
	const STATUS_REVOCATION_PENDING = 'revocation_pending';
	const STATUS_REVOKED            = 'revoked';
	const STATUS_SUPERSEDED         = 'superseded';

	const DECISION_APPROVED = 'approved';
	const DECISION_REJECTED = 'rejected';

	public static function can_request_revocation( $status ): bool {
		return self::STATUS_VALID === sanitize_key( (string) $status );
	}

	public static function can_decide( $requester_id, $decider_id ): bool {
		return absint( $requester_id ) > 0 && absint( $decider_id ) > 0 && absint( $requester_id ) !== absint( $decider_id );
	}

	public static function is_decision( $decision ): bool {
		return in_array( sanitize_key( (string) $decision ), array( self::DECISION_APPROVED, self::DECISION_REJECTED ), true );
	}

	public static function can_reissue( $status ): bool {
		return in_array( sanitize_key( (string) $status ), array( self::STATUS_VALID, self::STATUS_REVOKED ), true );
	}

	public static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize( $item );
			}
		}
		return $value;
	}

	public static function snapshot_hash( array $snapshot ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonicalize( $snapshot ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
