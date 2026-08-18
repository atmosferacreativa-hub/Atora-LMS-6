<?php
/**
 * Vista de duplicados de contactos.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$duplicates = (array) ( $duplicates ?? array() );

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h2><?php esc_html_e( 'Duplicados detectados', 'atora-lms' ); ?></h2>
		<p><?php echo esc_html( sprintf( __( '%d grupos de contacto requieren revisión.', 'atora-lms' ), count( $duplicates ) ) ); ?></p>
	</div>

	<?php if ( empty( $duplicates ) ) : ?>
		<p class="atora-crm-v2-empty"><?php esc_html_e( 'No se detectaron duplicados por email.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<?php foreach ( $duplicates as $group ) : ?>
			<div class="atora-crm-v2-panel" style="margin-bottom:16px;">
				<h3><?php echo esc_html( (string) ( $group['email_key'] ?? '' ) ); ?></h3>
				<div class="atora-crm-report-grid">
					<?php foreach ( (array) ( $group['contacts'] ?? array() ) as $index => $contact ) : ?>
						<article class="atora-crm-mini-card">
							<div class="atora-crm-mini-card__name">
								<?php echo esc_html( (string) ( $contact['name'] ?? __( 'Sin nombre', 'atora-lms' ) ) ); ?>
								<?php if ( 0 === $index ) : ?>
									<span class="atora-crm-badge atora-crm-badge--normal"><?php esc_html_e( 'Canon', 'atora-lms' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="atora-crm-mini-card__meta">ID <?php echo esc_html( absint( $contact['id'] ?? 0 ) ); ?></div>
							<div class="atora-crm-mini-card__meta"><?php echo esc_html( (string) ( $contact['phone'] ?? '—' ) ); ?></div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
