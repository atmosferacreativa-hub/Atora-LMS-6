<?php
/**
 * Helpers para normalizar CMI entre SCORM 1.2 y SCORM 2004.
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_SCORM_CMI {
	/**
	 * @param string               $scorm_version '1.2'|'2004'.
	 * @param array<string,string> $values        Mapa key=>value (solo lo último conocido).
	 * @return array{completion_status:string,success_status:string,is_complete:bool}
	 */
	public static function derive_state_from_values( string $scorm_version, array $values ): array {
		$scorm_version = trim( (string) $scorm_version );
		$completion = 'unknown';
		$success    = 'unknown';

		if ( '1.2' === $scorm_version ) {
			$lesson_status = strtolower( trim( (string) ( $values['cmi.core.lesson_status'] ?? '' ) ) );
			if ( in_array( $lesson_status, array( 'completed', 'passed', 'failed' ), true ) ) {
				$completion = 'completed';
			} elseif ( in_array( $lesson_status, array( 'incomplete', 'browsed', 'not attempted' ), true ) ) {
				$completion = 'incomplete';
			}
			if ( 'passed' === $lesson_status ) {
				$success = 'passed';
			} elseif ( 'failed' === $lesson_status ) {
				$success = 'failed';
			}
		} else {
			$completion_status = strtolower( trim( (string) ( $values['cmi.completion_status'] ?? '' ) ) );
			$success_status    = strtolower( trim( (string) ( $values['cmi.success_status'] ?? '' ) ) );

			if ( in_array( $completion_status, array( 'completed', 'incomplete', 'not attempted', 'unknown' ), true ) ) {
				$completion = $completion_status;
			}
			if ( in_array( $success_status, array( 'passed', 'failed', 'unknown' ), true ) ) {
				$success = $success_status;
			}
		}

		$is_complete = in_array( $completion, array( 'completed' ), true ) || in_array( $success, array( 'passed', 'failed' ), true );
		return array(
			'completion_status' => $completion,
			'success_status'    => $success,
			'is_complete'       => $is_complete,
		);
	}
}

