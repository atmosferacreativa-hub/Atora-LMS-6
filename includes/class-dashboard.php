<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/dashboard/trait-dashboard-render.php';
require_once __DIR__ . '/dashboard/trait-dashboard-schema-cache.php';
require_once __DIR__ . '/dashboard/trait-dashboard-learning-path.php';
require_once __DIR__ . '/dashboard/trait-dashboard-data.php';
require_once __DIR__ . '/dashboard/trait-dashboard-assets.php';

class CLMS_Dashboard {

	const SHORTCODE = 'clms_dashboard';
	const CACHE_TTL = 300;
	const DASHBOARD_SCHEMA_FILE = 'assets/ui/student-dashboard.json';

	protected static $assets_enqueued = false;
	protected static $schema_cache = null;

	use CLMS_Dashboard_Render_Trait;
	use CLMS_Dashboard_Schema_Cache_Trait;
	use CLMS_Dashboard_Learning_Path_Trait;
	use CLMS_Dashboard_Data_Trait;
	use CLMS_Dashboard_Assets_Trait;

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_dashboard_shortcode' ) );
		add_action( 'clms_lesson_completed', array( $this, 'invalidate_cache_from_lesson' ), 10, 2 );
		add_action( 'clms_submission_created', array( $this, 'invalidate_cache_from_submission' ), 10, 3 );
		add_action( 'clms_submission_graded', array( $this, 'invalidate_cache_from_submission' ), 10, 5 );
		add_action( 'clms_commerce_order_after_processed', array( $this, 'invalidate_cache_for_user' ), 10, 4 );
		add_action( 'clms_commerce_course_access_notified', array( $this, 'invalidate_cache_for_user' ), 10, 4 );
		add_action( 'clms_user_enrolled', array( $this, 'invalidate_cache_for_user' ), 10, 2 );
		add_action( 'clms_user_enrolled_in_program', array( $this, 'invalidate_cache_for_user' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'invalidate_cache_from_profile_update' ), 10, 2 );
		add_action( 'updated_user_meta', array( $this, 'invalidate_cache_from_profile_meta' ), 10, 4 );
		add_action( 'added_user_meta', array( $this, 'invalidate_cache_from_profile_meta' ), 10, 4 );
		add_action( 'deleted_user_meta', array( $this, 'invalidate_cache_from_profile_meta' ), 10, 4 );
	}
}
