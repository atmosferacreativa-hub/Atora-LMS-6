<?php
/**
 * Epic 8 — H5P (MVP): embed + tracking xAPI + auto-scoring + REST unificado.
 *
 * @package ATORA_LMS
 * @since   6.19.0
 */

namespace ATORA\H5P;

use ATORA\H5P\REST\H5P_Content_Controller;
use ATORA\H5P\REST\H5P_Library_Controller;
use ATORA\H5P\REST\H5P_Tracking_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class H5P_Module {

	const NONCE_ACTION = 'atora_h5p_track';

	public static function init(): void {
		$content_manager = new H5P_Content_Manager();
		add_action( 'init', array( $content_manager, 'maybe_register_cpt' ), 5 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'rename_admin_menu_label' ), 999 );
		}

		add_shortcode( 'atora_h5p', array( __CLASS__, 'shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'inject_into_lesson_content' ), 12 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_front_assets' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/**
	 * Renombra el item del menú lateral del CPT `h5p_content` a "Interactivo H5P".
	 *
	 * Esto cubre el caso donde el CPT ya existe (p.ej. plugin H5P externo),
	 * y por tanto ATORA no lo registra y no puede inyectar labels.
	 *
	 * @return void
	 */
	public static function rename_admin_menu_label(): void {
		global $menu, $submenu;

		$target_slug = 'edit.php?post_type=h5p_content';
		$target_label = __( '🟦 Interactivo H5P', 'atora-lms' );

		foreach ( (array) $menu as $i => $item ) {
			if ( isset( $item[2] ) && $target_slug === (string) $item[2] ) {
				$menu[ $i ][0] = $target_label;
				break;
			}
		}

		if ( isset( $submenu[ $target_slug ] ) && is_array( $submenu[ $target_slug ] ) ) {
			foreach ( $submenu[ $target_slug ] as $j => $item ) {
				if ( isset( $item[0] ) && is_string( $item[0] ) && '' !== $item[0] ) {
					$submenu[ $target_slug ][ $j ][0] = $target_label;
					break;
				}
			}
		}

		// Si el CPT está anidado bajo el menú ATORA (show_in_menu=clms-dashboard),
		// el label vive en el submenu de `clms-dashboard`.
		if ( isset( $submenu['clms-dashboard'] ) && is_array( $submenu['clms-dashboard'] ) ) {
			foreach ( $submenu['clms-dashboard'] as $k => $item ) {
				if ( isset( $item[2] ) && $target_slug === (string) $item[2] ) {
					$submenu['clms-dashboard'][ $k ][0] = $target_label;
					break;
				}
			}
		}
	}

	private static function get_lesson_h5p_config( int $lesson_id ): array {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return array();
		}

		$content_id = absint( get_post_meta( $lesson_id, '_clms_h5p_content_id', true ) );
		$embed      = '1' === (string) get_post_meta( $lesson_id, '_clms_h5p_embed', true );
		$track      = '1' === (string) get_post_meta( $lesson_id, '_clms_h5p_track', true );
		$autoscore  = '1' === (string) get_post_meta( $lesson_id, '_clms_h5p_autoscore', true );

		if ( ! $content_id ) {
			return array();
		}

		return array(
			'content_id' => $content_id,
			'embed'      => $embed,
			'track'      => $track,
			'autoscore'  => $autoscore,
		);
	}

	private static function is_h5p_available(): bool {
		return shortcode_exists( 'h5p' ) || post_type_exists( 'h5p_content' );
	}

	public static function shortcode( $atts = array() ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$lesson_id = isset( $atts['lesson_id'] ) ? absint( $atts['lesson_id'] ) : 0;
		if ( ! $lesson_id && is_singular( 'lm_lesson' ) ) {
			$lesson_id = absint( get_the_ID() );
		}

		if ( ! $lesson_id ) {
			return '';
		}

		$cfg = self::get_lesson_h5p_config( $lesson_id );
		if ( empty( $cfg['content_id'] ) || empty( $cfg['embed'] ) ) {
			return '';
		}

		return self::render_player( $lesson_id, absint( $cfg['content_id'] ) );
	}

	/**
	 * Inyecta H5P al inicio de la lección si se configuró y si el contenido no
	 * tiene ya un shortcode [h5p ...] (para evitar duplicados).
	 */
	public static function inject_into_lesson_content( string $content ): string {
		if ( ! is_singular( 'lm_lesson' ) ) {
			return $content;
		}

		$lesson_id = absint( get_the_ID() );
		if ( ! $lesson_id ) {
			return $content;
		}

		$cfg = self::get_lesson_h5p_config( $lesson_id );
		if ( empty( $cfg['content_id'] ) || empty( $cfg['embed'] ) ) {
			return $content;
		}

		if ( false !== stripos( $content, '[h5p' ) || false !== stripos( $content, '[atora_h5p' ) ) {
			return $content;
		}

		$block = do_shortcode( '[atora_h5p lesson_id="' . $lesson_id . '"]' );
		if ( '' === trim( (string) $block ) ) {
			return $content;
		}

		return $block . "\n\n" . $content;
	}

	public static function maybe_enqueue_front_assets(): void {
		if ( ! is_singular( 'lm_lesson' ) || ! is_user_logged_in() ) {
			return;
		}

		$lesson_id = absint( get_the_ID() );
		if ( ! $lesson_id ) {
			return;
		}

		$cfg = self::get_lesson_h5p_config( $lesson_id );
		if ( empty( $cfg['content_id'] ) || empty( $cfg['track'] ) ) {
			return;
		}

		wp_enqueue_script(
			'atora-h5p-tracker',
			ATORA_LMS_URL . 'assets/js/atora-h5p-tracker.js',
			array(),
			ATORA_LMS_VERSION,
			true
		);

		wp_localize_script(
			'atora-h5p-tracker',
			'ATORA_H5P_TRACK',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'atora/v1/h5p/track' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'lessonId'   => $lesson_id,
				'contentId'  => absint( $cfg['content_id'] ),
				'autoscore'  => ! empty( $cfg['autoscore'] ) ? 1 : 0,
			)
		);
	}

	public static function register_rest(): void {
		$content  = new H5P_Content_Manager();
		$tracking = new H5P_Tracking_Service();
		$libs     = new H5P_Library_Service();

		( new H5P_Content_Controller( $content ) )->register_routes();
		( new H5P_Library_Controller( $libs ) )->register_routes();
		( new H5P_Tracking_Controller( $tracking ) )->register_routes();
	}

	private static function render_player( int $lesson_id, int $content_id ): string {
		$lesson_id  = absint( $lesson_id );
		$content_id = absint( $content_id );

		if ( ! $lesson_id || ! $content_id ) {
			return '';
		}

		$view = ATORA_LMS_MODULES_DIR . 'h5p/views/h5p-player.php';
		if ( ! file_exists( $view ) ) {
			return '';
		}

		ob_start();
		require $view;
		return (string) ob_get_clean();
	}
}
