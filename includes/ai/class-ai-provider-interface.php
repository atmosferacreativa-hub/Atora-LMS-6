<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface CLMS_AI_Provider_Interface {

	/**
	 * Identificador interno del proveedor.
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Etiqueta visible del proveedor.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Indica si el proveedor estив listo para usarse.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Genera revisiиоn IA basada en payload interno del LMS.
	 *
	 * @param array $payload Payload normalizado por CLMS_AI.
	 * @return array|WP_Error
	 */
	public function generate_review( $payload );
}