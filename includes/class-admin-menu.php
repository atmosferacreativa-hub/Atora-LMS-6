<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/gradebook/class-gradebook-schema-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-calculation-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-grid-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-renderer.php';

require_once __DIR__ . '/admin-menu/trait-admin-menu-hubs.php';
require_once __DIR__ . '/admin-menu/trait-admin-menu-main-pages.php';
require_once __DIR__ . '/admin-menu/trait-admin-menu-navigation.php';
require_once __DIR__ . '/admin-menu/trait-admin-menu-academic-ops.php';
require_once __DIR__ . '/admin-menu/trait-admin-menu-widgets-and-hubs.php';

class CLMS_Admin_Menu {

	use CLMS_Admin_Menu_Hubs_Trait;
	use CLMS_Admin_Menu_Main_Pages_Trait;
	use CLMS_Admin_Menu_Navigation_Trait;
	use CLMS_Admin_Menu_Academic_Ops_Trait;
	use CLMS_Admin_Menu_Widgets_And_Hubs_Trait;

	const COURSE_FILTER_LIMIT = 200;

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_admin_pages' ) );
		add_action( 'admin_menu',            array( $this, 'cleanup_atora_submenus' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_head',            array( $this, 'render_atora_menu_styles' ) );
		add_action( 'wp_dashboard_setup',    array( $this, 'register_wp_dashboard_widgets' ) );
		add_action( 'admin_post_clms_recalculate_course_gradebook', array( $this, 'handle_recalculate_gradebook' ) );
		add_action( 'admin_post_clms_duplicate_course', array( $this, 'handle_duplicate_course' ) );
	}
}
