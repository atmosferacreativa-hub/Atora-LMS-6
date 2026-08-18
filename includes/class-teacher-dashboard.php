<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/teacher-dashboard/trait-teacher-dashboard.php';

class CLMS_Teacher_Dashboard {

	const SHORTCODE = 'clms_teacher_dashboard';
	const DASHBOARD_SCHEMA_FILE = 'assets/ui/teacher-dashboard.json';

	protected static $assets_enqueued = false;
	protected static $schema_cache = null;

	use CLMS_Teacher_Dashboard_Trait;

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}
}
