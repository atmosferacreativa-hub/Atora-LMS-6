<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-metabox-lesson-registration.php';
require_once __DIR__ . '/trait-metabox-lesson-render.php';
require_once __DIR__ . '/trait-metabox-lesson-save.php';

trait CLMS_Metabox_Lesson_Trait {
	use CLMS_Metabox_Lesson_Registration_Trait;
	use CLMS_Metabox_Lesson_Render_Trait;
	use CLMS_Metabox_Lesson_Save_Trait;
}
