<?php
/**
 * Portafolios — UI + REST + shortcodes.
 *
 * @package ATORA_LMS
 * @since   6.17.0
 */

namespace ATORA\Portfolios;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Portfolios_Module {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_shortcode( 'atora_portfolio', array( __CLASS__, 'shortcode_my_portfolio' ) );
		add_shortcode( 'atora_portfolio_public', array( __CLASS__, 'shortcode_public_portfolio' ) );

		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		}

		add_action( 'admin_post_atora_portfolio_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_atora_portfolio_add_item', array( __CLASS__, 'handle_add_item' ) );
		add_action( 'admin_post_atora_portfolio_update_item', array( __CLASS__, 'handle_update_item' ) );
		add_action( 'admin_post_atora_portfolio_delete_item', array( __CLASS__, 'handle_delete_item' ) );
		add_action( 'admin_post_atora_portfolio_reorder', array( __CLASS__, 'handle_reorder' ) );
		add_action( 'admin_post_atora_portfolio_add_feedback', array( __CLASS__, 'handle_add_feedback' ) );
		add_action( 'admin_post_atora_portfolio_assess', array( __CLASS__, 'handle_assess' ) );
		add_action( 'admin_post_atora_portfolio_export_zip', array( __CLASS__, 'handle_export_zip' ) );
	}

	private static function service(): Portfolios_Service {
		return new Portfolios_Service();
	}

	public static function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Portafolios', 'atora-lms' ),
			__( 'Portafolios', 'atora-lms' ),
			'edit_posts',
			'atora-portfolios',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id    = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$portfolio_id = isset( $_GET['portfolio_id'] ) ? absint( wp_unslash( $_GET['portfolio_id'] ) ) : 0;

		$service = self::service();
		$viewer  = get_current_user_id();

		echo '<div class="wrap"><h1>' . esc_html__( 'Portafolios', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'MVP: portafolio por estudiante/curso con evidencias (submissions), reflexión y feedback.', 'atora-lms' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="atora-portfolios">';
		echo '<label><strong>' . esc_html__( 'Curso (course_id)', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="course_id" value="' . esc_attr( (string) $course_id ) . '" min="1" style="width:180px">';
		echo '<button class="button button-primary" type="submit" style="margin-left:8px">' . esc_html__( 'Cargar', 'atora-lms' ) . '</button>';
		echo '</form>';

		if ( ! $course_id ) {
			echo '<p class="description">' . esc_html__( 'Indica un course_id para ver portafolios del curso.', 'atora-lms' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! $service->viewer_can_access_course( $viewer, $course_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) );
		}

		if ( $portfolio_id ) {
			self::render_admin_portfolio_detail( $portfolio_id, $course_id, $service, $viewer );
			echo '</div>';
			return;
		}

		$rows = $service->list_course_portfolios( $course_id, 300 );
		if ( ! current_user_can( 'manage_options' ) ) {
			$rows = array_values(
				array_filter(
					(array) $rows,
					static fn( $r ) => 'private' !== sanitize_key( (string) ( is_array( $r ) ? ( $r['visibility'] ?? '' ) : '' ) )
				)
			);
		}
		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No hay portafolios aún para este curso.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width: 1200px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Estudiante', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Título', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Visibilidad', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actualizado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$pid = absint( $row['id'] ?? 0 );
			$uid = absint( $row['user_id'] ?? 0 );
			$u = $uid ? get_userdata( $uid ) : null;
			$name  = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : (string) $uid;
			$email = $u ? (string) $u->user_email : '';

			$detail_url = add_query_arg(
				array(
					'page'         => 'atora-portfolios',
					'course_id'    => $course_id,
					'portfolio_id' => $pid,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( $name ) . '</strong><br><span class="description">' . esc_html( $email ) . '</span></td>';
			echo '<td>' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( sanitize_key( (string) ( $row['visibility'] ?? '' ) ) ) . '</td>';
			echo '<td><span class="description">' . esc_html( (string) ( $row['updated_at'] ?? '' ) ) . '</span></td>';
			echo '<td><a class="button button-small" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Ver', 'atora-lms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private static function render_admin_portfolio_detail( int $portfolio_id, int $course_id, Portfolios_Service $service, int $viewer_id ): void {
		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) || absint( $portfolio['course_id'] ?? 0 ) !== absint( $course_id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) . '</p></div>';
			return;
		}

		$visibility = sanitize_key( (string) ( $portfolio['visibility'] ?? '' ) );
		$owner_id   = absint( $portfolio['user_id'] ?? 0 );
		if ( 'private' === $visibility && ! current_user_can( 'manage_options' ) && $viewer_id !== $owner_id ) {
			wp_die( esc_html__( 'Este portafolio es privado.', 'atora-lms' ) );
		}

		$user_id = absint( $portfolio['user_id'] ?? 0 );
		$u = $user_id ? get_userdata( $user_id ) : null;
		$name = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : (string) $user_id;

		echo '<hr style="margin:18px 0">';
		echo '<h2>' . esc_html__( 'Detalle de portafolio', 'atora-lms' ) . '</h2>';
		echo '<p><strong>' . esc_html( $name ) . '</strong> <span class="description">#' . esc_html( (string) $user_id ) . ' — ' . esc_html( (string) get_the_title( $course_id ) ) . '</span></p>';
		echo '<p><strong>' . esc_html__( 'Título:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $portfolio['title'] ?? '' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Visibilidad:', 'atora-lms' ) . '</strong> ' . esc_html( $visibility ) . '</p>';

		if ( class_exists( '\ZipArchive' ) ) {
			$export_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'       => 'atora_portfolio_export_zip',
						'portfolio_id' => $portfolio_id,
					),
					admin_url( 'admin-post.php' )
				),
				'atora_portfolio_export_zip_' . $portfolio_id
			);
			echo '<p style="margin:10px 0 0;"><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Exportar ZIP', 'atora-lms' ) . '</a> <span class="description">' . esc_html__( 'Incluye HTML imprimible (puedes guardar como PDF).', 'atora-lms' ) . '</span></p>';
		} else {
			echo '<p class="description" style="margin:10px 0 0;">' . esc_html__( 'Exportar ZIP no disponible (falta ext-zip en PHP).', 'atora-lms' ) . '</p>';
		}

		$items = $service->list_items( $portfolio_id );
		if ( empty( $items ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Este portafolio aún no tiene evidencias.', 'atora-lms' ) . '</p></div>';
		} else {
			echo '<h3 style="margin-top:16px">' . esc_html__( 'Evidencias', 'atora-lms' ) . '</h3>';
			echo '<table class="widefat striped" style="max-width: 1200px">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( '#', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Lección', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Reflexión', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Tags', 'atora-lms' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $items as $item ) {
				$item_id = absint( $item['id'] ?? 0 );
				$lesson_title = sanitize_text_field( (string) ( $item['lesson_title'] ?? '' ) );
				$tags = isset( $item['tags'] ) && is_array( $item['tags'] ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $item['tags'] ) ) : '';
				$reflection = sanitize_textarea_field( (string) ( $item['reflection'] ?? '' ) );

				echo '<tr>';
				echo '<td>#' . esc_html( (string) $item_id ) . '</td>';
				echo '<td>' . esc_html( $lesson_title ? $lesson_title : '—' ) . '</td>';
				echo '<td><span class="description">' . esc_html( $reflection ? $reflection : '—' ) . '</span></td>';
				echo '<td><span class="description">' . esc_html( $tags ? $tags : '—' ) . '</span></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3 style="margin-top:18px">' . esc_html__( 'Feedback', 'atora-lms' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin: 10px 0; max-width: 900px;">';
		echo '<input type="hidden" name="action" value="atora_portfolio_add_feedback">';
		echo '<input type="hidden" name="portfolio_id" value="' . esc_attr( (string) $portfolio_id ) . '">';
		wp_nonce_field( 'atora_portfolio_add_feedback_' . $portfolio_id );
		echo '<textarea name="comment" rows="3" style="width:100%" placeholder="' . esc_attr__( 'Escribe feedback para el estudiante…', 'atora-lms' ) . '"></textarea>';
		echo '<p style="margin:8px 0 0">';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Enviar feedback', 'atora-lms' ) . '</button>';
		echo '</p>';
		echo '</form>';

		$feedback = $service->list_feedback( $portfolio_id, 100 );
		if ( empty( $feedback ) ) {
			echo '<p class="description">' . esc_html__( 'No hay feedback aún.', 'atora-lms' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width: 1200px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Autor', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Comentario', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Fecha', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $feedback as $f ) {
			$author = sanitize_text_field( (string) ( $f['author_name'] ?? '' ) );
			$comment = wp_strip_all_tags( (string) ( $f['comment'] ?? '' ) );
			$date = sanitize_text_field( (string) ( $f['created_at'] ?? '' ) );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $author ? $author : '—' ) . '</strong><br><span class="description">' . esc_html( sanitize_key( (string) ( $f['author_role'] ?? '' ) ) ) . '</span></td>';
			echo '<td><span class="description">' . esc_html( $comment ? $comment : '—' ) . '</span></td>';
			echo '<td><span class="description">' . esc_html( $date ? $date : '—' ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h3 style="margin-top:18px">' . esc_html__( 'Evaluación con rúbrica', 'atora-lms' ) . '</h3>';
		self::render_admin_assessment_box( $portfolio, $service );
	}

	private static function render_admin_assessment_box( array $portfolio, Portfolios_Service $service ): void {
		$portfolio_id = absint( $portfolio['id'] ?? 0 );
		$course_id    = absint( $portfolio['course_id'] ?? 0 );
		if ( ! $portfolio_id || ! $course_id ) {
			return;
		}

		$viewer = get_current_user_id();
		if ( ! $service->viewer_can_access_course( $viewer, $course_id ) ) {
			return;
		}

		$rubric_id = isset( $_GET['rubric_id'] ) ? absint( wp_unslash( $_GET['rubric_id'] ) ) : 0;
		$final = $service->get_final_assessment( $portfolio_id );
		$final_rubric = absint( $final['rubric_id'] ?? 0 );
		if ( ! $rubric_id && $final_rubric ) {
			$rubric_id = $final_rubric;
		}

		$can_see_all = current_user_can( 'edit_others_lm_courses' ) || current_user_can( 'manage_options' );
		$rubric_args = array(
			'post_type'      => 'clms_rubric',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( ! $can_see_all ) {
			$rubric_args['author'] = $viewer;
		}
		$rubrics = get_posts( $rubric_args );

		echo '<div style="padding: 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; max-width: 1050px;">';

		if ( ! empty( $final ) ) {
			$title = $final_rubric ? (string) get_the_title( $final_rubric ) : '';
			echo '<p style="margin:0 0 10px 0;">';
			echo '<strong>' . esc_html__( 'Evaluación final:', 'atora-lms' ) . '</strong> ';
			echo esc_html( (string) absint( $final['total_percent'] ?? 0 ) ) . '/100';
			if ( $title ) {
				echo ' <span class="description">(' . esc_html( $title ) . ')</span>';
			}
			if ( ! empty( $final['assessed_by_name'] ) ) {
				echo ' <span class="description">— ' . esc_html( (string) $final['assessed_by_name'] ) . '</span>';
			}
			if ( ! empty( $final['created_at'] ) ) {
				echo ' <span class="description">— ' . esc_html( (string) $final['created_at'] ) . '</span>';
			}
			echo '</p>';
			if ( ! empty( $final['comment'] ) ) {
				echo '<blockquote style="margin:0 0 10px 0;padding:10px 12px;background:#f9fafb;border-left:4px solid #e5e7eb;">' . esc_html( (string) $final['comment'] ) . '</blockquote>';
			}
		} else {
			echo '<p class="description" style="margin:0 0 10px 0;">' . esc_html__( 'Aún no hay evaluación final.', 'atora-lms' ) . '</p>';
		}

		$base_url = add_query_arg(
			array(
				'page'         => 'atora-portfolios',
				'course_id'    => $course_id,
				'portfolio_id' => $portfolio_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:0 0 12px 0;">';
		echo '<input type="hidden" name="page" value="atora-portfolios">';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $course_id ) . '">';
		echo '<input type="hidden" name="portfolio_id" value="' . esc_attr( (string) $portfolio_id ) . '">';
		echo '<label><strong>' . esc_html__( 'Rúbrica', 'atora-lms' ) . '</strong></label><br>';
		echo '<select name="rubric_id" style="min-width: 360px; max-width: 100%;">';
		echo '<option value="0">' . esc_html__( 'Selecciona…', 'atora-lms' ) . '</option>';
		foreach ( (array) $rubrics as $r ) {
			if ( ! $r instanceof \WP_Post ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) absint( $r->ID ) ) . '" ' . selected( $rubric_id, absint( $r->ID ), false ) . '>' . esc_html( (string) $r->post_title ) . '</option>';
		}
		echo '</select>';
		echo '<button type="submit" class="button" style="margin-left:8px">' . esc_html__( 'Cargar rúbrica', 'atora-lms' ) . '</button>';
		echo '</form>';

		if ( $rubric_id && class_exists( '\CLMS_Rubric' ) ) {
			$criteria = (array) \CLMS_Rubric::get_criteria( $rubric_id );
			$max      = absint( \CLMS_Rubric::get_total_points( $rubric_id ) );
			if ( $max <= 0 ) {
				$max = 100;
			}
			$saved_scores = isset( $final['scores'] ) && is_array( $final['scores'] ) ? (array) $final['scores'] : array();

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="atora_portfolio_assess">';
			echo '<input type="hidden" name="portfolio_id" value="' . esc_attr( (string) $portfolio_id ) . '">';
			echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $course_id ) . '">';
			echo '<input type="hidden" name="rubric_id" value="' . esc_attr( (string) $rubric_id ) . '">';
			wp_nonce_field( 'atora_portfolio_assess_' . $portfolio_id );
			echo '<p class="description" style="margin:0 0 10px 0;">' . esc_html( sprintf( __( 'Califica por criterio (máx. %d pts. total). Guardar sobrescribe la evaluación final.', 'atora-lms' ), $max ) ) . '</p>';

			foreach ( $criteria as $i => $c ) {
				$name = isset( $c['name'] ) ? sanitize_text_field( (string) $c['name'] ) : '';
				$desc = isset( $c['description'] ) ? sanitize_textarea_field( (string) $c['description'] ) : '';
				$maxp = isset( $c['max_points'] ) ? absint( $c['max_points'] ) : 0;
				$key  = (string) $i;
				$val  = isset( $saved_scores[ $key ] ) && is_numeric( $saved_scores[ $key ] ) ? (int) $saved_scores[ $key ] : 0;

				echo '<div style="margin: 0 0 12px 0; padding: 12px; border: 1px solid #f3f4f6; border-radius: 10px;">';
				echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html( $name ? $name : ( __( 'Criterio', 'atora-lms' ) . ' ' . ( $i + 1 ) ) ) . '</strong> <span class="description">(' . esc_html( (string) $maxp ) . ' pts)</span></p>';
				if ( $desc ) {
					echo '<p class="description" style="margin:0 0 8px 0;">' . esc_html( $desc ) . '</p>';
				}
				echo '<input type="number" name="scores[' . esc_attr( $key ) . ']" min="0" max="' . esc_attr( (string) $maxp ) . '" step="1" value="' . esc_attr( (string) $val ) . '" style="width: 120px;">';
				echo '</div>';
			}

			echo '<p style="margin:0 0 10px 0;">';
			echo '<label><strong>' . esc_html__( 'Comentario (opcional)', 'atora-lms' ) . '</strong></label><br>';
			echo '<textarea name="comment" rows="3" style="width:100%; max-width: 760px;">' . esc_textarea( (string) ( $final['comment'] ?? '' ) ) . '</textarea>';
			echo '</p>';

			echo '<p style="margin:0;">';
			echo '<button type="submit" class="button button-primary" onclick="return confirm(\'' . esc_js( __( '¿Guardar como evaluación final?', 'atora-lms' ) ) . '\')">' . esc_html__( 'Guardar evaluación final', 'atora-lms' ) . '</button> ';
			echo '<a class="button" href="' . esc_url( $base_url ) . '">' . esc_html__( 'Recargar', 'atora-lms' ) . '</a>';
			echo '</p>';
			echo '</form>';
		}

		echo '</div>';
	}

	// ── Shortcodes ──────────────────────────────────────────────────────────

	public static function shortcode_my_portfolio( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="atora-notice">' . esc_html__( 'Debes iniciar sesión para ver tu portafolio.', 'atora-lms' ) . '</p>';
		}

		$atts = shortcode_atts(
			array(
				'course_id' => 0,
			),
			$atts
		);

		$service   = self::service();
		$user_id   = get_current_user_id();
		$course_id = absint( $atts['course_id'] );
		if ( ! $course_id && isset( $_GET['course_id'] ) ) {
			$course_id = absint( wp_unslash( $_GET['course_id'] ) );
		}

		$courses = class_exists( '\ATORA\LMS\LMS_Compatibility_Layer' )
			? (array) \ATORA\LMS\LMS_Compatibility_Layer::get_enrolled_courses( $user_id )
			: ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'get_user_enrolled_courses' ) ? (array) \CLMS_Helper::get_user_enrolled_courses( $user_id ) : array() );
		$courses = array_values( array_filter( array_map( 'absint', (array) $courses ) ) );

		if ( empty( $courses ) ) {
			return '<p class="atora-notice">' . esc_html__( 'No estás inscrito en cursos.', 'atora-lms' ) . '</p>';
		}

		if ( ! $course_id ) {
			$course_id = absint( $courses[0] ?? 0 );
		}
		if ( ! in_array( $course_id, $courses, true ) ) {
			return '<p class="atora-notice">' . esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) . '</p>';
		}

		$portfolio = $service->get_or_create_portfolio( $course_id, $user_id );
		$portfolio_id = absint( $portfolio['id'] ?? 0 );
		$items = $portfolio_id ? $service->list_items( $portfolio_id ) : array();

		$public_slug = sanitize_text_field( (string) ( $portfolio['public_slug'] ?? '' ) );
		$public_url  = $public_slug ? rest_url( 'atora/v1/portfolios/public/' . rawurlencode( $public_slug ) ) : '';

		ob_start();
		?>
		<div class="atora-portfolio" style="max-width: 980px; margin: 0 auto;">
			<h2 style="margin:0 0 12px 0;"><?php echo esc_html__( 'Mi portafolio', 'atora-lms' ); ?></h2>

			<form method="get" style="margin: 0 0 16px 0;">
				<label><strong><?php esc_html_e( 'Curso', 'atora-lms' ); ?></strong></label><br>
				<select name="course_id" style="min-width: 280px;">
					<?php foreach ( $courses as $cid ) : ?>
						<option value="<?php echo esc_attr( (string) $cid ); ?>" <?php selected( $course_id, $cid ); ?>>
							<?php echo esc_html( get_the_title( $cid ) ? get_the_title( $cid ) : ( '#' . $cid ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="atora-btn atora-btn-secondary" style="margin-left: 8px;"><?php esc_html_e( 'Cargar', 'atora-lms' ); ?></button>
			</form>

			<div style="margin:0 0 18px 0;">
				<?php if ( $portfolio_id && class_exists( '\\ZipArchive' ) ) : ?>
					<?php
					$export_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'       => 'atora_portfolio_export_zip',
								'portfolio_id' => $portfolio_id,
							),
							admin_url( 'admin-post.php' )
						),
						'atora_portfolio_export_zip_' . $portfolio_id
					);
					?>
					<a class="atora-btn atora-btn-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Exportar ZIP', 'atora-lms' ); ?></a>
					<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Incluye HTML imprimible (puedes guardar como PDF).', 'atora-lms' ); ?></span>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Exportar ZIP no disponible (falta ext-zip en PHP).', 'atora-lms' ); ?></span>
				<?php endif; ?>
			</div>

			<?php
			$assessment = $portfolio_id ? $service->get_final_assessment( $portfolio_id ) : array();
			?>
			<?php if ( ! empty( $assessment ) ) : ?>
				<div style="padding: 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; margin-bottom: 18px;">
					<h3 style="margin:0 0 10px 0;"><?php esc_html_e( 'Evaluación', 'atora-lms' ); ?></h3>
					<p style="margin:0 0 8px 0;">
						<strong><?php esc_html_e( 'Nota final:', 'atora-lms' ); ?></strong>
						<?php echo esc_html( (string) absint( $assessment['total_percent'] ?? 0 ) ); ?>/100
						<?php
						$ar = absint( $assessment['rubric_id'] ?? 0 );
						$title = $ar ? (string) get_the_title( $ar ) : '';
						if ( $title ) :
							?>
							<span class="description">(<?php echo esc_html( $title ); ?>)</span>
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $assessment['comment'] ) ) : ?>
						<blockquote style="margin:0;padding:10px 12px;background:#f9fafb;border-left:4px solid #e5e7eb;"><?php echo esc_html( (string) $assessment['comment'] ); ?></blockquote>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div style="padding: 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; margin-bottom: 18px;">
				<h3 style="margin:0 0 10px 0;"><?php esc_html_e( 'Configuración', 'atora-lms' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="atora_portfolio_save_settings">
					<input type="hidden" name="portfolio_id" value="<?php echo esc_attr( (string) $portfolio_id ); ?>">
					<?php wp_nonce_field( 'atora_portfolio_save_settings_' . $portfolio_id ); ?>
					<p style="margin:0 0 10px 0;">
						<label><strong><?php esc_html_e( 'Título', 'atora-lms' ); ?></strong></label><br>
						<input type="text" name="title" value="<?php echo esc_attr( (string) ( $portfolio['title'] ?? '' ) ); ?>" style="width: 100%; max-width: 520px;">
					</p>
					<p style="margin:0 0 10px 0;">
						<label><strong><?php esc_html_e( 'Visibilidad', 'atora-lms' ); ?></strong></label><br>
						<select name="visibility" style="min-width: 240px;">
							<?php
							$vis = sanitize_key( (string) ( $portfolio['visibility'] ?? 'teachers' ) );
							foreach ( array( 'private' => __( 'Privado', 'atora-lms' ), 'teachers' => __( 'Docentes', 'atora-lms' ), 'public' => __( 'Público', 'atora-lms' ) ) as $key => $label ) :
								?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $vis, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description" style="display:block;margin-top:6px;">
							<?php esc_html_e( 'Si es “Público”, se genera un link de lectura (solo lectura).', 'atora-lms' ); ?>
						</span>
						<?php if ( $public_url ) : ?>
							<span class="description" style="display:block;margin-top:6px;">
								<?php esc_html_e( 'Link público (REST):', 'atora-lms' ); ?>
								<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $public_url ); ?></a>
							</span>
						<?php endif; ?>
					</p>
					<p style="margin:0;">
						<button type="submit" class="atora-btn atora-btn-primary"><?php esc_html_e( 'Guardar', 'atora-lms' ); ?></button>
					</p>
				</form>
			</div>

			<div style="padding: 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; margin-bottom: 18px;">
				<h3 style="margin:0 0 10px 0;"><?php esc_html_e( 'Evidencias', 'atora-lms' ); ?></h3>

				<?php if ( empty( $items ) ) : ?>
					<p class="description" style="margin:0 0 12px 0;"><?php esc_html_e( 'Aún no has agregado evidencias. Agrega tus entregas (submissions) de este curso.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px 0;">
						<input type="hidden" name="action" value="atora_portfolio_reorder">
						<input type="hidden" name="portfolio_id" value="<?php echo esc_attr( (string) $portfolio_id ); ?>">
						<?php wp_nonce_field( 'atora_portfolio_reorder_' . $portfolio_id ); ?>
						<p class="description" style="margin:0 0 8px 0;"><?php esc_html_e( 'Orden: arrastra no disponible en MVP; usa números y guarda.', 'atora-lms' ); ?></p>
						<table style="width:100%; border-collapse: collapse; max-width: 760px;">
							<thead>
								<tr>
									<th style="text-align:left; padding: 6px 0;"><?php esc_html_e( 'Orden', 'atora-lms' ); ?></th>
									<th style="text-align:left; padding: 6px 0;"><?php esc_html_e( 'Evidencia', 'atora-lms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $items as $item ) : ?>
									<?php $item_id = absint( $item['id'] ?? 0 ); ?>
									<tr style="border-top: 1px solid #f3f4f6;">
										<td style="padding: 10px 0; width: 90px;">
											<input type="number" name="positions[<?php echo esc_attr( (string) $item_id ); ?>]" min="1" step="1" value="<?php echo esc_attr( (string) (int) ( $item['position'] ?? 0 ) ); ?>" style="width: 70px;">
										</td>
										<td style="padding: 10px 0;">
											<strong><?php echo esc_html( (string) ( $item['lesson_title'] ?? '' ) ); ?></strong>
											<br><span class="description">submission #<?php echo esc_html( (string) absint( $item['submission_id'] ?? 0 ) ); ?></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p style="margin: 10px 0 0;">
							<button type="submit" class="atora-btn atora-btn-secondary"><?php esc_html_e( 'Guardar orden', 'atora-lms' ); ?></button>
						</p>
					</form>

					<table style="width:100%; border-collapse: collapse;">
						<thead>
							<tr>
								<th style="text-align:left; padding: 6px 0;"><?php esc_html_e( 'Lección', 'atora-lms' ); ?></th>
								<th style="text-align:left; padding: 6px 0;"><?php esc_html_e( 'Reflexión y tags', 'atora-lms' ); ?></th>
								<th style="text-align:left; padding: 6px 0;"><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $item ) : ?>
								<?php $item_id = absint( $item['id'] ?? 0 ); ?>
								<tr style="border-top: 1px solid #f3f4f6;">
									<td style="padding: 10px 0; width: 280px;">
										<strong><?php echo esc_html( (string) ( $item['lesson_title'] ?? '' ) ); ?></strong>
										<br><span class="description">submission #<?php echo esc_html( (string) absint( $item['submission_id'] ?? 0 ) ); ?></span>
									</td>
									<td style="padding: 10px 0;">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="atora_portfolio_update_item">
											<input type="hidden" name="portfolio_id" value="<?php echo esc_attr( (string) $portfolio_id ); ?>">
											<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $item_id ); ?>">
											<?php wp_nonce_field( 'atora_portfolio_update_item_' . $item_id ); ?>
											<textarea name="reflection" rows="2" style="width: 100%; max-width: 520px;"><?php echo esc_textarea( (string) ( $item['reflection'] ?? '' ) ); ?></textarea>
											<input type="text" name="tags" value="<?php echo esc_attr( isset( $item['tags'] ) && is_array( $item['tags'] ) ? implode( ',', (array) $item['tags'] ) : '' ); ?>" style="width: 100%; max-width: 520px; margin-top:6px;" placeholder="<?php echo esc_attr__( 'tags (separados por coma)', 'atora-lms' ); ?>">
											<p style="margin:6px 0 0;">
												<button type="submit" class="atora-btn atora-btn-secondary"><?php esc_html_e( 'Guardar', 'atora-lms' ); ?></button>
											</p>
										</form>
									</td>
									<td style="padding: 10px 0; width: 140px;">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar evidencia del portafolio?', 'atora-lms' ) ); ?>');">
											<input type="hidden" name="action" value="atora_portfolio_delete_item">
											<input type="hidden" name="portfolio_id" value="<?php echo esc_attr( (string) $portfolio_id ); ?>">
											<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $item_id ); ?>">
											<?php wp_nonce_field( 'atora_portfolio_delete_item_' . $item_id ); ?>
											<button type="submit" class="atora-btn atora-btn-secondary"><?php esc_html_e( 'Eliminar', 'atora-lms' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<hr style="margin: 16px 0;">

				<h4 style="margin:0 0 10px 0;"><?php esc_html_e( 'Agregar entregas del curso', 'atora-lms' ); ?></h4>
				<?php
				$submissions = get_posts(
					array(
						'post_type'      => class_exists( '\CLMS_Submission' ) ? \CLMS_Submission::CPT : 'clms_submission',
						'post_status'    => array( 'publish', 'private' ),
						'posts_per_page' => 20,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'orderby'        => 'date',
						'order'          => 'DESC',
						'meta_query'     => array(
							array( 'key' => '_clms_submission_course_id', 'value' => $course_id, 'type' => 'NUMERIC' ),
							array( 'key' => '_clms_submission_user_id', 'value' => $user_id, 'type' => 'NUMERIC' ),
						),
					)
				);
				?>
				<?php if ( empty( $submissions ) ) : ?>
					<p class="description" style="margin:0;"><?php esc_html_e( 'No hay submissions recientes para este curso.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<ul style="margin:0 0 0 18px;">
						<?php foreach ( $submissions as $submission_id ) : ?>
							<?php
							$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
							$lesson_title = $lesson_id ? (string) get_the_title( $lesson_id ) : '';
							?>
							<li style="margin: 8px 0;">
								<strong><?php echo esc_html( $lesson_title ? $lesson_title : __( 'Entrega', 'atora-lms' ) ); ?></strong>
								<span class="description">#<?php echo esc_html( (string) absint( $submission_id ) ); ?></span>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left: 8px;">
									<input type="hidden" name="action" value="atora_portfolio_add_item">
									<input type="hidden" name="portfolio_id" value="<?php echo esc_attr( (string) $portfolio_id ); ?>">
									<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) absint( $submission_id ) ); ?>">
									<?php wp_nonce_field( 'atora_portfolio_add_item_' . $portfolio_id ); ?>
									<button type="submit" class="atora-btn atora-btn-secondary"><?php esc_html_e( 'Agregar', 'atora-lms' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div style="padding: 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff;">
				<h3 style="margin:0 0 10px 0;"><?php esc_html_e( 'Feedback recibido', 'atora-lms' ); ?></h3>
				<?php
				$feedback = $portfolio_id ? $service->list_feedback( $portfolio_id, 50 ) : array();
				?>
				<?php if ( empty( $feedback ) ) : ?>
					<p class="description" style="margin:0;"><?php esc_html_e( 'Aún no hay feedback.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<ul style="margin:0 0 0 18px;">
						<?php foreach ( $feedback as $f ) : ?>
							<li style="margin: 10px 0;">
								<strong><?php echo esc_html( (string) ( $f['author_name'] ?? '' ) ); ?></strong>
								<span class="description"><?php echo esc_html( sanitize_key( (string) ( $f['author_role'] ?? '' ) ) ); ?> · <?php echo esc_html( (string) ( $f['created_at'] ?? '' ) ); ?></span><br>
								<span><?php echo esc_html( wp_strip_all_tags( (string) ( $f['comment'] ?? '' ) ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function shortcode_public_portfolio( $atts ): string {
		$atts = shortcode_atts(
			array(
				'slug' => '',
			),
			$atts
		);

		$slug = sanitize_text_field( (string) $atts['slug'] );
		if ( '' === $slug && isset( $_GET['slug'] ) ) {
			$slug = sanitize_text_field( (string) wp_unslash( $_GET['slug'] ) );
		}
		if ( '' === $slug ) {
			return '<p class="atora-notice">' . esc_html__( 'Falta el slug del portafolio.', 'atora-lms' ) . '</p>';
		}

		$service = self::service();
		$portfolio = $service->get_portfolio_by_public_slug( $slug );
		if ( empty( $portfolio ) ) {
			return '<p class="atora-notice">' . esc_html__( 'Portafolio no disponible.', 'atora-lms' ) . '</p>';
		}

		$items = $service->list_items( absint( $portfolio['id'] ?? 0 ) );
		ob_start();
		?>
		<div class="atora-portfolio-public" style="max-width: 980px; margin: 0 auto;">
			<h2 style="margin:0 0 10px 0;"><?php echo esc_html( (string) ( $portfolio['title'] ?? __( 'Portafolio', 'atora-lms' ) ) ); ?></h2>
			<p class="description" style="margin:0 0 14px 0;">
				<?php echo esc_html( (string) get_the_title( absint( $portfolio['course_id'] ?? 0 ) ) ); ?>
			</p>

			<?php if ( empty( $items ) ) : ?>
				<p class="description"><?php esc_html_e( 'No hay evidencias.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<ol style="padding-left: 18px;">
					<?php foreach ( $items as $item ) : ?>
						<li style="margin: 12px 0;">
							<strong><?php echo esc_html( (string) ( $item['lesson_title'] ?? '' ) ); ?></strong>
							<?php if ( ! empty( $item['reflection'] ) ) : ?>
								<br><span class="description"><?php echo esc_html( (string) $item['reflection'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ── Admin-post handlers (shortcode forms) ────────────────────────────────

	public static function handle_save_settings(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$portfolio_id = isset( $_POST['portfolio_id'] ) ? absint( wp_unslash( $_POST['portfolio_id'] ) ) : 0;
		check_admin_referer( 'atora_portfolio_save_settings_' . $portfolio_id );

		$service   = self::service();
		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$user_id = get_current_user_id();
		if ( absint( $portfolio['user_id'] ?? 0 ) !== $user_id ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$service->update_portfolio_settings(
			$portfolio_id,
			array(
				'title'      => isset( $_POST['title'] ) ? (string) wp_unslash( $_POST['title'] ) : '',
				'visibility' => isset( $_POST['visibility'] ) ? (string) wp_unslash( $_POST['visibility'] ) : '',
			)
		);

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	public static function handle_add_item(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$portfolio_id  = isset( $_POST['portfolio_id'] ) ? absint( wp_unslash( $_POST['portfolio_id'] ) ) : 0;
		$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		check_admin_referer( 'atora_portfolio_add_item_' . $portfolio_id );

		$service   = self::service();
		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$user_id = get_current_user_id();
		if ( absint( $portfolio['user_id'] ?? 0 ) !== $user_id ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		// Ensure the submission belongs to the same course + user.
		$course_id = absint( $portfolio['course_id'] ?? 0 );
		$sub_course = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		$sub_user   = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( ! $submission_id || $sub_course !== $course_id || $sub_user !== $user_id ) {
			wp_die( esc_html__( 'Submission inválido para este portafolio.', 'atora-lms' ) );
		}

		$service->add_item( $portfolio_id, $submission_id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	public static function handle_update_item(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$item_id      = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;
		$portfolio_id = isset( $_POST['portfolio_id'] ) ? absint( wp_unslash( $_POST['portfolio_id'] ) ) : 0;
		check_admin_referer( 'atora_portfolio_update_item_' . $item_id );

		$service = self::service();
		$item = $service->get_item( $item_id );
		if ( empty( $item ) || absint( $item['portfolio_id'] ?? 0 ) !== $portfolio_id ) {
			wp_die( esc_html__( 'Item no encontrado.', 'atora-lms' ) );
		}

		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$user_id = get_current_user_id();
		if ( absint( $portfolio['user_id'] ?? 0 ) !== $user_id ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$tags_raw = isset( $_POST['tags'] ) ? (string) wp_unslash( $_POST['tags'] ) : '';
		$tags = '' !== trim( $tags_raw ) ? preg_split( '/\s*,\s*/', trim( $tags_raw ) ) : array();

		$service->update_item(
			$item_id,
			array(
				'reflection' => isset( $_POST['reflection'] ) ? (string) wp_unslash( $_POST['reflection'] ) : '',
				'tags'       => is_array( $tags ) ? $tags : array(),
			)
		);

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	public static function handle_delete_item(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$item_id      = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;
		$portfolio_id = isset( $_POST['portfolio_id'] ) ? absint( wp_unslash( $_POST['portfolio_id'] ) ) : 0;
		check_admin_referer( 'atora_portfolio_delete_item_' . $item_id );

		$service = self::service();
		$item = $service->get_item( $item_id );
		if ( empty( $item ) || absint( $item['portfolio_id'] ?? 0 ) !== $portfolio_id ) {
			wp_die( esc_html__( 'Item no encontrado.', 'atora-lms' ) );
		}

		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$user_id = get_current_user_id();
		if ( absint( $portfolio['user_id'] ?? 0 ) !== $user_id ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$service->delete_item( $item_id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	public static function handle_reorder(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$portfolio_id = isset( $_POST['portfolio_id'] ) ? absint( wp_unslash( $_POST['portfolio_id'] ) ) : 0;
		check_admin_referer( 'atora_portfolio_reorder_' . $portfolio_id );

		$service   = self::service();
		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$user_id = get_current_user_id();
		if ( absint( $portfolio['user_id'] ?? 0 ) !== $user_id ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$positions = isset( $_POST['positions'] ) && is_array( $_POST['positions'] ) ? (array) wp_unslash( $_POST['positions'] ) : array();
		$map = array();
		foreach ( $positions as $item_id => $pos ) {
			$item_id = absint( $item_id );
			if ( ! $item_id ) {
				continue;
			}
			$map[ $item_id ] = max( 1, (int) $pos );
		}

		asort( $map );
		$item_ids = array_keys( $map );
		$service->reorder_items( $portfolio_id, $item_ids );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	public static function handle_add_feedback(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$portfolio_id = isset( $_POST['portfolio_id'] ) ? absint( wp_unslash( $_POST['portfolio_id'] ) ) : 0;
		check_admin_referer( 'atora_portfolio_add_feedback_' . $portfolio_id );

		$service = self::service();
		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$viewer = get_current_user_id();
		$course_id = absint( $portfolio['course_id'] ?? 0 );
		if ( ! $service->viewer_can_access_course( $viewer, $course_id ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		if ( ! current_user_can( 'manage_options' ) && 'private' === sanitize_key( (string) ( $portfolio['visibility'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Este portafolio es privado.', 'atora-lms' ) );
		}

		$comment = isset( $_POST['comment'] ) ? (string) wp_unslash( $_POST['comment'] ) : '';
		$service->add_feedback( $portfolio_id, $viewer, $comment, null, 'teacher' );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=atora-portfolios&course_id=' . $course_id . '&portfolio_id=' . $portfolio_id ) );
		exit;
	}

	public static function handle_assess(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$portfolio_id = isset( $_POST['portfolio_id'] ) ? absint( wp_unslash( $_POST['portfolio_id'] ) ) : 0;
		check_admin_referer( 'atora_portfolio_assess_' . $portfolio_id );

		$service = self::service();
		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$viewer   = get_current_user_id();
		$course_id = absint( $portfolio['course_id'] ?? 0 );
		if ( ! $service->viewer_can_access_course( $viewer, $course_id ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$rubric_id = isset( $_POST['rubric_id'] ) ? absint( wp_unslash( $_POST['rubric_id'] ) ) : 0;
		$scores    = isset( $_POST['scores'] ) && is_array( $_POST['scores'] ) ? (array) wp_unslash( $_POST['scores'] ) : array();
		$comment   = isset( $_POST['comment'] ) ? (string) wp_unslash( $_POST['comment'] ) : '';

		$service->save_final_assessment( $portfolio_id, $rubric_id, $scores, $comment, $viewer );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=atora-portfolios&course_id=' . $course_id . '&portfolio_id=' . $portfolio_id ) );
		exit;
	}

	// ── REST ────────────────────────────────────────────────────────────────

	public static function register_rest_routes(): void {
		register_rest_route(
			'atora/v1',
			'/portfolios/my',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_my_portfolios' ),
					'permission_callback' => static fn() => is_user_logged_in(),
					'args'                => array(
						'course_id' => array( 'type' => 'integer', 'required' => false ),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/portfolios/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get_portfolio' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view_portfolio' ),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/portfolios/(?P<id>\\d+)/items',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_add_item' ),
					'permission_callback' => array( __CLASS__, 'rest_can_manage_portfolio' ),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/portfolios/(?P<id>\\d+)/items/(?P<item_id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'rest_update_item' ),
					'permission_callback' => array( __CLASS__, 'rest_can_manage_portfolio' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'rest_delete_item' ),
					'permission_callback' => array( __CLASS__, 'rest_can_manage_portfolio' ),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/portfolios/(?P<id>\\d+)/feedback',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_add_feedback' ),
					'permission_callback' => array( __CLASS__, 'rest_can_comment_portfolio' ),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/portfolios/(?P<id>\\d+)/assessment',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_save_assessment' ),
					'permission_callback' => array( __CLASS__, 'rest_can_comment_portfolio' ),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/portfolios/public/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_public_portfolio' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public static function rest_my_portfolios( WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		$service = self::service();
		$user_id = get_current_user_id();
		$rows = $service->list_my_portfolios( $user_id, $course_id );
		return rest_ensure_response( array( 'portfolios' => $rows ) );
	}

	public static function rest_can_view_portfolio( WP_REST_Request $r ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$service = self::service();
		$portfolio = $service->get_portfolio( absint( $r['id'] ) );
		if ( empty( $portfolio ) ) {
			return false;
		}

		$viewer = get_current_user_id();
		if ( absint( $portfolio['user_id'] ?? 0 ) === $viewer ) {
			return true;
		}

		if ( ! $service->viewer_can_access_course( $viewer, absint( $portfolio['course_id'] ?? 0 ) ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return 'private' !== sanitize_key( (string) ( $portfolio['visibility'] ?? '' ) );
	}

	public static function rest_can_manage_portfolio( WP_REST_Request $r ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$service = self::service();
		$portfolio = $service->get_portfolio( absint( $r['id'] ) );
		if ( empty( $portfolio ) ) {
			return false;
		}

		return absint( $portfolio['user_id'] ?? 0 ) === get_current_user_id();
	}

	public static function rest_can_comment_portfolio( WP_REST_Request $r ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$service = self::service();
		$portfolio = $service->get_portfolio( absint( $r['id'] ) );
		if ( empty( $portfolio ) ) {
			return false;
		}
		if ( ! $service->viewer_can_access_course( get_current_user_id(), absint( $portfolio['course_id'] ?? 0 ) ) ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return 'private' !== sanitize_key( (string) ( $portfolio['visibility'] ?? '' ) );
	}

	public static function rest_get_portfolio( WP_REST_Request $r ) {
		$service = self::service();
		$portfolio = $service->get_portfolio( absint( $r['id'] ) );
		if ( empty( $portfolio ) ) {
			return new \WP_Error( 'not_found', __( 'Portafolio no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$items = $service->list_items( absint( $portfolio['id'] ?? 0 ) );
		$feedback = $service->list_feedback( absint( $portfolio['id'] ?? 0 ), 200 );

		return rest_ensure_response(
			array(
				'portfolio' => $portfolio,
				'items'     => $items,
				'feedback'  => $feedback,
			)
		);
	}

	public static function rest_add_item( WP_REST_Request $r ) {
		$service = self::service();
		$portfolio = $service->get_portfolio( absint( $r['id'] ) );
		if ( empty( $portfolio ) ) {
			return new \WP_Error( 'not_found', __( 'Portafolio no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$data = $r->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$submission_id = absint( $data['submission_id'] ?? 0 );
		if ( ! $submission_id ) {
			return new \WP_Error( 'missing_submission', __( 'submission_id requerido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$user_id  = absint( $portfolio['user_id'] ?? 0 );
		$course_id = absint( $portfolio['course_id'] ?? 0 );
		$sub_course = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		$sub_user   = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( $sub_course !== $course_id || $sub_user !== $user_id ) {
			return new \WP_Error( 'invalid_submission', __( 'Submission no pertenece a este curso/usuario.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$item = $service->add_item( absint( $portfolio['id'] ), $submission_id, $data );
		return rest_ensure_response( array( 'item' => $item ) );
	}

	public static function rest_update_item( WP_REST_Request $r ) {
		$service = self::service();
		$item_id = absint( $r['item_id'] );
		$portfolio_id = absint( $r['id'] );

		$item = $service->get_item( $item_id );
		if ( empty( $item ) || absint( $item['portfolio_id'] ?? 0 ) !== $portfolio_id ) {
			return new \WP_Error( 'not_found', __( 'Item no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$data = $r->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$ok = $service->update_item( $item_id, $data );
		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}

	public static function rest_delete_item( WP_REST_Request $r ) {
		$service = self::service();
		$item_id = absint( $r['item_id'] );
		$portfolio_id = absint( $r['id'] );

		$item = $service->get_item( $item_id );
		if ( empty( $item ) || absint( $item['portfolio_id'] ?? 0 ) !== $portfolio_id ) {
			return new \WP_Error( 'not_found', __( 'Item no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$ok = $service->delete_item( $item_id );
		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}

	public static function rest_add_feedback( WP_REST_Request $r ) {
		$service = self::service();
		$portfolio = $service->get_portfolio( absint( $r['id'] ) );
		if ( empty( $portfolio ) ) {
			return new \WP_Error( 'not_found', __( 'Portafolio no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$data = $r->get_json_params();
		$data = is_array( $data ) ? $data : array();
		$comment = isset( $data['comment'] ) ? (string) $data['comment'] : '';
		if ( '' === trim( $comment ) ) {
			return new \WP_Error( 'missing_comment', __( 'comment requerido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$feedback = $service->add_feedback(
			absint( $portfolio['id'] ),
			get_current_user_id(),
			$comment,
			isset( $data['item_id'] ) ? absint( $data['item_id'] ) : null,
			'teacher'
		);

		return rest_ensure_response( array( 'feedback' => $feedback ) );
	}

	public static function rest_save_assessment( WP_REST_Request $r ) {
		$service = self::service();
		$portfolio = $service->get_portfolio( absint( $r['id'] ) );
		if ( empty( $portfolio ) ) {
			return new \WP_Error( 'not_found', __( 'Portafolio no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_grade_submissions' ) ) {
			return new \WP_Error( 'forbidden', __( 'No tienes permisos para evaluar.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data = $r->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$rubric_id = absint( $data['rubric_id'] ?? 0 );
		$scores    = isset( $data['scores'] ) && is_array( $data['scores'] ) ? (array) $data['scores'] : array();
		$comment   = isset( $data['comment'] ) ? (string) $data['comment'] : '';

		$assessment = $service->save_final_assessment(
			absint( $portfolio['id'] ),
			$rubric_id,
			$scores,
			$comment,
			get_current_user_id()
		);

		if ( empty( $assessment ) ) {
			return new \WP_Error( 'save_failed', __( 'No se pudo guardar la evaluación.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'assessment' => $assessment ) );
	}

	public static function rest_public_portfolio( WP_REST_Request $r ) {
		$slug = sanitize_text_field( (string) $r['slug'] );
		$service = self::service();
		$portfolio = $service->get_portfolio_by_public_slug( $slug );
		if ( empty( $portfolio ) ) {
			return new \WP_Error( 'not_found', __( 'Portafolio no disponible.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$items = $service->list_items( absint( $portfolio['id'] ?? 0 ) );
		return rest_ensure_response(
			array(
				'portfolio' => array(
					'id'        => absint( $portfolio['id'] ?? 0 ),
					'course_id' => absint( $portfolio['course_id'] ?? 0 ),
					'title'     => sanitize_text_field( (string) ( $portfolio['title'] ?? '' ) ),
				),
				'items'     => array_map(
					static function( $item ) {
						return array(
							'lesson_title' => sanitize_text_field( (string) ( $item['lesson_title'] ?? '' ) ),
							'reflection'   => sanitize_textarea_field( (string) ( $item['reflection'] ?? '' ) ),
							'tags'         => isset( $item['tags'] ) && is_array( $item['tags'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', (array) $item['tags'] ) ) ) : array(),
						);
					},
					(array) $items
				),
			)
		);
	}

	// ── Export ─────────────────────────────────────────────────────────────

	public static function handle_export_zip(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$portfolio_id = isset( $_REQUEST['portfolio_id'] ) ? absint( wp_unslash( $_REQUEST['portfolio_id'] ) ) : 0;
		if ( ! $portfolio_id ) {
			wp_die( esc_html__( 'portfolio_id requerido.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_portfolio_export_zip_' . $portfolio_id );

		if ( ! class_exists( '\ZipArchive' ) ) {
			wp_die( esc_html__( 'Exportar ZIP no está disponible: falta ext-zip en PHP. Habilita ZipArchive en el contenedor/servidor.', 'atora-lms' ) );
		}

		$service   = self::service();
		$portfolio = $service->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			wp_die( esc_html__( 'Portafolio no encontrado.', 'atora-lms' ) );
		}

		$viewer_id  = get_current_user_id();
		$owner_id   = absint( $portfolio['user_id'] ?? 0 );
		$course_id  = absint( $portfolio['course_id'] ?? 0 );
		$visibility = sanitize_key( (string) ( $portfolio['visibility'] ?? '' ) );

		if ( $viewer_id !== $owner_id ) {
			if ( ! $service->viewer_can_access_course( $viewer_id, $course_id ) ) {
				wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) );
			}
			if ( 'private' === $visibility && ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Este portafolio es privado.', 'atora-lms' ) );
			}
		}

		$zip = $service->export_portfolio_zip( $portfolio_id );
		$filename = isset( $zip['filename'] ) ? (string) $zip['filename'] : '';
		$content  = isset( $zip['content'] ) ? $zip['content'] : null;

		if ( '' === $filename || ! is_string( $content ) || '' === $content ) {
			wp_die( esc_html__( 'No se pudo generar el ZIP.', 'atora-lms' ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) strlen( $content ) );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
