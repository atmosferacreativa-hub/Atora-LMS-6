<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/metabox-course/trait-metabox-course-ui.php';
require_once __DIR__ . '/metabox-course/trait-metabox-course-save-notices.php';
require_once __DIR__ . '/metabox-course/trait-metabox-course-enrollment-ajax.php';

class CLMS_Metabox_Course {

	use CLMS_Metabox_Course_UI_Trait;
	use CLMS_Metabox_Course_Save_Notices_Trait;
	use CLMS_Metabox_Course_Enrollment_Ajax_Trait;

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta_boxes' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_on_course' ) );
		// AJAX: matrícula manual desde metabox
		add_action( 'wp_ajax_clms_enroll_user',   array( $this, 'ajax_enroll_user' ) );
		add_action( 'wp_ajax_clms_unenroll_user', array( $this, 'ajax_unenroll_user' ) );
	}
}
