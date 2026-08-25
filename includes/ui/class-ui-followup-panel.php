<?php
/**
 * Followup_Panel — panel lateral compartido (PT-1.2, sprint 6.9.0).
 *
 * Forma homogénea, la misma que ya usa Today_Aggregator_Service::get_today()
 * (6.8.0 PT-1.2) para sus items — este componente no inventa una
 * segunda forma, la extiende con `subtitle`/`items[].badges`/`actions`
 * cuando hace falta mostrar una lista de personas dentro de un panel,
 * no solo una lista de ítems de una pantalla.
 *
 * Server-side: genera el HTML de la carcasa del panel (título,
 * subtítulo, filas vía CLMS_UI_List_Row, acciones en lote). El
 * contenido dinámico (abrir/cerrar, qué ocurrencia mostrar, qué pasa
 * al hacer clic en una acción) lo maneja el lado JS
 * (assets/shared/followup-panel.js), que renderiza exactamente
 * el mismo marcado en el cliente para los casos donde el panel se
 * llena vía REST sin recargar la página — ver ese archivo para la
 * versión JS espejo de render()/render_row().
 *
 * @package ATORA_LMS
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Followup_Panel {

	/**
	 * @param string $dom_id ID del contenedor raíz del panel en el DOM (p. ej. "atora-fu-panel").
	 * @return string Marcado vacío del panel (backdrop + hoja + contenedores con ID fijos que el
	 *                JS completa dinámicamente). No recibe datos porque el panel se abre vacío y
	 *                se llena vía JS/REST — este método es el que reemplaza el marcado que antes
	 *                vivía duplicado en cada vista (followup-plans.php, today-page.php).
	 */
	public static function render_shell( string $dom_id ): string {
		$dom_id = sanitize_html_class( $dom_id );

		return '<div id="' . esc_attr( $dom_id ) . '" class="atora-ui-panel" hidden aria-hidden="true">'
			. '<div class="atora-ui-panel-backdrop" id="' . esc_attr( $dom_id ) . '-backdrop"></div>'
			. '<div class="atora-ui-panel-sheet" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $dom_id ) . '-title">'
				. '<button type="button" class="atora-ui-panel-close" id="' . esc_attr( $dom_id ) . '-close" aria-label="' . esc_attr__( 'Cerrar', 'atora-lms' ) . '">&times;</button>'
				. '<h2 id="' . esc_attr( $dom_id ) . '-title"></h2>'
				. '<p class="atora-ui-panel-subtitle" id="' . esc_attr( $dom_id ) . '-subtitle"></p>'
				. '<div class="atora-ui-panel-actions" id="' . esc_attr( $dom_id ) . '-actions"></div>'
				. '<div class="atora-ui-panel-rows" id="' . esc_attr( $dom_id ) . '-rows"></div>'
			. '</div>'
			. '</div>';
	}

	/**
	 * Render completo server-side (título/subtítulo/filas/acciones ya
	 * resueltos) — para el caso donde el panel se necesita como HTML
	 * estático, no llenado por JS (p. ej. una futura vista impresa, o
	 * pruebas). Los usos reales de 6.9.0 (calendario, "Hoy") llenan el
	 * shell de render_shell() vía JS, pero ambos comparten
	 * exactamente el mismo marcado de filas (CLMS_UI_List_Row) y el
	 * mismo criterio de estado vacío.
	 *
	 * @param array $panel {title, subtitle, items, actions, notice, empty_message, empty_submessage}
	 * @return string
	 */
	public static function render( array $panel ): string {
		$title    = sanitize_text_field( (string) ( $panel['title'] ?? '' ) );
		$subtitle = sanitize_text_field( (string) ( $panel['subtitle'] ?? '' ) );
		$items    = (array) ( $panel['items'] ?? array() );
		$actions  = (array) ( $panel['actions'] ?? array() );
		$notice   = sanitize_text_field( (string) ( $panel['notice'] ?? '' ) );

		$html = '<div class="atora-ui-panel-content">';
		$html .= '<h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $subtitle ) {
			$html .= '<p class="atora-ui-panel-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		if ( '' !== $notice ) {
			$html .= '<p class="atora-ui-panel-notice">' . esc_html( $notice ) . '</p>';
		}

		$bulk_actions = array_values( array_filter( $actions, static fn( $a ) => 'bulk' === ( $a['scope'] ?? '' ) ) );
		if ( ! empty( $bulk_actions ) && ! empty( $items ) ) {
			$html .= '<div class="atora-ui-panel-actions">';
			foreach ( $bulk_actions as $action ) {
				$action_id = sanitize_key( (string) ( $action['action_id'] ?? '' ) );
				if ( '' === $action_id ) {
					continue;
				}
				$html .= '<button type="button" class="button atora-ui-panel-bulk-action" data-action-id="' . esc_attr( $action_id ) . '">'
					. esc_html( (string) ( $action['label'] ?? '' ) ) . '</button>';
			}
			$html .= '</div>';
		}

		if ( empty( $items ) ) {
			$empty_message    = (string) ( $panel['empty_message'] ?? __( 'Nada por acá.', 'atora-lms' ) );
			$empty_submessage = (string) ( $panel['empty_submessage'] ?? '' );
			$html .= '<div class="atora-ui-panel-empty">'
				. '<p class="atora-ui-panel-empty-title">' . esc_html( $empty_message ) . '</p>'
				. ( '' !== $empty_submessage ? '<p class="atora-ui-panel-empty-sub">' . esc_html( $empty_submessage ) . '</p>' : '' )
				. '</div>';
		} else {
			$item_actions = array_values( array_filter( $actions, static fn( $a ) => 'item' === ( $a['scope'] ?? '' ) ) );
			$html .= '<div class="atora-ui-panel-rows">';
			foreach ( $items as $item ) {
				if ( ! empty( $item_actions ) && empty( $item['actions'] ) ) {
					$item['actions'] = $item_actions;
				}
				$html .= CLMS_UI_List_Row::render( $item );
			}
			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}
}
