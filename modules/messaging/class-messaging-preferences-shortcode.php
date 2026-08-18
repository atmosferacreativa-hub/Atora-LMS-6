<?php
/**
 * [atora_preferencias] — PT-4.1/4.2 (sprint 6.4.0)
 *
 * Pantalla de preferencias de mensajería para el estudiante. Móvil
 * primero, lenguaje concreto, sin recarga completa al guardar — ver
 * los requisitos de UX no negociables de la OT de este sprint.
 *
 * @package ATORA_LMS\Messaging
 * @since   6.4.0
 */

namespace ATORA\Messaging;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Preferences_Shortcode {

	const NONCE_ACTION = 'atora_preferencias';

	public static function init(): void {
		add_shortcode( 'atora_preferencias', array( __CLASS__, 'render' ) );

		add_action( 'wp_ajax_atora_save_preferences', array( __CLASS__, 'ajax_save_preferences' ) );
		add_action( 'wp_ajax_atora_request_phone_verification', array( __CLASS__, 'ajax_request_verification' ) );
		add_action( 'wp_ajax_atora_verify_phone_code', array( __CLASS__, 'ajax_verify_code' ) );
	}

	/**
	 * @return string
	 */
	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p style="padding:16px;background:#fef2f2;border-radius:8px;color:#991b1b">'
				. esc_html__( 'Inicia sesión para administrar tus preferencias de notificación.', 'atora-lms' )
				. '</p>';
		}

		$user_id = get_current_user_id();
		$prefs   = Preferences::get( $user_id );
		$phone   = trim( (string) get_user_meta( $user_id, 'atora_phone', true ) );

		$whatsapp_consent = (bool) get_user_meta( $user_id, 'atora_consent_whatsapp', true );
		$whatsapp_verified = Preferences::is_phone_verified( $user_id );
		$whatsapp_active   = Preferences::is_whatsapp_active( $user_id );
		$telegram_active   = Preferences::is_telegram_active( $user_id );

		$category_labels = array(
			'academico'     => array(
				'title'       => __( 'Calificaciones y tareas', 'atora-lms' ),
				'description' => __( 'Avísame cuando califiquen mis tareas o me asignen algo nuevo.', 'atora-lms' ),
			),
			'recordatorios' => array(
				'title'       => __( 'Recordatorios', 'atora-lms' ),
				'description' => __( 'Avísame de vencimientos, inactividad y lecciones nuevas.', 'atora-lms' ),
			),
			'institucional' => array(
				'title'       => __( 'Avisos de mi institución', 'atora-lms' ),
				'description' => __( 'Anuncios de mi sección o de la academia.', 'atora-lms' ),
			),
		);

		ob_start();
		?>
		<div class="atora-prefs" style="max-width:480px;margin:0 auto;font-family:-apple-system,system-ui,sans-serif;color:#0f172a">
			<style>
				.atora-prefs *{box-sizing:border-box}
				.atora-prefs h2{font-size:18px;font-weight:700;margin:24px 0 4px}
				.atora-prefs .atora-prefs__lead{font-size:13px;color:#475569;margin:0 0 16px}
				.atora-prefs .atora-prefs__row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:14px 0;border-bottom:1px solid #e2e8f0}
				.atora-prefs .atora-prefs__row-text{flex:1;min-width:0}
				.atora-prefs .atora-prefs__row-title{font-size:15px;font-weight:600;margin:0 0 2px}
				.atora-prefs .atora-prefs__row-desc{font-size:13px;color:#64748b;margin:0}
				/* Interruptor accesible: 44px de alto mínimo, foco visible */
				.atora-prefs .atora-toggle{position:relative;display:inline-block;width:52px;height:30px;flex-shrink:0;margin-top:2px}
				.atora-prefs .atora-toggle input{position:absolute;opacity:0;width:44px;height:44px;top:-7px;left:-4px;margin:0;cursor:pointer;z-index:2}
				.atora-prefs .atora-toggle__track{position:absolute;inset:0;background:#cbd5e1;border-radius:999px;transition:background .15s;pointer-events:none}
				.atora-prefs .atora-toggle input:checked ~ .atora-toggle__track{background:#16a34a}
				.atora-prefs .atora-toggle input:focus-visible ~ .atora-toggle__track{outline:3px solid #1d4ed8;outline-offset:2px}
				.atora-prefs .atora-toggle__thumb{position:absolute;top:3px;left:3px;width:24px;height:24px;background:#fff;border-radius:50%;transition:transform .15s;pointer-events:none;box-shadow:0 1px 2px rgba(0,0,0,.2)}
				.atora-prefs .atora-toggle input:checked ~ .atora-toggle__thumb{transform:translateX(22px)}
				.atora-prefs .atora-toggle input:disabled ~ .atora-toggle__track{opacity:.5}
				.atora-prefs select,.atora-prefs input[type=time]{min-height:44px;font-size:15px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;background:#fff}
				.atora-prefs .atora-badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px}
				.atora-prefs .atora-badge--ok{background:#dcfce7;color:#166534}
				.atora-prefs .atora-badge--pending{background:#fef3c7;color:#92400e}
				.atora-prefs .atora-badge--off{background:#f1f5f9;color:#64748b}
				.atora-prefs button{min-height:44px;font-size:15px;font-weight:600;border-radius:8px;border:none;cursor:pointer;padding:0 20px}
				.atora-prefs .atora-btn-primary{background:#1d4ed8;color:#fff;width:100%;margin-top:20px}
				.atora-prefs .atora-btn-secondary{background:#f1f5f9;color:#0f172a}
				.atora-prefs .atora-prefs__verify-box{background:#f8fafc;border-radius:8px;padding:12px;margin-top:8px}
				.atora-prefs .atora-prefs__verify-box input[type=text]{min-height:44px;font-size:18px;letter-spacing:4px;text-align:center;width:120px;border:1px solid #cbd5e1;border-radius:8px}
				.atora-prefs .atora-prefs__msg{display:none;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:12px}
				.atora-prefs .atora-prefs__msg--ok{background:#dcfce7;color:#166534}
				.atora-prefs .atora-prefs__msg--err{background:#fef2f2;color:#991b1b}
				.atora-prefs a{color:#1d4ed8}
				.atora-prefs a:focus-visible,.atora-prefs button:focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}
				@media (max-width:360px){ .atora-prefs .atora-prefs__row{flex-wrap:wrap} }
			</style>

			<h1 style="font-size:20px;font-weight:800;margin:0 0 4px"><?php esc_html_e( 'Preferencias de notificación', 'atora-lms' ); ?></h1>
			<p class="atora-prefs__lead"><?php esc_html_e( 'Elige cómo y cuándo quieres que te avisemos.', 'atora-lms' ); ?></p>

			<h2><?php esc_html_e( 'Canales', 'atora-lms' ); ?></h2>

			<div class="atora-prefs__row">
				<div class="atora-prefs__row-text">
					<p class="atora-prefs__row-title"><?php esc_html_e( 'Correo', 'atora-lms' ); ?><span class="atora-badge atora-badge--ok"><?php esc_html_e( 'Activo', 'atora-lms' ); ?></span></p>
					<p class="atora-prefs__row-desc"><?php esc_html_e( 'Siempre disponible, no se puede apagar.', 'atora-lms' ); ?></p>
				</div>
			</div>

			<div class="atora-prefs__row">
				<div class="atora-prefs__row-text">
					<p class="atora-prefs__row-title">
						WhatsApp
						<?php if ( $whatsapp_active ) : ?>
							<span class="atora-badge atora-badge--ok"><?php esc_html_e( 'Verificado', 'atora-lms' ); ?></span>
						<?php elseif ( $whatsapp_consent && $phone && ! $whatsapp_verified ) : ?>
							<span class="atora-badge atora-badge--pending"><?php esc_html_e( 'Falta verificar', 'atora-lms' ); ?></span>
						<?php else : ?>
							<span class="atora-badge atora-badge--off"><?php esc_html_e( 'Inactivo', 'atora-lms' ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( ! $phone ) : ?>
						<p class="atora-prefs__row-desc"><?php esc_html_e( 'Agrega tu número de teléfono en tu perfil para activarlo.', 'atora-lms' ); ?></p>
					<?php elseif ( ! $whatsapp_verified ) : ?>
						<p class="atora-prefs__row-desc"><?php esc_html_e( 'Verifica tu número para recibir avisos por WhatsApp.', 'atora-lms' ); ?></p>
						<div class="atora-prefs__verify-box" data-atora-verify-box>
							<button type="button" class="atora-btn-secondary" data-atora-request-code><?php esc_html_e( 'Enviar código por WhatsApp', 'atora-lms' ); ?></button>
							<div data-atora-code-input style="display:none;margin-top:10px">
								<label for="atora-verify-code" style="display:block;font-size:12px;color:#64748b;margin-bottom:6px"><?php esc_html_e( 'Código de 6 dígitos (vence en 10 minutos)', 'atora-lms' ); ?></label>
								<input type="text" id="atora-verify-code" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
								<button type="button" class="atora-btn-secondary" data-atora-submit-code style="margin-left:8px"><?php esc_html_e( 'Confirmar', 'atora-lms' ); ?></button>
							</div>
							<p class="atora-prefs__msg" data-atora-verify-msg role="status"></p>
						</div>
					<?php else : ?>
						<p class="atora-prefs__row-desc"><?php esc_html_e( 'Puedes recibir avisos por WhatsApp.', 'atora-lms' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $phone && $whatsapp_verified ) : ?>
				<label class="atora-toggle">
					<input type="checkbox" name="whatsapp_consent" data-atora-field="whatsapp_consent" <?php checked( $whatsapp_consent ); ?> aria-label="<?php esc_attr_e( 'Activar WhatsApp', 'atora-lms' ); ?>">
					<span class="atora-toggle__track"></span><span class="atora-toggle__thumb"></span>
				</label>
				<?php endif; ?>
			</div>

			<div class="atora-prefs__row">
				<div class="atora-prefs__row-text">
					<p class="atora-prefs__row-title">
						Telegram
						<span class="atora-badge <?php echo $telegram_active ? 'atora-badge--ok' : 'atora-badge--off'; ?>"><?php echo $telegram_active ? esc_html__( 'Vinculado', 'atora-lms' ) : esc_html__( 'Sin vincular', 'atora-lms' ); ?></span>
					</p>
					<p class="atora-prefs__row-desc"><?php esc_html_e( 'Vincula tu cuenta desde el bot de Telegram de la academia.', 'atora-lms' ); ?></p>
				</div>
			</div>

			<h2><?php esc_html_e( 'Qué quieres recibir', 'atora-lms' ); ?></h2>
			<form data-atora-prefs-form>
				<?php wp_nonce_field( self::NONCE_ACTION, 'atora_prefs_nonce' ); ?>
				<?php foreach ( $category_labels as $cat => $label ) : ?>
				<div class="atora-prefs__row">
					<div class="atora-prefs__row-text">
						<p class="atora-prefs__row-title"><?php echo esc_html( $label['title'] ); ?></p>
						<p class="atora-prefs__row-desc"><?php echo esc_html( $label['description'] ); ?></p>
						<label style="display:block;margin-top:8px;font-size:12px;color:#475569">
							<?php esc_html_e( 'Frecuencia', 'atora-lms' ); ?>
							<select name="frequency[<?php echo esc_attr( $cat ); ?>]" style="display:block;margin-top:4px;width:100%">
								<option value="instant" <?php selected( $prefs['frequency'][ $cat ], 'instant' ); ?>><?php esc_html_e( 'Al instante', 'atora-lms' ); ?></option>
								<option value="digest" <?php selected( $prefs['frequency'][ $cat ], 'digest' ); ?>><?php esc_html_e( 'Un resumen al día', 'atora-lms' ); ?></option>
							</select>
						</label>
					</div>
					<label class="atora-toggle">
						<input type="checkbox" name="categories[<?php echo esc_attr( $cat ); ?>]" <?php checked( $prefs['categories'][ $cat ] ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Activar %s', 'atora-lms' ), $label['title'] ) ); ?>">
						<span class="atora-toggle__track"></span><span class="atora-toggle__thumb"></span>
					</label>
				</div>
				<?php endforeach; ?>

				<h2><?php esc_html_e( 'Horario de no molestar', 'atora-lms' ); ?></h2>
				<p class="atora-prefs__lead"><?php esc_html_e( 'No te escribiremos en este rango — los mensajes se envían apenas termine.', 'atora-lms' ); ?></p>
				<div style="display:flex;gap:12px;align-items:center;padding:8px 0">
					<label style="flex:1;font-size:13px;color:#475569">
						<?php esc_html_e( 'Desde', 'atora-lms' ); ?>
						<input type="time" name="dnd_start" value="<?php echo esc_attr( $prefs['dnd_start'] ); ?>" style="display:block;width:100%;margin-top:4px">
					</label>
					<label style="flex:1;font-size:13px;color:#475569">
						<?php esc_html_e( 'Hasta', 'atora-lms' ); ?>
						<input type="time" name="dnd_end" value="<?php echo esc_attr( $prefs['dnd_end'] ); ?>" style="display:block;width:100%;margin-top:4px">
					</label>
				</div>

				<button type="submit" class="atora-btn-primary"><?php esc_html_e( 'Guardar cambios', 'atora-lms' ); ?></button>
				<p class="atora-prefs__msg" data-atora-save-msg role="status"></p>
			</form>

			<p style="margin-top:28px;text-align:center">
				<a href="#" data-atora-unsubscribe-all><?php esc_html_e( 'Darme de baja de todo', 'atora-lms' ); ?></a>
			</p>
		</div>

		<script>
		(function(){
			var root = document.currentScript.previousElementSibling;
			if ( ! root || ! root.classList || ! root.classList.contains('atora-prefs') ) {
				root = document.querySelector('.atora-prefs');
			}
			if ( ! root ) { return; }

			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ); ?>;

			function post( action, data ) {
				var body = new URLSearchParams( Object.assign( { action: action, nonce: nonce }, data || {} ) );
				return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function( r ) { return r.json(); } );
			}

			function showMsg( el, text, ok ) {
				if ( ! el ) { return; }
				el.textContent = text;
				el.className = 'atora-prefs__msg ' + ( ok ? 'atora-prefs__msg--ok' : 'atora-prefs__msg--err' );
				el.style.display = 'block';
			}

			var form = root.querySelector('[data-atora-prefs-form]');
			if ( form ) {
				form.addEventListener( 'submit', function( e ) {
					e.preventDefault();
					var msg = form.querySelector('[data-atora-save-msg]');
					var fd  = new FormData( form );
					var payload = {};
					fd.forEach( function( v, k ) { payload[ k ] = v; } );
					// checkboxes ausentes = desactivados; FormData solo manda los marcados.
					<?php foreach ( array_keys( $category_labels ) as $cat ) : ?>
					if ( ! form.querySelector('[name="categories[<?php echo esc_js( $cat ); ?>]"]').checked ) { payload['categories[<?php echo esc_js( $cat ); ?>]'] = ''; }
					<?php endforeach; ?>

					post( 'atora_save_preferences', payload ).then( function( res ) {
						showMsg( msg, res && res.success ? '<?php echo esc_js( __( 'Guardado.', 'atora-lms' ) ); ?>' : ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'No se pudo guardar.', 'atora-lms' ) ); ?>', !!( res && res.success ) );
					} ).catch( function() {
						showMsg( msg, '<?php echo esc_js( __( 'No se pudo guardar. Intenta de nuevo.', 'atora-lms' ) ); ?>', false );
					} );
				} );
			}

			var verifyBox = root.querySelector('[data-atora-verify-box]');
			if ( verifyBox ) {
				var msgEl = verifyBox.querySelector('[data-atora-verify-msg]');
				var reqBtn = verifyBox.querySelector('[data-atora-request-code]');
				var codeWrap = verifyBox.querySelector('[data-atora-code-input]');

				if ( reqBtn ) {
					reqBtn.addEventListener( 'click', function() {
						reqBtn.disabled = true;
						post( 'atora_request_phone_verification', {} ).then( function( res ) {
							if ( res && res.success ) {
								codeWrap.style.display = 'block';
								showMsg( msgEl, '<?php echo esc_js( __( 'Te enviamos un código por WhatsApp.', 'atora-lms' ) ); ?>', true );
							} else {
								showMsg( msgEl, ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'No se pudo enviar el código.', 'atora-lms' ) ); ?>', false );
								reqBtn.disabled = false;
							}
						} );
					} );
				}

				var submitBtn = verifyBox.querySelector('[data-atora-submit-code]');
				if ( submitBtn ) {
					submitBtn.addEventListener( 'click', function() {
						var code = verifyBox.querySelector('#atora-verify-code').value;
						post( 'atora_verify_phone_code', { code: code } ).then( function( res ) {
							if ( res && res.success ) {
								showMsg( msgEl, '<?php echo esc_js( __( '¡Listo! WhatsApp verificado.', 'atora-lms' ) ); ?>', true );
								setTimeout( function() { window.location.reload(); }, 1200 );
							} else {
								showMsg( msgEl, ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'Código incorrecto.', 'atora-lms' ) ); ?>', false );
							}
						} );
					} );
				}
			}

			var unsub = root.querySelector('[data-atora-unsubscribe-all]');
			if ( unsub ) {
				unsub.addEventListener( 'click', function( e ) {
					e.preventDefault();
					if ( ! window.confirm( <?php echo wp_json_encode( __( '¿Seguro que quieres dejar de recibir todos los avisos?', 'atora-lms' ) ); ?> ) ) { return; }
					post( 'atora_save_preferences', { unsubscribe_all: '1' } ).then( function() { window.location.reload(); } );
				} );
			}
		})();
		</script>
		<?php
		return (string) ob_get_clean();
	}

	// ── AJAX ───────────────────────────────────────────────────────────────

	public static function ajax_save_preferences(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sesión requerida.', 'atora-lms' ) ), 401 );
		}
		$user_id = get_current_user_id();

		if ( ! empty( $_POST['unsubscribe_all'] ) ) {
			Preferences::unsubscribe_all( $user_id );
			wp_send_json_success();
		}

		$categories = array();
		foreach ( Preferences::CATEGORIES as $cat ) {
			$categories[ $cat ] = ! empty( $_POST['categories'][ $cat ] );
		}
		$frequency = array();
		foreach ( Preferences::CATEGORIES as $cat ) {
			$frequency[ $cat ] = isset( $_POST['frequency'][ $cat ] ) ? sanitize_key( (string) wp_unslash( $_POST['frequency'][ $cat ] ) ) : 'instant';
		}

		Preferences::save( $user_id, array(
			'categories' => $categories,
			'frequency'  => $frequency,
			'dnd_start'  => sanitize_text_field( (string) wp_unslash( $_POST['dnd_start'] ?? '' ) ),
			'dnd_end'    => sanitize_text_field( (string) wp_unslash( $_POST['dnd_end'] ?? '' ) ),
		) );

		if ( isset( $_POST['whatsapp_consent'] ) && Preferences::is_phone_verified( $user_id ) ) {
			update_user_meta( $user_id, 'atora_consent_whatsapp', ! empty( $_POST['whatsapp_consent'] ) );
		}

		wp_send_json_success();
	}

	public static function ajax_request_verification(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sesión requerida.', 'atora-lms' ) ), 401 );
		}

		$result = Preferences::request_phone_verification( get_current_user_id() );
		if ( $result['ok'] ) {
			wp_send_json_success();
		}
		wp_send_json_error( array( 'message' => self::reason_message( $result['reason'] ?? '' ) ) );
	}

	public static function ajax_verify_code(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sesión requerida.', 'atora-lms' ) ), 401 );
		}

		$code   = sanitize_text_field( (string) wp_unslash( $_POST['code'] ?? '' ) );
		$result = Preferences::verify_phone_code( get_current_user_id(), $code );

		if ( $result['ok'] ) {
			wp_send_json_success();
		}
		wp_send_json_error( array( 'message' => self::reason_message( $result['reason'] ?? '' ) ) );
	}

	/**
	 * PT-UX — "ningún error genérico": cada motivo de fallo tiene un
	 * mensaje concreto y accionable, no un "algo salió mal".
	 *
	 * @param string $reason
	 * @return string
	 */
	private static function reason_message( string $reason ): string {
		$map = array(
			'sin_telefono'          => __( 'No tienes un número de teléfono guardado. Agrégalo en tu perfil primero.', 'atora-lms' ),
			'limite_intentos'       => __( 'Ya pediste el código varias veces. Espera una hora antes de volver a intentar.', 'atora-lms' ),
			'whatsapp_no_disponible'=> __( 'WhatsApp no está disponible en este momento. Intenta más tarde.', 'atora-lms' ),
			'envio_fallido'         => __( 'No se pudo enviar el código. Verifica tu número e intenta de nuevo.', 'atora-lms' ),
			'formato_invalido'      => __( 'El código debe tener 6 dígitos.', 'atora-lms' ),
			'codigo_expirado'       => __( 'El código venció. Pide uno nuevo.', 'atora-lms' ),
			'codigo_incorrecto'     => __( 'Ese código no es correcto. Revisa el mensaje de WhatsApp e intenta de nuevo.', 'atora-lms' ),
		);

		return $map[ $reason ] ?? __( 'No se pudo completar la acción.', 'atora-lms' );
	}
}
