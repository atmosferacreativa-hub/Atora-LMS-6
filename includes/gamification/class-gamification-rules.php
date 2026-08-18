<?php
/**
 * Reglas y configuración de gamificación académica.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gamification_Rules {

	const OPT_ENABLED         = 'clms_gamification_enabled';
	const OPT_POINTS          = 'clms_gamification_event_points';
	const OPT_PASS_THRESHOLD  = 'clms_gamification_pass_threshold';
	const OPT_LEVEL_NAMES     = 'clms_gamification_level_names';

	/**
	 * Reglas de eventos.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_rules() {
		$points = get_option( self::OPT_POINTS, array() );
		$points = is_array( $points ) ? $points : array();

		$default_points = array(
			'lesson_completed'   => 10,
			'submission_sent'    => 8,
			'evaluation_passed'  => 25,
			'feedback_received'  => 6,
			'course_completed'   => 80,
			'program_completed'  => 140,
			'certificate_issued' => 120,
			'peer_review_quality'=> 12,
		);

		$rules = array();
		foreach ( $default_points as $event_type => $default ) {
			$rules[ $event_type ] = array(
				'enabled' => $this->is_enabled(),
				'points'  => isset( $points[ $event_type ] ) ? max( 0, absint( $points[ $event_type ] ) ) : $default,
			);
		}

		$rules['evaluation_passed']['pass_threshold'] = $this->get_pass_threshold();

		return apply_filters( 'clms_gamification_rules_service', $rules );
	}

	/**
	 * Nombres de niveles académicos.
	 *
	 * @return array<int,string>
	 */
	public function get_level_names() {
		$names = get_option( self::OPT_LEVEL_NAMES, array() );
		$names = is_array( $names ) ? $names : array();

		$defaults = array(
			1 => __( 'Aspirante', 'atora-lms' ),
			2 => __( 'Aprendiz activo', 'atora-lms' ),
			3 => __( 'Comunicador en formación', 'atora-lms' ),
			4 => __( 'Productor académico', 'atora-lms' ),
			5 => __( 'Perfil profesional', 'atora-lms' ),
		);

		foreach ( $defaults as $level => $label ) {
			if ( ! empty( $names[ $level ] ) ) {
				$defaults[ $level ] = sanitize_text_field( (string) $names[ $level ] );
			}
		}

		return $defaults;
	}

	/**
	 * Umbrales de nivel.
	 *
	 * @return array<int,int>
	 */
	public function get_level_thresholds() {
		$thresholds = array(
			1 => 0,
			2 => 120,
			3 => 320,
			4 => 620,
			5 => 980,
		);

		$thresholds = apply_filters( 'clms_gamification_level_thresholds_service', $thresholds );
		if ( ! is_array( $thresholds ) ) {
			return array( 1 => 0 );
		}

		$sanitized = array();
		foreach ( $thresholds as $level => $points ) {
			$sanitized[ max( 1, absint( $level ) ) ] = max( 0, absint( $points ) );
		}
		ksort( $sanitized );

		if ( empty( $sanitized ) || ! isset( $sanitized[1] ) ) {
			$sanitized[1] = 0;
			ksort( $sanitized );
		}

		return $sanitized;
	}

	public function get_pass_threshold() {
		$value = absint( get_option( self::OPT_PASS_THRESHOLD, 70 ) );
		if ( $value < 1 ) {
			$value = 70;
		}
		return min( 100, $value );
	}

	public function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, true );
	}
}
