<?php
/**
 * H5P — Tracking xAPI y sincronización de nota (MVP).
 *
 * Objetivo: ATORA controla el tracking (tabla propia) aunque el render/editor
 * de H5P venga de un plugin externo. Esto permite analytics/gradebook sin
 * depender del storage interno de H5P.
 *
 * @package ATORA_LMS
 * @since   6.19.0
 */

namespace ATORA\H5P;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class H5P_Tracking_Service {

	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_h5p_tracking';
	}

	/**
	 * Upsert agregado por usuario/lección/contenido (compatibilidad con el esquema inicial).
	 *
	 * @param int                 $user_id
	 * @param int                 $lesson_id
	 * @param int                 $course_id
	 * @param int                 $content_id
	 * @param array<string,mixed> $statement
	 * @param array<string,mixed> $derived
	 * @return array{row_id:int,updated:bool,derived:array<string,mixed>}|WP_Error
	 */
	public function upsert_tracking( int $user_id, int $lesson_id, int $course_id, int $content_id, array $statement, array $derived ) {
		global $wpdb;

		$user_id    = absint( $user_id );
		$lesson_id  = absint( $lesson_id );
		$course_id  = absint( $course_id );
		$content_id = absint( $content_id );

		if ( ! $user_id || ! $lesson_id || ! $content_id ) {
			return new WP_Error( 'atora_h5p_invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$lock_key = 'atora_h5p_track_lock_' . $user_id . '_' . $lesson_id;
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'atora_h5p_rate_limited', __( 'Demasiadas solicitudes.', 'atora-lms' ), array( 'status' => 429 ) );
		}
		set_transient( $lock_key, 1, 2 );

		$now = current_time( 'mysql' );

		$verb_id   = sanitize_text_field( (string) ( $derived['verb_id'] ?? '' ) );
		$status    = sanitize_key( (string) ( $derived['completion_status'] ?? '' ) );
		$score_raw = isset( $derived['score_raw'] ) && is_numeric( $derived['score_raw'] ) ? (float) $derived['score_raw'] : null;
		$score_max = isset( $derived['score_max'] ) && is_numeric( $derived['score_max'] ) ? (float) $derived['score_max'] : null;
		$score_pct = isset( $derived['score_percent'] ) && is_numeric( $derived['score_percent'] ) ? absint( $derived['score_percent'] ) : null;

		$statement_json = wp_json_encode( $statement );
		if ( ! is_string( $statement_json ) ) {
			$statement_json = '{}';
		}
		if ( strlen( $statement_json ) > 20000 ) {
			$statement_json = substr( $statement_json, 0, 20000 );
		}

		$data_json = null;
		$extra = array(
			'success'  => isset( $derived['success'] ) ? (bool) $derived['success'] : null,
			'duration' => isset( $derived['duration'] ) ? (string) $derived['duration'] : '',
			'object_id'=> isset( $derived['object_id'] ) ? (string) $derived['object_id'] : '',
		);
		$data_json = wp_json_encode( $extra );
		if ( ! is_string( $data_json ) ) {
			$data_json = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE user_id = %d AND lesson_id = %d AND h5p_content_id = %d LIMIT 1",
				$user_id,
				$lesson_id,
				$content_id
			)
		);
		$existing_id = absint( $existing_id );

		$data = array(
			'user_id'            => $user_id,
			'lesson_id'          => $lesson_id,
			'course_id'          => $course_id,
			'h5p_content_id'     => $content_id,
			'last_verb'          => $verb_id,
			'completion_status'  => $status,
			'last_event_at'      => $now,
			'last_statement_json'=> $statement_json,
			'data_json'          => $data_json,
			'updated_at'         => $now,
		);

		if ( null !== $score_raw ) { $data['score_raw'] = $score_raw; }
		if ( null !== $score_max ) { $data['score_max'] = $score_max; }
		if ( null !== $score_pct ) { $data['score_percent'] = $score_pct; }

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table()} SET attempts = attempts + 1 WHERE id = %d",
					$existing_id
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = (bool) $wpdb->update( $this->table(), $data, array( 'id' => $existing_id ) );
			if ( ! $ok ) {
				return new WP_Error( 'atora_h5p_db_error', __( 'No se pudo guardar el tracking.', 'atora-lms' ), array( 'status' => 500 ) );
			}
			return array( 'row_id' => $existing_id, 'updated' => true, 'derived' => $derived );
		}

		$data['attempts']       = 1;
		$data['first_event_at'] = $now;
		$data['created_at']     = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = (bool) $wpdb->insert( $this->table(), $data );
		if ( ! $ok ) {
			return new WP_Error( 'atora_h5p_db_error', __( 'No se pudo guardar el tracking.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		return array( 'row_id' => absint( $wpdb->insert_id ), 'updated' => true, 'derived' => $derived );
	}

	/**
	 * Deriva campos "BI-friendly" desde xAPI.
	 *
	 * @param array<string,mixed> $statement
	 * @return array<string,mixed>
	 */
	public function derive_from_statement( array $statement ): array {
		$verb_id = '';
		if ( isset( $statement['verb']['id'] ) && is_string( $statement['verb']['id'] ) ) {
			$verb_id = (string) $statement['verb']['id'];
		}

		$object_id = '';
		if ( isset( $statement['object']['id'] ) && is_string( $statement['object']['id'] ) ) {
			$object_id = (string) $statement['object']['id'];
		}

		$result = isset( $statement['result'] ) && is_array( $statement['result'] ) ? (array) $statement['result'] : array();
		$score  = isset( $result['score'] ) && is_array( $result['score'] ) ? (array) $result['score'] : array();

		$score_raw = null;
		$score_max = null;
		$score_pct = null;

		if ( isset( $score['raw'] ) && is_numeric( $score['raw'] ) ) {
			$score_raw = (float) $score['raw'];
		}
		if ( isset( $score['max'] ) && is_numeric( $score['max'] ) ) {
			$score_max = (float) $score['max'];
		}
		if ( null !== $score_raw && null !== $score_max && $score_max > 0 ) {
			$score_pct = (int) round( ( $score_raw / $score_max ) * 100 );
			$score_pct = max( 0, min( 100, $score_pct ) );
		}

		$completion = '';
		if ( isset( $result['completion'] ) ) {
			$completion = (bool) $result['completion'] ? 'completed' : 'in_progress';
		}
		if ( '' === $completion ) {
			if ( false !== strpos( $verb_id, 'completed' ) ) {
				$completion = 'completed';
			} elseif ( false !== strpos( $verb_id, 'attempted' ) ) {
				$completion = 'attempted';
			} elseif ( false !== strpos( $verb_id, 'answered' ) ) {
				$completion = 'answered';
			} elseif ( false !== strpos( $verb_id, 'progressed' ) ) {
				$completion = 'progressed';
			}
		}

		$success = false;
		if ( array_key_exists( 'success', $result ) ) {
			$success = (bool) $result['success'];
		}

		$duration = '';
		if ( isset( $result['duration'] ) && is_string( $result['duration'] ) ) {
			$duration = (string) $result['duration'];
		}

		return array(
			'verb_id'       => sanitize_text_field( $verb_id ),
			'object_id'     => sanitize_text_field( $object_id ),
			'score_raw'     => null !== $score_raw ? (float) $score_raw : null,
			'score_max'     => null !== $score_max ? (float) $score_max : null,
			'score_percent' => null !== $score_pct ? absint( $score_pct ) : null,
			'completion_status' => sanitize_key( $completion ),
			'success'       => $success,
			'duration'      => sanitize_text_field( $duration ),
		);
	}

	/**
	 * Mapa simple de actividad para Learning Analytics (últimos N días).
	 *
	 * @param int   $course_id
	 * @param int[] $student_ids
	 * @param int   $days
	 * @return array<int,array{h5p_events_14d:int,last_h5p_at:string}>
	 */
	public function get_course_activity_map( int $course_id, array $student_ids, int $days = 14 ): array {
		global $wpdb;

		$course_id   = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', (array) $student_ids ) ) );
		$days        = max( 1, absint( $days ) );

		if ( ! $course_id || empty( $student_ids ) ) {
			return array();
		}

		$since        = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
		$args         = array_merge( array( $course_id, $since ), $student_ids );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT user_id, SUM(attempts) AS c, MAX(last_event_at) AS last_at
			 FROM {$this->table()}
			 WHERE course_id = %d
			   AND last_event_at >= %s
			   AND user_id IN ({$placeholders})
			 GROUP BY user_id",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$map = array();
		foreach ( $rows as $row ) {
			$uid = absint( $row['user_id'] ?? 0 );
			if ( ! $uid ) {
				continue;
			}
			$map[ $uid ] = array(
				'h5p_events_14d' => absint( $row['c'] ?? 0 ),
				'last_h5p_at'    => sanitize_text_field( (string) ( $row['last_at'] ?? '' ) ),
			);
		}

		return $map;
	}

	/**
	 * Sincroniza nota hacia el "canal quiz" legacy (usermeta clms_quiz_attempt_{lesson}).
	 *
	 * Solo se usa como MVP mientras se decide un canal nativo H5P→gradebook.
	 */
	public function maybe_sync_autoscore_to_quiz_channel( int $user_id, int $lesson_id, int $score_percent, array $statement = array() ): void {
		$user_id       = absint( $user_id );
		$lesson_id     = absint( $lesson_id );
		$score_percent = max( 0, min( 100, absint( $score_percent ) ) );

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		$quiz_enabled = '1' === (string) get_post_meta( $lesson_id, '_clms_quiz_enabled', true );
		if ( $quiz_enabled ) {
			return;
		}

		$key     = 'clms_quiz_attempt_' . $lesson_id;
		$current = get_user_meta( $user_id, $key, true );
		$current = is_array( $current ) ? $current : array();

		$attempts      = isset( $current['attempts'] ) ? absint( $current['attempts'] ) + 1 : 1;
		$previous_best = isset( $current['best_score'] ) ? absint( $current['best_score'] ) : ( isset( $current['score'] ) ? absint( $current['score'] ) : 0 );
		$best_score    = max( $previous_best, $score_percent );
		$is_new_best   = $score_percent > $previous_best || 1 === $attempts;

		$stored = array(
			'score'       => $best_score,
			'best_score'  => $best_score,
			'last_score'  => $score_percent,
			'is_new_best' => $is_new_best,
			'answers'     => array(),
			'breakdown'   => array(),
			'feedback'    => __( 'Resultado registrado desde H5P.', 'atora-lms' ),
			'submitted'   => current_time( 'mysql' ),
			'attempts'    => $attempts,
			'h5p'         => array(
				'statement_id' => isset( $statement['id'] ) ? sanitize_text_field( (string) $statement['id'] ) : '',
			),
		);

		update_user_meta( $user_id, $key, $stored );
	}
}
