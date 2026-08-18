<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Service {

	/** @var CLMS_Gradebook_Grid_Service */
	protected $grid_service;

	/** @var CLMS_Gradebook_Calculation_Service */
	protected $calculation_service;

	/** @var CLMS_Gradebook_Schema_Service */
	protected $schema_service;

	public function __construct( $grid_service = null, $calculation_service = null, $schema_service = null ) {
		$this->grid_service        = $grid_service instanceof CLMS_Gradebook_Grid_Service ? $grid_service : new CLMS_Gradebook_Grid_Service();
		$this->calculation_service = $calculation_service instanceof CLMS_Gradebook_Calculation_Service ? $calculation_service : new CLMS_Gradebook_Calculation_Service();
		$this->schema_service      = $schema_service instanceof CLMS_Gradebook_Schema_Service ? $schema_service : new CLMS_Gradebook_Schema_Service();
	}

	/**
	 * Punto de entrada para construir la matriz del gradebook.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $args Filtros de consulta.
	 * @return array
	 */
	public function build_grid( $course_id, $args = array() ) {
		$course_id = absint( $course_id );
		$args      = is_array( $args ) ? $args : array();

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return $this->empty_grid( $course_id );
		}

		$schema  = $this->schema_service->get_schema( $course_id, $args );
		$columns = $this->grid_service->build_columns( $course_id, $schema, $args );
		$rows    = $this->grid_service->build_rows( $course_id, $columns, $schema, $this->calculation_service, $args );

		$grid = array(
			'course_id' => $course_id,
			'columns'   => is_array( $columns ) ? $columns : array(),
			'rows'      => is_array( $rows ) ? $rows : array(),
			'schema'    => is_array( $schema ) ? $schema : array(),
		);

		return apply_filters( 'clms_gradebook_grid', $grid, $course_id, $args, $schema );
	}

	/**
	 * Estructura vacía segura para curso inválido o sin datos.
	 *
	 * @param int $course_id ID del curso.
	 * @return array
	 */
	protected function empty_grid( $course_id ) {
		return array(
			'course_id' => absint( $course_id ),
			'columns'   => array(),
			'rows'      => array(),
			'schema'    => array(),
		);
	}
}
