<?php
/**
 * "Hoy" — agregador de urgencia real (PT-1, sprint 6.8.0).
 *
 * No reescribe lógica de negocio de ningún servicio consultado — solo
 * lee y da forma homogénea al resultado (§0.2 de la OT). Cada fuente
 * se consulta EN VIVO en cada llamada, sin caché, mismo principio que
 * rige el motor de planes de seguimiento desde 6.6.0.
 *
 * Convención de archivo (`includes/today/`, clase global `CLMS_*`)
 * elegida siguiendo el precedente real ya existente para servicios
 * transversales sin un solo módulo dueño: `includes/dashboard/`
 * (CLMS_Teacher_Dashboard_Data, CLMS_Teacher_Priority_Queue_Service),
 * registrado en el mismo loader declarativo (module-groups) que esos.
 *
 * @package ATORA_LMS
 * @since   6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Today_Aggregator_Service {

	/**
	 * Etapas académicas consideradas de mayor riesgo (PT-2.1, nivel 2)
	 * — mismas claves que ya usa la plantilla "Alta atención" de
	 * Followup_Plan_Service::get_academic_templates() (6.6.0): no se
	 * inventa un criterio nuevo de "qué es riesgo alto", se reutiliza
	 * el que el propio motor de planes ya trata como tal.
	 */
	const ACADEMIC_HIGH_RISK_STAGES = array( 'at_risk', 'intervention', 'needs_support' );

	/**
	 * Umbral de score comercial "bajo" (PT-2.1, nivel 2) — mismo
	 * `score_max` que ya usa la plantilla comercial "Nutrir leads
	 * fríos" (6.7.0), derivado de los propios cortes de
	 * Scoring_Service::get_score_label(): no una segunda categorización.
	 */
	const COMMERCIAL_HIGH_RISK_SCORE_MAX = 39;

	public function __construct() {
		add_shortcode( 'atora_hoy', array( $this, 'render_shortcode' ) );
	}

	/**
	 * PT-1.2: lista homogénea de items, sin importar la fuente,
	 * ordenada por urgencia real (PT-2, ver sort_by_urgency()).
	 *
	 * @param int $user_id
	 * @return array<int,array{source:string,title:string,count:int,urgency:string,url:string}>
	 */
	public function get_today( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		$has_academic   = $this->user_has_academic_access( $user_id );
		$has_commercial = $this->user_has_commercial_access( $user_id );

		$items = array();

		// Las ocurrencias de planes de seguimiento ya están naturalmente
		// scopeadas por user_id en la tabla (PT-1.3): un docente sin
		// planes comerciales simplemente no tiene filas con domain
		// comercial que consultar — no hace falta un chequeo de rol acá
		// además de la consulta misma.
		$items = array_merge( $items, $this->collect_followup_items( $user_id ) );

		if ( $has_academic ) {
			$items = array_merge( $items, $this->collect_academic_digest_items( $user_id ) );
		}

		$items = array_merge( $items, $this->collect_task_items( $user_id, $has_academic, $has_commercial ) );

		return $this->sort_by_urgency( $items );
	}

	/**
	 * PT-2: ordena por la regla de urgencia real de la OT.
	 *
	 * Nivel 1 (2.1): rango interno `_tier` (1-5, asignado por cada
	 * collect_*()) — 1/2 mapean a urgency='alta', 3/4 a 'media', 5 a
	 * 'baja' (PT-2.1). Ordenar por `_tier` garantiza estructuralmente
	 * que ningún ítem de baja urgencia puede aparecer antes que uno de
	 * alta: los tiers de 'alta' (1,2) son siempre numéricamente menores
	 * que los de 'media' (3,4), que a su vez son menores que el de
	 * 'baja' (5) — ver criterio de aceptación de PT-2.
	 *
	 * Nivel 2 (2.3): dentro del mismo tier, gana quien tiene una hora
	 * límite específica (`_has_specific_time`) sobre quien solo tiene
	 * "hoy" como granularidad.
	 *
	 * Nivel 3 (2.2): dentro de lo anterior, por volumen (`count`)
	 * descendente — más estudiantes/contactos afectados primero.
	 *
	 * Las claves internas `_tier`/`_has_specific_time` se descartan
	 * antes de devolver — no forman parte del shape público de PT-1.2.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	protected function sort_by_urgency( array $items ) {
		usort(
			$items,
			static function ( $a, $b ) {
				$tier_a = absint( $a['_tier'] ?? 4 );
				$tier_b = absint( $b['_tier'] ?? 4 );
				if ( $tier_a !== $tier_b ) {
					return $tier_a <=> $tier_b;
				}

				$specific_a = ! empty( $a['_has_specific_time'] );
				$specific_b = ! empty( $b['_has_specific_time'] );
				if ( $specific_a !== $specific_b ) {
					return $specific_a ? -1 : 1;
				}

				$count_a = absint( $a['count'] ?? 0 );
				$count_b = absint( $b['count'] ?? 0 );
				if ( $count_a !== $count_b ) {
					return $count_b <=> $count_a;
				}

				return 0;
			}
		);

		return array_map(
			static function ( $item ) {
				unset( $item['_tier'], $item['_has_specific_time'] );
				return $item;
			},
			$items
		);
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	protected function user_has_academic_access( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		// Misma lista de capacidades que can_access_academic_calendar()
		// (trait-admin-menu-navigation.php) — duplicada acá porque esta
		// clase global no tiene acceso a ese trait; mismo patrón ya
		// usado en modules/crm-v2/views/followup-plans.php para el
		// mismo problema, documentado ahí con el mismo motivo.
		return user_can( $user_id, 'manage_options' )
			|| user_can( $user_id, 'clms_access_admin' )
			|| user_can( $user_id, 'clms_manage_courses' )
			|| user_can( $user_id, 'clms_manage_lessons' )
			|| user_can( $user_id, 'clms_view_teacher_dashboard' )
			|| user_can( $user_id, 'clms_grade_submissions' )
			|| user_can( $user_id, 'edit_posts' );
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	protected function user_has_commercial_access( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		return user_can( $user_id, 'clms_access_crm_view' ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * PT-1.2/2.1: ocurrencias de planes de seguimiento (ambos dominios)
	 * con al menos un estudiante/contacto sin contactar todavía —
	 * fuente `followup_academic` / `followup_commercial`.
	 *
	 * @param int $user_id
	 * @return array<int,array<string,mixed>>
	 */
	protected function collect_followup_items( $user_id ) {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Followup_Plan_Service' ) ) {
			return array();
		}

		$occurrences = \ATORA\CRM_V2\Services\Followup_Plan_Service::get_due_occurrences_for_user( absint( $user_id ) );
		$items = array();

		foreach ( $occurrences as $occurrence ) {
			$uncontacted = absint( $occurrence['uncontacted'] ?? 0 );
			if ( $uncontacted <= 0 ) {
				continue; // nada pendiente en esta ocurrencia -- no es un ítem de "Hoy".
			}

			$domain      = (string) ( $occurrence['domain'] ?? 'academic' );
			$is_overdue  = ! empty( $occurrence['is_overdue'] );
			$high_risk   = $this->occurrence_has_high_risk_uncontacted( $occurrence );
			$tier        = $is_overdue ? 1 : ( $high_risk ? 2 : 4 );
			$entity_word = $this->entity_word( $domain, $uncontacted );
			$occ_title   = sanitize_text_field( (string) ( $occurrence['title'] ?? '' ) );

			if ( 1 === $tier ) {
				$title = sprintf(
					/* translators: 1: count, 2: "estudiante(s)"/"contacto(s)", 3: occurrence title */
					__( '%1$d %2$s sin contactar — %3$s (atrasado)', 'atora-lms' ),
					$uncontacted,
					$entity_word,
					$occ_title
				);
			} elseif ( 2 === $tier ) {
				$title = sprintf(
					__( '%1$d %2$s en riesgo — %3$s', 'atora-lms' ),
					$uncontacted,
					$entity_word,
					$occ_title
				);
			} else {
				$title = sprintf(
					__( '%1$d %2$s para revisar hoy — %3$s', 'atora-lms' ),
					$uncontacted,
					$entity_word,
					$occ_title
				);
			}

			$items[] = array(
				'source'                => 'commercial' === $domain ? 'followup_commercial' : 'followup_academic',
				'title'                 => $title,
				'count'                 => $uncontacted,
				'urgency'               => $tier <= 2 ? 'alta' : 'media',
				'url'                   => admin_url( 'admin.php?page=atora-followup-plans&event_id=' . absint( $occurrence['event_id'] ?? 0 ) ),
				'_tier'                 => $tier,
				'_has_specific_time'    => false, // las ocurrencias se materializan a una hora fija (08:00), no una hora real elegida.
			);
		}

		return $items;
	}

	/**
	 * @param array $occurrence Una fila de get_due_occurrences_for_user().
	 * @return bool
	 */
	protected function occurrence_has_high_risk_uncontacted( array $occurrence ) {
		$domain = (string) ( $occurrence['domain'] ?? 'academic' );

		foreach ( (array) ( $occurrence['students'] ?? array() ) as $entity ) {
			if ( ! empty( $entity['contacted'] ) ) {
				continue; // ya contactado -- no aporta urgencia nueva.
			}

			if ( 'commercial' === $domain ) {
				$score = isset( $entity['meta']['score'] ) ? (int) $entity['meta']['score'] : 100;
				if ( $score <= self::COMMERCIAL_HIGH_RISK_SCORE_MAX ) {
					return true;
				}
			} elseif ( in_array( (string) ( $entity['stage'] ?? '' ), self::ACADEMIC_HIGH_RISK_STAGES, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * PT-1.2/2.1: entregas sin calificar (>48h), estudiantes inactivos,
	 * quizzes pendientes — fuentes `grading` / `inactivity` / `quiz`,
	 * ambas leídas de CLMS_Teacher_Digest_Service sin duplicar su
	 * lógica de conteo (solo sus dos wrappers públicos nuevos, ver esa
	 * clase). Mismo destino que el digest diario ya usa para estos tres
	 * conceptos (`atora-students-hub`) — no se inventa un enlace "más
	 * específico" que no existe todavía por ítem individual.
	 *
	 * @param int $user_id
	 * @return array<int,array<string,mixed>>
	 */
	protected function collect_academic_digest_items( $user_id ) {
		if ( ! function_exists( 'atora_lms' ) ) {
			return array();
		}

		$service = atora_lms( 'CLMS_Teacher_Digest_Service' );
		if ( ! $service || ! method_exists( $service, 'get_pending_counts_for_teacher' ) ) {
			return array();
		}

		$user_id = absint( $user_id );
		$counts  = $service->get_pending_counts_for_teacher( $user_id );
		$url     = admin_url( 'admin.php?page=atora-students-hub' );
		$items   = array();

		$stale_grading = method_exists( $service, 'get_stale_submission_count_for_teacher' )
			? absint( $service->get_stale_submission_count_for_teacher( $user_id, 48 ) )
			: 0;

		if ( $stale_grading > 0 ) {
			$items[] = array(
				'source'             => 'grading',
				'title'              => sprintf(
					/* translators: %d: count */
					_n( '%d entrega esperando tu calificación', '%d entregas esperando tu calificación', $stale_grading, 'atora-lms' ),
					$stale_grading
				),
				'count'              => $stale_grading,
				'urgency'            => 'media',
				'url'                => $url,
				'_tier'              => 3,
				'_has_specific_time' => false,
			);
		}

		$inactive = absint( $counts['students_inactive'] ?? 0 );
		if ( $inactive > 0 ) {
			$items[] = array(
				'source'             => 'inactivity',
				'title'              => sprintf(
					_n( '%d estudiante inactivo esperando tu mensaje', '%d estudiantes inactivos esperando tu mensaje', $inactive, 'atora-lms' ),
					$inactive
				),
				'count'              => $inactive,
				'urgency'            => 'media',
				'url'                => $url,
				'_tier'              => 3,
				'_has_specific_time' => false,
			);
		}

		$quizzes = absint( $counts['quizzes_pending'] ?? 0 );
		if ( $quizzes > 0 ) {
			$items[] = array(
				'source'             => 'quiz',
				'title'              => sprintf(
					_n( '%d examen pendiente de revisión', '%d exámenes pendientes de revisión', $quizzes, 'atora-lms' ),
					$quizzes
				),
				'count'              => $quizzes,
				'urgency'            => 'media',
				'url'                => $url,
				'_tier'              => 3,
				'_has_specific_time' => false,
			);
		}

		return $items;
	}

	/**
	 * PT-1.2/2.1: tareas vencidas (nivel 1) y próximas 7 días (nivel 5)
	 * asignadas al usuario — fuente `task`. Reutiliza
	 * Task_Service::list_tasks() tal cual existe (ya soporta filtrar
	 * por assigned_to/status/date_from/date_to) — no se le agrega nada.
	 *
	 * @param int  $user_id
	 * @param bool $has_academic   No usado directamente -- las tareas ya vienen scopeadas por assigned_to.
	 * @param bool $has_commercial No usado directamente -- ídem.
	 * @return array<int,array<string,mixed>>
	 */
	protected function collect_task_items( $user_id, $has_academic, $has_commercial ) {
		unset( $has_academic, $has_commercial ); // ver docblock -- assigned_to ya acota correctamente.

		if ( ! class_exists( '\ATORA\CRM_V2\Services\Task_Service' ) ) {
			return array();
		}

		$user_id = absint( $user_id );
		$now     = current_time( 'mysql', true );
		$week    = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days', current_time( 'timestamp', true ) ) );
		$items   = array();
		$seen    = array();

		foreach ( array( 'pending', 'in_progress' ) as $status ) {
			$overdue = \ATORA\CRM_V2\Services\Task_Service::list_tasks(
				array( 'assigned_to' => $user_id, 'status' => $status, 'date_to' => $now, 'limit' => 50 )
			);
			foreach ( (array) ( $overdue['items'] ?? array() ) as $task ) {
				$task_id = absint( $task['id'] ?? 0 );
				if ( empty( $task['due_at'] ) || isset( $seen[ $task_id ] ) ) {
					continue;
				}
				$seen[ $task_id ] = true;
				$items[]           = $this->build_task_item( $task, 1 );
			}

			$upcoming = \ATORA\CRM_V2\Services\Task_Service::list_tasks(
				array( 'assigned_to' => $user_id, 'status' => $status, 'date_from' => $now, 'date_to' => $week, 'limit' => 50 )
			);
			foreach ( (array) ( $upcoming['items'] ?? array() ) as $task ) {
				$task_id = absint( $task['id'] ?? 0 );
				if ( isset( $seen[ $task_id ] ) ) {
					continue;
				}
				$seen[ $task_id ] = true;
				$items[]           = $this->build_task_item( $task, 5 );
			}
		}

		return $items;
	}

	/**
	 * @param array $task Fila de Task_Service::list_tasks().
	 * @param int   $tier 1 (vencida) o 5 (próxima).
	 * @return array<string,mixed>
	 */
	protected function build_task_item( array $task, $tier ) {
		$contact_id = absint( $task['contact_id'] ?? 0 );
		// Mismo patrón de enlace por tarea que modules/crm-v2/views/hub.php
		// ya usa: directo al contacto si la tarea tiene uno, o al hub de
		// crecimiento si no -- no se inventa una vista nueva.
		$url = $contact_id
			? admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . $contact_id )
			: admin_url( 'admin.php?page=atora-growth-hub' );

		return array(
			'source'             => 'task',
			'title'              => sanitize_text_field( (string) ( $task['title'] ?? __( 'Tarea', 'atora-lms' ) ) ),
			'count'              => 1,
			'urgency'            => 1 === (int) $tier ? 'alta' : 'baja',
			'url'                => $url,
			'_tier'              => (int) $tier,
			'_has_specific_time' => ! empty( $task['due_at'] ),
		);
	}

	/**
	 * @param string $domain
	 * @param int    $count
	 * @return string
	 */
	protected function entity_word( $domain, $count ) {
		$count = absint( $count );
		if ( 'commercial' === $domain ) {
			return 1 === $count ? __( 'contacto', 'atora-lms' ) : __( 'contactos', 'atora-lms' );
		}
		return 1 === $count ? __( 'estudiante', 'atora-lms' ) : __( 'estudiantes', 'atora-lms' );
	}

	/**
	 * [atora_hoy] — PT-3.1. Placeholder mínimo hasta que PT-3 agregue
	 * el render real; se registra el shortcode ya en PT-1 para que el
	 * hook exista desde el primer commit del sprint.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		return '';
	}
}
