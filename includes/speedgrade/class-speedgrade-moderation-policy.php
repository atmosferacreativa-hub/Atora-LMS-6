<?php
/**
 * Reglas del flujo de moderación de SpeedGrader 2.
 *
 * @package ATORA_LMS
 * @since 6.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_SpeedGrade_Moderation_Policy {

	const TRANSITIONS = array(
		'none'              => array( 'pending' ),
		'changes_requested' => array( 'pending' ),
		'pending'           => array( 'approved', 'changes_requested' ),
		'approved'          => array(),
	);

	public static function can_transition( $from, $to ) {
		$from = sanitize_key( (string) $from );
		$to   = sanitize_key( (string) $to );

		return isset( self::TRANSITIONS[ $from ] )
			&& in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	public static function can_moderate( $primary_grader_id, $moderator_id ) {
		return absint( $primary_grader_id ) > 0
			&& absint( $moderator_id ) > 0
			&& absint( $primary_grader_id ) !== absint( $moderator_id );
	}

	public static function is_unresolved( $status ) {
		return in_array(
			sanitize_key( (string) $status ),
			array( 'pending', 'changes_requested' ),
			true
		);
	}

	public static function direct_publish_allowed( $institutional, $status ) {
		return ! $institutional || 'approved' === sanitize_key( (string) $status );
	}
}
