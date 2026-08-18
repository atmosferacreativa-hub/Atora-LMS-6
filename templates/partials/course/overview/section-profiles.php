<?php
/**
 * Partial: Perfiles de ingreso/egreso — single-course (overview)
 * Variables: $ingress, $egress
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cov-profiles">
	<?php if ( $ingress ) : ?>
		<div class="cov-profile-card">
			<h2 class="cov-section-title"><?php esc_html_e( 'Perfil de ingreso', 'atora-lms' ); ?></h2>
			<div class="cov-profile-content"><?php echo nl2br( esc_html( $ingress ) ); ?></div>
		</div>
	<?php endif; ?>
	<?php if ( $egress ) : ?>
		<div class="cov-profile-card">
			<h2 class="cov-section-title"><?php esc_html_e( 'Perfil de egreso', 'atora-lms' ); ?></h2>
			<div class="cov-profile-content"><?php echo nl2br( esc_html( $egress ) ); ?></div>
		</div>
	<?php endif; ?>
</div>
