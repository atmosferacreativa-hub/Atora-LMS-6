<?php
/**
 * CRM Contact profile 360.
 *
 * @package ATORA_LMS\CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$crm_class = '\\ATORA\\CRM\\CRM';
$can_view  = current_user_can( 'manage_options' ) || ( function_exists( 'is_super_admin' ) && is_super_admin() ) || (
	class_exists( $crm_class ) && method_exists( $crm_class, 'can_access_crm' )
		? (bool) $crm_class::can_access_crm( get_current_user_id() )
		: false
);

if ( ! $can_view ) {
	wp_die( esc_html__( 'No tienes permisos para ver el perfil CRM.', 'atora-lms' ) );
}

global $wpdb;
$contact_id = absint( $_GET['contact_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$contact    = null;
$timeline   = array();

if ( $contact_id > 0 ) {
	$contact = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1",
			$contact_id
		)
	);
	if ( $contact ) {
		$timeline = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT activity_type, activity_data, created_at
				 FROM {$wpdb->prefix}atora_contact_activities
				 WHERE contact_id = %d
				 ORDER BY created_at DESC
				 LIMIT 100",
				$contact_id
			)
		);
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Ficha CRM 360', 'atora-lms' ); ?></h1>
	<?php if ( ! $contact ) : ?>
		<p><?php esc_html_e( 'Contacto no encontrado.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:1100px;">
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Perfil básico', 'atora-lms' ); ?></h2>
				<p><strong><?php esc_html_e( 'Nombre:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) ( $contact->name ?? '' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Email:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) ( $contact->email ?? '' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Teléfono:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) ( $contact->phone ?? '' ) ); ?></p>
				<p><strong><?php esc_html_e( 'WhatsApp:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) ( $contact->whatsapp ?? '' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Estado:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) ( $contact->status ?? '' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Fuente:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) ( $contact->source ?? '' ) ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm&tab=inbox&s=' . rawurlencode( (string) ( $contact->email ?? '' ) ) ) ); ?>"><?php esc_html_e( 'Ver conversaciones', 'atora-lms' ); ?></a></p>
			</div>
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Timeline', 'atora-lms' ); ?></h2>
				<?php if ( empty( $timeline ) ) : ?>
					<p><?php esc_html_e( 'Sin actividad registrada.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<ul>
						<?php foreach ( $timeline as $item ) : ?>
							<li><strong><?php echo esc_html( (string) sanitize_key( $item->activity_type ?? '' ) ); ?></strong> · <?php echo esc_html( (string) sanitize_text_field( $item->created_at ?? '' ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
