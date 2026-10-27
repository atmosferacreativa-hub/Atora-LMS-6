<?php
/**
 * Editor de plantillas de correo (6.38.0, Orden C · Comunicación).
 *
 * Cada plantilla del catálogo puede personalizarse con un asunto y bloques (título, texto, botón, separador).
 * - Una variable desconocida es un error al guardar (no un hueco vacío al enviar).
 * - El texto plano se genera solo a partir del HTML.
 * - "Restaurar" vuelve a la plantilla original.
 *
 * @package ATORA_LMS
 * @since 6.38.0
 */

namespace ATORA\EmailEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Template_Editor {

	const OPTION      = 'atora_email_template_overrides';
	const BLOCK_TYPES = array( 'heading', 'text', 'button', 'divider' );
	const MAX_BLOCKS  = 30;

	/** @return array<string,array{subject:string,blocks:array}> */
	public static function overrides(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function override_for( string $key ): ?array {
		$all = self::overrides();
		return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Variables que acepta una plantilla: las de todo correo más las que ya usa la plantilla original.
	 *
	 * @return string[]
	 */
	public static function allowed_variables( string $key ): array {
		$template = Email_Templates::get_original( $key );
		if ( ! $template ) {
			return array();
		}
		$base = array(
			'display_name', 'user_email', 'user_login', 'site_name', 'site_url', 'dashboard_url', 'unsub_url',
			'preferences_url', 'course_title', 'course_url', 'lesson_title', 'lesson_url',
		);
		$own  = self::variables_in( implode( ' ', array( (string) ( $template['subject'] ?? '' ), (string) ( $template['body'] ?? '' ), (string) ( $template['button_text'] ?? '' ) ) ) );
		$own  = array_merge( $own, (array) ( $template['variables'] ?? array() ), self::known_metadata( $key ) );
		$all  = array_values( array_unique( array_merge( $base, $own ) ) );
		sort( $all );
		return $all;
	}

	/** @return string[] */
	public static function variables_in( string $text ): array {
		preg_match_all( '/\{\{\s*(\w+)\s*\}\}/', $text, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * @param array{subject?:mixed,blocks?:mixed} $data
	 * @return array{subject:string,blocks:array}|\WP_Error
	 */
	public static function validate( string $key, array $data ) {
		if ( ! Email_Templates::get( $key ) ) {
			return new \WP_Error( 'atora_email_template_unknown', __( 'Esa plantilla no existe.', 'atora-lms' ) );
		}
		$subject = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );
		if ( '' === $subject ) {
			return new \WP_Error( 'atora_email_template_subject', __( 'El asunto no puede quedar vacío.', 'atora-lms' ) );
		}
		$blocks = array();
		foreach ( array_slice( is_array( $data['blocks'] ?? null ) ? $data['blocks'] : array(), 0, self::MAX_BLOCKS ) as $raw ) {
			$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
			if ( ! in_array( $type, self::BLOCK_TYPES, true ) ) {
				continue;
			}
			$block = array( 'type' => $type );
			if ( 'divider' !== $type ) {
				$block['text'] = sanitize_textarea_field( (string) ( $raw['text'] ?? '' ) );
				if ( '' === trim( $block['text'] ) ) {
					continue;
				}
			}
			if ( 'button' === $type ) {
				$url = trim( (string) ( $raw['url'] ?? '' ) );
				// La URL puede ser una variable ({{course_url}}) o una dirección http(s).
				if ( 1 !== preg_match( '/^\{\{\s*\w+\s*\}\}$/', $url ) && ! wp_http_validate_url( $url ) ) {
					return new \WP_Error( 'atora_email_template_url', sprintf( /* translators: %s: url */ __( 'El enlace del botón no es válido: %s', 'atora-lms' ), $url ) );
				}
				$block['url'] = $url;
			}
			$blocks[] = $block;
		}
		if ( ! $blocks ) {
			return new \WP_Error( 'atora_email_template_empty', __( 'Agrega al menos un bloque con contenido.', 'atora-lms' ) );
		}

		$used = self::variables_in( $subject . ' ' . wp_json_encode( $blocks ) );
		$bad  = array_values( array_diff( $used, self::allowed_variables( $key ) ) );
		if ( $bad ) {
			return new \WP_Error(
				'atora_email_unknown_variable',
				sprintf( /* translators: %s: variables */ __( 'Variables desconocidas: %s. Usa solo las de la lista.', 'atora-lms' ), '{{' . implode( '}}, {{', $bad ) . '}}' ),
				array( 'variables' => $bad )
			);
		}
		return array( 'subject' => $subject, 'blocks' => $blocks );
	}

	/** @return true|\WP_Error */
	public static function save( string $key, array $data ) {
		$clean = self::validate( $key, $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$all         = self::overrides();
		$all[ $key ] = $clean + array( 'updated_at' => current_time( 'mysql', true ), 'updated_by' => get_current_user_id() );
		update_option( self::OPTION, $all, false );
		return true;
	}

	public static function reset( string $key ): void {
		$all = self::overrides();
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );
	}

	public static function render_blocks( array $blocks, array $context ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			$type = (string) ( $block['type'] ?? '' );
			$text = Email_Templates::render_string( (string) ( $block['text'] ?? '' ), $context );
			switch ( $type ) {
				case 'heading':
					$html .= '<h1 style="color:#0f172a;font-size:22px;margin:0 0 16px;">' . $text . '</h1>';
					break;
				case 'text':
					$html .= '<p style="margin:0 0 14px;line-height:1.55;">' . nl2br( $text ) . '</p>';
					break;
				case 'button':
					$url   = esc_url( html_entity_decode( Email_Templates::render_string( (string) ( $block['url'] ?? '' ), $context ) ) );
					$html .= '' !== $url
						? '<p style="margin:18px 0;"><a href="' . $url . '" style="display:inline-block;background:#0f172a;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">' . $text . '</a></p>'
						: '';
					break;
				case 'divider':
					$html .= '<hr style="border:0;border-top:1px solid #e2e8f0;margin:20px 0;">';
					break;
			}
		}
		return $html;
	}

	/**
	 * Vista previa con datos de ejemplo (no envía nada).
	 *
	 * @return array{subject:string,html:string,text:string}|\WP_Error
	 */
	public static function preview( string $key, ?array $data, \WP_User $user ) {
		if ( null !== $data ) {
			$clean = self::validate( $key, $data );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
		}
		$sample  = self::sample_metadata();
		$context = Email_Templates::build_context( $user, $sample );
		$filter  = static fn() => null === $data ? false : $clean;
		if ( null !== $data ) {
			add_filter( 'atora/email/template_override_preview/' . $key, $filter );
		}
		$out = array(
			'subject' => wp_specialchars_decode( Email_Templates::render_string( (string) ( self::subject_for( $key, null !== $data ? $clean : null ) ), $context ) ),
			'html'    => Email_Templates::render_html( $key, $context ),
			'text'    => Email_Templates::render_text( $key, $context ),
		);
		if ( null !== $data ) {
			remove_filter( 'atora/email/template_override_preview/' . $key, $filter );
		}
		return $out;
	}

	/** Bloques vigentes de una plantilla (vista previa en curso o personalización guardada). */
	public static function active_blocks( string $key ): ?array {
		$preview = apply_filters( 'atora/email/template_override_preview/' . $key, false );
		if ( is_array( $preview ) ) {
			return $preview['blocks'];
		}
		$override = self::override_for( $key );
		return $override && ! empty( $override['blocks'] ) ? (array) $override['blocks'] : null;
	}

	private static function subject_for( string $key, ?array $clean ): string {
		if ( $clean ) {
			return $clean['subject'];
		}
		$template = Email_Templates::get( $key );
		return (string) ( $template['subject'] ?? '' ); // Incluye el asunto personalizado guardado.
	}

	/** @return array<string,string> */
	private static function sample_metadata(): array {
		return array(
			'lesson_title'   => __( 'Lección de ejemplo', 'atora-lms' ),
			'grade'          => '4.5',
			'due_at'         => date_i18n( get_option( 'date_format' ), time() + DAY_IN_SECONDS ),
			'student_name'   => __( 'Estudiante de ejemplo', 'atora-lms' ),
			'teacher_name'   => __( 'Docente de ejemplo', 'atora-lms' ),
			'custom_subject' => __( 'Asunto de ejemplo', 'atora-lms' ),
			'discount'       => '20',
			'order_id'       => '1001',
			'button_url'     => home_url( '/' ),
		);
	}

	/** @return string[] Variables que el código del plugin pasa a cada plantilla. */
	private static function known_metadata( string $key ): array {
		$map = array(
			'grade_published'          => array( 'grade' ),
			'special_offer'            => array( 'discount' ),
			'purchase_confirmation'    => array( 'order_id' ),
			'invoice_available'        => array( 'order_id' ),
			'affiliate_commission'     => array( 'commission' ),
			'program_started'          => array( 'program_title' ),
			'program_module_completed' => array( 'program_title', 'module_title' ),
			'newsletter'               => array( 'custom_subject' ),
			'crm_campaign'             => array( 'custom_subject' ),
		);
		$extra = $map[ $key ] ?? array();
		if ( str_starts_with( $key, 'atora_' ) ) {
			$extra[] = 'button_url';
		}
		return $extra;
	}
}
