<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Assistant_Tools_Trait {
	// ── Dispatcher de herramientas ────────────────────────────────────────────

	protected function dispatch_tool( $tool, $message, $params, $context, $history ) {
		switch ( $tool ) {
			case 'plan_course':
				return $this->tool_plan_course( $message, $params, $context );
			case 'research':
				return $this->tool_research( $message, $params, $context );
			case 'improve_lesson':
				return $this->tool_improve_lesson( $message, $params, $context );
			case 'presentation':
				return $this->tool_presentation( $message, $params, $context );
			case 'rubric':
				return $this->tool_rubric( $message, $params, $context );
			case 'quiz':
				return $this->tool_quiz( $message, $params, $context );
			case 'analyze_group':
				return $this->tool_analyze_group( $params, $context );
			case 'draft_email':
				return $this->tool_draft_email( $message, $params, $context );
			default:
				return $this->tool_chat( $message, $context, $history );
		}
	}

	// ── Herramienta: Chat libre ───────────────────────────────────────────────

	protected function tool_chat( $message, $context, $history ) {
		$system  = $this->build_system_prompt( $context, $message );
		$messages = $this->build_messages_from_history( $history, $message );

		$raw = $this->call_ai( $system, $messages );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return array(
			'tool'    => 'chat',
			'text'    => $raw,
			'actions' => array(),
		);
	}

	// ── Herramienta: Planificador de curso ────────────────────────────────────

	protected function tool_plan_course( $message, $params, $context ) {
		$topic    = isset( $params['topic'] )    ? $params['topic']    : $message;
		$level    = isset( $params['level'] )    ? $params['level']    : 'intermedio';
		$duration = isset( $params['duration'] ) ? $params['duration'] : '8 semanas';
		$count    = isset( $params['lessons'] )  ? absint( $params['lessons'] ) : 12;

		$system = $this->build_system_prompt( $context );

		$prompt = "Crea un plan de curso completo con la siguiente estructura:\n\n"
			. "Tema: {$topic}\n"
			. "Nivel: {$level}\n"
			. "Duración total: {$duration}\n"
			. "Número de lecciones: {$count}\n\n"
			. "Genera:\n"
			. "1. DESCRIPCIÓN DEL CURSO (3-4 oraciones, incluye valor y público objetivo)\n"
			. "2. OBJETIVOS DE APRENDIZAJE (5-7 objetivos, verbos de Bloom)\n"
			. "3. ESTRUCTURA POR MÓDULOS (agrupa las lecciones en 3-4 módulos temáticos)\n"
			. "   Para cada módulo: nombre, objetivo, duración aproximada\n"
			. "   Para cada lección: título, tipo (video/lectura/taller/evaluación), duración estimada, objetivo específico\n"
			. "4. EVALUACIÓN SUGERIDA (tipos y ponderación)\n"
			. "5. RECURSOS RECOMENDADOS (herramientas, bibliografía clave)\n\n"
			. "Usa formato Markdown con headers claros. Sé específico y pedagógico.";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return array(
			'tool'    => 'plan_course',
			'text'    => $raw,
			'actions' => array(
				array( 'label' => __( 'Copiar al portapapeles', 'atora-lms' ), 'type' => 'copy' ),
			),
		);
	}

	// ── Herramienta: Investigación ────────────────────────────────────────────

	protected function tool_research( $message, $params, $context ) {
		$topic = isset( $params['topic'] ) ? $params['topic'] : $message;

		$system = $this->build_system_prompt( $context );

		$prompt = "Actúa como un investigador educativo senior. Prepara un brief de investigación profundo sobre:\n\n"
			. "TEMA: {$topic}\n\n"
			. "El brief debe incluir:\n"
			. "## 1. Resumen ejecutivo\n"
			. "(3-4 párrafos sobre el estado actual del tema)\n\n"
			. "## 2. Conceptos fundamentales\n"
			. "(Los 5-8 conceptos clave que el estudiante debe dominar, con explicación clara de cada uno)\n\n"
			. "## 3. Frameworks y modelos relevantes\n"
			. "(Teorías, metodologías o marcos de referencia establecidos)\n\n"
			. "## 4. Aplicaciones prácticas\n"
			. "(Cómo se aplica en el mundo real, ejemplos concretos y casos de uso)\n\n"
			. "## 5. Errores comunes y misconceptions\n"
			. "(Qué conceptos erróneos suelen tener los estudiantes)\n\n"
			. "## 6. Actividades de aprendizaje sugeridas\n"
			. "(3-5 ejercicios o proyectos para reforzar el aprendizaje)\n\n"
			. "## 7. Fuentes y lecturas recomendadas\n"
			. "(Libros, artículos, recursos online de alta calidad)\n\n"
			. "Sé exhaustivo, preciso y pedagógicamente sólido.";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return array(
			'tool'    => 'research',
			'text'    => $raw,
			'actions' => array(
				array( 'label' => __( 'Copiar al portapapeles', 'atora-lms' ), 'type' => 'copy' ),
				array( 'label' => __( 'Guardar como notas de la lección', 'atora-lms' ), 'type' => 'save', 'artifact' => 'lesson_notes' ),
			),
		);
	}

	// ── Herramienta: Mejorar lección ──────────────────────────────────────────

	protected function tool_improve_lesson( $message, $params, $context ) {
		$focus  = isset( $params['focus'] ) ? $params['focus'] : $message;
		$system = $this->build_system_prompt( $context, $focus );

		$has_content      = '' !== $context['lesson_content'];
		$has_transcription = '' !== $context['transcription'];

		if ( ! $has_content && ! $has_transcription && ! $focus ) {
			return new WP_Error( 'no_content', __( 'No hay contenido de lección disponible. Abre una lección antes de usar esta herramienta.', 'atora-lms' ) );
		}

		$prompt = "Analiza el contenido de esta lección y genera mejoras concretas.\n\n";

		if ( $focus ) {
			$prompt .= "El profesor quiere enfocarse en: {$focus}\n\n";
		}

		$prompt .= "Genera un análisis en las siguientes secciones:\n\n"
			. "## 🔍 Diagnóstico\n"
			. "¿Qué está bien en esta lección? ¿Qué falta o podría mejorar?\n\n"
			. "## ✏️ Mejoras específicas al contenido\n"
			. "Lista de cambios concretos con el texto mejorado (no solo sugerencias genéricas).\n\n"
			. "## 🎯 Objetivos de aprendizaje sugeridos\n"
			. "3-5 objetivos claros y medibles basados en el contenido.\n\n"
			. "## 💡 Actividades complementarias\n"
			. "2-3 actividades prácticas que refuercen lo aprendido.\n\n"
			. "## ❓ Preguntas de comprensión\n"
			. "5 preguntas para verificar el entendimiento del estudiante.\n\n"
			. "## 📚 Recursos adicionales\n"
			. "3-4 recursos externos para profundizar.";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return array(
			'tool'    => 'improve_lesson',
			'text'    => $raw,
			'actions' => array(
				array( 'label' => __( 'Copiar al portapapeles', 'atora-lms' ), 'type' => 'copy' ),
				array( 'label' => __( 'Guardar como notas', 'atora-lms' ), 'type' => 'save', 'artifact' => 'lesson_notes' ),
			),
		);
	}

	// ── Herramienta: Presentación ─────────────────────────────────────────────

	protected function tool_presentation( $message, $params, $context ) {
		$system    = $this->build_system_prompt( $context );
		$topic     = isset( $params['topic'] )  ? $params['topic']    : $message;
		$slides    = isset( $params['slides'] ) ? absint( $params['slides'] ) : 8;
		$slides    = max( 4, min( 20, $slides ) );

		$prompt = "Crea una presentación profesional de {$slides} diapositivas sobre:\n"
			. "TEMA: {$topic}\n\n"
			. ( $context['lesson_title'] ? "Contexto: lección '{$context['lesson_title']}' del curso '{$context['course_title']}'\n\n" : '' )
			. "Para cada diapositiva usa este formato EXACTO:\n\n"
			. "---SLIDE---\n"
			. "TÍTULO: [título de la diapositiva]\n"
			. "TIPO: [title|content|bullets|two-col|quote|image-text|summary]\n"
			. "CONTENIDO:\n"
			. "[contenido principal, máximo 5 puntos clave o 120 palabras]\n"
			. "NOTA_PRESENTER:\n"
			. "[notas para el presentador, 2-3 oraciones con contexto extra]\n"
			. "---END_SLIDE---\n\n"
			. "Estructura sugerida:\n"
			. "- Diap. 1: Portada (título, subtítulo)\n"
			. "- Diap. 2: Agenda o introducción\n"
			. "- Diap. 3 a N-2: Contenido principal\n"
			. "- Diap. N-1: Resumen de puntos clave\n"
			. "- Diap. N: Cierre, próximos pasos o preguntas\n\n"
			. "Sé concreto, visual en la descripción y pedagógicamente sólido.";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		// Parsear el formato de diapositivas
		$slides_data = $this->parse_presentation( $raw );
		$html        = $this->render_presentation_html( $slides_data, $topic );

		return array(
			'tool'           => 'presentation',
			'text'           => $raw,
			'slides'         => $slides_data,
			'presentation_html' => $html,
			'slides_count'   => count( $slides_data ),
			'actions'        => array(
				array( 'label' => __( 'Ver presentación', 'atora-lms' ), 'type' => 'preview_presentation' ),
				array( 'label' => __( 'Guardar en lección', 'atora-lms' ), 'type' => 'save', 'artifact' => 'presentation' ),
				array( 'label' => __( 'Imprimir / PDF', 'atora-lms' ), 'type' => 'print_presentation' ),
			),
		);
	}

	/**
	 * Parsea el formato ---SLIDE--- al array de diapositivas.
	 */
	protected function parse_presentation( $raw ) {
		$slides = array();
		$blocks = preg_split( '/---SLIDE---/', $raw );

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}

			// Quitar el cierre
			$block = preg_replace( '/---END_SLIDE---.*$/s', '', $block );

			$slide = array(
				'title'   => '',
				'type'    => 'content',
				'content' => '',
				'note'    => '',
			);

			if ( preg_match( '/TÍTULO:\s*(.+)/i', $block, $m ) ) {
				$slide['title'] = trim( $m[1] );
			}
			if ( preg_match( '/TIPO:\s*(.+)/i', $block, $m ) ) {
				$slide['type'] = sanitize_key( trim( $m[1] ) );
			}
			if ( preg_match( '/CONTENIDO:\s*(.+?)(?=NOTA_PRESENTER:|$)/is', $block, $m ) ) {
				$slide['content'] = trim( $m[1] );
			}
			if ( preg_match( '/NOTA_PRESENTER:\s*(.+)/is', $block, $m ) ) {
				$slide['note'] = trim( $m[1] );
			}

			if ( $slide['title'] || $slide['content'] ) {
				$slides[] = $slide;
			}
		}

		return $slides;
	}

	/**
	 * Genera HTML de presentación tipo diapositivas para visualización y print.
	 */
	protected function render_presentation_html( $slides, $title ) {
		ob_start();
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#1a1a2e;color:#fff}
.deck{display:flex;flex-direction:column;gap:0}
.slide{min-height:100vh;padding:60px;display:flex;flex-direction:column;justify-content:center;page-break-after:always;position:relative}
.slide:nth-child(odd){background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%)}
.slide:nth-child(even){background:linear-gradient(135deg,#0f3460 0%,#1a1a2e 100%)}
.slide-num{position:absolute;top:24px;right:32px;font-size:12px;opacity:.4;font-weight:600}
.slide-title{font-size:clamp(28px,4vw,52px);font-weight:800;line-height:1.2;margin-bottom:28px;background:linear-gradient(90deg,#e94560,#f5a623);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.slide-content{font-size:clamp(15px,2vw,22px);line-height:1.7;opacity:.9;max-width:900px}
.slide-content ul{padding-left:24px;margin-top:8px}
.slide-content li{margin-bottom:12px}
.slide-note{position:absolute;bottom:20px;left:60px;right:60px;font-size:12px;opacity:.4;font-style:italic;border-top:1px solid rgba(255,255,255,.15);padding-top:10px}
.slide-type-title .slide-title{font-size:clamp(36px,5vw,72px);text-align:center;margin:0 auto 16px}
.slide-type-title .slide-content{text-align:center;font-size:clamp(16px,2vw,24px);opacity:.7;margin:0 auto}
.slide-type-quote{justify-content:center;text-align:center}
.slide-type-quote .slide-content{font-size:clamp(20px,3vw,36px);font-style:italic;opacity:.9;max-width:700px;margin:0 auto;position:relative;padding:0 40px}
.slide-type-quote .slide-content::before,.slide-type-quote .slide-content::after{content:'"';font-size:80px;opacity:.3;position:absolute;top:-20px;left:0;font-family:Georgia,serif}
.slide-type-quote .slide-content::after{content:'"';top:auto;bottom:-40px;left:auto;right:0}
.slide-type-summary .slide-content{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:16px}
@media print{
  body{background:#fff;color:#1a1a2e}
  .slide{min-height:100vh;background:#fff!important;border:2px solid #e0e0e0;margin-bottom:0}
  .slide-title{-webkit-text-fill-color:#1a1a2e;background:none;color:#e94560}
}
</style>
</head>
<body>
<div class="deck">
<?php foreach ( $slides as $i => $slide ) :
	$type_class = 'slide-type-' . ( $slide['type'] ?: 'content' );
	$content_html = $this->markdown_to_html( $slide['content'] );
?>
<div class="slide <?php echo esc_attr( $type_class ); ?>">
	<span class="slide-num"><?php echo esc_html( $i + 1 ); ?>/<?php echo esc_html( count( $slides ) ); ?></span>
	<?php if ( $slide['title'] ) : ?>
		<div class="slide-title"><?php echo esc_html( $slide['title'] ); ?></div>
	<?php endif; ?>
	<div class="slide-content"><?php echo wp_kses_post( $content_html ); ?></div>
	<?php if ( $slide['note'] ) : ?>
		<div class="slide-note">📝 <?php echo esc_html( $slide['note'] ); ?></div>
	<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	// ── Herramienta: Rúbrica ──────────────────────────────────────────────────

	protected function tool_rubric( $message, $params, $context ) {
		$system    = $this->build_system_prompt( $context );
		$objective = isset( $params['objective'] ) ? $params['objective'] : $message;
		$criteria  = isset( $params['criteria'] )  ? absint( $params['criteria'] ) : 5;
		$criteria  = max( 3, min( 10, $criteria ) );

		$prompt = "Crea una rúbrica de evaluación profesional para:\n\n"
			. "OBJETIVO DE APRENDIZAJE: {$objective}\n"
			. ( $context['lesson_title'] ? "LECCIÓN: {$context['lesson_title']}\n" : '' )
			. "NÚMERO DE CRITERIOS: {$criteria}\n\n"
			. "Para cada criterio usa este formato EXACTO:\n\n"
			. "CRITERIO: [nombre corto del criterio]\n"
			. "DESCRIPCION: [descripción detallada de qué se evalúa y cómo se diferencia cada nivel]\n"
			. "PUNTOS: [puntos máximos, número entre 10 y 30]\n"
			. "---\n\n"
			. "Después de todos los criterios, agrega:\n\n"
			. "TOTAL_PUNTOS: [suma total]\n"
			. "NOTAS_USO: [instrucciones breves para el evaluador]\n\n"
			. "Los criterios deben cubrir: contenido/conocimiento, estructura/organización, "
			. "argumentación/evidencia, claridad/comunicación, y aspecto diferenciador propio de la materia.\n"
			. "Sé específico y evita criterios genéricos que no aporten información útil.";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$parsed = $this->parse_rubric( $raw );

		return array(
			'tool'           => 'rubric',
			'text'           => $raw,
			'rubric_parsed'  => $parsed,
			'actions'        => array(
				array( 'label' => __( 'Guardar como rúbrica oficial', 'atora-lms' ), 'type' => 'save', 'artifact' => 'rubric' ),
				array( 'label' => __( 'Copiar al portapapeles', 'atora-lms' ), 'type' => 'copy' ),
			),
		);
	}

	/**
	 * Parsea el formato de rúbrica al array de criterios.
	 */
	protected function parse_rubric( $raw ) {
		$criteria = array();
		$blocks   = preg_split( '/---+/', $raw );

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}

			$c = array( 'name' => '', 'description' => '', 'max_points' => 10 );

			if ( preg_match( '/CRITERIO:\s*(.+)/i', $block, $m ) ) {
				$c['name'] = sanitize_text_field( trim( $m[1] ) );
			}
			if ( preg_match( '/DESCRIPCION:\s*(.+?)(?=PUNTOS:|$)/is', $block, $m ) ) {
				$c['description'] = sanitize_textarea_field( trim( $m[1] ) );
			}
			if ( preg_match( '/PUNTOS:\s*(\d+)/i', $block, $m ) ) {
				$c['max_points'] = max( 1, min( 100, absint( $m[1] ) ) );
			}

			if ( $c['name'] ) {
				$criteria[] = $c;
			}
		}

		return $criteria;
	}

	// ── Herramienta: Quiz ─────────────────────────────────────────────────────

	protected function tool_quiz( $message, $params, $context ) {
		$system = $this->build_system_prompt( $context );
		$topic  = isset( $params['topic'] )  ? $params['topic']    : $message;
		$count  = isset( $params['count'] )  ? absint( $params['count'] )  : 10;
		$diff   = isset( $params['difficulty'] ) ? $params['difficulty'] : 'mixta';
		$count  = max( 3, min( 20, $count ) );

		$has_source = $context['lesson_content'] || $context['transcription'];
		$source_hint = $has_source
			? 'Basa las preguntas en el contenido de la lección y la transcripción del video que tienes en contexto.'
			: "Basa las preguntas en el tema: {$topic}";

		$prompt = "Genera exactamente {$count} preguntas de opción múltiple.\n"
			. $source_hint . "\n"
			. "Dificultad: {$diff} (baja=recordar, media=comprender/aplicar, alta=analizar/crear)\n\n"
			. "Para cada pregunta usa este formato EXACTO:\n\n"
			. "PREGUNTA: [texto de la pregunta]\n"
			. "A: [opción A]\n"
			. "B: [opción B]\n"
			. "C: [opción C]\n"
			. "D: [opción D]\n"
			. "CORRECTA: [A|B|C|D]\n"
			. "EXPLICACION: [por qué es correcta esa respuesta]\n"
			. "DIFICULTAD: [baja|media|alta]\n"
			. "---\n\n"
			. "Requisitos:\n"
			. "- Preguntas claras, sin ambigüedad\n"
			. "- Distractores plausibles pero claramente incorrectos\n"
			. "- Distribuye correctas entre A, B, C y D\n"
			. "- Todas las preguntas en español\n"
			. "- No uses 'Todas las anteriores' ni 'Ninguna de las anteriores'";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$questions = $this->parse_quiz( $raw );

		return array(
			'tool'       => 'quiz',
			'text'       => $raw,
			'questions'  => $questions,
			'count'      => count( $questions ),
			'actions'    => array(
				array( 'label' => __( 'Guardar en banco de preguntas', 'atora-lms' ), 'type' => 'save', 'artifact' => 'quiz' ),
				array( 'label' => __( 'Copiar al portapapeles', 'atora-lms' ), 'type' => 'copy' ),
			),
		);
	}

	/**
	 * Parsea el formato de preguntas.
	 */
	protected function parse_quiz( $raw ) {
		$questions = array();
		$blocks    = preg_split( '/---+/', $raw );

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}

			$q = array(
				'question'    => '',
				'options'     => array( 'A' => '', 'B' => '', 'C' => '', 'D' => '' ),
				'correct'     => 'A',
				'explanation' => '',
				'difficulty'  => 'media',
			);

			if ( preg_match( '/PREGUNTA:\s*(.+?)(?=^[A-D]:|$)/ims', $block, $m ) ) {
				$q['question'] = sanitize_text_field( trim( $m[1] ) );
			}
			foreach ( array( 'A', 'B', 'C', 'D' ) as $letter ) {
				if ( preg_match( '/' . $letter . ':\s*(.+)/i', $block, $m ) ) {
					$q['options'][ $letter ] = sanitize_text_field( trim( $m[1] ) );
				}
			}
			if ( preg_match( '/CORRECTA:\s*([ABCD])/i', $block, $m ) ) {
				$q['correct'] = strtoupper( trim( $m[1] ) );
			}
			if ( preg_match( '/EXPLICACION:\s*(.+?)(?=DIFICULTAD:|$)/is', $block, $m ) ) {
				$q['explanation'] = sanitize_textarea_field( trim( $m[1] ) );
			}
			if ( preg_match( '/DIFICULTAD:\s*(.+)/i', $block, $m ) ) {
				$q['difficulty'] = sanitize_key( trim( $m[1] ) );
			}

			if ( $q['question'] && $q['options']['A'] ) {
				$questions[] = $q;
			}
		}

		return $questions;
	}

	// ── Herramienta: Analizar grupo ───────────────────────────────────────────

	protected function tool_analyze_group( $params, $context ) {
		$course_id = $context['course_id'];

		if ( ! $course_id ) {
			return new WP_Error( 'no_course', __( 'No hay un curso activo para analizar. Abre un curso antes de usar esta herramienta.', 'atora-lms' ) );
		}

		// Recopilar datos reales del grupo
		$analytics = $this->collect_group_analytics( $course_id );

		$system = $this->build_system_prompt( $context );

		$prompt = "Analiza los siguientes datos del grupo del curso \"{$context['course_title']}\" "
			. "y genera un informe pedagógico completo:\n\n"
			. "DATOS DEL GRUPO:\n"
			. "- Estudiantes matriculados: {$analytics['enrolled']}\n"
			. "- Estudiantes con al menos 1 entrega: {$analytics['active']}\n"
			. "- Entregas pendientes de revisión: {$analytics['pending']}\n"
			. "- Entregas calificadas: {$analytics['graded']}\n"
			. "- Nota promedio del grupo: {$analytics['avg_grade']}\n"
			. "- Nota más alta: {$analytics['max_grade']}\n"
			. "- Nota más baja: {$analytics['min_grade']}\n"
			. "- Tasa de completitud del curso: {$analytics['completion_rate']}%\n"
			. "- Estudiantes con riesgo de abandono (sin actividad reciente): {$analytics['at_risk']}\n\n"
			. "Genera:\n"
			. "## 📊 Resumen del grupo\n"
			. "## ✅ Fortalezas del grupo\n"
			. "## ⚠️ Áreas de preocupación\n"
			. "## 🚨 Estudiantes en riesgo\n"
			. "(basado en baja actividad o notas muy bajas)\n"
			. "## 💡 Recomendaciones pedagógicas\n"
			. "(acciones concretas que el profesor puede tomar esta semana)\n"
			. "## 📝 Mensaje sugerido para el grupo\n"
			. "(borrador de mensaje motivacional para enviar a los estudiantes)";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return array(
			'tool'      => 'analyze_group',
			'text'      => $raw,
			'analytics' => $analytics,
			'actions'   => array(
				array( 'label' => __( 'Copiar informe', 'atora-lms' ), 'type' => 'copy' ),
			),
		);
	}

	/**
	 * Recopila métricas reales del grupo desde la base de datos.
	 */
	protected function collect_group_analytics( $course_id ) {
		$course_id = absint( $course_id );

		$enrolled_users = get_post_meta( $course_id, '_clms_enrolled_users', true );
		$enrolled       = is_array( $enrolled_users ) ? count( $enrolled_users ) : 0;

		$lessons = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lessons = is_array( $lessons ) ? array_map( 'absint', $lessons ) : array();

		$all_grades  = array();
		$graded      = 0;
		$pending     = 0;
		$active_ids  = array();
		$at_risk     = 0;

		if ( ! empty( $lessons ) ) {
			$submissions = get_posts( array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lessons,
						'compare' => 'IN',
					),
				),
			) );

			foreach ( $submissions as $sub_id ) {
				$status   = get_post_meta( $sub_id, '_clms_submission_status', true );
				$grade    = get_post_meta( $sub_id, '_clms_submission_grade', true );
				$student  = absint( get_post_meta( $sub_id, '_clms_submission_user_id', true ) );

				if ( $student ) {
					$active_ids[ $student ] = true;
				}

				if ( in_array( $status, array( 'submitted', 'in_review' ), true ) ) {
					$pending++;
				}

				if ( 'graded' === $status && '' !== (string) $grade ) {
					$graded++;
					$all_grades[] = absint( $grade );
				}
			}
		}

		// Estimar en riesgo: matriculados sin entregas
		if ( is_array( $enrolled_users ) ) {
			foreach ( $enrolled_users as $uid ) {
				if ( ! isset( $active_ids[ absint( $uid ) ] ) ) {
					$at_risk++;
				}
			}
		}

		$avg_grade = ! empty( $all_grades ) ? (int) round( array_sum( $all_grades ) / count( $all_grades ) ) : 0;
		$max_grade = ! empty( $all_grades ) ? max( $all_grades ) : 0;
		$min_grade = ! empty( $all_grades ) ? min( $all_grades ) : 0;

		// Tasa de completitud (promedio de progreso)
		$completion_total = 0;
		$completion_count = 0;
		if ( is_array( $enrolled_users ) && ! empty( $lessons ) ) {
			foreach ( $enrolled_users as $uid ) {
				$uid      = absint( $uid );
				$completed = get_user_meta( $uid, '_clms_completed_lessons', true );
				$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();
				$done      = count( array_intersect( $lessons, $completed ) );
				$completion_total += $done;
				$completion_count++;
			}
		}
		$completion_rate = ( $completion_count > 0 && count( $lessons ) > 0 )
			? (int) round( ( $completion_total / ( $completion_count * count( $lessons ) ) ) * 100 )
			: 0;

		return array(
			'enrolled'         => $enrolled,
			'active'           => count( $active_ids ),
			'pending'          => $pending,
			'graded'           => $graded,
			'avg_grade'        => $avg_grade > 0 ? $avg_grade : 'sin datos',
			'max_grade'        => $max_grade > 0 ? $max_grade : 'sin datos',
			'min_grade'        => $min_grade > 0 ? $min_grade : 'sin datos',
			'completion_rate'  => $completion_rate,
			'at_risk'          => $at_risk,
		);
	}

}
