<?php
/**
 * CLMS_UI_Section_Registry
 *
 * Registro centralizado de secciones disponibles por contexto.
 *
 * Cada sección se registra con:
 *   id               Identificador único (ej: "hero")
 *   label            Etiqueta legible para el admin (ej: "Cabecera")
 *   contexts         Contextos en que aparece: [] = todos
 *   render_callback  callable|null — usado por CLMS_UI_Template_Engine::render()
 *   allowed_variants Variantes visuales disponibles (para futuro builder)
 *   default_props    Props por defecto de la sección
 *
 * En esta iteración los templates actuales siguen renderizando sus propias
 * secciones. Esta clase sienta la base para que el builder admin liste y
 * configure secciones sin tocar código PHP.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Section_Registry {

	private static ?self $instance = null;

	/** @var array<string,array> */
	private array $sections = array();

	public function __construct() {
		if ( null === self::$instance ) {
			self::$instance = $this;
		}
		$this->register_defaults();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			new self();
		}
		return self::$instance;
	}

	// ── API pública ────────────────────────────────────────────────────────────

	/**
	 * Registra una sección.
	 *
	 * @param string $id   Identificador único (sanitize_key).
	 * @param array  $args Metadatos de la sección.
	 */
	public function register( string $id, array $args = array() ): void {
		$id = sanitize_key( $id );
		if ( ! $id ) {
			return;
		}

		$this->sections[ $id ] = wp_parse_args(
			$args,
			array(
				'id'               => $id,
				'label'            => $id,
				'contexts'         => array(),
				'render_callback'  => null,
				'allowed_variants' => array( 'default' ),
				'default_props'    => array(),
			)
		);
		$this->sections[ $id ]['id'] = $id;
	}

	/**
	 * Devuelve la definición de una sección o null si no está registrada.
	 */
	public function get( string $id ): ?array {
		return $this->sections[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Secciones disponibles para un contexto determinado.
	 * Si una sección tiene contexts vacío, aplica a todos.
	 *
	 * @return array<string,array>
	 */
	public function get_for_context( string $context ): array {
		$result = array();
		foreach ( $this->sections as $id => $section ) {
			$contexts = $section['contexts'] ?? array();
			if ( empty( $contexts ) || in_array( $context, $contexts, true ) ) {
				$result[ $id ] = $section;
			}
		}
		return $result;
	}

	/**
	 * Todas las secciones registradas.
	 *
	 * @return array<string,array>
	 */
	public function all(): array {
		return $this->sections;
	}

	// ── Secciones por defecto ──────────────────────────────────────────────────

	private function register_defaults(): void {
		$course_sections = array(
			'hero'         => __( 'Cabecera / Hero', 'atora-lms' ),
			'video'        => __( 'Video del curso', 'atora-lms' ),
			'about'        => __( 'Acerca del curso', 'atora-lms' ),
			'academic'     => __( 'Ficha académica', 'atora-lms' ),
			'instructor'   => __( 'Docente(s)', 'atora-lms' ),
			'benefits'     => __( '¿Qué aprenderás?', 'atora-lms' ),
			'summary'      => __( 'Resumen del curso', 'atora-lms' ),
			'profiles'     => __( 'Perfiles de ingreso y egreso', 'atora-lms' ),
			'curriculum'   => __( 'Contenido del curso', 'atora-lms' ),
			'requirements' => __( 'Requisitos previos', 'atora-lms' ),
			'gallery'      => __( 'Galería', 'atora-lms' ),
			'testimonials' => __( 'Testimonios', 'atora-lms' ),
			'faq'          => __( 'Preguntas frecuentes', 'atora-lms' ),
			'cta'          => __( 'Llamada a la acción', 'atora-lms' ),
			'crm_lead'     => __( 'CRM — Captura de lead', 'atora-lms' ),
			'related'      => __( 'Complementos recomendados', 'atora-lms' ),
		);

		foreach ( $course_sections as $id => $label ) {
			$contexts = array( 'course_overview', 'course_commercial' );
			if ( 'academic' === $id ) {
				$contexts = array( 'course_overview' );
			}
			if ( 'crm_lead' === $id ) {
				$contexts = array( 'course_commercial', 'program_commercial' );
			}
			$this->register( $id, array(
				'label'    => $label,
				'contexts' => $contexts,
			) );
		}

		// Variantes visuales para secciones clave (render de variantes no-default en Sprint 6)
		$this->register( 'hero', array(
			'label'            => __( 'Cabecera / Hero', 'atora-lms' ),
			'contexts'         => array( 'course_overview', 'course_commercial' ),
			'allowed_variants' => array( 'default', 'centered', 'minimal' ),
		) );
		$this->register( 'curriculum', array(
			'label'            => __( 'Contenido del curso', 'atora-lms' ),
			'contexts'         => array( 'course_overview', 'course_commercial' ),
			'allowed_variants' => array( 'default', 'accordion', 'compact' ),
		) );
		$this->register( 'instructor', array(
			'label'            => __( 'Docente(s)', 'atora-lms' ),
			'contexts'         => array( 'course_overview', 'course_commercial' ),
			'allowed_variants' => array( 'default', 'minimal' ),
		) );

		// Secciones de lección
		$lesson_sections = array(
			'breadcrumb'   => __( 'Migas de pan', 'atora-lms' ),
			'header'       => __( 'Cabecera de lección', 'atora-lms' ),
			'cover'        => __( 'Portada de lección', 'atora-lms' ),
			'videos'       => __( 'Videos de la lección', 'atora-lms' ),
			'content'      => __( 'Contenido principal', 'atora-lms' ),
			'live_class'   => __( 'Clase en vivo', 'atora-lms' ),
			'resources'    => __( 'Material de apoyo', 'atora-lms' ),
			'quiz'         => __( 'Quiz', 'atora-lms' ),
			'submission'   => __( 'Entrega de actividad', 'atora-lms' ),
			'next'         => __( 'Siguiente paso', 'atora-lms' ),
			// Legacy aliases mantenidos por compatibilidad.
			'evaluation'   => __( 'Evaluación', 'atora-lms' ),
			'tips'         => __( 'Consejos del docente', 'atora-lms' ),
			'navigation'   => __( 'Navegación entre lecciones', 'atora-lms' ),
			'progress'     => __( 'Progreso', 'atora-lms' ),
			'ai_assistant' => __( 'Asistente IA', 'atora-lms' ),
		);

		foreach ( $lesson_sections as $id => $label ) {
			$this->register( $id, array(
				'label'    => $label,
				'contexts' => array( 'lesson' ),
			) );
		}

		// Secciones de programa comercial
		$program_sections = array(
			'hero'     => __( 'Cabecera / Hero', 'atora-lms' ),
			'summary'  => __( 'Resumen del programa', 'atora-lms' ),
			'modules'  => __( 'Módulos del programa', 'atora-lms' ),
			'related'  => __( 'Relacionados', 'atora-lms' ),
			'cta'      => __( 'Llamada a la acción', 'atora-lms' ),
		);

		foreach ( $program_sections as $id => $label ) {
			$this->register( $id, array(
				'label'    => $label,
				'contexts' => array( 'program_commercial' ),
			) );
		}

		/**
		 * Permite a extensiones registrar secciones adicionales.
		 *
		 * @param CLMS_UI_Section_Registry $registry
		 */
		do_action( 'clms_ui_register_sections', $this );
	}
}
