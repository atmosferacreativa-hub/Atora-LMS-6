<?php
/**
 * CLMS_UI_Admin_Builder
 *
 * Metabox "Diseño de página" para cursos, programas y lecciones.
 * Permite al editor reordenar secciones (drag-and-drop), activar/desactivar,
 * elegir variante visual por sección, cambiar tema y escoger una plantilla
 * desde una galería visual antes de aplicar la base.
 *
 * ── Flujo de datos ────────────────────────────────────────────────────────────
 *   1. RENDER: carga schema fusionado (default + override de post meta).
 *   2. El usuario interactúa → JS (template-builder.js) actualiza hidden inputs.
 *   3. ON save_post: PHP valida nonce → lee inputs → llama al repositorio.
 *      - Input con JSON → save_post_override() guarda en _clms_ui_schema_{context}.
 *      - Input vacío   → delete_post_override() elimina el override (vuelve al default).
 *
 * ── Contextos soportados ─────────────────────────────────────────────────────
 *   lm_course:   course_commercial + course_overview
 *   lm_program:  program_commercial + program_overview
 *   lm_lesson:   lesson
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Admin_Builder {

	/** Post types soportados y sus contextos de builder. */
	private const POST_TYPE_CONTEXTS = array(
		'lm_course'   => array(
			array( 'context' => 'course_commercial', 'label_key' => 'Página comercial' ),
			array( 'context' => 'course_overview',   'label_key' => 'Vista de alumno' ),
		),
		'lm_program'  => array(
			array( 'context' => 'program_commercial', 'label_key' => 'Página del programa' ),
			array( 'context' => 'program_overview',   'label_key' => 'Vista de alumno' ),
		),
		'lm_lesson'   => array(
			array( 'context' => 'lesson', 'label_key' => 'Layout de lección' ),
		),
	);

	/** Límites configurables por contexto. */
	private const LIMITS_CONFIG = array(
		'course_commercial' => array(
			'lesson_preview'    => array( 'label_key' => 'Vista previa de lecciones (0 = todas)', 'default' => 6 ),
			'related_items'     => array( 'label_key' => 'Elementos relacionados (0 = todos)',    'default' => 6 ),
			'gallery_items'     => array( 'label_key' => 'Imágenes de galería (0 = todas)',        'default' => 0 ),
			'testimonial_items' => array( 'label_key' => 'Testimonios (0 = todos)',                'default' => 0 ),
		),
		'program_commercial' => array(
			'related_items' => array( 'label_key' => 'Elementos relacionados (0 = todos)', 'default' => 6 ),
		),
		'course_overview' => array(
			'gallery_items'     => array( 'label_key' => 'Imágenes de galería (0 = todas)', 'default' => 0 ),
			'testimonial_items' => array( 'label_key' => 'Testimonios (0 = todos)',          'default' => 0 ),
		),
	);

	/** Temas disponibles. */
	private const THEMES = array(
		'light' => 'Claro',
		'dark'  => 'Oscuro',
		'auto'  => 'Automático',
	);

	/** Límites defensivos para evitar payloads de schema desproporcionados. */
	private const MAX_SCHEMA_JSON_BYTES   = 180000;
	private const MAX_SCHEMA_DEPTH        = 10;
	private const MAX_SCHEMA_NODE_COUNT   = 2000;
	private const MAX_SCHEMA_STRING_CHARS = 2048;

	/** Claves permitidas en el schema persistido por metabox. */
	private const ALLOWED_SCHEMA_KEYS = array(
		'version',
		'sections',
		'limits',
		'variant',
		'theme',
		'preset',
		'props',
	);

	public function __construct() {
		add_action( 'add_meta_boxes',        array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post',             array( $this, 'save_schema' ), 15, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	// ── Metaboxes ──────────────────────────────────────────────────────────────

	public function add_meta_boxes(): void {
		foreach ( array_keys( self::POST_TYPE_CONTEXTS ) as $post_type ) {
			add_meta_box(
				'clms_ui_layout_' . $post_type,
				__( 'Ajustes avanzados de diseño', 'atora-lms' ),
				array( $this, 'render_builder_metabox' ),
				$post_type,
				'normal',
				'low'
			);
		}
	}

	/**
	 * Callback unificado: detecta el post type y llama a render_builder().
	 */
	public function render_builder_metabox( WP_Post $post ): void {
		if ( ! $this->modules_available() ) {
			echo '<p class="description">' . esc_html__( 'El motor de UI no está disponible.', 'atora-lms' ) . '</p>';
			return;
		}

		$tabs = self::POST_TYPE_CONTEXTS[ $post->post_type ] ?? array();
		if ( empty( $tabs ) ) {
			return;
		}

		echo '<p class="description" style="margin:0 0 12px;">'
			. esc_html__( 'Recomendado: elige una plantilla desde la galería visual. Usa este panel para ajustar orden, límites y variantes avanzadas.', 'atora-lms' )
			. '</p>';

		$this->render_builder( $post->ID, $tabs );
	}

	// ── Enqueue ────────────────────────────────────────────────────────────────

	public function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! array_key_exists( $screen->post_type, self::POST_TYPE_CONTEXTS ) ) {
			return;
		}

		$post_type = $screen->post_type;
		$post_id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$version   = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : ( defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
		$assets    = defined( 'ATORA_LMS_ASSETS_URL' ) ? ATORA_LMS_ASSETS_URL
			: ( defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL . 'assets/' : '' );

		// Fallback estable de WordPress para drag & drop en admin.
		wp_enqueue_script( 'jquery-ui-sortable' );

		// Template builder CSS
		wp_enqueue_style(
			'atora-template-builder',
			$assets . 'css/template-builder.css',
			array(),
			$version
		);

		// Template builder JS
		wp_enqueue_script(
			'atora-template-builder',
			$assets . 'js/template-builder.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			$version,
			true
		);

		// Config para JS (solo si las clases UI están disponibles)
		if ( $this->modules_available() ) {
			wp_localize_script(
				'atora-template-builder',
				'ATORA_TB',
				$this->build_js_config( $post_id, $post_type )
			);
		}

		$screen_id  = isset( $screen->id ) ? (string) $screen->id : $post_type;
		$metabox_id = 'clms_ui_layout_' . $post_type;
		add_filter(
			'postbox_classes_' . $screen_id . '_' . $metabox_id,
			static function( array $classes ): array {
				if ( ! in_array( 'closed', $classes, true ) ) {
					$classes[] = 'closed';
				}
				return $classes;
			}
		);
	}

	// ── Save ───────────────────────────────────────────────────────────────────

	/**
	 * Guarda los schemas de UI en post meta tras verificar nonce y permisos.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public function save_schema( int $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( in_array( $post->post_status ?? '', array( 'auto-draft', 'inherit' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( empty( $_POST['clms_ui_layout_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['clms_ui_layout_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'clms_save_ui_layout_' . $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! array_key_exists( $post_type, self::POST_TYPE_CONTEXTS ) ) {
			return;
		}

		if ( ! class_exists( 'CLMS_UI_Schema_Repository', false ) ) {
			return;
		}

		$repo    = new CLMS_UI_Schema_Repository();
		$schemas = isset( $_POST['clms_ui_schema'] ) && is_array( $_POST['clms_ui_schema'] )
			? $_POST['clms_ui_schema'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		foreach ( $schemas as $context => $json ) {
			$context = sanitize_key( (string) $context );
			if ( ! $repo->is_valid_context( $context ) ) {
				continue;
			}

			$json = (string) wp_unslash( $json );

			if ( '' === trim( $json ) ) {
				$repo->delete_post_override( $post_id, $context );
				continue;
			}

			if ( strlen( $json ) > self::MAX_SCHEMA_JSON_BYTES ) {
				continue;
			}

			$decoded = json_decode( $json, true, self::MAX_SCHEMA_DEPTH );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$sanitized = $this->sanitize_schema_payload( $decoded );
			if ( empty( $sanitized ) || ! is_array( $sanitized ) ) {
				continue;
			}

			$repo->save_post_override( $post_id, $context, $sanitized );
		}
	}

	/**
	 * Sanitiza recursivamente el payload del schema enviado por el metabox.
	 * Mantiene sólo claves conocidas y aplica límites de profundidad/tamaño.
	 */
	private function sanitize_schema_payload( array $schema ): array {
		$state = array(
			'nodes' => 0,
		);

		$clean = array();
		foreach ( self::ALLOWED_SCHEMA_KEYS as $key ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				continue;
			}

			switch ( $key ) {
				case 'version':
					$clean['version'] = max( 1, absint( $schema['version'] ) );
					break;

				case 'sections':
					$clean['sections'] = $this->sanitize_schema_sections(
						is_array( $schema['sections'] ) ? $schema['sections'] : array(),
						$state
					);
					break;

				case 'limits':
					$clean['limits'] = $this->sanitize_schema_limits(
						is_array( $schema['limits'] ) ? $schema['limits'] : array()
					);
					break;

				case 'variant':
				case 'theme':
				case 'preset':
					$clean[ $key ] = sanitize_key( (string) $schema[ $key ] );
					break;

				case 'props':
					$clean['props'] = $this->sanitize_schema_value( $schema['props'], 1, $state );
					if ( ! is_array( $clean['props'] ) ) {
						unset( $clean['props'] );
					}
					break;
			}
		}

		return $clean;
	}

	/**
	 * Sanitiza secciones del schema preservando compatibilidad con strings legacy.
	 */
	private function sanitize_schema_sections( array $sections, array &$state ): array {
		$clean = array();

		foreach ( $sections as $entry ) {
			if ( count( $clean ) >= 150 || $state['nodes'] >= self::MAX_SCHEMA_NODE_COUNT ) {
				break;
			}

			if ( is_string( $entry ) ) {
				$id = sanitize_key( $entry );
				if ( '' !== $id ) {
					$clean[] = $id;
				}
				continue;
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$section_id = isset( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : '';
			if ( '' === $section_id ) {
				continue;
			}

			$section = array(
				'id'      => $section_id,
				'enabled' => ! isset( $entry['enabled'] ) ? true : (bool) $entry['enabled'],
			);

			if ( isset( $entry['variant'] ) ) {
				$section['variant'] = sanitize_key( (string) $entry['variant'] );
			}

			if ( isset( $entry['visibility'] ) ) {
				$section['visibility'] = sanitize_key( (string) $entry['visibility'] );
			}

			if ( isset( $entry['props'] ) ) {
				$props = $this->sanitize_schema_value( $entry['props'], 2, $state );
				if ( is_array( $props ) ) {
					$section['props'] = $props;
				}
			}

			$clean[] = $section;
		}

		return $clean;
	}

	/**
	 * Sanitiza el bloque "limits" como mapa key => int no negativo.
	 */
	private function sanitize_schema_limits( array $limits ): array {
		$clean = array();

		foreach ( $limits as $key => $value ) {
			$k = sanitize_key( (string) $key );
			if ( '' === $k ) {
				continue;
			}
			$clean[ $k ] = max( 0, min( 1000, absint( $value ) ) );
		}

		return $clean;
	}

	/**
	 * Sanitización defensiva recursiva para estructuras de props.
	 *
	 * @param mixed $value Valor a limpiar.
	 * @param int   $depth Profundidad actual.
	 * @param array $state Estado compartido de conteo de nodos.
	 * @return mixed
	 */
	private function sanitize_schema_value( $value, int $depth, array &$state ) {
		if ( $depth > self::MAX_SCHEMA_DEPTH || $state['nodes'] >= self::MAX_SCHEMA_NODE_COUNT ) {
			return null;
		}

		$state['nodes']++;

		if ( is_array( $value ) ) {
			$is_list = array_values( $value ) === $value;
			$clean   = array();

			foreach ( $value as $k => $v ) {
				if ( $state['nodes'] >= self::MAX_SCHEMA_NODE_COUNT ) {
					break;
				}

				$next = $this->sanitize_schema_value( $v, $depth + 1, $state );
				if ( null === $next ) {
					continue;
				}

				if ( $is_list ) {
					$clean[] = $next;
				} else {
					$key = sanitize_key( (string) $k );
					if ( '' === $key ) {
						continue;
					}
					$clean[ $key ] = $next;
				}
			}

			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$clean = sanitize_text_field( $value );
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $clean, 0, self::MAX_SCHEMA_STRING_CHARS );
			}
			return substr( $clean, 0, self::MAX_SCHEMA_STRING_CHARS );
		}

		return null;
	}

	// ── JS config builder ──────────────────────────────────────────────────────

	/**
	 * Construye el objeto de configuración que se pasa a template-builder.js
	 * vía wp_localize_script como window.ATORA_TB.
	 */
	private function build_js_config( int $post_id, string $post_type ): array {
		$tabs     = self::POST_TYPE_CONTEXTS[ $post_type ] ?? array();
		$ctx_list = array_column( $tabs, 'context' );

		// Presets indexados por contexto — el JS usa presetsByCtx[ctx][key]
		$presets_by_ctx = array();
		foreach ( $ctx_list as $ctx ) {
			$ctx_presets = CLMS_UI_Template_Presets::get_for_context( $ctx );
			$presets_by_ctx[ $ctx ] = array();
			foreach ( $ctx_presets as $key => $preset ) {
				$presets_by_ctx[ $ctx ][ $key ] = array(
					'label'            => $preset['label'],
					'description'      => $preset['description'] ?? '',
					'theme'            => $preset['theme'] ?? 'light',
					'sections'         => $preset['sections']         ?? array(),
					'sections_enabled' => $preset['sections_enabled'] ?? array(),
					'sections_order'   => $preset['sections_order']   ?? array(),
				);
			}
		}

		// Temas para JS
		$themes_js = array();
		foreach ( self::THEMES as $key => $label ) {
			$themes_js[ $key ] = __( $label, 'atora-lms' );
		}

		return array(
			'postId'       => $post_id,
			'ctxList'      => $ctx_list,
			'presetsByCtx' => $presets_by_ctx,
			'themes'       => $themes_js,
			'i18n'         => array(
				'¿Restablecer el diseño al valor por defecto del plugin?' =>
					__( '¿Restablecer el diseño al valor por defecto del plugin?', 'atora-lms' ),
				'Por defecto'   => __( 'Por defecto', 'atora-lms' ),
				'Personalizado' => __( 'Personalizado', 'atora-lms' ),
			),
		);
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	/**
	 * Renderiza el HTML del builder para un post dado y una lista de tabs/contextos.
	 *
	 * @param int   $post_id
	 * @param array $tabs    Array de [ 'context' => string, 'label_key' => string ]
	 */
	private function render_builder( int $post_id, array $tabs ): void {
		wp_nonce_field( 'clms_save_ui_layout_' . $post_id, 'clms_ui_layout_nonce' );

		$repo     = new CLMS_UI_Schema_Repository();
		$registry = CLMS_UI_Section_Registry::instance();

		// Construir datos de cada contexto
		$contexts_data = array();
		foreach ( $tabs as $tab ) {
			$context      = $tab['context'];
			$schema       = $repo->get_merged_schema( $post_id, $context );
			$has_override = null !== $repo->get_post_override( $post_id, $context );
			$ctx_sections = $registry->get_for_context( $context );
			$section_list = array();

			foreach ( $schema['sections'] as $entry ) {
				if ( empty( $entry['id'] ) ) {
					continue;
				}
				$id      = sanitize_key( (string) $entry['id'] );
				$defined = $ctx_sections[ $id ] ?? null;

				$section_list[] = array(
					'id'               => $id,
					'label'            => $defined
						? (string) $defined['label']
						: ucwords( str_replace( '_', ' ', $id ) ),
					'enabled'          => (bool) ( $entry['enabled'] ?? true ),
					'variant'          => (string) ( $entry['variant'] ?? 'default' ),
					'allowed_variants' => $defined
						? (array) ( $defined['allowed_variants'] ?? array( 'default' ) )
						: array( 'default' ),
					'visibility'       => (string) ( $entry['visibility'] ?? 'always' ),
				);
			}

			$limits_cfg    = self::LIMITS_CONFIG[ $context ] ?? array();
			$saved_limits  = (array) ( $schema['limits'] ?? array() );
			$limits_values = array();
			foreach ( $limits_cfg as $key => $def ) {
				$limits_values[ $key ] = isset( $saved_limits[ $key ] )
					? (int) $saved_limits[ $key ]
					: $def['default'];
			}

			$contexts_data[ $context ] = array(
				'tab_label'     => __( $tab['label_key'], 'atora-lms' ),
				'sections'      => $section_list,
				'has_override'  => $has_override,
				'theme'         => (string) ( $schema['theme'] ?? 'light' ),
				'preset'        => (string) ( $schema['preset'] ?? 'default' ),
				'presets'       => $this->build_preset_gallery_cards( $context, $ctx_sections, (string) ( $schema['preset'] ?? 'default' ) ),
				'limits_config' => $limits_cfg,
				'limits_values' => $limits_values,
			);
		}

		$first_ctx = array_key_first( $contexts_data );
		$multi_tab = count( $tabs ) > 1;
		?>
		<div class="atora-uib-wrap" id="atora-uib-<?php echo esc_attr( (string) $post_id ); ?>">

		<?php if ( $multi_tab ) : ?>
		<div class="atora-uib-tabs" role="tablist">
			<?php foreach ( $contexts_data as $ctx => $data ) : ?>
				<button type="button"
					class="atora-uib-tab<?php echo $ctx === $first_ctx ? ' is-active' : ''; ?>"
					data-ctx="<?php echo esc_attr( $ctx ); ?>"
					role="tab"
					aria-selected="<?php echo $ctx === $first_ctx ? 'true' : 'false'; ?>"
					aria-controls="atora-uib-panel-<?php echo esc_attr( $ctx ); ?>">
					<?php echo esc_html( $data['tab_label'] ); ?>
					<?php if ( $data['has_override'] ) : ?>
						<span class="atora-uib-badge"
							title="<?php esc_attr_e( 'Configuración personalizada activa', 'atora-lms' ); ?>"
							aria-label="<?php esc_attr_e( 'Personalizado', 'atora-lms' ); ?>">✎</span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<?php foreach ( $contexts_data as $ctx => $data ) : ?>
		<div class="atora-uib-panel<?php echo $ctx === $first_ctx ? ' is-active' : ''; ?>"
			id="atora-uib-panel-<?php echo esc_attr( $ctx ); ?>"
			data-panel="<?php echo esc_attr( $ctx ); ?>"
			role="tabpanel">

			<p class="atora-uib-desc">
				<?php esc_html_e( 'Arrastra para reordenar. Usa el checkbox para activar o desactivar cada sección.', 'atora-lms' ); ?>
				<?php if ( $data['has_override'] ) : ?>
					— <strong><?php esc_html_e( 'Configuración personalizada activa.', 'atora-lms' ); ?></strong>
				<?php endif; ?>
			</p>

			<div class="atora-uib-template-hub">
				<div class="atora-uib-template-hub__head">
					<p class="atora-uib-template-hub__eyebrow"><?php esc_html_e( 'Galería de plantillas', 'atora-lms' ); ?></p>
					<p class="atora-uib-template-hub__copy">
						<?php esc_html_e( 'Elige una base visual y luego ajusta secciones, tema y límites sin perder la estructura.', 'atora-lms' ); ?>
					</p>
				</div>

				<div class="atora-uib-template-gallery"
					data-atora-template-gallery="<?php echo esc_attr( $ctx ); ?>">
					<?php foreach ( $data['presets'] as $preset ) : ?>
						<label class="atora-uib-template-card<?php echo ! empty( $preset['active'] ) ? ' is-active' : ''; ?>"
							data-preset-card="<?php echo esc_attr( $preset['key'] ); ?>">
							<input
								type="radio"
								class="atora-uib-template-card__input"
								name="atora-uib-preset-<?php echo esc_attr( $ctx ); ?>"
								value="<?php echo esc_attr( $preset['key'] ); ?>"
								<?php checked( ! empty( $preset['active'] ) ); ?>
							>
							<span class="atora-uib-template-card__eyebrow"><?php esc_html_e( 'Plantilla', 'atora-lms' ); ?></span>
							<span class="atora-uib-template-card__title"><?php echo esc_html( $preset['label'] ); ?></span>
							<span class="atora-uib-template-card__desc">
								<?php echo esc_html( $preset['description'] ?: __( 'Base visual editable para empezar rápido.', 'atora-lms' ) ); ?>
							</span>
							<span class="atora-uib-template-card__meta">
								<strong>
									<?php
									echo esc_html(
										sprintf(
											_n( '%d sección', '%d secciones', (int) $preset['section_count'], 'atora-lms' ),
											(int) $preset['section_count']
										)
									);
									?>
								</strong>
								<span class="atora-uib-template-card__theme">
									<?php echo esc_html( $preset['theme_label'] ); ?>
								</span>
							</span>
							<span class="atora-uib-template-card__chips">
								<?php foreach ( array_slice( (array) $preset['section_labels'], 0, 3 ) as $section_label ) : ?>
									<span class="atora-uib-template-pill"><?php echo esc_html( $section_label ); ?></span>
								<?php endforeach; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>

				<p class="atora-uib-template-hub__foot">
					<?php esc_html_e( 'Pulsa "Aplicar plantilla" para cargar su base y luego personaliza cada sección desde Gutenberg o desde este panel.', 'atora-lms' ); ?>
				</p>
			</div>

			<input
				type="hidden"
				name="clms_ui_schema[<?php echo esc_attr( $ctx ); ?>]"
				id="atora-uib-schema-<?php echo esc_attr( $ctx ); ?>"
				value=""
			>

			<?php if ( empty( $data['sections'] ) ) : ?>
				<p class="atora-uib-empty"><?php esc_html_e( 'No hay secciones registradas para este contexto.', 'atora-lms' ); ?></p>
			<?php else : ?>
			<ul class="atora-uib-list"
				id="atora-uib-list-<?php echo esc_attr( $ctx ); ?>"
				aria-label="<?php esc_attr_e( 'Secciones de la página', 'atora-lms' ); ?>">

				<?php foreach ( $data['sections'] as $section ) :
					$item_class = 'atora-uib-item';
					if ( ! $section['enabled'] ) {
						$item_class .= ' is-disabled';
					}
					$toggle_id = 'atora-uib-toggle-' . $ctx . '-' . $section['id'];
					$has_variants = count( $section['allowed_variants'] ) > 1;
				?>
				<li class="<?php echo esc_attr( $item_class ); ?>"
					data-id="<?php echo esc_attr( $section['id'] ); ?>"
					data-visibility="<?php echo esc_attr( $section['visibility'] ); ?>">

					<span class="atora-uib-handle"
						title="<?php esc_attr_e( 'Arrastrar para reordenar', 'atora-lms' ); ?>"
						aria-hidden="true">&#9776;</span>

					<input
						type="checkbox"
						class="atora-uib-toggle"
						id="<?php echo esc_attr( $toggle_id ); ?>"
						<?php checked( $section['enabled'] ); ?>
						aria-label="<?php echo esc_attr( $section['label'] ); ?>"
					>

					<label class="atora-uib-label" for="<?php echo esc_attr( $toggle_id ); ?>">
						<?php echo esc_html( $section['label'] ); ?>
					</label>

					<?php if ( $has_variants ) : ?>
					<select
						class="atora-uib-variant-sel"
						aria-label="<?php esc_attr_e( 'Variante', 'atora-lms' ); ?>"
						<?php disabled( ! $section['enabled'] ); ?>
						title="<?php esc_attr_e( 'Variante visual', 'atora-lms' ); ?>"
					>
						<?php foreach ( $section['allowed_variants'] as $variant_id ) : ?>
							<option value="<?php echo esc_attr( $variant_id ); ?>"
								<?php selected( $section['variant'], $variant_id ); ?>>
								<?php echo esc_html( ucfirst( $variant_id ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php endif; ?>

				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>

			<?php if ( ! empty( $data['limits_config'] ) ) : ?>
			<div class="atora-uib-limits" id="atora-uib-limits-<?php echo esc_attr( $ctx ); ?>">
				<p class="atora-uib-limits-title"><?php esc_html_e( 'Límites de contenido', 'atora-lms' ); ?></p>
				<div class="atora-uib-limits-grid">
					<?php foreach ( $data['limits_config'] as $limit_key => $def ) : ?>
					<label class="atora-uib-limit-label">
						<?php echo esc_html( __( $def['label_key'], 'atora-lms' ) ); // phpcs:ignore WordPress.WP.I18n ?>
						<input
							type="number"
							min="0"
							step="1"
							class="atora-uib-limit-input small-text"
							data-ctx="<?php echo esc_attr( $ctx ); ?>"
							data-limit-key="<?php echo esc_attr( $limit_key ); ?>"
							value="<?php echo esc_attr( (string) ( $data['limits_values'][ $limit_key ] ?? $def['default'] ) ); ?>"
						>
					</label>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="atora-uib-footer">

				<!-- Tema -->
				<div class="atora-uib-footer-group">
					<span class="atora-uib-footer-label"><?php esc_html_e( 'Tema:', 'atora-lms' ); ?></span>
					<select
						class="atora-uib-footer-select"
						id="atora-uib-theme-<?php echo esc_attr( $ctx ); ?>"
						aria-label="<?php esc_attr_e( 'Tema visual', 'atora-lms' ); ?>">
						<?php foreach ( self::THEMES as $theme_key => $theme_label ) : ?>
							<option value="<?php echo esc_attr( $theme_key ); ?>"
								<?php selected( $data['theme'], $theme_key ); ?>>
								<?php echo esc_html( __( $theme_label, 'atora-lms' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="atora-uib-footer-group">
					<span class="atora-uib-footer-label"><?php esc_html_e( 'Plantilla:', 'atora-lms' ); ?></span>
					<button type="button"
						class="button button-primary atora-uib-btn atora-uib-apply-preset"
						data-ctx="<?php echo esc_attr( $ctx ); ?>">
						<?php esc_html_e( 'Aplicar plantilla', 'atora-lms' ); ?>
					</button>
				</div>

				<span class="atora-uib-spacer"></span>

				<!-- Restablecer -->
				<button type="button"
					class="button atora-uib-btn atora-uib-btn-danger atora-uib-reset"
					data-ctx="<?php echo esc_attr( $ctx ); ?>"
					title="<?php esc_attr_e( 'Eliminar la configuración personalizada y volver al diseño por defecto', 'atora-lms' ); ?>">
					<?php esc_html_e( 'Restablecer', 'atora-lms' ); ?>
				</button>

				<!-- Badge de estado -->
				<?php if ( $data['has_override'] ) : ?>
					<span class="atora-uib-badge" data-badge-ctx="<?php echo esc_attr( $ctx ); ?>">
						<?php esc_html_e( 'Personalizado', 'atora-lms' ); ?>
					</span>
				<?php else : ?>
					<span class="atora-uib-badge is-default" data-badge-ctx="<?php echo esc_attr( $ctx ); ?>">
						<?php esc_html_e( 'Por defecto', 'atora-lms' ); ?>
					</span>
				<?php endif; ?>

			</div><!-- .atora-uib-footer -->

		</div><!-- .atora-uib-panel -->
		<?php endforeach; ?>

		</div><!-- .atora-uib-wrap -->
		<?php
	}

	// ── Utilidades ─────────────────────────────────────────────────────────────

	private function modules_available(): bool {
		return class_exists( 'CLMS_UI_Schema_Repository', false )
			&& class_exists( 'CLMS_UI_Section_Registry', false )
			&& class_exists( 'CLMS_UI_Template_Migrator', false )
			&& class_exists( 'CLMS_UI_Template_Presets', false );
	}

	/**
	 * Devuelve la paleta de plantilla visual para una tarjeta del selector.
	 */
	private function build_preset_gallery_cards( string $context, array $ctx_sections, string $current_preset ): array {
		$cards = array();

		foreach ( CLMS_UI_Template_Presets::get_for_context( $context ) as $preset_key => $preset_cfg ) {
			$summary = $this->summarize_preset_sections( $preset_cfg, $ctx_sections );
			$theme   = sanitize_key( (string) ( $preset_cfg['theme'] ?? 'light' ) );

			$cards[] = array(
				'key'            => (string) $preset_key,
				'label'          => (string) ( $preset_cfg['label'] ?? $preset_key ),
				'description'    => (string) ( $preset_cfg['description'] ?? '' ),
				'theme'          => $theme,
				'theme_label'    => $this->get_theme_label( $theme ),
				'section_count'   => $summary['count'],
				'section_labels'  => $summary['labels'],
				'active'         => sanitize_key( $current_preset ) === sanitize_key( (string) $preset_key ),
			);
		}

		return $cards;
	}

	/**
	 * Resume qué secciones incluye una plantilla para mostrar un preview breve.
	 */
	private function summarize_preset_sections( array $preset, array $ctx_sections ): array {
		$has_explicit_definition = ! empty( $preset['sections'] ) || ! empty( $preset['sections_enabled'] ) || ! empty( $preset['sections_order'] );

		if ( ! $has_explicit_definition ) {
			return array(
				'count'  => count( $ctx_sections ),
				'labels' => array( __( 'Todas las secciones', 'atora-lms' ) ),
			);
		}

		$section_ids = array();
		if ( ! empty( $preset['sections'] ) && is_array( $preset['sections'] ) ) {
			foreach ( $preset['sections'] as $entry ) {
				$section_id = is_array( $entry ) ? sanitize_key( (string) ( $entry['id'] ?? '' ) ) : '';
				if ( $section_id ) {
					$section_ids[] = $section_id;
				}
			}
		} elseif ( ! empty( $preset['sections_order'] ) && is_array( $preset['sections_order'] ) ) {
			$section_ids = array_map( 'sanitize_key', $preset['sections_order'] );
		} elseif ( ! empty( $preset['sections_enabled'] ) && is_array( $preset['sections_enabled'] ) ) {
			$section_ids = array_map( 'sanitize_key', $preset['sections_enabled'] );
		}

		$section_ids = array_values( array_filter( array_unique( $section_ids ) ) );
		$labels      = array();

		foreach ( array_slice( $section_ids, 0, 4 ) as $section_id ) {
			$labels[] = isset( $ctx_sections[ $section_id ] )
				? (string) $ctx_sections[ $section_id ]['label']
				: ucwords( str_replace( '_', ' ', $section_id ) );
		}

		return array(
			'count'  => count( $section_ids ),
			'labels' => $labels,
		);
	}

	/**
	 * Traduce el nombre interno del tema de una plantilla a una etiqueta visible.
	 */
	private function get_theme_label( string $theme ): string {
		$theme = sanitize_key( $theme );
		$label = self::THEMES[ $theme ] ?? self::THEMES['light'];

		return (string) __( $label, 'atora-lms' );
	}
}
