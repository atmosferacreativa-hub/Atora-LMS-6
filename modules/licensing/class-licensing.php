<?php
/**
 * ATORA LMS — Licensing & Updates
 *
 * Sistema comercial de licencias con actualizaciones self-hosted.
 *
 * Diseño:
 *  - El plugin funciona SIN licencia (no se bloquea al cliente jamás).
 *  - Con licencia válida: recibe actualizaciones automáticas desde
 *    el servidor de licencias (Update URI estándar de WP 5.8+).
 *  - Verificación semanal en segundo plano (cron), con caché de 12h
 *    para no golpear el servidor en cada carga de admin.
 *
 * API remota esperada (implementar en atora-lms.com — ver docs/LICENSING-SERVER.md):
 *  POST {server}/wp-json/atora-licensing/v1/activate    { license_key, site_url }
 *  POST {server}/wp-json/atora-licensing/v1/deactivate  { license_key, site_url }
 *  POST {server}/wp-json/atora-licensing/v1/check       { license_key, site_url, version }
 *  GET  {server}/wp-json/atora-licensing/v1/update?license_key=&site_url=&version=
 *       → { version, package (zip URL firmada), tested, requires_php, sections{...} }
 *
 * @package ATORA_LMS
 * @since   6.2.0
 */

namespace ATORA\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Licensing
 */
class Licensing {

	const OPT_KEY      = 'atora_license_key';
	const OPT_STATUS   = 'atora_license_status';   // valid | invalid | expired | inactive.
	const OPT_DATA     = 'atora_license_data';     // Payload del servidor (expira, plan, sites).
	const TRANSIENT    = 'atora_license_last_check';
	const CRON_HOOK    = 'atora_license_check_cron';
	const SERVER_URL   = 'https://atora-lms.com';
	const API_NS       = '/wp-json/atora-licensing/v1';

	/**
	 * Bootstrap del módulo.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Actualizaciones self-hosted — hook estándar por hostname del Update URI.
		add_filter( 'update_plugins_atora-lms.com', array( __CLASS__, 'check_for_update' ), 10, 4 );

		// Info del plugin en el modal "Ver detalles".
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 99 );
			add_action( 'wp_ajax_atora_license_action', array( __CLASS__, 'ajax_license_action' ) );
			add_action( 'admin_notices', array( __CLASS__, 'maybe_notice' ) );
		}

		// Cron semanal de verificación.
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_check' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	// ── Estado ───────────────────────────────────────────────────────────────

	/**
	 * ¿Hay licencia válida?
	 *
	 * @return bool
	 */
	public static function is_valid(): bool {
		return 'valid' === get_option( self::OPT_STATUS, 'inactive' );
	}

	/**
	 * Clave enmascarada para UI.
	 *
	 * @return string
	 */
	public static function masked_key(): string {
		$key = (string) get_option( self::OPT_KEY, '' );
		if ( strlen( $key ) < 9 ) {
			return $key ? '••••' : '';
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	// ── API remota ───────────────────────────────────────────────────────────

	/**
	 * Llamada al servidor de licencias.
	 *
	 * @param string              $endpoint activate|deactivate|check.
	 * @param array<string,mixed> $body     Cuerpo extra.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function api( string $endpoint, array $body = array() ) {
		$url  = self::SERVER_URL . self::API_NS . '/' . $endpoint;
		$body = array_merge(
			array(
				'license_key' => (string) get_option( self::OPT_KEY, '' ),
				'site_url'    => home_url(),
				'version'     => defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '',
			),
			$body
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $data ) ) {
			return new \WP_Error(
				'atora_license_http',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Servidor de licencias respondió con error (HTTP %d).', 'atora-lms' ),
					$code
				)
			);
		}

		return $data;
	}

	/**
	 * Activa una licencia.
	 *
	 * @param string $key Clave.
	 * @return array{ok:bool,message:string}
	 */
	public static function activate( string $key ): array {
		$key = sanitize_text_field( $key );
		if ( '' === $key ) {
			return array( 'ok' => false, 'message' => __( 'Introduce una clave de licencia.', 'atora-lms' ) );
		}

		update_option( self::OPT_KEY, $key, false );
		$result = self::api( 'activate' );

		if ( is_wp_error( $result ) ) {
			// Sin conexión no invalidamos: queda "inactive" y se reintenta por cron.
			update_option( self::OPT_STATUS, 'inactive', false );
			return array( 'ok' => false, 'message' => $result->get_error_message() );
		}

		$status = ! empty( $result['valid'] ) ? 'valid' : ( $result['status'] ?? 'invalid' );
		update_option( self::OPT_STATUS, $status, false );
		update_option( self::OPT_DATA, $result, false );
		delete_transient( self::TRANSIENT );

		return array(
			'ok'      => 'valid' === $status,
			'message' => 'valid' === $status
				? __( 'Licencia activada. Las actualizaciones automáticas están habilitadas.', 'atora-lms' )
				: (string) ( $result['message'] ?? __( 'La licencia no es válida para este sitio.', 'atora-lms' ) ),
		);
	}

	/**
	 * Desactiva la licencia en este sitio.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function deactivate(): array {
		self::api( 'deactivate' ); // Best effort; ignoramos error de red.
		update_option( self::OPT_STATUS, 'inactive', false );
		delete_option( self::OPT_DATA );
		delete_transient( self::TRANSIENT );
		return array( 'ok' => true, 'message' => __( 'Licencia desactivada en este sitio.', 'atora-lms' ) );
	}

	/**
	 * Verificación periódica (cron) con caché.
	 *
	 * @return void
	 */
	public static function cron_check(): void {
		if ( '' === (string) get_option( self::OPT_KEY, '' ) ) {
			return;
		}
		if ( get_transient( self::TRANSIENT ) ) {
			return;
		}
		set_transient( self::TRANSIENT, 1, 12 * HOUR_IN_SECONDS );

		$result = self::api( 'check' );
		if ( is_wp_error( $result ) ) {
			return; // Fallo de red: mantenemos el estado anterior (nunca degradamos por un timeout).
		}
		$status = ! empty( $result['valid'] ) ? 'valid' : ( $result['status'] ?? 'invalid' );
		update_option( self::OPT_STATUS, $status, false );
		update_option( self::OPT_DATA, $result, false );
	}

	// ── Actualizaciones self-hosted ──────────────────────────────────────────

	/**
	 * Filtro update_plugins_{hostname} (WP 5.8+).
	 *
	 * @param array|false $update      Update data previa.
	 * @param array       $plugin_data Cabeceras del plugin.
	 * @param string      $plugin_file Ruta relativa del plugin.
	 * @param array       $locales     Locales.
	 * @return array|false
	 */
	public static function check_for_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( false === strpos( (string) $plugin_file, 'atora_lms.php' ) ) {
			return $update;
		}
		if ( ! self::is_valid() ) {
			return $update; // Sin licencia válida no hay update (el plugin sigue funcionando).
		}

		$cached = get_transient( 'atora_update_payload' );
		if ( false === $cached ) {
			$url = add_query_arg(
				array(
					'license_key' => rawurlencode( (string) get_option( self::OPT_KEY, '' ) ),
					'site_url'    => rawurlencode( home_url() ),
					'version'     => rawurlencode( defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '' ),
				),
				self::SERVER_URL . self::API_NS . '/update'
			);
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			$cached   = array();
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				$cached  = is_array( $decoded ) ? $decoded : array();
			}
			set_transient( 'atora_update_payload', $cached, 6 * HOUR_IN_SECONDS );
		}

		if ( empty( $cached['version'] ) || empty( $cached['package'] ) ) {
			return $update;
		}
		if ( ! version_compare( (string) $cached['version'], (string) ( $plugin_data['Version'] ?? '0' ), '>' ) ) {
			return $update;
		}

		return array(
			'id'           => 'atora-lms.com/atora-lms',
			'slug'         => 'atora-lms',
			'version'      => (string) $cached['version'],
			'url'          => self::SERVER_URL,
			'package'      => (string) $cached['package'],
			'tested'       => (string) ( $cached['tested'] ?? '' ),
			'requires_php' => (string) ( $cached['requires_php'] ?? '8.1' ),
		);
	}

	/**
	 * Modal "Ver detalles" del plugin.
	 *
	 * @param false|object|array $result Resultado previo.
	 * @param string             $action Acción solicitada.
	 * @param object             $args   Args.
	 * @return false|object
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'atora-lms' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$cached = get_transient( 'atora_update_payload' );
		if ( ! is_array( $cached ) || empty( $cached['version'] ) ) {
			return $result;
		}
		$info                = new \stdClass();
		$info->name          = 'ATORA LMS';
		$info->slug          = 'atora-lms';
		$info->version       = (string) $cached['version'];
		$info->author        = '<a href="https://mundocap.com">@mundocap</a>';
		$info->homepage      = self::SERVER_URL;
		$info->requires_php  = (string) ( $cached['requires_php'] ?? '8.1' );
		$info->tested        = (string) ( $cached['tested'] ?? '' );
		$info->download_link = (string) ( $cached['package'] ?? '' );
		$info->sections      = is_array( $cached['sections'] ?? null )
			? array_map( 'wp_kses_post', $cached['sections'] )
			: array( 'changelog' => __( 'Consulta el changelog en atora-lms.com.', 'atora-lms' ) );
		return $info;
	}

	// ── Admin UI ─────────────────────────────────────────────────────────────

	/**
	 * Página de licencia bajo el menú ATORA.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'', // PT-4.4.3: reubicado bajo el hub "Ajustes" (clms-settings-hub).
			__( 'Licencia', 'atora-lms' ),
			__( 'Licencia', 'atora-lms' ),
			'manage_options',
			'atora-license',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render de la página de licencia.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		$status = (string) get_option( self::OPT_STATUS, 'inactive' );
		$data   = (array) get_option( self::OPT_DATA, array() );
		$nonce  = wp_create_nonce( 'atora_license_admin' );
		$badges = array(
			'valid'    => array( '#00a32a', __( 'Activa', 'atora-lms' ) ),
			'expired'  => array( '#dba617', __( 'Expirada', 'atora-lms' ) ),
			'invalid'  => array( '#d63638', __( 'Inválida', 'atora-lms' ) ),
			'inactive' => array( '#8c8f94', __( 'Sin activar', 'atora-lms' ) ),
		);
		list( $color, $label ) = $badges[ $status ] ?? $badges['inactive'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ATORA LMS — Licencia', 'atora-lms' ); ?></h1>
			<div class="card" style="max-width:640px;padding:20px">
				<p>
					<strong><?php esc_html_e( 'Estado:', 'atora-lms' ); ?></strong>
					<span style="display:inline-block;padding:2px 10px;border-radius:12px;color:#fff;background:<?php echo esc_attr( $color ); ?>">
						<?php echo esc_html( $label ); ?>
					</span>
					<?php if ( ! empty( $data['expires'] ) ) : ?>
						&nbsp;·&nbsp;<?php esc_html_e( 'Expira:', 'atora-lms' ); ?> <?php echo esc_html( (string) $data['expires'] ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $data['plan'] ) ) : ?>
						&nbsp;·&nbsp;<?php esc_html_e( 'Plan:', 'atora-lms' ); ?> <?php echo esc_html( (string) $data['plan'] ); ?>
					<?php endif; ?>
				</p>
				<p>
					<input type="text" id="atora-license-key" class="regular-text"
						placeholder="<?php esc_attr_e( 'Clave de licencia', 'atora-lms' ); ?>"
						value="<?php echo esc_attr( self::masked_key() ); ?>" />
				</p>
				<p>
					<button class="button button-primary" id="atora-license-activate"><?php esc_html_e( 'Activar', 'atora-lms' ); ?></button>
					<button class="button" id="atora-license-deactivate"><?php esc_html_e( 'Desactivar', 'atora-lms' ); ?></button>
					<span id="atora-license-msg" style="margin-left:8px"></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'Sin licencia, el plugin sigue funcionando pero no recibe actualizaciones automáticas ni soporte.', 'atora-lms' ); ?>
				</p>
			</div>

			<div class="card" style="max-width:640px;padding:20px;margin-top:16px;border-left:4px solid #d63638">
				<h2 style="margin-top:0"><?php esc_html_e( 'Zona de riesgo', 'atora-lms' ); ?></h2>
				<?php
				if ( isset( $_POST['atora_uninstall_toggle'] ) && check_admin_referer( 'atora_uninstall_toggle' ) ) {
					update_option( 'atora_lms_delete_data_on_uninstall', ! empty( $_POST['atora_delete_data'] ), false );
					echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Preferencia guardada.', 'atora-lms' ) . '</p></div>';
				}
				$delete_on_uninstall = (bool) get_option( 'atora_lms_delete_data_on_uninstall', false );
				?>
				<form method="post">
					<?php wp_nonce_field( 'atora_uninstall_toggle' ); ?>
					<input type="hidden" name="atora_uninstall_toggle" value="1" />
					<label>
						<input type="checkbox" name="atora_delete_data" value="1" <?php checked( $delete_on_uninstall ); ?> />
						<?php esc_html_e( 'Eliminar TODOS los datos (51 tablas, cursos, matrículas, CRM, certificados) al desinstalar el plugin.', 'atora-lms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Por defecto los datos se conservan para proteger tu operación. Activa esto solo si abandonas ATORA definitivamente.', 'atora-lms' ); ?></p>
					<p><button class="button"><?php esc_html_e( 'Guardar preferencia', 'atora-lms' ); ?></button></p>
				</form>
			</div>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			function act(op){
				var key = document.getElementById('atora-license-key').value;
				var msg = document.getElementById('atora-license-msg');
				msg.textContent = '…';
				var body = new URLSearchParams({ action:'atora_license_action', _wpnonce:nonce, op:op, key:key });
				fetch(ajaxurl, { method:'POST', credentials:'same-origin',
					headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString() })
				.then(function(r){ return r.json(); })
				.then(function(j){ msg.textContent = (j.data && j.data.message) || ''; if (j.success) { setTimeout(function(){ location.reload(); }, 900); } })
				.catch(function(){ msg.textContent = 'Error de red'; });
			}
			document.getElementById('atora-license-activate').addEventListener('click', function(){ act('activate'); });
			document.getElementById('atora-license-deactivate').addEventListener('click', function(){ act('deactivate'); });
		})();
		</script>
		<?php
	}

	/**
	 * Handler AJAX activar/desactivar.
	 *
	 * @return void
	 */
	public static function ajax_license_action(): void {
		check_ajax_referer( 'atora_license_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}
		$op  = isset( $_POST['op'] ) ? sanitize_key( (string) wp_unslash( $_POST['op'] ) ) : '';
		$key = isset( $_POST['key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['key'] ) ) : '';

		// Si el input llega enmascarado (••••), conservar la clave guardada.
		if ( false !== strpos( $key, '•' ) ) {
			$key = (string) get_option( self::OPT_KEY, '' );
		}

		$result = ( 'deactivate' === $op ) ? self::deactivate() : self::activate( $key );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Aviso suave si no hay licencia (solo en pantallas del plugin, 1 vez por semana).
	 *
	 * @return void
	 */
	public static function maybe_notice(): void {
		if ( self::is_valid() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'clms' ) ) {
			return;
		}
		if ( get_transient( 'atora_license_notice_snooze' ) ) {
			return;
		}
		set_transient( 'atora_license_notice_snooze', 1, WEEK_IN_SECONDS );
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>ATORA LMS:</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Activa tu licencia para recibir actualizaciones automáticas y soporte.', 'atora-lms' ),
			esc_url( admin_url( 'admin.php?page=atora-license' ) ),
			esc_html__( 'Activar licencia →', 'atora-lms' )
		);
	}
}
