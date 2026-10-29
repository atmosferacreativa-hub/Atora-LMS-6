<?php
/**
 * Aviso de inicio de período (6.41.0).
 *
 * El día en que empieza un período abierto (fecha de la academia), cada estudiante matriculado en sus cursos recibe un
 * aviso con la lista de sus cursos de ese período. Un aviso por estudiante y período (dedupe), y el período queda
 * marcado para no repetirlo. Lo corre el cron diario; también al guardar un período que ya empezó.
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Period_Notices {

	const HOOK = 'atora_periods_daily';

	public static function boot(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/** Envía los avisos pendientes. @return int Avisos enviados. */
	public static function run(): int {
		global $wpdb;
		$today   = (string) current_time( 'Y-m-d' );
		$periods = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . ATORA_Periods_Service::table_periods() . " WHERE status = 'open' AND starts_on IS NOT NULL AND starts_on <= %s AND ( ends_on IS NULL OR ends_on >= %s ) AND start_notified_at IS NULL",
			$today,
			$today
		), ARRAY_A ); // phpcs:ignore WordPress.DB
		$sent = 0;
		foreach ( $periods as $period ) {
			$sent += self::notify( $period );
		}
		return $sent;
	}

	/** @param array<string,mixed> $period */
	public static function notify( array $period ): int {
		global $wpdb;
		$period_id     = absint( $period['id'] ?? 0 );
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( $period_id <= 0 || ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return 0;
		}
		$courses = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT wp_course_id FROM ' . ATORA_Periods_Service::table_links() . ' WHERE period_id = %d', $period_id ) ) ); // phpcs:ignore WordPress.DB

		// Cursos del período por estudiante.
		$by_student = array();
		foreach ( $courses as $wp_course ) {
			if ( 'publish' !== get_post_status( $wp_course ) ) {
				continue;
			}
			foreach ( array_map( 'absint', (array) CLMS_Helper::get_enrolled_student_ids( $wp_course ) ) as $student ) {
				$by_student[ $student ][] = (string) get_the_title( $wp_course );
			}
		}

		$sent = 0;
		foreach ( $by_student as $student => $titles ) {
			usort( $titles, static fn( $a, $b ) => strnatcasecmp( remove_accents( $a ), remove_accents( $b ) ) );
			if ( $notifications->add_notification( $student, array(
				'type'       => 'period_start',
				/* translators: %s nombre del período */
				'title'      => sprintf( __( 'Comienza %s', 'atora-lms' ), (string) $period['name'] ),
				/* translators: %s lista de cursos */
				'message'    => sprintf( __( 'Tus cursos de este período: %s.', 'atora-lms' ), implode( ', ', $titles ) ),
				'link'       => home_url( '/' ),
				'dedupe_key' => 'period_start_' . $period_id,
			) ) ) {
				++$sent;
			}
		}
		$wpdb->update( ATORA_Periods_Service::table_periods(), array( 'start_notified_at' => current_time( 'mysql', true ) ), array( 'id' => $period_id ) ); // phpcs:ignore WordPress.DB
		return $sent;
	}
}
