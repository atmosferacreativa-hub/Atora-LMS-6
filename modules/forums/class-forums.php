<?php
/**
 * Forums module bootstrap.
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

use ATORA\Forums\Forums_Installer;
use ATORA\Forums\Forums_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-forums-installer.php';
require_once __DIR__ . '/class-forums-service.php';
require_once __DIR__ . '/class-forums-notifications.php';
require_once __DIR__ . '/class-forums-rest-controller.php';

final class ATORA_Forums {
	private const ADMIN_NONCE_ACTION = 'atora_forums_admin_reports';
	private const FRONT_NONCE_ACTION = 'atora_forums_front';
	private const AJAX_NONCE_ACTION  = 'atora_forums_ajax';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'ensure_tables' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_shortcode( 'atora_forum', array( __CLASS__, 'shortcode_forum' ) );
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'wp_ajax_atora_forum_similar', array( __CLASS__, 'ajax_similar' ) );
	}

	public static function ensure_tables(): void {
		Forums_Installer::ensure_tables();
	}

	public static function rest(): void {
		Forums_REST_Controller::register_routes();
	}

	public static function shortcode_forum( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'course' => '' ), $atts, 'atora_forum' );
		$course = absint( $atts['course'] );
		if ( $course <= 0 ) {
			$course = self::detect_current_course_id();
		}
		if ( $course <= 0 ) {
			return '<p>' . esc_html__( 'Foro no configurado.', 'atora-lms' ) . '</p>';
		}

		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Inicia sesión para ver el foro.', 'atora-lms' ) . '</p>';
		}

		$forum = \ATORA\Forums\Forums_Service::forum_for_scope( 'course', $course );
		if ( is_wp_error( $forum ) || ! is_array( $forum ) ) {
			return '<p>' . esc_html__( 'Foro no configurado.', 'atora-lms' ) . '</p>';
		}
		$user_id = get_current_user_id();
		if ( ! \ATORA\Forums\Forums_Service::can_participate( $user_id, $forum ) ) {
			return '<p>' . esc_html__( 'No tienes acceso a este foro.', 'atora-lms' ) . '</p>';
		}

		wp_enqueue_style( 'atora-forums-web', plugins_url( 'assets/forums-web.css', __FILE__ ), array(), (string) filemtime( __DIR__ . '/assets/forums-web.css' ) );
		wp_enqueue_script( 'atora-forums-web', plugins_url( 'assets/forums-web.js', __FILE__ ), array(), (string) filemtime( __DIR__ . '/assets/forums-web.js' ), true );
		wp_localize_script( 'atora-forums-web', 'ATORA_FORUMS_WEB', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::AJAX_NONCE_ACTION ),
			'forumId'  => (int) $forum['id'],
		) );

		$notice = '';
		$notice_role = 'status';
		$topic_id = absint( $_GET['topic'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['atora_forum_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$handled = self::handle_front_post( $forum, $topic_id );
			if ( $handled['handled'] ) {
				$notice = (string) $handled['message'];
				$notice_role = (string) $handled['role'];
				$topic_id = (int) $handled['topic_id'];
			}
		}

		return self::render_forum( $forum, $topic_id, $notice, $notice_role );
	}

	public static function admin_menu(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		add_submenu_page(
			'atora-lms',
			__( 'Foros', 'atora-lms' ),
			__( 'Foros', 'atora-lms' ),
			'read',
			'atora-forums',
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function render_admin(): void {
		if ( ! is_user_logged_in() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Foros: reportes', 'atora-lms' ) . '</h1><p>' . esc_html__( 'No tienes acceso.', 'atora-lms' ) . '</p></div>';
			return;
		}
		$user_id = get_current_user_id();
		if ( ! self::user_can_access_reports_admin( $user_id ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Foros: reportes', 'atora-lms' ) . '</h1><p>' . esc_html__( 'No tienes acceso.', 'atora-lms' ) . '</p></div>';
			return;
		}

		$notice = '';
		$notice_role = 'status';
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['atora_forums_admin_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$res = self::handle_admin_post( $user_id );
			if ( $res['handled'] ) {
				$notice = (string) $res['message'];
				$notice_role = (string) $res['role'];
			}
		}

		global $wpdb;
		$rows = self::get_open_reports_for_user( $user_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Foros: reportes', 'atora-lms' ) . '</h1>';
		if ( $notice ) {
			echo '<div class="notice notice-info"><p role="' . esc_attr( $notice_role ) . '">' . esc_html( $notice ) . '</p></div>';
		}
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No hay reportes pendientes.', 'atora-lms' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Tipo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Objetivo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Motivo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Quién', 'atora-lms' ) . '</th><th>' . esc_html__( 'Fecha', 'atora-lms' ) . '</th><th>' . esc_html__( 'Acciones', 'atora-lms' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$link = self::topic_link_for_report( (array) $row );
			$u = get_user_by( 'id', (int) $row['reporter_id'] );
			echo '<tr>';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td>' . esc_html( (string) $row['target_type'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['target_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['reason'] ) . '</td>';
			echo '<td>' . esc_html( $u ? ( $u->display_name ?: $u->user_login ) : '#' . (int) $row['reporter_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td>';
			if ( $link ) {
				echo '<a href="' . esc_url( $link ) . '">' . esc_html__( 'Ver tema', 'atora-lms' ) . '</a><br />';
			}
			echo '<form method="post" style="margin-top:6px">';
			echo '<input type="hidden" name="atora_forums_admin_action" value="report_action" />';
			echo '<input type="hidden" name="report_id" value="' . (int) $row['id'] . '" />';
			wp_nonce_field( self::ADMIN_NONCE_ACTION, '_wpnonce', false );
			echo '<button class="button" name="action" value="dismiss">' . esc_html__( 'Resolver (sin acción)', 'atora-lms' ) . '</button> ';
			echo '<button class="button button-primary" name="action" value="hide_resolve">' . esc_html__( 'Ocultar el mensaje y resolver', 'atora-lms' ) . '</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function detect_current_course_id(): int {
		if ( is_singular( 'lm_course' ) ) {
			return absint( get_queried_object_id() ?: get_the_ID() );
		}
		if ( is_singular( 'lm_lesson' ) ) {
			$lesson_id = absint( get_queried_object_id() ?: get_the_ID() );
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_lesson_course_id' ) ) {
				$cid = absint( \CLMS_Helper::get_lesson_course_id( $lesson_id ) );
				if ( $cid > 0 ) {
					return $cid;
				}
			}
			$cid = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );
			if ( $cid > 0 ) {
				return $cid;
			}
			$cid = absint( get_post_meta( $lesson_id, 'course_id', true ) );
			return $cid;
		}
		return 0;
	}

	private static function handle_front_post( array $forum, int $current_topic_id ): array {
		$out = array(
			'handled'  => false,
			'message'  => '',
			'role'     => 'status',
			'topic_id' => $current_topic_id,
		);
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $_POST['_wpnonce'] ), self::FRONT_NONCE_ACTION ) ) {
			$out['handled'] = true;
			$out['message'] = __( 'Nonce inválido.', 'atora-lms' );
			$out['role'] = 'alert';
			return $out;
		}
		$user_id = get_current_user_id();
		if ( ! \ATORA\Forums\Forums_Service::can_participate( $user_id, $forum ) ) {
			$out['handled'] = true;
			$out['message'] = __( 'No tienes acceso a este foro.', 'atora-lms' );
			$out['role'] = 'alert';
			return $out;
		}
		$action = sanitize_key( (string) ( $_POST['atora_forum_action'] ?? '' ) );
		global $wpdb;

		if ( 'create_topic' === $action ) {
			if ( \ATORA\Forums\Forums_Service::is_read_only( $forum ) ) {
				$out['handled'] = true;
				$out['message'] = __( 'El foro está en solo lectura.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			$title = sanitize_text_field( (string) ( $_POST['title'] ?? '' ) );
			$body = sanitize_textarea_field( (string) ( $_POST['body'] ?? '' ) );
			if ( '' === $title || '' === $body ) {
				$out['handled'] = true;
				$out['message'] = __( 'Faltan campos.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			$event = sanitize_text_field( (string) ( $_POST['client_event_id'] ?? '' ) );
			if ( '' === $event ) {
				$event = 'web-' . wp_generate_uuid4();
			}
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE forum_id = %d AND author_id = %d AND client_event_id = %s LIMIT 1", (int) $forum['id'], $user_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( is_array( $existing ) ) {
				$out['handled'] = true;
				$out['message'] = __( 'Tema ya enviado.', 'atora-lms' );
				$out['topic_id'] = (int) $existing['id'];
				return $out;
			}
			$ok = $wpdb->insert( $wpdb->prefix . 'atora_forum_topics', array(
				'forum_id'        => (int) $forum['id'],
				'institution_id'  => (int) $forum['institution_id'],
				'author_id'       => $user_id,
				'title'           => $title,
				'body'            => $body,
				'client_event_id' => $event,
				'last_activity_at' => current_time( 'mysql', true ),
			) ); // phpcs:ignore WordPress.DB
			$out['handled'] = true;
			if ( ! $ok ) {
				$out['message'] = __( 'No se pudo crear el tema.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			$out['topic_id'] = (int) $wpdb->insert_id;
			$out['message'] = __( 'Tema creado.', 'atora-lms' );
			return $out;
		}

		if ( 'create_reply' === $action ) {
			$topic_id = absint( $_POST['topic_id'] ?? 0 );
			$topic = $topic_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
			if ( ! is_array( $topic ) || (int) $topic['forum_id'] !== (int) $forum['id'] ) {
				$out['handled'] = true;
				$out['message'] = __( 'Tema no encontrado.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			if ( \ATORA\Forums\Forums_Service::is_read_only( $forum ) || ! empty( $topic['closed'] ) ) {
				$out['handled'] = true;
				$out['message'] = __( 'El tema está cerrado.', 'atora-lms' );
				$out['role'] = 'alert';
				$out['topic_id'] = $topic_id;
				return $out;
			}
			$body = sanitize_textarea_field( (string) ( $_POST['body'] ?? '' ) );
			if ( '' === $body ) {
				$out['handled'] = true;
				$out['message'] = __( 'Faltan campos.', 'atora-lms' );
				$out['role'] = 'alert';
				$out['topic_id'] = $topic_id;
				return $out;
			}
			$event = sanitize_text_field( (string) ( $_POST['client_event_id'] ?? '' ) );
			if ( '' === $event ) {
				$event = 'web-' . wp_generate_uuid4();
			}
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE topic_id = %d AND author_id = %d AND client_event_id = %s LIMIT 1", $topic_id, $user_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$out['handled'] = true;
			$out['topic_id'] = $topic_id;
			if ( is_array( $existing ) ) {
				$out['message'] = __( 'Respuesta ya enviada.', 'atora-lms' );
				return $out;
			}
			$ok = $wpdb->insert( $wpdb->prefix . 'atora_forum_replies', array(
				'topic_id'        => $topic_id,
				'forum_id'        => (int) $forum['id'],
				'institution_id'  => (int) $forum['institution_id'],
				'author_id'       => $user_id,
				'body'            => $body,
				'client_event_id' => $event,
			) ); // phpcs:ignore WordPress.DB
			if ( ! $ok ) {
				$out['message'] = __( 'No se pudo crear la respuesta.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_forum_topics SET replies_count = replies_count + 1, last_activity_at = %s WHERE id = %d", current_time( 'mysql', true ), $topic_id ) ); // phpcs:ignore WordPress.DB
			$out['message'] = __( 'Respuesta enviada.', 'atora-lms' );
			return $out;
		}

		if ( in_array( $action, array( 'moderate_topic', 'moderate_reply' ), true ) ) {
			$out['handled'] = true;
			$is_mod = \ATORA\Forums\Forums_Service::can_moderate( $user_id, $forum );
			if ( ! $is_mod ) {
				$out['message'] = __( 'No puedes moderar este foro.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			if ( 'moderate_topic' === $action ) {
				$topic_id = absint( $_POST['topic_id'] ?? 0 );
				$topic = $topic_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
				if ( ! is_array( $topic ) || (int) $topic['forum_id'] !== (int) $forum['id'] ) {
					$out['message'] = __( 'Tema no encontrado.', 'atora-lms' );
					$out['role'] = 'alert';
					return $out;
				}
				$do = sanitize_key( (string) ( $_POST['action'] ?? '' ) );
				$fields = array();
				if ( in_array( $do, array( 'pin', 'unpin' ), true ) ) {
					$fields['pinned'] = 'pin' === $do ? 1 : 0;
				} elseif ( in_array( $do, array( 'close', 'open' ), true ) ) {
					$fields['closed'] = 'close' === $do ? 1 : 0;
				} elseif ( in_array( $do, array( 'hide', 'show' ), true ) ) {
					$fields['hidden'] = 'hide' === $do ? 1 : 0;
				} elseif ( 'accept_reply' === $do ) {
					$rid = absint( $_POST['reply_id'] ?? 0 );
					if ( $rid <= 0 ) {
						$out['message'] = __( 'Respuesta inválida.', 'atora-lms' );
						$out['role'] = 'alert';
						return $out;
					}
					$fields['accepted_reply_id'] = $rid;
				} else {
					$out['message'] = __( 'Acción inválida.', 'atora-lms' );
					$out['role'] = 'alert';
					return $out;
				}
				$wpdb->update( $wpdb->prefix . 'atora_forum_topics', $fields, array( 'id' => $topic_id ) ); // phpcs:ignore WordPress.DB
				$out['topic_id'] = $topic_id;
				$out['message'] = __( 'Acción aplicada.', 'atora-lms' );
				return $out;
			}
			$reply_id = absint( $_POST['reply_id'] ?? 0 );
			$reply = $reply_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d LIMIT 1", $reply_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
			if ( ! is_array( $reply ) || (int) $reply['forum_id'] !== (int) $forum['id'] ) {
				$out['message'] = __( 'Respuesta no encontrada.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			$do = sanitize_key( (string) ( $_POST['action'] ?? '' ) );
			if ( ! in_array( $do, array( 'hide', 'show' ), true ) ) {
				$out['message'] = __( 'Acción inválida.', 'atora-lms' );
				$out['role'] = 'alert';
				return $out;
			}
			$wpdb->update( $wpdb->prefix . 'atora_forum_replies', array( 'hidden' => 'hide' === $do ? 1 : 0 ), array( 'id' => $reply_id ) ); // phpcs:ignore WordPress.DB
			$out['topic_id'] = (int) $reply['topic_id'];
			$out['message'] = __( 'Acción aplicada.', 'atora-lms' );
			return $out;
		}

		return $out;
	}

	private static function render_forum( array $forum, int $topic_id, string $notice, string $notice_role ): string {
		$user_id = get_current_user_id();
		$is_mod = \ATORA\Forums\Forums_Service::can_moderate( $user_id, $forum );
		$filter = sanitize_key( (string) ( $_GET['filter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $filter, array( '', 'unanswered', 'pinned', 'mine' ), true ) ) {
			$filter = '';
		}

		$nonce = wp_create_nonce( self::FRONT_NONCE_ACTION );
		$base_args = array( 'filter' => $filter );
		$out = '<div class="atora-forum" role="region" aria-label="' . esc_attr__( 'Foro', 'atora-lms' ) . '">';
		if ( $notice ) {
			$out .= '<div class="atora-forum-notice" role="' . esc_attr( $notice_role ) . '">' . esc_html( $notice ) . '</div>';
		}
		$out .= '<div class="atora-forum-toolbar" role="navigation" aria-label="' . esc_attr__( 'Filtros', 'atora-lms' ) . '">';
		$out .= self::filter_link( __( 'Todos', 'atora-lms' ), remove_query_arg( array( 'filter', 'topic' ) ), '' === $filter );
		$out .= self::filter_link( __( 'Sin respuesta', 'atora-lms' ), add_query_arg( array( 'filter' => 'unanswered' ), remove_query_arg( array( 'topic' ) ) ), 'unanswered' === $filter );
		$out .= self::filter_link( __( 'Fijados', 'atora-lms' ), add_query_arg( array( 'filter' => 'pinned' ), remove_query_arg( array( 'topic' ) ) ), 'pinned' === $filter );
		$out .= self::filter_link( __( 'Míos', 'atora-lms' ), add_query_arg( array( 'filter' => 'mine' ), remove_query_arg( array( 'topic' ) ) ), 'mine' === $filter );
		$out .= '</div>';

		if ( $topic_id > 0 ) {
			$out .= self::render_topic_detail( $forum, $topic_id, $nonce, $is_mod );
			$out .= '<p><a class="atora-forum-back" href="' . esc_url( remove_query_arg( array( 'topic' ) ) ) . '">' . esc_html__( 'Volver a la lista', 'atora-lms' ) . '</a></p>';
			$out .= '</div>';
			return $out;
		}

		$out .= self::render_topics_list( $forum, $filter, $is_mod );
		if ( ! \ATORA\Forums\Forums_Service::is_read_only( $forum ) ) {
			$out .= self::render_create_topic_form( $forum, $nonce );
		}
		$out .= '</div>';
		return $out;
	}

	private static function filter_link( string $label, string $url, bool $current ): string {
		return '<a class="atora-forum-filter' . ( $current ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private static function render_topics_list( array $forum, string $filter, bool $is_mod ): string {
		$user_id = get_current_user_id();
		global $wpdb;
		$where = 'forum_id = %d';
		$args = array( (int) $forum['id'] );
		if ( ! $is_mod ) {
			$where .= ' AND hidden = 0';
		}
		if ( 'mine' === $filter ) {
			$where .= ' AND author_id = %d';
			$args[] = $user_id;
		}
		if ( 'pinned' === $filter ) {
			$where .= ' AND pinned = 1';
		}
		if ( 'unanswered' === $filter ) {
			$where .= ' AND replies_count = 0';
		}
		$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE {$where} ORDER BY pinned DESC, last_activity_at DESC, id DESC LIMIT 20", $args ); // phpcs:ignore WordPress.DB
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = '<div class="atora-forum-topics" role="list" aria-label="' . esc_attr__( 'Temas', 'atora-lms' ) . '">';
		if ( ! $rows ) {
			$out .= '<p>' . esc_html__( 'Aún no hay temas.', 'atora-lms' ) . '</p></div>';
			return $out;
		}
		foreach ( (array) $rows as $row ) {
			$title = sanitize_text_field( (string) $row['title'] );
			$url = add_query_arg( array( 'topic' => (int) $row['id'] ), remove_query_arg( array() ) );
			$meta = array();
			if ( ! empty( $row['pinned'] ) ) { $meta[] = __( 'Fijado', 'atora-lms' ); }
			if ( ! empty( $row['closed'] ) ) { $meta[] = __( 'Cerrado', 'atora-lms' ); }
			if ( $is_mod && ! empty( $row['hidden'] ) ) { $meta[] = __( 'Oculto', 'atora-lms' ); }
			$out .= '<div class="atora-forum-topic" role="listitem">';
			$out .= '<a class="atora-forum-topic-title" href="' . esc_url( $url ) . '">' . esc_html( $title ?: __( 'Sin título', 'atora-lms' ) ) . '</a>';
			$out .= '<div class="atora-forum-topic-meta">' . esc_html( implode( ' · ', array_filter( $meta ) ) ) . '</div>';
			$out .= '</div>';
		}
		$out .= '</div>';
		return $out;
	}

	private static function render_topic_detail( array $forum, int $topic_id, string $nonce, bool $is_mod ): string {
		global $wpdb;
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $topic ) || (int) $topic['forum_id'] !== (int) $forum['id'] ) {
			return '<p>' . esc_html__( 'Tema no encontrado.', 'atora-lms' ) . '</p>';
		}
		if ( ! $is_mod && ! empty( $topic['hidden'] ) ) {
			return '<p>' . esc_html__( 'Tema no encontrado.', 'atora-lms' ) . '</p>';
		}
		$user_id = get_current_user_id();
		$can_accept = \ATORA\Forums\Forums_Service::can_accept_reply( $user_id, $forum, $topic );
		$replies = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE topic_id = %d" . ( $is_mod ? '' : ' AND hidden = 0' ) . ' ORDER BY id ASC', $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$out = '<article class="atora-forum-topic-detail">';
		$out .= '<h2 class="atora-forum-h2">' . esc_html( sanitize_text_field( (string) $topic['title'] ) ) . '</h2>';
		$out .= '<div class="atora-forum-body">' . wp_kses_post( wpautop( (string) $topic['body'] ) ) . '</div>';
		if ( $is_mod ) {
			$out .= '<div class="atora-forum-mod">';
			$out .= self::moderation_form_topic( $topic_id, $nonce, empty( $topic['pinned'] ) ? 'pin' : 'unpin', empty( $topic['pinned'] ) ? __( 'Fijar', 'atora-lms' ) : __( 'Quitar fijado', 'atora-lms' ) );
			$out .= self::moderation_form_topic( $topic_id, $nonce, empty( $topic['closed'] ) ? 'close' : 'open', empty( $topic['closed'] ) ? __( 'Cerrar', 'atora-lms' ) : __( 'Abrir', 'atora-lms' ) );
			$out .= self::moderation_form_topic( $topic_id, $nonce, empty( $topic['hidden'] ) ? 'hide' : 'show', empty( $topic['hidden'] ) ? __( 'Ocultar', 'atora-lms' ) : __( 'Mostrar', 'atora-lms' ) );
			$out .= '</div>';
		}

		$out .= '<h3 class="atora-forum-h3">' . esc_html__( 'Respuestas', 'atora-lms' ) . '</h3>';
		$out .= '<div class="atora-forum-replies" role="list">';
		foreach ( (array) $replies as $reply ) {
			$rid = (int) $reply['id'];
			$is_accepted = absint( $topic['accepted_reply_id'] ?? 0 ) === $rid;
			$out .= '<div class="atora-forum-reply" role="listitem">';
			$out .= $is_accepted ? '<div class="atora-forum-badge">' . esc_html__( 'Aceptada', 'atora-lms' ) . '</div>' : '';
			if ( $is_mod && ! empty( $reply['hidden'] ) ) {
				$out .= '<div class="atora-forum-badge">' . esc_html__( 'Oculta', 'atora-lms' ) . '</div>';
			}
			$out .= '<div class="atora-forum-body">' . wp_kses_post( wpautop( (string) $reply['body'] ) ) . '</div>';
			$out .= '<div class="atora-forum-reply-actions">';
			if ( $can_accept ) {
				$out .= self::moderation_form_topic_accept( $topic_id, $rid, $nonce, __( 'Marcar aceptada', 'atora-lms' ) );
			}
			if ( $is_mod ) {
				$out .= self::moderation_form_reply( $rid, $nonce, empty( $reply['hidden'] ) ? 'hide' : 'show', empty( $reply['hidden'] ) ? __( 'Ocultar', 'atora-lms' ) : __( 'Mostrar', 'atora-lms' ) );
			}
			$out .= '</div>';
			$out .= '</div>';
		}
		$out .= '</div>';

		if ( \ATORA\Forums\Forums_Service::is_read_only( $forum ) || ! empty( $topic['closed'] ) ) {
			$out .= '<p>' . esc_html__( 'El tema está cerrado.', 'atora-lms' ) . '</p>';
			$out .= '</article>';
			return $out;
		}
		$out .= self::render_reply_form( $topic_id, $nonce );
		$out .= '</article>';
		return $out;
	}

	private static function moderation_form_topic( int $topic_id, string $nonce, string $action, string $label ): string {
		return '<form method="post" class="atora-forum-inline">'
			. '<input type="hidden" name="atora_forum_action" value="moderate_topic" />'
			. '<input type="hidden" name="topic_id" value="' . $topic_id . '" />'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />'
			. '<button type="submit" class="atora-forum-btn">' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	private static function moderation_form_topic_accept( int $topic_id, int $reply_id, string $nonce, string $label ): string {
		return '<form method="post" class="atora-forum-inline">'
			. '<input type="hidden" name="atora_forum_action" value="moderate_topic" />'
			. '<input type="hidden" name="topic_id" value="' . $topic_id . '" />'
			. '<input type="hidden" name="action" value="accept_reply" />'
			. '<input type="hidden" name="reply_id" value="' . $reply_id . '" />'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />'
			. '<button type="submit" class="atora-forum-btn">' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	private static function moderation_form_reply( int $reply_id, string $nonce, string $action, string $label ): string {
		return '<form method="post" class="atora-forum-inline">'
			. '<input type="hidden" name="atora_forum_action" value="moderate_reply" />'
			. '<input type="hidden" name="reply_id" value="' . $reply_id . '" />'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />'
			. '<button type="submit" class="atora-forum-btn">' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	private static function render_create_topic_form( array $forum, string $nonce ): string {
		$out  = '<section class="atora-forum-create">';
		$out .= '<h3 class="atora-forum-h3">' . esc_html__( 'Crear tema', 'atora-lms' ) . '</h3>';
		$out .= '<form method="post" class="atora-forum-form" data-forum-id="' . (int) $forum['id'] . '">';
		$out .= '<input type="hidden" name="atora_forum_action" value="create_topic" />';
		$out .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
		$out .= '<label class="atora-forum-label" for="atora-forum-title">' . esc_html__( 'Título', 'atora-lms' ) . '</label>';
		$out .= '<input id="atora-forum-title" name="title" class="atora-forum-input" type="text" required />';
		$out .= '<div class="atora-forum-similar" aria-live="polite"></div>';
		$out .= '<label class="atora-forum-label" for="atora-forum-body">' . esc_html__( 'Mensaje', 'atora-lms' ) . '</label>';
		$out .= '<textarea id="atora-forum-body" name="body" class="atora-forum-textarea" rows="5" required></textarea>';
		$out .= '<button type="submit" class="atora-forum-btn atora-forum-btn-primary">' . esc_html__( 'Publicar', 'atora-lms' ) . '</button>';
		$out .= '</form>';
		$out .= '</section>';
		return $out;
	}

	private static function render_reply_form( int $topic_id, string $nonce ): string {
		$out  = '<section class="atora-forum-reply-form">';
		$out .= '<h3 class="atora-forum-h3">' . esc_html__( 'Responder', 'atora-lms' ) . '</h3>';
		$out .= '<form method="post" class="atora-forum-form">';
		$out .= '<input type="hidden" name="atora_forum_action" value="create_reply" />';
		$out .= '<input type="hidden" name="topic_id" value="' . $topic_id . '" />';
		$out .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
		$out .= '<label class="atora-forum-label" for="atora-forum-reply">' . esc_html__( 'Mensaje', 'atora-lms' ) . '</label>';
		$out .= '<textarea id="atora-forum-reply" name="body" class="atora-forum-textarea" rows="4" required></textarea>';
		$out .= '<button type="submit" class="atora-forum-btn atora-forum-btn-primary">' . esc_html__( 'Enviar', 'atora-lms' ) . '</button>';
		$out .= '</form>';
		$out .= '</section>';
		return $out;
	}

	public static function ajax_similar(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Inicia sesión.', 'atora-lms' ) ), 401 );
		}
		$forum_id = absint( $_GET['forum_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q = trim( (string) ( $_GET['q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$forum = $forum_id ? \ATORA\Forums\Forums_Service::forum_by_id( $forum_id ) : null;
		if ( ! $forum ) {
			wp_send_json_success( array( 'items' => array() ) );
		}
		$user_id = get_current_user_id();
		if ( ! \ATORA\Forums\Forums_Service::can_participate( $user_id, $forum ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso a este foro.', 'atora-lms' ) ), 403 );
		}
		if ( '' === $q ) {
			wp_send_json_success( array( 'items' => array() ) );
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$wpdb->prefix}atora_forum_topics WHERE forum_id = %d AND hidden = 0 AND (title LIKE %s OR body LIKE %s) ORDER BY last_activity_at DESC, id DESC LIMIT 5", (int) $forum['id'], $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array( 'id' => (int) $row['id'], 'title' => sanitize_text_field( (string) $row['title'] ) );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	private static function user_can_access_reports_admin( int $user_id ): bool {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		if ( class_exists( 'ATORA_Teacher_Scope' ) && method_exists( 'ATORA_Teacher_Scope', 'has_teacher_role' ) ) {
			return (bool) \ATORA_Teacher_Scope::has_teacher_role( $user_id );
		}
		return false;
	}

	public static function get_open_reports_for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}atora_forum_reports WHERE status = 'open' ORDER BY id DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return array();
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$forum = \ATORA\Forums\Forums_Service::forum_by_id( (int) $row['forum_id'] );
			if ( ! $forum ) {
				continue;
			}
			if ( ! \ATORA\Forums\Forums_Service::can_moderate( $user_id, $forum ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	private static function handle_admin_post( int $user_id ): array {
		$out = array( 'handled' => false, 'message' => '', 'role' => 'status' );
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $_POST['_wpnonce'] ), self::ADMIN_NONCE_ACTION ) ) {
			$out['handled'] = true;
			$out['message'] = __( 'Nonce inválido.', 'atora-lms' );
			$out['role'] = 'alert';
			return $out;
		}
		$report_id = absint( $_POST['report_id'] ?? 0 );
		$action = sanitize_key( (string) ( $_POST['action'] ?? '' ) );
		if ( $report_id <= 0 || ! in_array( $action, array( 'dismiss', 'hide_resolve' ), true ) ) {
			return $out;
		}
		global $wpdb;
		$report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_reports WHERE id = %d LIMIT 1", $report_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $report ) ) {
			$out['handled'] = true;
			$out['message'] = __( 'Reporte no encontrado.', 'atora-lms' );
			$out['role'] = 'alert';
			return $out;
		}
		$forum = \ATORA\Forums\Forums_Service::forum_by_id( (int) $report['forum_id'] );
		if ( ! $forum || ! \ATORA\Forums\Forums_Service::can_moderate( $user_id, $forum ) ) {
			$out['handled'] = true;
			$out['message'] = __( 'No tienes acceso.', 'atora-lms' );
			$out['role'] = 'alert';
			return $out;
		}
		$out['handled'] = true;
		if ( 'hide_resolve' === $action ) {
			$target_id = absint( $report['target_id'] ?? 0 );
			$type = (string) ( $report['target_type'] ?? '' );
			if ( 'topic' === $type ) {
				$wpdb->update( $wpdb->prefix . 'atora_forum_topics', array( 'hidden' => 1 ), array( 'id' => $target_id ) ); // phpcs:ignore WordPress.DB
			} elseif ( 'reply' === $type ) {
				$wpdb->update( $wpdb->prefix . 'atora_forum_replies', array( 'hidden' => 1 ), array( 'id' => $target_id ) ); // phpcs:ignore WordPress.DB
			}
			$wpdb->update( $wpdb->prefix . 'atora_forum_reports', array( 'status' => 'resolved' ), array( 'id' => $report_id ) ); // phpcs:ignore WordPress.DB
			$out['message'] = __( 'Mensaje oculto y reporte resuelto.', 'atora-lms' );
			return $out;
		}
		$wpdb->update( $wpdb->prefix . 'atora_forum_reports', array( 'status' => 'dismissed' ), array( 'id' => $report_id ) ); // phpcs:ignore WordPress.DB
		$out['message'] = __( 'Reporte resuelto.', 'atora-lms' );
		return $out;
	}

	private static function topic_link_for_report( array $report ): string {
		$forum = \ATORA\Forums\Forums_Service::forum_by_id( (int) ( $report['forum_id'] ?? 0 ) );
		if ( ! $forum ) {
			return '';
		}
		$topic_id = 0;
		if ( 'topic' === (string) ( $report['target_type'] ?? '' ) ) {
			$topic_id = absint( $report['target_id'] ?? 0 );
		} elseif ( 'reply' === (string) ( $report['target_type'] ?? '' ) ) {
			global $wpdb;
			$topic_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT topic_id FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d LIMIT 1", absint( $report['target_id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB
		}
		if ( $topic_id <= 0 ) {
			return '';
		}
		if ( 'course' !== (string) ( $forum['scope_type'] ?? '' ) ) {
			return '';
		}
		$url = get_permalink( absint( $forum['scope_id'] ?? 0 ) );
		if ( ! $url ) {
			return '';
		}
		return add_query_arg( array( 'topic' => $topic_id ), $url );
	}
}

