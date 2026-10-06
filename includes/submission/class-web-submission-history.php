<?php
/**
 * Historial de entregas web (6.31.0).
 *
 * Cada entrega hecha desde el formulario web escribe una fila nueva en
 * `atora_assignment_submissions` (solo añadir), como las móviles: así el
 * docente ve todos los intentos con su fecha. El post `clms_submission` sigue
 * siendo el que califica SpeedGrader.
 *
 * - Ids de tabla (`atora_courses` / `atora_lessons`), igual que las filas móviles.
 * - En tareas grupales la fila es de quien entregó y apunta a la entrega maestra.
 * - Migración idempotente: una fila "intento 1" por cada entrega web existente
 *   sin fila (`client_event_id` = `web-migrated-{post}`), por lotes en segundo
 *   plano al actualizar y con `wp atora submissions migrate-web`.
 *
 * @package ATORA_LMS
 * @since 6.31.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Web_Submission_History {

	const OPTION     = 'atora_web_submissions_migrated';
	const CRON_HOOK  = 'atora_web_submissions_migrate';
	const BATCH_SIZE = 200;

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'atora submissions migrate-web', array( __CLASS__, 'cli' ) );
		}
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_assignment_submissions';
	}

	/** Lección y curso de tabla para una lección (post). @return array{0:int,1:int} */
	private static function table_ids( int $wp_lesson_id, int $wp_course_id ): array {
		$lesson = class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ? \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $wp_lesson_id ) : null;
		$course = class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) && $wp_course_id ? \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course_id ) : null;
		$course_id = $course ? absint( $course['id'] ) : absint( $lesson['course_id'] ?? 0 );
		return array( $lesson ? absint( $lesson['id'] ) : 0, $course_id );
	}

	private static function files_json( int $post_id ): string {
		$ids = get_post_meta( $post_id, '_clms_submission_files', true );
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file          = $attachment_id ? get_attached_file( $attachment_id ) : '';
			$out[]         = array(
				'filename'      => $file ? wp_basename( $file ) : '',
				'mime_type'     => (string) get_post_mime_type( $attachment_id ),
				'bytes'         => $file && file_exists( $file ) ? (int) filesize( $file ) : 0,
				'attachment_id' => $attachment_id,
			);
		}
		return (string) wp_json_encode( $out );
	}

	/**
	 * Fila de un intento web. `$event` identifica el intento (único por usuario).
	 *
	 * @return int Id insertado, 0 si ya existía o no hay tabla/lección.
	 */
	public static function record( int $post_id, string $event, ?string $received_at = null, ?int $attempt = null ): int {
		global $wpdb;
		$post_id      = absint( $post_id );
		$wp_lesson_id = absint( get_post_meta( $post_id, '_clms_submission_lesson_id', true ) );
		$wp_course_id = absint( get_post_meta( $post_id, '_clms_submission_course_id', true ) );
		$user_id      = absint( get_post_meta( $post_id, '_clms_submission_user_id', true ) );
		if ( ! $user_id ) {
			$user_id = absint( get_post_meta( $post_id, '_clms_submission_submitted_by', true ) );
		}
		if ( ! $user_id ) {
			$user_id = absint( get_post_field( 'post_author', $post_id ) );
		}
		list( $lesson_id, $course_id ) = self::table_ids( $wp_lesson_id, $wp_course_id );
		if ( ! $post_id || ! $user_id || ! $lesson_id ) {
			return 0;
		}

		$received = $received_at ?: gmdate( 'Y-m-d H:i:s' );
		$due_ts   = class_exists( 'ATORA_Mobile_REST_Controller' ) ? ATORA_Mobile_REST_Controller::assignment_due_ts( $wp_lesson_id ) : 0;
		if ( null === $attempt ) {
			$attempt = 1 + absint( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(attempt) FROM ' . self::table() . ' WHERE user_id = %d AND lesson_id = %d', $user_id, $lesson_id ) ) ); // phpcs:ignore WordPress.DB
		}

		$ok = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'INSERT IGNORE INTO ' . self::table() . ' (institution_id, user_id, course_id, lesson_id, wp_post_id, attempt, status, body_text, files_json, client_event_id, client_submitted_at, server_received_at, due_at, is_late, source, created_at)
			 VALUES (%d, %d, %d, %d, %d, %d, %s, %s, %s, %s, NULL, %s, %s, %d, %s, %s)',
			class_exists( 'ATORA_Teacher_Scope' ) && $wp_course_id ? ATORA_Teacher_Scope::course_institution( $wp_course_id ) : 0,
			$user_id,
			$course_id,
			$lesson_id,
			$post_id,
			$attempt,
			'submitted',
			(string) get_post_meta( $post_id, '_clms_submission_comment', true ),
			self::files_json( $post_id ),
			substr( $event, 0, 64 ),
			$received,
			$due_ts > 0 ? gmdate( 'Y-m-d H:i:s', $due_ts ) : null,
			( $due_ts > 0 && strtotime( $received . ' UTC' ) > $due_ts ) ? 1 : 0,
			'web',
			$received
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Intentos de una entrega (web y móvil), del primero al último, con sus archivos.
	 *
	 * @return array<int,array{attempt:int,source:string,received_at:?string,client_at:?string,body_text:string,files:array,is_late:bool}>
	 */
	public static function attempts_for_post( int $post_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . self::table() . " WHERE wp_post_id = %d AND status <> 'processing' ORDER BY attempt ASC, id ASC",
			$post_id
		), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			$files = array();
			foreach ( (array) json_decode( (string) $row['files_json'], true ) as $file ) {
				$attachment = absint( $file['attachment_id'] ?? 0 );
				$files[]    = array(
					'attachment_id' => $attachment,
					'filename'      => (string) ( $file['filename'] ?? '' ),
					'mime_type'     => (string) ( $file['mime_type'] ?? '' ),
					'bytes'         => (int) ( $file['bytes'] ?? 0 ),
					'url'           => $attachment ? (string) wp_get_attachment_url( $attachment ) : '',
				);
			}
			$out[] = array(
				'attempt'     => (int) $row['attempt'],
				'user_id'     => (int) $row['user_id'],
				'source'      => (string) $row['source'],
				'received_at' => $row['server_received_at'] ? (string) $row['server_received_at'] : null,
				'client_at'   => $row['client_submitted_at'] ? (string) $row['client_submitted_at'] : null,
				'body_text'   => (string) $row['body_text'],
				'files'       => $files,
				'is_late'     => (bool) $row['is_late'],
			);
		}
		return $out;
	}

	/** Intento elegido: el pedido si existe; si no, el último. 0 sin intentos. */
	public static function selected_attempt( array $attempts, int $requested ): int {
		$numbers = wp_list_pluck( $attempts, 'attempt' );
		if ( $requested > 0 && in_array( $requested, $numbers, true ) ) {
			return $requested;
		}
		return $numbers ? (int) max( $numbers ) : 0;
	}

	/** Intento hecho desde el formulario web (no desde la app). */
	public static function record_web_attempt( int $post_id ): int {
		return self::record( $post_id, 'web-' . wp_generate_uuid4() );
	}

	/** Entregas web existentes sin fila (excluye copias grupales). @return int[] */
	public static function pending_post_ids( int $limit, int $after = 0 ): array {
		global $wpdb;
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN " . self::table() . " a ON a.wp_post_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} sh ON sh.post_id = p.ID AND sh.meta_key = '_clms_submission_is_shadow' AND sh.meta_value = '1'
			 WHERE p.ID > %d AND p.post_type = 'clms_submission' AND p.post_status IN ('publish','private') AND a.id IS NULL AND sh.meta_id IS NULL
			 ORDER BY p.ID ASC LIMIT %d",
			$after,
			$limit
		) ) );
	}

	/** Un lote de la migración desde el post `$after`. @return array{migrated:int,skipped:int,remaining:bool,last:int} */
	public static function migrate_batch( int $limit = self::BATCH_SIZE, int $after = 0 ): array {
		$migrated = 0;
		$skipped  = 0;
		$ids      = self::pending_post_ids( $limit, $after );
		foreach ( $ids as $post_id ) {
			$submitted = (string) get_post_meta( $post_id, '_clms_submission_submitted_at', true );
			$received  = '' !== $submitted ? get_gmt_from_date( $submitted ) : (string) get_post_field( 'post_date_gmt', $post_id );
			if ( self::record( $post_id, 'web-migrated-' . $post_id, $received ?: null, 1 ) > 0 ) {
				++$migrated;
			} else {
				++$skipped;
			}
		}
		// El cursor avanza también sobre las omitidas (sin lección de tabla): no se repiten en bucle.
		return array( 'migrated' => $migrated, 'skipped' => $skipped, 'remaining' => count( $ids ) === $limit, 'last' => $ids ? (int) end( $ids ) : $after );
	}

	public static function maybe_schedule(): void {
		if ( '1' !== (string) get_option( self::OPTION, '' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	public static function run_batch(): void {
		$result = self::migrate_batch( self::BATCH_SIZE, absint( get_option( self::OPTION . '_cursor', 0 ) ) );
		update_option( self::OPTION . '_cursor', $result['last'], false );
		if ( $result['remaining'] ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
			return;
		}
		update_option( self::OPTION, '1', false );
	}

	/** `wp atora submissions migrate-web` */
	public static function cli(): void {
		$total = array( 'migrated' => 0, 'skipped' => 0 );
		$after = 0;
		do {
			$result = self::migrate_batch( self::BATCH_SIZE, $after );
			$after  = $result['last'];
			$total['migrated'] += $result['migrated'];
			$total['skipped']  += $result['skipped'];
		} while ( $result['remaining'] );
		update_option( self::OPTION, '1', false );
		\WP_CLI::success( sprintf( 'Entregas web migradas: %d. Sin lección de tabla (omitidas): %d.', $total['migrated'], $total['skipped'] ) );
	}
}
