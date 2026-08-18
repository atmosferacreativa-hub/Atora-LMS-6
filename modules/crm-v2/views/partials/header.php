<?php
/**
 * Header compartido de CRM v2.
 *
 * Variables esperadas:
 * - string $screen
 * - array  $nav_items
 * - array  $overview
 * - array  $notice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$screen      = isset( $screen ) ? (string) $screen : 'hub';
$nav_items   = isset( $nav_items ) && is_array( $nav_items ) ? $nav_items : array();
$nav_group   = isset( $nav_group ) ? (string) $nav_group : 'commercial';
$overview    = isset( $overview ) && is_array( $overview ) ? $overview : array();
$notice      = isset( $notice ) && is_array( $notice ) ? $notice : array();

$hero_kicker = __( 'CRM unificado', 'atora-lms' );
$hero_title  = __( 'Consola CRM académico-comercial', 'atora-lms' );
$hero_copy   = __( 'Prioriza acciones diarias, seguimiento estudiantil y campañas sin salir del flujo operativo.', 'atora-lms' );

if ( 'commercial' === $nav_group ) {
	$hero_kicker = __( 'Hub comercial', 'atora-lms' );
	$hero_title  = __( 'Operación comercial ATORA', 'atora-lms' );
	$hero_copy   = __( 'Gestiona leads, campañas y cierres con foco total en conversión.', 'atora-lms' );
} elseif ( 'academic' === $nav_group ) {
	$hero_kicker = __( 'Hub académico', 'atora-lms' );
	$hero_title  = __( 'Acompañamiento académico ATORA', 'atora-lms' );
	$hero_copy   = __( 'Monitorea riesgo, tareas docentes y avance estudiantil desde un solo hub.', 'atora-lms' );
} elseif ( 'selector' === $nav_group ) {
	$hero_kicker = __( 'Entrada CRM', 'atora-lms' );
	$hero_title  = __( 'Elige tu hub de trabajo', 'atora-lms' );
	$hero_copy   = __( 'Selecciona el frente operativo que quieres abrir para esta sesión.', 'atora-lms' );
}
?>
<div class="wrap atora-crm-v2-app">
	<section class="atora-crm-v2-app__hero">
		<div>
			<span class="atora-crm-v2-app__kicker"><?php echo esc_html( $hero_kicker ); ?></span>
			<h1><?php echo esc_html( $hero_title ); ?></h1>
			<p><?php echo esc_html( $hero_copy ); ?></p>
		</div>
		<div class="atora-crm-v2-app__hero-stats">
			<article><strong><?php echo esc_html( number_format_i18n( absint( $overview['leads_count'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Leads', 'atora-lms' ); ?></span></article>
			<article><strong><?php echo esc_html( number_format_i18n( absint( $overview['student_count'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Estudiantes', 'atora-lms' ); ?></span></article>
			<article><strong><?php echo esc_html( number_format_i18n( absint( $overview['overdue_tasks'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Tareas vencidas', 'atora-lms' ); ?></span></article>
			<article><strong><?php echo esc_html( number_format_i18n( absint( $overview['risk_students'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Riesgo académico', 'atora-lms' ); ?></span></article>
		</div>
	</section>

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="atora-crm-v2-app__notice <?php echo ! empty( $notice['type'] ) ? 'is-' . esc_attr( $notice['type'] ) : ''; ?>">
			<?php echo esc_html( (string) $notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<nav class="atora-crm-v2-app__nav" aria-label="<?php esc_attr_e( 'Navegación CRM', 'atora-lms' ); ?>">
		<?php foreach ( $nav_items as $item_key => $item ) : ?>
			<?php
			$slug   = sanitize_key( (string) ( $item['slug'] ?? '' ) );
			$label  = sanitize_text_field( (string) ( $item['label'] ?? $item_key ) );
			$active = $screen === $item_key;
			?>
			<a class="atora-crm-v2-app__tab <?php echo $active ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
