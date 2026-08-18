<?php
/**
 * CLMS_Whisper_Live — Transcripción de audio en vivo con Whisper (OpenAI)
 *
 * Flujo:
 *   1. El navegador graba audio con MediaRecorder (WebM/Opus, chunks de ~5s)
 *   2. Cada chunk se envía vía AJAX (multipart/form-data) al endpoint
 *      wp_ajax_clms_whisper_chunk
 *   3. PHP reenvía el audio al API de Whisper y devuelve el texto transcrito
 *   4. El JS acumula los textos y los muestra en tiempo real
 *   5. Al finalizar la sesión, el texto completo puede guardarse en
 *      post_meta del objeto (lección, foro, etc.)
 *
 * Shortcode: [clms_whisper_recorder post_id="123" field="notes"]
 *   - post_id: ID del post donde guardar la transcripción (opcional)
 *   - field:   meta key donde guardar (default: _clms_whisper_transcript)
 *
 * También disponible como widget flotante en la vista de lección.
 *
 * Requisitos:
 *   - API key de Whisper (OpenAI) en clms_ai_settings['whisper_api_key']
 *   - PHP con soporte de tmp files (sys_get_temp_dir())
 *   - Navegador con MediaRecorder API (Chrome, Firefox, Edge, Safari ≥ 14.1)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Whisper_Live {

	const AJAX_CHUNK  = 'clms_whisper_chunk';
	const AJAX_SAVE   = 'clms_whisper_save';
	const META_KEY    = '_clms_whisper_transcript';
	const MAX_SIZE_MB = 24; // Whisper API límite: 25 MB

	private static $instance = null;
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'wp_ajax_'        . self::AJAX_CHUNK, array( $this, 'ajax_chunk' ) );
		add_action( 'wp_ajax_'        . self::AJAX_SAVE,  array( $this, 'ajax_save' ) );
		add_shortcode( 'clms_whisper_recorder', array( $this, 'shortcode' ) );
		// Widget flotante en lecciones
		add_action( 'wp_footer', array( $this, 'inject_footer_widget' ) );
	}

	// ── AJAX: transcribir chunk de audio ─────────────────────────────────────────

	public function ajax_chunk() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión para transcribir audio.', 'atora-lms' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_whisper_live' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		$api_key = $this->get_api_key();
		if ( ! $api_key ) {
			wp_send_json_error( array( 'message' => __( 'API key de Whisper no configurada.', 'atora-lms' ) ) );
		}

		if ( empty( $_FILES['audio'] ) || UPLOAD_ERR_OK !== $_FILES['audio']['error'] ) {
			wp_send_json_error( array( 'message' => __( 'No se recibió audio.', 'atora-lms' ) ) );
		}

		$file     = $_FILES['audio'];
		$size_mb  = $file['size'] / 1024 / 1024;

		if ( $size_mb > self::MAX_SIZE_MB ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Chunk demasiado grande (%s MB). Máx: %s MB.', 'atora-lms' ), round( $size_mb, 1 ), self::MAX_SIZE_MB ) ) );
		}

		// Guardar temporalmente con extensión correcta para que Whisper la reconozca
		$tmp_path = $this->save_tmp_audio( $file['tmp_name'], $file['type'] );
		if ( ! $tmp_path ) {
			wp_send_json_error( array( 'message' => __( 'Error al procesar el archivo de audio.', 'atora-lms' ) ) );
		}

		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'es';
		$text     = $this->transcribe( $tmp_path, $api_key, $language );

		// Limpiar temporal
		@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}

		wp_send_json_success( array(
			'text'      => sanitize_textarea_field( $text ),
			'timestamp' => current_time( 'mysql' ),
		) );
	}

	// ── AJAX: guardar transcripción completa ──────────────────────────────────────

	public function ajax_save() {
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! wp_verify_nonce( $nonce, 'clms_whisper_save_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Sin sesión.', 'atora-lms' ) ), 403 );
		}

		if ( $post_id && ! CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		$text      = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$field     = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : self::META_KEY;

		if ( ! $text ) {
			wp_send_json_error( array( 'message' => __( 'Sin texto para guardar.', 'atora-lms' ) ) );
		}

		if ( $post_id ) {
			update_post_meta( $post_id, $field, $text );
		}

		// También guardar en user_meta como historial personal
		$user_id  = get_current_user_id();
		$history  = (array) get_user_meta( $user_id, '_clms_whisper_history', true );
		$history[] = array(
			'text'     => mb_substr( $text, 0, 500 ),
			'post_id'  => $post_id,
			'saved_at' => current_time( 'mysql' ),
		);
		// Mantener solo las últimas 20 transcripciones
		if ( count( $history ) > 20 ) {
			$history = array_slice( $history, -20 );
		}
		update_user_meta( $user_id, '_clms_whisper_history', $history );

		wp_send_json_success( array(
			'message' => __( 'Transcripción guardada.', 'atora-lms' ),
			'post_id' => $post_id,
			'field'   => $field,
		) );
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────────

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'post_id'  => 0,
			'field'    => self::META_KEY,
			'language' => 'es',
			'label'    => 'Grabar nota de voz',
		), $atts );

		$post_id  = absint( $atts['post_id'] );
		$field    = sanitize_key( $atts['field'] );
		$language = sanitize_text_field( $atts['language'] );

		return $this->render_widget( $post_id, $field, $language, esc_html( $atts['label'] ), 'inline' );
	}

	// ── Widget flotante en lecciones ──────────────────────────────────────────────

	public function inject_footer_widget() {
		if ( ! is_singular( 'lm_lesson' ) ) { return; }
		if ( ! is_user_logged_in() ) { return; }

		$api_key = $this->get_api_key();
		if ( ! $api_key ) { return; } // No mostrar si no está configurado

		$post_id = get_the_ID();
		echo $this->render_widget( $post_id, self::META_KEY, 'es', 'Transcribir voz', 'floating' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// ── Render del widget ─────────────────────────────────────────────────────────

	private function render_widget( $post_id, $field, $language, $label, $mode = 'inline' ) {
		$nonce       = wp_create_nonce( 'clms_whisper_live' );
		$save_nonce  = wp_create_nonce( 'clms_whisper_save_' . $post_id );
		$ajax_url    = admin_url( 'admin-ajax.php' );
		$uid         = 'clms-wl-' . uniqid();

		$existing = $post_id ? (string) get_post_meta( $post_id, $field, true ) : '';

		$float_style = 'floating' === $mode
			? 'position:fixed;bottom:20px;left:20px;z-index:99999;max-width:320px;box-shadow:0 4px 20px rgba(0,0,0,.15);'
			: '';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $uid ); ?>"
		     class="clms-whisper-widget"
		     style="<?php echo esc_attr( $float_style ); ?>background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:14px 16px;font-family:sans-serif;font-size:13px;">

			<?php if ( 'floating' === $mode ) : ?>
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
				<span style="font-weight:600;font-size:13px">🎙️ Transcripción de voz</span>
				<button onclick="document.getElementById('<?php echo esc_attr( $uid ); ?>').style.display='none'"
				        style="background:none;border:none;cursor:pointer;font-size:16px;color:#999"
				        title="Cerrar">✕</button>
			</div>
			<?php else : ?>
			<div style="font-weight:600;margin-bottom:10px">🎙️ <?php echo esc_html( $label ); ?></div>
			<?php endif; ?>

			<div style="display:flex;gap:8px;margin-bottom:10px">
				<button id="<?php echo esc_attr( $uid ); ?>-start"
				        style="background:#e53935;color:#fff;border:none;border-radius:6px;padding:7px 14px;cursor:pointer;font-size:13px;flex:1">
					⏺ Grabar
				</button>
				<button id="<?php echo esc_attr( $uid ); ?>-stop"
				        disabled
				        style="background:#e0e0e0;color:#999;border:none;border-radius:6px;padding:7px 14px;cursor:not-allowed;font-size:13px;flex:1">
					⏹ Detener
				</button>
			</div>

			<div id="<?php echo esc_attr( $uid ); ?>-status"
			     style="font-size:11px;color:#888;margin-bottom:8px;min-height:16px"></div>

			<textarea id="<?php echo esc_attr( $uid ); ?>-text"
			          rows="4"
			          style="width:100%;box-sizing:border-box;border:1px solid #ccc;border-radius:6px;padding:8px;font-size:13px;resize:vertical"
			          placeholder="La transcripción aparecerá aquí..."
			><?php echo esc_textarea( $existing ); ?></textarea>

			<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
				<button id="<?php echo esc_attr( $uid ); ?>-clear"
				        style="background:none;border:1px solid #ccc;border-radius:6px;padding:5px 12px;cursor:pointer;font-size:12px;color:#555">
					Limpiar
				</button>
				<?php if ( $post_id && is_user_logged_in() ) : ?>
				<button id="<?php echo esc_attr( $uid ); ?>-save"
				        style="background:#1976d2;color:#fff;border:none;border-radius:6px;padding:5px 12px;cursor:pointer;font-size:12px">
					Guardar
				</button>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function(){
			var uid        = '<?php echo esc_js( $uid ); ?>';
			var nonce      = '<?php echo esc_js( $nonce ); ?>';
			var saveNonce  = '<?php echo esc_js( $save_nonce ); ?>';
			var ajaxUrl    = '<?php echo esc_js( $ajax_url ); ?>';
			var postId     = <?php echo (int) $post_id; ?>;
			var field      = '<?php echo esc_js( $field ); ?>';
			var language   = '<?php echo esc_js( $language ); ?>';

			var btnStart   = document.getElementById(uid + '-start');
			var btnStop    = document.getElementById(uid + '-stop');
			var btnClear   = document.getElementById(uid + '-clear');
			var btnSave    = document.getElementById(uid + '-save');
			var status     = document.getElementById(uid + '-status');
			var textarea   = document.getElementById(uid + '-text');

			var mediaRec   = null;
			var chunks     = [];
			var chunkTimer = null;
			var isRecording = false;
			var CHUNK_MS   = 5000; // enviar cada 5 segundos

			// Verificar soporte
			if (!navigator.mediaDevices || !window.MediaRecorder) {
				btnStart.disabled = true;
				status.textContent = '⚠️ Tu navegador no soporta grabación de audio.';
				return;
			}

			btnStart.addEventListener('click', startRecording);
			btnStop.addEventListener('click', stopRecording);
			btnClear.addEventListener('click', function(){ textarea.value = ''; });
			if (btnSave) btnSave.addEventListener('click', saveTranscription);

			function startRecording() {
				navigator.mediaDevices.getUserMedia({ audio: true })
					.then(function(stream) {
						var mimeType = 'audio/webm;codecs=opus';
						if (!MediaRecorder.isTypeSupported(mimeType)) {
							mimeType = 'audio/webm';
						}
						if (!MediaRecorder.isTypeSupported(mimeType)) {
							mimeType = ''; // dejar que el browser elija
						}

						mediaRec = mimeType
							? new MediaRecorder(stream, { mimeType: mimeType })
							: new MediaRecorder(stream);

						mediaRec.ondataavailable = function(e) {
							if (e.data && e.data.size > 0) {
								chunks.push(e.data);
							}
						};

						mediaRec.onstop = function() {
							if (chunks.length) {
								sendChunks(new Blob(chunks, { type: mediaRec.mimeType }));
								chunks = [];
							}
							stream.getTracks().forEach(function(t){ t.stop(); });
						};

						// Solicitar datos cada CHUNK_MS
						mediaRec.start(CHUNK_MS);
						isRecording = true;

						// Enviar chunks acumulados periódicamente (mientras graba)
						chunkTimer = setInterval(function() {
							if (mediaRec && mediaRec.state === 'recording' && chunks.length) {
								var blob = new Blob(chunks, { type: mediaRec.mimeType });
								chunks = [];
								sendChunks(blob);
							}
						}, CHUNK_MS * 2);

						setUI(true);
						status.textContent = '🔴 Grabando...';
					})
					.catch(function(err) {
						status.textContent = '⚠️ No se pudo acceder al micrófono: ' + err.message;
					});
			}

			function stopRecording() {
				clearInterval(chunkTimer);
				if (mediaRec && mediaRec.state !== 'inactive') {
					mediaRec.stop();
				}
				isRecording = false;
				setUI(false);
				status.textContent = '⏸ Procesando último segmento...';
			}

			function sendChunks(blob) {
				if (!blob || blob.size < 1000) { return; } // ignorar chunks vacíos

				var ext = 'webm';
				if (blob.type.includes('ogg')) ext = 'ogg';
				if (blob.type.includes('mp4')) ext = 'mp4';

				var fd = new FormData();
				fd.append('action', 'clms_whisper_chunk');
				fd.append('nonce', nonce);
				fd.append('language', language);
				fd.append('audio', blob, 'chunk.' + ext);

				status.textContent = '⏳ Transcribiendo...';

				fetch(ajaxUrl, { method:'POST', body:fd })
					.then(function(r){ return r.json(); })
					.then(function(res) {
						if (res.success && res.data.text) {
							var t = res.data.text.trim();
							if (t) {
								textarea.value += (textarea.value ? ' ' : '') + t;
								textarea.scrollTop = textarea.scrollHeight;
							}
							status.textContent = isRecording ? '🔴 Grabando...' : '✅ Listo';
						} else {
							status.textContent = '⚠️ ' + (res.data.message || 'Error al transcribir');
						}
					})
					.catch(function() {
						status.textContent = '⚠️ Error de conexión';
					});
			}

			function saveTranscription() {
				var text = textarea.value.trim();
				if (!text) { status.textContent = 'Sin texto para guardar.'; return; }

				var fd = new FormData();
				fd.append('action', 'clms_whisper_save');
				fd.append('nonce', saveNonce);
				fd.append('post_id', postId);
				fd.append('field', field);
				fd.append('text', text);

				fetch(ajaxUrl, { method:'POST', body:fd })
					.then(function(r){ return r.json(); })
					.then(function(res) {
						status.textContent = res.success ? '✅ Guardado' : '⚠️ ' + (res.data.message || 'Error');
					});
			}

			function setUI(recording) {
				btnStart.disabled   = recording;
				btnStop.disabled    = !recording;
				btnStart.style.background = recording ? '#aaa' : '#e53935';
				btnStop.style.background  = recording ? '#333' : '#e0e0e0';
				btnStop.style.color       = recording ? '#fff' : '#999';
				btnStop.style.cursor      = recording ? 'pointer' : 'not-allowed';
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	// ── Transcripción via Whisper API ─────────────────────────────────────────────

	/**
	 * Envía un archivo de audio a Whisper y devuelve el texto transcrito.
	 *
	 * @param string $file_path  Ruta al archivo temporal con extensión correcta.
	 * @param string $api_key
	 * @param string $language   Código ISO 639-1 ('es', 'en', etc.)
	 * @return string|WP_Error
	 */
	private function transcribe( $file_path, $api_key, $language = 'es' ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'no_file', __( 'Archivo de audio no encontrado.', 'atora-lms' ) );
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $manager || ! method_exists( $manager, 'transcribe_audio' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		$result = $manager->transcribe_audio(
			$file_path,
			array(
				'api_key'        => $api_key,
				'language'       => $language,
				'response_format' => 'json',
				'timeout'        => 60,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (string) ( $result['text'] ?? '' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────────

	private function save_tmp_audio( $tmp_name, $mime_type ) {
		$ext_map = array(
			'audio/webm'       => 'webm',
			'audio/ogg'        => 'ogg',
			'audio/mpeg'       => 'mp3',
			'audio/mp4'        => 'mp4',
			'audio/wav'        => 'wav',
			'audio/x-wav'      => 'wav',
			'video/webm'       => 'webm', // MediaRecorder a veces reporta video/webm
		);

		$ext      = $ext_map[ $mime_type ] ?? 'webm';
		$tmp_dest = sys_get_temp_dir() . '/clms_whisper_' . wp_generate_password( 12, false ) . '.' . $ext;

		if ( ! move_uploaded_file( $tmp_name, $tmp_dest ) ) {
			return false;
		}

		return $tmp_dest;
	}

	private function get_api_key() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_whisper_api_key' ) ) {
			return (string) CLMS_AI_Settings_Service::get_whisper_api_key();
		}

		$options     = (array) get_option( 'clms_ai_settings', array() );
		$whisper_key = trim( $options['whisper_api_key'] ?? '' );
		return $whisper_key;
	}

	/**
	 * Devuelve la transcripción guardada de un post.
	 */
	public static function get_transcript( $post_id ) {
		return (string) get_post_meta( absint( $post_id ), self::META_KEY, true );
	}
}
