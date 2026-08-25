<?php
/**
 * Followup_Domain_Provider — contrato de dominio para el motor de
 * planes de seguimiento (PT-1, sprint 6.7.0).
 *
 * El motor construido en 6.6.0 (Followup_Plan_Service,
 * Followup_Plan_Resolver, Followup_Recurrence) no tiene nada
 * intrínsecamente académico en su núcleo: programa fechas y filtra por
 * etapa. Lo único académico era DE DÓNDE salían las etapas y quién
 * está en cuál — eso es exactamente lo que esta interfaz aísla.
 *
 * Convención de la interfaz (métodos estáticos + `implements`) tomada
 * de modules/email-engine/providers/class-provider-interface.php, el
 * único precedente real de este patrón en el plugin — no se inventa
 * una convención nueva.
 *
 * @package ATORA_LMS\CRM_V2
 * @since   6.7.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Followup_Domain_Provider {

	/**
	 * Etapas del dominio, con color — misma forma que
	 * Student_Followup_Service::get_stages() / Deal_Service::get_stages().
	 *
	 * @return array<string,array{label:string,color:string}>
	 */
	public static function get_stages(): array;

	/**
	 * Resuelve, EN VIVO (nunca desde una copia guardada), qué entidades
	 * del dominio están en alguna de las etapas pedidas, dentro del
	 * agrupador indicado.
	 *
	 * "Entidad" es el nombre neutro que reemplaza a "estudiante": un
	 * estudiante en el dominio académico, un contacto/deal en el
	 * comercial. $entity_scope_ids es el agrupador propio de cada
	 * dominio — secciones en el académico; en el comercial, vacío
	 * (alcance implícito = cartera del vendedor, vía $context) o una
	 * lista explícita de contact_ids para selección manual (plantilla
	 * "Cuenta clave", sin filtro de etapa).
	 *
	 * @param array<int,int>    $entity_scope_ids IDs del agrupador del dominio (ver docblock de cada implementación).
	 * @param array<int,string> $stage_keys       Etapas a incluir. Vacío = sin filtro de etapa (solo selección manual).
	 * @param array<string,mixed> $context        Datos adicionales específicos del dominio (p. ej. 'owner_id', umbrales de plantilla).
	 * @return array{
	 *     entities: array<int,array{entity_id:int,display_name:string,stage:string,stage_label:string,scope_ids:array<int,int>,meta:array<string,mixed>}>,
	 *     scope_meta: array<int,array{id:int,title:string}>,
	 *     empty_reason: string,
	 *     filtered_out_count: int
	 * }
	 */
	public static function resolve_entities_in_stages( array $entity_scope_ids, array $stage_keys, array $context = array() ): array;
}
