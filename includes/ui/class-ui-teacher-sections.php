<?php
/**
 * CLMS_UI_Teacher_Sections
 *
 * Secciones del perfil público de un docente (atora_teacher).
 * Secciones: hero, bio, achievements, courses, stats.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Teacher_Sections {

	// ── Cache ────────────────────────────────────────────────────────────────

	/** @var array<int,array> */
	private static array $cache = array();

	// ── Registro ─────────────────────────────────────────────────────────────

	public static function register_callbacks(): void {
		$registry = class_exists( 'CLMS_UI_Section_Registry' )
			? CLMS_UI_Section_Registry::instance()
			: null;

		if ( ! $registry ) {
			return;
		}

		$sections = array(
			'teacher_hero'         => array( 'label' => __( 'Docente — Cabecera',        'atora-lms' ), 'callback' => array( __CLASS__, 'render_hero' ) ),
			'teacher_bio'          => array( 'label' => __( 'Docente — Biografía',       'atora-lms' ), 'callback' => array( __CLASS__, 'render_bio' ) ),
			'teacher_achievements' => array( 'label' => __( 'Docente — Logros + Video',  'atora-lms' ), 'callback' => array( __CLASS__, 'render_achievements' ) ),
			'teacher_courses'      => array( 'label' => __( 'Docente — Cursos',          'atora-lms' ), 'callback' => array( __CLASS__, 'render_courses' ) ),
			'teacher_stats'        => array( 'label' => __( 'Docente — Estadísticas',    'atora-lms' ), 'callback' => array( __CLASS__, 'render_stats' ) ),
			'teacher_extra'        => array( 'label' => __( 'Docente — Sección extra',   'atora-lms' ), 'callback' => array( __CLASS__, 'render_extra' ) ),
		);

		foreach ( $sections as $id => $args ) {
			$registry->register( $id, array(
				'label'           => $args['label'],
				'contexts'        => array( 'teacher' ),
				'render_callback' => $args['callback'],
			) );
		}
	}

	// ── Datos ─────────────────────────────────────────────────────────────────

	public static function data( int $teacher_id ): array {
		if ( isset( self::$cache[ $teacher_id ] ) ) {
			return self::$cache[ $teacher_id ];
		}

		$post = get_post( $teacher_id );
		if ( ! $post || 'atora_teacher' !== $post->post_type ) {
			self::$cache[ $teacher_id ] = array();
			return array();
		}

		$name      = $post->post_title ? $post->post_title : __( 'Docente', 'atora-lms' );
		$short_bio = (string) get_post_field( 'post_excerpt', $teacher_id );
		$long_bio  = (string) get_post_field( 'post_content', $teacher_id );
		$specialty = (string) get_post_meta( $teacher_id, '_clms_teacher_specialty', true );
		$ach_raw   = (string) get_post_meta( $teacher_id, '_clms_teacher_achievements', true );
		$soc_raw   = (string) get_post_meta( $teacher_id, '_clms_teacher_socials', true );
		$photo_id  = (int) get_post_thumbnail_id( $teacher_id );

		$video_url     = (string) get_post_meta( $teacher_id, '_clms_teacher_video', true );
		$extra_title   = (string) get_post_meta( $teacher_id, '_clms_teacher_extra_title', true );
		$extra_content = (string) get_post_meta( $teacher_id, '_clms_teacher_extra_content', true );

		$data = array(
			'teacher_id'          => $teacher_id,
			'name'                => sanitize_text_field( $name ),
			'short_bio'           => sanitize_text_field( $short_bio ),
			'long_bio'            => $long_bio,
			'specialty'           => sanitize_text_field( $specialty ),
			'achievements'        => self::parse_lines( $ach_raw ),
			'socials'             => self::parse_socials( $soc_raw ),
			'photo_id'            => $photo_id,
			'profile_url'         => (string) get_permalink( $teacher_id ),
			'video_url'           => esc_url_raw( $video_url ),
			'extra_title'         => sanitize_text_field( $extra_title ),
			'extra_content'       => $extra_content,
			'published_courses'   => array(),
			'published_courses_n' => 0,
		);

		// Cursos donde el docente aparece en _clms_course_teacher_ids
		$linked = get_posts( array(
			'post_type'              => 'lm_course',
			'post_status'            => 'publish',
			'posts_per_page'         => 50,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( array(
				'key'     => '_clms_course_teacher_ids',
				'value'   => $teacher_id,
				'compare' => 'LIKE',
			) ),
		) );
		$data['published_courses']   = $linked ?: array();
		$data['published_courses_n'] = count( $data['published_courses'] );

		self::$cache[ $teacher_id ] = $data;
		return $data;
	}

	// ── Parsers ───────────────────────────────────────────────────────────────

	private static function parse_lines( string $raw ): array {
		$raw   = trim( $raw );
		$items = array();
		if ( '' === $raw ) {
			return $items;
		}
		foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$items[] = sanitize_text_field( $line );
			}
		}
		return $items;
	}

	private static function parse_socials( string $raw ): array {
		$raw   = trim( $raw );
		$items = array();
		if ( '' === $raw ) {
			return $items;
		}
		foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
			$line  = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$label = sanitize_text_field( $parts[0] );
			$url   = ! empty( $parts[1] ) ? esc_url_raw( $parts[1] ) : '';
			if ( $label && $url ) {
				$items[] = array( 'label' => $label, 'url' => $url );
			}
		}
		return $items;
	}

	// ── Render callbacks ─────────────────────────────────────────────────────

	public static function render_hero( array $section, $ctx, array $schema ): void {
		$teacher_id = $ctx->entity_id;
		$data       = self::data( $teacher_id );
		if ( empty( $data ) ) {
			return;
		}
		$partial = self::resolve_partial( 'teacher/section-hero.php' );
		if ( $partial ) {
			extract( array( 'data' => $data, 'section' => $section, 'schema' => $schema, 'ctx' => $ctx ) );
			include $partial;
		}
	}

	public static function render_bio( array $section, $ctx, array $schema ): void {
		$teacher_id = $ctx->entity_id;
		$data       = self::data( $teacher_id );
		if ( empty( $data ) || '' === trim( $data['long_bio'] ) ) {
			return;
		}
		$partial = self::resolve_partial( 'teacher/section-bio.php' );
		if ( $partial ) {
			extract( array( 'data' => $data, 'section' => $section, 'schema' => $schema, 'ctx' => $ctx ) );
			include $partial;
		}
	}

	public static function render_achievements( array $section, $ctx, array $schema ): void {
		$teacher_id = $ctx->entity_id;
		$data       = self::data( $teacher_id );
		if ( empty( $data ) || empty( $data['achievements'] ) ) {
			return;
		}
		$partial = self::resolve_partial( 'teacher/section-achievements.php' );
		if ( $partial ) {
			extract( array( 'data' => $data, 'section' => $section, 'schema' => $schema, 'ctx' => $ctx ) );
			include $partial;
		}
	}

	public static function render_courses( array $section, $ctx, array $schema ): void {
		$teacher_id   = $ctx->entity_id;
		$data         = self::data( $teacher_id );
		$courses_limit = isset( $schema['limits']['courses_limit'] ) ? (int) $schema['limits']['courses_limit'] : 6;
		if ( empty( $data ) || empty( $data['published_courses'] ) ) {
			return;
		}
		$courses = array_slice( $data['published_courses'], 0, max( 1, $courses_limit ) );
		$partial = self::resolve_partial( 'teacher/section-courses.php' );
		if ( $partial ) {
			extract( array( 'data' => $data, 'courses' => $courses, 'section' => $section, 'schema' => $schema, 'ctx' => $ctx ) );
			include $partial;
		}
	}

	public static function render_stats( array $section, $ctx, array $schema ): void {
		$teacher_id = $ctx->entity_id;
		$data       = self::data( $teacher_id );
		if ( empty( $data ) ) {
			return;
		}
		$partial = self::resolve_partial( 'teacher/section-stats.php' );
		if ( $partial ) {
			extract( array( 'data' => $data, 'section' => $section, 'schema' => $schema, 'ctx' => $ctx ) );
			include $partial;
		}
	}

	public static function render_extra( array $section, $ctx, array $schema ): void {
		$teacher_id = $ctx->entity_id;
		$data       = self::data( $teacher_id );
		if ( empty( $data ) || ( '' === trim( $data['extra_title'] ) && '' === trim( $data['extra_content'] ) ) ) {
			return;
		}
		$partial = self::resolve_partial( 'teacher/section-extra.php' );
		if ( $partial ) {
			extract( array( 'data' => $data, 'section' => $section, 'schema' => $schema, 'ctx' => $ctx ) );
			include $partial;
		}
	}

	// ── Helper: convierte URL de video en iframe embed ────────────────────────

	public static function get_video_embed( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		// YouTube
		if ( preg_match( '/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
			$id = $m[1];
			return '<iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $id ) . '" frameborder="0" allowfullscreen loading="lazy" title="Video"></iframe>';
		}
		// Vimeo
		if ( preg_match( '/vimeo\.com\/(?:video\/)?(\d+)/', $url, $m ) ) {
			$id = $m[1];
			return '<iframe src="https://player.vimeo.com/video/' . esc_attr( $id ) . '" frameborder="0" allowfullscreen loading="lazy" title="Video"></iframe>';
		}
		// Video directo (mp4, webm, ogv)
		if ( preg_match( '/\.(mp4|webm|ogv)(\?.*)?$/i', $url ) ) {
			return '<video controls preload="metadata" style="width:100%;border-radius:inherit">'
				. '<source src="' . esc_url( $url ) . '">'
				. '</video>';
		}
		return '';
	}

	// ── Helper ────────────────────────────────────────────────────────────────

	private static function resolve_partial( string $relative ): string {
		// Child-theme override → plugin fallback
		$child = get_stylesheet_directory() . '/atora-lms/templates/partials/' . $relative;
		if ( file_exists( $child ) ) {
			return $child;
		}
		$plugin = defined( 'ATORA_LMS_DIR' )
			? trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/' . $relative
			: trailingslashit( dirname( dirname( __DIR__ ) ) ) . 'templates/partials/' . $relative;
		return file_exists( $plugin ) ? $plugin : '';
	}
}
