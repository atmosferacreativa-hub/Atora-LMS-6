<?php
/**
 * Clase en vivo de una lección: una sola lectura para la web, la agenda, la app y los recordatorios (6.39.0).
 *
 * Antes había dos datos sin relación: la reunión que crea el módulo de clases en vivo (`_atora_live_session`) y los
 * campos que el docente llena a mano en la lección (`_clms_live_class_*`). La lección y la agenda solo leían los
 * segundos, así que una reunión creada por ATORA (Zoom, Meet) no aparecía nunca al estudiante.
 *
 * Aquí:
 * - se lee la reunión del módulo y, si no hay, la carga manual;
 * - la hora de inicio se convierte a UTC con la zona de la clase (antes se tomaba la hora local como UTC);
 * - el enlace se abre 10 min antes y se cierra 15 min después del final;
 * - la grabación es decisión del docente: sin grabación, la sube después o enlace automático del proveedor.
 *
 * Integración con proveedores externos; ATORA no transmite video.
 *
 * @package ATORA_LMS
 * @since 6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Live_Session_Service {

	const META            = '_atora_live_session';
	const RECORDING_META  = '_atora_live_recording';
	const RECORDING_MODES = array( 'none', 'upload', 'provider' );
	const OPEN_BEFORE     = 600;  // 10 min.
	const CLOSE_AFTER     = 900;  // 15 min.
	const VIDEO_FORMATS   = array( 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm4v' => 'video/x-m4v' );

	const PROVIDER_LABELS = array(
		'zoom'    => 'Zoom',
		'meet'    => 'Google Meet',
		'teams'   => 'Microsoft Teams',
		'youtube' => 'YouTube Live',
		'custom'  => 'Enlace externo',
	);

	/**
	 * Sesión normalizada de la lección o null.
	 *
	 * @return array{provider:string,provider_label:string,source:string,start_local:string,timezone:string,start_utc:int,duration_minutes:int,end_utc:int,join_url:string,host_url:string,meeting_id:string,recording_mode:string,notes:string}|null
	 */
	public static function get( int $lesson_id ): ?array {
		$module = get_post_meta( $lesson_id, self::META, true );
		if ( is_array( $module ) && ! empty( $module['provider'] ) && ! empty( $module['start_datetime'] ) ) {
			$tz    = self::tz( (string) ( $module['timezone'] ?? '' ) );
			$start = ! empty( $module['start_utc'] ) ? (int) $module['start_utc'] : self::utc_from_local( (string) $module['start_datetime'], $tz );
			$dur   = max( 1, absint( $module['duration_minutes'] ?? 60 ) );
			return self::shape( array(
				'provider'         => sanitize_key( (string) $module['provider'] ),
				'source'           => 'module',
				'start_local'      => (string) $module['start_datetime'],
				'timezone'         => $tz,
				'start_utc'        => $start,
				'duration_minutes' => $dur,
				'join_url'         => (string) ( $module['join_url'] ?? $module['custom_url'] ?? '' ),
				'host_url'         => (string) ( $module['start_url'] ?? '' ),
				'meeting_id'       => (string) ( $module['meeting_id'] ?? '' ),
				'recording_mode'   => self::recording_mode( $module ),
				'notes'            => (string) ( $module['notes'] ?? '' ),
			) );
		}

		$starts = str_replace( 'T', ' ', (string) get_post_meta( $lesson_id, '_clms_live_class_starts_at', true ) );
		$url    = (string) get_post_meta( $lesson_id, '_clms_live_class_url', true );
		$notes  = (string) get_post_meta( $lesson_id, '_clms_live_class_notes', true );
		if ( '' === $starts && '' === $url ) {
			return null;
		}
		$tz    = self::tz( (string) get_post_meta( $lesson_id, '_clms_live_class_timezone', true ) );
		$start = '' !== $starts ? self::utc_from_local( $starts, $tz ) : 0;
		$ends  = str_replace( 'T', ' ', (string) get_post_meta( $lesson_id, '_clms_live_class_ends_at', true ) );
		$end   = '' !== $ends ? self::utc_from_local( $ends, $tz ) : 0;
		$map   = array( 'google_meet' => 'meet', 'youtube_live' => 'youtube' );
		$prov  = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_live_class_provider', true ) );
		$prov  = $map[ $prov ] ?? ( '' !== $prov ? $prov : 'custom' );
		return self::shape( array(
			'provider'         => $prov,
			'source'           => 'manual',
			'start_local'      => $starts,
			'timezone'         => $tz,
			'start_utc'        => $start,
			'duration_minutes' => $start && $end > $start ? (int) round( ( $end - $start ) / 60 ) : 60,
			'join_url'         => $url,
			'host_url'         => '',
			'meeting_id'       => '',
			'recording_mode'   => 'none',
			'notes'            => $notes,
		) );
	}

	private static function shape( array $s ): array {
		$s['provider_label'] = self::PROVIDER_LABELS[ $s['provider'] ] ?? __( 'Clase en vivo', 'atora-lms' );
		$s['end_utc']        = $s['start_utc'] ? $s['start_utc'] + $s['duration_minutes'] * MINUTE_IN_SECONDS : 0;
		return $s;
	}

	public static function recording_mode( array $module ): string {
		$mode = sanitize_key( (string) ( $module['recording_mode'] ?? '' ) );
		if ( in_array( $mode, self::RECORDING_MODES, true ) ) {
			return $mode;
		}
		return ! empty( $module['record'] ) ? 'provider' : 'none'; // Sesiones anteriores: casilla "grabar".
	}

	public static function tz( string $tz ): string {
		$tz = trim( $tz );
		if ( '' !== $tz && in_array( $tz, timezone_identifiers_list(), true ) ) {
			return $tz;
		}
		return wp_timezone_string() ?: 'UTC';
	}

	/** Hora local de la clase ("2026-11-03 18:00") en su zona → marca UTC. 0 si no se puede leer. */
	public static function utc_from_local( string $local, string $tz ): int {
		$local = trim( str_replace( 'T', ' ', $local ) );
		if ( '' === $local ) {
			return 0;
		}
		try {
			$date = new DateTimeImmutable( $local, new DateTimeZone( self::tz( $tz ) ) );
		} catch ( \Exception $e ) {
			return 0;
		}
		return $date->getTimestamp();
	}

	/** @return string scheduled | open | ended | unscheduled */
	public static function state( array $s, ?int $now = null ): string {
		$now = $now ?? time();
		if ( empty( $s['start_utc'] ) ) {
			return 'unscheduled';
		}
		if ( $now < $s['start_utc'] - self::OPEN_BEFORE ) {
			return 'scheduled';
		}
		if ( $now > $s['end_utc'] + self::CLOSE_AFTER ) {
			return 'ended';
		}
		return 'open';
	}

	/** ¿Puede gestionar la clase (docente del curso o administrador)? */
	public static function can_manage( int $user_id, int $lesson_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$course = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );
		if ( $course && class_exists( 'ATORA_Teacher_Scope' ) ) {
			return ATORA_Teacher_Scope::can_access_course( $user_id, $course ) && user_can( $user_id, 'edit_post', $lesson_id );
		}
		return user_can( $user_id, 'edit_post', $lesson_id );
	}

	/**
	 * Lo que ve una persona: estado, cuánto falta, enlace solo con la clase abierta (al docente, el de anfitrión
	 * si existe) y la grabación.
	 */
	public static function join_info( int $lesson_id, int $user_id, ?int $now = null ): ?array {
		$s = self::get( $lesson_id );
		if ( ! $s ) {
			return null;
		}
		$now     = $now ?? time();
		$state   = self::state( $s, $now );
		$manager = self::can_manage( $user_id, $lesson_id );
		$url     = '';
		if ( 'open' === $state || ( 'unscheduled' === $state && '' !== $s['join_url'] ) ) {
			$url = $manager && '' !== $s['host_url'] ? $s['host_url'] : $s['join_url'];
		}
		return array(
			'status'           => $state,
			'provider'         => $s['provider'],
			'provider_label'   => $s['provider_label'],
			'starts_at'        => $s['start_utc'] ? gmdate( 'c', $s['start_utc'] ) : null,
			'ends_at'          => $s['end_utc'] ? gmdate( 'c', $s['end_utc'] ) : null,
			'timezone'         => $s['timezone'],
			'starts_in_sec'    => $s['start_utc'] ? max( 0, $s['start_utc'] - $now ) : null,
			'opens_at'         => $s['start_utc'] ? gmdate( 'c', $s['start_utc'] - self::OPEN_BEFORE ) : null,
			'join_url'         => esc_url_raw( $url ),
			'is_host'          => $manager && '' !== $s['host_url'] && '' !== $url,
			'notes'            => $s['notes'],
			'recording_mode'   => $s['recording_mode'],
			'recording'        => self::recording( $lesson_id ),
		);
	}

	// ── Grabación ───────────────────────────────────────────────────────────

	/** @return array{type:string,url:string,source:string,note:string}|null */
	public static function recording( int $lesson_id ): ?array {
		$rec = get_post_meta( $lesson_id, self::RECORDING_META, true );
		if ( ! is_array( $rec ) || empty( $rec['type'] ) ) {
			return null;
		}
		if ( 'video' === $rec['type'] ) {
			$url = (string) wp_get_attachment_url( absint( $rec['attachment_id'] ?? 0 ) );
			return '' === $url ? null : array( 'type' => 'video', 'url' => $url, 'source' => 'upload', 'note' => '' );
		}
		$url = esc_url_raw( (string) ( $rec['url'] ?? '' ) );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'type'   => 'link',
			'url'    => $url,
			'source' => (string) ( $rec['source'] ?? 'link' ),
			'note'   => (string) ( $rec['note'] ?? '' ),
		);
	}

	/**
	 * Publica la grabación. Un enlace (http/https) o un video ya subido a la biblioteca de medios.
	 *
	 * @param array{url?:string,attachment_id?:int,source?:string,note?:string} $rec
	 * @return true|WP_Error
	 */
	public static function set_recording( int $lesson_id, array $rec, int $by = 0 ) {
		$attachment = absint( $rec['attachment_id'] ?? 0 );
		if ( $attachment ) {
			$file = (string) get_attached_file( $attachment );
			$type = wp_check_filetype_and_ext( $file, basename( $file ), self::VIDEO_FORMATS );
			if ( ! $file || empty( $type['ext'] ) ) {
				return new WP_Error( 'atora_live_recording_format', __( 'La grabación debe ser un video MP4, WebM, MOV o M4V.', 'atora-lms' ) );
			}
			$data = array( 'type' => 'video', 'attachment_id' => $attachment );
		} else {
			$url = trim( (string) ( $rec['url'] ?? '' ) );
			if ( ! self::is_web_url( $url ) ) {
				return new WP_Error( 'atora_live_recording_url', __( 'El enlace de la grabación no es válido.', 'atora-lms' ) );
			}
			$data = array(
				'type'   => 'link',
				'url'    => esc_url_raw( $url ),
				'source' => in_array( (string) ( $rec['source'] ?? '' ), array( 'zoom', 'meet', 'teams' ), true ) ? (string) $rec['source'] : 'link',
				'note'   => sanitize_text_field( (string) ( $rec['note'] ?? '' ) ),
			);
		}
		$data['set_by'] = $by;
		$data['set_at'] = gmdate( 'Y-m-d H:i:s' );
		update_post_meta( $lesson_id, self::RECORDING_META, $data );
		do_action( 'atora/live/recording_published', $lesson_id, $data );
		return true;
	}

	/**
	 * Enlace http(s) con host. No se usa wp_http_validate_url: resuelve DNS (pensada para peticiones del servidor)
	 * y rechazaría enlaces válidos de un servidor que no resuelve desde aquí.
	 */
	public static function is_web_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& esc_url_raw( $url, array( 'http', 'https' ) ) === $url;
	}

	/** El docente la quita: queda marcado para que el proveedor no la vuelva a poner. */
	public static function clear_recording( int $lesson_id ): void {
		update_post_meta( $lesson_id, self::RECORDING_META, array( 'type' => '', 'cleared' => 1, 'set_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/** El proveedor no reemplaza una grabación que el docente puso o quitó a mano. */
	public static function provider_may_set_recording( int $lesson_id ): bool {
		$s = self::get( $lesson_id );
		if ( ! $s || 'provider' !== $s['recording_mode'] ) {
			return false;
		}
		$rec = get_post_meta( $lesson_id, self::RECORDING_META, true );
		if ( ! is_array( $rec ) || ! $rec ) {
			return true;
		}
		if ( ! empty( $rec['cleared'] ) ) {
			return false;
		}
		return in_array( (string) ( $rec['source'] ?? '' ), array( 'zoom', 'meet', 'teams' ), true );
	}
}
