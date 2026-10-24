<?php
/**
 * SCORM (Orden B) — reproductor web + seguimiento (Bloque 2).
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

namespace ATORA\SCORM;

use ATORA\SCORM\REST\SCORM_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCORM_Module {
	public const PLAYER_QUERY_VAR = 'atora_scorm';

	public static function init(): void {
		require_once ATORA_LMS_INCLUDES_DIR . 'scorm/class-scorm-manifest-parser.php';
		require_once ATORA_LMS_INCLUDES_DIR . 'scorm/class-scorm-attempt-service.php';
		require_once ATORA_LMS_INCLUDES_DIR . 'scorm/class-scorm-gradebook-integration.php';
		require_once ATORA_LMS_INCLUDES_DIR . 'scorm/class-scorm-zip-extractor.php';
		require_once ATORA_LMS_MODULES_DIR . 'scorm/rest/class-scorm-controller.php';

		add_action( 'init', array( __CLASS__, 'register_query_var' ) );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_htaccess' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_player' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );

		\CLMS_SCORM_Gradebook_Integration::init();
	}

	public static function maybe_migrate_htaccess(): void {
		$done = (string) get_option( 'atora_scorm_htaccess_migrated', '' );
		if ( '' !== $done ) {
			return;
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}
		$uploads = wp_upload_dir();
		$basedir = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' );
		if ( '' === $basedir ) {
			return;
		}
		$root = $basedir . '/atora-library/scorm';
		if ( ! is_dir( $root ) ) {
			update_option( 'atora_scorm_htaccess_migrated', ATORA_LMS_VERSION, false );
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			$path = (string) ( $file instanceof \SplFileInfo ? $file->getPathname() : '' );
			if ( '' === $path || '.htaccess' !== basename( $path ) ) {
				continue;
			}
			\CLMS_SCORM_Zip_Extractor::ensure_protected_directory( dirname( $path ) );
		}
		update_option( 'atora_scorm_htaccess_migrated', ATORA_LMS_VERSION, false );
	}

	public static function register_query_var(): void {
		add_filter( 'query_vars', static function ( array $vars ): array {
			$vars[] = self::PLAYER_QUERY_VAR;
			return $vars;
		} );
	}

	public static function register_rest(): void {
		( new SCORM_Controller( new \CLMS_SCORM_Attempt_Service() ) )->register_routes();
	}

	public static function maybe_render_player(): void {
		$enabled = get_query_var( self::PLAYER_QUERY_VAR );
		if ( ! $enabled && empty( $_GET[ self::PLAYER_QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! is_user_logged_in() ) {
			status_header( 401 );
			echo esc_html__( 'Debes iniciar sesión para abrir el paquete SCORM.', 'atora-lms' );
			exit;
		}
		$lesson_id          = isset( $_GET['lesson_id'] ) ? absint( $_GET['lesson_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$library_item_id    = isset( $_GET['library_item_id'] ) ? absint( $_GET['library_item_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$library_version_id = isset( $_GET['library_version_id'] ) ? absint( $_GET['library_version_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $lesson_id || ! $library_item_id ) {
			status_header( 400 );
			echo esc_html__( 'Parámetros SCORM inválidos.', 'atora-lms' );
			exit;
		}
		if ( 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			status_header( 404 );
			echo esc_html__( 'Lección no encontrada.', 'atora-lms' );
			exit;
		}
		$user_id = get_current_user_id();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_access_lesson' ) ) {
			if ( ! \CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
				status_header( 403 );
				echo esc_html__( 'No tienes acceso a esta lección.', 'atora-lms' );
				exit;
			}
		}

		wp_enqueue_style(
			'atora-scorm-player',
			ATORA_LMS_URL . 'assets/css/atora-scorm-player.css',
			array(),
			ATORA_LMS_VERSION
		);
		wp_enqueue_script(
			'atora-scorm-player',
			ATORA_LMS_URL . 'assets/js/atora-scorm-player.js',
			array(),
			ATORA_LMS_VERSION,
			true
		);
		wp_localize_script(
			'atora-scorm-player',
			'ATORA_SCORM_PLAYER',
			array(
				'restBase'         => esc_url_raw( rest_url( 'atora/v1/scorm' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'lessonId'         => $lesson_id,
				'libraryItemId'    => $library_item_id,
				'libraryVersionId' => $library_version_id,
			)
		);

		// Template mínimo standalone.
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body class="atora-scorm-player">
	<div class="atora-scorm-player__topbar">
		<div class="atora-scorm-player__title"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></div>
		<div class="atora-scorm-player__controls">
			<button type="button" class="atora-scorm-player__btn" data-action="prev">&larr;</button>
			<button type="button" class="atora-scorm-player__btn" data-action="next">&rarr;</button>
		</div>
	</div>
	<div class="atora-scorm-player__frame">
		<iframe id="atora-scorm-iframe" title="SCORM" allow="fullscreen" referrerpolicy="strict-origin-when-cross-origin"></iframe>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}
}

