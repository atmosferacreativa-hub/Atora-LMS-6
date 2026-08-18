<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-admin-menu-main-pages-academic.php';
require_once __DIR__ . '/trait-admin-menu-main-pages-commercial.php';

trait CLMS_Admin_Menu_Main_Pages_Trait {
	use CLMS_Admin_Menu_Main_Pages_Academic_Trait;
	use CLMS_Admin_Menu_Main_Pages_Commercial_Trait;
}
