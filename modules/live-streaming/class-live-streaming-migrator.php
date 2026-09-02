<?php
/**
 * Live_Streaming_Migrator — P6.3 (sprint 6.13.0)
 *
 * Vuelca las sesiones (_atora_live_session en postmeta) y la asistencia
 * (_atora_live_attendance_{lesson_id} en usermeta) ya existentes hacia
 * atora_live_sessions / atora_attendance. Mismo patrón que F3/F4: esto
 * es el volcado inicial idempotente — el dual-write en Live_Streaming /
 * Provider_Zoom ya mantiene las tablas al día para todo lo nuevo desde
 * 6.13.0. Sin cutover en este release: postmeta/usermeta siguen siendo
 * la fuente de verdad que lee el resto del código.
 *
 * @package ATORA_LMS\LiveStreaming
 * @since   6.13.0
 */

namespace ATORA\LiveStreaming;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Live_Streaming_Migrator {

	/**
	 * @return array{sessions: int, attendance: int}
	 */
	public static function migrate_all(): array {
		$lesson_ids = get_posts( array(
			'post_type'      => 'lm_lesson',
			'meta_key'       => Live_Streaming::LESSON_META,
			'fields'         => 'ids',
			'posts_per_page' => -1,
		) );

		$sessions_migrated   = 0;
		$attendance_migrated = 0;

		foreach ( $lesson_ids as $lesson_id ) {
			$session = get_post_meta( $lesson_id, Live_Streaming::LESSON_META, true );
			$session = is_array( $session ) ? $session : array();
			if ( empty( $session['provider'] ) ) {
				continue;
			}

			$session_row_id = Live_Session_Repository::upsert_session( (int) $lesson_id, $session );
			if ( $session_row_id ) {
				$sessions_migrated++;
				$attendance_migrated += self::migrate_attendance_for_lesson( (int) $lesson_id, $session_row_id );
			}
		}

		return array( 'sessions' => $sessions_migrated, 'attendance' => $attendance_migrated );
	}

	/**
	 * Recorre usermeta buscando `_atora_live_attendance_{lesson_id}` — no
	 * hay índice de "qué usuarios tienen esta meta key" más eficiente que
	 * una consulta directa a wp_usermeta acotada por meta_key.
	 *
	 * @param int $lesson_id
	 * @param int $session_row_id
	 * @return int Filas de asistencia migradas.
	 */
	private static function migrate_attendance_for_lesson( int $lesson_id, int $session_row_id ): int {
		global $wpdb;

		$course_id = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );
		$meta_key  = '_atora_live_attendance_' . $lesson_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $meta_key )
		);

		$migrated = 0;
		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $data ) ) {
				continue;
			}

			Live_Session_Repository::record_attendance( array(
				'user_id'    => (int) $row->user_id,
				'session_id' => $session_row_id,
				'course_id'  => $course_id,
				'source'     => 'zoom',
				'duration_seconds' => absint( $data['duration'] ?? 0 ) * 60,
				'status'     => self::map_legacy_status( (string) ( $data['status'] ?? '' ) ),
			) );
			$migrated++;
		}

		return $migrated;
	}

	/**
	 * @param string $status attended|partial|absent (legado, en inglés).
	 * @return string presente|tarde|ausente (vocabulario del OT para atora_attendance).
	 */
	private static function map_legacy_status( string $status ): string {
		switch ( $status ) {
			case 'attended':
				return 'presente';
			case 'partial':
				return 'tarde';
			default:
				return 'ausente';
		}
	}
}
