<?php
/**
 * CLMS Enrollment Manager
 *
 * Sistema modular de matriculación con cuatro métodos independientes:
 *  1. WooCommerce — matrícula automática al completar una compra.
 *  2. Invitaciones — el instructor envía un token único por email.
 *  3. Enlace de acceso — URL pública con modos libre, contraseña o con registro.
 *  4. Manual / CSV — el admin escribe un email o sube un CSV.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/enrollment-manager/trait-enrollment-manager-invitations.php';
require_once __DIR__ . '/enrollment-manager/trait-enrollment-manager-access-enrollment.php';
require_once __DIR__ . '/enrollment-manager/trait-enrollment-manager-frontend.php';
require_once __DIR__ . '/enrollment-manager/trait-enrollment-manager-ajax.php';
require_once __DIR__ . '/enrollment-manager/trait-enrollment-manager-utils.php';

class CLMS_Enrollment_Manager {

	// -------------------------------------------------------------------------
	// Constantes
	// -------------------------------------------------------------------------

	const DB_VERSION        = '1.2';
	const DB_VERSION_OPTION = 'clms_enrollment_db_version';

	// Modos de enlace de acceso.
	const LINK_FREE     = 'free';       // Cualquiera con el enlace entra.
	const LINK_PASSWORD = 'password';   // Requiere contraseña adicional.
	const LINK_REGISTER = 'register';   // Obliga a crear cuenta primero.

	/**
	 * Caché en memoria: evita múltiples SHOW TABLES en la misma solicitud.
	 *
	 * @var bool
	 */
	private static $db_ensured = false;

	use CLMS_Enrollment_Manager_Invitations_Trait;
	use CLMS_Enrollment_Manager_Access_Enrollment_Trait;
	use CLMS_Enrollment_Manager_Frontend_Trait;
	use CLMS_Enrollment_Manager_Ajax_Trait;
	use CLMS_Enrollment_Manager_Utils_Trait;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	public function __construct() {

		// DB.
		add_action( 'plugins_loaded', array( $this, 'maybe_install_db' ), 20 );

		// WooCommerce hooks removidos — ahora manejados exclusivamente por CLMS_Commerce_Hooks
		// desde v4.21.1 para evitar procesamiento duplicado. CLMS_Commerce_Hooks tiene prioridad 20
		// y gestión completa de notificaciones de acceso al curso.
		//
		// add_action( 'woocommerce_order_status_completed',  array( $this, 'handle_woo_order' ), 10 );
		// add_action( 'woocommerce_order_status_processing', array( $this, 'handle_woo_order' ), 10 );

		// Frontend — interceptar URLs de unirse.
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_enrollment_redirect' ), 1 );

		// AJAX — admin.
		add_action( 'wp_ajax_clms_em_send_invitation',  array( $this, 'ajax_send_invitation' ) );
		add_action( 'wp_ajax_clms_em_revoke_invitation', array( $this, 'ajax_revoke_invitation' ) );
		add_action( 'wp_ajax_clms_em_regenerate_link',  array( $this, 'ajax_regenerate_link' ) );
		add_action( 'wp_ajax_clms_em_enroll_manual',    array( $this, 'ajax_enroll_manual' ) );
		add_action( 'wp_ajax_clms_em_unenroll',         array( $this, 'ajax_unenroll' ) );
		add_action( 'wp_ajax_clms_em_process_csv',      array( $this, 'ajax_process_csv' ) );

		// AJAX — frontend (para unirse con contraseña sin estar logueado).
		add_action( 'wp_ajax_clms_em_join_password',        array( $this, 'ajax_join_password' ) );
		add_action( 'wp_ajax_nopriv_clms_em_join_password', array( $this, 'ajax_join_password' ) );

		// Sincronizar matrícula cuando el admin asigna el rol desde el perfil de usuario.
		add_action( 'set_user_role', array( $this, 'maybe_sync_enrollment_on_role_change' ), 10, 3 );
	}
}
