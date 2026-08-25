<?php
/**
 * Followup_Plan_Resolver — PT-2 (sprint 6.6.0), generalizado por
 * dominio en PT-1 (sprint 6.7.0).
 *
 * Resuelve, EN VIVO, a qué entidades le toca una ocurrencia de un
 * plan de seguimiento. Nunca lee ni escribe una lista congelada — cada
 * llamada vuelve a despachar al proveedor de dominio correspondiente
 * (Academic_Domain_Provider / Commercial_Domain_Provider), que a su
 * vez consulta el tablero en vivo de su dominio, así que el mismo plan
 * puede devolver entidades distintas hoy que ayer.
 *
 * Principio central de la OT (repetido acá porque gobierna todo este
 * archivo, ambos sprints): un plan programa CUÁNDO revisar y filtra
 * DINÁMICAMENTE por etapa en el momento de cada ocurrencia — nunca
 * congela una lista al crearse.
 *
 * PT-1 de 6.7.0 (regla central de ese sprint, criterio de aceptación
 * explícito): este archivo NO tiene ninguna referencia directa a
 * Student_Followup_Service ni a Deal_Service — toda esa lógica vive
 * en los proveedores de dominio; acá solo queda el despacho por
 * `domain`, la fusión de ocurrencias (PT-2.3) y las exclusiones
 * puntuales (PT-4.7), que son genéricas a cualquier dominio.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Followup_Plan_Resolver {

	/**
	 * Dominios soportados → clase del proveedor. Mapa estático simple
	 * (no un registro dinámico) — dos dominios es todo lo que este
	 * sprint construye; un tercero (ver "fuera de alcance" de la OT)
	 * solo agrega una entrada acá el día que exista.
	 *
	 * @var array<string,string>
	 */
	private const DOMAIN_PROVIDERS = array(
		'academic'   => '\ATORA\CRM_V2\Services\Academic_Domain_Provider',
		'commercial' => '\ATORA\CRM_V2\Services\Commercial_Domain_Provider',
	);

	/**
	 * Resuelve los destinatarios reales de una ocurrencia de un plan,
	 * a la fecha indicada — consulta el estado ACTUAL del dominio del
	 * plan, nunca una copia guardada.
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
	 * @return array{sections:array<int,array>, students:array<int,array>, empty_reason:string, filtered_out_count:int}
	 */
	public static function resolve_recipients( int $plan_id, string $occurrence_date, int $exclude_event_id = 0 ): array {
		$plan = Followup_Plan_Service::get_plan( $plan_id );
		if ( ! $plan ) {
			return array( 'sections' => array(), 'students' => array(), 'empty_reason' => 'plan_not_found', 'filtered_out_count' => 0 );
		}

		return self::resolve_for_definition(
			(array) $plan['section_ids'],
			(array) $plan['stage_filter'],
			$exclude_event_id,
			$occurrence_date,
			(string) ( $plan['domain'] ?? 'academic' ),
			(array) ( $plan['domain_config'] ?? array() ),
			(int) $plan['teacher_id']
		);
	}

	/**
	 * Misma resolución que resolve_recipients(), pero a partir de una
	 * definición todavía no guardada — usada por la vista previa del
	 * asistente de 4 pasos (PT-4.2.2), que evalúa secciones/etapas tal
	 * como el docente/vendedor las está ajustando, antes de aplicar el
	 * plan.
	 *
	 * @param array<int,int>      $entity_scope_ids IDs del agrupador del dominio (secciones en académico; ver
	 *                                               docblock de Commercial_Domain_Provider para el comercial).
	 * @param array<int,string>   $stage_filter     Claves de etapa del dominio. Vacío = selección manual pura (solo comercial).
	 * @param int                 $exclude_event_id Ver resolve_recipients().
	 * @param string              $occurrence_date  Ver resolve_recipients().
	 * @param string              $domain           'academic' (default, preserva el comportamiento de 6.6.0) o 'commercial'.
	 * @param array<string,mixed> $domain_config    Parámetros propios de plantilla (min_stalled_days, score_max, exclude_active_sequence…).
	 * @param int                 $owner_id         Dueño del plan (docente o vendedor) — usado por el dominio comercial para acotar a su cartera.
	 * @return array{sections:array<int,array>, students:array<int,array>, empty_reason:string, filtered_out_count:int}
	 */
	public static function resolve_for_definition(
		array $entity_scope_ids,
		array $stage_filter,
		int $exclude_event_id = 0,
		string $occurrence_date = '',
		string $domain = 'academic',
		array $domain_config = array(),
		int $owner_id = 0
	): array {
		unset( $occurrence_date ); // reservado para filtros futuros dependientes de fecha — ver docblock de arriba.

		$provider = self::get_domain_provider( $domain );
		if ( ! $provider ) {
			return array( 'sections' => array(), 'students' => array(), 'empty_reason' => 'dominio_no_disponible', 'filtered_out_count' => 0 );
		}

		$entity_scope_ids = array_values( array_unique( array_filter( array_map( 'absint', $entity_scope_ids ) ) ) );
		$stage_filter     = array_values( array_unique( array_filter( array_map( 'sanitize_key', $stage_filter ) ) ) );

		$context             = $domain_config;
		$context['owner_id'] = absint( $owner_id );

		$resolved   = $provider::resolve_entities_in_stages( $entity_scope_ids, $stage_filter, $context );
		$entities   = (array) ( $resolved['entities'] ?? array() );
		$scope_meta = (array) ( $resolved['scope_meta'] ?? array() );
		$filtered_out_count = absint( $resolved['filtered_out_count'] ?? 0 );

		if ( empty( $entities ) ) {
			return array(
				'sections'           => $scope_meta,
				'students'           => array(),
				'empty_reason'       => (string) ( $resolved['empty_reason'] ?? 'nadie_cumple_el_filtro_hoy' ),
				'filtered_out_count' => $filtered_out_count,
			);
		}

		// Exclusiones puntuales de ESTA ocurrencia (PT-4.7) — nunca
		// afectan al plan ni a otras ocurrencias de la misma serie.
		// Genérico a cualquier dominio: opera sobre entity_id, no sabe
		// ni le importa si es un user_id académico o un contact_id
		// comercial.
		$excluded = $exclude_event_id > 0 ? Followup_Plan_Service::get_excluded_students( $exclude_event_id ) : array();

		$students = array();
		foreach ( $entities as $entity ) {
			$entity_id = (int) ( $entity['entity_id'] ?? 0 );
			if ( ! $entity_id || in_array( $entity_id, $excluded, true ) ) {
				continue;
			}

			$meta                  = (array) ( $entity['meta'] ?? array() );
			$students[ $entity_id ] = array(
				'user_id'      => $entity_id,
				'display_name' => (string) ( $entity['display_name'] ?? '' ),
				'stage'        => (string) ( $entity['stage'] ?? '' ),
				'stage_label'  => (string) ( $entity['stage_label'] ?? '' ),
				'section_ids'  => array_values( (array) ( $entity['scope_ids'] ?? array() ) ),
				'risk_level'   => (string) ( $meta['risk_level'] ?? 'normal' ),
				'meta'         => $meta,
			);
		}

		if ( empty( $students ) ) {
			return array( 'sections' => $scope_meta, 'students' => array(), 'empty_reason' => 'nadie_cumple_el_filtro_hoy', 'filtered_out_count' => $filtered_out_count );
		}

		return array( 'sections' => $scope_meta, 'students' => array_values( $students ), 'empty_reason' => '', 'filtered_out_count' => $filtered_out_count );
	}

	/**
	 * @param string $domain
	 * @return string|null Nombre completo de la clase proveedora, o null si el dominio no existe/no está disponible.
	 */
	private static function get_domain_provider( string $domain ): ?string {
		$domain = sanitize_key( $domain );
		$class  = self::DOMAIN_PROVIDERS[ $domain ] ?? null;
		return ( $class && class_exists( $class ) ) ? $class : null;
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
