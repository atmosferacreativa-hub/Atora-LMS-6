<?php
/**
 * Eliminación de cuenta (6.33.0), exigida por Google Play y Apple.
 *
 * - El usuario la pide desde la app (`POST /account/deletion-request`) o desde
 *   la página pública `/eliminar-cuenta/` (con sesión, un botón; sin sesión, un
 *   enlace de confirmación por correo, sin revelar si el correo existe).
 * - Se registra en `atora_account_deletions`, se avisa a los administradores
 *   por el buzón y se responde con el plazo (30 días).
 * - El administrador la procesa en ATORA LMS → Solicitudes de eliminación:
 *   **anonimizar** (recomendado: se borran nombre, correo, usuario y datos
 *   personales, sesiones y dispositivos; las notas, entregas y actas se
 *   conservan sin datos personales) o **eliminar por completo** (también lo
 *   académico).
 *
 * @package ATORA_LMS
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Account_Deletion {

	const TABLE        = 'atora_account_deletions';
	const DAYS         = 30;
	const PAGE         = 'atora-account-deletions';
	const QUERY_VAR    = 'atora_eliminar_cuenta';
	const LINK_TTL     = DAY_IN_SECONDS;
	const REWRITE_OPTION = 'atora_account_deletion_rewrite';

	public static function boot(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_atora_account_deletion_process', array( __CLASS__, 'process_from_admin' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_schema' ) );
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', static function ( $vars ) {
			$vars[] = self::QUERY_VAR;
			return $vars;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** 6.33.1: columna `detail` en instalaciones de 6.33.0 (dbDelta con IF NOT EXISTS no altera tablas). */
	public static function ensure_schema(): void {
		global $wpdb;
		if ( get_option( 'atora_account_deletions_schema' ) === '6.33.1' ) {
			return;
		}
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) && ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'detail' ) ) ) { // phpcs:ignore WordPress.DB
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN detail TEXT DEFAULT NULL AFTER note" ); // phpcs:ignore WordPress.DB
		}
		update_option( 'atora_account_deletions_schema', '6.33.1', false );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^eliminar-cuenta/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		if ( get_option( self::REWRITE_OPTION ) !== '6.33.0' ) {
			update_option( self::REWRITE_OPTION, '6.33.0', false );
			flush_rewrite_rules( false );
		}
	}

	/** Enlace público para pedir la eliminación sin la app (el que pide Google Play). */
	public static function public_url(): string {
		return get_option( 'permalink_structure' ) ? home_url( '/eliminar-cuenta/' ) : add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	public static function pending( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	public static function deadline( string $requested_at_gmt ): string {
		return gmdate( 'Y-m-d', strtotime( $requested_at_gmt . ' UTC' ) + self::DAYS * DAY_IN_SECONDS );
	}

	private static function payload( array $row ): array {
		$deadline = self::deadline( (string) $row['requested_at'] );
		return array(
			'request_id'   => (int) $row['id'],
			'status'       => (string) $row['status'],
			'requested_at' => gmdate( 'c', strtotime( $row['requested_at'] . ' UTC' ) ),
			'deadline'     => $deadline,
			/* translators: %s: fecha límite */
			'message'      => sprintf( __( 'Recibimos tu solicitud. La academia eliminará tu cuenta y tus datos personales a más tardar el %s. Las notas y actas que la institución deba conservar por ley se guardan sin tus datos personales.', 'atora-lms' ), wp_date( get_option( 'date_format' ), strtotime( $deadline ) ) ),
		);
	}

	/**
	 * Registra la solicitud (si ya hay una pendiente, devuelve esa) y avisa a los administradores.
	 *
	 * @return array|WP_Error {request_id, status, requested_at, deadline, message}
	 */
	public static function request( int $user_id, string $source, string $note = '' ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'atora_account_deletion_user', __( 'Usuario no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$existing = self::pending( $user_id );
		if ( $existing ) {
			return self::payload( $existing ) + array( 'replayed' => true );
		}
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert( self::table(), array( // phpcs:ignore WordPress.DB
			'user_id'      => $user_id,
			'status'       => 'pending',
			'source'       => in_array( $source, array( 'app', 'web' ), true ) ? $source : 'web',
			'note'         => substr( sanitize_textarea_field( $note ), 0, 500 ),
			'requested_at' => $now,
		) );
		if ( ! $ok ) {
			return new WP_Error( 'atora_db_error', __( 'No se pudo registrar la solicitud. Intenta de nuevo.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$row = self::pending( $user_id );
		self::notify_admins( $user_id, (int) $row['id'] );
		do_action( 'atora_account_deletion_requested', $user_id, (int) $row['id'] );
		return self::payload( $row ) + array( 'replayed' => false );
	}

	private static function notify_admins( int $user_id, int $request_id ): void {
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) as $admin_id ) {
			$notifications->add_notification( (int) $admin_id, array(
				'type'       => 'account_deletion',
				'title'      => __( 'Solicitud de eliminación de cuenta', 'atora-lms' ),
				/* translators: 1: nombre, 2: días */
				'message'    => sprintf( __( '%1$s pidió eliminar su cuenta. Procésala en un plazo de %2$d días.', 'atora-lms' ), $user ? $user->display_name : '#' . $user_id, self::DAYS ),
				'link'       => admin_url( 'admin.php?page=' . self::PAGE ),
				'dedupe_key' => 'account_deletion_' . $request_id,
			) );
		}
	}

	/** Advertencia que se acepta antes de eliminar por completo (6.33.0). */
	public static function full_delete_warning(): string {
		return __( 'Se borrarán también notas y actas; la institución puede estar obligada a conservarlas.', 'atora-lms' );
	}

	/**
	 * Procesa una solicitud. Por defecto **anonimiza**; eliminar por completo
	 * (`delete`) exige `$confirmed_full = true`, es decir, que el administrador
	 * aceptó la advertencia. Queda registrado quién la ejecutó y cuándo.
	 *
	 * @return true|WP_Error
	 */
	public static function process( int $request_id, string $mode = 'anonymize', int $actor_id = 0, bool $confirmed_full = false ) {
		global $wpdb;
		self::ensure_schema();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $request_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		// 6.33.1: una solicitud incompleta se puede reintentar.
		if ( ! $row || ! in_array( $row['status'], array( 'pending', 'incomplete' ), true ) ) {
			return new WP_Error( 'atora_account_deletion_state', __( 'La solicitud no existe o ya se procesó.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $mode, array( 'anonymize', 'delete' ), true ) ) {
			return new WP_Error( 'atora_account_deletion_mode', __( 'Acción no válida.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( 'delete' === $mode && ! $confirmed_full ) {
			return new WP_Error( 'atora_account_deletion_confirm', self::full_delete_warning() . ' ' . __( 'Confirma expresamente para eliminar por completo, o anonimiza.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$user_id = (int) $row['user_id'];
		$user    = get_userdata( $user_id );
		if ( $user && user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'atora_account_deletion_admin', __( 'Una cuenta de administrador no se elimina desde aquí.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		// Correo y nombre originales (en un reintento, guardados en el detalle del primer intento).
		$previous = json_decode( (string) ( $row['detail'] ?? '' ), true );
		$email    = $user && false === strpos( $user->user_email, '@anonimo.invalid' ) ? (string) $user->user_email : (string) ( $previous['email'] ?? '' );
		$name     = $user && __( 'Usuario eliminado', 'atora-lms' ) !== $user->display_name ? (string) $user->display_name : (string) ( $previous['name'] ?? '' );

		// 1) Colas canceladas y datos de todos los módulos (borradores de WordPress, incluidos los de ATORA).
		$errors = array();
		ATORA_Privacy_Erasers::with_mode( $mode, static function () use ( $user_id, $email, $name, &$errors ) {
			$result = ATORA_Privacy_Erasers::erase( $user_id, $email, $name );
			$errors = $result['errors'];
			foreach ( (array) apply_filters( 'wp_privacy_personal_data_erasers', array() ) as $key => $eraser ) {
				if ( 'atora-lms' === $key || '' === $email || ! is_callable( $eraser['callback'] ?? null ) ) {
					continue;
				}
				for ( $page = 1; $page <= 50; $page++ ) {
					$response = call_user_func( $eraser['callback'], $email, $page );
					if ( ! is_array( $response ) || ! empty( $response['done'] ) ) {
						break;
					}
				}
			}
		} );

		// 2) La cuenta: anonimizada (por defecto) o eliminada.
		if ( $user ) {
			self::anonymize( $user_id );
			if ( 'delete' === $mode ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
			}
		}

		// 3) Verificación: no queda nombre, correo ni id (salvo lo académico al anonimizar).
		$left   = ATORA_Privacy_Erasers::verify( 'delete' === $mode ? $user_id : $user_id, $email, $name, $mode );
		$issues = array_merge( $errors, $left );
		$status = $issues ? 'incomplete' : 'processed';
		$wpdb->update( self::table(), array( // phpcs:ignore WordPress.DB
			'status'       => $status,
			'mode'         => $mode,
			'note'         => '',
			'detail'       => wp_json_encode( array( 'email' => $issues ? $email : '', 'name' => $issues ? $name : '', 'issues' => $issues ) ),
			'processed_at' => current_time( 'mysql', true ),
			'processed_by' => $actor_id,
		), array( 'id' => $request_id ) );
		if ( $issues ) {
			do_action( 'atora_account_deletion_incomplete', $user_id, $mode, $request_id, $issues );
			return new WP_Error( 'atora_account_deletion_incomplete', __( 'La eliminación quedó incompleta. Detalle:', 'atora-lms' ) . ' ' . implode( '; ', array_slice( $issues, 0, 10 ) ), array( 'status' => 500, 'issues' => $issues ) );
		}
		do_action( 'atora_account_deletion_processed', $user_id, $mode, $request_id );
		return true;
	}

	/** Borra los datos personales; deja la cuenta como "Usuario eliminado" para conservar lo académico. */
	public static function anonymize( int $user_id ): void {
		global $wpdb;
		// Sesiones de la app, de la web y avisos al teléfono.
		$wpdb->update( $wpdb->prefix . 'atora_mobile_sessions', array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( $wpdb->prefix . 'atora_mobile_push_tokens', array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
		delete_user_meta( $user_id, 'atora_mobile_sessions' );

		$label = __( 'Usuario eliminado', 'atora-lms' );
		// Sin los avisos automáticos de WordPress ("tu correo/contraseña cambió") a la dirección vieja.
		add_filter( 'send_email_change_email', '__return_false', 99 );
		add_filter( 'send_password_change_email', '__return_false', 99 );
		wp_update_user( array(
			'ID'           => $user_id,
			'display_name' => $label,
			'nickname'     => $label,
			'first_name'   => '',
			'last_name'    => '',
			'description'  => '',
			'user_url'     => '',
			'user_email'   => 'eliminado-' . $user_id . '-' . wp_generate_password( 8, false, false ) . '@anonimo.invalid',
			'user_pass'    => wp_generate_password( 32, true, true ),
		) );
		remove_filter( 'send_email_change_email', '__return_false', 99 );
		remove_filter( 'send_password_change_email', '__return_false', 99 );
		$wpdb->update( $wpdb->users, array( 'user_login' => 'eliminado_' . $user_id, 'user_nicename' => 'eliminado-' . $user_id ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB
		// Datos de contacto y perfil (las metas académicas se conservan).
		$keys = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB
		foreach ( $keys as $key ) {
			if ( preg_match( '/phone|telefono|tel[eé]fono|address|direcci|birth|nacimiento|avatar|photo|foto|billing_|shipping_|document|dni|cedula|c[eé]dula|whatsapp|telegram|social|linkedin|instagram|facebook|twitter/i', (string) $key ) ) {
				delete_user_meta( $user_id, (string) $key );
			}
		}
		$user = new WP_User( $user_id );
		$user->set_role( '' );
		clean_user_cache( $user_id );
	}

	// ── Administración ──────────────────────────────────────────────────────────

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'Solicitudes de eliminación', 'atora-lms' ), __( 'Solicitudes de eliminación', 'atora-lms' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_admin' ) );
	}

	public static function process_from_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'atora_account_deletion_' . $request_id );
		$mode      = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'anonymize';
		$confirmed = ! empty( $_POST['confirm_full_delete'] );
		$result    = self::process( $request_id, '' === $mode ? 'anonymize' : $mode, get_current_user_id(), $confirmed );
		$args   = array( 'page' => self::PAGE, 'done' => is_wp_error( $result ) ? 0 : 1 );
		if ( is_wp_error( $result ) ) {
			$args['error'] = rawurlencode( $result->get_error_message() );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . " ORDER BY status = 'pending' DESC, id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
		echo '<div class="wrap"><h1>' . esc_html__( 'Solicitudes de eliminación de cuenta', 'atora-lms' ) . '</h1>';
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) . '</p></div>';
		} elseif ( ! empty( $_GET['done'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Solicitud procesada.', 'atora-lms' ) . '</p></div>';
		}
		// phpcs:enable
		/* translators: %s: URL pública */
		echo '<p>' . wp_kses_post( sprintf( __( 'Página pública para pedir la eliminación sin la app (la que se informa a Google Play): <a href="%1$s">%1$s</a>', 'atora-lms' ), esc_url( self::public_url() ) ) ) . '</p>';
		echo '<p>' . esc_html__( 'Anonimizar borra nombre, correo, usuario y datos de contacto, cancela los correos y mensajes pendientes, y limpia mensajes, CRM, sesiones, dispositivos y registros de todos los módulos; las notas, entregas, actas y certificados se conservan sin datos personales (el certificado queda a nombre de un titular anonimizado). Eliminar por completo borra también lo académico. Antes de marcarla como procesada se verifica que no quede nada; si algo queda, la solicitud queda incompleta con el detalle y se puede reintentar.', 'atora-lms' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Usuario', 'atora-lms' ) . '</th><th>' . esc_html__( 'Pedida', 'atora-lms' ) . '</th><th>' . esc_html__( 'Plazo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Origen', 'atora-lms' ) . '</th><th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No hay solicitudes.', 'atora-lms' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			echo '<tr><td>' . esc_html( $user ? $user->display_name . ' <' . $user->user_email . '>' : '#' . $row['user_id'] ) . ( '' !== (string) $row['note'] ? '<br><em>' . esc_html( $row['note'] ) . '</em>' : '' ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( (string) $row['requested_at'], 'Y-m-d H:i' ) ) . '</td><td>' . esc_html( self::deadline( (string) $row['requested_at'] ) ) . '</td><td>' . esc_html( 'app' === $row['source'] ? 'App' : 'Web' ) . '</td>';
			$state = 'pending' === $row['status'] ? __( 'Pendiente', 'atora-lms' ) : ( 'incomplete' === $row['status'] ? __( 'Incompleta', 'atora-lms' ) : ( 'delete' === $row['mode'] ? __( 'Eliminada por completo', 'atora-lms' ) : __( 'Anonimizada', 'atora-lms' ) ) );
			if ( 'pending' !== $row['status'] ) {
				$actor = get_userdata( (int) $row['processed_by'] );
				/* translators: 1: estado, 2: administrador, 3: fecha */
				$state = sprintf( __( '%1$s por %2$s el %3$s', 'atora-lms' ), $state, $actor ? $actor->display_name : '#' . (int) $row['processed_by'], get_date_from_gmt( (string) $row['processed_at'], 'Y-m-d H:i' ) );
			}
			$detail = json_decode( (string) ( $row['detail'] ?? '' ), true );
			$issues = is_array( $detail ) ? (array) ( $detail['issues'] ?? array() ) : array();
			echo '<td>' . esc_html( $state ) . ( $issues ? '<br><small style="color:#a11d1d">' . esc_html( implode( '; ', array_slice( $issues, 0, 8 ) ) ) . '</small>' : '' ) . '</td><td>';
			if ( in_array( $row['status'], array( 'pending', 'incomplete' ), true ) ) {
				$form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'atora_account_deletion_' . (int) $row['id'], '_wpnonce', true, false )
					. '<input type="hidden" name="action" value="atora_account_deletion_process"><input type="hidden" name="request_id" value="' . (int) $row['id'] . '">';
				// Acción por defecto: anonimizar.
				echo $form . '<input type="hidden" name="mode" value="anonymize"><button class="button button-primary" onclick="return confirm(\'' . esc_js( __( '¿Anonimizar la cuenta? No se puede deshacer.', 'atora-lms' ) ) . '\')">' . esc_html__( 'Anonimizar', 'atora-lms' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formulario armado con valores escapados.
				// Eliminar por completo: aparte, con la advertencia aceptada expresamente.
				echo '<details style="margin-top:6px"><summary>' . esc_html__( 'Eliminar por completo…', 'atora-lms' ) . '</summary>' . $form // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. '<input type="hidden" name="mode" value="delete"><p style="color:#a11d1d;max-width:420px"><label><input type="checkbox" name="confirm_full_delete" value="1" required> ' . esc_html( self::full_delete_warning() ) . '</label></p>'
					. '<button class="button" onclick="return confirm(\'' . esc_js( self::full_delete_warning() . ' ' . __( '¿Eliminar por completo?', 'atora-lms' ) ) . '\')">' . esc_html__( 'Eliminar por completo', 'atora-lms' ) . '</button></form></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── Página pública ──────────────────────────────────────────────────────────

	public static function link_token( int $user_id, int $expires ): string {
		return hash_hmac( 'sha256', 'delete-account|' . $user_id . '|' . $expires, wp_salt( 'auth' ) );
	}

	private static function throttled(): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'atora_del_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return $hits >= 10;
	}

	public static function maybe_render(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		self::page( self::handle_public_request() );
	}

	/** Lógica de la página pública (separada para las pruebas). @return array{state:string,message?:string} */
	public static function handle_public_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- el formulario anónimo no tiene sesión; se protege con enlace firmado por correo.
		// 1) Enlace del correo: confirma y registra.
		if ( isset( $_GET['u'], $_GET['e'], $_GET['t'] ) ) {
			$user_id = absint( $_GET['u'] );
			$expires = absint( $_GET['e'] );
			$token   = sanitize_text_field( wp_unslash( $_GET['t'] ) );
			if ( $expires < time() || ! hash_equals( self::link_token( $user_id, $expires ), $token ) ) {
				return array( 'state' => 'invalid_link' );
			}
			$result = self::request( $user_id, 'web' );
			return is_wp_error( $result ) ? array( 'state' => 'error', 'message' => $result->get_error_message() ) : array( 'state' => 'requested', 'message' => $result['message'] );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			// 2) Con sesión: el botón (con nonce).
			if ( is_user_logged_in() ) {
				if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'atora_delete_my_account' ) ) {
					return array( 'state' => 'error', 'message' => __( 'La página venció. Recárgala e intenta de nuevo.', 'atora-lms' ) );
				}
				$result = self::request( get_current_user_id(), 'web', isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '' );
				return is_wp_error( $result ) ? array( 'state' => 'error', 'message' => $result->get_error_message() ) : array( 'state' => 'requested', 'message' => $result['message'] );
			}
			// 3) Sin sesión: enlace de confirmación al correo (misma respuesta exista o no).
			if ( self::throttled() ) {
				return array( 'state' => 'throttled' );
			}
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$user  = $email ? get_user_by( 'email', $email ) : false;
			if ( $user ) {
				$expires = time() + self::LINK_TTL;
				$link    = add_query_arg( array( 'u' => $user->ID, 'e' => $expires, 't' => self::link_token( (int) $user->ID, $expires ) ), self::public_url() );
				wp_mail(
					$user->user_email,
					/* translators: %s: academia */
					sprintf( __( 'Confirma la eliminación de tu cuenta en %s', 'atora-lms' ), get_bloginfo( 'name' ) ),
					/* translators: %s: enlace */
					sprintf( __( "Recibimos un pedido para eliminar tu cuenta. Si fuiste tú, confírmalo en las próximas 24 horas con este enlace:\n\n%s\n\nSi no lo pediste, ignora este correo: no se hará nada.", 'atora-lms' ), $link )
				);
			}
			return array( 'state' => 'email_sent' );
		}
		// phpcs:enable
		return array( 'state' => is_user_logged_in() ? 'form_logged_in' : 'form' );
	}

	public static function content( array $r ): string {
		$intro = '<h1>' . esc_html__( 'Eliminar mi cuenta', 'atora-lms' ) . '</h1><p>' . esc_html__( 'Puedes pedir que se elimine tu cuenta y tus datos personales de esta academia. Las notas y actas que la institución deba conservar por ley se guardan sin tus datos personales.', 'atora-lms' ) . '</p>';
		switch ( $r['state'] ) {
			case 'requested':
				return '<h1>' . esc_html__( 'Solicitud registrada', 'atora-lms' ) . '</h1><p>' . esc_html( $r['message'] ) . '</p>';
			case 'email_sent':
				return '<h1>' . esc_html__( 'Revisa tu correo', 'atora-lms' ) . '</h1><p>' . esc_html__( 'Si el correo corresponde a una cuenta de esta academia, te enviamos un enlace para confirmar la eliminación. Vence en 24 horas.', 'atora-lms' ) . '</p>';
			case 'invalid_link':
				return '<h1>' . esc_html__( 'Enlace vencido o no válido', 'atora-lms' ) . '</h1><p><a href="' . esc_url( self::public_url() ) . '">' . esc_html__( 'Vuelve a pedirlo', 'atora-lms' ) . '</a></p>';
			case 'throttled':
				return '<h1>' . esc_html__( 'Demasiados intentos', 'atora-lms' ) . '</h1><p>' . esc_html__( 'Intenta de nuevo en una hora.', 'atora-lms' ) . '</p>';
			case 'error':
				return '<h1>' . esc_html__( 'No se pudo registrar', 'atora-lms' ) . '</h1><p>' . esc_html( $r['message'] ?? '' ) . '</p>';
			case 'form_logged_in':
				return $intro . '<form method="post">' . wp_nonce_field( 'atora_delete_my_account', '_wpnonce', true, false ) . '<p><label>' . esc_html__( 'Comentario (opcional)', 'atora-lms' ) . '<br><textarea name="note" rows="3"></textarea></label></p><button>' . esc_html__( 'Pedir la eliminación de mi cuenta', 'atora-lms' ) . '</button></form>';
		}
		return $intro . '<form method="post"><p><label>' . esc_html__( 'Correo de tu cuenta', 'atora-lms' ) . '<br><input type="email" name="email" required autocomplete="email"></label></p><button>' . esc_html__( 'Enviarme el enlace de confirmación', 'atora-lms' ) . '</button></form><p><small>' . esc_html__( 'También puedes pedirlo desde la app: Yo → Eliminar mi cuenta.', 'atora-lms' ) . '</small></p>';
	}

	private static function page( array $r ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( __( 'Eliminar mi cuenta', 'atora-lms' ) . ' · ' . get_bloginfo( 'name' ) ) . '</title><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;padding:24px}main{max-width:540px;margin:40px auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.06)}.academy{color:#6b7280;font-size:14px}input,textarea{padding:10px;border:1px solid #d1d5db;border-radius:8px;width:100%;box-sizing:border-box}button{padding:12px 18px;border:0;border-radius:8px;background:#a11d1d;color:#fff;font-weight:700;min-height:44px}</style></head><body><main><div class="academy">' . esc_html( get_bloginfo( 'name' ) ) . '</div>' . self::content( $r ) . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- content() escapa cada dato.
		exit;
	}
}
