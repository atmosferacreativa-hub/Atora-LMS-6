<?php
/**
 * Vista: dashboard público del afiliado
 *
 * @package ATORA_LMS\Affiliates
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ATORA\Affiliates\Affiliates;
use ATORA\Affiliates\Affiliate_Commissions;

$user_id   = get_current_user_id();
$affiliate = Affiliates::get_affiliate_by_user( $user_id );
$stats     = Affiliate_Commissions::get_monthly_stats( (int) $affiliate->id );
$balance   = Affiliate_Commissions::get_pending_balance( (int) $affiliate->id );
$history   = Affiliate_Commissions::get_history( (int) $affiliate->id, 20 );
$ref_url   = add_query_arg( 'ref', $affiliate->referral_code, home_url( '/' ) );
$opts      = Affiliates::get_options();
$min_payout = (float) ( $opts['min_payout'] ?? 50 );
?>

<div class="atora-affiliate-dashboard" style="max-width:900px;">

	<h2><?php esc_html_e( 'Mi panel de afiliado', 'atora-lms' ); ?></h2>

	<!-- Stats del mes -->
	<div class="atora-aff-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:32px;">
		<?php
		$cards = array(
			array( __( 'Clicks', 'atora-lms' ),       $stats['clicks'] ),
			array( __( 'Conversiones', 'atora-lms' ), $stats['conversions'] ),
			array( __( 'Tasa', 'atora-lms' ),          $stats['rate'] . '%' ),
			array( __( 'Comisiones', 'atora-lms' ),   '$' . number_format( $stats['commission_total'], 2 ) ),
			array( __( 'Balance', 'atora-lms' ),       '$' . number_format( $balance, 2 ) ),
		);
		foreach ( $cards as $card ) :
			?>
			<div style="background:var(--atora-surface);border:1px solid var(--atora-border);
			            border-radius:var(--ac-radius-sm);padding:16px;text-align:center;">
				<div style="font-size:24px;font-weight:700;color:var(--atora-accent);">
					<?php echo esc_html( $card[1] ); ?>
				</div>
				<div style="font-size:13px;color:var(--atora-text-muted);margin-top:4px;">
					<?php echo esc_html( $card[0] ); ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- Mi enlace de referido -->
	<div style="background:var(--atora-surface);border:1px solid var(--atora-border);
	            border-radius:var(--ac-radius-sm);padding:20px;margin-bottom:24px;">
		<h3 style="margin:0 0 12px;"><?php esc_html_e( 'Mi enlace de referido', 'atora-lms' ); ?></h3>
		<div style="display:flex;gap:8px;align-items:center;">
			<input type="text"
			       id="atora-aff-ref-url"
			       value="<?php echo esc_url( $ref_url ); ?>"
			       readonly
			       style="flex:1;padding:8px 12px;border:1px solid var(--atora-border);border-radius:var(--ac-radius-xs);">
			<button onclick="copyRef()" style="padding:8px 16px;background:var(--atora-accent);color:#fff;border:none;border-radius:var(--ac-radius-xs);cursor:pointer;">
				<?php esc_html_e( 'Copiar', 'atora-lms' ); ?>
			</button>
		</div>
		<p style="margin:8px 0 0;font-size:13px;color:var(--atora-text-muted);">
			<?php
			printf(
				/* translators: %s: código */
				esc_html__( 'Código: %s', 'atora-lms' ),
				'<strong>' . esc_html( $affiliate->referral_code ) . '</strong>'
			);
			?>
		</p>
	</div>

	<!-- Solicitar pago -->
	<?php if ( $balance >= $min_payout ) : ?>
		<div style="background:var(--atora-success-bg);border:1px solid var(--atora-success);
		            border-radius:var(--ac-radius-xs);padding:16px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;">
			<span>
				<?php
				printf(
					/* translators: %s: balance */
					esc_html__( 'Tienes $%s disponibles para retirar.', 'atora-lms' ),
					number_format( $balance, 2 )
				);
				?>
			</span>
			<button style="padding:8px 16px;background:var(--atora-success);color:#fff;border:none;border-radius:var(--ac-radius-xs);cursor:pointer;">
				<?php esc_html_e( 'Solicitar pago', 'atora-lms' ); ?>
			</button>
		</div>
	<?php else : ?>
		<p style="color:var(--atora-text-muted);font-size:13px;">
			<?php
			printf(
				/* translators: 1: balance actual, 2: mínimo */
				esc_html__( 'Balance actual: $%1$s. Mínimo de retiro: $%2$s.', 'atora-lms' ),
				number_format( $balance, 2 ),
				number_format( $min_payout, 2 )
			);
			?>
		</p>
	<?php endif; ?>

	<!-- Historial de comisiones -->
	<h3><?php esc_html_e( 'Historial de comisiones', 'atora-lms' ); ?></h3>

	<?php if ( empty( $history ) ) : ?>
		<p style="color:var(--atora-text-muted);"><?php esc_html_e( 'Aún no tienes comisiones registradas.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table style="width:100%;border-collapse:collapse;font-size:14px;">
			<thead>
				<tr style="border-bottom:2px solid var(--atora-border);">
					<th style="text-align:left;padding:8px;"><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
					<th style="text-align:left;padding:8px;"><?php esc_html_e( 'Pedido', 'atora-lms' ); ?></th>
					<th style="text-align:right;padding:8px;"><?php esc_html_e( 'Venta', 'atora-lms' ); ?></th>
					<th style="text-align:right;padding:8px;"><?php esc_html_e( 'Comisión', 'atora-lms' ); ?></th>
					<th style="text-align:center;padding:8px;"><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $row ) : ?>
					<tr style="border-bottom:1px solid var(--atora-border);">
						<td style="padding:8px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->created_at ) ) ); ?></td>
						<td style="padding:8px;">#<?php echo absint( $row->order_id ); ?></td>
						<td style="padding:8px;text-align:right;"><?php echo esc_html( '$' . number_format( (float) $row->amount, 2 ) ); ?></td>
						<td style="padding:8px;text-align:right;font-weight:600;color:var(--atora-success);">
							<?php echo esc_html( '$' . number_format( (float) $row->commission_amount, 2 ) ); ?>
						</td>
						<td style="padding:8px;text-align:center;">
							<?php
							$status_labels = array(
								'pending'  => '<span style="color:var(--atora-warning);">' . esc_html__( 'Pendiente', 'atora-lms' ) . '</span>',
								'approved' => '<span style="color:var(--atora-accent);">' . esc_html__( 'Aprobada', 'atora-lms' ) . '</span>',
								'paid'     => '<span style="color:var(--atora-success);">' . esc_html__( 'Pagada', 'atora-lms' ) . '</span>',
								'rejected' => '<span style="color:var(--atora-danger);">' . esc_html__( 'Rechazada', 'atora-lms' ) . '</span>',
							);
							echo $status_labels[ $row->status ] ?? esc_html( $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

</div><!-- .atora-affiliate-dashboard -->

<script>
function copyRef() {
	const input = document.getElementById('atora-aff-ref-url');
	if ( navigator.clipboard ) {
		navigator.clipboard.writeText(input.value);
	} else {
		input.select();
		document.execCommand('copy');
	}
	alert('<?php echo esc_js( __( '¡Enlace copiado!', 'atora-lms' ) ); ?>');
}
</script>
