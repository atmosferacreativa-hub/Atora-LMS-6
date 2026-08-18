<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/frontend/trait-frontend-theme-auth.php';
require_once __DIR__ . '/frontend/trait-frontend-access-profile.php';
require_once __DIR__ . '/frontend/trait-frontend-navigation.php';

class CLMS_Frontend {

	use CLMS_Frontend_Theme_Auth_Trait;
	use CLMS_Frontend_Access_Profile_Trait;
	use CLMS_Frontend_Navigation_Trait;

	public function __construct() {
		add_shortcode( 'clms_logout',      array( $this, 'shortcode_logout' ) );
		add_shortcode( 'clms_user_nav',    array( $this, 'shortcode_user_nav' ) );
		add_shortcode( 'clms_if',          array( $this, 'shortcode_clms_if' ) );
		add_shortcode( 'clms_login_form',  array( $this, 'shortcode_login_form' ) );
		add_shortcode( 'clms_auth_button', array( $this, 'shortcode_auth_button' ) );
		add_shortcode( 'clms_my_profile',  array( $this, 'shortcode_my_profile' ) );
		// Alias usado en el sitio: [ac_lms_auth]
		add_shortcode( 'ac_lms_auth',      array( $this, 'shortcode_login_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
		add_filter( 'body_class', array( $this, 'add_theme_body_class' ) );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_nav_menu_by_role' ), 10, 2 );
		add_filter( 'wp_nav_menu_args', array( $this, 'filter_nav_menu_args_by_context' ), 20 );
		add_filter( 'theme_mod_nav_menu_locations', array( $this, 'filter_nav_menu_locations_by_role' ), 20 );
		add_filter( 'login_redirect', array( $this, 'redirect_after_login_by_role' ), 20, 3 );
		// Bloquear wp-admin para estudiantes y visitantes
		add_action( 'admin_init', array( $this, 'redirect_students_from_admin' ) );
		// Requiere completar perfil antes de iniciar curso/lección.
		add_action( 'template_redirect', array( $this, 'enforce_profile_completion_before_learning' ), 9 );
		// Ocultar barra de administración
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_students' ) );
	}
}
