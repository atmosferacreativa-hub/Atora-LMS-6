<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/metabox-lesson/trait-metabox-lesson.php';

class CLMS_Metabox_Lesson {

	const NONCE_ACTION = 'clms_save_lesson_metabox';
	const NONCE_NAME   = 'clms_lesson_metabox_nonce';

	const COURSE_META_FALLBACK_1 = '_clms_lesson_course_id';
	const COURSE_META_FALLBACK_2 = 'course_id';

	const AI_SOURCE_MODE_META   = '_clms_ai_source_mode';
	const AI_GUIDE_ATTACHMENT   = '_clms_ai_guide_attachment_id';
	const AI_GUIDE_SOURCE_LABEL = '_clms_ai_guide_source_label';
	const AI_BANK_SIZE_META     = '_clms_ai_question_bank_size';
	const AI_GENERATED_AT_META  = '_clms_quiz_generated_at';

	const QUIZ_QUESTIONS_META_KEY = '_clms_quiz_questions';
	const QUIZ_ENABLED_META_KEY   = '_clms_quiz_enabled';

	const LESSON_SUBTITLE_META      = '_clms_lesson_subtitle';
	const LESSON_PUBLIC_SNIPPET     = '_clms_lesson_public_snippet';
	const LESSON_MODULE_META        = '_clms_lesson_module';
	const LESSON_COVER_IMAGE_ID     = '_clms_lesson_cover_image_id';
	const LESSON_SUPPORT_RESOURCES  = '_clms_lesson_resources';
	const LESSON_TIP_1              = '_clms_tip_1';
	const LESSON_TIP_2              = '_clms_tip_2';
	const LESSON_TIP_3              = '_clms_tip_3';
	const LESSON_VIDEO_URL          = '_clms_lesson_video_url';
	const LESSON_VIDEO_SOURCE       = '_clms_lesson_video_source';
	const LESSON_VIDEO_COUNT        = '_clms_lesson_video_count';
	const LESSON_EXTRA_VIDEOS       = '_clms_lesson_extra_videos';
	const LESSON_UI_LIMIT_VIDEOS    = '_clms_lesson_ui_limit_videos';
	const LESSON_UI_LIMIT_RESOURCES = '_clms_lesson_ui_limit_resources';
	const LESSON_UI_LIMIT_TIPS      = '_clms_lesson_ui_limit_tips';
	const LIVE_CLASS_PROVIDER       = '_clms_live_class_provider';
	const LIVE_CLASS_URL            = '_clms_live_class_url';
	const LIVE_CLASS_STARTS_AT      = '_clms_live_class_starts_at';
	const LIVE_CLASS_ENDS_AT        = '_clms_live_class_ends_at';
	const LIVE_CLASS_TIMEZONE       = '_clms_live_class_timezone';
	const LIVE_CLASS_NOTES          = '_clms_live_class_notes';

	/**
	 * Flag para evitar duplicar assets (CSS/JS/nonce) entre los 4 metaboxes.
	 */
	private static $assets_done = false;

	use CLMS_Metabox_Lesson_Trait;

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );
		add_action( 'save_post_lm_lesson', array( $this, 'save_metabox' ), 20, 2 );
		add_action( 'admin_post_clms_clear_ai_quiz_bank', array( $this, 'handle_clear_ai_quiz_bank' ) );
	}
}
