<?php
/**
 * CLMS_UI_Lesson_Sections
 *
 * Secciones de render para single lesson usando el engine UI.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Lesson_Sections {

	private const DEFAULT_SECTION_IDS = array(
		'breadcrumb',
		'header',
		'cover',
		'videos',
		'content',
		'tips',
		'live_class',
		'resources',
		'quiz',
		'submission',
		'progress',
		'next',
	);

	private const PUBLIC_SECTION_IDS = array(
		'breadcrumb',
		'header',
	);

	/** @var array<int,array<string,mixed>> */
	private static array $cache = array();

	/** @var array<int,bool> */
	private static array $lesson_comment_panel_rendered = array();

	public static function default_section_ids(): array {
		return self::DEFAULT_SECTION_IDS;
	}

	public static function public_section_ids(): array {
		return self::PUBLIC_SECTION_IDS;
	}

	public static function fallback_schema(): array {
		$sections = array();
		foreach ( self::DEFAULT_SECTION_IDS as $id ) {
			$normalized = CLMS_UI_Template_Migrator::normalize_section( $id );
			if ( ! empty( $normalized ) ) {
				$sections[] = $normalized;
			}
		}

		return CLMS_UI_Template_Migrator::fill_defaults(
			array(
				'version'  => CLMS_UI_Template_Migrator::CURRENT_VERSION,
				'preset'   => 'default',
				'theme'    => 'light',
				'variant'  => 'default',
				'props'    => array(),
				'sections' => $sections,
				'limits'   => array(
					'videos'    => 0,
					'resources' => 0,
					'tips'      => 0,
				),
			)
		);
	}

	/**
	 * Datos normalizados de una lección.
	 *
	 * @param int $lesson_id
	 * @return array<string,mixed>
	 */
	public static function data( int $lesson_id ): array {
		if ( isset( self::$cache[ $lesson_id ] ) ) {
			return self::$cache[ $lesson_id ];
		}

		$user_id   = get_current_user_id();
		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) ) : 0;

		$can_access = false;
		if ( $user_id ) {
			if (
				current_user_can( 'manage_options' )
				|| current_user_can( 'clms_manage_lessons' )
				|| current_user_can( 'clms_manage_courses' )
			) {
				$can_access = true;
			} elseif ( class_exists( 'CLMS_Helper' ) ) {
				$can_access = (bool) CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id );
			}
		}

			$subtitle   = (string) get_post_meta( $lesson_id, '_clms_lesson_subtitle', true );
			$cover_id   = absint( get_post_meta( $lesson_id, '_clms_lesson_cover_image_id', true ) );
			$task_title = (string) get_post_meta( $lesson_id, 'lm_task_title', true );
			$l_type_raw = class_exists( 'CLMS_Helper' )
				? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_activity_type', '_clms_activity_mode' ), 'lectura' )
				: (string) ( get_post_meta( $lesson_id, 'lm_activity_type', true ) ?: 'lectura' );
			$l_type = self::normalize_activity_type( $l_type_raw );

		$type_label_map = array(
				'lectura' => __( 'Lección', 'atora-lms' ),
				'tarea'   => __( 'Tarea', 'atora-lms' ),
				'quiz'    => __( 'Evaluación', 'atora-lms' ),
		);
		$type_label = $type_label_map[ $l_type ] ?? __( 'Lección', 'atora-lms' );

		$type_badge_class_map = array(
				'lectura' => 'atora-badge-gray',
				'tarea'   => 'atora-badge-amber',
				'quiz'    => 'atora-badge-red',
		);
		$type_badge_class = $type_badge_class_map[ $l_type ] ?? 'atora-badge-gray';

		$extra_videos = self::normalize_videos( $lesson_id );
		$resources    = self::normalize_resources( $lesson_id );

		$course_lessons = array();
		$prev_id        = 0;
		$next_id        = 0;
		if ( $course_id && class_exists( 'CLMS_Helper' ) ) {
			$course_lessons = CLMS_Helper::get_course_lessons( $course_id );
			$course_lessons = is_array( $course_lessons )
				? array_values( array_map( 'absint', $course_lessons ) )
				: array();

			if ( ! empty( $course_lessons ) ) {
				$pos = array_search( $lesson_id, $course_lessons, true );
				if ( false !== $pos ) {
					$prev_id = isset( $course_lessons[ $pos - 1 ] ) ? absint( $course_lessons[ $pos - 1 ] ) : 0;
					$next_id = isset( $course_lessons[ $pos + 1 ] ) ? absint( $course_lessons[ $pos + 1 ] ) : 0;
				}
			}
		}

		$primary_cta = array();
		if ( $next_id ) {
			$primary_cta = array(
				'url'   => get_permalink( $next_id ),
				'label' => __( 'Siguiente lección', 'atora-lms' ),
				'note'  => __( 'Continúa para mantener el ritmo.', 'atora-lms' ),
			);
		} elseif ( $course_id ) {
			$primary_cta = array(
				'url'   => get_permalink( $course_id ),
				'label' => __( 'Volver al curso', 'atora-lms' ),
				'note'  => __( 'Explora el resto del contenido disponible.', 'atora-lms' ),
			);
		}

		$show_course_link = (bool) ( $course_id && $next_id );
		$sidebar_html     = '';
		if ( $course_id && class_exists( 'CLMS_Lesson_Sidebar' ) ) {
			$sidebar_obj  = new CLMS_Lesson_Sidebar();
			$sidebar_html = (string) $sidebar_obj->get_sidebar_html( $course_id, $lesson_id, $user_id );
		}

		$completed = array();
		if ( $user_id ) {
			$raw_completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
			$completed     = is_array( $raw_completed ) ? array_map( 'absint', $raw_completed ) : array();
		}

		$total_lessons = count( $course_lessons );
		$done_lessons  = $total_lessons > 0 ? count( array_intersect( $course_lessons, $completed ) ) : 0;
		$progress      = $total_lessons > 0 ? (int) round( $done_lessons / $total_lessons * 100 ) : 0;

		self::$cache[ $lesson_id ] = compact(
			'lesson_id',
			'user_id',
			'course_id',
			'can_access',
			'subtitle',
			'cover_id',
			'task_title',
			'l_type',
			'type_label',
			'type_badge_class',
			'extra_videos',
			'resources',
			'course_lessons',
			'prev_id',
			'next_id',
			'primary_cta',
			'show_course_link',
			'sidebar_html',
			'total_lessons',
			'done_lessons',
			'progress'
		);

		return self::$cache[ $lesson_id ];
	}

	/**
	 * Construye el view model consumido por templates/single-lesson.php.
	 *
	 * Centraliza resolución de schema/context/engine + estado de acceso
	 * para mantener el template como wrapper delgado.
	 *
	 * @param int $lesson_id
	 * @return array<string,mixed>
	 */
	public static function build_view_model( int $lesson_id ): array {
		$lesson_id = absint( $lesson_id );
		unset( self::$lesson_comment_panel_rendered[ $lesson_id ] );

		$resolver = new CLMS_UI_Template_Resolver();
		$repo     = $resolver->repository();

		$schema = apply_filters(
			'clms_lesson_ui_schema',
			$resolver->resolve( $lesson_id, 'lesson' ),
			$lesson_id
		);

		if ( ! $repo->validate( $schema ) || empty( $schema['sections'] ) ) {
			$schema = self::fallback_schema();
		}

		$ctx             = CLMS_UI_Template_Context::make( $lesson_id, 'lesson' );
		$engine          = new CLMS_UI_Template_Engine();
		$sections_output = $engine->render_to_array( $ctx, $schema );
		$sections_order  = self::normalize_learning_flow_order( array_keys( $sections_output ) );

		$d = self::data( $lesson_id );

		$user_id      = isset( $d['user_id'] ) ? absint( $d['user_id'] ) : get_current_user_id();
		$course_id    = isset( $d['course_id'] ) ? absint( $d['course_id'] ) : 0;
		$can_access   = ! empty( $d['can_access'] ) || $ctx->is_enrolled;
		$sidebar_html = (string) ( $d['sidebar_html'] ?? '' );

		if ( ! $course_id && class_exists( 'CLMS_Helper' ) ) {
			$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}

		if ( ! $sidebar_html && $course_id && class_exists( 'CLMS_Lesson_Sidebar' ) ) {
			$sidebar_obj  = new CLMS_Lesson_Sidebar();
			$sidebar_html = (string) $sidebar_obj->get_sidebar_html( $course_id, $lesson_id, $user_id );
		}

		$public_sections = array_values(
			array_filter(
				array_map( 'sanitize_key', self::public_section_ids() )
			)
		);

		$render_section_ids = $sections_order;
		$view_state         = 'full';

		if ( ! $user_id || ! $can_access ) {
			$view_state = $user_id ? 'restricted' : 'guest';

			$render_section_ids = array_values(
				array_filter(
					$sections_order,
					static function ( $section_id ) use ( $public_sections ) {
						$section_id = sanitize_key( (string) $section_id );
						return in_array( $section_id, $public_sections, true );
					}
				)
			);
		}

		return array(
			'lesson_id'         => $lesson_id,
			'schema'            => $schema,
			'context'           => $ctx,
			'sections_output'   => $sections_output,
			'sections_order'    => $sections_order,
			'render_section_ids' => $render_section_ids,
			'public_sections'   => $public_sections,
			'view_state'        => $view_state,
			'user_id'           => $user_id,
			'course_id'         => $course_id,
			'can_access'        => $can_access,
			'sidebar_html'      => $sidebar_html,
		);
	}

	/**
	 * Ajusta orden mínimo de lectura para evitar fricción UX:
	 * recursos antes de evaluación, y bloque de clase en vivo antes de ambos.
	 *
	 * @param array $section_ids IDs renderizados.
	 * @return array
	 */
	private static function normalize_learning_flow_order( array $section_ids ): array {
		$section_ids = array_values(
			array_filter(
				array_map(
					static function ( $id ): string {
						return sanitize_key( (string) $id );
					},
					$section_ids
				),
				'strlen'
			)
		);

		$section_ids = self::move_section_before_targets( $section_ids, 'resources', array( 'quiz', 'submission', 'evaluation' ) );
		$section_ids = self::move_section_before_targets( $section_ids, 'live_class', array( 'resources', 'quiz', 'submission', 'evaluation' ) );

		return $section_ids;
	}

	/**
	 * Reubica una sección para que quede antes del primer target disponible.
	 *
	 * @param array $section_ids Lista de secciones.
	 * @param string $source ID que se moverá.
	 * @param array $targets IDs objetivo.
	 * @return array
	 */
	private static function move_section_before_targets( array $section_ids, string $source, array $targets ): array {
		$source_index = array_search( $source, $section_ids, true );
		if ( false === $source_index ) {
			return $section_ids;
		}

		$target_indexes = array();
		foreach ( $targets as $target ) {
			$target_index = array_search( sanitize_key( (string) $target ), $section_ids, true );
			if ( false !== $target_index ) {
				$target_indexes[] = (int) $target_index;
			}
		}

		if ( empty( $target_indexes ) ) {
			return $section_ids;
		}

		$target_index = min( $target_indexes );
		if ( $source_index < $target_index ) {
			return $section_ids;
		}

		$source_item = $section_ids[ $source_index ];
		array_splice( $section_ids, $source_index, 1 );
		array_splice( $section_ids, $target_index, 0, array( $source_item ) );

		return array_values( $section_ids );
	}

	public static function render_breadcrumb( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );

		$d = self::data( $ctx->entity_id );
		if ( empty( $d['course_id'] ) ) {
			return;
		}
		?>
		<nav class="atora-lesson-breadcrumb" aria-label="<?php esc_attr_e( 'Navegación', 'atora-lms' ); ?>">
			<?php if ( ! empty( $d['show_course_link'] ) ) : ?>
				<a href="<?php echo esc_url( get_permalink( $d['course_id'] ) ); ?>">
					← <?php echo esc_html( get_the_title( $d['course_id'] ) ); ?>
				</a>
			<?php else : ?>
				<span>← <?php echo esc_html( get_the_title( $d['course_id'] ) ); ?></span>
			<?php endif; ?>
			<span class="atora-lesson-breadcrumb__sep" aria-hidden="true">/</span>
			<span><?php echo esc_html( get_the_title( $ctx->entity_id ) ); ?></span>
		</nav>
		<?php
	}

	public static function render_header( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		$d = self::data( $ctx->entity_id );
		?>
		<div class="atora-lesson-header">
			<div class="atora-lesson-header__meta">
				<span class="atora-badge <?php echo esc_attr( $d['type_badge_class'] ); ?>">
					<?php echo esc_html( $d['type_label'] ); ?>
				</span>
			</div>
			<h1 class="atora-lesson-title"><?php echo esc_html( get_the_title( $ctx->entity_id ) ); ?></h1>
			<?php if ( ! empty( $d['subtitle'] ) ) : ?>
				<p class="atora-lesson-subtitle"><?php echo esc_html( $d['subtitle'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_cover( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) ) {
			return;
		}

		if ( ! empty( $d['cover_id'] ) ) :
			?>
			<div class="atora-lesson-cover">
				<?php echo wp_get_attachment_image( $d['cover_id'], 'large', false, array( 'class' => 'atora-lesson-cover-img' ) ); ?>
			</div>
			<?php
		elseif ( has_post_thumbnail( $ctx->entity_id ) ) :
			?>
			<div class="atora-lesson-cover">
				<?php echo get_the_post_thumbnail( $ctx->entity_id, 'large', array( 'class' => 'atora-lesson-cover-img' ) ); ?>
			</div>
			<?php
		endif;
	}

	/**
	 * Renderiza un bloque reusable de lección con cabecera opcional.
	 *
	 * @param array    $args             Configuración de render.
	 * @param callable $content_renderer Callback que imprime el contenido interno.
	 */
	private static function render_lesson_block( array $args, callable $content_renderer ): void {
		$tag = isset( $args['tag'] ) ? strtolower( (string) $args['tag'] ) : 'div';
		if ( ! in_array( $tag, array( 'div', 'section', 'article' ), true ) ) {
			$tag = 'div';
		}

		$class = isset( $args['class'] ) ? trim( (string) $args['class'] ) : '';

		$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';
		$title_tag = isset( $args['title_tag'] ) ? strtolower( (string) $args['title_tag'] ) : 'h3';
		if ( ! in_array( $title_tag, array( 'h2', 'h3', 'h4', 'p', 'span' ), true ) ) {
			$title_tag = 'h3';
		}
		$title_class = isset( $args['title_class'] ) ? trim( (string) $args['title_class'] ) : '';

		echo '<' . esc_html( $tag );
		if ( '' !== $class ) {
			echo ' class="' . esc_attr( $class ) . '"';
		}
		echo '>';

		if ( '' !== $title ) {
			echo '<' . esc_html( $title_tag );
			if ( '' !== $title_class ) {
				echo ' class="' . esc_attr( $title_class ) . '"';
			}
			echo '>' . esc_html( $title ) . '</' . esc_html( $title_tag ) . '>';
		}

		call_user_func( $content_renderer );

		echo '</' . esc_html( $tag ) . '>';
	}

	/**
	 * Bloque reusable para listas de "Puntos clave".
	 *
	 * @param array $tips Lista de tips de la lección.
	 */
	private static function render_tips_block( array $tips ): void {
		$tips = array_values(
			array_filter(
				array_map(
					static function( $tip ): string {
						return trim( (string) $tip );
					},
					$tips
				),
				'strlen'
			)
		);

		if ( empty( $tips ) ) {
			return;
		}

		self::render_lesson_block(
			array(
				'tag'         => 'div',
				'class'       => 'atora-lesson-tips',
				'title'       => __( 'Puntos clave', 'atora-lms' ),
				'title_tag'   => 'p',
				'title_class' => 'atora-lesson-tips-title',
			),
			static function() use ( $tips ): void {
				?>
				<ul class="atora-lesson-tips-list">
					<?php foreach ( $tips as $tip ) : ?>
						<li><?php echo esc_html( $tip ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php
			}
		);
	}

	public static function render_videos( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) || empty( $d['extra_videos'] ) ) {
			return;
		}

		$limits          = self::resolve_limits( $ctx->entity_id, $schema );
		$videos_limit    = $limits['videos'];
		$tips_limit      = $limits['tips'];
		$extra_videos_ui = $d['extra_videos'];

		if ( $videos_limit > 0 ) {
			$extra_videos_ui = array_slice( $extra_videos_ui, 0, $videos_limit );
		}

		foreach ( $extra_videos_ui as $video ) {
			$vd_source   = isset( $video['source'] ) ? (string) $video['source'] : 'youtube';
			$vd_url      = isset( $video['url'] ) ? (string) $video['url'] : '';
			$vd_desc     = isset( $video['description'] ) ? trim( (string) $video['description'] ) : '';
			$vd_thumb_id = isset( $video['thumb_id'] ) ? absint( $video['thumb_id'] ) : 0;
			$vd_tips     = array_filter(
				array(
					isset( $video['tip_1'] ) ? (string) $video['tip_1'] : '',
					isset( $video['tip_2'] ) ? (string) $video['tip_2'] : '',
					isset( $video['tip_3'] ) ? (string) $video['tip_3'] : '',
				)
			);

			$vd_thumb_url = '';
			if ( $vd_thumb_id ) {
				$vd_thumb_url = (string) ( wp_get_attachment_image_url( $vd_thumb_id, 'large' ) ?: '' );
			}

			$video_html = self::render_video_player( $vd_source, $vd_url, $vd_thumb_url );
			if ( ! $video_html ) {
				continue;
			}

			echo $video_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( $vd_desc ) {
				echo '<div class="atora-video-description">' . wp_kses_post( $vd_desc ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			if ( ! empty( $vd_tips ) ) {
				if ( $tips_limit > 0 ) {
					$vd_tips = array_slice( array_values( $vd_tips ), 0, $tips_limit );
				}
				self::render_tips_block( $vd_tips );
			}
			}
		}

	public static function render_content( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) ) {
			return;
		}

		$content = (string) get_post_field( 'post_content', $ctx->entity_id );
		if ( '' !== trim( $content ) ) {
			$strip_tags = array();
			if ( self::lesson_has_active_quiz( $ctx->entity_id ) && ( self::has_enabled_section( $schema, 'quiz' ) || self::has_enabled_section( $schema, 'evaluation' ) ) ) {
				$strip_tags[] = 'clms_quiz';
			}
			if ( self::has_enabled_section( $schema, 'submission' ) || self::has_enabled_section( $schema, 'evaluation' ) ) {
				$strip_tags[] = 'clms_submission_form';
			}

			if ( ! empty( $strip_tags ) ) {
				$content = self::strip_shortcodes_from_content( $content, $strip_tags );
			}
		}
		?>
		<div class="atora-lesson-content">
			<?php echo apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	}

	public static function render_tips( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) ) {
			return;
		}

		$tips = array();
		if ( empty( $d['extra_videos'] ) ) {
			$tips = array_filter(
				array(
					(string) get_post_meta( $ctx->entity_id, '_clms_tip_1', true ),
					(string) get_post_meta( $ctx->entity_id, '_clms_tip_2', true ),
					(string) get_post_meta( $ctx->entity_id, '_clms_tip_3', true ),
				)
			);
		}

		if ( empty( $tips ) ) {
			return;
		}

		$limits    = self::resolve_limits( $ctx->entity_id, $schema );
		$tips_limit = $limits['tips'];
		if ( $tips_limit > 0 ) {
			$tips = array_slice( array_values( $tips ), 0, $tips_limit );
		}
		self::render_tips_block( $tips );
	}

	public static function render_live_class( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) ) {
			return;
		}

		$lesson_id   = absint( $ctx->entity_id );
		$session_raw = class_exists( 'CLMS_Helper' )
			? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_session_type' ), 'asincrono' )
			: (string) get_post_meta( $lesson_id, 'lm_session_type', true );
		$session_type = sanitize_key( $session_raw );
		$provider     = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_live_class_provider', true ) );
		$url          = esc_url( (string) get_post_meta( $lesson_id, '_clms_live_class_url', true ) );
		$starts_at    = self::format_live_datetime( (string) get_post_meta( $lesson_id, '_clms_live_class_starts_at', true ) );
		$ends_at      = self::format_live_datetime( (string) get_post_meta( $lesson_id, '_clms_live_class_ends_at', true ) );
		$timezone     = sanitize_text_field( (string) get_post_meta( $lesson_id, '_clms_live_class_timezone', true ) );
		$notes        = sanitize_textarea_field( (string) get_post_meta( $lesson_id, '_clms_live_class_notes', true ) );

		$is_live_session = in_array( $session_type, array( 'sincrono', 'hibrido' ), true );
		$has_live_data   = '' !== $url || '' !== $starts_at || '' !== $ends_at || '' !== $notes;
		if ( ! $is_live_session && ! $has_live_data ) {
			return;
		}

		$provider_labels = array(
			'zoom'         => __( 'Zoom', 'atora-lms' ),
			'google_meet'  => __( 'Google Meet', 'atora-lms' ),
			'youtube_live' => __( 'YouTube Live', 'atora-lms' ),
			'custom'       => __( 'Enlace externo', 'atora-lms' ),
		);
		$provider_label = $provider_labels[ $provider ] ?? __( 'Clase en vivo', 'atora-lms' );
		?>
		<section class="atora-lesson-live-class">
			<div class="atora-lesson-live-class__head">
				<p class="atora-lesson-live-class__eyebrow"><?php esc_html_e( 'Clase en vivo', 'atora-lms' ); ?></p>
				<span class="atora-lesson-live-class__provider"><?php echo esc_html( $provider_label ); ?></span>
			</div>
			<?php if ( $starts_at || $ends_at ) : ?>
				<p class="atora-lesson-live-class__schedule">
					<?php if ( $starts_at && $ends_at ) : ?>
						<?php printf( esc_html__( 'Horario: %1$s a %2$s', 'atora-lms' ), esc_html( $starts_at ), esc_html( $ends_at ) ); ?>
					<?php elseif ( $starts_at ) : ?>
						<?php printf( esc_html__( 'Inicia: %s', 'atora-lms' ), esc_html( $starts_at ) ); ?>
					<?php else : ?>
						<?php printf( esc_html__( 'Finaliza: %s', 'atora-lms' ), esc_html( $ends_at ) ); ?>
					<?php endif; ?>
					<?php if ( '' !== $timezone ) : ?>
						<?php echo esc_html( ' · ' . $timezone ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $notes ) : ?>
				<p class="atora-lesson-live-class__notes"><?php echo esc_html( $notes ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $url ) : ?>
				<a class="atora-btn atora-btn-primary" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Abrir clase en vivo', 'atora-lms' ); ?>
				</a>
			<?php else : ?>
				<p class="atora-lesson-live-class__pending"><?php esc_html_e( 'Tu docente publicará el enlace de la clase en vivo pronto.', 'atora-lms' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function render_quiz( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) ) {
			return;
		}

		if ( ! self::lesson_has_active_quiz( $ctx->entity_id ) ) {
			$rendered_read_evidence = false;
			if ( self::should_show_read_evidence( $ctx->entity_id ) ) {
				self::render_read_evidence_inline( $ctx->entity_id, $ctx->user_id );
				$rendered_read_evidence = true;
			}
			if ( $rendered_read_evidence && self::student_has_read_evidence( $ctx->entity_id, $ctx->user_id ) ) {
				self::render_student_lesson_comment_panel_once( $ctx->entity_id, $ctx->user_id );
			}
			return;
		}

		$default_shortcode = sprintf( '[clms_quiz lesson_id="%d"]', absint( $ctx->entity_id ) );
		$quiz_shortcode    = apply_filters( 'atora_lesson_quiz_shortcode', $default_shortcode, $ctx->entity_id );
		if ( ! $quiz_shortcode ) {
			return;
		}

		echo do_shortcode( $quiz_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( self::student_has_quiz_attempt( $ctx->entity_id, $ctx->user_id ) ) {
			self::render_student_lesson_comment_panel_once( $ctx->entity_id, $ctx->user_id );
		}
	}

	private static function render_read_evidence_inline( int $lesson_id, int $user_id ): void {
		$lesson_id = absint( $lesson_id );
		$user_id   = absint( $user_id );

		if ( ! $lesson_id || ! $user_id || ! is_user_logged_in() ) {
			return;
		}

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		$config = ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) )
			? (array) $evidence_service->get_activity_evidence_config( $lesson_id )
			: array();
		$read_record = ( $evidence_service && method_exists( $evidence_service, 'get_read_evidence_record' ) )
			? (array) $evidence_service->get_read_evidence_record( $user_id, $lesson_id )
			: array();
		$read_requirement = isset( $config['read_requirement'] ) ? sanitize_key( (string) $config['read_requirement'] ) : 'seen_or_comment';
		$is_comment_required = 'comment' === $read_requirement;
		$feedback_state = isset( $_GET['clms_read_evidence'] ) ? sanitize_key( wp_unslash( $_GET['clms_read_evidence'] ) ) : '';
		?>
		<div class="atora-lesson-read-evidence">
			<h3 class="atora-lesson-read-evidence__title"><?php esc_html_e( 'Evidencia de lectura', 'atora-lms' ); ?></h3>
			<?php if ( 'updated' === $feedback_state ) : ?>
				<p class="atora-lesson-read-evidence__message is-success"><?php esc_html_e( 'Se registró tu evidencia de lectura correctamente.', 'atora-lms' ); ?></p>
			<?php elseif ( 'pending_comment' === $feedback_state ) : ?>
				<p class="atora-lesson-read-evidence__message"><?php esc_html_e( 'Tu visto se guardó, pero esta lección exige comentario para contar como completada.', 'atora-lms' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $read_record['seen'] ) ) : ?>
				<?php
				$updated_at_raw = (string) ( $read_record['updated_at'] ?? '' );
				$updated_at_ts  = $updated_at_raw ? strtotime( $updated_at_raw ) : false;
				$updated_label  = $updated_at_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated_at_ts ) : $updated_at_raw;
				?>
				<p class="atora-lesson-read-evidence__meta">
					<?php
					printf(
						/* translators: %s: last update datetime */
						esc_html__( 'Último registro: %s', 'atora-lms' ),
						esc_html( $updated_label )
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post" class="atora-lesson-read-evidence__form">
				<?php wp_nonce_field( 'clms_mark_read_evidence_' . $lesson_id ); ?>
				<input type="hidden" name="clms_action" value="mark_read_evidence">
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>">
				<label for="atora-read-comment-<?php echo esc_attr( (string) $lesson_id ); ?>">
					<?php echo esc_html( $is_comment_required ? __( 'Comentario de lectura (obligatorio)', 'atora-lms' ) : __( 'Comentario de lectura (opcional)', 'atora-lms' ) ); ?>
				</label>
				<textarea id="atora-read-comment-<?php echo esc_attr( (string) $lesson_id ); ?>" name="clms_read_comment" rows="3" placeholder="<?php echo esc_attr__( 'Comparte una idea clave que te llevas de esta lección.', 'atora-lms' ); ?>"><?php echo esc_textarea( (string) ( $read_record['comment'] ?? '' ) ); ?></textarea>
				<button type="submit" class="atora-btn atora-btn-secondary"><?php esc_html_e( 'Marcar como visto', 'atora-lms' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function render_student_lesson_comment_panel( int $lesson_id, int $user_id ): void {
		$lesson_id = absint( $lesson_id );
		$user_id   = absint( $user_id );
		if ( ! $lesson_id || ! $user_id || ! is_user_logged_in() ) {
			return;
		}

		$current_comment = '';
		if ( class_exists( 'CLMS_Progress' ) && method_exists( 'CLMS_Progress', 'get_student_lesson_feedback_record' ) ) {
			$feedback_record = (array) CLMS_Progress::get_student_lesson_feedback_record( $user_id, $lesson_id );
			$current_comment = isset( $feedback_record['comment'] ) ? (string) $feedback_record['comment'] : '';
		} else {
			$raw_log = get_user_meta( $user_id, '_clms_lesson_feedback_log', true );
			$raw_log = is_array( $raw_log ) ? $raw_log : array();
			$entry   = isset( $raw_log[ $lesson_id ] ) && is_array( $raw_log[ $lesson_id ] ) ? $raw_log[ $lesson_id ] : array();
			$current_comment = isset( $entry['comment'] ) ? (string) $entry['comment'] : '';
		}

		$status = isset( $_GET['clms_lesson_feedback'] ) ? sanitize_key( wp_unslash( $_GET['clms_lesson_feedback'] ) ) : '';
		?>
		<section class="atora-lesson-student-comment">
			<div class="atora-lesson-student-comment__head">
				<h3 class="atora-lesson-student-comment__title"><?php esc_html_e( 'Comentario para tu docente', 'atora-lms' ); ?></h3>
				<p class="atora-lesson-student-comment__subtitle"><?php esc_html_e( 'Comparte cómo te fue en esta clase. Tu docente lo verá en SpeedGrade.', 'atora-lms' ); ?></p>
			</div>
			<?php if ( 'updated' === $status ) : ?>
				<p class="atora-lesson-student-comment__message is-success"><?php esc_html_e( 'Tu comentario se guardó correctamente.', 'atora-lms' ); ?></p>
			<?php endif; ?>
			<form method="post" class="atora-lesson-student-comment__form">
				<?php wp_nonce_field( 'clms_save_lesson_feedback_' . $lesson_id ); ?>
				<input type="hidden" name="clms_action" value="save_lesson_feedback">
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) $lesson_id ); ?>">
				<textarea name="clms_lesson_feedback_comment" rows="4" placeholder="<?php echo esc_attr__( 'Ej. La parte más clara fue..., me costó..., y me gustaría reforzar...', 'atora-lms' ); ?>"><?php echo esc_textarea( $current_comment ); ?></textarea>
				<button type="submit" class="atora-btn atora-btn-secondary"><?php esc_html_e( 'Guardar comentario', 'atora-lms' ); ?></button>
			</form>
		</section>
		<?php
	}

	private static function render_student_lesson_comment_panel_once( int $lesson_id, int $user_id ): void {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id || isset( self::$lesson_comment_panel_rendered[ $lesson_id ] ) ) {
			return;
		}

		self::$lesson_comment_panel_rendered[ $lesson_id ] = true;
		self::render_student_lesson_comment_panel( $lesson_id, $user_id );
	}

	public static function render_submission( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) || empty( $d['task_title'] ) ) {
			return;
		}

		echo do_shortcode( '[clms_submission_form lesson_id="' . intval( $ctx->entity_id ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( self::student_has_submission( $ctx->entity_id, $ctx->user_id ) ) {
			self::render_student_lesson_comment_panel_once( $ctx->entity_id, $ctx->user_id );
		}
	}

	public static function render_resources( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) || empty( $d['resources'] ) ) {
			return;
		}

		$limits       = self::resolve_limits( $ctx->entity_id, $schema );
		$resources_ui = $d['resources'];
		if ( $limits['resources'] > 0 ) {
			$resources_ui = array_slice( $resources_ui, 0, $limits['resources'] );
		}
		?>
		<div class="atora-lesson-resources">
			<div class="atora-lesson-resources-header">
				<h3 class="atora-lesson-resources-title"><?php esc_html_e( 'Recursos de apoyo', 'atora-lms' ); ?></h3>
				<span class="atora-lesson-resources-count">
					<?php
					printf(
						/* translators: %d: number of resources */
						esc_html__( '%d recursos', 'atora-lms' ),
						count( $resources_ui )
					);
					?>
				</span>
			</div>
			<p class="atora-lesson-resources-subtitle"><?php esc_html_e( 'Material complementario para avanzar con más claridad.', 'atora-lms' ); ?></p>
			<div class="atora-resources-grid">
				<?php foreach ( $resources_ui as $resource ) : ?>
					<?php
					$r_title    = isset( $resource['title'] ) ? (string) $resource['title'] : '';
					$r_desc     = isset( $resource['description'] ) ? (string) $resource['description'] : '';
					$r_type     = isset( $resource['type'] ) ? (string) $resource['type'] : '';
					$r_file_id  = ! empty( $resource['file_id'] ) ? absint( $resource['file_id'] ) : 0;
					$r_url      = ! empty( $resource['url'] ) ? (string) $resource['url'] : '';
					$r_thumb_id = ! empty( $resource['thumb_id'] ) ? absint( $resource['thumb_id'] ) : 0;

					$href = '';
					if ( $r_file_id ) {
						$href = (string) wp_get_attachment_url( $r_file_id );
						if ( ! $r_title ) {
							$r_title = (string) get_the_title( $r_file_id );
						}
					} elseif ( $r_url ) {
						$href = $r_url;
					}

					if ( ! $r_title ) {
						$r_title = $href ? basename( (string) $r_url ) : __( 'Recurso', 'atora-lms' );
					}

					$embed = self::get_resource_video_embed( $r_type, $r_url ? $r_url : $href );
					?>
					<article class="atora-resource-card">
						<div class="atora-resource-media">
							<?php if ( $r_thumb_id ) : ?>
								<?php echo wp_get_attachment_image( $r_thumb_id, array( 240, 160 ), false, array( 'class' => 'atora-resource-thumb' ) ); ?>
							<?php else : ?>
								<div class="atora-resource-fallback">
									<span class="atora-resource-fallback-title"><?php echo esc_html( $r_title ); ?></span>
									<?php if ( $r_type ) : ?>
										<span class="atora-resource-fallback-type"><?php echo esc_html( strtoupper( $r_type ) ); ?></span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="atora-resource-body">
							<div class="atora-resource-head">
								<strong class="atora-resource-title"><?php echo esc_html( $r_title ); ?></strong>
								<?php if ( $r_type ) : ?>
									<span class="atora-resource-type"><?php echo esc_html( strtoupper( $r_type ) ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( $r_desc ) : ?>
								<p class="atora-resource-desc"><?php echo esc_html( $r_desc ); ?></p>
							<?php endif; ?>
							<?php if ( $embed ) : ?>
								<div class="atora-resource-embed">
									<?php echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php elseif ( $href ) : ?>
								<a class="atora-resource-link" href="<?php echo esc_url( $href ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Ver / Descargar', 'atora-lms' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public static function render_next( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) || empty( $d['primary_cta']['url'] ) ) {
			return;
		}
		?>
		<div class="atora-lesson-next">
			<div>
				<p class="atora-lesson-next__eyebrow"><?php esc_html_e( 'Tu siguiente paso', 'atora-lms' ); ?></p>
				<h3 class="atora-lesson-next__title"><?php echo esc_html( $d['primary_cta']['label'] ); ?></h3>
				<p class="atora-lesson-next__text"><?php echo esc_html( $d['primary_cta']['note'] ); ?></p>
			</div>
			<a class="atora-btn atora-btn-primary" href="<?php echo esc_url( $d['primary_cta']['url'] ); ?>">
				<?php echo esc_html( $d['primary_cta']['label'] ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Alias legacy: en schemas viejos "evaluation" agrupaba quiz + entrega.
	 */
	public static function render_evaluation( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( self::has_enabled_section( $schema, 'quiz' ) || self::has_enabled_section( $schema, 'submission' ) ) {
			return;
		}

		self::render_quiz( $section, $ctx, $schema );
		self::render_submission( $section, $ctx, $schema );
	}

	/**
	 * Alias legacy para navegación.
	 */
	public static function render_navigation( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( self::has_enabled_section( $schema, 'next' ) ) {
			return;
		}

		self::render_next( $section, $ctx, $schema );
	}

	public static function render_progress( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['can_access'] ) ) {
			return;
		}

		$total_lessons = max( 0, absint( $d['total_lessons'] ?? 0 ) );
		if ( $total_lessons <= 0 ) {
			return;
		}

		$done_lessons = max( 0, absint( $d['done_lessons'] ?? 0 ) );
		$progress     = max( 0, min( 100, absint( $d['progress'] ?? 0 ) ) );

		self::render_lesson_block(
			array(
				'tag'   => 'section',
				'class' => 'atora-lesson-progress',
			),
			static function () use ( $progress, $done_lessons, $total_lessons ): void {
				?>
				<div class="atora-lesson-progress__head">
					<p class="atora-lesson-progress__eyebrow"><?php esc_html_e( 'Progreso del curso', 'atora-lms' ); ?></p>
					<p class="atora-lesson-progress__value"><?php echo esc_html( $progress ); ?>%</p>
				</div>
				<div class="atora-lesson-progress__bar">
					<div class="atora-lesson-progress__bar-fill" style="width:<?php echo esc_attr( $progress ); ?>%"></div>
				</div>
				<p class="atora-lesson-progress__meta">
					<?php
					printf(
						/* translators: 1: completed lessons, 2: total lessons */
						esc_html__( '%1$d de %2$d lecciones completadas', 'atora-lms' ),
						$done_lessons,
						$total_lessons
					);
					?>
				</p>
				<?php
			}
		);
	}

	private static function normalize_activity_type( $activity_type ): string {
		$activity_type = sanitize_key( (string) $activity_type );
		$aliases = array(
			'quiz'       => 'quiz',
			'evaluacion' => 'quiz',
			'evaluation' => 'quiz',
			'tarea'      => 'tarea',
			'task'       => 'tarea',
			'assignment' => 'tarea',
			'lectura'    => 'lectura',
			'reading'    => 'lectura',
		);
		return isset( $aliases[ $activity_type ] ) ? $aliases[ $activity_type ] : 'lectura';
	}

	private static function lesson_has_active_quiz( int $lesson_id ): bool {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return false;
		}

		$enabled  = (string) get_post_meta( $lesson_id, '_clms_quiz_enabled', true );
		$has_eval = sanitize_key( (string) get_post_meta( $lesson_id, '_lm_quiz_has_eval', true ) );
		$is_enabled = in_array( $enabled, array( '1', 'yes', 'true' ), true ) || 'yes' === $has_eval;
		if ( ! $is_enabled ) {
			return false;
		}

		$questions = get_post_meta( $lesson_id, '_clms_quiz_questions', true );
		if ( is_array( $questions ) && ! empty( $questions ) ) {
			return true;
		}

		$bank_size = absint( get_post_meta( $lesson_id, '_clms_ai_question_bank_size', true ) );
		if ( $bank_size > 0 ) {
			return true;
		}

		$content = (string) get_post_field( 'post_content', $lesson_id );
		return '' !== trim( $content ) && has_shortcode( $content, 'clms_quiz' );
	}

	private static function should_show_read_evidence( int $lesson_id ): bool {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return false;
		}

		if ( self::lesson_has_active_quiz( $lesson_id ) ) {
			return false;
		}

		$type_raw = class_exists( 'CLMS_Helper' )
			? CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_activity_type', '_clms_activity_mode' ), 'lectura' )
			: (string) get_post_meta( $lesson_id, 'lm_activity_type', true );
		$activity_type = self::normalize_activity_type( $type_raw );

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( ! $evidence_service || ! method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
			return 'lectura' === $activity_type;
		}

		$config = (array) $evidence_service->get_activity_evidence_config( $lesson_id );
		$evidence_type = isset( $config['evidence_type'] ) ? sanitize_key( (string) $config['evidence_type'] ) : '';
		if ( 'read_only' === $evidence_type ) {
			return true;
		}

		if ( '' === $evidence_type || 'practice' === $evidence_type ) {
			return 'lectura' === $activity_type;
		}

		return false;
	}

	private static function format_live_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$ts = strtotime( str_replace( 'T', ' ', $value ) );
		if ( ! $ts ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	public static function register_callbacks(): void {
		if ( ! class_exists( 'CLMS_UI_Section_Registry' ) ) {
			return;
		}

		$registry = CLMS_UI_Section_Registry::instance();

		$callbacks = array(
			'breadcrumb' => array( self::class, 'render_breadcrumb' ),
			'header'     => array( self::class, 'render_header' ),
			'cover'      => array( self::class, 'render_cover' ),
			'videos'     => array( self::class, 'render_videos' ),
			'content'    => array( self::class, 'render_content' ),
			'tips'       => array( self::class, 'render_tips' ),
			'live_class' => array( self::class, 'render_live_class' ),
			'quiz'       => array( self::class, 'render_quiz' ),
			'submission' => array( self::class, 'render_submission' ),
			'resources'  => array( self::class, 'render_resources' ),
			'next'       => array( self::class, 'render_next' ),
			'evaluation' => array( self::class, 'render_evaluation' ),
			'navigation' => array( self::class, 'render_navigation' ),
			'progress'   => array( self::class, 'render_progress' ),
		);

		$labels = array(
			'breadcrumb' => __( 'Migas de pan', 'atora-lms' ),
			'header'     => __( 'Cabecera de lección', 'atora-lms' ),
			'cover'      => __( 'Portada de lección', 'atora-lms' ),
			'videos'     => __( 'Videos', 'atora-lms' ),
			'content'    => __( 'Contenido', 'atora-lms' ),
			'tips'       => __( 'Puntos clave', 'atora-lms' ),
			'live_class' => __( 'Clase en vivo', 'atora-lms' ),
			'quiz'       => __( 'Quiz', 'atora-lms' ),
			'submission' => __( 'Entrega', 'atora-lms' ),
			'resources'  => __( 'Recursos de apoyo', 'atora-lms' ),
			'next'       => __( 'Siguiente paso', 'atora-lms' ),
			'evaluation' => __( 'Evaluación', 'atora-lms' ),
			'navigation' => __( 'Navegación', 'atora-lms' ),
			'progress'   => __( 'Progreso', 'atora-lms' ),
		);

		foreach ( $callbacks as $id => $callback ) {
			$section = $registry->get( $id );
			if ( ! $section ) {
				$registry->register(
					$id,
					array(
						'label'    => $labels[ $id ] ?? $id,
						'contexts' => array( 'lesson' ),
					)
				);
				$section = $registry->get( $id );
			}

			if ( ! $section ) {
				continue;
			}

			$section['render_callback'] = $callback;
			$registry->register( $id, $section );
		}
	}

	public static function render_inline_assets(): void {
		$partial = self::partial_path( 'inline-assets.php' );
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	/**
	 * @return array{videos:int,resources:int,tips:int}
	 */
	private static function resolve_limits( int $lesson_id, array $schema ): array {
		$videos_limit    = self::schema_limit( $schema, 'videos', 0 );
		$resources_limit = self::schema_limit( $schema, 'resources', 0 );
		$tips_limit      = self::schema_limit( $schema, 'tips', 0 );

		$lesson_videos_limit_raw = get_post_meta( $lesson_id, '_clms_lesson_ui_limit_videos', true );
		if ( '' !== (string) $lesson_videos_limit_raw ) {
			$videos_limit = max( 0, absint( $lesson_videos_limit_raw ) );
		}

		$lesson_resources_limit_raw = get_post_meta( $lesson_id, '_clms_lesson_ui_limit_resources', true );
		if ( '' !== (string) $lesson_resources_limit_raw ) {
			$resources_limit = max( 0, absint( $lesson_resources_limit_raw ) );
		}

		$lesson_tips_limit_raw = get_post_meta( $lesson_id, '_clms_lesson_ui_limit_tips', true );
		if ( '' !== (string) $lesson_tips_limit_raw ) {
			$tips_limit = max( 0, absint( $lesson_tips_limit_raw ) );
		}

		return array(
			'videos'    => $videos_limit,
			'resources' => $resources_limit,
			'tips'      => $tips_limit,
		);
	}

	private static function schema_limit( array $schema, string $key, int $default = 0 ): int {
		$engine = atora_lms() ? atora_lms()->get_module( 'CLMS_UI_Template_Engine' ) : null;
		if ( $engine instanceof CLMS_UI_Template_Engine ) {
			return $engine->get_limit( $schema, $key, $default );
		}

		$limits = $schema['limits'] ?? array();
		if ( ! is_array( $limits ) || ! array_key_exists( $key, $limits ) ) {
			return max( 0, $default );
		}

		return max( 0, absint( $limits[ $key ] ) );
	}

	private static function normalize_videos( int $lesson_id ): array {
		$extra_videos = get_post_meta( $lesson_id, '_clms_lesson_extra_videos', true );
		$extra_videos = is_array( $extra_videos )
			? array_values(
				array_filter(
					$extra_videos,
					static function ( $video ) {
						return is_array( $video ) && ! empty( $video['url'] );
					}
				)
			)
			: array();

		if ( ! empty( $extra_videos ) ) {
			return $extra_videos;
		}

		$legacy_url    = (string) get_post_meta( $lesson_id, '_clms_lesson_video_url', true );
		$legacy_source = (string) ( get_post_meta( $lesson_id, '_clms_lesson_video_source', true ) ?: 'youtube' );

		if ( ! $legacy_url ) {
			return array();
		}

		return array(
			array(
				'source'      => $legacy_source,
				'url'         => $legacy_url,
				'description' => '',
				'tip_1'       => get_post_meta( $lesson_id, '_clms_tip_1', true ),
				'tip_2'       => get_post_meta( $lesson_id, '_clms_tip_2', true ),
				'tip_3'       => get_post_meta( $lesson_id, '_clms_tip_3', true ),
			),
		);
	}

	private static function normalize_resources( int $lesson_id ): array {
		$resources = get_post_meta( $lesson_id, '_clms_lesson_resources', true );
		if ( ! is_array( $resources ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$resources,
				static function ( $resource ) {
					return is_array( $resource )
						&& (
							! empty( $resource['title'] )
							|| ! empty( $resource['file_id'] )
							|| ! empty( $resource['url'] )
						);
				}
			)
		);
	}

	private static function render_video_player( string $source, string $url, string $thumb_url = '' ): string {
		if ( ! $url ) {
			return '';
		}

		if ( 'url' === $source ) {
			$poster = $thumb_url ? ' poster="' . esc_url( $thumb_url ) . '"' : '';
			return '<div class="atora-lesson-video">'
				. '<div class="atora-lesson-video-ratio">'
				. '<video controls preload="metadata"' . $poster . '>'
				. '<source src="' . esc_url( $url ) . '">'
				. '</video>'
				. '</div>'
				. '</div>';
		}

		$embed_src = '';

		if ( 'youtube' === $source && preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $url, $match ) ) {
			$embed_src = 'https://www.youtube-nocookie.com/embed/' . $match[1] . '?rel=0&autoplay=1';
			if ( ! $thumb_url ) {
				$thumb_url = 'https://img.youtube.com/vi/' . $match[1] . '/hqdefault.jpg';
			}
		} elseif ( 'vimeo' === $source && preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $url, $match ) ) {
			$embed_src = 'https://player.vimeo.com/video/' . $match[1] . '?autoplay=1';
		} elseif ( 'bunny' === $source ) {
			$embed_src = $url;
		} elseif ( 'drive' === $source ) {
			if ( preg_match( '#/file/d/([a-zA-Z0-9_\-]+)#', $url, $match ) ) {
				$embed_src = 'https://drive.google.com/file/d/' . $match[1] . '/preview';
			} elseif ( preg_match( '#[?&]id=([a-zA-Z0-9_\-]+)#', $url, $match ) ) {
				$embed_src = 'https://drive.google.com/file/d/' . $match[1] . '/preview';
			}
		}

		if ( ! $embed_src ) {
			return '';
		}

		$wrap_open  = '<div class="atora-lesson-video"><div class="atora-lesson-video-ratio';
		$wrap_close = '</div></div>';

		if ( $thumb_url ) {
			$play_icon  = '<svg viewBox="0 0 68 48" width="72" height="52" xmlns="http://www.w3.org/2000/svg">';
			$play_icon .= '<path d="M66.5 7.7a8.5 8.5 0 00-6-6C55.8 0 34 0 34 0S12.2 0 7.5 1.7a8.5 8.5 0 00-6 6C0 11.4 0 19.2 0 24s0 12.6 1.5 16.3a8.5 8.5 0 006 6C12.2 48 34 48 34 48s21.8 0 26.5-1.7a8.5 8.5 0 006-6C68 36.6 68 31.8 68 27.2V20.8C68 16.2 68 11.4 66.5 7.7z" fill="rgba(0,0,0,.8)"/>';
			$play_icon .= '<polygon points="27,15 45,24 27,33" fill="#fff"/></svg>';

			return $wrap_open . ' atora-lite-player" data-src="' . esc_attr( $embed_src ) . '">'
				. '<img class="atora-lite-thumb" src="' . esc_url( $thumb_url ) . '" alt="" loading="lazy">'
				. '<div class="atora-lite-overlay"></div>'
				. '<button class="atora-lite-play" type="button" aria-label="' . esc_attr__( 'Reproducir video', 'atora-lms' ) . '">' . $play_icon . '</button>'
				. $wrap_close;
		}

		return $wrap_open . '">'
			. '<iframe src="' . esc_url( $embed_src ) . '" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
			. $wrap_close;
	}

	private static function get_resource_video_embed( string $type, string $url ): string {
		if ( 'video' !== $type || ! $url ) {
			return '';
		}

		if ( preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $url, $match ) ) {
			return '<div class="atora-resource-video"><iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $match[1] ) . '" allowfullscreen loading="lazy"></iframe></div>';
		}

		if ( preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $url, $match ) ) {
			return '<div class="atora-resource-video"><iframe src="https://player.vimeo.com/video/' . esc_attr( $match[1] ) . '" allowfullscreen loading="lazy"></iframe></div>';
		}

		return '';
	}

	private static function strip_shortcodes_from_content( string $content, array $shortcode_tags ): string {
		$shortcode_tags = array_values(
			array_filter(
				array_map(
					static function ( $tag ): string {
						return sanitize_key( (string) $tag );
					},
					$shortcode_tags
				),
				'strlen'
			)
		);

		if ( '' === trim( $content ) || empty( $shortcode_tags ) ) {
			return $content;
		}

		$pattern = '/' . get_shortcode_regex( $shortcode_tags ) . '/s';
		$updated = preg_replace( $pattern, '', $content );
		return is_string( $updated ) ? $updated : $content;
	}

	private static function student_has_quiz_attempt( int $lesson_id, int $user_id ): bool {
		$lesson_id = absint( $lesson_id );
		$user_id   = absint( $user_id );
		if ( ! $lesson_id || ! $user_id ) {
			return false;
		}

		$attempt = get_user_meta( $user_id, 'clms_quiz_attempt_' . $lesson_id, true );
		if ( is_array( $attempt ) ) {
			return ! empty( $attempt );
		}

		return '' !== (string) $attempt;
	}

	private static function student_has_read_evidence( int $lesson_id, int $user_id ): bool {
		$lesson_id = absint( $lesson_id );
		$user_id   = absint( $user_id );
		if ( ! $lesson_id || ! $user_id ) {
			return false;
		}

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( ! $evidence_service || ! method_exists( $evidence_service, 'get_read_evidence_record' ) ) {
			return false;
		}

		$record = (array) $evidence_service->get_read_evidence_record( $user_id, $lesson_id );
		return ! empty( $record['seen'] ) || '' !== trim( (string) ( $record['comment'] ?? '' ) );
	}

	private static function student_has_submission( int $lesson_id, int $user_id ): bool {
		$lesson_id = absint( $lesson_id );
		$user_id   = absint( $user_id );
		if ( ! $lesson_id || ! $user_id ) {
			return false;
		}

		$submission = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Submission') : null;
		if ( $submission && method_exists( $submission, 'get_user_submission_for_grading' ) ) {
			$data = (array) $submission->get_user_submission_for_grading( $user_id, $lesson_id );
			return ! empty( $data['submission_id'] );
		}

		return false;
	}

	private static function has_enabled_section( array $schema, string $target_id ): bool {
		$target_id = sanitize_key( $target_id );
		if ( ! $target_id ) {
			return false;
		}

		foreach ( (array) ( $schema['sections'] ?? array() ) as $entry ) {
			if ( is_string( $entry ) ) {
				if ( sanitize_key( $entry ) === $target_id ) {
					return true;
				}
				continue;
			}

			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}

			if ( sanitize_key( (string) $entry['id'] ) !== $target_id ) {
				continue;
			}

			if ( ! array_key_exists( 'enabled', $entry ) || (bool) $entry['enabled'] ) {
				return true;
			}
		}

		return false;
	}

	private static function partial_path( string $filename ): string {
		$child_path  = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/lesson/' . $filename;
		$plugin_path = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/lesson/' . $filename;

		return file_exists( $child_path ) ? $child_path : $plugin_path;
	}
}
