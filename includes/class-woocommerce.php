<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class CLMS_WooCommerce {

	/**
	 * Meta principal: producto -> curso.
	 *
	 * @var string
	 */
	const PRODUCT_COURSE_META = '_clms_linked_course_id';

	/**
	 * Meta principal: producto -> programa.
	 *
	 * @var string
	 */
	const PRODUCT_PROGRAM_META = '_clms_linked_program_id';

	/**
	 * Meta bundle: producto -> cursos.
	 *
	 * @var string
	 */
	const PRODUCT_BUNDLE_COURSES_META = '_clms_bundle_course_ids';

	/**
	 * Meta bundle: producto -> programas.
	 *
	 * @var string
	 */
	const PRODUCT_BUNDLE_PROGRAMS_META = '_clms_bundle_program_ids';

	/**
	 * Meta de tipo de acceso comercial.
	 *
	 * @var string
	 */
	const PRODUCT_ACCESS_MODE_META = '_clms_access_mode';

	/**
	 * Meta de duración de acceso en días.
	 *
	 * @var string
	 */
	const PRODUCT_ACCESS_DURATION_DAYS_META = '_clms_access_duration_days';

	/**
	 * Meta inversa: curso -> producto.
	 *
	 * @var string
	 */
	const COURSE_PRODUCT_META = '_clms_product_id';

	/**
	 * Meta inversa legacy: curso -> producto.
	 *
	 * @var string
	 */
	const COURSE_PRODUCT_META_LEGACY = '_clms_linked_product_id';

	/**
	 * Meta inversa: programa -> producto.
	 *
	 * @var string
	 */
	const PROGRAM_PRODUCT_META = '_clms_program_product_id';

	/**
	 * Límite razonable para selector del metabox.
	 *
	 * @var int
	 */
	const COURSE_SELECTOR_LIMIT = 200;

	/**
	 * Inicializa hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_product_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_product_metabox' ) );
	}

	/**
	 * Registra metabox en productos.
	 *
	 * @return void
	 */
	public function register_product_metabox() {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}

		add_meta_box(
			'clms-product-course-link',
			'Curso vinculado',
			array( $this, 'render_product_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render del metabox.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public function render_product_metabox( $post ) {
		wp_nonce_field( 'clms_save_product_course_link', 'clms_product_course_nonce' );

		$current_course_id = absint( get_post_meta( $post->ID, self::PRODUCT_COURSE_META, true ) );
		$current_program_id = absint( get_post_meta( $post->ID, self::PRODUCT_PROGRAM_META, true ) );
		$bundle_course_ids  = get_post_meta( $post->ID, self::PRODUCT_BUNDLE_COURSES_META, true );
		$bundle_program_ids = get_post_meta( $post->ID, self::PRODUCT_BUNDLE_PROGRAMS_META, true );
		$access_mode        = (string) get_post_meta( $post->ID, self::PRODUCT_ACCESS_MODE_META, true );
		$access_days        = absint( get_post_meta( $post->ID, self::PRODUCT_ACCESS_DURATION_DAYS_META, true ) );
		$bundle_course_ids  = is_array( $bundle_course_ids ) ? array_map( 'absint', $bundle_course_ids ) : array();
		$bundle_program_ids = is_array( $bundle_program_ids ) ? array_map( 'absint', $bundle_program_ids ) : array();

		$courses = get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => self::COURSE_SELECTOR_LIMIT,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);
		$programs = get_posts(
			array(
				'post_type'              => 'lm_program',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => self::COURSE_SELECTOR_LIMIT,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);
		?>
		<p>
			<label for="clms_access_mode"><strong>Tipo de acceso LMS</strong></label>
		</p>
		<p>
			<select name="clms_access_mode" id="clms_access_mode" style="width:100%;">
				<option value="course" <?php selected( $access_mode, 'course' ); ?>>Curso individual</option>
				<option value="program" <?php selected( $access_mode, 'program' ); ?>>Programa individual</option>
				<option value="bundle" <?php selected( $access_mode, 'bundle' ); ?>>Bundle / paquete</option>
				<option value="membership" <?php selected( $access_mode, 'membership' ); ?>>Membresía</option>
			</select>
		</p>
		<p>
			<label for="clms_linked_course_id"><strong>Curso LMS vinculado</strong></label>
		</p>

		<p>
			<select name="clms_linked_course_id" id="clms_linked_course_id" style="width:100%;">
				<option value="">— Ninguno —</option>
				<?php foreach ( $courses as $course ) : ?>
					<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $current_course_id, $course->ID ); ?>>
						<?php echo esc_html( $course->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="description">
			Al completar la compra, el cliente será inscrito automáticamente en el curso vinculado.
		</p>

		<p>
			<label for="clms_linked_program_id"><strong>Programa LMS vinculado</strong></label>
		</p>
		<p>
			<select name="clms_linked_program_id" id="clms_linked_program_id" style="width:100%;">
				<option value="">— Ninguno —</option>
				<?php foreach ( $programs as $program ) : ?>
					<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( $current_program_id, $program->ID ); ?>>
						<?php echo esc_html( $program->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="clms_bundle_course_ids"><strong>Cursos del bundle</strong></label>
		</p>
		<p>
			<select name="clms_bundle_course_ids[]" id="clms_bundle_course_ids" style="width:100%;min-height:140px" multiple>
				<?php foreach ( $courses as $course ) : ?>
					<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( in_array( $course->ID, $bundle_course_ids, true ), true ); ?>>
						<?php echo esc_html( $course->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="clms_bundle_program_ids"><strong>Programas del bundle</strong></label>
		</p>
		<p>
			<select name="clms_bundle_program_ids[]" id="clms_bundle_program_ids" style="width:100%;min-height:120px" multiple>
				<?php foreach ( $programs as $program ) : ?>
					<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( in_array( $program->ID, $bundle_program_ids, true ), true ); ?>>
						<?php echo esc_html( $program->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="clms_access_duration_days"><strong>Duración de acceso (días)</strong></label>
		</p>
		<p>
			<input type="number" min="0" step="1" name="clms_access_duration_days" id="clms_access_duration_days" style="width:100%;" value="<?php echo esc_attr( $access_days ); ?>">
		</p>
		<p class="description">
			`0` o vacío mantiene acceso sin expiración. Útil para membresías o campañas temporales.
		</p>

		<?php if ( count( $courses ) >= self::COURSE_SELECTOR_LIMIT ) : ?>
			<p class="description">
				Se muestran los primeros <?php echo esc_html( self::COURSE_SELECTOR_LIMIT ); ?> cursos por orden alfabético.
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Guarda metabox producto -> curso.
	 *
	 * @param int $post_id ID del producto.
	 * @return void
	 */
	public function save_product_metabox( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['clms_product_course_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['clms_product_course_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'clms_save_product_course_link' ) ) {
			return;
		}

		if ( ! CLMS_Access::can_manage_commerce() ) {
			return;
		}

		$new_course_id = isset( $_POST['clms_linked_course_id'] )
			? absint( wp_unslash( $_POST['clms_linked_course_id'] ) )
			: 0;
		$new_program_id = isset( $_POST['clms_linked_program_id'] )
			? absint( wp_unslash( $_POST['clms_linked_program_id'] ) )
			: 0;
		$bundle_course_ids = isset( $_POST['clms_bundle_course_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['clms_bundle_course_ids'] ) ) ) ) ) : array();
		$bundle_program_ids = isset( $_POST['clms_bundle_program_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['clms_bundle_program_ids'] ) ) ) ) ) : array();
		$access_mode = isset( $_POST['clms_access_mode'] ) ? sanitize_key( wp_unslash( $_POST['clms_access_mode'] ) ) : 'course';
		$access_mode = in_array( $access_mode, array( 'course', 'program', 'bundle', 'membership' ), true ) ? $access_mode : 'course';
		$access_days = isset( $_POST['clms_access_duration_days'] ) ? absint( wp_unslash( $_POST['clms_access_duration_days'] ) ) : 0;

		$old_course_id = absint( get_post_meta( $post_id, self::PRODUCT_COURSE_META, true ) );
		$old_program_id = absint( get_post_meta( $post_id, self::PRODUCT_PROGRAM_META, true ) );

		if ( $old_course_id && $old_course_id !== $new_course_id ) {
			$current_product_on_course = absint( get_post_meta( $old_course_id, self::COURSE_PRODUCT_META, true ) );

			if ( $current_product_on_course === $post_id ) {
				delete_post_meta( $old_course_id, self::COURSE_PRODUCT_META );
			}
			$current_legacy_product_on_course = absint( get_post_meta( $old_course_id, self::COURSE_PRODUCT_META_LEGACY, true ) );
			if ( $current_legacy_product_on_course === $post_id ) {
				delete_post_meta( $old_course_id, self::COURSE_PRODUCT_META_LEGACY );
			}
		}

		if ( $new_course_id && 'lm_course' !== get_post_type( $new_course_id ) ) {
			$new_course_id = 0;
		}

		if ( $old_program_id && $old_program_id !== $new_program_id ) {
			$current_product_on_program = absint( get_post_meta( $old_program_id, self::PROGRAM_PRODUCT_META, true ) );

			if ( $current_product_on_program === $post_id ) {
				delete_post_meta( $old_program_id, self::PROGRAM_PRODUCT_META );
			}
		}

		if ( $new_program_id && 'lm_program' !== get_post_type( $new_program_id ) ) {
			$new_program_id = 0;
		}

		$bundle_course_ids = array_values(
			array_filter(
				$bundle_course_ids,
				static function( $course_id ) use ( $new_course_id ) {
					return 'lm_course' === get_post_type( $course_id ) && $course_id !== $new_course_id;
				}
			)
		);
		$bundle_program_ids = array_values(
			array_filter(
				$bundle_program_ids,
				static function( $program_id ) use ( $new_program_id ) {
					return 'lm_program' === get_post_type( $program_id ) && $program_id !== $new_program_id;
				}
			)
		);

		if ( $new_course_id ) {
			update_post_meta( $post_id, self::PRODUCT_COURSE_META, $new_course_id );
			update_post_meta( $new_course_id, self::COURSE_PRODUCT_META, $post_id );
			update_post_meta( $new_course_id, self::COURSE_PRODUCT_META_LEGACY, $post_id );
		} else {
			delete_post_meta( $post_id, self::PRODUCT_COURSE_META );
		}

		if ( $new_program_id ) {
			update_post_meta( $post_id, self::PRODUCT_PROGRAM_META, $new_program_id );
			update_post_meta( $new_program_id, self::PROGRAM_PRODUCT_META, $post_id );
		} else {
			delete_post_meta( $post_id, self::PRODUCT_PROGRAM_META );
		}

		update_post_meta( $post_id, self::PRODUCT_ACCESS_MODE_META, $access_mode );

		if ( ! empty( $bundle_course_ids ) ) {
			update_post_meta( $post_id, self::PRODUCT_BUNDLE_COURSES_META, $bundle_course_ids );
		} else {
			delete_post_meta( $post_id, self::PRODUCT_BUNDLE_COURSES_META );
		}

		if ( ! empty( $bundle_program_ids ) ) {
			update_post_meta( $post_id, self::PRODUCT_BUNDLE_PROGRAMS_META, $bundle_program_ids );
		} else {
			delete_post_meta( $post_id, self::PRODUCT_BUNDLE_PROGRAMS_META );
		}

		if ( $access_days > 0 ) {
			update_post_meta( $post_id, self::PRODUCT_ACCESS_DURATION_DAYS_META, $access_days );
		} else {
			delete_post_meta( $post_id, self::PRODUCT_ACCESS_DURATION_DAYS_META );
		}
	}

	/**
	 * Obtiene el curso vinculado a un producto.
	 *
	 * @param int $product_id Producto.
	 * @return int
	 */
	public static function get_linked_course_id( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return 0;
		}

		return absint( get_post_meta( $product_id, self::PRODUCT_COURSE_META, true ) );
	}

	/**
	 * Obtiene el producto vinculado a un curso.
	 *
	 * @param int $course_id Curso.
	 * @return int
	 */
	public static function get_course_product_id( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id ) {
			return 0;
		}

		$product_id = absint( get_post_meta( $course_id, self::COURSE_PRODUCT_META, true ) );
		if ( $product_id ) {
			return $product_id;
		}

		return absint( get_post_meta( $course_id, self::COURSE_PRODUCT_META_LEGACY, true ) );
	}

	/**
	 * Obtiene el programa vinculado a un producto.
	 *
	 * @param int $product_id Producto.
	 * @return int
	 */
	public static function get_linked_program_id( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return 0;
		}

		return absint( get_post_meta( $product_id, self::PRODUCT_PROGRAM_META, true ) );
	}

	/**
	 * Obtiene el producto vinculado a un programa.
	 *
	 * @param int $program_id Programa.
	 * @return int
	 */
	public static function get_program_product_id( $program_id ) {
		$program_id = absint( $program_id );

		if ( ! $program_id ) {
			return 0;
		}

		return absint( get_post_meta( $program_id, self::PROGRAM_PRODUCT_META, true ) );
	}

	/**
	 * Compatibilidad con enrollment: resuelve curso por producto.
	 *
	 * @param int $product_id Producto.
	 * @return int
	 */
	public static function get_course_id_for_product( $product_id ) {
		return self::get_linked_course_id( $product_id );
	}

	/**
	 * Obtiene configuración LMS de un producto.
	 *
	 * @param int $product_id Producto.
	 * @return array
	 */
	public static function get_product_access_map( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return array(
				'mode'          => 'course',
				'course_id'     => 0,
				'program_id'    => 0,
				'bundle_courses'=> array(),
				'bundle_programs'=> array(),
				'access_days'   => 0,
			);
		}

		$bundle_courses  = get_post_meta( $product_id, self::PRODUCT_BUNDLE_COURSES_META, true );
		$bundle_programs = get_post_meta( $product_id, self::PRODUCT_BUNDLE_PROGRAMS_META, true );

		return array(
			'mode'            => (string) get_post_meta( $product_id, self::PRODUCT_ACCESS_MODE_META, true ) ?: 'course',
			'course_id'       => self::get_linked_course_id( $product_id ),
			'program_id'      => self::get_linked_program_id( $product_id ),
			'bundle_courses'  => is_array( $bundle_courses ) ? array_values( array_filter( array_map( 'absint', $bundle_courses ) ) ) : array(),
			'bundle_programs' => is_array( $bundle_programs ) ? array_values( array_filter( array_map( 'absint', $bundle_programs ) ) ) : array(),
			'access_days'     => absint( get_post_meta( $product_id, self::PRODUCT_ACCESS_DURATION_DAYS_META, true ) ),
		);
	}
}
