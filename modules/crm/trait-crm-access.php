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
		// PT-1 (6.11.0): implementación real movida a
		// CLMS_Contacts_Core_Service (includes/contacts-core/) -- ver su
		// docblock. Este método queda como delegado para no romper los
		// ~40 call sites existentes de CRM::.
		return clms_core( 'CLMS_Contacts_Core_Service' )->can_access_crm( $user_id );
	}

	/**
	 * Valida si un usuario puede gestionar el CRM sin restricciones.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function can_manage_crm( int $user_id = 0 ): bool {
		// PT-1 (6.11.0): ver can_access_crm() arriba.
		return clms_core( 'CLMS_Contacts_Core_Service' )->can_manage_crm( $user_id );
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
		// PT-1 (6.11.0): ver can_access_crm() arriba. El criterio PT-1.3
		// (6.5.1) sobre qué caps otorgan alcance global vive ahora en
		// CLMS_Contacts_Core_Service::has_global_contact_scope().
		return clms_core( 'CLMS_Contacts_Core_Service' )->has_global_contact_scope( $user_id );
	}

	/**
	 * Devuelve IDs de estudiantes visibles para el usuario actual (scope por enrollments).
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,int>
	 */
	public static function get_accessible_contact_user_ids( int $user_id = 0 ): array {
		// PT-1 (6.11.0): ver can_access_crm() arriba.
		return clms_core( 'CLMS_Contacts_Core_Service' )->get_accessible_contact_user_ids( $user_id );
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
