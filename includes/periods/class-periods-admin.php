<?php
/**
 * Admin: Períodos (base).
 *
 * Página bajo `clms-dashboard` para:
 * - crear períodos,
 * - asignar cursos,
 * - cerrar período con visibilidad para cursos pasados.
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

use ATORA\LMS\Tenant_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Periods_Admin {
	private const NONCE_ACTION = 'atora_periods_admin';

	public static function init(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Períodos', 'atora-lms' ),
			__( 'Períodos', 'atora-lms' ),
			ATORA_Periods_Service::CAP_CLOSE_PERIODS,
			'atora-periods',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		$actor_id = get_current_user_id();
		if ( ! user_can( $actor_id, ATORA_Periods_Service::CAP_CLOSE_PERIODS ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		$inst = class_exists( Tenant_Context::class ) ? Tenant_Context::require_current_institution_id() : 0;
		$institution_id = is_wp_error( $inst ) ? 0 : absint( $inst );
		if ( $institution_id <= 0 ) {
			wp_die( esc_html__( 'No se pudo resolver la institución.', 'atora-lms' ) );
		}

		$notice = '';
		$type   = 'info';

		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( self::NONCE_ACTION );
			$action = sanitize_key( (string) ( $_POST['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'create' === $action ) {
				$res = ATORA_Periods_Service::create_period(
					$actor_id,
					$institution_id,
					(string) ( $_POST['name'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
					(string) ( $_POST['starts_on'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
					(string) ( $_POST['ends_on'] ?? '' ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				);
				if ( is_wp_error( $res ) ) {
					$notice = $res->get_error_message();
					$type = 'error';
				} else {
					$notice = __( 'Período creado.', 'atora-lms' );
				}
			} elseif ( 'assign' === $action ) {
				$period_id = absint( $_POST['period_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$wp_course_id = absint( $_POST['wp_course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$res = ATORA_Periods_Service::assign_course( $actor_id, $period_id, $wp_course_id );
				if ( is_wp_error( $res ) ) {
					$notice = $res->get_error_message();
					$type = 'error';
				} else {
					$notice = __( 'Curso asignado.', 'atora-lms' );
				}
			} elseif ( 'close' === $action ) {
				$period_id = absint( $_POST['period_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$vis = (string) ( $_POST['past_visibility'] ?? 'visible' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$until = (string) ( $_POST['visible_until'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$res = ATORA_Periods_Service::close_period( $actor_id, $period_id, $vis, $until );
				if ( is_wp_error( $res ) ) {
					$notice = $res->get_error_message();
					$type = 'error';
				} else {
					$notice = __( 'Período cerrado.', 'atora-lms' );
				}
			}
		}

		$periods = ATORA_Periods_Service::list_periods( $institution_id );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Períodos', 'atora-lms' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Crear período', 'atora-lms' ); ?></h2>
			<form method="post" style="max-width:720px">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="create" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="atora-period-name"><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></label></th>
						<td><input id="atora-period-name" type="text" name="name" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="atora-period-start"><?php esc_html_e( 'Inicio', 'atora-lms' ); ?></label></th>
						<td><input id="atora-period-start" type="date" name="starts_on"></td>
					</tr>
					<tr>
						<th scope="row"><label for="atora-period-end"><?php esc_html_e( 'Fin', 'atora-lms' ); ?></label></th>
						<td><input id="atora-period-end" type="date" name="ends_on"></td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Crear', 'atora-lms' ); ?></button></p>
			</form>

			<h2 style="margin-top:28px"><?php esc_html_e( 'Períodos existentes', 'atora-lms' ); ?></h2>
			<?php if ( ! $periods ) : ?>
				<p><?php esc_html_e( 'Aún no hay períodos.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Inicio', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Fin', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $periods as $p ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) ( $p['name'] ?? '' ) ); ?></strong></td>
							<td><?php echo esc_html( (string) ( $p['starts_on'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $p['ends_on'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $p['status'] ?? '' ) ); ?></td>
							<td>
								<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="period_id" value="<?php echo (int) $p['id']; ?>" />
									<div>
										<label style="display:block;font-size:12px;font-weight:600"><?php esc_html_e( 'Asignar curso (WP ID)', 'atora-lms' ); ?></label>
										<input type="number" name="wp_course_id" min="1" style="width:120px" />
										<button class="button" name="action" value="assign"><?php esc_html_e( 'Asignar', 'atora-lms' ); ?></button>
									</div>
									<?php if ( 'open' === (string) ( $p['status'] ?? '' ) ) : ?>
										<div>
											<label style="display:block;font-size:12px;font-weight:600"><?php esc_html_e( 'Visibilidad al cerrar', 'atora-lms' ); ?></label>
											<select name="past_visibility">
												<option value="visible"><?php esc_html_e( 'Visible', 'atora-lms' ); ?></option>
												<option value="hidden"><?php esc_html_e( 'Oculto', 'atora-lms' ); ?></option>
												<option value="until"><?php esc_html_e( 'Visible hasta…', 'atora-lms' ); ?></option>
											</select>
											<input type="date" name="visible_until" />
											<button class="button button-primary" name="action" value="close"><?php esc_html_e( 'Cerrar', 'atora-lms' ); ?></button>
										</div>
									<?php else : ?>
										<span style="color:#64748b"><?php esc_html_e( 'Cerrado', 'atora-lms' ); ?></span>
									<?php endif; ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

