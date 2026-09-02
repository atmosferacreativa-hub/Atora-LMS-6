<?php
/**
 * ATORA LMS v5 — Orquestador de módulos
 *
 * Carga condicionalmente los módulos nuevos de v5 según el contexto
 * (admin / public / cron) para minimizar la huella de memoria.
 *
 * @package ATORA_LMS
 * @since   5.0.0
 */

namespace ATORA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class V5_Modules
 *
 * @since 5.0.0
 */
class V5_Modules {

	/**
	 * Contexto detectado del request actual.
	 *
	 * @var array<string,bool>|null
	 */
	private static ?array $context = null;

	/**
	 * Arranca todos los módulos v5 con carga condicional según contexto.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$ctx = self::get_context();

		// Siempre activos para seguridad/auth y eventos base. Security es
		// núcleo (no desactivable); affiliates sí es un módulo apagable (PT-2).
		self::load_security();
		if ( self::module_active( 'affiliates' ) ) {
			self::load_affiliates();
		}

		// Licencias y actualizaciones (admin + cron; hooks de update corren en cualquier contexto).
		// No es uno de los 19 slugs de módulo del sprint — queda fuera del gate.
		self::load_licensing();

		// Email engine se mantiene activo para colas/eventos transaccionales.
		if ( self::module_active( 'email-engine' ) ) {
			self::load_email_engine();
		}

		// Calendar/Live: útiles en admin, REST/webhooks, cron y pantallas frontend académicas.
		if ( $ctx['is_admin'] || $ctx['is_rest'] || $ctx['is_webhook'] || $ctx['is_cron'] || $ctx['is_front'] ) {
			if ( self::module_active( 'calendar' ) ) {
				self::load_calendar();
			}
			if ( self::module_active( 'live-streaming' ) ) {
				self::load_live_streaming();
			}
		}

		// Newsletter: admin/cron/rest/ajax y rutas de archivo newsletter en frontend.
		if ( ( $ctx['is_admin'] || $ctx['is_cron'] || $ctx['is_rest'] || $ctx['is_ajax'] || $ctx['is_newsletter_front'] )
			&& self::module_active( 'newsletter' ) ) {
			self::load_newsletter();
		}

		// Analytics: engine (admin/cron/rest) + forms/popups en frontend cuando aplique.
		if ( self::module_active( 'analytics' ) ) {
			self::load_analytics( $ctx );
		}

		// Messaging: evitar carga en frontend público general.
		if ( ( $ctx['is_admin'] || $ctx['is_cron'] || $ctx['is_rest'] || $ctx['is_ajax'] || $ctx['is_webhook'] )
			&& self::module_active( 'messaging' ) ) {
			self::load_messaging();
		}

		// CRM y Automation conservan carga amplia por dependencia de eventos transversales.
		if ( self::module_active( 'crm' ) ) {
			self::load_crm();
		}
		if ( self::module_active( 'automation' ) ) {
			self::load_automation();
		}

		// P10 (6.13.0): Google (login/registro, Meet, Drive) — carga
		// amplia porque el botón de login vive en wp-login.php, fuera de
		// cualquier contexto admin/rest/cron.
		if ( self::module_active( 'google' ) ) {
			self::load_google();
		}
	}

	/**
	 * Consulta el registro de módulos (PT-2, 6.3.0). Fail-open si el
	 * registro no está disponible por algún motivo, igual que
	 * CLMS_Loader::module_condition_passes().
	 *
	 * @param string $slug
	 * @return bool
	 */
	private static function module_active( string $slug ): bool {
		return ! class_exists( 'CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( $slug );
	}

	// ── Loaders ──────────────────────────────────────────────────────────────

	/** @return void */
	private static function load_google(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'google/';
		self::require_file( $dir . 'class-google-module.php' );
		self::require_file( $dir . 'class-google-identity.php' );
		self::require_file( $dir . 'class-google-drive.php' );

		if ( class_exists( 'ATORA\Google\Google_Module' ) ) {
			\ATORA\Google\Google_Module::init();
		}
		if ( class_exists( 'ATORA\Google\Google_Identity' ) ) {
			\ATORA\Google\Google_Identity::init();
		}
	}

	/** @return void */
	private static function load_licensing(): void {
		self::require_file( ATORA_LMS_MODULES_DIR . 'licensing/class-licensing.php' );
		if ( class_exists( '\ATORA\Licensing\Licensing' ) ) {
			\ATORA\Licensing\Licensing::init();
		}
	}

	/** @return void */
	private static function load_security(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'security/';
		self::require_file( $dir . 'class-security.php' );
		self::require_file( $dir . 'class-extended-registration.php' );
		self::require_file( $dir . 'class-captcha.php' );
		self::require_file( $dir . 'class-2fa-manager.php' );
		self::require_file( $dir . 'providers/class-2fa-totp.php' );
		self::require_file( $dir . 'providers/class-2fa-email.php' );
		if ( class_exists( 'ATORA\Security\Security' ) ) {
			\ATORA\Security\Security::init();
		}
	}

	/** @return void */
	private static function load_affiliates(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'affiliates/';
		self::require_file( $dir . 'class-affiliates.php' );
		self::require_file( $dir . 'class-affiliate-tracker.php' );
		self::require_file( $dir . 'class-affiliate-commissions.php' );
		if ( class_exists( 'ATORA\Affiliates\Affiliates' ) ) {
			\ATORA\Affiliates\Affiliates::init();
		}
	}

	/** @return void */
	private static function load_email_engine(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'email-engine/';
		self::require_file( $dir . 'class-email-templates.php' );
		self::require_file( $dir . 'class-email-queue.php' );
		self::require_file( $dir . 'class-email-engine.php' );
		if ( class_exists( 'ATORA\EmailEngine\Email_Engine' ) ) {
			\ATORA\EmailEngine\Email_Engine::init();
		}
	}

	/** @return void */
	private static function load_calendar(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'calendar/';
		self::require_file( $dir . 'class-calendar.php' );
		self::require_file( $dir . 'class-calendar-sync.php' );
		if ( class_exists( 'ATORA\Calendar\Calendar' ) ) {
			\ATORA\Calendar\Calendar::init();
		}
		if ( class_exists( 'ATORA\Calendar\Calendar_Sync' ) ) {
			\ATORA\Calendar\Calendar_Sync::init();
		}
	}

	/** @return void */
	private static function load_live_streaming(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'live-streaming/';
		// P6/P7 (6.13.0): repositorio de escritura dual + contrato de
		// providers — deben cargar antes que class-live-streaming.php,
		// que ya los usa en save_metabox()/register_webhook_routes().
		self::require_file( $dir . 'class-live-session-repository.php' );
		self::require_file( $dir . 'providers/class-provider-interface.php' );
		self::require_file( $dir . 'providers/class-provider-zoom.php' );
		// P8 (6.13.0): Meet reusa el OAuth de Calendar — requiere que ese
		// módulo ya esté cargado (gateado arriba, antes de live-streaming).
		if ( class_exists( 'ATORA\Calendar\Calendar_Sync' ) ) {
			self::require_file( $dir . 'providers/class-provider-meet.php' );
		}
		self::require_file( $dir . 'class-live-streaming.php' );
		self::require_file( $dir . 'class-live-streaming-migrator.php' );
		if ( class_exists( 'ATORA\LiveStreaming\Live_Streaming' ) ) {
			\ATORA\LiveStreaming\Live_Streaming::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::require_file( $dir . 'class-live-streaming-cli.php' );
			if ( class_exists( 'ATORA\LiveStreaming\Live_Streaming_CLI' ) ) {
				\ATORA\LiveStreaming\Live_Streaming_CLI::init();
			}
		}
	}

	/** @return void */
	private static function load_newsletter(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'newsletter/';
		self::require_file( $dir . 'class-newsletter.php' );
		if ( class_exists( 'ATORA\Newsletter\Newsletter' ) ) {
			\ATORA\Newsletter\Newsletter::init();
		}
	}

	/** @return void */
	private static function load_analytics( array $ctx = array() ): void {
		$dir = ATORA_LMS_MODULES_DIR . 'analytics/';
		$ctx = ! empty( $ctx ) ? $ctx : self::get_context();

		if ( $ctx['is_admin'] || $ctx['is_cron'] || $ctx['is_rest'] ) {
			self::require_file( $dir . 'class-analytics-engine.php' );
			if ( class_exists( 'ATORA\Analytics\Analytics_Engine' ) ) {
				\ATORA\Analytics\Analytics_Engine::init();
			}
		}

		$needs_front_analytics = $ctx['is_front'] || $ctx['is_ajax'];
		if ( $needs_front_analytics ) {
			self::require_file( $dir . 'class-forms-builder.php' );
			self::require_file( $dir . 'class-popups.php' );
			if ( class_exists( 'ATORA\Analytics\Forms_Builder' ) ) {
				\ATORA\Analytics\Forms_Builder::init();
			}
			if ( class_exists( 'ATORA\Analytics\Popups' ) ) {
				\ATORA\Analytics\Popups::init();
			}
		}
	}

	/** @return void */
	private static function load_messaging(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'messaging/';
		self::require_file( $dir . 'class-whatsapp.php' );
		self::require_file( $dir . 'class-telegram-bot.php' );
		// PT-5.1: la tabla del agrupador se crea desde
		// Messaging_Router::init(), así que debe cargarse antes.
		self::require_file( $dir . 'class-messaging-digest-store.php' );
		self::require_file( $dir . 'class-messaging-router.php' );
		if ( class_exists( 'ATORA\Messaging\Messaging_Router' ) ) {
			\ATORA\Messaging\Messaging_Router::init();
		}

		// PT-4 (6.4.0): modelo de preferencias + shortcode [atora_preferencias].
		self::require_file( $dir . 'class-messaging-preferences.php' );
		self::require_file( $dir . 'class-messaging-preferences-shortcode.php' );
		if ( class_exists( 'ATORA\Messaging\Preferences_Shortcode' ) ) {
			\ATORA\Messaging\Preferences_Shortcode::init();
		}

		// PT-5.2 (6.4.0): cron que consume Digest_Store.
		self::require_file( $dir . 'class-messaging-digest-cron.php' );
		if ( class_exists( 'ATORA\Messaging\Digest_Cron' ) ) {
			\ATORA\Messaging\Digest_Cron::init();
		}

		// PT-3 (6.4.0): puente eventos académicos → Messaging_Router.
		// Vive detrás del mismo gate del módulo 'messaging' (esta
		// función) porque no tiene sentido registrar sus listeners si
		// el módulo de mensajería está apagado.
		self::require_file( ATORA_LMS_DIR . 'includes/academic/class-academic-messaging-bridge.php' );
		// PT-1 (6.5.10): esta clase es GLOBAL (sin namespace), pero este
		// archivo vive en `namespace ATORA;` — una referencia estática sin
		// calificar se resuelve en tiempo de compilación a
		// `ATORA\CLMS_Academic_Messaging_Bridge`, que no existe. La
		// comprobación class_exists() de arriba pasaba igual (un string
		// literal no se resuelve por namespace), enmascarando el fatal
		// hasta la llamada real. Requiere el backslash inicial para
		// apuntar al namespace global explícitamente.
		if ( class_exists( '\CLMS_Academic_Messaging_Bridge' ) ) {
			\CLMS_Academic_Messaging_Bridge::init();
		}
	}

	/** @return void */
	private static function load_crm(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'crm/';
		self::require_file( $dir . 'class-crm.php' );
		if ( class_exists( 'ATORA\CRM\CRM' ) ) {
			\ATORA\CRM\CRM::init();
		}

		$crm_v2_dir = ATORA_LMS_MODULES_DIR . 'crm-v2/';
		self::require_file( $crm_v2_dir . 'class-crm-v2-app.php' );
		if ( class_exists( 'ATORA\CRM_V2\CRM_V2_App' ) ) {
			\ATORA\CRM_V2\CRM_V2_App::init();
		}
	}

	/** @return void */
	private static function load_automation(): void {
		$dir = ATORA_LMS_MODULES_DIR . 'automation/';
		self::require_file( $dir . 'class-outbound-webhooks.php' );
		self::require_file( $dir . 'class-automation-engine.php' );
		if ( class_exists( 'ATORA\Automation\Automation_Engine' ) ) {
			\ATORA\Automation\Automation_Engine::init();
		}
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Require file solo si existe (evita fatales en entornos parciales).
	 *
	 * @param string $path Ruta absoluta al archivo.
	 * @return void
	 */
	private static function require_file( string $path ): void {
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Detecta contexto del request para carga condicional progresiva.
	 *
	 * @return array<string,bool>
	 */
	private static function get_context(): array {
		if ( null !== self::$context ) {
			return self::$context;
		}

		$is_admin   = is_admin();
		$is_ajax    = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$is_cron    = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$is_rest    = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || self::is_rest_request_uri();
		$is_webhook = self::is_webhook_request_uri();
		$is_front   = ! $is_admin && ! $is_ajax && ! $is_cron && ! $is_rest;

		self::$context = array(
			'is_admin'           => $is_admin,
			'is_ajax'            => $is_ajax,
			'is_cron'            => $is_cron,
			'is_rest'            => $is_rest,
			'is_webhook'         => $is_webhook,
			'is_front'           => $is_front,
			'is_newsletter_front'=> $is_front && self::is_newsletter_front_request(),
		);

		return self::$context;
	}

	/**
	 * Comprueba si la URI actual pertenece a REST.
	 *
	 * @return bool
	 */
	private static function is_rest_request_uri(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return ( false !== strpos( $uri, '/wp-json/' ) || false !== strpos( $uri, 'rest_route=' ) );
	}

	/**
	 * Comprueba si la URI actual apunta a un webhook de ATORA.
	 *
	 * @return bool
	 */
	private static function is_webhook_request_uri(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return ( false !== strpos( $uri, '/webhooks/' ) || false !== strpos( $uri, 'webhook' ) );
	}

	/**
	 * Detecta requests frontend relacionados al archivo/newsletter.
	 *
	 * @return bool
	 */
	private static function is_newsletter_front_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return ( false !== strpos( $uri, '/newsletter' ) || false !== strpos( $uri, 'atora_newsletter' ) );
	}
}
