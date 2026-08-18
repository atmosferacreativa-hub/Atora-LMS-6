<?php
/**
 * ATORA LMS v5 — Sistema de Popups
 *
 * Popups de captura de leads, exit intent, gamificación y anuncios.
 * Triggers: entrada, scroll %, exit intent, tiempo en página, click.
 * Frequency control via cookie / localStorage.
 * Targeting por URL, rol, referrer.
 *
 * @package ATORA_LMS\Analytics
 * @since   5.0.0
 */

namespace ATORA\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Popups
 *
 * @since 5.0.0
 */
class Popups {

	/**
	 * Inicializa el módulo.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Inyectar popups activos en el footer.
		add_action( 'wp_footer', array( __CLASS__, 'render_active_popups' ) );

		// Gamification popups en lecciones.
		add_action( 'wp_footer', array( __CLASS__, 'render_gamification_popup' ) );

		// Assets.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Admin.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu',     array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'wp_ajax_atora_popup_save', array( __CLASS__, 'ajax_save' ) );
		}
	}

	/**
	 * Renderiza todos los popups activos que aplican a la página actual.
	 *
	 * @return void
	 */
	public static function render_active_popups(): void {
		$popups = self::get_active_popups();

		foreach ( $popups as $popup ) {
			if ( ! self::popup_matches_current_page( $popup ) ) {
				continue;
			}

			$config = json_decode( get_post_meta( $popup->ID, 'atora_popup_config', true ), true );
			self::render_popup_html( $popup->ID, $config );
		}
	}

	/**
	 * Renderiza popups de gamificación en páginas de lección.
	 *
	 * @return void
	 */
	public static function render_gamification_popup(): void {
		if ( ! is_singular( 'lm_lesson' ) || ! is_user_logged_in() ) {
			return;
		}

		$messages = array(
			'25'  => __( '🔥 ¡Buen comienzo! Ya llevas el 25%', 'atora-lms' ),
			'50'  => __( '💪 ¡Vas a la mitad! No te detengas', 'atora-lms' ),
			'75'  => __( '⭐ ¡Casi lo logras! Un empujón más', 'atora-lms' ),
			'100' => __( '🎯 ¡Lección completada! Excelente trabajo', 'atora-lms' ),
		);

		$lesson_id = get_the_ID();
		?>
		<div id="atora-gamification-popup"
		     data-lesson-id="<?php echo absint( $lesson_id ); ?>"
		     data-messages='<?php echo esc_attr( wp_json_encode( $messages ) ); ?>'
		     style="display:none;position:fixed;bottom:24px;right:24px;z-index:9999;
		            background:var(--atora-accent);color:#fff;padding:16px 20px;
		            border-radius:var(--ac-radius);box-shadow:var(--ac-shadow-lg);
		            max-width:280px;font-size:15px;font-weight:600;
		            animation:atora-slide-up .3s ease;">
			<button onclick="document.getElementById('atora-gamification-popup').style.display='none'"
			        style="position:absolute;top:6px;right:8px;background:transparent;border:none;
			               color:rgba(255,255,255,.7);cursor:pointer;font-size:16px;">×</button>
			<span id="atora-gamification-message"></span>
		</div>

		<style>
		@keyframes atora-slide-up {
			from { transform:translateY(20px); opacity:0; }
			to   { transform:translateY(0);    opacity:1; }
		}
		</style>
		<?php
	}

	/**
	 * Obtiene los popups activos.
	 *
	 * @return \WP_Post[]
	 */
	private static function get_active_popups(): array {
		return get_posts( array(
			'post_type'      => 'atora_popup',
			'posts_per_page' => 10,
			'meta_query'     => array(
				array( 'key' => 'atora_popup_active', 'value' => '1' ),
			),
		) );
	}

	/**
	 * Verifica si un popup aplica a la página actual.
	 *
	 * @param \WP_Post $popup Popup.
	 * @return bool
	 */
	private static function popup_matches_current_page( \WP_Post $popup ): bool {
		$targeting = json_decode( get_post_meta( $popup->ID, 'atora_popup_targeting', true ), true );

		if ( empty( $targeting['pages'] ) || 'all' === $targeting['pages'] ) {
			return true;
		}

		if ( 'homepage' === $targeting['pages'] && is_home() ) {
			return true;
		}

		if ( ! empty( $targeting['url_pattern'] ) ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
			$current_url = home_url( $request_uri );
			return (bool) preg_match( '/' . preg_quote( $targeting['url_pattern'], '/' ) . '/', $current_url );
		}

		return false;
	}

	/**
	 * Renderiza el HTML de un popup.
	 *
	 * @param int   $popup_id ID del popup.
	 * @param array $config   Configuración del popup.
	 * @return void
	 */
	private static function render_popup_html( int $popup_id, array $config ): void {
		$trigger   = sanitize_key( $config['trigger'] ?? 'entry' );
		$frequency = sanitize_key( $config['frequency'] ?? 'once_session' );
		$delay     = absint( $config['delay_seconds'] ?? 3 );
		$scroll    = absint( $config['scroll_percent'] ?? 50 );
		$title     = sanitize_text_field( $config['title'] ?? '' );
		$content   = wp_kses_post( $config['content'] ?? '' );
		$form_id   = absint( $config['form_id'] ?? 0 );
		?>
		<div id="atora-popup-<?php echo absint( $popup_id ); ?>"
		     class="atora-popup"
		     data-trigger="<?php echo esc_attr( $trigger ); ?>"
		     data-frequency="<?php echo esc_attr( $frequency ); ?>"
		     data-delay="<?php echo absint( $delay ); ?>"
		     data-scroll="<?php echo absint( $scroll ); ?>"
		     style="display:none;position:fixed;inset:0;z-index:99999;
		            background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
			<div style="background:var(--atora-bg);border-radius:var(--ac-radius);padding:32px;
			            max-width:480px;width:90%;position:relative;box-shadow:var(--ac-shadow-lg);">
				<button onclick="atoraClosePopup(<?php echo absint( $popup_id ); ?>)"
				        style="position:absolute;top:12px;right:16px;background:transparent;border:none;
				               font-size:22px;cursor:pointer;color:var(--atora-text-muted);">×</button>
				<?php if ( $title ) : ?>
					<h3 style="margin:0 0 12px;font-size:20px;color:var(--atora-text);">
						<?php echo esc_html( $title ); ?>
					</h3>
				<?php endif; ?>
				<div><?php echo wp_kses_post( $content ); ?></div>
				<?php if ( $form_id ) : ?>
					<?php echo do_shortcode( '[atora_form id="' . absint( $form_id ) . '"]' ); // phpcs:ignore ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/** @return void */
	public static function enqueue_assets(): void {
		wp_enqueue_script(
			'atora-popups',
			ATORA_LMS_MODULES_URL . 'analytics/assets/popups.js',
			array(),
			ATORA_LMS_VERSION,
			true
		);
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-popups' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'clms-dashboard',
			__( 'Popups', 'atora-lms' ),
			__( 'Popups', 'atora-lms' ),
			'manage_options',
			'atora-popups',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'analytics/popups/admin.php';
				if ( file_exists( $view ) ) {
					try {
						require $view;
					} catch ( \Throwable $e ) {
						if ( function_exists( 'error_log' ) ) {
							error_log( '[ATORA Popups] Error al renderizar admin: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
						echo '<div class="wrap"><h1>' . esc_html__( 'Popups', 'atora-lms' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'No se pudo renderizar el panel de Popups. Revisa el log de errores.', 'atora-lms' ) . '</p></div></div>';
					}
					return;
				}

				echo '<div class="wrap"><h1>' . esc_html__( 'Popups', 'atora-lms' ) . '</h1><div class="notice notice-warning"><p>' . esc_html__( 'Vista de Popups no disponible. Falta el archivo modules/analytics/popups/admin.php.', 'atora-lms' ) . '</p></div></div>';
			}
		);
	}

	/** @return void */
	public static function ajax_save(): void {
		check_ajax_referer( 'atora_popup_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		$id    = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

		$data = array( 'post_type' => 'atora_popup', 'post_title' => $title, 'post_status' => 'publish' );
		if ( $id ) { $data['ID'] = $id; wp_update_post( $data ); } else { $id = wp_insert_post( $data ); }

		update_post_meta( (int) $id, 'atora_popup_active',    ! empty( $_POST['active'] ) ? 1 : 0 );
		update_post_meta( (int) $id, 'atora_popup_config',    wp_json_encode( $_POST['config'] ?? array() ) );
		update_post_meta( (int) $id, 'atora_popup_targeting', wp_json_encode( $_POST['targeting'] ?? array() ) );

		wp_send_json_success( array( 'id' => $id ) );
	}
}
