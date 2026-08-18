<?php
/**
 * Servicio de competencias académicas por curso.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Competency_Service {

	const META_COMPETENCIES_STRUCT = '_clms_course_competencies_struct';
	const META_COMPETENCIES_TEXT   = '_clms_course_competencies';

	/**
	 * Obtiene competencias del curso normalizadas.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_course_competencies( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$raw_struct = get_post_meta( $course_id, self::META_COMPETENCIES_STRUCT, true );
		$list       = $this->normalize_competencies( $raw_struct );

		if ( ! empty( $list ) ) {
			return $list;
		}

		$raw_text = (string) get_post_meta( $course_id, self::META_COMPETENCIES_TEXT, true );
		if ( '' === trim( $raw_text ) ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw_text );
		$rows  = array();
		foreach ( (array) $lines as $line ) {
			$title = sanitize_text_field( (string) $line );
			if ( '' === $title ) {
				continue;
			}
			$rows[] = array(
				'title' => $title,
			);
		}

		return $this->normalize_competencies( $rows );
	}

	/**
	 * Guarda competencias del curso en estructura compatible.
	 *
	 * @param int   $course_id     Curso.
	 * @param array $competencies  Competencias.
	 * @return bool
	 */
	public function save_course_competencies( $course_id, $competencies ) {
		$course_id     = absint( $course_id );
		$competencies  = $this->normalize_competencies( $competencies );

		if ( ! $course_id ) {
			return false;
		}

		update_post_meta( $course_id, self::META_COMPETENCIES_STRUCT, $competencies );

		$lines = array();
		foreach ( $competencies as $competency ) {
			$lines[] = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
		}

		update_post_meta( $course_id, self::META_COMPETENCIES_TEXT, implode( "\n", array_filter( $lines ) ) );

		return true;
	}

	/**
	 * Obtiene una competencia puntual por ID.
	 *
	 * @param int    $course_id      Curso.
	 * @param string $competency_id  ID de competencia.
	 * @return array<string,mixed>
	 */
	public function get_competency( $course_id, $competency_id ) {
		$course_id     = absint( $course_id );
		$competency_id = sanitize_key( (string) $competency_id );
		$list          = $this->get_course_competencies( $course_id );

		if ( '' === $competency_id || empty( $list ) ) {
			return array();
		}

		foreach ( $list as $competency ) {
			$id = isset( $competency['id'] ) ? sanitize_key( (string) $competency['id'] ) : '';
			if ( $id === $competency_id ) {
				return $competency;
			}
		}

		return array();
	}

	/**
	 * Resuelve ID técnico de competencia desde id/label legacy.
	 *
	 * @param int    $course_id      Curso.
	 * @param string $competency_id  ID técnico recibido.
	 * @param string $competency     Etiqueta legacy.
	 * @return string
	 */
	public function resolve_competency_id( $course_id, $competency_id = '', $competency = '' ) {
		$course_id     = absint( $course_id );
		$competency_id = sanitize_key( (string) $competency_id );
		$competency    = sanitize_text_field( (string) $competency );

		if ( '' !== $competency_id && $course_id > 0 ) {
			$found = $this->get_competency( $course_id, $competency_id );
			if ( ! empty( $found ) ) {
				return $competency_id;
			}
		}

		$slug = sanitize_key( sanitize_title( $competency ) );
		if ( '' === $slug ) {
			return $competency_id;
		}

		if ( $course_id <= 0 ) {
			return $slug;
		}

		$list = $this->get_course_competencies( $course_id );
		foreach ( $list as $item ) {
			$item = is_array( $item ) ? $item : array();
			$id   = sanitize_key( (string) ( $item['id'] ?? '' ) );
			$name = sanitize_key( sanitize_title( (string) ( $item['title'] ?? '' ) ) );
			if ( '' !== $id && ( $id === $slug || $name === $slug ) ) {
				return $id;
			}
		}

		return $slug;
	}

	/**
	 * Normaliza estructura de competencias.
	 *
	 * @param mixed $raw Valor sin procesar.
	 * @return array<int,array<string,mixed>>
	 */
	public function normalize_competencies( $raw ) {
		$rows = is_array( $raw ) ? array_values( $raw ) : array();
		$out  = array();
		$seen = array();

		foreach ( $rows as $index => $row ) {
			$row = is_array( $row ) ? $row : array();

			$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
			if ( '' === $title && isset( $row['name'] ) ) {
				$title = sanitize_text_field( (string) $row['name'] );
			}
			if ( '' === $title ) {
				continue;
			}

			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				$id = sanitize_title( $title );
			}
			if ( '' === $id ) {
				$id = 'comp_' . absint( $index + 1 );
			}
			if ( isset( $seen[ $id ] ) ) {
				$id = $id . '_' . absint( $index + 1 );
			}
			$seen[ $id ] = true;

			$level = isset( $row['level'] ) ? sanitize_key( (string) $row['level'] ) : 'basic';
			if ( ! in_array( $level, array( 'basic', 'intermediate', 'advanced' ), true ) ) {
				$level = 'basic';
			}

			$required = ! empty( $row['required'] );
			$weight   = isset( $row['weight'] ) ? absint( $row['weight'] ) : 0;

			$out[] = array(
				'id'          => $id,
				'title'       => $title,
				'description' => isset( $row['description'] ) ? sanitize_textarea_field( (string) $row['description'] ) : '',
				'level'       => $level,
				'required'    => $required,
				'weight'      => max( 0, min( 100, $weight ) ),
			);
		}

		return $out;
	}
}
