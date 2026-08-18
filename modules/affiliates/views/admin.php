<?php
/**
 * Vista admin del módulo de afiliados.
 *
 * @package ATORA_LMS\Affiliates
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar afiliados.', 'atora-lms' ) );
}

use ATORA\Affiliates\Affiliates;

global $wpdb;

$options         = Affiliates::get_options();
$aff_table       = "{$wpdb->prefix}atora_affiliates";
$comm_table      = "{$wpdb->prefix}atora_affiliate_commissions";
$ajax_nonce      = wp_create_nonce( 'atora_affiliate_admin' );
$min_payout      = (float) ( $options['min_payout'] ?? 50 );
$today           = wp_date( get_option( 'date_format' ) );
$affiliates_data = array();
$totals          = array(
	'total'        => 0,
	'pending'      => 0,
	'active'       => 0,
	'rejected'     => 0,
	'pending_pay'  => 0.0,
	'paid_total'   => 0.0,
);

$aff_table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aff_table ) ) === $aff_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$comm_table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $comm_table ) ) === $comm_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

if ( $aff_table_exists ) {
	if ( $comm_table_exists ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$affiliates_data = (array) $wpdb->get_results(
			"SELECT a.*,
				u.display_name,
				u.user_email,
				COUNT(c.id) AS commission_count,
				COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.commission_amount ELSE 0 END), 0) AS approved_commission,
				COALESCE(SUM(CASE WHEN c.status = 'paid' THEN c.commission_amount ELSE 0 END), 0) AS paid_commission
			FROM {$aff_table} a
			LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
			LEFT JOIN {$comm_table} c ON c.affiliate_id = a.id
			GROUP BY a.id
			ORDER BY a.created_at DESC"
		);
		// phpcs:enable
	} else {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$affiliates_data = (array) $wpdb->get_results(
			"SELECT a.*, u.display_name, u.user_email
			FROM {$aff_table} a
			LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
			ORDER BY a.created_at DESC"
		);
		// phpcs:enable
	}
}

foreach ( $affiliates_data as $row ) {
	$status = sanitize_key( (string) ( $row->status ?? '' ) );

	$totals['total']++;
	if ( isset( $totals[ $status ] ) ) {
		$totals[ $status ]++;
	}

	$totals['pending_pay'] += (float) ( $row->approved_commission ?? 0 );
	$totals['paid_total']  += (float) ( $row->paid_commission ?? 0 );
}

$header_stats = array(
	array(
		'label' => __( 'Afiliados', 'atora-lms' ),
		'value' => number_format_i18n( (int) $totals['total'] ),
	),
	array(
		'label' => __( 'Activos', 'atora-lms' ),
		'value' => number_format_i18n( (int) $totals['active'] ),
	),
	array(
		'label' => __( 'Pendientes', 'atora-lms' ),
		'value' => number_format_i18n( (int) $totals['pending'] ),
	),
	array(
		'label' => __( 'Pago pendiente', 'atora-lms' ),
		'value' => '$' . number_format_i18n( (float) $totals['pending_pay'], 2 ),
	),
);

$guide_steps = array(
	__( 'Activa y ajusta parámetros base del programa de afiliados.', 'atora-lms' ),
	__( 'Revisa solicitudes pendientes y valida datos de payout.', 'atora-lms' ),
	__( 'Publica pagos y monitorea rendimiento por afiliado.', 'atora-lms' ),
);

$quick_links = array(
	array(
		'title'       => __( 'Hub comercial', 'atora-lms' ),
		'description' => __( 'Volver a ventas, afiliados y crecimiento.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=clms-commercial-hub' ),
	),
	array(
		'title'       => __( 'CRM Hub', 'atora-lms' ),
		'description' => __( 'Cruzar afiliados con datos de contactos y leads.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=clms-crm-hub' ),
	),
	array(
		'title'       => __( 'Hub ajustes', 'atora-lms' ),
		'description' => __( 'Parámetros técnicos, email y mensajería.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=clms-settings-hub' ),
	),
	array(
		'title'       => __( 'Analítica', 'atora-lms' ),
		'description' => __( 'KPIs y tendencias del ecosistema.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=clms-analytics' ),
	),
);
?>

<div class="wrap atora-hub atora-hub--commercial atora-affiliates-hub">
	<div class="atora-hub__header">
		<div>
			<span class="atora-hub__context"><?php esc_html_e( 'Hub comercial', 'atora-lms' ); ?></span>
			<h1 class="atora-hub__title"><?php esc_html_e( 'Gestión de afiliados', 'atora-lms' ); ?></h1>
			<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( 'Aliados, comisiones y pagos', 'atora-lms' ); ?></p>
			<p class="atora-hub__subtitle"><?php esc_html_e( 'Concentra referidos, comisiones y pagos en un solo flujo operativo alineado con el resto de hubs.', 'atora-lms' ); ?></p>
		</div>
		<div class="atora-hub__meta">
			<?php foreach ( $header_stats as $header_stat ) : ?>
				<div class="atora-hub__meta-item">
					<strong class="atora-hub__meta-num"><?php echo esc_html( (string) $header_stat['value'] ); ?></strong>
					<span class="atora-hub__meta-lbl"><?php echo esc_html( (string) $header_stat['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="atora-hub__guide">
		<h3 class="atora-hub__guide-title"><?php esc_html_e( 'Flujo recomendado en 3 pasos', 'atora-lms' ); ?></h3>
		<div class="atora-hub__guide-list">
			<?php foreach ( $guide_steps as $step_index => $step_label ) : ?>
				<div class="atora-hub__guide-step">
					<span class="atora-hub__guide-badge"><?php echo esc_html( (string) ( $step_index + 1 ) ); ?></span>
					<span><?php echo esc_html( (string) $step_label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="atora-hub__quick">
		<h2 class="atora-hub__quick-title"><?php esc_html_e( 'Operación de afiliados', 'atora-lms' ); ?></h2>
		<?php settings_errors( 'atora_affiliates_settings' ); ?>
		<?php if ( ! $aff_table_exists ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Las tablas de afiliados aún no existen. Ejecuta el instalador/migrador de módulos v5 para habilitarlas.', 'atora-lms' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $affiliates_data ) ) : ?>
			<p><?php esc_html_e( 'Aún no hay afiliados registrados.', 'atora-lms' ); ?></p>
		<?php else : ?>
			<div class="atora-affiliates-table-wrap">
				<table class="widefat striped atora-affiliates-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Afiliado', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Código', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Comisión', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Pendiente pago', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Pagado', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $affiliates_data as $row ) : ?>
							<?php
							$affiliate_id = absint( $row->id ?? 0 );
							$status       = sanitize_key( (string) ( $row->status ?? 'pending' ) );
							$pending_pay  = (float) ( $row->approved_commission ?? 0 );
							$paid_total   = (float) ( $row->paid_commission ?? 0 );
							$rate         = (float) ( $row->commission_rate ?? 0 );
							$email        = sanitize_email( (string) ( $row->user_email ?? '' ) );
							$name         = sanitize_text_field( (string) ( $row->display_name ?? '' ) );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( '' !== $name ? $name : __( 'Usuario sin nombre', 'atora-lms' ) ); ?></strong><br>
									<code><?php echo esc_html( $email ); ?></code>
								</td>
								<td>
									<span class="atora-status atora-status--<?php echo esc_attr( sanitize_html_class( $status ) ); ?>">
										<?php echo esc_html( ucfirst( $status ) ); ?>
									</span>
								</td>
								<td><code><?php echo esc_html( (string) ( $row->referral_code ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $rate, 2 ) ); ?>%</td>
								<td><?php echo esc_html( '$' . number_format_i18n( $pending_pay, 2 ) ); ?></td>
								<td><?php echo esc_html( '$' . number_format_i18n( $paid_total, 2 ) ); ?></td>
								<td>
									<div class="atora-aff-actions">
										<?php if ( 'pending' === $status ) : ?>
											<button type="button" class="button button-primary" data-aff-action="approve" data-affiliate-id="<?php echo esc_attr( (string) $affiliate_id ); ?>">
												<?php esc_html_e( 'Aprobar', 'atora-lms' ); ?>
											</button>
											<button type="button" class="button" data-aff-action="reject" data-affiliate-id="<?php echo esc_attr( (string) $affiliate_id ); ?>">
												<?php esc_html_e( 'Rechazar', 'atora-lms' ); ?>
											</button>
										<?php else : ?>
											<button type="button" class="button" data-aff-action="approve" data-affiliate-id="<?php echo esc_attr( (string) $affiliate_id ); ?>">
												<?php esc_html_e( 'Activar', 'atora-lms' ); ?>
											</button>
										<?php endif; ?>

										<?php if ( $pending_pay >= $min_payout ) : ?>
											<button type="button" class="button button-secondary" data-aff-action="pay" data-affiliate-id="<?php echo esc_attr( (string) $affiliate_id ); ?>">
												<?php esc_html_e( 'Marcar pagado', 'atora-lms' ); ?>
											</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<div class="atora-hub__quick">
		<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Configuración del programa', 'atora-lms' ); ?></h3>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'atora_affiliates_settings' );
			do_settings_sections( 'atora-affiliates-settings' );
			submit_button( __( 'Guardar configuración', 'atora-lms' ) );
			?>
		</form>
	</div>

	<div class="atora-hub__quick">
		<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Red de hubs ATORA', 'atora-lms' ); ?></h3>
		<div class="atora-hub__quick-grid">
			<?php foreach ( $quick_links as $item ) : ?>
				<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
					<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
					<span><?php echo esc_html( (string) $item['description'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<style>
	.atora-affiliates-hub .atora-affiliates-table-wrap { overflow-x: auto; }
	.atora-affiliates-hub .atora-affiliates-table th,
	.atora-affiliates-hub .atora-affiliates-table td { vertical-align: top; }
	.atora-affiliates-hub .atora-aff-actions { display: flex; flex-wrap: wrap; gap: 6px; }
	.atora-affiliates-hub .atora-status {
		display: inline-block;
		padding: 3px 10px;
		border-radius: 999px;
		font-weight: 700;
		font-size: 11px;
	}
	.atora-affiliates-hub .atora-status--pending { background: #fff7e0; color: #8f5a00; }
	.atora-affiliates-hub .atora-status--active { background: #e8f6ed; color: #1f7a3e; }
	.atora-affiliates-hub .atora-status--rejected { background: #fdeaea; color: #a12727; }
	.atora-affiliates-hub .atora-status--suspended { background: #eef1f6; color: #3f4d62; }
</style>

<script>
document.addEventListener('click', function(event) {
	const trigger = event.target.closest('[data-aff-action]');
	if (!trigger) {
		return;
	}

	const actionMap = {
		approve: 'atora_affiliate_approve',
		reject: 'atora_affiliate_reject',
		pay: 'atora_affiliate_pay'
	};

	const action = trigger.getAttribute('data-aff-action') || '';
	const actionName = actionMap[action] || '';
	const affiliateId = trigger.getAttribute('data-affiliate-id') || '';
	if (!actionName || !affiliateId || typeof window.ajaxurl === 'undefined') {
		return;
	}

	trigger.disabled = true;
	const params = new URLSearchParams();
	params.append('action', actionName);
	params.append('affiliate_id', affiliateId);
	params.append('_ajax_nonce', '<?php echo esc_js( $ajax_nonce ); ?>');

	fetch(window.ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
		},
		body: params.toString()
	})
		.then(function(response) { return response.json(); })
		.then(function(payload) {
			if (!payload || !payload.success) {
				throw new Error('ATORA/Affiliates action failed');
			}
			window.location.reload();
		})
		.catch(function() {
			trigger.disabled = false;
			window.alert('<?php echo esc_js( __( 'No se pudo ejecutar la acción. Revisa permisos o intenta de nuevo.', 'atora-lms' ) ); ?>');
		});
});
</script>
