<?php
/**
 * ATORA\Messaging\Digest_Cron — PT-5.2 (sprint 6.4.0)
 *
 * Consume Digest_Store por usuario y construye un solo mensaje.
 * Corre cada hora (no una vez al día): así, si el horario de no
 * molestar de un estudiante cubre la hora configurada de envío
 * (`atora_digest_send_hour`, default 18), el resumen se difiere a la
 * próxima hora en que ya no esté en DND — nunca se descarta (PT-UX:
 * "se difiere, no se descarta").
 *
 * @package ATORA_LMS\Messaging
 * @since   6.4.0
 */

namespace ATORA\Messaging;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Digest_Cron {

	const CRON_HOOK        = 'atora_messaging_digest_check';
	const OPT_SEND_HOUR    = 'atora_digest_send_hour';
	const META_LAST_SENT   = 'atora_last_digest_sent_date';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/** @return void */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * @return array{sent:int, deferred:int, skipped_empty:int}
	 */
	public static function run(): array {
		$stats = array( 'sent' => 0, 'deferred' => 0, 'skipped_empty' => 0 );

		if ( ! class_exists( '\ATORA\Messaging\Digest_Store' ) || ! class_exists( '\ATORA\Messaging\Messaging_Router' ) ) {
			return $stats;
		}

		// PT-2.3 (6.5.4): liberar reclamos abandonados y descartar lo
		// vencido antes de procesar nada esta corrida.
		Digest_Store::run_maintenance();

		$send_hour = max( 0, min( 23, absint( get_option( self::OPT_SEND_HOUR, 18 ) ) ) );
		if ( (int) current_time( 'G' ) < $send_hour ) {
			// Todavía no es la hora del resumen — nada que hacer esta corrida.
			return $stats;
		}

		$today = current_time( 'Y-m-d' );

		foreach ( Digest_Store::get_users_with_pending_items() as $user_id ) {
			// Como máximo un resumen por día por usuario — sin esto, un
			// evento 'low' que llega después de la hora configurada
			// dispararía un segundo envío esa misma corrida horaria.
			if ( $today === get_user_meta( $user_id, self::META_LAST_SENT, true ) ) {
				continue;
			}

			// PT-2.2 (6.5.4): reclamo atómico — si otra corrida de cron
			// solapada ya se llevó estas filas, esto devuelve vacío y
			// se salta sin reprocesar nada.
			$items = Digest_Store::claim_items_for_user( $user_id );
			if ( empty( $items ) ) {
				++$stats['skipped_empty'];
				continue;
			}

			if ( self::in_do_not_disturb_window( $user_id ) ) {
				// PT-2.2 (6.5.4): liberar el reclamo puntual en vez de
				// dejarlo 'claimed' hasta que la limpieza de reclamos
				// abandonados lo libere por antigüedad — se reintenta
				// en la próxima corrida horaria, no en ~15-60 min.
				Digest_Store::release_claim( wp_list_pluck( $items, 'id' ) );
				++$stats['deferred'];
				continue;
			}

			$sent_ok = ( 1 === count( $items ) )
				? self::send_single_item( $user_id, $items[0] )
				: self::send_grouped_digest( $user_id, $items );

			if ( $sent_ok ) {
				Digest_Store::clear_items( wp_list_pluck( $items, 'id' ) );
				update_user_meta( $user_id, self::META_LAST_SENT, $today );
				++$stats['sent'];
			}
		}

		return $stats;
	}

	/**
	 * PT-5.4: un resumen de un solo ítem se envía como el mensaje
	 * normal que hubiera sido — no como "resumen de 1 línea".
	 *
	 * @param int   $user_id
	 * @param array $item {id,type,template_key,variables}
	 * @return bool
	 */
	private static function send_single_item( int $user_id, array $item ): bool {
		return Messaging_Router::send(
			$user_id,
			$item['type'],
			$item['template_key'],
			$item['variables'],
			array( 'skip_digest' => true )
		);
	}

	/**
	 * @param int   $user_id
	 * @param array $items
	 * @return bool
	 */
	private static function send_grouped_digest( int $user_id, array $items ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) { return false; }

		$count   = count( $items );
		$summary = self::summarize_items( $items );

		return Messaging_Router::send(
			$user_id,
			'student_digest',
			'atora_daily_digest_student',
			array(
				'student_name' => sanitize_text_field( (string) $user->display_name ),
				'count'        => $count,
				'summary'      => $summary,
				'button_url'   => home_url( '/?atora_digest=1' ),
			),
			array( 'skip_digest' => true, 'priority' => 'low' )
		);
	}

	/**
	 * Primeras 1-2 novedades en una línea legible, para la variable
	 * `{{3}}` de la plantilla — el estudiante debe entender de qué se
	 * trata sin abrir el enlace (regla de UX del sprint).
	 *
	 * @param array $items
	 * @return string
	 */
	private static function summarize_items( array $items ): string {
		$labels = array();
		foreach ( array_slice( $items, 0, 2 ) as $item ) {
			$title = sanitize_text_field( (string) ( $item['variables']['lesson_title'] ?? $item['variables']['course_title'] ?? '' ) );
			$labels[] = '' !== $title ? $title : $item['type'];
		}
		return implode( ', ', $labels );
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	private static function in_do_not_disturb_window( int $user_id ): bool {
		if ( ! class_exists( '\ATORA\Messaging\Preferences' ) ) { return false; }

		$prefs = Preferences::get( $user_id );
		if ( '' === $prefs['dnd_start'] || '' === $prefs['dnd_end'] ) { return false; }

		$now   = current_time( 'H:i' );
		$start = $prefs['dnd_start'];
		$end   = $prefs['dnd_end'];

		if ( $start <= $end ) {
			return $now >= $start && $now < $end;
		}
		// Ventana que cruza medianoche (p.ej. 22:00–07:00).
		return $now >= $start || $now < $end;
	}
}
