<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-teacher-dashboard-render.php';
require_once __DIR__ . '/trait-teacher-dashboard-data.php';
require_once __DIR__ . '/trait-teacher-dashboard-inbox.php';
require_once __DIR__ . '/trait-teacher-dashboard-assets.php';

trait CLMS_Teacher_Dashboard_Trait {
	use CLMS_Teacher_Dashboard_Render_Trait;
	use CLMS_Teacher_Dashboard_Data_Trait;
	use CLMS_Teacher_Dashboard_Inbox_Trait;
	use CLMS_Teacher_Dashboard_Assets_Trait;
}
