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
	const META_KEY            = '_atora_mobile_sessions_v1'; // legacy (migración).
	const TABLE               = 'atora_mobile_sessions';
	const ACCESS_TTL          = 15 * MINUTE_IN_SECONDS;
	const REFRESH_TTL         = 30 * DAY_IN_SECONDS;
	const MAX_ACTIVE_SESSIONS = 5;
	const LAST_USED_THROTTLE  = 300; // 5 min.
	const ROTATE_GRACE        = 60;  // 60s.

	public static function issue( int $user_id, string $device_name = '' ): array {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$session_id     = bin2hex( random_bytes( 16 ) );
		$access_secret  = self::random_secret();
		$refresh_secret = self::random_secret();

		if ( self::table_ready() ) {
			self::maybe_migrate_from_usermeta( $user_id );

			$now_ts = time();
			$access_expires  = gmdate( 'Y-m-d H:i:s', $now_ts + self::ACCESS_TTL );
			$refresh_expires = gmdate( 'Y-m-d H:i:s', $now_ts + self::REFRESH_TTL );

			$table = $wpdb->prefix . self::TABLE;
			$institution_id = absint( (int) get_option( 'atora_default_institution', 0 ) );
			$app_version = isset( $_SERVER['HTTP_X_ATORA_APP_VERSION'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_X_ATORA_APP_VERSION'] ) : '';

			$wpdb->insert(
				$table,
				array(
					'session_id'      => $session_id,
					'user_id'         => $user_id,
					'institution_id'  => $institution_id,
					'access_hash'     => self::hash_secret( $access_secret ),
					'refresh_hash'    => self::hash_secret( $refresh_secret ),
					'prev_access_hash'=> '',
					'prev_refresh_hash'=> '',
					'access_expires'  => $access_expires,
					'refresh_expires' => $refresh_expires,
					'grace_until'     => null,
					'device_name'     => sanitize_text_field( $device_name ),
					'app_version'     => $app_version,
					'last_used_at'    => gmdate( 'Y-m-d H:i:s', $now_ts ),
				),
				array( '%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
			);

			self::enforce_limit( $user_id );
		} else {
			// Fallback legacy (sin tabla).
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
		}

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
		global $wpdb;

		$parts = self::parse_token( $token );
		if ( ! $parts ) {
			return new WP_Error( 'atora_mobile_invalid_token', __( 'Token móvil inválido.', 'atora-lms' ), array( 'status' => 401 ) );
		}

		list( $user_id, $session_id, $secret ) = $parts;
		$hash_key = 'refresh' === $kind ? 'refresh_hash' : 'access_hash';
		$exp_key  = 'refresh' === $kind ? 'refresh_expires' : 'access_expires';

		if ( self::table_ready() ) {
			$table = $wpdb->prefix . self::TABLE;
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s AND user_id = %d LIMIT 1", $session_id, $user_id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'atora_mobile_session_not_found', __( 'La sesión móvil no existe o fue revocada.', 'atora-lms' ), array( 'status' => 401 ) );
			}

			$now_ts = time();
			$now_mysql = gmdate( 'Y-m-d H:i:s', $now_ts );
			$exp_mysql = (string) ( $row[ $exp_key ] ?? '' );
			if ( '' === $exp_mysql || strtotime( $exp_mysql . ' UTC' ) < $now_ts ) {
				if ( 'refresh' === $kind ) {
					self::revoke_session( $user_id, $session_id );
				}
				return new WP_Error( 'atora_mobile_token_expired', __( 'El token móvil expiró.', 'atora-lms' ), array( 'status' => 401 ) );
			}

			$revoked_at = (string) ( $row['revoked_at'] ?? '' );
			$grace_until = (string) ( $row['grace_until'] ?? '' );
			$in_grace = '' !== $grace_until && strtotime( $grace_until . ' UTC' ) >= $now_ts;
			if ( '' !== $revoked_at && ! $in_grace ) {
				return new WP_Error( 'atora_mobile_session_evicted', __( 'La sesión móvil fue expulsada.', 'atora-lms' ), array( 'status' => 401 ) );
			}

			$expected = (string) ( $row[ $hash_key ] ?? '' );
			$prev_expected = (string) ( $row[ 'refresh' === $kind ? 'prev_refresh_hash' : 'prev_access_hash' ] ?? '' );
			$secret_hash_new = self::hash_secret( $secret );
			$secret_hash_old = self::legacy_hash_secret( $secret );

			$ok = ( '' !== $expected && ( hash_equals( $expected, $secret_hash_new ) || hash_equals( $expected, $secret_hash_old ) ) );
			if ( ! $ok && $in_grace && '' !== $prev_expected ) {
				$ok = hash_equals( $prev_expected, $secret_hash_new ) || hash_equals( $prev_expected, $secret_hash_old );
			}
			if ( ! $ok ) {
				return new WP_Error( 'atora_mobile_invalid_token', __( 'Token móvil inválido.', 'atora-lms' ), array( 'status' => 401 ) );
			}

			if ( ! get_userdata( $user_id ) ) {
				self::revoke_session( $user_id, $session_id );
				return new WP_Error( 'atora_mobile_user_not_found', __( 'El usuario de la sesión ya no existe.', 'atora-lms' ), array( 'status' => 401 ) );
			}

			// Throttling: 1 escritura/5min por sesión.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET last_used_at = %s
					 WHERE session_id = %s AND user_id = %d
					   AND (last_used_at IS NULL OR last_used_at < DATE_SUB(%s, INTERVAL 5 MINUTE))",
					$now_mysql,
					$session_id,
					$user_id,
					$now_mysql
				)
			);

			return array(
				'user_id'    => $user_id,
				'session_id' => $session_id,
				'device_name'=> (string) ( $row['device_name'] ?? '' ),
			);
		}

		// Legacy validate (usermeta).
		$sessions = self::get_sessions( $user_id );
		$session  = $sessions[ $session_id ] ?? null;

		if ( ! is_array( $session ) || empty( $session[ $hash_key ] ) || empty( $session[ $exp_key ] ) ) {
			return new WP_Error( 'atora_mobile_session_not_found', __( 'La sesión móvil no existe o fue revocada.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		if ( (int) $session[ $exp_key ] < time() ) {
			if ( 'refresh' === $kind ) {
				self::revoke_session( $user_id, $session_id );
			}
			return new WP_Error( 'atora_mobile_token_expired', __( 'El token móvil expiró.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		if ( ! hash_equals( (string) $session[ $hash_key ], self::hash_secret( $secret ) ) && ! hash_equals( (string) $session[ $hash_key ], self::legacy_hash_secret( $secret ) ) ) {
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
		global $wpdb;

		$validated = self::validate( $refresh_token, 'refresh' );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$user_id    = absint( $validated['user_id'] );
		$session_id = (string) $validated['session_id'];

		// Tabla (preferida): rotación con gracia e idempotencia bajo concurrencia.
		if ( self::table_ready() ) {
			$table = $wpdb->prefix . self::TABLE;
			$parts = self::parse_token( $refresh_token );
			$secret = is_array( $parts ) ? (string) $parts[2] : '';

			$now_ts   = time();
			$now_mysql = gmdate( 'Y-m-d H:i:s', $now_ts );
			$grace_mysql = gmdate( 'Y-m-d H:i:s', $now_ts + self::ROTATE_GRACE );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'START TRANSACTION' );
			try {
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s AND user_id = %d FOR UPDATE", $session_id, $user_id ),
					ARRAY_A
				);
				if ( ! is_array( $row ) ) {
					throw new \RuntimeException( 'Sesión no encontrada.' );
				}

				$expected = (string) ( $row['refresh_hash'] ?? '' );
				$prev_expected = (string) ( $row['prev_refresh_hash'] ?? '' );
				$grace_until = (string) ( $row['grace_until'] ?? '' );
				$in_grace = '' !== $grace_until && strtotime( $grace_until . ' UTC' ) >= $now_ts;
				$secret_hash_new = self::hash_secret( $secret );
				$secret_hash_old = self::legacy_hash_secret( $secret );

				$matches = ( '' !== $expected && ( hash_equals( $expected, $secret_hash_new ) || hash_equals( $expected, $secret_hash_old ) ) );
				if ( ! $matches && $in_grace && '' !== $prev_expected ) {
					$matches = hash_equals( $prev_expected, $secret_hash_new ) || hash_equals( $prev_expected, $secret_hash_old );
				}
				if ( ! $matches ) {
					throw new \RuntimeException( 'Refresh token inválido.' );
				}

				$access_secret_new  = self::random_secret();
				$refresh_secret_new = self::random_secret();
				$access_expires_new  = gmdate( 'Y-m-d H:i:s', $now_ts + self::ACCESS_TTL );
				$refresh_expires_new = gmdate( 'Y-m-d H:i:s', $now_ts + self::REFRESH_TTL );

				$updated = $wpdb->update(
					$table,
					array(
						'prev_access_hash'  => (string) ( $row['access_hash'] ?? '' ),
						'prev_refresh_hash' => (string) ( $row['refresh_hash'] ?? '' ),
						'access_hash'       => self::hash_secret( $access_secret_new ),
						'refresh_hash'      => self::hash_secret( $refresh_secret_new ),
						'access_expires'    => $access_expires_new,
						'refresh_expires'   => $refresh_expires_new,
						'grace_until'       => $grace_mysql,
						'device_name'       => sanitize_text_field( $device_name ?: (string) ( $row['device_name'] ?? '' ) ),
						'last_used_at'      => $now_mysql,
					),
					array( 'session_id' => $session_id, 'user_id' => $user_id ),
					array( '%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
					array( '%s','%d' )
				);
				if ( false === $updated ) {
					throw new \RuntimeException( 'No se pudo rotar la sesión.' );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'COMMIT' );

				return array(
					'access_token'       => self::compose_token( $user_id, $session_id, $access_secret_new ),
					'refresh_token'      => self::compose_token( $user_id, $session_id, $refresh_secret_new ),
					'token_type'         => 'Bearer',
					'expires_in'         => self::ACCESS_TTL,
					'refresh_expires_in' => self::REFRESH_TTL,
					'session_id'         => $session_id,
				);
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'atora_mobile_refresh_failed', $e->getMessage(), array( 'status' => 401 ) );
			}
		}

		// Legacy: revoca sesión y emite otra (best-effort).
		self::revoke_session( $user_id, $session_id );
		return self::issue( $user_id, $device_name ?: (string) $validated['device_name'] );
	}

	public static function revoke_token( string $token ): bool {
		$parts = self::parse_token( $token );
		if ( ! $parts ) {
			return false;
		}
		return self::revoke_session( (int) $parts[0], (string) $parts[1] );
	}

	public static function revoke_session( int $user_id, string $session_id ): bool {
		global $wpdb;

		$user_id = absint( $user_id );
		$session_id = sanitize_text_field( $session_id );
		if ( $user_id <= 0 || '' === $session_id ) {
			return false;
		}

		if ( self::table_ready() ) {
			$table = $wpdb->prefix . self::TABLE;
			$now_mysql = gmdate( 'Y-m-d H:i:s', time() );
			$updated = $wpdb->update(
				$table,
				array( 'revoked_at' => $now_mysql ),
				array( 'user_id' => $user_id, 'session_id' => $session_id ),
				array( '%s' ),
				array( '%d','%s' )
			);
			return false !== $updated;
		}

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
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! preg_match( '/^[a-f0-9]{24,32}$/', $parts[1] ) || strlen( $parts[2] ) < 32 ) {
			return null;
		}
		$user_id = absint( $parts[0] );
		return $user_id > 0 ? array( $user_id, $parts[1], $parts[2] ) : null;
	}

	private static function random_secret(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	private static function hash_secret( string $secret ): string {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	private static function legacy_hash_secret( string $secret ): string {
		return wp_hash( $secret );
	}

	private static function table_ready(): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	private static function maybe_migrate_from_usermeta( int $user_id ): void {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! self::table_ready() ) { return; }

		$legacy = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			return;
		}

		$table = $wpdb->prefix . self::TABLE;
		foreach ( $legacy as $session_id => $session ) {
			$session_id = is_string( $session_id ) ? $session_id : '';
			if ( '' === $session_id || ! preg_match( '/^[a-f0-9]{24,32}$/', $session_id ) ) { continue; }
			$session = is_array( $session ) ? $session : array();

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND user_id = %d", $session_id, $user_id )
			) > 0;
			if ( $exists ) { continue; }

			$access_hash  = (string) ( $session['access_hash'] ?? '' );
			$refresh_hash = (string) ( $session['refresh_hash'] ?? '' );
			$access_exp   = isset( $session['access_expires'] ) ? absint( $session['access_expires'] ) : 0;
			$refresh_exp  = isset( $session['refresh_expires'] ) ? absint( $session['refresh_expires'] ) : 0;
			$created_ts   = isset( $session['created_at'] ) ? absint( $session['created_at'] ) : 0;
			$last_used_ts = isset( $session['last_used_at'] ) ? absint( $session['last_used_at'] ) : 0;

			$wpdb->insert(
				$table,
				array(
					'session_id'      => $session_id,
					'user_id'         => $user_id,
					'institution_id'  => absint( (int) get_option( 'atora_default_institution', 0 ) ),
					'access_hash'     => $access_hash,
					'refresh_hash'    => $refresh_hash,
					'prev_access_hash'=> '',
					'prev_refresh_hash'=> '',
					'access_expires'  => $access_exp ? gmdate( 'Y-m-d H:i:s', $access_exp ) : null,
					'refresh_expires' => $refresh_exp ? gmdate( 'Y-m-d H:i:s', $refresh_exp ) : null,
					'grace_until'     => null,
					'device_name'     => sanitize_text_field( (string) ( $session['device_name'] ?? '' ) ),
					'app_version'     => '',
					'created_at'      => $created_ts ? gmdate( 'Y-m-d H:i:s', $created_ts ) : gmdate( 'Y-m-d H:i:s', time() ),
					'last_used_at'    => $last_used_ts ? gmdate( 'Y-m-d H:i:s', $last_used_ts ) : null,
					'revoked_at'      => null,
				)
			);
		}
	}

	private static function enforce_limit( int $user_id ): void {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! self::table_ready() ) { return; }

		$table = $wpdb->prefix . self::TABLE;
		$active = absint( (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND revoked_at IS NULL",
			$user_id
		) ) );
		if ( $active <= self::MAX_ACTIVE_SESSIONS ) { return; }

		$to_revoke = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT session_id FROM {$table}
			 WHERE user_id = %d AND revoked_at IS NULL
			 ORDER BY COALESCE(last_used_at, created_at) ASC, id ASC
			 LIMIT 1",
			$user_id
		) );
		if ( '' === $to_revoke ) { return; }

		$now_mysql = gmdate( 'Y-m-d H:i:s', time() );
		$wpdb->update(
			$table,
			array( 'revoked_at' => $now_mysql ),
			array( 'user_id' => $user_id, 'session_id' => $to_revoke ),
			array( '%s' ),
			array( '%d','%s' )
		);
	}
}
