<?php
/**
 * Delegation attribution/audit hooks (E-10).
 *
 * @package ATORA_LMS
 * @since   6.26.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Delegation_Attribution {

	public static function init(): void {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	public static function on_save_post( $post_id, $post, $update ): void {
		unset( $update );

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! $post instanceof WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( (string) $post->post_type, array( 'lm_course', 'lm_lesson' ), true ) ) {
			return;
		}

		$actor_id = get_current_user_id();
		$owner_id = absint( $post->post_author );
		if ( $actor_id <= 0 || $owner_id <= 0 || $actor_id === $owner_id ) {
			return;
		}

		if ( ! class_exists( 'ATORA_Delegation_Service' ) || ! ATORA_Delegation_Service::covers( $actor_id, $post, 'content' ) ) {
			return;
		}

		update_post_meta( $post_id, '_atora_last_edited_by', absint( $actor_id ) );
		update_post_meta( $post_id, '_atora_last_edited_at', current_time( 'mysql', true ) );
	}

	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post ) { return; }
		if ( 'publish' !== (string) $new_status || 'publish' === (string) $old_status ) {
			return;
		}
		if ( ! in_array( (string) $post->post_type, array( 'lm_course', 'lm_lesson' ), true ) ) {
			return;
		}

		$actor_id = get_current_user_id();
		$owner_id = absint( $post->post_author );
		if ( $actor_id <= 0 || $owner_id <= 0 || $actor_id === $owner_id ) {
			return;
		}

		if ( ! class_exists( 'ATORA_Delegation_Service' ) || ! ATORA_Delegation_Service::covers( $actor_id, $post, 'content' ) ) {
			return;
		}

		update_post_meta( absint( $post->ID ), '_atora_published_by', absint( $actor_id ) );
	}
}

