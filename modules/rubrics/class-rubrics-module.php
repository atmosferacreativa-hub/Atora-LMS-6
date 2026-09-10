<?php
/**
 * ATORA LMS — Rubrics v2 (pesos, escalas, holística + presets)
 *
 * Nota: el CPT principal `clms_rubric` vive en `includes/class-rubric.php`.
 * Este módulo añade presets y helpers de escala.
 *
 * @package ATORA_LMS
 * @since   6.13.3
 */

namespace ATORA\Rubrics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rubrics_Module {

	const PRESET_CPT = 'clms_rubric_preset';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_preset_cpt' ) );
		add_action( 'clms_submission_graded', array( __CLASS__, 'lock_course_scale_on_first_grade' ), 5, 5 );
	}

	public static function register_preset_cpt(): void {
		register_post_type(
			self::PRESET_CPT,
			array(
				'labels'             => array(
					'name'          => __( 'Presets de rúbrica', 'atora-lms' ),
					'singular_name' => __( 'Preset de rúbrica', 'atora-lms' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'clms-dashboard',
				'show_in_rest'       => false,
				'supports'           => array( 'title' ),
				'capability_type'    => array( 'clms_rubric', 'clms_rubrics' ),
				'map_meta_cap'       => true,
				'has_archive'        => false,
				'publicly_queryable' => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-clipboard',
			)
		);
	}

	/**
	 * Locks course grade scale after the first published grade.
	 *
	 * @param int    $submission_id
	 * @param int    $student_id
	 * @param string $status
	 * @param mixed  $grade
	 * @param string $feedback
	 * @return void
	 */
	public static function lock_course_scale_on_first_grade( int $submission_id, int $student_id, string $status = '', $grade = '', string $feedback = '' ): void {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id || '' === (string) $grade ) {
			return;
		}

		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $course_id ) {
			return;
		}

		$scale = sanitize_key( (string) get_post_meta( $course_id, '_clms_course_grade_scale', true ) );
		if ( '' === $scale ) {
			return;
		}

		if ( '1' === (string) get_post_meta( $course_id, '_clms_course_grade_scale_locked', true ) ) {
			return;
		}

		update_post_meta( $course_id, '_clms_course_grade_scale_locked', '1' );
	}
}
