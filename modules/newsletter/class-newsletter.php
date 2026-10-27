<?php
/**
 * ATORA LMS v5 — Newsletter
 *
 * Sistema de newsletters académicas y comerciales con:
 * - Programación recurrente (diaria/semanal/quincenal/mensual)
 * - A/B testing de subject lines
 * - Integración Blog → Email (RSS)
 * - Integración Podcast → Email (RSS)
 * - Archivo público de newsletters
 * - Segmentación de audiencia
 *
 * @package ATORA_LMS\Newsletter
 * @since   5.0.0
 */

namespace ATORA\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Newsletter
 *
 * @since 5.0.0
 */
class Newsletter {

	/**
	 * Inicializa el módulo.
	 *
	 * @return void
	 */
	public static function init(): void {
		// CPT para newsletters.
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );

		// Cron: envío programado (cada hora).
		add_action( 'atora_newsletter_cron', array( __CLASS__, 'process_scheduled' ) );
		if ( ! wp_next_scheduled( 'atora_newsletter_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'atora_newsletter_cron' );
		}

		// 6.38.0: envío por lotes.
		add_action( 'atora_nl_send_batch', array( __CLASS__, 'process_send_batch' ) );

		// Cron: importar blog/podcast (cada hora).
		add_action( 'atora_newsletter_feed_cron', array( __CLASS__, 'process_feeds' ) );
		if ( ! wp_next_scheduled( 'atora_newsletter_feed_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'atora_newsletter_feed_cron' );
		}

		// Hook: nuevos posts del blog.
		add_action( 'publish_post', array( __CLASS__, 'on_post_published' ) );

		// REST API.
		// P2 (6.12.0): gateado por módulo 'newsletter'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'newsletter' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		}

		// Shortcode archivo público.
		add_shortcode( 'atora_newsletter_archive', array( __CLASS__, 'render_archive_shortcode' ) );

		// Admin.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'wp_ajax_atora_nl_save',   array( __CLASS__, 'ajax_save' ) );
			add_action( 'wp_ajax_atora_nl_send',   array( __CLASS__, 'ajax_send_now' ) );
			add_action( 'wp_ajax_atora_nl_preview',array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_atora_nl_test',   array( __CLASS__, 'ajax_send_test' ) );
		}
	}

	// ── CPT ───────────────────────────────────────────────────────────────────

	/**
	 * Registra el Custom Post Type para newsletters.
	 *
	 * @return void
	 */
	public static function register_cpt(): void {
		register_post_type( 'atora_newsletter', array(
			'labels'       => array(
				'name'          => __( 'Newsletters', 'atora-lms' ),
				'singular_name' => __( 'Newsletter', 'atora-lms' ),
			),
			'public'       => true,
			'show_in_menu' => false,
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'has_archive'  => false,
			'rewrite'      => array( 'slug' => 'newsletter' ),
			'show_in_rest' => true,
		) );
	}

	// ── CRUD ──────────────────────────────────────────────────────────────────

	/**
	 * Crea o actualiza una newsletter.
	 *
	 * @param array $data Datos de la newsletter.
	 * @return int|\WP_Error ID del post o error.
	 */
	public static function save( array $data ) {
		$post_id = absint( $data['id'] ?? 0 );

		$post_data = array(
			'post_type'    => 'atora_newsletter',
			'post_title'   => sanitize_text_field( $data['title'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $data['excerpt'] ?? '' ),
			'post_status'  => 'publish',
		);

		if ( $post_id ) {
			$post_data['ID'] = $post_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		// Meta.
		$meta = array(
			'atora_nl_type'            => sanitize_key( $data['type'] ?? 'academic' ),
			'atora_nl_status'          => sanitize_key( $data['status'] ?? 'draft' ),
			'atora_nl_sections'        => wp_json_encode( $data['sections'] ?? array() ),
			'atora_nl_schedule_type'   => sanitize_key( $data['schedule_type'] ?? 'once' ),
			'atora_nl_schedule_config' => wp_json_encode( $data['schedule_config'] ?? array() ),
			'atora_nl_segment'         => wp_json_encode( $data['segment'] ?? array() ),
			'atora_nl_ab_enabled'      => ! empty( $data['ab_enabled'] ) ? 1 : 0,
			'atora_nl_ab_variants'     => wp_json_encode( $data['ab_variants'] ?? array() ),
		);

		// 6.38.0: editar un boletín en envío o ya enviado no reinicia su estado.
		$current = (string) get_post_meta( $post_id, 'atora_nl_status', true );
		if ( in_array( $current, array( 'sending', 'paused', 'sent' ), true ) ) {
			unset( $meta['atora_nl_status'] );
		}

		foreach ( $meta as $key => $value ) {
			// 6.38.0: update_post_meta quita barras; sin wp_slash el JSON perdía los acentos ("boletu00edn").
			update_post_meta( $post_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
		}

		return $post_id;
	}

	/**
	 * Obtiene newsletters con filtros.
	 *
	 * @param array $args Argumentos WP_Query.
	 * @return \WP_Post[]
	 */
	public static function get_list( array $args = array() ): array {
		$defaults = array(
			'post_type'      => 'atora_newsletter',
			'posts_per_page' => 20,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		return get_posts( array_merge( $defaults, $args ) );
	}

	// ── Envío ─────────────────────────────────────────────────────────────────

	/** Destinatarios por lote (cada lote es una petición corta; el resto sigue por cron). */
	const BATCH_SIZE = 200;

	/** Ventana de deduplicación: un boletín nunca llega dos veces a la misma persona. */
	const DEDUPE_MINUTES = 43200;

	/**
	 * Inicia el envío de un boletín (6.38.0): por lotes a través de la cola de correo.
	 * Antes se encolaba a todos en una sola petición (y con una plantilla inexistente, a nadie).
	 * Se puede pausar, reanudar y cancelar; reanudar o reintentar no duplica envíos.
	 *
	 * @param int $post_id ID de la newsletter.
	 * @return array{queued:int,skipped:int,failed:int,status:string}
	 */
	public static function send( int $post_id ): array {
		$status = (string) get_post_meta( $post_id, 'atora_nl_status', true );
		if ( 'sent' === $status ) {
			return self::send_state( $post_id ); // Ya enviado: no se repite.
		}
		if ( 'paused' === $status ) {
			return self::resume( $post_id );
		}
		if ( 'sending' !== $status ) {
			update_post_meta( $post_id, 'atora_nl_cursor', 0 );
			update_post_meta( $post_id, 'atora_nl_counts', wp_json_encode( array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'ab_a' => 0, 'ab_b' => 0 ) ) );
			update_post_meta( $post_id, 'atora_nl_status', 'sending' );
			update_post_meta( $post_id, 'atora_nl_started_at', current_time( 'mysql', true ) );
		}
		return self::process_send_batch( $post_id );
	}

	/**
	 * Procesa un lote. Lo llama `send()` y luego el cron (`atora_nl_send_batch`) hasta terminar.
	 *
	 * @return array{queued:int,skipped:int,failed:int,status:string}
	 */
	public static function process_send_batch( int $post_id ): array {
		global $wpdb;
		$lock = 'atora_nl_send_' . $post_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return self::send_state( $post_id );
		}
		try {
			if ( 'sending' !== (string) get_post_meta( $post_id, 'atora_nl_status', true ) ) {
				return self::send_state( $post_id );
			}
			$cursor  = absint( get_post_meta( $post_id, 'atora_nl_cursor', true ) );
			$counts  = self::counts( $post_id );
			$segment = json_decode( (string) get_post_meta( $post_id, 'atora_nl_segment', true ), true );
			$ids     = self::subscriber_ids_page( is_array( $segment ) ? $segment : array(), $cursor, self::BATCH_SIZE );

			$ab_enabled = (bool) get_post_meta( $post_id, 'atora_nl_ab_enabled', true );
			$variants   = json_decode( (string) get_post_meta( $post_id, 'atora_nl_ab_variants', true ), true );
			$variants   = is_array( $variants ) ? $variants : array();
			$title      = get_the_title( $post_id );
			$already    = self::already_queued( $post_id, $ids );

			foreach ( $ids as $user_id ) {
				if ( isset( $already[ $user_id ] ) ) {
					$counts['skipped']++; // Reintento o reinicio tras cancelar: no se duplica.
					$cursor = $user_id;
					continue;
				}
				$variant = $ab_enabled ? ( 0 === $user_id % 2 ? 'a' : 'b' ) : '';
				$subject = '' !== $variant ? (string) ( $variants[ 'variant_' . $variant ]['subject'] ?? '' ) : '';
				$queued  = \ATORA\EmailEngine\Email_Queue::enqueue( array(
					'template'              => 'newsletter',
					'user_id'               => $user_id,
					'priority'              => 'low',
					'dedupe_window_minutes' => self::DEDUPE_MINUTES,
					'metadata'              => array_filter( array(
						'newsletter_id'  => $post_id,
						'custom_subject' => '' !== $subject ? $subject : $title,
						'ab_variant'     => $variant,
					), static fn( $v ) => '' !== $v ),
				) );
				if ( $queued ) {
					$counts['queued']++;
					if ( '' !== $variant ) {
						$counts[ 'ab_' . $variant ]++;
					}
				} else {
					$counts['skipped']++; // Suprimido, sin dirección o ya enviado.
				}
				$cursor = $user_id;
			}

			update_post_meta( $post_id, 'atora_nl_cursor', $cursor );
			update_post_meta( $post_id, 'atora_nl_counts', wp_json_encode( $counts ) );

			if ( count( $ids ) < self::BATCH_SIZE ) {
				update_post_meta( $post_id, 'atora_nl_status', 'sent' );
				update_post_meta( $post_id, 'atora_nl_sent_count', $counts['queued'] );
				update_post_meta( $post_id, 'atora_nl_sent_at', current_time( 'mysql', true ) );
				if ( $ab_enabled ) {
					$variants['variant_a']['sent_to'] = $counts['ab_a'];
					$variants['variant_b']['sent_to'] = $counts['ab_b'];
					update_post_meta( $post_id, 'atora_nl_ab_variants', wp_slash( wp_json_encode( $variants ) ) );
					wp_schedule_single_event( time() + DAY_IN_SECONDS, 'atora_nl_ab_pick_winner', array( $post_id ) );
				}
				do_action( 'atora/newsletter/sent', $post_id, $counts );
			} elseif ( ! wp_next_scheduled( 'atora_nl_send_batch', array( $post_id ) ) ) {
				wp_schedule_single_event( time() + 30, 'atora_nl_send_batch', array( $post_id ) );
			}
			return self::send_state( $post_id );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function pause( int $post_id ): array {
		if ( 'sending' === (string) get_post_meta( $post_id, 'atora_nl_status', true ) ) {
			update_post_meta( $post_id, 'atora_nl_status', 'paused' );
			wp_clear_scheduled_hook( 'atora_nl_send_batch', array( $post_id ) );
		}
		return self::send_state( $post_id );
	}

	public static function resume( int $post_id ): array {
		if ( 'paused' === (string) get_post_meta( $post_id, 'atora_nl_status', true ) ) {
			update_post_meta( $post_id, 'atora_nl_status', 'sending' );
			return self::process_send_batch( $post_id );
		}
		return self::send_state( $post_id );
	}

	public static function cancel( int $post_id ): array {
		if ( in_array( (string) get_post_meta( $post_id, 'atora_nl_status', true ), array( 'sending', 'paused' ), true ) ) {
			update_post_meta( $post_id, 'atora_nl_status', 'cancelled' );
			wp_clear_scheduled_hook( 'atora_nl_send_batch', array( $post_id ) );
		}
		return self::send_state( $post_id );
	}

	/** @return array{queued:int,skipped:int,failed:int,status:string} */
	public static function send_state( int $post_id ): array {
		return self::counts( $post_id ) + array( 'status' => (string) get_post_meta( $post_id, 'atora_nl_status', true ) );
	}

	/** @return array{queued:int,skipped:int,failed:int} */
	private static function counts( int $post_id ): array {
		$counts = json_decode( (string) get_post_meta( $post_id, 'atora_nl_counts', true ), true );
		$counts = is_array( $counts ) ? $counts : array();
		return array(
			'queued'  => (int) ( $counts['queued'] ?? 0 ),
			'skipped' => (int) ( $counts['skipped'] ?? 0 ),
			'failed'  => (int) ( $counts['failed'] ?? 0 ),
			'ab_a'    => (int) ( $counts['ab_a'] ?? 0 ),
			'ab_b'    => (int) ( $counts['ab_b'] ?? 0 ),
		);
	}

	/**
	 * Usuarios de la lista que ya tienen este boletín en la cola (cualquier estado).
	 *
	 * @param int[] $user_ids
	 * @return array<int,true>
	 */
	private static function already_queued( int $post_id, array $user_ids ): array {
		global $wpdb;
		if ( ! $user_ids ) {
			return array();
		}
		$in   = implode( ',', array_map( 'absint', $user_ids ) );
		$rows = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT DISTINCT user_id FROM {$wpdb->prefix}atora_email_queue
			 WHERE user_id IN ({$in}) AND (metadata LIKE %s OR metadata LIKE %s)",
			'%"newsletter_id":' . $post_id . ',%',
			'%"newsletter_id":' . $post_id . '}%'
		) );
		return array_fill_keys( array_map( 'absint', (array) $rows ), true );
	}

	// ── Cron: envío programado ────────────────────────────────────────────────

	/**
	 * Procesa newsletters programadas para enviar ahora.
	 *
	 * @return void
	 */
	public static function process_scheduled(): void {
		$newsletters = self::get_list( array(
			'meta_query' => array(
				array( 'key' => 'atora_nl_status', 'value' => 'scheduled' ),
			),
		) );

		foreach ( $newsletters as $nl ) {
			$config = json_decode( get_post_meta( $nl->ID, 'atora_nl_schedule_config', true ), true );
			if ( empty( $config ) ) {
				continue;
			}

			if ( self::is_scheduled_for_now( $config ) ) {
				self::send( $nl->ID );
			}
		}
	}

	/**
	 * Determina si la newsletter está programada para enviarse ahora.
	 *
	 * @param array $config Configuración de schedule.
	 * @return bool
	 */
	private static function is_scheduled_for_now( array $config ): bool {
		$tz       = sanitize_text_field( $config['timezone'] ?? 'UTC' );
		$now      = new \DateTime( 'now', new \DateTimeZone( $tz ) );
		$freq     = sanitize_key( $config['frequency'] ?? '' );
		$day      = strtolower( sanitize_text_field( $config['day'] ?? '' ) );
		$time     = sanitize_text_field( $config['time'] ?? '09:00' );
		$cur_time = $now->format( 'H:i' );
		$cur_day  = strtolower( $now->format( 'l' ) );

		if ( $cur_time !== $time ) {
			return false;
		}

		if ( 'daily' === $freq ) {
			return true;
		}

		if ( 'weekly' === $freq && $cur_day === $day ) {
			return true;
		}

		if ( 'biweekly' === $freq && $cur_day === $day ) {
			$week = (int) $now->format( 'W' );
			return $week % 2 === 0;
		}

		if ( 'monthly' === $freq ) {
			$dom = (int) $now->format( 'j' );
			return $dom === absint( $config['day_of_month'] ?? 1 );
		}

		return false;
	}

	// ── Integración Blog ──────────────────────────────────────────────────────

	/**
	 * Reacciona a la publicación de un nuevo post.
	 *
	 * @param int $post_id ID del post.
	 * @return void
	 */
	public static function on_post_published( int $post_id ): void {
		$opts = get_option( 'atora_newsletter_blog_options', array() );

		if ( empty( $opts['enabled'] ) ) {
			return;
		}

		$post       = get_post( $post_id );
		$categories = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'slugs' ) );
		$allowed    = array_map( 'sanitize_title', (array) ( $opts['categories'] ?? array() ) );

		if ( ! empty( $allowed ) && ! array_intersect( $categories, $allowed ) ) {
			return;
		}

		$frequency = sanitize_key( $opts['frequency'] ?? 'immediate' );

		if ( 'immediate' === $frequency ) {
			self::send_blog_notification( $post );
		} else {
			// Añadir a la cola de digest.
			$pending   = get_option( 'atora_nl_pending_blog_posts', array() );
			$pending[] = $post_id;
			update_option( 'atora_nl_pending_blog_posts', array_unique( array_map( 'absint', $pending ) ) );
		}
	}

	/**
	 * Envía una notificación de nuevo post a los suscriptores del blog.
	 *
	 * @param \WP_Post $post Post publicado.
	 * @return void
	 */
	private static function send_blog_notification( \WP_Post $post ): void {
		$subscribers = self::get_newsletter_subscribers();
		$excerpt     = wp_trim_words( $post->post_excerpt ?: $post->post_content, 25 );

		foreach ( $subscribers as $user_id ) {
			\ATORA\EmailEngine\Email_Queue::enqueue( array(
				'template' => 'new_course_launch',
				'user_id'  => $user_id,
				'priority' => 'low',
				'metadata' => array(
					'course_title' => $post->post_title,
					'post_url'     => get_permalink( $post->ID ),
					'excerpt'      => $excerpt,
				),
			) );
		}
	}

	// ── Integración Podcast (RSS) ─────────────────────────────────────────────

	/**
	 * Procesa los feeds RSS configurados (blog y podcast).
	 *
	 * @return void
	 */
	public static function process_feeds(): void {
		$opts = get_option( 'atora_newsletter_podcast_options', array() );

		if ( empty( $opts['enabled'] ) || empty( $opts['rss_url'] ) ) {
			return;
		}

		$rss_url  = esc_url_raw( $opts['rss_url'] );
		$cache_key = 'atora_nl_podcast_' . md5( $rss_url );

		// Cargar feed con SimplePie (built-in en WordPress).
		$feed = fetch_feed( $rss_url );

		if ( is_wp_error( $feed ) ) {
			return;
		}

		$processed = get_option( 'atora_nl_processed_podcast_guids', array() );
		$max_items = $feed->get_item_quantity( 10 );
		$items     = $feed->get_items( 0, $max_items );

		foreach ( $items as $item ) {
			$guid = $item->get_id();

			if ( in_array( $guid, $processed, true ) ) {
				continue;
			}

			// Nuevo episodio: encolar notificación.
			$enclosure = $item->get_enclosure();
			$mp3_url   = $enclosure ? esc_url_raw( $enclosure->get_link() ) : '';

			$subscribers = self::get_newsletter_subscribers();

			foreach ( $subscribers as $user_id ) {
				\ATORA\EmailEngine\Email_Queue::enqueue( array(
					'template' => 'new_course_launch',
					'user_id'  => $user_id,
					'priority' => 'low',
					'metadata' => array(
						'course_title' => $item->get_title(),
						'post_url'     => $item->get_permalink(),
						'excerpt'      => wp_trim_words( $item->get_description(), 30 ),
						'mp3_url'      => $mp3_url,
						'duration'     => $enclosure ? $enclosure->get_duration( true ) : '',
					),
				) );
			}

			$processed[] = $guid;
		}

		update_option( 'atora_nl_processed_podcast_guids', array_slice( $processed, -200 ) );
	}

	// ── REST ──────────────────────────────────────────────────────────────────

	/**
	 * Registra los endpoints REST del módulo.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/newsletters', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_list' ),
				// Privado: la lista de newsletters expone estado, conteo de envíos y metadatos internos.
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_create' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			),
		) );

		register_rest_route( 'atora/v1', '/newsletters/(?P<id>\d+)/send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_send' ),
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		) );

		// 6.38.0: control del envío por lotes.
		register_rest_route( 'atora/v1', '/newsletters/(?P<id>\d+)/(?P<op>pause|resume|cancel|status)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_control' ),
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		) );
	}

	/** @param \WP_REST_Request $r Request. */
	public static function rest_control( \WP_REST_Request $r ): \WP_REST_Response {
		$id = absint( $r->get_param( 'id' ) );
		if ( ! $id || 'atora_newsletter' !== get_post_type( $id ) ) {
			return new \WP_REST_Response( array( 'code' => 'atora_newsletter_not_found' ), 404 );
		}
		switch ( (string) $r->get_param( 'op' ) ) {
			case 'pause':
				return rest_ensure_response( self::pause( $id ) );
			case 'resume':
				return rest_ensure_response( self::resume( $id ) );
			case 'cancel':
				return rest_ensure_response( self::cancel( $id ) );
		}
		return rest_ensure_response( self::send_state( $id ) );
	}

	/** @param \WP_REST_Request $r Request. */
	public static function rest_list( \WP_REST_Request $r ): \WP_REST_Response {
		$items = array_map( static function ( \WP_Post $p ) {
			return array(
				'id'      => $p->ID,
				'title'   => $p->post_title,
				'excerpt' => $p->post_excerpt,
				'date'    => $p->post_date,
				'type'    => get_post_meta( $p->ID, 'atora_nl_type', true ),
				'status'  => get_post_meta( $p->ID, 'atora_nl_status', true ),
				'sent'    => (int) get_post_meta( $p->ID, 'atora_nl_sent_count', true ),
				'url'     => get_permalink( $p->ID ),
			);
		}, self::get_list() );

		return rest_ensure_response( $items );
	}

	/** @param \WP_REST_Request $r Request. */
	public static function rest_create( \WP_REST_Request $r ) {
		$result = self::save( $r->get_json_params() ?? array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	/** @param \WP_REST_Request $r Request. */
	public static function rest_send( \WP_REST_Request $r ): \WP_REST_Response {
		$counts = self::send( absint( $r->get_param( 'id' ) ) );
		return rest_ensure_response( $counts );
	}

	// ── Shortcode archivo ─────────────────────────────────────────────────────

	/**
	 * Renderiza el archivo público de newsletters.
	 *
	 * @return string
	 */
	public static function render_archive_shortcode(): string {
		$newsletters = self::get_list( array(
			'posts_per_page' => 12,
			'meta_query'     => array(
				array( 'key' => 'atora_nl_status', 'value' => 'sent' ),
			),
		) );

		ob_start();
		?>
		<div class="atora-newsletter-archive" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;">
		<?php foreach ( $newsletters as $nl ) :
			$type = get_post_meta( $nl->ID, 'atora_nl_type', true );
			$sent = (int) get_post_meta( $nl->ID, 'atora_nl_sent_count', true );
			?>
			<article style="background:var(--atora-surface);border:1px solid var(--atora-border);
			                border-radius:var(--ac-radius-sm);padding:20px;">
				<?php if ( has_post_thumbnail( $nl->ID ) ) :
					echo get_the_post_thumbnail( $nl->ID, 'medium', array( 'style' => 'width:100%;border-radius:4px;margin-bottom:12px;' ) );
				endif; ?>
				<span style="font-size:11px;color:var(--atora-accent);text-transform:uppercase;font-weight:600;">
					<?php echo esc_html( 'academic' === $type ? __( 'Académica', 'atora-lms' ) : __( 'Comercial', 'atora-lms' ) ); ?>
				</span>
				<h3 style="margin:4px 0 8px;font-size:16px;">
					<a href="<?php echo esc_url( get_permalink( $nl->ID ) ); ?>"
					   style="color:var(--atora-text);text-decoration:none;">
						<?php echo esc_html( $nl->post_title ); ?>
					</a>
				</h3>
				<p style="color:var(--atora-text-muted);font-size:13px;margin:0 0 8px;">
					<?php echo esc_html( $nl->post_excerpt ); ?>
				</p>
				<small style="color:var(--atora-text-subtle);">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $nl->post_date ) ) ); ?>
					<?php if ( $sent ) : ?>
						· <?php printf( esc_html__( '%d enviados', 'atora-lms' ), $sent ); ?>
					<?php endif; ?>
				</small>
			</article>
		<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── AJAX admin ────────────────────────────────────────────────────────────

	/** @return void */
	public static function ajax_save(): void {
		check_ajax_referer( 'atora_nl_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		$id = self::save( $_POST );
		is_wp_error( $id ) ? wp_send_json_error( $id->get_error_message() ) : wp_send_json_success( array( 'id' => $id ) );
	}

	/** @return void */
	public static function ajax_send_now(): void {
		check_ajax_referer( 'atora_nl_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		$id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$counts = self::send( $id );
		wp_send_json_success( $counts );
	}

	/** @return void */
	public static function ajax_preview(): void {
		check_ajax_referer( 'atora_nl_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		$id   = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$html = self::render_newsletter_html( $id, get_current_user_id() );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/** @return void */
	public static function ajax_send_test(): void {
		check_ajax_referer( 'atora_nl_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		$id    = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? get_option( 'admin_email' ) ) );
		\ATORA\EmailEngine\Email_Queue::enqueue( array(
			'template'      => 'newsletter',
			'user_id'       => get_current_user_id(),
			'priority'      => 'high',
			'metadata'      => array( 'newsletter_id' => $id, 'test_email' => $email ),
		) );
		wp_send_json_success( array( 'message' => __( 'Email de prueba enviado.', 'atora-lms' ) ) );
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-newsletter' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'', // PT-4.4.3: reubicado bajo el hub "Comunicación" (atora-communication-hub).
			__( 'Newsletter', 'atora-lms' ),
			__( 'Newsletter', 'atora-lms' ),
			'manage_options',
			'atora-newsletter',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'newsletter/views/admin.php';
				if ( file_exists( $view ) ) {
					try {
						require $view;
					} catch ( \Throwable $e ) {
						if ( function_exists( 'error_log' ) ) {
							error_log( '[ATORA Newsletter] Error al renderizar admin: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
						echo '<div class="wrap"><h1>' . esc_html__( 'Newsletter', 'atora-lms' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'No se pudo renderizar el panel de Newsletter. Revisa el log de errores.', 'atora-lms' ) . '</p></div></div>';
					}
					return;
				}

				echo '<div class="wrap"><h1>' . esc_html__( 'Newsletter', 'atora-lms' ) . '</h1><div class="notice notice-warning"><p>' . esc_html__( 'Vista de Newsletter no disponible. Falta el archivo modules/newsletter/views/admin.php.', 'atora-lms' ) . '</p></div></div>';
			}
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function get_recipients( int $post_id ): array {
		$segment = json_decode( (string) get_post_meta( $post_id, 'atora_nl_segment', true ), true );
		$segment = is_array( $segment ) ? $segment : array();
		$all     = array();
		$cursor  = 0;
		do {
			$page   = self::subscriber_ids_page( $segment, $cursor, 1000 );
			$all    = array_merge( $all, $page );
			$cursor = $page ? (int) end( $page ) : $cursor;
		} while ( count( $page ) === 1000 );
		return $all;
	}

	/**
	 * Obtiene todos los suscriptores de la newsletter (con consent_marketing o consent_academic).
	 *
	 * @return int[]
	 */
	public static function get_newsletter_subscribers(): array {
		$all    = array();
		$cursor = 0;
		do {
			$page   = self::subscriber_ids_page( array(), $cursor, 1000 );
			$all    = array_merge( $all, $page );
			$cursor = $page ? (int) end( $page ) : $cursor;
		} while ( count( $page ) === 1000 );
		return $all;
	}

	/**
	 * 6.38.0: una página de suscriptores resuelta en la base de datos (segmento incluido), ordenada por ID.
	 * Antes se cargaban todos en memoria y el segmento se filtraba con una consulta por usuario.
	 *
	 * @param array $segment `{ rules: [ { field, operator: equals|not_equals|contains, value } ] }` sobre la
	 *                       meta `atora_{field}`.
	 * @return int[]
	 */
	public static function subscriber_ids_page( array $segment, int $after_id, int $limit ): array {
		global $wpdb;
		$joins  = array();
		$wheres      = array();
		$wheres_args = array();
		$args        = array();
		$n      = 0;
		foreach ( (array) ( $segment['rules'] ?? array() ) as $rule ) {
			$field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
			if ( '' === $field || strlen( $field ) > 50 ) {
				continue;
			}
			$operator = sanitize_key( (string) ( $rule['operator'] ?? 'equals' ) );
			$value    = sanitize_text_field( (string) ( $rule['value'] ?? '' ) );
			$alias    = 'r' . ( ++$n );
			$joins[]  = "LEFT JOIN {$wpdb->usermeta} {$alias} ON {$alias}.user_id = u.ID AND {$alias}.meta_key = %s";
			$args[]   = 'atora_' . $field;
			if ( 'not_equals' === $operator ) {
				$wheres[] = "({$alias}.meta_value IS NULL OR {$alias}.meta_value <> %s)";
				$wheres_args[] = $value;
			} elseif ( 'contains' === $operator ) {
				$wheres[] = "{$alias}.meta_value LIKE %s";
				$wheres_args[] = '%' . $wpdb->esc_like( $value ) . '%';
			} else {
				$wheres[] = "{$alias}.meta_value = %s";
				$wheres_args[] = $value;
			}
		}
		$sql = "SELECT DISTINCT u.ID FROM {$wpdb->users} u
			 LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'atora_consent_marketing'
			 LEFT JOIN {$wpdb->prefix}atora_email_preferences p ON p.user_id = u.ID
			 LEFT JOIN {$wpdb->prefix}atora_email_suppression s ON s.email = u.user_email
			 " . implode( "\n", $joins ) . "
			 WHERE (m.meta_value = '1' OR m.meta_value IS NULL)
			   AND (p.unsubscribed_all IS NULL OR p.unsubscribed_all = 0)
			   AND s.id IS NULL
			   AND u.ID > %d" . ( $wheres ? ' AND ' . implode( ' AND ', $wheres ) : '' ) . '
			 ORDER BY u.ID ASC
			 LIMIT %d';
		$params = array_merge( $args, array( $after_id ), $wheres_args, array( max( 1, $limit ) ) );
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Genera el HTML de una newsletter para un usuario.
	 *
	 * @param int $post_id ID de la newsletter.
	 * @param int $user_id ID del usuario.
	 * @return string
	 */
	public static function render_newsletter_html( int $post_id, int $user_id ): string {
		$user    = get_userdata( $user_id );
		$context = \ATORA\EmailEngine\Email_Templates::build_context( $user, array() );
		$footer  = \ATORA\EmailEngine\Email_Templates::get_compliance_footer( $context );
		return \ATORA\EmailEngine\Email_Templates::get_html_wrapper( self::render_body( $post_id ) . $footer, $context );
	}

	/** 6.38.0: cuerpo del boletín (lo usa también la plantilla de correo `newsletter`). */
	public static function render_body( int $post_id ): string {
		$nl = get_post( $post_id );
		if ( ! $nl ) {
			return '';
		}

		$sections_raw = get_post_meta( $post_id, 'atora_nl_sections', true );
		$sections     = json_decode( $sections_raw, true );

		$body = '<h1 style="font-size:24px;color:#0f172a;">' . esc_html( $nl->post_title ) . '</h1>';

		foreach ( (array) $sections as $section ) {
			$type = sanitize_key( $section['type'] ?? '' );
			switch ( $type ) {
				case 'hero':
					$body .= '<div style="background:#6366f1;padding:32px;border-radius:8px;text-align:center;margin-bottom:16px;">';
					$body .= '<h2 style="color:#fff;margin:0;">' . esc_html( $section['title'] ?? '' ) . '</h2>';
					$body .= '<p style="color:#e0e7ff;">' . esc_html( $section['subtitle'] ?? '' ) . '</p>';
					if ( ! empty( $section['cta_url'] ) ) {
						$body .= '<a href="' . esc_url( $section['cta_url'] ) . '" style="background:#fff;color:#6366f1;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;">' . esc_html( $section['cta_text'] ?? 'Ver más' ) . '</a>';
					}
					$body .= '</div>';
					break;

				case 'article':
					$body .= '<div style="margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid #e2e8f0;">';
					$body .= '<h3 style="margin:0 0 8px;">' . esc_html( $section['title'] ?? '' ) . '</h3>';
					$body .= '<p style="color:#475569;">' . esc_html( $section['content'] ?? '' ) . '</p>';
					if ( ! empty( $section['url'] ) ) {
						$body .= '<a href="' . esc_url( $section['url'] ) . '">Leer más →</a>';
					}
					$body .= '</div>';
					break;

				case 'cta':
					$body .= '<div style="text-align:center;padding:24px 0;">';
					$body .= '<a href="' . esc_url( $section['url'] ?? '#' ) . '" style="background:#6366f1;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;">';
					$body .= esc_html( $section['text'] ?? 'Más info' ) . '</a></div>';
					break;
			}
		}

		return $body;
	}
}
