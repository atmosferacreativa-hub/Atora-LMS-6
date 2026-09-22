<?php
/**
 * CLMS_Legacy_Slug_Redirects — PT-4.3.4 (sprint 6.3.0)
 *
 * Mapa único y documentado de slugs de página admin retirados de la
 * navegación visible → su reemplazo. Se aplica en 'admin_init', antes
 * de que admin.php despache al callback de renderizado, así que nunca
 * se llega a ejecutar la vista vieja.
 *
 * Regla del sprint: ninguna página se elimina, solo se reubica. Un
 * slug retirado SIEMPRE redirige (301) a su reemplazo — nunca 404, nunca
 * "no tienes permitido acceder". Este mapa se retira en 6.5.0, cuando
 * los slugs legacy se consideren candidatos a eliminación real.
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Legacy_Slug_Redirects {

	/**
	 * slug_antiguo => slug_nuevo. Solo mapea páginas cuyo reemplazo
	 * renderiza contenido equivalente o superior — nunca un slug
	 * "consolidado" hacia otro que pierda funcionalidad real.
	 *
	 * @return array<string,string>
	 */
	public static function get_map(): array {
		return array(
			// PT-4.3.1 — Analytics ×3: atora-analytics-dashboard es el real.
			'atora-analytics' => 'atora-analytics-dashboard',
			'clms-analytics'  => 'atora-analytics-dashboard',
			// B.2 (6.26.6): slug fantasma observado en enlaces viejos.
			// La UI real de cohortes es el post type `lm_cohort`.
			'clms-cohorts'    => 'edit.php?post_type=lm_cohort',
		);
	}

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	public static function maybe_redirect(): void {
		if ( ! isset( $_GET['page'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_key( wp_unslash( (string) $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$map = self::get_map();
		if ( ! isset( $map[ $page ] ) ) { return; }

		$target = (string) $map[ $page ];
		// Soporta targets que ya son admin.php?page=... (slug) o pantallas
		// core como edit.php?post_type=...
		$is_full_admin_path = false !== strpos( $target, '.php' ) || false !== strpos( $target, '?' );
		wp_safe_redirect( admin_url( $is_full_admin_path ? $target : ( 'admin.php?page=' . $target ) ), 301 );
		exit;
	}
}
