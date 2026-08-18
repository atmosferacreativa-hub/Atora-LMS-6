<?php
/**
 * CLMS_UI_Editor_Templates
 *
 * Biblioteca de templates de pagina completa para el editor Gutenberg.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Editor_Templates {

	public function __construct() {
		add_action( 'init', array( $this, 'register_patterns' ), 25 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ), 40 );
		add_action( 'add_meta_boxes', array( $this, 'remove_native_custom_fields_metabox' ), 100 );
		add_action( 'admin_menu', array( $this, 'remove_native_custom_fields_metabox' ), 100 );
	}

	public function enqueue_editor_assets(): void {
		$version  = ( defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0' ) . '-editor-templates-20260510';
		$base_url = defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL : trailingslashit( plugin_dir_url( __FILE__ ) ) . '../../';

		wp_enqueue_script(
			'atora-lms-editor-templates',
			trailingslashit( $base_url ) . 'blocks/editor-templates.js',
			array( 'wp-blocks', 'wp-data', 'wp-dom-ready', 'wp-i18n', 'wp-element', 'wp-components', 'wp-plugins', 'wp-edit-post' ),
			$version,
			true
		);

		wp_enqueue_style(
			'atora-lms-editor-templates',
			trailingslashit( $base_url ) . 'blocks/editor-templates.css',
			array(),
			$version
		);

		wp_localize_script(
			'atora-lms-editor-templates',
			'ATORA_EDITOR_TEMPLATES',
			array(
				'templates' => $this->get_templates(),
				'labels'    => array(
					'tab'       => __( 'Templates', 'atora-lms' ),
					'search'    => __( 'Buscar templates', 'atora-lms' ),
					'insert'    => __( 'Insertar', 'atora-lms' ),
					'replace'   => __( 'Reemplazar contenido', 'atora-lms' ),
					'empty'     => __( 'No hay templates con ese filtro.', 'atora-lms' ),
					'confirm'   => __( 'Esto reemplazara los bloques actuales. Continuar?', 'atora-lms' ),
					'panel'     => __( 'Templates ATORA', 'atora-lms' ),
					'help'      => __( 'Tambien estan disponibles en Patrones > ATORA Templates.', 'atora-lms' ),
				),
			)
		);
	}

	public function register_patterns(): void {
		if ( ! function_exists( 'register_block_pattern' ) || ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category(
			'atora-editor-templates',
			array(
				'label' => __( 'ATORA Templates', 'atora-lms' ),
			)
		);

		foreach ( $this->get_templates() as $template ) {
			if ( empty( $template['id'] ) || empty( $template['content'] ) ) {
				continue;
			}

			register_block_pattern(
				'atora-lms-template/' . sanitize_key( $template['id'] ),
				array(
					'title'       => sprintf(
						/* translators: %s: template title. */
						__( 'Template ATORA: %s', 'atora-lms' ),
						$template['title']
					),
					'description' => $template['description'] ?? '',
					'categories'  => array( 'atora-editor-templates' ),
					'keywords'    => array( 'atora', 'template', sanitize_title( (string) ( $template['type'] ?? '' ) ) ),
					'content'     => $template['content'],
				)
			);
		}
	}

	public function remove_native_custom_fields_metabox(): void {
		if ( ! is_admin() || ! function_exists( 'remove_meta_box' ) ) {
			return;
		}

		foreach ( get_post_types( array(), 'names' ) as $post_type ) {
			remove_meta_box( 'postcustom', $post_type, 'normal' );
			remove_meta_box( 'postcustom', $post_type, 'advanced' );
		}
	}

	private function get_templates(): array {
		return array(
			array(
				'id'          => 'home-academia-visual',
				'type'        => __( 'Home', 'atora-lms' ),
				'title'       => __( 'Home Academia Visual', 'atora-lms' ),
				'description' => __( 'Portada editorial con promesa, catalogo y prueba social.', 'atora-lms' ),
				'tone'        => 'light',
				'content'     => $this->template_home_academia_visual(),
			),
			array(
				'id'          => 'home-lms-catalog',
				'type'        => __( 'Home', 'atora-lms' ),
				'title'       => __( 'Home Cursos y Programas', 'atora-lms' ),
				'description' => __( 'Entrada directa a catalogo, rutas de aprendizaje y comunidad.', 'atora-lms' ),
				'tone'        => 'blue',
				'content'     => $this->template_home_lms_catalog(),
			),
			array(
				'id'          => 'home-studio-dark',
				'type'        => __( 'Home', 'atora-lms' ),
				'title'       => __( 'Home Studio Dark', 'atora-lms' ),
				'description' => __( 'Primera pantalla oscura con contraste alto y bloques de conversion.', 'atora-lms' ),
				'tone'        => 'dark',
				'content'     => $this->template_home_studio_dark(),
			),
			array(
				'id'          => 'blog-editorial',
				'type'        => __( 'Blog', 'atora-lms' ),
				'title'       => __( 'Blog Editorial', 'atora-lms' ),
				'description' => __( 'Indice de blog con intro, destacados y listado editorial.', 'atora-lms' ),
				'tone'        => 'light',
				'content'     => $this->template_blog_editorial(),
			),
			array(
				'id'          => 'blog-magazine',
				'type'        => __( 'Blog', 'atora-lms' ),
				'title'       => __( 'Blog Magazine', 'atora-lms' ),
				'description' => __( 'Grid de publicaciones para lectura rapida y categorias.', 'atora-lms' ),
				'tone'        => 'blue',
				'content'     => $this->template_blog_magazine(),
			),
			array(
				'id'          => 'post-longform',
				'type'        => __( 'Post', 'atora-lms' ),
				'title'       => __( 'Post Longform', 'atora-lms' ),
				'description' => __( 'Estructura larga para articulos, ensayos y guias profundas.', 'atora-lms' ),
				'tone'        => 'light',
				'content'     => $this->template_post_longform(),
			),
			array(
				'id'          => 'post-tutorial',
				'type'        => __( 'Post', 'atora-lms' ),
				'title'       => __( 'Post Tutorial', 'atora-lms' ),
				'description' => __( 'Formato de pasos, materiales, checklist y cierre accionable.', 'atora-lms' ),
				'tone'        => 'dark',
				'content'     => $this->template_post_tutorial(),
			),
			array(
				'id'          => 'header-studio-simple',
				'type'        => __( 'Header', 'atora-lms' ),
				'title'       => __( 'Header Studio Simple', 'atora-lms' ),
				'description' => __( 'Logo, titulo del sitio, navegacion y CTA limpio.', 'atora-lms' ),
				'tone'        => 'light',
				'content'     => $this->template_header_studio_simple(),
			),
			array(
				'id'          => 'header-landing-cta',
				'type'        => __( 'Header', 'atora-lms' ),
				'title'       => __( 'Header Landing CTA', 'atora-lms' ),
				'description' => __( 'Header compacto para landings con accion principal.', 'atora-lms' ),
				'tone'        => 'blue',
				'content'     => $this->template_header_landing_cta(),
			),
			array(
				'id'          => 'header-student-access',
				'type'        => __( 'Header', 'atora-lms' ),
				'title'       => __( 'Header Acceso Estudiante', 'atora-lms' ),
				'description' => __( 'Header con navegacion de usuario y boton de autenticacion.', 'atora-lms' ),
				'tone'        => 'dark',
				'content'     => $this->template_header_student_access(),
			),
			array(
				'id'          => 'footer-studio-full',
				'type'        => __( 'Footer', 'atora-lms' ),
				'title'       => __( 'Footer Studio Completo', 'atora-lms' ),
				'description' => __( 'Footer de cuatro columnas para marca, enlaces y contacto.', 'atora-lms' ),
				'tone'        => 'dark',
				'content'     => $this->template_footer_studio_full(),
			),
			array(
				'id'          => 'footer-academy-cta',
				'type'        => __( 'Footer', 'atora-lms' ),
				'title'       => __( 'Footer Academia CTA', 'atora-lms' ),
				'description' => __( 'Cierre con llamada a catalogo y enlaces esenciales.', 'atora-lms' ),
				'tone'        => 'blue',
				'content'     => $this->template_footer_academy_cta(),
			),
			array(
				'id'          => 'footer-minimal',
				'type'        => __( 'Footer', 'atora-lms' ),
				'title'       => __( 'Footer Minimal', 'atora-lms' ),
				'description' => __( 'Footer sobrio para paginas limpias y funnels.', 'atora-lms' ),
				'tone'        => 'light',
				'content'     => $this->template_footer_minimal(),
			),
		);
	}

	private function template_home_academia_visual(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#f7f3ec"},"spacing":{"padding":{"top":"86px","right":"24px","bottom":"64px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#f7f3ec;padding-top:86px;padding-right:24px;padding-bottom:64px;padding-left:24px"><!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"left":"52px"}}}} -->
<div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","width":"58%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:58%"><!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","fontStyle":"normal","fontWeight":"700"},"color":{"text":"#1769f6"}}} -->
<p class="has-text-color" style="color:#1769f6;font-style:normal;font-weight:700;text-transform:uppercase">Academia Atmósfera Creativa</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(3.2rem, 8vw, 6.8rem)","lineHeight":"0.94"},"color":{"text":"#0d0d0b"}}} -->
<h1 class="wp-block-heading has-text-color" style="color:#0d0d0b;font-size:clamp(3.2rem, 8vw, 6.8rem);line-height:0.94">Forma tu voz. Afina tu mirada.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.25rem","lineHeight":"1.7"},"color":{"text":"#4b5563"}}} -->
<p class="has-text-color" style="color:#4b5563;font-size:1.25rem;line-height:1.7">Una pagina de inicio para presentar cursos, programas y una experiencia academica visual con autoridad, ritmo y conversion.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#0d0d0b","text":"#ffffff"},"border":{"radius":"999px"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" style="border-radius:999px;color:#ffffff;background-color:#0d0d0b">Ver cursos</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline","style":{"border":{"radius":"999px"}}} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" style="border-radius:999px">Conocer la academia</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"42%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:42%"><!-- wp:group {"style":{"border":{"radius":"8px"},"color":{"background":"#ffffff"},"spacing":{"padding":{"top":"28px","right":"28px","bottom":"28px","left":"28px"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="border-radius:8px;background-color:#ffffff;padding-top:28px;padding-right:28px;padding-bottom:28px;padding-left:28px"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Aprende con metodo y sensibilidad visual</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul><!-- wp:list-item --><li>Diplomados y cursos online</li><!-- /wp:list-item --><!-- wp:list-item --><li>Ruta para estudiantes inscritos</li><!-- /wp:list-item --><!-- wp:list-item --><li>Contenido editorial para atraer nuevos alumnos</li><!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"56px","bottom":"36px"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide" style="padding-top:56px;padding-bottom:36px"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">Cursos destacados</h2>
<!-- /wp:heading -->

<!-- wp:atora-lms/shortcode-clms-catalog {"attrs":{"per_page":"6","type":"course","columns":"3","filters":"0","recommendations":"1","recommendations_limit":"3"}} /--></div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"18px"}}}} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Una experiencia clara para aprender, practicar y mostrar progreso real.</p><!-- /wp:paragraph --></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Ideal para combinar narrativa comercial y vida academica en una sola pagina.</p><!-- /wp:paragraph --></blockquote>
<!-- /wp:quote --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
HTML;
	}

	private function template_home_lms_catalog(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#1769f6","text":"#ffffff"},"spacing":{"padding":{"top":"76px","right":"24px","bottom":"70px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1120px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#ffffff;background-color:#1769f6;padding-top:76px;padding-right:24px;padding-bottom:70px;padding-left:24px"><!-- wp:heading {"level":1,"textAlign":"center","style":{"typography":{"fontSize":"clamp(3rem, 8vw, 6rem)","lineHeight":"0.96"}}} -->
<h1 class="wp-block-heading has-text-align-center" style="font-size:clamp(3rem, 8vw, 6rem);line-height:0.96">Tu campus visual, simple y listo para crecer.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.25rem","lineHeight":"1.7"}}} -->
<p class="has-text-align-center" style="font-size:1.25rem;line-height:1.7">Una home para mostrar cursos, programas, comunidad y acceso rapido al aprendizaje.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"#ffffff","text":"#0d0d0b"},"border":{"radius":"999px"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" style="border-radius:999px;color:#0d0d0b;background-color:#ffffff">Explorar catalogo</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"padding":{"top":"44px","bottom":"24px"},"blockGap":{"left":"18px"}}}} -->
<div class="wp-block-columns alignwide" style="padding-top:44px;padding-bottom:24px"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Cursos</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Presenta ofertas individuales con filtros y recomendaciones.</p><!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Programas</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Agrupa rutas academicas, diplomados y experiencias completas.</p><!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Estudiantes</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Conecta con paneles, progreso y comunidad de aprendizaje.</p><!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:atora-lms/shortcode-clms-catalog {"attrs":{"per_page":"9","type":"all","columns":"3","filters":"0","recommendations":"1"}} /-->
HTML;
	}

	private function template_home_studio_dark(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#0d0d0b","text":"#f7f3ec"},"spacing":{"padding":{"top":"86px","right":"24px","bottom":"74px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1120px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#f7f3ec;background-color:#0d0d0b;padding-top:86px;padding-right:24px;padding-bottom:74px;padding-left:24px"><!-- wp:paragraph {"align":"center","style":{"typography":{"textTransform":"uppercase","fontStyle":"normal","fontWeight":"700"},"color":{"text":"#f4b21b"}}} -->
<p class="has-text-align-center has-text-color" style="color:#f4b21b;font-style:normal;font-weight:700;text-transform:uppercase">Atora Studio</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"textAlign":"center","style":{"typography":{"fontSize":"clamp(3.4rem, 9vw, 7rem)","lineHeight":"0.92"}}} -->
<h1 class="wp-block-heading has-text-align-center" style="font-size:clamp(3.4rem, 9vw, 7rem);line-height:0.92">Una primera impresion con pulso y claridad.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.2rem","lineHeight":"1.75"}}} -->
<p class="has-text-align-center" style="font-size:1.2rem;line-height:1.75">Template oscuro para lanzamientos, cohortes, diplomados y landings que necesitan contraste real.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"padding":{"top":"44px","bottom":"44px"},"blockGap":{"left":"18px"}}}} -->
<div class="wp-block-columns alignwide" style="padding-top:44px;padding-bottom:44px"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Narrativa</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Promesa fuerte, subtitulo claro y CTA con foco.</p><!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Prueba</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Testimonios, docentes, comunidad y resultados visibles.</p><!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Conversion</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Una ruta de lectura que invita a avanzar sin saturar.</p><!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:atora-lms/shortcode-clms-teachers {"attrs":{"limit":"6","columns":"3","show_bio":"yes","show_achievements":"yes","show_socials":"yes","show_profile_link":"yes"}} /-->
HTML;
	}

	private function template_blog_editorial(): string {
		return <<<'HTML'
<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"64px","bottom":"34px"}}},"layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignwide" style="padding-top:64px;padding-bottom:34px"><!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","fontStyle":"normal","fontWeight":"700"},"color":{"text":"#1769f6"}}} -->
<p class="has-text-color" style="color:#1769f6;font-style:normal;font-weight:700;text-transform:uppercase">Blog</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(3rem, 8vw, 6rem)","lineHeight":"0.95"}}} -->
<h1 class="wp-block-heading" style="font-size:clamp(3rem, 8vw, 6rem);line-height:0.95">Ideas para mirar, crear y aprender mejor.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.2rem","lineHeight":"1.7"},"color":{"text":"#4b5563"}}} -->
<p class="has-text-color" style="color:#4b5563;font-size:1.2rem;line-height:1.7">Una entrada editorial para publicar ensayos, guias, entrevistas y recursos academicos.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:query {"query":{"perPage":7,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"only","inherit":false},"displayLayout":{"type":"flex","columns":1}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"24px","bottom":"24px"}},"border":{"bottom":{"color":"#e5e7eb","width":"1px"}}},"layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group" style="border-bottom-color:#e5e7eb;border-bottom-width:1px;padding-top:24px;padding-bottom:24px"><!-- wp:post-title {"isLink":true,"style":{"typography":{"fontSize":"2rem","lineHeight":"1.1"}}} /-->
<!-- wp:post-excerpt {"moreText":"Leer articulo"} /-->
<!-- wp:post-date /--></div>
<!-- /wp:group -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
HTML;
	}

	private function template_blog_magazine(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#f0ebe2"},"spacing":{"padding":{"top":"58px","right":"24px","bottom":"40px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#f0ebe2;padding-top:58px;padding-right:24px;padding-bottom:40px;padding-left:24px"><!-- wp:heading {"level":1,"textAlign":"center","style":{"typography":{"fontSize":"clamp(3rem, 8vw, 6rem)","lineHeight":"0.95"}}} -->
<h1 class="wp-block-heading has-text-align-center" style="font-size:clamp(3rem, 8vw, 6rem);line-height:0.95">Revista visual</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Historias, tecnicas, entrevistas y recursos para una comunidad creativa.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:query {"query":{"perPage":9,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"displayLayout":{"type":"flex","columns":3}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:group {"style":{"spacing":{"padding":{"top":"18px","right":"18px","bottom":"18px","left":"18px"}},"border":{"radius":"8px","color":"#e5e7eb","width":"1px"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-border-color" style="border-color:#e5e7eb;border-width:1px;border-radius:8px;padding-top:18px;padding-right:18px;padding-bottom:18px;padding-left:18px"><!-- wp:post-featured-image {"isLink":true,"aspectRatio":"4/3"} /-->
<!-- wp:post-title {"isLink":true,"style":{"typography":{"fontSize":"1.35rem","lineHeight":"1.15"}}} /-->
<!-- wp:post-excerpt {"excerptLength":18} /--></div>
<!-- /wp:group -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
HTML;
	}

	private function template_post_longform(): string {
		return <<<'HTML'
<!-- wp:group {"layout":{"type":"constrained","contentSize":"820px"},"style":{"spacing":{"padding":{"top":"56px","bottom":"24px"}}}} -->
<div class="wp-block-group" style="padding-top:56px;padding-bottom:24px"><!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","fontStyle":"normal","fontWeight":"700"},"color":{"text":"#1769f6"}}} -->
<p class="has-text-color" style="color:#1769f6;font-style:normal;font-weight:700;text-transform:uppercase">Ensayo visual</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.8rem, 7vw, 5.4rem)","lineHeight":"0.98"}}} -->
<h1 class="wp-block-heading" style="font-size:clamp(2.8rem, 7vw, 5.4rem);line-height:0.98">Titulo del articulo con una promesa clara.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.22rem","lineHeight":"1.75"},"color":{"text":"#4b5563"}}} -->
<p class="has-text-color" style="color:#4b5563;font-size:1.22rem;line-height:1.75">Escribe aqui una entrada potente que situe el problema, el contexto y la idea central del articulo.</p>
<!-- /wp:paragraph -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:heading -->
<h2 class="wp-block-heading">La idea central</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Desarrolla la tesis principal con ejemplos, imagenes y referencias. Mantiene parrafos breves y una progresion clara.</p>
<!-- /wp:paragraph -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Incluye una cita o reflexion destacada que haga respirar la lectura.</p><!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Aplicacion practica</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Conecta la idea con una accion concreta para estudiantes, fotografos o comunicadores.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML;
	}

	private function template_post_tutorial(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#0d0d0b","text":"#f7f3ec"},"spacing":{"padding":{"top":"58px","right":"24px","bottom":"44px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#f7f3ec;background-color:#0d0d0b;padding-top:58px;padding-right:24px;padding-bottom:44px;padding-left:24px"><!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase","fontStyle":"normal","fontWeight":"700"},"color":{"text":"#f4b21b"}}} -->
<p class="has-text-color" style="color:#f4b21b;font-style:normal;font-weight:700;text-transform:uppercase">Tutorial</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(3rem, 8vw, 5.8rem)","lineHeight":"0.96"}}} -->
<h1 class="wp-block-heading" style="font-size:clamp(3rem, 8vw, 5.8rem);line-height:0.96">Como lograr un resultado concreto paso a paso.</h1>
<!-- /wp:heading --></div>
<!-- /wp:group -->

<!-- wp:group {"layout":{"type":"constrained","contentSize":"860px"},"style":{"spacing":{"padding":{"top":"42px","bottom":"42px"}}}} -->
<div class="wp-block-group" style="padding-top:42px;padding-bottom:42px"><!-- wp:heading -->
<h2 class="wp-block-heading">Materiales</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul><!-- wp:list-item --><li>Material o recurso 1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Material o recurso 2</li><!-- /wp:list-item --><!-- wp:list-item --><li>Material o recurso 3</li><!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Paso 1</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Describe la primera accion con claridad. Evita mezclar varias instrucciones en un mismo bloque.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Paso 2</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Explica que debe observar el lector y que errores conviene evitar.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Checklist final</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul><!-- wp:list-item --><li>Resultado revisado</li><!-- /wp:list-item --><!-- wp:list-item --><li>Detalle ajustado</li><!-- /wp:list-item --><!-- wp:list-item --><li>Siguiente practica definida</li><!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
HTML;
	}

	private function template_header_studio_simple(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#ffffff"},"spacing":{"padding":{"top":"14px","right":"24px","bottom":"14px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#ffffff;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px"><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:site-logo {"width":76} /-->

<!-- wp:site-title {"level":0,"style":{"typography":{"fontStyle":"normal","fontWeight":"800"}}} /--></div>
<!-- /wp:group -->

<!-- wp:navigation {"overlayMenu":"mobile","layout":{"type":"flex","justifyContent":"right"}} /-->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"border":{"radius":"999px"},"color":{"background":"#0d0d0b","text":"#ffffff"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" style="border-radius:999px;color:#ffffff;background-color:#0d0d0b">Ingresar</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
HTML;
	}

	private function template_header_landing_cta(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#1769f6","text":"#ffffff"},"spacing":{"padding":{"top":"12px","right":"24px","bottom":"12px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#ffffff;background-color:#1769f6;padding-top:12px;padding-right:24px;padding-bottom:12px;padding-left:24px"><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:site-logo {"width":70} /-->

<!-- wp:site-title {"level":0,"style":{"typography":{"fontStyle":"normal","fontWeight":"800"}}} /--></div>
<!-- /wp:group -->

<!-- wp:paragraph {"style":{"typography":{"fontStyle":"normal","fontWeight":"700"}}} -->
<p style="font-style:normal;font-weight:700">Nueva cohorte abierta</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"border":{"radius":"999px"},"color":{"background":"#ffffff","text":"#0d0d0b"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" style="border-radius:999px;color:#0d0d0b;background-color:#ffffff">Inscribirme</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
HTML;
	}

	private function template_header_student_access(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#0d0d0b","text":"#f7f3ec"},"spacing":{"padding":{"top":"14px","right":"24px","bottom":"14px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#f7f3ec;background-color:#0d0d0b;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px"><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:site-logo {"width":72} /-->

<!-- wp:site-title {"level":0,"style":{"typography":{"fontStyle":"normal","fontWeight":"800"}}} /--></div>
<!-- /wp:group -->

<!-- wp:atora-lms/shortcode-clms-user-nav {"attrs":{"courses_url":"/cursos/","login_url":"","register_url":"","class":""}} /-->

<!-- wp:atora-lms/shortcode-clms-auth-button {"attrs":{"login_label":"Ingresar","login_url":"","logout_label":"Cerrar sesion","logout_redirect":"","class":"clms-auth-btn"}} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
HTML;
	}

	private function template_footer_studio_full(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#0d0d0b","text":"#f7f3ec"},"spacing":{"padding":{"top":"46px","right":"24px","bottom":"42px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#f7f3ec;background-color:#0d0d0b;padding-top:46px;padding-right:24px;padding-bottom:42px;padding-left:24px"><!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"28px"}}}} -->
<div class="wp-block-columns"><!-- wp:column {"width":"34%"} -->
<div class="wp-block-column" style="flex-basis:34%"><!-- wp:site-logo {"width":92} /-->

<!-- wp:site-title {"level":0} /-->

<!-- wp:paragraph -->
<p>Formacion en fotografia, comunicacion estrategica y aprendizaje digital.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Academia</h4>
<!-- /wp:heading -->

<!-- wp:navigation {"overlayMenu":"never"} /--></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Contacto</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>hola@atmosferacreativa.com<br>Caracas, Venezuela</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Redes</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Instagram<br>YouTube<br>TikTok</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
HTML;
	}

	private function template_footer_academy_cta(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#1769f6","text":"#ffffff"},"spacing":{"padding":{"top":"48px","right":"24px","bottom":"48px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"960px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#ffffff;background-color:#1769f6;padding-top:48px;padding-right:24px;padding-bottom:48px;padding-left:24px"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">Sigue aprendiendo con Atmósfera Creativa</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Explora cursos, programas y recursos para entrenar tu mirada.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"border":{"radius":"999px"},"color":{"background":"#ffffff","text":"#0d0d0b"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" style="border-radius:999px;color:#0d0d0b;background-color:#ffffff">Ver catalogo</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
HTML;
	}

	private function template_footer_minimal(): string {
		return <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#f7f3ec","text":"#0d0d0b"},"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} -->
<div class="wp-block-group alignfull has-text-color has-background" style="color:#0d0d0b;background-color:#f7f3ec;padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px"><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:site-title {"level":0} /-->

<!-- wp:paragraph -->
<p>© Atora Studio. Todos los derechos reservados.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
HTML;
	}
}
