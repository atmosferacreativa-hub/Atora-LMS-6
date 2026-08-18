<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/metabox-program/trait-metabox-program-ui.php';
require_once __DIR__ . '/metabox-program/trait-metabox-program-save-notices.php';
require_once __DIR__ . '/metabox-program/trait-metabox-program-enrollment-ajax.php';

class CLMS_Metabox_Program {

	use CLMS_Metabox_Program_UI_Trait;
	use CLMS_Metabox_Program_Save_Notices_Trait;
	use CLMS_Metabox_Program_Enrollment_Ajax_Trait;

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_lm_program', array( $this, 'save_meta_boxes' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_on_program' ) );
		add_action( 'wp_ajax_clms_enroll_user_program',   array( $this, 'ajax_enroll_user_program' ) );
		add_action( 'wp_ajax_clms_unenroll_user_program', array( $this, 'ajax_unenroll_user_program' ) );
		add_action( 'wp_ajax_clms_enroll_csv_program',  array( $this, 'ajax_enroll_csv_program' ) );
		add_action( 'wp_ajax_clms_save_program_woo',    array( $this, 'ajax_save_program_woo' ) );
	}
}
