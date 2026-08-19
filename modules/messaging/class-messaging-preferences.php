<?php
/**
 * ATORA_Messaging_Preferences — PT-4 (sprint 6.4.0)
 *
 * Modelo de datos de las preferencias de mensajería de un estudiante:
 * categorías activas, frecuencia por categoría, horario de no
 * molestar, verificación de teléfono para WhatsApp, y token firmado
 * de baja sin sesión. Sin UI aquí — eso es [atora_preferencias]
 * (class-messaging-preferences-shortcode.php).
 *
 * `can_receive_whatsapp()` (PT-3, 6.5.1) es la ÚNICA fuente de verdad
 * sobre si un usuario puede recibir WhatsApp: consentimiento + teléfono
 * no vacío + teléfono verificado. Antes de 6.5.1, Messaging_Router y
 * Campaign_Service leían `atora_consent_whatsapp` cada uno por su
 * cuenta, sin exigir verificación — un consentimiento heredado de un
 * registro viejo (con `atora_phone_verified = 0`) bastaba para recibir
 * WhatsApp real. Ningún otro punto del código debe volver a evaluar
 * estas tres condiciones por su cuenta; todos pasan por aquí.
 *
 * @package ATORA_LMS\Messaging
 * @since   6.4.0
 */

namespace ATORA\Messaging;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Preferences {

	const META_PREFS            = 'atora_messaging_preferences';
	const META_PHONE_VERIFIED   = 'atora_phone_verified';
	/** PT-4.1 (6.5.1): huella del número verificado — si el teléfono cambia, la huella deja de coincidir y is_phone_verified() falla aunque el booleano siga en 1. */
	const META_PHONE_VERIFIED_HASH = 'atora_phone_verified_hash';
	const META_VERIFY_CODE_HASH = 'atora_phone_verify_code_hash';
	const META_VERIFY_EXPIRES   = 'atora_phone_verify_expires';
	const META_VERIFY_ATTEMPTS  = 'atora_phone_verify_attempts';
	const META_VERIFY_WINDOW    = 'atora_phone_verify_window_start';
	/** PT-5 (6.5.1): intentos de VALIDAR un código, contador independiente del de SOLICITAR uno (META_VERIFY_ATTEMPTS/META_VERIFY_WINDOW, que limita pedir códigos nuevos). */
	const META_VERIFY_CODE_ATTEMPTS = 'atora_phone_verify_code_attempts';
	const MAX_CODE_ATTEMPTS         = 5;

	const CATEGORIES = array( 'academico', 'recordatorios', 'institucional' );

	const CODE_TTL_MINUTES    = 10;
	const MAX_ATTEMPTS_HOUR   = 3;

	/**
	 * Preferencias por defecto: las 3 categorías activas (correo ya
	 * cubre todo hoy, así que "todo activo" no es un cambio real de
	 * volumen — ver §UX), frecuencia instantánea, sin horario de no
	 * molestar.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'categories' => array_fill_keys( self::CATEGORIES, true ),
			'frequency'  => array_fill_keys( self::CATEGORIES, 'instant' ), // 'instant' | 'digest'
			'dnd_start'  => '',
			'dnd_end'    => '',
		);
	}

	/**
	 * @param int $user_id
	 * @return array<string,mixed>
	 */
	public static function get( int $user_id ): array {
		$stored   = get_user_meta( $user_id, self::META_PREFS, true );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::get_defaults();

		$prefs = array(
			'categories' => array_merge( $defaults['categories'], array_intersect_key( (array) ( $stored['categories'] ?? array() ), $defaults['categories'] ) ),
			'frequency'  => array_merge( $defaults['frequency'], array_intersect_key( (array) ( $stored['frequency'] ?? array() ), $defaults['frequency'] ) ),
			'dnd_start'  => self::sanitize_time( (string) ( $stored['dnd_start'] ?? '' ) ),
			'dnd_end'    => self::sanitize_time( (string) ( $stored['dnd_end'] ?? '' ) ),
		);

		foreach ( $prefs['frequency'] as $cat => $freq ) {
			$prefs['frequency'][ $cat ] = in_array( $freq, array( 'instant', 'digest' ), true ) ? $freq : 'instant';
		}

		return $prefs;
	}

	/**
	 * @param int                 $user_id
	 * @param array<string,mixed> $data Forma de get()/get_defaults().
	 * @return void
	 */
	public static function save( int $user_id, array $data ): void {
		$current = self::get( $user_id );

		$categories = array();
		foreach ( self::CATEGORIES as $cat ) {
			$categories[ $cat ] = ! empty( $data['categories'][ $cat ] ?? $current['categories'][ $cat ] );
		}

		$frequency = array();
		foreach ( self::CATEGORIES as $cat ) {
			$freq = (string) ( $data['frequency'][ $cat ] ?? $current['frequency'][ $cat ] );
			$frequency[ $cat ] = in_array( $freq, array( 'instant', 'digest' ), true ) ? $freq : 'instant';
		}

		update_user_meta(
			$user_id,
			self::META_PREFS,
			array(
				'categories' => $categories,
				'frequency'  => $frequency,
				'dnd_start'  => self::sanitize_time( (string) ( $data['dnd_start'] ?? $current['dnd_start'] ) ),
				'dnd_end'    => self::sanitize_time( (string) ( $data['dnd_end'] ?? $current['dnd_end'] ) ),
			)
		);
	}

	/**
	 * Baja total: desactiva las 3 categorías y ambos canales opcionales.
	 * El correo transaccional (2FA, etc.) no se ve afectado — esas
	 * categorías nunca aparecen aquí (Messaging_Router::category_for_type()
	 * devuelve null para ellas).
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function unsubscribe_all( int $user_id ): void {
		self::save( $user_id, array( 'categories' => array_fill_keys( self::CATEGORIES, false ) ) );
	}

	/**
	 * @param int    $user_id
	 * @param string $time 'HH:MM' o ''.
	 * @return string
	 */
	private static function sanitize_time( string $time ): string {
		$time = trim( $time );
		if ( '' === $time ) { return ''; }
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}

	// ── Canales ────────────────────────────────────────────────────────────

	/**
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_phone_verified( int $user_id ): bool {
		if ( ! (bool) get_user_meta( $user_id, self::META_PHONE_VERIFIED, true ) ) {
			return false;
		}

		// PT-3.1/3.2 (6.5.2): la rama de compatibilidad de PT-4.1 (6.5.1)
		// que trataba "verified=1 con hash vacío" como verificado por
		// retrocompatibilidad se retira aquí — confirmado con el
		// responsable del proyecto que ninguna instalación real llegó a
		// operar con el esquema de verificación anterior a 6.5.1, así
		// que no hay nadie a quien esa rama estuviera protegiendo. Sin
		// huella, no hay verificación válida — fail closed, sin
		// excepción de compatibilidad.
		$stored_hash = (string) get_user_meta( $user_id, self::META_PHONE_VERIFIED_HASH, true );
		if ( '' === $stored_hash ) {
			return false;
		}

		$current_phone = trim( (string) get_user_meta( $user_id, 'atora_phone', true ) );
		return hash_equals( $stored_hash, wp_hash( self::normalize_phone_for_hash( $current_phone ) ) );
	}

	/**
	 * Normalización estable para comparar números — mismo criterio que
	 * CRM::normalize_contact_phone() (dígitos + '+' inicial si lo
	 * tenía), reimplementado aquí porque esa está en el namespace
	 * ATORA\CRM y no se expone públicamente.
	 *
	 * @param string $phone
	 * @return string
	 */
	private static function normalize_phone_for_hash( string $phone ): string {
		$phone = sanitize_text_field( $phone );
		if ( '' === $phone ) {
			return '';
		}

		$has_plus = 0 === strpos( $phone, '+' );
		$digits   = (string) preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}

		return $has_plus ? '+' . $digits : $digits;
	}

	/**
	 * PT-4.3 (6.5.1): punto único de escritura de atora_phone — escribe
	 * el número e invalida la verificación si de verdad cambió (no en
	 * cada guardado, que sería invalidar de más por un no-cambio real).
	 * Reemplaza los tres puntos que escribían atora_phone directo
	 * (perfil del estudiante, ficha del CRM, edición admin del perfil
	 * académico) — encontrados al buscar "atora_phone" en todo el
	 * árbol, no solo los dos que mencionaba la auditoría.
	 *
	 * @param int    $user_id
	 * @param string $phone
	 * @return void
	 */
	public static function update_phone( int $user_id, string $phone ): void {
		$phone   = sanitize_text_field( $phone );
		$current = trim( (string) get_user_meta( $user_id, 'atora_phone', true ) );
		$changed = self::normalize_phone_for_hash( $current ) !== self::normalize_phone_for_hash( $phone );

		update_user_meta( $user_id, 'atora_phone', $phone );

		if ( $changed ) {
			self::invalidate_phone_verification( $user_id );
		}
	}

	/**
	 * PT-3 (6.5.1): única fuente de verdad para "¿puede este usuario
	 * recibir WhatsApp de verdad?" — consentimiento + teléfono no vacío
	 * + teléfono verificado. Antes de esto, Messaging_Router y
	 * Campaign_Service solo miraban el consentimiento; un registro
	 * viejo con consentimiento heredado pero sin verificar terminaba
	 * recibiendo mensajes por un canal que nunca confirmó.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function can_receive_whatsapp( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'atora_consent_whatsapp', true )
			&& self::is_phone_verified( $user_id )
			&& '' !== trim( (string) get_user_meta( $user_id, 'atora_phone', true ) );
	}

	/**
	 * Alias histórico de can_receive_whatsapp() — se mantiene porque la
	 * pantalla de preferencias [atora_preferencias] ya lo usa para
	 * mostrar el estado del canal; misma lógica, un solo lugar.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_whatsapp_active( int $user_id ): bool {
		return self::can_receive_whatsapp( $user_id );
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_telegram_active( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'atora_consent_telegram', true );
	}

	// ── Verificación de teléfono (PT-4.3) ─────────────────────────────────

	/**
	 * Genera y envía un código de 6 dígitos por WhatsApp. Aplica el
	 * límite de 3 intentos por hora ANTES de generar/enviar — un
	 * intento consumido es una solicitud de código, no solo un código
	 * incorrecto.
	 *
	 * @param int $user_id
	 * @return array{ok:bool, reason?:string}
	 */
	public static function request_phone_verification( int $user_id ): array {
		$phone = trim( (string) get_user_meta( $user_id, 'atora_phone', true ) );
		if ( '' === $phone ) {
			return array( 'ok' => false, 'reason' => 'sin_telefono' );
		}

		if ( ! self::under_verify_rate_limit( $user_id ) ) {
			return array( 'ok' => false, 'reason' => 'limite_intentos' );
		}

		$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		update_user_meta( $user_id, self::META_VERIFY_CODE_HASH, wp_hash( $code ) );
		update_user_meta( $user_id, self::META_VERIFY_EXPIRES, time() + ( self::CODE_TTL_MINUTES * MINUTE_IN_SECONDS ) );
		self::register_verify_attempt( $user_id );
		// PT-5.2 (6.5.1): un código nuevo resetea el contador de intentos
		// de validación del código anterior.
		delete_user_meta( $user_id, self::META_VERIFY_CODE_ATTEMPTS );

		if ( ! class_exists( '\ATORA\Messaging\WhatsApp' ) ) {
			return array( 'ok' => false, 'reason' => 'whatsapp_no_disponible' );
		}

		$sent = WhatsApp::send_template( $phone, 'atora_phone_verification', array( $code ) );

		return $sent
			? array( 'ok' => true )
			: array( 'ok' => false, 'reason' => 'envio_fallido' );
	}

	/**
	 * @param int    $user_id
	 * @param string $code Código de 6 dígitos ingresado por el usuario.
	 * @return array{ok:bool, reason?:string}
	 */
	public static function verify_phone_code( int $user_id, string $code ): array {
		$code = preg_replace( '/\D/', '', $code );
		if ( 6 !== strlen( $code ) ) {
			return array( 'ok' => false, 'reason' => 'formato_invalido' );
		}

		// PT-5.1 (6.5.1): tope de intentos de VALIDACIÓN — antes nada
		// contaba los códigos incorrectos, solo la solicitud de códigos
		// nuevos (3/hora, ya existía). Sin esto, un código de 6 dígitos
		// es fuerza-bruteable dentro de su ventana de 10 minutos.
		$code_attempts = absint( get_user_meta( $user_id, self::META_VERIFY_CODE_ATTEMPTS, true ) );
		if ( $code_attempts >= self::MAX_CODE_ATTEMPTS ) {
			self::invalidate_current_code( $user_id );
			return array( 'ok' => false, 'reason' => 'demasiados_intentos' );
		}

		$expires = absint( get_user_meta( $user_id, self::META_VERIFY_EXPIRES, true ) );
		if ( ! $expires || time() > $expires ) {
			return array( 'ok' => false, 'reason' => 'codigo_expirado' );
		}

		$stored_hash = (string) get_user_meta( $user_id, self::META_VERIFY_CODE_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, wp_hash( $code ) ) ) {
			update_user_meta( $user_id, self::META_VERIFY_CODE_ATTEMPTS, $code_attempts + 1 );
			if ( $code_attempts + 1 >= self::MAX_CODE_ATTEMPTS ) {
				self::invalidate_current_code( $user_id );
				return array( 'ok' => false, 'reason' => 'demasiados_intentos' );
			}
			return array( 'ok' => false, 'reason' => 'codigo_incorrecto' );
		}

		delete_user_meta( $user_id, self::META_VERIFY_CODE_ATTEMPTS );

		$phone = trim( (string) get_user_meta( $user_id, 'atora_phone', true ) );
		update_user_meta( $user_id, self::META_PHONE_VERIFIED, true );
		update_user_meta( $user_id, self::META_PHONE_VERIFIED_HASH, wp_hash( self::normalize_phone_for_hash( $phone ) ) );
		delete_user_meta( $user_id, self::META_VERIFY_CODE_HASH );
		delete_user_meta( $user_id, self::META_VERIFY_EXPIRES );

		return array( 'ok' => true );
	}

	/**
	 * Invalida la verificación — usarse cuando el estudiante cambia el
	 * número de teléfono, para que no quede "verificado" un número que
	 * ya no es el suyo.
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function invalidate_phone_verification( int $user_id ): void {
		delete_user_meta( $user_id, self::META_PHONE_VERIFIED );
		delete_user_meta( $user_id, self::META_PHONE_VERIFIED_HASH );
		self::invalidate_current_code( $user_id );
	}

	/**
	 * PT-5.1 (6.5.1): invalida solo el código de verificación vigente
	 * (por vencimiento del tope de intentos) — a diferencia de
	 * invalidate_phone_verification(), NO toca un teléfono ya
	 * verificado previamente. Exige solicitar un código nuevo para
	 * seguir intentando.
	 *
	 * @param int $user_id
	 * @return void
	 */
	private static function invalidate_current_code( int $user_id ): void {
		delete_user_meta( $user_id, self::META_VERIFY_CODE_HASH );
		delete_user_meta( $user_id, self::META_VERIFY_EXPIRES );
		delete_user_meta( $user_id, self::META_VERIFY_CODE_ATTEMPTS );
	}

	/**
	 * @param int $user_id
	 * @return bool true si todavía puede solicitar un código esta hora.
	 */
	private static function under_verify_rate_limit( int $user_id ): bool {
		$window_start = absint( get_user_meta( $user_id, self::META_VERIFY_WINDOW, true ) );
		if ( ! $window_start || ( time() - $window_start ) >= HOUR_IN_SECONDS ) {
			return true; // ventana vencida o inexistente — se reinicia en register_verify_attempt().
		}

		$attempts = absint( get_user_meta( $user_id, self::META_VERIFY_ATTEMPTS, true ) );
		return $attempts < self::MAX_ATTEMPTS_HOUR;
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private static function register_verify_attempt( int $user_id ): void {
		$window_start = absint( get_user_meta( $user_id, self::META_VERIFY_WINDOW, true ) );
		if ( ! $window_start || ( time() - $window_start ) >= HOUR_IN_SECONDS ) {
			update_user_meta( $user_id, self::META_VERIFY_WINDOW, time() );
			update_user_meta( $user_id, self::META_VERIFY_ATTEMPTS, 1 );
			return;
		}

		update_user_meta( $user_id, self::META_VERIFY_ATTEMPTS, absint( get_user_meta( $user_id, self::META_VERIFY_ATTEMPTS, true ) ) + 1 );
	}

	// ── Token de baja sin sesión (PT-4.4) ─────────────────────────────────

	/**
	 * Token firmado (HMAC, sin estado en BD) para el enlace de baja de
	 * cada mensaje — válido sin iniciar sesión. Codifica user_id +
	 * categoría (o 'all') + expiración; no requiere tabla de tokens.
	 *
	 * @param int    $user_id
	 * @param string $category Categoría a la que aplica el enlace, o 'all'.
	 * @param int    $ttl_days Vigencia en días (default 90 — el enlace vive tanto como el mensaje pueda leerse tarde).
	 * @return string
	 */
	public static function generate_unsubscribe_token( int $user_id, string $category = 'all', int $ttl_days = 90 ): string {
		$expires = time() + ( max( 1, $ttl_days ) * DAY_IN_SECONDS );
		$payload = $user_id . '|' . $category . '|' . $expires;
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

		return rtrim( strtr( base64_encode( $payload . '|' . $sig ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $token
	 * @return array{ok:bool, user_id?:int, category?:string, reason?:string}
	 */
	public static function verify_unsubscribe_token( string $token ): array {
		$decoded = base64_decode( strtr( $token, '-_', '+/' ), true );
		if ( false === $decoded ) {
			return array( 'ok' => false, 'reason' => 'token_invalido' );
		}

		$parts = explode( '|', $decoded );
		if ( 4 !== count( $parts ) ) {
			return array( 'ok' => false, 'reason' => 'token_invalido' );
		}

		list( $user_id, $category, $expires, $sig ) = $parts;
		$payload = $user_id . '|' . $category . '|' . $expires;

		if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), $sig ) ) {
			return array( 'ok' => false, 'reason' => 'token_invalido' );
		}

		if ( time() > absint( $expires ) ) {
			return array( 'ok' => false, 'reason' => 'token_expirado' );
		}

		return array( 'ok' => true, 'user_id' => absint( $user_id ), 'category' => sanitize_key( $category ) );
	}
}
