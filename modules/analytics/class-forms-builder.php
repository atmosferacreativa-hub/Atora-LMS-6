<?php
/**
 * ATORA LMS v5 — Forms Builder
 *
 * Constructor de formularios drag & drop con schema JSON.
 * Validación server-side, CSRF, honeypot anti-spam, conditional logic.
 * Shortcode [atora_form id="123"].
 *
 * @package ATORA_LMS\Analytics
 * @since   5.0.0
 */

namespace ATORA\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Forms_Builder
 *
 * @since 5.0.0
 */
class Forms_Builder {

	private const UNICODE_FIX_OPTION = 'atora_forms_schema_unicode_fix_6265';

	/**
	 * Inicializa el módulo.
	 *
	 * @return void
	 */
	public static function init(): void {
		// CPT para formularios.
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_schema_unicode_escapes' ), 20 );

		// Shortcode.
		add_shortcode( 'atora_form', array( __CLASS__, 'render_shortcode' ) );

		// AJAX submit público.
		add_action( 'wp_ajax_atora_form_submit',        array( __CLASS__, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_atora_form_submit', array( __CLASS__, 'handle_submit' ) );

		// Admin.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu',         array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'wp_ajax_atora_form_save',      array( __CLASS__, 'ajax_save' ) );
			add_action( 'wp_ajax_atora_form_delete',    array( __CLASS__, 'ajax_delete' ) );
			add_action( 'wp_ajax_atora_form_entries',   array( __CLASS__, 'ajax_get_entries' ) );
		}

		// Assets frontend.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
	}

	// ── CPT ───────────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_cpt(): void {
		register_post_type( 'atora_form', array(
			'labels'       => array(
				'name'          => __( 'Formularios', 'atora-lms' ),
				'singular_name' => __( 'Formulario', 'atora-lms' ),
			),
			'public'       => false,
			'show_in_menu' => false,
			'supports'     => array( 'title' ),
			'show_in_rest' => true,
		) );
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────

	/**
	 * Decodifica escapes unicode rotos que llegaron como "u00e9" en vez de "é".
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function normalize_unicode_escapes_for_schema( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::normalize_unicode_escapes_for_schema( $v );
			}
			return $out;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$s = $value;
		if ( ! preg_match( '/\\\\u[0-9a-fA-F]{4}|(?<!\\\\)u[0-9a-fA-F]{4}/', $s ) ) {
			return $value;
		}

		// Insertar la barra que falta en uXXXX y convertir via json_decode de string.
		$s = (string) preg_replace( '/(?<!\\\\)u([0-9a-fA-F]{4})/', '\\\\u$1', $s );

		// Preservar \uXXXX al escapar el JSON.
		$placeholder = "\x1A";
		$s2 = str_replace( '\\u', $placeholder . 'u', $s );
		$escaped = addcslashes( $s2, "\\\"\n\r\t" );
		$escaped = str_replace( $placeholder . 'u', '\\u', $escaped );

		$decoded = json_decode( '"' . $escaped . '"' );
		return is_string( $decoded ) ? $decoded : $value;
	}

	/**
	 * Migra schemas ya guardados que tienen escapes unicode rotos.
	 *
	 * @return void
	 */
	public static function maybe_migrate_schema_unicode_escapes(): void {
		if ( get_option( self::UNICODE_FIX_OPTION ) ) {
			return;
		}

		$ids = get_posts( array(
			'post_type'      => 'atora_form',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( (array) $ids as $form_id ) {
			$form_id = absint( $form_id );
			if ( ! $form_id ) {
				continue;
			}

			$raw = (string) get_post_meta( $form_id, 'atora_form_schema', true );
			if ( '' === $raw ) {
				continue;
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$normalized = self::normalize_unicode_escapes_for_schema( $decoded );
			if ( $normalized === $decoded ) {
				continue;
			}

			update_post_meta( $form_id, 'atora_form_schema', wp_json_encode( $normalized ) );
		}

		update_option( self::UNICODE_FIX_OPTION, 1, false );
	}

	/**
	 * Renderiza el formulario en el frontend.
	 *
	 * @param array $atts Atributos: id.
	 * @return string
	 */
	public static function render_shortcode( array $atts = array() ): string {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts );
		$form_id = absint( $atts['id'] );

		if ( ! $form_id ) {
			return '';
		}

		$schema = json_decode( get_post_meta( $form_id, 'atora_form_schema', true ), true );
		$schema = self::normalize_unicode_escapes_for_schema( is_array( $schema ) ? $schema : array() );

		if ( empty( $schema['fields'] ) ) {
			return '';
		}

		wp_enqueue_script( 'atora-forms' );
		wp_enqueue_style( 'atora-forms' );

		ob_start();
		?>
		<form class="atora-form"
		      id="atora-form-<?php echo absint( $form_id ); ?>"
		      data-form-id="<?php echo absint( $form_id ); ?>"
		      novalidate>

			<?php wp_nonce_field( 'atora_form_' . $form_id, 'atora_form_nonce' ); ?>

			<!-- Honeypot anti-spam -->
			<div style="display:none!important;" aria-hidden="true">
				<input type="text" name="atora_hp_name" tabindex="-1" autocomplete="off">
			</div>

			<?php foreach ( $schema['fields'] as $field ) : ?>
				<?php echo self::render_field( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>

			<button type="submit" class="atora-btn atora-btn-primary" style="margin-top:16px;">
				<?php echo esc_html( $schema['submit_text'] ?? __( 'Enviar', 'atora-lms' ) ); ?>
			</button>

			<div class="atora-form-messages" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renderiza un campo individual del formulario.
	 *
	 * @param array $field Configuración del campo.
	 * @return string
	 */
	private static function render_field( array $field ): string {
		$type     = sanitize_key( $field['type'] ?? 'text' );
		$name     = sanitize_key( $field['name'] ?? 'field_' . wp_rand( 1000, 9999 ) );
		$label    = sanitize_text_field( $field['label'] ?? '' );
		$required = ! empty( $field['required'] );
		$ph       = sanitize_text_field( $field['placeholder'] ?? '' );
		$id_attr  = 'atora-field-' . $name;

		$req_attr = $required ? ' required' : '';
		$req_mark = $required ? ' <span aria-hidden="true" style="color:var(--atora-danger);">*</span>' : '';

		ob_start();
		?>
		<div class="atora-form-field" style="margin-bottom:16px;">
			<label for="<?php echo esc_attr( $id_attr ); ?>" style="display:block;font-weight:600;margin-bottom:4px;font-size:14px;">
				<?php echo esc_html( $label ); ?><?php echo $req_mark; // phpcs:ignore ?>
			</label>
			<?php if ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $id_attr ); ?>"
				          name="fields[<?php echo esc_attr( $name ); ?>]"
				          placeholder="<?php echo esc_attr( $ph ); ?>"
				          rows="4"
				          <?php echo $req_attr; // phpcs:ignore ?>
				          style="width:100%;padding:8px 12px;border:1px solid var(--atora-border);border-radius:var(--ac-radius-xs);"></textarea>
			<?php elseif ( 'select' === $type && ! empty( $field['options'] ) ) : ?>
				<select id="<?php echo esc_attr( $id_attr ); ?>"
				        name="fields[<?php echo esc_attr( $name ); ?>]"
				        <?php echo $req_attr; // phpcs:ignore ?>
				        style="width:100%;padding:8px 12px;border:1px solid var(--atora-border);border-radius:var(--ac-radius-xs);">
					<option value=""><?php esc_html_e( '— Selecciona —', 'atora-lms' ); ?></option>
					<?php foreach ( (array) $field['options'] as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'checkbox' === $type ) : ?>
				<label style="display:flex;align-items:center;gap:8px;font-weight:400;">
					<input type="checkbox"
					       id="<?php echo esc_attr( $id_attr ); ?>"
					       name="fields[<?php echo esc_attr( $name ); ?>]"
					       value="1"
					       <?php echo $req_attr; // phpcs:ignore ?>>
					<?php echo esc_html( $ph ?: $label ); ?>
				</label>
			<?php else : ?>
				<input type="<?php echo esc_attr( in_array( $type, array( 'email', 'tel', 'number', 'url' ), true ) ? $type : 'text' ); ?>"
				       id="<?php echo esc_attr( $id_attr ); ?>"
				       name="fields[<?php echo esc_attr( $name ); ?>]"
				       placeholder="<?php echo esc_attr( $ph ); ?>"
				       <?php echo $req_attr; // phpcs:ignore ?>
				       style="width:100%;padding:8px 12px;border:1px solid var(--atora-border);border-radius:var(--ac-radius-xs);">
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Submit ────────────────────────────────────────────────────────────────

	/**
	 * Procesa el envío de un formulario.
	 *
	 * @return void
	 */
	/** PT-5.1 (6.5.4): envíos por IP por formulario permitidos dentro de la ventana, si el formulario no fija su propio `throttle_per_15min` en el schema. */
	const DEFAULT_THROTTLE_LIMIT = 10;
	const THROTTLE_WINDOW_MINUTES = 15;

	/**
	 * PT-1 (6.5.5): orden del flujo de envío, corregido — form_id
	 * válido → el post existe → es realmente un atora_form → nonce
	 * válido → (recién ahí) resolver IP y aplicar rate limit → honeypot
	 * → validar campos → guardar/procesar. Antes, is_throttled() corría
	 * como primera instrucción, antes de confirmar siquiera que
	 * form_id > 0 o que el formulario existiera — una petición anónima
	 * con miles de form_id fabricados podía generar filas de contador
	 * ilimitadas (DoS de bajo costo) sin pasar nunca el nonce. Ahora
	 * ninguna escritura persistente (contador, entrada, email) ocurre
	 * hasta que form_id y nonce son válidos.
	 *
	 * @return void
	 */
	public static function handle_submit(): void {
		$form_id = absint( wp_unslash( $_POST['form_id'] ?? 0 ) );

		if ( $form_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Formulario no encontrado.', 'atora-lms' ) ) );
		}

		if ( 'atora_form' !== get_post_type( $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Formulario no encontrado.', 'atora-lms' ) ) );
		}

		$schema = json_decode( (string) get_post_meta( $form_id, 'atora_form_schema', true ), true );

		if ( ! $schema ) {
			wp_send_json_error( array( 'message' => __( 'Formulario no encontrado.', 'atora-lms' ) ) );
		}

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['atora_form_nonce'] ?? '' ) ),
			'atora_form_' . $form_id
		) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ) );
		}

		// PT-1 (6.5.5): recién acá — form_id y nonce ya son válidos —
		// se resuelve la IP y se consume cupo del rate limit. Nonce y
		// honeypot no bastan solos contra un script que obtiene el
		// nonce de la página pública una vez y lo reutiliza en envíos
		// repetidos (los nonces de WP no son de un solo uso), así que
		// el throttle sigue contando el intento aunque el resto de la
		// validación falle después (regla 5.3: cuenta envíos que llegan
		// al servidor con nonce válido, no solo los exitosos).
		if ( self::is_throttled( $form_id ) ) {
			// PT-5.2: mensaje genérico — no revela el límite exacto,
			// para no ayudar a calibrar el ataque.
			wp_send_json_error( array( 'message' => __( 'Demasiados envíos, intenta más tarde.', 'atora-lms' ) ) );
		}

		// Honeypot.
		if ( ! empty( $_POST['atora_hp_name'] ) ) {
			wp_send_json_success( array( 'message' => __( 'Formulario enviado.', 'atora-lms' ) ) );
		}

		// Sanitizar campos.
		$submitted = array();
		foreach ( (array) ( $_POST['fields'] ?? array() ) as $key => $value ) {
			$submitted[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
		}

		// Validar campos requeridos.
		foreach ( $schema['fields'] ?? array() as $field ) {
			if ( ! empty( $field['required'] ) ) {
				$fname = sanitize_key( $field['name'] ?? '' );
				if ( empty( $submitted[ $fname ] ) ) {
					wp_send_json_error( array(
						'message' => sprintf(
							__( 'El campo "%s" es obligatorio.', 'atora-lms' ),
							$field['label'] ?? $fname
						),
					) );
				}
			}
		}

		// Guardar entrada.
		$entry_id = self::save_entry( $form_id, $submitted );

		// Procesar acciones post-envío.
		self::process_form_actions( $form_id, $schema, $submitted, get_current_user_id() );

		do_action( 'atora/forms/submitted', $form_id, $entry_id, $submitted );

		$success_message = sanitize_text_field( $schema['success_message'] ?? __( '¡Gracias! Tu mensaje fue enviado.', 'atora-lms' ) );
		wp_send_json_success( array( 'message' => $success_message ) );
	}

	/**
	 * PT-1 (6.5.5): tope por IP por formulario en la ventana de 15
	 * minutos — configurable por formulario vía `throttle_per_15min`
	 * en el schema JSON. Contador atómico en tabla dedicada
	 * (atora_form_throttle) vía INSERT ... ON DUPLICATE KEY UPDATE en
	 * vez del patrón get_transient()+set_transient() anterior
	 * (lectura-incremento-escritura no atómico: dos peticiones
	 * concurrentes podían leer el mismo valor y ambas incrementar mal,
	 * excediendo el límite real bajo carga). El incremento en sí es
	 * atómico por bloqueo de fila InnoDB — la fila (form_id, ip_hash,
	 * window_start) es única, así que el motor serializa cualquier
	 * incremento concurrente sobre la misma clave.
	 *
	 * Ventana fija (no deslizante): window_start = inicio del bloque
	 * de THROTTLE_WINDOW_MINUTES actual — evita el efecto de la
	 * versión anterior donde cada envío repetido renovaba el TTL del
	 * transient, extendiendo el bloqueo indefinidamente mientras
	 * siguieran llegando intentos.
	 *
	 * Se llama únicamente después de confirmar que form_id corresponde
	 * a un atora_form real (ver handle_submit()) — la clave nunca
	 * depende de un form_id arbitrario, así que la cardinalidad de
	 * filas está acotada a "IP real × formulario real × ventana".
	 *
	 * Regla 5.3: si no se puede determinar la IP, no se bloquea — sin
	 * IP no hay una clave de conteo confiable, y el nonce + honeypot
	 * ya filtran buena parte del spam trivial de todos modos.
	 *
	 * @param int $form_id
	 * @return bool
	 */
	private static function is_throttled( int $form_id ): bool {
		$ip = \ATORA_Client_IP::get();
		if ( '' === $ip ) {
			return false;
		}

		$schema = json_decode( (string) get_post_meta( $form_id, 'atora_form_schema', true ), true );
		$limit  = max( 1, absint( $schema['throttle_per_15min'] ?? self::DEFAULT_THROTTLE_LIMIT ) );

		global $wpdb;
		$table        = $wpdb->prefix . 'atora_form_throttle';
		$window_secs  = self::THROTTLE_WINDOW_MINUTES * MINUTE_IN_SECONDS;
		$window_start = (int) ( floor( time() / $window_secs ) * $window_secs );
		$ip_hash      = hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) );

		// PT-6 (6.5.8): hallazgo real — si el INSERT/SELECT de abajo
		// fallaba (tabla ausente, migración incompleta, error de BD),
		// $wpdb->get_var() devolvía null, (int) null = 0, y
		// "0 > $limit" es false: el formulario público quedaba SIN
		// límite de envíos mientras el backend estuviera roto —
		// exactamente lo opuesto de lo que un rate limiter debe hacer
		// ante un fallo de infraestructura. Ahora se falla cerrado: si
		// el INSERT o el SELECT no pueden confirmarse, se trata como
		// "bloqueado" (mensaje genérico, igual que el límite normal),
		// nunca como "sin límite".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (form_id, ip_hash, window_start, attempts) VALUES (%d, %s, %d, 1)
				 ON DUPLICATE KEY UPDATE attempts = attempts + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$form_id,
				$ip_hash,
				$window_start
			)
		);

		if ( false === $inserted ) {
			self::log_throttle_backend_failure( $form_id );
			return true; // fail-closed.
		}

		$attempts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$table} WHERE form_id = %d AND ip_hash = %s AND window_start = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$form_id,
				$ip_hash,
				$window_start
			)
		);

		if ( null === $attempts ) {
			self::log_throttle_backend_failure( $form_id );
			return true; // fail-closed.
		}

		return (int) $attempts > $limit;
	}

	/**
	 * Registra un fallo del backend de throttle sin datos personales
	 * (ni IP, ni body del formulario) — solo el form_id, suficiente
	 * para diagnosticar sin exponer información del visitante.
	 *
	 * @param int $form_id
	 * @return void
	 */
	private static function log_throttle_backend_failure( int $form_id ): void {
		if ( function_exists( 'error_log' ) ) {
			error_log( '[ATORA][forms-throttle] backend de rate limit no disponible para form_id ' . $form_id . ' — envío rechazado por defecto (fail-closed).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Guarda una entrada del formulario en la BD.
	 *
	 * @param int   $form_id   ID del formulario.
	 * @param array $submitted Datos enviados.
	 * @return int ID de la entrada.
	 */
	private static function save_entry( int $form_id, array $submitted ): int {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}atora_form_entries",
			array(
				'form_id'    => $form_id,
				'user_id'    => get_current_user_id(),
				'entry_data' => wp_json_encode( $submitted ),
				// PT-1 (6.5.5): antes evaluaba una condición sin relación
				// (Extended_Registration::is_valid_phone('')) que siempre
				// tomaba la misma rama, y leía REMOTE_ADDR directo sin
				// pasar por el resolutor de proxies confiables.
				'ip_address' => \ATORA_Client_IP::get(),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Ejecuta las acciones configuradas post-envío.
	 *
	 * @param int      $form_id   ID del formulario.
	 * @param array    $schema    Schema del formulario.
	 * @param array    $submitted Datos enviados.
	 * @param int      $user_id   ID del usuario.
	 * @return void
	 */
	private static function process_form_actions( int $form_id, array $schema, array $submitted, int $user_id ): void {
		// Email al admin a través de la pasarela central.
		if ( ! empty( $schema['notify_admin'] ) ) {
			$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
			$post_title  = get_the_title( $form_id );
			$body        = '<h3>' . esc_html( $post_title ) . '</h3><table>';
			foreach ( $submitted as $k => $v ) {
				$body .= '<tr><td><strong>' . esc_html( $k ) . '</strong></td><td>' . esc_html( $v ) . '</td></tr>';
			}
			$body .= '</table>';
			$subject = sprintf( __( 'Nueva respuesta de formulario: %s', 'atora-lms' ), $post_title );

			if ( class_exists( 'ATORA_Email_Gateway' ) && method_exists( 'ATORA_Email_Gateway', 'send' ) ) {
				\ATORA_Email_Gateway::send(
					$admin_email,
					$subject,
					$body,
					array(
						'headline'   => $post_title,
						'preheader'  => __( 'Nueva respuesta de formulario', 'atora-lms' ),
					)
				);
			} elseif ( class_exists( 'CLMS_Email' ) && method_exists( 'CLMS_Email', 'send' ) ) {
				\CLMS_Email::send( $admin_email, $subject, $body );
			}
		}

		// Autoresponder al usuario.
		if ( ! empty( $schema['autoresponder_template'] ) && $user_id ) {
			\ATORA\EmailEngine\Email_Queue::enqueue( array(
				'template' => sanitize_key( $schema['autoresponder_template'] ),
				'user_id'  => $user_id,
				'priority' => 'medium',
				'metadata' => $submitted,
			) );
		}

		// Agregar tag CRM.
		if ( ! empty( $schema['crm_tag'] ) && $user_id && class_exists( 'ATORA\CRM\CRM' ) ) {
			\ATORA\CRM\CRM::add_tag( $user_id, sanitize_text_field( $schema['crm_tag'] ) );
		}
	}

	// ── Admin AJAX ────────────────────────────────────────────────────────────

	/** @return void */
	public static function ajax_save(): void {
		check_ajax_referer( 'atora_form_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		$title    = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$form_id  = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$schema_in = $_POST['schema'] ?? array();
		if ( is_string( $schema_in ) ) {
			$decoded = json_decode( (string) $schema_in, true );
			$schema_in = is_array( $decoded ) ? $decoded : array();
		}
		$schema_in = self::normalize_unicode_escapes_for_schema( $schema_in );
		$schema   = wp_json_encode( $schema_in );

		$post_data = array(
			'post_type'   => 'atora_form',
			'post_title'  => $title,
			'post_status' => 'publish',
		);

		if ( $form_id ) {
			$post_data['ID'] = $form_id;
			wp_update_post( $post_data );
		} else {
			$form_id = wp_insert_post( $post_data );
		}

		update_post_meta( (int) $form_id, 'atora_form_schema', $schema );
		wp_send_json_success( array( 'id' => $form_id ) );
	}

	/** @return void */
	public static function ajax_delete(): void {
		check_ajax_referer( 'atora_form_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		wp_delete_post( $id, true );
		wp_send_json_success();
	}

	/** @return void */
	public static function ajax_get_entries(): void {
		check_ajax_referer( 'atora_form_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		global $wpdb;
		$form_id = absint( wp_unslash( $_POST['form_id'] ?? 0 ) );
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_form_entries WHERE form_id = %d ORDER BY created_at DESC LIMIT 100",
			$form_id
		) );
		wp_send_json_success( $rows );
	}

	// ── Admin menú ────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-forms' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'', // PT-4.4.3: reubicado bajo el hub "Informes" (atora-reports-hub).
			__( 'Formularios', 'atora-lms' ),
			__( 'Formularios', 'atora-lms' ),
			'manage_options',
			'atora-forms',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'analytics/forms/admin.php';
				if ( file_exists( $view ) ) {
					try {
						require $view;
					} catch ( \Throwable $e ) {
						if ( function_exists( 'error_log' ) ) {
							error_log( '[ATORA Formularios] Error al renderizar admin: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
						echo '<div class="wrap"><h1>' . esc_html__( 'Formularios', 'atora-lms' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'No se pudo renderizar el panel de Formularios. Revisa el log de errores.', 'atora-lms' ) . '</p></div></div>';
					}
					return;
				}

				echo '<div class="wrap"><h1>' . esc_html__( 'Formularios', 'atora-lms' ) . '</h1><div class="notice notice-warning"><p>' . esc_html__( 'Vista de Formularios no disponible. Falta el archivo modules/analytics/forms/admin.php.', 'atora-lms' ) . '</p></div></div>';
			}
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/** @return void */
	public static function maybe_enqueue_assets(): void {
		wp_register_script(
			'atora-forms',
			ATORA_LMS_MODULES_URL . 'analytics/assets/forms.js',
			array(),
			ATORA_LMS_VERSION,
			true
		);

		wp_localize_script( 'atora-forms', 'atoraForms', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'i18n'     => array(
				'sending' => __( 'Enviando…', 'atora-lms' ),
				'error'   => __( 'Error al enviar. Intenta de nuevo.', 'atora-lms' ),
			),
		) );

		wp_register_style(
			'atora-forms',
			ATORA_LMS_MODULES_URL . 'analytics/assets/forms.css',
			array(),
			ATORA_LMS_VERSION
		);
	}
}
