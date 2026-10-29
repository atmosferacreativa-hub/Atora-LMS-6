<?php
/**
 * Períodos académicos (base).
 *
 * - Un período pertenece a una institución.
 * - Un curso (WP `lm_course`) puede asignarse a un período.
 * - Al cerrar un período, define la visibilidad de sus cursos pasados para estudiantes.
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

use ATORA\LMS\Tenant_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Periods_Service {
	public const CAP_CLOSE_PERIODS = 'atora_close_periods';

	private const OPTION_SCHEMA_VERSION = 'atora_periods_schema_version';
	private const SCHEMA_VERSION = '2'; // 2: start_notified_at (aviso de inicio) y CREATE TABLE sin IF NOT EXISTS (dbDelta agrega columnas).

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'ensure_schema' ), 20 );
	}

	public static function table_periods(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_periods';
	}

	public static function table_links(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_period_courses';
	}

	public static function ensure_schema(): void {
		if ( version_compare( (string) get_option( self::OPTION_SCHEMA_VERSION, '0' ), self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$periods = self::table_periods();
		$links   = self::table_links();

		dbDelta( "CREATE TABLE {$periods} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(255) NOT NULL DEFAULT '',
			starts_on DATE NULL DEFAULT NULL,
			ends_on DATE NULL DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			closed_at DATETIME NULL DEFAULT NULL,
			closed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			past_visibility VARCHAR(20) NOT NULL DEFAULT 'visible',
			visible_until DATE NULL DEFAULT NULL,
			start_notified_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY inst_status (institution_id, status),
			KEY inst_dates (institution_id, ends_on, starts_on)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$links} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			period_id BIGINT UNSIGNED NOT NULL,
			wp_course_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY course_unique (wp_course_id),
			KEY inst_period (institution_id, period_id)
		) {$charset};" );

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	/** @return int|WP_Error */
	public static function current_institution_id_for_user( int $user_id ) {
		if ( ! class_exists( Tenant_Context::class ) ) {
			return 0;
		}
		return Tenant_Context::resolve_institution_for_user( $user_id );
	}

	public static function can_close_periods( int $user_id, int $institution_id ): bool {
		$user_id = absint( $user_id );
		$institution_id = absint( $institution_id );
		if ( $user_id <= 0 || $institution_id <= 0 ) {
			return false;
		}
		if ( ! user_can( $user_id, self::CAP_CLOSE_PERIODS ) ) {
			return false;
		}
		$resolved = self::current_institution_id_for_user( $user_id );
		return ! is_wp_error( $resolved ) && absint( $resolved ) === $institution_id;
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_periods( int $institution_id ): array {
		global $wpdb;
		$institution_id = absint( $institution_id );
		if ( $institution_id <= 0 ) {
			return array();
		}
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_periods() . ' WHERE institution_id = %d ORDER BY ends_on DESC, starts_on DESC, id DESC', $institution_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB
		return $rows;
	}

	/** @return array<string,mixed>|null */
	public static function get_period( int $period_id ): ?array {
		global $wpdb;
		$period_id = absint( $period_id );
		if ( $period_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_periods() . ' WHERE id = %d LIMIT 1', $period_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	public static function create_period( int $actor_id, int $institution_id, string $name, string $starts_on = '', string $ends_on = '' ) {
		$actor_id = absint( $actor_id );
		$institution_id = absint( $institution_id );
		if ( ! self::can_close_periods( $actor_id, $institution_id ) ) {
			return new WP_Error( 'atora_periods_forbidden', __( 'No tienes permiso para crear períodos en esta institución.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'atora_periods_invalid', __( 'Nombre requerido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$starts_on = self::sanitize_date( $starts_on );
		$ends_on   = self::sanitize_date( $ends_on );

		global $wpdb;
		$ok = $wpdb->insert( self::table_periods(), array(
			'institution_id' => $institution_id,
			'name'           => $name,
			'starts_on'      => $starts_on ?: null,
			'ends_on'        => $ends_on ?: null,
			'status'         => 'open',
		) ); // phpcs:ignore WordPress.DB
		if ( ! $ok ) {
			return new WP_Error( 'atora_periods_db', __( 'No se pudo crear el período.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		return self::get_period( (int) $wpdb->insert_id );
	}

	public static function assign_course( int $actor_id, int $period_id, int $wp_course_id ) {
		$period = self::get_period( $period_id );
		if ( ! $period ) {
			return new WP_Error( 'atora_periods_not_found', __( 'Período no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$institution_id = absint( $period['institution_id'] ?? 0 );
		if ( ! self::can_close_periods( $actor_id, $institution_id ) ) {
			return new WP_Error( 'atora_periods_forbidden', __( 'No tienes permiso para asignar cursos en este período.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$wp_course_id = absint( $wp_course_id );
		if ( $wp_course_id <= 0 || 'lm_course' !== get_post_type( $wp_course_id ) ) {
			return new WP_Error( 'atora_periods_invalid', __( 'Curso inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( class_exists( 'ATORA_Teacher_Scope' ) && method_exists( 'ATORA_Teacher_Scope', 'course_institution' ) ) {
			$course_inst = absint( ATORA_Teacher_Scope::course_institution( $wp_course_id ) );
			if ( $course_inst > 0 && $course_inst !== $institution_id ) {
				return new WP_Error( 'atora_periods_forbidden', __( 'El curso pertenece a otra institución.', 'atora-lms' ), array( 'status' => 403 ) );
			}
		}

		global $wpdb;
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_links() . ' WHERE wp_course_id = %d LIMIT 1', $wp_course_id ) ); // phpcs:ignore WordPress.DB
		if ( $existing > 0 ) {
			$wpdb->update( self::table_links(), array( 'period_id' => $period_id, 'institution_id' => $institution_id ), array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB
			return true;
		}
		$ok = $wpdb->insert( self::table_links(), array(
			'institution_id' => $institution_id,
			'period_id'      => $period_id,
			'wp_course_id'   => $wp_course_id,
		) ); // phpcs:ignore WordPress.DB
		return (bool) $ok;
	}

	public static function close_period( int $actor_id, int $period_id, string $visibility, string $visible_until = '' ) {
		$period = self::get_period( $period_id );
		if ( ! $period ) {
			return new WP_Error( 'atora_periods_not_found', __( 'Período no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$institution_id = absint( $period['institution_id'] ?? 0 );
		if ( ! self::can_close_periods( $actor_id, $institution_id ) ) {
			return new WP_Error( 'atora_periods_forbidden', __( 'No tienes permiso para cerrar períodos en esta institución.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( 'closed' === (string) ( $period['status'] ?? '' ) ) {
			return new WP_Error( 'atora_periods_closed', __( 'El período ya está cerrado.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		$visibility = sanitize_key( $visibility );
		if ( ! in_array( $visibility, array( 'visible', 'hidden', 'until' ), true ) ) {
			$visibility = 'visible';
		}
		$visible_until = 'until' === $visibility ? self::sanitize_date( $visible_until ) : '';
		if ( 'until' === $visibility && '' === $visible_until ) {
			return new WP_Error( 'atora_periods_invalid', __( 'Fecha requerida para visibilidad hasta.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$wpdb->update( self::table_periods(), array(
			'status'          => 'closed',
			'closed_at'       => current_time( 'mysql', true ),
			'closed_by'       => absint( $actor_id ),
			'past_visibility' => $visibility,
			'visible_until'   => 'until' === $visibility ? $visible_until : null,
		), array( 'id' => $period_id ) ); // phpcs:ignore WordPress.DB

		if ( class_exists( 'CLMS_Audit_Log_Service' ) ) {
			CLMS_Audit_Log_Service::log( $actor_id, 'period_closed', 'period', $period_id, array(
				'institution_id' => $institution_id,
				'visibility'     => $visibility,
				'visible_until'  => $visible_until ?: null,
			) );
		}

		return self::get_period( $period_id );
	}

	/** @return int period_id or 0 */
	public static function course_period_id( int $wp_course_id ): int {
		global $wpdb;
		$wp_course_id = absint( $wp_course_id );
		if ( $wp_course_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT period_id FROM ' . self::table_links() . ' WHERE wp_course_id = %d LIMIT 1', $wp_course_id ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array{active:array<int,array<string,mixed>>, past:array<int,array{period:array<string,mixed>,courses:array<int,array<string,mixed>>}>} */
	public static function grouped_courses_for_user( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array( 'active' => array(), 'past' => array() );
		}

		// Institución activa del estudiante. Sin membresía (un WordPress por institución) o con varias sin elegir, no se
		// filtra por institución: la matrícula sigue siendo la que da acceso, como antes de los períodos.
		$institution_id = 0;
		if ( class_exists( Tenant_Context::class ) ) {
			$inst = Tenant_Context::resolve_institution_for_user( $user_id );
			$institution_id = is_wp_error( $inst ) ? 0 : absint( $inst );
		}

		$course_ids = array();
		if ( class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) && method_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service', 'get_enrolled_wp_course_ids' ) ) {
			$course_ids = (array) \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( $user_id );
		} elseif ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_courses' ) ) {
			$course_ids = (array) \CLMS_Helper::get_user_enrolled_courses( $user_id );
		}
		$course_ids = array_values( array_unique( array_filter( array_map( 'absint', $course_ids ) ) ) );
		if ( ! $course_ids ) {
			return array( 'active' => array(), 'past' => array() );
		}

		// Filtra por institución del usuario.
		if ( $institution_id > 0 && class_exists( 'ATORA_Teacher_Scope' ) && method_exists( 'ATORA_Teacher_Scope', 'course_institution' ) ) {
			$course_ids = array_values( array_filter( $course_ids, static function ( int $wp_course_id ) use ( $institution_id ): bool {
				$inst = absint( ATORA_Teacher_Scope::course_institution( $wp_course_id ) );
				return $inst <= 0 || $inst === $institution_id;
			} ) );
		}
		if ( ! $course_ids ) {
			return array( 'active' => array(), 'past' => array() );
		}

		$posts = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'post__in'       => $course_ids,
			'orderby'        => 'post__in',
		) );

		$completed_lessons = (array) get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed_lessons = array_map( 'absint', $completed_lessons );

		$active = array();
		$past_courses_by_period = array();
		$period_cache = array();

		foreach ( $posts as $course ) {
			$wp_course_id = absint( $course->ID );
			$period_id = self::course_period_id( $wp_course_id );
			$period = null;
			if ( $period_id > 0 ) {
				if ( isset( $period_cache[ $period_id ] ) ) {
					$period = $period_cache[ $period_id ];
				} else {
					$period = self::get_period( $period_id );
					$period_cache[ $period_id ] = $period;
				}
			}

			$payload = self::course_payload( $course, $completed_lessons );
			$payload['cover_url'] = class_exists( 'ATORA_Course_Cover_Resolver' ) ? ( ATORA_Course_Cover_Resolver::for_wp_post( $wp_course_id ) ?: null ) : $payload['thumbnail'];
			$payload['color'] = self::course_color( $wp_course_id );

			if ( ! $period || 'closed' !== (string) ( $period['status'] ?? '' ) ) {
				$active[] = $payload;
				continue;
			}
			if ( $institution_id > 0 && absint( $period['institution_id'] ?? 0 ) !== $institution_id ) {
				continue;
			}
			if ( ! self::period_is_visible_to_students( $period ) ) {
				continue;
			}
			if ( ! isset( $past_courses_by_period[ $period_id ] ) ) {
				$past_courses_by_period[ $period_id ] = array( 'period' => $period, 'courses' => array() );
			}
			$past_courses_by_period[ $period_id ]['courses'][] = $payload;
		}

		// Orden de períodos: del más reciente al más antiguo.
		$past = array_values( $past_courses_by_period );
		usort( $past, static function ( array $a, array $b ): int {
			$pa = (array) ( $a['period'] ?? array() );
			$pb = (array) ( $b['period'] ?? array() );
			$ea = (string) ( $pa['ends_on'] ?? '' );
			$eb = (string) ( $pb['ends_on'] ?? '' );
			if ( $ea !== $eb ) {
				return strcmp( $eb, $ea );
			}
			$sa = (string) ( $pa['starts_on'] ?? '' );
			$sb = (string) ( $pb['starts_on'] ?? '' );
			if ( $sa !== $sb ) {
				return strcmp( $sb, $sa );
			}
			return (int) ( $pb['id'] ?? 0 ) <=> (int) ( $pa['id'] ?? 0 );
		} );

		return array( 'active' => $active, 'past' => $past );
	}

	private static function course_payload( WP_Post $course, array $completed_lessons ): array {
		$user_id = get_current_user_id();
		$lessons   = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course->ID ) : array();
		$total     = count( $lessons );
		$completed = 0;
		foreach ( $lessons as $lesson ) {
			$lesson_id = is_object( $lesson ) ? $lesson->ID : absint( $lesson );
			if ( in_array( $lesson_id, $completed_lessons, true ) ) {
				++$completed;
			}
		}
		$progress = $total > 0 ? round( ( $completed / $total ) * 100 ) : 0;

		return array(
			'id'                => $course->ID,
			'title'             => $course->post_title,
			'slug'              => $course->post_name,
			'url'               => get_permalink( $course->ID ),
			'thumbnail'         => get_the_post_thumbnail_url( $course->ID, 'medium' ) ?: null,
			'progress'          => $progress,
			'completed'         => 100 === $progress,
			'total_lessons'     => $total,
			'completed_lessons' => $completed,
		);
	}

	/**
	 * Período cerrado del curso (o null si el curso no está en un período cerrado).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function closed_period_of( int $wp_course_id ): ?array {
		$period_id = self::course_period_id( $wp_course_id );
		if ( $period_id <= 0 ) {
			return null;
		}
		$period = self::get_period( $period_id );
		return $period && 'closed' === (string) ( $period['status'] ?? '' ) ? $period : null;
	}

	/**
	 * ¿El cierre del período le quitó a este usuario el acceso al curso?
	 *
	 * El administrador o Control académico decide al cerrar: «visible» deja repasar sin límite; «visible hasta» deja
	 * repasar hasta esa fecha (de la academia); «oculto» cierra el acceso. Solo afecta a estudiantes: quien gestiona el
	 * LMS, el docente con el curso en su alcance y quien ve los registros académicos de la institución siguen entrando.
	 */
	public static function access_closed( int $user_id, int $wp_course_id ): bool {
		$user_id      = absint( $user_id );
		$wp_course_id = absint( $wp_course_id );
		if ( $user_id <= 0 || $wp_course_id <= 0 ) {
			return false;
		}
		$period = self::closed_period_of( $wp_course_id );
		if ( ! $period || self::period_is_visible_to_students( $period ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'clms_manage_courses' ) ) {
			return false;
		}
		if ( class_exists( 'ATORA_Teacher_Scope' ) ) {
			if ( ATORA_Teacher_Scope::can_access_course( $user_id, $wp_course_id ) || ATORA_Teacher_Scope::views_institution( $user_id, $wp_course_id ) ) {
				return false;
			}
		}
		return true;
	}

	/** Datos del período para la app (null si el curso no está en un período). @return array<string,mixed>|null */
	public static function period_payload( int $wp_course_id ): ?array {
		$period_id = self::course_period_id( $wp_course_id );
		$period    = $period_id > 0 ? self::get_period( $period_id ) : null;
		if ( ! $period ) {
			return null;
		}
		return array(
			'id'            => absint( $period['id'] ),
			'name'          => (string) $period['name'],
			'starts_on'     => $period['starts_on'] ? (string) $period['starts_on'] : null,
			'ends_on'       => $period['ends_on'] ? (string) $period['ends_on'] : null,
			'status'        => (string) $period['status'],
			'visible_until' => 'until' === (string) $period['past_visibility'] && $period['visible_until'] ? (string) $period['visible_until'] : null,
		);
	}

	public static function color_of( int $wp_course_id ): string {
		return self::course_color( $wp_course_id );
	}

	private static function period_is_visible_to_students( array $period ): bool {
		$vis = (string) ( $period['past_visibility'] ?? 'visible' );
		if ( 'hidden' === $vis ) {
			return false;
		}
		if ( 'until' === $vis ) {
			$until = (string) ( $period['visible_until'] ?? '' );
			if ( '' === $until ) {
				return false;
			}
			// Fecha de la academia (zona horaria del sitio): «visible hasta el 15» incluye todo el 15 en hora local.
			$today = (string) current_time( 'Y-m-d' );
			return $today <= $until;
		}
		return true;
	}

	private static function course_color( int $wp_course_id ): string {
		$wp_course_id = absint( $wp_course_id );
		$meta = (string) get_post_meta( $wp_course_id, 'color', true );
		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $meta ) ) {
			return $meta;
		}
		$meta = (string) get_post_meta( $wp_course_id, 'course_color', true );
		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $meta ) ) {
			return $meta;
		}
		$palette = array( '#1d4ed8', '#0f766e', '#7c3aed', '#be123c', '#b45309', '#059669', '#4f46e5', '#0ea5e9' );
		return $palette[ $wp_course_id % count( $palette ) ];
	}

	private static function sanitize_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}
}

