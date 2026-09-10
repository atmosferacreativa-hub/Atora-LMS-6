<?php
/**
 * CLMS_Module_Registry — PT-2 (sprint 6.3.0)
 *
 * Fuente única de verdad sobre qué módulos existen y cuáles están activos.
 * No carga ni descarga nada por sí misma: los tres sistemas de carga del
 * plugin (CLMS_Loader, ATORA\V5_Modules::boot(), y las llamadas sueltas a
 * atora_lms_require_module() en atora_lms.php) la consultan antes de hacer
 * su trabajo. Ver docs/DEUDA-TECNICA.md para el porqué de los tres sistemas.
 *
 * Regla de cero cambio de comportamiento por defecto: si la option
 * `atora_active_modules` nunca se guardó, TODOS los módulos están activos.
 * Los módulos `core` (lms, academic, gradebook, security) nunca pueden
 * desactivarse, sin importar el contenido de la option — is_active() lo
 * garantiza a nivel de código, no solo en la UI del admin.
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Module_Registry {

	const OPTION = 'atora_active_modules';

	/** @var array<string,bool>|null Caché estático por request. */
	private static ?array $active_cache = null;

	/** @var array<string,bool> Slugs desconocidos ya registrados en el log en este request (P4, 6.12.0). */
	private static array $unknown_slug_logged = array();

	/**
	 * Definición declarativa de los módulos del plugin.
	 *
	 * `requires`: otros slugs que deben estar activos para que este lo esté
	 * (usado para bloquear desactivación de un módulo del que otros dependen,
	 * y para cascada de activación).
	 * `provides_pages`: slugs de página admin (menu_slug de add_submenu_page)
	 * que aporta — usados por CLMS_Module_Guard para bloquear acceso directo.
	 * `provides_shortcodes`: tags de shortcode que aporta — usados por
	 * CLMS_Module_Guard para registrar un stub inocuo cuando está inactivo.
	 * `provides_rest`: prefijos de ruta REST (bajo su namespace, sin el
	 * namespace) que aporta — P2 (6.12.0). Documentales: el gate real ya
	 * ocurre en el add_action('rest_api_init', ...) de cada módulo; esto
	 * es lo que `wp atora rest-audit` usa para clasificar una ruta
	 * observada contra el módulo que se espera que la sirva. Una ruta no
	 * cubierta por ningún prefijo queda "sin clasificar" en el audit —
	 * fail-open, se registra igual, igual criterio que is_active().
	 * `core`: si es true, nunca se puede desactivar.
	 *
	 * @return array<string,array>
	 */
	public static function get_modules(): array {
		return array(
			'lms'            => array(
				'label'               => 'LMS',
				'description'         => 'Cursos, lecciones, matrículas — núcleo del plugin.',
				'group'               => 'core',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-lms-migration' ),
				'provides_shortcodes' => array(),
				// C8.2 (6.13.1): /build-probe (health check) y /migration
				// (F1-F4) son core, sin gate de módulo — se clasifican acá
				// para que el audit de rest-audit no los reporte como
				// "sin clasificar" pese a estar correctamente siempre activos.
				'provides_rest'       => array( '/courses', '/lessons', '/enrollments', '/build-probe', '/migration' ),
				'tables'              => array( 'atora_courses', 'atora_lessons', 'atora_enrollments' ),
				'core'                => true,
			),
			'academic'       => array(
				'label'               => 'Académico',
				'description'         => 'Competencias, evidencias, estado académico, planes de mejora.',
				'group'               => 'core',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'clms-academic-hub', 'clms-academic-content', 'clms-instructor-profile', 'clms-academic-wizard', 'clms-academic-reports' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array(),
				'tables'              => array(),
				'core'                => true,
			),
			'groups'         => array(
				'label'               => 'Grupos',
				'description'         => 'Evaluación por grupos (Group Assessment): gestión de grupos, entregas grupales y reportes.',
				'group'               => 'core',
				'requires'            => array( 'lms', 'gradebook' ),
				'provides_pages'      => array( 'atora-groups' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/groups' ),
				'tables'              => array( 'clms_groups', 'clms_group_members', 'clms_group_submissions', 'clms_group_grade_overrides', 'clms_group_audit_log' ),
				'core'                => true,
			),
			'rubrics'        => array(
				'label'               => 'Rúbricas v2',
				'description'         => 'Rúbricas con pesos, escalas y presets reutilizables.',
				'group'               => 'core',
				'requires'            => array( 'lms', 'gradebook' ),
				'provides_pages'      => array(),
				'provides_shortcodes' => array(),
				'provides_rest'       => array(),
				'tables'              => array(),
				'core'                => true,
			),
			'gradebook'      => array(
				'label'               => 'Gradebook',
				'description'         => 'Calificaciones, cálculo, exportación.',
				'group'               => 'core',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'clms-gradebook', 'clms-speedgrader' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array(),
				'tables'              => array( 'atora_gradebook' ),
				'core'                => true,
			),
			'certificates'   => array(
				'label'               => 'Certificados',
				'description'         => 'Emisión y verificación de certificados de curso/programa.',
				'group'               => 'experience',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array(),
				'provides_shortcodes' => array( 'atora_my_certificates' ),
				'provides_rest'       => array(),
				'tables'              => array( 'atora_certificates' ),
				'core'                => false,
			),
			'crm'            => array(
				'label'               => 'CRM',
				'description'         => 'Contactos, pipeline, scoring (incluye CRM v2).',
				'group'               => 'growth',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-crm-v2', 'atora-crm', 'clms-crm-hub' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/contacts', '/companies', '/lists', '/campaigns', '/pipeline', '/tasks', '/inbox', '/reports', '/crm', '/messages', '/followup-plans', '/abandoned-carts', '/sequences', '/url' ),
				'tables'              => array( 'atora_contacts', 'atora_contact_tags', 'atora_contact_activities', 'atora_contact_notes', 'atora_companies', 'atora_crm_lists', 'atora_contact_list_pivot', 'atora_conversations', 'atora_conversation_messages' ),
				'core'                => false,
			),
			'email-engine'   => array(
				'label'               => 'Email Engine',
				'description'         => 'Plantillas, colas y envío transaccional.',
				'group'               => 'communication',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-emails' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/webhooks/brevo', '/webhooks/sendgrid', '/webhooks/mailgun', '/webhooks/ses', '/webhooks/postmark' ),
				'tables'              => array( 'atora_email_queue', 'atora_email_templates', 'atora_email_preferences', 'atora_email_consent_log', 'atora_email_analytics', 'atora_email_events' ),
				'core'                => false,
			),
			'newsletter'     => array(
				'label'               => 'Newsletter',
				'description'         => 'Boletín y archivo público de newsletter.',
				'group'               => 'communication',
				'requires'            => array( 'email-engine' ),
				'provides_pages'      => array( 'atora-newsletter' ),
				'provides_shortcodes' => array( 'atora_newsletter_archive' ),
				'provides_rest'       => array( '/newsletters' ),
				'tables'              => array( 'atora_newsletters' ),
				'core'                => false,
			),
			'automation'     => array(
				'label'               => 'Automatización',
				'description'         => 'Webhooks salientes y motor de automatizaciones.',
				'group'               => 'growth',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-webhooks', 'atora-automations' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/automations' ),
				'tables'              => array( 'atora_automations', 'atora_automation_queue', 'atora_automation_execution_log' ),
				'core'                => false,
			),
			'messaging'      => array(
				'label'               => 'Mensajería',
				'description'         => 'Integraciones WhatsApp/Telegram (la mensajería in-app básica no se apaga).',
				'group'               => 'communication',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-messaging' ),
				'provides_shortcodes' => array( 'atora_preferencias' ),
				'provides_rest'       => array( '/webhooks/whatsapp', '/telegram/webhook' ),
				'tables'              => array( 'atora_message_queue', 'atora_message_log', 'atora_telegram_links' ),
				'core'                => false,
			),
			'calendar'       => array(
				'label'               => 'Calendario',
				'description'         => 'Eventos, sincronización con calendarios externos.',
				'group'               => 'experience',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-calendar' ),
				'provides_shortcodes' => array( 'atora_calendar' ),
				// C8.2 (6.13.1): /calendar/task es de la vista de tareas del
				// calendario (crm-v2/rest/class-calendar-events-rest-controller.php).
				'provides_rest'       => array( '/calendar/events', '/calendar/bookings', '/calendar/task' ),
				'tables'              => array( 'atora_calendar_events', 'atora_calendar_bookings', 'atora_calendar_sync' ),
				'core'                => false,
			),
			'commerce'       => array(
				'label'               => 'Comercio',
				'description'         => 'Ventas, carritos, checkout.',
				'group'               => 'growth',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'clms-commercial-hub', 'clms-commercial-operations', 'clms-commerce-hub', 'clms-commerce-dashboard' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array(),
				'tables'              => array( 'atora_abandoned_carts', 'atora_url_store', 'atora_url_clicks' ),
				'core'                => false,
			),
			'affiliates'     => array(
				'label'               => 'Afiliados',
				'description'         => 'Programa de afiliados y comisiones.',
				'group'               => 'growth',
				'requires'            => array( 'commerce' ),
				'provides_pages'      => array( 'atora-affiliates' ),
				'provides_shortcodes' => array( 'atora_affiliate_dashboard', 'atora_affiliate_apply' ),
				'provides_rest'       => array(),
				'tables'              => array( 'atora_affiliates', 'atora_affiliate_clicks', 'atora_affiliate_commissions' ),
				'core'                => false,
			),
			'analytics'      => array(
				'label'               => 'Analytics',
				'description'         => 'Formularios, popups y motor de analítica (módulos v5; la analítica LMS base no se apaga).',
				'group'               => 'reports',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-analytics-dashboard', 'atora-analytics', 'atora-popups', 'atora-forms' ),
				'provides_shortcodes' => array( 'atora_form' ),
				'provides_rest'       => array( '/analytics' ),
				'tables'              => array( 'atora_user_engagement', 'atora_form_entries', 'atora_form_throttle' ),
				'core'                => false,
			),
			'early-warning'  => array(
				'label'               => 'Early Warning',
				'description'         => 'Alertas tempranas para docentes (riesgo por curso).',
				'group'               => 'reports',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'atora-early-warning' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/early-warning' ),
				'tables'              => array( 'atora_early_warning' ),
				'core'                => true,
			),
			'learning-analytics' => array(
				'label'               => 'Learning Analytics',
				'description'         => 'Scoring de riesgo y snapshots académicos por estudiante/curso.',
				'group'               => 'reports',
				'requires'            => array( 'lms', 'academic' ),
				'provides_pages'      => array( 'atora-learning-analytics' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/learning-analytics' ),
				'tables'              => array( 'atora_student_analytics' ),
				'core'                => true,
			),
			'portfolios'    => array(
				'label'               => 'Portafolios',
				'description'         => 'E-portfolios por estudiante/curso: evidencias, reflexiones y feedback.',
				'group'               => 'experience',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'atora-portfolios' ),
				'provides_shortcodes' => array( 'atora_portfolio', 'atora_portfolio_public' ),
				'provides_rest'       => array( '/portfolios' ),
				'tables'              => array( 'atora_portfolios', 'atora_portfolio_assessments', 'atora_portfolio_items', 'atora_portfolio_feedback' ),
				'core'                => true,
			),
			'classroom'     => array(
				'label'               => 'Google Classroom',
				'description'         => 'Epic 6: mapeo de cursos + sync de rosters y (luego) tareas/notas.',
				'group'               => 'integrations',
				'requires'            => array( 'google', 'lms' ),
				'provides_pages'      => array( 'atora-classroom' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/classroom' ),
				'tables'              => array( 'atora_google_classroom_course_map', 'atora_google_classroom_sync_log' ),
				'core'                => false,
			),
			'security'       => array(
				'label'               => 'Seguridad',
				'description'         => 'Registro extendido, CAPTCHA, 2FA. Autenticación base siempre activa.',
				'group'               => 'core',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-security' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array(),
				'tables'              => array( 'atora_audit_log' ),
				'core'                => true,
			),
			'ai'             => array(
				'label'               => 'IA',
				'description'         => 'Copilotos, exámenes con IA, asistente de estudiante.',
				'group'               => 'ai',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'clms-ai-hub', 'clms-ai-settings', 'clms-ai-exams' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/ai' ),
				'tables'              => array(),
				'core'                => false,
			),
			'live-streaming' => array(
				'label'               => 'Streaming en vivo',
				'description'         => 'Clases en vivo.',
				'group'               => 'experience',
				'requires'            => array(),
				'provides_pages'      => array(),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/webhooks/zoom', '/live' ),
				'tables'              => array( 'atora_live_sessions', 'atora_attendance' ),
				'core'                => false,
			),
			'webhooks'       => array(
				'label'               => 'Webhooks',
				'description'         => 'Dispatcher de webhooks entrantes/salientes de integración.',
				'group'               => 'integrations',
				'requires'            => array(),
				'provides_pages'      => array(),
				'provides_shortcodes' => array(),
				'provides_rest'       => array(),
				'tables'              => array( 'atora_webhooks' ),
				'core'                => false,
			),
			// PT-1/PT-2 (6.10.0): el ranking público competitivo se
			// eliminó por decisión de producto. Lo que queda es
			// individual, no comparativo entre estudiantes: puntos/
			// niveles personales en el panel del propio estudiante, e
			// insignias por curso (sobresaliente/destacado/aplicado/
			// regular/en atención) según puntualidad, promedio e
			// interacción -- sin shortcode ni página propia, se
			// muestran dentro del panel de estudiante ya existente.
			'gamification'   => array(
				'label'               => 'Gamificación',
				'description'         => 'Puntos e insignias individuales del estudiante (sin ranking comparativo) — puntualidad, promedio e interacción por curso.',
				'group'               => 'experience',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array(),
				'provides_shortcodes' => array(),
				'provides_rest'       => array(),
				'tables'              => array( 'clms_badges' ),
				'core'                => false,
			),
			// P10 (6.13.0): identidad, Calendar, Drive con Google — cada
			// instalación crea su propio proyecto en Google Cloud (BYO
			// client ID), ver modules/google/.
			'google'         => array(
				'label'               => 'Google',
				'description'         => 'Login con Google, Meet y Drive (drive.file) — client ID propio por instalación.',
				'group'               => 'integrations',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-google' ),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/google' ),
				'tables'              => array( 'atora_google_drive_files' ),
				'core'                => false,
			),
			'mcp'            => array(
				'label'               => 'MCP',
				'description'         => 'API keys y servidor MCP para integraciones con agentes.',
				'group'               => 'integrations',
				'requires'            => array(),
				'provides_pages'      => array(),
				'provides_shortcodes' => array(),
				'provides_rest'       => array( '/tools', '/openapi.json', '/api-keys' ),
				'tables'              => array( 'atora_api_keys', 'atora_api_rate_limit' ),
				'core'                => false,
			),
		);
	}

	/**
	 * Slugs activos. Sin option guardada (instalación existente o nueva sin
	 * configurar aún) => todos activos, para no cambiar comportamiento por
	 * defecto (regla 3 del sprint).
	 *
	 * @return string[]
	 */
	public static function get_active_slugs(): array {
		$all = array_keys( self::get_modules() );

		$stored = get_option( self::OPTION, false );
		if ( false === $stored || ! is_array( $stored ) ) {
			return $all;
		}

		// Los core siempre están, incluso si faltan en una option corrupta/vieja.
		$core   = array_keys( array_filter( self::get_modules(), static fn( $m ) => ! empty( $m['core'] ) ) );
		$active = array_values( array_unique( array_merge( $core, array_intersect( $stored, $all ) ) ) );

		/**
		 * Filtro de enganche para el sprint de licencias (P4, 6.12.0): permite
		 * restringir el set de módulos activos según el estado de licencia de
		 * la instalación. Se aplica DESPUÉS de la unión con los módulos core
		 * para que un filtro mal escrito no pueda apagar el núcleo. Inerte en
		 * este release — nadie lo engancha todavía.
		 *
		 * @param string[] $active Slugs activos antes del filtro.
		 */
		$filtered = (array) apply_filters( 'atora/profile/allowed_modules', $active );

		return array_values( array_unique( array_merge( $core, array_intersect( $filtered, $all ) ) ) );
	}

	/**
	 * @param string $slug
	 * @return bool
	 */
	public static function is_active( string $slug ): bool {
		$modules = self::get_modules();

		// Los módulos core nunca se pueden apagar, ni siquiera con una option
		// corrupta o manipulada — la opción NO es la fuente de verdad para core.
		if ( isset( $modules[ $slug ] ) && ! empty( $modules[ $slug ]['core'] ) ) {
			return true;
		}

		if ( null === self::$active_cache ) {
			self::$active_cache = array_fill_keys( self::get_active_slugs(), true );
		}

		// Slug desconocido (módulo nuevo aún no registrado, typo, etc.):
		// fail-open, igual que el fallthrough de module_condition_passes(). Se
		// mantiene el fail-open (P4, 6.12.0) pero se deja rastro en el log —
		// un módulo futuro que se active solo en un despliegue institucional
		// auditado debe quedar visible, una vez por request.
		if ( ! isset( $modules[ $slug ] ) ) {
			if ( empty( self::$unknown_slug_logged[ $slug ] ) ) {
				self::$unknown_slug_logged[ $slug ] = true;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[ATORA] CLMS_Module_Registry::is_active() — slug de módulo desconocido: "%s" (fail-open)', $slug ) );
			}
			return true;
		}

		return ! empty( self::$active_cache[ $slug ] );
	}

	/**
	 * Módulos activos que declaran `$slug` en su `requires`. Usado para
	 * bloquear la desactivación de un módulo del que otros dependen.
	 *
	 * @param string $slug
	 * @return string[]
	 */
	public static function get_active_dependents( string $slug ): array {
		$dependents = array();
		foreach ( self::get_modules() as $candidate => $def ) {
			if ( $candidate === $slug ) { continue; }
			if ( in_array( $slug, $def['requires'] ?? array(), true ) && self::is_active( $candidate ) ) {
				$dependents[] = $candidate;
			}
		}
		return $dependents;
	}

	/**
	 * Cierre transitivo de dependencias de `$slug` (incluyéndolo), para
	 * activación en cascada.
	 *
	 * @param string $slug
	 * @return string[]
	 */
	public static function resolve_activation_closure( string $slug ): array {
		$modules = self::get_modules();
		$closure = array();
		$stack   = array( $slug );

		while ( $stack ) {
			$current = array_pop( $stack );
			if ( isset( $closure[ $current ] ) || ! isset( $modules[ $current ] ) ) { continue; }
			$closure[ $current ] = true;
			foreach ( $modules[ $current ]['requires'] ?? array() as $dep ) {
				$stack[] = $dep;
			}
		}

		return array_keys( $closure );
	}

	/**
	 * Invalida la caché estática de is_active(). Llamar tras guardar la
	 * option desde la página de administración para que el mismo request
	 * refleje el nuevo estado.
	 */
	public static function flush_cache(): void {
		self::$active_cache = null;
	}
}
