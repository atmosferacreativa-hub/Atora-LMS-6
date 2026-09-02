<?php
/**
 * Aplicación principal CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2;

use ATORA\CRM_V2\Services\Campaign_Service;
use ATORA\CRM_V2\Services\Contact_Service;
use ATORA\CRM_V2\Services\CRM_Email_Service;
use ATORA\CRM_V2\Services\DB_Service;
use ATORA\CRM_V2\Services\Deal_Service;
use ATORA\CRM_V2\Services\Report_Service;
use ATORA\CRM_V2\Services\Student_Followup_Service;
use ATORA\CRM_V2\Services\Task_Service;
use ATORA\CRM_V2\Rest\CRM_REST_Controller;
use ATORA\CRM_V2\Rest\Pipeline_REST_Controller;
use ATORA\CRM_V2\Rest\Tasks_REST_Controller;
use ATORA\CRM_V2\Rest\Calendar_REST_Controller;
use ATORA\CRM_V2\Rest\Inbox_REST_Controller;
use ATORA\CRM_V2\Rest\Reports_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRM_V2_App {
	/**
	 * Bandera para evitar boot doble.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Inicializa CRM v2.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$booted ) {
			return;
		}

		self::load_dependencies();

		if ( ! self::is_enabled() ) {
			self::$booted = true;
			return;
		}

		DB_Service::maybe_install_schema();

		// P2 (6.12.0): gateado por módulo 'crm'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'crm' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		// ── Invalidar caché de scope CRM al cambiar matrículas (Fase 5) ───
		add_action( 'clms_user_enrolled_in_course',     array( __CLASS__, 'flush_crm_scope_cache' ), 10, 2 );
		add_action( 'clms_user_unenrolled_from_course', array( __CLASS__, 'flush_crm_scope_cache' ), 10, 2 );
		add_action( 'set_user_role',                    array( __CLASS__, 'flush_crm_scope_cache_by_user' ), 10, 1 );
		add_action( 'add_user_role',                    array( __CLASS__, 'flush_crm_scope_cache_by_user' ), 10, 1 );

		// PT-1.3/PT-4.5 (6.6.0) — mantenimiento diario de planes de
		// seguimiento: extiende la ventana de ocurrencias materializadas
		// de cada plan activo, y desactiva (sin borrar) los planes sin
		// end_date propio cuyas secciones ya cerraron su período. Mismo
		// hook diario ya usado por Calendar/2FA/Abandoned_Cart_Service —
		// no se crea un cron nuevo.
		if ( class_exists( 'ATORA\CRM_V2\Services\Followup_Plan_Service' ) ) {
			add_action( 'atora_daily_cron', array( '\ATORA\CRM_V2\Services\Followup_Plan_Service', 'run_daily_maintenance' ) );
		}

		self::$booted = true;
	}

	/**
	 * Registra endpoints REST.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		DB_Service::maybe_install_schema();

		CRM_REST_Controller::register_routes();
		Pipeline_REST_Controller::register_routes();
		Tasks_REST_Controller::register_routes();
		Calendar_REST_Controller::register_routes();
		Inbox_REST_Controller::register_routes();
		Reports_REST_Controller::register_routes();
		// Fase 1
		if ( class_exists( 'ATORA\CRM_V2\Rest\CRM_Draft_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\CRM_Draft_REST_Controller::register_routes();
		}
		// Fase 2
		if ( class_exists( 'ATORA\CRM_V2\Rest\Calendar_Events_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Calendar_Events_REST_Controller::register_routes();
		}
		// Fase 3
		if ( class_exists( 'ATORA\CRM_V2\Rest\Campaign_Builder_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Campaign_Builder_REST_Controller::register_routes();
		}
		if ( class_exists( 'ATORA\CRM_V2\Rest\Reports_Dashboard_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Reports_Dashboard_REST_Controller::register_routes();
		}
		// Fase 4 — Ficha 360 accionable
		if ( class_exists( 'ATORA\CRM_V2\Rest\Contact_Actions_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Contact_Actions_REST_Controller::register_routes();
		}
		// Fase 9 — Empresas + Listas
		if ( class_exists( 'ATORA\CRM_V2\Rest\Companies_Lists_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Companies_Lists_REST_Controller::register_routes();
		}
		// Fase 10 — URL tracking + Sequence
		if ( class_exists( 'ATORA\CRM_V2\Rest\Sequence_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Sequence_REST_Controller::register_routes();
		}
		// Fase 10 — Abandoned Carts
		if ( class_exists( 'ATORA\CRM_V2\Rest\Abandoned_Carts_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Abandoned_Carts_REST_Controller::register_routes();
		}
		// PT-4 (6.6.0) — Planes de seguimiento
		if ( class_exists( 'ATORA\CRM_V2\Rest\Followup_Plans_REST_Controller' ) ) {
			\ATORA\CRM_V2\Rest\Followup_Plans_REST_Controller::register_routes();
		}
	}

	/**
	 * Encola assets en pantallas CRM v2.
	 *
	 * @param string $hook Hook de admin.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		// PT-4 (6.6.0): atora-followup-plans se agrega acá — mismo REST
		// namespace (atora-crm/v2) y mismo objeto localizado atoraCrmV2
		// que el resto de CRM v2, así que necesita el mismo bootstrap de
		// assets. Condición puramente aditiva: ninguna página existente
		// deja de matchear por este cambio.
		$is_crm_v2_page = false !== strpos( $page, 'atora-crm-v2' ) || false !== strpos( $page, 'atora-crm-' ) || 'clms-crm-hub' === $page || 'atora-followup-plans' === $page;
		if ( ! $is_crm_v2_page ) {
			return;
		}

		if ( ! defined( 'ATORA_LMS_MODULES_URL' ) ) {
			return;
		}

		$ver = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '5.27.0';

		// ── CSS base CRM v2 ───────────────────────────────────────────────
		wp_enqueue_style( 'atora-crm-v2-ui', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-v2.css', array(), $ver );

		// ── JS base CRM v2 (Fase 1) ───────────────────────────────────────
		wp_enqueue_script( 'atora-crm-v2-ui', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-v2.js', array(), $ver, true );

		// ── Kanban ────────────────────────────────────────────────────────
		wp_enqueue_script( 'atora-crm-v2-kanban', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-kanban.js', array( 'atora-crm-v2-ui' ), $ver, true );

		// ── FullCalendar 6 (Fase 2) — local si existe, si no CDN ─────────
		$fc_ver  = '6.1.15';
		$fc_base = 'https://unpkg.com/@fullcalendar/';
		$fc_local_core = ATORA_LMS_MODULES_URL . 'crm-v2/assets/fullcalendar/core.global.min.js';
		$fc_local_exists = file_exists( str_replace( ATORA_LMS_MODULES_URL, ATORA_LMS_MODULES_DIR, $fc_local_core ) );

		if ( $fc_local_exists ) {
			$fc_url_core        = ATORA_LMS_MODULES_URL . 'crm-v2/assets/fullcalendar/core.global.min.js';
			$fc_url_daygrid     = ATORA_LMS_MODULES_URL . 'crm-v2/assets/fullcalendar/daygrid.global.min.js';
			$fc_url_timegrid    = ATORA_LMS_MODULES_URL . 'crm-v2/assets/fullcalendar/timegrid.global.min.js';
			$fc_url_list        = ATORA_LMS_MODULES_URL . 'crm-v2/assets/fullcalendar/list.global.min.js';
			$fc_url_interaction = ATORA_LMS_MODULES_URL . 'crm-v2/assets/fullcalendar/interaction.global.min.js';
			$fc_url_locale      = ATORA_LMS_MODULES_URL . 'crm-v2/assets/fullcalendar/es.global.min.js';
		} else {
			$fc_url_core        = $fc_base . 'core@' . $fc_ver . '/index.global.min.js';
			$fc_url_daygrid     = $fc_base . 'daygrid@' . $fc_ver . '/index.global.min.js';
			$fc_url_timegrid    = $fc_base . 'timegrid@' . $fc_ver . '/index.global.min.js';
			$fc_url_list        = $fc_base . 'list@' . $fc_ver . '/index.global.min.js';
			$fc_url_interaction = $fc_base . 'interaction@' . $fc_ver . '/index.global.min.js';
			$fc_url_locale      = $fc_base . 'core@' . $fc_ver . '/locales/es.global.min.js';
		}

		wp_enqueue_script( 'fullcalendar-core',        $fc_url_core,        array(),                           $fc_ver, true );
		wp_enqueue_script( 'fullcalendar-daygrid',     $fc_url_daygrid,     array( 'fullcalendar-core' ),      $fc_ver, true );
		wp_enqueue_script( 'fullcalendar-timegrid',    $fc_url_timegrid,    array( 'fullcalendar-daygrid' ),   $fc_ver, true );
		wp_enqueue_script( 'fullcalendar-list',        $fc_url_list,        array( 'fullcalendar-core' ),      $fc_ver, true );
		wp_enqueue_script( 'fullcalendar-interaction', $fc_url_interaction, array( 'fullcalendar-core' ),      $fc_ver, true );
		wp_enqueue_script( 'fullcalendar-locale-es',   $fc_url_locale,      array( 'fullcalendar-core' ),      $fc_ver, true );

		// ── CSS Calendario (Fase 2) ────────────────────────────────────────
		if ( file_exists( ATORA_LMS_MODULES_DIR . 'crm-v2/assets/crm-calendar.css' ) ) {
			wp_enqueue_style( 'atora-crm-calendar-ui', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-calendar.css', array( 'atora-crm-v2-ui' ), $ver );
		}

		// ── JS Calendario (Fase 2) ─────────────────────────────────────────
		wp_enqueue_script(
			'atora-crm-v2-calendar',
			ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-calendar.js',
			array( 'atora-crm-v2-ui', 'fullcalendar-interaction', 'fullcalendar-list', 'fullcalendar-locale-es' ),
			$ver,
			true
		);

		// ── CSS/JS Planes de seguimiento (PT-4, 6.6.0) — solo en su propia
		// pantalla, reutilizando el mismo pipeline de FullCalendar
		// (handles fullcalendar-*) ya registrado arriba para el
		// calendario CRM — no se vuelve a cargar la librería.
		if ( 'atora-followup-plans' === $page ) {
			if ( file_exists( ATORA_LMS_MODULES_DIR . 'crm-v2/assets/followup-plans.css' ) ) {
				wp_enqueue_style( 'atora-followup-plans-ui', ATORA_LMS_MODULES_URL . 'crm-v2/assets/followup-plans.css', array( 'atora-crm-v2-ui', 'atora-ui-followup-panel' ), $ver );
			}
			wp_enqueue_script(
				'atora-followup-plans',
				ATORA_LMS_MODULES_URL . 'crm-v2/assets/followup-plans.js',
				// PT-1.4 (6.9.0): depende de atora-ui-followup-panel (el
				// AtoraUI.Panel compartido) -- el retrofit del panel de
				// ocurrencia ya no construye su propio marcado de filas.
				array( 'atora-crm-v2-ui', 'atora-ui-followup-panel', 'fullcalendar-interaction', 'fullcalendar-list', 'fullcalendar-locale-es' ),
				$ver,
				true
			);
		}

		// ── CSS Campaign Builder (Fase 3) ──────────────────────────────────
		if ( file_exists( ATORA_LMS_MODULES_DIR . 'crm-v2/assets/crm-campaigns.css' ) ) {
			wp_enqueue_style( 'atora-crm-campaigns-ui', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-campaigns.css', array( 'atora-crm-v2-ui' ), $ver );
		}

		// ── JS Campaign Builder — solo en pantalla de campañas (Fase 3) ───
		if ( false !== strpos( $page, 'campaigns' ) ) {
			if ( file_exists( ATORA_LMS_MODULES_DIR . 'crm-v2/assets/crm-email-builder.css' ) ) {
				wp_enqueue_style( 'atora-crm-email-builder', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-email-builder.css', array( 'atora-crm-v2-ui' ), $ver );
			}
			if ( file_exists( ATORA_LMS_MODULES_DIR . 'crm-v2/assets/crm-campaigns.js' ) ) {
				wp_enqueue_script( 'atora-crm-campaigns', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-campaigns.js', array( 'atora-crm-v2-ui' ), $ver, true );
			}
			if ( file_exists( ATORA_LMS_MODULES_DIR . 'crm-v2/assets/crm-email-builder.js' ) ) {
				wp_enqueue_script( 'atora-crm-email-builder', ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-email-builder.js', array( 'atora-crm-v2-ui' ), $ver, true );
			}
			// Fase II S7 — Editor visual de email
			if ( file_exists( ATORA_LMS_DIR . 'assets/admin/email-editor/email-editor.js' ) ) {
				wp_enqueue_script( 'atora-email-editor', ATORA_LMS_URL . 'assets/admin/email-editor/email-editor.js', array(), $ver, true );
			}
		}

		if ( false !== strpos( $page, 'reports' ) ) {
			wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
		}

		// Fase 4 — JS de la ficha 360 (solo en la pantalla de contactos)
		if ( false !== strpos( $page, 'contacts' ) || 'atora-crm-v2' === $page ) {
			if ( file_exists( ATORA_LMS_MODULES_DIR . 'crm-v2/assets/crm-contact-360.js' ) ) {
				wp_enqueue_script(
					'atora-crm-contact-360',
					ATORA_LMS_MODULES_URL . 'crm-v2/assets/crm-contact-360.js',
					array( 'atora-crm-v2-ui' ),
					$ver,
					true
				);
			}
		}

		// ── Localización unificada (Fases 1-4) ────────────────────────────
		wp_localize_script(
			'atora-crm-v2-ui',
			'atoraCrmV2',
			array(
				'restBase' => esc_url_raw( rest_url( CRM_REST_Controller::REST_NAMESPACE . '/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'adminUrl' => esc_url( admin_url() ),
				'i18n'     => array(
					// ── Kanban / base ──────────────────────────────────────
					'moving'               => __( 'Actualizando tablero...', 'atora-lms' ),
					'saved'                => __( 'Cambio guardado.', 'atora-lms' ),
					'error'                => __( 'No fue posible guardar el cambio.', 'atora-lms' ),
					// Fase 4 — Ficha 360 accionable
					'noteRequired'  => __( 'La nota no puede estar vacía.', 'atora-lms' ),
					'tagRequired'   => __( 'Escribe el nombre del tag.', 'atora-lms' ),
					'stageRequired' => __( 'Selecciona la etapa de destino.', 'atora-lms' ),
					'completing'    => __( 'Completando tarea…', 'atora-lms' ),
					'noActivity'    => __( 'Sin actividad registrada.', 'atora-lms' ),
					'noTags'        => __( 'Sin etiquetas', 'atora-lms' ),
					// ── Fase 1 — Sin recargas ──────────────────────────────
					'loading'              => __( 'Procesando…', 'atora-lms' ),
					'saving'               => __( 'Guardando…', 'atora-lms' ),
					'resetting'            => __( 'Restaurando…', 'atora-lms' ),
					'applying'             => __( 'Aplicando segmento…', 'atora-lms' ),
					'deleting'             => __( 'Eliminando…', 'atora-lms' ),
					'refreshing'           => __( 'Actualizando muestra…', 'atora-lms' ),
					'launching'            => __( 'Lanzando campaña…', 'atora-lms' ),
					'running'              => __( 'Ejecutando acción masiva…', 'atora-lms' ),
					'previewUpdated'       => __( 'Muestra actualizada.', 'atora-lms' ),
					'noContacts'           => __( 'Sin contactos para la configuración actual.', 'atora-lms' ),
					'noCampaigns'          => __( 'Aún no hay campañas registradas.', 'atora-lms' ),
					'confirmReset'         => __( '¿Restaurar la configuración base? Perderás el borrador actual.', 'atora-lms' ),
					'confirmDelete'        => __( '¿Eliminar el segmento seleccionado?', 'atora-lms' ),
					'confirmBulk'          => __( '¿Ejecutar la acción masiva "%s" sobre el segmento actual?', 'atora-lms' ),
					'confirmCampaign'      => __( '¿Lanzar la campaña sobre el segmento actual?', 'atora-lms' ),
					'segmentNameRequired'  => __( 'Indica un nombre para el segmento.', 'atora-lms' ),
					'segmentSelectRequired'=> __( 'Selecciona un segmento de la lista.', 'atora-lms' ),
					'campaignNameRequired' => __( 'Indica el nombre interno de la campaña antes de ejecutar.', 'atora-lms' ),
					'bulkSelectRequired'   => __( 'Selecciona una acción masiva.', 'atora-lms' ),
					// ── Fase 2 — Calendario ────────────────────────────────
					'rescheduling'         => __( 'Reprogramando tarea…', 'atora-lms' ),
					'completing'           => __( 'Completando tarea…', 'atora-lms' ),
					'creating'             => __( 'Creando…', 'atora-lms' ),
					'createTask'           => __( 'Crear tarea', 'atora-lms' ),
					'completeTask'         => __( 'Marcar completada', 'atora-lms' ),
					'viewContact'          => __( 'Ver contacto', 'atora-lms' ),
					'viewCampaigns'        => __( 'Gestionar campañas', 'atora-lms' ),
					'titleRequired'        => __( 'Indica el título de la tarea.', 'atora-lms' ),
					'fcNotLoaded'          => __( 'FullCalendar no cargado. Verifica la conexión a internet.', 'atora-lms' ),
					// ── Fase 3 — Campaign Builder ──────────────────────────
					'nameRequired'         => __( 'Indica el nombre de la campaña.', 'atora-lms' ),
					'subjectRequired'      => __( 'El asunto es obligatorio.', 'atora-lms' ),
					'messageRequired'      => __( 'El mensaje no puede estar vacío.', 'atora-lms' ),
					'testEmailRequired'    => __( 'Indica el email de prueba.', 'atora-lms' ),
					'sending'              => __( 'Enviando…', 'atora-lms' ),
					'testSent'             => __( 'Email de prueba enviado.', 'atora-lms' ),
					'launched'             => __( 'Campaña lanzada.', 'atora-lms' ),
					'cloning'              => __( 'Clonando campaña…', 'atora-lms' ),
					'cloned'               => __( 'Campaña clonada.', 'atora-lms' ),
					'paused'               => __( 'Campaña pausada.', 'atora-lms' ),
					'allStatuses'          => __( 'Todos los estados', 'atora-lms' ),
					'emailEst'             => __( 'con email', 'atora-lms' ),
					'showPreview'          => __( 'Mostrar preview', 'atora-lms' ),
					'hidePreview'          => __( 'Ocultar preview', 'atora-lms' ),
					'ctaLabel'             => __( 'Ver más', 'atora-lms' ),
					'previewNoSubject'     => __( '(Sin asunto)', 'atora-lms' ),
					'previewNoBody'        => __( '(Sin mensaje)', 'atora-lms' ),
					'modeQueue'            => __( 'encolar para envío real', 'atora-lms' ),
					'modeSimulate'         => __( 'simular sin envíos', 'atora-lms' ),
					'confirmLaunch'        => __( '¿Lanzar la campaña en modo: %s?', 'atora-lms' ),
					'confirmPause'         => __( '¿Pausar esta campaña?', 'atora-lms' ),
					'queued'               => __( 'Encolados', 'atora-lms' ),
					'simulated'            => __( 'Simulados', 'atora-lms' ),
					'skipped'              => __( 'Omitidos', 'atora-lms' ),
					'summaryName'          => __( 'Nombre', 'atora-lms' ),
					'summarySubject'       => __( 'Asunto', 'atora-lms' ),
					'summaryAudience'      => __( 'Estado contacto', 'atora-lms' ),
					'summaryTag'           => __( 'Tag', 'atora-lms' ),
					'summarySearch'        => __( 'Búsqueda', 'atora-lms' ),
					'summaryChannel'       => __( 'Canal', 'atora-lms' ),
					'summaryIdentity'      => __( 'Identidad', 'atora-lms' ),
					'summaryMode'          => __( 'Modo', 'atora-lms' ),
					'summaryScheduled'     => __( 'Programada', 'atora-lms' ),
					'loadError'            => __( 'Error al cargar campañas.', 'atora-lms' ),
				),
			)
		);
	}

	/**
	 * Renderiza una pantalla de CRM v2.
	 *
	 * @param string $page_slug Slug actual.
	 * @return void
	 */
	public static function render_page( string $page_slug = 'atora-crm-v2' ): void {
		self::load_dependencies();

		if ( ! self::is_enabled() ) {
			wp_die( esc_html__( 'CRM v2 está desactivado. Activa el feature flag para continuar.', 'atora-lms' ) );
		}

		if ( ! self::can_access() ) {
			wp_die( esc_html__( 'No tienes permisos para acceder al CRM.', 'atora-lms' ) );
		}

		$screen = self::resolve_screen_key( $page_slug );
		if ( 'hub' === $screen && self::can_manage() && self::has_academic_context() ) {
			$screen = 'hub_selector';
		}
		$entry_redirect = self::maybe_redirect_entry_screen( $screen );
		if ( '' !== $entry_redirect ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $entry_redirect ) );
			exit;
		}

		$notice = self::handle_form_actions( $screen );

		$nav_items = self::get_nav_items_for_screen( $screen );
		$overview  = Report_Service::get_overview();
		$context   = array(
			'screen'       => $screen,
			'nav_items'    => $nav_items,
			'nav_group'    => self::get_nav_group_for_screen( $screen ),
			'overview'     => $overview,
			'notice'       => $notice,
			'channels'     => self::get_channel_state(),
			'is_admin'     => self::can_manage(),
			'page_url'     => admin_url( 'admin.php?page=' . $page_slug ),
		);

		switch ( $screen ) {
			case 'hub':
				$context['sales']    = Deal_Service::get_summary();
				$context['academic'] = array(
					'risk_students'  => Student_Followup_Service::count_risk_students(),
					'overdue_tasks'  => Task_Service::count_overdue(),
					'upcoming_tasks' => Task_Service::count_upcoming(),
				);
				$context['tasks'] = Task_Service::list_tasks(
					array(
						'status'         => 'pending',
						'limit'          => 8,
						'scope_user_ids' => self::get_scope_user_ids_or_null(),
					)
				);
				break;

			case 'hub_selector':
				$context['hub_options'] = self::get_entry_hub_options();
				break;

			case 'hub_comercial':
				$context['kpis'] = array(
					'leads_active'    => Contact_Service::count_contacts( array( 'status' => 'lead' ) ),
					'pipeline_value'  => (float) ( Deal_Service::get_summary()['pipeline_value'] ?? 0 ),
					'conversion_rate' => self::compute_conversion_rate_30d(),
					'overdue_tasks'   => Task_Service::count_overdue(),
				);
				$context['mini_board']       = self::filter_board_by_scope( Deal_Service::get_mini_board( array( 'stages' => array( 'new_lead', 'contacted', 'interested', 'proposal_sent', 'payment_pending' ) ) ) );
				$context['urgent_tasks']     = Task_Service::list_tasks( array( 'status' => 'pending', 'scope' => 'commercial', 'limit' => 5, 'scope_user_ids' => self::get_scope_user_ids_or_null() ) );
				$context['recent_campaigns'] = Campaign_Service::get_campaigns( 3 );
				break;

			case 'hub_academico':
				$context['kpis'] = array(
					'risk_students'   => Student_Followup_Service::count_risk_students(),
					'grading_pending' => Task_Service::count_by_type( 'grading' ),
					'completion_rate' => Student_Followup_Service::compute_completion_rate(),
					'stale_followups' => Student_Followup_Service::count_stale( 7 ),
				);
				$context['mini_board']     = self::filter_board_by_scope( Student_Followup_Service::get_mini_board( array( 'stages' => array( 'new_enrolled', 'active', 'at_risk', 'intervention' ) ) ) );
				$context['top_risk']       = CRM_REST_Controller::filter_rows_by_scope( Student_Followup_Service::get_top_risk( 5 ) );
				$context['grading_tasks']  = Task_Service::list_tasks( array( 'task_type' => 'grading', 'status' => 'pending', 'limit' => 5, 'scope_user_ids' => self::get_scope_user_ids_or_null() ) );
				break;

			case 'pipeline_sales':
				$context['board'] = self::filter_board_by_scope( Deal_Service::get_board() );
				break;

			case 'pipeline_academic':
				$context['board'] = self::filter_board_by_scope( Student_Followup_Service::get_board() );
				break;

			case 'inbox':
				$context['inbox'] = self::get_inbox_snapshot();
				$context['identity_options'] = self::get_identity_options();
				break;

			case 'contacts':
				$contact_id = absint( $_GET['contact_id'] ?? 0 );
				$context['filters'] = array(
					'search' => sanitize_text_field( (string) ( $_GET['s'] ?? '' ) ),
					'status' => sanitize_key( (string) ( $_GET['status'] ?? '' ) ),
					'tag'    => sanitize_text_field( (string) ( $_GET['tag'] ?? '' ) ),
				);
				$context['contacts'] = Contact_Service::list_contacts(
					array(
						'search'   => $context['filters']['search'],
						'status'   => $context['filters']['status'],
						'tag'      => $context['filters']['tag'],
						'page'     => absint( $_GET['paged'] ?? 1 ),
						'per_page' => 20,
					)
				);
				$context['contact_360'] = ( $contact_id > 0 && CRM_REST_Controller::contact_id_is_visible( $contact_id ) )
					? Contact_Service::get_contact_360( $contact_id )
					: array();
				break;

			case 'calendar':
				$context['tasks'] = Task_Service::list_tasks(
					array(
						'status'         => sanitize_key( (string) ( $_GET['task_status'] ?? '' ) ),
						'limit'          => 120,
						'scope_user_ids' => self::get_scope_user_ids_or_null(),
					)
				);
				$context['task_types'] = Task_Service::get_task_types();
				$context['upcoming_campaigns'] = class_exists( '\\ATORA\\CRM_V2\\Services\\Campaign_Service' )
					? Campaign_Service::get_upcoming_campaigns( 40 )
					: array();
				break;

			case 'campaigns':
				CRM_Email_Service::sync_tracking_to_recipients();
				$context['templates'] = Campaign_Service::get_templates();
				$context['campaigns'] = Campaign_Service::get_campaigns( 30 );
				$context['identity_options'] = self::get_identity_options();
				break;

			case 'reports':
			case 'reports_comercial':
			case 'reports_academico':
				CRM_Email_Service::sync_tracking_to_recipients();
				$context['sales_report']    = Report_Service::get_sales_report();
				$context['academic_report'] = Report_Service::get_academic_report();
				$context['dashboard_data']  = Report_Service::get_dashboard_data();
				break;

			case 'settings':
				$context['settings_hub_url'] = admin_url( 'admin.php?page=clms-settings-hub' );
				$context['custom_fields']    = Contact_Service::get_custom_fields();
				break;

			case 'duplicates':
				$context['duplicates'] = Contact_Service::find_duplicates( 50 );
				break;
		}

		$view_file = __DIR__ . '/views/' . self::get_view_for_screen( $screen );
		if ( ! file_exists( $view_file ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'CRM v2', 'atora-lms' ) . '</h1><p>' . esc_html__( 'Vista no disponible.', 'atora-lms' ) . '</p></div>';
			return;
		}

		require $view_file;
	}

	/**
	 * Configuración habilitada.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'is_crm_v2_enabled' ) ) {
			return (bool) \CLMS_Settings::is_crm_v2_enabled();
		}
		return ! empty( get_option( 'clms_crm_v2_enabled', false ) );
	}

	/**
	 * Permiso acceso CRM.
	 *
	 * @return bool
	 */
	public static function can_access(): bool {
		if ( current_user_can( 'manage_options' ) || ( function_exists( 'is_super_admin' ) && is_super_admin() ) ) {
			return true;
		}

		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'can_access_crm' ) ) {
			return (bool) \ATORA\CRM\CRM::can_access_crm( get_current_user_id() );
		}

		return false;
	}

	/**
	 * Permiso gestión avanzada.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'can_manage_crm' ) ) {
			return (bool) \ATORA\CRM\CRM::can_manage_crm( get_current_user_id() );
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Mapa de navegación principal.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_nav_items(): array {
		return array(
			'hub'                => array( 'slug' => 'atora-crm-v2',                   'label' => __( 'Hub CRM', 'atora-lms' ) ),
			'hub_selector'       => array( 'slug' => 'atora-crm-v2',                   'label' => __( 'Seleccionar hub', 'atora-lms' ) ),
			'hub_comercial'      => array( 'slug' => 'atora-crm-comercial',           'label' => __( 'Hub comercial', 'atora-lms' ) ),
			'pipeline_sales'     => array( 'slug' => 'atora-crm-v2-pipeline-sales',    'label' => __( 'Pipeline de ventas', 'atora-lms' ) ),
			'inbox'              => array( 'slug' => 'atora-crm-v2-inbox',             'label' => __( 'Bandeja', 'atora-lms' ) ),
			'contacts'           => array( 'slug' => 'atora-crm-v2-contacts',          'label' => __( 'Contactos', 'atora-lms' ) ),
			'campaigns'          => array( 'slug' => 'atora-crm-v2-campaigns',         'label' => __( 'Campañas', 'atora-lms' ) ),
			'reports_comercial'  => array( 'slug' => 'atora-crm-v2-reports',           'label' => __( 'Reportes', 'atora-lms' ) ),
			'hub_academico'      => array( 'slug' => 'atora-crm-academico',           'label' => __( 'Hub académico', 'atora-lms' ) ),
			'pipeline_academic'  => array( 'slug' => 'atora-crm-v2-pipeline-academic', 'label' => __( 'Acompañamiento', 'atora-lms' ) ),
			'calendar'           => array( 'slug' => 'atora-crm-v2-calendar',          'label' => __( 'Calendario', 'atora-lms' ) ),
			'reports_academico'  => array( 'slug' => 'atora-crm-academico-reports',    'label' => __( 'Reportes académicos', 'atora-lms' ) ),
			'reports'            => array( 'slug' => 'atora-crm-v2-reports',           'label' => __( 'Reportes', 'atora-lms' ) ),
			'settings'           => array( 'slug' => 'atora-crm-v2-settings',          'label' => __( 'Configuración', 'atora-lms' ) ),
			'duplicates'         => array( 'slug' => 'atora-crm-v2-duplicates',        'label' => __( 'Duplicados', 'atora-lms' ) ),
		);
	}

	/**
	 * Determina vista según pantalla.
	 *
	 * @param string $screen Screen key.
	 * @return string
	 */
	private static function get_view_for_screen( string $screen ): string {
		switch ( $screen ) {
			case 'pipeline_sales':
				return 'pipeline-sales.php';
			case 'hub_selector':
				return 'hub.php';
			case 'hub_comercial':
				return 'hub-comercial.php';
			case 'hub_academico':
				return 'hub-academico.php';
			case 'pipeline_academic':
				return 'pipeline-academic.php';
			case 'inbox':
				return 'inbox.php';
			case 'contacts':
				return 'contact-360.php';
			case 'calendar':
				return 'calendar.php';
			case 'campaigns':
				return 'campaigns.php';
			case 'reports':
			case 'reports_comercial':
				return 'reports-comercial.php';
			case 'reports_academico':
				return 'reports-academico.php';
			case 'duplicates':
				return 'contact-duplicates.php';
			case 'settings':
				return 'settings.php';
			default:
				return 'hub.php';
		}
	}

	/**
	 * Resuelve clave interna de pantalla.
	 *
	 * @param string $page_slug Page slug.
	 * @return string
	 */
	private static function resolve_screen_key( string $page_slug ): string {
		$slug_to_screen = array(
			'atora-crm-v2'                    => 'hub',
			'atora-crm-comercial'             => 'hub_comercial',
			'atora-crm-academico'             => 'hub_academico',
			'atora-crm-v2-pipeline-sales'     => 'pipeline_sales',
			'atora-crm-v2-pipeline-academic'  => 'pipeline_academic',
			'atora-crm-v2-inbox'              => 'inbox',
			'atora-crm-v2-contacts'           => 'contacts',
			'atora-crm-v2-calendar'           => 'calendar',
			'atora-crm-v2-campaigns'          => 'campaigns',
			'atora-crm-v2-reports'            => 'reports',
			'atora-crm-academico-reports'     => 'reports_academico',
			'atora-crm-v2-settings'           => 'settings',
			'atora-crm-v2-duplicates'         => 'duplicates',
		);

		$page_slug = sanitize_key( $page_slug );
		return $slug_to_screen[ $page_slug ] ?? 'hub';
	}

	/**
	 * Maneja acciones POST de UI.
	 *
	 * @param string $screen Pantalla activa.
	 * @return array{type:string,message:string}
	 */
	private static function handle_form_actions( string $screen ): array {
		if ( 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return array( 'type' => '', 'message' => '' );
		}

		$action = sanitize_key( (string) ( $_POST['atora_crm_v2_form_action'] ?? '' ) );
		$nonce  = sanitize_text_field( (string) ( $_POST['atora_crm_v2_nonce'] ?? '' ) );

		if ( '' === $action || ! wp_verify_nonce( $nonce, 'atora_crm_v2_ui_action' ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'No se pudo validar la acción. Recarga la pantalla e inténtalo de nuevo.', 'atora-lms' ),
			);
		}

		switch ( $action ) {
			case 'create_lead_quick':
				$contact_id = Contact_Service::create_lead_quick(
					array(
						'name'     => sanitize_text_field( (string) ( $_POST['lead_name'] ?? '' ) ),
						'email'    => sanitize_email( (string) ( $_POST['lead_email'] ?? '' ) ),
						'interest' => sanitize_text_field( (string) ( $_POST['lead_interest'] ?? '' ) ),
					)
				);

				return array(
					'type'    => $contact_id > 0 ? 'success' : 'error',
					'message' => $contact_id > 0 ? __( 'Lead creado correctamente.', 'atora-lms' ) : __( 'No se pudo crear el lead rápido.', 'atora-lms' ),
				);

			case 'create_task':
				$task_contact_id = absint( $_POST['task_contact_id'] ?? 0 );
				$task_user_id    = absint( $_POST['task_user_id'] ?? 0 );
				if ( $task_contact_id > 0 && ! CRM_REST_Controller::contact_id_is_visible( $task_contact_id ) ) {
					return array(
						'type'    => 'error',
						'message' => __( 'No tienes permisos para este contacto.', 'atora-lms' ),
					);
				}
				if ( $task_user_id > 0 && ! CRM_REST_Controller::user_id_is_visible( $task_user_id ) ) {
					return array(
						'type'    => 'error',
						'message' => __( 'No tienes permisos para este usuario.', 'atora-lms' ),
					);
				}

				$task_id = Task_Service::create_task(
					array(
						'title'       => sanitize_text_field( (string) ( $_POST['task_title'] ?? '' ) ),
						'contact_id'  => $task_contact_id,
						'user_id'     => $task_user_id,
						'task_type'   => sanitize_key( (string) ( $_POST['task_type'] ?? 'followup_email' ) ),
						'priority'    => sanitize_key( (string) ( $_POST['task_priority'] ?? 'medium' ) ),
						'due_at'      => sanitize_text_field( (string) ( $_POST['task_due_at'] ?? '' ) ),
						'assigned_to' => absint( $_POST['task_assigned_to'] ?? get_current_user_id() ),
						'notes'       => sanitize_textarea_field( (string) ( $_POST['task_notes'] ?? '' ) ),
					)
				);
				return array(
					'type'    => $task_id > 0 ? 'success' : 'error',
					'message' => $task_id > 0
						? __( 'Tarea creada correctamente.', 'atora-lms' )
						: __( 'No se pudo crear la tarea.', 'atora-lms' ),
				);

			case 'complete_task':
				$task_id = absint( $_POST['task_id'] ?? 0 );
				if ( ! CRM_REST_Controller::task_id_is_visible( $task_id ) ) {
					return array(
						'type'    => 'error',
						'message' => __( 'No tienes permisos para esta tarea.', 'atora-lms' ),
					);
				}

				$done = Task_Service::complete_task( $task_id );
				return array(
					'type'    => $done ? 'success' : 'error',
					'message' => $done
						? __( 'Tarea marcada como completada.', 'atora-lms' )
						: __( 'No fue posible completar la tarea.', 'atora-lms' ),
				);

			case 'create_campaign':
				$campaign_id = Campaign_Service::create_campaign(
					array(
						'name'           => sanitize_text_field( (string) ( $_POST['campaign_name'] ?? '' ) ),
						'template_key'   => sanitize_key( (string) ( $_POST['campaign_template'] ?? 'lead_welcome' ) ),
						'channel'        => sanitize_key( (string) ( $_POST['campaign_channel'] ?? 'email' ) ),
						'identity'       => sanitize_key( (string) ( $_POST['campaign_identity'] ?? '' ) ),
						'subject'        => sanitize_text_field( (string) ( $_POST['campaign_subject'] ?? '' ) ),
						'message'        => sanitize_textarea_field( (string) ( $_POST['campaign_message'] ?? '' ) ),
						'cta_url'        => esc_url_raw( (string) ( $_POST['campaign_cta_url'] ?? '' ) ),
						'execution_mode' => sanitize_key( (string) ( $_POST['campaign_execution_mode'] ?? 'simulate' ) ),
						'scheduled_at'   => sanitize_text_field( (string) ( $_POST['campaign_scheduled_at'] ?? '' ) ),
						'search'         => sanitize_text_field( (string) ( $_POST['campaign_search'] ?? '' ) ),
						'status'         => sanitize_key( (string) ( $_POST['campaign_status_filter'] ?? '' ) ),
						'tag'            => sanitize_text_field( (string) ( $_POST['campaign_tag_filter'] ?? '' ) ),
						'course_id'      => absint( $_POST['campaign_course_id'] ?? 0 ),
					)
				);
				return array(
					'type'    => $campaign_id > 0 ? 'success' : 'error',
					'message' => $campaign_id > 0
						? __( 'Campaña guardada. Puedes simular o lanzar en cola.', 'atora-lms' )
						: __( 'No se pudo guardar la campaña.', 'atora-lms' ),
				);

			case 'launch_campaign':
				$result = Campaign_Service::launch_campaign(
					absint( $_POST['campaign_id'] ?? 0 ),
					sanitize_key( (string) ( $_POST['campaign_launch_mode'] ?? 'simulate' ) )
				);
				return array(
					'type'    => ! empty( $result['success'] ) ? 'success' : 'error',
					'message' => sanitize_text_field( (string) ( $result['message'] ?? __( 'No se pudo ejecutar la campaña.', 'atora-lms' ) ) ),
				);

			case 'send_campaign_test':
				$sent = Campaign_Service::send_test(
					sanitize_email( (string) ( $_POST['test_email'] ?? '' ) ),
					sanitize_text_field( (string) ( $_POST['campaign_subject'] ?? '' ) ),
					sanitize_textarea_field( (string) ( $_POST['campaign_message'] ?? '' ) ),
					esc_url_raw( (string) ( $_POST['campaign_cta_url'] ?? '' ) ),
					sanitize_key( (string) ( $_POST['campaign_identity'] ?? '' ) )
				);
				return array(
					'type'    => $sent ? 'success' : 'error',
					'message' => $sent
						? __( 'Prueba aceptada por el proveedor. Verifica bandeja (y spam) y revisa el estado en CRM.', 'atora-lms' )
						: __( 'No se pudo enviar el email de prueba.', 'atora-lms' ),
				);

			case 'reply_inbox_email':
				$conversation_id = absint( $_POST['conversation_id'] ?? 0 );
				$contact_id      = absint( $_POST['contact_id'] ?? 0 );
				if ( $conversation_id > 0 && ! CRM_REST_Controller::conversation_id_is_visible( $conversation_id ) ) {
					return array(
						'type'    => 'error',
						'message' => __( 'No tienes permisos para responder esta conversación.', 'atora-lms' ),
					);
				}
				if ( $contact_id > 0 && ! CRM_REST_Controller::contact_id_is_visible( $contact_id ) ) {
					return array(
						'type'    => 'error',
						'message' => __( 'No tienes permisos para responder este contacto.', 'atora-lms' ),
					);
				}

				$reply_result = CRM_Email_Service::enqueue_inbox_reply(
					sanitize_email( (string) ( $_POST['reply_email'] ?? '' ) ),
					sanitize_text_field( (string) ( $_POST['reply_subject'] ?? '' ) ),
					sanitize_textarea_field( (string) ( $_POST['reply_message'] ?? '' ) ),
					sanitize_key( (string) ( $_POST['reply_identity'] ?? 'teacher' ) ),
					array(
						'contact_id'      => $contact_id,
						'conversation_id' => $conversation_id,
					)
				);

				return array(
					'type'    => ! empty( $reply_result['success'] ) ? 'success' : 'error',
					'message' => sanitize_text_field(
						(string) (
							$reply_result['message']
							?? __( 'No fue posible responder el inbox.', 'atora-lms' )
						)
					),
				);

			case 'save_contact_note':
				$note_contact_id = absint( $_POST['note_contact_id'] ?? 0 );
				if ( ! CRM_REST_Controller::contact_id_is_visible( $note_contact_id ) ) {
					return array(
						'type'    => 'error',
						'message' => __( 'No tienes permisos para este contacto.', 'atora-lms' ),
					);
				}

				$saved = Contact_Service::save_note(
					$note_contact_id,
					sanitize_textarea_field( (string) ( $_POST['note_content'] ?? '' ) ),
					get_current_user_id()
				);
				return array(
					'type'    => $saved ? 'success' : 'error',
					'message' => $saved
						? __( 'Nota guardada en la ficha del contacto.', 'atora-lms' )
						: __( 'No fue posible guardar la nota.', 'atora-lms' ),
				);

			case 'save_custom_field_definition':
				$saved = Contact_Service::save_custom_field_definition(
					array(
						'field_key'   => sanitize_key( (string) ( $_POST['field_key'] ?? '' ) ),
						'label'       => sanitize_text_field( (string) ( $_POST['field_label'] ?? '' ) ),
						'field_type'  => sanitize_key( (string) ( $_POST['field_type'] ?? 'text' ) ),
						'options'     => sanitize_textarea_field( (string) ( $_POST['field_options'] ?? '' ) ),
						'is_required' => ! empty( $_POST['field_required'] ),
						'show_in_360' => ! empty( $_POST['field_show_in_360'] ),
						'sort_order'  => absint( $_POST['field_sort_order'] ?? 0 ),
					)
				);

				return array(
					'type'    => $saved ? 'success' : 'error',
					'message' => $saved ? __( 'Campo personalizado guardado.', 'atora-lms' ) : __( 'No se pudo guardar el campo personalizado.', 'atora-lms' ),
				);
		}

		return array( 'type' => '', 'message' => '' );
	}

	/**
	 * Snapshot básico de inbox.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_inbox_snapshot(): array {
		global $wpdb;

		$conv_table = $wpdb->prefix . 'atora_conversations';
		$msg_table  = $wpdb->prefix . 'atora_conversation_messages';
		if ( ! DB_Service::table_exists( $conv_table ) || ! DB_Service::table_exists( $msg_table ) ) {
			return array(
				'conversations' => array(),
				'messages'      => array(),
				'selected'      => array(),
			);
		}

		$selected_id = absint( $_GET['conversation_id'] ?? 0 );
		$scope_user_ids = CRM_REST_Controller::get_scope_user_ids();
		$conversations_sql = "SELECT c.*, ct.name AS contact_name, ct.email AS contact_email
			 FROM {$conv_table} c
			 LEFT JOIN {$wpdb->prefix}atora_contacts ct ON ct.id = c.contact_id";
		$params = array();
		if ( ! CRM_REST_Controller::has_global_scope() ) {
			$scope_user_ids = array_values( array_filter( array_map( 'absint', $scope_user_ids ) ) );
			if ( empty( $scope_user_ids ) ) {
				return array(
					'conversations' => array(),
					'messages'      => array(),
					'selected'      => array(),
				);
			}
			$in = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
			$conversations_sql .= " WHERE (c.user_id IN ({$in}) OR ct.user_id IN ({$in}))";
			foreach ( $scope_user_ids as $scope_user_id ) {
				$params[] = $scope_user_id;
			}
			foreach ( $scope_user_ids as $scope_user_id ) {
				$params[] = $scope_user_id;
			}
		}
		$conversations_sql .= ' ORDER BY c.last_message_at DESC, c.updated_at DESC LIMIT 80';
		$conversations = (array) (
			empty( $params )
				? $wpdb->get_results( $conversations_sql, ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				: $wpdb->get_results( $wpdb->prepare( $conversations_sql, ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! $selected_id && ! empty( $conversations ) ) {
			$selected_id = absint( $conversations[0]['id'] ?? 0 );
		}

		$messages = array();
		$selected = array();
		if ( $selected_id ) {
			if ( ! CRM_REST_Controller::conversation_id_is_visible( $selected_id ) ) {
				$selected_id = 0;
			}
		}

		if ( $selected_id ) {
			$messages = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$msg_table} WHERE conversation_id = %d ORDER BY created_at ASC LIMIT 250",
					$selected_id
				),
				ARRAY_A
			);

			foreach ( $conversations as $conversation ) {
				if ( absint( $conversation['id'] ?? 0 ) === $selected_id ) {
					$selected = $conversation;
					break;
				}
			}
		}

		return array(
			'conversations' => $conversations,
			'messages'      => $messages,
			'selected'      => $selected,
		);
	}

	/**
	 * Scope de usuarios visibles para consumo de servicios.
	 *
	 * `null` = alcance global.
	 *
	 * @return array<int,int>|null
	 */
	private static function get_scope_user_ids_or_null() {
		if ( CRM_REST_Controller::has_global_scope() ) {
			return null;
		}

		return CRM_REST_Controller::get_scope_user_ids();
	}

	/**
	 * Filtra un board por alcance del usuario.
	 *
	 * @param array<string,mixed> $board Board CRM.
	 * @return array<string,mixed>
	 */
	private static function filter_board_by_scope( array $board ): array {
		$items = (array) ( $board['items'] ?? array() );
		foreach ( $items as $stage => $rows ) {
			$items[ $stage ] = CRM_REST_Controller::filter_rows_by_scope( (array) $rows, 'user_id', 'contact_id' );
		}
		$board['items'] = $items;

		return $board;
	}

	/**
	 * Determina grupo de navegación.
	 *
	 * @param string $screen Pantalla.
	 * @return string
	 */
	private static function get_nav_group_for_screen( string $screen ): string {
		if ( in_array( $screen, array( 'hub_academico', 'pipeline_academic', 'calendar', 'reports_academico' ), true ) ) {
			return 'academic';
		}

		if ( in_array( $screen, array( 'hub_selector', 'hub' ), true ) ) {
			return 'selector';
		}

		return 'commercial';
	}

	/**
	 * Filtra navegación por grupo.
	 *
	 * @param string $screen Pantalla.
	 * @return array<string,array<string,string>>
	 */
	private static function get_nav_items_for_screen( string $screen ): array {
		$items = self::get_nav_items();
		$group = self::get_nav_group_for_screen( $screen );

		if ( 'selector' === $group ) {
			return array(
				'hub_comercial' => $items['hub_comercial'],
				'hub_academico' => $items['hub_academico'],
			);
		}

		if ( 'academic' === $group ) {
			return array(
				'hub_academico'     => $items['hub_academico'],
				'pipeline_academic' => $items['pipeline_academic'],
				'calendar'          => $items['calendar'],
				'contacts'          => $items['contacts'],
				'reports_academico' => $items['reports_academico'],
				'settings'          => $items['settings'],
			);
		}

		$nav = array(
			'hub_comercial'  => $items['hub_comercial'],
			'pipeline_sales' => $items['pipeline_sales'],
			'inbox'          => $items['inbox'],
			'contacts'       => $items['contacts'],
		);

		if ( current_user_can( 'crm_manage_campaigns' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' ) ) {
			$nav['campaigns'] = $items['campaigns'];
		}

		if ( current_user_can( 'crm_view_reports' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' ) ) {
			$nav['reports_comercial'] = $items['reports_comercial'];
		}

		if ( self::can_manage() ) {
			$nav['duplicates'] = $items['duplicates'];
			$nav['settings']   = $items['settings'];
		}

		return $nav;
	}

	/**
	 * Redirige slug base al hub adecuado.
	 *
	 * @param string $screen Pantalla.
	 * @return string
	 */
	private static function maybe_redirect_entry_screen( string $screen ): string {
		if ( 'hub' !== $screen ) {
			return '';
		}

		if ( self::can_manage() && self::has_academic_context() ) {
			return '';
		}

		if ( self::can_manage() ) {
			return 'atora-crm-comercial';
		}

		if ( self::has_academic_context() ) {
			return 'atora-crm-academico';
		}

		return '';
	}

	/**
	 * Opciones del selector de entrada.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function get_entry_hub_options(): array {
		return array(
			array(
				'slug'        => 'atora-crm-comercial',
				'label'       => __( 'Hub comercial', 'atora-lms' ),
				'description' => __( 'Leads, campañas, pipeline y cierres.', 'atora-lms' ),
			),
			array(
				'slug'        => 'atora-crm-academico',
				'label'       => __( 'Hub académico', 'atora-lms' ),
				'description' => __( 'Seguimiento, riesgo, tareas docentes y retención.', 'atora-lms' ),
			),
		);
	}

	/**
	 * Calcula conversión de 30 días.
	 *
	 * @return float
	 */
	public static function compute_conversion_rate_30d(): float {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_deals';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0.0;
		}

		$row = (array) $wpdb->get_row(
			"SELECT
				SUM(stage IN ('won','enrolled')) AS won_total,
				SUM(stage = 'lost') AS lost_total
			 FROM {$table}
			 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$won  = absint( $row['won_total'] ?? 0 );
		$lost = absint( $row['lost_total'] ?? 0 );
		return ( $won + $lost ) > 0 ? round( ( $won / ( $won + $lost ) ) * 100, 1 ) : 0.0;
	}

	/**
	 * Determina si el usuario también tiene contexto académico.
	 *
	 * @return bool
	 */
	private static function has_academic_context(): bool {
		return current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'clms_grade_submissions' ) || current_user_can( 'edit_posts' );
	}

	/**
	 * Estado de canales en esta ronda.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_channel_state(): array {
		$email_ready = class_exists( '\\ATORA_Email_Gateway' );
		return array(
			'email' => array(
				'label' => __( 'Email', 'atora-lms' ),
				'ready' => $email_ready,
				'note'  => $email_ready
					? __( 'Canal activo con Email Engine y cola.', 'atora-lms' )
					: __( 'Canal no disponible: revisar Email Engine.', 'atora-lms' ),
			),
			'whatsapp' => array(
				'label' => __( 'WhatsApp', 'atora-lms' ),
				'ready' => false,
				'note'  => __( 'Canal preparado para integración futura. API no activa en esta ronda.', 'atora-lms' ),
			),
			'telegram' => array(
				'label' => __( 'Telegram', 'atora-lms' ),
				'ready' => false,
				'note'  => __( 'Canal preparado para integración futura. API no activa en esta ronda.', 'atora-lms' ),
			),
		);
	}

	/**
	 * Opciones de identidad de envío para UI CRM v2.
	 *
	 * @return array<string,string>
	 */
	private static function get_identity_options(): array {
		if ( ! class_exists( '\\ATORA\\EmailEngine\\Email_Identity_Resolver' ) ) {
			$file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'email-engine/class-email-identity-resolver.php' : '';
			if ( $file && file_exists( $file ) ) {
				require_once $file;
			}
		}
		if ( class_exists( '\\ATORA\\EmailEngine\\Email_Identity_Resolver' ) && method_exists( '\\ATORA\\EmailEngine\\Email_Identity_Resolver', 'get_ui_options' ) ) {
			$options = \ATORA\EmailEngine\Email_Identity_Resolver::get_ui_options();
			return is_array( $options ) ? $options : array();
		}

		return array(
			'academia' => __( 'Academia', 'atora-lms' ),
			'teacher'  => __( 'Docencia', 'atora-lms' ),
			'admin'    => __( 'Comercial', 'atora-lms' ),
		);
	}

	/**
	 * Requiere dependencias internas.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = __DIR__;

		$files = array(
			$base . '/services/class-db-service.php',
			$base . '/services/class-activity-service.php',
			$base . '/services/class-contact-service.php',
			$base . '/services/class-task-service.php',
			$base . '/services/class-deal-service.php',
			$base . '/services/class-student-followup-service.php',
			$base . '/services/class-crm-email-service.php',
			$base . '/services/class-campaign-service.php',
			$base . '/services/class-report-service.php',
			$base . '/rest/class-crm-rest-controller.php',
			$base . '/rest/class-pipeline-rest-controller.php',
			$base . '/rest/class-tasks-rest-controller.php',
			$base . '/rest/class-calendar-rest-controller.php',
			$base . '/rest/class-inbox-rest-controller.php',
			$base . '/rest/class-reports-rest-controller.php',
			$base . '/rest/class-reports-dashboard-rest-controller.php',
			// Fase 4 — Ficha 360 accionable
			$base . '/rest/class-contact-actions-rest-controller.php',
			// Fase 9 — Empresas + Listas
			$base . '/services/class-company-service.php',
			$base . '/services/class-list-service.php',
			$base . '/rest/class-companies-lists-rest-controller.php',
			// Fase 10 — URL Store + Sequence REST + Abandoned Carts
			$base . '/services/class-sequence-service.php',
			$base . '/services/class-url-store-service.php',
			$base . '/rest/class-sequence-rest-controller.php',
			$base . '/rest/class-abandoned-carts-rest-controller.php',
			// PT-1/PT-2/PT-3/PT-4 (6.6.0) — Planes de seguimiento
			// PT-1 (6.7.0): la interfaz de dominio y sus dos
			// implementaciones deben cargarse ANTES que el resolver — los
			// proveedores usan `implements Followup_Domain_Provider`, que
			// PHP resuelve en el momento en que declara la clase.
			$base . '/services/class-followup-domain-provider-interface.php',
			$base . '/services/class-academic-domain-provider.php',
			$base . '/services/class-commercial-domain-provider.php',
			$base . '/services/class-followup-recurrence.php',
			$base . '/services/class-followup-plan-resolver.php',
			$base . '/services/class-followup-plan-service.php',
			$base . '/rest/class-followup-plans-rest-controller.php',
			// Fase 1 — Sin recargas de página
			$base . '/rest/class-crm-draft-rest-controller.php',
			// Fase 2 — Calendario FullCalendar
			$base . '/rest/class-calendar-events-rest-controller.php',
			// Fase 3 — Campaign Builder
			$base . '/rest/class-campaign-builder-rest-controller.php',
		);

		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	public static function flush_crm_scope_cache( int $student_user_id, int $teacher_user_id = 0 ): void {
		if ( $student_user_id > 0 ) {
			delete_transient( 'atora_crm_scope_' . $student_user_id );
		}
		if ( $teacher_user_id > 0 ) {
			delete_transient( 'atora_crm_scope_' . $teacher_user_id );
		}
	}

	public static function flush_crm_scope_cache_by_user( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_transient( 'atora_crm_scope_' . $user_id );
		}
	}
}
