<?php
/**
 * Tab: Invitaciones.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'clms_invitations';
$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
$rows = array();

if ( $exists ) {
	$rows = (array) $wpdb->get_results(
		"SELECT id, invited_email, course_id, program_id, status, created_at, expires_at, used_count, max_uses
		 FROM {$table}
		 WHERE access_mode = 'invite'
		 ORDER BY id DESC
		 LIMIT 50"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
?>
<h2><?php esc_html_e( 'Invitaciones y reenvío', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Últimas 50 invitaciones generadas. Puedes reenviar desde aquí.', 'atora-lms' ); ?></p>

<?php if ( ! $exists ) : ?>
	<p><?php esc_html_e( 'Tabla de invitaciones no encontrada.', 'atora-lms' ); ?></p>
<?php elseif ( empty( $rows ) ) : ?>
	<p><?php esc_html_e( 'No hay invitaciones registradas.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Email', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Recurso', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Uso', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$program_id   = absint( $row->program_id ?? 0 );
				$course_id    = absint( $row->course_id ?? 0 );
				$is_program   = $program_id > 0 && 'lm_program' === get_post_type( $program_id );
				$target_id    = $is_program ? $program_id : $course_id;
				$target_label = $is_program ? __( 'Programa', 'atora-lms' ) : __( 'Curso', 'atora-lms' );
				$target_title = (string) get_the_title( $target_id );
				?>
				<tr>
					<td><?php echo esc_html( (string) absint( $row->id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_email( $row->invited_email ?? '' ) ); ?></td>
					<td><?php echo esc_html( $target_label ); ?></td>
					<td><?php echo esc_html( $target_title ); ?></td>
					<td><?php echo esc_html( (string) sanitize_key( $row->status ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) absint( $row->used_count ?? 0 ) . '/' . (string) absint( $row->max_uses ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_text_field( $row->created_at ?? '' ) ); ?></td>
					<td>
						<form method="post" style="margin:0;">
							<?php wp_nonce_field( 'atora_email_admin_action', 'atora_email_nonce' ); ?>
							<input type="hidden" name="atora_email_action" value="resend_invitation">
							<input type="hidden" name="invitation_id" value="<?php echo esc_attr( (string) absint( $row->id ?? 0 ) ); ?>">
							<button type="submit" class="button button-small"><?php esc_html_e( 'Reenviar', 'atora-lms' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
