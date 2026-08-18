<?php
/**
 * ATORA LMS v5 — Plantillas de email
 *
 * Motor de plantillas Mustache-like para los 16 emails transaccionales.
 * Renderiza HTML responsive + versión plain text.
 * Footer de compliance se inyecta automáticamente (no removible).
 *
 * @package ATORA_LMS\EmailEngine
 * @since   5.0.0
 */

namespace ATORA\EmailEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Email_Templates
 *
 * @since 5.0.0
 */
class Email_Templates {

	/**
	 * Catálogo de plantillas.
	 *
	 * @return array<string, array>
	 */
	public static function get_catalog(): array {
		return array(
			// ── Académicos ──────────────────────────────────────────────────
			'welcome_course'        => array(
				'id'       => 1,
				'name'     => __( 'Bienvenida al curso', 'atora-lms' ),
				'subject'  => __( '¡Bienvenido/a a {{course_title}}! 🎓', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			'grade_published'       => array(
				'id'       => 2,
				'name'     => __( 'Calificación publicada', 'atora-lms' ),
				'subject'  => __( 'Tu calificación en {{lesson_title}} ya está disponible', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			'new_lesson_unlocked'   => array(
				'id'       => 3,
				'name'     => __( 'Nueva lección disponible', 'atora-lms' ),
				'subject'  => __( '🔓 Nueva lección desbloqueada: {{lesson_title}}', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			'inactivity_reminder'   => array(
				'id'       => 4,
				'name'     => __( 'Recordatorio de inactividad', 'atora-lms' ),
				'subject'  => __( '{{display_name}}, te echamos de menos 👋', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			'certificate_ready'     => array(
				'id'       => 5,
				'name'     => __( 'Certificado listo', 'atora-lms' ),
				'subject'  => __( '🎉 ¡Tu certificado de {{course_title}} está listo!', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			'program_started'       => array(
				'id'       => 6,
				'name'     => __( 'Programa iniciado', 'atora-lms' ),
				'subject'  => __( 'Comenzamos: {{program_title}}', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			'program_module_completed' => array(
				'id'       => 7,
				'name'     => __( 'Módulo completado', 'atora-lms' ),
				'subject'  => __( '✅ Módulo {{module_title}} completado', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			'submission_received'   => array(
				'id'       => 8,
				'name'     => __( 'Entrega recibida', 'atora-lms' ),
				'subject'  => __( 'Recibimos tu entrega — {{lesson_title}}', 'atora-lms' ),
				'category' => 'academic',
				'active'   => true,
			),
			// ── Comerciales ─────────────────────────────────────────────────
			'new_course_launch'     => array(
				'id'       => 9,
				'name'     => __( 'Lanzamiento de curso', 'atora-lms' ),
				'subject'  => __( '🚀 Nuevo curso: {{course_title}}', 'atora-lms' ),
				'category' => 'commercial',
				'active'   => true,
			),
			'cart_abandonment'      => array(
				'id'       => 10,
				'name'     => __( 'Carrito abandonado', 'atora-lms' ),
				'subject'  => __( '¿Olvidaste algo? Tu curso te espera 🛒', 'atora-lms' ),
				'category' => 'commercial',
				'active'   => true,
			),
			'personalized_recommendation' => array(
				'id'       => 11,
				'name'     => __( 'Recomendación personalizada', 'atora-lms' ),
				'subject'  => __( 'Esto puede interesarte, {{display_name}} 💡', 'atora-lms' ),
				'category' => 'commercial',
				'active'   => true,
			),
			'special_offer'         => array(
				'id'       => 12,
				'name'     => __( 'Oferta especial', 'atora-lms' ),
				'subject'  => __( '⚡ Oferta especial: {{discount}}% de descuento', 'atora-lms' ),
				'category' => 'commercial',
				'active'   => true,
			),
			// ── Sistema ──────────────────────────────────────────────────────
			'purchase_confirmation' => array(
				'id'       => 13,
				'name'     => __( 'Confirmación de compra', 'atora-lms' ),
				'subject'  => __( 'Confirmación de tu pedido #{{order_id}}', 'atora-lms' ),
				'category' => 'system',
				'active'   => true,
			),
			'password_recovery'     => array(
				'id'       => 14,
				'name'     => __( 'Recuperar contraseña', 'atora-lms' ),
				'subject'  => __( 'Recupera tu contraseña en {{site_name}}', 'atora-lms' ),
				'category' => 'system',
				'active'   => true,
			),
			'account_changes'       => array(
				'id'       => 15,
				'name'     => __( 'Cambios en cuenta', 'atora-lms' ),
				'subject'  => __( 'Tu cuenta en {{site_name}} ha sido actualizada', 'atora-lms' ),
				'category' => 'system',
				'active'   => true,
			),
			'invoice_available'     => array(
				'id'       => 16,
				'name'     => __( 'Factura disponible', 'atora-lms' ),
				'subject'  => __( 'Tu factura del pedido #{{order_id}} está lista', 'atora-lms' ),
				'category' => 'system',
				'active'   => true,
			),
			// ── Extra (Sprint 11-12) ─────────────────────────────────────────
			'affiliate_commission'  => array(
				'id'       => 17,
				'name'     => __( 'Comisión de afiliado', 'atora-lms' ),
				'subject'  => __( '💰 ¡Nueva comisión! +${{commission}}', 'atora-lms' ),
				'category' => 'affiliates',
				'active'   => true,
			),
			'manual_invitation'     => array(
				'id'       => 18,
				'name'     => __( 'Invitación manual', 'atora-lms' ),
				'subject'  => __( 'Invitación al curso: {{course_title}}', 'atora-lms' ),
				'category' => 'system',
				'active'   => true,
			),
			'crm_campaign'         => array(
				'id'       => 19,
				'name'     => __( 'Campaña CRM', 'atora-lms' ),
				'subject'  => __( '{{custom_subject}}', 'atora-lms' ),
				'category' => 'commercial',
				'active'   => true,
			),
		);
	}

	/**
	 * Obtiene una plantilla por su clave.
	 *
	 * @param string $key Clave de la plantilla.
	 * @return array|null
	 */
	public static function get( string $key ): ?array {
		$catalog = self::get_catalog();
		return $catalog[ $key ] ?? null;
	}

	/**
	 * Construye el contexto de variables para una plantilla.
	 *
	 * @param \WP_User $user     Usuario destinatario.
	 * @param array    $metadata Metadata adicional (course_id, lesson_id, grade…).
	 * @return array
	 */
	public static function build_context( \WP_User $user, array $metadata = array() ): array {
		$site_name     = get_bloginfo( 'name' );
		$course_id     = absint( $metadata['course_id'] ?? 0 );
		$lesson_id     = absint( $metadata['lesson_id'] ?? 0 );
		$dashboard_url = home_url( '/dashboard/' );

		$page_id = absint( get_option( 'clms_student_dashboard_page_id' ) );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				$dashboard_url = $url;
			}
		}

		return array_merge( array(
			'display_name'     => $user->display_name,
			'user_email'       => $user->user_email,
			'user_login'       => $user->user_login,
			'site_name'        => $site_name,
			'site_url'         => home_url( '/' ),
			'dashboard_url'    => $dashboard_url,
			'unsub_url'        => add_query_arg( 'atora_email_action', 'unsubscribe', home_url( '/' ) ),
			'preferences_url'  => add_query_arg( 'atora_email_action', 'preferences', home_url( '/' ) ),
			'course_title'     => $course_id ? get_the_title( $course_id ) : '',
			'course_url'       => $course_id ? (string) get_permalink( $course_id ) : '',
			'lesson_title'     => $lesson_id ? get_the_title( $lesson_id ) : '',
			'lesson_url'       => $lesson_id ? (string) get_permalink( $lesson_id ) : '',
			'grade'            => $metadata['grade'] ?? '',
			'best_score'       => $metadata['best_score'] ?? '',
			'latest_score'     => $metadata['latest_score'] ?? '',
			'attempts'         => $metadata['attempts'] ?? '',
			'remaining_attempts' => $metadata['remaining_attempts'] ?? '',
			'retry_available'  => ! empty( $metadata['retry_available'] ) ? 1 : 0,
			'next_deadline'    => $metadata['next_deadline'] ?? '',
			'student_message'  => $metadata['student_message'] ?? '',
			'retry_hint'       => $metadata['retry_hint'] ?? '',
			'evaluation_type'  => $metadata['evaluation_type'] ?? '',
			'evaluation_mode'  => $metadata['evaluation_mode'] ?? '',
			'submitted_label'  => $metadata['submitted_label'] ?? '',
			'order_id'         => $metadata['order_id'] ?? '',
			'commission'       => isset( $metadata['commission'] ) ? number_format( (float) $metadata['commission'], 2 ) : '0.00',
			'discount'         => $metadata['discount'] ?? '',
			'program_title'    => $metadata['program_title'] ?? '',
			'module_title'     => $metadata['module_title'] ?? '',
		), $metadata );
	}

	/**
	 * Renderiza el subject de una plantilla.
	 *
	 * @param string $template Subject con {{variables}}.
	 * @param array  $context  Contexto.
	 * @return string
	 */
	public static function render_string( string $template, array $context ): string {
		return preg_replace_callback( '/\{\{(\w+)\}\}/', static function ( $matches ) use ( $context ) {
			$value = $context[ $matches[1] ] ?? '';
			if ( is_bool( $value ) ) {
				$value = $value ? '1' : '0';
			} elseif ( ! is_scalar( $value ) ) {
				$value = '';
			}
			return esc_html( (string) $value );
		}, $template );
	}

	/**
	 * Renderiza el HTML completo del email (wrapper + contenido + footer compliance).
	 *
	 * @param string $template_key Clave de plantilla.
	 * @param array  $context      Contexto.
	 * @return string
	 */
	public static function render_html( string $template_key, array $context ): string {
		$body    = self::get_template_html_body( $template_key, $context );
		$footer  = self::get_compliance_footer( $context );
		$wrapper = self::get_html_wrapper( $body . $footer, $context );

		return $wrapper;
	}

	/**
	 * Renderiza la versión plain text.
	 *
	 * @param string $template_key Clave de plantilla.
	 * @param array  $context      Contexto.
	 * @return string
	 */
	public static function render_text( string $template_key, array $context ): string {
		$html = self::render_html( $template_key, $context );
		return self::html_to_plain_text( $html );
	}

	// ── HTML body por plantilla ───────────────────────────────────────────────

	/**
	 * Devuelve el contenido HTML específico de la plantilla.
	 *
	 * @param string $key     Clave de plantilla.
	 * @param array  $context Contexto.
	 * @return string
	 */
	private static function get_template_html_body( string $key, array $context ): string {
		$view = ATORA_LMS_MODULES_DIR . 'email-engine/templates/' . sanitize_key( $key ) . '.php';

		if ( file_exists( $view ) ) {
			ob_start();
			extract( $context, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $view;
			return ob_get_clean();
		}

		// Fallback genérico.
		return self::generic_body( $key, $context );
	}

	/**
	 * Genera un cuerpo genérico para plantillas sin vista específica.
	 *
	 * @param string $key     Clave de plantilla.
	 * @param array  $context Contexto.
	 * @return string
	 */
	private static function generic_body( string $key, array $context ): string {
		$template = self::get( $key );
		$title    = $template['name'] ?? $key;
		$name     = esc_html( $context['display_name'] ?? '' );

		return "
		<h1 style='color:#0f172a;font-size:22px;margin:0 0 16px;'>{$title}</h1>
		<p>Hola {$name},</p>
		<p>Tienes una nueva notificación en <strong>" . esc_html( $context['site_name'] ) . "</strong>.</p>
		<p><a href='" . esc_url( $context['dashboard_url'] ) . "'
		      style='display:inline-block;background:#6366f1;color:#fff;padding:12px 24px;
		             border-radius:8px;text-decoration:none;font-weight:600;'>
			Ir a mi panel
		</a></p>";
	}

	// ── Wrapper HTML ──────────────────────────────────────────────────────────

	/**
	 * Envuelve el contenido en el wrapper HTML del email.
	 *
	 * @param string $content Contenido.
	 * @param array  $context Contexto.
	 * @return string
	 */
	private static function get_html_wrapper( string $content, array $context ): string {
		$site_name = esc_html( $context['site_name'] ?? get_bloginfo( 'name' ) );

		// Branding configurable desde Settings → color primario, logo y nombre.
		$opts       = (array) get_option( 'atora_email_engine_options', array() );
		$primary    = sanitize_hex_color( $opts['brand_primary_color'] ?? '' ) ?: '#3f98ee';
		$logo_url   = esc_url( $opts['brand_logo_url'] ?? '' );
		$brand_name = esc_html( $opts['brand_name'] ?? $site_name );

		$header_content = $logo_url
			? "<img src='{$logo_url}' alt='{$brand_name}' style='max-height:48px;' />"
			: "<h2 style='color:#fff;margin:0;font-size:20px;font-weight:700;'>{$brand_name}</h2>";

		return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$site_name}</title>
<style>
body{margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#0f172a}
.wrapper{max-width:600px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.07)}
.header{background:{$primary};padding:24px;text-align:center}
.body{padding:32px}
.footer{background:#f8fafc;padding:20px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;text-align:center}
a{color:{$primary}}
@media(max-width:600px){.wrapper{margin:0;border-radius:0}}
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">{$header_content}</div>
  <div class="body">{$content}</div>
</div>
</body>
</html>
HTML;
	}

	// ── Footer de compliance ──────────────────────────────────────────────────

	/**
	 * Genera el footer de compliance (NO removible — CAN-SPAM / GDPR).
	 *
	 * @param array $context Contexto.
	 * @return string
	 */
	public static function get_compliance_footer( array $context ): string {
		$unsub_url   = esc_url( $context['unsub_url'] ?? home_url( '/' ) );
		$prefs_url   = esc_url( $context['preferences_url'] ?? home_url( '/' ) );
		$site_name   = esc_html( $context['site_name'] ?? get_bloginfo( 'name' ) );
		$course_title = esc_html( $context['course_title'] ?? '' );
		$address     = esc_html( get_option( 'atora_academy_address', get_option( 'blogdescription', '' ) ) );

		$reason = $course_title
			? sprintf( __( 'Recibes este email porque estás inscrito en %s.', 'atora-lms' ), $course_title )
			: sprintf( __( 'Recibes este email porque tienes una cuenta en %s.', 'atora-lms' ), $site_name );

		return "
		<div style='border-top:1px solid #e2e8f0;margin-top:32px;padding-top:20px;font-size:12px;color:#94a3b8;text-align:center;'>
			<p>{$reason}</p>
			<p>{$site_name}" . ( $address ? "<br>{$address}" : '' ) . "</p>
			<p>
				<a href='{$prefs_url}' style='color:#94a3b8;'>" . esc_html__( 'Gestionar preferencias', 'atora-lms' ) . "</a>
				&nbsp;·&nbsp;
				<a href='{$unsub_url}' style='color:#94a3b8;'>" . esc_html__( 'Cancelar suscripción', 'atora-lms' ) . "</a>
			</p>
			<p style='color:#cbd5e1;font-size:10px;'>
				Powered by <a href='https://atoralms.com' style='color:#94a3b8;text-decoration:none;'>ATORA LMS</a>
			</p>
		</div>";
	}

	/**
	 * Convierte HTML de email a texto plano legible (sin CSS embebido).
	 *
	 * @param string $html HTML completo.
	 * @return string
	 */
	private static function html_to_plain_text( string $html ): string {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}

		// Remueve bloques no textuales antes de hacer strip para evitar ruido.
		$html = (string) preg_replace( '#<style\b[^>]*>.*?</style>#is', ' ', $html );
		$html = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', ' ', $html );
		$html = (string) preg_replace( '#<head\b[^>]*>.*?</head>#is', ' ', $html );
		$html = (string) preg_replace( '#<noscript\b[^>]*>.*?</noscript>#is', ' ', $html );

		// Conserva saltos lógicos para mejorar lectura en clientes sin HTML.
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</(p|div|h1|h2|h3|h4|h5|h6|tr|section|article)>#i', "\n", $html );
		$html = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = (string) preg_replace( '#</li>#i', "\n", $html );

		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}
}
