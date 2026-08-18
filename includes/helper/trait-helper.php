<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-helper-core.php';
require_once __DIR__ . '/trait-helper-academic.php';
require_once __DIR__ . '/trait-helper-commercial.php';
require_once __DIR__ . '/trait-helper-utilities.php';
require_once __DIR__ . '/trait-helper-grading.php';

trait CLMS_Helper_Trait {
	use CLMS_Helper_Core_Trait;
	use CLMS_Helper_Academic_Trait;
	use CLMS_Helper_Commercial_Trait;
	use CLMS_Helper_Utilities_Trait;
	use CLMS_Helper_Grading_Trait;
}
