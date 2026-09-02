<?php
/**
 * Live_Session_Repository — P6 (sprint 6.13.0)
 *
 * Escritura dual hacia atora_live_sessions / atora_attendance durante la
 * migración desde postmeta/usermeta (mismo patrón que F3/F4: lectura
 * preferente desde tabla, escritura dual durante una versión, sin
 * cutover en este release — ver Live_Streaming_Migrator para el
 * volcado inicial de datos existentes).
 *
 * @package ATORA_LMS\LiveStreaming
 * @since   6.13.0
 */

namespace ATORA\LiveStreaming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Live_Session_Repository {

	/**
	 * Inserta o actualiza una fila de atora_live_sessions a partir de los
	 * datos de sesión ya guardados en postmeta (_atora_live_session).
	 * Idempotente: (provider, external_id) es único; sin external_id se
	 * dedupe por lesson_id.
	 *
	 * @param int   $lesson_id
	 * @param array $session   Mismo shape que Live_Streaming::LESSON_META.
	 * @return int ID de la fila en atora_live_sessions, 0 si no se pudo escribir.
	 */
	public static function upsert_session( int $lesson_id, array $session ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_live_sessions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}

		$course_id   = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );
		$provider    = sanitize_key( (string) ( $session['provider'] ?? '' ) );
		$external_id = sanitize_text_field( (string) ( $session['meeting_id'] ?? $session['external_id'] ?? '' ) );

		$row = array(
			'lesson_id'          => $lesson_id,
			'course_id'          => $course_id,
			'provider'           => $provider ?: 'custom',
			// C0.2 (6.13.1): NULL, no '' — la columna es nullable
			// precisamente para que proveedores sin external_id (custom,
			// YouTube, Teams sin webhook) puedan coexistir bajo el
			// UNIQUE KEY (provider, external_id); MySQL sí permite
			// múltiples NULL, no múltiples ''.
			'external_id'        => '' !== $external_id ? $external_id : null,
			'join_url'           => esc_url_raw( (string) ( $session['join_url'] ?? $session['custom_url'] ?? '' ) ),
			'start_datetime'     => ! empty( $session['start_datetime'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $session['start_datetime'] ) ) : null,
			'duration_minutes'   => absint( $session['duration_minutes'] ?? 60 ),
			'timezone'           => sanitize_text_field( (string) ( $session['timezone'] ?? 'UTC' ) ),
			'recording_url'      => esc_url_raw( (string) ( $session['recording_url'] ?? '' ) ),
			'status'             => sanitize_key( (string) ( $session['status'] ?? 'scheduled' ) ),
			// C0.3/C1.5 (6.13.1): columna propia — sustituye el
			// update_option('atora_meet_organizer_user_id_'.$code) que
			// creaba una fila de wp_options sin límite por cada clase Meet.
			'created_by_user_id' => absint( $session['created_by_user_id'] ?? $session['actor_user_id'] ?? 0 ),
		);

		// C1.4 (6.13.1): con external_id vacío/nulo, la dedupe SIEMPRE es
		// por lesson_id — nunca por (provider, external_id), que ya no
		// distingue nada cuando ambos son NULL.
		$existing_id = 0;
		if ( null !== $row['external_id'] ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE provider = %s AND external_id = %s", $row['provider'], $row['external_id'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
		if ( ! $existing_id ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE lesson_id = %d ORDER BY id DESC LIMIT 1", $lesson_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		if ( $existing_id ) {
			$wpdb->update( $table, $row, array( 'id' => $existing_id ) );
			return $existing_id;
		}

		$row['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( $table, $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $lesson_id
	 * @return array|null
	 */
	public static function get_by_lesson( int $lesson_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_live_sessions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lesson_id = %d ORDER BY id DESC LIMIT 1", $lesson_id ), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Registra (o actualiza) una fila de asistencia. Único en
	 * (session_id, user_id, external_participant_id) desde C0.1
	 * (6.13.1) — un reintento del mismo webhook actualiza en vez de
	 * duplicar, y varios anónimos de la misma sesión ya no colapsan en
	 * una sola fila.
	 *
	 * @param array $row {
	 *     @type int    user_id
	 *     @type int    session_id
	 *     @type int    course_id
	 *     @type string source                  zoom|meet|teams|manual|qr
	 *     @type string external_participant_id `participant` de Meet o UUID de Zoom — clave estable si alguien se reconecta. '' para asistencia identificada/manual/QR.
	 *     @type string joined_at               MySQL datetime, opcional.
	 *     @type string left_at                 MySQL datetime, opcional.
	 *     @type int    duration_seconds
	 *     @type string status                  presente|tarde|ausente|justificado
	 *     @type int    recorded_by
	 * }
	 * @return void
	 */
	public static function record_attendance( array $row ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_attendance';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		$user_id                 = absint( $row['user_id'] ?? 0 );
		$session_id              = absint( $row['session_id'] ?? 0 );
		$external_participant_id = sanitize_text_field( (string) ( $row['external_participant_id'] ?? '' ) );

		$data = array(
			'user_id'                 => $user_id,
			'session_id'              => $session_id,
			'external_participant_id' => $external_participant_id,
			'display_name'            => sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
			'course_id'               => absint( $row['course_id'] ?? 0 ),
			'source'                  => sanitize_key( (string) ( $row['source'] ?? 'manual' ) ),
			'joined_at'               => ! empty( $row['joined_at'] ) ? $row['joined_at'] : null,
			'left_at'                 => ! empty( $row['left_at'] ) ? $row['left_at'] : null,
			'duration_seconds'        => absint( $row['duration_seconds'] ?? 0 ),
			'status'                  => sanitize_key( (string) ( $row['status'] ?? 'ausente' ) ),
			'recorded_by'             => absint( $row['recorded_by'] ?? 0 ),
		);

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE session_id = %d AND user_id = %d AND external_participant_id = %s",
				$session_id, $user_id, $external_participant_id
			)
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( $table, $data );
		}

		// P9 (6.13.0): punto de instrumentación para conectar asistencia
		// con seguimiento académico y gradebook — ver
		// includes/academic/class-attendance-academic-bridge.php.
		if ( $user_id ) {
			do_action( 'atora/attendance/recorded', $user_id, absint( $data['course_id'] ?? 0 ), $data['status'] );
		}
	}

	/**
	 * C4 (6.13.1): asistencia de una sesión para el panel del metabox —
	 * user_id = 0 (anónimos) incluidos, con su nombre de pantalla si
	 * quedó registrado.
	 *
	 * @param int $session_id
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_attendance_for_session( int $session_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_attendance';
		if ( ! $session_id || (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE session_id = %d ORDER BY joined_at ASC, created_at ASC",
			$session_id
		), ARRAY_A );
	}
}
