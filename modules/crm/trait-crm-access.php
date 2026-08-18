<?php

namespace ATORA\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CRM_Access_Trait {
	/**
	 * Valida si un usuario puede acceder al CRM.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function can_access_crm( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return false;
		}

		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
		if ( user_can( $user_id, 'manage_options' ) || $is_super_admin ) {
			return true;
		}

		$allowed_caps = array(
			'manage_options',
			// Fase 5 — caps CRM granulares (reemplazan edit_posts como puerta)
			'clms_access_crm_view',
			'clms_manage_crm',
			// Caps LMS que implican acceso al CRM
			'clms_access_admin',
			'clms_manage_commerce',
			'clms_manage_courses',
			'clms_manage_lessons',
			'clms_view_teacher_dashboard',
			'clms_grade_submissions',
		);

		$can_access = false;
		foreach ( $allowed_caps as $capability ) {
			if ( user_can( $user_id, $capability ) ) {
				$can_access = true;
				break;
			}
		}

		/**
		 * Filtra el acceso operativo al CRM.
		 *
		 * @param bool  $can_access   Si el usuario puede acceder.
		 * @param int   $user_id      ID de usuario evaluado.
		 * @param array $allowed_caps Capacidades consideradas por defecto.
		 */
		return (bool) apply_filters( 'atora_crm_can_access_user', $can_access, $user_id, $allowed_caps );
	}

	/**
	 * Valida si un usuario puede gestionar el CRM sin restricciones.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function can_manage_crm( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		return $user_id && ( user_can( $user_id, 'clms_manage_crm' ) || user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * Determina si el usuario actual tiene alcance global de contactos (sin filtrar por matrículas).
	 *
	 * Se usa para operadores comerciales, admins operativos o equipos que coordinan
	 * con visibilidad completa del CRM.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function has_global_contact_scope( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return false;
		}

		if ( ! self::can_access_crm( $user_id ) ) {
			return false;
		}

		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
		if ( user_can( $user_id, 'manage_options' ) || $is_super_admin ) {
			return true;
		}

		// Operación/comercial: visibilidad completa.
		if (
			user_can( $user_id, 'clms_access_admin' )
			|| user_can( $user_id, 'clms_manage_commerce' )
			|| user_can( $user_id, 'clms_manage_courses' )
			|| user_can( $user_id, 'clms_manage_lessons' )
		) {
			return true;
		}

		// Colaborador WP (edit_posts) pero no docente: visibilidad operativa completa.
		if (
			user_can( $user_id, 'edit_posts' )
			&& ! user_can( $user_id, 'clms_view_teacher_dashboard' )
			&& ! user_can( $user_id, 'clms_grade_submissions' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Devuelve IDs de estudiantes visibles para el usuario actual (scope por enrollments).
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,int>
	 */
	public static function get_accessible_contact_user_ids( int $user_id = 0 ): array {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return array();
		}

		if ( self::can_manage_crm( $user_id ) ) {
			return array();
		}

		if ( ! self::can_access_crm( $user_id ) ) {
			return array();
		}

		if ( self::has_global_contact_scope( $user_id ) ) {
			return array();
		}

		$student_ids = array();
		$messaging   = null;

		if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'module' ) ) {
			$messaging = \clms_core('CLMS_Messaging');
		}
		if ( ! $messaging && class_exists( '\CLMS_Messaging' ) ) {
			$messaging = new \CLMS_Messaging();
		}

		if ( $messaging && method_exists( $messaging, 'get_compose_context_for_user' ) ) {
			$context     = (array) $messaging->get_compose_context_for_user( $user_id );
			$student_ids = array_map( 'absint', array_keys( (array) ( $context['students'] ?? array() ) ) );
		}

		if ( empty( $student_ids ) && class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			$course_ids = get_posts(
				array(
					'post_type'              => 'lm_course',
					'post_status'            => array( 'publish', 'private', 'draft' ),
					'author'                 => $user_id,
					'fields'                 => 'ids',
					'posts_per_page'         => 200,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( (array) $course_ids as $course_id ) {
				foreach ( (array) \CLMS_Helper::get_enrolled_student_ids( absint( $course_id ) ) as $student_id ) {
					$student_ids[] = absint( $student_id );
				}
			}
		}

		$student_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $student_ids )
				)
			)
		);

		return $student_ids;
	}

	/**
	 * Determina si el usuario actual puede acceder a un contacto concreto.
	 *
	 * @param int $contact_id Contacto CRM.
	 * @param int $user_id    Usuario.
	 * @return bool
	 */
	protected static function current_user_can_access_contact( int $contact_id, int $user_id = 0 ): bool {
		$contact_id = absint( $contact_id );
		$user_id    = absint( $user_id ?: get_current_user_id() );
		if ( ! $contact_id || ! self::can_access_crm( $user_id ) ) {
			return false;
		}

		if ( self::can_manage_crm( $user_id ) ) {
			return true;
		}

		global $wpdb;
		$contact_user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1",
				$contact_id
			)
		);

		if ( ! $contact_user_id ) {
			// Leads sin user_id asociado (captura por formulario, etc.): accesibles solo en alcance global.
			return self::has_global_contact_scope( $user_id );
		}

		$allowed_user_ids = self::get_accessible_contact_user_ids( $user_id );
		return in_array( $contact_user_id, $allowed_user_ids, true );
	}

	/**
	 * Inicializa el módulo CRM.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::has_required_tables() ) {
			self::maybe_install_required_tables();
		}

		if ( ! self::has_required_tables() ) {
			return;
		}

		// Hooks de actividad automática.
		add_action( 'user_register',                    array( __CLASS__, 'on_user_registered' ) );
		add_action( 'atora/security/registration_saved', array( __CLASS__, 'on_registration_profile_saved' ), 10, 2 );
		add_action( 'woocommerce_thankyou',             array( __CLASS__, 'on_purchase' ) );
		add_action( 'clms_user_enrolled_in_course',     array( __CLASS__, 'on_course_enrolled' ), 10, 2 );
		add_action( 'atora/forms/submitted',            array( __CLASS__, 'on_form_submitted' ), 10, 3 );
		add_action( 'atora/email/webhook_event',        array( __CLASS__, 'on_email_event' ), 10, 3 );
		add_action( 'atora/whatsapp/message_received',  array( __CLASS__, 'on_whatsapp_message_received' ), 10, 2 );
		add_action( 'atora/telegram/message_received',  array( __CLASS__, 'on_telegram_message_received' ), 10, 3 );

		// Cron: auto-tags diario.
		add_action( 'atora_crm_autotag_cron', array( __CLASS__, 'process_auto_tags' ) );
		if ( ! wp_next_scheduled( 'atora_crm_autotag_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'atora_crm_autotag_cron' );
		}

		// REST API.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Admin.
		if ( is_admin() ) {
			// PT-4.3.3 (6.3.0): sin hook a register_admin_menu() — este
			// registro (cap 'read', visible bajo clms-dashboard) coexistía
			// con 'atora-crm-v2' (el hub, cap clms_access_crm_view) como
			// una segunda entrada "CRM" visible en el sidebar al mismo
			// tiempo. El hub ya sirve 'atora-crm' como alias legacy oculto
			// (render_crm_v2_page() detecta page=atora-crm y renderiza el
			// fallback legacy) — una sola entrada visible de CRM ahora.
			add_action( 'wp_ajax_atora_crm_search',    array( __CLASS__, 'ajax_search' ) );
			add_action( 'wp_ajax_atora_crm_note_save', array( __CLASS__, 'ajax_save_note' ) );
			add_action( 'wp_ajax_atora_crm_tag',       array( __CLASS__, 'ajax_add_tag' ) );
			add_action( 'wp_ajax_atora_crm_export',    array( __CLASS__, 'ajax_export_csv' ) );
		}
	}

}
