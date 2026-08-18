<?php
/**
 * Vista admin CRM v2 — Fase 1 (sin recargas de página)
 *
 * Todas las acciones van por REST via fetch().
 * La página no se recarga en ninguna operación del formulario.
 *
 * Variables esperadas (igual que antes):
 * - array  $draft
 * - array  $segments, $channels, $delivery_modes, $execution_modes
 * - array  $statuses, $courses, $available_tags, $saved_segments
 * - array  $segment_result, $recent_campaigns
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$segment_stats = is_array( $segment_result['stats'] ?? null ) ? $segment_result['stats'] : array();
$preview_rows  = is_array( $segment_result['contacts'] ?? null ) ? $segment_result['contacts'] : array();
$total_rows    = absint( $segment_result['total'] ?? 0 );

$bulk_options = array(
	'none'       => __( 'Sin acción masiva', 'atora-lms' ),
	'add_tag'    => __( 'Agregar etiqueta', 'atora-lms' ),
	'remove_tag' => __( 'Quitar etiqueta', 'atora-lms' ),
	'set_status' => __( 'Cambiar estado', 'atora-lms' ),
	'cleanup'    => __( 'Depurar contactos', 'atora-lms' ),
);

// nonce para REST — lo lee crm-v2.js desde atoraCrmV2.nonce (ya localizado)
?>
<div class="wrap atora-crm-v2" id="atora-crm-v2-segmentor">

	<?php /* Toast global — aparece y desaparece sin recargar */ ?>
	<div id="crm-toast" class="crm-toast" aria-live="polite" aria-atomic="true" hidden></div>

	<section class="atora-crm-v2__hero">
		<div>
			<h1><?php esc_html_e( 'CRM — Segmentos y Campañas', 'atora-lms' ); ?></h1>
			<p><?php esc_html_e( 'Segmenta contactos, ejecuta campañas y aplica acciones masivas sin salir de esta pantalla.', 'atora-lms' ); ?></p>
		</div>
		<div class="atora-crm-v2__hero-actions">
			<button type="button" id="crm-btn-save-draft" class="button button-primary crm-action-btn" data-action="save_draft">
				<?php esc_html_e( 'Guardar borrador', 'atora-lms' ); ?>
			</button>
			<button type="button" id="crm-btn-reset-draft" class="button crm-action-btn" data-action="reset_draft">
				<?php esc_html_e( 'Restaurar base', 'atora-lms' ); ?>
			</button>
		</div>
	</section>

	<div class="atora-crm-v2__grid">

		<?php /* ===================== 1) SEGMENTO ===================== */ ?>
		<section class="atora-crm-v2__card">
			<h3><?php esc_html_e( '1) Segmento', 'atora-lms' ); ?></h3>

			<label for="crm-audience"><?php esc_html_e( 'Audiencia', 'atora-lms' ); ?></label>
			<select id="crm-audience" name="audience" class="crm-field">
				<?php foreach ( $segments as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $draft['audience'], (string) $k ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="crm-status-filter"><?php esc_html_e( 'Estado', 'atora-lms' ); ?></label>
			<select id="crm-status-filter" name="status_filter" class="crm-field">
				<option value=""><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
				<?php foreach ( $statuses as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $draft['status_filter'], (string) $k ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="crm-course"><?php esc_html_e( 'Curso (opcional)', 'atora-lms' ); ?></label>
			<select id="crm-course" name="course_id" class="crm-field">
				<option value="0"><?php esc_html_e( 'Todos los cursos', 'atora-lms' ); ?></option>
				<?php foreach ( $courses as $course_id => $course_title ) : ?>
					<option value="<?php echo esc_attr( (string) $course_id ); ?>" <?php selected( absint( $draft['course_id'] ), absint( $course_id ) ); ?>>
						<?php echo esc_html( $course_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="crm-tags"><?php esc_html_e( 'Etiquetas (coma separada)', 'atora-lms' ); ?></label>
			<input id="crm-tags" type="text" name="segment_tags" class="crm-field"
				value="<?php echo esc_attr( (string) $draft['segment_tags'] ); ?>"
				placeholder="<?php esc_attr_e( '#inactive-30d, premium, cohorte-a', 'atora-lms' ); ?>">
			<?php if ( ! empty( $available_tags ) ) : ?>
				<p class="atora-crm-v2__hint">
					<?php esc_html_e( 'Tags existentes:', 'atora-lms' ); ?>
					<?php echo esc_html( implode( ', ', array_slice( $available_tags, 0, 16 ) ) ); ?>
				</p>
			<?php endif; ?>

			<label for="crm-search"><?php esc_html_e( 'Buscar en contactos', 'atora-lms' ); ?></label>
			<input id="crm-search" type="text" name="search" class="crm-field"
				value="<?php echo esc_attr( (string) $draft['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Nombre, email, teléfono...', 'atora-lms' ); ?>">

			<?php /* --- Segmentos guardados --- */ ?>
			<div class="atora-crm-v2__inline-actions crm-segment-saver">
				<input type="text" id="crm-segment-name" name="segment_name" class="crm-field"
					value="<?php echo esc_attr( (string) $draft['segment_name'] ); ?>"
					placeholder="<?php esc_attr_e( 'Nombre del segmento', 'atora-lms' ); ?>">
				<button type="button" class="button crm-action-btn" data-action="save_segment">
					<?php esc_html_e( 'Guardar segmento', 'atora-lms' ); ?>
				</button>
			</div>

			<div class="atora-crm-v2__inline-actions">
				<select id="crm-saved-segment" class="crm-field">
					<option value=""><?php esc_html_e( 'Segmentos guardados', 'atora-lms' ); ?></option>
					<?php foreach ( $saved_segments as $seg_id => $seg ) : ?>
						<option value="<?php echo esc_attr( (string) $seg_id ); ?>">
							<?php echo esc_html( (string) ( $seg['name'] ?? $seg_id ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button crm-action-btn" data-action="apply_segment">
					<?php esc_html_e( 'Aplicar', 'atora-lms' ); ?>
				</button>
				<button type="button" class="button button-link-delete crm-action-btn" data-action="delete_segment">
					<?php esc_html_e( 'Eliminar', 'atora-lms' ); ?>
				</button>
			</div>

			<?php /* Botón de preview instantáneo */ ?>
			<button type="button" class="button crm-action-btn crm-preview-btn" data-action="preview">
				<?php esc_html_e( '↻ Actualizar muestra', 'atora-lms' ); ?>
			</button>
		</section>

		<?php /* ===================== 2) MENSAJE Y CANAL ===================== */ ?>
		<section class="atora-crm-v2__card">
			<h3><?php esc_html_e( '2) Mensaje y canal', 'atora-lms' ); ?></h3>

			<label for="crm-channel"><?php esc_html_e( 'Canal', 'atora-lms' ); ?></label>
			<select id="crm-channel" name="channel" class="crm-field">
				<?php foreach ( $channels as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $draft['channel'], (string) $k ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="crm-subject"><?php esc_html_e( 'Asunto', 'atora-lms' ); ?></label>
			<input id="crm-subject" type="text" name="subject" class="crm-field"
				value="<?php echo esc_attr( (string) $draft['subject'] ); ?>"
				placeholder="<?php esc_attr_e( 'Ej: Novedades de la semana', 'atora-lms' ); ?>">

			<label for="crm-message"><?php esc_html_e( 'Mensaje', 'atora-lms' ); ?></label>
			<textarea id="crm-message" name="message" rows="9" class="crm-field"
				placeholder="<?php esc_attr_e( 'Texto base de la campaña. Mantén frases cortas y claras.', 'atora-lms' ); ?>"><?php echo esc_textarea( (string) $draft['message'] ); ?></textarea>

			<label for="crm-cta"><?php esc_html_e( 'URL de llamada a la acción (opcional)', 'atora-lms' ); ?></label>
			<input id="crm-cta" type="url" name="cta_url" class="crm-field"
				value="<?php echo esc_attr( (string) $draft['cta_url'] ); ?>" placeholder="https://...">

			<label class="atora-crm-v2__check">
				<input type="checkbox" id="crm-respect-prefs" name="respect_email_prefs" class="crm-field" value="1"
					<?php checked( ! empty( $draft['respect_email_prefs'] ) ); ?>>
				<span><?php esc_html_e( 'Respetar preferencias de email del usuario (recomendado)', 'atora-lms' ); ?></span>
			</label>
		</section>

		<?php /* ===================== 3) EJECUCIÓN ===================== */ ?>
		<section class="atora-crm-v2__card">
			<h3><?php esc_html_e( '3) Ejecución', 'atora-lms' ); ?></h3>

			<label for="crm-campaign-name"><?php esc_html_e( 'Nombre interno de campaña', 'atora-lms' ); ?></label>
			<input id="crm-campaign-name" type="text" name="campaign_name" class="crm-field"
				value="<?php echo esc_attr( (string) $draft['campaign_name'] ); ?>"
				placeholder="<?php esc_attr_e( 'Ej: Reactivación cohortes mayo', 'atora-lms' ); ?>">

			<label for="crm-delivery"><?php esc_html_e( 'Modo de entrega', 'atora-lms' ); ?></label>
			<select id="crm-delivery" name="delivery" class="crm-field">
				<?php foreach ( $delivery_modes as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $draft['delivery'], (string) $k ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="crm-execution"><?php esc_html_e( 'Modo de ejecución', 'atora-lms' ); ?></label>
			<select id="crm-execution" name="execution_mode" class="crm-field">
				<?php foreach ( $execution_modes as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $draft['execution_mode'], (string) $k ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="crm-scheduled-at"><?php esc_html_e( 'Fecha/hora programada', 'atora-lms' ); ?></label>
			<input id="crm-scheduled-at" type="datetime-local" name="scheduled_at" class="crm-field"
				value="<?php echo esc_attr( (string) $draft['scheduled_at'] ); ?>">

			<p class="atora-crm-v2__hint">
				<?php esc_html_e( 'Simular no envía nada. Encolar envía a la cola actual de ATORA para procesamiento seguro.', 'atora-lms' ); ?>
			</p>

			<button type="button"
				class="button button-primary button-hero crm-action-btn"
				data-action="launch_campaign"
				id="crm-btn-launch">
				<?php esc_html_e( 'Ejecutar campaña', 'atora-lms' ); ?>
			</button>
		</section>
	</div>

	<?php /* ===================== 4) ACCIÓN MASIVA ===================== */ ?>
	<section class="atora-crm-v2__bulk">
		<h3><?php esc_html_e( '4) Acción masiva sobre el segmento', 'atora-lms' ); ?></h3>
		<div class="atora-crm-v2__bulk-row">
			<select id="crm-bulk-action" name="bulk_action" class="crm-field">
				<?php foreach ( $bulk_options as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $draft['bulk_action'], (string) $k ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<input type="text" id="crm-bulk-value" name="bulk_value" class="crm-field"
				value="<?php echo esc_attr( (string) $draft['bulk_value'] ); ?>"
				placeholder="<?php esc_attr_e( 'Valor: etiqueta o estado', 'atora-lms' ); ?>">
			<button type="button" class="button crm-action-btn" data-action="run_bulk_action" id="crm-btn-bulk">
				<?php esc_html_e( 'Aplicar acción', 'atora-lms' ); ?>
			</button>
		</div>
		<p class="atora-crm-v2__hint">
			<?php esc_html_e( 'Depurar elimina solo contactos sin cuenta; los contactos con usuario se archivan para evitar pérdida académica.', 'atora-lms' ); ?>
		</p>
	</section>

	<?php /* ===================== KPIs del segmento ===================== */ ?>
	<section class="atora-crm-v2__summary" id="crm-summary">
		<article>
			<span><?php esc_html_e( 'Contactos del segmento', 'atora-lms' ); ?></span>
			<strong id="crm-stat-total"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?></strong>
		</article>
		<article>
			<span><?php esc_html_e( 'Listos para email', 'atora-lms' ); ?></span>
			<strong id="crm-stat-email"><?php echo esc_html( number_format_i18n( absint( $segment_stats['email_ready'] ?? 0 ) ) ); ?></strong>
		</article>
		<article>
			<span><?php esc_html_e( 'Listos para mensajería', 'atora-lms' ); ?></span>
			<strong id="crm-stat-msg"><?php echo esc_html( number_format_i18n( absint( $segment_stats['messaging_ready'] ?? 0 ) ) ); ?></strong>
		</article>
		<article>
			<span><?php esc_html_e( 'Vinculados a usuario', 'atora-lms' ); ?></span>
			<strong id="crm-stat-linked"><?php echo esc_html( number_format_i18n( absint( $segment_stats['linked_users'] ?? 0 ) ) ); ?></strong>
		</article>
	</section>

	<?php /* ===================== Tabla de muestra del segmento ===================== */ ?>
	<section class="atora-crm-v2__table-wrap" id="crm-preview-wrap">
		<h3><?php esc_html_e( 'Muestra del segmento', 'atora-lms' ); ?></h3>
		<div id="crm-preview-body">
			<?php if ( empty( $preview_rows ) ) : ?>
				<p class="atora-crm-v2__empty"><?php esc_html_e( 'Sin contactos para la configuración actual.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped atora-crm-v2__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Contacto', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Tags', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Canales', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Actualizado', 'atora-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $preview_rows as $row ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( (string) ( $row['name'] ?: __( '(Sin nombre)', 'atora-lms' ) ) ); ?></strong><br>
									<small><?php echo esc_html( (string) ( $row['email'] ?: ( $row['phone'] ?: '—' ) ) ); ?></small>
								</td>
								<td><span class="atora-crm-v2__badge"><?php echo esc_html( (string) ( $statuses[ $row['status'] ] ?? $row['status'] ) ); ?></span></td>
								<td>
									<?php
									$tags = is_array( $row['tags'] ?? null ) ? $row['tags'] : array();
									echo esc_html( ! empty( $tags ) ? implode( ', ', array_slice( $tags, 0, 4 ) ) : __( 'Sin tags', 'atora-lms' ) );
									?>
								</td>
								<td>
									<?php
									$ch      = is_array( $row['channels'] ?? null ) ? $row['channels'] : array();
									$enabled = array();
									if ( ! empty( $ch['email'] ) ) { $enabled[] = 'Email'; }
									if ( ! empty( $ch['whatsapp'] ) ) { $enabled[] = 'WhatsApp'; }
									if ( ! empty( $ch['telegram'] ) ) { $enabled[] = 'Telegram'; }
									echo esc_html( ! empty( $enabled ) ? implode( ' · ', $enabled ) : __( 'No disponible', 'atora-lms' ) );
									?>
								</td>
								<td><?php echo esc_html( (string) ( $row['updated_at'] ?: '—' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</section>

	<?php /* ===================== Historial de campañas ===================== */ ?>
	<section class="atora-crm-v2__campaigns" id="crm-campaigns-wrap">
		<h3><?php esc_html_e( 'Historial de campañas CRM v2', 'atora-lms' ); ?></h3>
		<div id="crm-campaigns-body">
			<?php if ( empty( $recent_campaigns ) ) : ?>
				<p class="atora-crm-v2__empty"><?php esc_html_e( 'Aún no hay campañas registradas en esta fase.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped atora-crm-v2__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Campaña', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Canal', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Impacto', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_campaigns as $campaign ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( (string) ( $campaign['name'] ?: $campaign['id'] ) ); ?></strong><br>
									<small><?php echo esc_html( (string) ( $campaign['subject'] ?: $campaign['preview'] ) ); ?></small>
								</td>
								<td><?php echo esc_html( ucfirst( (string) ( $campaign['channel'] ?? 'email' ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $campaign['status'] ?? '' ) ); ?></td>
								<td>
									<?php
									echo esc_html( sprintf(
										/* translators: 1: total, 2: email queued, 3: messages queued */
										__( 'Total: %1$d · Email: %2$d · Msg: %3$d', 'atora-lms' ),
										absint( $campaign['contacts_total'] ?? 0 ),
										absint( $campaign['queued_email'] ?? 0 ),
										absint( $campaign['queued_message'] ?? 0 )
									) );
									?>
								</td>
								<td><?php echo esc_html( (string) ( $campaign['created_at'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</section>

</div><?php /* end #atora-crm-v2-segmentor */ ?>
