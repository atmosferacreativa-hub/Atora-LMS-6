<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Grading_Status_Service {

	/**
	 * Lista de estados válidos para SpeedGrade y Gradebook.
	 * Incluye estados legacy para compatibilidad.
	 *
	 * @return array
	 */
	public function get_allowed_statuses() {
		// Si existe el mapa completo de estados, usarlo.
		if ( class_exists( 'CLMS_Academic_Status_Map' ) ) {
			return CLMS_Academic_Status_Map::ALL_STATUSES;
		}

		// Fallback: estados legacy + nuevos esenciales.
		return array(
			'draft',
			'submitted',
			'late',
			'resubmitted',
			'in_review',
			'needs_revision',
			'needs_review',
			'returned',
			'graded',
			'excused',
			'locked',
			'overridden',
			'missing',
		);
	}

	/**
	 * Normaliza un estado de entrada, con compatibilidad hacia estados legacy.
	 *
	 * @param string $status Estado crudo.
	 * @return string Estado normalizado.
	 */
	public function normalize_status( $status ) {
		if ( class_exists( 'CLMS_Academic_Status_Map' ) ) {
			return CLMS_Academic_Status_Map::normalize( (string) $status );
		}

		$status = sanitize_key( (string) $status );
		return in_array( $status, $this->get_allowed_statuses(), true ) ? $status : 'in_review';
	}

	/**
	 * Devuelve la etiqueta legible de un estado.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	public function get_label( $status ) {
		if ( class_exists( 'CLMS_Academic_Status_Map' ) ) {
			return CLMS_Academic_Status_Map::label( (string) $status );
		}

		$labels = array(
			'submitted'      => __( 'Enviada', 'atora-lms' ),
			'in_review'      => __( 'En revisión', 'atora-lms' ),
			'graded'         => __( 'Calificada', 'atora-lms' ),
			'needs_revision' => __( 'Requiere corrección', 'atora-lms' ),
			'returned'       => __( 'Devuelta', 'atora-lms' ),
		);

		return $labels[ sanitize_key( (string) $status ) ] ?? sanitize_text_field( (string) $status );
	}

	/**
	 * Verifica si el estado implica que el docente debe actuar.
	 *
	 * @param string $status Estado.
	 * @return bool
	 */
	public function needs_teacher_action( $status ) {
		if ( class_exists( 'CLMS_Academic_Status_Map' ) ) {
			return CLMS_Academic_Status_Map::needs_teacher_action( (string) $status );
		}

		return in_array( sanitize_key( (string) $status ), array( 'submitted', 'in_review', 'late', 'resubmitted' ), true );
	}
}
