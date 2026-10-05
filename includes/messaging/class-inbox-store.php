<?php
/**
 * Buzón propio (6.30.0): hilos, participantes y mensajes en tablas.
 *
 * Reemplaza el almacenamiento en metas de usuario (`clms_internal_messages`,
 * tope 100; `clms_notifications`, tope 50), que perdía mensajes viejos, no
 * tenía hilos reales y podía pisar escrituras simultáneas. Cada mensaje es una
 * fila: dos escrituras al mismo tiempo no se pisan.
 *
 * Tipos de hilo:
 * - `system`: "Avisos" de un usuario (un participante). Cada aviso lleva su
 *   `kind` y su enlace; se lee aviso por aviso (`read_at`).
 * - `direct`: conversación entre dos personas, opcionalmente de un curso. Se
 *   lee por hilo (último leído del participante).
 * - `course`: reservado para avisos de curso a varios participantes.
 *
 * Idempotencia: `client_event_id` es único por autor; reenviar el mismo
 * devuelve el mensaje ya creado. `legacy_id` (el uuid de la meta) hace
 * idempotente la migración. Los errores de base de datos son WP_Error 503.
 *
 * @package ATORA_LMS
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Inbox_Store {

	const SYSTEM_SUBJECT = 'Avisos';

	/** Días que se conservan los avisos ya leídos (los mensajes de conversación no se borran). */
	const NOTICE_RETENTION_DAYS = 180;

	private static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	public static function tables_ready(): bool {
		global $wpdb;
		$table = self::t( 'atora_messages' );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function institution_id(): int {
		if ( class_exists( '\ATORA\LMS\Tenant_Context' ) && method_exists( '\ATORA\LMS\Tenant_Context', 'current_institution_id' ) ) {
			$id = \ATORA\LMS\Tenant_Context::current_institution_id();
			if ( is_numeric( $id ) ) {
				return absint( $id );
			}
		}
		return absint( (int) get_option( 'atora_default_institution', 0 ) );
	}

	private static function unavailable( string $what ): WP_Error {
		return new WP_Error( 'atora_inbox_unavailable', sprintf( 'Buzón no disponible (%s).', $what ), array( 'status' => 503 ) );
	}

	// ── Hilos ────────────────────────────────────────────────────────────

	/** Hilo "Avisos" del usuario (se crea la primera vez). */
	public static function system_thread_id( int $user_id ) {
		return self::ensure_thread( 'system:' . $user_id, 'system', 0, self::SYSTEM_SUBJECT, array( $user_id => 'owner' ) );
	}

	/** Conversación entre dos usuarios (de un curso, o general con curso 0). */
	public static function direct_thread_id( int $user_a, int $user_b, int $course_id = 0, string $subject = '', array $roles = array() ) {
		$low  = min( $user_a, $user_b );
		$high = max( $user_a, $user_b );
		return self::ensure_thread(
			sprintf( 'direct:%d:%d:%d', $low, $high, $course_id ),
			'direct',
			$course_id,
			$subject,
			array(
				$user_a => $roles[ $user_a ] ?? 'member',
				$user_b => $roles[ $user_b ] ?? 'member',
			)
		);
	}

	/**
	 * Crea el hilo si no existe (INSERT IGNORE sobre la clave única: dos
	 * peticiones simultáneas terminan en el mismo hilo) y asegura participantes.
	 *
	 * @param array<int,string> $participants user_id => rol.
	 * @return int|WP_Error
	 */
	private static function ensure_thread( string $key, string $type, int $course_id, string $subject, array $participants ) {
		global $wpdb;
		$threads = self::t( 'atora_message_threads' );
		$id      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$threads} WHERE thread_key = %s", $key ) ); // phpcs:ignore WordPress.DB
		if ( ! $id ) {
			$ok = $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"INSERT IGNORE INTO {$threads} (institution_id, type, thread_key, course_id, subject, created_at) VALUES (%d, %s, %s, %d, %s, %s)",
					self::institution_id(), $type, $key, $course_id, $subject, current_time( 'mysql', true )
				)
			);
			if ( false === $ok ) {
				return self::unavailable( 'hilo' );
			}
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$threads} WHERE thread_key = %s", $key ) ); // phpcs:ignore WordPress.DB
			if ( ! $id ) {
				return self::unavailable( 'hilo' );
			}
		}
		foreach ( $participants as $user_id => $role ) {
			$added = self::add_participant( $id, (int) $user_id, (string) $role );
			if ( is_wp_error( $added ) ) {
				return $added;
			}
		}
		return $id;
	}

	public static function add_participant( int $thread_id, int $user_id, string $role = 'member' ) {
		global $wpdb;
		$ok = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::t( 'atora_message_participants' ) . ' (thread_id, user_id, institution_id, role, created_at) VALUES (%d, %d, %d, %s, %s)',
				$thread_id, $user_id, self::institution_id(), sanitize_key( $role ), current_time( 'mysql', true )
			)
		);
		return false === $ok ? self::unavailable( 'participante' ) : true;
	}

	public static function is_participant( int $thread_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'atora_message_participants' ) . ' WHERE thread_id = %d AND user_id = %d', $thread_id, $user_id ) ); // phpcs:ignore WordPress.DB
	}

	public static function thread( int $thread_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'atora_message_threads' ) . ' WHERE id = %d', $thread_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/** @return int[] */
	public static function participant_ids( int $thread_id ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'atora_message_participants' ) . ' WHERE thread_id = %d', $thread_id ) ) ); // phpcs:ignore WordPress.DB
	}

	// ── Mensajes ─────────────────────────────────────────────────────────

	/**
	 * Agrega un mensaje.
	 *
	 * @param array $fields kind, title, body, link, course_id, lesson_id, submission_id,
	 *                      meta (array), client_event_id, legacy_id, dedupe_key, read_at, created_at (GMT).
	 * @return array{message:array, created:bool}|WP_Error  `created` false si ya existía (reenvío, migración o dedupe).
	 */
	public static function add_message( int $thread_id, int $author_id, array $fields ) {
		global $wpdb;
		$messages = self::t( 'atora_messages' );
		$event    = isset( $fields['client_event_id'] ) && '' !== (string) $fields['client_event_id'] ? substr( sanitize_text_field( (string) $fields['client_event_id'] ), 0, 64 ) : null;
		$legacy   = isset( $fields['legacy_id'] ) && '' !== (string) $fields['legacy_id'] ? substr( sanitize_text_field( (string) $fields['legacy_id'] ), 0, 64 ) : null;
		$dedupe   = isset( $fields['dedupe_key'] ) ? substr( sanitize_key( (string) $fields['dedupe_key'] ), 0, 100 ) : '';

		// Reenvío del mismo evento del cliente: se devuelve el ya creado.
		if ( null !== $event ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$messages} WHERE author_id = %d AND client_event_id = %s", $author_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( $existing ) {
				return array( 'message' => $existing, 'created' => false );
			}
		}
		if ( null !== $legacy ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$messages} WHERE thread_id = %d AND legacy_id = %s", $thread_id, $legacy ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( $existing ) {
				return array( 'message' => $existing, 'created' => false );
			}
		}
		if ( '' !== $dedupe ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$messages} WHERE thread_id = %d AND dedupe_key = %s LIMIT 1", $thread_id, $dedupe ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( $existing ) {
				return array( 'message' => $existing, 'created' => false );
			}
		}

		$created_at = ! empty( $fields['created_at'] ) ? (string) $fields['created_at'] : current_time( 'mysql', true );
		$row        = array(
			'institution_id'  => self::institution_id(),
			'thread_id'       => $thread_id,
			'author_id'       => $author_id,
			'kind'            => substr( sanitize_key( (string) ( $fields['kind'] ?? 'message' ) ), 0, 40 ) ?: 'message',
			'title'           => sanitize_text_field( (string) ( $fields['title'] ?? '' ) ),
			'body'            => sanitize_textarea_field( (string) ( $fields['body'] ?? '' ) ),
			'link'            => esc_url_raw( (string) ( $fields['link'] ?? '' ) ),
			'course_id'       => absint( $fields['course_id'] ?? 0 ),
			'lesson_id'       => absint( $fields['lesson_id'] ?? 0 ),
			'submission_id'   => absint( $fields['submission_id'] ?? 0 ),
			'meta'            => wp_json_encode( is_array( $fields['meta'] ?? null ) ? $fields['meta'] : array() ),
			'client_event_id' => $event,
			'legacy_id'       => $legacy,
			'dedupe_key'      => $dedupe,
			'read_at'         => ! empty( $fields['read_at'] ) ? (string) $fields['read_at'] : null,
			'created_at'      => $created_at,
		);
		$ok = $wpdb->insert( $messages, $row ); // phpcs:ignore WordPress.DB
		if ( false === $ok ) {
			// Carrera con el mismo client_event_id: gana el otro, se devuelve el suyo.
			if ( null !== $event ) {
				$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$messages} WHERE author_id = %d AND client_event_id = %s", $author_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $existing ) {
					return array( 'message' => $existing, 'created' => false );
				}
			}
			return self::unavailable( 'mensaje' );
		}
		$row['id'] = (int) $wpdb->insert_id;

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE ' . self::t( 'atora_message_threads' ) . ' SET last_message_id = %d, last_message_at = %s WHERE id = %d AND (last_message_at IS NULL OR last_message_at <= %s)',
				$row['id'], $created_at, $thread_id, $created_at
			)
		);
		// El autor ya leyó lo suyo.
		if ( $author_id > 0 ) {
			self::mark_thread_read( $thread_id, $author_id, $row['id'] );
		}

		/**
		 * Mensaje o aviso nuevo en el buzón (no se dispara en la migración).
		 * La cola de notificaciones al teléfono escucha aquí; nunca envía dentro de esta petición.
		 *
		 * @param array $row       Fila del mensaje.
		 * @param int   $thread_id Hilo.
		 */
		if ( empty( $fields['silent'] ) ) {
			do_action( 'atora/inbox_message_created', $row, $thread_id );
		}

		return array( 'message' => $row, 'created' => true );
	}

	public static function message( int $message_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'atora_messages' ) . ' WHERE id = %d', $message_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/**
	 * Mensajes de un hilo, del más nuevo al más viejo, antes de `before` (id).
	 *
	 * @return array<int,array>
	 */
	public static function thread_messages( int $thread_id, int $before = 0, int $limit = 30 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$sql   = $before > 0
			? $wpdb->prepare( 'SELECT * FROM ' . self::t( 'atora_messages' ) . ' WHERE thread_id = %d AND id < %d ORDER BY id DESC LIMIT %d', $thread_id, $before, $limit )
			: $wpdb->prepare( 'SELECT * FROM ' . self::t( 'atora_messages' ) . ' WHERE thread_id = %d ORDER BY id DESC LIMIT %d', $thread_id, $limit );
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Mensajes recibidos por el usuario en todos sus hilos (no los que escribió), del más nuevo al más viejo.
	 *
	 * @param array $args system_only, exclude_system, unread_only, limit.
	 * @return array<int,array> Filas con `thread_type`, `last_read_message_id` y `is_read`.
	 */
	public static function received( int $user_id, array $args = array() ): array {
		global $wpdb;
		$m     = self::t( 'atora_messages' );
		$p     = self::t( 'atora_message_participants' );
		$th    = self::t( 'atora_message_threads' );
		$where = array( 'p.user_id = %d', 'm.author_id <> %d' );
		$vals  = array( $user_id, $user_id );
		if ( ! empty( $args['system_only'] ) ) {
			$where[] = "t.type = 'system'";
		}
		if ( ! empty( $args['exclude_system'] ) ) {
			$where[] = "t.type <> 'system'";
		}
		if ( ! empty( $args['unread_only'] ) ) {
			$where[] = self::unread_condition();
		}
		$limit = isset( $args['limit'] ) ? max( 1, absint( $args['limit'] ) ) : 500;
		$vals[] = $limit;
		$sql   = "SELECT m.*, t.type AS thread_type, t.subject AS thread_subject, p.last_read_message_id,
		                 CASE WHEN " . self::unread_condition() . " THEN 0 ELSE 1 END AS is_read
		          FROM {$m} m
		          JOIN {$p} p ON p.thread_id = m.thread_id
		          JOIN {$th} t ON t.id = m.thread_id
		          WHERE " . implode( ' AND ', $where ) . '
		          ORDER BY m.id DESC LIMIT %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** No leído: un aviso sin `read_at`; un mensaje de conversación después del último leído. */
	private static function unread_condition(): string {
		return "((t.type = 'system' AND m.read_at IS NULL) OR (t.type <> 'system' AND m.id > p.last_read_message_id))";
	}

	/** Contador único de no leídos: campana web, panel, buzón docente y app. */
	public static function unread_count( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$sql = 'SELECT COUNT(*) FROM ' . self::t( 'atora_messages' ) . ' m
		        JOIN ' . self::t( 'atora_message_participants' ) . ' p ON p.thread_id = m.thread_id AND p.user_id = %d
		        JOIN ' . self::t( 'atora_message_threads' ) . ' t ON t.id = m.thread_id
		        WHERE m.author_id <> %d AND ' . self::unread_condition();
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $user_id, $user_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Hilos del usuario, el más reciente primero. `cursor` es "fecha|id" del último de la página anterior.
	 *
	 * @return array{items:array<int,array>, next_cursor:string}
	 */
	public static function threads_for_user( int $user_id, string $cursor = '', int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		$th    = self::t( 'atora_message_threads' );
		$p     = self::t( 'atora_message_participants' );
		$m     = self::t( 'atora_messages' );
		$where = 'p.user_id = %d AND t.last_message_id > 0';
		$vals  = array( $user_id );
		if ( '' !== $cursor && false !== strpos( $cursor, '|' ) ) {
			list( $at, $id ) = explode( '|', $cursor, 2 );
			$where  .= ' AND (t.last_message_at < %s OR (t.last_message_at = %s AND t.id < %d))';
			$vals[]  = $at;
			$vals[]  = $at;
			$vals[]  = absint( $id );
		}
		$vals[] = $limit + 1;
		$sql    = "SELECT t.*, p.role, p.muted, p.last_read_message_id,
		                  (SELECT COUNT(*) FROM {$m} m WHERE m.thread_id = t.id AND m.author_id <> p.user_id AND
		                     ((t.type = 'system' AND m.read_at IS NULL) OR (t.type <> 'system' AND m.id > p.last_read_message_id))) AS unread
		           FROM {$th} t JOIN {$p} p ON p.thread_id = t.id
		           WHERE {$where}
		           ORDER BY t.last_message_at DESC, t.id DESC LIMIT %d";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$next = '';
		if ( count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
			$last = end( $rows );
			$next = $last['last_message_at'] . '|' . $last['id'];
		}
		return array( 'items' => $rows, 'next_cursor' => $next );
	}

	// ── Lectura ──────────────────────────────────────────────────────────

	/** Marca leído el hilo hasta `upto` (0 = todo). Los avisos del hilo `system` se marcan uno por uno. */
	public static function mark_thread_read( int $thread_id, int $user_id, int $upto = 0 ): bool {
		global $wpdb;
		$thread = self::thread( $thread_id );
		if ( ! $thread || ! self::is_participant( $thread_id, $user_id ) ) {
			return false;
		}
		if ( $upto <= 0 ) {
			$upto = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . self::t( 'atora_messages' ) . ' WHERE thread_id = %d', $thread_id ) ); // phpcs:ignore WordPress.DB
		}
		if ( 'system' === $thread['type'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'atora_messages' ) . ' SET read_at = %s WHERE thread_id = %d AND id <= %d AND read_at IS NULL', current_time( 'mysql', true ), $thread_id, $upto ) ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'atora_message_participants' ) . ' SET last_read_message_id = %d WHERE thread_id = %d AND user_id = %d AND last_read_message_id < %d', $upto, $thread_id, $user_id, $upto ) ); // phpcs:ignore WordPress.DB
		return true;
	}

	/** Marca un solo mensaje (aviso: solo ese; conversación: hasta ese). */
	public static function mark_message_read( int $user_id, int $message_id ): bool {
		global $wpdb;
		$message = self::message( $message_id );
		if ( ! $message || ! self::is_participant( (int) $message['thread_id'], $user_id ) ) {
			return false;
		}
		$thread = self::thread( (int) $message['thread_id'] );
		if ( $thread && 'system' === $thread['type'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'atora_messages' ) . ' SET read_at = %s WHERE id = %d AND read_at IS NULL', current_time( 'mysql', true ), $message_id ) ); // phpcs:ignore WordPress.DB
			return true;
		}
		return self::mark_thread_read( (int) $message['thread_id'], $user_id, $message_id );
	}

	/** Id público de un mensaje: el uuid de la meta para los migrados (los enlaces viejos siguen sirviendo) o "m{id}". */
	public static function public_id( array $row ): string {
		return ! empty( $row['legacy_id'] ) ? (string) $row['legacy_id'] : 'm' . (int) $row['id'];
	}

	/** Resuelve un id público dentro de los hilos del usuario. */
	public static function find_for_user( int $user_id, string $public_id ): ?array {
		global $wpdb;
		$public_id = sanitize_text_field( $public_id );
		$m         = self::t( 'atora_messages' );
		$p         = self::t( 'atora_message_participants' );
		if ( preg_match( '/^m(\d+)$/', $public_id, $match ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT m.* FROM {$m} m JOIN {$p} p ON p.thread_id = m.thread_id AND p.user_id = %d WHERE m.id = %d", $user_id, (int) $match[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT m.* FROM {$m} m JOIN {$p} p ON p.thread_id = m.thread_id AND p.user_id = %d WHERE m.legacy_id = %s LIMIT 1", $user_id, $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return $row ?: null;
	}

	// ── Retención ────────────────────────────────────────────────────────

	/** Borra avisos leídos hace más de N días. Nunca toca mensajes de conversación. */
	public static function purge_read_notices( int $days = self::NOTICE_RETENTION_DAYS ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$m      = self::t( 'atora_messages' );
		$th     = self::t( 'atora_message_threads' );
		$done   = $wpdb->query( $wpdb->prepare( "DELETE m FROM {$m} m JOIN {$th} t ON t.id = m.thread_id WHERE t.type = 'system' AND m.read_at IS NOT NULL AND m.read_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
		return false === $done ? 0 : (int) $done;
	}

	/** Meta decodificada de un mensaje. */
	public static function meta( array $row ): array {
		$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
		return is_array( $meta ) ? $meta : array();
	}

	// ── Formas heredadas (API pública de CLMS_Messaging y CLMS_Notifications) ──

	private static function local_time( string $gmt ): string {
		return '' !== $gmt ? get_date_from_gmt( $gmt ) : '';
	}

	/** Un mensaje con la forma que devolvía CLMS_Messaging::get_messages(). */
	public static function legacy_message( array $row ): array {
		$meta      = self::meta( $row );
		$author    = (int) $row['author_id'];
		$thread_id = (int) $row['thread_id'];
		$type      = (string) ( $row['thread_type'] ?? '' );
		if ( '' === $type ) {
			$thread = self::thread( $thread_id );
			$type   = $thread ? (string) $thread['type'] : 'direct';
		}
		$sender_name = (string) ( $meta['sender_name'] ?? '' );
		if ( '' === $sender_name && $author > 0 ) {
			$user        = get_user_by( 'id', $author );
			$sender_name = $user ? ( $user->display_name ?: $user->user_login ) : '';
		}
		return array(
			'id'                  => self::public_id( $row ),
			'message_type'        => sanitize_key( (string) ( $meta['message_type'] ?? $row['kind'] ) ),
			'sender_type'         => sanitize_key( (string) ( $meta['sender_type'] ?? ( $author > 0 ? 'teacher' : 'system' ) ) ),
			'sender_id'           => $author,
			'sender_name'         => $sender_name,
			'title'               => (string) $row['title'],
			'message'             => (string) $row['body'],
			'link'                => (string) $row['link'],
			'course_id'           => (int) $row['course_id'],
			'program_id'          => absint( $meta['program_id'] ?? 0 ),
			'lesson_id'           => (int) $row['lesson_id'],
			'submission_id'       => (int) $row['submission_id'],
			'recommendation_type' => sanitize_key( (string) ( $meta['recommendation_type'] ?? '' ) ),
			'priority'            => sanitize_key( (string) ( $meta['priority'] ?? 'normal' ) ),
			'thread_id'           => 't' . $thread_id,
			'thread_type'         => $type,
			'thread_label'        => 'system' === $type ? self::SYSTEM_SUBJECT : (string) ( $row['thread_subject'] ?? '' ),
			'reply_to'            => (string) ( $meta['reply_to'] ?? '' ),
			'automation_source'   => sanitize_key( (string) ( $meta['automation_source'] ?? '' ) ),
			'kind'                => (string) $row['kind'],
			'is_read'             => isset( $row['is_read'] ) ? ( (int) $row['is_read'] ? 1 : 0 ) : ( null !== $row['read_at'] ? 1 : 0 ),
			'created_at'          => self::local_time( (string) $row['created_at'] ),
			'dedupe_key'          => (string) $row['dedupe_key'],
		);
	}

	/** Un aviso con la forma que devolvía CLMS_Notifications::get_notifications(). */
	public static function legacy_notice( array $row ): array {
		$meta = self::meta( $row );
		return array(
			'id'            => self::public_id( $row ),
			'type'          => (string) $row['kind'],
			'kind'          => (string) $row['kind'],
			'title'         => (string) $row['title'],
			'message'       => (string) $row['body'],
			'link'          => (string) $row['link'],
			'course_id'     => (int) $row['course_id'],
			'lesson_id'     => (int) $row['lesson_id'],
			'submission_id' => (int) $row['submission_id'],
			'status'        => sanitize_key( (string) ( $meta['status'] ?? '' ) ),
			'grade'         => isset( $meta['grade'] ) && '' !== (string) $meta['grade'] ? absint( $meta['grade'] ) : '',
			'feedback'      => (string) ( $meta['feedback'] ?? '' ),
			'is_read'       => null !== $row['read_at'] ? 1 : 0,
			'created_at'    => self::local_time( (string) $row['created_at'] ),
		);
	}
}
