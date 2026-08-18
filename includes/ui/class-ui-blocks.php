<?php
/**
 * CLMS_UI_Blocks
 *
 * Registra los bloques Gutenberg dinámicos de ATORA LMS.
 *
 * ── Principio de diseño ───────────────────────────────────────────────────────
 *   Cero duplicación de render. Cada render_callback delega al mismo
 *   render_callback registrado en CLMS_UI_Section_Registry, que es el mismo
 *   que usan los templates PHP. El bloque es solo un punto de entrada alternativo
 *   al engine, no una nueva implementación.
 *
 * ── Bloques registrados ───────────────────────────────────────────────────────
 *   atora-lms/section-hero         Hero del curso
 *   atora-lms/section-faq          Preguntas frecuentes
 *   atora-lms/section-instructor   Docente(s)
 *   atora-lms/section-curriculum   Contenido del curso
 *   atora-lms/section-cta          Llamada a la acción
 *   atora-lms/course-template      Template completo (todas las secciones activas)
 *
 * ── Flujo de render ───────────────────────────────────────────────────────────
 *   1. WordPress llama al render_callback con ($attrs, $content, $block).
 *   2. Se obtiene $post_id de $block->context['postId'] o get_the_ID().
 *   3. Se construye CLMS_UI_Template_Context con el contexto del atributo.
 *   4. Se carga el schema fusionado (default + override de post meta).
 *   5. Se llama al render_callback de la sección desde el registry.
 *   6. Se retorna el HTML capturado con ob_start/ob_get_clean.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Blocks {

	/** Mapeo bloque → section_id en el registry (null = contenedor). */
	private const SECTION_BLOCKS = array(
		'section-hero'       => 'hero',
		'section-faq'        => 'faq',
		'section-instructor' => 'instructor',
		'section-curriculum' => 'curriculum',
		'section-cta'        => 'cta',
	);

	/** Directorio raíz de los bloques, relativo a ATORA_LMS_DIR. */
	private const BLOCKS_SUBDIR = 'blocks/';

	public function __construct() {
		add_action( 'init',                     array( $this, 'register_blocks' ) );
		add_filter( 'block_categories_all',     array( $this, 'register_category' ), 5, 1 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	// ── Categoría de bloques ──────────────────────────────────────────────────

	public function register_category( array $categories ): array {
		foreach ( $categories as $cat ) {
			if ( 'atora-lms' === ( $cat['slug'] ?? '' ) ) {
				return $categories;
			}
		}
		array_unshift( $categories, array(
			'slug'  => 'atora-lms',
			'title' => 'ATORA LMS',
			'icon'  => null,
		) );
		return $categories;
	}

	// ── Registro de bloques ───────────────────────────────────────────────────

	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$blocks_dir = $this->blocks_dir();

		// Bloques de sección
		foreach ( self::SECTION_BLOCKS as $block_slug => $section_id ) {
			$block_json = $blocks_dir . $block_slug . '/block.json';
			if ( ! file_exists( $block_json ) ) {
				continue;
			}
			register_block_type(
				$block_json,
				array( 'render_callback' => $this->make_visual_section_callback( $section_id ) )
			);
		}

		// Bloque contenedor
		$container_json = $blocks_dir . 'course-template/block.json';
		if ( file_exists( $container_json ) ) {
			register_block_type(
				$container_json,
				array( 'render_callback' => array( $this, 'render_course_template' ) )
			);
		}

		$this->register_pattern_category();
		$this->register_patterns();
	}

	private function get_section_defaults( string $section_id ): array {
		$defaults = array(
			'hero' => array(
				'eyebrow' => __( 'ATORA Premium', 'atora-lms' ),
				'title' => __( 'Una narrativa premium merece una primera pantalla más precisa.', 'atora-lms' ),
				'subtitle' => __( 'Diseña con una jerarquía clara, una promesa fuerte y una CTA que sí invite a avanzar.', 'atora-lms' ),
				'align' => 'left',
				'media_type' => 'image',
				'media_url' => '',
				'image_shape' => 'rounded',
				'primary_label' => __( 'CTA principal', 'atora-lms' ),
				'primary_url' => '#',
				'secondary_label' => __( 'CTA secundaria', 'atora-lms' ),
				'secondary_url' => '#',
			),
			'faq' => array(
				'eyebrow' => __( 'Preguntas frecuentes', 'atora-lms' ),
				'title' => __( 'Resuelve objeciones sin romper el ritmo visual.', 'atora-lms' ),
				'align' => 'left',
				'items' => array(),
			),
			'instructor' => array(
				'eyebrow' => __( 'Docentes', 'atora-lms' ),
				'title' => __( 'Muestra la autoridad detrás de la propuesta.', 'atora-lms' ),
				'align' => 'left',
				'card_style' => 'rounded',
				'items' => array(),
			),
			'curriculum' => array(
				'eyebrow' => __( 'Contenido', 'atora-lms' ),
				'title' => __( 'Temario claro, ritmo nítido, estructura premium.', 'atora-lms' ),
				'align' => 'left',
				'items' => array(),
			),
			'cta' => array(
				'eyebrow' => __( 'Cierre', 'atora-lms' ),
				'title' => __( 'Convierte el interés en acción con una CTA elegante.', 'atora-lms' ),
				'align' => 'center',
				'label' => __( 'Quiero empezar', 'atora-lms' ),
				'url' => '#',
			),
		);

		return $defaults[ $section_id ] ?? array();
	}

	private function normalize_items( $items, array $fallback_items ): array {
		if ( ! is_array( $items ) ) {
			return $fallback_items;
		}

		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = $item;
		}

		return ! empty( $out ) ? $out : $fallback_items;
	}

	private function make_visual_section_callback( string $section_id ): callable {
		return function ( array $attrs, string $content, WP_Block $block ) use ( $section_id ): string {
			return $this->render_visual_section( $section_id, $attrs );
		};
	}

	/**
	 * Registra la categoría de patrones premium de ATORA.
	 */
	private function register_pattern_category(): void {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category(
			'atora-lms-premium',
			array(
				'label' => __( 'ATORA Premium', 'atora-lms' ),
			)
		);
	}

	/**
	 * Registra patrones premium de arranque para landings comerciales.
	 */
	private function register_patterns(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		register_block_pattern(
			'atora-lms/premium-course-landing',
			array(
				'title'       => __( 'Landing premium para curso', 'atora-lms' ),
				'description' => __( 'Arranque visual editable para una landing de curso con estructura premium y copy fácil de ajustar.', 'atora-lms' ),
				'categories'  => array( 'atora-lms-premium' ),
				'postTypes'   => array( 'lm_course' ),
				'keywords'    => array( 'curso', 'landing', 'premium', 'editable', 'ATORA' ),
				'content'     => $this->build_course_premium_pattern(),
			)
		);

		register_block_pattern(
			'atora-lms/premium-program-landing',
			array(
				'title'       => __( 'Landing premium para programa', 'atora-lms' ),
				'description' => __( 'Estructura visual para presentar un programa con claridad, narrativa y llamadas a la acción editables.', 'atora-lms' ),
				'categories'  => array( 'atora-lms-premium' ),
				'postTypes'   => array( 'lm_program' ),
				'keywords'    => array( 'programa', 'landing', 'premium', 'editable', 'ATORA' ),
				'content'     => $this->build_program_premium_pattern(),
			)
		);

		register_block_pattern(
			'atora-lms/premium-lesson-landing',
			array(
				'title'       => __( 'Lección premium editable', 'atora-lms' ),
				'description' => __( 'Estructura visual de lección para abrir en Gutenberg con una narrativa clara, recursos y siguiente paso.', 'atora-lms' ),
				'categories'  => array( 'atora-lms-premium' ),
				'postTypes'   => array( 'lm_lesson' ),
				'keywords'    => array( 'lección', 'lesson', 'premium', 'editable', 'ATORA' ),
				'content'     => $this->build_lesson_premium_pattern(),
			)
		);
	}

	/**
	 * Patrón visual premium para cursos.
	 */
	private function build_course_premium_pattern(): string {
		$eyebrow        = esc_html__( 'Ruta premium editable', 'atora-lms' );
		$title          = esc_html__( 'Diseña una landing de curso que se vea premium y se edite sin fricción.', 'atora-lms' );
		$lead           = esc_html__( 'Empieza con un bloque visual claro, ajusta el copy y publica una página comercial lista para vender y enseñar.', 'atora-lms' );
		$button_primary  = esc_html__( 'Explorar cursos', 'atora-lms' );
		$button_secondary = esc_html__( 'Abrir editor', 'atora-lms' );
		$card_1_title    = esc_html__( 'Claridad comercial', 'atora-lms' );
		$card_1_copy     = esc_html__( 'Un héroe simple, una promesa fuerte y una lectura rápida para visitantes con intención real.', 'atora-lms' );
		$card_2_title    = esc_html__( 'Narrativa académica', 'atora-lms' );
		$card_2_copy     = esc_html__( 'Secciones que explican qué se aprende, para quién es el curso y cómo avanza el recorrido.', 'atora-lms' );
		$card_3_title    = esc_html__( 'Editable por profes', 'atora-lms' );
		$card_3_copy     = esc_html__( 'Bloques normales de Gutenberg para que el equipo académico cambie textos, listas y botones sin tocar PHP.', 'atora-lms' );
		$quote_title     = esc_html__( 'Una landing premium no debería sentirse técnica.', 'atora-lms' );
		$quote_copy      = esc_html__( 'Debe sentirse clara, guiada y lista para que un profesor la entienda y la edite con confianza.', 'atora-lms' );

		return <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"72px","right":"24px","bottom":"72px","left":"24px"}},"color":{"background":"#f8fafc"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#f8fafc;padding-top:72px;padding-right:24px;padding-bottom:72px;padding-left:24px">
	<!-- wp:group {"layout":{"type":"constrained","contentSize":"1080px"}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.16em","fontSize":"12px"},"color":{"text":"#2563eb"}}} -->
		<p class="has-text-color" style="color:#2563eb;text-transform:uppercase;letter-spacing:.16em;font-size:12px">{$eyebrow}</p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.8rem, 6vw, 4.8rem)","lineHeight":"1.05"},"spacing":{"margin":{"top":"0","bottom":"16px"}}}} -->
		<h1 style="font-size:clamp(2.8rem, 6vw, 4.8rem);line-height:1.05;margin-top:0;margin-bottom:16px">{$title}</h1>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"style":{"typography":{"fontSize":"20px","lineHeight":"1.65"},"color":{"text":"#475569"}}} -->
		<p class="has-text-color" style="color:#475569;font-size:20px;line-height:1.65">{$lead}</p>
		<!-- /wp:paragraph -->

		<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"28px","bottom":"44px"}}}} -->
		<div class="wp-block-buttons" style="margin-top:28px;margin-bottom:44px">
			<!-- wp:button -->
			<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">{$button_primary}</a></div>
			<!-- /wp:button -->

			<!-- wp:button {"className":"is-style-outline"} -->
			<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button">{$button_secondary}</a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->

		<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"16px"}}}} -->
		<div class="wp-block-columns">
			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$card_1_title}</h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$card_1_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$card_2_title}</h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$card_2_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$card_3_title}</h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$card_3_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->

		<!-- wp:quote {"style":{"spacing":{"margin":{"top":"36px","bottom":"24px"}},"color":{"background":"#ffffff"}}} -->
		<blockquote class="wp-block-quote has-background" style="background-color:#ffffff;margin-top:36px;margin-bottom:24px">
			<p>{$quote_title}</p>
			<cite>{$quote_copy}</cite>
		</blockquote>
		<!-- /wp:quote -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML;
	}

	/**
	 * Patrón visual premium para programas.
	 */
	private function build_program_premium_pattern(): string {
		$eyebrow         = esc_html__( 'Sistema visual de programa', 'atora-lms' );
		$title           = esc_html__( 'Presenta tu programa como una ruta clara, elegante y fácil de editar.', 'atora-lms' );
		$lead            = esc_html__( 'Úsalo para mallas, itinerarios y programas de varias capas sin depender de un builder pesado.', 'atora-lms' );
		$button_primary   = esc_html__( 'Explorar programas', 'atora-lms' );
		$button_secondary = esc_html__( 'Editar contenido', 'atora-lms' );
		$card_1_title     = esc_html__( 'Estructura comprensible', 'atora-lms' );
		$card_1_copy      = esc_html__( 'Una composición que separa visión general, promesa, módulos y cierre sin saturar al lector.', 'atora-lms' );
		$card_2_title     = esc_html__( 'Narrativa académica', 'atora-lms' );
		$card_2_copy      = esc_html__( 'Cada bloque puede convertirse en una explicación clara del recorrido, ritmo y resultados esperados.', 'atora-lms' );
		$card_3_title     = esc_html__( 'Listo para prompt + edición', 'atora-lms' );
		$card_3_copy      = esc_html__( 'Primero generas una base visual; después afinas copy, secciones y llamadas a la acción dentro de Gutenberg.', 'atora-lms' );
		$final_title      = esc_html__( 'Visual premium, control real.', 'atora-lms' );
		$final_copy       = esc_html__( 'El objetivo no es esconder el sistema, sino darle al profesor una interfaz que pueda manejar con confianza.', 'atora-lms' );

		return <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"72px","right":"24px","bottom":"72px","left":"24px"}},"color":{"background":"#f8fafc"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#f8fafc;padding-top:72px;padding-right:24px;padding-bottom:72px;padding-left:24px">
	<!-- wp:group {"layout":{"type":"constrained","contentSize":"1080px"}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.16em","fontSize":"12px"},"color":{"text":"#2563eb"}}} -->
		<p class="has-text-color" style="color:#2563eb;text-transform:uppercase;letter-spacing:.16em;font-size:12px">{$eyebrow}</p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.8rem, 6vw, 4.6rem)","lineHeight":"1.05"},"spacing":{"margin":{"top":"0","bottom":"16px"}}}} -->
		<h1 style="font-size:clamp(2.8rem, 6vw, 4.6rem);line-height:1.05;margin-top:0;margin-bottom:16px">{$title}</h1>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"style":{"typography":{"fontSize":"20px","lineHeight":"1.65"},"color":{"text":"#475569"}}} -->
		<p class="has-text-color" style="color:#475569;font-size:20px;line-height:1.65">{$lead}</p>
		<!-- /wp:paragraph -->

		<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"28px","bottom":"44px"}}}} -->
		<div class="wp-block-buttons" style="margin-top:28px;margin-bottom:44px">
			<!-- wp:button -->
			<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">{$button_primary}</a></div>
			<!-- /wp:button -->

			<!-- wp:button {"className":"is-style-outline"} -->
			<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button">{$button_secondary}</a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->

		<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"16px"}}}} -->
		<div class="wp-block-columns">
			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$card_1_title}</h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$card_1_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$card_2_title}</h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$card_2_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$card_3_title}</h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$card_3_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->

		<!-- wp:separator {"style":{"spacing":{"margin":{"top":"40px","bottom":"28px"}}}} -->
		<hr class="wp-block-separator" style="margin-top:40px;margin-bottom:28px" />
		<!-- /wp:separator -->

		<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}}}} -->
		<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px">
			<!-- wp:heading {"level":2,"style":{"spacing":{"margin":{"top":"0","bottom":"12px"}}}} -->
			<h2 style="margin-top:0;margin-bottom:12px">{$final_title}</h2>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.75"}}} -->
			<p class="has-text-color" style="color:#475569;line-height:1.75">{$final_copy}</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML;
	}

	/**
	 * Patrón visual premium para lecciones.
	 */
	private function build_lesson_premium_pattern(): string {
		$eyebrow = esc_html__( 'Lección premium editable', 'atora-lms' );
		$title   = esc_html__( 'Abre la clase con claridad y deja que el contenido haga el resto.', 'atora-lms' );
		$lead    = esc_html__( 'Una estructura de lección pensada para profes no técnicos: útil, visual y fácil de ajustar dentro de Gutenberg.', 'atora-lms' );
		$bullet_1 = esc_html__( 'Explica el objetivo de aprendizaje en una sola frase.', 'atora-lms' );
		$bullet_2 = esc_html__( 'Resume los puntos clave con un listado simple y escaneable.', 'atora-lms' );
		$bullet_3 = esc_html__( 'Cierra con recursos, práctica o siguiente paso sin recargar la vista.', 'atora-lms' );
		$callout_title = esc_html__( 'Consejo para la edición', 'atora-lms' );
		$callout_copy  = esc_html__( 'Si la lección ya tiene bloques, este patrón te ayuda a ordenar la narrativa sin ocultar el contenido real.', 'atora-lms' );
		$aside_title   = esc_html__( 'Antes de empezar', 'atora-lms' );
		$aside_copy    = esc_html__( 'Define el tiempo estimado, los recursos necesarios y la idea principal que el estudiante debe recordar.', 'atora-lms' );
		$cta_label     = esc_html__( 'Continuar la lección', 'atora-lms' );

		return <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"64px","right":"24px","bottom":"64px","left":"24px"}},"color":{"background":"#f8fafc"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="background-color:#f8fafc;padding-top:64px;padding-right:24px;padding-bottom:64px;padding-left:24px">
	<!-- wp:group {"layout":{"type":"constrained","contentSize":"1040px"}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.16em","fontSize":"12px"},"color":{"text":"#2563eb"}}} -->
		<p class="has-text-color" style="color:#2563eb;text-transform:uppercase;letter-spacing:.16em;font-size:12px">{$eyebrow}</p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.4rem, 5vw, 4.2rem)","lineHeight":"1.06"},"spacing":{"margin":{"top":"0","bottom":"16px"}}}} -->
		<h1 style="font-size:clamp(2.4rem, 5vw, 4.2rem);line-height:1.06;margin-top:0;margin-bottom:16px">{$title}</h1>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"style":{"typography":{"fontSize":"19px","lineHeight":"1.7"},"color":{"text":"#475569"}}} -->
		<p class="has-text-color" style="color:#475569;font-size:19px;line-height:1.7">{$lead}</p>
		<!-- /wp:paragraph -->

		<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"16px"},"margin":{"top":"28px","bottom":"28px"}}}} -->
		<div class="wp-block-columns" style="margin-top:28px;margin-bottom:28px">
			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$aside_title}</h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$aside_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">
				<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"18px","width":"1px","color":"#e5e7eb"},"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}}}} -->
				<div class="wp-block-group has-background" style="background-color:#ffffff;border-color:#e5e7eb;border-width:1px;border-radius:18px;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px">
					<!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"top":"0","bottom":"10px"}}}} -->
					<h3 style="margin-top:0;margin-bottom:10px">{$callout_title}</h3>
					<!-- /wp:heading -->

					<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"lineHeight":"1.7"}}} -->
					<p class="has-text-color" style="color:#475569;line-height:1.7">{$callout_copy}</p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:column -->
		</div>
		<!-- /wp:columns -->

		<!-- wp:list {"style":{"spacing":{"margin":{"top":"0","bottom":"28px"}},"typography":{"lineHeight":"1.8"}}} -->
		<ul style="margin-top:0;margin-bottom:28px;line-height:1.8">
			<li>{$bullet_1}</li>
			<li>{$bullet_2}</li>
			<li>{$bullet_3}</li>
		</ul>
		<!-- /wp:list -->

		<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"0","bottom":"0"}}}} -->
		<div class="wp-block-buttons">
			<!-- wp:button -->
			<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">{$cta_label}</a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML;
	}

	// ── Render callbacks ──────────────────────────────────────────────────────

	/**
	 * Devuelve un closure que renderiza una sección del registry.
	 * El closure captura $section_id para identificar qué sección renderizar.
	 *
	 * @param string $section_id  ID de sección en CLMS_UI_Section_Registry.
	 */
	private function make_section_callback( string $section_id ): callable {
		return function ( array $attrs, string $content, WP_Block $block ) use ( $section_id ): string {
			return $this->render_section( $section_id, $attrs, $block );
		};
	}

	/**
	 * Renderiza una sección individual delegando al render_callback del registry.
	 * No implementa lógica de render propia; solo encapsula el output buffer.
	 *
	 * @param string   $section_id
	 * @param array    $attrs       Atributos del bloque (schema_context, variant).
	 * @param WP_Block $block       Instancia del bloque (context tiene postId).
	 */
	private function render_section( string $section_id, array $attrs, WP_Block $block ): string {
		if ( ! $this->engine_available() ) {
			return '';
		}

		$post_id = $this->resolve_post_id( $block );
		if ( ! $post_id ) {
			return '';
		}

		$registry = CLMS_UI_Section_Registry::instance();
		$sec_def  = $registry->get( $section_id );

		if ( ! $sec_def || ! is_callable( $sec_def['render_callback'] ?? null ) ) {
			return '';
		}

		$schema_context = $this->resolve_course_template_context(
			$post_id,
			sanitize_key( (string) ( $attrs['schema_context'] ?? '' ) )
		);
		$variant        = sanitize_key( (string) ( $attrs['variant']         ?? 'default' ) );

		$ctx    = CLMS_UI_Template_Context::make( $post_id, $schema_context );
		$schema = $this->schema_repo()->get_merged_schema( $post_id, $schema_context );

		// Construye la entrada de sección que el callback espera
		$section_entry = array(
			'id'         => $section_id,
			'enabled'    => true,
			'variant'    => $variant,
			'props'      => array(),
			'visibility' => 'always',
			'roles'      => array(),
			'style'      => array(),
		);

		ob_start();
		call_user_func( $sec_def['render_callback'], $section_entry, $ctx, $schema );
		return (string) ob_get_clean();
	}

	/**
	 * Renderiza todas las secciones activas del schema en orden.
	 * Callback del bloque contenedor atora-lms/course-template.
	 *
	 * @param array    $attrs
	 * @param string   $content
	 * @param WP_Block $block
	 */
	public function render_course_template( array $attrs, string $content, WP_Block $block ): string {
		unset( $content );

		if ( ! $this->engine_available() ) {
			return '';
		}

		$post_id = $this->resolve_post_id( $block );
		if ( ! $post_id ) {
			return '';
		}

		$schema_context = $this->resolve_course_template_context(
			$post_id,
			sanitize_key( (string) ( $attrs['schema_context'] ?? '' ) )
		);

		$ctx    = CLMS_UI_Template_Context::make( $post_id, $schema_context );
		$schema = $this->schema_repo()->get_merged_schema( $post_id, $schema_context );
		$engine = new CLMS_UI_Template_Engine();
		$render = $engine->render_to_array( $ctx, $schema );
		if ( empty( $render ) ) {
			return '';
		}

		return '<div class="atora-premium-template atora-premium-template--' . esc_attr( $schema_context ) . '">' . implode( '', $render ) . '</div>';
	}

	private function resolve_course_template_context( int $post_id, string $requested_context = '' ): string {
		if ( in_array( $requested_context, array( 'course_commercial', 'course_overview' ), true ) ) {
			return $requested_context;
		}

		$preview_template = isset( $_GET['clms_preview_template'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( (string) wp_unslash( $_GET['clms_preview_template'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		if ( 'commercial' === $preview_template ) {
			return 'course_commercial';
		}
		if ( 'overview' === $preview_template ) {
			return 'course_overview';
		}

		$mode        = (string) get_post_meta( $post_id, '_clms_commercial_mode', true );
		$user_id     = get_current_user_id();
		$is_admin    = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
		$is_enrolled = $is_admin;

		if ( ! $is_enrolled && $user_id && class_exists( 'CLMS_Helper' ) ) {
			$is_enrolled = (bool) CLMS_Helper::user_is_enrolled_in_course( $user_id, $post_id );
		}

		if ( 'commercial' === $mode && ! $is_enrolled ) {
			return 'course_commercial';
		}

		return 'course_overview';
	}

	private function render_visual_section( string $section_id, array $attrs ): string {
		$data = wp_parse_args( $attrs, $this->get_section_defaults( $section_id ) );
		$align = in_array( (string) ( $data['align'] ?? 'left' ), array( 'left', 'center', 'right' ), true ) ? (string) $data['align'] : 'left';
		$title = esc_html( (string) ( $data['title'] ?? '' ) );
		$eyebrow = esc_html( (string) ( $data['eyebrow'] ?? '' ) );
		$subtitle = esc_html( (string) ( $data['subtitle'] ?? '' ) );
		$classes = 'atora-premium-section atora-premium-section--' . esc_attr( $section_id ) . ' is-align-' . esc_attr( $align );

		ob_start();
		?>
		<section class="<?php echo esc_attr( $classes ); ?>">
			<div class="atora-premium-shell">
				<?php if ( $eyebrow ) : ?>
					<p class="atora-premium-eyebrow"><?php echo $eyebrow; ?></p>
				<?php endif; ?>
				<?php if ( $title ) : ?>
					<h2 class="atora-premium-title"><?php echo $title; ?></h2>
				<?php endif; ?>
				<?php if ( $subtitle ) : ?>
					<p class="atora-premium-subtitle"><?php echo $subtitle; ?></p>
				<?php endif; ?>
				<?php if ( 'hero' === $section_id ) : ?>
					<?php $this->render_visual_hero_body( $data ); ?>
				<?php elseif ( 'faq' === $section_id ) : ?>
					<?php $this->render_visual_faq_body( $data ); ?>
				<?php elseif ( 'instructor' === $section_id ) : ?>
					<?php $this->render_visual_instructor_body( $data ); ?>
				<?php elseif ( 'curriculum' === $section_id ) : ?>
					<?php $this->render_visual_curriculum_body( $data ); ?>
				<?php elseif ( 'cta' === $section_id ) : ?>
					<?php $this->render_visual_cta_body( $data ); ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private function render_visual_hero_body( array $data ): void {
		$media_url = esc_url( (string) ( $data['media_url'] ?? '' ) );
		$image_shape = in_array( (string) ( $data['image_shape'] ?? 'rounded' ), array( 'round', 'square', 'vertical', 'rounded' ), true ) ? (string) $data['image_shape'] : 'rounded';
		?>
		<div class="atora-premium-hero is-<?php echo esc_attr( $image_shape ); ?>">
			<div class="atora-premium-hero__copy">
				<?php if ( ! empty( $data['primary_label'] ) ) : ?>
					<div class="atora-premium-actions">
						<a class="atora-premium-button is-primary" href="<?php echo esc_url( (string) ( $data['primary_url'] ?? '#' ) ); ?>"><?php echo esc_html( (string) $data['primary_label'] ); ?></a>
						<a class="atora-premium-button is-secondary" href="<?php echo esc_url( (string) ( $data['secondary_url'] ?? '#' ) ); ?>"><?php echo esc_html( (string) ( $data['secondary_label'] ?? '' ) ); ?></a>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $media_url ) : ?>
				<div class="atora-premium-hero__media"><img src="<?php echo $media_url; ?>" alt="" /></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_visual_faq_body( array $data ): void {
		$items = $this->normalize_items( $data['items'] ?? array(), array(
			array( 'question' => __( '¿Cómo se edita?', 'atora-lms' ), 'answer' => __( 'Directamente en el bloque, con controles de pregunta, respuesta y estilo.', 'atora-lms' ) ),
			array( 'question' => __( '¿Cuántos items puedo mostrar?', 'atora-lms' ), 'answer' => __( 'Los que necesites, sin romper la composición.', 'atora-lms' ) ),
		) );
		echo '<div class="atora-premium-list">';
		foreach ( $items as $item ) {
			echo '<details class="atora-premium-faq"><summary>' . esc_html( (string) ( $item['question'] ?? '' ) ) . '</summary><div>' . wp_kses_post( wpautop( (string) ( $item['answer'] ?? '' ) ) ) . '</div></details>';
		}
		echo '</div>';
	}

	private function render_visual_instructor_body( array $data ): void {
		$items = $this->normalize_items( $data['items'] ?? array(), array(
			array( 'name' => __( 'Docente principal', 'atora-lms' ), 'role' => __( 'Especialista ATORA', 'atora-lms' ), 'bio' => __( 'Autoridad visible, bio breve y enfoque premium.', 'atora-lms' ), 'image_url' => '' ),
		) );
		echo '<div class="atora-premium-grid">';
		foreach ( $items as $item ) {
			echo '<article class="atora-premium-card">';
			if ( ! empty( $item['image_url'] ) ) {
				echo '<img src="' . esc_url( (string) $item['image_url'] ) . '" alt="" />';
			}
			echo '<h3>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</h3>';
			echo '<p>' . esc_html( (string) ( $item['role'] ?? '' ) ) . '</p>';
			echo '<div>' . wp_kses_post( wpautop( (string) ( $item['bio'] ?? '' ) ) ) . '</div>';
			echo '</article>';
		}
		echo '</div>';
	}

	private function render_visual_curriculum_body( array $data ): void {
		$items = $this->normalize_items( $data['items'] ?? array(), array(
			array( 'title' => __( 'Módulo 1', 'atora-lms' ), 'text' => __( 'Introducción y base conceptual.', 'atora-lms' ) ),
			array( 'title' => __( 'Módulo 2', 'atora-lms' ), 'text' => __( 'Aplicación práctica y seguimiento.', 'atora-lms' ) ),
		) );
		echo '<ol class="atora-premium-timeline">';
		foreach ( $items as $item ) {
			echo '<li><strong>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</strong><p>' . esc_html( (string) ( $item['text'] ?? '' ) ) . '</p></li>';
		}
		echo '</ol>';
	}

	private function render_visual_cta_body( array $data ): void {
		echo '<div class="atora-premium-cta"><a class="atora-premium-button is-primary" href="' . esc_url( (string) ( $data['url'] ?? '#' ) ) . '">' . esc_html( (string) ( $data['label'] ?? '' ) ) . '</a></div>';
	}

	// ── Assets del editor ─────────────────────────────────────────────────────

	public function enqueue_editor_assets(): void {
		$version  = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0';
		$base_url = defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL : trailingslashit( plugin_dir_url( __FILE__ ) ) . '../../';

		wp_enqueue_script(
			'atora-lms-blocks-editor',
			trailingslashit( $base_url ) . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n' ),
			$version,
			true
		);

		wp_enqueue_style(
			'atora-lms-blocks-editor',
			trailingslashit( $base_url ) . 'blocks/editor.css',
			array(),
			$version
		);

		// Config disponible como window.ATORA_BLOCKS en el editor
		wp_localize_script(
			'atora-lms-blocks-editor',
			'ATORA_BLOCKS',
			array(
				'contexts' => array(
					'course_commercial',
					'course_overview',
					'lesson',
					'program_commercial',
					'program_overview',
				),
				'variants' => array( 'default', 'centered', 'minimal', 'accordion', 'compact' ),
			)
		);
	}

	// ── Utilidades ────────────────────────────────────────────────────────────

	/** Singleton del repositorio de schemas (evita instancias repetidas por bloque). */
	private ?CLMS_UI_Schema_Repository $repo = null;

	private function schema_repo(): CLMS_UI_Schema_Repository {
		if ( ! $this->repo ) {
			$this->repo = new CLMS_UI_Schema_Repository();
		}
		return $this->repo;
	}


	/**
	 * Obtiene el post ID del contexto del bloque o del loop global.
	 * `usesContext: ["postId"]` en block.json hace que WordPress inyecte
	 * el post ID del post padre en $block->context.
	 */
	private function resolve_post_id( WP_Block $block ): int {
		$from_ctx = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;
		return $from_ctx ?: (int) get_the_ID();
	}

	/**
	 * Verifica que las clases del engine de UI estén cargadas.
	 * Los bloques son no-ops silenciosos si el módulo UI no está activo.
	 */
	private function engine_available(): bool {
		return class_exists( 'CLMS_UI_Section_Registry', false )
			&& class_exists( 'CLMS_UI_Template_Context', false )
			&& class_exists( 'CLMS_UI_Schema_Repository', false )
			&& class_exists( 'CLMS_UI_Template_Engine', false )
			&& class_exists( 'CLMS_UI_Template_Resolver', false );
	}

	private function blocks_dir(): string {
		$base = defined( 'ATORA_LMS_DIR' ) ? ATORA_LMS_DIR : trailingslashit( dirname( __FILE__, 3 ) );
		return trailingslashit( $base ) . self::BLOCKS_SUBDIR;
	}
}
