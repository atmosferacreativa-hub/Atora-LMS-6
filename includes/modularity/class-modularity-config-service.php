<?php
/**
 * Servicio liviano de modularidad para desacoplar configuración y mapeos
 * sin alterar el comportamiento por defecto.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Modularity_Config_Service {

	/**
	 * Aplica un filtro modular por clave.
	 *
	 * @param string $key   Clave de filtro.
	 * @param mixed  $value Valor.
	 * @param mixed  ...$args Contexto adicional.
	 * @return mixed
	 */
	public function apply( $key, $value, ...$args ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return $value;
		}

		return apply_filters( 'clms_modularity_' . $key, $value, ...$args );
	}

	/**
	 * Normaliza metadatos de prioridad para dashboards/colas.
	 *
	 * @param array<string,mixed> $meta Meta de prioridad.
	 * @return array<string,mixed>
	 */
	public function normalize_priority_meta( $meta ) {
		$meta = is_array( $meta ) ? $meta : array();

		$rank  = isset( $meta['priority_rank'] ) ? absint( $meta['priority_rank'] ) : 5;
		$label = isset( $meta['priority_label'] ) ? sanitize_text_field( (string) $meta['priority_label'] ) : '';
		$class = isset( $meta['priority_class'] ) ? sanitize_html_class( (string) $meta['priority_class'] ) : 'is-info';

		if ( '' === $class ) {
			$class = 'is-info';
		}

		return array(
			'priority_rank'  => $rank,
			'priority_label' => $label,
			'priority_class' => $class,
		);
	}
}
