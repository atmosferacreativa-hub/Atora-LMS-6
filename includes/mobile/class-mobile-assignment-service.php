<?php
/**
 * Entregas de tareas por la API móvil (6.27.0).
 *
 * Reglas vinculantes (docs/SINCRONIZACION-OFFLINE.md):
 * - Solo añadir: cada intento es una fila nueva en atora_assignment_submissions.
 * - Idempotencia por client_event_id único por usuario.
 * - Doble marca de tiempo: is_late se calcula con server_received_at contra due_at;
 *   client_submitted_at es informativa.
 *
 * Puente con SpeedGrader: cada intento también actualiza el post clms_submission
 * del estudiante (el mismo que usa el flujo web), enlazado por wp_post_id.
 *
 * @package ATORA_LMS
 * @since 6.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Assignment_Service {
	const UPLOAD_TTL           = 6 * HOUR_IN_SECONDS;
	const DEFAULT_CHUNK        = 1048576;
	const MIN_CHUNK            = 65536;
	const MAX_CHUNK            = 5242880;
	const SUBMISSION_LIMIT     = 20;
	const SUBMISSION_WINDOW    = 10 * MINUTE_IN_SECONDS;
	const UPLOAD_SESSION_LIMIT = 60;
	const UPLOAD_SESSION_WINDOW = 10 * MINUTE_IN_SECONDS;
	const CLEANUP_HOOK         = 'atora_mobile_upload_cleanup';

	private ATORA_Mobile_Assignment_Store $store;
	private string $storage_dir;

	/** @var array{allowed_mimes: array<string,string>, max_file_size: int, max_files: int} */
	private array $policy;

	/** @var callable(): int */
	private $clock;

	/** @var callable(string, int, int, int): bool  (scope, identifier, limit, window) */
	private $rate_limiter;

	/** @var callable(string, string, array<string,string>): string  (path, filename, allowed) => ext real o '' */
	private $type_detector;

	/** @var callable(int, array, array, array): (array|WP_Error)  (user_id, lesson_ctx, submission_row, uploads) */
	private $bridge;

	public function __construct(
		ATORA_Mobile_Assignment_Store $store,
		string $storage_dir,
		array $policy,
		callable $clock,
		callable $rate_limiter,
		callable $type_detector,
		callable $bridge
	) {
		$this->store         = $store;
		$this->storage_dir   = rtrim( $storage_dir, '/\\' );
		$this->policy        = $policy;
		$this->clock         = $clock;
		$this->rate_limiter  = $rate_limiter;
		$this->type_detector = $type_detector;
		$this->bridge        = $bridge;
	}

	/**
	 * Instancia de producción: tablas reales, política de archivos del flujo web,
	 * limitador ATORA_Rate_Limiter y puente al CPT clms_submission.
	 */
	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$submission = self::submission_engine();
			$instance   = new self(
				new ATORA_Mobile_Assignment_Wpdb_Store(),
				self::default_storage_dir(),
				$submission ? $submission->get_upload_policy() : array( 'allowed_mimes' => array(), 'max_file_size' => 0, 'max_files' => 0 ),
				static function (): int { return time(); },
				static function ( string $scope, string $identifier, int $limit, int $window ): bool {
					return class_exists( 'ATORA_Rate_Limiter' )
						? ATORA_Rate_Limiter::consume( $scope, $identifier, $limit, $window, false )
						: true;
				},
				array( __CLASS__, 'detect_real_extension' ),
				array( __CLASS__, 'write_clms_submission' )
			);
		}
		return $instance;
	}

	public function policy(): array {
		return $this->policy;
	}

	// ── Subidas reanudables ─────────────────────────────────────────────────

	/**
	 * @param array{filename?:string,mime_type?:string,total_bytes?:int,chunk_size?:int} $params
	 * @return array|WP_Error
	 */
	public function create_upload_session( int $user_id, array $params ) {
		$filename    = self::clean_filename( (string) ( $params['filename'] ?? '' ) );
		$total_bytes = (int) ( $params['total_bytes'] ?? 0 );
		$chunk_size  = (int) ( $params['chunk_size'] ?? self::DEFAULT_CHUNK );
		$chunk_size  = min( self::MAX_CHUNK, max( self::MIN_CHUNK, $chunk_size ) );

		if ( '' === $filename ) {
			return self::error( 'atora_mobile_upload_filename', __( 'Falta el nombre del archivo.', 'atora-lms' ), 400 );
		}
		$ext = $this->declared_extension( $filename );
		if ( '' === $ext ) {
			return self::error( 'atora_mobile_upload_type', __( 'Formato de archivo no permitido.', 'atora-lms' ), 422 );
		}
		if ( $total_bytes < 1 || $total_bytes > (int) $this->policy['max_file_size'] ) {
			return self::error( 'atora_mobile_upload_size', __( 'El archivo supera el tamaño permitido.', 'atora-lms' ), 422 );
		}
		if ( ! ( $this->rate_limiter )( 'atora_mobile_upload_session', (string) $user_id, self::UPLOAD_SESSION_LIMIT, self::UPLOAD_SESSION_WINDOW ) ) {
			return self::error( 'atora_mobile_rate_limited', __( 'Demasiadas subidas. Espera antes de volver a intentarlo.', 'atora-lms' ), 429 );
		}

		$this->cleanup_expired( 20 );

		$dir = $this->ensure_storage_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$token = 'tok_' . bin2hex( random_bytes( 24 ) );
		$path  = $this->storage_dir . '/' . $token . '.part';
		if ( false === @file_put_contents( $path, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return self::error( 'atora_mobile_upload_storage', __( 'No se pudo preparar la subida.', 'atora-lms' ), 500 );
		}

		$now     = $this->now();
		$expires = gmdate( 'Y-m-d H:i:s', $now + self::UPLOAD_TTL );
		$id      = $this->store->insert_upload( array(
			'user_id'        => $user_id,
			'submission_id'  => 0,
			'upload_token'   => $token,
			'filename'       => $filename,
			'mime_type'      => (string) $this->policy['allowed_mimes'][ $ext ],
			'total_bytes'    => $total_bytes,
			'received_bytes' => 0,
			'chunk_size'     => $chunk_size,
			'status'         => 'open',
			'storage_path'   => $path,
			'created_at'     => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at'     => $expires,
		) );
		if ( $id <= 0 ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return self::error( 'atora_mobile_upload_storage', __( 'No se pudo preparar la subida.', 'atora-lms' ), 500 );
		}

		return array(
			'upload_token'   => $token,
			'expires_at'     => $expires,
			'received_bytes' => 0,
			'chunk_size'     => $chunk_size,
		);
	}

	/**
	 * Recibe un chunk. Solo se acepta en orden: start debe ser received_bytes.
	 *
	 * @return array|WP_Error
	 */
	public function put_chunk( int $user_id, string $upload_token, string $content_range, string $body ) {
		$upload = $this->owned_upload( $user_id, $upload_token );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}
		if ( 'open' !== (string) $upload['status'] ) {
			return self::error( 'atora_mobile_upload_closed', __( 'La subida ya está cerrada.', 'atora-lms' ), 409, array( 'received_bytes' => (int) $upload['received_bytes'] ) );
		}

		if ( ! preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/', trim( $content_range ), $m ) ) {
			return self::error( 'atora_mobile_upload_range', __( 'Content-Range inválido.', 'atora-lms' ), 400 );
		}
		$start    = (int) $m[1];
		$end      = (int) $m[2];
		$total    = (int) $m[3];
		$received = (int) $upload['received_bytes'];
		$length   = strlen( $body );

		if ( $total !== (int) $upload['total_bytes'] || $end < $start || $end >= $total ) {
			return self::error( 'atora_mobile_upload_range', __( 'Content-Range inválido.', 'atora-lms' ), 400 );
		}
		if ( $start !== $received ) {
			return self::error( 'atora_mobile_upload_out_of_order', __( 'El fragmento no continúa la subida.', 'atora-lms' ), 409, array( 'received_bytes' => $received ) );
		}
		if ( ( $end - $start + 1 ) !== $length || $length > (int) $upload['chunk_size'] ) {
			return self::error( 'atora_mobile_upload_range', __( 'El tamaño del fragmento no coincide.', 'atora-lms' ), 400 );
		}

		$path   = (string) $upload['storage_path'];
		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return self::error( 'atora_mobile_upload_storage', __( 'No se pudo guardar el fragmento.', 'atora-lms' ), 500 );
		}
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return self::error( 'atora_mobile_upload_storage', __( 'No se pudo guardar el fragmento.', 'atora-lms' ), 500 );
			}
			// Un fallo anterior pudo dejar bytes sin confirmar: el archivo siempre vuelve a received_bytes.
			ftruncate( $handle, $start );
			fseek( $handle, $start );
			$written = fwrite( $handle, $body );
			fflush( $handle );
			if ( $written !== $length ) {
				ftruncate( $handle, $start );
				return self::error( 'atora_mobile_upload_storage', __( 'No se pudo guardar el fragmento.', 'atora-lms' ), 500 );
			}
			if ( ! $this->store->advance_upload( (int) $upload['id'], $start, $start + $length ) ) {
				ftruncate( $handle, $start );
				$current = $this->store->find_upload( $upload_token );
				return self::error( 'atora_mobile_upload_out_of_order', __( 'El fragmento no continúa la subida.', 'atora-lms' ), 409, array( 'received_bytes' => (int) ( $current['received_bytes'] ?? $received ) ) );
			}
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}

		return array( 'received_bytes' => $start + $length, 'status' => 'open' );
	}

	/**
	 * Cierra la subida y valida el tipo real del archivo.
	 *
	 * @return array|WP_Error
	 */
	public function complete_upload( int $user_id, string $upload_token ) {
		$upload = $this->owned_upload( $user_id, $upload_token );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}
		if ( 'complete' === (string) $upload['status'] ) {
			return array( 'status' => 'complete', 'received_bytes' => (int) $upload['received_bytes'] );
		}
		if ( 'open' !== (string) $upload['status'] ) {
			return self::error( 'atora_mobile_upload_closed', __( 'La subida ya está cerrada.', 'atora-lms' ), 409 );
		}
		if ( (int) $upload['received_bytes'] !== (int) $upload['total_bytes'] ) {
			return self::error( 'atora_mobile_upload_incomplete', __( 'Faltan fragmentos por subir.', 'atora-lms' ), 409, array( 'received_bytes' => (int) $upload['received_bytes'] ) );
		}

		$path = (string) $upload['storage_path'];
		$ext  = ( $this->type_detector )( $path, (string) $upload['filename'], (array) $this->policy['allowed_mimes'] );
		if ( '' === $ext || ! isset( $this->policy['allowed_mimes'][ $ext ] ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->store->update_upload( (int) $upload['id'], array( 'status' => 'rejected' ) );
			return self::error( 'atora_mobile_upload_type', __( 'El contenido del archivo no corresponde a un formato permitido.', 'atora-lms' ), 422 );
		}

		$this->store->update_upload( (int) $upload['id'], array(
			'status'    => 'complete',
			'mime_type' => (string) $this->policy['allowed_mimes'][ $ext ],
		) );
		return array( 'status' => 'complete', 'received_bytes' => (int) $upload['received_bytes'] );
	}

	// ── Entregas ────────────────────────────────────────────────────────────

	/**
	 * Crea un intento. Reenviar el mismo client_event_id devuelve la misma entrega.
	 *
	 * @param array{lesson_id:int, wp_lesson_id:int, course_id:int, due_ts:int, allow_resubmission:bool, group_mode:bool} $lesson
	 * @param array $params Cuerpo JSON del contrato.
	 * @return array{submission: array, replayed: bool}|WP_Error
	 */
	public function create_submission( int $user_id, array $lesson, array $params ) {
		$event_id = trim( (string) ( $params['client_event_id'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $event_id ) ) {
			return self::error( 'atora_mobile_submission_event', __( 'client_event_id inválido.', 'atora-lms' ), 400 );
		}

		$existing = $this->store->find_submission_by_event( $user_id, $event_id );
		if ( $existing && 'processing' !== (string) $existing['status'] ) {
			if ( (int) $existing['lesson_id'] !== (int) $lesson['lesson_id'] ) {
				return self::error( 'atora_mobile_submission_event', __( 'client_event_id ya usado en otra lección.', 'atora-lms' ), 409 );
			}
			return array( 'submission' => self::present_submission( $existing ), 'replayed' => true );
		}
		if ( $existing ) {
			return self::error( 'atora_mobile_submission_in_progress', __( 'La entrega se está procesando. Reintenta en unos segundos.', 'atora-lms' ), 503 );
		}

		if ( ! empty( $lesson['group_mode'] ) ) {
			return self::error( 'atora_mobile_submission_group', __( 'Las tareas grupales se entregan desde la web.', 'atora-lms' ), 422 );
		}
		if ( ! ( $this->rate_limiter )( 'atora_mobile_submission', (string) $user_id, self::SUBMISSION_LIMIT, self::SUBMISSION_WINDOW ) ) {
			return self::error( 'atora_mobile_rate_limited', __( 'Demasiadas entregas. Espera antes de volver a intentarlo.', 'atora-lms' ), 429 );
		}

		$previous = $this->store->max_attempt( $user_id, (int) $lesson['lesson_id'] );
		if ( empty( $lesson['allow_resubmission'] ) && $previous > 0 ) {
			return self::error( 'atora_mobile_submission_closed', __( 'Esta tarea no admite más intentos.', 'atora-lms' ), 409 );
		}

		$body_text = isset( $params['body_text'] ) ? trim( (string) $params['body_text'] ) : '';
		$tokens    = array();
		foreach ( (array) ( $params['files'] ?? array() ) as $file ) {
			$token = is_array( $file ) ? trim( (string) ( $file['upload_token'] ?? '' ) ) : '';
			if ( '' !== $token ) {
				$tokens[ $token ] = true;
			}
		}
		$tokens = array_keys( $tokens );
		if ( count( $tokens ) > (int) $this->policy['max_files'] ) {
			return self::error( 'atora_mobile_submission_files', sprintf( __( 'Solo puedes adjuntar hasta %d archivos.', 'atora-lms' ), (int) $this->policy['max_files'] ), 422 );
		}
		if ( '' === $body_text && empty( $tokens ) ) {
			return self::error( 'atora_mobile_submission_empty', __( 'La entrega está vacía.', 'atora-lms' ), 422 );
		}

		$uploads = array();
		foreach ( $tokens as $token ) {
			$upload = $this->owned_upload( $user_id, $token, false );
			if ( is_wp_error( $upload ) ) {
				return $upload;
			}
			if ( 'complete' !== (string) $upload['status'] || 0 !== (int) $upload['submission_id'] ) {
				return self::error( 'atora_mobile_submission_upload_state', __( 'Uno de los archivos no está listo o ya se usó.', 'atora-lms' ), 409 );
			}
			$uploads[] = $upload;
		}

		$now      = $this->now();
		$due_ts   = (int) ( $lesson['due_ts'] ?? 0 );
		$client   = self::parse_client_time( $params['client_submitted_at'] ?? null );
		$row      = array(
			'user_id'             => $user_id,
			'course_id'           => (int) $lesson['course_id'],
			'lesson_id'           => (int) $lesson['lesson_id'],
			'wp_post_id'          => 0,
			'attempt'             => $previous + 1,
			'status'              => 'processing',
			'body_text'           => $body_text,
			'files_json'          => self::files_json( $uploads, array() ),
			'client_event_id'     => $event_id,
			'client_submitted_at' => $client,
			'server_received_at'  => gmdate( 'Y-m-d H:i:s', $now ),
			'due_at'              => $due_ts > 0 ? gmdate( 'Y-m-d H:i:s', $due_ts ) : null,
			'is_late'             => ( $due_ts > 0 && $now > $due_ts ) ? 1 : 0,
			'source'              => 'mobile',
			'created_at'          => gmdate( 'Y-m-d H:i:s', $now ),
		);

		$id = $this->store->insert_submission( $row );
		if ( $id <= 0 ) {
			// Carrera con otro envío del mismo evento: devolver ese.
			$winner = $this->store->find_submission_by_event( $user_id, $event_id );
			if ( $winner && 'processing' !== (string) $winner['status'] ) {
				return array( 'submission' => self::present_submission( $winner ), 'replayed' => true );
			}
			return self::error( 'atora_mobile_submission_in_progress', __( 'La entrega se está procesando. Reintenta en unos segundos.', 'atora-lms' ), 503 );
		}
		$row['id'] = $id;

		$bridged = ( $this->bridge )( $user_id, $lesson, $row, $uploads );
		if ( is_wp_error( $bridged ) ) {
			// Sin puente el docente no vería la entrega: se descarta y el cliente reintenta.
			$this->store->delete_submission( $id );
			return $bridged;
		}

		$final = array(
			'status'     => 'submitted',
			'wp_post_id' => (int) ( $bridged['wp_post_id'] ?? 0 ),
			'files_json' => self::files_json( $uploads, (array) ( $bridged['attachment_ids'] ?? array() ) ),
		);
		$this->store->update_submission( $id, $final );
		foreach ( $uploads as $upload ) {
			$this->store->update_upload( (int) $upload['id'], array( 'status' => 'attached', 'submission_id' => $id ) );
		}

		return array( 'submission' => self::present_submission( array_merge( $row, $final ) ), 'replayed' => false );
	}

	/** @return array<int, array> */
	public function list_submissions( int $user_id, int $lesson_id ): array {
		return array_map( array( __CLASS__, 'present_submission' ), $this->store->list_submissions( $user_id, $lesson_id ) );
	}

	/** Borra sesiones vencidas y sus archivos parciales. */
	public function cleanup_expired( int $limit = 200 ): int {
		$removed = 0;
		foreach ( $this->store->expired_uploads( gmdate( 'Y-m-d H:i:s', $this->now() ), $limit ) as $upload ) {
			$path = (string) ( $upload['storage_path'] ?? '' );
			if ( '' !== $path && 0 === strpos( $path, $this->storage_dir . '/' ) && is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$this->store->delete_upload( (int) $upload['id'] );
			$removed++;
		}
		return $removed;
	}

	// ── Presentación ────────────────────────────────────────────────────────

	public static function present_submission( array $row ): array {
		$files = json_decode( (string) ( $row['files_json'] ?? '' ), true );
		return array(
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'lesson_id'           => (int) ( $row['lesson_id'] ?? 0 ),
			'attempt'             => (int) ( $row['attempt'] ?? 1 ),
			'status'              => (string) ( $row['status'] ?? 'submitted' ),
			'body_text'           => (string) ( $row['body_text'] ?? '' ),
			'files'               => is_array( $files ) ? array_values( array_map( static function ( $f ) {
				return array(
					'filename'  => (string) ( $f['filename'] ?? '' ),
					'mime_type' => (string) ( $f['mime_type'] ?? '' ),
					'bytes'     => (int) ( $f['bytes'] ?? 0 ),
				);
			}, $files ) ) : array(),
			'client_submitted_at' => isset( $row['client_submitted_at'] ) && '' !== (string) $row['client_submitted_at'] ? (string) $row['client_submitted_at'] : null,
			'server_received_at'  => (string) ( $row['server_received_at'] ?? '' ),
			'due_at'              => isset( $row['due_at'] ) && '' !== (string) $row['due_at'] ? (string) $row['due_at'] : null,
			'is_late'             => ! empty( $row['is_late'] ),
			'wp_post_id'          => (int) ( $row['wp_post_id'] ?? 0 ),
		);
	}

	// ── Internos ────────────────────────────────────────────────────────────

	/**
	 * Sesión de subida del usuario. La de otro usuario responde 404, igual que una inexistente.
	 *
	 * @return array|WP_Error
	 */
	private function owned_upload( int $user_id, string $upload_token, bool $check_expiry = true ) {
		$upload = '' !== $upload_token ? $this->store->find_upload( $upload_token ) : null;
		if ( ! $upload || (int) $upload['user_id'] !== $user_id ) {
			return self::error( 'atora_mobile_upload_not_found', __( 'Subida no encontrada.', 'atora-lms' ), 404 );
		}
		$expires = isset( $upload['expires_at'] ) ? strtotime( (string) $upload['expires_at'] . ' UTC' ) : 0;
		if ( $check_expiry && $expires && $this->now() >= $expires ) {
			return self::error( 'atora_mobile_upload_expired', __( 'La subida caducó. Inicia una nueva.', 'atora-lms' ), 410 );
		}
		return $upload;
	}

	private function declared_extension( string $filename ): string {
		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		return isset( $this->policy['allowed_mimes'][ $ext ] ) ? $ext : '';
	}

	/** @return true|WP_Error */
	private function ensure_storage_dir() {
		if ( ! is_dir( $this->storage_dir ) && ! @mkdir( $this->storage_dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return self::error( 'atora_mobile_upload_storage', __( 'No se pudo preparar la subida.', 'atora-lms' ), 500 );
		}
		// Bloquea el acceso web directo (Apache) y el listado del directorio.
		if ( ! is_file( $this->storage_dir . '/.htaccess' ) ) {
			@file_put_contents( $this->storage_dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! is_file( $this->storage_dir . '/index.php' ) ) {
			@file_put_contents( $this->storage_dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}

	private function now(): int {
		return (int) ( $this->clock )();
	}

	private static function files_json( array $uploads, array $attachment_ids ): string {
		$out = array();
		foreach ( array_values( $uploads ) as $i => $upload ) {
			$out[] = array(
				'upload_token'  => (string) $upload['upload_token'],
				'filename'      => (string) $upload['filename'],
				'mime_type'     => (string) $upload['mime_type'],
				'bytes'         => (int) $upload['total_bytes'],
				'attachment_id' => (int) ( $attachment_ids[ $i ] ?? 0 ),
			);
		}
		return (string) wp_json_encode( $out );
	}

	private static function parse_client_time( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	private static function clean_filename( string $filename ): string {
		$filename = basename( str_replace( '\\', '/', $filename ) );
		if ( function_exists( 'sanitize_file_name' ) ) {
			return (string) sanitize_file_name( $filename );
		}
		return (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $filename );
	}

	private static function error( string $code, string $message, int $status, array $extra = array() ): WP_Error {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	// ── Integración WordPress (producción) ──────────────────────────────────

	/** Instancia compartida de CLMS_Submission (no se crea otra para no duplicar sus hooks). */
	public static function submission_engine(): ?CLMS_Submission {
		$engine = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Submission' ) : null;
		return $engine instanceof CLMS_Submission ? $engine : null;
	}

	public static function default_storage_dir(): string {
		$base = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			$base    = (string) ( $uploads['basedir'] ?? '' );
		}
		if ( '' === $base ) {
			$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : sys_get_temp_dir();
		}
		$dir = $base . '/atora-private/mobile-uploads';
		/** Permite ubicar los fragmentos fuera de la raíz web. */
		return (string) apply_filters( 'atora/mobile/upload_dir', $dir );
	}

	/**
	 * Extensión real según el contenido (misma regla que el flujo web, incluida la
	 * excepción de docx/odt detectados como zip).
	 */
	public static function detect_real_extension( string $path, string $filename, array $allowed ): string {
		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$check = wp_check_filetype_and_ext( $path, $filename, $allowed );
		$ext   = ! empty( $check['ext'] ) ? (string) $check['ext'] : '';
		if ( '' === $ext || 'zip' === $ext ) {
			$name_check = wp_check_filetype( $filename, $allowed );
			$name_ext   = ! empty( $name_check['ext'] ) ? (string) $name_check['ext'] : '';
			if ( in_array( $name_ext, array( 'docx', 'odt' ), true ) && class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( true === $zip->open( $path ) ) {
					$zip->close();
					$ext = $name_ext;
				}
			}
		}
		return isset( $allowed[ $ext ] ) ? $ext : '';
	}

	/**
	 * Escribe/actualiza el post clms_submission que leen SpeedGrader y el Gradebook.
	 *
	 * @return array{wp_post_id:int, attachment_ids:int[]}|WP_Error
	 */
	public static function write_clms_submission( int $user_id, array $lesson, array $row, array $uploads ) {
		$engine = self::submission_engine();
		if ( ! $engine ) {
			return self::error( 'atora_mobile_submission_unavailable', __( 'El motor de entregas no está disponible.', 'atora-lms' ), 503 );
		}
		$wp_lesson_id = (int) $lesson['wp_lesson_id'];
		$post_id      = $engine->save_submission( $user_id, $wp_lesson_id, array( 'comment' => (string) $row['body_text'] ), array() );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return self::error( 'atora_mobile_submission_bridge', __( 'No se pudo registrar la entrega.', 'atora-lms' ), 500 );
		}
		$post_id = (int) $post_id;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = array();
		foreach ( $uploads as $upload ) {
			$attachment_id = media_handle_sideload(
				array(
					'name'     => (string) $upload['filename'],
					'tmp_name' => (string) $upload['storage_path'],
				),
				$post_id
			);
			if ( is_wp_error( $attachment_id ) ) {
				return self::error( 'atora_mobile_submission_bridge', __( 'No se pudo guardar uno de los archivos adjuntos.', 'atora-lms' ), 500 );
			}
			update_post_meta( $attachment_id, '_clms_submission_owner', $user_id );
			update_post_meta( $attachment_id, '_clms_submission_id', $post_id );
			$attachment_ids[] = (int) $attachment_id;
		}

		// El intento móvil reemplaza los adjuntos del intento anterior en el post (el historial vive en la tabla).
		update_post_meta( $post_id, '_clms_submission_files', $attachment_ids );
		update_post_meta( $post_id, '_clms_submission_attachments', $attachment_ids );
		update_post_meta( $post_id, '_clms_submission_source', 'mobile' );
		update_post_meta( $post_id, '_clms_submission_mobile_row_id', (int) $row['id'] );
		update_post_meta( $post_id, '_clms_submission_is_late', (int) $row['is_late'] );
		if ( ! empty( $row['client_submitted_at'] ) ) {
			update_post_meta( $post_id, '_clms_submission_client_submitted_at', get_date_from_gmt( (string) $row['client_submitted_at'] ) );
		} else {
			delete_post_meta( $post_id, '_clms_submission_client_submitted_at' );
		}

		return array( 'wp_post_id' => $post_id, 'attachment_ids' => $attachment_ids );
	}
}
