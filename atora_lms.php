<?php
/**
 * Plugin Name:       ATORA LMS
 * Plugin URI:        https://atora.studio
 * Description:       LMS modular para WordPress con IA, evaluaciones, certificados, CRM, mensajería multi-canal, afiliados, live streaming y más. Autor: Atora Studio. Creado por Atmósfera Creativa.
 * Version:           6.26.5
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Atora Studio
 * Author URI:        https://atora.studio
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://atora.studio
 * Text Domain:       atora-lms
 * Domain Path:       /languages
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
/**
 * Changelog v5.27.0 — 2026-04-29
 *
 * MAJOR — ATORA LMS v5.0 "The Complete System"
 *
 * NEW MODULES:
 * - [SECURITY]    Registro extendido (país, ciudad, teléfono, WhatsApp, timezone, idioma)
 * - [SECURITY]    2FA multi-canal: TOTP, Email, SMS (Twilio), WhatsApp
 * - [SECURITY]    Captcha inteligente: hCaptcha y Cloudflare Turnstile
 * - [AFFILIATES]  Sistema de afiliados base: tracking, clicks, comisiones
 * - [AFFILIATES]  Dashboard público del afiliado
 * - [DB]          5 tablas nuevas: 2fa_tokens, trusted_devices, affiliates,
 *                 affiliate_clicks, affiliate_commissions
 *
 * IMPROVED (desde v4.21.1):
 * - Arquitectura modular con directorio /modules/
 * - Namespace ATORA\ para todos los módulos nuevos
 * - GDPR/CAN-SPAM compliance por defecto
 * - Conditional loading — admin/public/cron cargan solo lo necesario
 *
 * INHERITED FIXES (v4.21.1):
 * - Email de acceso se envía correctamente en re-compras
 * - Hooks WooCommerce sin duplicación
 * - Matrícula manual con expiración correcta
 * - Contraseñas seguras en logs
 * - Limpieza automática de notificaciones >90 días
 */
if ( ! defined( 'ATORA_LMS_VERSION' ) ) {
	define( 'ATORA_LMS_VERSION', '6.26.5' );
}

if ( ! defined( 'ATORA_LMS_FILE' ) ) {
	define( 'ATORA_LMS_FILE', __FILE__ );
}

if ( ! defined( 'ATORA_LMS_DIR' ) ) {
	define( 'ATORA_LMS_DIR', plugin_dir_path( ATORA_LMS_FILE ) );
}

if ( ! defined( 'ATORA_LMS_URL' ) ) {
	define( 'ATORA_LMS_URL', plugin_dir_url( ATORA_LMS_FILE ) );
}

if ( ! defined( 'ATORA_LMS_INCLUDES_DIR' ) ) {
	define( 'ATORA_LMS_INCLUDES_DIR', ATORA_LMS_DIR . 'includes/' );
}

if ( ! defined( 'ATORA_LMS_ASSETS_URL' ) ) {
	define( 'ATORA_LMS_ASSETS_URL', ATORA_LMS_URL . 'assets/' );
}

if ( ! defined( 'ATORA_LMS_MODULES_DIR' ) ) {
	define( 'ATORA_LMS_MODULES_DIR', ATORA_LMS_DIR . 'modules/' );
}

// Modo desarrollo: activar en wp-config.php con define('ATORA_DEV_MODE', true).
// En producción siempre es false. Permite webhooks sin firma en local y logs verbosos.
defined( 'ATORA_DEV_MODE' ) || define( 'ATORA_DEV_MODE', false );

// PT-5 (6.5.10): registro centralizado de intervalos de cron
// personalizados ('every_5_minutes', 'every_15_minutes'). Antes, cada
// módulo (Calendar_Sync, LMS_Migration_Admin, Live_Streaming,
// Automation_Engine, Email_Queue, Messaging_Router) registraba su
// propia copia vía add_filter('cron_schedules', ...) dentro de su
// propio init() — pero V5_Modules carga los módulos v5
// condicionalmente según el contexto del request (admin/public/cron,
// "para minimizar la huella de memoria", ver su propio docblock), así
// que un contexto donde el módulo QUE NECESITA el intervalo carga sin
// que el módulo que lo REGISTRA también cargue producía
// wp_schedule_event()->invalid_schedule. Este filtro vive acá, en el
// bootstrap del plugin, que se ejecuta sin condición en cualquier
// contexto (front-end, admin, cron, REST, AJAX) — así el intervalo
// SIEMPRE está disponible sin importar qué subconjunto de módulos
// haya cargado. Los registros existentes en cada módulo se dejan
// intactos (redundantes pero inofensivos — mismos valores, y
// remover una fuente de verdad ya probada no es el cambio mínimo que
// pide este hotfix); no se renombra ningún slug de schedule
// persistido, así que los eventos de cron ya programados en instalaciones
// existentes siguen siendo válidos sin necesidad de reprogramarlos.
add_filter( 'cron_schedules', static function ( array $schedules ): array {
	if ( ! isset( $schedules['every_5_minutes'] ) ) {
		$schedules['every_5_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Cada 5 minutos', 'atora-lms' ),
		);
	}
	if ( ! isset( $schedules['every_15_minutes'] ) ) {
		$schedules['every_15_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Cada 15 minutos', 'atora-lms' ),
		);
	}
	return $schedules;
} );

// PT-3 (6.5.11): flush-de-reescritura versionado, de una sola vez.
//
// register_activation_hook()/register_deactivation_hook() eran, hasta
// este sprint, los ÚNICOS dos puntos donde flush_rewrite_rules() se
// llamaba en todo el plugin — ambos son eventos explícitos de
// activar/desactivar en wp-admin. Una actualización de plugin normal
// en producción (reemplazar los archivos vía FTP/SFTP, un gestor de
// releases, o el propio "Actualizar ahora" de WordPress) NO dispara
// ninguno de los dos hooks — WordPress no desactiva-y-reactiva el
// plugin al actualizar sus archivos. El resultado: si en CUALQUIER
// punto de la historia de este plugin cambió un slug/estructura de
// reescritura de un CPT (curso, docente, programa, etc.), cualquier
// sitio en producción que se actualizó por reemplazo de archivos
// (el camino normal, no una reactivación manual) se quedó para
// siempre con la caché de rewrite_rules vieja en la BD — de ahí URLs
// de curso y de perfil de docente devolviendo 404 aunque el registro
// de post types/rewrite rules en el código esté (y siempre haya
// estado) correcto: CLMS_Loader::boot() (más abajo, en el 'init' de
// prioridad 1) instancia tanto CLMS_CPT como CLMS_Instructor en cada
// request normal, y ambos enganchan su propio registro a 'init' con
// prioridad por defecto — WordPress SÍ ejecuta callbacks agregados a
// un hook mientras ese mismo hook está corriendo, siempre que su
// prioridad sea mayor a la que se está procesando (comportamiento
// núcleo de WP_Hook::add_filter()/resort_active_iterations(), no
// específico de este plugin) — así que el registro en sí nunca fue el
// problema; la caché de reglas nunca se refrescaba.
//
// Fix: una versión de reescritura separada de ATORA_LMS_VERSION (no
// cada bump de versión debe forzar un flush — sería el mismo
// antipatrón "flush en cada request" que este mismo hotfix prohíbe,
// solo que a nivel de deploy en vez de a nivel de request). Se
// comprueba en 'wp_loaded' — después de que 'init' completó del todo,
// garantizando que CLMS_CPT/CLMS_Instructor ya registraron sus reglas
// para este mismo request — y solo si la versión de reescritura
// guardada es anterior a esta, se hace UN flush suave (sin reescribir
// .htaccess) y se persiste la nueva versión para que ningún request
// futuro repita el flush.
// PT-3 (6.5.12): bump obligatorio. Un sitio que ya corrió el flush de
// 6.5.11 (versión '6.5.11-1' ya persistida) capturó las reglas TAL
// COMO estaban registradas en ESE momento — si el registro anidado del
// que dependía ese flush no llegó a completarse a tiempo para esa
// ejecución puntual (la ambigüedad que este mismo sprint elimina con
// el registro estructural temprano de arriba), ese sitio quedó
// permanentemente con reglas incompletas, porque el chequeo de versión
// nunca reintenta un flush ya "hecho". Subir la versión acá fuerza
// exactamente UN flush más, esta vez con el registro determinista ya
// en su lugar antes de que 'wp_loaded' lo lea — sin importar si el
// flush de 6.5.11 fue correcto o no, este corrige cualquier resto de
// ese caso.
// PT-1 (6.5.13): bump obligatorio, igual que en 6.5.12. Un sitio que
// corrió el flush de 6.5.12 (registro determinista correcto,
// confirmado) igual seguía sirviendo 404 en curso/docente hasta que
// un usuario guardaba manualmente Ajustes → Enlaces permanentes en
// wp-admin — la diferencia real no era el registro (ya correcto desde
// 6.5.12) sino que ese flush AUTOMÁTICO y silencioso nunca purgaba el
// caché de página (LiteSpeed/WP Rocket/W3TC/etc., muy probablemente
// activo en producción — este mismo código ya lo detecta y lo
// muestra en los paneles de diagnóstico vía la constante WP_CACHE,
// solo que nunca actuaba sobre esa detección). El flush de WordPress
// reconstruye la opción rewrite_rules correctamente, pero una
// respuesta 404 para /cursos/algun-curso/ ya cacheada por el plugin
// de caché de página sigue sirviéndose sin volver a tocar PHP en
// absoluto — el guardado MANUAL de permalinks funcionaba porque la
// mayoría de esos plugins de caché purgan su caché completo al
// detectar un cambio en los ajustes de enlaces permanentes, algo que
// una llamada directa a flush_rewrite_rules() por código nunca
// dispara. Subir la versión acá fuerza un flush más, ahora
// acompañado de una purga explícita de caché de página (ver abajo).
define( 'ATORA_LMS_REWRITE_VERSION', '6.5.13-1' );

add_action( 'wp_loaded', static function () {
	$stored = (string) get_option( 'atora_lms_rewrite_version', '' );

	if ( '' !== $stored && version_compare( $stored, ATORA_LMS_REWRITE_VERSION, '>=' ) ) {
		return;
	}

	flush_rewrite_rules( false );
	atora_lms_purge_known_page_caches();
	update_option( 'atora_lms_rewrite_version', ATORA_LMS_REWRITE_VERSION, false );
}, 20 );

/**
 * PT-1 (6.5.13): purga defensiva del caché de página de los plugins de
 * caché más comunes en WordPress, cada uno detectado por su propia
 * función/acción pública documentada — nunca asume cuál (si alguno)
 * está instalado. Se llama después de flush_rewrite_rules() en la
 * migración versionada de arriba, y queda disponible para cualquier
 * otro punto futuro que necesite el mismo comportamiento (p.ej. el
 * botón manual "Purgar caché" del panel de Mantenimiento).
 *
 * @return void
 */
function atora_lms_purge_known_page_caches(): void {
	// LiteSpeed Cache.
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	// WP Rocket.
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	// W3 Total Cache.
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}

	// WP Super Cache.
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	// WP Fastest Cache.
	if ( isset( $GLOBALS['wp_fastest_cache'] ) && is_object( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
		$GLOBALS['wp_fastest_cache']->deleteCache( true );
	}

	// SiteGround Optimizer.
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}

	// Object cache genérico (Redis/Memcached vía el drop-in estándar de WP).
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

// PT-1/PT-2 (6.5.12): registro ESTRUCTURAL determinista de CPTs/rewrite
// de LMS — curso ('lm_course', slug 'cursos'), docente ('atora_teacher'),
// programa/cohorte/lección, y la regla de perfil de docente
// (/docentes/{slug}/) — a 'init' prioridad 0, en el NIVEL SUPERIOR del
// archivo (no anidado dentro de otro callback de 'init' ya en
// ejecución).
//
// Contexto: el bootstrap normal (más abajo) registra estos mismos CPTs/
// reglas indirectamente — CLMS_Loader::boot() corre dentro de un
// callback de 'init' con prioridad 1, e internamente instancia
// CLMS_CPT/CLMS_Instructor, cuyos propios constructores hacen
// add_action('init', ..., 5) / add_action('init', ..., 6) para el registro real. WordPress SÍ
// soporta agregar callbacks a un hook mientras ese mismo hook está
// corriendo (WP_Hook::add_filter()/resort_active_iterations(), estable
// desde 4.7) — en teoría, ese registro anidado también se ejecuta en el
// mismo pase. Pero el flush versionado de arriba (PT-3, 6.5.11) captura
// las reglas de reescritura EXACTAMENTE como estén registradas en el
// momento del flush, y solo se ejecuta UNA vez (protegido por versión) —
// si por CUALQUIER motivo (un plugin de terceros interfiriendo con el
// mismo hook, una particularidad de una versión de PHP/WP puntual, un
// orden de carga distinto en algún contexto de request) el registro
// anidado no llegara a completarse a tiempo para ESE flush específico,
// el sitio quedaría con reglas incompletas guardadas permanentemente
// (la opción de versión ya diría "hecho", así que nunca se reintenta).
// En vez de depender de esa cadena de anidamiento para lo estructural,
// se registra una copia determinista y temprana acá, sin esperar a
// CLMS_Loader — así el flush de arriba (y cualquier flush manual futuro,
// activación, o guardado de permalinks) siempre encuentra el CPT y la
// regla de reescritura ya registrados, sin ambigüedad de orden.
//
// No se instancian CLMS_CPT/CLMS_Instructor con `new` (eso ejecutaría
// sus constructores completos, duplicando después los hooks de columnas
// admin/metaboxes cuando CLMS_Loader cree sus propias instancias más
// adelante en el mismo request) — se usa
// ReflectionClass::newInstanceWithoutConstructor() para invocar
// exactamente los métodos de registro reales (mismo código, sin
// duplicar), sin ningún efecto secundario del constructor. Volver a
// llamar register_post_type()/add_rewrite_rule() más tarde (vía el
// registro anidado normal, si corre) es inofensivo — WordPress permite
// re-registrar el mismo CPT/regla sin error.
add_action( 'init', static function () {
	$cpt_file = ATORA_LMS_DIR . 'includes/class-cpt.php';
	if ( file_exists( $cpt_file ) ) {
		require_once $cpt_file;
		if ( class_exists( 'CLMS_CPT' ) ) {
			try {
				$reflection = new ReflectionClass( 'CLMS_CPT' );
				$early_cpt  = $reflection->newInstanceWithoutConstructor();
				$early_cpt->register_post_types();
				$early_cpt->register_taxonomies();
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					error_log( '[ATORA LMS] Registro estructural temprano de CPT falló: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}

	$instructor_file = ATORA_LMS_DIR . 'includes/class-instructor.php';
	if ( file_exists( $instructor_file ) ) {
		require_once $instructor_file;
		if ( class_exists( 'CLMS_Instructor' ) ) {
			try {
				$reflection        = new ReflectionClass( 'CLMS_Instructor' );
				$early_instructor  = $reflection->newInstanceWithoutConstructor();
				$early_instructor->register_rewrite();
				add_filter( 'query_vars', array( $early_instructor, 'register_query_vars' ) );
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					error_log( '[ATORA LMS] Registro estructural temprano de ruta de docente falló: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}
}, 0 );

// Bug #4 fix: limpiar opciones de versión del schema cuando el plugin se actualiza,
// para forzar que maybe_install_schema() cree las tablas nuevas de Fases 6-11.
add_action( 'plugins_loaded', static function() {
	$installed = (string) get_option( 'atora_lms_plugin_version', '' );
	if ( defined( 'ATORA_LMS_VERSION' ) && version_compare( $installed, ATORA_LMS_VERSION, '<' ) ) {
		delete_option( 'atora_crm_v2_schema_version' );
		delete_option( 'atora_db_columns_version' );
		update_option( 'atora_lms_plugin_version', ATORA_LMS_VERSION, false );

		// 6.1.1: invalidar caché del payload del panel de estudiante (course_ids
		// quedaban resueltos en el espacio de IDs equivocado).
		if ( class_exists( 'CLMS_Cache' ) ) {
			\CLMS_Cache::bump_version( 'dashboard' );
		}
	}
}, 1 );

// Aviso visible en admin si DEV_MODE está activo en producción (audit P2-6)
if ( ATORA_DEV_MODE ) {
	add_action( 'admin_notices', static function() {
		echo '<div class="notice notice-error" style="border-left-color:#e24b4a"><p>'
			. '<strong>⚠ ATORA LMS — ATORA_DEV_MODE activo.</strong> '
			. esc_html__( 'Desactívalo antes de producción: ', 'atora-lms' )
			. '<code>define(\'ATORA_DEV_MODE\', false);</code> en wp-config.php</p></div>';
	} );
}

if ( ! defined( 'ATORA_LMS_MODULES_URL' ) ) {
	define( 'ATORA_LMS_MODULES_URL', ATORA_LMS_URL . 'modules/' );
}

if ( ! defined( 'ATORA_LMS_BUILD_SIGNATURE' ) ) {
	define( 'ATORA_LMS_BUILD_SIGNATURE', 'atora-lms-' . ATORA_LMS_VERSION );
}

/**
 * Build probe manifest for deployment verification across environments.
 *
 * @return array<string,array<string,mixed>>
 */
function atora_lms_build_probe_manifest(): array {
	$files = array(
		'atora_lms.php',
		'includes/class-admin-menu.php',
		'modules/crm/class-crm.php',
		'modules/crm/views/admin.php',
		'modules/crm/views/contact-profile.php',
		'modules/crm-v2/class-crm-v2-app.php',
		// Módulos cargados condicionalmente en el bootstrap de 'init' — si faltan
		// en el servidor (deploy incompleto), fallan en silencio salvo por este probe.
		'includes/onboarding/class-onboarding-wizard.php',
		'includes/class-loader.php',
		'includes/class-access.php',
		'includes/class-lms-compatibility-layer.php',
		'includes/class-cpt.php',
		'includes/class-enrollment-manager.php',
		'includes/commerce/class-abandoned-cart-service.php',
		'modules/class-v5-installer.php',
		'modules/class-v5-modules.php',
		'modules/lms/class-lms-migration-admin.php',
		'modules/lms/class-lms-write-facade.php',
		'modules/lms/class-lms-read-router.php',
		'modules/lms/class-lms-parity.php',
		'modules/lms/class-lms-section-service.php',
		'modules/lms/class-lms-section-projector.php',
		'modules/lms/class-lms-course-service.php',
		'modules/lms/class-lms-enrollment-service.php',
		'modules/lms/class-lms-migrator.php',
		'modules/lms/class-lms-rest-controller.php',
		'modules/crm-v2/class-academy-context.php',
		'modules/webhooks/class-webhook-dispatcher.php',
		'modules/crm-v2/services/class-scoring-service.php',
		'modules/mcp/class-api-key-service.php',
		'modules/mcp/class-mcp-module.php',
		'modules/mcp/class-api-keys-rest-controller.php',
	);

	$manifest = array();

	foreach ( $files as $relative_file ) {
		$relative_file = ltrim( (string) $relative_file, '/\\' );
		$absolute_file = ATORA_LMS_DIR . $relative_file;
		$exists        = file_exists( $absolute_file );
		$mtime         = $exists ? (int) filemtime( $absolute_file ) : 0;
		$manifest[ $relative_file ] = array(
			'exists'    => $exists,
			'size'      => $exists ? (int) filesize( $absolute_file ) : 0,
			'md5'       => $exists ? (string) md5_file( $absolute_file ) : '',
			'mtime_utc' => $mtime > 0 ? gmdate( 'c', $mtime ) : '',
		);
	}

	return $manifest;
}

/**
 * Build probe payload shared by admin and REST endpoints.
 *
 * @return array<string,mixed>
 */
function atora_lms_get_build_probe_payload(): array {
	$current_user_id = (int) get_current_user_id();
	$is_super_admin  = (bool) ( function_exists( 'is_super_admin' ) && is_super_admin() );

	$crm_class       = '\ATORA\CRM\CRM';
	$crm_v2_app      = '\ATORA\CRM_V2\CRM_V2_App';
	$crm_class_ready = class_exists( $crm_class ) && method_exists( $crm_class, 'can_access_crm' );
	$crm_v2_ready    = class_exists( $crm_v2_app ) && method_exists( $crm_v2_app, 'can_access' );

	$crm_can_access  = $crm_class_ready ? (bool) $crm_class::can_access_crm( $current_user_id ) : false;
	$crm_can_manage  = $crm_class_ready && method_exists( $crm_class, 'can_manage_crm' )
		? (bool) $crm_class::can_manage_crm( $current_user_id )
		: false;
	$crm_v2_enabled  = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'is_crm_v2_enabled' )
		? (bool) CLMS_Settings::is_crm_v2_enabled()
		: (bool) get_option( 'clms_crm_v2_enabled', false );
	$crm_v2_access   = $crm_v2_ready ? (bool) $crm_v2_app::can_access() : false;

	$recommended_crm_url = ( $crm_v2_enabled && $crm_v2_access )
		? admin_url( 'admin.php?page=atora-crm-v2' )
		: admin_url( 'admin.php?page=atora-crm' );

	return array(
		'signature'      => ATORA_LMS_BUILD_SIGNATURE,
		'plugin_version' => ATORA_LMS_VERSION,
		'site_url'       => (string) site_url(),
		'home_url'       => (string) home_url(),
		'is_multisite'   => (bool) is_multisite(),
		'is_super_admin' => $is_super_admin,
		'user_id'        => $current_user_id,
		'php_version'    => (string) PHP_VERSION,
		'wp_version'     => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
		'timestamp_utc'  => gmdate( 'c' ),
		'runtime_access' => array(
			'current_user_can_read'           => (bool) current_user_can( 'read' ),
			'current_user_can_manage_options' => (bool) current_user_can( 'manage_options' ),
			'crm_class_ready'                 => $crm_class_ready,
			'crm_can_access'                  => $crm_can_access,
			'crm_can_manage'                  => $crm_can_manage,
			'crm_v2_enabled'                  => $crm_v2_enabled,
			'crm_v2_app_ready'                => $crm_v2_ready,
			'crm_v2_can_access'               => $crm_v2_access,
			'recommended_crm_url'             => $recommended_crm_url,
		),
		'manifest'       => atora_lms_build_probe_manifest(),
	);
}

/**
 * Outputs runtime build probe JSON for admins.
 *
 * URL example:
 * /wp-admin/admin.php?page=clms-dashboard&atora_build_probe=1
 *
 * @return void
 */
function atora_lms_handle_build_probe(): void {
	if ( ! is_admin() ) {
		return;
	}

	$probe_flag = isset( $_GET['atora_build_probe'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['atora_build_probe'] ) ) : '';
	if ( '1' !== $probe_flag ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) && ! ( function_exists( 'is_super_admin' ) && is_super_admin() ) ) {
		wp_die( esc_html__( 'No tienes permisos para ver el probe de build.', 'atora-lms' ), esc_html__( 'Acceso denegado', 'atora-lms' ), array( 'response' => 403 ) );
	}

	$payload = atora_lms_get_build_probe_payload();

	nocache_headers();

	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
	}

	$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) || '' === $json ) {
		$json = '{"error":"build_probe_encode_failed"}';
	}

	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}
add_action( 'admin_init', 'atora_lms_handle_build_probe' );

if ( ! function_exists( 'atora_theme_get_active_page_template' ) ) {
	/**
	 * Compatibilidad con temas ATORA que esperan resolver la plantilla activa de una página.
	 *
	 * @param int|null $post_id ID opcional del post/página.
	 * @return string
	 */
	function atora_theme_get_active_page_template( $post_id = null ): string {
		$post_id = $post_id ? absint( $post_id ) : get_queried_object_id();

		if ( $post_id > 0 ) {
			$template = (string) get_post_meta( $post_id, '_wp_page_template', true );
			if ( '' !== $template ) {
				return $template;
			}
		}

		if ( is_front_page() ) {
			return 'front-page.php';
		}

		if ( is_page() ) {
			return 'page.php';
		}

		return '';
	}
}

/**
 * REST endpoint for build probe diagnostics.
 *
 * GET /wp-json/atora/v1/build-probe
 *
 * @return void
 */
function atora_lms_register_build_probe_rest_route(): void {
	if ( ! function_exists( 'register_rest_route' ) ) {
		return;
	}

	register_rest_route(
		'atora/v1',
		'/build-probe',
		array(
			'methods'             => 'GET',
			'callback'            => static function () {
				return rest_ensure_response( atora_lms_get_build_probe_payload() );
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' ) || ( function_exists( 'is_super_admin' ) && is_super_admin() );
			},
		)
	);
}
add_action( 'rest_api_init', 'atora_lms_register_build_probe_rest_route' );

// Legacy CLMS_ constants for internal code compatibility.
if ( ! defined( 'CLMS_VERSION' ) ) {
	define( 'CLMS_VERSION', ATORA_LMS_VERSION );
}

if ( ! defined( 'CLMS_PLUGIN_DIR' ) ) {
	define( 'CLMS_PLUGIN_DIR', ATORA_LMS_DIR );
}

/**
 * Minimum requirement checks — run before any hook registration.
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', static function () {
		echo '<div class="error"><p>';
		printf(
			/* translators: %s: current PHP version */
			esc_html__( 'ATORA LMS requires PHP 8.1 or higher. You are running PHP %s.', 'atora-lms' ),
			esc_html( PHP_VERSION )
		);
		echo '</p></div>';
	} );
	return;
}

if ( version_compare( $GLOBALS['wp_version'], '6.4', '<' ) ) {
	add_action( 'admin_notices', static function () {
		echo '<div class="error"><p>';
		esc_html_e( 'ATORA LMS requires WordPress 6.4 or higher.', 'atora-lms' );
		echo '</p></div>';
	} );
	return;
}

/**
 * Global loader instance — accessible via atora_lms().
 *
 * @var CLMS_Loader|null
 */
global $clms_loader_instance;
$clms_loader_instance = null;

/**
 * Returns the global loader instance, or a specific registered module.
 *
 * Used by CLMS_Helper::module() and any module that needs
 * access to already-initialized instances.
 *
 * @param string|null $module Nombre de clase del módulo a recuperar vía
 *                             CLMS_Loader::get_module(). Si se omite (o es
 *                             'CLMS_Loader'), se devuelve el propio loader.
 * @return CLMS_Loader|object|null
 */
function atora_lms( $module = null ) {
	global $clms_loader_instance;

	if ( ! $clms_loader_instance && class_exists( 'CLMS_Loader' ) && method_exists( 'CLMS_Loader', 'instance' ) ) {
		$clms_loader_instance = CLMS_Loader::instance();
	}

	if ( null === $module || 'CLMS_Loader' === $module ) {
		return $clms_loader_instance;
	}

	if ( $clms_loader_instance && method_exists( $clms_loader_instance, 'get_module' ) ) {
		return $clms_loader_instance->get_module( $module );
	}

	return null;
}

/**
 * @deprecated Use atora_lms() instead.
 *
 * @param string|null $module Nombre de clase del módulo a recuperar.
 * @return CLMS_Loader|object|null
 */
function clms_core( $module = null ) {
	return atora_lms( $module );
}

/**
 * Obtiene el contenido estándar de la entrada actual usando `the_content()`.
 *
 * Se usa para compatibilidad con maquetadores que esperan que el template
 * ejecute este punto del ciclo (Elementor, Divi, Beaver, etc.).
 *
 * @param int  $post_id      ID del post/entrada actual.
 * @param bool $fallback_raw Si true, usa post_content cuando the_content está vacío.
 * @return string
 */
function atora_lms_get_entry_content_html( int $post_id = 0, bool $fallback_raw = true ): string {
	$post_id = $post_id > 0 ? $post_id : get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	ob_start();
	the_content();
	$content_html = (string) ob_get_clean();

	if ( $fallback_raw && '' === trim( wp_strip_all_tags( $content_html ) ) ) {
		$raw_content = (string) get_post_field( 'post_content', $post_id );
		if ( '' !== trim( wp_strip_all_tags( $raw_content ) ) ) {
			$content_html = (string) apply_filters( 'the_content', $raw_content );
		}
	}

	/**
	 * Permite filtrar el HTML de contenido capturado para compatibilidad builders.
	 *
	 * @param string $content_html Contenido final.
	 * @param int    $post_id      ID de la entrada.
	 * @param bool   $fallback_raw Si se aplicó fallback desde post_content.
	 */
	return (string) apply_filters( 'atora_lms_entry_content_html', $content_html, $post_id, $fallback_raw );
}

/**
 * Detecta si una entrada tiene contenido de Gutenberg.
 *
 * Este helper se usa para activar la ruta block-first solo cuando el editor
 * realmente está usando bloques, sin cambiar el comportamiento de contenido
 * clásico o páginas heredadas.
 *
 * @param int $post_id ID del post/entrada actual.
 * @return bool
 */
function atora_lms_entry_has_block_content( int $post_id = 0 ): bool {
	$post_id = $post_id > 0 ? $post_id : get_the_ID();
	if ( ! $post_id || ! function_exists( 'has_blocks' ) ) {
		return false;
	}

	$raw_content = (string) get_post_field( 'post_content', $post_id );
	$has_blocks  = has_blocks( $raw_content );

	/**
	 * Permite forzar o impedir la ruta block-first por entrada.
	 *
	 * @param bool   $has_blocks Estado detectado por Gutenberg.
	 * @param int    $post_id    ID de la entrada.
	 * @param string $raw_content Contenido crudo del post.
	 */
	return (bool) apply_filters( 'atora_lms_entry_has_block_content', $has_blocks, $post_id, $raw_content );
}

/**
 * Ejecuta `the_content()` sin renderizar salida visible.
 *
 * Útil para templates UI propios de ATORA que no usan el contenido clásico,
 * pero deben mantener compatibilidad con maquetadores.
 *
 * @param int $post_id ID de la entrada.
 * @return void
 */
function atora_lms_builder_compat_touch_content( int $post_id = 0 ): void {
	atora_lms_get_entry_content_html( $post_id, false );
}

/**
 * Detecta si la request actual proviene de un contexto de maquetador/preview.
 *
 * Compatible con Elementor, Divi, Beaver, Bricks, Brizy, Oxygen y Breakdance.
 *
 * @param string $post_type Tipo de post esperado.
 * @param int    $post_id   ID del post esperado.
 * @return bool
 */
function atora_lms_is_builder_preview_request( string $post_type = '', int $post_id = 0 ): bool {
	$known_query_flags = array(
		'elementor-preview',
		'elementor_library',
		'et_fb',
		'fl_builder',
		'ct_builder',
		'bricks',
		'brizy-edit',
		'breakdance',
		'oxy_user_library',
		'vc_editable',
		'vcv-action',
	);

	foreach ( $known_query_flags as $flag ) {
		if ( isset( $_GET[ $flag ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
	}

	$action = isset( $_GET['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: '';

	if ( in_array( $action, array( 'elementor', 'ct_builder', 'fl_builder', 'vc_inline' ), true ) ) {
		return true;
	}

	$is_preview = is_preview();
	if ( $post_type ) {
		$is_preview = $is_preview && is_singular( $post_type );
	}

	/**
	 * Permite extender detección de contexto builder/preview.
	 *
	 * @param bool   $is_preview Estado detectado.
	 * @param string $post_type  Tipo de post actual.
	 * @param int    $post_id    ID del post actual.
	 */
	return (bool) apply_filters( 'atora_lms_is_builder_preview_request', $is_preview, $post_type, $post_id );
}

/**
 * Requiere un archivo de módulo condicional y reporta si falta en disco.
 *
 * Hosts compartidos (ej. instalaciones vía Softaculous) a veces dejan un
 * despliegue incompleto sin ningún error visible: el `if ( file_exists() )`
 * simplemente no hace nada y la funcionalidad desaparece en silencio. Este
 * helper convierte esa falla silenciosa en un log + aviso visible en admin.
 *
 * @param string        $relative_path Ruta relativa a ATORA_LMS_DIR.
 * @param callable|null $on_loaded     Callback ejecutado tras el require exitoso.
 * @return bool True si el archivo existía y se cargó.
 */
function atora_lms_require_module( string $relative_path, ?callable $on_loaded = null ): bool {
	$absolute_path = ATORA_LMS_DIR . ltrim( $relative_path, '/\\' );

	if ( ! file_exists( $absolute_path ) ) {
		atora_lms_report_missing_module( $relative_path );
		return false;
	}

	require_once $absolute_path;

	if ( null !== $on_loaded ) {
		$on_loaded();
	}

	return true;
}

/**
 * Igual que atora_lms_require_module(), pero primero consulta el registro
 * de módulos (PT-2, 6.3.0). Si el módulo está desactivado, no requiere el
 * archivo ni ejecuta el callback — comportamiento equivalente a que el
 * archivo no existiera, pero sin el aviso de "módulo faltante" (es una
 * ausencia intencional, no un deploy incompleto).
 *
 * @param string        $slug          Slug del módulo en CLMS_Module_Registry.
 * @param string        $relative_path Ruta relativa al archivo principal.
 * @param callable|null $on_loaded     Callback tras requerir el archivo.
 * @return bool True si se cargó (módulo activo y archivo encontrado).
 */
function atora_lms_require_module_if_active( string $slug, string $relative_path, ?callable $on_loaded = null ): bool {
	if ( class_exists( 'CLMS_Module_Registry' ) && ! CLMS_Module_Registry::is_active( $slug ) ) {
		return false;
	}
	return atora_lms_require_module( $relative_path, $on_loaded );
}

/**
 * Registra un módulo esperado que no se encontró en disco.
 *
 * @param string $relative_path Ruta relativa esperada.
 * @return void
 */
function atora_lms_report_missing_module( string $relative_path ): void {
	global $atora_lms_missing_modules;
	if ( ! is_array( $atora_lms_missing_modules ) ) {
		$atora_lms_missing_modules = array();
	}
	$atora_lms_missing_modules[] = $relative_path;

	error_log( sprintf( '[ATORA LMS] Módulo esperado no encontrado en disco (¿deploy incompleto?): %s', $relative_path ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Aviso en admin cuando algún módulo esperado no cargó por faltar en disco.
 */
add_action( 'admin_notices', static function() {
	global $atora_lms_missing_modules;
	if ( empty( $atora_lms_missing_modules ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$missing = array_values( array_unique( $atora_lms_missing_modules ) );
	$count   = count( $missing );
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'ATORA LMS: archivos del plugin incompletos en el servidor.', 'atora-lms' ); ?></strong>
			<?php
			printf(
				/* translators: %d: number of missing files. */
				esc_html( _n( 'Falta %d archivo esperado — probablemente una subida/instalación incompleta.', 'Faltan %d archivos esperados — probablemente una subida/instalación incompleta.', $count, 'atora-lms' ) ),
				(int) $count
			);
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-dashboard&atora_build_probe=1' ) ); ?>">
				<?php esc_html_e( 'Ver diagnóstico completo', 'atora-lms' ); ?>
			</a>
		</p>
		<p style="font-family:monospace;font-size:12px"><?php echo esc_html( implode( ', ', $missing ) ); ?></p>
	</div>
	<?php
} );

/**
 * Bootstrap — load the module loader and initialize all modules.
 *
 * Se ejecuta en init para evitar notices de carga temprana de i18n
 * en WP 6.7+ cuando algún módulo traduce cadenas en constructores.
 */
add_action( 'init', static function () {
	global $clms_loader_instance;

	// PT-1 (6.5.5): resolución de IP centralizada — utilidad de bajo nivel,
	// sin dependencias, cargada incondicionalmente para que cualquier
	// módulo (Forms_Builder, Student_Assistant, Extended_Registration...)
	// pueda usarla sin importar el orden/estado del sistema de módulos.
	require_once ATORA_LMS_DIR . 'includes/class-atora-client-ip.php';
	require_once ATORA_LMS_DIR . 'includes/class-atora-rate-limiter.php';

	// PT-4 (6.5.7): limpieza periódica de tablas de rate limit — se
	// registra incondicionalmente (no depende de ningún módulo
	// opcional), igual que los dos helpers de arriba.
	require_once ATORA_LMS_DIR . 'includes/class-atora-security-maintenance.php';
	if ( class_exists( 'ATORA_Security_Maintenance' ) ) {
		ATORA_Security_Maintenance::init();
	}

	// 6.26.5: sello de versión (build-info.json / git).
	require_once ATORA_LMS_DIR . 'includes/class-build-info.php';

	// P10.1 (6.13.0): cifrado de tokens OAuth — bloqueante, debe cargar
	// antes que Calendar_Sync y cualquier proveedor Google (Meet/Drive).
	require_once ATORA_LMS_DIR . 'includes/security/class-token-crypto.php';

	// P5 (6.12.0): log de auditoría institucional — core, siempre activo
	// (parte de 'security'), independiente del perfil elegido.
	require_once ATORA_LMS_DIR . 'includes/security/class-audit-log-service.php';
	if ( class_exists( 'CLMS_Audit_Log_Service' ) ) {
		CLMS_Audit_Log_Service::init();
	}

	// P9 (6.13.0): asistencia conectada a seguimiento académico — core
	// (parte de 'academic'), se activa sola si 'live-streaming' no está
	// activo (do_action() sin listeners registrados es un no-op).
	require_once ATORA_LMS_DIR . 'includes/academic/class-attendance-academic-bridge.php';
	if ( class_exists( 'CLMS_Attendance_Academic_Bridge' ) ) {
		CLMS_Attendance_Academic_Bridge::init();
	}

	// ── PT-2 (6.3.0): registro de módulos — debe cargar antes que cualquier
	// sistema de carga (A/B/C) que lo consulte.
	require_once ATORA_LMS_DIR . 'includes/modularity/class-module-registry.php';
	require_once ATORA_LMS_DIR . 'includes/modularity/class-module-guard.php';
	require_once ATORA_LMS_DIR . 'includes/modularity/class-module-admin-page.php';
	require_once ATORA_LMS_DIR . 'includes/modularity/class-install-profiles.php';
	require_once ATORA_LMS_DIR . 'includes/modularity/class-profile-labels.php';
	require_once ATORA_LMS_DIR . 'includes/modularity/class-institucion-settings-page.php';
	if ( class_exists( 'CLMS_Module_Guard' ) ) { CLMS_Module_Guard::init(); }
	if ( class_exists( 'CLMS_Module_Admin_Page' ) ) { CLMS_Module_Admin_Page::init(); }
	if ( class_exists( 'CLMS_Institucion_Settings_Page' ) ) { CLMS_Institucion_Settings_Page::init(); }

	// P2.4 (6.12.0): comando WP-CLI `wp atora rest-audit`.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		atora_lms_require_module( 'includes/modularity/class-rest-audit-cli.php', static function() {
			if ( class_exists( 'CLMS_Rest_Audit_CLI' ) ) {
				CLMS_Rest_Audit_CLI::init();
			}
		} );
		atora_lms_require_module( 'includes/modularity/class-atora-version-cli.php', static function() {
			if ( class_exists( 'ATORA_Version_CLI' ) ) {
				ATORA_Version_CLI::init();
			}
		} );
	}
	// PT-1 (6.12.0): migra perfiles de instalación 6.3.0–6.11.0 (academia/
	// institucional/corporativo) a los cuatro perfiles nuevos, una sola vez.
	if ( class_exists( 'CLMS_Install_Profiles' ) ) { CLMS_Install_Profiles::maybe_migrate_legacy_profile(); }

	// PT-4.2.4: red de seguridad contra slugs de menú admin duplicados (solo WP_DEBUG).
	require_once ATORA_LMS_DIR . 'includes/admin-menu/class-menu-debug-guard.php';
	if ( class_exists( 'CLMS_Menu_Debug_Guard' ) ) { CLMS_Menu_Debug_Guard::init(); }

	// PT-4.3.4: redirecciones de slugs de menú retirados de la navegación visible.
	require_once ATORA_LMS_DIR . 'includes/admin-menu/class-legacy-slug-redirects.php';
	if ( class_exists( 'CLMS_Legacy_Slug_Redirects' ) ) { CLMS_Legacy_Slug_Redirects::init(); }

	// Registro de versión actual y anterior para facilitar rollback controlado.
	$current_version = (string) get_option( 'atora_lms_current_version', '' );
	if ( $current_version !== ATORA_LMS_VERSION ) {
		if ( '' !== $current_version ) {
			update_option( 'atora_lms_previous_version', $current_version, false );
		}
		update_option( 'atora_lms_current_version', ATORA_LMS_VERSION, false );
	}

	atora_lms_require_module( 'includes/class-loader.php', static function() use ( &$clms_loader_instance ) {
		if ( class_exists( 'CLMS_Loader' ) && method_exists( 'CLMS_Loader', 'boot' ) ) {
			$clms_loader_instance = CLMS_Loader::boot();
		}
	} );

	// Keep roles/caps in sync across updates (not only on activation).
	$installed_version = get_option( 'atora_lms_roles_version', '' );
	if ( $installed_version !== ATORA_LMS_VERSION ) {
		atora_lms_require_module( 'includes/class-access.php' );
		if ( class_exists( 'CLMS_Access' ) ) {
			CLMS_Access::add_roles_and_caps();
			update_option( 'atora_lms_roles_version', ATORA_LMS_VERSION );
		}
	}

	// Mantener el esquema v5 sincronizado también en upgrades (no solo activación).
	atora_lms_require_module( 'modules/class-v5-installer.php', static function() {
		if ( class_exists( 'ATORA\V5_Installer' ) ) {
			ATORA\V5_Installer::install();
		}
	} );

	// Mostrar sello de versión en admin (para depuración / QA).
	if ( is_admin() ) {
		add_filter( 'admin_footer_text', static function( string $text ): string {
			if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'ATORA_Build_Info' ) ) {
				return $text;
			}
			$info = ATORA_Build_Info::get();
			$dirty = $info['dirty'] ?? null;
			$dirty_label = null === $dirty ? 'dirty: ?' : ( $dirty ? 'dirty' : 'clean' );
			$build_stale = ! empty( $info['build_stale'] ) ? 'build: stale' : 'build: ok';
			$stamp = trim( sprintf(
				'ATORA LMS %s · %s (%s, %s, %s)',
				(string) ( $info['version'] ?? '' ),
				(string) ( $info['commit_short'] ?? '' ),
				(string) ( $info['origin'] ?? '' ),
				(string) $dirty_label,
				(string) $build_stale
			) );
			return '' !== $text ? $text . ' · ' . esc_html( $stamp ) : esc_html( $stamp );
		} );
	}

	// Bootstrap v5 modules (Security, Affiliates, …).
	atora_lms_require_module( 'modules/class-v5-modules.php', static function() {
		if ( class_exists( 'ATORA\V5_Modules' ) ) {
			ATORA\V5_Modules::boot();
		}
	} );

	/**
	 * Fires once the plugin has fully bootstrapped all modules.
	 *
	 * @param CLMS_Loader|null $clms_loader_instance
	 */
	// Registrar clms-ui como handle base (dependencia declarada por varios estilos del plugin)
	add_action( 'wp_enqueue_scripts',    function() {
		if ( ! wp_style_is( 'clms-ui', 'registered' ) ) {
			wp_register_style( 'clms-ui', false, array(), ATORA_LMS_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
	}, 1 );
	add_action( 'admin_enqueue_scripts', function() {
		if ( ! wp_style_is( 'clms-ui', 'registered' ) ) {
			wp_register_style( 'clms-ui', false, array(), ATORA_LMS_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
	}, 1 );

	// ── Sprint S18: Onboarding Wizard ────────────────────────────────────────
	atora_lms_require_module( 'includes/onboarding/class-onboarding-wizard.php', static function() {
		ATORA_Onboarding_Wizard::init();
	} );

	// ── E-10: Delegación (instructor asistente) ─────────────────────────────
	atora_lms_require_module( 'includes/delegation/class-delegation-service.php' );
	atora_lms_require_module( 'includes/delegation/class-delegation-caps.php', static function() {
		if ( class_exists( 'ATORA_Delegation_Caps' ) ) {
			ATORA_Delegation_Caps::init();
		}
	} );
	atora_lms_require_module( 'includes/delegation/class-delegation-attribution.php', static function() {
		if ( class_exists( 'ATORA_Delegation_Attribution' ) ) {
			ATORA_Delegation_Attribution::init();
		}
	} );
	atora_lms_require_module( 'includes/delegation/class-delegation-ui.php', static function() {
		if ( is_admin() && class_exists( 'ATORA_Delegation_UI' ) ) {
			ATORA_Delegation_UI::init();
		}
	} );

	// ── Fase V S16: Migración LMS — página admin + handler AJAX ─────────────
	atora_lms_require_module( 'modules/lms/class-lms-migration-admin.php', static function() {
		ATORA_LMS_Migration_Admin::init();
	} );

	// ── F2: LMS Write Facade (carga antes del compat layer) ──────────────────
	atora_lms_require_module( 'modules/lms/class-lms-write-facade.php' );

	// ── F3: Read Router + Parity (carga antes del compat layer) ──────────────
	atora_lms_require_module( 'modules/lms/class-lms-read-router.php' );
	atora_lms_require_module( 'modules/lms/class-lms-parity.php' );
	atora_lms_require_module( 'modules/lms/class-lms-section-service.php' );
	atora_lms_require_module( 'modules/lms/class-lms-section-projector.php' );

	// ── F4 task 1.6: comando WP-CLI `wp atora lms cutover` ───────────────────
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			atora_lms_require_module( 'modules/lms/class-lms-cli.php', static function() {
				if ( class_exists( '\ATORA\LMS\LMS_CLI' ) ) {
					\ATORA\LMS\LMS_CLI::init();
				}
			} );

			// ── B.1: comando WP-CLI `wp atora enrollment reconcile` ──────────────
			atora_lms_require_module( 'modules/lms/class-lms-enrollment-cli.php', static function() {
				if ( class_exists( '\ATORA\LMS\LMS_Enrollment_CLI' ) ) {
					\ATORA\LMS\LMS_Enrollment_CLI::init();
				}
			} );

			// ── 6.26.5: comando WP-CLI `wp atora rubrics migrate/verify` ──────────
			atora_lms_require_module( 'modules/rubrics/class-rubrics-cli.php', static function() {
				if ( class_exists( '\ATORA\LMS\Rubrics_CLI' ) ) {
					\ATORA\LMS\Rubrics_CLI::init();
			}
		} );

		// ── X-01: comando WP-CLI `wp atora tenancy rollback` ─────────────────
		atora_lms_require_module( 'modules/tenancy/class-tenancy-cli.php', static function() {
			atora_lms_require_module( 'modules/tenancy/class-institution-service.php' );
			atora_lms_require_module( 'modules/tenancy/class-cohort-migrator.php' );
			if ( class_exists( '\ATORA\LMS\Tenancy_CLI' ) ) {
				\ATORA\LMS\Tenancy_CLI::init();
			}
		} );
	}

	// ── Fase V S16: LMS Compatibility Layer ──────────────────────────────────
	atora_lms_require_module( 'includes/class-lms-compatibility-layer.php', static function() {
		ATORA_LMS_Compatibility_Layer::init();
	} );

	// ── Fase IV S15: Academy Context (multi-tenant base) ─────────────────────
	atora_lms_require_module_if_active( 'crm', 'modules/crm-v2/class-academy-context.php' );

	// ── X-01: Tenant Context (institución) ──────────────────────────────────
	atora_lms_require_module( 'modules/tenancy/class-tenant-context.php' );
	atora_lms_require_module( 'modules/tenancy/class-institution-service.php' );
	atora_lms_require_module( 'modules/tenancy/class-cohort-table-service.php' );
	atora_lms_require_module( 'modules/tenancy/class-tenancy-audit.php' );
	atora_lms_require_module( 'modules/rubrics/class-rubric-read-router.php' );
	atora_lms_require_module( 'modules/rubrics/class-rubric-service.php' );
	atora_lms_require_module( 'modules/tenancy/class-deployment-profile-service.php', static function() {
		if ( class_exists( '\ATORA\LMS\Deployment_Profile_Service' ) ) {
			\ATORA\LMS\Deployment_Profile_Service::init();
		}
	} );
	atora_lms_require_module( 'modules/tenancy/class-tenancy-query-detector.php', static function() {
		if ( class_exists( '\ATORA\LMS\Tenancy_Query_Detector' ) ) {
			\ATORA\LMS\Tenancy_Query_Detector::init();
		}
	} );

	// ── Fase IV S13: Webhook Dispatcher ──────────────────────────────────────
	atora_lms_require_module_if_active( 'webhooks', 'modules/webhooks/class-webhook-dispatcher.php', static function() {
		ATORA_Webhook_Dispatcher::init();
	} );

	// ── Fase III S11: Shortcode alias [atora_my_certificates] ────────────────
	add_shortcode( 'atora_my_certificates', function(): string {
		if ( ! class_exists( 'CLMS_Certificates' ) ) {
			return '<p>' . esc_html__( 'Sistema de certificados no disponible.', 'atora-lms' ) . '</p>';
		}
		$cert = new CLMS_Certificates();
		if ( method_exists( $cert, 'render_my_certificates_shortcode' ) ) {
			return (string) $cert->render_my_certificates_shortcode();
		}
		return '';
	} );

	// ── Fase III S11: Certificados automáticos desde LMS propio ──────────────
	add_action( 'atora/lms/course_completed', function( int $user_id, int $course_id ) {
		if ( class_exists( 'CLMS_Certificates' ) ) {
			$cert = new CLMS_Certificates();
			if ( method_exists( $cert, 'handle_course_completed' ) ) {
				$cert->handle_course_completed( $user_id, $course_id );
			}
		}
	}, 10, 2 );

	// PT-1 (6.10.0): el leaderboard público (Fase III S9) se eliminó por
	// decisión de producto -- competía con la filosofía de "seguimiento,
	// no competencia" que ya rige el CRM académico (6.6.0+). Ver
	// includes/gamification/class-student-badge-service.php (PT-2) por
	// lo que lo reemplaza: insignias individuales, no comparativas.

	// ── Fase II S4: Scoring predictivo ───────────────────────────────────────
	if ( atora_lms_require_module_if_active( 'crm', 'modules/crm-v2/services/class-scoring-service.php' ) ) {
		if ( ! wp_next_scheduled( 'atora_scoring_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'atora_scoring_cron' );
		}
		add_action( 'atora_scoring_cron', function() {
			if ( class_exists( '\ATORA\CRM_V2\Services\Scoring_Service' ) ) {
				\ATORA\CRM_V2\Services\Scoring_Service::recalculate_all( 100 );
			}
		} );
	}

	// ── Fase 12B: Design system admin CSS ────────────────────────────────────
	add_action( 'admin_enqueue_scripts', function( string $hook ) {
		if ( class_exists( 'CLMS_Core' ) && method_exists( 'CLMS_Core', 'instance' ) ) {
			$core = CLMS_Core::instance();
			if ( method_exists( $core, 'enqueue_atora_admin_styles' ) ) {
				$core->enqueue_atora_admin_styles( $hook );
			}
		}
	} );

	// PT-2.1 (6.9.0): búsqueda persistente -- misma posición visible en
	// toda página atora-*/clms-*, nunca un elemento que aparece y
	// desaparece según la pantalla. Mismo patrón de hook que el design
	// system de arriba (CLMS_Core::instance(), gate por página adentro
	// del método) — no un mecanismo nuevo.
	add_action( 'in_admin_header', function() {
		if ( class_exists( 'CLMS_Core' ) && method_exists( 'CLMS_Core', 'instance' ) ) {
			$core = CLMS_Core::instance();
			if ( method_exists( $core, 'render_atora_search_bar' ) ) {
				$core->render_atora_search_bar();
			}
		}
	} );

	// ── Fase 10: Abandoned Cart Service (WooCommerce) ─────────────────────────
	atora_lms_require_module_if_active( 'commerce', 'includes/commerce/class-abandoned-cart-service.php', static function() {
		ATORA_Abandoned_Cart_Service::init();
	} );

	// ── Fase 11: Módulo LMS — tablas propias ─────────────────────────────────
	foreach ( array(
		'class-lms-course-service.php',
		'class-lms-enrollment-service.php',
		'class-lms-migrator.php',
		'class-lms-rest-controller.php',
	) as $lms_file ) {
		atora_lms_require_module( 'modules/lms/' . $lms_file );
	}
	add_action( 'rest_api_init', array( 'ATORA\\LMS\\LMS_REST_Controller', 'register_routes' ) );

	// ── Mobile API v1: tokens opacos y experiencia estudiantil ───────────────
	atora_lms_require_module( 'includes/mobile/class-mobile-token-service.php' );
	atora_lms_require_module( 'includes/mobile/class-mobile-rest-controller.php' );
	if ( class_exists( 'ATORA_Mobile_REST_Controller' ) ) {
		add_action( 'rest_api_init', array( 'ATORA_Mobile_REST_Controller', 'register_routes' ) );
	}
	// Sincronizar CPT con tablas propias al publicar/actualizar
	add_action( 'save_post_lm_course', function( int $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
		if ( class_exists( 'ATORA\\LMS\\LMS_Migrator' ) ) {
			ATORA\LMS\LMS_Migrator::migrate_courses( 1, 0 );
		}
	}, 20 );

	// ── Fase 12C: MCP + API Keys ──────────────────────────────────────────────
	if ( ! class_exists( 'CLMS_Module_Registry' ) || CLMS_Module_Registry::is_active( 'mcp' ) ) {
		foreach ( array(
			'class-api-key-service.php',
			'class-mcp-module.php',
			'class-api-keys-rest-controller.php',
		) as $mcp_file ) {
			atora_lms_require_module( 'modules/mcp/' . $mcp_file );
		}
		if ( class_exists( 'ATORA_MCP_Module' ) ) {
			ATORA_MCP_Module::init();
		}
	}
	// P2 (6.12.0): explícito además del guard de class_exists() de abajo
	// (que ya lo hacía inerte si 'mcp' está inactivo) — así el audit de
	// `wp atora rest-audit` puede listar esto como clasificado.
	if ( ! class_exists( 'CLMS_Module_Registry' ) || CLMS_Module_Registry::is_active( 'mcp' ) ) {
		add_action( 'rest_api_init', function() {
			if ( class_exists( 'ATORA_API_Keys_REST_Controller' ) ) {
				ATORA_API_Keys_REST_Controller::register_routes();
			}
		} );
	}

	do_action( 'atora_lms_loaded', $clms_loader_instance );
}, 1 );

// Filtrar bloques Gutenberg a solo los seguros para email cuando estamos en páginas ATORA
add_filter( 'allowed_block_types_all', static function( $allowed_types, $block_editor_context ) {
	if ( ! is_admin() ) { return $allowed_types; }
	$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$email_pages = array( 'clms-email-hub', 'atora-emails', 'atora-newsletter', 'atora-automations', 'atora-crm-v2-campaigns', 'atora-crm-v2-sequences' );
	if ( $page && ( in_array( $page, $email_pages, true ) || false !== strpos( $page, 'campaign' ) || false !== strpos( $page, 'sequence' ) ) ) {
		return array(
			'core/paragraph', 'core/heading', 'core/image', 'core/list', 'core/list-item',
			'core/buttons', 'core/button', 'core/separator', 'core/spacer', 'core/html',
			'core/quote', 'core/column', 'core/columns',
		);
	}
	return $allowed_types;
}, 10, 2 );

// Bug #2 fix: safety net para el menú ATORA en admin_menu de WordPress.
// El loader ya instancia CLMS_Admin_Menu en init, pero si falla (dependencias
// no resueltas o carga tardía), este hook garantiza el registro del menú.
add_action( 'admin_menu', static function() {
	if ( class_exists( 'CLMS_Admin_Menu' ) ) {
		// Ya instanciado por el loader — no crear segunda instancia
		return;
	}
	atora_lms_require_module( 'includes/class-admin-menu.php', static function() {
		new CLMS_Admin_Menu();
	} );
}, 5 );

/**
 * Carga de traducciones.
 *
 * En WP 6.7+ cargar traducciones antes de `init` dispara avisos de "just in time".
 * Por eso se mantiene un único punto de carga en `init`.
 */
function atora_lms_load_textdomain(): void {
	if ( is_textdomain_loaded( 'atora-lms' ) ) {
		return;
	}

	load_plugin_textdomain( 'atora-lms', false, dirname( plugin_basename( ATORA_LMS_FILE ) ) . '/languages' );
}

add_action( 'init', 'atora_lms_load_textdomain', 0 );

/**
 * Activation hook.
 */
function atora_lms_activate(): void {
	// Register CPT so rewrite rules flush correctly.
	atora_lms_require_module( 'includes/class-cpt.php', static function() {
		if ( class_exists( 'CLMS_CPT' ) ) {
			new CLMS_CPT();
		}
	} );

	// Install roles and capabilities.
	atora_lms_require_module( 'includes/class-access.php' );
	if ( class_exists( 'CLMS_Access' ) ) {
		CLMS_Access::add_roles_and_caps();
		update_option( 'atora_lms_roles_version', ATORA_LMS_VERSION );
	}

	// Install enrollment manager DB table.
	atora_lms_require_module( 'includes/class-enrollment-manager.php' );
	if ( class_exists( 'CLMS_Enrollment_Manager' ) ) {
		CLMS_Enrollment_Manager::install_db();
	}

	// P3 (6.12.0): en una activación genuinamente nueva (este sitio nunca
	// tuvo ATORA LMS antes — 'atora_lms_activated' no existe todavía),
	// aplicar el perfil 'docente' como default explícito ANTES de crear
	// tablas. Sin esto, 'atora_active_modules' seguiría sin guardarse en
	// este punto y CLMS_Module_Registry haría fail-open a "todo activo"
	// (la instalación wizard, paso 1, corre recién en el siguiente
	// request) — create_tables() crearía las 58 tablas de una instalación
	// 'academia' antes de que el usuario elija perfil, violando el
	// objetivo de "instalación limpia con perfil docente crea menos de la
	// mitad de las tablas". El wizard, en su paso 1, puede aplicar
	// después un perfil distinto — apply() + ensure_active_module_tables()
	// ya crean entonces lo que falte. Una reactivación (el sitio ya tenía
	// datos) no toca nada acá — regla de cero cambio de comportamiento
	// por defecto para instalaciones existentes.
	if ( ! get_option( 'atora_lms_activated', false ) ) {
		atora_lms_require_module( 'includes/modularity/class-module-registry.php' );
		atora_lms_require_module( 'includes/modularity/class-install-profiles.php' );
		if ( class_exists( 'CLMS_Install_Profiles' ) && false === get_option( CLMS_Install_Profiles::OPTION, false ) ) {
			CLMS_Install_Profiles::apply( 'docente' );
		}
	}

	// Install v5 module tables (Security, Affiliates, …).
	atora_lms_require_module( 'modules/class-v5-installer.php', static function() {
		if ( class_exists( 'ATORA\V5_Installer' ) ) {
			ATORA\V5_Installer::install();
		}
	} );

	if ( ! empty( $GLOBALS['atora_lms_missing_modules'] ) ) {
		error_log( '[ATORA LMS] Activación completada con módulos faltantes: ' . implode( ', ', array_unique( $GLOBALS['atora_lms_missing_modules'] ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	flush_rewrite_rules( false );
	update_option( 'atora_lms_activated', current_time( 'mysql' ) );
	// PT-3 (6.5.11): la activación ya hizo su propio flush arriba —
	// marcar la versión de reescritura acá para que el chequeo de
	// 'wp_loaded' no repita un segundo flush innecesario en el
	// siguiente request tras una activación fresca.
	if ( defined( 'ATORA_LMS_REWRITE_VERSION' ) ) {
		update_option( 'atora_lms_rewrite_version', ATORA_LMS_REWRITE_VERSION, false );
	}
}

register_activation_hook( ATORA_LMS_FILE, 'atora_lms_activate' );

/**
 * Deactivation hook.
 */
function atora_lms_deactivate(): void {
	flush_rewrite_rules( false );
}

register_deactivation_hook( ATORA_LMS_FILE, 'atora_lms_deactivate' );

/**
 * Uninstall — must be a named function, not a Closure, for WP serialization.
 */
function atora_lms_uninstall(): void {
	$static_options = array(
		'atora_lms_activated',
		'atora_lms_settings',
		'atora_lms_openai_key',
		'atora_lms_roles_version',
		'clms_academy_settings',
		'clms_ai_settings',
		'clms_openai_api_key',
		'clms_navigation_settings',
		'clms_advanced_settings',
		'clms_ai_alerts_settings',
		'clms_ai_quizzes_generated',
		'clms_ai_api_calls',
		'clms_ai_month_cost',
		'clms_lesson_presets',
		'clms_enrollment_db_version',
		'_clms_webhooks',
	);

	foreach ( $static_options as $option_name ) {
		delete_option( $option_name );
	}

	global $wpdb;
	if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
		$option_like_patterns = array(
			'_clms_kb_%',
			'_clms_kb_emb_%',
			'clms_cache_version_%',
		);

		foreach ( $option_like_patterns as $pattern ) {
			$matches = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$pattern
				)
			);

			foreach ( $matches as $option_name ) {
				if ( is_string( $option_name ) && '' !== $option_name ) {
					delete_option( $option_name );
				}
			}
		}
	}

	// Custom post types are intentionally preserved to protect user content.
}

register_uninstall_hook( ATORA_LMS_FILE, 'atora_lms_uninstall' );
