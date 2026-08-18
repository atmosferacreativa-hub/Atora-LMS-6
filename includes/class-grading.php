<?php
/**
 * CLMS_Grading — Calificaciones, resumen académico y SpeedGrade.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/grading/renderers/class-speedgrade-renderer.php';
require_once __DIR__ . '/grading/renderers/class-rubric-panel-renderer.php';
require_once __DIR__ . '/grading/renderers/class-grading-history-renderer.php';
require_once __DIR__ . '/grading/services/class-grading-query-service.php';
require_once __DIR__ . '/grading/services/class-grading-status-service.php';
require_once __DIR__ . '/grading/services/class-grading-feedback-service.php';
require_once __DIR__ . '/grading/actions/class-speedgrade-actions.php';
require_once __DIR__ . '/grading/trait-grading.php';

class CLMS_Grading {

	const SUBMISSION_CPT    = 'clms_submission';
	const SPEEDGRADE_VAR    = 'clms_speedgrade';
	const SPEEDGRADE_ACTION = 'clms_speedgrade_save';
	const SPEEDGRADE_NONCE  = 'clms_speedgrade_nonce';
	const SPEEDGRADE_RETURN = 'clms_return';
	const CACHE_TTL         = 300;

	protected static $assets_enqueued = false;

	use CLMS_Grading_Trait;

	public function __construct() {
		add_shortcode( 'clms_speedgrade', array( $this, 'render_speedgrade_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_speedgrade_screen' ) );
		add_action( 'wp_ajax_clms_generate_ai_review', array( $this, 'ajax_generate_ai_review' ) );
		add_action( 'clms_submission_created', array( $this, 'invalidate_cache_from_submission' ), 10, 3 );
		add_action( 'clms_submission_graded', array( $this, 'invalidate_cache_from_graded_submission' ), 10, 2 );
		add_action( 'clms_lesson_completed', array( $this, 'invalidate_cache_from_lesson' ), 10, 2 );
	}
}
