<?php
/**
 * Suscriptores del boletín como contactos del CRM (6.38.0, Orden C · Bloque 2).
 *
 * - El boletín es una lista del CRM (`atora_crm_lists`, slug `boletin`); un suscriptor es un contacto
 *   (`atora_contacts`) con estado en `atora_contact_list_pivot`: subscribed | pending | unsubscribed.
 * - Doble opt-in configurable (`atora_newsletter_double_optin`, activo por defecto): el alta queda pendiente
 *   hasta que la persona confirma con el enlace firmado del correo.
 * - Cada alta, confirmación y baja queda en `atora_email_consent_log` con fecha, IP y origen.
 * - Segmentos resueltos en la base de datos, paginados por ID de contacto.
 * - Migración idempotente desde el modelo anterior (usuarios + marca de consentimiento). Decisión del titular
 *   (2026-10-27): solo el consentimiento explícito queda suscrito; quien no tenía marca queda pendiente.
 *
 * @package ATORA_LMS
 * @since 6.38.0
 */

namespace ATORA\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Newsletter_Subscribers {

	const LIST_SLUG       = 'boletin';
	const OPTION_DOUBLE   = 'atora_newsletter_double_optin';
	const STATUSES        = array( 'subscribed', 'pending', 'unsubscribed' );
	const RULE_TYPES      = array( 'tag', 'list', 'course', 'program', 'institution', 'enrollment_status', 'score_min', 'active_within_days', 'inactive_for_days', 'meta' );
	const SIGNUP_PER_HOUR = 10;

	public static function init(): void {
		add_action( 'atora/email/unsubscribed', array( __CLASS__, 'on_user_unsubscribed' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_shortcode( 'atora_boletin', array( __CLASS__, 'render_signup_shortcode' ) );
	}

	// ── Lista ───────────────────────────────────────────────────────────────

	/** ID de la lista sin crearla (0 si no existe). Para lecturas. */
	public static function existing_list_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_crm_lists WHERE slug = %s LIMIT 1", self::LIST_SLUG ) ); // phpcs:ignore WordPress.DB
	}

	public static function list_id(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_crm_lists';
		$id    = self::existing_list_id();
		if ( $id ) {
			return $id;
		}
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"INSERT IGNORE INTO {$table} (title, slug, description, type, is_public, created_by) VALUES (%s, %s, %s, 'newsletter', 1, 0)",
			__( 'Boletín', 'atora-lms' ),
			self::LIST_SLUG,
			__( 'Suscriptores del boletín.', 'atora-lms' )
		) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", self::LIST_SLUG ) ); // phpcs:ignore WordPress.DB
	}

	public static function double_optin(): bool {
		return (bool) get_option( self::OPTION_DOUBLE, 1 );
	}

	public static function normalize_email( string $email ): string {
		return strtolower( trim( sanitize_email( $email ) ) );
	}

	// ── Contactos ───────────────────────────────────────────────────────────

	/** Contacto del usuario o del correo; lo crea si no existe. Nunca duplica por correo. */
	public static function ensure_contact( string $email, string $name = '', int $user_id = 0, string $source = 'newsletter' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contacts';
		$email = self::normalize_email( $email );
		if ( $user_id > 0 ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB
			if ( $id ) {
				return $id;
			}
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE LOWER(TRIM(email)) = %s ORDER BY id ASC LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB
		if ( $id ) {
			if ( $user_id > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = %d WHERE id = %d AND (user_id IS NULL OR user_id = 0)", $user_id, $id ) ); // phpcs:ignore WordPress.DB
			}
			return $id;
		}
		$record = array(
			'email'      => $email,
			'name'       => sanitize_text_field( $name ),
			'source'     => sanitize_key( $source ),
			'status'     => 'lead',
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $user_id > 0 ) {
			$record['user_id'] = $user_id;
		}
		$suppress = $wpdb->suppress_errors( true ); // Carrera con otra alta del mismo correo: la clave única gana.
		$wpdb->insert( $table, $record );
		$wpdb->suppress_errors( $suppress );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB
	}

	/** @return object|null fila de atora_contacts */
	public static function contact( int $contact_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1", $contact_id ) ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function status( int $contact_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_contact_list_pivot WHERE contact_id = %d AND list_id = %d LIMIT 1", $contact_id, self::list_id() ) ); // phpcs:ignore WordPress.DB
	}

	// ── Alta, confirmación y baja ───────────────────────────────────────────

	/**
	 * Alta en el boletín. Con doble opt-in queda pendiente y se manda el correo de confirmación.
	 *
	 * @return string subscribed | pending | unchanged | invalid
	 */
	public static function subscribe( string $email, string $name = '', int $user_id = 0, string $source = 'form', ?bool $double = null ): string {
		$contact_id = self::ensure_contact( $email, $name, $user_id, $source );
		if ( ! $contact_id ) {
			return 'invalid';
		}
		$current = self::status( $contact_id );
		if ( 'subscribed' === $current ) {
			return 'unchanged';
		}
		$double = null === $double ? self::double_optin() : $double;
		$target = $double ? 'pending' : 'subscribed';
		self::set_status( $contact_id, $target );
		self::log( $contact_id, 'subscribed' === $target ? 'subscribe' : 'subscribe_pending', $source );
		if ( 'pending' === $target ) {
			self::send_confirmation( $contact_id );
		}
		return $target;
	}

	public static function confirm_token( int $contact_id, string $email ): string {
		return 'n' . $contact_id . '.' . substr( hash_hmac( 'sha256', 'atora-nl-confirm|' . $contact_id . '|' . self::normalize_email( $email ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function confirm_url( int $contact_id, string $email ): string {
		return add_query_arg( array( 'atora_email_action' => 'confirm_subscription', 'atora_email_token' => self::confirm_token( $contact_id, $email ) ), home_url( '/' ) );
	}

	/** @return string confirmed | invalid */
	public static function confirm( string $token ): string {
		if ( 1 !== preg_match( '/^n(\d{1,20})\.([a-f0-9]{32})$/', $token, $m ) ) {
			return 'invalid';
		}
		$contact = self::contact( (int) $m[1] );
		if ( ! $contact || ! hash_equals( self::confirm_token( (int) $contact->id, (string) $contact->email ), $token ) ) {
			return 'invalid';
		}
		if ( 'subscribed' !== self::status( (int) $contact->id ) ) {
			self::set_status( (int) $contact->id, 'subscribed' );
			self::log( (int) $contact->id, 'subscribe_confirmed', 'email_link' );
		}
		return 'confirmed';
	}

	public static function unsubscribe( int $contact_id, string $source = 'email_link' ): void {
		if ( 'unsubscribed' === self::status( $contact_id ) ) {
			return;
		}
		self::set_status( $contact_id, 'unsubscribed' );
		self::log( $contact_id, 'unsubscribe', $source );
	}

	/** Baja general de un usuario (enlace del pie): también sale del boletín. */
	public static function on_user_unsubscribed( int $user_id ): void {
		global $wpdb;
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_contacts WHERE user_id = %d LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB
		if ( $contact_id && '' !== self::status( $contact_id ) ) {
			self::unsubscribe( $contact_id );
		}
	}

	public static function send_confirmation( int $contact_id ): bool {
		$contact = self::contact( $contact_id );
		if ( ! $contact || ! class_exists( '\\ATORA\\EmailEngine\\Email_Queue' ) ) {
			return false;
		}
		$args = array(
			'template'              => 'newsletter_confirm',
			'priority'              => 'high',
			'dedupe_window_minutes' => 60,
			'metadata'              => array(
				'contact_id'  => $contact_id,
				'confirm_url' => self::confirm_url( $contact_id, (string) $contact->email ),
			),
		);
		if ( ! empty( $contact->user_id ) ) {
			$args['user_id'] = (int) $contact->user_id;
		} else {
			$args['contact_id'] = $contact_id;
		}
		return (bool) \ATORA\EmailEngine\Email_Queue::enqueue( $args );
	}

	private static function set_status( int $contact_id, string $status ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"INSERT INTO {$wpdb->prefix}atora_contact_list_pivot (contact_id, list_id, status, joined_at, updated_at) VALUES (%d, %d, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)",
			$contact_id,
			self::list_id(),
			$status,
			current_time( 'mysql', true ),
			current_time( 'mysql', true )
		) );
	}

	private static function log( int $contact_id, string $action, string $source ): void {
		global $wpdb;
		$contact = self::contact( $contact_id );
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$wpdb->insert(
			$wpdb->prefix . 'atora_email_consent_log',
			array(
				'user_id'        => $contact && ! empty( $contact->user_id ) ? (int) $contact->user_id : 0,
				'action'         => substr( $action, 0, 30 ),
				'preference_key' => 'newsletter:contact:' . $contact_id,
				'old_value'      => null,
				'new_value'      => in_array( $action, array( 'subscribe', 'subscribe_confirmed', 'migrated_subscribed' ), true ) ? 1 : 0,
				'ip_address'     => false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
				'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
				'source'         => substr( sanitize_key( $source ), 0, 60 ),
				'created_at'     => current_time( 'mysql', true ),
			)
		);
	}

	// ── Segmentos ───────────────────────────────────────────────────────────

	/**
	 * Una página de suscriptores confirmados (sin suprimidos ni bajas), ordenada por contacto.
	 *
	 * Reglas (todas deben cumplirse): tag, list, course, program, institution, enrollment_status, score_min,
	 * active_within_days, inactive_for_days, meta (campo/operador/valor sobre la meta `atora_{campo}`).
	 *
	 * @return array<int,array{contact_id:int,user_id:int}>
	 */
	public static function page( array $segment, int $after_contact_id, int $limit ): array {
		global $wpdb;
		$p       = $wpdb->prefix;
		$where   = array();
		$args    = array();
		$rules   = (array) ( $segment['rules'] ?? array() );
		$columns = null;

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$type  = sanitize_key( (string) ( $rule['type'] ?? ( isset( $rule['field'] ) ? 'meta' : '' ) ) );
			$value = $rule['value'] ?? '';
			switch ( $type ) {
				case 'tag':
					$where[] = "EXISTS (SELECT 1 FROM {$p}atora_contact_tags t WHERE t.contact_id = c.id AND t.tag_name = %s)";
					$args[]  = sanitize_text_field( (string) $value );
					break;
				case 'list':
					$where[] = "EXISTS (SELECT 1 FROM {$p}atora_contact_list_pivot l WHERE l.contact_id = c.id AND l.list_id = %d AND l.status = 'subscribed')";
					$args[]  = absint( $value );
					break;
				case 'course':
					$where[] = "EXISTS (SELECT 1 FROM {$p}atora_enrollments e WHERE e.user_id = c.user_id AND (e.course_id = %d OR e.wp_course_id = %d))";
					$args[]  = absint( $value );
					$args[]  = absint( $value );
					break;
				case 'program':
					$where[] = "EXISTS (SELECT 1 FROM {$p}atora_program_enrollments pe WHERE pe.user_id = c.user_id AND (pe.program_id = %d OR pe.wp_program_id = %d))";
					$args[]  = absint( $value );
					$args[]  = absint( $value );
					break;
				case 'institution':
					$where[] = "EXISTS (SELECT 1 FROM {$p}atora_institution_members im WHERE im.user_id = c.user_id AND im.institution_id = %d AND im.status = 'active')";
					$args[]  = absint( $value );
					break;
				case 'enrollment_status':
					$where[] = "EXISTS (SELECT 1 FROM {$p}atora_enrollments es WHERE es.user_id = c.user_id AND es.status = %s)";
					$args[]  = sanitize_key( (string) $value );
					break;
				case 'score_min':
					$columns = $columns ?? (array) $wpdb->get_col( "SHOW COLUMNS FROM {$p}atora_contacts" ); // phpcs:ignore WordPress.DB
					if ( in_array( 'conversion_score', $columns, true ) ) {
						$where[] = 'c.conversion_score >= %d';
						$args[]  = absint( $value );
					} else {
						$where[] = '1 = 0'; // Sin scoring del CRM no hay a quién elegir: mejor nadie que todos.
					}
					break;
				case 'active_within_days':
				case 'inactive_for_days':
					$since   = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - absint( $value ) * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp -- la meta se guarda en hora local.
					$exists  = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} a WHERE a.user_id = c.user_id AND a.meta_key = '_clms_last_access_at' AND a.meta_value >= %s)";
					$where[] = 'active_within_days' === $type ? $exists : "NOT {$exists}";
					$args[]  = $since;
					break;
				case 'meta':
					$field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
					if ( '' === $field || strlen( $field ) > 50 ) {
						break;
					}
					$operator = sanitize_key( (string) ( $rule['operator'] ?? 'equals' ) );
					$val      = sanitize_text_field( (string) $value );
					$sub      = "SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id = c.user_id AND m.meta_key = %s AND ";
					if ( 'not_equals' === $operator ) {
						$where[] = "NOT EXISTS ({$sub} m.meta_value = %s)";
						array_push( $args, 'atora_' . $field, $val );
					} elseif ( 'contains' === $operator ) {
						$where[] = "EXISTS ({$sub} m.meta_value LIKE %s)";
						array_push( $args, 'atora_' . $field, '%' . $wpdb->esc_like( $val ) . '%' );
					} else {
						$where[] = "EXISTS ({$sub} m.meta_value = %s)";
						array_push( $args, 'atora_' . $field, $val );
					}
					break;
			}
		}

		$sql = "SELECT c.id AS contact_id, COALESCE(c.user_id, 0) AS user_id
			FROM {$p}atora_contacts c
			JOIN {$p}atora_contact_list_pivot lp ON lp.contact_id = c.id AND lp.list_id = %d AND lp.status = 'subscribed'
			LEFT JOIN {$p}atora_email_suppression s ON s.email = c.email
			LEFT JOIN {$p}atora_email_preferences ep ON ep.user_id = c.user_id
			WHERE c.id > %d AND s.id IS NULL AND (ep.unsubscribed_all IS NULL OR ep.unsubscribed_all = 0)"
			. ( $where ? ' AND ' . implode( ' AND ', $where ) : '' )
			. ' ORDER BY c.id ASC LIMIT %d';
		$params = array_merge( array( self::list_id(), $after_contact_id ), $args, array( max( 1, $limit ) ) );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( static fn( array $r ): array => array( 'contact_id' => (int) $r['contact_id'], 'user_id' => (int) $r['user_id'] ), $rows );
	}

	public static function count( array $segment = array() ): int {
		$total  = 0;
		$cursor = 0;
		do {
			$page   = self::page( $segment, $cursor, 1000 );
			$total += count( $page );
			$cursor = $page ? (int) end( $page )['contact_id'] : $cursor;
		} while ( 1000 === count( $page ) );
		return $total;
	}

	// ── Migración ───────────────────────────────────────────────────────────

	/**
	 * Pasa los suscriptores del modelo anterior (usuarios con la marca `atora_consent_marketing`) a la lista.
	 * Idempotente: no toca a quien ya está en la lista ni duplica contactos (correo normalizado).
	 *
	 * - marca '1' → suscrito; sin marca → pendiente (sin enviar correo); marca '0' → no entra.
	 * - quien se dio de baja de todo o está suprimido → no entra.
	 *
	 * @return array{subscribed:int,pending:int,skipped_existing:int,excluded:int,contacts_created:int}
	 */
	public static function migrate( bool $dry_run = false, int $batch = 500 ): array {
		global $wpdb;
		$out    = array( 'subscribed' => 0, 'pending' => 0, 'skipped_existing' => 0, 'excluded' => 0, 'contacts_created' => 0 );
		$list   = $dry_run ? self::existing_list_id() : self::list_id();
		$cursor = 0;
		do {
			$users = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT u.ID, u.user_email, u.display_name, m.meta_value AS consent,
				        (ep.unsubscribed_all = 1) AS unsubscribed, (s.id IS NOT NULL) AS suppressed
				 FROM {$wpdb->users} u
				 LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'atora_consent_marketing'
				 LEFT JOIN {$wpdb->prefix}atora_email_preferences ep ON ep.user_id = u.ID
				 LEFT JOIN {$wpdb->prefix}atora_email_suppression s ON s.email = u.user_email
				 WHERE u.ID > %d ORDER BY u.ID ASC LIMIT %d",
				$cursor,
				$batch
			) );
			foreach ( $users as $u ) {
				$cursor = (int) $u->ID;
				if ( '0' === (string) $u->consent || ! empty( $u->unsubscribed ) || ! empty( $u->suppressed ) || ! is_email( (string) $u->user_email ) ) {
					$out['excluded']++;
					continue;
				}
				$target   = '1' === (string) $u->consent ? 'subscribed' : 'pending';
				$existing = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
					"SELECT id FROM {$wpdb->prefix}atora_contacts WHERE user_id = %d OR LOWER(TRIM(email)) = %s ORDER BY id ASC LIMIT 1",
					(int) $u->ID,
					self::normalize_email( (string) $u->user_email )
				) );
				$in_list = $existing && $list ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_contact_list_pivot WHERE contact_id = %d AND list_id = %d", $existing, $list ) ) : ''; // phpcs:ignore WordPress.DB
				if ( '' !== $in_list ) {
					$out['skipped_existing']++;
					continue;
				}
				if ( ! $existing ) {
					$out['contacts_created']++;
				}
				$out[ $target ]++;
				if ( $dry_run ) {
					continue;
				}
				$contact_id = $existing ?: self::ensure_contact( (string) $u->user_email, (string) $u->display_name, (int) $u->ID, 'migration' );
				if ( ! $contact_id ) {
					continue;
				}
				$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
					"INSERT IGNORE INTO {$wpdb->prefix}atora_contact_list_pivot (contact_id, list_id, status, joined_at, updated_at) VALUES (%d, %d, %s, %s, %s)",
					$contact_id,
					$list,
					$target,
					current_time( 'mysql', true ),
					current_time( 'mysql', true )
				) );
				self::log( $contact_id, 'migrated_' . $target, 'migration' );
			}
		} while ( count( $users ) === $batch );
		if ( ! $dry_run ) {
			update_option( 'atora_newsletter_migrated_at', current_time( 'mysql', true ), false );
		}
		return $out;
	}

	/** Envía la confirmación a los pendientes (una vez cada uno). @return int enviados */
	public static function send_pending_confirmations( int $limit = 500 ): int {
		global $wpdb;
		$ids  = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT contact_id FROM {$wpdb->prefix}atora_contact_list_pivot WHERE list_id = %d AND status = 'pending' ORDER BY contact_id ASC LIMIT %d",
			self::list_id(),
			$limit
		) ) );
		$sent = 0;
		foreach ( $ids as $id ) {
			$sent += self::send_confirmation( $id ) ? 1 : 0;
		}
		return $sent;
	}

	// ── Formulario público ──────────────────────────────────────────────────

	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/newsletter/subscribe', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_subscribe' ),
			'permission_callback' => '__return_true', // Público: limitado por IP y con campo trampa.
			'args'                => array(
				'email'   => array( 'type' => 'string', 'required' => true ),
				'name'    => array( 'type' => 'string' ),
				'consent' => array( 'type' => 'boolean', 'required' => true ),
				'website' => array( 'type' => 'string' ),
			),
		) );
	}

	public static function rest_subscribe( \WP_REST_Request $r ): \WP_REST_Response {
		if ( '' !== trim( (string) $r->get_param( 'website' ) ) ) {
			return new \WP_REST_Response( array( 'status' => 'pending' ), 200 ); // Bot: se responde igual, no se guarda nada.
		}
		if ( true !== rest_sanitize_boolean( $r->get_param( 'consent' ) ) ) {
			return new \WP_REST_Response( array( 'code' => 'atora_newsletter_consent', 'message' => __( 'Marca la casilla para aceptar recibir el boletín.', 'atora-lms' ) ), 400 );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'atora_nl_signup_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::SIGNUP_PER_HOUR ) {
			return new \WP_REST_Response( array( 'code' => 'atora_newsletter_rate', 'message' => __( 'Demasiados intentos. Prueba en un rato.', 'atora-lms' ) ), 429 );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$email = (string) $r->get_param( 'email' );
		$user  = get_user_by( 'email', self::normalize_email( $email ) );
		// Con un usuario existente se exige siempre la confirmación por correo: nadie suscribe a otro.
		$result = self::subscribe( $email, (string) $r->get_param( 'name' ), $user ? (int) $user->ID : 0, 'form', $user ? true : null );
		if ( 'invalid' === $result ) {
			return new \WP_REST_Response( array( 'code' => 'atora_newsletter_email', 'message' => __( 'Ese correo no es válido.', 'atora-lms' ) ), 400 );
		}
		// La respuesta no revela si el correo ya estaba suscrito.
		return new \WP_REST_Response( array( 'status' => 'unchanged' === $result ? ( self::double_optin() ? 'pending' : 'subscribed' ) : $result ), 200 );
	}

	public static function render_signup_shortcode(): string {
		$id = 'atora-nl-' . wp_rand( 1000, 9999 );
		ob_start();
		?>
		<form class="atora-boletin" id="<?php echo esc_attr( $id ); ?>" novalidate>
			<p><label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Correo', 'atora-lms' ); ?></label><br>
				<input id="<?php echo esc_attr( $id ); ?>-email" type="email" name="email" required autocomplete="email" style="min-height:44px;width:100%;max-width:360px;"></p>
			<p><label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Nombre (opcional)', 'atora-lms' ); ?></label><br>
				<input id="<?php echo esc_attr( $id ); ?>-name" type="text" name="name" autocomplete="name" style="min-height:44px;width:100%;max-width:360px;"></p>
			<p style="position:absolute;left:-9999px;" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></p>
			<p><label><input type="checkbox" name="consent" value="1" required> <?php esc_html_e( 'Acepto recibir el boletín. Puedo darme de baja cuando quiera.', 'atora-lms' ); ?></label></p>
			<p><button type="submit" style="min-height:44px;"><?php esc_html_e( 'Suscribirme', 'atora-lms' ); ?></button></p>
			<p class="atora-boletin-msg" role="status" aria-live="polite"></p>
		</form>
		<script>
		( function () {
			var f = document.getElementById( <?php echo wp_json_encode( $id ); ?> );
			if ( ! f ) { return; }
			f.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msg = f.querySelector( '.atora-boletin-msg' );
				fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'atora/v1/newsletter/subscribe' ) ) ); ?>, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { email: f.email.value, name: f.name.value, website: f.website.value, consent: f.consent.checked } )
				} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					msg.textContent = d.message || ( 'pending' === d.status
						? <?php echo wp_json_encode( __( 'Te enviamos un correo: confirma tu suscripción con el enlace.', 'atora-lms' ) ); ?>
						: <?php echo wp_json_encode( __( 'Listo, quedaste suscrito.', 'atora-lms' ) ); ?> );
				} ).catch( function () {
					msg.textContent = <?php echo wp_json_encode( __( 'No se pudo enviar. Revisa tu conexión e inténtalo de nuevo.', 'atora-lms' ) ); ?>;
				} );
			} );
		}() );
		</script>
		<?php
		return (string) ob_get_clean();
	}
}
