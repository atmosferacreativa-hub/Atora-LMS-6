<?php
/**
 * CLMS_Email — Capa central de correo transaccional de ATORA LMS
 *
 * Centraliza remitente, plantilla HTML institucional y branding usando
 * los ajustes de academia guardados en CLMS_Settings::get_academy_settings().
 *
 * El SMTP no está gestionado aquí; se delega al proveedor configurado
 * a nivel de WordPress (p. ej. Brevo vía plugin SMTP externo).
 *
 * Uso:
 *   CLMS_Email::send( $to, $subject, $message, $args );
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Email {

	// ── Constructor — registra filtros de remitente ───────────────────────────

	public function __construct() {
		add_filter( 'wp_mail_from',      array( $this, 'filter_from_email' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ) );
	}

	/**
	 * Sobreescribe la dirección "De:" con el email de contacto de la academia.
	 *
	 * @param string $original Email original de WordPress.
	 * @return string
	 */
	public function filter_from_email( $original ) {
		$branding = self::get_branding();
		$contact  = $branding['contact_email'];
		return ( $contact && is_email( $contact ) ) ? $contact : $original;
	}

	/**
	 * Sobreescribe el nombre "De:" con el nombre de la academia.
	 *
	 * @param string $original Nombre original de WordPress.
	 * @return string
	 */
	public function filter_from_name( $original ) {
		$branding = self::get_branding();
		$name     = $branding['academy_name'];
		return $name ? $name : $original;
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Envía un email con la plantilla institucional de ATORA.
	 *
	 * @param string|array $to      Destinatario o array de destinatarios.
	 * @param string       $subject Asunto del email.
	 * @param string       $message Cuerpo principal. Acepta texto plano o HTML
	 *                              limitado (wp_kses_post). Las líneas se
	 *                              convierten a párrafos automáticamente (wpautop).
	 * @param array        $args    Argumentos opcionales:
	 *   - preheader   string  Texto oculto de previsualización (< 150 caracteres).
	 *   - headline    string  Titular visible del email (encabezado h1).
	 *   - button_text string  Texto del botón CTA.
	 *   - button_url  string  URL del botón CTA.
	 *   - footer_note string  Nota adicional en el pie del email.
	 *   - reply_to    string  Dirección de respuesta.
	 *   - attachments array   Rutas absolutas de archivos adjuntos.
	 *   - headers     array   Headers adicionales (strings "Nombre: valor").
	 * @return bool True si wp_mail() aceptó el mensaje.
	 */
	public static function send( $to, $subject, $message, array $args = array() ) {
		$subject = (string) $subject;
		$message = (string) $message;

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$reply_to = isset( $args['reply_to'] ) ? sanitize_email( (string) $args['reply_to'] ) : '';
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $extra_header ) {
				if ( is_string( $extra_header ) && '' !== trim( $extra_header ) ) {
					$headers[] = $extra_header;
				}
			}
		}

		$attachments = isset( $args['attachments'] ) && is_array( $args['attachments'] )
			? array_values( array_filter( $args['attachments'], 'is_string' ) )
			: array();

		$html = self::build_template( $subject, $message, $args );

		add_filter( 'wp_mail_content_type', array( 'CLMS_Email', 'html_content_type' ) );
		$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		remove_filter( 'wp_mail_content_type', array( 'CLMS_Email', 'html_content_type' ) );

		return (bool) $sent;
	}

	/**
	 * Callback para wp_mail_content_type: fuerza HTML.
	 * Se añade y elimina alrededor de cada send() para no afectar otros emails.
	 *
	 * @return string
	 */
	public static function html_content_type() {
		return 'text/html';
	}

	// ── Plantilla ─────────────────────────────────────────────────────────────

	/**
	 * Construye el HTML completo de la plantilla institucional.
	 *
	 * @param string $subject Asunto (usado como title y headline por defecto).
	 * @param string $message Cuerpo del mensaje.
	 * @param array  $args    Igual que en send().
	 * @return string HTML del email.
	 */
	private static function build_template( $subject, $message, array $args ) {
		$b = self::get_branding();

		$color   = esc_attr( $b['primary_color'] );
		$name    = $b['academy_name'];
		$email_c = $b['contact_email'];
		$logo_id = $b['logo_id'];

		$preheader   = isset( $args['preheader'] )   ? sanitize_text_field( (string) $args['preheader'] )   : '';
		$headline    = isset( $args['headline'] )    ? sanitize_text_field( (string) $args['headline'] )    : sanitize_text_field( $subject );
		$button_text = isset( $args['button_text'] ) ? sanitize_text_field( (string) $args['button_text'] ) : '';
		$button_url  = isset( $args['button_url'] )  ? esc_url_raw( (string) $args['button_url'] )          : '';
		$footer_note = isset( $args['footer_note'] ) ? sanitize_text_field( (string) $args['footer_note'] ) : '';

		// Cuerpo: acepta HTML limitado; wpautop convierte saltos de línea en párrafos.
		$body_html = wp_kses_post( wpautop( $message ) );

		// ── Bloque de cabecera: logo + nombre de academia ─────────────────────
		$header_inner = '';
		if ( $logo_id ) {
			$logo_src = wp_get_attachment_url( absint( $logo_id ) );
			if ( $logo_src ) {
				$header_inner .= '<img src="' . esc_url( $logo_src ) . '" alt="' . esc_attr( $name ) . '"'
					. ' style="max-height:48px;max-width:200px;height:auto;display:block;margin:0 auto 8px;">';
				$header_inner .= '<p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;">' . esc_html( $name ) . '</p>';
			}
		}

		if ( '' === $header_inner ) {
			$header_inner = '<p style="margin:0;color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-.3px;">' . esc_html( $name ) . '</p>';
		}

		// ── Preheader oculto ──────────────────────────────────────────────────
		$preheader_block = '';
		if ( $preheader ) {
			$preheader_block = '<div style="display:none;font-size:1px;color:#f4f6f9;line-height:1px;'
				. 'max-height:0;max-width:0;opacity:0;overflow:hidden;">'
				. esc_html( $preheader ) . '</div>';
		}

		// ── Botón CTA ─────────────────────────────────────────────────────────
		$cta_block = '';
		if ( $button_text && $button_url ) {
			$cta_block = '<tr><td style="padding:24px 40px 8px;text-align:center;">'
				. '<a href="' . esc_url( $button_url ) . '"'
				. ' style="display:inline-block;background-color:' . $color . ';color:#ffffff;'
				. 'padding:12px 28px;border-radius:6px;text-decoration:none;font-size:15px;'
				. 'font-weight:600;letter-spacing:.3px;">'
				. esc_html( $button_text )
				. '</a></td></tr>';
		}

		// ── Pie de página ─────────────────────────────────────────────────────
		$footer_contact_html = '';
		if ( $email_c && is_email( $email_c ) ) {
			$footer_contact_html = '<p style="margin:0 0 4px;">'
				. '<a href="mailto:' . esc_attr( $email_c ) . '" style="color:#9ca3af;text-decoration:none;">'
				. esc_html( $email_c )
				. '</a></p>';
		}

		$site_host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$footer_site_html = '<p style="margin:0;">'
			. '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:#9ca3af;text-decoration:none;">'
			. esc_html( $site_host ?: home_url() )
			. '</a></p>';

		$footer_note_html = $footer_note
			? '<p style="margin:8px 0 0;font-size:12px;color:#9ca3af;">' . esc_html( $footer_note ) . '</p>'
			: '';

		// ── Ensamblado final ──────────────────────────────────────────────────
		$html  = '<!DOCTYPE html>';
		$html .= '<html lang="es">';
		$html .= '<head>';
		$html .= '<meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$html .= '<title>' . esc_html( $subject ) . '</title>';
		$html .= '</head>';
		$html .= '<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';
		$html .= $preheader_block;

		// Tabla exterior (fondo gris)
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" role="presentation"'
			. ' style="background-color:#f4f6f9;padding:32px 16px;">';
		$html .= '<tr><td align="center">';

		// Tabla interior (tarjeta blanca, max 600px)
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" role="presentation"'
			. ' style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">';

		// Cabecera con color de marca
		$html .= '<tr><td style="background-color:' . $color . ';padding:28px 40px;text-align:center;">';
		$html .= $header_inner;
		$html .= '</td></tr>';

		// Titular
		$html .= '<tr><td style="padding:32px 40px 0;">';
		$html .= '<h1 style="margin:0;font-size:20px;font-weight:700;color:#1a202c;line-height:1.35;">';
		$html .= esc_html( $headline );
		$html .= '</h1>';
		$html .= '</td></tr>';

		// Cuerpo del mensaje
		$html .= '<tr><td style="padding:16px 40px 8px;font-size:15px;color:#374151;line-height:1.75;">';
		$html .= $body_html;
		$html .= '</td></tr>';

		// Botón CTA (si hay)
		$html .= $cta_block;

		// Separador + pie
		$html .= '<tr><td style="padding:24px 40px 32px;border-top:1px solid #e5e7eb;text-align:center;'
			. 'color:#9ca3af;font-size:13px;">';
		$html .= $footer_contact_html;
		$html .= $footer_site_html;
		$html .= $footer_note_html;
		$html .= '</td></tr>';

		$html .= '</table>'; // tarjeta
		$html .= '</td></tr>';
		$html .= '</table>'; // fondo
		$html .= '</body></html>';

		return $html;
	}

	// ── Branding ──────────────────────────────────────────────────────────────

	/**
	 * Lee los datos de branding desde CLMS_Settings si está disponible.
	 * Fallback a wp_options y get_bloginfo() si la clase no está cargada todavía.
	 *
	 * @return array { academy_name, primary_color, logo_id, contact_email, teacher_contact_email, admin_contact_email }
	 */
	private static function get_branding() {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' ) ) {
			$s = CLMS_Settings::get_academy_settings();
		} else {
			$stored = (array) get_option( 'clms_academy_settings', array() );
			$s      = array_merge(
				array(
					'academy_name'  => get_bloginfo( 'name' ),
					'primary_color' => '#6366f1',
					'logo_id'       => 0,
					'contact_email' => get_bloginfo( 'admin_email' ),
					'teacher_contact_email' => get_bloginfo( 'admin_email' ),
					'admin_contact_email' => get_bloginfo( 'admin_email' ),
				),
				$stored
			);
		}

		return array(
			'academy_name'  => sanitize_text_field( (string) ( isset( $s['academy_name'] ) ? $s['academy_name'] : get_bloginfo( 'name' ) ) ),
			'primary_color' => ( sanitize_hex_color( (string) ( isset( $s['primary_color'] ) ? $s['primary_color'] : '#6366f1' ) ) ?: '#6366f1' ),
			'logo_id'       => absint( isset( $s['logo_id'] ) ? $s['logo_id'] : 0 ),
			'contact_email' => sanitize_email( (string) ( isset( $s['contact_email'] ) ? $s['contact_email'] : '' ) ),
			'teacher_contact_email' => sanitize_email( (string) ( isset( $s['teacher_contact_email'] ) ? $s['teacher_contact_email'] : '' ) ),
			'admin_contact_email' => sanitize_email( (string) ( isset( $s['admin_contact_email'] ) ? $s['admin_contact_email'] : '' ) ),
		);
	}
}
