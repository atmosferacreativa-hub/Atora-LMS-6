<?php
/**
 * Academic_Domain_Provider — PT-1.2 (sprint 6.7.0).
 *
 * Envuelve Student_Followup_Service + Section_Service sin modificarlos
 * (adaptador, no reescritura) para exponer el contrato genérico
 * Followup_Domain_Provider. La lógica de acá es EXACTAMENTE la que
 * vivía antes directamente en Followup_Plan_Resolver::resolve_for_definition()
 * en 6.6.0 — se movió, no se reescribió, para que el comportamiento de
 * los planes académicos existentes no cambie ni un bit (§0.5 de la OT).
 *
 * @package ATORA_LMS\CRM_V2
 * @since   6.7.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Academic_Domain_Provider implements Followup_Domain_Provider {

	/**
	 * @return array<string,array{label:string,color:string}>
	 */
	public static function get_stages(): array {
		return Student_Followup_Service::get_stages();
	}

	/**
	 * $entity_scope_ids acá son IDs de sección (atora_sections.id).
	 *
	 * @param array<int,int>    $entity_scope_ids IDs de sección.
	 * @param array<int,string> $stage_keys       Claves de etapa de Student_Followup_Service::get_stages().
	 * @param array<string,mixed> $context        No usado por este dominio (sin umbrales propios).
	 * @return array{entities:array,scope_meta:array,empty_reason:string,filtered_out_count:int}
	 */
	public static function resolve_entities_in_stages( array $entity_scope_ids, array $stage_keys, array $context = array() ): array {
		unset( $context ); // el dominio académico no tiene parámetros propios todavía.

		if ( ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			return array( 'entities' => array(), 'scope_meta' => array(), 'empty_reason' => 'lms_no_disponible', 'filtered_out_count' => 0 );
		}

		if ( empty( $entity_scope_ids ) || empty( $stage_keys ) ) {
			return array( 'entities' => array(), 'scope_meta' => array(), 'empty_reason' => 'sin_configuracion', 'filtered_out_count' => 0 );
		}

		// 1. Roster en vivo de las secciones del plan (solo estudiantes
		// activos en la sección — un estudiante retirado no debe seguir
		// apareciendo en el checklist de un docente).
		$scope_meta           = array();
		$student_to_sections  = array();
		foreach ( $entity_scope_ids as $section_id ) {
			$section = \ATORA\LMS\Section_Service::get( $section_id );
			if ( ! $section ) {
				continue;
			}
			$scope_meta[ $section_id ] = array(
				'id'    => $section_id,
				'title' => sanitize_text_field( (string) ( $section['title'] ?? '' ) ),
			);

			$roster = \ATORA\LMS\Section_Service::get_section_roster( $section_id );
			foreach ( $roster as $entry ) {
				if ( 'active' !== ( $entry['status'] ?? '' ) ) {
					continue;
				}
				$user_id = (int) $entry['user_id'];
				if ( ! $user_id ) {
					continue;
				}
				$student_to_sections[ $user_id ][] = $section_id;
			}
		}

		if ( empty( $student_to_sections ) ) {
			return array( 'entities' => array(), 'scope_meta' => $scope_meta, 'empty_reason' => 'sin_estudiantes_en_secciones', 'filtered_out_count' => 0 );
		}

		// 2. Etapa ACTUAL en vivo — sync_from_lms() adentro de get_board()
		// garantiza que no se lee una copia potencialmente vieja.
		$board         = Student_Followup_Service::get_board();
		$stage_by_user = array();
		foreach ( $stage_keys as $stage_key ) {
			foreach ( (array) ( $board['items'][ $stage_key ] ?? array() ) as $item ) {
				$user_id = (int) ( $item['user_id'] ?? 0 );
				if ( $user_id ) {
					$stage_by_user[ $user_id ] = $item;
				}
			}
		}

		if ( empty( $stage_by_user ) ) {
			return array( 'entities' => array(), 'scope_meta' => $scope_meta, 'empty_reason' => 'nadie_en_esas_etapas_hoy', 'filtered_out_count' => 0 );
		}

		// 3. Intersección: estudiante de una sección del plan, en una
		// etapa del filtro.
		$entities = array();
		foreach ( $student_to_sections as $user_id => $sections_for_user ) {
			if ( ! isset( $stage_by_user[ $user_id ] ) ) {
				continue;
			}

			$item       = $stage_by_user[ $user_id ];
			$user       = get_userdata( $user_id );
			$entities[] = array(
				'entity_id'    => $user_id,
				'display_name' => $user ? sanitize_text_field( (string) $user->display_name ) : '',
				'stage'        => (string) ( $item['stage'] ?? '' ),
				'stage_label'  => (string) ( $item['stage_label'] ?? '' ),
				'scope_ids'    => array_values( array_unique( $sections_for_user ) ),
				'meta'         => array( 'risk_level' => (string) ( $item['risk_level'] ?? 'normal' ) ),
			);
		}

		if ( empty( $entities ) ) {
			return array( 'entities' => array(), 'scope_meta' => $scope_meta, 'empty_reason' => 'nadie_cumple_el_filtro_hoy', 'filtered_out_count' => 0 );
		}

		return array( 'entities' => $entities, 'scope_meta' => $scope_meta, 'empty_reason' => '', 'filtered_out_count' => 0 );
	}
}
