<?php
/**
 * Forum notifications via CLMS_Notifications infrastructure.
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

namespace ATORA\Forums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Forums_Notifications {
	public static function notify_topic_replied( int $topic_author, int $topic_id, int $reply_author, int $forum_id ): void {
		if ( $topic_author <= 0 || $topic_author === $reply_author ) {
			return;
		}
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}
		$link = rest_url( 'atora-mobile/v1/forums/topics/' . $topic_id );
		$notifications->add_notification( $topic_author, array(
			'type'       => 'forum_reply',
			'title'      => __( 'Nueva respuesta', 'atora-lms' ),
			'message'    => __( 'Respondieron a tu tema en el foro.', 'atora-lms' ),
			'link'       => $link,
			'dedupe_key' => 'forum_reply_' . $topic_id,
		) );
	}

	public static function notify_reply_accepted( int $reply_author, int $topic_id, int $accepted_by ): void {
		if ( $reply_author <= 0 || $reply_author === $accepted_by ) {
			return;
		}
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}
		$link = rest_url( 'atora-mobile/v1/forums/topics/' . $topic_id );
		$notifications->add_notification( $reply_author, array(
			'type'       => 'forum_accepted',
			'title'      => __( 'Respuesta aceptada', 'atora-lms' ),
			'message'    => __( 'Marcaron tu respuesta como aceptada.', 'atora-lms' ),
			'link'       => $link,
			'dedupe_key' => 'forum_accepted_' . $topic_id,
		) );
	}

	public static function notify_moderators_reported( array $moderator_ids, int $report_id, int $forum_id ): void {
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}
		$link = admin_url( 'admin.php?page=atora-forums' );
		foreach ( array_unique( array_map( 'absint', $moderator_ids ) ) as $uid ) {
			if ( $uid <= 0 ) {
				continue;
			}
			$notifications->add_notification( $uid, array(
				'type'       => 'forum_report',
				'title'      => __( 'Reporte en el foro', 'atora-lms' ),
				'message'    => __( 'Hay un nuevo reporte pendiente.', 'atora-lms' ),
				'link'       => $link,
				'dedupe_key' => 'forum_report_' . $report_id,
			) );
		}
	}
}

