<?php
/**
 * CLMS_UI_Shortcode_Blocks
 *
 * Registra bloques Gutenberg dinamicos para los shortcodes publicos de ATORA LMS.
 * Cada bloque renderiza el shortcode real en frontend, de modo que el shortcode
 * sigue siendo la fuente de verdad y Gutenberg solo ofrece una entrada visual.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Shortcode_Blocks {

	/**
	 * Shortcodes visibles en el inserter de Gutenberg.
	 *
	 * Los demas se siguen registrando para no romper contenido existente, pero
	 * quedan fuera del selector principal para reducir ruido editorial.
	 */
	private const INSERTER_SHORTCODES = array(
		'clms_dashboard',
		'clms_course_list',
		'clms_my_courses',
		'clms_catalog',
		'clms_course_instructor',
		'clms_instructor_profile',
		'clms_teachers',
		'clms_login_form',
		'clms_auth_button',
		'clms_user_nav',
		'atora_form',
		'atora_product_purchase_box',
	);

	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ), 30 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ), 20 );
	}

	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		foreach ( $this->get_registry() as $tag => $config ) {
			$block_name = $this->block_name_from_shortcode( $tag );

			register_block_type(
				$block_name,
				array(
					'api_version'     => 3,
					'title'           => $config['title'],
					'category'        => 'atora-lms',
					'icon'            => $config['icon'] ?? 'shortcode',
					'description'     => $config['description'] ?? '',
					'attributes'      => array(
						'attrs'      => array(
							'type'    => 'object',
							'default' => $this->default_attrs_for_config( $config ),
						),
						'className'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'style'      => array(
							'type'    => 'object',
							'default' => array(),
						),
						'textColor'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'backgroundColor' => array(
							'type'    => 'string',
							'default' => '',
						),
						'fontSize'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'extraAttrs' => array(
							'type'    => 'string',
							'default' => '',
						),
						'content'    => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					'supports'        => array(
						'html'     => false,
						'inserter' => $this->is_inserter_shortcode( $tag ),
						'color'    => array(
							'text'       => true,
							'background' => true,
						),
						'typography' => array(
							'fontSize' => true,
						),
						'spacing' => array(
							'margin'  => true,
							'padding' => true,
						),
					),
					'render_callback' => function ( array $attributes, string $content = '' ) use ( $tag, $config ): string {
						return $this->render_shortcode_block( $tag, $config, $attributes, $content );
					},
				)
			);
		}
	}

	public function enqueue_editor_assets(): void {
		$version  = ( defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0' ) . '-shortcode-blocks-20260512';
		$base_url = defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL : trailingslashit( plugin_dir_url( __FILE__ ) ) . '../../';

		wp_enqueue_script(
			'atora-lms-shortcode-blocks-editor',
			trailingslashit( $base_url ) . 'blocks/shortcode-blocks.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			$version,
			true
		);

		wp_enqueue_style(
			'atora-lms-shortcode-blocks-editor',
			trailingslashit( $base_url ) . 'blocks/shortcode-blocks.css',
			array(),
			$version
		);

		wp_localize_script(
			'atora-lms-shortcode-blocks-editor',
			'ATORA_SHORTCODE_BLOCKS',
			array(
				'category' => 'atora-lms',
				'blocks'   => $this->get_editor_registry(),
			)
		);
	}

	public function render_shortcode_block( string $tag, array $config, array $attributes, string $content = '' ): string {
		if ( ! shortcode_exists( $tag ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<div class="clms-shortcode-block__missing">' . esc_html(
					sprintf(
						/* translators: %s: shortcode tag. */
						__( 'El shortcode [%s] no esta disponible en este contexto.', 'atora-lms' ),
						$tag
					)
				) . '</div>';
			}

			return '';
		}

		$attrs = isset( $attributes['attrs'] ) && is_array( $attributes['attrs'] ) ? $attributes['attrs'] : array();
		$attrs = $this->sanitize_shortcode_attrs( $config, $attrs );
		$extra = $this->parse_extra_attrs( $attributes['extraAttrs'] ?? '' );
		$attrs = array_merge( $attrs, $extra );

		$inner_content = '';
		if ( ! empty( $config['has_content'] ) ) {
			$inner_content = isset( $attributes['content'] ) ? wp_kses_post( (string) $attributes['content'] ) : '';
		}

		$shortcode = $this->build_shortcode( $tag, $attrs, $inner_content, ! empty( $config['has_content'] ) );

		$wrapper_class = 'clms-shortcode-block clms-shortcode-block--' . str_replace( '_', '-', $tag );

		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			$wrapper_attrs = get_block_wrapper_attributes(
				array(
					'class' => $wrapper_class,
				)
			);
		} else {
			$wrapper_attrs = 'class="' . esc_attr( $wrapper_class ) . '"';
		}

		return '<div ' . $wrapper_attrs . '>' . do_shortcode( $shortcode ) . '</div>';
	}

	private function get_editor_registry(): array {
		$blocks = array();

		foreach ( $this->get_registry() as $tag => $config ) {
			$blocks[] = array(
				'name'        => $this->block_name_from_shortcode( $tag ),
				'tag'         => $tag,
				'title'       => $config['title'],
				'description' => $config['description'] ?? '',
				'icon'        => $config['icon'] ?? 'shortcode',
				'inserter'    => $this->is_inserter_shortcode( $tag ),
				'fields'      => $config['fields'] ?? array(),
				'defaults'    => $this->default_attrs_for_config( $config ),
				'hasContent'  => ! empty( $config['has_content'] ),
			);
		}

		return $blocks;
	}

	private function is_inserter_shortcode( string $tag ): bool {
		$visible_tags = apply_filters( 'atora_lms_shortcode_blocks_inserter_tags', self::INSERTER_SHORTCODES );
		$visible_tags = is_array( $visible_tags ) ? array_map( 'sanitize_key', $visible_tags ) : self::INSERTER_SHORTCODES;

		return in_array( sanitize_key( $tag ), $visible_tags, true );
	}

	private function block_name_from_shortcode( string $tag ): string {
		return 'atora-lms/shortcode-' . str_replace( '_', '-', sanitize_key( $tag ) );
	}

	private function default_attrs_for_config( array $config ): array {
		$defaults = array();

		foreach ( $config['fields'] ?? array() as $field ) {
			if ( isset( $field['key'] ) ) {
				$defaults[ $field['key'] ] = $field['default'] ?? '';
			}
		}

		return $defaults;
	}

	private function sanitize_shortcode_attrs( array $config, array $attrs ): array {
		$out = array();

		foreach ( $config['fields'] ?? array() as $field ) {
			if ( empty( $field['key'] ) ) {
				continue;
			}

			$key   = sanitize_key( (string) $field['key'] );
			$type  = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			$value = $attrs[ $key ] ?? ( $field['default'] ?? '' );

			if ( 'number' === $type ) {
				$out[ $key ] = ! empty( $field['allowNegative'] ) ? (string) intval( $value ) : (string) absint( $value );
				continue;
			}

			if ( 'toggle' === $type ) {
				$out[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';
				continue;
			}

			if ( 'url' === $type ) {
				$out[ $key ] = esc_url_raw( (string) $value );
				continue;
			}

			if ( 'select' === $type ) {
				$allowed = array();
				foreach ( $field['options'] ?? array() as $option ) {
					if ( isset( $option['value'] ) ) {
						$allowed[] = (string) $option['value'];
					}
				}
				$value = sanitize_key( (string) $value );
				$out[ $key ] = in_array( $value, $allowed, true ) ? $value : (string) ( $field['default'] ?? '' );
				continue;
			}

			if ( 'textarea' === $type ) {
				$out[ $key ] = sanitize_textarea_field( (string) $value );
				continue;
			}

			$out[ $key ] = sanitize_text_field( (string) $value );
		}

		return $out;
	}

	private function parse_extra_attrs( $raw ): array {
		$raw = trim( wp_strip_all_tags( (string) $raw ) );
		if ( '' === $raw ) {
			return array();
		}

		$parsed = shortcode_parse_atts( $raw );
		if ( ! is_array( $parsed ) ) {
			return array();
		}

		$out = array();
		foreach ( $parsed as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $key ) ) {
				continue;
			}

			$out[ sanitize_key( $key ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}

		return $out;
	}

	private function build_shortcode( string $tag, array $attrs, string $content = '', bool $has_content = false ): string {
		$parts = array();

		foreach ( $attrs as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || '' === (string) $value ) {
				continue;
			}
			$parts[] = $key . '="' . esc_attr( (string) $value ) . '"';
		}

		$attr_string = $parts ? ' ' . implode( ' ', $parts ) : '';

		if ( $has_content ) {
			return '[' . $tag . $attr_string . ']' . $content . '[/' . $tag . ']';
		}

		return '[' . $tag . $attr_string . ']';
	}

	private function get_registry(): array {
		$text = function ( string $key, string $label, string $default = '' ): array {
			return array( 'key' => $key, 'label' => $label, 'type' => 'text', 'default' => $default );
		};
		$textarea = function ( string $key, string $label, string $default = '' ): array {
			return array( 'key' => $key, 'label' => $label, 'type' => 'textarea', 'default' => $default );
		};
		$url = function ( string $key, string $label, string $default = '' ): array {
			return array( 'key' => $key, 'label' => $label, 'type' => 'url', 'default' => $default );
		};
		$number = function ( string $key, string $label, string $default = '0', bool $allow_negative = false ): array {
			return array( 'key' => $key, 'label' => $label, 'type' => 'number', 'default' => $default, 'allowNegative' => $allow_negative );
		};
		$toggle = function ( string $key, string $label, string $default = '1' ): array {
			return array( 'key' => $key, 'label' => $label, 'type' => 'toggle', 'default' => $default );
		};
		$select = function ( string $key, string $label, string $default, array $options ): array {
			return array( 'key' => $key, 'label' => $label, 'type' => 'select', 'default' => $default, 'options' => $options );
		};
		$yes_no = array(
			array( 'label' => __( 'Si', 'atora-lms' ), 'value' => 'yes' ),
			array( 'label' => __( 'No', 'atora-lms' ), 'value' => 'no' ),
		);

		$catalog_type_options = array(
			array( 'label' => __( 'Todos', 'atora-lms' ), 'value' => 'all' ),
			array( 'label' => __( 'Cursos', 'atora-lms' ), 'value' => 'course' ),
			array( 'label' => __( 'Programas', 'atora-lms' ), 'value' => 'program' ),
		);

		if ( post_type_exists( 'product' ) ) {
			$catalog_type_options[] = array( 'label' => __( 'Productos', 'atora-lms' ), 'value' => 'product' );
		}

		$catalog_level_options = array(
			array( 'label' => __( 'Todos', 'atora-lms' ), 'value' => '' ),
		);

		if ( taxonomy_exists( 'lm_course_level' ) ) {
			$level_terms = get_terms(
				array(
					'taxonomy'   => 'lm_course_level',
					'hide_empty' => false,
				)
			);

			if ( ! is_wp_error( $level_terms ) && is_array( $level_terms ) ) {
				foreach ( $level_terms as $level_term ) {
					if ( empty( $level_term->slug ) ) {
						continue;
					}
					$catalog_level_options[] = array(
						'label' => (string) $level_term->name,
						'value' => (string) $level_term->slug,
					);
				}
			}
		}

		return array(
			'clms_dashboard' => array(
				'title'       => __( 'ATORA: Panel de estudiante', 'atora-lms' ),
				'description' => __( 'Renderiza el dashboard del estudiante.', 'atora-lms' ),
				'icon'        => 'dashboard',
				'fields'      => array(),
			),
			'clms_teacher_dashboard' => array(
				'title'       => __( 'ATORA Shortcode: Panel docente', 'atora-lms' ),
				'description' => __( 'Renderiza el dashboard docente.', 'atora-lms' ),
				'icon'        => 'welcome-learn-more',
				'fields'      => array(),
			),
			'clms_course_list' => array(
				'title'       => __( 'ATORA: Lista de cursos', 'atora-lms' ),
				'description' => __( 'Muestra tarjetas de cursos con imagen, resumen, metadatos y CTA.', 'atora-lms' ),
				'icon'        => 'list-view',
				'fields'      => array(
					$number( 'limit', __( 'Limite', 'atora-lms' ), '-1', true ),
					$number( 'columns', __( 'Columnas (desktop)', 'atora-lms' ), '' ),
				),
			),
			'clms_courses' => array(
				'title'       => __( 'ATORA Shortcode: Cursos', 'atora-lms' ),
				'description' => __( 'Alias de la lista de cursos.', 'atora-lms' ),
				'icon'        => 'welcome-learn-more',
				'fields'      => array(
					$number( 'limit', __( 'Limite', 'atora-lms' ), '-1', true ),
					$number( 'columns', __( 'Columnas (desktop)', 'atora-lms' ), '' ),
				),
			),
			'clms_lesson_list' => array(
				'title'       => __( 'ATORA Shortcode: Lecciones del curso', 'atora-lms' ),
				'description' => __( 'Lista las lecciones de un curso.', 'atora-lms' ),
				'icon'        => 'list-view',
				'fields'      => array( $number( 'course_id', __( 'ID de curso', 'atora-lms' ) ) ),
			),
			'clms_course_lessons' => array(
				'title'       => __( 'ATORA Shortcode: Lecciones', 'atora-lms' ),
				'description' => __( 'Alias de lecciones del curso.', 'atora-lms' ),
				'icon'        => 'list-view',
				'fields'      => array( $number( 'course_id', __( 'ID de curso', 'atora-lms' ) ) ),
			),
			'clms_lessons' => array(
				'title'       => __( 'ATORA Shortcode: Todas las lecciones', 'atora-lms' ),
				'description' => __( 'Alias de lecciones del curso.', 'atora-lms' ),
				'icon'        => 'list-view',
				'fields'      => array( $number( 'course_id', __( 'ID de curso', 'atora-lms' ) ) ),
			),
			'clms_my_courses' => array(
				'title'       => __( 'ATORA: Mis cursos', 'atora-lms' ),
				'description' => __( 'Muestra cursos inscritos del usuario.', 'atora-lms' ),
				'icon'        => 'portfolio',
				'fields'      => array(),
			),
			'clms_catalog' => array(
				'title'       => __( 'ATORA: Catalogo', 'atora-lms' ),
				'description' => __( 'Catalogo unificado de cursos, programas y productos.', 'atora-lms' ),
				'icon'        => 'screenoptions',
				'fields'      => array(
					$number( 'per_page', __( 'Items por pagina', 'atora-lms' ), '12', true ),
					$select( 'type', __( 'Tipo', 'atora-lms' ), 'all', $catalog_type_options ),
					$select( 'level', __( 'Nivel', 'atora-lms' ), '', $catalog_level_options ),
					$number( 'columns', __( 'Columnas (desktop)', 'atora-lms' ), '' ),
					$toggle( 'filters', __( 'Filtros en frontend', 'atora-lms' ), '0' ),
					$toggle( 'recommendations', __( 'Mostrar recomendaciones', 'atora-lms' ) ),
					$number( 'recommendations_limit', __( 'Limite de recomendaciones', 'atora-lms' ), '3' ),
					$text( 'recommendations_context_type', __( 'Tipo de contexto', 'atora-lms' ) ),
					$number( 'recommendations_context_id', __( 'ID de contexto', 'atora-lms' ) ),
				),
			),
			'clms_marketplace' => array(
				'title'       => __( 'ATORA Shortcode: Marketplace', 'atora-lms' ),
				'description' => __( 'Alias del catalogo comercial.', 'atora-lms' ),
				'icon'        => 'store',
				'fields'      => array(
					$number( 'per_page', __( 'Items por pagina', 'atora-lms' ), '12', true ),
					$select( 'type', __( 'Tipo', 'atora-lms' ), 'all', $catalog_type_options ),
					$select( 'level', __( 'Nivel', 'atora-lms' ), '', $catalog_level_options ),
					$number( 'columns', __( 'Columnas (desktop)', 'atora-lms' ), '' ),
					$toggle( 'filters', __( 'Filtros en frontend', 'atora-lms' ), '0' ),
					$toggle( 'recommendations', __( 'Mostrar recomendaciones', 'atora-lms' ) ),
				),
			),
			'clms_lesson_panel' => array(
				'title'       => __( 'ATORA Shortcode: Panel de leccion', 'atora-lms' ),
				'description' => __( 'Panel de progreso, quiz y entrega de leccion.', 'atora-lms' ),
				'icon'        => 'editor-table',
				'fields'      => array(
					$number( 'lesson_id', __( 'ID de leccion', 'atora-lms' ) ),
					$toggle( 'show_quiz', __( 'Mostrar quiz', 'atora-lms' ) ),
					$toggle( 'show_submission', __( 'Mostrar entrega', 'atora-lms' ) ),
					$toggle( 'show_progress', __( 'Mostrar progreso', 'atora-lms' ) ),
					$toggle( 'show_course_link', __( 'Mostrar enlace al curso', 'atora-lms' ) ),
				),
			),
			'clms_quiz' => array(
				'title'       => __( 'ATORA Shortcode: Quiz', 'atora-lms' ),
				'description' => __( 'Evaluacion de una leccion.', 'atora-lms' ),
				'icon'        => 'forms',
				'fields'      => array(
					$number( 'lesson_id', __( 'ID de leccion', 'atora-lms' ) ),
					$toggle( 'show_result', __( 'Mostrar resultado', 'atora-lms' ) ),
					$toggle( 'show_feedback', __( 'Mostrar feedback', 'atora-lms' ) ),
					$toggle( 'allow_retry', __( 'Permitir reintento', 'atora-lms' ) ),
				),
			),
			'clms_submission_form' => array(
				'title'       => __( 'ATORA Shortcode: Entrega', 'atora-lms' ),
				'description' => __( 'Formulario de entrega de tarea.', 'atora-lms' ),
				'icon'        => 'upload',
				'fields'      => array( $number( 'lesson_id', __( 'ID de leccion', 'atora-lms' ) ) ),
			),
			'clms_speedgrade' => array(
				'title'       => __( 'ATORA Shortcode: SpeedGrade', 'atora-lms' ),
				'description' => __( 'Pantalla de calificacion rapida.', 'atora-lms' ),
				'icon'        => 'clipboard',
				'fields'      => array( $number( 'submission_id', __( 'ID de entrega', 'atora-lms' ) ) ),
			),
			'clms_grade_breakdown' => array(
				'title'       => __( 'ATORA Shortcode: Calificaciones', 'atora-lms' ),
				'description' => __( 'Desglose de calificaciones por curso.', 'atora-lms' ),
				'icon'        => 'chart-bar',
				'fields'      => array( $number( 'course_id', __( 'ID de curso', 'atora-lms' ) ), $number( 'student_id', __( 'ID de estudiante', 'atora-lms' ) ) ),
			),
			'clms_streak' => array(
				'title'       => __( 'ATORA Shortcode: Racha', 'atora-lms' ),
				'description' => __( 'Racha de aprendizaje del usuario.', 'atora-lms' ),
				'icon'        => 'star-filled',
				'fields'      => array( $number( 'user_id', __( 'ID de usuario', 'atora-lms' ) ) ),
			),
			'clms_certificate' => array(
				'title'       => __( 'ATORA Shortcode: Certificado', 'atora-lms' ),
				'description' => __( 'Certificado de un curso.', 'atora-lms' ),
				'icon'        => 'awards',
				'fields'      => array( $number( 'course_id', __( 'ID de curso', 'atora-lms' ) ) ),
			),
			'clms_my_certificates' => array(
				'title'       => __( 'ATORA Shortcode: Mis certificados', 'atora-lms' ),
				'description' => __( 'Certificados del estudiante.', 'atora-lms' ),
				'icon'        => 'awards',
				'fields'      => array(),
			),
			'clms_verify_certificate' => array(
				'title'       => __( 'ATORA Shortcode: Verificar certificado', 'atora-lms' ),
				'description' => __( 'Formulario o resultado de verificacion publica.', 'atora-lms' ),
				'icon'        => 'search',
				'fields'      => array( $text( 'code', __( 'Codigo', 'atora-lms' ) ) ),
			),
			'clms_student_profile' => array(
				'title'       => __( 'ATORA Shortcode: Perfil de estudiante', 'atora-lms' ),
				'description' => __( 'Perfil publico o privado de estudiante.', 'atora-lms' ),
				'icon'        => 'id',
				'fields'      => array( $number( 'user_id', __( 'ID de usuario', 'atora-lms' ) ) ),
			),
			'clms_my_profile' => array(
				'title'       => __( 'ATORA Shortcode: Mi perfil', 'atora-lms' ),
				'description' => __( 'Perfil del usuario actual.', 'atora-lms' ),
				'icon'        => 'admin-users',
				'fields'      => array(),
			),
			'clms_my_learning_profile' => array(
				'title'       => __( 'ATORA Shortcode: Perfil de aprendizaje', 'atora-lms' ),
				'description' => __( 'Perfil de aprendizaje por curso.', 'atora-lms' ),
				'icon'        => 'welcome-learn-more',
				'fields'      => array( $number( 'course_id', __( 'ID de curso', 'atora-lms' ) ) ),
			),
			'clms_learning_path' => array(
				'title'       => __( 'ATORA Shortcode: Ruta de aprendizaje', 'atora-lms' ),
				'description' => __( 'Ruta personalizada de aprendizaje.', 'atora-lms' ),
				'icon'        => 'networking',
				'fields'      => array( $number( 'course_id', __( 'ID de curso', 'atora-lms' ) ) ),
			),
			'clms_student_chat' => array(
				'title'       => __( 'ATORA Shortcode: Chat estudiante', 'atora-lms' ),
				'description' => __( 'Asistente AI para modo academico o ventas.', 'atora-lms' ),
				'icon'        => 'format-chat',
				'fields'      => array(
					$number( 'course_id', __( 'ID de curso', 'atora-lms' ) ),
					$select( 'mode', __( 'Modo', 'atora-lms' ), 'auto', array(
						array( 'label' => __( 'Automatico', 'atora-lms' ), 'value' => 'auto' ),
						array( 'label' => __( 'Academico', 'atora-lms' ), 'value' => 'academic' ),
						array( 'label' => __( 'Ventas', 'atora-lms' ), 'value' => 'sales' ),
					) ),
				),
			),
			'clms_course_instructor' => array(
				'title'       => __( 'ATORA: Docente del curso', 'atora-lms' ),
				'description' => __( 'Caja del docente asociado al curso.', 'atora-lms' ),
				'icon'        => 'businessperson',
				'fields'      => array(
					$number( 'course_id', __( 'ID de curso', 'atora-lms' ) ),
					$select( 'show_courses', __( 'Mostrar cursos', 'atora-lms' ), 'yes', $yes_no ),
					$number( 'courses_limit', __( 'Limite de cursos', 'atora-lms' ), '4' ),
					$select( 'show_bio', __( 'Mostrar bio', 'atora-lms' ), 'yes', $yes_no ),
					$select( 'show_socials', __( 'Mostrar redes', 'atora-lms' ), 'yes', $yes_no ),
					$select( 'show_meta', __( 'Mostrar meta', 'atora-lms' ), 'yes', $yes_no ),
				),
			),
			'clms_instructor_profile' => array(
				'title'       => __( 'ATORA: Perfil docente', 'atora-lms' ),
				'description' => __( 'Perfil de un instructor.', 'atora-lms' ),
				'icon'        => 'businessperson',
				'fields'      => array(
					$number( 'user_id', __( 'ID de usuario', 'atora-lms' ) ),
					$select( 'show_courses', __( 'Mostrar cursos', 'atora-lms' ), 'yes', $yes_no ),
					$number( 'courses_limit', __( 'Limite de cursos', 'atora-lms' ), '6' ),
					$select( 'show_bio', __( 'Mostrar bio', 'atora-lms' ), 'yes', $yes_no ),
					$select( 'show_socials', __( 'Mostrar redes', 'atora-lms' ), 'yes', $yes_no ),
					$select( 'show_meta', __( 'Mostrar meta', 'atora-lms' ), 'yes', $yes_no ),
				),
			),
			'clms_teachers' => array(
				'title'       => __( 'ATORA: Docentes', 'atora-lms' ),
				'description' => __( 'Grid de docentes.', 'atora-lms' ),
				'icon'        => 'groups',
				'fields'      => array(
					$number( 'limit', __( 'Limite', 'atora-lms' ), '12' ),
					$number( 'columns', __( 'Columnas', 'atora-lms' ), '3' ),
					$select( 'show_bio', __( 'Mostrar bio', 'atora-lms' ), 'yes', $yes_no ),
					$select( 'show_achievements', __( 'Mostrar logros', 'atora-lms' ), 'yes', $yes_no ),
					$select( 'show_socials', __( 'Mostrar redes', 'atora-lms' ), 'yes', $yes_no ),
					$select( 'show_profile_link', __( 'Mostrar enlace al perfil', 'atora-lms' ), 'yes', $yes_no ),
					$text( 'ids', __( 'IDs especificos', 'atora-lms' ) ),
				),
			),
			'clms_login_form' => array(
				'title'       => __( 'ATORA: Login', 'atora-lms' ),
				'description' => __( 'Formulario de inicio de sesion.', 'atora-lms' ),
				'icon'        => 'lock',
				'fields'      => array(
					$url( 'redirect', __( 'URL de redireccion', 'atora-lms' ) ),
					$text( 'logged_in_msg', __( 'Mensaje con sesion iniciada', 'atora-lms' ), __( 'Hola, {name}. Ya tienes sesion iniciada.', 'atora-lms' ) ),
					$url( 'dashboard_url', __( 'URL del panel', 'atora-lms' ) ),
					$text( 'dashboard_label', __( 'Etiqueta del panel', 'atora-lms' ), __( 'Ir a mi panel', 'atora-lms' ) ),
					$text( 'logout_label', __( 'Etiqueta de salida', 'atora-lms' ), __( 'Cerrar sesion', 'atora-lms' ) ),
				),
			),
			'ac_lms_auth' => array(
				'title'       => __( 'ATORA Shortcode: Login legacy', 'atora-lms' ),
				'description' => __( 'Alias legacy del formulario de login.', 'atora-lms' ),
				'icon'        => 'lock',
				'fields'      => array( $url( 'redirect', __( 'URL de redireccion', 'atora-lms' ) ) ),
			),
			'clms_logout' => array(
				'title'       => __( 'ATORA Shortcode: Logout', 'atora-lms' ),
				'description' => __( 'Boton de cierre de sesion.', 'atora-lms' ),
				'icon'        => 'exit',
				'fields'      => array( $text( 'label', __( 'Etiqueta', 'atora-lms' ), __( 'Cerrar sesion', 'atora-lms' ) ), $url( 'redirect', __( 'URL de redireccion', 'atora-lms' ) ) ),
			),
			'clms_auth_button' => array(
				'title'       => __( 'ATORA: Boton de acceso', 'atora-lms' ),
				'description' => __( 'Boton de ingreso o salida segun estado del usuario.', 'atora-lms' ),
				'icon'        => 'button',
				'fields'      => array(
					$text( 'login_label', __( 'Etiqueta login', 'atora-lms' ), __( 'Ingresar', 'atora-lms' ) ),
					$url( 'login_url', __( 'URL login', 'atora-lms' ) ),
					$text( 'logout_label', __( 'Etiqueta logout', 'atora-lms' ), __( 'Cerrar sesion', 'atora-lms' ) ),
					$url( 'logout_redirect', __( 'Redireccion logout', 'atora-lms' ) ),
					$text( 'class', __( 'Clase CSS', 'atora-lms' ), 'clms-auth-btn' ),
				),
			),
			'clms_user_nav' => array(
				'title'       => __( 'ATORA: Navegacion de usuario', 'atora-lms' ),
				'description' => __( 'Menu de usuario sensible al rol.', 'atora-lms' ),
				'icon'        => 'menu',
				'fields'      => array(
					$url( 'courses_url', __( 'URL de cursos', 'atora-lms' ), home_url( '/cursos/' ) ),
					$url( 'login_url', __( 'URL login', 'atora-lms' ) ),
					$url( 'register_url', __( 'URL registro', 'atora-lms' ) ),
					$text( 'class', __( 'Clase CSS', 'atora-lms' ) ),
				),
			),
			'clms_if' => array(
				'title'       => __( 'ATORA Shortcode: Condicional por rol', 'atora-lms' ),
				'description' => __( 'Muestra contenido solo para roles o estados definidos.', 'atora-lms' ),
				'icon'        => 'visibility',
				'has_content' => true,
				'fields'      => array( $text( 'role', __( 'Roles o estados', 'atora-lms' ), 'logged-in' ) ),
			),
			'atora_form' => array(
				'title'       => __( 'ATORA: Formulario', 'atora-lms' ),
				'description' => __( 'Formulario creado en ATORA.', 'atora-lms' ),
				'icon'        => 'feedback',
				'fields'      => array( $number( 'id', __( 'ID de formulario', 'atora-lms' ) ) ),
			),
			'atora_newsletter_archive' => array(
				'title'       => __( 'ATORA Shortcode: Archivo newsletter', 'atora-lms' ),
				'description' => __( 'Archivo publico de newsletters.', 'atora-lms' ),
				'icon'        => 'email-alt',
				'fields'      => array(),
			),
			'atora_affiliate_dashboard' => array(
				'title'       => __( 'ATORA Shortcode: Dashboard afiliado', 'atora-lms' ),
				'description' => __( 'Panel publico del afiliado.', 'atora-lms' ),
				'icon'        => 'money-alt',
				'fields'      => array(),
			),
			'atora_affiliate_apply' => array(
				'title'       => __( 'ATORA Shortcode: Solicitud afiliado', 'atora-lms' ),
				'description' => __( 'Formulario de solicitud de afiliado.', 'atora-lms' ),
				'icon'        => 'forms',
				'fields'      => array(),
			),
			'atora_calendar' => array(
				'title'       => __( 'ATORA Shortcode: Calendario', 'atora-lms' ),
				'description' => __( 'Calendario academico.', 'atora-lms' ),
				'icon'        => 'calendar-alt',
				'fields'      => array(
					$number( 'course_id', __( 'ID de curso', 'atora-lms' ) ),
					$text( 'view', __( 'Vista inicial', 'atora-lms' ), 'dayGridMonth' ),
				),
			),
			'atora_product_purchase_box' => array(
				'title'       => __( 'ATORA: Compra de producto', 'atora-lms' ),
				'description' => __( 'Caja de compra para producto WooCommerce.', 'atora-lms' ),
				'icon'        => 'cart',
				'fields'      => array( $number( 'id', __( 'ID de producto', 'atora-lms' ) ) ),
			),
			'clms_peer_review_inbox' => array(
				'title'       => __( 'ATORA Shortcode: Revision entre pares', 'atora-lms' ),
				'description' => __( 'Bandeja de revisiones pendientes.', 'atora-lms' ),
				'icon'        => 'groups',
				'fields'      => array(),
			),
			'clms_peer_review_training' => array(
				'title'       => __( 'ATORA Shortcode: Entrenamiento peer review', 'atora-lms' ),
				'description' => __( 'Modulo de entrenamiento para revision entre pares.', 'atora-lms' ),
				'icon'        => 'welcome-learn-more',
				'fields'      => array(),
			),
			'clms_whisper_recorder' => array(
				'title'       => __( 'ATORA Shortcode: Grabadora Whisper', 'atora-lms' ),
				'description' => __( 'Grabadora y transcripcion de voz.', 'atora-lms' ),
				'icon'        => 'microphone',
				'fields'      => array(
					$number( 'post_id', __( 'ID de post', 'atora-lms' ) ),
					$text( 'field', __( 'Campo meta', 'atora-lms' ), '_clms_transcript' ),
					$text( 'language', __( 'Idioma', 'atora-lms' ), 'es' ),
					$text( 'label', __( 'Etiqueta', 'atora-lms' ), __( 'Grabar nota de voz', 'atora-lms' ) ),
				),
			),
		);
	}
}
