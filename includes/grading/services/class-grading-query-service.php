<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Grading_Query_Service {

	/**
	 * Normaliza filas de cola para evitar llaves faltantes en render.
	 *
	 * @param array $queue_items
	 * @return array
	 */
	public function normalize_queue_items( $queue_items ) {
		$queue_items = is_array( $queue_items ) ? $queue_items : array();
		$result      = array();

		foreach ( $queue_items as $item ) {
			$item     = is_array( $item ) ? $item : array();
			$result[] = array(
				'id'             => absint( $item['id'] ?? 0 ),
				'is_current'     => ! empty( $item['is_current'] ),
				'student_name'   => sanitize_text_field( (string) ( $item['student_name'] ?? '' ) ),
				'lesson_title'   => sanitize_text_field( (string) ( $item['lesson_title'] ?? '' ) ),
				'status'         => sanitize_key( (string) ( $item['status'] ?? '' ) ),
				'status_label'   => sanitize_text_field( (string) ( $item['status_label'] ?? '' ) ),
				'priority_class' => sanitize_html_class( (string) ( $item['priority_class'] ?? '' ) ),
				'priority_label' => sanitize_text_field( (string) ( $item['priority_label'] ?? '' ) ),
			);
		}

		return $result;
	}
}
