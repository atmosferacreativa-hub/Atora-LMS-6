<?php
/**
 * Agenda del estudiante (6.30.0): eventos del calendario, fechas límite de
 * tareas y quizzes, y clases en vivo de sus cursos.
 *
 * Mismas reglas de visibilidad que el resto de la API: solo cursos con
 * matrícula activa y publicados; solo lecciones publicadas. Las fechas se
 * guardan en hora local del sitio y se devuelven en ISO 8601 con desfase.
 * Ids de curso y lección: los de las tablas (los mismos de la API móvil).
 *
 * @package ATORA_LMS
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Agenda_Service {

	const MAX_DAYS = 62;

	/**
	 * Cursos visibles del usuario: [table_course_id => ['wp' => post, 'title' => …]].
	 *
	 * @return array<int,array{wp:int,title:string}>
	 */
	public static function courses( int $user_id ): array {
		$out = array();
		if ( ! class_exists( 'CLMS_Helper' ) || ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
			return $out;
		}
		foreach ( array_filter( array_map( 'absint', (array) CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) as $wp_course ) {
			$course = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course );
			if ( ! $course || 'published' !== (string) ( $course['status'] ?? '' ) || 'publish' !== get_post_status( $wp_course ) ) {
				continue;
			}
			$out[ absint( $course['id'] ) ] = array( 'wp' => $wp_course, 'title' => sanitize_text_field( (string) get_the_title( $wp_course ) ) );
		}
		return $out;
	}

	/** Fecha local del sitio → marca de tiempo (0 si vacía o inválida). */
	public static function local_ts( string $date, string $time = '', string $tz = '' ): int {
		$date = trim( $date );
		if ( '' === $date ) {
			return 0;
		}
		try {
			$zone = '' !== $tz ? new DateTimeZone( $tz ) : wp_timezone();
		} catch ( \Exception $e ) {
			$zone = wp_timezone();
		}
		try {
			$value = new DateTimeImmutable( trim( $date . ' ' . $time ), $zone );
		} catch ( \Exception $e ) {
			return 0;
		}
		return $value->getTimestamp();
	}

	/** ISO 8601 con el desfase de la zona del sitio. */
	public static function iso( int $ts, string $tz = '' ): string {
		try {
			$zone = '' !== $tz ? new DateTimeZone( $tz ) : wp_timezone();
		} catch ( \Exception $e ) {
			$zone = wp_timezone();
		}
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $zone )->format( 'c' );
	}

	/** Fecha límite de una lección (post), o 0. Misma lectura que SpeedGrader, en la zona del sitio. */
	public static function due_ts( int $wp_lesson_id ): int {
		$date = (string) CLMS_Helper::get_post_meta_first( $wp_lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );
		$time = (string) CLMS_Helper::get_post_meta_first( $wp_lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' );
		if ( '' !== trim( $date ) && '' === trim( $time ) && ! preg_match( '/\d{1,2}:\d{2}/', $date ) ) {
			$time = '23:59';
		}
		return self::local_ts( $date, $time );
	}

	/** ¿Ya la entregó / rindió / completó? */
	public static function is_done( int $user_id, int $table_lesson_id, int $wp_lesson_id, string $type ): bool {
		global $wpdb;
		$completed = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_lesson_progress WHERE user_id = %d AND lesson_id = %d", $user_id, $table_lesson_id ) ); // phpcs:ignore WordPress.DB
		if ( 'completed' === $completed ) {
			return true;
		}
		if ( 'assignment' === $type ) {
			$submission = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Submission' ) : null;
			$found      = $submission && method_exists( $submission, 'get_user_submission_for_grading' ) ? (array) $submission->get_user_submission_for_grading( $user_id, $wp_lesson_id ) : array();
			return ! empty( $found['submission_id'] );
		}
		if ( 'quiz' === $type ) {
			$attempt = get_user_meta( $user_id, 'clms_quiz_attempt_' . $wp_lesson_id, true );
			return ! empty( $attempt );
		}
		return false;
	}

	/**
	 * Fechas límite de tareas y quizzes entre dos marcas.
	 *
	 * @return array<int,array>
	 */
	public static function deadlines( int $user_id, int $from, int $to, ?array $courses = null ): array {
		$items   = array();
		$courses = $courses ?? self::courses( $user_id );
		foreach ( $courses as $course_id => $course ) {
			foreach ( \ATORA\LMS\LMS_Course_Service::get_lessons( $course_id ) as $lesson ) {
				$wp_lesson = absint( $lesson['wp_post_id'] ?? 0 );
				if ( ! $wp_lesson || 'publish' !== get_post_status( $wp_lesson ) ) {
					continue;
				}
				$due = self::due_ts( $wp_lesson );
				if ( ! $due || $due < $from || $due > $to ) {
					continue;
				}
				$link = class_exists( 'ATORA_Mobile_REST_Controller' ) ? ATORA_Mobile_REST_Controller::lesson_link( $wp_lesson ) : null;
				$type = $link['type'] ?? 'lesson';
				if ( ! in_array( $type, array( 'assignment', 'quiz' ), true ) ) {
					continue;
				}
				$items[] = array(
					'type'      => 'assignment' === $type ? 'assignment_due' : 'quiz_due',
					'title'     => sanitize_text_field( (string) ( $lesson['title'] ?? get_the_title( $wp_lesson ) ) ),
					'starts_at' => self::iso( $due ),
					'ends_at'   => null,
					'_ts'       => $due,
					'course'    => array( 'id' => $course_id, 'title' => $course['title'] ),
					'link'      => $link,
					'done'      => self::is_done( $user_id, absint( $lesson['id'] ), $wp_lesson, $type ),
				);
			}
		}
		return $items;
	}

	/** Clases en vivo (meta de la lección) entre dos marcas. */
	private static function live_classes( int $from, int $to, array $courses ): array {
		$items = array();
		foreach ( $courses as $course_id => $course ) {
			foreach ( \ATORA\LMS\LMS_Course_Service::get_lessons( $course_id ) as $lesson ) {
				$wp_lesson = absint( $lesson['wp_post_id'] ?? 0 );
				if ( ! $wp_lesson || 'publish' !== get_post_status( $wp_lesson ) ) {
					continue;
				}
				$tz    = (string) get_post_meta( $wp_lesson, '_clms_live_class_timezone', true );
				$start = self::local_ts( str_replace( 'T', ' ', (string) get_post_meta( $wp_lesson, '_clms_live_class_starts_at', true ) ), '', $tz );
				if ( ! $start || $start < $from || $start > $to ) {
					continue;
				}
				$end     = self::local_ts( str_replace( 'T', ' ', (string) get_post_meta( $wp_lesson, '_clms_live_class_ends_at', true ) ), '', $tz );
				$items[] = array(
					'type'      => 'live_class',
					'title'     => sanitize_text_field( (string) ( $lesson['title'] ?? get_the_title( $wp_lesson ) ) ),
					'starts_at' => self::iso( $start, $tz ),
					'ends_at'   => $end ? self::iso( $end, $tz ) : null,
					'_ts'       => $start,
					'course'    => array( 'id' => $course_id, 'title' => $course['title'] ),
					'link'      => class_exists( 'ATORA_Mobile_REST_Controller' ) ? ATORA_Mobile_REST_Controller::lesson_link( $wp_lesson ) : null,
					'done'      => false,
				);
			}
		}
		return $items;
	}

	/** Eventos del calendario del usuario o de sus cursos (sin ocurrencias internas de seguimiento). */
	private static function calendar_events( int $user_id, int $from, int $to, array $courses ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_calendar_events';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
			return array();
		}
		$by_wp = array();
		foreach ( $courses as $course_id => $course ) {
			$by_wp[ $course['wp'] ] = $course_id;
		}
		$zone        = wp_timezone();
		$from_local  = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
		$to_local    = ( new DateTimeImmutable( '@' . $to ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
		$course_list = $by_wp ? implode( ',', array_map( 'absint', array_keys( $by_wp ) ) ) : '0';
		$has_plan    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'followup_plan_id' ) ); // phpcs:ignore WordPress.DB
		$plan_filter = $has_plan ? ' AND (followup_plan_id IS NULL OR followup_plan_id = 0)' : '';
		$rows        = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE start_datetime BETWEEN %s AND %s AND (user_id = %d OR course_id IN ({$course_list})){$plan_filter} ORDER BY start_datetime ASC LIMIT 500",
				$from_local,
				$to_local,
				$user_id
			),
			ARRAY_A
		);
		$items = array();
		foreach ( $rows as $row ) {
			$wp_course = absint( $row['course_id'] );
			if ( $wp_course && ! isset( $by_wp[ $wp_course ] ) ) {
				continue;
			}
			$start = self::local_ts( (string) $row['start_datetime'] );
			$end   = ! empty( $row['end_datetime'] ) ? self::local_ts( (string) $row['end_datetime'] ) : 0;
			$link  = null;
			if ( ! empty( $row['lesson_id'] ) && class_exists( 'ATORA_Mobile_REST_Controller' ) ) {
				$link = ATORA_Mobile_REST_Controller::lesson_link( absint( $row['lesson_id'] ) );
			}
			$items[] = array(
				'type'      => 'event',
				'title'     => sanitize_text_field( (string) $row['title'] ),
				'starts_at' => self::iso( $start ),
				'ends_at'   => $end ? self::iso( $end ) : null,
				'_ts'       => $start,
				'course'    => $wp_course ? array( 'id' => $by_wp[ $wp_course ], 'title' => $courses[ $by_wp[ $wp_course ] ]['title'] ) : null,
				'link'      => $link,
				'done'      => false,
			);
		}
		return $items;
	}

	const REMINDER_HOOK = 'atora_deadline_reminders_daily';

	public static function boot(): void {
		add_action( self::REMINDER_HOOK, array( __CLASS__, 'send_deadline_reminders' ) );
		add_action( 'init', static function () {
			if ( ! wp_next_scheduled( self::REMINDER_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::REMINDER_HOOK );
			}
		} );
	}

	/**
	 * Aviso "fecha límite" (kind `deadline_reminder`) para tareas y quizzes que vencen
	 * en las próximas 24 horas y aún no se entregaron. Uno por lección y estudiante.
	 *
	 * @return int Avisos creados.
	 */
	public static function send_deadline_reminders(): int {
		global $wpdb;
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return 0;
		}
		$users = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->prefix}atora_enrollments WHERE status = 'active'" ) ); // phpcs:ignore WordPress.DB
		$now   = time();
		$sent  = 0;
		foreach ( $users as $user_id ) {
			foreach ( self::deadlines( $user_id, $now, $now + DAY_IN_SECONDS ) as $item ) {
				if ( $item['done'] || empty( $item['link']['id'] ) ) {
					continue;
				}
				$wp_lesson = class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ? \ATORA\LMS\LMS_Course_Service::get_lesson( (int) $item['link']['id'] ) : null;
				$created   = $notifications->add_notification(
					$user_id,
					array(
						'type'       => 'deadline_reminder',
						'title'      => __( 'Fecha límite próxima', 'atora-lms' ),
						'message'    => sprintf( __( '%1$s vence el %2$s.', 'atora-lms' ), $item['title'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['_ts'] ) ),
						'lesson_id'  => $wp_lesson ? absint( $wp_lesson['wp_post_id'] ?? 0 ) : 0,
						'dedupe_key' => 'deadline_' . (int) $item['link']['id'] . '_' . (int) $item['_ts'],
					)
				);
				if ( $created ) {
					++$sent;
				}
			}
		}
		return $sent;
	}

	/**
	 * Agenda entre dos marcas (máximo 62 días).
	 *
	 * @return array<int,array>|WP_Error
	 */
	public static function for_user( int $user_id, int $from, int $to ) {
		if ( $to < $from || ( $to - $from ) > self::MAX_DAYS * DAY_IN_SECONDS ) {
			return new WP_Error( 'atora_agenda_range', sprintf( __( 'El rango máximo es de %d días.', 'atora-lms' ), self::MAX_DAYS ), array( 'status' => 400 ) );
		}
		return self::for_courses( $user_id, $from, $to, self::courses( $user_id ) );
	}

	/**
	 * 6.31.0: agenda de unos cursos dados ([table_course_id => ['wp','title']]),
	 * p. ej. los del docente. `done` se evalúa para `$user_id`.
	 */
	public static function for_courses( int $user_id, int $from, int $to, array $courses ): array {
		$items   = array_merge(
			self::calendar_events( $user_id, $from, $to, $courses ),
			self::deadlines( $user_id, $from, $to, $courses ),
			self::live_classes( $from, $to, $courses )
		);
		usort( $items, static fn( $a, $b ) => $a['_ts'] <=> $b['_ts'] );
		return array_map(
			static function ( $item ) {
				unset( $item['_ts'] );
				return $item;
			},
			$items
		);
	}
}
