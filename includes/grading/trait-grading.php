<?php
/**
 * CLMS_Grading — Calificaciones, resumen académico y SpeedGrade.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-grading-summary-cache.php';
require_once __DIR__ . '/trait-grading-speedgrade.php';
require_once __DIR__ . '/trait-grading-ai-review.php';
require_once __DIR__ . '/trait-grading-helpers.php';

trait CLMS_Grading_Trait {
	use CLMS_Grading_Summary_Cache_Trait;
	use CLMS_Grading_SpeedGrade_Trait;
	use CLMS_Grading_AI_Review_Trait;
	use CLMS_Grading_Helpers_Trait;
}
