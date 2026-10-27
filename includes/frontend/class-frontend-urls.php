<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers de URLs frontend para experiencia “theme-first”.
 *
 * Objetivo: si el sitio tiene páginas frontend (ej. /cuenta/, /dashboard/),
 * usar esas rutas en lugar de redirigir a wp-login.php crudo.
 */
final class CLMS_Frontend_URLs {
	/**
	 * Resuelve una URL frontend por key.
	 *
	 * Duplicamos la lógica de CLMS_Frontend_Access_Profile_Trait para poder
	 * reutilizarla fuera del objeto (shortcodes, templates, UI comercial).
	 *
	 * Keys: dashboard, profile, messages, courses
	 *
	 * @param string $key
	 * @return string
	 */
	public static function resolve_frontend_page_url( string $key ): string {
		$option = get_option( 'clms_frontend_pages', array() );

		if ( ! empty( $option[ $key ] ) ) {
			$page = get_post( absint( $option[ $key ] ) );
			if ( $page && 'publish' === $page->post_status ) {
				return (string) get_permalink( $page->ID );
			}
		}

		$slug_map = array(
			'dashboard' => array( 'mi-panel', 'panel-estudiante', 'panel', 'dashboard' ),
			'profile'   => array( 'mi-perfil', 'perfil', 'profile', 'cuenta' ),
			'messages'  => array( 'mensajes', 'messages', 'bandeja' ),
			'courses'   => array( 'mis-cursos', 'cursos', 'courses' ),
		);

		if ( isset( $slug_map[ $key ] ) ) {
			foreach ( $slug_map[ $key ] as $slug ) {
				$page = get_page_by_path( $slug );
				if ( $page && 'publish' === $page->post_status ) {
					return (string) get_permalink( $page->ID );
				}
			}
		}

		return '';
	}

	/**
	 * URL de login preferente.
	 *
	 * - Si existe una página frontend (profile/cuenta), usamos esa y pasamos
	 *   redirect_to como query param.
	 * - Si no, caemos a wp_login_url() (comportamiento actual).
	 *
	 * @param string $redirect_to
	 * @param string $fallback_redirect
	 * @return string
	 */
	public static function login_url( string $redirect_to = '', string $fallback_redirect = '' ): string {
		$redirect_to = $redirect_to ? esc_url_raw( $redirect_to ) : '';
		$fallback_redirect = $fallback_redirect ? esc_url_raw( $fallback_redirect ) : '';

		$profile_url = self::resolve_frontend_page_url( 'profile' );
		if ( '' !== $profile_url ) {
			return '' !== $redirect_to
				? (string) add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $profile_url )
				: $profile_url;
		}

		if ( '' === $redirect_to ) {
			$redirect_to = $fallback_redirect;
		}
		if ( '' === $redirect_to ) {
			$redirect_to = is_singular() ? (string) get_permalink() : (string) home_url( '/' );
		}

		return (string) wp_login_url( $redirect_to );
	}
	/** Published learning destinations; never invent a route or expose admin links. */
	public static function learning_navigation( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) { return array(); }
		static $pages = null;
		if ( null === $pages ) {
			$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		}
		$find = static function ( $shortcode ) use ( $pages ) {
			if ( ! shortcode_exists( $shortcode ) ) { return ''; }
			foreach ( $pages as $page ) {
				if ( ! $page->post_password && has_shortcode( $page->post_content, $shortcode ) ) { return (string) get_permalink( $page ); }
			}
			return '';
		};
		$teacher = user_can( $user_id, 'clms_view_teacher_dashboard' ) || user_can( $user_id, 'clms_grade_submissions' );
		$dashboard = $teacher ? $find( 'clms_teacher_dashboard' ) : '';
		$dashboard = $dashboard ?: self::resolve_frontend_page_url( 'dashboard' );
		$candidates = array(
			array( 'label' => $teacher ? __( 'Panel docente', 'atora-lms' ) : __( 'Mi aprendizaje', 'atora-lms' ), 'url' => $dashboard ),
			array( 'label' => __( 'Mis cursos', 'atora-lms' ), 'url' => $find( 'clms_my_courses' ) ),
			array( 'label' => __( 'Agenda', 'atora-lms' ), 'url' => $find( 'atora_calendar' ) ),
			array( 'label' => __( 'Mensajes', 'atora-lms' ), 'url' => self::resolve_frontend_page_url( 'messages' ) ),
			array( 'label' => __( 'Mi perfil', 'atora-lms' ), 'url' => self::resolve_frontend_page_url( 'profile' ) ),
		);
		return array_values( array_filter( $candidates, static function ( $item ) { return '' !== $item['url']; } ) );
	}

}

