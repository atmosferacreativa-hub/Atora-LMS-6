<?php
/**
 * Commercial_Domain_Provider — PT-1.2 (sprint 6.7.0).
 *
 * Envuelve Deal_Service (pipeline comercial) sin modificarlo, para que
 * el motor de planes de seguimiento construido en 6.6.0 pueda apuntar
 * al CRM comercial además del académico.
 *
 * "Entidad" acá es un contact_id (no un deal_id): un contacto puede
 * tener como máximo un deal abierto a la vez (ensure_deal_for_contact()
 * ya lo garantiza), y el resto del motor — contactos registrados,
 * exclusiones puntuales, coordinación con secuencias (PT-4) — razona
 * sobre "la persona", no sobre el artefacto de pipeline.
 *
 * Alcance ($entity_scope_ids): a diferencia del dominio académico, el
 * pipeline comercial no tiene un agrupador explícito tipo "sección" —
 * decisión de PT-1.5, documentada en docs/DEUDA-TECNICA.md. Dos modos:
 *   - Filtro por etapa (uso normal): $entity_scope_ids vacío, el
 *     alcance implícito es "la cartera del vendedor dueño del plan"
 *     (assigned_to = $context['owner_id']).
 *   - Selección manual (plantilla "Cuenta clave", PT-2.1): $stage_keys
 *     vacío, $entity_scope_ids son contact_ids elegidos a mano por el
 *     vendedor — sin ningún filtro de etapa.
 *
 * @package ATORA_LMS\CRM_V2
 * @since   6.7.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Commercial_Domain_Provider implements Followup_Domain_Provider {

	/**
	 * @return array<string,array{label:string,color:string}>
	 */
	public static function get_stages(): array {
		return class_exists( '\ATORA\CRM_V2\Services\Deal_Service' ) ? Deal_Service::get_stages() : array();
	}

	/**
	 * @param array<int,int>    $entity_scope_ids contact_ids para selección manual ("Cuenta clave"); vacío en modo por-etapa.
	 * @param array<int,string> $stage_keys       Claves de Deal_Service::get_stages(). Vacío = modo selección manual pura.
	 * @param array<string,mixed> $context {
	 *     @type int   owner_id             ID del vendedor dueño del plan (assigned_to del deal). 0 = sin restringir por vendedor.
	 *     @type int   min_stalled_days     Plantilla "Deals estancados" (PT-2.2): excluye deals con movimiento dentro de N días.
	 *     @type int   score_max            Plantilla "Nutrir leads fríos": excluye contactos con score de conversión mayor a N.
	 *     @type bool  exclude_active_sequence Plantilla/plan (PT-4.4): oculta de la lista (no del conteo) a quien tiene una secuencia automática activa.
	 * }
	 * @return array{entities:array,scope_meta:array,empty_reason:string,filtered_out_count:int}
	 */
	public static function resolve_entities_in_stages( array $entity_scope_ids, array $stage_keys, array $context = array() ): array {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Deal_Service' ) ) {
			return array( 'entities' => array(), 'scope_meta' => array(), 'empty_reason' => 'crm_no_disponible', 'filtered_out_count' => 0 );
		}

		$entity_scope_ids = array_values( array_unique( array_filter( array_map( 'absint', $entity_scope_ids ) ) ) );
		$stage_keys       = array_values( array_unique( array_filter( array_map( 'sanitize_key', $stage_keys ) ) ) );
		$owner_id         = absint( $context['owner_id'] ?? 0 );

		if ( empty( $stage_keys ) ) {
			// Modo selección manual (PT-2.1, "Cuenta clave") — sin filtro
			// de etapa, $entity_scope_ids SON los contact_ids elegidos.
			if ( empty( $entity_scope_ids ) ) {
				return array( 'entities' => array(), 'scope_meta' => array(), 'empty_reason' => 'sin_configuracion', 'filtered_out_count' => 0 );
			}
			return self::resolve_manual_selection( $entity_scope_ids, $context );
		}

		$board  = Deal_Service::get_board();
		$stages = Deal_Service::get_stages();

		$candidates = array();
		foreach ( $stage_keys as $stage_key ) {
			foreach ( (array) ( $board['items'][ $stage_key ] ?? array() ) as $deal ) {
				$contact_id = absint( $deal['contact_id'] ?? 0 );
				if ( ! $contact_id ) {
					continue;
				}
				if ( $owner_id && absint( $deal['assigned_to'] ?? 0 ) !== $owner_id ) {
					continue; // fuera de la cartera del vendedor dueño del plan.
				}
				$candidates[ $contact_id ] = $deal;
			}
		}

		if ( empty( $candidates ) ) {
			return array( 'entities' => array(), 'scope_meta' => array(), 'empty_reason' => 'nadie_en_esas_etapas_hoy', 'filtered_out_count' => 0 );
		}

		return self::build_entities( $candidates, $stages, $context );
	}

	/**
	 * PT-2.1 "Cuenta clave": resuelve exactamente los contact_ids
	 * elegidos por el vendedor, sin filtro de etapa — el deal más
	 * reciente de cada uno (si tiene alguno) define su etapa mostrada.
	 * Un contacto elegido sin ningún deal todavía igual aparece (con
	 * etapa vacía) — "Cuenta clave" es sobre PERSONAS elegidas a mano,
	 * no sobre el estado de su pipeline.
	 *
	 * @param array<int,int>       $contact_ids
	 * @param array<string,mixed>  $context
	 * @return array{entities:array,scope_meta:array,empty_reason:string,filtered_out_count:int}
	 */
	private static function resolve_manual_selection( array $contact_ids, array $context ): array {
		$stages = Deal_Service::get_stages();
		$deals  = Deal_Service::get_latest_deal_per_contact( $contact_ids );

		$candidates = array();
		foreach ( $contact_ids as $contact_id ) {
			$candidates[ $contact_id ] = $deals[ $contact_id ] ?? array( 'contact_id' => $contact_id, 'title' => '', 'stage' => '', 'id' => 0, 'updated_at' => '' );
		}

		return self::build_entities( $candidates, $stages, $context );
	}

	/**
	 * Normaliza deals candidatos a la forma genérica de la interfaz,
	 * aplicando los filtros propios de plantilla que traiga $context
	 * (PT-2.2 min_stalled_days, "Nutrir leads fríos" score_max) y la
	 * coordinación con secuencias (PT-4.4 exclude_active_sequence) —
	 * este último NUNCA elimina al contacto de $filtered_out_count,
	 * solo de la lista mostrada (PT-4.2: "la coincidencia no oculta,
	 * marca" — ocultar del todo sería perder visibilidad sin que el
	 * vendedor lo haya decidido).
	 *
	 * @param array<int,array> $candidates contact_id => fila de deal normalizada.
	 * @param array             $stages
	 * @param array             $context
	 * @return array{entities:array,scope_meta:array,empty_reason:string,filtered_out_count:int}
	 */
	private static function build_entities( array $candidates, array $stages, array $context ): array {
		$min_stalled_days       = absint( $context['min_stalled_days'] ?? 0 );
		$score_max              = array_key_exists( 'score_max', $context ) ? (int) $context['score_max'] : null;
		$exclude_active_sequence = ! empty( $context['exclude_active_sequence'] );

		$entities           = array();
		$filtered_out_count = 0;

		foreach ( $candidates as $contact_id => $deal ) {
			$contact_id = absint( $contact_id );
			$stage      = sanitize_key( (string) ( $deal['stage'] ?? '' ) );
			$updated_at = (string) ( $deal['updated_at'] ?? '' );

			$days_stalled = null;
			if ( '' !== $updated_at ) {
				$days_stalled = (int) floor( ( current_time( 'timestamp', true ) - strtotime( $updated_at ) ) / DAY_IN_SECONDS );
			}

			// PT-2.2: "Deals estancados" — excluye lo que sí tuvo movimiento reciente.
			if ( $min_stalled_days > 0 && ( null === $days_stalled || $days_stalled < $min_stalled_days ) ) {
				continue;
			}

			$score       = 0;
			$score_label = '';
			if ( class_exists( '\ATORA\CRM_V2\Services\Scoring_Service' ) ) {
				$score       = Scoring_Service::calculate_score( $contact_id );
				$score_label = Scoring_Service::get_score_label( $score );
			}

			// "Nutrir leads fríos": excluye contactos con score por
			// encima del umbral (no son leads fríos).
			if ( null !== $score_max && $score > $score_max ) {
				continue;
			}

			$sequence = self::resolve_sequence_info( $contact_id );

			if ( $exclude_active_sequence && $sequence && $sequence['active'] ) {
				++$filtered_out_count; // PT-4.4: cuenta, pero no se muestra.
				continue;
			}

			$entities[] = array(
				'entity_id'    => $contact_id,
				'display_name' => sanitize_text_field( (string) ( $deal['title'] ?? '' ) ),
				'stage'        => $stage,
				'stage_label'  => (string) ( $stages[ $stage ]['label'] ?? $stage ),
				'scope_ids'    => array(),
				'meta'         => array(
					'deal_id'      => absint( $deal['id'] ?? 0 ),
					'score'        => $score,
					'score_label'  => $score_label,
					'days_stalled' => $days_stalled,
					'sequence'     => $sequence,
				),
			);
		}

		if ( empty( $entities ) ) {
			$reason = $filtered_out_count > 0 ? 'todos_en_secuencia_activa' : 'nadie_cumple_el_filtro_hoy';
			return array( 'entities' => array(), 'scope_meta' => array(), 'empty_reason' => $reason, 'filtered_out_count' => $filtered_out_count );
		}

		return array( 'entities' => $entities, 'scope_meta' => array(), 'empty_reason' => '', 'filtered_out_count' => $filtered_out_count );
	}

	/**
	 * PT-4.1: inscripción activa más reciente de un contacto en una
	 * secuencia automática — reutiliza
	 * Sequence_Service::get_contact_enrollments(), que ya existe y ya
	 * filtra status='active'; no se duplica esa consulta acá.
	 *
	 * @param int $contact_id
	 * @return array{active:bool,sequence_name:string,current_step:int,total_steps:int}|null
	 */
	private static function resolve_sequence_info( int $contact_id ): ?array {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Sequence_Service' ) ) {
			return null;
		}

		$enrollments = Sequence_Service::get_contact_enrollments( $contact_id );
		if ( empty( $enrollments ) ) {
			return null;
		}

		$enrollment = $enrollments[0]; // get_contact_enrollments() ya ordena por enrolled_at DESC.
		$steps      = Sequence_Service::get_steps( absint( $enrollment['sequence_id'] ?? 0 ) );

		return array(
			'active'        => true,
			'sequence_name' => sanitize_text_field( (string) ( $enrollment['sequence_name'] ?? '' ) ),
			'current_step'  => absint( $enrollment['current_step'] ?? 1 ),
			'total_steps'   => count( $steps ),
		);
	}
}
