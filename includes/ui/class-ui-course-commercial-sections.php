<?php
/**
 * CLMS_UI_Course_Commercial_Sections
 *
 * Registro de secciones de la landing comercial de cursos.
 * Cada sección tiene un render_callback estático que extrae datos del caché
 * y hace include del partial correspondiente.
 *
 * Uso: CLMS_UI_Course_Commercial_Sections::register_callbacks() — llamado vía clms_ui_loaded.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Course_Commercial_Sections {

	// ── Cache de datos ─────────────────────────────────────────────────────────

	/** @var array<int,array> */
	private static array $cache = array();

	// ── Parsers de campos (JSON-first con fallback a formato legado) ───────────

	/**
	 * FAQ: JSON array de {"q","a"} o legado "pregunta|respuesta\n".
	 */
	private static function parse_faq( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded ) ) {
			$items = array();
			foreach ( $decoded as $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				$q = trim( (string) ( $entry['q'] ?? '' ) );
				if ( '' === $q ) { continue; }
				$items[] = array(
					'q' => $q,
					'a' => trim( (string) ( $entry['a'] ?? '' ) ),
				);
			}
			return $items;
		}
		// Legado: "pregunta|respuesta" por línea
		$items = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$parts = explode( '|', $line, 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) ) {
				$items[] = array(
					'q' => trim( $parts[0] ),
					'a' => trim( $parts[1] ),
				);
			}
		}
		return $items;
	}

	/**
	 * Testimonials: JSON array de {"name","role","text","rating"} o legado "nombre|rol|texto\n".
	 * El campo de texto siempre se normaliza a la clave "text" (antes "quote").
	 */
	private static function parse_testimonials( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded ) ) {
			$items = array();
			foreach ( $decoded as $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				// Acepta tanto "text" (v2) como "quote" (legado JSON)
				$text = trim( (string) ( $entry['text'] ?? $entry['quote'] ?? '' ) );
				if ( '' === $text ) { continue; }
				$items[] = array(
					'name'   => trim( (string) ( $entry['name'] ?? '' ) ),
					'role'   => trim( (string) ( $entry['role'] ?? '' ) ),
					'text'   => $text,
					'rating' => isset( $entry['rating'] ) ? min( 5, max( 0, (int) $entry['rating'] ) ) : 0,
				);
			}
			return $items;
		}
		// Legado: "nombre|rol|texto" por línea
		$items = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$parts = explode( '|', $line, 3 );
			if ( 3 === count( $parts ) && '' !== trim( $parts[0] ) ) {
				$items[] = array(
					'name'   => trim( $parts[0] ),
					'role'   => trim( $parts[1] ),
					'text'   => trim( $parts[2] ),
					'rating' => 0,
				);
			}
		}
		return $items;
	}

	/**
	 * Benefits: JSON array de strings o legado texto libre con separadores ✓ • ; /.
	 */
	private static function parse_benefits( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded ) ) {
			return array_values( array_filter( array_map( function ( $s ) {
				return trim( (string) $s );
			}, $decoded ), 'strlen' ) );
		}
		// Legado: texto libre con múltiples separadores posibles
		$items = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) { continue; }
			$parts = preg_split( '/\s*(?:✓|•|;|\/)\s*/u', $line );
			if ( ! is_array( $parts ) || empty( $parts ) ) {
				$parts = array( $line );
			}
			foreach ( $parts as $part ) {
				$part = preg_replace( '/^[\-\x{2022}\x{2713}\*\s]+/u', '', (string) $part );
				$part = trim( $part );
				if ( '' !== $part ) {
					$items[] = $part;
				}
			}
		}
		return array_values( array_unique( $items ) );
	}

	/**
	 * Requirements / listas simples: JSON array de strings o legado una línea por ítem.
	 */
	private static function parse_string_list( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded ) ) {
			return array_values( array_filter( array_map( function ( $s ) {
				return trim( (string) $s );
			}, $decoded ), 'strlen' ) );
		}
		// Legado: una línea por ítem, strip bullet chars
		$items = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
			$line = preg_replace( '/^[\-\*\x{2022}\x{2713}\s]+/u', '', (string) $line );
			$line = trim( $line );
			if ( '' !== $line ) {
				$items[] = $line;
			}
		}
		return array_values( array_unique( $items ) );
	}

	/**
	 * Gallery: JSON array de enteros o legado IDs separados por coma.
	 */
	private static function parse_gallery_ids( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded ) ) {
			return array_values( array_filter( array_map( 'absint', $decoded ) ) );
		}
		// Legado: "123,456,789"
		$ids = array();
		foreach ( explode( ',', $raw ) as $gid ) {
			$gid = absint( trim( $gid ) );
			if ( $gid ) {
				$ids[] = $gid;
			}
		}
		return $ids;
	}

	/**
	 * Carga y cachea todos los datos del curso comercial en una sola pasada.
	 * Los callbacks de sección llaman a este método para obtener sus variables.
	 *
	 * @param int $course_id
	 * @return array
	 */
	public static function data( int $course_id ): array {
		if ( isset( self::$cache[ $course_id ] ) ) {
			return self::$cache[ $course_id ];
		}

		$title           = (string) get_the_title( $course_id );
		$subtitle        = (string) get_post_meta( $course_id, '_clms_course_subtitle', true );
		$tagline         = (string) get_post_meta( $course_id, '_clms_commercial_tagline', true );
		$benefits_raw    = get_post_meta( $course_id, '_clms_course_benefits', true );
		$benefits        = is_array( $benefits_raw ) ? implode( "\n", $benefits_raw ) : (string) $benefits_raw;
		$requirements_raw = get_post_meta( $course_id, '_clms_course_requirements', true );
		$requirements    = is_array( $requirements_raw ) ? implode( "\n", $requirements_raw ) : (string) $requirements_raw;
		$duration        = (string) get_post_meta( $course_id, '_clms_course_duration', true );
		$price           = (string) get_post_meta( $course_id, '_clms_course_price', true );
		$price_label     = (string) get_post_meta( $course_id, '_clms_course_price_label', true );
		$certificate     = (string) get_post_meta( $course_id, '_clms_course_certificate', true );
		$hero_video      = (string) get_post_meta( $course_id, '_clms_commercial_hero_video', true );
		$hero_vid_src    = (string) get_post_meta( $course_id, '_clms_commercial_hero_video_source', true ) ?: 'youtube';
		$ingress         = (string) get_post_meta( $course_id, '_clms_commercial_ingress_profile', true );
		$egress          = (string) get_post_meta( $course_id, '_clms_commercial_egress_profile', true );
		$testimonials    = (string) get_post_meta( $course_id, '_clms_commercial_testimonials', true );
		$gallery_ids_raw = (string) get_post_meta( $course_id, '_clms_commercial_gallery_ids', true );
		$faq_raw         = (string) get_post_meta( $course_id, '_clms_commercial_faq', true );
		$course_permalink = (string) get_permalink( $course_id );

		$offer_data  = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_offer_data( $course_id ) : array();
		$cta_url     = $offer_data['url'] ?? '';
		$cta_label   = $offer_data['label'] ?? '';

		if ( '' === trim( $cta_url ) ) {
			$cta_url = (string) get_post_meta( $course_id, '_clms_commercial_cta_url', true );
		}
		if ( '' === trim( $cta_label ) ) {
			$cta_label = (string) get_post_meta( $course_id, '_clms_commercial_cta_label', true );
			if ( '' === trim( $cta_label ) ) {
				$cta_label = __( 'Inscribirme ahora', 'atora-lms' );
			}
		}
		if ( '' === trim( $price_label ) && ! empty( $offer_data['price_label'] ) ) {
			$price_label = (string) $offer_data['price_label'];
		}
		if ( '' === trim( $price ) && ! empty( $offer_data['price'] ) ) {
			$price = (string) $offer_data['price'];
		}

		// Instructor HTML
		$instructor_html       = '';
		$teacher_section_title = __( 'Tu docente', 'atora-lms' );
		$teacher_ids           = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		$teacher_ids           = is_array( $teacher_ids ) ? array_values( array_filter( array_map( 'absint', $teacher_ids ) ) ) : array();
		if ( count( $teacher_ids ) > 1 ) {
			$teacher_section_title = __( 'Equipo docente', 'atora-lms' );
		}
		if ( class_exists( 'CLMS_Instructor' ) ) {
			$instructor = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' )
				? clms_core('CLMS_Instructor')
				: null;
			if ( ! $instructor ) {
				$instructor = new CLMS_Instructor();
			}
			if ( method_exists( $instructor, 'get_teachers_html_for_course' ) ) {
				$instructor_html = $instructor->get_teachers_html_for_course(
					$course_id,
					array(
						'show_bio'          => true,
						'show_specialty'    => true,
						'show_achievements' => true,
						'show_socials'      => true,
						'show_profile_link' => true,
					)
				);
			} elseif ( method_exists( $instructor, 'get_instructor_box_html' ) ) {
				$instructor_html = $instructor->get_instructor_box_html( $course_id, array(
					'show_courses'  => true,
					'courses_limit' => 3,
					'show_bio'      => true,
					'show_socials'  => true,
					'show_meta'     => true,
				) );
			}
			// else: método no disponible en esta versión del módulo → $instructor_html queda vacío
		}

		// Lessons
		$lesson_ids = array();
		if ( class_exists( 'CLMS_Helper' ) ) {
			$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
			$lesson_ids = is_array( $lesson_ids ) ? array_values( array_map( 'absint', $lesson_ids ) ) : array();
		}

		$program_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_program_ids( $course_id ) : array();

		$competency_count = 0;
		$evidence_summary = array(
			'required'    => 0,
			'certifiable' => 0,
		);

		$competency_service = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' )
			? clms_core('CLMS_Competency_Service')
			: null;
		if ( $competency_service && method_exists( $competency_service, 'get_course_competencies' ) ) {
			$competencies      = (array) $competency_service->get_course_competencies( $course_id );
			$competency_count  = count( $competencies );
		}

		$evidence_service = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' )
			? clms_core('CLMS_Evidence_Service')
			: null;
		if ( $evidence_service && method_exists( $evidence_service, 'get_course_required_evidences' ) ) {
			$required_evidences           = (array) $evidence_service->get_course_required_evidences( $course_id );
			$evidence_summary['required'] = count( $required_evidences );
			$evidence_summary['certifiable'] = count(
				array_filter(
					$required_evidences,
					static function ( $item ) {
						if ( ! is_array( $item ) ) {
							return false;
						}
						if ( ! empty( $item['is_required_for_certificate'] ) ) {
							return true;
						}
						$type = isset( $item['evidence_type'] ) ? sanitize_key( (string) $item['evidence_type'] ) : '';
						return in_array( $type, array( 'evidencia_certificable', 'final', 'evaluation_final' ), true );
					}
				)
			);
		}

		// Summary include items
		$course_include_items = array();
		if ( $duration ) {
			$course_include_items[] = sprintf( __( 'Duración estimada: %s', 'atora-lms' ), $duration );
		}
		if ( ! empty( $lesson_ids ) ) {
			$course_include_items[] = sprintf( __( '%d lecciones prácticas', 'atora-lms' ), count( $lesson_ids ) );
		}
		if ( $certificate ) {
			$course_include_items[] = __( 'Certificado al finalizar', 'atora-lms' );
		}
		if ( ! empty( $program_ids ) ) {
			$course_include_items[] = __( 'Forma parte de un programa académico', 'atora-lms' );
		}
		if ( $competency_count > 0 ) {
			$course_include_items[] = sprintf( __( '%d competencias clave', 'atora-lms' ), $competency_count );
		}
		if ( $evidence_summary['required'] > 0 ) {
			$course_include_items[] = sprintf( __( '%d evidencias evaluables', 'atora-lms' ), absint( $evidence_summary['required'] ) );
		}
		$course_include_items = array_slice( array_values( $course_include_items ), 0, 4 );

		// Hero video
		$embed_src                = '';
		$hero_direct_video_url    = '';
		$hero_fallback_iframe_src = '';
		if ( $hero_video ) {
			$hero_video = trim( $hero_video );
			if ( preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $hero_video, $m ) && in_array( $hero_vid_src, array( 'youtube', 'url' ), true ) ) {
				$embed_src = 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&showinfo=0';
			} elseif ( preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $hero_video, $m ) && in_array( $hero_vid_src, array( 'vimeo', 'url' ), true ) ) {
				$embed_src = 'https://player.vimeo.com/video/' . $m[1];
			} elseif ( 'url' === $hero_vid_src ) {
				if ( preg_match( '#drive\.google\.com/file/d/([^/\?]+)#', $hero_video, $m ) ) {
					$hero_fallback_iframe_src = 'https://drive.google.com/file/d/' . $m[1] . '/preview';
				} elseif ( preg_match( '#(?:drive\.google\.com/open\?id=|docs\.google\.com/uc\?id=)([^&]+)#', $hero_video, $m ) ) {
					$hero_fallback_iframe_src = 'https://drive.google.com/file/d/' . $m[1] . '/preview';
				} elseif ( preg_match( '/\.(?:mp4|webm|ogg|m4v|mov)(?:\?.*)?$/i', $hero_video ) ) {
					$hero_direct_video_url = $hero_video;
				} else {
					$hero_fallback_iframe_src = $hero_video;
				}
			}
		}

		// Gallery — JSON array de ints o legado CSV
		$gallery_image_ids = self::parse_gallery_ids( $gallery_ids_raw );

		// FAQ — JSON array de {q,a} o legado "q|a" por línea
		$faq_items = self::parse_faq( $faq_raw );

		// Testimonials — JSON array de {name,role,text,rating} o legado "name|role|text"
		// La clave de texto se normaliza siempre a "text" (antes era "quote")
		$testimonial_items = self::parse_testimonials( $testimonials );

		// Benefits — JSON array de strings o legado texto con separadores ✓ • ; /
		$benefit_items = self::parse_benefits( $benefits );

		// Requirements — JSON array de strings o legado una línea por ítem
		// $requirements se conserva como string raw para compatibilidad; $requirement_items es el array listo
		$requirement_items = self::parse_string_list( $requirements );

		// Related items
		$related_items = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_commercial_related_items( $course_id, 6 ) : array();

		$related_type_labels = array(
			'course'     => __( 'Curso', 'atora-lms' ),
			'curso'      => __( 'Curso', 'atora-lms' ),
			'program'    => __( 'Programa', 'atora-lms' ),
			'programa'   => __( 'Programa', 'atora-lms' ),
			'mentoria'   => __( 'Mentoría', 'atora-lms' ),
			'mentoring'  => __( 'Mentoría', 'atora-lms' ),
			'bundle'     => __( 'Paquete', 'atora-lms' ),
			'membership' => __( 'Membresía', 'atora-lms' ),
			'recurso'    => __( 'Recurso', 'atora-lms' ),
			'resource'   => __( 'Recurso', 'atora-lms' ),
			'podcast'    => __( 'Podcast', 'atora-lms' ),
			'book'       => __( 'Libro', 'atora-lms' ),
		);

		// Hero image (computed here so all vars exist before cache)
		$hero_featured_image_url  = (string) get_the_post_thumbnail_url( $course_id, 'large' );
		$hero_featured_image_html = (string) get_the_post_thumbnail(
			$course_id,
			'large',
			array(
				'style' => 'width:100%;border-radius:14px;display:block',
				'alt'   => esc_attr( $title ),
			)
		);
		$hero_has_direct_video = '' !== $hero_direct_video_url;
		$hero_has_video_media  = (bool) ( $embed_src || $hero_fallback_iframe_src || $hero_has_direct_video );

		self::$cache[ $course_id ] = compact(
			'title', 'subtitle', 'tagline', 'benefits', 'requirements',
			'duration', 'price', 'price_label', 'certificate',
			'hero_video', 'hero_vid_src', 'ingress', 'egress',
			'offer_data', 'cta_url', 'cta_label', 'course_permalink',
			'instructor_html', 'teacher_section_title',
			'lesson_ids', 'program_ids', 'course_include_items',
			'competency_count', 'evidence_summary',
			'embed_src', 'hero_direct_video_url', 'hero_fallback_iframe_src',
			'hero_featured_image_url', 'hero_featured_image_html',
			'hero_has_direct_video', 'hero_has_video_media',
			'gallery_image_ids', 'faq_items', 'testimonial_items', 'benefit_items',
			'requirement_items', 'related_items', 'related_type_labels'
		);

		return self::$cache[ $course_id ];
	}

	// ── Callbacks de render ────────────────────────────────────────────────────

	public static function render_hero( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d       = self::data( $ctx->entity_id );
		$variant = sanitize_key( (string) ( $section['variant'] ?? 'default' ) );
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = self::variant_partial( 'section-hero', $variant );
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_summary( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['course_include_items'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-summary.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-summary.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_instructor( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['instructor_html'] ) ) { return; }
		$variant = sanitize_key( (string) ( $section['variant'] ?? 'default' ) );
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = self::variant_partial( 'section-instructor', $variant );
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_benefits( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['benefit_items'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-benefits.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-benefits.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_profiles( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['ingress'] ) && empty( $d['egress'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-profiles.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-profiles.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_curriculum( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['lesson_ids'] ) ) { return; }
		$variant = sanitize_key( (string) ( $section['variant'] ?? 'default' ) );
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$engine = atora_lms()->get_module( 'CLMS_UI_Template_Engine' );

		if ( $ctx->is_enrolled || $ctx->is_admin ) {
			$preview_max = 0;
		} else {
			$preview_max = $engine instanceof CLMS_UI_Template_Engine ? $engine->get_limit( $schema, 'lesson_preview', 6 ) : 6;
		}

		$partial = self::variant_partial( 'section-curriculum', $variant );
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_requirements( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['requirement_items'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-requirements.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-requirements.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_gallery( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['gallery_image_ids'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$engine        = atora_lms()->get_module( 'CLMS_UI_Template_Engine' );
		$gallery_limit = $engine instanceof CLMS_UI_Template_Engine ? $engine->get_limit( $schema, 'gallery_items', 0 ) : 0;
		if ( $gallery_limit > 0 ) {
			$gallery_image_ids = array_slice( $gallery_image_ids, 0, $gallery_limit );
		}
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-gallery.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-gallery.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_testimonials( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['testimonial_items'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$engine             = atora_lms()->get_module( 'CLMS_UI_Template_Engine' );
		$testimonials_limit = $engine instanceof CLMS_UI_Template_Engine ? $engine->get_limit( $schema, 'testimonial_items', 0 ) : 0;
		if ( $testimonials_limit > 0 ) {
			$testimonial_items = array_slice( $testimonial_items, 0, $testimonials_limit );
		}
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-testimonials.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-testimonials.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_faq( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['faq_items'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-faq.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-faq.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_cta( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['cta_url'] ) && $ctx->is_enrolled ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-cta.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-cta.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_related( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['related_items'] ) ) { return; }
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$engine        = atora_lms()->get_module( 'CLMS_UI_Template_Engine' );
		$related_limit = $engine instanceof CLMS_UI_Template_Engine ? $engine->get_limit( $schema, 'related_items', 6 ) : 6;
		if ( $related_limit > 0 ) {
			$related_items = array_slice( $related_items, 0, $related_limit );
		}
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/section-related.php';
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/section-related.php';
		}
		if ( file_exists( $partial ) ) {
			include $partial;
		}
	}

	public static function render_crm_lead( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		unset( $section, $schema );
		if ( ! class_exists( 'CLMS_UI_CRM_Lead_Section', false ) ) {
			return;
		}
		CLMS_UI_CRM_Lead_Section::render( $ctx->entity_id, $ctx->schema_context );
	}

	// ── Utilidades de partials ────────────────────────────────────────────────

	/**
	 * Resuelve la ruta absoluta de un partial de sección.
	 * Intenta primero el child-theme, luego la ruta del plugin.
	 */
	private static function partial_path( string $filename ): string {
		$child  = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/' . $filename;
		$plugin = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/' . $filename;
		return file_exists( $child ) ? $child : $plugin;
	}

	/**
	 * Ruta del partial de sección, con soporte de variante.
	 * Busca `section-{base}--{variant}.php` primero; cae al default si no existe.
	 */
	private static function variant_partial( string $base, string $variant ): string {
		if ( 'default' !== $variant && '' !== $variant ) {
			$path = self::partial_path( $base . '--' . $variant . '.php' );
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		return self::partial_path( $base . '.php' );
	}

	// ── Registro en el registry ────────────────────────────────────────────────

	/**
	 * Registra los render_callbacks en el Section Registry.
	 * Llamado vía hook clms_ui_loaded.
	 */
	public static function register_callbacks(): void {
		if ( ! class_exists( 'CLMS_UI_Section_Registry' ) ) {
			return;
		}
		$registry = CLMS_UI_Section_Registry::instance();

		$callbacks = array(
			'hero'         => array( self::class, 'render_hero' ),
			'summary'      => array( self::class, 'render_summary' ),
			'instructor'   => array( self::class, 'render_instructor' ),
			'benefits'     => array( self::class, 'render_benefits' ),
			'profiles'     => array( self::class, 'render_profiles' ),
			'curriculum'   => array( self::class, 'render_curriculum' ),
			'requirements' => array( self::class, 'render_requirements' ),
			'gallery'      => array( self::class, 'render_gallery' ),
			'testimonials' => array( self::class, 'render_testimonials' ),
			'faq'          => array( self::class, 'render_faq' ),
			'cta'          => array( self::class, 'render_cta' ),
			'crm_lead'     => array( self::class, 'render_crm_lead' ),
			'related'      => array( self::class, 'render_related' ),
		);

		foreach ( $callbacks as $id => $cb ) {
			$section = $registry->get( $id );
			if ( $section ) {
				$section['render_callback'] = $cb;
				$registry->register( $id, $section );
			}
		}
	}
}
