<?php
/**
 * "Hoy" — vista de admin (PT-3, sprint 6.8.0).
 *
 * Sin JS/AJAX para el contenido principal a propósito — server-rendered
 * en cada carga (§UX: "la primera pantalla que alguien ve al entrar
 * debe cargar rápido"), y como CLMS_Today_Aggregator_Service::get_today()
 * no cachea nada (PT-1.4), un ítem accionado en su página de destino
 * (p. ej. marcar contactado en el panel de una ocurrencia) ya no
 * aparece la próxima vez que se visita esta pantalla, sin necesidad de
 * ninguna sincronización adicional (criterio de aceptación de PT-3).
 *
 * @package ATORA_LMS
 * @since   6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = get_current_user_id();
$service = function_exists( 'atora_lms' ) ? atora_lms( 'CLMS_Today_Aggregator_Service' ) : null;
$items   = ( $service && method_exists( $service, 'get_today' ) ) ? $service->get_today( $user_id ) : array();
?>
<div class="wrap atora-hoy-wrap">
	<div class="atora-hoy-header">
		<h1><?php esc_html_e( 'Hoy', 'atora-lms' ); ?></h1>
		<p class="atora-hoy-subtitle"><?php esc_html_e( 'Lo que necesita tu atención ahora mismo, en un solo lugar.', 'atora-lms' ); ?></p>
	</div>

	<?php if ( $service && method_exists( $service, 'render_items_html' ) ) : ?>
		<?php echo $service->render_items_html( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado adentro. ?>
	<?php else : ?>
		<div class="atora-hoy-empty">
			<p class="atora-hoy-empty-title"><?php esc_html_e( '✨ Nada urgente por ahora.', 'atora-lms' ); ?></p>
			<p class="atora-hoy-empty-sub"><?php esc_html_e( 'Buen momento para adelantar algo, o simplemente respirar.', 'atora-lms' ); ?></p>
		</div>
	<?php endif; ?>
</div>
