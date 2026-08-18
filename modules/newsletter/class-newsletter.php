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

		// Cron: importar blog/podcast (cada hora).
		add_action( 'atora_newsletter_feed_cron', array( __CLASS__, 'process_feeds' ) );
		if ( ! wp_next_scheduled( 'atora_newsletter_feed_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'atora_newsletter_feed_cron' );
		}

		// Hook: nuevos posts del blog.
		add_action( 'publish_post', array( __CLASS__, 'on_post_published' ) );

		// REST API.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

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

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
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

	/**
	 * Envía una newsletter a su audiencia.
	 *
	 * @param int $post_id ID de la newsletter.
	 * @return array { sent: int, failed: int }
	 */
	public static function send( int $post_id ): array {
		$ab_enabled = (bool) get_post_meta( $post_id, 'atora_nl_ab_enabled', true );

		if ( $ab_enabled ) {
			return self::send_ab_test( $post_id );
		}

		$recipients = self::get_recipients( $post_id );
		$counts     = array( 'sent' => 0, 'failed' => 0, 'skipped' => 0 );

		foreach ( $recipients as $user_id ) {
			// Fase 10: verificar suppression list antes de enviar
			$user_obj = get_userdata( $user_id );
			if ( $user_obj && class_exists( '\ATORA\CRM_V2\Services\Sequence_Service' ) ) {
				if ( \ATORA\CRM_V2\Services\Sequence_Service::is_suppressed( $user_obj->user_email ) ) {
					$counts['skipped'] = ( $counts['skipped'] ?? 0 ) + 1;
					continue;
				}
			}
			$queued = \ATORA\EmailEngine\Email_Queue::enqueue( array(
				'template' => 'newsletter',
				'user_id'  => $user_id,
				'priority' => 'medium',
				'metadata' => array( 'newsletter_id' => $post_id ),
			) );

			$queued ? $counts['sent']++ : $counts['failed']++;
		}

		update_post_meta( $post_id, 'atora_nl_sent_count', $counts['sent'] );
		update_post_meta( $post_id, 'atora_nl_sent_at', current_time( 'mysql', true ) );
		update_post_meta( $post_id, 'atora_nl_status', 'sent' );

		do_action( 'atora/newsletter/sent', $post_id, $counts );

		return $counts;
	}

	/**
	 * Envía una newsletter con A/B testing (50/50 split inicial).
	 *
	 * @param int $post_id ID de la newsletter.
	 * @return array
	 */
	private static function send_ab_test( int $post_id ): array {
		$recipients = self::get_recipients( $post_id );
		$half       = (int) ceil( count( $recipients ) / 2 );
		$group_a    = array_slice( $recipients, 0, $half );
		$group_b    = array_slice( $recipients, $half );

		$ab_variants = json_decode( get_post_meta( $post_id, 'atora_nl_ab_variants', true ), true );

		// Enviar variante A.
		foreach ( $group_a as $user_id ) {
			\ATORA\EmailEngine\Email_Queue::enqueue( array(
				'template' => 'newsletter',
				'user_id'  => $user_id,
				'priority' => 'medium',
				'metadata' => array(
					'newsletter_id' => $post_id,
					'ab_variant'    => 'a',
					'subject'       => $ab_variants['variant_a']['subject'] ?? '',
				),
			) );
		}

		// Enviar variante B.
		foreach ( $group_b as $user_id ) {
			\ATORA\EmailEngine\Email_Queue::enqueue( array(
				'template' => 'newsletter',
				'user_id'  => $user_id,
				'priority' => 'medium',
				'metadata' => array(
					'newsletter_id' => $post_id,
					'ab_variant'    => 'b',
					'subject'       => $ab_variants['variant_b']['subject'] ?? '',
				),
			) );
		}

		$ab_variants['variant_a']['sent_to'] = count( $group_a );
		$ab_variants['variant_b']['sent_to'] = count( $group_b );
		update_post_meta( $post_id, 'atora_nl_ab_variants', wp_json_encode( $ab_variants ) );

		// Programar elección del ganador en 24h.
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'atora_nl_ab_pick_winner', array( $post_id ) );

		return array( 'sent' => count( $recipients ), 'failed' => 0 );
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

	/**
	 * Obtiene los destinatarios de una newsletter según su segmento.
	 *
	 * @param int $post_id ID de la newsletter.
	 * @return int[] IDs de usuarios.
	 */
	public static function get_recipients( int $post_id ): array {
		$segment = json_decode( get_post_meta( $post_id, 'atora_nl_segment', true ), true );

		if ( empty( $segment ) ) {
			return self::get_newsletter_subscribers();
		}

		// Segmento específico: aplicar filtros.
		return self::apply_segment_filters( $segment );
	}

	/**
	 * Obtiene todos los suscriptores de la newsletter (con consent_marketing o consent_academic).
	 *
	 * @return int[]
	 */
	public static function get_newsletter_subscribers(): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT DISTINCT u.ID FROM {$wpdb->users} u
			 LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'atora_consent_marketing'
			 LEFT JOIN {$wpdb->prefix}atora_email_preferences p ON p.user_id = u.ID
			 LEFT JOIN {$wpdb->prefix}atora_email_suppression s ON s.email = u.user_email
			 WHERE (m.meta_value = '1' OR m.meta_value IS NULL)
			   AND (p.unsubscribed_all IS NULL OR p.unsubscribed_all = 0)
			   AND s.id IS NULL" /* excluir suprimidos (Fase 10) */
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Aplica filtros de segmento a la lista de usuarios.
	 *
	 * @param array $segment Configuración del segmento.
	 * @return int[]
	 */
	private static function apply_segment_filters( array $segment ): array {
		$base = self::get_newsletter_subscribers();

		foreach ( $segment['rules'] ?? array() as $rule ) {
			$field    = sanitize_key( $rule['field'] ?? '' );
			$operator = sanitize_key( $rule['operator'] ?? 'equals' );
			$value    = sanitize_text_field( $rule['value'] ?? '' );

			$base = array_filter( $base, static function ( $user_id ) use ( $field, $operator, $value ) {
				$user_val = get_user_meta( $user_id, 'atora_' . $field, true );
				switch ( $operator ) {
					case 'equals':     return $user_val === $value;
					case 'not_equals': return $user_val !== $value;
					case 'contains':   return str_contains( (string) $user_val, $value );
					default:           return true;
				}
			} );
		}

		return array_values( $base );
	}

	/**
	 * Genera el HTML de una newsletter para un usuario.
	 *
	 * @param int $post_id ID de la newsletter.
	 * @param int $user_id ID del usuario.
	 * @return string
	 */
	public static function render_newsletter_html( int $post_id, int $user_id ): string {
		$nl      = get_post( $post_id );
		$user    = get_userdata( $user_id );
		$context = \ATORA\EmailEngine\Email_Templates::build_context( $user, array() );

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

		$footer = \ATORA\EmailEngine\Email_Templates::get_compliance_footer( $context );

		return \ATORA\EmailEngine\Email_Templates::get_html_wrapper( $body . $footer, $context );
	}
}
