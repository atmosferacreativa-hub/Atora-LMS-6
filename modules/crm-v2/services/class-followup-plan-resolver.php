<?php
/**
 * Followup_Plan_Resolver — PT-2 (sprint 6.6.0).
 *
 * Resuelve, EN VIVO, a qué estudiantes le toca una ocurrencia de un
 * plan de seguimiento. Nunca lee ni escribe una lista congelada —
 * cada llamada vuelve a consultar Student_Followup_Service (que a su
 * vez hace su propio sync_from_lms()) y Section_Service, así que el
 * mismo plan puede devolver estudiantes distintos hoy que ayer.
 *
 * Principio central de la OT: un plan programa CUÁNDO revisar y
 * filtra DINÁMICAMENTE por etapa en el momento de cada ocurrencia —
 * nunca congela una lista al crearse. Este archivo es la única pieza
 * del sprint responsable de esa resolución; PT-4 (UI) y PT-5
 * (notificaciones) lo consumen, nunca reimplementan el filtro.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Followup_Plan_Resolver {

	/**
	 * Resuelve los destinatarios reales de una ocurrencia de un plan,
	 * a la fecha indicada — consulta el estado ACTUAL de
	 * Student_Followup_Service, nunca una copia guardada.
	 *
	 * @param int    $plan_id         ID del plan.
	 * @param string $occurrence_date Fecha Y-m-d de la ocurrencia (referencia únicamente —
	 *                                la resolución siempre usa el estado de HOY; el parámetro
	 *                                existe para que el llamador pueda registrar/mostrar a qué
	 *                                fecha corresponde la consulta, y para uso futuro si el
	 *                                filtro llegara a depender de la fecha misma).
	 * @param int    $exclude_event_id ID de la ocurrencia (fila de atora_calendar_events) cuyas
	 *                                exclusiones puntuales (PT-4.7) deben respetarse, o 0 si
	 *                                todavía no existe una fila real (vista previa antes de guardar).
	 * @return array{sections:array<int,array>, students:array<int,array>, empty_reason:string}
	 */
	public static function resolve_recipients( int $plan_id, string $occurrence_date, int $exclude_event_id = 0 ): array {
		$plan = Followup_Plan_Service::get_plan( $plan_id );
		if ( ! $plan ) {
			return array( 'sections' => array(), 'students' => array(), 'empty_reason' => 'plan_not_found' );
		}

		return self::resolve_for_definition(
			(array) $plan['section_ids'],
			(array) $plan['stage_filter'],
			$exclude_event_id,
			$occurrence_date
		);
	}

	/**
	 * Misma resolución que resolve_recipients(), pero a partir de una
	 * definición todavía no guardada — usada por la vista previa del
	 * asistente de 4 pasos (PT-4.2.2), que evalúa secciones/etapas tal
	 * como el docente las está ajustando, antes de aplicar el plan.
	 *
	 * @param array<int,int>    $section_ids  IDs de sección.
	 * @param array<int,string> $stage_filter Claves de etapa de Student_Followup_Service::get_stages().
	 * @param int               $exclude_event_id Ver resolve_recipients().
	 * @param string            $occurrence_date  Ver resolve_recipients().
	 * @return array{sections:array<int,array>, students:array<int,array>, empty_reason:string}
	 */
	public static function resolve_for_definition( array $section_ids, array $stage_filter, int $exclude_event_id = 0, string $occurrence_date = '' ): array {
		unset( $occurrence_date ); // reservado para filtros futuros dependientes de fecha — ver docblock de arriba.

		$section_ids  = array_values( array_unique( array_filter( array_map( 'absint', $section_ids ) ) ) );
		$stage_filter = array_values( array_unique( array_filter( array_map( 'sanitize_key', $stage_filter ) ) ) );

		if ( empty( $section_ids ) || empty( $stage_filter ) ) {
			return array( 'sections' => array(), 'students' => array(), 'empty_reason' => 'sin_configuracion' );
		}

		if ( ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			return array( 'sections' => array(), 'students' => array(), 'empty_reason' => 'lms_no_disponible' );
		}

		// 1. Roster en vivo de las secciones del plan (solo estudiantes
		// activos en la sección — un estudiante retirado no debe seguir
		// apareciendo en el checklist de un docente).
		$section_meta      = array();
		$student_to_sections = array();
		foreach ( $section_ids as $section_id ) {
			$section = \ATORA\LMS\Section_Service::get( $section_id );
			if ( ! $section ) {
				continue;
			}
			$section_meta[ $section_id ] = array(
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
			return array( 'sections' => $section_meta, 'students' => array(), 'empty_reason' => 'sin_estudiantes_en_secciones' );
		}

		// 2. Etapa ACTUAL en vivo — sync_from_lms() adentro de get_board()
		// garantiza que no se lee una copia potencialmente vieja.
		$board          = Student_Followup_Service::get_board();
		$stage_by_user  = array();
		foreach ( $stage_filter as $stage_key ) {
			foreach ( (array) ( $board['items'][ $stage_key ] ?? array() ) as $item ) {
				$user_id = (int) ( $item['user_id'] ?? 0 );
				if ( $user_id ) {
					$stage_by_user[ $user_id ] = $item;
				}
			}
		}

		if ( empty( $stage_by_user ) ) {
			return array( 'sections' => $section_meta, 'students' => array(), 'empty_reason' => 'nadie_en_esas_etapas_hoy' );
		}

		// 3. Exclusiones puntuales de ESTA ocurrencia (PT-4.7) — nunca
		// afectan al plan ni a otras ocurrencias de la misma serie.
		$excluded = $exclude_event_id > 0 ? Followup_Plan_Service::get_excluded_students( $exclude_event_id ) : array();

		// 4. Intersección: estudiante de una sección del plan, en una
		// etapa del filtro, no excluido puntualmente de esta ocurrencia.
		$students = array();
		foreach ( $student_to_sections as $user_id => $sections_for_user ) {
			if ( ! isset( $stage_by_user[ $user_id ] ) ) {
				continue;
			}
			if ( in_array( $user_id, $excluded, true ) ) {
				continue;
			}

			$item               = $stage_by_user[ $user_id ];
			$user               = get_userdata( $user_id );
			$students[ $user_id ] = array(
				'user_id'      => $user_id,
				'display_name' => $user ? sanitize_text_field( (string) $user->display_name ) : '',
				'stage'        => (string) ( $item['stage'] ?? '' ),
				'stage_label'  => (string) ( $item['stage_label'] ?? '' ),
				'section_ids'  => array_values( array_unique( $sections_for_user ) ),
				'risk_level'   => (string) ( $item['risk_level'] ?? 'normal' ),
			);
		}

		if ( empty( $students ) ) {
			return array( 'sections' => $section_meta, 'students' => array(), 'empty_reason' => 'nadie_cumple_el_filtro_hoy' );
		}

		return array( 'sections' => $section_meta, 'students' => array_values( $students ), 'empty_reason' => '' );
	}

	/**
	 * PT-2.3: fusiona dos o más resoluciones (mismo día, mismo
	 * docente) en un único bloque — unión de estudiantes sin
	 * duplicados. La decisión de CUÁNDO fusionar (mismo día + misma
	 * sección) es de PT-4 (presentación); esta función solo garantiza
	 * que la fusión en sí, dado un conjunto de resoluciones, no pueda
	 * ser ambigua ni duplicar un estudiante presente en más de un plan.
	 *
	 * @param array<int,array{sections:array,students:array,empty_reason:string}> $resolutions
	 * @return array{sections:array<int,array>, students:array<int,array>}
	 */
	public static function merge_resolutions( array $resolutions ): array {
		$sections = array();
		$students = array();

		foreach ( $resolutions as $resolution ) {
			foreach ( (array) ( $resolution['sections'] ?? array() ) as $section_id => $section ) {
				$sections[ $section_id ] = $section;
			}
			foreach ( (array) ( $resolution['students'] ?? array() ) as $student ) {
				$user_id = (int) ( $student['user_id'] ?? 0 );
				if ( ! $user_id ) {
					continue;
				}
				if ( isset( $students[ $user_id ] ) ) {
					// Ya presente por otro plan del mismo bloque — combina las
					// secciones involucradas, sin duplicar la entrada del estudiante.
					$students[ $user_id ]['section_ids'] = array_values( array_unique( array_merge(
						$students[ $user_id ]['section_ids'],
						(array) ( $student['section_ids'] ?? array() )
					) ) );
					continue;
				}
				$students[ $user_id ] = $student;
			}
		}

		return array( 'sections' => $sections, 'students' => array_values( $students ) );
	}
}
