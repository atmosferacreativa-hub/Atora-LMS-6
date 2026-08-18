<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/loader/trait-loader-bootstrap.php';
require_once __DIR__ . '/loader/trait-loader-module-groups.php';
require_once __DIR__ . '/loader/trait-loader-modules.php';
require_once __DIR__ . '/loader/trait-loader-templates.php';

class CLMS_Loader {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Instancias activas por clase.
	 *
	 * @var array<string,object>
	 */
	protected $instances = array();

	/**
	 * Ya fue inicializado.
	 *
	 * @var bool
	 */
	protected $initialized = false;

	/**
	 * Archivos base obligatorios.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected $base_modules = array(
		array(
			'file'  => 'includes/class-helper.php',
			'class' => 'CLMS_Helper',
		),
		array(
			'file'  => 'includes/class-ai-settings-service.php',
			'class' => 'CLMS_AI_Settings_Service',
		),
		array(
			'file'  => 'includes/class-email.php',
			'class' => 'CLMS_Email',
		),
		array(
			'file'  => 'includes/class-cache.php',
			'class' => 'CLMS_Cache',
		),
		array(
			'file'  => 'includes/class-cpt.php',
			'class' => 'CLMS_CPT',
		),
		array(
			'file'  => 'includes/class-lesson.php',
			'class' => 'CLMS_Lesson',
		),
		array(
			'file'  => 'includes/class-rubric.php',
			'class' => 'CLMS_Rubric',
		),
		array(
			'file'  => 'includes/class-transcription.php',
			'class' => 'CLMS_Transcription',
		),
		array(
			'file'  => 'includes/class-teacher-assistant.php',
			'class' => 'CLMS_Teacher_Assistant',
		),
		array(
			'file'  => 'includes/class-metabox-course.php',
			'class' => 'CLMS_Metabox_Course',
		),
		array(
			'file'  => 'includes/class-metabox-program.php',
			'class' => 'CLMS_Metabox_Program',
		),
		array(
			'file'  => 'includes/class-metabox-lesson.php',
			'class' => 'CLMS_Metabox_Lesson',
		),
		array(
			'file'  => 'includes/class-metabox-cohort.php',
			'class' => 'CLMS_Metabox_Cohort',
		),
	);

	/**
	 * Grupos de módulos.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	protected $module_groups = array();

	use CLMS_Loader_Bootstrap_Trait;
	use CLMS_Loader_Module_Groups_Trait;
	use CLMS_Loader_Modules_Trait;
	use CLMS_Loader_Templates_Trait;

	public function __construct() {
		$this->module_groups = $this->get_module_groups();
	}

	/**
	 * Static factory method for easy initialization
	 * Allows calling: CLMS_Loader::boot();
	 */
	public static function boot() {
		if ( self::$instance instanceof self ) {
			self::$instance->init();
			return self::$instance;
		}

		self::$instance = new self();
		self::$instance->init();

		return self::$instance;
	}

	/**
	 * Returns the shared loader instance if it has been booted.
	 *
	 * @return self|null
	 */
	public static function instance() {
		return self::$instance;
	}
}
