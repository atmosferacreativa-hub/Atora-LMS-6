<?php
/**
 * "Actividad" — lo que ya se resolvió (PT-3, sprint 6.9.0).
 *
 * Simétrico a CLMS_Today_Aggregator_Service (6.8.0): mismo principio
 * de agregador de solo lectura sobre servicios ya existentes, sin
 * duplicar su lógica. "Hoy" responde qué falta; "Actividad" responde
 * qué ya se hizo — nunca el mismo ítem en ambas listas (PT-3.4): un
 * contacto recién marcado sale de "Hoy" (porque get_due_occurrences_for_user()
 * ya no lo cuenta como pendiente en la siguiente carga) y entra acá
 * (porque get_recent_contacts_for_user() ahora lo ve) — son la misma
 * fuente de datos consultada con un filtro complementario, nunca los
 * dos filtros a la vez.
 *
 * @package ATORA_LMS
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Activity_Feed_Service {

	/**
	 * PT-3.1: lista homogénea de items positivos de los últimos $days
	 * días, orden cronológico inverso (PT-3.3).
	 *
	 * @param int $user_id
	 * @param int $days
	 * @return array<int,array{source:string,title:string,count:int,tone:string,url:string}>
	 */
	public function get_recent( $user_id, $days = 7 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		$items = array_merge(
			$this->collect_contacted_items( $user_id, $days ),
			$this->collect_stage_improvement_items( $user_id, $days )
		);

		usort( $items, static function ( $a, $b ) {
			return strcmp( (string) ( $b['_when'] ?? '' ), (string) ( $a['_when'] ?? '' ) );
		} );

		return array_map( static function ( $item ) {
			unset( $item['_when'] );
			return $item;
		}, $items );
	}

	/**
	 * PT-3.1: contactos marcados por el usuario (`mark_contacted()`,
	 * 6.6.0/6.7.0) — reutiliza Followup_Plan_Service::get_recent_contacts_for_user(),
	 * agregada en este mismo sprint como lectura puntual.
	 *
	 * @param int $user_id
	 * @param int $days
	 * @return array<int,array<string,mixed>>
	 */
	protected function collect_contacted_items( $user_id, $days ) {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Followup_Plan_Service' ) ) {
			return array();
		}

		$rows  = \ATORA\CRM_V2\Services\Followup_Plan_Service::get_recent_contacts_for_user( absint( $user_id ), absint( $days ) );
		$items = array();

		foreach ( $rows as $row ) {
			$domain = (string) ( $row['domain'] ?? 'academic' );
			$event_id = absint( $row['event_id'] ?? 0 );

			$items[] = array(
				'source'  => 'commercial' === $domain ? 'contacted_commercial' : 'contacted_academic',
				'title'   => sprintf(
					/* translators: 1: entity name, 2: occurrence title */
					__( 'Contactaste a %1$s — %2$s', 'atora-lms' ),
					(string) ( $row['entity_name'] ?? '' ),
					(string) ( $row['title'] ?? '' )
				),
				'count'   => 1,
				'tone'    => 'positivo',
				'url'     => $event_id ? admin_url( 'admin.php?page=atora-followup-plans&event_id=' . $event_id ) : admin_url( 'admin.php?page=atora-hoy' ),
				'_when'   => (string) ( $row['contacted_at'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * PT-3.1: estudiantes/contactos que pasaron de una etapa de riesgo
	 * a una mejor — reutiliza Activity_Service::get_recent_stage_improvements_for_user(),
	 * agregada en este mismo sprint como lectura puntual sobre la
	 * tabla que move_followup()/move_deal() ya escriben.
	 *
	 * @param int $user_id
	 * @param int $days
	 * @return array<int,array<string,mixed>>
	 */
	protected function collect_stage_improvement_items( $user_id, $days ) {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Activity_Service' ) ) {
			return array();
		}

		$rows  = \ATORA\CRM_V2\Services\Activity_Service::get_recent_stage_improvements_for_user( absint( $user_id ), absint( $days ) );
		$items = array();

		foreach ( $rows as $row ) {
			$domain     = (string) ( $row['domain'] ?? 'academic' );
			$contact_id = absint( $row['contact_id'] ?? 0 );

			$items[] = array(
				'source' => 'commercial' === $domain ? 'stage_improved_commercial' : 'stage_improved_academic',
				'title'  => sprintf(
					/* translators: %s: entity name */
					__( '%s mejoró de etapa', 'atora-lms' ),
					(string) ( $row['contact_name'] ?? '' )
				),
				'count'  => 1,
				'tone'   => 'positivo',
				// PT-2.4 (mismo criterio que la búsqueda): solo comercial
				// tiene una ficha real (contact-360) a la que enlazar.
				'url'    => ( 'commercial' === $domain && $contact_id )
					? admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . $contact_id )
					: admin_url( 'admin.php?page=atora-students-hub' ),
				'_when'  => (string) ( $row['created_at'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * PT-3.3: vista simple, orden cronológico inverso (get_recent() ya
	 * ordena), sin agrupar por urgencia como "Hoy" -- acá no hay
	 * niveles, todo es tono positivo. Mismo criterio de estado vacío
	 * cálido que el resto de la serie.
	 *
	 * @param array<int,array<string,mixed>> $items Resultado de get_recent().
	 * @return string HTML ya escapado.
	 */
	public function render_items_html( array $items ) {
		if ( empty( $items ) ) {
			return '<div class="atora-hoy-empty">'
				. '<p class="atora-hoy-empty-title">' . esc_html__( 'Todavía nada que celebrar esta semana.', 'atora-lms' ) . '</p>'
				. '<p class="atora-hoy-empty-sub">' . esc_html__( 'Apenas marques un contacto o alguien mejore de etapa, aparece acá.', 'atora-lms' ) . '</p>'
				. '</div>';
		}

		$html = '<ul class="atora-hoy-items atora-hoy-items--activity">';
		foreach ( $items as $item ) {
			$html .= '<li class="atora-hoy-item atora-hoy-item--positivo">';
			$html .= '<a class="atora-hoy-item-link" href="' . esc_url( (string) ( $item['url'] ?? '#' ) ) . '">';
			$html .= '<span class="atora-hoy-item-title">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</span>';
			$html .= '<span class="atora-hoy-item-arrow" aria-hidden="true">→</span>';
			$html .= '</a></li>';
		}
		$html .= '</ul>';

		return $html;
	}
}
