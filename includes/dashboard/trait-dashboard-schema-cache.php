<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Dashboard_Schema_Cache_Trait {
	protected function get_dashboard_ui_schema( $user_id = 0 ) {
		if ( null !== self::$schema_cache ) {
			return self::$schema_cache;
		}

		$schema = array(
			'version'  => 1,
			'sections' => array(
				array( 'id' => 'hero', 'enabled' => true ),
				array( 'id' => 'onboarding', 'enabled' => true ),
				array( 'id' => 'next_step', 'enabled' => true ),
				array( 'id' => 'journey', 'enabled' => true ),
				array( 'id' => 'progress', 'enabled' => true ),
				array( 'id' => 'alerts', 'enabled' => true ),
				array( 'id' => 'incidents', 'enabled' => true ),
				array( 'id' => 'academic', 'enabled' => true ),
				array( 'id' => 'memory', 'enabled' => true ),
			),
			'limits'   => array(
				'course_cards'       => 3,
				'pending_items'      => 6,
				'feedback_items'     => 4,
				'notification_items' => 5,
				'lesson_items'       => 5,
				'alert_items'        => 2,
				'journey_items'      => 4,
			),
		);

		$schema_path = defined( 'ATORA_LMS_DIR' ) ? trailingslashit( ATORA_LMS_DIR ) . self::DASHBOARD_SCHEMA_FILE : '';
		if ( $schema_path && file_exists( $schema_path ) && is_readable( $schema_path ) ) {
			$raw = file_get_contents( $schema_path );
			$decoded = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['version'] ) ) {
					$schema['version'] = absint( $decoded['version'] );
				}
				if ( isset( $decoded['sections'] ) && is_array( $decoded['sections'] ) ) {
					$schema['sections'] = $decoded['sections'];
				}
				if ( isset( $decoded['limits'] ) && is_array( $decoded['limits'] ) ) {
					$schema['limits'] = array_merge( $schema['limits'], $decoded['limits'] );
				}
			}
		}

		$schema['limits'] = $this->normalize_dashboard_limits( $schema['limits'] );
		$schema['sections'] = $this->normalize_dashboard_sections( $schema['sections'] );

		$schema = apply_filters( 'clms_dashboard_ui_schema', $schema, $user_id );
		self::$schema_cache = $schema;

		return $schema;
	}

	protected function normalize_dashboard_limits( $limits ) {
		$limits = is_array( $limits ) ? $limits : array();
		$normalized = array();

		foreach ( $limits as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$normalized[ $key ] = max( 0, absint( $value ) );
		}

		return $normalized;
	}

	protected function normalize_dashboard_sections( $sections ) {
		$sections = is_array( $sections ) ? $sections : array();
		$normalized = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$id = isset( $section['id'] ) ? sanitize_key( (string) $section['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$normalized[] = array(
				'id'      => $id,
				'enabled' => ! array_key_exists( 'enabled', $section ) || (bool) $section['enabled'],
			);
		}

		return $normalized;
	}

	protected function resolve_dashboard_sections( $schema, $default_sections ) {
		$default_sections = is_array( $default_sections ) ? array_values( array_filter( $default_sections ) ) : array();
		$sections_config  = ( is_array( $schema ) && isset( $schema['sections'] ) ) ? $schema['sections'] : array();
		$ordered          = array();

		if ( ! empty( $sections_config ) ) {
			foreach ( $sections_config as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$id = isset( $section['id'] ) ? sanitize_key( (string) $section['id'] ) : '';
				if ( '' === $id || ! in_array( $id, $default_sections, true ) ) {
					continue;
				}
				if ( array_key_exists( 'enabled', $section ) && ! $section['enabled'] ) {
					continue;
				}
				$ordered[] = $id;
			}
		}

		if ( empty( $ordered ) ) {
			return $default_sections;
		}

		return $ordered;
	}

	protected function get_dashboard_limit( $schema, $key, $default ) {
		$default = max( 0, absint( $default ) );
		if ( ! is_array( $schema ) || empty( $schema['limits'] ) || ! is_array( $schema['limits'] ) ) {
			return $default;
		}
		$key = sanitize_key( (string) $key );
		if ( '' === $key || ! array_key_exists( $key, $schema['limits'] ) ) {
			return $default;
		}
		return max( 0, absint( $schema['limits'][ $key ] ) );
	}

	protected function get_dashboard_schema_hash( $schema ) {
		$schema = is_array( $schema ) ? $schema : array();
		$json   = function_exists( 'wp_json_encode' ) ? wp_json_encode( $schema ) : json_encode( $schema );
		return $json ? md5( $json ) : '';
	}

	// ── Caché ────────────────────────────────────────────────────────────────────

	protected function get_cache_key( $user_id ) {
		if ( class_exists( 'CLMS_Cache' ) ) {
			return CLMS_Cache::build_key( 'dashboard', array( $user_id ) );
		}
		return 'clms_dashboard_snapshot_' . absint( $user_id );
	}

	protected function get_cached_payload( $user_id ) {
		if ( class_exists( 'CLMS_Cache' ) ) {
			$cached = CLMS_Cache::get( 'dashboard', array( $user_id ), array() );
			return is_array( $cached ) ? $cached : array();
		}
		$key    = $this->get_cache_key( $user_id );
		$cached = get_transient( $key );
		return is_array( $cached ) ? $cached : array();
	}

	protected function set_cached_payload( $user_id, $payload ) {
		if ( ! $user_id || ! is_array( $payload ) ) {
			return;
		}
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::set( 'dashboard', array( $user_id ), $payload, self::CACHE_TTL );
			return;
		}
		set_transient( $this->get_cache_key( $user_id ), $payload, self::CACHE_TTL );
	}

	public function invalidate_cache_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::delete( 'dashboard', array( $user_id ) );
			return;
		}
		delete_transient( $this->get_cache_key( $user_id ) );
	}

	public function invalidate_cache_from_lesson( $user_id, $lesson_id ) {
		unset( $lesson_id );
		$this->invalidate_cache_for_user( $user_id );
	}

	public function invalidate_cache_from_submission( $submission_id, $lesson_id, $user_id ) {
		unset( $submission_id, $lesson_id );
		$this->invalidate_cache_for_user( $user_id );
	}

	public function invalidate_cache_from_profile_update( $user_id, $old_user_data ) {
		unset( $old_user_data );
		$this->invalidate_cache_for_user( $user_id );
	}

	public function invalidate_cache_from_profile_meta( $meta_id, $user_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );

		if ( ! $user_id ) {
			return;
		}

		$tracked = array(
			'description',
			'_clms_student_interests',
			'_clms_student_level',
			'_clms_student_goals',
			'_clms_student_area',
			'_clms_student_personalization_consent',
		);

		if ( in_array( (string) $meta_key, $tracked, true ) ) {
			$this->invalidate_cache_for_user( $user_id );
		}
	}

}
