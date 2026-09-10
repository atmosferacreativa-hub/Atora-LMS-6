<?php
/**
 * Microsoft_Outlook — calendario iCal (suscripción Outlook).
 *
 * MVP: feed .ics por curso con entregas (due_date/late_date) y sesiones live.
 *
 * @package ATORA_LMS
 * @since   6.20.0
 */

namespace ATORA\Microsoft;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Microsoft_Outlook {

	const TOKEN_META = '_atora_outlook_ical_token';

	public static function rest_can_access_ical( WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		$token     = sanitize_text_field( (string) $r->get_param( 'token' ) );
		if ( ! $course_id || '' === $token ) {
			return false;
		}

		// 1) Token match (para suscripción Outlook sin login).
		$expected = (string) get_post_meta( $course_id, self::TOKEN_META, true );
		if ( '' !== $expected && hash_equals( $expected, $token ) ) {
			return true;
		}

		// 2) Sesión con permisos.
		if ( is_user_logged_in() && class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
			return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
		}

		return false;
	}

	public static function rest_get_ical_url( WP_REST_Request $r ): WP_REST_Response {
		$course_id = absint( $r->get_param( 'course_id' ) );
		if ( ! $course_id ) {
			return new WP_REST_Response( array( 'message' => __( 'Curso inválido.', 'atora-lms' ) ), 400 );
		}

		$token = (string) get_post_meta( $course_id, self::TOKEN_META, true );
		if ( '' === $token ) {
			$token = wp_generate_password( 24, false, false );
			update_post_meta( $course_id, self::TOKEN_META, $token );
		}

		$url = add_query_arg(
			array(
				'course_id' => $course_id,
				'token'     => $token,
			),
			rest_url( 'atora/v1/microsoft/outlook/ical' )
		);

		return new WP_REST_Response(
			array(
				'course_id' => $course_id,
				'url'       => esc_url_raw( $url ),
			),
			200
		);
	}

	public static function rest_ical_feed( WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		if ( ! $course_id || ! class_exists( '\CLMS_Helper' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Curso inválido.', 'atora-lms' ) ), 400 );
		}

		$events = self::build_events_for_course( $course_id );
		$ics    = self::build_ics( $course_id, $events );

		$response = new WP_REST_Response( $ics, 200 );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$response->header( 'Content-Disposition', 'inline; filename="atora-course-' . $course_id . '.ics"' );
		return $response;
	}

	/**
	 * @param int $course_id
	 * @return array<int,array{uid:string,dtstart:int,dtend:int,summary:string,description:string,url:string}>
	 */
	private static function build_events_for_course( int $course_id ): array {
		$course_id = absint( $course_id );
		$lesson_ids = (array) \CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = array_values( array_filter( array_map( 'absint', (array) $lesson_ids ) ) );

		$events = array();

		foreach ( $lesson_ids as $lesson_id ) {
			$title = (string) get_the_title( $lesson_id );
			$url   = (string) get_permalink( $lesson_id );

			// Due date (o late date si existe).
			$due_date  = (string) get_post_meta( $lesson_id, '_clms_due_date', true );
			$late_date = (string) get_post_meta( $lesson_id, '_clms_due_date_late', true );
			$due_time  = (string) get_post_meta( $lesson_id, '_clms_due_time', true );

			$date = '' !== trim( $late_date ) ? trim( $late_date ) : trim( $due_date );
			if ( '' !== $date ) {
				$time = '' !== trim( $due_time ) ? trim( $due_time ) : '23:59';
				$ts   = strtotime( $date . ' ' . $time );
				if ( $ts ) {
					$events[] = array(
						'uid'         => 'atora-due-' . $lesson_id . '@atora',
						'dtstart'     => (int) $ts,
						'dtend'       => (int) ( $ts + 60 ),
						'summary'     => sprintf( __( 'Entrega: %s', 'atora-lms' ), $title ),
						'description' => __( 'Fecha límite registrada en ATORA.', 'atora-lms' ),
						'url'         => $url,
					);
				}
			}

			// Live session (si aplica).
			$starts = (string) get_post_meta( $lesson_id, '_clms_live_class_starts_at', true );
			$ends   = (string) get_post_meta( $lesson_id, '_clms_live_class_ends_at', true );
			$live_url = (string) get_post_meta( $lesson_id, '_clms_live_class_url', true );

			$ts_start = $starts ? strtotime( $starts ) : 0;
			$ts_end   = $ends ? strtotime( $ends ) : 0;
			if ( $ts_start ) {
				if ( ! $ts_end || $ts_end <= $ts_start ) {
					$ts_end = $ts_start + ( 60 * 60 );
				}
				$events[] = array(
					'uid'         => 'atora-live-' . $lesson_id . '@atora',
					'dtstart'     => (int) $ts_start,
					'dtend'       => (int) $ts_end,
					'summary'     => sprintf( __( 'Clase en vivo: %s', 'atora-lms' ), $title ),
					'description' => $live_url ? ( __( 'Link de la sesión:', 'atora-lms' ) . ' ' . $live_url ) : __( 'Sesión en vivo registrada en ATORA.', 'atora-lms' ),
					'url'         => $url,
				);
			}
		}

		return $events;
	}

	/**
	 * @param int   $course_id
	 * @param array $events
	 * @return string
	 */
	private static function build_ics( int $course_id, array $events ): string {
		$course_title = (string) get_the_title( $course_id );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//ATORA LMS//Epic7//ES',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::ics_escape( $course_title ? $course_title : ( 'Curso ' . $course_id ) ),
		);

		foreach ( (array) $events as $e ) {
			$uid = self::ics_escape( (string) ( $e['uid'] ?? '' ) );
			$dtstart = absint( $e['dtstart'] ?? 0 );
			$dtend   = absint( $e['dtend'] ?? 0 );
			$summary = self::ics_escape( (string) ( $e['summary'] ?? '' ) );
			$desc    = self::ics_escape( (string) ( $e['description'] ?? '' ) );
			$url     = self::ics_escape( (string) ( $e['url'] ?? '' ) );

			if ( '' === $uid || ! $dtstart ) {
				continue;
			}
			if ( ! $dtend || $dtend <= $dtstart ) {
				$dtend = $dtstart + 60;
			}

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $uid;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\\THis\\Z' );
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\\THis\\Z', $dtstart );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\\THis\\Z', $dtend );
			if ( $summary ) {
				$lines[] = 'SUMMARY:' . $summary;
			}
			if ( $desc ) {
				$lines[] = 'DESCRIPTION:' . $desc;
			}
			if ( $url ) {
				$lines[] = 'URL:' . $url;
			}
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function ics_escape( string $value ): string {
		$value = str_replace( array( "\\", "\r", "\n", ";", "," ), array( "\\\\", '', "\\n", "\\;", "\\," ), $value );
		return $value;
	}
}

