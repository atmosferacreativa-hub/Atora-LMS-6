<?php
/**
 * Tokens opacos, revocables y rotables para clientes móviles ATORA.
 *
 * @package ATORA_LMS
 * @since 6.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Token_Service {
	const META_KEY            = '_atora_mobile_sessions_v1';
	const ACCESS_TTL          = 15 * MINUTE_IN_SECONDS;
	const REFRESH_TTL         = 30 * DAY_IN_SECONDS;
	const MAX_ACTIVE_SESSIONS = 5;

	public static function issue( int $user_id, string $device_name = '' ): array {
		$session_id    = bin2hex( random_bytes( 12 ) );
		$access_secret = self::random_secret();
		$refresh_secret = self::random_secret();
		$now           = time();

		$sessions = self::get_sessions( $user_id );
		$sessions = self::prune( $sessions, $now );
		$sessions[ $session_id ] = array(
			'access_hash'    => self::hash_secret( $access_secret ),
			'refresh_hash'   => self::hash_secret( $refresh_secret ),
			'access_expires' => $now + self::ACCESS_TTL,
			'refresh_expires'=> $now + self::REFRESH_TTL,
			'created_at'     => $now,
			'last_used_at'   => $now,
			'device_name'    => sanitize_text_field( $device_name ),
		);
		$sessions = self::limit_sessions( $sessions );
		update_user_meta( $user_id, self::META_KEY, $sessions );

		return array(
			'access_token'       => self::compose_token( $user_id, $session_id, $access_secret ),
			'refresh_token'      => self::compose_token( $user_id, $session_id, $refresh_secret ),
			'token_type'         => 'Bearer',
			'expires_in'         => self::ACCESS_TTL,
			'refresh_expires_in' => self::REFRESH_TTL,
			'session_id'         => $session_id,
		);
	}

	public static function validate( string $token, string $kind = 'access' ) {
		$parts = self::parse_token( $token );
		if ( ! $parts ) {
			return new WP_Error( 'atora_mobile_invalid_token', __( 'Token móvil inválido.', 'atora-lms' ), array( 'status' => 401 ) );
		}

		list( $user_id, $session_id, $secret ) = $parts;
		$sessions = self::get_sessions( $user_id );
		$session  = $sessions[ $session_id ] ?? null;
		$hash_key = 'refresh' === $kind ? 'refresh_hash' : 'access_hash';
		$exp_key  = 'refresh' === $kind ? 'refresh_expires' : 'access_expires';

		if ( ! is_array( $session ) || empty( $session[ $hash_key ] ) || empty( $session[ $exp_key ] ) ) {
			return new WP_Error( 'atora_mobile_session_not_found', __( 'La sesión móvil no existe o fue revocada.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		if ( (int) $session[ $exp_key ] < time() ) {
			if ( 'refresh' === $kind ) {
				self::revoke_session( $user_id, $session_id );
			}
			return new WP_Error( 'atora_mobile_token_expired', __( 'El token móvil expiró.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		if ( ! hash_equals( (string) $session[ $hash_key ], self::hash_secret( $secret ) ) ) {
			return new WP_Error( 'atora_mobile_invalid_token', __( 'Token móvil inválido.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		if ( ! get_userdata( $user_id ) ) {
			self::revoke_session( $user_id, $session_id );
			return new WP_Error( 'atora_mobile_user_not_found', __( 'El usuario de la sesión ya no existe.', 'atora-lms' ), array( 'status' => 401 ) );
		}

		$session['last_used_at'] = time();
		$sessions[ $session_id ] = $session;
		update_user_meta( $user_id, self::META_KEY, $sessions );

		return array(
			'user_id'    => $user_id,
			'session_id' => $session_id,
			'device_name'=> (string) ( $session['device_name'] ?? '' ),
		);
	}

	public static function rotate( string $refresh_token, string $device_name = '' ) {
		$validated = self::validate( $refresh_token, 'refresh' );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		self::revoke_session( (int) $validated['user_id'], (string) $validated['session_id'] );
		return self::issue(
			(int) $validated['user_id'],
			$device_name ?: (string) $validated['device_name']
		);
	}

	public static function revoke_token( string $token ): bool {
		$parts = self::parse_token( $token );
		if ( ! $parts ) {
			return false;
		}
		return self::revoke_session( (int) $parts[0], (string) $parts[1] );
	}

	public static function revoke_session( int $user_id, string $session_id ): bool {
		$sessions = self::get_sessions( $user_id );
		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}
		unset( $sessions[ $session_id ] );
		update_user_meta( $user_id, self::META_KEY, $sessions );
		return true;
	}

	public static function bearer_from_request( WP_REST_Request $request ): string {
		$header = trim( (string) $request->get_header( 'authorization' ) );
		if ( ! preg_match( '/^Bearer\s+([^\s]+)$/i', $header, $matches ) ) {
			return '';
		}
		return (string) $matches[1];
	}

	private static function get_sessions( int $user_id ): array {
		$sessions = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $sessions ) ? $sessions : array();
	}

	private static function prune( array $sessions, int $now ): array {
		foreach ( $sessions as $id => $session ) {
			if ( ! is_array( $session ) || (int) ( $session['refresh_expires'] ?? 0 ) < $now ) {
				unset( $sessions[ $id ] );
			}
		}
		return $sessions;
	}

	private static function limit_sessions( array $sessions ): array {
		if ( count( $sessions ) <= self::MAX_ACTIVE_SESSIONS ) {
			return $sessions;
		}
		uasort( $sessions, static function( array $a, array $b ): int {
			return (int) ( $a['last_used_at'] ?? 0 ) <=> (int) ( $b['last_used_at'] ?? 0 );
		} );
		return array_slice( $sessions, -self::MAX_ACTIVE_SESSIONS, null, true );
	}

	private static function compose_token( int $user_id, string $session_id, string $secret ): string {
		return $user_id . '.' . $session_id . '.' . $secret;
	}

	private static function parse_token( string $token ): ?array {
		$parts = explode( '.', trim( $token ), 3 );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! preg_match( '/^[a-f0-9]{24}$/', $parts[1] ) || strlen( $parts[2] ) < 32 ) {
			return null;
		}
		$user_id = absint( $parts[0] );
		return $user_id > 0 ? array( $user_id, $parts[1], $parts[2] ) : null;
	}

	private static function random_secret(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	private static function hash_secret( string $secret ): string {
		return wp_hash( $secret );
	}
}
