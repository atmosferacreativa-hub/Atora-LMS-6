<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Schema_Service {

	const SCHEMA_META_KEY  = '_clms_gradebook_scheme';
	const LEGACY_META_KEY  = '_clms_grading_scheme';

	/**
	 * Obtiene el esquema base del gradebook para un curso.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $args Argumentos de consulta.
	 * @return array
	 */
	public function get_schema( $course_id, $args = array() ) {
		$course_id = absint( $course_id );
		$args      = is_array( $args ) ? $args : array();
		$scheme    = $this->get_weight_groups( $course_id );

		$schema = array(
			'course_id'      => $course_id,
			'weight_groups'  => $scheme,
			'has_scheme'     => ! empty( $scheme ),
			'weights_sum'    => $this->sum_weights( $scheme ),
			'default_group'  => 'general',
			'cohort_enabled' => false,
			'args'           => array(
				'student_search'  => sanitize_text_field( (string) ( $args['student_search'] ?? '' ) ),
				'activity_search' => sanitize_text_field( (string) ( $args['activity_search'] ?? '' ) ),
				'status'          => sanitize_key( (string) ( $args['status'] ?? '' ) ),
				'group'           => sanitize_key( (string) ( $args['group'] ?? '' ) ),
				'cohort_id'       => absint( $args['cohort_id'] ?? 0 ),
			),
		);

		return apply_filters( 'clms_gradebook_schema', $schema, $course_id, $args );
	}

	/**
	 * Guarda el esquema de grupos y pesos del gradebook de un curso.
	 * Mantiene compatibilidad con CLMS_Grading_Engine.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $groups    Array de grupos: [{key, label, weight, drop_lowest?}].
	 * @return array {success: bool, error?: string, weights_sum: float}
	 */
	public function save_scheme( $course_id, $groups ) {
		$course_id = absint( $course_id );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return array( 'success' => false, 'error' => __( 'Curso no válido.', 'atora-lms' ) );
		}

		if ( ! is_array( $groups ) ) {
			return array( 'success' => false, 'error' => __( 'Grupos no válidos.', 'atora-lms' ) );
		}

		$normalized = array();
		$total_weight = 0.0;

		foreach ( $groups as $group ) {
			$group = is_array( $group ) ? $group : array();
			$key   = sanitize_key( (string) ( $group['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$weight = max( 0.0, min( 100.0, (float) ( $group['weight'] ?? 0 ) ) );
			$normalized[ $key ] = array(
				'key'         => $key,
				'label'       => sanitize_text_field( (string) ( $group['label'] ?? ucfirst( str_replace( '_', ' ', $key ) ) ) ),
				'weight'      => $weight,
				'drop_lowest' => max( 0, absint( $group['drop_lowest'] ?? 0 ) ),
			);
			$total_weight += $weight;
		}

		if ( empty( $normalized ) ) {
			delete_post_meta( $course_id, self::SCHEMA_META_KEY );
			do_action( 'clms_gradebook_scheme_updated', $course_id, array() );
			return array( 'success' => true, 'weights_sum' => 0.0 );
		}

		update_post_meta( $course_id, self::SCHEMA_META_KEY, $normalized );

		if ( class_exists( 'CLMS_Grading_Engine' ) ) {
			$engine = new CLMS_Grading_Engine();
			$engine->invalidate_grade_cache( 0, $course_id );
		}

		do_action( 'clms_gradebook_scheme_updated', $course_id, $normalized );

		return array(
			'success'      => true,
			'weights_sum'  => round( $total_weight, 2 ),
			'warning'      => abs( 100 - $total_weight ) > 0.01
				? __( 'Los pesos no suman 100%. El cálculo se normalizará proporcionalmente.', 'atora-lms' )
				: '',
		);
	}

	/**
	 * Obtiene los grupos de ponderación del curso.
	 * Prioriza _clms_gradebook_scheme (nuevo) sobre _clms_grading_scheme (legacy).
	 *
	 * @param int $course_id ID del curso.
	 * @return array
	 */
	public function get_weight_groups( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$new_scheme = get_post_meta( $course_id, self::SCHEMA_META_KEY, true );
		if ( is_array( $new_scheme ) && ! empty( $new_scheme ) ) {
			return $this->normalize_groups( $new_scheme );
		}

		$legacy = get_post_meta( $course_id, self::LEGACY_META_KEY, true );
		if ( is_array( $legacy ) && ! empty( $legacy ) ) {
			return $this->normalize_legacy_scheme( $legacy );
		}

		return array();
	}

	/**
	 * Suma los pesos del esquema.
	 *
	 * @param array $groups Grupos normalizados.
	 * @return float
	 */
	public function sum_weights( $groups ) {
		$groups = is_array( $groups ) ? $groups : array();
		return round(
			array_sum( array_column( array_values( $groups ), 'weight' ) ),
			2
		);
	}

	/**
	 * Normaliza grupos guardados en el nuevo formato.
	 */
	protected function normalize_groups( $raw ) {
		$raw        = is_array( $raw ) ? $raw : array();
		$normalized = array();
		foreach ( $raw as $group_key => $group_data ) {
			$group_data = is_array( $group_data ) ? $group_data : array();
			$key        = sanitize_key( is_string( $group_key ) ? $group_key : (string) ( $group_data['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = array(
				'key'         => $key,
				'label'       => sanitize_text_field( (string) ( $group_data['label'] ?? ucfirst( str_replace( '_', ' ', $key ) ) ) ),
				'weight'      => max( 0, min( 100, (float) ( $group_data['weight'] ?? 0 ) ) ),
				'drop_lowest' => max( 0, absint( $group_data['drop_lowest'] ?? 0 ) ),
			);
		}
		return $normalized;
	}

	/**
	 * Normaliza esquema legacy de CLMS_Grading_Engine a formato gradebook.
	 * El legacy tiene 'components' con {assignments, quizzes, ...}.
	 */
	protected function normalize_legacy_scheme( $legacy ) {
		$legacy     = is_array( $legacy ) ? $legacy : array();
		$components = isset( $legacy['components'] ) && is_array( $legacy['components'] )
			? $legacy['components']
			: $legacy;

		$normalized = array();
		foreach ( $components as $key => $data ) {
			$data = is_array( $data ) ? $data : array();
			$k    = sanitize_key( (string) $key );
			if ( '' === $k || ! isset( $data['weight'] ) ) {
				continue;
			}

			$label_map = array(
				'assignments' => 'Tareas',
				'quizzes'     => 'Exámenes / Quizzes',
				'projects'    => 'Proyectos',
				'participation' => 'Participación',
			);

			$normalized[ $k ] = array(
				'key'         => $k,
				'label'       => sanitize_text_field( $label_map[ $k ] ?? ucfirst( str_replace( '_', ' ', $k ) ) ),
				'weight'      => max( 0, min( 100, (float) $data['weight'] ) ),
				'drop_lowest' => max( 0, absint( $data['drop_lowest'] ?? 0 ) ),
			);
		}
		return $normalized;
	}
}
