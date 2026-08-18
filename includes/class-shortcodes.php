<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/shortcodes/trait-shortcodes-assets.php';
require_once __DIR__ . '/shortcodes/trait-shortcodes-listings.php';
require_once __DIR__ . '/shortcodes/trait-shortcodes-enrollment-catalog.php';
require_once __DIR__ . '/shortcodes/trait-shortcodes-helpers.php';

class CLMS_Shortcodes {

	/**
	 * Evita duplicar inline assets.
	 *
	 * @var bool
	 */
	protected static $assets_enqueued = false;

	use CLMS_Shortcodes_Assets_Trait;
	use CLMS_Shortcodes_Listings_Trait;
	use CLMS_Shortcodes_Enrollment_Catalog_Trait;
	use CLMS_Shortcodes_Helpers_Trait;

	public function __construct() {
		add_shortcode( 'clms_course_list', array( $this, 'render_course_list_shortcode' ) );
		add_shortcode( 'clms_courses', array( $this, 'render_course_list_shortcode' ) );

		add_shortcode( 'clms_lesson_list', array( $this, 'render_lesson_list_shortcode' ) );
		add_shortcode( 'clms_course_lessons', array( $this, 'render_lesson_list_shortcode' ) );
		add_shortcode( 'clms_lessons', array( $this, 'render_lesson_list_shortcode' ) );

		add_shortcode( 'clms_my_courses', array( $this, 'render_my_courses_shortcode' ) );
		add_shortcode( 'clms_catalog', array( $this, 'render_catalog_shortcode' ) );
		add_shortcode( 'clms_marketplace', array( $this, 'render_catalog_shortcode' ) );

		add_action( 'wp_ajax_clms_enroll_course', array( $this, 'ajax_enroll_course' ) );
		add_action( 'wp_ajax_nopriv_clms_enroll_course', array( $this, 'ajax_enroll_course' ) );

		add_action( 'init', array( $this, 'handle_enroll_post' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 20 );
	}
}
