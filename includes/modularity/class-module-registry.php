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

	/**
	 * Definición declarativa de los 19 módulos del plugin.
	 *
	 * `requires`: otros slugs que deben estar activos para que este lo esté
	 * (usado para bloquear desactivación de un módulo del que otros dependen,
	 * y para cascada de activación).
	 * `provides_pages`: slugs de página admin (menu_slug de add_submenu_page)
	 * que aporta — usados por CLMS_Module_Guard para bloquear acceso directo.
	 * `provides_shortcodes`: tags de shortcode que aporta — usados por
	 * CLMS_Module_Guard para registrar un stub inocuo cuando está inactivo.
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
				'tables'              => array(),
				'core'                => false,
			),
			'email-engine'   => array(
				'label'               => 'Email Engine',
				'description'         => 'Plantillas, colas y envío transaccional.',
				'group'               => 'communication',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-emails' ),
				'provides_shortcodes' => array(),
				'tables'              => array(),
				'core'                => false,
			),
			'newsletter'     => array(
				'label'               => 'Newsletter',
				'description'         => 'Boletín y archivo público de newsletter.',
				'group'               => 'communication',
				'requires'            => array( 'email-engine' ),
				'provides_pages'      => array( 'atora-newsletter' ),
				'provides_shortcodes' => array( 'atora_newsletter_archive' ),
				'tables'              => array(),
				'core'                => false,
			),
			'automation'     => array(
				'label'               => 'Automatización',
				'description'         => 'Webhooks salientes y motor de automatizaciones.',
				'group'               => 'growth',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-webhooks', 'atora-automations' ),
				'provides_shortcodes' => array(),
				'tables'              => array(),
				'core'                => false,
			),
			'messaging'      => array(
				'label'               => 'Mensajería',
				'description'         => 'Integraciones WhatsApp/Telegram (la mensajería in-app básica no se apaga).',
				'group'               => 'communication',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-messaging' ),
				'provides_shortcodes' => array( 'atora_preferencias' ),
				'tables'              => array(),
				'core'                => false,
			),
			'calendar'       => array(
				'label'               => 'Calendario',
				'description'         => 'Eventos, sincronización con calendarios externos.',
				'group'               => 'experience',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-calendar' ),
				'provides_shortcodes' => array( 'atora_calendar' ),
				'tables'              => array(),
				'core'                => false,
			),
			'commerce'       => array(
				'label'               => 'Comercio',
				'description'         => 'Ventas, carritos, checkout.',
				'group'               => 'growth',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'clms-commercial-hub', 'clms-commercial-operations', 'clms-commerce-hub', 'clms-commerce-dashboard' ),
				'provides_shortcodes' => array(),
				'tables'              => array(),
				'core'                => false,
			),
			'affiliates'     => array(
				'label'               => 'Afiliados',
				'description'         => 'Programa de afiliados y comisiones.',
				'group'               => 'growth',
				'requires'            => array( 'commerce' ),
				'provides_pages'      => array( 'atora-affiliates' ),
				'provides_shortcodes' => array( 'atora_affiliate_dashboard', 'atora_affiliate_apply' ),
				'tables'              => array(),
				'core'                => false,
			),
			'analytics'      => array(
				'label'               => 'Analytics',
				'description'         => 'Formularios, popups y motor de analítica (módulos v5; la analítica LMS base no se apaga).',
				'group'               => 'reports',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-analytics-dashboard', 'atora-analytics', 'atora-popups', 'atora-forms' ),
				'provides_shortcodes' => array( 'atora_form' ),
				'tables'              => array(),
				'core'                => false,
			),
			'security'       => array(
				'label'               => 'Seguridad',
				'description'         => 'Registro extendido, CAPTCHA, 2FA. Autenticación base siempre activa.',
				'group'               => 'core',
				'requires'            => array(),
				'provides_pages'      => array( 'atora-security' ),
				'provides_shortcodes' => array(),
				'tables'              => array(),
				'core'                => true,
			),
			'ai'             => array(
				'label'               => 'IA',
				'description'         => 'Copilotos, exámenes con IA, asistente de estudiante.',
				'group'               => 'ai',
				'requires'            => array( 'lms' ),
				'provides_pages'      => array( 'clms-ai-hub', 'clms-ai-settings', 'clms-ai-exams' ),
				'provides_shortcodes' => array(),
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
				'tables'              => array(),
				'core'                => false,
			),
			'webhooks'       => array(
				'label'               => 'Webhooks',
				'description'         => 'Dispatcher de webhooks entrantes/salientes de integración.',
				'group'               => 'integrations',
				'requires'            => array(),
				'provides_pages'      => array(),
				'provides_shortcodes' => array(),
				'tables'              => array(),
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
				'tables'              => array(),
				'core'                => false,
			),
			'mcp'            => array(
				'label'               => 'MCP',
				'description'         => 'API keys y servidor MCP para integraciones con agentes.',
				'group'               => 'integrations',
				'requires'            => array(),
				'provides_pages'      => array(),
				'provides_shortcodes' => array(),
				'tables'              => array(),
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
		$core = array_keys( array_filter( self::get_modules(), static fn( $m ) => ! empty( $m['core'] ) ) );
		return array_values( array_unique( array_merge( $core, array_intersect( $stored, $all ) ) ) );
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
		// fail-open, igual que el fallthrough de module_condition_passes().
		if ( ! isset( $modules[ $slug ] ) ) {
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
