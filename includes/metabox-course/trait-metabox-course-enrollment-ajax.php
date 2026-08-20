<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Course_Enrollment_Ajax_Trait {
	public function render_enrollment_box( $post ) {
		if ( ! $post || 'lm_course' !== $post->post_type ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			return;
		}

		$course_id = absint( $post->ID );
		$nonce     = wp_create_nonce( 'clms_enrollment_nonce' );
		$ajax_url  = esc_url( admin_url( 'admin-ajax.php' ) );

		// Datos del enrollment manager.
		$em         = class_exists( 'CLMS_Enrollment_Manager' ) ? new CLMS_Enrollment_Manager() : null;
		$students   = $em ? $em->get_enrolled_students( $course_id ) : array();
		$link_data  = $em ? $em->get_access_link_data( $course_id ) : null;
		$invitations = $em ? $em->get_course_invitations( $course_id ) : array();

		// WooCommerce: producto vinculado.
		$linked_product_id = get_post_meta( $course_id, '_clms_linked_product_id', true );

		$base_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL
			: ATORA_LMS_URL;
		wp_enqueue_script(
			'atora-ui',
			$base_url . 'assets/js/atora-ui.js',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
			true
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'add_ui_i18n_script' ) ) {
			CLMS_Helper::add_ui_i18n_script( 'atora-ui' );
		}

		?>
		<style>
		/* Layout-only — colors come from admin.css (CSS variables, dark-mode-safe) */
		.clms-ac-panel{display:none;padding:16px 0 8px}
		.clms-ac-panel.active{display:block}
		.clms-row{display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap}
		.clms-row input[type=text],.clms-row input[type=email],.clms-row select{flex:1;min-width:160px;padding:6px 10px;border:1px solid var(--clms-border,#ddd);border-radius:6px;font-size:13px;background:var(--clms-bg,#fff);color:var(--clms-ink,#1d2327)}
		.clms-btn{padding:7px 14px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;transition:opacity .15s}
		.clms-btn:hover{opacity:.85}
		.clms-student-list{margin:0;padding:0;list-style:none;max-height:280px;overflow-y:auto;border:1px solid var(--clms-border-soft,#e5e7eb);border-radius:6px}
		.clms-student-list li{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid var(--clms-border-soft,#e5e7eb);font-size:13px;color:var(--clms-ink,#1d2327)}
		.clms-student-list li:last-child{border-bottom:none}
		.clms-student-email{color:var(--clms-muted,#6b7280);margin-left:6px}
		.clms-msg{padding:8px 12px;border-radius:6px;font-size:13px;margin:8px 0;display:none}
		.clms-link-pw-label{font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:var(--clms-ink-2,#374151)}
			.clms-link-copy-btn{margin-left:8px;background:none;border:1px solid var(--clms-border-soft,#d1d5db);border-radius:4px;padding:2px 8px;cursor:pointer;font-size:12px;color:var(--clms-ink,#1d2327)}
			.clms-section-label{font-size:12px;font-weight:600;margin:12px 0 6px;color:var(--clms-ink-2,#374151)}
			.clms-meta-muted{color:var(--clms-muted,#6b7280);font-size:11px;margin-left:6px}
			.clms-p-desc{font-size:13px;color:var(--clms-ink-2,#374151);margin:0 0 10px}
			.clms-p-hint{font-size:12px;color:var(--clms-muted,#6b7280);margin:4px 0 0}
			.clms-p-empty{color:var(--clms-muted,#6b7280);font-size:13px;margin:0}
			.clms-csv-upload{display:grid;gap:10px;padding:12px;border:1px solid var(--clms-border-soft,#e5e7eb);border-radius:8px;background:var(--clms-bg,#fff)}
			.clms-dropzone{display:grid;gap:4px;place-items:center;border:1px dashed var(--clms-border,#d1d5db);border-radius:8px;padding:14px;text-align:center;background:var(--clms-bg-soft,#f8fafc);cursor:pointer;transition:border-color .15s,background .15s}
			.clms-dropzone.is-dragover{border-color:var(--clms-primary,#6366f1);background:rgba(99,102,241,.08)}
			.clms-dropzone-title{font-weight:700;color:var(--clms-ink,#1d2327);font-size:13px}
			.clms-dropzone-sub{font-size:12px;color:var(--clms-muted,#6b7280)}
			.clms-submission-file-list{display:grid;gap:6px}
			.clms-submission-file{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:var(--clms-ink,#1d2327);background:var(--clms-bg-soft,#f8fafc);border-radius:6px;padding:6px 8px}
			.clms-submission-file-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.clms-submission-file-size{color:var(--clms-muted,#6b7280);flex-shrink:0}
			.clms-csv-status{font-size:12px;color:var(--clms-muted,#6b7280)}
			.clms-csv-status.atora-status-error{color:#b91c1c}
			</style>

		<ul class="clms-ac-tabs">
			<li><button class="active" onclick="clmsTab(event,'clms-tab-students')"><?php esc_html_e( 'Matriculados', 'atora-lms' ); ?> <span class="clms-badge clms-badge-gray" id="clms-count-badge"><?php echo count( $students ); ?></span></button></li>
			<li><button onclick="clmsTab(event,'clms-tab-manual')"><?php esc_html_e( 'Manual', 'atora-lms' ); ?></button></li>
			<li><button onclick="clmsTab(event,'clms-tab-csv')"><?php esc_html_e( 'CSV masivo', 'atora-lms' ); ?></button></li>
			<li><button onclick="clmsTab(event,'clms-tab-invites')"><?php esc_html_e( 'Invitaciones', 'atora-lms' ); ?> <span class="clms-badge clms-badge-gray"><?php echo count( $invitations ); ?></span></button></li>
			<li><button onclick="clmsTab(event,'clms-tab-link')"><?php esc_html_e( 'Enlace de acceso', 'atora-lms' ); ?></button></li>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
			<li><button onclick="clmsTab(event,'clms-tab-woo')"><?php esc_html_e( 'WooCommerce', 'atora-lms' ); ?></button></li>
			<?php endif; ?>
		</ul>

		<?php /* -------- TAB: MATRICULADOS -------- */ ?>
		<div id="clms-tab-students" class="clms-ac-panel active">
			<div class="clms-msg" id="clms-msg-students"></div>
			<?php if ( empty( $students ) ) : ?>
				<p class="clms-p-empty"><em><?php esc_html_e( 'Ningún estudiante matriculado aún.', 'atora-lms' ); ?></em></p>
			<?php else : ?>
				<ul class="clms-student-list" id="clms-student-list">
					<?php foreach ( $students as $s ) : ?>
					<li id="clms-srow-<?php echo esc_attr( $s['id'] ); ?>">
						<span>
							<strong><?php echo esc_html( $s['display_name'] ); ?></strong>
							<span class="clms-student-email"><?php echo esc_html( $s['email'] ); ?></span>
						</span>
						<button type="button" class="clms-btn clms-btn-red" onclick="clmsEMUnenroll(<?php echo esc_js( $course_id ); ?>,<?php echo esc_js( $s['id'] ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
							<?php esc_html_e( 'Quitar', 'atora-lms' ); ?>
						</button>
					</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php /* -------- TAB: MANUAL -------- */ ?>
		<div id="clms-tab-manual" class="clms-ac-panel">
			<p class="clms-p-desc"><?php esc_html_e( 'Matricula un estudiante por email o nombre de usuario.', 'atora-lms' ); ?></p>
			<div class="clms-row">
				<input type="text" id="clms-manual-identifier" placeholder="<?php esc_attr_e( 'Email o usuario', 'atora-lms' ); ?>">
				<label style="font-size:12px;white-space:nowrap"><input type="checkbox" id="clms-manual-create"> <?php esc_html_e( 'Crear cuenta si no existe', 'atora-lms' ); ?></label>
				<button type="button" class="clms-btn clms-btn-blue" onclick="clmsEMEnrollManual(<?php echo esc_js( $course_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
					<?php esc_html_e( 'Matricular', 'atora-lms' ); ?>
				</button>
			</div>
			<div class="clms-msg" id="clms-msg-manual"></div>
			<p class="clms-p-hint"><?php esc_html_e( 'Si marcas "Crear cuenta", se creará un usuario con el email dado y se le enviarán sus credenciales por correo.', 'atora-lms' ); ?></p>
		</div>

		<?php /* -------- TAB: CSV -------- */ ?>
			<div id="clms-tab-csv" class="clms-ac-panel">
					<p class="clms-p-desc"><?php esc_html_e( 'Sube un CSV con emails para matricular en lote. Formato: email, nombre, apellido, whatsapp, telegram, telefono, pais, ciudad, estado, sexo, edad (campos extra opcionales).', 'atora-lms' ); ?></p>
				<div
					class="clms-csv-upload"
					data-atora-submission-upload
					data-atora-max-files="1"
					data-atora-max-size="0"
					data-atora-allowed="csv,txt"
					data-atora-msg-too-many="<?php echo esc_attr( __( 'Solo puedes subir 1 archivo.', 'atora-lms' ) ); ?>"
					data-atora-msg-too-large="<?php echo esc_attr( __( 'Archivo demasiado grande.', 'atora-lms' ) ); ?>"
					data-atora-msg-invalid-type="<?php echo esc_attr__( 'Solo se permiten archivos CSV o TXT.', 'atora-lms' ); ?>"
					data-atora-msg-ready="<?php echo esc_attr__( 'Archivo listo para importar.', 'atora-lms' ); ?>"
					data-atora-msg-empty="<?php echo esc_attr__( 'No se seleccionó ningún archivo.', 'atora-lms' ); ?>"
				>
					<div class="clms-dropzone" data-atora-dropzone tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Arrastra el CSV o toca para seleccionarlo', 'atora-lms' ); ?>">
						<span class="clms-dropzone-title"><?php esc_html_e( 'Arrastra tu CSV aquí', 'atora-lms' ); ?></span>
						<span class="clms-dropzone-sub"><?php esc_html_e( 'o toca para seleccionarlo', 'atora-lms' ); ?></span>
					</div>
					<div class="clms-row">
						<input type="file" id="clms-csv-file" accept=".csv,.txt" style="flex:1" data-atora-file-input>
						<label style="font-size:12px;white-space:nowrap"><input type="checkbox" id="clms-csv-create" checked> <?php esc_html_e( 'Crear cuentas nuevas', 'atora-lms' ); ?></label>
						<label style="font-size:12px;white-space:nowrap"><input type="checkbox" id="clms-csv-notify-existing"> <?php esc_html_e( 'Enviar correo a usuarios existentes', 'atora-lms' ); ?></label>
						<button type="button" class="clms-btn clms-btn-green" onclick="clmsEMProcessCSV(<?php echo esc_js( $course_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
							<?php esc_html_e( 'Importar', 'atora-lms' ); ?>
						</button>
					</div>
					<div class="clms-submission-file-list" data-atora-file-list hidden></div>
					<div class="clms-csv-status" data-atora-status aria-live="polite"></div>
				</div>
				<div class="clms-msg" id="clms-msg-csv"></div>
				<pre id="clms-csv-result"></pre>
			</div>

		<?php /* -------- TAB: INVITACIONES -------- */ ?>
		<div id="clms-tab-invites" class="clms-ac-panel">
			<p class="clms-p-desc"><?php esc_html_e( 'Envía un enlace de invitación nominal a un email específico.', 'atora-lms' ); ?></p>
			<div class="clms-row">
				<input type="email" id="clms-inv-email" placeholder="<?php esc_attr_e( 'Email del invitado', 'atora-lms' ); ?>">
				<select id="clms-inv-expires">
					<option value="24"><?php esc_html_e( 'Expira en 24h', 'atora-lms' ); ?></option>
					<option value="72" selected><?php esc_html_e( 'Expira en 72h', 'atora-lms' ); ?></option>
					<option value="168"><?php esc_html_e( 'Expira en 7 días', 'atora-lms' ); ?></option>
					<option value="0"><?php esc_html_e( 'Sin expiración', 'atora-lms' ); ?></option>
				</select>
				<label style="font-size:12px;white-space:nowrap"><input type="checkbox" id="clms-inv-send" checked> <?php esc_html_e( 'Enviar email', 'atora-lms' ); ?></label>
				<button type="button" class="clms-btn clms-btn-blue" onclick="clmsEMSendInvite(<?php echo esc_js( $course_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
					<?php esc_html_e( 'Generar invitación', 'atora-lms' ); ?>
				</button>
			</div>
			<div class="clms-msg" id="clms-msg-invites"></div>

			<?php if ( ! empty( $invitations ) ) : ?>
			<p class="clms-section-label"><?php esc_html_e( 'Historial de invitaciones:', 'atora-lms' ); ?></p>
			<ul class="clms-invite-list" id="clms-invite-list">
				<?php foreach ( $invitations as $inv ) :
					$badge = 'active' === $inv->status ? 'green' : ( 'exhausted' === $inv->status ? 'yellow' : ( 'revoked' === $inv->status ? 'red' : 'gray' ) );
					$badge_label = array(
						'active'    => __( 'Activa',   'atora-lms' ),
						'exhausted' => __( 'Agotada',  'atora-lms' ),
						'revoked'   => __( 'Revocada', 'atora-lms' ),
						'expired'   => __( 'Caducada', 'atora-lms' ),
					);
				?>
				<li id="clms-inv-row-<?php echo esc_attr( $inv->token ); ?>">
					<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px">
						<div>
							<?php if ( $inv->invited_email ) : ?>
								<strong><?php echo esc_html( $inv->invited_email ); ?></strong> —
							<?php endif; ?>
							<span class="clms-badge clms-badge-<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $badge_label[ $inv->status ] ?? $inv->status ); ?></span>
							<span class="clms-meta-muted"><?php echo esc_html( $inv->used_count . '/' . $inv->max_uses ); ?> <?php esc_html_e( 'usos', 'atora-lms' ); ?></span>
							<?php if ( $inv->expires_at ) : ?>
								<span class="clms-meta-muted"> · <?php esc_html_e( 'Expira:', 'atora-lms' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $inv->expires_at ) ) ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( 'active' === $inv->status ) : ?>
						<button type="button" class="clms-btn clms-btn-red" onclick="clmsEMRevokeInvite('<?php echo esc_js( $inv->token ); ?>','<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
							<?php esc_html_e( 'Revocar', 'atora-lms' ); ?>
						</button>
						<?php endif; ?>
					</div>
					<div class="token-url" style="margin-top:4px">
						<?php echo esc_html( add_query_arg( 'clms_invite', rawurlencode( $inv->token ), home_url( '/' ) ) ); ?>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php else : ?>
				<p id="clms-invite-list-empty" class="clms-p-empty" style="margin:10px 0 0"><em><?php esc_html_e( 'No hay invitaciones generadas.', 'atora-lms' ); ?></em></p>
				<ul class="clms-invite-list" id="clms-invite-list" style="display:none"></ul>
			<?php endif; ?>
		</div>

		<?php /* -------- TAB: ENLACE DE ACCESO -------- */ ?>
		<div id="clms-tab-link" class="clms-ac-panel">
			<p class="clms-p-desc"><?php esc_html_e( 'Genera una URL que permite que alguien acceda al curso sin invitación nominal.', 'atora-lms' ); ?></p>

			<div class="clms-row" style="align-items:flex-end;flex-wrap:wrap;gap:10px">
				<div>
					<label class="clms-link-pw-label"><?php esc_html_e( 'Modo de acceso', 'atora-lms' ); ?></label>
					<select id="clms-link-mode" onchange="clmsLinkModeChange()">
						<option value="free" <?php selected( $link_data ? $link_data['mode'] : '', 'free' ); ?>><?php esc_html_e( 'Libre (cualquiera con el enlace)', 'atora-lms' ); ?></option>
						<option value="password" <?php selected( $link_data ? $link_data['mode'] : '', 'password' ); ?>><?php esc_html_e( 'Con contraseña', 'atora-lms' ); ?></option>
						<option value="register" <?php selected( $link_data ? $link_data['mode'] : '', 'register' ); ?>><?php esc_html_e( 'Con registro obligatorio', 'atora-lms' ); ?></option>
					</select>
				</div>
				<div id="clms-link-pw-wrap" style="display:<?php echo ( $link_data && $link_data['mode'] === 'password' ) ? 'block' : 'none'; ?>">
					<label class="clms-link-pw-label"><?php esc_html_e( 'Contraseña de acceso', 'atora-lms' ); ?></label>
					<input type="text" id="clms-link-password" placeholder="<?php esc_attr_e( 'Código de acceso', 'atora-lms' ); ?>" style="width:160px;padding:6px 10px;border:1px solid var(--clms-border,#ddd);border-radius:6px;font-size:13px;background:var(--clms-bg,#fff);color:var(--clms-ink,#1d2327)">
				</div>
				<button type="button" class="clms-btn clms-btn-blue" onclick="clmsEMRegenerateLink(<?php echo esc_js( $course_id ); ?>,'<?php echo esc_js( $nonce ); ?>','<?php echo esc_js( $ajax_url ); ?>')">
					<?php echo $link_data ? esc_html__( 'Regenerar enlace', 'atora-lms' ) : esc_html__( 'Generar enlace', 'atora-lms' ); ?>
				</button>
			</div>
			<div class="clms-msg" id="clms-msg-link"></div>

			<?php
			$link_mode_labels = array(
				'free'     => __( 'Libre',           'atora-lms' ),
				'password' => __( 'Con contraseña',  'atora-lms' ),
				'register' => __( 'Con registro',    'atora-lms' ),
			);
			?>
			<?php if ( $link_data ) : ?>
			<div class="clms-link-box" id="clms-link-display">
				<div style="font-size:12px;color:var(--clms-muted,#6b7280);margin-bottom:4px">
					<?php esc_html_e( 'Enlace activo:', 'atora-lms' ); ?>
					<strong style="color:var(--clms-ink-2,#374151)"><?php echo esc_html( $link_mode_labels[ $link_data['mode'] ] ?? $link_data['mode'] ); ?></strong>
					<span class="clms-meta-muted"><?php echo esc_html( $link_data['uses'] ); ?> <?php esc_html_e( 'usos', 'atora-lms' ); ?></span>
				</div>
				<a href="<?php echo esc_url( $link_data['url'] ); ?>" target="_blank"><?php echo esc_html( $link_data['url'] ); ?></a>
				<button type="button" class="clms-link-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js( $link_data['url'] ); ?>');this.textContent='<?php echo esc_js( __( '✓ Copiado', 'atora-lms' ) ); ?>'">
					<?php esc_html_e( 'Copiar', 'atora-lms' ); ?>
				</button>
			</div>
			<?php else : ?>
			<div class="clms-link-box" id="clms-link-display" style="display:none"></div>
			<?php endif; ?>
		</div>

		<?php if ( class_exists( 'WooCommerce' ) ) : ?>
		<?php /* -------- TAB: WOOCOMMERCE -------- */ ?>
		<div id="clms-tab-woo" class="clms-ac-panel">
			<?php if ( $linked_product_id ) :
				$product = wc_get_product( $linked_product_id );
			?>
				<p class="clms-p-desc"><?php esc_html_e( 'La matrícula se activa automáticamente cuando se completa la compra de:', 'atora-lms' ); ?></p>
				<div class="clms-link-box">
					<strong><?php echo $product ? esc_html( $product->get_name() ) : esc_html( sprintf( __( 'Producto #%d', 'atora-lms' ), $linked_product_id ) ); ?></strong>
					<?php if ( $product ) : ?>
						<span class="clms-meta-muted"><?php echo esc_html( $product->get_price_html() ); ?></span>
						<a href="<?php echo esc_url( get_edit_post_link( $linked_product_id ) ); ?>" style="margin-left:10px;font-size:12px" target="_blank"><?php esc_html_e( 'Editar producto', 'atora-lms' ); ?></a>
					<?php endif; ?>
				</div>
				<p class="clms-p-hint"><?php esc_html_e( 'Para cambiar el producto vinculado, edita el campo en la ficha del producto WooCommerce.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<p class="clms-p-empty"><?php esc_html_e( 'No hay un producto WooCommerce vinculado a este curso.', 'atora-lms' ); ?></p>
				<p class="clms-p-hint"><?php esc_html_e( 'Para vincular, crea o edita un producto y asigna este curso en la sección "Datos del Curso" del producto.', 'atora-lms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<script>
		function clmsTab(e, tabId) {
			e.preventDefault();
			document.querySelectorAll('.clms-ac-panel').forEach(function(el){ el.classList.remove('active'); });
			document.querySelectorAll('.clms-ac-tabs button').forEach(function(b){ b.classList.remove('active'); });
			var panel = document.getElementById(tabId);
			if (panel) panel.classList.add('active');
			e.currentTarget.classList.add('active');
		}

		function clmsLinkModeChange() {
			var mode = document.getElementById('clms-link-mode').value;
			document.getElementById('clms-link-pw-wrap').style.display = (mode === 'password') ? 'block' : 'none';
		}

		function clmsShowMsg(containerId, msg, type) {
			var el = document.getElementById(containerId);
			if (!el) return;
			el.textContent = msg;
			el.className = 'clms-msg ' + type;
			el.style.display = 'block';
			if (type === 'ok') setTimeout(function(){ el.style.display = 'none'; }, 5000);
		}

		function clmsPost(ajaxUrl, data, callback) {
			fetch(ajaxUrl, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: new URLSearchParams(data).toString()
			}).then(function(r){ return r.json(); }).then(callback);
		}

		// --- Manual enroll ---
		function clmsEMEnrollManual(courseId, nonce, ajaxUrl) {
			var id = document.getElementById('clms-manual-identifier').value.trim();
			if (!id) { clmsShowMsg('clms-msg-manual','<?php echo esc_js( __( 'Escribe un email o usuario.', 'atora-lms' ) ); ?>','err'); return; }
			var create = document.getElementById('clms-manual-create').checked ? '1' : '';
			clmsPost(ajaxUrl, {action:'clms_em_enroll_manual', course_id:courseId, identifier:id, create_missing:create, nonce:nonce}, function(res){
				if (res.success) {
					clmsShowMsg('clms-msg-manual', res.data.message, 'ok');
					document.getElementById('clms-manual-identifier').value = '';
					// Add to student list tab
					var list = document.getElementById('clms-student-list');
					if (!list) {
						var wrap = document.getElementById('clms-tab-students');
						list = document.createElement('ul');
						list.id = 'clms-student-list';
						list.className = 'clms-student-list';
						wrap.appendChild(list);
					}
					if (!document.getElementById('clms-srow-' + res.data.user_id)) {
						var li = document.createElement('li');
						li.id = 'clms-srow-' + res.data.user_id;
						li.innerHTML = '<span><strong>' + res.data.display_name + '</strong> <span class="clms-student-email">' + res.data.user_email + '</span></span>'
							+ '<button type="button" class="clms-btn clms-btn-red" onclick="clmsEMUnenroll('+courseId+','+res.data.user_id+',\''+nonce+'\',\''+ajaxUrl+'\')"><?php echo esc_js( __( 'Quitar', 'atora-lms' ) ); ?></button>';
						list.appendChild(li);
						var badge = document.getElementById('clms-count-badge');
						if (badge) badge.textContent = list.querySelectorAll('li').length;
					}
				} else {
					clmsShowMsg('clms-msg-manual', res.data.message || '<?php echo esc_js( __( 'Error.', 'atora-lms' ) ); ?>', 'err');
				}
			});
		}

		// --- Unenroll ---
		function clmsEMUnenroll(courseId, userId, nonce, ajaxUrl) {
			if (!confirm('<?php echo esc_js( __( '¿Quitar la matrícula de este estudiante?', 'atora-lms' ) ); ?>')) return;
			clmsPost(ajaxUrl, {action:'clms_em_unenroll', course_id:courseId, user_id:userId, nonce:nonce}, function(res){
				if (res.success) {
					var row = document.getElementById('clms-srow-' + userId);
					if (row) row.remove();
					clmsShowMsg('clms-msg-students', res.data.message || '<?php echo esc_js( __( 'Desmatriculado.', 'atora-lms' ) ); ?>', 'ok');
					var badge = document.getElementById('clms-count-badge');
					var list = document.getElementById('clms-student-list');
					if (badge && list) badge.textContent = list.querySelectorAll('li').length;
				} else {
					clmsShowMsg('clms-msg-students', res.data.message || '<?php echo esc_js( __( 'Error.', 'atora-lms' ) ); ?>', 'err');
				}
			});
		}

		// --- CSV import ---
		function clmsEMProcessCSV(courseId, nonce, ajaxUrl) {
			var fileInput = document.getElementById('clms-csv-file');
			if (!fileInput.files.length) { clmsShowMsg('clms-msg-csv','<?php echo esc_js( __( 'Selecciona un archivo CSV.', 'atora-lms' ) ); ?>','err'); return; }
			var create = document.getElementById('clms-csv-create').checked ? '1' : '';
			var notifyExisting = document.getElementById('clms-csv-notify-existing').checked ? '1' : '';
			var fd = new FormData();
			fd.append('action', 'clms_em_process_csv');
			fd.append('course_id', courseId);
			fd.append('create_missing', create);
			fd.append('notify_existing', notifyExisting);
			fd.append('nonce', nonce);
			fd.append('csv_file', fileInput.files[0]);
			clmsShowMsg('clms-msg-csv','<?php echo esc_js( __( 'Procesando…', 'atora-lms' ) ); ?>','ok');
			fetch(ajaxUrl, {method:'POST', body:fd})
				.then(function(r){ return r.json(); })
				.then(function(res){
					if (res.success) {
						var d = res.data;
						var msg = '✓ Matriculados: ' + d.enrolled + ' · Omitidos: ' + d.skipped + ' · Cuentas creadas: ' + d.created;
						if (typeof d.notified_existing !== 'undefined') {
							msg += ' · Correos a existentes: ' + d.notified_existing;
						}
						clmsShowMsg('clms-msg-csv', msg, 'ok');
						var pre = document.getElementById('clms-csv-result');
						if (d.errors && d.errors.length) {
							pre.textContent = d.errors.join('\n');
							pre.style.display = 'block';
						}
					} else {
						clmsShowMsg('clms-msg-csv', res.data.message || '<?php echo esc_js( __( 'Error al procesar el CSV.', 'atora-lms' ) ); ?>', 'err');
					}
				});
		}

		// --- Invitation ---
		function clmsEMSendInvite(courseId, nonce, ajaxUrl) {
			var email = document.getElementById('clms-inv-email').value.trim();
			if (!email) { clmsShowMsg('clms-msg-invites','<?php echo esc_js( __( 'Escribe un email.', 'atora-lms' ) ); ?>','err'); return; }
			var expires = document.getElementById('clms-inv-expires').value;
			var sendNow = document.getElementById('clms-inv-send').checked ? '1' : '';
			clmsPost(ajaxUrl, {action:'clms_em_send_invitation', course_id:courseId, email:email, expires_hours:expires, max_uses:1, send_now:sendNow, nonce:nonce}, function(res){
				if (res.success) {
					clmsShowMsg('clms-msg-invites', res.data.message, 'ok');
					document.getElementById('clms-inv-email').value = '';
					// Add to invitation list
					var list = document.getElementById('clms-invite-list');
					var empty = document.getElementById('clms-invite-list-empty');
					if (empty) { empty.remove(); }
					if (list) {
						list.style.display = '';
						var li = document.createElement('li');
						li.id = 'clms-inv-row-' + res.data.token;
						li.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px">'
							+ '<div><strong>' + res.data.email + '</strong> — <span class="clms-badge clms-badge-green">Activa</span>'
							+ ' <span style="color:#6b7280;font-size:11px;margin-left:6px">0/1 usos</span></div>'
							+ '<button type="button" class="clms-btn clms-btn-red" onclick="clmsEMRevokeInvite(\''+res.data.token+'\',\''+nonce+'\',\''+ajaxUrl+'\')"><?php echo esc_js( __( 'Revocar', 'atora-lms' ) ); ?></button></div>'
							+ '<div class="token-url" style="margin-top:4px">' + res.data.url + '</div>';
						list.prepend(li);
					}
				} else {
					clmsShowMsg('clms-msg-invites', res.data.message || '<?php echo esc_js( __( 'Error.', 'atora-lms' ) ); ?>', 'err');
				}
			});
		}

		function clmsEMRevokeInvite(token, nonce, ajaxUrl) {
			if (!confirm('<?php echo esc_js( __( '¿Revocar esta invitación?', 'atora-lms' ) ); ?>')) return;
			clmsPost(ajaxUrl, {action:'clms_em_revoke_invitation', token:token, nonce:nonce}, function(res){
				if (res.success) {
					var row = document.getElementById('clms-inv-row-' + token);
					if (row) { row.style.opacity = '.4'; row.querySelector('button') && row.querySelector('button').remove(); }
					clmsShowMsg('clms-msg-invites', res.data.message, 'ok');
				}
			});
		}

		// --- Access link ---
		function clmsEMRegenerateLink(courseId, nonce, ajaxUrl) {
			var mode = document.getElementById('clms-link-mode').value;
			var pw = document.getElementById('clms-link-password') ? document.getElementById('clms-link-password').value : '';
			if (mode === 'password' && !pw.trim()) { clmsShowMsg('clms-msg-link','<?php echo esc_js( __( 'Escribe una contraseña de acceso.', 'atora-lms' ) ); ?>','err'); return; }
			clmsPost(ajaxUrl, {action:'clms_em_regenerate_link', course_id:courseId, mode:mode, link_password:pw, nonce:nonce}, function(res){
				if (res.success) {
					clmsShowMsg('clms-msg-link', res.data.message, 'ok');
					var box = document.getElementById('clms-link-display');
					box.style.display = '';
					var modeLabels = {free:'<?php echo esc_js( __( 'Libre', 'atora-lms' ) ); ?>',password:'<?php echo esc_js( __( 'Con contraseña', 'atora-lms' ) ); ?>',register:'<?php echo esc_js( __( 'Con registro', 'atora-lms' ) ); ?>'};
					box.innerHTML = '<div style="font-size:12px;color:var(--clms-muted,#6b7280);margin-bottom:4px"><?php echo esc_js( __( 'Enlace activo:', 'atora-lms' ) ); ?> <strong style="color:var(--clms-ink-2,#374151)">'
						+ (modeLabels[res.data.mode] || res.data.mode)
						+ '</strong> <span class="clms-meta-muted">0 <?php echo esc_js( __( 'usos', 'atora-lms' ) ); ?></span></div>'
						+ '<a href="'+res.data.url+'" target="_blank">'+res.data.url+'</a>'
						+ ' <button type="button" class="clms-link-copy-btn" onclick="navigator.clipboard.writeText(\''+res.data.url+'\');this.textContent=\'<?php echo esc_js( __( '✓ Copiado', 'atora-lms' ) ); ?>\'"><?php echo esc_js( __( 'Copiar', 'atora-lms' ) ); ?></button>';
				} else {
					clmsShowMsg('clms-msg-link', res.data.message || '<?php echo esc_js( __( 'Error.', 'atora-lms' ) ); ?>', 'err');
				}
			});
		}
		</script>
		<?php
	}

	public function ajax_enroll_user() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			wp_send_json_error( __( 'Sin permisos.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_enrollment_nonce' ) ) {
			wp_send_json_error( __( 'Nonce inválido.', 'atora-lms' ) );
		}

		$course_id  = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		$user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			wp_send_json_error( __( 'Curso inválido.', 'atora-lms' ) );
		}

		// PT-9 (6.5.5): la comprobación de arriba solo exige la
		// capability genérica clms_manage_courses — sin esto, cualquier
		// instructor podía matricular usuarios en un curso que no le
		// pertenece. Mismo criterio jerárquico que
		// LMS_REST_Controller::can_manage_this_course().
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_others_lm_courses' )
			&& (int) get_post_field( 'post_author', $course_id ) !== get_current_user_id()
		) {
			wp_send_json_error( __( 'Sin permisos sobre este curso.', 'atora-lms' ) );
		}

		// Find user by email or login
		$user = get_user_by( 'email', $user_login );
		if ( ! $user ) {
			$user = get_user_by( 'login', $user_login );
		}
		if ( ! $user ) {
			wp_send_json_error( __( 'Usuario no encontrado. Usa email o nombre de usuario exactos.', 'atora-lms' ) );
		}

		if ( ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
			wp_send_json_error( __( 'Sistema de matrícula no disponible.', 'atora-lms' ) );
		}

		CLMS_Helper::enroll_user_in_course( $user->ID, $course_id );

		wp_send_json_success(
			array(
				'message'      => sprintf( __( '%s matriculado/a correctamente.', 'atora-lms' ), $user->display_name ),
				'user_id'      => $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			)
		);
	}

	public function ajax_unenroll_user() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_manage_courses' ) ) {
			wp_send_json_error( __( 'Sin permisos.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_enrollment_nonce' ) ) {
			wp_send_json_error( __( 'Nonce inválido.', 'atora-lms' ) );
		}

		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		$user_id   = isset( $_POST['user_id'] )   ? absint( $_POST['user_id'] )   : 0;

		if ( ! $course_id || ! $user_id ) {
			wp_send_json_error( __( 'Datos inválidos.', 'atora-lms' ) );
		}

		// PT-9 (6.5.5): igual que ajax_enroll_user() — sin esto,
		// cualquier instructor con clms_manage_courses podía desmatricular
		// usuarios de un curso ajeno.
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_others_lm_courses' )
			&& (int) get_post_field( 'post_author', $course_id ) !== get_current_user_id()
		) {
			wp_send_json_error( __( 'Sin permisos sobre este curso.', 'atora-lms' ) );
		}

		// Remove from user meta
		$user_courses = get_user_meta( $user_id, '_clms_enrolled_courses', true );
		$user_courses = is_array( $user_courses ) ? array_map( 'absint', $user_courses ) : array();
		$user_courses = array_values( array_diff( $user_courses, array( $course_id ) ) );
		update_user_meta( $user_id, '_clms_enrolled_courses', $user_courses );

		// Remove from course meta
		$course_users = get_post_meta( $course_id, '_clms_enrolled_users', true );
		$course_users = is_array( $course_users ) ? array_map( 'absint', $course_users ) : array();
		$course_users = array_values( array_diff( $course_users, array( $user_id ) ) );
		update_post_meta( $course_id, '_clms_enrolled_users', $course_users );

		// F2.1: notificar al compat layer para que actualice la tabla atora_enrollments.
		do_action( 'clms_user_unenrolled', $user_id, $course_id );

		$user = get_userdata( $user_id );
		$name = $user ? $user->display_name : "ID $user_id";

		wp_send_json_success(
			array(
				'message' => sprintf( __( 'Matrícula de %s eliminada.', 'atora-lms' ), $name ),
			)
		);
	}
}
