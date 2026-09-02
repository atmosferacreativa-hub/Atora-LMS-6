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
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_styles' ) );
	}

	/**
	 * El CSS de "Hoy" (assets/admin/atora-hoy.css) se encola en
	 * wp-admin vía enqueue_atora_admin_styles() (trait-admin-menu-hubs.php),
	 * gateado a esa página — pero el shortcode [atora_hoy] puede
	 * incrustarse en una página normal de WordPress, fuera de
	 * wp-admin, donde ese hook no corre. Este método cubre ese caso.
	 *
	 * @return void
	 */
	public function maybe_enqueue_frontend_styles() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'atora_hoy' ) ) {
			return;
		}
		if ( ! defined( 'ATORA_LMS_URL' ) || ! defined( 'ATORA_LMS_DIR' ) ) {
			return;
		}

		$ver = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0';
		wp_enqueue_style( 'atora-admin-ds', ATORA_LMS_URL . 'assets/admin/atora-admin.css', array(), $ver );

		$css_file = ATORA_LMS_DIR . 'assets/admin/atora-hoy.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style( 'atora-hoy', ATORA_LMS_URL . 'assets/admin/atora-hoy.css', array( 'atora-admin-ds' ), $ver );
		}
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
			// P9 (6.13.0): asistencia conectada al panel "Hoy" — estudiantes
			// con N ausencias consecutivas en cursos del propio docente.
			$items = array_merge( $items, $this->collect_attendance_items( $user_id ) );
		}

		// PT-3 (6.11.0): antes no había ninguna fuente de coordinador acá
		// -- ver docs/DEUDA-TECNICA.md, "Vista de coordinador — explícitamente
		// diferida". Gate propio (no has_academic/has_commercial): un
		// coordinador puede no tener ninguna de esas dos caps.
		if ( $this->user_has_coordinator_access( $user_id ) ) {
			$items = array_merge( $items, $this->collect_coordinator_items( $user_id ) );
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
	 * PT-3 (6.11.0): true si el usuario coordina al menos una sección
	 * (Section_Service::get_coordinator_section_ids()) — gate de
	 * collect_coordinator_items(), mismo patrón que
	 * user_has_academic_access()/user_has_commercial_access().
	 *
	 * @param int $user_id
	 * @return bool
	 */
	protected function user_has_coordinator_access( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			return false;
		}
		return ! empty( \ATORA\LMS\Section_Service::get_coordinator_section_ids( $user_id ) );
	}

	/**
	 * PT-1.2/2.1: ocurrencias de planes de seguimiento (ambos dominios)
	 * con al menos un estudiante/contacto sin contactar todavía —
	 * fuente `followup_academic` / `followup_commercial`.
	 *
	 * @param int $user_id
	 * @return array<int,array<string,mixed>>
	 */
	/**
	 * P9 (6.13.0): estudiantes con ausencias consecutivas en cursos del
	 * docente — mismo shape de ítem que collect_followup_items().
	 *
	 * @param int $user_id
	 * @return array<int,array<string,mixed>>
	 */
	protected function collect_attendance_items( $user_id ) {
		if ( ! class_exists( 'CLMS_Attendance_Academic_Bridge' ) ) {
			return array();
		}

		$at_risk = CLMS_Attendance_Academic_Bridge::get_at_risk_students_for_teacher( absint( $user_id ) );
		$items   = array();

		foreach ( $at_risk as $row ) {
			$items[] = array(
				'source'             => 'attendance',
				'title'              => sprintf(
					/* translators: 1: nombre del estudiante, 2: número de ausencias, 3: curso */
					__( '%1$s lleva %2$d ausencias seguidas — %3$s', 'atora-lms' ),
					get_userdata( $row['user_id'] ) ? get_userdata( $row['user_id'] )->display_name : sprintf( '#%d', $row['user_id'] ),
					$row['absences'],
					$row['course_title']
				),
				'count'              => $row['absences'],
				'urgency'            => 'alta',
				'url'                => admin_url( 'admin.php?page=clms-academic-hub&user_id=' . $row['user_id'] ),
				'_tier'              => 2,
				'_has_specific_time' => false,
			);
		}

		return $items;
	}

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

			$event_id = absint( $occurrence['event_id'] ?? 0 );
			$items[] = array(
				'source'                => 'commercial' === $domain ? 'followup_commercial' : 'followup_academic',
				'title'                 => $title,
				'count'                 => $uncontacted,
				'urgency'               => $tier <= 2 ? 'alta' : 'media',
				'url'                   => admin_url( 'admin.php?page=atora-followup-plans&event_id=' . $event_id ),
				// PT-1.5/PT-4.1 (6.9.0): today.js intercepta el clic en
				// filas con este atributo y abre el panel compartido en
				// línea en vez de navegar -- el href de arriba sigue
				// funcionando como respaldo (JS deshabilitado, clic
				// derecho "abrir en pestaña nueva", etc.).
				'event_id'              => $event_id,
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
	 * PT-3 (6.11.0): primera fuente real de datos para un coordinador --
	 * cuenta cuántos estudiantes de las secciones que coordina recibieron
	 * una alerta 'academic_at_risk_alert' (ver
	 * CLMS_Academic_Messaging_Bridge::on_at_risk_signal(), PT-2 del mismo
	 * sprint) en los últimos 7 días. Deliberadamente NO recalcula
	 * get_student_course_status() por cada estudiante del roster -- ver
	 * CLMS_Contacts_Core_Service::count_recent_activity_for_users(), una
	 * sola consulta con JOIN. Promedio agregado por sección y conteo de
	 * entregas pendientes por sección quedan fuera de este sprint --
	 * requerirían agregación nueva que hoy no existe ni de forma barata
	 * (documentado en el reporte de cierre).
	 *
	 * @param int $user_id
	 * @return array
	 */
	protected function collect_coordinator_items( $user_id ) {
		if ( ! class_exists( '\ATORA\LMS\Section_Service' ) || ! function_exists( 'atora_lms' ) ) {
			return array();
		}

		$user_id      = absint( $user_id );
		$section_ids  = \ATORA\LMS\Section_Service::get_coordinator_section_ids( $user_id );
		if ( empty( $section_ids ) ) {
			return array();
		}

		$student_ids = array();
		foreach ( $section_ids as $section_id ) {
			foreach ( (array) \ATORA\LMS\Section_Service::get_section_student_ids( $section_id ) as $student_id ) {
				$student_ids[] = absint( $student_id );
			}
		}
		$student_ids = array_values( array_unique( array_filter( $student_ids ) ) );
		if ( empty( $student_ids ) ) {
			return array();
		}

		$contacts_core = atora_lms( 'CLMS_Contacts_Core_Service' );
		if ( ! $contacts_core || ! method_exists( $contacts_core, 'count_recent_activity_for_users' ) ) {
			return array();
		}

		$at_risk_count = $contacts_core->count_recent_activity_for_users( $student_ids, 'academic_at_risk_alert', 7 );
		if ( $at_risk_count <= 0 ) {
			return array();
		}

		return array(
			array(
				'source'             => 'coordinator_at_risk',
				'title'              => sprintf(
					_n( '%d estudiante en riesgo en tus secciones (últimos 7 días)', '%d estudiantes en riesgo en tus secciones (últimos 7 días)', $at_risk_count, 'atora-lms' ),
					$at_risk_count
				),
				'count'              => $at_risk_count,
				'urgency'            => 'alta',
				'url'                => admin_url( 'admin.php?page=atora-hoy' ),
				'_tier'              => 2,
				'_has_specific_time' => false,
			),
		);
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
	 * [atora_hoy] (PT-3.1) — mismo render que la página de admin
	 * (includes/today/views/today-page.php), para no duplicar el
	 * marcado en dos lugares. Usuario no logueado: nada que mostrar,
	 * mismo criterio que el resto de shortcodes de cuenta del plugin
	 * (atora_preferencias, atora_affiliate_dashboard).
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		ob_start();
		echo '<div class="atora-hoy-shortcode">';
		echo $this->render_items_html( $this->get_today( $user_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado adentro.
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * PT-3.1/3.2/3.3: marcado de la lista de "Hoy", agrupada
	 * visualmente por urgencia (no por fuente — el docente/vendedor no
	 * necesita saber de dónde viene un ítem). Compartido entre la
	 * página de admin y el shortcode — un solo lugar que mantiene el
	 * HTML.
	 *
	 * @param array<int,array<string,mixed>> $items Resultado de get_today().
	 * @return string HTML ya escapado.
	 */
	public function render_items_html( array $items ) {
		if ( empty( $items ) ) {
			// PT-3.3: estado vacío positivo, con tono cálido -- nunca una
			// lista en blanco sin contexto, misma regla que rige todos
			// los estados vacíos de la serie 6.6.0/6.7.0.
			return '<div class="atora-hoy-empty">'
				. '<p class="atora-hoy-empty-title">' . esc_html__( '✨ Nada urgente por ahora.', 'atora-lms' ) . '</p>'
				. '<p class="atora-hoy-empty-sub">' . esc_html__( 'Buen momento para adelantar algo, o simplemente respirar.', 'atora-lms' ) . '</p>'
				. '</div>';
		}

		$groups = array(
			'alta'  => array( 'label' => __( 'Necesita tu atención ahora', 'atora-lms' ), 'items' => array() ),
			'media' => array( 'label' => __( 'Para revisar hoy', 'atora-lms' ), 'items' => array() ),
			'baja'  => array( 'label' => __( 'Próximos días', 'atora-lms' ), 'items' => array() ),
		);

		foreach ( $items as $item ) {
			$urgency = isset( $groups[ $item['urgency'] ?? '' ] ) ? $item['urgency'] : 'media';
			$groups[ $urgency ]['items'][] = $item;
		}

		$html = '<div class="atora-hoy-list">';
		foreach ( $groups as $urgency => $group ) {
			if ( empty( $group['items'] ) ) {
				continue;
			}
			$html .= '<section class="atora-hoy-group atora-hoy-group--' . esc_attr( $urgency ) . '">';
			$html .= '<h2 class="atora-hoy-group-title">' . esc_html( $group['label'] ) . '</h2>';
			$html .= '<ul class="atora-hoy-items">';
			foreach ( $group['items'] as $item ) {
				$source   = sanitize_key( (string) ( $item['source'] ?? '' ) );
				$event_id = absint( $item['event_id'] ?? 0 );
				// PT-1.5/PT-4.1 (6.9.0): un ítem de followup lleva
				// data-event-id -- today.js lo intercepta para abrir el
				// panel compartido en línea en vez de navegar a otra
				// página. El resto de fuentes (grading/inactivity/quiz/
				// task) no tiene un panel equivalente todavía (ver
				// docs/DEUDA-TECNICA.md) y sigue navegando normal.
				$data_attr = ( in_array( $source, array( 'followup_academic', 'followup_commercial' ), true ) && $event_id )
					? ' data-event-id="' . esc_attr( (string) $event_id ) . '"'
					: '';

				$html .= '<li class="atora-hoy-item atora-hoy-item--' . esc_attr( $urgency ) . '">';
				$html .= '<a class="atora-hoy-item-link" href="' . esc_url( (string) ( $item['url'] ?? '#' ) ) . '"' . $data_attr . '>';
				$html .= '<span class="atora-hoy-item-title">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</span>';
				$html .= '<span class="atora-hoy-item-arrow" aria-hidden="true">→</span>';
				$html .= '</a></li>';
			}
			$html .= '</ul></section>';
		}
		$html .= '</div>';

		return $html;
	}
}
