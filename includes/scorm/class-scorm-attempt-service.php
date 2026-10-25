<?php
/**
 * SCORM: persistencia de intentos y valores CMI.
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-scorm-cmi.php';

final class CLMS_SCORM_Attempt_Service {
	private const DEFAULT_GRADE_STATUS = 'in_review';
	private const SUCCESS_STATUS_ORDER = array(
		'unknown' => 0,
		'failed'  => 1,
		'passed'  => 2,
	);
	private const COMPLETION_STATUS_ORDER = array(
		'unknown'    => 0,
		'incomplete' => 1,
		'completed'  => 2,
	);

	public static function table_attempts(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_scorm_attempts';
	}

	public static function table_cmi(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_scorm_cmi';
	}

	public static function table_commit_events(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_scorm_commit_events';
	}

	private function storage_unavailable( string $context ): WP_Error {
		if ( function_exists( 'error_log' ) ) {
			error_log( '[ATORA SCORM] ' . $context . ': error de base de datos' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return new WP_Error(
			'atora_scorm_storage_unavailable',
			__( 'No se pudo guardar. Reintenta en unos segundos.', 'atora-lms' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function start_or_resume_attempt( int $user_id, int $lesson_id, int $library_item_id, int $library_version_id, string $scorm_version, int $max_attempts = 0 ) {
		global $wpdb;

		$user_id            = absint( $user_id );
		$lesson_id          = absint( $lesson_id );
		$library_item_id    = absint( $library_item_id );
		$library_version_id = absint( $library_version_id );
		$scorm_version      = trim( (string) $scorm_version );
		$max_attempts       = max( 0, absint( $max_attempts ) );

		if ( ! $user_id || ! $lesson_id || ! $library_item_id || ! $library_version_id || '' === $scorm_version ) {
			return new WP_Error( 'clms_scorm_invalid_attempt_ref', __( 'Parámetros inválidos.', 'atora-lms' ) );
		}

		$institution_id = 0;
		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) && method_exists( '\\ATORA\\LMS\\Tenant_Context', 'current_institution_id' ) ) {
			$resolved = \ATORA\LMS\Tenant_Context::current_institution_id();
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$institution_id = absint( $resolved );
		}

		$table = self::table_attempts();
		$existing = $this->find_last_attempt_row( $user_id, $lesson_id, $library_item_id, $library_version_id );
		if ( ! empty( $existing ) && empty( $existing['is_complete'] ) ) {
			return $existing;
		}

		if ( $max_attempts > 0 ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND lesson_id = %d AND library_item_id = %d AND is_complete = 1",
					$user_id,
					$lesson_id,
					$library_item_id
				)
			);
			if ( $count >= $max_attempts ) {
				return new WP_Error( 'atora_scorm_no_attempts', __( 'No quedan intentos disponibles.', 'atora-lms' ), array( 'status' => 409 ) );
			}
		}

		$next_number = ! empty( $existing['attempt_number'] ) ? ( absint( $existing['attempt_number'] ) + 1 ) : 1;

		$ok = $wpdb->insert(
			$table,
			array(
				'institution_id'     => $institution_id,
				'user_id'            => $user_id,
				'lesson_id'          => $lesson_id,
				'library_item_id'    => $library_item_id,
				'library_version_id' => $library_version_id,
				'attempt_number'     => $next_number,
				'scorm_version'      => $scorm_version,
				'grade_status'       => self::DEFAULT_GRADE_STATUS,
				'completion_status'  => 'unknown',
				'success_status'     => 'unknown',
				'is_complete'        => 0,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( ! $ok ) {
			return $this->storage_unavailable( 'start_or_resume_attempt: insert' );
		}

		$attempt_id = (int) $wpdb->insert_id;
		$row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $attempt_id ), ARRAY_A );
		return $row;
	}

	/**
	 * Devuelve el intento abierto si existe (para que el lanzador pueda
	 * aplicar reglas de disponibilidad sin crear intentos nuevos).
	 *
	 * @return array<string,mixed>
	 */
	public function find_open_attempt( int $user_id, int $lesson_id, int $library_item_id, int $library_version_id ): array {
		$existing = $this->find_last_attempt_row( $user_id, $lesson_id, $library_item_id, $library_version_id );
		return ( ! empty( $existing ) && empty( $existing['is_complete'] ) ) ? $existing : array();
	}

	/** @return array<string,mixed> */
	private function find_last_attempt_row( int $user_id, int $lesson_id, int $library_item_id, int $library_version_id ): array {
		global $wpdb;
		$table = self::table_attempts();
		return (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d AND library_item_id = %d AND library_version_id = %d ORDER BY attempt_number DESC LIMIT 1",
				absint( $user_id ),
				absint( $lesson_id ),
				absint( $library_item_id ),
				absint( $library_version_id )
			),
			ARRAY_A
		);
	}

	public function get_attempt_for_user( int $attempt_id, int $user_id ): array|WP_Error {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		$user_id    = absint( $user_id );
		if ( ! $attempt_id || ! $user_id ) {
			return new WP_Error( 'clms_scorm_invalid_attempt', __( 'Intento inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$table = self::table_attempts();
		$row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $attempt_id ), ARRAY_A );
		if ( empty( $row ) ) {
			return new WP_Error( 'clms_scorm_attempt_not_found', __( 'Intento no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( absint( $row['user_id'] ?? 0 ) !== $user_id ) {
			return new WP_Error( 'rest_forbidden', __( 'No tienes permiso.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		return $row;
	}

	/**
	 * @param array<string,string> $values
	 * @return array{attempt:array<string,mixed>,derived:array<string,mixed>}|WP_Error
	 */
	public function commit_values( int $attempt_id, int $user_id, array $values ) {
		global $wpdb;

		$attempt = $this->get_attempt_for_user( $attempt_id, $user_id );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		$clean_values = array();
		foreach ( $values as $key => $value ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$clean_values[ $key ] = null === $value ? '' : (string) $value;
			}
		}
		$values = $clean_values;
		if ( empty( $values ) ) {
			return array( 'attempt' => $attempt, 'derived' => array() );
		}

		$cmi_table = self::table_cmi();
		foreach ( $values as $key => $value ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$cmi_table} (attempt_id, cmi_key, cmi_value) VALUES (%d, %s, %s)
					 ON DUPLICATE KEY UPDATE cmi_value = VALUES(cmi_value), updated_at = CURRENT_TIMESTAMP",
					absint( $attempt_id ),
					$key,
					$value
				)
			);
			if ( false === $ok ) {
				return $this->storage_unavailable( 'commit_values: cmi insert/update' );
			}
		}

		$derived = $this->derive_and_update_attempt_from_values( (array) $attempt, $values );
		if ( is_wp_error( $derived ) ) {
			return $derived;
		}

		$attempts_table = self::table_attempts();
		$fresh = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempts_table} WHERE id = %d LIMIT 1", $attempt_id ), ARRAY_A );
		return array( 'attempt' => $fresh ?: (array) $attempt, 'derived' => $derived );
	}

	/**
	 * @param array<string,mixed>  $attempt
	 * @param array<string,string> $values
	 * @return array<string,mixed>|WP_Error
	 */
	private function derive_and_update_attempt_from_values( array $attempt, array $values ) {
		global $wpdb;

		$scorm_version = (string) ( $attempt['scorm_version'] ?? '' );
		$derived       = CLMS_SCORM_CMI::derive_state_from_values( $scorm_version, $values + $this->extract_attempt_cmi_snapshot( $attempt ) );

		$update = array(
			'completion_status' => (string) ( $derived['completion_status'] ?? 'unknown' ),
			'success_status'    => (string) ( $derived['success_status'] ?? 'unknown' ),
			'is_complete'       => ! empty( $derived['is_complete'] ) ? 1 : 0,
		);

		$has_score = $this->commit_includes_score( $scorm_version, $values );
		if ( $has_score ) {
			$score = $this->extract_score_fields( $scorm_version, $values );
			$update = array_merge( $update, $score );
			if ( null !== ( $update['score_raw'] ?? null ) || null !== ( $update['score_max'] ?? null ) || null !== ( $update['score_min'] ?? null ) ) {
				$update['score_percent'] = $this->compute_percent( $update['score_raw'] ?? null, $update['score_min'] ?? null, $update['score_max'] ?? null );
			}
		}

		$has_time = $this->commit_includes_time( $scorm_version, $values );
		if ( $has_time ) {
			$update = array_merge( $update, $this->extract_time_fields( $scorm_version, $values ) );
		}

		$has_loc = $this->commit_includes_location_or_suspend( $scorm_version, $values );
		if ( $has_loc ) {
			$update = array_merge( $update, $this->extract_location_and_suspend( $scorm_version, $values ) );
		}

		$was_complete = ! empty( $attempt['is_complete'] );
		if ( $was_complete ) {
			$update['is_complete'] = 1;
			$update['completion_status'] = $this->prefer_non_regressing_status(
				sanitize_key( (string) ( $attempt['completion_status'] ?? 'unknown' ) ),
				sanitize_key( (string) ( $update['completion_status'] ?? 'unknown' ) ),
				self::COMPLETION_STATUS_ORDER
			);
			$update['success_status'] = $this->prefer_non_regressing_status(
				sanitize_key( (string) ( $attempt['success_status'] ?? 'unknown' ) ),
				sanitize_key( (string) ( $update['success_status'] ?? 'unknown' ) ),
				self::SUCCESS_STATUS_ORDER
			);
		}
		if ( ! $was_complete && ! empty( $update['is_complete'] ) ) {
			$update['ended_at'] = current_time( 'mysql', true );
			if ( $this->should_auto_release_grade( absint( $attempt['lesson_id'] ?? 0 ) ) ) {
				$update['grade_status'] = 'graded';
			}
		}

		$attempts_table = self::table_attempts();
		$updated = $wpdb->update(
			$attempts_table,
			$update,
			array( 'id' => absint( $attempt['id'] ?? 0 ) )
		);
		if ( false === $updated ) {
			return $this->storage_unavailable( 'derive_and_update_attempt_from_values: update attempt' );
		}

		if ( ! $was_complete && ! empty( $update['is_complete'] ) ) {
			$this->maybe_mark_lesson_completed( absint( $attempt['user_id'] ?? 0 ), absint( $attempt['lesson_id'] ?? 0 ), (string) ( $update['completion_status'] ?? '' ), (string) ( $update['success_status'] ?? '' ) );
		}

		return $derived;
	}

	/**
	 * Commit idempotente por evento del cliente (atora-mobile).
	 *
	 * Si llega el mismo `client_event_id` para el mismo intento, no se vuelven a
	 * escribir valores CMI ni se recalcula el intento: se devuelve la respuesta
	 * almacenada (si existe) o un snapshot del intento.
	 *
	 * @param array<string,string> $values
	 * @return array{attempt:array<string,mixed>,derived:array<string,mixed>}|WP_Error
	 */
	public function commit_values_with_client_event( int $attempt_id, int $user_id, array $values, string $client_event_id, string $device_time = '' ) {
		global $wpdb;
		$attempt_id       = absint( $attempt_id );
		$user_id          = absint( $user_id );
		$client_event_id  = sanitize_text_field( $client_event_id );
		$device_time      = sanitize_text_field( $device_time );
		if ( ! $attempt_id || ! $user_id || '' === $client_event_id ) {
			return new WP_Error( 'atora_scorm_invalid_event', __( 'Evento inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$attempt = $this->get_attempt_for_user( $attempt_id, $user_id );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		$table = self::table_commit_events();
		$existing = (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE attempt_id = %d AND client_event_id = %s LIMIT 1", $attempt_id, $client_event_id ),
			ARRAY_A
		);
		if ( ! empty( $existing ) ) {
			$response_json = (string) ( $existing['response_json'] ?? '' );
			if ( '' !== $response_json ) {
				$decoded = json_decode( $response_json, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
			return array( 'attempt' => (array) $attempt, 'derived' => array() );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'attempt_id'      => $attempt_id,
				'client_event_id' => $client_event_id,
				'device_time'     => $device_time,
				'response_json'   => '',
			),
			array( '%d', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return $this->storage_unavailable( 'commit_values_with_client_event: insert' );
		}

		$result = $this->commit_values( $attempt_id, $user_id, $values );
		if ( is_wp_error( $result ) ) {
			$wpdb->delete( $table, array( 'attempt_id' => $attempt_id, 'client_event_id' => $client_event_id ), array( '%d', '%s' ) );
			return $result;
		}

		$encoded = wp_json_encode( $result );
		$wpdb->update(
			$table,
			array( 'response_json' => $encoded ),
			array( 'attempt_id' => $attempt_id, 'client_event_id' => $client_event_id ),
			array( '%s' ),
			array( '%d', '%s' )
		);
		return $result;
	}

	/**
	 * @param array<string,int> $order
	 */
	private function prefer_non_regressing_status( string $current, string $next, array $order ): string {
		$current = sanitize_key( $current );
		$next    = sanitize_key( $next );
		$current_rank = $order[ $current ] ?? 0;
		$next_rank    = $order[ $next ] ?? 0;
		return $next_rank >= $current_rank ? $next : $current;
	}

	private function commit_includes_score( string $scorm_version, array $values ): bool {
		$keys = ( '1.2' === $scorm_version )
			? array( 'cmi.core.score.raw', 'cmi.core.score.min', 'cmi.core.score.max' )
			: array( 'cmi.score.raw', 'cmi.score.min', 'cmi.score.max', 'cmi.score.scaled' );
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				return true;
			}
		}
		return false;
	}

	private function commit_includes_time( string $scorm_version, array $values ): bool {
		$session_key = ( '1.2' === $scorm_version ) ? 'cmi.core.session_time' : 'cmi.session_time';
		$total_key   = ( '1.2' === $scorm_version ) ? 'cmi.core.total_time' : 'cmi.total_time';
		return array_key_exists( $session_key, $values ) || array_key_exists( $total_key, $values );
	}

	private function commit_includes_location_or_suspend( string $scorm_version, array $values ): bool {
		$location_key = ( '1.2' === $scorm_version ) ? 'cmi.core.lesson_location' : 'cmi.location';
		return array_key_exists( $location_key, $values ) || array_key_exists( 'cmi.suspend_data', $values );
	}

	/** @return array<string,string> */
	private function extract_attempt_cmi_snapshot( array $attempt ): array {
		$scorm_version = (string) ( $attempt['scorm_version'] ?? '' );
		if ( '1.2' === $scorm_version ) {
			return array(
				'cmi.core.lesson_status' => (string) ( $attempt['completion_status'] ?? 'unknown' ),
			);
		}
		return array(
			'cmi.completion_status' => (string) ( $attempt['completion_status'] ?? 'unknown' ),
			'cmi.success_status'    => (string) ( $attempt['success_status'] ?? 'unknown' ),
		);
	}

	/**
	 * @param array<string,string> $values
	 * @return array<string,mixed>
	 */
	private function extract_score_fields( string $scorm_version, array $values ): array {
		$raw = null;
		$min = null;
		$max = null;
		if ( '1.2' === $scorm_version ) {
			$raw = $this->parse_decimal( $values['cmi.core.score.raw'] ?? null );
			$min = $this->parse_decimal( $values['cmi.core.score.min'] ?? null );
			$max = $this->parse_decimal( $values['cmi.core.score.max'] ?? null );
		} else {
			$raw = $this->parse_decimal( $values['cmi.score.raw'] ?? null );
			$min = $this->parse_decimal( $values['cmi.score.min'] ?? null );
			$max = $this->parse_decimal( $values['cmi.score.max'] ?? null );
			if ( null === $raw ) {
				$scaled = $this->parse_decimal( $values['cmi.score.scaled'] ?? null );
				if ( null !== $scaled ) {
					$raw = $scaled * 100;
					$min = 0;
					$max = 100;
				}
			}
		}
		return array(
			'score_raw' => $raw,
			'score_min' => $min,
			'score_max' => $max,
		);
	}

	/**
	 * @param array<string,string> $values
	 * @return array<string,mixed>
	 */
	private function extract_time_fields( string $scorm_version, array $values ): array {
		$session_key = ( '1.2' === $scorm_version ) ? 'cmi.core.session_time' : 'cmi.session_time';
		$total_key   = ( '1.2' === $scorm_version ) ? 'cmi.core.total_time' : 'cmi.total_time';
		$session = $this->parse_time_to_seconds( $values[ $session_key ] ?? '' );
		$total   = $this->parse_time_to_seconds( $values[ $total_key ] ?? '' );
		return array(
			'session_time_seconds' => null !== $session ? $session : 0,
			'total_time_seconds'   => null !== $total ? $total : 0,
		);
	}

	/**
	 * @param array<string,string> $values
	 * @return array<string,mixed>
	 */
	private function extract_location_and_suspend( string $scorm_version, array $values ): array {
		$location_key = ( '1.2' === $scorm_version ) ? 'cmi.core.lesson_location' : 'cmi.location';
		$location = isset( $values[ $location_key ] ) ? (string) $values[ $location_key ] : '';
		$suspend = isset( $values['cmi.suspend_data'] ) ? (string) $values['cmi.suspend_data'] : null;
		return array(
			'lesson_location' => sanitize_text_field( $location ),
			'suspend_data'    => null !== $suspend ? (string) $suspend : null,
		);
	}

	private function parse_decimal( $value ): ?float {
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		return (float) $value;
	}

	private function compute_percent( $raw, $min, $max ): ?float {
		if ( null === $raw ) {
			return null;
		}
		$raw = (float) $raw;
		if ( null !== $max && null !== $min ) {
			$max = (float) $max;
			$min = (float) $min;
			if ( $max > $min ) {
				return max( 0.0, min( 100.0, ( ( $raw - $min ) / ( $max - $min ) ) * 100 ) );
			}
		}
		if ( null !== $max && (float) $max > 0 ) {
			$max = (float) $max;
			return max( 0.0, min( 100.0, ( $raw / $max ) * 100 ) );
		}
		return max( 0.0, min( 100.0, $raw ) );
	}

	private function parse_time_to_seconds( string $time ): ?int {
		$time = trim( $time );
		if ( '' === $time ) {
			return null;
		}
		// SCORM 1.2: HH:MM:SS(.SS)
		if ( preg_match( '/^(\d+):(\d{2}):(\d{2})(?:\.(\d+))?$/', $time, $m ) ) {
			$h = (int) $m[1];
			$min = (int) $m[2];
			$s = (int) $m[3];
			return max( 0, ( $h * 3600 ) + ( $min * 60 ) + $s );
		}
		// SCORM 2004: ISO 8601 duration (parcial), ej: PT1H2M3S
		if ( preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/i', $time, $m ) ) {
			$h = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
			$min = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
			$s = isset( $m[3] ) && '' !== $m[3] ? (float) $m[3] : 0.0;
			return max( 0, (int) floor( ( $h * 3600 ) + ( $min * 60 ) + $s ) );
		}
		return null;
	}

	private function should_auto_release_grade( int $lesson_id ): bool {
		$lesson_id = absint( $lesson_id );
		$opt = get_option( 'atora_scorm_auto_release', true );
		$opt = (bool) $opt;
		return (bool) apply_filters( 'atora_scorm_auto_release', $opt, $lesson_id );
	}

	private function maybe_mark_lesson_completed( int $user_id, int $wp_lesson_id, string $completion_status, string $success_status ): void {
		$user_id      = absint( $user_id );
		$wp_lesson_id = absint( $wp_lesson_id );
		if ( ! $user_id || ! $wp_lesson_id ) {
			return;
		}

		$rule = sanitize_key( (string) get_post_meta( $wp_lesson_id, '_clms_scorm_completion_rule', true ) );
		if ( '' === $rule ) {
			$rule = sanitize_key( (string) get_option( 'atora_scorm_completion_rule', 'both' ) );
		}
		if ( ! in_array( $rule, array( 'completed', 'passed', 'both' ), true ) ) {
			$rule = 'both';
		}

		$should_complete = false;
		if ( 'completed' === $rule ) {
			$should_complete = 'completed' === sanitize_key( $completion_status );
		} elseif ( 'passed' === $rule ) {
			$should_complete = 'passed' === sanitize_key( $success_status );
		} else {
			$should_complete = 'completed' === sanitize_key( $completion_status ) || 'passed' === sanitize_key( $success_status );
		}

		if ( ! $should_complete ) {
			return;
		}

		$atora_lesson_id = absint( apply_filters( 'atora_scorm_map_wp_lesson_to_atora_lesson', 0, $wp_lesson_id, $user_id ) );
		if ( ! $atora_lesson_id && class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) && method_exists( '\\ATORA\\LMS\\LMS_Course_Service', 'get_lesson_by_wp_post' ) ) {
			$atora_lesson = \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $wp_lesson_id );
			if ( is_array( $atora_lesson ) && ! empty( $atora_lesson['id'] ) ) {
				$atora_lesson_id = absint( $atora_lesson['id'] );
			}
		}
		if ( $atora_lesson_id ) {
			$override = apply_filters( 'atora_scorm_complete_lesson_override', null, $user_id, $atora_lesson_id, $wp_lesson_id );
			if ( is_callable( $override ) ) {
				call_user_func( $override, $user_id, $atora_lesson_id, $wp_lesson_id );
				return;
			}
			if ( class_exists( '\\ATORA\\LMS\\LMS_Write_Facade' ) && method_exists( '\\ATORA\\LMS\\LMS_Write_Facade', 'complete_lesson' ) ) {
				\ATORA\LMS\LMS_Write_Facade::complete_lesson( $user_id, $atora_lesson_id );
				return;
			}
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();
		$completed[] = $wp_lesson_id;
		update_user_meta( $user_id, '_clms_completed_lessons', array_values( array_unique( $completed ) ) );
	}
}

