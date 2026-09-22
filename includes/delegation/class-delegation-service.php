<?php
/**
 * Delegation service (E-10): instructor asistente por delegación.
 *
 * @package ATORA_LMS
 * @since   6.26.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Delegation_Service {

	/**
	 * Cache por-request de covers().
	 *
	 * @var array<string,bool>
	 */
	private static array $covers_cache = array();

	/**
	 * Cache por-request de instructores titulares delegantes.
	 *
	 * @var array<int, array<int,int>>
	 */
	private static array $delegating_cache = array();

	/**
	 * Determina si una delegación activa cubre un permiso sobre un curso/lesson.
	 *
	 * @param int    $user_id         ID del usuario (assistant).
	 * @param mixed  $post_or_course  WP_Post|int (lm_course/lm_lesson) o ID del post.
	 * @param string $perm            content|moderate|enroll|grade|access.
	 * @return bool
	 */
	public static function covers( int $user_id, $post_or_course, string $perm = 'content' ): bool {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return false; }

		$perm = sanitize_key( $perm );
		if ( '' === $perm ) { $perm = 'content'; }

		if ( ! class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			return false;
		}
		$inst = \ATORA\LMS\Tenant_Context::require_current_institution_id();
		if ( is_wp_error( $inst ) ) { return false; }
		$institution_id = absint( $inst );
		if ( $institution_id <= 0 ) { return false; }

		$post = null;
		if ( is_numeric( $post_or_course ) ) {
			$post = get_post( absint( $post_or_course ) );
		} elseif ( is_object( $post_or_course ) && $post_or_course instanceof WP_Post ) {
			$post = $post_or_course;
		}
		if ( ! $post ) { return false; }

		if ( ! in_array( (string) $post->post_type, array( 'lm_course', 'lm_lesson' ), true ) ) {
			return false;
		}

		$instructor_id = absint( $post->post_author );
		if ( $instructor_id <= 0 || $instructor_id === $user_id ) {
			return false;
		}

		$wp_course_id = 0;
		if ( 'lm_course' === (string) $post->post_type ) {
			$wp_course_id = absint( $post->ID );
		} elseif ( 'lm_lesson' === (string) $post->post_type ) {
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
				$wp_course_id = absint( \CLMS_Helper::get_course_id_from_lesson( absint( $post->ID ) ) );
			}
		}

		$key = implode( ':', array( (string) $institution_id, (string) $user_id, (string) $instructor_id, (string) $wp_course_id, (string) $post->ID, (string) $perm ) );
		if ( isset( self::$covers_cache[ $key ] ) ) {
			return self::$covers_cache[ $key ];
		}

		$table = $wpdb->prefix . 'atora_instructor_delegations';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			self::$covers_cache[ $key ] = false;
			return false;
		}

		$now = current_time( 'mysql', true );
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, scope, wp_course_id, perms_json, expires_at
				 FROM {$table}
				 WHERE institution_id = %d
				   AND instructor_id = %d
				   AND assistant_id = %d
				   AND status = 'active'
				   AND (expires_at IS NULL OR expires_at > %s)",
				$institution_id,
				$instructor_id,
				$user_id,
				$now
			),
			ARRAY_A
		);

		$covered = false;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$scope = sanitize_key( (string) ( $row['scope'] ?? 'instructor' ) );
			$row_wp_course_id = absint( $row['wp_course_id'] ?? 0 );

			if ( 'course' === $scope ) {
				if ( $wp_course_id <= 0 || $row_wp_course_id <= 0 || $wp_course_id !== $row_wp_course_id ) {
					continue;
				}
			} elseif ( 'instructor' !== $scope ) {
				continue;
			}

			$perms = self::normalize_perms( $row['perms_json'] ?? null );
			if ( ! empty( $perms[ $perm ] ) ) {
				$covered = true;
				break;
			}
		}

		self::$covers_cache[ $key ] = $covered;
		return $covered;
	}

	/**
	 * IDs de instructores titulares que han delegado al usuario.
	 *
	 * @param int $user_id Usuario (assistant).
	 * @return array<int,int>
	 */
	public static function delegating_instructors( int $user_id ): array {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return array(); }

		if ( isset( self::$delegating_cache[ $user_id ] ) ) {
			return self::$delegating_cache[ $user_id ];
		}

		if ( ! class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			self::$delegating_cache[ $user_id ] = array();
			return array();
		}
		$inst = \ATORA\LMS\Tenant_Context::require_current_institution_id();
		if ( is_wp_error( $inst ) ) {
			self::$delegating_cache[ $user_id ] = array();
			return array();
		}
		$institution_id = absint( $inst );
		if ( $institution_id <= 0 ) {
			self::$delegating_cache[ $user_id ] = array();
			return array();
		}

		$table = $wpdb->prefix . 'atora_instructor_delegations';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			self::$delegating_cache[ $user_id ] = array();
			return array();
		}

		$now = current_time( 'mysql', true );
		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT instructor_id
				 FROM {$table}
				 WHERE institution_id = %d
				   AND assistant_id = %d
				   AND status = 'active'
				   AND (expires_at IS NULL OR expires_at > %s)",
				$institution_id,
				$user_id,
				$now
			)
		);

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $rows ) ) ) );
		self::$delegating_cache[ $user_id ] = $ids;
		return $ids;
	}

	/**
	 * Crea/actualiza una delegación (idempotente por clave única).
	 *
	 * @param int   $instructor_id Titular.
	 * @param int   $assistant_id  Asistente.
	 * @param array $args          scope, wp_course_id, perms, expires_at, created_by.
	 * @return int delegation_id
	 */
	public static function grant( int $instructor_id, int $assistant_id, array $args ): int {
		global $wpdb;

		$instructor_id = absint( $instructor_id );
		$assistant_id  = absint( $assistant_id );
		if ( $instructor_id <= 0 || $assistant_id <= 0 || $instructor_id === $assistant_id ) {
			return 0;
		}

		if ( ! class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			return 0;
		}
		$inst = \ATORA\LMS\Tenant_Context::require_current_institution_id();
		if ( is_wp_error( $inst ) ) { return 0; }
		$institution_id = absint( $inst );
		if ( $institution_id <= 0 ) { return 0; }

		$scope = sanitize_key( (string) ( $args['scope'] ?? 'instructor' ) );
		if ( ! in_array( $scope, array( 'instructor', 'course' ), true ) ) {
			$scope = 'instructor';
		}
		$wp_course_id = absint( $args['wp_course_id'] ?? ( $args['course_id'] ?? 0 ) );
		if ( 'course' === $scope && $wp_course_id <= 0 ) {
			return 0;
		}

		$created_by = absint( $args['created_by'] ?? $instructor_id );
		if ( $created_by <= 0 ) { $created_by = $instructor_id; }

		$expires_at = isset( $args['expires_at'] ) ? sanitize_text_field( (string) $args['expires_at'] ) : '';
		if ( '' === trim( $expires_at ) ) {
			$expires_at = null;
		}

		$perms = is_array( $args['perms'] ?? null ) ? (array) $args['perms'] : array();
		$normalized = self::normalize_perms( $perms );
		$perms_json = wp_json_encode( $normalized );

		$table = $wpdb->prefix . 'atora_instructor_delegations';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}

		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE instructor_id = %d AND assistant_id = %d AND scope = %s AND wp_course_id = %d
				 LIMIT 1",
				$instructor_id,
				$assistant_id,
				$scope,
				$wp_course_id
			)
		);

		if ( $existing > 0 ) {
			$wpdb->update(
				$table,
				array(
					'institution_id' => $institution_id,
					'status'         => 'active',
					'perms_json'      => $perms_json,
					'expires_at'      => $expires_at,
					'revoked_by'      => 0,
					'revoked_at'      => null,
				),
				array( 'id' => $existing ),
				array( '%d','%s','%s','%s','%d','%s' ),
				array( '%d' )
			);
			self::audit( $institution_id, $created_by, $instructor_id, 0, 'delegation', $existing, 'grant', array(
				'assistant_id' => $assistant_id,
				'scope'        => $scope,
				'wp_course_id' => $wp_course_id,
				'perms'        => $normalized,
				'expires_at'   => $expires_at,
			) );
			return $existing;
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'institution_id' => $institution_id,
				'instructor_id'  => $instructor_id,
				'assistant_id'   => $assistant_id,
				'scope'          => $scope,
				'wp_course_id'   => $wp_course_id,
				'status'         => 'active',
				'perms_json'     => $perms_json,
				'created_by'     => $created_by,
				'expires_at'     => $expires_at,
			),
			array( '%d','%d','%d','%s','%d','%s','%s','%d','%s' )
		);
		if ( ! $ok ) { return 0; }

		$id = (int) $wpdb->insert_id;
		self::audit( $institution_id, $created_by, $instructor_id, 0, 'delegation', $id, 'grant', array(
			'assistant_id' => $assistant_id,
			'scope'        => $scope,
			'wp_course_id' => $wp_course_id,
			'perms'        => $normalized,
			'expires_at'   => $expires_at,
		) );
		return $id;
	}

	public static function revoke( int $delegation_id, int $actor_id ): bool {
		global $wpdb;

		$delegation_id = absint( $delegation_id );
		$actor_id      = absint( $actor_id );
		if ( $delegation_id <= 0 || $actor_id <= 0 ) { return false; }

		$table = $wpdb->prefix . 'atora_instructor_delegations';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $delegation_id ),
			ARRAY_A
		);
		if ( ! $row ) { return false; }

		$ok = $wpdb->update(
			$table,
			array(
				'status'     => 'revoked',
				'revoked_by' => $actor_id,
				'revoked_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $delegation_id ),
			array( '%s','%d','%s' ),
			array( '%d' )
		);

		if ( false === $ok ) { return false; }

		self::audit(
			absint( $row['institution_id'] ?? 0 ),
			$actor_id,
			absint( $row['instructor_id'] ?? 0 ),
			0,
			'delegation',
			$delegation_id,
			'revoke',
			array( 'assistant_id' => absint( $row['assistant_id'] ?? 0 ) )
		);

		return true;
	}

	public static function for_instructor( int $instructor_id ): array {
		global $wpdb;
		$instructor_id = absint( $instructor_id );
		if ( $instructor_id <= 0 ) { return array(); }

		$table = $wpdb->prefix . 'atora_instructor_delegations';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE instructor_id = %d
				 ORDER BY created_at DESC, id DESC",
				$instructor_id
			),
			ARRAY_A
		);

		return array_values( array_filter( array_map( static function( $row ) {
			return is_array( $row ) ? $row : null;
		}, $rows ) ) );
	}

	private static function normalize_perms( $raw ): array {
		$perms = array(
			'content'   => true,
			'moderate'  => true,
			'enroll'    => false,
			'grade'     => false,
			'access'    => false,
		);

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : $raw;
		}

		if ( is_array( $raw ) ) {
			foreach ( $perms as $key => $_default ) {
				if ( array_key_exists( $key, $raw ) ) {
					$perms[ $key ] = (bool) $raw[ $key ];
				}
			}
		}

		return $perms;
	}

	private static function audit( int $institution_id, int $actor_id, int $on_behalf_of, int $target_user_id, string $object_type, int $object_id, string $action, array $payload ): void {
		if ( class_exists( '\\ATORA\\LMS\\Tenancy_Audit_Service' ) ) {
			\ATORA\LMS\Tenancy_Audit_Service::record( $institution_id, $actor_id, $on_behalf_of, $target_user_id, $object_type, $object_id, $action, $payload );
			return;
		}

		global $wpdb;
		$institution_id = absint( $institution_id );
		$actor_id       = absint( $actor_id );
		$object_id      = absint( $object_id );
		if ( $institution_id <= 0 || $actor_id <= 0 || $object_id <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . 'atora_tenancy_audit';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'institution_id' => $institution_id,
				'cohort_id'      => 0,
				'actor_id'       => $actor_id,
				'on_behalf_of'   => absint( $on_behalf_of ),
				'target_user_id' => absint( $target_user_id ),
				'object_type'    => sanitize_key( $object_type ),
				'object_id'      => $object_id,
				'action'         => sanitize_key( $action ),
				'payload_json'   => wp_json_encode( $payload ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d','%d','%d','%d','%d','%s','%d','%s','%s','%s' )
		);
	}
}
