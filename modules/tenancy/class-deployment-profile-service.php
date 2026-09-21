<?php
/**
 * Deployment_Profile_Service — perfiles small/medium/large + avisos de cupo.
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.4
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Deployment_Profile_Service {

	const OPTION_KEY = 'atora_deployment_profile';

	public static function init(): void {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'maybe_render_seats_notice' ) );
		}
	}

	public static function current_profile(): string {
		$profile = sanitize_key( (string) get_option( self::OPTION_KEY, 'small' ) );
		return in_array( $profile, array( 'small', 'medium', 'large' ), true ) ? $profile : 'small';
	}

	public static function cap_for_profile( string $profile ): int {
		$profile = sanitize_key( $profile );
		if ( 'medium' === $profile ) { return 1500; }
		if ( 'large' === $profile ) { return 10000; }
		return 100;
	}

	public static function seats_used_total(): int {
		if ( ! class_exists( '\\ATORA\\LMS\\Institution_Service' ) ) {
			return 0;
		}
		$rows = Institution_Service::seats_report();
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$total += absint( is_array( $row ) ? ( $row['seats_used'] ?? 0 ) : 0 );
		}
		return max( 0, $total );
	}

	/**
	 * Valida `seats_licensed` contra el tope del perfil (clamp).
	 *
	 * @return int Filas afectadas.
	 */
	public static function clamp_seats_licensed_to_profile_cap(): int {
		global $wpdb;

		$cap = self::cap_for_profile( self::current_profile() );
		if ( $cap <= 0 ) { return 0; }

		$table = $wpdb->prefix . 'atora_institutions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET seats_licensed = %d WHERE seats_licensed > %d", $cap, $cap ) );
	}

	public static function maybe_render_seats_notice(): void {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { return; }
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) { return; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }

		if ( ! ( current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) ) ) {
			return;
		}

		$profile = self::current_profile();
		$cap     = self::cap_for_profile( $profile );
		$used    = self::seats_used_total();
		if ( $cap <= 0 ) { return; }

		$ratio = $used / max( 1, $cap );
		if ( $ratio < 0.9 ) {
			return;
		}

		$is_over = $used > $cap;
		$class   = $is_over ? 'notice notice-error' : 'notice notice-warning';
		$title   = $is_over
			? __( 'ATORA: instalación sobre el tope del perfil', 'atora-lms' )
			: __( 'ATORA: instalación cerca del tope del perfil', 'atora-lms' );

		$message = sprintf(
			/* translators: 1: profile, 2: used seats, 3: cap */
			__( 'Perfil: %1$s — Asientos usados: %2$d / %3$d.', 'atora-lms' ),
			esc_html( $profile ),
			absint( $used ),
			absint( $cap )
		);

		echo '<div class="' . esc_attr( $class ) . '"><p><strong>' . esc_html( $title ) . '</strong> ' . esc_html( $message ) . '</p></div>';

		if ( $is_over ) {
			self::maybe_record_overcap_audit( $profile, $used, $cap );
		}
	}

	private static function maybe_record_overcap_audit( string $profile, int $used, int $cap ): void {
		if ( ! class_exists( '\\ATORA\\LMS\\Tenancy_Audit_Service' ) ) {
			return;
		}

		$institution_id = absint( get_option( 'atora_default_institution', 0 ) );
		$actor_id       = absint( get_current_user_id() );
		if ( $institution_id <= 0 || $actor_id <= 0 ) {
			return;
		}

		$key = 'atora_seats_overcap_' . $institution_id . '_' . gmdate( 'Ymd' );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		Tenancy_Audit_Service::record(
			$institution_id,
			$actor_id,
			0,
			0,
			'institution',
			$institution_id,
			'seats_overcap',
			array(
				'profile' => sanitize_key( $profile ),
				'used'    => absint( $used ),
				'cap'     => absint( $cap ),
			)
		);
	}
}

