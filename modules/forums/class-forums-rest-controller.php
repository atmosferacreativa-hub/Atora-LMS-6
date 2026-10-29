<?php
/**
 * Mobile API: atora-mobile/v1/forums/*
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

namespace ATORA\Forums;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Forums_REST_Controller {
	public static function register_routes(): void {
		$ns = 'atora-mobile/v1';
		$auth = array( 'ATORA_Mobile_REST_Controller', 'authorize' );

		register_rest_route( $ns, '/forums', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'forums' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/forums/(?P<forum_id>\d+)/topics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'topics' ),
			'permission_callback' => $auth,
			'args'                => array( 'forum_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/forums/(?P<forum_id>\d+)/similar', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'similar' ),
			'permission_callback' => $auth,
			'args'                => array( 'forum_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/forums/(?P<forum_id>\d+)/topics', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_topic' ),
				'permission_callback' => $auth,
			),
		), true );
		register_rest_route( $ns, '/forums/topics/(?P<topic_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'topic_detail' ),
			'permission_callback' => $auth,
			'args'                => array( 'topic_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/forums/topics/(?P<topic_id>\d+)/replies', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_reply' ),
			'permission_callback' => $auth,
			'args'                => array( 'topic_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/forums/topics/(?P<topic_id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'edit_topic' ),
				'permission_callback' => $auth,
			),
		), true );
		register_rest_route( $ns, '/forums/replies/(?P<reply_id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'edit_reply' ),
				'permission_callback' => $auth,
			),
		), true );
		register_rest_route( $ns, '/forums/topics/(?P<topic_id>\d+)/report', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'report_topic' ),
			'permission_callback' => $auth,
			'args'                => array( 'topic_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/forums/replies/(?P<reply_id>\d+)/report', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'report_reply' ),
			'permission_callback' => $auth,
			'args'                => array( 'reply_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/forums/topics/(?P<topic_id>\d+)/moderate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'moderate_topic' ),
			'permission_callback' => $auth,
			'args'                => array( 'topic_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( $ns, '/forums/reports/(?P<report_id>\d+)/resolve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'resolve_report' ),
			'permission_callback' => $auth,
			'args'                => array( 'report_id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
	}

	public static function forums(): WP_REST_Response {
		$user_id = get_current_user_id();
		$items = array();

		// Course forums.
		if ( class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) && method_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service', 'get_enrolled_wp_course_ids' ) ) {
			$course_ids = (array) \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( $user_id );
			foreach ( array_values( array_unique( array_map( 'absint', $course_ids ) ) ) as $wp_course_id ) {
				if ( $wp_course_id <= 0 ) {
					continue;
				}
				$forum = Forums_Service::forum_for_scope( 'course', $wp_course_id );
				if ( is_wp_error( $forum ) ) {
					continue;
				}
				$items[] = self::forum_out( $forum );
			}
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	public static function topics( WP_REST_Request $request ) {
		$forum = Forums_Service::forum_by_id( absint( $request['forum_id'] ) );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$cursor = (string) $request->get_param( 'cursor' );
		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		$limit = min( 30, max( 1, absint( $request->get_param( 'limit' ) ?: 20 ) ) );

		global $wpdb;
		$where = 'forum_id = %d';
		$args = array( (int) $forum['id'] );
		if ( ! Forums_Service::can_moderate( $user_id, $forum ) ) {
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
		if ( '' !== $cursor ) {
			$where .= ' AND id < %d';
			$args[] = absint( $cursor );
		}
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE {$where} ORDER BY pinned DESC, last_activity_at DESC, id DESC LIMIT {$limit}",
			$args
		); // phpcs:ignore WordPress.DB
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$items = array();
		$next = null;
		foreach ( (array) $rows as $row ) {
			$items[] = self::topic_out( $row );
			$next = (string) $row['id'];
		}
		return new WP_REST_Response( array( 'items' => $items, 'next_cursor' => $next ), 200 );
	}

	public static function similar( WP_REST_Request $request ) {
		$forum = Forums_Service::forum_by_id( absint( $request['forum_id'] ) );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $q ) {
			return new WP_REST_Response( array( 'items' => array() ), 200 );
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE forum_id = %d AND hidden = 0 AND (title LIKE %s OR body LIKE %s) ORDER BY last_activity_at DESC, id DESC LIMIT 5",
			(int) $forum['id'],
			$like,
			$like
		), ARRAY_A ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array( 'items' => array_map( array( __CLASS__, 'topic_out' ), (array) $rows ) ), 200 );
	}

	public static function topic_detail( WP_REST_Request $request ) {
		$topic_id = absint( $request['topic_id'] );
		global $wpdb;
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $topic ) ) {
			return new WP_Error( 'atora_forums_topic_not_found', __( 'Tema no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$forum = Forums_Service::forum_by_id( (int) $topic['forum_id'] );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( ! Forums_Service::can_moderate( $user_id, $forum ) && ! empty( $topic['hidden'] ) ) {
			return new WP_Error( 'atora_forums_topic_hidden', __( 'Tema no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$replies = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE topic_id = %d" . ( Forums_Service::can_moderate( $user_id, $forum ) ? '' : ' AND hidden = 0' ) . ' ORDER BY id ASC', $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array(
			'topic'   => self::topic_out( $topic ),
			'replies' => array_map( array( __CLASS__, 'reply_out' ), (array) $replies ),
		), 200 );
	}

	public static function create_topic( WP_REST_Request $request ) {
		$forum = Forums_Service::forum_by_id( absint( $request['forum_id'] ) );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( Forums_Service::is_read_only( $forum ) ) {
			return new WP_Error( 'atora_forums_read_only', __( 'El foro está en solo lectura.', 'atora-lms' ), array( 'status' => 409 ) );
		}

		$params = (array) $request->get_json_params();
		$title = sanitize_text_field( (string) ( $params['title'] ?? '' ) );
		$body = sanitize_textarea_field( (string) ( $params['body'] ?? '' ) );
		$event = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		if ( '' === $title || '' === $body || '' === $event ) {
			return new WP_Error( 'atora_forums_invalid', __( 'Faltan campos.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE forum_id = %d AND author_id = %d AND client_event_id = %s LIMIT 1", (int) $forum['id'], $user_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( is_array( $existing ) ) {
			return new WP_REST_Response( array( 'topic' => self::topic_out( $existing ), 'replayed' => true ), 200 );
		}
		$ok = $wpdb->insert( $wpdb->prefix . 'atora_forum_topics', array(
			'forum_id'         => (int) $forum['id'],
			'institution_id'   => (int) $forum['institution_id'],
			'author_id'        => $user_id,
			'title'            => $title,
			'body'             => $body,
			'client_event_id'  => $event,
			'last_activity_at' => current_time( 'mysql', true ),
		) ); // phpcs:ignore WordPress.DB
		if ( ! $ok ) {
			return new WP_Error( 'atora_forums_db', __( 'No se pudo crear el tema.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array( 'topic' => self::topic_out( (array) $topic ) ), 201 );
	}

	public static function create_reply( WP_REST_Request $request ) {
		$topic_id = absint( $request['topic_id'] );
		global $wpdb;
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $topic ) ) {
			return new WP_Error( 'atora_forums_topic_not_found', __( 'Tema no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$forum = Forums_Service::forum_by_id( (int) $topic['forum_id'] );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( Forums_Service::is_read_only( $forum ) || ! empty( $topic['closed'] ) ) {
			return new WP_Error( 'atora_forums_closed', __( 'El tema está cerrado.', 'atora-lms' ), array( 'status' => 409 ) );
		}

		$params = (array) $request->get_json_params();
		$body = sanitize_textarea_field( (string) ( $params['body'] ?? '' ) );
		$event = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		if ( '' === $body || '' === $event ) {
			return new WP_Error( 'atora_forums_invalid', __( 'Faltan campos.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE topic_id = %d AND author_id = %d AND client_event_id = %s LIMIT 1", $topic_id, $user_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( is_array( $existing ) ) {
			return new WP_REST_Response( array( 'reply' => self::reply_out( $existing ), 'replayed' => true ), 200 );
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
			return new WP_Error( 'atora_forums_db', __( 'No se pudo crear la respuesta.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$reply_id = (int) $wpdb->insert_id;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_forum_topics SET replies_count = replies_count + 1, last_activity_at = %s WHERE id = %d", current_time( 'mysql', true ), $topic_id ) ); // phpcs:ignore WordPress.DB
		$reply = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d LIMIT 1", $reply_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		Forums_Notifications::notify_topic_replied( (int) $topic['author_id'], $topic_id, $user_id, (int) $forum['id'] );
		return new WP_REST_Response( array( 'reply' => self::reply_out( (array) $reply ) ), 201 );
	}

	public static function edit_topic( WP_REST_Request $request ) {
		$topic_id = absint( $request['topic_id'] );
		global $wpdb;
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $topic ) ) {
			return new WP_Error( 'atora_forums_topic_not_found', __( 'Tema no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$forum = Forums_Service::forum_by_id( (int) $topic['forum_id'] );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( ! Forums_Service::can_edit_message( $user_id, $topic ) && ! Forums_Service::can_moderate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_edit_forbidden', __( 'No puedes editar este tema.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$params = (array) $request->get_json_params();
		$body = sanitize_textarea_field( (string) ( $params['body'] ?? '' ) );
		if ( '' === $body ) {
			return new WP_Error( 'atora_forums_invalid', __( 'Falta el cuerpo.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$wpdb->update( $wpdb->prefix . 'atora_forum_topics', array( 'body' => $body ), array( 'id' => $topic_id ) ); // phpcs:ignore WordPress.DB
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array( 'topic' => self::topic_out( (array) $topic ) ), 200 );
	}

	public static function edit_reply( WP_REST_Request $request ) {
		$reply_id = absint( $request['reply_id'] );
		global $wpdb;
		$reply = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d LIMIT 1", $reply_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $reply ) ) {
			return new WP_Error( 'atora_forums_reply_not_found', __( 'Respuesta no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$forum = Forums_Service::forum_by_id( (int) $reply['forum_id'] );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( ! Forums_Service::can_edit_message( $user_id, $reply ) && ! Forums_Service::can_moderate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_edit_forbidden', __( 'No puedes editar esta respuesta.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$params = (array) $request->get_json_params();
		$body = sanitize_textarea_field( (string) ( $params['body'] ?? '' ) );
		if ( '' === $body ) {
			return new WP_Error( 'atora_forums_invalid', __( 'Falta el cuerpo.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$wpdb->update( $wpdb->prefix . 'atora_forum_replies', array( 'body' => $body ), array( 'id' => $reply_id ) ); // phpcs:ignore WordPress.DB
		$reply = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d LIMIT 1", $reply_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array( 'reply' => self::reply_out( (array) $reply ) ), 200 );
	}

	public static function report_topic( WP_REST_Request $request ) {
		return self::create_report( 'topic', absint( $request['topic_id'] ), (array) $request->get_json_params() );
	}

	public static function report_reply( WP_REST_Request $request ) {
		return self::create_report( 'reply', absint( $request['reply_id'] ), (array) $request->get_json_params() );
	}

	private static function create_report( string $type, int $target_id, array $params ) {
		global $wpdb;
		if ( $target_id <= 0 ) {
			return new WP_Error( 'atora_forums_invalid', __( 'Reporte inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$user_id = get_current_user_id();
		$reason = sanitize_text_field( (string) ( $params['reason'] ?? '' ) );
		if ( '' === $reason ) {
			return new WP_Error( 'atora_forums_invalid', __( 'Indica el motivo.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$forum_id = 0;
		$inst = 0;
		if ( 'topic' === $type ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT forum_id, institution_id FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $target_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$forum_id = absint( $row['forum_id'] ?? 0 );
			$inst = absint( $row['institution_id'] ?? 0 );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT forum_id, institution_id FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d LIMIT 1", $target_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$forum_id = absint( $row['forum_id'] ?? 0 );
			$inst = absint( $row['institution_id'] ?? 0 );
		}
		$forum = $forum_id ? Forums_Service::forum_by_id( $forum_id ) : null;
		if ( ! $forum || ! Forums_Service::can_participate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No tienes acceso a este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$wpdb->insert( $wpdb->prefix . 'atora_forum_reports', array(
			'forum_id'        => $forum_id,
			'institution_id'  => $inst,
			'target_type'     => $type,
			'target_id'       => $target_id,
			'reporter_id'     => $user_id,
			'reason'          => $reason,
			'status'          => 'open',
		) ); // phpcs:ignore WordPress.DB
		$report_id = (int) $wpdb->insert_id;
		$mods = self::moderators_for_forum( $forum );
		Forums_Notifications::notify_moderators_reported( $mods, $report_id, $forum_id );
		return new WP_REST_Response( array( 'report_id' => $report_id ), 201 );
	}

	public static function moderate_topic( WP_REST_Request $request ) {
		$topic_id = absint( $request['topic_id'] );
		$params = (array) $request->get_json_params();
		$action = sanitize_key( (string) ( $params['action'] ?? '' ) );
		global $wpdb;
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $topic ) ) {
			return new WP_Error( 'atora_forums_topic_not_found', __( 'Tema no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$forum = Forums_Service::forum_by_id( (int) $topic['forum_id'] );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		$fields = array();
		if ( in_array( $action, array( 'pin', 'unpin', 'close', 'open', 'hide', 'show' ), true ) ) {
			if ( ! Forums_Service::can_moderate( $user_id, $forum ) ) {
				return new WP_Error( 'atora_forums_forbidden', __( 'No puedes moderar este foro.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			$fields = array(
				'pinned' => in_array( $action, array( 'pin', 'unpin' ), true ) ? ( 'pin' === $action ? 1 : 0 ) : (int) $topic['pinned'],
				'closed' => in_array( $action, array( 'close', 'open' ), true ) ? ( 'close' === $action ? 1 : 0 ) : (int) $topic['closed'],
				'hidden' => in_array( $action, array( 'hide', 'show' ), true ) ? ( 'hide' === $action ? 1 : 0 ) : (int) $topic['hidden'],
			);
		} elseif ( 'accept_reply' === $action ) {
			$reply_id = absint( $params['reply_id'] ?? 0 );
			if ( $reply_id <= 0 ) {
				return new WP_Error( 'atora_forums_invalid', __( 'Respuesta inválida.', 'atora-lms' ), array( 'status' => 400 ) );
			}
			if ( ! Forums_Service::can_accept_reply( $user_id, $forum, $topic ) ) {
				return new WP_Error( 'atora_forums_forbidden', __( 'No puedes aceptar respuestas en este tema.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			$reply = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d AND topic_id = %d LIMIT 1", $reply_id, $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( ! is_array( $reply ) ) {
				return new WP_Error( 'atora_forums_reply_not_found', __( 'Respuesta no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
			}
			$fields = array( 'accepted_reply_id' => $reply_id );
			Forums_Notifications::notify_reply_accepted( (int) $reply['author_id'], $topic_id, $user_id );
		} else {
			return new WP_Error( 'atora_forums_invalid', __( 'Acción inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$wpdb->update( $wpdb->prefix . 'atora_forum_topics', $fields, array( 'id' => $topic_id ) ); // phpcs:ignore WordPress.DB
		$topic = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_topics WHERE id = %d LIMIT 1", $topic_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array( 'topic' => self::topic_out( (array) $topic ) ), 200 );
	}

	public static function resolve_report( WP_REST_Request $request ) {
		$report_id = absint( $request['report_id'] );
		global $wpdb;
		$report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forum_reports WHERE id = %d LIMIT 1", $report_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $report ) ) {
			return new WP_Error( 'atora_forums_report_not_found', __( 'Reporte no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$forum = Forums_Service::forum_by_id( (int) $report['forum_id'] );
		if ( ! $forum ) {
			return new WP_Error( 'atora_forums_not_found', __( 'Foro no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Forums_Service::can_moderate( $user_id, $forum ) ) {
			return new WP_Error( 'atora_forums_forbidden', __( 'No puedes moderar este foro.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$params = (array) $request->get_json_params();
		$status = sanitize_key( (string) ( $params['status'] ?? 'resolved' ) );
		if ( ! in_array( $status, array( 'resolved', 'dismissed' ), true ) ) {
			$status = 'resolved';
		}
		$wpdb->update( $wpdb->prefix . 'atora_forum_reports', array( 'status' => $status ), array( 'id' => $report_id ) ); // phpcs:ignore WordPress.DB
		return new WP_REST_Response( array( 'status' => $status ), 200 );
	}

	private static function forum_out( array $forum ): array {
		$scope_type = (string) $forum['scope_type'];
		$scope_id = absint( $forum['scope_id'] );
		$title = '';
		if ( 'course' === $scope_type ) {
			$title = (string) get_the_title( $scope_id );
		}
		return array(
			'id'         => absint( $forum['id'] ),
			'scope_type' => $scope_type,
			'scope_id'   => $scope_id,
			'title'      => sanitize_text_field( $title ),
			'read_only'  => Forums_Service::is_read_only( $forum ),
		);
	}

	private static function topic_out( array $row ): array {
		return array(
			'id'                 => (int) $row['id'],
			'forum_id'            => (int) $row['forum_id'],
			'title'              => sanitize_text_field( (string) $row['title'] ),
			'body'               => (string) $row['body'],
			'author'             => array( 'id' => (int) $row['author_id'], 'name' => self::user_name( (int) $row['author_id'] ) ),
			'pinned'             => ! empty( $row['pinned'] ),
			'closed'             => ! empty( $row['closed'] ),
			'hidden'             => ! empty( $row['hidden'] ),
			'accepted_reply_id'  => absint( $row['accepted_reply_id'] ?? 0 ) ?: null,
			'replies_count'      => absint( $row['replies_count'] ?? 0 ),
			'last_activity_at'   => (string) $row['last_activity_at'],
			'created_at'         => (string) $row['created_at'],
		);
	}

	private static function reply_out( array $row ): array {
		return array(
			'id'        => (int) $row['id'],
			'topic_id'  => (int) $row['topic_id'],
			'body'      => (string) $row['body'],
			'author'    => array( 'id' => (int) $row['author_id'], 'name' => self::user_name( (int) $row['author_id'] ) ),
			'hidden'    => ! empty( $row['hidden'] ),
			'created_at' => (string) $row['created_at'],
		);
	}

	private static function user_name( int $user_id ): string {
		$user = get_user_by( 'id', $user_id );
		return $user ? ( $user->display_name ?: $user->user_login ) : '';
	}

	private static function moderators_for_forum( array $forum ): array {
		$mods = array();
		$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ids' ) );
		$mods = array_merge( $mods, array_map( 'absint', (array) $admins ) );
		return array_values( array_unique( array_filter( $mods ) ) );
	}
}

