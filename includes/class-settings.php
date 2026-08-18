<?php
/**
 * CLMS_Settings — Panel "Configuración de Atora"
 *
 * Centraliza:
 *   - Datos de la academia (nombre, logo, color)
 *   - APIs: OpenAI, Anthropic, Gemini y Whisper
 *   - Ajustes de IA (proveedor activo, modelos)
 *   - Ajustes avanzados
 *
 * Reemplaza el antiguo submenu "IA" de CLMS_AI_Admin, que queda
 * desregistrado si este panel está activo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/settings/trait-settings-core.php';
require_once __DIR__ . '/settings/trait-settings-render.php';
require_once __DIR__ . '/settings/trait-settings-channels-helpers.php';
require_once __DIR__ . '/settings/trait-settings-ai.php';
require_once __DIR__ . '/settings/trait-settings-academy-navigation.php';

class CLMS_Settings {

	const OPTION_ACADEMY = 'clms_academy_settings';
	const OPTION_AI      = 'clms_ai_settings';       // misma key que usa CLMS_AI
	const OPTION_ADV     = 'clms_advanced_settings';
	const OPTION_NAV     = 'clms_navigation_settings';
	const OPTION_CRM_V2_ENABLED = 'clms_crm_v2_enabled';
	const OPTION_EMAIL_ENGINE = 'atora_email_engine_options';
	const OPTION_WHATSAPP     = 'atora_whatsapp_options';
	const OPTION_TELEGRAM     = 'atora_telegram_options';
	const PAGE_SLUG      = 'clms-settings';
	const NONCE_ACTION   = 'clms_settings_save';

	/**
	 * Evita doble render accidental de la pantalla cuando hay callbacks duplicados.
	 *
	 * @var bool
	 */
	protected static $page_rendered = false;

	use CLMS_Settings_Core_Trait;
	use CLMS_Settings_Render_Trait;
	use CLMS_Settings_Channels_Helpers_Trait;
	use CLMS_Settings_AI_Trait;
	use CLMS_Settings_Academy_Navigation_Trait;

	public function __construct() {
		add_action( 'init',           array( $this, 'maybe_redirect_legacy_settings_path' ), 1 );
		add_action( 'admin_menu',     array( $this, 'register_menu' ), 15 );
		add_action( 'admin_init',     array( $this, 'maybe_remove_old_ai_submenu' ), 25 );
		add_action( 'admin_init',     array( $this, 'register_feature_flags' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_clms_save_settings', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_clms_ai_test_connection', array( $this, 'ajax_test_ai_connection' ) );
		add_filter( 'pre_update_option_' . self::OPTION_AI, array( $this, 'filter_pre_update_ai_settings' ), 10, 2 );
	}
}
