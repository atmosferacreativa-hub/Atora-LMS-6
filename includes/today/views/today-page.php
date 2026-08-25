<?php
/**
 * "Hoy" / "Actividad" — vista de admin (PT-3 de 6.8.0; pestaña de
 * Actividad agregada en PT-3 de 6.9.0).
 *
 * Sin JS/AJAX para el contenido principal a propósito — server-rendered
 * en cada carga (§UX: "la primera pantalla que alguien ve al entrar
 * debe cargar rápido"), y como CLMS_Today_Aggregator_Service::get_today()
 * no cachea nada (PT-1.4 de 6.8.0), un ítem accionado en su página de
 * destino (p. ej. marcar contactado en el panel de una ocurrencia) ya
 * no aparece la próxima vez que se visita esta pantalla, sin
 * necesidad de ninguna sincronización adicional.
 *
 * "Hoy" y "Actividad" (PT-3.4, 6.9.0) NUNCA se mezclan en una sola
 * lista — son respuestas a preguntas distintas (qué falta vs. qué ya
 * se hizo) — pero comparten esta misma página y este mismo punto de
 * entrada de menú, alternadas por un parámetro de URL (?view=actividad),
 * no una segunda ruta de menú.
 *
 * @package ATORA_LMS
 * @since   6.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = get_current_user_id();
$view    = isset( $_GET['view'] ) && 'actividad' === sanitize_key( (string) wp_unslash( $_GET['view'] ) ) ? 'actividad' : 'hoy';

$today_service    = function_exists( 'atora_lms' ) ? atora_lms( 'CLMS_Today_Aggregator_Service' ) : null;
$activity_service = function_exists( 'atora_lms' ) ? atora_lms( 'CLMS_UI_Activity_Feed_Service' ) : null;

$hoy_url       = admin_url( 'admin.php?page=atora-hoy' );
$actividad_url = admin_url( 'admin.php?page=atora-hoy&view=actividad' );
?>
<div class="wrap atora-hoy-wrap">
	<div class="atora-hoy-header">
		<h1><?php echo 'actividad' === $view ? esc_html__( 'Actividad', 'atora-lms' ) : esc_html__( 'Hoy', 'atora-lms' ); ?></h1>
		<p class="atora-hoy-subtitle">
			<?php
			echo 'actividad' === $view
				? esc_html__( 'Lo que ya resolviste, para que también lo veas.', 'atora-lms' )
				: esc_html__( 'Lo que necesita tu atención ahora mismo, en un solo lugar.', 'atora-lms' );
			?>
		</p>
	</div>

	<div class="atora-hoy-tabs" role="tablist">
		<a class="atora-hoy-tab<?php echo 'hoy' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( $hoy_url ); ?>" role="tab" aria-selected="<?php echo 'hoy' === $view ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'Hoy', 'atora-lms' ); ?>
		</a>
		<a class="atora-hoy-tab<?php echo 'actividad' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( $actividad_url ); ?>" role="tab" aria-selected="<?php echo 'actividad' === $view ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'Actividad', 'atora-lms' ); ?>
		</a>
	</div>

	<?php if ( 'actividad' === $view ) : ?>
		<?php
		$items = ( $activity_service && method_exists( $activity_service, 'get_recent' ) ) ? $activity_service->get_recent( $user_id, 7 ) : array();
		?>
		<?php if ( $activity_service && method_exists( $activity_service, 'render_items_html' ) ) : ?>
			<?php echo $activity_service->render_items_html( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado adentro. ?>
		<?php else : ?>
			<div class="atora-hoy-empty">
				<p class="atora-hoy-empty-title"><?php esc_html_e( 'Todavía nada que celebrar esta semana.', 'atora-lms' ); ?></p>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<?php
		$items = ( $today_service && method_exists( $today_service, 'get_today' ) ) ? $today_service->get_today( $user_id ) : array();
		?>
		<?php if ( $today_service && method_exists( $today_service, 'render_items_html' ) ) : ?>
			<?php echo $today_service->render_items_html( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado adentro. ?>
		<?php else : ?>
			<div class="atora-hoy-empty">
				<p class="atora-hoy-empty-title"><?php esc_html_e( '✨ Nada urgente por ahora.', 'atora-lms' ); ?></p>
				<p class="atora-hoy-empty-sub"><?php esc_html_e( 'Buen momento para adelantar algo, o simplemente respirar.', 'atora-lms' ); ?></p>
			</div>
		<?php endif; ?>

		<!-- PT-1.5/PT-4.1 (6.9.0): mismo panel compartido que el calendario
		     académico/comercial -- un ítem de followup se abre acá mismo,
		     sin navegar a otra página. Solo en la pestaña "Hoy": Actividad
		     no tiene ocurrencias que abrir, solo hechos ya resueltos. -->
		<?php if ( class_exists( 'CLMS_UI_Followup_Panel' ) ) : ?>
			<?php echo CLMS_UI_Followup_Panel::render_shell( 'atora-hoy-panel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado adentro. ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
