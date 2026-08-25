<?php
/**
 * CLMS_UI_Search_Service — búsqueda persistente y federada (PT-2, sprint 6.9.0).
 *
 * Reutiliza EXACTAMENTE los mecanismos de alcance ya construidos —
 * ninguna segunda capa de permisos (§2.2 de la OT):
 *   - Académico: `Section_Service::get_sections_by_teacher()` (el
 *     mismo llamado que ya usa `modules/crm-v2/views/followup-plans.php`
 *     desde 6.6.0) y `get_section_student_ids()` (el mismo que
 *     `Teacher_Digest_Service` ya usa) — un docente sin
 *     `can_access_academic_calendar()` no dispara ninguna consulta;
 *     un docente con acceso solo ve estudiantes de SUS secciones.
 *   - Comercial: `Contact_Service::search_contacts()`, que ya aplica
 *     `get_scope_user_ids()` internamente (vía `build_where_clause()`)
 *     en cada llamada — no se reimplementa ese alcance acá.
 *
 * @package ATORA_LMS
 * @since   6.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Search_Service {

	const REST_NAMESPACE = 'clms/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** @return void */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_search_request' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Params: q (término), limit (por grupo, default 5).
	 * @return WP_REST_Response
	 */
	public function handle_search_request( $request ) {
		$term  = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$limit = max( 1, min( 20, absint( $request->get_param( 'limit' ) ?: 5 ) ) );

		if ( mb_strlen( trim( $term ) ) < 2 ) {
			return rest_ensure_response( array( 'success' => true, 'groups' => array() ) );
		}

		$user_id = get_current_user_id();
		$groups  = array();

		$students = $this->search_students( $user_id, $term, $limit );
		if ( ! empty( $students ) ) {
			$groups['students'] = array( 'label' => __( 'Estudiantes', 'atora-lms' ), 'items' => $students );
		}

		$sections = $this->search_sections( $user_id, $term, $limit );
		if ( ! empty( $sections ) ) {
			$groups['sections'] = array( 'label' => __( 'Secciones', 'atora-lms' ), 'items' => $sections );
		}

		$contacts = $this->search_contacts( $term, $limit );
		if ( ! empty( $contacts ) ) {
			$groups['contacts'] = array( 'label' => __( 'Contactos', 'atora-lms' ), 'items' => $contacts );
		}

		return rest_ensure_response( array( 'success' => true, 'groups' => $groups ) );
	}

	/**
	 * PT-2.2: solo estudiantes de las secciones donde $user_id es
	 * docente — mismo alcance que followup-plans.php ya usa. Un
	 * usuario sin acceso académico (verificado con el mismo criterio
	 * de capacidades que can_access_academic_calendar(), duplicado acá
	 * con el mismo motivo documentado desde 6.9.0 PT-1 en el
	 * agregador de "Hoy") nunca dispara esta consulta.
	 *
	 * @param int    $user_id
	 * @param string $term
	 * @param int    $limit
	 * @return array<int,array<string,mixed>>
	 */
	private function search_students( $user_id, $term, $limit ) {
		if ( ! $this->user_has_academic_access( $user_id ) || ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			return array();
		}

		$sections = \ATORA\LMS\Section_Service::get_sections_by_teacher( absint( $user_id ) );
		if ( empty( $sections ) ) {
			return array();
		}

		$student_ids = array();
		$section_by_student = array();
		foreach ( $sections as $section ) {
			$section_id = absint( $section['id'] ?? 0 );
			if ( ! $section_id || ! method_exists( '\ATORA\LMS\Section_Service', 'get_section_student_ids' ) ) {
				continue;
			}
			foreach ( \ATORA\LMS\Section_Service::get_section_student_ids( $section_id ) as $student_id ) {
				$student_id = absint( $student_id );
				if ( ! $student_id ) {
					continue;
				}
				$student_ids[ $student_id ] = true;
				if ( ! isset( $section_by_student[ $student_id ] ) ) {
					$section_by_student[ $student_id ] = sanitize_text_field( (string) ( $section['title'] ?? '' ) );
				}
			}
		}

		$student_ids = array_keys( $student_ids );
		if ( empty( $student_ids ) ) {
			return array();
		}

		$user_query = new WP_User_Query( array(
			'include' => $student_ids,
			'search'  => '*' . esc_attr( $term ) . '*',
			'search_columns' => array( 'display_name', 'user_email', 'user_login' ),
			'number'  => $limit,
			'fields'  => array( 'ID', 'display_name', 'user_email' ),
		) );

		$results = array();
		foreach ( (array) $user_query->get_results() as $user ) {
			$results[] = array(
				'id'   => absint( $user->ID ),
				'name' => sanitize_text_field( (string) $user->display_name ),
				'meta' => sanitize_text_field( (string) ( $section_by_student[ absint( $user->ID ) ] ?? '' ) ),
				// PT-2.4: no existe todavía una ficha de estudiante
				// dedicada en el plugin (verificado antes de decidir --
				// ver docs/DEUDA-TECNICA.md) -- el destino más cercano
				// real es el hub de estudiantes, no un listado genérico
				// de búsqueda que obligue a re-buscar.
				'url'  => admin_url( 'admin.php?page=atora-students-hub' ),
				'badges' => array( __( 'Estudiante', 'atora-lms' ) ),
			);
		}

		return $results;
	}

	/**
	 * PT-2.2: solo secciones donde $user_id es docente.
	 *
	 * @param int    $user_id
	 * @param string $term
	 * @param int    $limit
	 * @return array<int,array<string,mixed>>
	 */
	private function search_sections( $user_id, $term, $limit ) {
		if ( ! $this->user_has_academic_access( $user_id ) || ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			return array();
		}

		$sections = \ATORA\LMS\Section_Service::get_sections_by_teacher( absint( $user_id ) );
		if ( empty( $sections ) ) {
			return array();
		}

		$term_lower = mb_strtolower( $term );
		$matches    = array();
		foreach ( $sections as $section ) {
			$title = sanitize_text_field( (string) ( $section['title'] ?? '' ) );
			if ( '' === $title || false === mb_strpos( mb_strtolower( $title ), $term_lower ) ) {
				continue;
			}
			$matches[] = array(
				'id'     => absint( $section['id'] ?? 0 ),
				'name'   => $title,
				'meta'   => sanitize_text_field( (string) ( $section['status'] ?? '' ) ),
				'url'    => admin_url( 'admin.php?page=atora-students-hub' ), // ver nota en search_students().
				'badges' => array( __( 'Sección', 'atora-lms' ) ),
			);
			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return $matches;
	}

	/**
	 * PT-2.2: reutiliza Contact_Service::search_contacts() tal cual —
	 * ese método ya aplica get_scope_user_ids() internamente en cada
	 * llamada (build_where_clause()), así que este método no necesita
	 * (y no debe) agregar su propio filtro de alcance encima.
	 *
	 * @param string $term
	 * @param int    $limit
	 * @return array<int,array<string,mixed>>
	 */
	private function search_contacts( $term, $limit ) {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Contact_Service' ) ) {
			return array();
		}
		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'can_access_crm' ) && ! \ATORA\CRM\CRM::can_access_crm() ) {
			return array();
		}

		$items   = \ATORA\CRM_V2\Services\Contact_Service::search_contacts( $term, $limit );
		$results = array();
		foreach ( $items as $item ) {
			$contact_id = absint( $item['id'] ?? 0 );
			if ( ! $contact_id ) {
				continue;
			}
			$results[] = array(
				'id'     => $contact_id,
				'name'   => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'meta'   => sanitize_text_field( (string) ( $item['email'] ?? '' ) ),
				'url'    => admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . $contact_id ),
				'badges' => array( __( 'Contacto', 'atora-lms' ) ),
			);
		}

		return $results;
	}

	/**
	 * Misma lista de capacidades que can_access_academic_calendar()
	 * (trait-admin-menu-navigation.php) — duplicada acá con el mismo
	 * motivo ya documentado en CLMS_Today_Aggregator_Service (6.8.0):
	 * esta clase global no tiene acceso a ese trait.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private function user_has_academic_access( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		return user_can( $user_id, 'manage_options' )
			|| user_can( $user_id, 'clms_access_admin' )
			|| user_can( $user_id, 'clms_manage_courses' )
			|| user_can( $user_id, 'clms_manage_lessons' )
			|| user_can( $user_id, 'clms_view_teacher_dashboard' )
			|| user_can( $user_id, 'clms_grade_submissions' )
			|| user_can( $user_id, 'edit_posts' );
	}
}
