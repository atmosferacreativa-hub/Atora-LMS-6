<?php
/**
 * Registro de cambios de contenido para la sincronización móvil (6.28.0).
 *
 * Cada fila solo marca qué objeto cambió (curso, lección o matrícula); el estado
 * se lee al responder. Se escribe desde un único punto: la acción
 * `atora/lms/content_revised` que emiten `LMS_Course_Service::update*()` y la
 * sincronización del editor (6.27.4), más los eventos de matrícula.
 *
 * Cursor opaco: base64url de {"v":1,"id":N} (incremental) o
 * {"v":1,"full":1,"snap":N,"off":K} (estado completo paginado).
 *
 * @package ATORA_LMS\LMS
 * @since   6.28.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LMS_Content_Changes {
	const PAGE_SIZE      = 200;
	const RETENTION_DAYS = 90;
	const PURGE_HOOK     = 'atora_content_changes_purge';
	/** Mayor id borrado por la retención: un cursor anterior obliga a sincronizar todo. */
	const PURGED_OPTION  = 'atora_content_changes_purged_through';

	public static function init(): void {
		add_action( 'atora/lms/content_revised', array( __CLASS__, 'on_revised' ), 10, 3 );
		add_action( 'atora/lms/enrolled', array( __CLASS__, 'on_enrolled' ), 30, 2 );
		add_action( 'atora/lms/unenrolled', array( __CLASS__, 'on_unenrolled' ), 30, 2 );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	// ── Escritura ────────────────────────────────────────────────────────────

	public static function on_revised( $object_type, $object_id, $revision = 0 ): void {
		$object_type = (string) $object_type;
		$object_id   = absint( $object_id );
		if ( 'course' === $object_type ) {
			$row = LMS_Course_Service::get( $object_id );
			self::record( 'course', $object_id, $object_id, 0, self::change_type( $row ), absint( $revision ) );
		} elseif ( 'lesson' === $object_type ) {
			$row = LMS_Course_Service::get_lesson( $object_id );
			self::record( 'lesson', $object_id, absint( $row['course_id'] ?? 0 ), 0, self::change_type( $row ), absint( $revision ) );
		}
	}

	public static function on_enrolled( $user_id, $course_id ): void {
		self::record( 'enrollment', absint( $course_id ), absint( $course_id ), absint( $user_id ), 'enrolled', 0 );
	}

	public static function on_unenrolled( $user_id, $course_id ): void {
		self::record( 'enrollment', absint( $course_id ), absint( $course_id ), absint( $user_id ), 'unenrolled', 0 );
	}

	public static function record( string $object_type, int $object_id, int $course_id, int $user_id, string $change_type, int $revision ): void {
		global $wpdb;
		if ( $object_id <= 0 ) {
			return;
		}
		$institution_id = $course_id > 0
			? absint( $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}atora_courses WHERE id = %d", $course_id ) ) )
			: 0;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'atora_content_changes',
			array(
				'institution_id' => $institution_id,
				'object_type'    => $object_type,
				'object_id'      => $object_id,
				'course_id'      => $course_id,
				'user_id'        => $user_id,
				'change_type'    => $change_type,
				'revision'       => $revision,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s' )
		);
		if ( false === $ok && class_exists( '\\ATORA_Mobile_Db_Errors' ) ) {
			// 6.28.2: corre dentro de un guardado del editor, no de una ruta móvil: se registra.
			// La reparación de tablas (wp atora lms align-tables) no recupera la fila; el
			// siguiente guardado del mismo objeto vuelve a marcarlo.
			\ATORA_Mobile_Db_Errors::log( 'registro de cambios (' . $object_type . ' ' . $object_id . ')' );
		}
	}

	/** Borra filas de más de 90 días y recuerda hasta qué id se borró. */
	public static function purge(): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'atora_content_changes';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$max_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$table} WHERE created_at < %s", $cutoff ) ) );
		if ( $max_id <= 0 ) {
			return 0;
		}
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $max_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		update_option( self::PURGED_OPTION, max( $max_id, absint( get_option( self::PURGED_OPTION, 0 ) ) ), false );
		return $deleted;
	}

	// ── Lectura ──────────────────────────────────────────────────────────────

	/**
	 * Cambios visibles para un usuario.
	 *
	 * @param int    $user_id
	 * @param int[]  $course_ids Cursos matriculados y visibles para el usuario.
	 * @param string $cursor     Vacío para el estado completo.
	 * @return array{items:array, next_cursor:string, has_more:bool, reset:bool, full:bool}
	 */
	public static function for_user( int $user_id, array $course_ids, string $cursor = '', int $page_size = self::PAGE_SIZE ): array {
		$page_size = max( 1, $page_size );
		$courses   = self::institutions( $course_ids );
		$state     = self::decode_cursor( $cursor );
		$reset     = false;

		if ( '' !== $cursor && null === $state ) {
			$reset = true; // Cursor ilegible: se empieza de nuevo.
		} elseif ( $state && empty( $state['full'] ) && $state['id'] < absint( get_option( self::PURGED_OPTION, 0 ) ) ) {
			$reset = true; // Más antiguo que lo conservado.
		}

		if ( null === $state || $reset || ! empty( $state['full'] ) ) {
			$snap = ( $state && ! empty( $state['full'] ) && ! $reset ) ? $state['snap'] : self::max_id();
			$off  = ( $state && ! empty( $state['full'] ) && ! $reset ) ? $state['off'] : 0;
			return self::full_page( $courses, $snap, $off, $page_size ) + array( 'reset' => $reset );
		}

		return self::incremental_page( $user_id, $courses, $state['id'], $page_size );
	}

	private static function full_page( array $courses, int $snap, int $off, int $page_size ): array {
		$all = array();
		foreach ( array_keys( $courses ) as $course_id ) {
			$course = LMS_Course_Service::get( $course_id );
			if ( ! $course ) {
				continue;
			}
			$all[] = array( 'type' => 'course', 'id' => $course_id, 'course_id' => $course_id, 'revision' => absint( $course['revision'] ?? 1 ), 'removed' => false );
			foreach ( LMS_Course_Service::get_lessons( $course_id ) as $lesson ) {
				$all[] = array( 'type' => 'lesson', 'id' => absint( $lesson['id'] ), 'course_id' => $course_id, 'revision' => absint( $lesson['revision'] ?? 1 ), 'removed' => false );
			}
		}
		$items    = array_slice( $all, $off, $page_size );
		$has_more = count( $all ) > $off + $page_size;
		return array(
			'items'       => $items,
			'has_more'    => $has_more,
			'next_cursor' => $has_more
				? self::encode_cursor( array( 'v' => 1, 'full' => 1, 'snap' => $snap, 'off' => $off + $page_size ) )
				: self::encode_cursor( array( 'v' => 1, 'id' => $snap ) ),
			'full'        => true,
		);
	}

	private static function incremental_page( int $user_id, array $courses, int $after_id, int $page_size ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_content_changes';
		$where = $wpdb->prepare( "(object_type = 'enrollment' AND user_id = %d)", $user_id );
		if ( ! empty( $courses ) ) {
			$ids    = implode( ',', array_map( 'absint', array_keys( $courses ) ) );
			$where .= " OR (object_type IN ('course','lesson') AND course_id IN ({$ids}))";
		}
		// Foto antes de leer: lo que entre después llega en la siguiente llamada.
		$snapshot = self::max_id();
		$rows     = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND id <= %d AND ({$where}) ORDER BY id ASC LIMIT %d", $after_id, $snapshot, $page_size + 1 ),
			ARRAY_A
		);
		$has_more = count( $rows ) > $page_size;
		$rows     = array_slice( $rows, 0, $page_size );
		// Página final: el cursor avanza hasta la foto (aunque las últimas filas sean de otros usuarios).
		$last_id = $has_more ? absint( end( $rows )['id'] ) : max( $after_id, $snapshot );

		// Solo el último cambio de cada objeto dentro de la página; el estado se lee ahora.
		$latest = array();
		foreach ( $rows as $row ) {
			$type = (string) $row['object_type'];
			$cid  = absint( $row['course_id'] );
			if ( 'enrollment' !== $type && ( ! isset( $courses[ $cid ] ) || absint( $row['institution_id'] ) !== absint( $courses[ $cid ] ) ) ) {
				continue; // Otro inquilino.
			}
			unset( $latest[ $type . ':' . $row['object_id'] ] );
			$latest[ $type . ':' . $row['object_id'] ] = $row;
		}

		$items = array();
		foreach ( $latest as $row ) {
			$type = (string) $row['object_type'];
			$id   = absint( $row['object_id'] );
			if ( 'enrollment' === $type ) {
				$items[] = array( 'type' => 'enrollment', 'id' => $id, 'course_id' => $id, 'active' => isset( $courses[ $id ] ) );
				continue;
			}
			$current = 'course' === $type ? LMS_Course_Service::get( $id ) : LMS_Course_Service::get_lesson( $id );
			$items[] = array(
				'type'      => $type,
				'id'        => $id,
				'course_id' => absint( $row['course_id'] ),
				'revision'  => absint( $current['revision'] ?? $row['revision'] ),
				'removed'   => 'updated' !== self::change_type( $current ),
			);
		}

		return array(
			'items'       => $items,
			'has_more'    => $has_more,
			'next_cursor' => self::encode_cursor( array( 'v' => 1, 'id' => $last_id ) ),
			'reset'       => false,
			'full'        => false,
		);
	}

	// ── Auxiliares ───────────────────────────────────────────────────────────

	/** @return array<int,int> course_id => institution_id */
	private static function institutions( array $course_ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $course_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT id, institution_id FROM {$wpdb->prefix}atora_courses WHERE id IN (" . implode( ',', $ids ) . ')',
			ARRAY_A
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ absint( $row['id'] ) ] = absint( $row['institution_id'] );
		}
		return $map;
	}

	private static function change_type( ?array $row ): string {
		return $row && 'published' === (string) ( $row['status'] ?? '' ) ? 'updated' : 'removed';
	}

	private static function max_id(): int {
		global $wpdb;
		return absint( $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}atora_content_changes" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public static function encode_cursor( array $state ): string {
		return rtrim( strtr( base64_encode( (string) wp_json_encode( $state ) ), '+/', '-_' ), '=' );
	}

	/** @return array{id:int, full?:int, snap?:int, off?:int}|null */
	public static function decode_cursor( string $cursor ): ?array {
		if ( '' === $cursor || strlen( $cursor ) > 200 ) {
			return null;
		}
		$json  = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		$state = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $state ) || 1 !== ( $state['v'] ?? null ) ) {
			return null;
		}
		if ( ! empty( $state['full'] ) ) {
			return array( 'id' => 0, 'full' => 1, 'snap' => absint( $state['snap'] ?? 0 ), 'off' => absint( $state['off'] ?? 0 ) );
		}
		return isset( $state['id'] ) ? array( 'id' => absint( $state['id'] ) ) : null;
	}
}
