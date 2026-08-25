<?php
/**
 * Followup_List_Row — fila de lista reutilizable (PT-1.3, sprint 6.9.0).
 *
 * Usada dentro del panel compartido (CLMS_UI_Followup_Panel), en
 * resultados de búsqueda (PT-2) y en el feed de Actividad (PT-3) — la
 * misma fila en los tres lugares, con la misma semántica de urgencia
 * (o de tono positivo, PT-3.2) resuelta a los tokens de
 * assets/shared/tokens.css, nunca un color suelto.
 *
 * @package ATORA_LMS
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_List_Row {

	/**
	 * @param array $item {
	 *     @type int    id       ID de la entidad (estudiante, contacto, etc.).
	 *     @type string name     Nombre a mostrar.
	 *     @type string meta     Línea secundaria (etapa, email, sección…).
	 *     @type string urgency  'alta'|'media'|'baja' — opcional, mutuamente excluyente con `tone`.
	 *     @type string tone     'positivo' — opcional (PT-3.2), mutuamente excluyente con `urgency`.
	 *     @type array  badges   Etiquetas cortas adicionales (texto plano, ya sanitizado por el llamador).
	 *     @type string url      Si se pasa, la fila entera es un enlace (usado por búsqueda/Actividad).
	 *     @type array  actions  Acciones de alcance "item" aplicables a esta fila específica —
	 *                           [{label, action_id, done_label?}]. `done_label` reemplaza `label`
	 *                           cuando `item.done` es true (p. ej. "✓ Contactado"). Renderizadas
	 *                           como botones `.atora-ui-row-action` con `data-action-id`/`data-id`,
	 *                           para que el JS del panel los delegue vía un solo listener.
	 *     @type bool   done     Si true, aplica el estado "hecho" (fila atenuada, botón con done_label).
	 * }
	 * @return string HTML ya escapado.
	 */
	public static function render( array $item ): string {
		$id      = absint( $item['id'] ?? 0 );
		$name    = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
		$meta    = sanitize_text_field( (string) ( $item['meta'] ?? '' ) );
		$urgency = sanitize_key( (string) ( $item['urgency'] ?? '' ) );
		$tone    = sanitize_key( (string) ( $item['tone'] ?? '' ) );
		$badges  = array_map( 'sanitize_text_field', (array) ( $item['badges'] ?? array() ) );
		$url     = (string) ( $item['url'] ?? '' );
		$actions = (array) ( $item['actions'] ?? array() );
		$done    = ! empty( $item['done'] );

		$state_class = $done ? ' atora-ui-row--done' : '';
		if ( in_array( $urgency, array( 'alta', 'media', 'baja' ), true ) ) {
			$state_class .= ' atora-ui-row--urgency-' . $urgency;
		} elseif ( 'positivo' === $tone ) {
			$state_class .= ' atora-ui-row--tone-positivo';
		}

		$badges_html = '';
		foreach ( $badges as $badge ) {
			if ( '' === $badge ) {
				continue;
			}
			$badges_html .= '<span class="atora-ui-row-badge">' . esc_html( $badge ) . '</span>';
		}

		$actions_html = '';
		foreach ( $actions as $action ) {
			$action_id = sanitize_key( (string) ( $action['action_id'] ?? '' ) );
			if ( '' === $action_id ) {
				continue;
			}
			$label = $done && ! empty( $action['done_label'] )
				? sanitize_text_field( (string) $action['done_label'] )
				: sanitize_text_field( (string) ( $action['label'] ?? '' ) );
			$actions_html .= '<button type="button" class="atora-ui-row-action" data-action-id="' . esc_attr( $action_id ) . '" data-id="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</button>';
		}

		$inner = '<span class="atora-ui-row-avatar" aria-hidden="true">' . esc_html( self::initial( $name ) ) . '</span>'
			. '<span class="atora-ui-row-info">'
				. '<span class="atora-ui-row-name">' . esc_html( $name ) . '</span>'
				. ( '' !== $meta ? '<span class="atora-ui-row-meta">' . esc_html( $meta ) . '</span>' : '' )
				. ( '' !== $badges_html ? '<span class="atora-ui-row-badges">' . $badges_html . '</span>' : '' )
			. '</span>';

		if ( '' !== $actions_html ) {
			return '<div class="atora-ui-row' . esc_attr( $state_class ) . '" data-id="' . esc_attr( (string) $id ) . '">'
				. $inner
				. '<span class="atora-ui-row-actions">' . $actions_html . '</span>'
				. '</div>';
		}

		if ( '' !== $url ) {
			return '<a class="atora-ui-row' . esc_attr( $state_class ) . '" href="' . esc_url( $url ) . '" data-id="' . esc_attr( (string) $id ) . '">'
				. $inner
				. '<span class="atora-ui-row-arrow" aria-hidden="true">→</span>'
				. '</a>';
		}

		return '<div class="atora-ui-row' . esc_attr( $state_class ) . '" data-id="' . esc_attr( (string) $id ) . '">' . $inner . '</div>';
	}

	/**
	 * @param string $name
	 * @return string Primera letra, mayúscula, para el avatar.
	 */
	private static function initial( string $name ): string {
		$name = trim( $name );
		return '' !== $name ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : '•';
	}
}
