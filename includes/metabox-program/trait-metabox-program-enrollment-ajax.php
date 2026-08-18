<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Program_Enrollment_Ajax_Trait {
	public function render_enrollment_box( $post ) {
		if ( ! $post || 'lm_program' !== $post->post_type ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			return;
		}

		$program_id = absint( $post->ID );
		$nonce      = wp_create_nonce( 'clms_program_enrollment_nonce' );
		$ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );

		$student_ids = array();
		if ( class_exists( 'CLMS_Helper' ) ) {
			$raw         = get_post_meta( $program_id, CLMS_Helper::PROGRAM_ENROLLED_USERS_META, true );
			$student_ids = is_array( $raw ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : array();
		}
		$students = array();
		foreach ( $student_ids as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$students[] = array( 'id' => $uid, 'display_name' => $u->display_name, 'email' => $u->user_email );
			}
		}

		$linked_product_id = (int) get_post_meta( $program_id, '_clms_program_linked_product_id', true );
		$em = class_exists( 'CLMS_Enrollment_Manager' ) ? new CLMS_Enrollment_Manager() : null;
		$invitations = $em && method_exists( $em, 'get_program_invitations' )
			? (array) $em->get_program_invitations( $program_id )
			: array();
		?>
		<style>
		#clms-program-enrollment-box .clmsp-panel{display:none;padding:14px 0 6px}
		#clms-program-enrollment-box .clmsp-panel.active{display:block}
		#clms-program-enrollment-box .clms-row{display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap}
		#clms-program-enrollment-box .clms-row input[type=text],
		#clms-program-enrollment-box .clms-row input[type=email],
		#clms-program-enrollment-box .clms-row input[type=file]{flex:1;min-width:160px;padding:6px 10px;border:1px solid var(--clms-border,#ddd);border-radius:6px;font-size:13px;background:var(--clms-bg,#fff);color:var(--clms-ink,#1d2327)}
		#clms-program-enrollment-box .clms-btn{padding:7px 14px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;transition:opacity .15s}
		#clms-program-enrollment-box .clms-btn:hover{opacity:.85}
		#clms-program-enrollment-box .clms-student-list{margin:0;padding:0;list-style:none;max-height:300px;overflow-y:auto;border:1px solid var(--clms-border-soft,#e5e7eb);border-radius:6px}
		#clms-program-enrollment-box .clms-student-list li{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid var(--clms-border-soft,#e5e7eb);font-size:13px;color:var(--clms-ink,#1d2327)}
		#clms-program-enrollment-box .clms-student-list li:last-child{border-bottom:none}
		#clms-program-enrollment-box .clms-student-email{color:var(--clms-muted,#6b7280);margin-left:6px}
		#clms-program-enrollment-box .clmsp-msg{padding:8px 12px;border-radius:6px;font-size:13px;margin:8px 0;display:none}
		#clms-program-enrollment-box .clms-p-desc{font-size:13px;color:var(--clms-ink-2,#374151);margin:0 0 10px}
		#clms-program-enrollment-box .clms-p-hint{font-size:12px;color:var(--clms-muted,#6b7280);margin:4px 0 0}
		#clms-program-enrollment-box .clms-p-empty{color:var(--clms-muted,#6b7280);font-size:13px;margin:0}
		#clms-program-enrollment-box .clms-invite-list{margin:8px 0 0;padding:0;list-style:none;display:grid;gap:8px}
		#clms-program-enrollment-box .clms-invite-list li{border:1px solid var(--clms-border-soft,#e5e7eb);border-radius:8px;padding:8px 10px;background:var(--clms-bg,#fff)}
		#clms-program-enrollment-box .token-url{font-size:12px;color:var(--clms-muted,#6b7280);word-break:break-all}
		#clms-program-enrollment-box pre{background:var(--clms-bg-soft,#f8fafc);padding:10px;border-radius:6px;font-size:12px;max-height:200px;overflow:auto;white-space:pre-wrap}
		</style>

		<div id="clms-program-enrollment-box">

		<ul class="clms-ac-tabs">
			<li><button type="button" class="active" onclick="clmspTab(event,'clmsp-tab-students')"><?php esc_html_e( 'Matriculados', 'atora-lms' ); ?> <span class="clms-badge clms-badge-gray" id="clmsp-count-badge"><?php echo count( $students ); ?></span></button></li>
			<li><button type="button" onclick="clmspTab(event,'clmsp-tab-manual')"><?php esc_html_e( 'Manual', 'atora-lms' ); ?></button></li>
			<li><button type="button" onclick="clmspTab(event,'clmsp-tab-csv')"><?php esc_html_e( 'CSV masivo', 'atora-lms' ); ?></button></li>
			<li><button type="button" onclick="clmspTab(event,'clmsp-tab-invites')"><?php esc_html_e( 'Invitaciones', 'atora-lms' ); ?> <span class="clms-badge clms-badge-gray"><?php echo count( $invitations ); ?></span></button></li>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
			<li><button type="button" onclick="clmspTab(event,'clmsp-tab-woo')"><?php esc_html_e( 'WooCommerce', 'atora-lms' ); ?></button></li>
			<?php endif; ?>
		</ul>

		<?php /* ── TAB: MATRICULADOS ── */ ?>
		<div id="clmsp-tab-students" class="clmsp-panel active">
			<div class="clmsp-msg" id="clmsp-msg-students"></div>
			<?php if ( empty( $students ) ) : ?>
				<p class="clms-p-empty"><em><?php esc_html_e( 'Ningún estudiante matriculado aún.', 'atora-lms' ); ?></em></p>
			<?php else : ?>
				<ul class="clms-student-list" id="clmsp-student-list">
					<?php foreach ( $students as $s ) : ?>
					<li id="clmsp-srow-<?php echo esc_attr( $s['id'] ); ?>">
						<span>
							<strong><?php echo esc_html( $s['display_name'] ); ?></strong>
							<span class="clms-student-email"><?php echo esc_html( $s['email'] ); ?></span>
						</span>
						<button type="button" class="clms-btn clms-btn-red"
							onclick="clmspUnenroll(<?php echo esc_js( $program_id ); ?>,<?php echo esc_js( $s['id'] ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
							<?php esc_html_e( 'Quitar', 'atora-lms' ); ?>
						</button>
					</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php /* ── TAB: MANUAL ── */ ?>
		<div id="clmsp-tab-manual" class="clmsp-panel">
			<p class="clms-p-desc"><?php esc_html_e( 'Matricula por email o nombre de usuario. Se inscribe también en todos los cursos del programa.', 'atora-lms' ); ?></p>
			<div class="clms-row">
				<input type="text" id="clmsp-manual-id" placeholder="<?php esc_attr_e( 'Email o usuario', 'atora-lms' ); ?>">
				<label style="font-size:12px;white-space:nowrap">
					<input type="checkbox" id="clmsp-manual-create"> <?php esc_html_e( 'Crear cuenta si no existe', 'atora-lms' ); ?>
				</label>
				<button type="button" class="clms-btn clms-btn-blue"
					onclick="clmspEnroll(<?php echo esc_js( $program_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
					<?php esc_html_e( 'Matricular', 'atora-lms' ); ?>
				</button>
			</div>
			<div class="clmsp-msg" id="clmsp-msg-manual"></div>
			<p class="clms-p-hint"><?php esc_html_e( 'Con "Crear cuenta" se genera usuario con ese email y se envían credenciales por correo.', 'atora-lms' ); ?></p>
		</div>

		<?php /* ── TAB: CSV ── */ ?>
		<div id="clmsp-tab-csv" class="clmsp-panel">
			<p class="clms-p-desc"><?php esc_html_e( 'Sube un CSV con emails para matricular en lote. Formato: email, nombre, apellido, whatsapp, telegram, telefono, pais, ciudad, estado, sexo, edad (campos extra opcionales).', 'atora-lms' ); ?></p>
			<div class="clms-row">
				<input type="file" id="clmsp-csv-file" accept=".csv,.txt">
				<label style="font-size:12px;white-space:nowrap">
					<input type="checkbox" id="clmsp-csv-create" checked> <?php esc_html_e( 'Crear cuentas nuevas', 'atora-lms' ); ?>
				</label>
				<label style="font-size:12px;white-space:nowrap">
					<input type="checkbox" id="clmsp-csv-notify-existing"> <?php esc_html_e( 'Enviar correo a usuarios existentes', 'atora-lms' ); ?>
				</label>
				<button type="button" class="clms-btn clms-btn-green"
					onclick="clmspProcessCSV(<?php echo esc_js( $program_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
					<?php esc_html_e( 'Importar', 'atora-lms' ); ?>
				</button>
			</div>
			<div class="clmsp-msg" id="clmsp-msg-csv"></div>
			<pre id="clmsp-csv-result" style="display:none"></pre>
		</div>

		<?php /* ── TAB: INVITACIONES ── */ ?>
		<div id="clmsp-tab-invites" class="clmsp-panel">
			<p class="clms-p-desc"><?php esc_html_e( 'Envía un enlace de invitación nominal para este programa.', 'atora-lms' ); ?></p>
			<div class="clms-row">
				<input type="email" id="clmsp-inv-email" placeholder="<?php esc_attr_e( 'Email del invitado', 'atora-lms' ); ?>">
				<select id="clmsp-inv-expires">
					<option value="24"><?php esc_html_e( 'Expira en 24h', 'atora-lms' ); ?></option>
					<option value="72" selected><?php esc_html_e( 'Expira en 72h', 'atora-lms' ); ?></option>
					<option value="168"><?php esc_html_e( 'Expira en 7 días', 'atora-lms' ); ?></option>
					<option value="0"><?php esc_html_e( 'Sin expiración', 'atora-lms' ); ?></option>
				</select>
				<label style="font-size:12px;white-space:nowrap">
					<input type="checkbox" id="clmsp-inv-send" checked> <?php esc_html_e( 'Enviar email', 'atora-lms' ); ?>
				</label>
				<button type="button" class="clms-btn clms-btn-blue"
					onclick="clmspSendInvite(<?php echo esc_js( $program_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
					<?php esc_html_e( 'Generar invitación', 'atora-lms' ); ?>
				</button>
			</div>
			<div class="clmsp-msg" id="clmsp-msg-invites"></div>

			<?php if ( ! empty( $invitations ) ) : ?>
				<p class="clms-p-hint"><?php esc_html_e( 'Historial de invitaciones de este programa.', 'atora-lms' ); ?></p>
				<ul class="clms-invite-list" id="clmsp-invite-list">
					<?php foreach ( $invitations as $inv ) : ?>
						<?php
						$status      = sanitize_key( (string) ( $inv->status ?? 'active' ) );
						$badge_class = 'active' === $status ? 'green' : ( 'exhausted' === $status ? 'yellow' : ( 'revoked' === $status ? 'red' : 'gray' ) );
						$badge_label = array(
							'active'    => __( 'Activa', 'atora-lms' ),
							'exhausted' => __( 'Agotada', 'atora-lms' ),
							'revoked'   => __( 'Revocada', 'atora-lms' ),
							'expired'   => __( 'Caducada', 'atora-lms' ),
						);
						?>
						<li id="clmsp-inv-row-<?php echo esc_attr( (string) $inv->token ); ?>">
							<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px">
								<div>
									<?php if ( ! empty( $inv->invited_email ) ) : ?>
										<strong><?php echo esc_html( (string) $inv->invited_email ); ?></strong> —
									<?php endif; ?>
									<span class="clms-badge clms-badge-<?php echo esc_attr( $badge_class ); ?>">
										<?php echo esc_html( $badge_label[ $status ] ?? $status ); ?>
									</span>
									<span class="clms-meta-muted"><?php echo esc_html( absint( $inv->used_count ) . '/' . absint( $inv->max_uses ) ); ?> <?php esc_html_e( 'usos', 'atora-lms' ); ?></span>
									<?php if ( ! empty( $inv->expires_at ) ) : ?>
										<span class="clms-meta-muted"> · <?php esc_html_e( 'Expira:', 'atora-lms' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $inv->expires_at ) ) ); ?></span>
									<?php endif; ?>
								</div>
								<?php if ( 'active' === $status ) : ?>
									<button type="button" class="clms-btn clms-btn-red"
										onclick="clmspRevokeInvite('<?php echo esc_js( (string) $inv->token ); ?>','<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
										<?php esc_html_e( 'Revocar', 'atora-lms' ); ?>
									</button>
								<?php endif; ?>
							</div>
							<div class="token-url" style="margin-top:4px"><?php echo esc_html( add_query_arg( 'clms_invite', rawurlencode( (string) $inv->token ), home_url( '/' ) ) ); ?></div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p id="clmsp-invite-list-empty" class="clms-p-empty"><em><?php esc_html_e( 'No hay invitaciones generadas para este programa.', 'atora-lms' ); ?></em></p>
				<ul class="clms-invite-list" id="clmsp-invite-list" style="display:none"></ul>
			<?php endif; ?>
		</div>

		<?php if ( class_exists( 'WooCommerce' ) ) : ?>
		<?php /* ── TAB: WOOCOMMERCE ── */ ?>
		<div id="clmsp-tab-woo" class="clmsp-panel">
			<p class="clms-p-desc"><?php esc_html_e( 'Vincula un producto de WooCommerce a este programa. Al comprarlo, el cliente queda matriculado automáticamente.', 'atora-lms' ); ?></p>
			<div class="clms-row">
				<input type="number" id="clmsp-woo-product-id" min="0"
					value="<?php echo esc_attr( $linked_product_id ?: '' ); ?>"
					placeholder="<?php esc_attr_e( 'ID del producto', 'atora-lms' ); ?>"
					style="width:160px;flex:none;padding:6px 10px;border:1px solid var(--clms-border,#ddd);border-radius:6px;font-size:13px;background:var(--clms-bg,#fff);color:var(--clms-ink,#1d2327)">
				<button type="button" class="clms-btn clms-btn-blue"
					onclick="clmspSaveWoo(<?php echo esc_js( $program_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
					<?php esc_html_e( 'Guardar', 'atora-lms' ); ?>
				</button>
				<?php if ( $linked_product_id ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $linked_product_id ) ); ?>" target="_blank" style="font-size:12px">
						<?php echo esc_html( get_the_title( $linked_product_id ) ); ?> →
					</a>
				<?php endif; ?>
			</div>
			<div class="clmsp-msg" id="clmsp-msg-woo"></div>
			<p class="clms-p-hint"><?php esc_html_e( 'Introduce el ID numérico del producto en WooCommerce.', 'atora-lms' ); ?></p>
		</div>
		<?php endif; ?>

		</div><!-- #clms-program-enrollment-box -->

		<script>
		(function(){
			// Tab switcher con scope a este metabox
			window.clmspTab = function(e, tabId) {
				e.preventDefault();
				var box = document.getElementById('clms-program-enrollment-box');
				box.querySelectorAll('.clmsp-panel').forEach(function(el){ el.classList.remove('active'); });
				box.querySelectorAll('.clms-ac-tabs button').forEach(function(b){ b.classList.remove('active'); });
				var panel = document.getElementById(tabId);
				if (panel) panel.classList.add('active');
				e.currentTarget.classList.add('active');
			};

			var PID  = <?php echo esc_js( $program_id ); ?>;
			var NC   = '<?php echo esc_js( $nonce ); ?>';
			var AJAX = '<?php echo esc_js( $ajax_url ); ?>';

			window.clmspEnroll = function(programId, nonce, ajaxUrl) {
				var id     = document.getElementById('clmsp-manual-id').value.trim();
				var create = document.getElementById('clmsp-manual-create').checked ? 1 : 0;
				var msgEl  = document.getElementById('clmsp-msg-manual');
				if (!id) { clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Introduce un email o usuario.', 'atora-lms' ) ); ?>'); return; }
				var fd = new FormData();
				fd.append('action','clms_enroll_user_program');
				fd.append('program_id', programId);
				fd.append('user_login', id);
				fd.append('create_missing', create);
				fd.append('nonce', nonce);
				fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
					if (data.success) {
						clmspMsg(msgEl,'success',data.data.message);
						clmspAddRow(data.data, programId, nonce, ajaxUrl);
						document.getElementById('clmsp-manual-id').value = '';
					} else {
						clmspMsg(msgEl,'error',data.data||'<?php echo esc_js( __( 'Error.', 'atora-lms' ) ); ?>');
					}
				}).catch(function(){ clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>'); });
			};

			window.clmspUnenroll = function(programId, userId, nonce, ajaxUrl) {
				if (!confirm('<?php echo esc_js( __( '¿Quitar la matrícula de este estudiante del programa?', 'atora-lms' ) ); ?>')) return;
				var msgEl = document.getElementById('clmsp-msg-students');
				var fd = new FormData();
				fd.append('action','clms_unenroll_user_program');
				fd.append('program_id', programId);
				fd.append('user_id', userId);
				fd.append('nonce', nonce);
				fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
					if (data.success) {
						var row = document.getElementById('clmsp-srow-'+userId);
						if (row) row.remove();
						clmspBadge(-1);
						clmspMsg(msgEl,'success',data.data.message);
					} else {
						clmspMsg(msgEl,'error',data.data||'<?php echo esc_js( __( 'Error.', 'atora-lms' ) ); ?>');
					}
				}).catch(function(){ clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>'); });
			};

			window.clmspProcessCSV = function(programId, nonce, ajaxUrl) {
				var file  = document.getElementById('clmsp-csv-file').files[0];
				var create = document.getElementById('clmsp-csv-create').checked ? 1 : 0;
				var notifyExisting = document.getElementById('clmsp-csv-notify-existing').checked ? 1 : 0;
				var msgEl = document.getElementById('clmsp-msg-csv');
				var pre   = document.getElementById('clmsp-csv-result');
				if (!file) { clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Selecciona un archivo CSV.', 'atora-lms' ) ); ?>'); return; }
				var fd = new FormData();
				fd.append('action','clms_enroll_csv_program');
				fd.append('program_id', programId);
				fd.append('create_missing', create);
				fd.append('notify_existing', notifyExisting);
				fd.append('nonce', nonce);
				fd.append('csv_file', file);
				clmspMsg(msgEl,'info','<?php echo esc_js( __( 'Procesando…', 'atora-lms' ) ); ?>');
				fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
					if (data.success) {
						var d = data.data;
						var notified = (typeof d.notified_existing !== 'undefined') ? d.notified_existing : 0;
						clmspMsg(msgEl,'success','<?php echo esc_js( __( 'Procesado:', 'atora-lms' ) ); ?> '+d.enrolled+' <?php echo esc_js( __( 'matriculados', 'atora-lms' ) ); ?>, '+d.skipped+' <?php echo esc_js( __( 'ya inscritos', 'atora-lms' ) ); ?>, '+d.errors+' <?php echo esc_js( __( 'errores', 'atora-lms' ) ); ?>, '+notified+' <?php echo esc_js( __( 'correos a existentes', 'atora-lms' ) ); ?>.');
						pre.style.display = 'block';
						pre.textContent = d.log.join('\n');
					} else {
						clmspMsg(msgEl,'error',data.data||'Error');
					}
				}).catch(function(){ clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>'); });
			};

			window.clmspSendInvite = function(programId, nonce, ajaxUrl) {
				var email   = document.getElementById('clmsp-inv-email').value.trim();
				var expires = document.getElementById('clmsp-inv-expires').value;
				var sendNow = document.getElementById('clmsp-inv-send').checked ? 1 : 0;
				var msgEl   = document.getElementById('clmsp-msg-invites');

				if (!email) {
					clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Escribe un email.', 'atora-lms' ) ); ?>');
					return;
				}

				var fd = new FormData();
				fd.append('action', 'clms_em_send_invitation');
				fd.append('program_id', programId);
				fd.append('email', email);
				fd.append('expires_hours', expires);
				fd.append('max_uses', 1);
				fd.append('send_now', sendNow);
				fd.append('nonce', nonce);

				fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
					if (data.success) {
						clmspMsg(msgEl,'success',data.data.message);
						document.getElementById('clmsp-inv-email').value = '';

						var list = document.getElementById('clmsp-invite-list');
						var empty = document.getElementById('clmsp-invite-list-empty');
						if (empty) { empty.remove(); }

						if (list) {
							list.style.display = '';
							var li = document.createElement('li');
							li.id = 'clmsp-inv-row-' + data.data.token;
							li.innerHTML =
								'<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px">'
								+ '<div><strong>' + clmspEsc(email) + '</strong> — <span class="clms-badge clms-badge-green"><?php echo esc_js( __( 'Activa', 'atora-lms' ) ); ?></span> '
								+ '<span class="clms-meta-muted">0/1 <?php echo esc_js( __( 'usos', 'atora-lms' ) ); ?></span></div>'
								+ '<button type="button" class="clms-btn clms-btn-red" onclick="clmspRevokeInvite(\'' + data.data.token + '\',\'' + nonce + '\',\'' + ajaxUrl + '\')"><?php echo esc_js( __( 'Revocar', 'atora-lms' ) ); ?></button>'
								+ '</div>'
								+ '<div class="token-url" style="margin-top:4px">' + clmspEsc(data.data.url) + '</div>';
							list.prepend(li);
						}
					} else {
						clmspMsg(msgEl,'error',data.data && data.data.message ? data.data.message : '<?php echo esc_js( __( 'Error al crear invitación.', 'atora-lms' ) ); ?>');
					}
				}).catch(function(){ clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>'); });
			};

			window.clmspRevokeInvite = function(token, nonce, ajaxUrl) {
				if (!confirm('<?php echo esc_js( __( '¿Revocar esta invitación?', 'atora-lms' ) ); ?>')) return;
				var msgEl = document.getElementById('clmsp-msg-invites');
				var fd = new FormData();
				fd.append('action','clms_em_revoke_invitation');
				fd.append('token', token);
				fd.append('nonce', nonce);
				fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
					if (data.success) {
						var row = document.getElementById('clmsp-inv-row-' + token);
						if (row) {
							row.style.opacity = '.5';
							var btn = row.querySelector('button');
							if (btn) btn.remove();
						}
						clmspMsg(msgEl,'success',data.data.message);
					} else {
						clmspMsg(msgEl,'error',data.data && data.data.message ? data.data.message : '<?php echo esc_js( __( 'No se pudo revocar la invitación.', 'atora-lms' ) ); ?>');
					}
				}).catch(function(){ clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>'); });
			};

			window.clmspSaveWoo = function(programId, nonce, ajaxUrl) {
				var pid   = parseInt(document.getElementById('clmsp-woo-product-id').value) || 0;
				var msgEl = document.getElementById('clmsp-msg-woo');
				var fd = new FormData();
				fd.append('action','clms_save_program_woo');
				fd.append('program_id', programId);
				fd.append('product_id', pid);
				fd.append('nonce', nonce);
				fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
					if (data.success) {
						clmspMsg(msgEl,'success',data.data.message);
					} else {
						clmspMsg(msgEl,'error',data.data||'Error');
					}
				}).catch(function(){ clmspMsg(msgEl,'error','<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>'); });
			};

			function clmspAddRow(d, programId, nonce, ajaxUrl) {
				var list = document.getElementById('clmsp-student-list');
				if (!list) {
					var empty = document.querySelector('#clmsp-tab-students .clms-p-empty');
					if (empty) empty.remove();
					list = document.createElement('ul');
					list.id = 'clmsp-student-list';
					list.className = 'clms-student-list';
					document.getElementById('clmsp-tab-students').appendChild(list);
				}
				if (document.getElementById('clmsp-srow-'+d.user_id)) return;
				var li = document.createElement('li');
				li.id = 'clmsp-srow-'+d.user_id;
				li.innerHTML = '<span><strong>'+clmspEsc(d.display_name)+'</strong><span class="clms-student-email">'+clmspEsc(d.email)+'</span></span>'
					+'<button type="button" class="clms-btn clms-btn-red" onclick="clmspUnenroll('+programId+','+d.user_id+',\''+nonce+'\',\''+ajaxUrl+'\')">'+
					'<?php echo esc_js( __( 'Quitar', 'atora-lms' ) ); ?>'+'</button>';
				list.appendChild(li);
				clmspBadge(1);
			}

			function clmspBadge(delta) {
				var b = document.getElementById('clmsp-count-badge');
				if (b) b.textContent = Math.max(0, parseInt(b.textContent||'0') + delta);
			}

			function clmspMsg(el, type, msg) {
				if (!el) return;
				el.style.display  = 'block';
				el.style.background = type === 'success' ? '#dcfce7' : (type === 'info' ? '#eff6ff' : '#fee2e2');
				el.style.color      = type === 'success' ? '#166534' : (type === 'info' ? '#1e40af' : '#991b1b');
				el.textContent = msg;
				if (type !== 'info') setTimeout(function(){ el.style.display = 'none'; }, 6000);
			}

			function clmspEsc(s) {
				return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}
		})();
		</script>
		<?php
	}

	public function ajax_enroll_user_program() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			wp_send_json_error( __( 'Sin permisos.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_program_enrollment_nonce' ) ) {
			wp_send_json_error( __( 'Nonce inválido.', 'atora-lms' ) );
		}

		$program_id     = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$user_login     = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
		$create_missing = ! empty( $_POST['create_missing'] );

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			wp_send_json_error( __( 'Programa inválido.', 'atora-lms' ) );
		}

		$user = get_user_by( 'email', $user_login );
		if ( ! $user ) {
			$user = get_user_by( 'login', $user_login );
		}

		$is_new_user = false;
		if ( ! $user && $create_missing && is_email( $user_login ) ) {
			$student_role = get_role( 'lms_student' ) ? 'lms_student' : 'subscriber';
			$password = wp_generate_password();
			$user_id  = wp_create_user( $user_login, $password, $user_login );
			if ( is_wp_error( $user_id ) ) {
				wp_send_json_error( $user_id->get_error_message() );
			}
			wp_update_user( array( 'ID' => $user_id, 'role' => $student_role ) );
			wp_new_user_notification( $user_id, null, 'user' );
			$user        = get_userdata( $user_id );
			$is_new_user = true;
		}

		if ( ! $user ) {
			wp_send_json_error( __( 'Usuario no encontrado. Usa email o nombre de usuario exactos.', 'atora-lms' ) );
		}

		// Usuarios existentes sin rol LMS reciben lms_student
		if ( ! $is_new_user && $user->roles && ! array_intersect( $user->roles, array( 'lms_student', 'administrator', 'editor' ) ) ) {
			$student_role = get_role( 'lms_student' ) ? 'lms_student' : 'subscriber';
			wp_update_user( array( 'ID' => $user->ID, 'role' => $student_role ) );
		}

		if ( ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'enroll_user_in_program' ) ) {
			wp_send_json_error( __( 'Sistema de matrícula no disponible.', 'atora-lms' ) );
		}

		$result = CLMS_Helper::enroll_user_in_program( $user->ID, $program_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'message'      => sprintf( __( '%s matriculado/a en el programa correctamente.', 'atora-lms' ), $user->display_name ),
			'user_id'      => $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
		) );
	}

	public function ajax_unenroll_user_program() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			wp_send_json_error( __( 'Sin permisos.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_program_enrollment_nonce' ) ) {
			wp_send_json_error( __( 'Nonce inválido.', 'atora-lms' ) );
		}

		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$user_id    = isset( $_POST['user_id'] )    ? absint( $_POST['user_id'] )    : 0;

		if ( ! $program_id || ! $user_id ) {
			wp_send_json_error( __( 'Datos inválidos.', 'atora-lms' ) );
		}

		// Quitar programa del usuario
		$user_programs = (array) get_user_meta( $user_id, CLMS_Helper::USER_ENROLLED_PROGRAMS_META, true );
		$user_programs = array_values( array_diff( array_map( 'absint', $user_programs ), array( $program_id ) ) );
		update_user_meta( $user_id, CLMS_Helper::USER_ENROLLED_PROGRAMS_META, $user_programs );

		// Quitar usuario del programa
		$program_users = (array) get_post_meta( $program_id, CLMS_Helper::PROGRAM_ENROLLED_USERS_META, true );
		$program_users = array_values( array_diff( array_map( 'absint', $program_users ), array( $user_id ) ) );
		update_post_meta( $program_id, CLMS_Helper::PROGRAM_ENROLLED_USERS_META, $program_users );

		$user = get_userdata( $user_id );
		$name = $user ? $user->display_name : "ID $user_id";

		wp_send_json_success( array(
			'message' => sprintf( __( 'Matrícula de %s eliminada del programa.', 'atora-lms' ), $name ),
		) );
	}

	public function ajax_enroll_csv_program() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			wp_send_json_error( __( 'Sin permisos.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_program_enrollment_nonce' ) ) {
			wp_send_json_error( __( 'Nonce inválido.', 'atora-lms' ) );
		}

		$program_id     = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$create_missing = ! empty( $_POST['create_missing'] );
		$notify_existing = ! empty( $_POST['notify_existing'] );

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			wp_send_json_error( __( 'Programa inválido.', 'atora-lms' ) );
		}

		if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( __( 'Archivo CSV requerido.', 'atora-lms' ) );
		}

		$ext = strtolower( pathinfo( sanitize_file_name( $_FILES['csv_file']['name'] ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			wp_send_json_error( __( 'Solo CSV o TXT.', 'atora-lms' ) );
		}

		$enrolled = 0;
		$skipped  = 0;
		$errors   = 0;
		$notified_existing = 0;
		$log      = array();
		$line_number = 0;
		$header_map  = array();

		if ( ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'enroll_user_in_program' ) ) {
			wp_send_json_error( __( 'Sistema de matrícula no disponible.', 'atora-lms' ) );
		}

		$handle = fopen( $_FILES['csv_file']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			wp_send_json_error( __( 'No se pudo abrir el archivo CSV.', 'atora-lms' ) );
		}

		$delimiter = $this->detect_csv_delimiter( (string) $_FILES['csv_file']['tmp_name'] );
		while ( ( $row = fgetcsv( $handle, 1000, $delimiter, '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$line_number++;
			if ( empty( $row ) || ! is_array( $row ) ) {
				continue;
			}
			if ( 1 === $line_number ) {
				$detected_map = $this->detect_student_csv_header_map( $row );
				if ( ! empty( $detected_map['email'] ) ) {
					$header_map = $detected_map;
					continue;
				}
			}

			$email_raw = $this->get_student_csv_column_value( $row, $header_map, 'email', 0 );
			$email     = sanitize_email( $email_raw );
			if ( '' === $email_raw ) {
				continue;
			}
			if ( ! is_email( $email ) ) {
				$errors++;
				$log[] = '✗ Línea ' . $line_number . ' — ' . sprintf( __( 'Email inválido: %s', 'atora-lms' ), $email_raw );
				continue;
			}

			$profile_data = $this->sanitize_student_profile_data(
				array(
					'first_name' => $this->get_student_csv_column_value( $row, $header_map, 'first_name', 1 ),
					'last_name'  => $this->get_student_csv_column_value( $row, $header_map, 'last_name', 2 ),
					'whatsapp'   => $this->get_student_csv_column_value( $row, $header_map, 'whatsapp', 3 ),
					'telegram'   => $this->get_student_csv_column_value( $row, $header_map, 'telegram', 4 ),
					'phone'      => $this->get_student_csv_column_value( $row, $header_map, 'phone', 5 ),
					'country'    => $this->get_student_csv_column_value( $row, $header_map, 'country_code', 6 ),
					'city'       => $this->get_student_csv_column_value( $row, $header_map, 'city', 7 ),
					'state'      => $this->get_student_csv_column_value( $row, $header_map, 'state', 8 ),
					'sex'        => $this->get_student_csv_column_value( $row, $header_map, 'sex', 9 ),
					'age'        => $this->get_student_csv_column_value( $row, $header_map, 'age', 10 ),
				)
			);
			$first = (string) ( $profile_data['first_name'] ?? '' );
			$last  = (string) ( $profile_data['last_name'] ?? '' );

			$user        = get_user_by( 'email', $email );
			$is_new_user = false;

			if ( ! $user && $create_missing ) {
				$student_role = get_role( 'lms_student' ) ? 'lms_student' : 'subscriber';
				$password = wp_generate_password();
				$username = sanitize_user( strstr( $email, '@', true ), true );
				if ( '' === $username ) {
					$username = 'estudiante';
				}
				$base = $username;
				$i    = 1;
				while ( username_exists( $username ) ) {
					$i++;
					$username = $base . $i;
				}
				$uid = wp_create_user( $username, $password, $email );
				if ( is_wp_error( $uid ) ) {
					$errors++;
					$log[] = "✗ $email — " . $uid->get_error_message();
					continue;
				}
				$update = array( 'ID' => $uid, 'role' => $student_role );
				if ( $first ) {
					$update['first_name']   = $first;
					$update['last_name']    = $last;
					$update['display_name'] = trim( "$first $last" );
				}
				wp_update_user( $update );
				wp_new_user_notification( $uid, null, 'user' );
				$user        = get_userdata( $uid );
				$is_new_user = true;
			}

			// Usuarios existentes sin rol LMS reciben lms_student
			if ( $user && ! $is_new_user && $user->roles && ! array_intersect( $user->roles, array( 'lms_student', 'administrator', 'editor' ) ) ) {
				$student_role = get_role( 'lms_student' ) ? 'lms_student' : 'subscriber';
				wp_update_user( array( 'ID' => $user->ID, 'role' => $student_role ) );
			}

			if ( ! $user ) {
				$errors++;
				$log[] = "✗ $email — " . __( 'Usuario no encontrado', 'atora-lms' );
				continue;
			}
			$this->persist_student_contact_meta( (int) $user->ID, $profile_data );

			if ( CLMS_Helper::user_is_enrolled_in_program( $user->ID, $program_id ) ) {
				$skipped++;
				$log[] = "– {$user->display_name} ({$email}) — " . __( 'ya inscrito', 'atora-lms' );
				continue;
			}

			$result = CLMS_Helper::enroll_user_in_program( $user->ID, $program_id );
			if ( is_wp_error( $result ) ) {
				$errors++;
				$log[] = "✗ {$user->display_name} ({$email}) — " . $result->get_error_message();
			} else {
				$enrolled++;
				if ( $notify_existing && ! $is_new_user && $this->send_existing_program_enrollment_email( $user, $program_id ) ) {
					$notified_existing++;
				}
				$log[] = "✓ {$user->display_name} ({$email})";
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		wp_send_json_success( array(
			'enrolled'          => $enrolled,
			'skipped'           => $skipped,
			'errors'            => $errors,
			'notified_existing' => $notified_existing,
			'log'               => $log,
		) );
	}

	/**
	 * Detecta delimitador CSV usando la primera línea.
	 *
	 * @param string $file_path Ruta temporal del CSV.
	 * @return string
	 */
	private function detect_csv_delimiter( string $file_path ): string {
		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return ',';
		}

		$line = '';
		$h    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $h ) {
			$line = (string) fgets( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( '' === $line ) {
			return ',';
		}

		$candidates = array( ';', ',', "\t", '|' );
		$best       = ',';
		$best_count = 0;
		foreach ( $candidates as $candidate ) {
			$count = substr_count( $line, $candidate );
			if ( $count > $best_count ) {
				$best       = $candidate;
				$best_count = $count;
			}
		}

		return $best_count > 0 ? $best : ',';
	}

	/**
	 * Detecta mapeo de cabecera para CSV de estudiantes.
	 *
	 * @param array $row Fila de cabecera.
	 * @return array
	 */
	private function detect_student_csv_header_map( array $row ): array {
		$aliases = array(
			'email'        => array( 'email', 'correo', 'correo_electronico', 'mail', 'e_mail' ),
			'first_name'   => array( 'nombre', 'nombres', 'first_name', 'firstname' ),
			'last_name'    => array( 'apellido', 'apellidos', 'aellidos', 'last_name', 'lastname' ),
			'whatsapp'     => array( 'whatsapp', 'wa' ),
			'telegram'     => array( 'telegram', 'telegram_user', 'telegram_username' ),
			'phone'        => array( 'telefono', 'phone', 'movil', 'celular' ),
			'country_code' => array( 'pais', 'country', 'country_code', 'countrycode' ),
			'city'         => array( 'ciudad', 'city' ),
			'state'        => array( 'estado', 'state', 'provincia', 'region' ),
			'sex'          => array( 'sexo', 'sex', 'genero', 'gender' ),
			'age'          => array( 'edad', 'age' ),
		);
		$map = array();

		foreach ( $row as $index => $raw_label ) {
			$label = strtolower( trim( sanitize_text_field( (string) $raw_label ) ) );
			if ( '' === $label ) {
				continue;
			}
			$label = str_replace(
				array( 'á', 'é', 'í', 'ó', 'ú', 'ñ', ' ', '-', '.' ),
				array( 'a', 'e', 'i', 'o', 'u', 'n', '_', '_', '_' ),
				$label
			);
			foreach ( $aliases as $canonical => $allowed ) {
				if ( in_array( $label, $allowed, true ) ) {
					$map[ $canonical ] = (int) $index;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Obtiene un valor de columna CSV por mapeo o fallback posicional.
	 *
	 * @param array  $row            Fila CSV.
	 * @param array  $header_map     Mapa de cabecera.
	 * @param string $canonical      Clave canónica.
	 * @param int    $fallback_index Índice fallback.
	 * @return string
	 */
	private function get_student_csv_column_value( array $row, array $header_map, string $canonical, int $fallback_index = 0 ): string {
		$canonical = sanitize_key( $canonical );
		if ( isset( $header_map[ $canonical ] ) ) {
			$idx = absint( $header_map[ $canonical ] );
			return isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
		}

		return isset( $row[ $fallback_index ] ) ? trim( (string) $row[ $fallback_index ] ) : '';
	}

	/**
	 * Sanitiza datos de contacto de estudiante.
	 *
	 * @param array $data Datos de entrada.
	 * @return array
	 */
	private function sanitize_student_profile_data( array $data ): array {
		$first_name = sanitize_text_field( (string) ( $data['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $data['last_name'] ?? '' ) );
		$phone      = sanitize_text_field( (string) ( $data['phone'] ?? '' ) );
		$whatsapp   = sanitize_text_field( (string) ( $data['whatsapp'] ?? '' ) );
		$telegram   = sanitize_text_field( (string) ( $data['telegram'] ?? '' ) );
		$country    = strtoupper( sanitize_text_field( (string) ( $data['country_code'] ?? ( $data['country'] ?? '' ) ) ) );
		$city       = sanitize_text_field( (string) ( $data['city'] ?? '' ) );
		$state      = sanitize_text_field( (string) ( $data['state'] ?? '' ) );
		$sex        = sanitize_key( (string) ( $data['sex'] ?? '' ) );
		$age        = absint( $data['age'] ?? 0 );

		$country = preg_replace( '/[^A-Z0-9_]/', '', $country );
		if ( ! is_string( $country ) ) {
			$country = '';
		}
		if ( strlen( $country ) > 5 ) {
			$country = substr( $country, 0, 5 );
		}
		$allowed_sexes = array( 'femenino', 'masculino', 'no_binario', 'prefiero_no_decir' );
		if ( '' !== $sex && ! in_array( $sex, $allowed_sexes, true ) ) {
			$sex = '';
		}
		if ( $age > 120 ) {
			$age = 120;
		}

		if ( '' === $phone && '' !== $whatsapp ) {
			$phone = $whatsapp;
		}
		if ( '' === $whatsapp && '' !== $phone ) {
			$whatsapp = $phone;
		}

		return array_filter(
			array(
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'phone'        => $phone,
				'whatsapp'     => $whatsapp,
				'telegram'     => $telegram,
				'country_code' => $country,
				'city'         => $city,
				'state'        => $state,
				'sex'          => $sex,
				'age'          => $age > 0 ? (string) $age : '',
			),
			static function ( $value ): bool {
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * Guarda datos de contacto en user_meta y sincroniza CRM.
	 *
	 * @param int   $user_id      Usuario.
	 * @param array $profile_data Datos de perfil.
	 * @return void
	 */
	private function persist_student_contact_meta( int $user_id, array $profile_data ): void {
		$user_id      = absint( $user_id );
		$profile_data = $this->sanitize_student_profile_data( $profile_data );
		if ( ! $user_id || empty( $profile_data ) ) {
			return;
		}

		$first_name = (string) ( $profile_data['first_name'] ?? '' );
		$last_name  = (string) ( $profile_data['last_name'] ?? '' );
		if ( '' !== $first_name || '' !== $last_name ) {
			$update = array( 'ID' => $user_id );
			if ( '' !== $first_name ) {
				$update['first_name'] = $first_name;
			}
			if ( '' !== $last_name ) {
				$update['last_name'] = $last_name;
			}
			$display_name = trim( $first_name . ' ' . $last_name );
			if ( '' !== $display_name ) {
				$update['display_name'] = $display_name;
				$update['nickname']     = $display_name;
			}
			wp_update_user( $update );
		}

		$meta_map = array(
			'phone'        => 'atora_phone',
			'whatsapp'     => 'atora_whatsapp',
			'telegram'     => 'atora_telegram',
			'country_code' => 'atora_country_code',
			'city'         => 'atora_city',
			'state'        => 'atora_state',
			'sex'          => 'atora_sex',
			'age'          => 'atora_age',
		);
		foreach ( $meta_map as $key => $meta_key ) {
			if ( isset( $profile_data[ $key ] ) ) {
				update_user_meta( $user_id, $meta_key, $profile_data[ $key ] );
			}
		}

		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'upsert_contact' ) ) {
			$user = get_userdata( $user_id );
			\ATORA\CRM\CRM::upsert_contact(
				array(
					'user_id'  => $user_id,
					'email'    => $user ? (string) $user->user_email : '',
					'name'     => $user ? (string) $user->display_name : '',
					'phone'    => (string) ( $profile_data['phone'] ?? '' ),
					'whatsapp' => (string) ( $profile_data['whatsapp'] ?? '' ),
					'country'  => (string) ( $profile_data['country_code'] ?? '' ),
					'city'     => (string) ( $profile_data['city'] ?? '' ),
					'source'   => 'import',
				)
			);
		}
	}

	/**
	 * Envía confirmación de inscripción a programa para usuarios existentes.
	 *
	 * @param WP_User|false $user       Usuario.
	 * @param int           $program_id Programa.
	 * @return bool
	 */
	private function send_existing_program_enrollment_email( $user, int $program_id ): bool {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$program_id = absint( $program_id );
		if ( $program_id <= 0 ) {
			return false;
		}

		$program_title = get_the_title( $program_id );
		$program_url   = get_permalink( $program_id );
		$subject       = sprintf(
			/* translators: %s: título del programa */
			__( 'Inscripción confirmada: %s', 'atora-lms' ),
			$program_title ? $program_title : __( 'Tu programa', 'atora-lms' )
		);
		$message       = sprintf(
			/* translators: %s: nombre del usuario */
			__( 'Hola %s,', 'atora-lms' ),
			esc_html( $user->display_name )
		);
		$message      .= '<br><br>' . sprintf(
			/* translators: %s: título del programa */
			__( 'Te confirmamos que has sido inscrito/a en %s.', 'atora-lms' ),
			'<strong>' . esc_html( $program_title ) . '</strong>'
		);
		$message      .= '<br><br>' . __( 'Puedes acceder desde tu panel de estudiante.', 'atora-lms' );

		return CLMS_Email::send(
			$user->user_email,
			$subject,
			$message,
			array(
				'headline'    => __( 'Inscripción confirmada', 'atora-lms' ),
				'button_text' => __( 'Ir al programa', 'atora-lms' ),
				'button_url'  => $program_url ? $program_url : wp_login_url(),
			)
		);
	}

	public function ajax_save_program_woo() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			wp_send_json_error( __( 'Sin permisos.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_program_enrollment_nonce' ) ) {
			wp_send_json_error( __( 'Nonce inválido.', 'atora-lms' ) );
		}

		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			wp_send_json_error( __( 'Programa inválido.', 'atora-lms' ) );
		}

		update_post_meta( $program_id, '_clms_program_linked_product_id', $product_id );

		wp_send_json_success( array(
			'message' => $product_id
				? sprintf( __( 'Producto #%d vinculado.', 'atora-lms' ), $product_id )
				: __( 'Producto desvinculado.', 'atora-lms' ),
		) );
	}
}
