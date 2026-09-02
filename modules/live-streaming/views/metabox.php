<?php
/**
 * Metabox de clase en vivo — C4 (sprint 6.13.1)
 *
 * Sin esta vista, P6/P7/P8 (6.13.0) eran infraestructura inalcanzable:
 * no existía ninguna pantalla donde un docente eligiera proveedor,
 * fijara horario o viera asistencia. Incluida por
 * Live_Streaming::render_metabox() — $post, $session y $provider ya
 * están definidos en ese scope.
 *
 * @package ATORA_LMS\LiveStreaming
 * @var \WP_Post $post
 * @var array    $session
 * @var string   $provider
 */

use ATORA\LiveStreaming\Live_Streaming;
use ATORA\LiveStreaming\Live_Session_Repository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$capabilities   = Live_Streaming::get_provider_capabilities();
$session_row    = Live_Session_Repository::get_by_lesson( $post->ID );
$attendance     = $session_row ? Live_Session_Repository::get_attendance_for_session( (int) $session_row['id'] ) : array();
$is_meet        = 'meet' === $provider;
$meet_orphaned  = $is_meet && ( empty( $session_row['external_id'] ) || empty( $session_row['created_by_user_id'] ) );
// C4 (6.13.1): comprobación local (sin red) — ver is_connected() para
// por qué no se usa get_valid_access_token() acá.
$google_connected = class_exists( '\ATORA\Calendar\Calendar_Sync' ) && \ATORA\Calendar\Calendar_Sync::is_connected( get_current_user_id() );
?>
<div class="atora-live-metabox" style="display:grid;gap:16px;max-width:720px">

	<div style="font-size:12px;color:<?php echo $google_connected ? '#166534' : '#991b1b'; ?>">
		<?php if ( $google_connected ) : ?>
			✓ <?php esc_html_e( 'Google conectado.', 'atora-lms' ); ?>
		<?php else : ?>
			✕ <?php esc_html_e( 'Google no conectado — necesario para crear clases con Meet.', 'atora-lms' ); ?>
			<?php if ( class_exists( '\ATORA\Google\Google_Module' ) && \ATORA\Google\Google_Module::get_authorize_url() ) : ?>
				<a href="<?php echo esc_url( \ATORA\Google\Google_Module::get_authorize_url() ); ?>"><?php esc_html_e( 'Autorizar →', 'atora-lms' ); ?></a>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div>
		<label style="font-weight:600;display:block;margin-bottom:6px"><?php esc_html_e( 'Proveedor', 'atora-lms' ); ?></label>
		<div style="display:grid;gap:8px">
			<?php foreach ( Live_Streaming::PROVIDER_LABELS as $slug => $label ) :
				$supports_attendance = in_array( 'attendance', $capabilities[ $slug ] ?? array(), true );
			?>
				<label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer">
					<input type="radio" name="atora_live_provider" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $provider, $slug ); ?>>
					<span><strong><?php echo esc_html( $label ); ?></strong></span>
					<?php if ( ! $supports_attendance ) : ?>
						<span style="color:#94a3b8;font-size:12px;margin-left:auto"><?php esc_html_e( 'sin asistencia automática', 'atora-lms' ); ?></span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
		<label>
			<?php esc_html_e( 'Inicio', 'atora-lms' ); ?>
			<input type="datetime-local" name="atora_live_start" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( (string) ( $session['start_datetime'] ?? '' ), 0, 16 ) ) ); ?>" style="display:block;width:100%">
		</label>
		<label>
			<?php esc_html_e( 'Duración (min)', 'atora-lms' ); ?>
			<input type="number" name="atora_live_duration" min="15" step="5" value="<?php echo esc_attr( (string) ( $session['duration_minutes'] ?? 60 ) ); ?>" style="display:block;width:100%">
		</label>
		<label>
			<?php esc_html_e( 'Zona horaria', 'atora-lms' ); ?>
			<input type="text" name="atora_live_tz" value="<?php echo esc_attr( (string) ( $session['timezone'] ?? 'UTC' ) ); ?>" style="display:block;width:100%">
		</label>
		<label>
			<?php esc_html_e( 'Máx. participantes (opcional)', 'atora-lms' ); ?>
			<input type="number" name="atora_live_max" min="0" value="<?php echo esc_attr( (string) ( $session['max_participants'] ?? 0 ) ); ?>" style="display:block;width:100%">
		</label>
	</div>

	<label style="display:flex;align-items:center;gap:8px">
		<input type="checkbox" name="atora_live_record" value="1" <?php checked( ! empty( $session['record'] ) ); ?>>
		<?php esc_html_e( 'Grabar la sesión (si el proveedor lo soporta)', 'atora-lms' ); ?>
	</label>

	<div id="atora-live-custom-url-wrap" style="<?php echo 'custom' === $provider ? '' : 'display:none'; ?>">
		<label>
			<?php esc_html_e( 'URL de la reunión', 'atora-lms' ); ?>
			<input type="url" name="atora_live_custom_url" value="<?php echo esc_attr( (string) ( $session['custom_url'] ?? '' ) ); ?>" style="display:block;width:100%" placeholder="https://...">
		</label>
	</div>
	<script>
	(function(){
		var radios = document.querySelectorAll('input[name="atora_live_provider"]');
		var wrap = document.getElementById('atora-live-custom-url-wrap');
		radios.forEach(function(r){
			r.addEventListener('change', function(){
				wrap.style.display = ( r.value === 'custom' && r.checked ) ? '' : ( document.querySelector('input[name="atora_live_provider"]:checked').value === 'custom' ? '' : 'none' );
			});
		});
	})();
	</script>

	<?php if ( ! empty( $session['join_url'] ) ) : ?>
		<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px">
			<p style="margin:0 0 6px"><strong><?php esc_html_e( 'Enlace de la clase', 'atora-lms' ); ?></strong></p>
			<input type="text" readonly value="<?php echo esc_attr( (string) $session['join_url'] ); ?>" onclick="this.select();" style="width:100%;font-family:monospace;font-size:12px">
			<p style="margin:6px 0 0;color:#64748b;font-size:12px">
				<?php echo esc_html( sprintf(
					/* translators: 1: external_id, 2: estado */
					__( 'ID: %1$s — estado: %2$s', 'atora-lms' ),
					(string) ( $session['meeting_id'] ?? $session['external_id'] ?? '—' ),
					(string) ( $session_row['status'] ?? 'scheduled' )
				) ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $is_meet ) : ?>
		<?php if ( ! $google_connected ) : ?>
			<div class="notice notice-warning inline" style="margin:0">
				<p>
					<?php esc_html_e( 'Tu cuenta no tiene Google conectado — Meet necesita Calendar autorizado para crear la reunión.', 'atora-lms' ); ?>
					<?php if ( class_exists( '\ATORA\Google\Google_Module' ) && \ATORA\Google\Google_Module::get_authorize_url() ) : ?>
						<a href="<?php echo esc_url( \ATORA\Google\Google_Module::get_authorize_url() ); ?>"><?php esc_html_e( 'Autorizar Google →', 'atora-lms' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		<?php elseif ( $meet_orphaned && ! empty( $session['provider'] ) ) : ?>
			<div class="notice notice-error inline" style="margin:0">
				<p>
					<?php esc_html_e( 'Esta clase de Meet no fue creada por ATORA (o el link se pegó a mano) — la API de Meet solo devuelve conferencias donde el organizador es esta app. Nunca habrá asistencia automática para esta sesión: bórrala y crea una nueva desde este mismo formulario.', 'atora-lms' ); ?>
				</p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $session_row ) : ?>
		<div>
			<h4 style="margin:0 0 8px"><?php esc_html_e( 'Asistencia', 'atora-lms' ); ?></h4>
			<?php if ( ! $attendance ) : ?>
				<p style="color:#64748b;font-size:13px"><?php esc_html_e( 'Todavía no hay asistencia registrada.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="font-size:13px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Entrada', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Salida', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Duración', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $attendance as $row ) :
						$user_id = (int) ( $row['user_id'] ?? 0 );
						$user    = $user_id ? get_userdata( $user_id ) : false;
						$name    = $user
							? $user->display_name
							: sprintf(
								/* translators: %s: nombre de pantalla del participante anónimo */
								__( 'No identificado — %s', 'atora-lms' ),
								$row['display_name'] ? $row['display_name'] : __( 'sin nombre', 'atora-lms' )
							);
					?>
						<tr>
							<td><?php echo esc_html( $name ); ?></td>
							<td><?php echo esc_html( (string) ( $row['joined_at'] ?? '—' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['left_at'] ?? '—' ) ); ?></td>
							<td><?php echo esc_html( gmdate( 'i:s', (int) ( $row['duration_seconds'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
