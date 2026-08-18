<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/teacher-assistant/trait-teacher-assistant.php';

/**
 * CLMS_Teacher_Assistant
 *
 * Asistente IA contextual para profesores. Sabe en qué lección/curso está el profesor,
 * tiene acceso a transcripciones, rúbricas y datos del grupo, y genera artefactos
 * guardables directamente en la plataforma.
 *
 * Herramientas disponibles:
 *   plan_course       → estructura de curso con módulos y lecciones
 *   research          → brief de investigación pedagógica sobre un tema
 *   improve_lesson    → sugerencias concretas para mejorar el contenido
 *   presentation      → diapositivas HTML listas para exportar/imprimir
 *   rubric            → rúbrica lista para guardar en el CPT clms_rubric
 *   quiz              → banco de preguntas compatible con el sistema existente
 *   analyze_group     → análisis de desempeño del grupo con alertas
 *   draft_email       → redacción de comunicación para estudiantes
 *   chat              → conversación libre con contexto del curso
 *
 * Almacenamiento:
 *   _clms_ta_history   user meta → array de hasta 20 conversaciones por usuario
 */
class CLMS_Teacher_Assistant {

	const MAX_HISTORY_TURNS = 20;
	const MAX_CONTEXT_CHARS = 8000;
	const USER_META_HISTORY = '_clms_ta_history';

	// Herramientas válidas
	const TOOLS = array(
		'plan_course',
		'research',
		'improve_lesson',
		'presentation',
		'rubric',
		'quiz',
		'analyze_group',
		'draft_email',
		'chat',
	);

	use CLMS_Teacher_Assistant_Trait;

	// ── Bootstrap ────────────────────────────────────────────────────────────────

	public function __construct() {
		add_action( 'admin_footer', array( $this, 'render_panel' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_clms_ta_run',          array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_clms_ta_save_artifact', array( $this, 'ajax_save_artifact' ) );
		add_action( 'wp_ajax_clms_ta_clear_history', array( $this, 'ajax_clear_history' ) );
	}
}
