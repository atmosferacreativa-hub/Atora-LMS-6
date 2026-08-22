<?php
/**
 * API_Key_Service — Gestión de API Keys para MCP y acceso externo (Fase 12C)
 *
 * @package ATORA_LMS
 * @since   5.29.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_API_Key_Service {

	const PREFIX = 'atora_';
	const TABLE  = 'atora_api_keys';

	/**
	 * Genera una nueva API key y la almacena (solo el hash).
	 * Retorna la key en texto plano solo una vez — no se puede recuperar.
	 *
	 * @param int    $user_id Usuario propietario.
	 * @param string $name    Nombre descriptivo.
	 * @param string $scopes  Scopes separados por coma: read,write,crm,lms,automation,all
	 * @return array|false { key, prefix, id } o false si falla.
	 */
	public static function create( int $user_id, string $name, string $scopes = 'read' ): array|false {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		if ( ! self::table_exists( $table ) ) { return false; }

		$raw_key    = self::PREFIX . bin2hex( random_bytes( 20 ) );
		$key_hash   = hash( 'sha256', $raw_key );
		$key_prefix = substr( $raw_key, 0, 8 );

		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'name'       => sanitize_text_field( $name ),
				'key_hash'   => $key_hash,
				'key_prefix' => $key_prefix,
				'scopes'     => sanitize_text_field( $scopes ),
				'is_active'  => 1,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $ok ) { return false; }

		return array(
			'id'     => (int) $wpdb->insert_id,
			'key'    => $raw_key, // solo aquí — no se vuelve a mostrar
			'prefix' => $key_prefix,
			'name'   => $name,
			'scopes' => $scopes,
		);
	}

	/**
	 * Valida una API key y devuelve sus datos si es válida.
	 *
	 * @param string $raw_key   Key en texto plano del header Authorization.
	 * @param string $operation PT-6.2 (6.5.1): 'read'|'write' — la operación
	 *                          real que se va a ejecutar, para aplicar el
	 *                          límite de rate limiting correcto. La
	 *                          autorización de si la key puede hacer esa
	 *                          operación la sigue decidiendo has_scope()
	 *                          después de esto, sin cambios.
	 * @return array|null Fila de la key o null si inválida/inactiva.
	 */
	public static function validate( string $raw_key, string $operation = 'read' ): ?array {
		global $wpdb;

		if ( '' === $raw_key || ! str_starts_with( $raw_key, self::PREFIX ) ) {
			return null;
		}

		$table    = $wpdb->prefix . self::TABLE;
		$key_hash = hash( 'sha256', $raw_key );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE key_hash = %s
				   AND is_active = 1
				   AND (expires_at IS NULL OR expires_at > %s)
				 LIMIT 1",
				$key_hash,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		if ( empty( $row ) ) { return null; }

		// ── Fase IV S14: Rate limiting por minuto ─────────────────────────────
		// PT-6.1 (6.5.1): el bucket era substr($raw_key,0,8) — con el
		// prefijo fijo "atora_" (6 chars), solo quedaban 2 caracteres hex
		// aleatorios de verdad: 256 combinaciones, colisión trivial entre
		// keys distintas. Se usa el id de la fila (clave primaria, ya
		// disponible aquí) en su lugar.
		$key_id = absint( $row['id'] ?? 0 );

		// PT-6.2 (6.5.1): antes se hacía sanitize_key() sobre el string
		// completo de scopes ("read,write" → "readwrite", que no coincide
		// con ninguna clave de $limits y siempre caía al default de 100
		// sin importar el scope real). Se explota igual que has_scope()
		// y se aplica el límite de la operación que de verdad se va a
		// ejecutar.
		//
		// PT-4 (6.5.4): 'all' ya NO tiene su propio número — antes una
		// key con ese scope podía hacer hasta 200 escrituras/min, diez
		// veces el límite de una key 'write' pura. 'all' es la unión de
		// capacidades (qué puede hacer, ya decidido por has_scope() más
		// abajo en el flujo), no una categoría de límite más permisiva:
		// el límite depende siempre de la operación real que se
		// ejecuta, nunca del scope declarado.
		$operation = sanitize_key( $operation );
		$limits    = array( 'read' => 100, 'write' => 20 );
		$limit     = $limits[ $operation ] ?? 100;
		$minute    = gmdate( 'YmdHi' );

		// PT-5 (6.5.5): el contador era get_transient()+set_transient()
		// (leer → incrementar en PHP → escribir) — dos peticiones
		// concurrentes de la misma key podían leer el mismo valor antes
		// de que cualquiera escribiera, perdiendo un incremento y
		// dejando pasar más peticiones que el límite real bajo carga.
		// wp_cache_incr()/add() solo son atómicos de verdad con un
		// object cache persistente (Redis/Memcached) — no garantizado en
		// una instalación estándar — así que se usa la misma tabla
		// dedicada + INSERT ... ON DUPLICATE KEY UPDATE que
		// Forms_Builder::is_throttled() (PT-1), atómico por bloqueo de
		// fila InnoDB, funciona con cualquier MySQL/MariaDB estándar.
		$rl_table = $wpdb->prefix . 'atora_api_rate_limit';

		// PT-8 (6.5.8): hallazgo real — si el INSERT/SELECT de abajo
		// fallaban, get_var() devolvía null, (int) null = 0, y
		// "0 > $limit" es false: la API key quedaba SIN límite de
		// peticiones mientras el backend del contador estuviera roto.
		// Ahora se falla cerrado — un fallo de infraestructura rechaza
		// la petición (return null, igual que "límite excedido"), nunca
		// la deja pasar sin límite.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$rl_table} (key_id, operation, minute_key, requests) VALUES (%d, %s, %s, 1)
				 ON DUPLICATE KEY UPDATE requests = requests + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key_id,
				$operation,
				$minute
			)
		);

		if ( false === $inserted ) {
			return null; // fail-closed.
		}

		$current_req = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT requests FROM {$rl_table} WHERE key_id = %d AND operation = %s AND minute_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key_id,
				$operation,
				$minute
			)
		);

		if ( null === $current_req ) {
			return null; // fail-closed.
		}

		if ( (int) $current_req > $limit ) {
			// Rate limit excedido — devolver null para que el autenticador rechace
			return null;
		}
		// ── /Rate limiting ────────────────────────────────────────────────────

		$wpdb->update( $table, array( 'last_used' => current_time( 'mysql', true ) ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );

		return $row;
	}

	/**
	 * Lista las keys de un usuario (sin el hash).
	 */
	public static function list( int $user_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ( ! self::table_exists( $table ) ) { return array(); }

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, key_prefix, scopes, last_used, expires_at, is_active, created_at
				 FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		);
	}

	/**
	 * Revoca (desactiva) una API key.
	 */
	public static function revoke( int $key_id, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return false !== $wpdb->update(
			$table,
			array( 'is_active' => 0 ),
			array( 'id' => $key_id, 'user_id' => $user_id ),
			array( '%d' ), array( '%d', '%d' )
		);
	}

	/**
	 * Verifica si la key tiene un scope concreto.
	 */
	public static function has_scope( array $key_row, string $scope ): bool {
		$scopes = explode( ',', sanitize_text_field( (string) ( $key_row['scopes'] ?? '' ) ) );
		return in_array( 'all', $scopes, true ) || in_array( $scope, $scopes, true );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}
}
