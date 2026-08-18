<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-teacher-assistant-ajax.php';
require_once __DIR__ . '/trait-teacher-assistant-context.php';
require_once __DIR__ . '/trait-teacher-assistant-tools.php';
require_once __DIR__ . '/trait-teacher-assistant-artifacts-helpers.php';
require_once __DIR__ . '/trait-teacher-assistant-panel.php';

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
trait CLMS_Teacher_Assistant_Trait {
	use CLMS_Teacher_Assistant_Ajax_Trait;
	use CLMS_Teacher_Assistant_Context_Trait;
	use CLMS_Teacher_Assistant_Tools_Trait;
	use CLMS_Teacher_Assistant_Artifacts_Helpers_Trait;
	use CLMS_Teacher_Assistant_Panel_Trait;
}
