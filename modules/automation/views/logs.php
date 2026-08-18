<?php
/**
 * Automation queue/logs tab.
 *
 * @package ATORA_LMS\Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows_to_render = $logs_rows;
$title = __( 'Logs de automatización', 'atora-lms' );
if ( 'queue' === $tab ) {
	$title = __( 'Cola de automatización', 'atora-lms' );
}
if ( 'errors' === $tab ) {
	$title = __( 'Errores de automatización', 'atora-lms' );
	$rows_to_render = $errors_rows;
}
?>
<h2><?php echo esc_html( $title ); ?></h2>
<?php if ( empty( $rows_to_render ) ) : ?>
	<p><?php esc_html_e( 'Sin registros para este filtro.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>#</th>
				<th><?php esc_html_e( 'Automation ID', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Usuario', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Reintentos', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Execute at', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Executed at', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows_to_render as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) absint( $row->id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) absint( $row->automation_id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) absint( $row->user_id ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_key( $row->status ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) absint( $row->retry_count ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_text_field( $row->execute_at ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) sanitize_text_field( $row->executed_at ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
