<?php
/**
 * CLMS_UI_Template_Parts
 *
 * Template parts editables para header y footer.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Template_Parts {

	public const POST_TYPE = 'clms_template_part';

	private const META_AREA   = '_clms_template_part_area';
	private const META_STICKY = '_clms_template_part_sticky';
	private const AREAS       = array( 'header', 'footer' );

	/**
	 * Contexto de render activo para template parts (frontend).
	 *
	 * Se usa para que el bloque core/navigation dentro del header/footer editable
	 * herede el menu clasico configurado en Ajustes → Navegación (por rol),
	 * evitando que el template part dependa de wp_navigation (Block Menus).
	 */
	private static $rendering_area = '';

	/** @var array Atributos del bloque core/navigation actualmente renderizado. */
	private static $navigation_context = array();

	/** @var array Cache por-request de blocks parseados para menus clasicos. */
	private static $menu_blocks_cache = array();

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_template_part' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 30 );
		add_action( 'admin_post_clms_create_template_part', array( $this, 'handle_create_template_part' ) );
		add_action( 'admin_post_clms_activate_template_part', array( $this, 'handle_activate_template_part' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'filter_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'clear_active_option_on_delete' ) );

		// Interceptar el bloque core/navigation dentro de template parts
		// para inyectar menus clasicos por rol (Ajustes → Navegación).
		add_filter( 'render_block_data', array( $this, 'capture_navigation_block_context' ), 5, 3 );
		add_filter( 'block_core_navigation_render_inner_blocks', array( $this, 'filter_navigation_inner_blocks_for_template_parts' ), 20 );
	}

	/**
	 * Captura attrs del bloque core/navigation mientras se renderiza un template part.
	 *
	 * @param array $parsed_block
	 * @param array $source_block
	 * @param mixed $parent_block
	 * @return array
	 */
	public function capture_navigation_block_context( $parsed_block, $source_block = array(), $parent_block = null ) {
		unset( $source_block, $parent_block );

		if ( '' === self::$rendering_area || ! is_array( $parsed_block ) ) {
			return $parsed_block;
		}

		if ( 'core/navigation' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}

		self::$navigation_context = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] )
			? $parsed_block['attrs']
			: array();

		return $parsed_block;
	}

	/**
	 * Reemplaza los inner blocks del core/navigation por el menu clasico indicado
	 * en los ajustes de ATORA, manteniendo interactividad y responsive overlay.
	 *
	 * @param WP_Block_List $inner_blocks
	 * @return WP_Block_List
	 */
	public function filter_navigation_inner_blocks_for_template_parts( $inner_blocks ) {
		if ( '' === self::$rendering_area ) {
			return $inner_blocks;
		}

		if ( ! $inner_blocks instanceof WP_Block_List ) {
			return $inner_blocks;
		}

		if ( ! class_exists( 'CLMS_Settings' ) ) {
			return $inner_blocks;
		}

		$nav_settings = CLMS_Settings::get_navigation_settings();
		$menu_id      = self::resolve_menu_id_for_area( $nav_settings, self::$rendering_area );

		if ( $menu_id <= 0 ) {
			return $inner_blocks;
		}

		$cache_key = self::$rendering_area . ':' . $menu_id;
		$blocks    = self::$menu_blocks_cache[ $cache_key ] ?? null;

		if ( null === $blocks ) {
			$blocks = self::build_blocks_for_classic_menu( $menu_id );
			self::$menu_blocks_cache[ $cache_key ] = $blocks;
		}

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return $inner_blocks;
		}

		$context = is_array( self::$navigation_context ) ? self::$navigation_context : array();

		return new WP_Block_List( $blocks, $context );
	}

	private static function resolve_menu_id_for_area( array $nav_settings, string $area ): int {
		if ( 'footer' === $area ) {
			return absint( $nav_settings['footer_menu'] ?? 0 );
		}

		$role     = self::get_frontend_role_context();
		$menu_key = 'header_menu_' . $role;
		$menu_id  = absint( $nav_settings[ $menu_key ] ?? 0 );

		if ( $menu_id <= 0 ) {
			$menu_id = absint( $nav_settings['header_menu_guest'] ?? 0 );
		}

		return $menu_id;
	}

	private static function get_frontend_role_context(): string {
		if ( ! is_user_logged_in() ) {
			return 'guest';
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return 'guest';
		}

		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'clms_access_admin' ) ) {
			return 'admin';
		}

		if (
			user_can( $user, 'clms_view_teacher_dashboard' ) ||
			user_can( $user, 'clms_manage_courses' ) ||
			user_can( $user, 'clms_grade_submissions' )
		) {
			return 'instructor';
		}

		if (
			user_can( $user, 'clms_manage_commerce' ) ||
			user_can( $user, 'clms_manage_enrollments' ) ||
			user_can( $user, 'clms_manage_course_access' ) ||
			user_can( $user, 'clms_manage_enrollment_access' )
		) {
			return 'collaborator';
		}

		return 'student';
	}

	private static function build_blocks_for_classic_menu( int $menu_id ): array {
		if ( ! class_exists( 'WP_Classic_To_Block_Menu_Converter' ) ) {
			return array();
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu || is_wp_error( $menu ) ) {
			return array();
		}

		$markup = WP_Classic_To_Block_Menu_Converter::convert( $menu );
		if ( is_wp_error( $markup ) || '' === trim( (string) $markup ) ) {
			return array();
		}

		$parsed = parse_blocks( (string) $markup );
		if ( function_exists( 'block_core_navigation_filter_out_empty_blocks' ) ) {
			$parsed = block_core_navigation_filter_out_empty_blocks( $parsed );
		}

		return is_array( $parsed ) ? $parsed : array();
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Templates header/footer', 'atora-lms' ),
					'singular_name' => __( 'Template header/footer', 'atora-lms' ),
					'add_new_item'  => __( 'Crear template', 'atora-lms' ),
					'edit_item'     => __( 'Editar template', 'atora-lms' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'editor', 'revisions' ),
				'capability_type' => 'post',
				'map_meta_cap'    => false,
				'capabilities'    => array(
					'edit_post'          => 'edit_theme_options',
					'read_post'          => 'edit_theme_options',
					'delete_post'        => 'edit_theme_options',
					'edit_posts'         => 'edit_theme_options',
					'edit_others_posts'  => 'edit_theme_options',
					'delete_posts'       => 'edit_theme_options',
					'publish_posts'      => 'edit_theme_options',
					'read_private_posts' => 'edit_theme_options',
					'create_posts'       => 'edit_theme_options',
				),
			)
		);
	}

	public function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_AREA,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => 'header',
				'sanitize_callback' => array( $this, 'sanitize_area' ),
				'auth_callback'     => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STICKY,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => false,
				'auth_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
			)
		);
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'clms-template-part-settings',
			__( 'Area del template', 'atora-lms' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		$area   = $this->get_post_area( $post->ID );
		$active = self::get_active_template_id( $area ) === (int) $post->ID;
		$sticky = (bool) get_post_meta( $post->ID, self::META_STICKY, true );
		$status = get_post_status( $post );
		wp_nonce_field( 'clms_template_part_settings_' . $post->ID, 'clms_template_part_nonce' );
		?>
		<?php if ( 'publish' !== $status ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Para mostrarse en frontend este template debe estar publicado.', 'atora-lms' ); ?></p>
			</div>
		<?php endif; ?>
		<p>
			<label for="clms-template-part-area"><strong><?php esc_html_e( 'Area', 'atora-lms' ); ?></strong></label>
			<select id="clms-template-part-area" name="clms_template_part_area" style="width:100%;margin-top:6px;">
				<option value="header" <?php selected( $area, 'header' ); ?>><?php esc_html_e( 'Header', 'atora-lms' ); ?></option>
				<option value="footer" <?php selected( $area, 'footer' ); ?>><?php esc_html_e( 'Footer', 'atora-lms' ); ?></option>
			</select>
		</p>
		<?php if ( 'header' === $area ) : ?>
		<p style="margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb">
			<label>
				<input type="checkbox" name="clms_template_part_sticky" value="1" <?php checked( $sticky ); ?>>
				<strong><?php esc_html_e( 'Header fijo (sticky)', 'atora-lms' ); ?></strong>
			</label>
			<span class="description" style="display:block;margin-top:4px">
				<?php esc_html_e( 'El header permanece visible al hacer scroll. Si está desmarcado, el header desaparece al bajar la página.', 'atora-lms' ); ?>
			</span>
		</p>
		<?php endif; ?>
		<p style="margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb">
			<label>
				<input type="checkbox" name="clms_template_part_active" value="1" <?php checked( $active ); ?>>
				<?php esc_html_e( 'Usar como template activo del sitio', 'atora-lms' ); ?>
			</label>
		</p>
		<p>
			<button type="submit" class="button button-primary" name="clms_template_part_active" value="1">
				<?php echo esc_html( 'footer' === $area ? __( 'Guardar como footer activo', 'atora-lms' ) : __( 'Guardar como header activo', 'atora-lms' ) ); ?>
			</button>
		</p>
		<p class="description"><?php esc_html_e( 'Publica o actualiza el template para que el tema activo pueda renderizarlo.', 'atora-lms' ); ?></p>
		<?php
	}

	public function save_template_part( int $post_id, WP_Post $post, bool $update ): void {
		unset( $update );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$nonce = isset( $_POST['clms_template_part_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_template_part_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_template_part_settings_' . $post_id ) ) {
			return;
		}

		$previous_area = $this->get_post_area( $post_id );
		$area          = isset( $_POST['clms_template_part_area'] ) ? $this->sanitize_area( wp_unslash( $_POST['clms_template_part_area'] ) ) : $previous_area;
		update_post_meta( $post_id, self::META_AREA, $area );

		if ( 'header' === $area ) {
			$is_sticky = ! empty( $_POST['clms_template_part_sticky'] );
			update_post_meta( $post_id, self::META_STICKY, $is_sticky ? '1' : '0' );
		}

		if ( self::get_active_template_id( $previous_area ) === $post_id && $previous_area !== $area ) {
			delete_option( self::active_option_name( $previous_area ) );
		}

		$is_active = ! empty( $_POST['clms_template_part_active'] );
		if ( $is_active && 'publish' === get_post_status( $post_id ) ) {
			update_option( self::active_option_name( $area ), $post_id, false );
		} elseif ( self::get_active_template_id( $area ) === $post_id ) {
			delete_option( self::active_option_name( $area ) );
		}

		unset( $post );
	}

	public function register_admin_page(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Templates Header/Footer', 'atora-lms' ),
			__( 'Templates H/F', 'atora-lms' ),
			'edit_theme_options',
			'clms-template-parts',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para editar templates del sitio.', 'atora-lms' ) );
		}

		$templates = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Templates editables de header y footer', 'atora-lms' ); ?></h1>
			<p><?php esc_html_e( 'Crea un template, editalo con Gutenberg, publicalo y activalo para que el tema lo use.', 'atora-lms' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->create_url( 'header' ) ); ?>"><?php esc_html_e( 'Crear header editable', 'atora-lms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->create_url( 'footer' ) ); ?>"><?php esc_html_e( 'Crear footer editable', 'atora-lms' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>"><?php esc_html_e( 'Ver todos', 'atora-lms' ); ?></a>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Template', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Area', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Accion', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( $templates ) : ?>
						<?php foreach ( $templates as $template ) : ?>
							<?php $area = $this->get_post_area( $template->ID ); ?>
							<?php $status_object = get_post_status_object( get_post_status( $template ) ); ?>
							<tr>
								<td><strong><?php echo esc_html( get_the_title( $template ) ); ?></strong></td>
								<td><?php echo esc_html( ucfirst( $area ) ); ?></td>
								<td><?php echo self::get_active_template_id( $area ) === (int) $template->ID ? esc_html__( 'Activo', 'atora-lms' ) : esc_html( $status_object ? $status_object->label : get_post_status( $template ) ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $template->ID, '' ) ); ?>"><?php esc_html_e( 'Editar en Gutenberg', 'atora-lms' ); ?></a>
									<?php if ( 'publish' === get_post_status( $template ) && self::get_active_template_id( $area ) !== (int) $template->ID ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $this->activate_url( (int) $template->ID, $area ) ); ?>"><?php echo esc_html( 'footer' === $area ? __( 'Activar footer', 'atora-lms' ) : __( 'Activar header', 'atora-lms' ) ); ?></a>
									<?php elseif ( 'publish' !== get_post_status( $template ) ) : ?>
										<span class="description"><?php esc_html_e( 'Publica para activar.', 'atora-lms' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Todavia no hay templates creados.', 'atora-lms' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_create_template_part(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para crear templates.', 'atora-lms' ) );
		}

		$area = isset( $_GET['area'] ) ? $this->sanitize_area( wp_unslash( $_GET['area'] ) ) : 'header';
		check_admin_referer( 'clms_create_template_part_' . $area );

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'header' === $area ? __( 'Header editable', 'atora-lms' ) : __( 'Footer editable', 'atora-lms' ),
				'post_content' => $this->default_template_content( $area ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( (int) $post_id, self::META_AREA, $area );

		wp_safe_redirect( get_edit_post_link( (int) $post_id, 'raw' ) );
		exit;
	}

	public function handle_activate_template_part(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para activar templates.', 'atora-lms' ) );
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
		$area    = isset( $_GET['area'] ) ? $this->sanitize_area( wp_unslash( $_GET['area'] ) ) : 'header';

		check_admin_referer( 'clms_activate_template_part_' . $post_id . '_' . $area );

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			$this->redirect_with_notice( 'not_found' );
		}

		if ( 'publish' !== $post->post_status ) {
			$this->redirect_with_notice( 'publish_first' );
		}

		update_post_meta( $post_id, self::META_AREA, $area );
		update_option( self::active_option_name( $area ), $post_id, false );
		$this->redirect_with_notice( 'activated' );
	}

	public function render_admin_notice(): void {
		if ( empty( $_GET['clms_template_part_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['clms_template_part_notice'] ) );
		$map    = array(
			'activated'     => array( 'success', __( 'Template activado correctamente.', 'atora-lms' ) ),
			'publish_first' => array( 'warning', __( 'Publica el template antes de activarlo en frontend.', 'atora-lms' ) ),
			'not_found'     => array( 'error', __( 'No se encontro el template solicitado.', 'atora-lms' ) ),
		);

		if ( empty( $map[ $notice ] ) ) {
			return;
		}

		$type    = $map[ $notice ][0];
		$message = $map[ $notice ][1];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	public function filter_columns( array $columns ): array {
		$columns['clms_template_area']   = __( 'Area', 'atora-lms' );
		$columns['clms_template_active'] = __( 'Activo', 'atora-lms' );
		return $columns;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'clms_template_area' === $column ) {
			echo esc_html( ucfirst( $this->get_post_area( $post_id ) ) );
			return;
		}

		if ( 'clms_template_active' === $column ) {
			$area = $this->get_post_area( $post_id );
			echo self::get_active_template_id( $area ) === $post_id ? esc_html__( 'Activo', 'atora-lms' ) : esc_html__( 'No', 'atora-lms' );
		}
	}

	public function clear_active_option_on_delete( int $post_id ): void {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( self::AREAS as $area ) {
			if ( self::get_active_template_id( $area ) === $post_id ) {
				delete_option( self::active_option_name( $area ) );
			}
		}
	}

	public function sanitize_area( $area ): string {
		$area = sanitize_key( (string) $area );
		return in_array( $area, self::AREAS, true ) ? $area : 'header';
	}

	private function get_post_area( int $post_id ): string {
		return $this->sanitize_area( get_post_meta( $post_id, self::META_AREA, true ) );
	}

	private function create_url( string $area ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'clms_create_template_part',
					'area'   => $area,
				),
				admin_url( 'admin-post.php' )
			),
			'clms_create_template_part_' . $area
		);
	}

	private function activate_url( int $post_id, string $area ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'clms_activate_template_part',
					'post_id' => $post_id,
					'area'    => $area,
				),
				admin_url( 'admin-post.php' )
			),
			'clms_activate_template_part_' . $post_id . '_' . $area
		);
	}

	private function redirect_with_notice( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                      => 'clms-template-parts',
					'clms_template_part_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function default_template_content( string $area ): string {
		if ( 'footer' === $area ) {
			return '<!-- wp:group {"align":"full","style":{"color":{"background":"#0d0d0b","text":"#f7f3ec"},"spacing":{"padding":{"top":"38px","right":"24px","bottom":"38px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} --><div class="wp-block-group alignfull has-text-color has-background" style="color:#f7f3ec;background-color:#0d0d0b;padding-top:38px;padding-right:24px;padding-bottom:38px;padding-left:24px"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:site-title /--><!-- wp:paragraph --><p>Formacion visual, comunicacion y aprendizaje digital.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:navigation {"overlayMenu":"never"} /--></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->';
		}

		return '<!-- wp:group {"align":"full","style":{"color":{"background":"#ffffff"},"spacing":{"padding":{"top":"14px","right":"24px","bottom":"14px","left":"24px"}}},"layout":{"type":"constrained","contentSize":"1180px"}} --><div class="wp-block-group alignfull has-background" style="background-color:#ffffff;padding-top:14px;padding-right:24px;padding-bottom:14px;padding-left:24px"><!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"center"}} --><div class="wp-block-group"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} --><div class="wp-block-group"><!-- wp:site-logo {"width":72} /--><!-- wp:site-title /--></div><!-- /wp:group --><!-- wp:navigation {"overlayMenu":"mobile"} /--></div><!-- /wp:group --></div><!-- /wp:group -->';
	}

	private static function active_option_name( string $area ): string {
		return 'clms_template_part_active_' . sanitize_key( $area );
	}

	public static function get_active_template_id( string $area ): int {
		$area = in_array( $area, self::AREAS, true ) ? $area : 'header';
		return absint( get_option( self::active_option_name( $area ), 0 ) );
	}

	public static function render_area( string $area ): string {
		$area    = in_array( $area, self::AREAS, true ) ? $area : 'header';
		$post_id = self::get_active_template_id( $area );

		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$previous_area    = self::$rendering_area;
		$previous_context = self::$navigation_context;

		self::$rendering_area     = $area;
		self::$navigation_context = array();

		try {
			$content = do_blocks( $post->post_content );
		} finally {
			self::$rendering_area     = $previous_area;
			self::$navigation_context = $previous_context;
		}

		$content = shortcode_unautop( $content );
		$content = do_shortcode( $content );

		if ( function_exists( 'wp_filter_content_tags' ) ) {
			$content = wp_filter_content_tags( $content );
		}

		if ( '' === trim( wp_strip_all_tags( $content ) ) && false === strpos( $content, '<img' ) ) {
			return '';
		}

		$extra_classes = '';
		if ( 'header' === $area && get_post_meta( $post_id, self::META_STICKY, true ) === '1' ) {
			$extra_classes = ' clms-template-part--sticky';
		}

		return '<div class="clms-template-part clms-template-part--' . esc_attr( $area ) . $extra_classes . '">' . $content . '</div>';
	}
}

function clms_get_editable_template_part( string $area ): string {
	return CLMS_UI_Template_Parts::render_area( $area );
}

function clms_render_editable_template_part( string $area ): bool {
	$content = clms_get_editable_template_part( $area );
	if ( '' === $content ) {
		return false;
	}

	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return true;
}
