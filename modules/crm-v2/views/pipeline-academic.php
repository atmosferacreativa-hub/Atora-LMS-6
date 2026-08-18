<?php
/**
 * Pipeline académico CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$board_stages = (array) ( $board['stages'] ?? array() );
$board_items  = (array) ( $board['items'] ?? array() );

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h2><?php esc_html_e( 'Acompañamiento estudiantil', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Gestiona progreso, riesgo y recuperación con acciones visibles por estudiante.', 'atora-lms' ); ?></p>
	</div>

	<div class="atora-crm-v2-kanban" data-kanban="academic">
		<?php foreach ( $board_stages as $stage_key => $stage ) : ?>
			<?php $cards = (array) ( $board_items[ $stage_key ] ?? array() ); ?>
			<article class="atora-crm-v2-kanban__column" data-stage="<?php echo esc_attr( $stage_key ); ?>">
				<header>
					<h3><?php echo esc_html( (string) ( $stage['label'] ?? $stage_key ) ); ?></h3>
					<span><?php echo esc_html( number_format_i18n( count( $cards ) ) ); ?></span>
				</header>
				<div class="atora-crm-v2-kanban__cards" data-dropzone="academic">
					<?php if ( empty( $cards ) ) : ?>
						<p class="atora-crm-v2-empty atora-crm-v2-empty--mini"><?php esc_html_e( 'Sin estudiantes en esta etapa.', 'atora-lms' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $cards as $card ) : ?>
						<article class="atora-crm-v2-deal" draggable="true" data-card-id="<?php echo esc_attr( (string) absint( $card['id'] ?? 0 ) ); ?>" data-card-type="academic">
							<strong><?php echo esc_html( (string) ( $card['course_title'] ?: __( 'Estudiante sin curso', 'atora-lms' ) ) ); ?></strong>
							<p><?php echo esc_html( (string) ( $card['next_action'] ?? '' ) ); ?></p>
							<?php
						$days_inactive = 0;
						if ( ! empty( $card['last_activity'] ) ) {
							$last = strtotime( (string) $card['last_activity'] );
							$days_inactive = $last ? (int) floor( ( time() - $last ) / DAY_IN_SECONDS ) : 0;
						}
						if ( $days_inactive >= 7 ) : ?>
						<span class="crm-inactivity-chip crm-inactivity-chip--<?php echo $days_inactive >= 14 ? 'urgent' : 'warn'; ?>">
							<?php echo esc_html( sprintf( __( 'Sin actividad %dd', 'atora-lms' ), $days_inactive ) ); ?>
						</span>
						<?php endif; ?>
						<div class="atora-crm-v2-deal__meta">
								<span><?php echo esc_html( sprintf( __( 'Progreso: %d%%', 'atora-lms' ), absint( $card['progress_percent'] ?? 0 ) ) ); ?></span>
								<span><?php echo esc_html( sprintf( __( 'Pendientes: %d', 'atora-lms' ), absint( $card['pending_activities'] ?? 0 ) ) ); ?></span>
							</div>
							<div class="atora-crm-v2-deal__actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . absint( $card['contact_id'] ?? 0 ) ) ); ?>"><?php esc_html_e( 'Perfil 360', 'atora-lms' ); ?></a>
								<?php if ( ! empty( $card['course_id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( absint( $card['course_id'] ) ) ?: '#' ); ?>"><?php esc_html_e( 'Abrir curso', 'atora-lms' ); ?></a>
								<?php endif; ?>
								<?php if ( in_array( $stage_key, array( 'at_risk', 'low_progress', 'intervention', 'needs_support' ), true ) ) : ?>
									<a class="crm-ai-analysis-link"
										href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . absint( $card['contact_id'] ?? 0 ) . '&ai_summary=1' ) ); ?>"
										style="color:#1d4ed8;font-size:11px">
										<?php esc_html_e( '🤖 Ver análisis IA', 'atora-lms' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>

<style>
.crm-inactivity-chip{display:inline-block;font-size:10px;font-weight:600;padding:2px 7px;border-radius:999px;margin-bottom:4px}
.crm-inactivity-chip--warn{background:#fef3c7;color:#92400e}
.crm-inactivity-chip--urgent{background:#fee2e2;color:#991b1b}
.crm-inactivity-auto-btn{background:none!important;border-color:#e2e8f0!important;color:#1d4ed8!important}
</style>
<script>
function atoraTriggerInactivityAuto(btn) {
	var cid = btn.getAttribute('data-contact-id');
	if (!window.confirm('¿Activar automatización de inactividad para este estudiante?')) { return; }
	btn.disabled = true;
	var cfg = window.atoraCrmV2 || {};
	fetch((cfg.restBase||'').replace(/\/+$/,'') + '/contacts/' + cid + '/tag', {
		method: 'POST', credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
		body: JSON.stringify({ tag: 'inactividad-detectada' })
	}).then(function(r) { return r.json(); }).then(function(d) {
		btn.textContent = '✓'; btn.style.color = '#059669';
	}).catch(function() { btn.disabled = false; });
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
