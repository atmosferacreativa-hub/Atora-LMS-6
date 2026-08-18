<?php
/**
 * Partial: Profiles section — single-course-commercial
 * Variables: $ingress (string), $egress (string)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section">
	<h2 class="cc-section-title"><?php esc_html_e( 'Perfiles de ingreso y egreso', 'atora-lms' ); ?></h2>
	<div class="cc-profiles">
		<?php if ( $ingress ) : ?>
			<div class="cc-profile-box">
				<p class="cc-profile-title"><?php esc_html_e( 'Para quién es este curso', 'atora-lms' ); ?></p>
				<p class="cc-profile-text"><?php echo esc_html( $ingress ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( $egress ) : ?>
			<div class="cc-profile-box">
				<p class="cc-profile-title"><?php esc_html_e( 'Al terminar serás capaz de', 'atora-lms' ); ?></p>
				<p class="cc-profile-text"><?php echo esc_html( $egress ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</div>
