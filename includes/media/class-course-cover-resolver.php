<?php
/**
 * Portada de un curso (API móvil, 6.27.3).
 *
 * Orden de resolución:
 * 1. imagen destacada del post del curso (`wp_post_id`), tamaño `large`;
 * 2. columna `thumbnail_url` de la tabla `atora_courses`.
 *
 * La columna solo la llenaba el migrador; ahora se mantiene al día cuando cambia
 * la imagen destacada (`_thumbnail_id`) y una reparación única la rellena para
 * los cursos existentes (`wp atora courses repair-covers`).
 *
 * @package ATORA_LMS
 * @since 6.27.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Course_Cover_Resolver {
	const COURSE_POST_TYPE = 'lm_course';
	const IMAGE_SIZE       = 'large';
	const REPAIR_OPTION    = 'atora_course_covers_repaired';

	public static function init(): void {
		add_action( 'added_post_meta', array( __CLASS__, 'on_thumbnail_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_thumbnail_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_thumbnail_meta' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'maybe_repair' ), 20 );
	}

	/** Portada de un curso de la tabla (fila formateada o cruda). */
	public static function resolve( array $course ): string {
		$featured = self::featured( absint( $course['wp_post_id'] ?? 0 ) );
		if ( '' !== $featured ) {
			return $featured;
		}
		return esc_url_raw( (string) ( $course['thumbnail_url'] ?? '' ) );
	}

	/** Portada a partir del post del curso (sin fila de tabla a mano). */
	public static function for_wp_post( int $wp_course_id ): string {
		return self::featured( $wp_course_id );
	}

	private static function featured( int $wp_course_id ): string {
		if ( $wp_course_id <= 0 ) {
			return '';
		}
		return esc_url_raw( (string) get_the_post_thumbnail_url( $wp_course_id, self::IMAGE_SIZE ) );
	}

	/** Añadir, cambiar o quitar la imagen destacada de un curso actualiza la columna. */
	public static function on_thumbnail_meta( $meta_id, $object_id, $meta_key ): void {
		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}
		$wp_course_id = absint( $object_id );
		if ( $wp_course_id <= 0 || self::COURSE_POST_TYPE !== get_post_type( $wp_course_id ) ) {
			return;
		}
		self::write_column( $wp_course_id, self::featured( $wp_course_id ) );
	}

	/**
	 * Rellena la columna desde la imagen destacada en los cursos donde difiere.
	 * Cursos sin imagen destacada o con la columna ya correcta no se tocan.
	 *
	 * @return array{scanned:int, repaired:int}
	 */
	public static function repair_all( bool $write = true ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			"SELECT id, wp_post_id, thumbnail_url FROM {$wpdb->prefix}atora_courses WHERE wp_post_id > 0",
			ARRAY_A
		);
		$repaired = 0;
		foreach ( $rows as $row ) {
			$featured = self::featured( absint( $row['wp_post_id'] ?? 0 ) );
			if ( '' === $featured || (string) ( $row['thumbnail_url'] ?? '' ) === $featured ) {
				continue;
			}
			if ( $write ) {
				self::write_column( absint( $row['wp_post_id'] ), $featured );
			}
			++$repaired;
		}
		return array( 'scanned' => count( $rows ), 'repaired' => $repaired );
	}

	/** Reparación única tras actualizar el plugin. */
	public static function maybe_repair(): void {
		if ( get_option( self::REPAIR_OPTION ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'atora_courses';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return; // Aún sin tabla: se intentará en la siguiente carga.
		}
		$result = self::repair_all();
		update_option( self::REPAIR_OPTION, array( 'version' => defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '', 'repaired' => $result['repaired'] ), false );
	}

	private static function write_column( int $wp_course_id, string $url ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'atora_courses',
			array( 'thumbnail_url' => $url ),
			array( 'wp_post_id' => $wp_course_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
