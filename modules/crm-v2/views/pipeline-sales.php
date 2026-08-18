<?php
/**
 * Pipeline comercial CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$board_stages = (array) ( $board['stages'] ?? array() );
$board_items  = (array) ( $board['items'] ?? array() );
$stage_help = array(
	'new_lead'         => __( 'Lead capturado. Falta el primer contacto comercial.', 'atora-lms' ),
	'contacted'        => __( 'Ya hubo primer contacto (email, WhatsApp, llamada o mensaje interno).', 'atora-lms' ),
	'interested'       => __( 'Respondio o mostro interes real. Proxima accion: oferta concreta.', 'atora-lms' ),
	'proposal_sent'    => __( 'Se envio propuesta: programa, fechas, precio o plan de pago.', 'atora-lms' ),
	'payment_pending'  => __( 'Hay intencion de pago pero falta confirmar. Requiere seguimiento corto.', 'atora-lms' ),
	'enrolled'         => __( 'Inscrito. Pasar a onboarding y asegurar primera semana.', 'atora-lms' ),
	'won'              => __( 'Cierre ganado. Mantener relacion y oportunidades futuras.', 'atora-lms' ),
	'lost'             => __( 'No se cerro. Registrar motivo para aprender y mejorar.', 'atora-lms' ),
	'reactivate_later' => __( 'Contacto enfriado pero recuperable. Programar recordatorio.', 'atora-lms' ),
);

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h3><?php esc_html_e( 'Flujo de leads', 'atora-lms' ); ?></h3>
		<a class="atora-crm-v2-link" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-contacts&status=lead' ) ); ?>"><?php esc_html_e( 'Ver leads', 'atora-lms' ); ?></a>
	</div>
	<p class="atora-crm-v2-muted"><?php esc_html_e( 'ATORA crea leads automaticamente (formularios, registro, compras e inscripciones). Luego avanzas moviendo la oportunidad por etapas.', 'atora-lms' ); ?></p>
	<ul class="atora-crm-v2-checklist">
		<li><?php esc_html_e( 'Formularios de interes (landing, contacto, descargables).', 'atora-lms' ); ?></li>
		<li><?php esc_html_e( 'Registro de usuario y perfil de preinscripcion.', 'atora-lms' ); ?></li>
		<li><?php esc_html_e( 'Compra en la tienda (WooCommerce).', 'atora-lms' ); ?></li>
		<li><?php esc_html_e( 'Inscripcion a curso o programa.', 'atora-lms' ); ?></li>
	</ul>
	<p class="atora-crm-v2-muted" style="margin-top:10px"><?php esc_html_e( 'Etapas del pipeline (arrastrar para actualizar):', 'atora-lms' ); ?></p>
	<ul class="atora-crm-v2-list">
		<?php foreach ( $board_stages as $stage_key => $stage ) : ?>
			<li>
				<strong><?php echo esc_html( (string) ( $stage['label'] ?? $stage_key ) ); ?></strong>
				<small><?php echo esc_html( (string) ( $stage_help[ $stage_key ] ?? '' ) ); ?></small>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h2><?php esc_html_e( 'Pipeline de ventas', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Arrastra una oportunidad entre etapas. El cambio se guarda sin recargar.', 'atora-lms' ); ?></p>
	</div>

	<div class="atora-crm-v2-kanban" data-kanban="sales">
		<?php foreach ( $board_stages as $stage_key => $stage ) : ?>
			<?php $cards = (array) ( $board_items[ $stage_key ] ?? array() ); ?>
			<article class="atora-crm-v2-kanban__column" data-stage="<?php echo esc_attr( $stage_key ); ?>">
				<header>
					<h3><?php echo esc_html( (string) ( $stage['label'] ?? $stage_key ) ); ?></h3>
					<span><?php echo esc_html( number_format_i18n( count( $cards ) ) ); ?></span>
				</header>
				<div class="atora-crm-v2-kanban__cards" data-dropzone="sales">
					<?php if ( empty( $cards ) ) : ?>
						<p class="atora-crm-v2-empty atora-crm-v2-empty--mini"><?php esc_html_e( 'Sin oportunidades en esta etapa.', 'atora-lms' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $cards as $card ) : ?>
						<article class="atora-crm-v2-deal" draggable="true" data-card-id="<?php echo esc_attr( (string) absint( $card['id'] ?? 0 ) ); ?>" data-card-type="sales">
							<strong><?php echo esc_html( (string) ( $card['title'] ?? '' ) ); ?></strong>
							<p><?php echo esc_html( (string) ( $card['next_action'] ?? '' ) ); ?></p>
							<div class="atora-crm-v2-deal__meta">
								<span><?php echo esc_html( sprintf( __( 'Canal: %s', 'atora-lms' ), strtoupper( (string) ( $card['channel'] ?? 'email' ) ) ) ); ?></span>
								<span><?php echo esc_html( '$' . number_format_i18n( (float) ( $card['estimated_value'] ?? 0 ), 2 ) ); ?></span>
							</div>
							<div class="atora-crm-v2-deal__actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . absint( $card['contact_id'] ?? 0 ) ) ); ?>"><?php esc_html_e( 'Ver ficha', 'atora-lms' ); ?></a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
