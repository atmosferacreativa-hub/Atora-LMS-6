<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Admin_Menu_Hubs_Trait {
	/**
	 * Encola el design system global de admin (Fase 12B).
	 */
	public function enqueue_atora_admin_styles( string $hook ): void {
		if ( ! isset( $_GET['page'] ) ) { return; }
		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) );
		if ( ! str_starts_with( $page, 'clms-' ) && ! str_starts_with( $page, 'atora-' ) ) { return; }
		if ( ! defined( 'ATORA_LMS_URL' ) ) { return; }
		wp_enqueue_style( 'atora-admin-ds', ATORA_LMS_URL . 'assets/admin/atora-admin.css', array(), defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0' );
	}

	/**
	 * Inyecta CSS para colorear la pestaña ATORA en el panel lateral de WP:
	 * fondo azul, icono ámbar, texto blanco, submenú azul oscuro.
	 */
	public function render_atora_menu_styles(): void {
		?>
		<style id="atora-menu-brand">
		/* ── ATORA Menu Tab: Blue background, amber icon, white text ── */
		#adminmenu #toplevel_page_clms-dashboard > a,
		#adminmenu #toplevel_page_clms-dashboard > a.wp-has-current-submenu,
		#adminmenu #toplevel_page_clms-dashboard.current > a,
		#adminmenu #toplevel_page_clms-dashboard.wp-has-current-submenu > a {
			background: #1d4ed8 !important;
			color: #fff !important;
		}
		#adminmenu #toplevel_page_clms-dashboard > a:hover {
			background: #1e40af !important;
			color: #fff !important;
		}
		#adminmenu #toplevel_page_clms-dashboard .wp-menu-image:before {
			color: #f59e0b !important;
		}
		#adminmenu #toplevel_page_clms-dashboard > a .wp-menu-name {
			color: #fff !important;
		}
		#adminmenu #toplevel_page_clms-dashboard > a .wp-menu-image img {
			opacity: 1 !important;
			filter: none !important;
		}
		/* Submenu: dark blue background, light text */
		#adminmenu #toplevel_page_clms-dashboard .wp-submenu {
			background: #1e3a8a !important;
		}
		#adminmenu #toplevel_page_clms-dashboard .wp-submenu a {
			color: #bfdbfe !important;
		}
		#adminmenu #toplevel_page_clms-dashboard .wp-submenu a:hover,
		#adminmenu #toplevel_page_clms-dashboard .wp-submenu li.current a {
			color: #fff !important;
			background: rgba(255,255,255,.1) !important;
		}
		/* ── Fase 12A: emojis de sección en el sidebar ── */
		#adminmenu #toplevel_page_clms-dashboard .wp-submenu li a {
			font-size: 13px !important;
			padding: 6px 14px !important;
		}
		/* Hover activo más visible */
		#adminmenu #toplevel_page_clms-dashboard .wp-submenu li.current > a {
			background: rgba(255,255,255,.18) !important;
			color: #fff !important;
			border-left: 2px solid #f59e0b !important;
			padding-left: 12px !important;
		}

		/* ── ATORA CRM Hub styles ── */
		.atora-hub {
			max-width: 1200px;
			padding: 0 0 40px;
		}
		.atora-hub__header {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			margin-bottom: 24px;
			padding: 28px 32px;
			background: linear-gradient(135deg,#0f172a 0%,#1e3a8a 50%,#1d4ed8 100%);
			border-radius: 20px;
			color: #fff;
		}
		.atora-hub__title {
			margin: 0 0 4px;
			font-size: 28px;
			font-weight: 800;
			color: #fff;
		}
		.atora-hub__date {
			margin: 0;
			font-size: 14px;
			color: rgba(255,255,255,.7);
		}
		.atora-hub__context {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			margin-bottom: 10px;
			padding: 6px 12px;
			border-radius: 999px;
			background: rgba(255,255,255,.18);
			color: #fff;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: .04em;
			text-transform: uppercase;
		}
			.atora-hub__subtitle {
				margin: 8px 0 0;
				font-size: 14px;
				line-height: 1.6;
				color: rgba(255,255,255,.82);
			}
			.atora-hub__meta {
				display: grid;
				grid-template-columns: repeat(2, minmax(120px, 1fr));
				gap: 10px;
				min-width: 280px;
			}
			.atora-hub__meta-item {
				background: rgba(255,255,255,.14);
				border: 1px solid rgba(255,255,255,.28);
				border-radius: 12px;
				padding: 10px 12px;
				display: grid;
				gap: 2px;
			}
			.atora-hub__meta-num {
				font-size: 20px;
				font-weight: 800;
				line-height: 1.1;
				color: #fff;
			}
			.atora-hub__meta-lbl {
				font-size: 11px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: .05em;
				color: rgba(255,255,255,.72);
			}
			.atora-hub__guide {
				background: #fff;
				border: 1px solid #e5e7eb;
				border-radius: 16px;
				padding: 16px 18px;
				margin-bottom: 20px;
				box-shadow: 0 1px 4px rgba(0,0,0,.05);
			}
			.atora-hub__guide-title {
				margin: 0 0 10px;
				font-size: 14px;
				font-weight: 800;
				color: #0f172a;
			}
			.atora-hub__guide-list {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 10px;
			}
			.atora-hub__guide-step {
				display: flex;
				align-items: flex-start;
				gap: 8px;
				padding: 10px 12px;
				border: 1px solid #e5e7eb;
				border-radius: 12px;
				background: #f8fafc;
				font-size: 13px;
				color: #334155;
				line-height: 1.4;
			}
			.atora-hub__guide-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 20px;
				height: 20px;
				border-radius: 999px;
				background: #dbeafe;
				color: #1d4ed8;
				font-size: 11px;
				font-weight: 800;
				flex-shrink: 0;
			}
			.atora-hub--email .atora-hub__header {
				background: linear-gradient(135deg,#0f766e 0%,#0891b2 52%,#1d4ed8 100%);
			}
			.atora-hub--email .atora-hub__quick-link:hover {
				border-color: #0891b2;
				color: #0f766e;
				background: #ecfeff;
			}
			.atora-hub--settings .atora-hub__header {
				background: linear-gradient(135deg,#1e3a8a 0%,#1d4ed8 60%,#c2410c 100%);
			}
			.atora-hub--settings .atora-hub__quick-link:hover {
				border-color: #1d4ed8;
				color: #1e3a8a;
				background: #eff6ff;
			}
			.atora-hub--automation .atora-hub__header {
				background: linear-gradient(135deg,#0f172a 0%,#334155 52%,#4338ca 100%);
			}
			.atora-hub--automation .atora-hub__quick-link:hover {
				border-color: #4338ca;
				color: #4338ca;
				background: #eef2ff;
			}
			.atora-hub--commercial .atora-hub__header {
				background: linear-gradient(135deg,#0f766e 0%,#0ea5a4 52%,#b45309 100%);
			}
			.atora-hub--commercial .atora-hub__quick-link:hover {
				border-color: #0f766e;
				color: #0f766e;
				background: #ecfdf5;
			}
			.atora-hub__primary {
				margin-bottom: 20px;
			}
		.atora-hub__primary-grid {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 12px;
		}
		.atora-hub__primary-link {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			padding: 14px 16px;
			border-radius: 14px;
			background: #fff;
			border: 1px solid #dbeafe;
			text-decoration: none;
			color: #1e3a8a;
			font-weight: 700;
			min-height: 58px;
			box-shadow: 0 1px 4px rgba(0,0,0,.05);
			transition: all .15s ease;
		}
		.atora-hub__primary-link:hover {
			border-color: #1d4ed8;
			background: #eff6ff;
			color: #1d4ed8;
			transform: translateY(-1px);
		}
		.atora-hub__primary-icon {
			font-size: 20px;
			line-height: 1;
		}
		.atora-hub__stats {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 16px;
			margin-bottom: 28px;
		}
		.atora-hub__stat {
			background: #fff;
			border: 1px solid #e5e7eb;
			border-radius: 16px;
			padding: 18px 22px;
			display: grid;
			gap: 4px;
			box-shadow: 0 1px 4px rgba(0,0,0,.05);
		}
		.atora-hub__stat-num {
			font-size: 32px;
			font-weight: 800;
			color: #1d4ed8;
			line-height: 1;
		}
		.atora-hub__stat-lbl {
			font-size: 12px;
			color: #6b7280;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: .05em;
		}
		.atora-hub__actions {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 20px;
			margin-bottom: 28px;
		}
		.atora-hub--academic .atora-hub__actions {
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 16px;
		}
		.atora-hub__actions--split {
			grid-template-columns: repeat(2, 1fr);
		}
		.atora-hub__action-card {
			border-radius: 20px;
			padding: 28px;
			display: grid;
			gap: 12px;
			align-content: start;
			color: #fff;
			position: relative;
			overflow: hidden;
			min-height: 320px;
		}
		.atora-hub__action-card--blue   { background: linear-gradient(135deg,#1d4ed8,#2563eb); }
		.atora-hub__action-card--indigo { background: linear-gradient(135deg,#4338ca,#6366f1); }
		.atora-hub__action-card--teal   { background: linear-gradient(135deg,#0f766e,#0d9488); }
		.atora-hub__action-card--amber  { background: linear-gradient(135deg,#b45309,#f59e0b); }
		.atora-hub__action-card--slate  { background: linear-gradient(135deg,#1e293b,#334155); }
		.atora-hub__action-icon { font-size: 32px; line-height: 1; }
		.atora-hub__action-title {
			margin: 0;
			font-size: 20px;
			font-weight: 800;
			color: #fff;
		}
		.atora-hub__action-desc {
			margin: 0;
			font-size: 14px;
			color: rgba(255,255,255,.82);
			line-height: 1.6;
		}
		.atora-hub__mini-list {
			display: grid;
			gap: 6px;
			margin-top: 4px;
		}
		.atora-hub__feature-list {
			margin: 0;
			padding: 0;
			list-style: none;
			display: grid;
			gap: 6px;
		}
		.atora-hub__feature-list li {
			font-size: 13px;
			color: rgba(255,255,255,.88);
			padding-left: 18px;
			position: relative;
		}
		.atora-hub__feature-list li::before {
			content: "•";
			position: absolute;
			left: 4px;
			color: rgba(255,255,255,.88);
		}
		.atora-hub__mini-item {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 6px 10px;
			background: rgba(255,255,255,.1);
			border-radius: 8px;
			font-size: 13px;
		}
		.atora-hub__mini-dot {
			width: 7px;
			height: 7px;
			border-radius: 50%;
			background: rgba(255,255,255,.7);
			flex-shrink: 0;
		}
		.atora-hub__status {
			width: 8px;
			height: 8px;
			border-radius: 50%;
			flex-shrink: 0;
			display: inline-block;
		}
		.atora-hub__status--success { background: #4ade80; }
		.atora-hub__status--error   { background: #f87171; }
		.atora-hub__status--pending { background: #fbbf24; }
		.atora-hub__mini-text {
			flex: 1;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
			color: rgba(255,255,255,.9);
		}
		.atora-hub__mini-date {
			font-size: 11px;
			color: rgba(255,255,255,.6);
			white-space: nowrap;
			flex-shrink: 0;
		}
		.atora-hub__mini-stats {
			display: flex;
			gap: 16px;
			margin-top: 4px;
		}
		.atora-hub__mini-stat {
			display: grid;
			gap: 2px;
			text-align: center;
		}
		.atora-hub__mini-stat strong {
			font-size: 22px;
			font-weight: 800;
			color: #fff;
			line-height: 1;
		}
		.atora-hub__mini-stat span {
			font-size: 11px;
			color: rgba(255,255,255,.65);
			text-transform: uppercase;
			letter-spacing: .05em;
		}
		.atora-hub__action-btn {
			display: inline-flex;
			align-items: center;
			margin-top: 6px;
			padding: 10px 18px;
			background: rgba(255,255,255,.18);
			border: 1px solid rgba(255,255,255,.3);
			border-radius: 10px;
			color: #fff;
			font-size: 14px;
			font-weight: 700;
			text-decoration: none;
			width: fit-content;
			transition: background .15s ease;
		}
		.atora-hub__action-btn:hover {
			background: rgba(255,255,255,.28);
			color: #fff;
		}
		.atora-hub__quick {
			background: #fff;
			border: 1px solid #e5e7eb;
			border-radius: 18px;
			padding: 24px;
			box-shadow: 0 1px 4px rgba(0,0,0,.05);
		}
		.atora-hub__quick-title {
			margin: 0 0 16px;
			font-size: 16px;
			font-weight: 700;
			color: #111827;
		}
		.atora-hub__quick-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
			gap: 10px;
		}
		.atora-hub__quick-link {
			display: block;
			padding: 12px 14px;
			border: 1px solid #e5e7eb;
			border-radius: 12px;
			text-decoration: none;
			font-size: 13px;
			color: #374151;
			transition: all .15s ease;
		}
		.atora-hub__quick-link:hover {
			border-color: #1d4ed8;
			color: #1d4ed8;
			background: #eff6ff;
		}
		.atora-hub__quick-link strong { display: block; }
			.atora-hub__quick-link span {
				display: block;
				margin-top: 4px;
				color: #6b7280;
				font-size: 12px;
			}
			.atora-hub__teachers {
				background: #fff;
				border: 1px solid #e5e7eb;
				border-radius: 18px;
				padding: 20px 22px;
				box-shadow: 0 1px 4px rgba(0,0,0,.05);
				margin-bottom: 28px;
			}
			.atora-hub__teachers-title {
				margin: 0 0 6px;
				font-size: 16px;
				font-weight: 800;
				color: #111827;
			}
			.atora-hub__teachers-copy {
				margin: 0 0 14px;
				font-size: 13px;
				color: #6b7280;
			}
			.atora-hub__teachers-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
				gap: 10px;
			}
			.atora-hub__teacher-chip {
				display: block;
				padding: 10px 12px;
				border-radius: 12px;
				border: 1px solid #e5e7eb;
				background: #f8fafc;
				color: #1f2937;
				text-decoration: none;
				font-size: 13px;
				font-weight: 600;
			}
			.atora-hub__teacher-chip:hover {
				border-color: #1d4ed8;
				background: #eff6ff;
				color: #1d4ed8;
			}
			.atora-hub__log {
				background: #fff;
				border: 1px solid #e5e7eb;
				border-radius: 18px;
				padding: 22px 24px;
				box-shadow: 0 1px 4px rgba(0,0,0,.05);
				margin-bottom: 28px;
			}
			.atora-hub__log-head {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 12px;
				margin-bottom: 14px;
			}
			.atora-hub__log-title {
				margin: 0;
				font-size: 16px;
				font-weight: 800;
				color: #111827;
			}
			.atora-hub__log-copy {
				margin: 4px 0 0;
				font-size: 13px;
				color: #6b7280;
			}
			.atora-hub__log-total {
				display: grid;
				gap: 2px;
				text-align: right;
			}
			.atora-hub__log-total strong {
				font-size: 28px;
				line-height: 1;
				color: #1d4ed8;
			}
			.atora-hub__log-total span {
				font-size: 11px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: .05em;
				color: #64748b;
			}
			.atora-hub__log-state {
				display: block;
				margin-top: 6px;
				font-size: 12px;
				color: #64748b;
			}
			.atora-hub__log-state[hidden] {
				display: none;
			}
			.atora-hub__log-state--error {
				color: #b91c1c;
			}
			.atora-hub__log-grid {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 12px;
			}
			.atora-hub__log-card {
				border: 1px solid #e5e7eb;
				border-radius: 12px;
				padding: 12px;
				background: #f8fafc;
			}
			.atora-hub__log-card h3 {
				margin: 0 0 8px;
				font-size: 12px;
				font-weight: 800;
				text-transform: uppercase;
				letter-spacing: .05em;
				color: #475569;
			}
			.atora-hub__log-list {
				list-style: none;
				margin: 0;
				padding: 0;
				display: grid;
				gap: 7px;
			}
			.atora-hub__log-item {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 10px;
				font-size: 12px;
			}
			.atora-hub__log-key {
				color: #334155;
			}
			.atora-hub__log-value {
				font-weight: 800;
				color: #0f172a;
			}
			.atora-hub__log-item--empty {
				justify-content: flex-start;
				color: #94a3b8;
			}
				@media (max-width: 1100px) {
					.atora-hub__primary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
					.atora-hub__stats { grid-template-columns: repeat(2, 1fr); }
					.atora-hub__actions { grid-template-columns: 1fr; }
					.atora-hub__actions--split { grid-template-columns: 1fr; }
					.atora-hub__log-grid { grid-template-columns: repeat(2, 1fr); }
					.atora-hub__header { display: grid; gap: 14px; }
					.atora-hub__meta { min-width: 0; width: 100%; }
				}
				@media (max-width: 782px) {
					.atora-hub__primary-grid { grid-template-columns: 1fr; }
					.atora-hub__stats { grid-template-columns: 1fr 1fr; }
					.atora-hub__header { padding: 20px; border-radius: 14px; }
					.atora-hub__title { font-size: 22px; }
					.atora-hub__log-grid { grid-template-columns: 1fr; }
					.atora-hub__log-head { display: grid; }
					.atora-hub__guide-list { grid-template-columns: 1fr; }
				}
			@media (max-width: 600px) {
				.atora-hub__stats { grid-template-columns: 1fr; }
				.atora-hub__meta { grid-template-columns: 1fr; }
			}
			</style>
		<?php
	}

	/**
	 * Enqueue the shared admin stylesheet on every ATORA admin page,
	 * including post-edit screens for lm_course and lm_lesson.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post;
		$hook = is_string( $hook ) ? $hook : '';

		$is_atora_page = (
			strpos( $hook, 'clms-' ) !== false ||
			strpos( $hook, 'atora' ) !== false ||
			'index.php' === $hook ||
			( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) &&
			  $post && in_array( get_post_type( $post ), array( 'lm_course', 'lm_lesson', 'lm_program' ), true ) )
		);

		if ( ! $is_atora_page ) {
			return;
		}

		$css_file = defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL . 'assets/css/admin.css' : '';
		if ( $css_file ) {
			wp_enqueue_style(
				'atora-admin',
				$css_file,
				array(),
				defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0'
			);
		}
			if ( defined( 'ATORA_LMS_URL' ) ) {
				wp_enqueue_style(
					'atora-admin-system',
					ATORA_LMS_URL . 'assets/admin/atora-admin.css',
					array( 'atora-admin' ),
					defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0'
				);
				wp_enqueue_script(
					'atora-crm-log-summary',
					ATORA_LMS_URL . 'assets/js/crm-log-summary.js',
					array(),
					defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0',
					true
				);
			}

		$current_page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$is_gradebook_page = ( 'clms-gradebook' === $current_page || false !== strpos( $hook, 'clms-gradebook' ) );
		if ( $is_gradebook_page && defined( 'ATORA_LMS_URL' ) ) {
			wp_enqueue_style(
				'atora-gradebook',
				ATORA_LMS_URL . 'assets/admin/gradebook/gradebook.css',
				array( 'atora-admin' ),
				defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0'
			);
			wp_enqueue_script(
				'atora-gradebook',
				ATORA_LMS_URL . 'assets/admin/gradebook/gradebook.js',
				array(),
				defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0',
				true
			);
			wp_localize_script(
				'atora-gradebook',
				'clmsGradebook',
				array(
					'rest_url'  => esc_url_raw( rest_url( 'clms/v1/' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'course_id' => isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0,
				)
			);
		}
	}

	public function register_admin_pages(): void {
		// Ícono SVG ámbar — triángulo "A" sobre fondo transparente.
		$icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<path fill="#f59e0b" d="M12 2L2 20h20L12 2zm0 5l6.5 11H5.5L12 7z"/>'
			. '<circle fill="#f59e0b" cx="12" cy="15" r="1.5"/>'
			. '</svg>'
		);
		$settings_cap  = $this->resolve_menu_capability( 'clms_access_admin' );
		$commerce_cap  = $this->resolve_menu_capability( 'clms_manage_commerce' );
		$courses_cap   = $this->resolve_menu_capability( 'clms_manage_courses' );
		$teacher_cap   = $this->resolve_menu_capability( 'clms_view_teacher_dashboard' );
		$grading_cap   = $this->resolve_menu_capability( 'clms_grade_submissions' );

		// Menú principal ATORA.
		add_menu_page(
			__( 'ATORA', 'atora-lms' ),
			__( 'ATORA', 'atora-lms' ),
			'read',
			'clms-dashboard',
			array( $this, 'render_escritorio_page' ),
			$icon_svg,
			25
		);

		// FASE 12A: menú reorganizado — 6 items con visibilidad por cap
		// ── Panel principal ──────────────────────────────────────────────────────
		add_submenu_page(
			'clms-dashboard',
			__( 'Panel', 'atora-lms' ),
			__( 'Panel', 'atora-lms' ),
			'read',
			'clms-dashboard',
			array( $this, 'render_escritorio_page' )
		);

		// ── Academia ──────────────────────────────────────────────────────────────
		add_submenu_page(
			'clms-dashboard',
			__( 'Academia', 'atora-lms' ),
			__( '📚 Academia', 'atora-lms' ),
			'read',
			'clms-academic-hub',
			array( $this, 'render_academic_hub_page' )
		);

		// ── CRM — visible para todos con acceso CRM ────────────────────────
		if ( current_user_can( 'clms_access_crm_view' ) || current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				'clms-dashboard',
				__( 'CRM', 'atora-lms' ),
				__( '🤝 CRM', 'atora-lms' ),
				'clms_access_crm_view',
				'atora-crm-v2',
				array( $this, 'render_crm_v2_page' )
			);
		}

		// ── Marketing — solo para quien gestiona campañas ─────────────────────
		if ( current_user_can( 'crm_manage_campaigns' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				'clms-dashboard',
				__( 'Marketing', 'atora-lms' ),
				__( '📧 Marketing', 'atora-lms' ),
				'crm_manage_campaigns',
				'clms-email-hub',
				array( $this, 'render_email_hub_page' )
			);
		}

		// ── Comercio — solo para rol comercial o admin ────────────────────────
		if ( current_user_can( $commerce_cap ) ) {
			add_submenu_page(
				'clms-dashboard',
				__( 'Comercio', 'atora-lms' ),
				__( '🛒 Comercio', 'atora-lms' ),
				$commerce_cap,
				'clms-commercial-hub',
				array( $this, 'render_commercial_hub_page' )
			);
		}

		// ── Calendario — visible para todos ───────────────────────────────────
		add_submenu_page(
			'clms-dashboard',
			__( 'Calendario', 'atora-lms' ),
			__( '📅 Calendario', 'atora-lms' ),
			'read',
			'atora-calendar',
			array( $this, 'render_calendar_page' )
		);

		// ── Ajustes — solo admin ──────────────────────────────────────────────
		if ( current_user_can( $settings_cap ) ) {
			add_submenu_page(
				'clms-dashboard',
				__( 'Ajustes', 'atora-lms' ),
				__( '⚙️ Ajustes', 'atora-lms' ),
				$settings_cap,
				'clms-settings-hub',
				array( $this, 'render_settings_hub_page' )
			);
			// ── IA — subítem de Ajustes (Fase II S5) ─────────────────────────
			add_submenu_page(
				'clms-dashboard',
				__( 'Inteligencia Artificial', 'atora-lms' ),
				__( '🧠 IA', 'atora-lms' ),
				$settings_cap,
				'clms-ai-hub',
				array( $this, 'render_ai_hub_page' )
			);
			// ── Analytics — subítem de Ajustes (Fase IV S12) ─────────────────
			add_submenu_page(
				'clms-dashboard',
				__( 'Analytics', 'atora-lms' ),
				__( '📊 Analytics', 'atora-lms' ),
				$settings_cap,
				'atora-analytics-dashboard',
				array( $this, 'render_analytics_dashboard_page' )
			);
		}

		// Páginas ocultas del sidebar — accesibles por URL directa (admin.php?page=…).
		// Los widgets del Escritorio las enlazan como "Ver completo".
			$hidden_pages = array(
				array( 'clms-my-profile',          'read',                        'render_my_profile_page' ),
				array( 'clms-instructor-profile',  $teacher_cap,                  'render_instructor_profile_page' ),
				array( 'clms-gradebook',           $grading_cap,                  'render_gradebook_page' ),
				array( 'clms-speedgrader',         $grading_cap,                  'render_speedgrader_page' ),
			array( 'clms-ai-hub',              $courses_cap,                  'render_ai_hub_page' ),
			array( 'clms-academic-content',    'read',                        'render_academic_content_page' ),
				array( 'clms-commercial-operations', $commerce_cap,               'render_commerce_hub_page' ),
			array( 'clms-commerce-hub',          $commerce_cap,               'render_legacy_commerce_hub_alias_page' ),
			array( 'clms-control-center',      'manage_options',              'render_control_center_page' ),
			array( 'clms-commerce-dashboard',  $commerce_cap,                 'render_commerce_dashboard_page' ),
			array( 'clms-settings',            $settings_cap,                 'render_settings_page' ),
				array( 'clms-analytics',           'clms_access_admin',           'render_analytics_page' ),
				array( 'clms-messages',            'clms_access_admin',           'render_messages_page' ),
				array( 'clms-maintenance',         'manage_options',              'render_maintenance_page' ),
				array( 'atora-webhooks',           $settings_cap,                 'render_atora_webhooks_page' ),
				array( 'atora-security',           $settings_cap,                 'render_atora_security_page' ),
				array( 'atora-affiliates',         $commerce_cap,                 'render_atora_affiliates_page' ),
			);
		// Slugs legacy: ocultos en sidebar, accesibles por URL — cap endurecida (audit P1-4)
		$hidden_pages[] = array( 'atora-crm',    'clms_access_crm_view', 'render_crm_v2_page' );
		$hidden_pages[] = array( 'clms-crm-hub', 'clms_access_crm_view', 'render_crm_hub_page' );

		if ( $this->is_crm_v2_enabled() ) {
			$hidden_pages[] = array( 'atora-crm-comercial',            'clms_access_crm_view', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-academico',            'clms_access_crm_view', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-pipeline-sales',    'clms_access_crm_view', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-pipeline-academic', 'clms_access_crm_view', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-inbox',             'read', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-contacts',          'read', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-calendar',          'read', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-campaigns',         'read', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-reports',           'read', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-academico-reports',    'read', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-settings',          'read', 'render_crm_v2_page' );
			$hidden_pages[] = array( 'atora-crm-v2-duplicates',        'read', 'render_crm_v2_page' );
		}
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$hidden_pages = (array) CLMS_Helper::modular_apply( 'admin_hidden_pages', $hidden_pages, get_current_user_id() );
		}

			foreach ( $hidden_pages as $page ) {
				if ( ! is_array( $page ) ) {
					continue;
				}

				$slug       = isset( $page[0] ) ? sanitize_key( (string) $page[0] ) : '';
				$capability = isset( $page[1] ) ? sanitize_key( (string) $page[1] ) : 'read';
				$callback   = isset( $page[2] ) ? sanitize_key( (string) $page[2] ) : '';

				if ( '' === $slug || '' === $callback || ! method_exists( $this, $callback ) ) {
					continue;
				}

				if ( '' === $capability ) {
					$capability = 'read';
				}

				add_submenu_page(
					'',          // parent vacío → no aparece en sidebar.
					'',
					'',
					$capability,
					$slug,
					array( $this, $callback )
				);
				}

			// Puente legacy: permite que módulos v5 registrados en `atora_lms_admin_menu`
			// inyecten sus submenús bajo el menú real de ATORA (`clms-dashboard`).
			do_action( 'atora_lms_admin_menu' );
	}

	/**
	 * Prioriza navegación por hubs, ocultando entradas de acceso duplicadas.
	 */
	public function cleanup_atora_submenus(): void {
		$hidden_submenus = array(
			'edit.php?post_type=lm_course',
			'edit.php?post_type=lm_program',
			'edit.php?post_type=lm_cohort',
			'edit.php?post_type=lm_lesson',
			'edit.php?post_type=atora_teacher',
			'edit.php?post_type=clms_rubric',
			'atora-calendar',
			'atora-analytics',
			'atora-live-streaming',
				'atora-webhooks',
				'atora-security',
				'atora-affiliates',
			);

		foreach ( $hidden_submenus as $submenu_slug ) {
			remove_submenu_page( 'clms-dashboard', $submenu_slug );
		}

		$this->prioritize_dashboard_root_submenu();
	}

	/**
	 * Fuerza "Panel ATORA" como primera entrada del menú principal para que
	 * el clic en el ícono ATORA siempre abra el escritorio.
	 */
	protected function prioritize_dashboard_root_submenu(): void {
		global $submenu;

		$items = (array) ( $submenu['clms-dashboard'] ?? array() );
		if ( empty( $items ) ) {
			return;
		}

		$root_item = null;
		$filtered  = array();
		foreach ( $items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'clms-dashboard' === $slug ) {
				$root_item = (array) $item;
				continue;
			}

			$filtered[] = $item;
		}

		if ( null === $root_item ) {
			$root_item = array(
				__( 'Panel ATORA', 'atora-lms' ),
				'read',
				'clms-dashboard',
				__( 'Panel ATORA', 'atora-lms' ),
			);
		}

		$root_item[0] = __( 'Panel ATORA', 'atora-lms' );
		$root_item[3] = __( 'Panel ATORA', 'atora-lms' );

		$submenu['clms-dashboard'] = array_merge( array( $root_item ), $filtered );
	}

	protected function get_gradebook_rubrics_url(): string {
		return add_query_arg(
			array(
				'page'  => 'clms-gradebook',
				'focus' => 'rubrics',
			),
			admin_url( 'admin.php' )
		);
	}

	protected function get_academic_calendar_url(): string {
		return add_query_arg(
			array(
				'page'     => 'atora-calendar',
				'scope'    => 'academic',
				'cal_view' => 'month',
				'type'     => 'live_class',
			),
			admin_url( 'admin.php' )
		);
	}

	protected function get_academic_content_url(): string {
		return admin_url( 'admin.php?page=clms-academic-content' );
	}

	protected function get_commercial_calendar_url(): string {
		return add_query_arg(
			array(
				'page'     => 'atora-calendar',
				'scope'    => 'commercial',
				'cal_view' => 'month',
				'type'     => 'meeting',
			),
			admin_url( 'admin.php' )
		);
	}

	protected function get_analytics_hub_url(): string {
		return admin_url( 'admin.php?page=clms-analytics' );
	}

	protected function get_commercial_operations_url(): string {
		return admin_url( 'admin.php?page=clms-commercial-operations' );
	}

	/**
	 * Determina si el andamiaje de CRM v2 está habilitado.
	 *
	 * @return bool
	 */
	protected function is_crm_v2_enabled(): bool {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'is_crm_v2_enabled' ) ) {
			return (bool) CLMS_Settings::is_crm_v2_enabled();
		}

		return ! empty( get_option( 'clms_crm_v2_enabled', false ) );
	}

	/**
	 * Proxy estable para renderizar la página de Ajustes de ATORA.
	 * Evita depender de callbacks nulos en el menú y mantiene compatibilidad
	 * con el owner central de configuración (CLMS_Settings).
	 */
	public function render_settings_page(): void {
		if ( ! $this->can_access_settings_hub() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$settings = null;

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' ) ) {
			$settings = clms_core('CLMS_Settings');
		}

		if ( $settings && method_exists( $settings, 'render_page' ) ) {
			$settings->render_page();
			return;
		}

		if ( class_exists( 'CLMS_Settings' ) ) {
			$fallback = new CLMS_Settings();
			if ( method_exists( $fallback, 'render_page' ) ) {
				$fallback->render_page();
				return;
			}
		}

		wp_die( esc_html__( 'No se pudo cargar la pantalla de ajustes de ATORA.', 'atora-lms' ) );
	}

	/**
	 * Hub de email: acceso unificado a Email Engine y Newsletter.
	 */
	public function render_email_hub_page(): void {
		if ( ! $this->can_access_settings_hub() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$today        = wp_date( get_option( 'date_format' ) );
		$user_id      = get_current_user_id();
		$crm_snapshot = $this->get_crm_summary_snapshot();
		$msg_snapshot = $this->get_message_hub_snapshot( $user_id );
		$header_stats = array(
			array(
				'label' => __( 'Emails pendientes', 'atora-lms' ),
				'value' => absint( $crm_snapshot['pending_emails'] ?? 0 ),
			),
			array(
				'label' => __( 'Últimos envíos', 'atora-lms' ),
				'value' => absint( count( (array) ( $crm_snapshot['recent_emails'] ?? array() ) ) ),
			),
			array(
				'label' => __( 'Leads activos', 'atora-lms' ),
				'value' => absint( $crm_snapshot['leads_count'] ?? 0 ),
			),
			array(
				'label' => __( 'Mensajes sin leer', 'atora-lms' ),
				'value' => absint( $msg_snapshot['stats']['unread'] ?? 0 ),
			),
		);
		$guide_steps  = array(
			__( 'Configura proveedor, remitentes e identidad de envío.', 'atora-lms' ),
			__( 'Crea campaña o boletín con copy claro y CTA simple.', 'atora-lms' ),
			__( 'Revisa cola, estado de entrega y ajusta antes de escalar.', 'atora-lms' ),
		);
		$cards = array(
			array(
				'variant'     => 'teal',
				'icon'        => '✉️',
				'title'       => __( 'Email Engine', 'atora-lms' ),
				'description' => __( 'Gestión operativa de plantillas, cola, diagnósticos e identidades de envío.', 'atora-lms' ),
				'features'    => array(
					__( 'Panel completo de emails transaccionales y académicos.', 'atora-lms' ),
					__( 'Diagnóstico de cola y estado de envío en tiempo real.', 'atora-lms' ),
					__( 'Control de identidades por área (academia, docente, admin).', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=atora-emails' ),
				'button'      => __( 'Abrir Email Engine', 'atora-lms' ),
			),
			array(
				'variant'     => 'indigo',
				'icon'        => '📰',
				'title'       => __( 'Newsletter', 'atora-lms' ),
				'description' => __( 'Campañas recurrentes, boletines y comunicación editorial para comunidad y leads.', 'atora-lms' ),
				'features'    => array(
					__( 'Creación y envío de newsletters desde flujo simple.', 'atora-lms' ),
					__( 'Programación y segmentación por audiencia.', 'atora-lms' ),
					__( 'Integración directa con el motor de emails actual.', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=atora-newsletter' ),
				'button'      => __( 'Abrir Newsletter', 'atora-lms' ),
			),
		);

		$quick_links = $this->get_operational_hub_links( 'clms-email-hub', 8 );
			?>
			<div class="wrap atora-hub atora-hub--email">
				<div class="atora-hub__header">
					<div>
						<span class="atora-hub__context"><?php esc_html_e( 'Email Hub', 'atora-lms' ); ?></span>
						<h1 class="atora-hub__title"><?php esc_html_e( 'Comunicaciones por email', 'atora-lms' ); ?></h1>
						<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( 'Operación de campañas y transaccionales', 'atora-lms' ); ?></p>
						<p class="atora-hub__subtitle"><?php esc_html_e( 'Concentra Email Engine y Newsletter en un solo lugar operativo para ejecutar campañas y transaccionales con claridad.', 'atora-lms' ); ?></p>
					</div>
					<div class="atora-hub__meta">
						<?php foreach ( $header_stats as $header_stat ) : ?>
							<div class="atora-hub__meta-item">
								<strong class="atora-hub__meta-num"><?php echo esc_html( number_format_i18n( (int) $header_stat['value'] ) ); ?></strong>
								<span class="atora-hub__meta-lbl"><?php echo esc_html( (string) $header_stat['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="atora-hub__guide">
					<h3 class="atora-hub__guide-title"><?php esc_html_e( 'Flujo recomendado en 3 pasos', 'atora-lms' ); ?></h3>
					<div class="atora-hub__guide-list">
						<?php foreach ( $guide_steps as $step_index => $step_label ) : ?>
							<div class="atora-hub__guide-step">
								<span class="atora-hub__guide-badge"><?php echo esc_html( (string) ( $step_index + 1 ) ); ?></span>
								<span><?php echo esc_html( (string) $step_label ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="atora-hub__actions atora-hub__actions--split">
					<?php foreach ( $cards as $card ) : ?>
						<div class="atora-hub__action-card atora-hub__action-card--<?php echo esc_attr( sanitize_html_class( (string) $card['variant'] ) ); ?>">
						<div class="atora-hub__action-icon"><?php echo esc_html( (string) $card['icon'] ); ?></div>
						<h2 class="atora-hub__action-title"><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<p class="atora-hub__action-desc"><?php echo esc_html( (string) $card['description'] ); ?></p>
						<ul class="atora-hub__feature-list">
							<?php foreach ( (array) ( $card['features'] ?? array() ) as $feature ) : ?>
								<li><?php echo esc_html( (string) $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="atora-hub__action-btn"><?php echo esc_html( (string) $card['button'] ); ?> →</a>
					</div>
				<?php endforeach; ?>
			</div>

				<div class="atora-hub__quick">
					<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Red de hubs ATORA', 'atora-lms' ); ?></h3>
					<div class="atora-hub__quick-grid">
					<?php foreach ( $quick_links as $item ) : ?>
						<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
							<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
							<span><?php echo esc_html( (string) $item['description'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Analytics dashboard (Fase IV S12).
	 */
	public function render_analytics_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		// Cargar Chart.js si no está encolado
		if ( ! wp_script_is( 'chart-js', 'enqueued' ) ) {
			wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
		}
		$view = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'analytics/views/dashboard.php' : '';
		if ( $view && file_exists( $view ) ) {
			require $view;
		}
	}

	/**
	 * Hub de ajustes: acceso a configuración general + email + mensajería.
	 */
	public function render_settings_hub_page(): void {
		if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$today        = wp_date( get_option( 'date_format' ) );
		$crm_snapshot = $this->get_crm_summary_snapshot();
			$header_stats = array(
				array(
					'label' => __( 'Módulos clave', 'atora-lms' ),
					'value' => 4,
				),
			array(
				'label' => __( 'Cola email', 'atora-lms' ),
				'value' => absint( $crm_snapshot['pending_emails'] ?? 0 ),
			),
			array(
				'label' => __( 'Eventos próximos', 'atora-lms' ),
				'value' => absint( count( (array) ( $crm_snapshot['upcoming_events'] ?? array() ) ) ),
			),
			array(
				'label' => __( 'Contactos CRM', 'atora-lms' ),
				'value' => absint( $crm_snapshot['contacts_count'] ?? 0 ),
			),
		);
		$guide_steps  = array(
			__( 'Define ajustes globales del LMS y seguridad base.', 'atora-lms' ),
			__( 'Conecta email y mensajería para notificaciones consistentes.', 'atora-lms' ),
			__( 'Valida permisos y guarda una configuración estable por entorno.', 'atora-lms' ),
		);
		$cards = array(
			array(
				'variant'     => 'blue',
				'icon'        => '⚙️',
				'title'       => __( 'Ajustes ATORA', 'atora-lms' ),
				'description' => __( 'Configuración central del LMS, seguridad, APIs y parámetros de operación.', 'atora-lms' ),
				'features'    => array(
					__( 'Ajustes generales de plataforma y entorno.', 'atora-lms' ),
					__( 'Opciones académicas, comerciales y técnicas.', 'atora-lms' ),
					__( 'Punto de entrada oficial de configuración.', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=clms-settings' ),
				'button'      => __( 'Abrir ajustes', 'atora-lms' ),
			),
			array(
				'variant'     => 'teal',
				'icon'        => '📧',
				'title'       => __( 'Ajustes de email', 'atora-lms' ),
				'description' => __( 'Proveedores, identidades y entregabilidad del ecosistema de correo.', 'atora-lms' ),
				'features'    => array(
					__( 'Control de proveedores y rutas de envío.', 'atora-lms' ),
					__( 'Identidades y plantillas del Email Engine.', 'atora-lms' ),
					__( 'Diagnósticos para soporte operativo.', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=atora-emails' ),
				'button'      => __( 'Abrir email', 'atora-lms' ),
			),
				array(
					'variant'     => 'amber',
					'icon'        => '💬',
					'title'       => __( 'Ajustes de mensajería', 'atora-lms' ),
				'description' => __( 'Configuración de canales como WhatsApp y Telegram para flujos académicos y CRM.', 'atora-lms' ),
				'features'    => array(
					__( 'Pruebas, ruteo y fallback por canal.', 'atora-lms' ),
					__( 'Conexión de mensajería con campañas y automatizaciones.', 'atora-lms' ),
					__( 'Centro de control de comunicación no-email.', 'atora-lms' ),
				),
					'url'         => admin_url( 'admin.php?page=atora-messaging' ),
					'button'      => __( 'Abrir mensajería', 'atora-lms' ),
				),
				array(
					'variant'     => 'slate',
					'icon'        => '🛡️',
					'title'       => __( 'Seguridad', 'atora-lms' ),
					'description' => __( 'Políticas de acceso, 2FA y captcha inteligente para proteger la operación.', 'atora-lms' ),
					'features'    => array(
						__( 'Define política de autenticación de dos factores.', 'atora-lms' ),
						__( 'Configura proveedor de captcha y llaves.', 'atora-lms' ),
						__( 'Fortalece seguridad sin salir del flujo de ajustes.', 'atora-lms' ),
					),
					'url'         => admin_url( 'admin.php?page=atora-security' ),
					'button'      => __( 'Abrir seguridad', 'atora-lms' ),
				),
			);
		$cards = $this->unique_hub_items_by_url( $cards, 0, true );

		$quick_links = $this->get_operational_hub_links( 'clms-settings-hub', 8 );
			?>
			<div class="wrap atora-hub atora-hub--settings">
				<div class="atora-hub__header">
					<div>
						<span class="atora-hub__context"><?php esc_html_e( 'Hub ajustes', 'atora-lms' ); ?></span>
						<h1 class="atora-hub__title"><?php esc_html_e( 'Configuración central', 'atora-lms' ); ?></h1>
						<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( 'Plataforma, email y mensajería', 'atora-lms' ); ?></p>
						<p class="atora-hub__subtitle"><?php esc_html_e( 'Concentra la configuración técnica y operativa en un solo lugar para reducir fricción entre paneles.', 'atora-lms' ); ?></p>
					</div>
					<div class="atora-hub__meta">
						<?php foreach ( $header_stats as $header_stat ) : ?>
							<div class="atora-hub__meta-item">
								<strong class="atora-hub__meta-num"><?php echo esc_html( number_format_i18n( (int) $header_stat['value'] ) ); ?></strong>
								<span class="atora-hub__meta-lbl"><?php echo esc_html( (string) $header_stat['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="atora-hub__guide">
					<h3 class="atora-hub__guide-title"><?php esc_html_e( 'Flujo recomendado en 3 pasos', 'atora-lms' ); ?></h3>
					<div class="atora-hub__guide-list">
						<?php foreach ( $guide_steps as $step_index => $step_label ) : ?>
							<div class="atora-hub__guide-step">
								<span class="atora-hub__guide-badge"><?php echo esc_html( (string) ( $step_index + 1 ) ); ?></span>
								<span><?php echo esc_html( (string) $step_label ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="atora-hub__actions">
					<?php foreach ( $cards as $card ) : ?>
						<div class="atora-hub__action-card atora-hub__action-card--<?php echo esc_attr( sanitize_html_class( (string) $card['variant'] ) ); ?>">
						<div class="atora-hub__action-icon"><?php echo esc_html( (string) $card['icon'] ); ?></div>
						<h2 class="atora-hub__action-title"><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<p class="atora-hub__action-desc"><?php echo esc_html( (string) $card['description'] ); ?></p>
						<ul class="atora-hub__feature-list">
							<?php foreach ( (array) ( $card['features'] ?? array() ) as $feature ) : ?>
								<li><?php echo esc_html( (string) $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="atora-hub__action-btn"><?php echo esc_html( (string) $card['button'] ); ?> →</a>
					</div>
				<?php endforeach; ?>
			</div>

				<div class="atora-hub__quick">
					<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Red de hubs ATORA', 'atora-lms' ); ?></h3>
					<div class="atora-hub__quick-grid">
					<?php foreach ( $quick_links as $item ) : ?>
						<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
							<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
							<span><?php echo esc_html( (string) $item['description'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Hub de automatizaciones para procesos CRM.
	 */
	public function render_automation_hub_page(): void {
		if ( ! $this->can_access_settings_hub() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$today        = wp_date( get_option( 'date_format' ) );
		$user_id      = get_current_user_id();
		$crm_snapshot = $this->get_crm_summary_snapshot();
		$msg_snapshot = $this->get_message_hub_snapshot( $user_id );
		$header_stats = array(
			array(
				'label' => __( 'Módulos activos', 'atora-lms' ),
				'value' => 2,
			),
			array(
				'label' => __( 'Cola email', 'atora-lms' ),
				'value' => absint( $crm_snapshot['pending_emails'] ?? 0 ),
			),
			array(
				'label' => __( 'Eventos próximos', 'atora-lms' ),
				'value' => absint( count( (array) ( $crm_snapshot['upcoming_events'] ?? array() ) ) ),
			),
			array(
				'label' => __( 'Mensajes sin leer', 'atora-lms' ),
				'value' => absint( $msg_snapshot['stats']['unread'] ?? 0 ),
			),
		);
		$guide_steps  = array(
			__( 'Crea el disparador según evento comercial o académico.', 'atora-lms' ),
			__( 'Define canal y acción: email, mensajería o webhook.', 'atora-lms' ),
			__( 'Monitorea ejecución, errores y reintentos desde los logs.', 'atora-lms' ),
		);
		$cards = array(
			array(
				'variant'     => 'slate',
				'icon'        => '⚡',
				'title'       => __( 'Automatizaciones', 'atora-lms' ),
				'description' => __( 'Diseña y opera reglas automáticas para lifecycle comercial y académico.', 'atora-lms' ),
				'features'    => array(
					__( 'Triggers, condiciones y acciones en un flujo central.', 'atora-lms' ),
					__( 'Integración con CRM, mensajería y evaluación.', 'atora-lms' ),
					__( 'Ejecución con trazabilidad y reintentos.', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=atora-automations' ),
				'button'      => __( 'Abrir automatizaciones', 'atora-lms' ),
			),
			array(
				'variant'     => 'indigo',
				'icon'        => '🔗',
				'title'       => __( 'Webhooks', 'atora-lms' ),
				'description' => __( 'Conecta ATORA con servicios externos por eventos salientes y endpoints controlados.', 'atora-lms' ),
				'features'    => array(
					__( 'Activadores por eventos clave del CRM.', 'atora-lms' ),
					__( 'Integración con sistemas externos y APIs.', 'atora-lms' ),
					__( 'Gestión de endpoints sin salir del ecosistema.', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=atora-webhooks' ),
				'button'      => __( 'Abrir webhooks', 'atora-lms' ),
			),
		);

		$quick_links = $this->get_operational_hub_links( 'clms-automation-hub', 8 );
			?>
			<div class="wrap atora-hub atora-hub--automation">
				<div class="atora-hub__header">
					<div>
						<span class="atora-hub__context"><?php esc_html_e( 'Hub automatizaciones', 'atora-lms' ); ?></span>
						<h1 class="atora-hub__title"><?php esc_html_e( 'Procesos automatizados del CRM', 'atora-lms' ); ?></h1>
						<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( 'Automatizaciones y webhooks', 'atora-lms' ); ?></p>
						<p class="atora-hub__subtitle"><?php esc_html_e( 'Concentra automatizaciones y webhooks del CRM en un solo lugar operativo para ejecutar procesos con trazabilidad.', 'atora-lms' ); ?></p>
					</div>
					<div class="atora-hub__meta">
						<?php foreach ( $header_stats as $header_stat ) : ?>
							<div class="atora-hub__meta-item">
								<strong class="atora-hub__meta-num"><?php echo esc_html( number_format_i18n( (int) $header_stat['value'] ) ); ?></strong>
								<span class="atora-hub__meta-lbl"><?php echo esc_html( (string) $header_stat['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="atora-hub__guide">
					<h3 class="atora-hub__guide-title"><?php esc_html_e( 'Flujo recomendado en 3 pasos', 'atora-lms' ); ?></h3>
					<div class="atora-hub__guide-list">
						<?php foreach ( $guide_steps as $step_index => $step_label ) : ?>
							<div class="atora-hub__guide-step">
								<span class="atora-hub__guide-badge"><?php echo esc_html( (string) ( $step_index + 1 ) ); ?></span>
								<span><?php echo esc_html( (string) $step_label ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="atora-hub__actions atora-hub__actions--split">
					<?php foreach ( $cards as $card ) : ?>
						<div class="atora-hub__action-card atora-hub__action-card--<?php echo esc_attr( sanitize_html_class( (string) $card['variant'] ) ); ?>">
						<div class="atora-hub__action-icon"><?php echo esc_html( (string) $card['icon'] ); ?></div>
						<h2 class="atora-hub__action-title"><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<p class="atora-hub__action-desc"><?php echo esc_html( (string) $card['description'] ); ?></p>
						<ul class="atora-hub__feature-list">
							<?php foreach ( (array) ( $card['features'] ?? array() ) as $feature ) : ?>
								<li><?php echo esc_html( (string) $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="atora-hub__action-btn"><?php echo esc_html( (string) $card['button'] ); ?> →</a>
					</div>
				<?php endforeach; ?>
			</div>

				<div class="atora-hub__quick">
					<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Red de hubs ATORA', 'atora-lms' ); ?></h3>
					<div class="atora-hub__quick-grid">
					<?php foreach ( $quick_links as $item ) : ?>
						<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
							<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
							<span><?php echo esc_html( (string) $item['description'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
			<?php
	}

	/**
	 * Hub comercial para operación de ventas, afiliados y crecimiento.
	 */
	public function render_commercial_hub_page(): void {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		global $wpdb;

		$today          = wp_date( get_option( 'date_format' ) );
		$product_count  = post_type_exists( 'product' ) ? $this->count_posts_by_type( 'product' ) : 0;
		$course_count   = $this->count_commercial_entities( 'lm_course' );
		$program_count  = $this->count_commercial_entities( 'lm_program' );
		$affiliate_total   = 0;
		$affiliate_active  = 0;
		$affiliate_pending = 0;
		$pending_payouts   = 0;

		$aff_table = "{$wpdb->prefix}atora_affiliates";
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$aff_table}'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$affiliate_total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$aff_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$affiliate_active  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$aff_table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$affiliate_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$aff_table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$comm_table = "{$wpdb->prefix}atora_affiliate_commissions";
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$comm_table}'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$pending_payouts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$comm_table} WHERE status = 'approved'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$header_stats = array(
			array(
				'label' => __( 'Productos', 'atora-lms' ),
				'value' => $product_count,
			),
			array(
				'label' => __( 'Cursos comerciales', 'atora-lms' ),
				'value' => $course_count,
			),
			array(
				'label' => __( 'Afiliados activos', 'atora-lms' ),
				'value' => $affiliate_active,
			),
			array(
				'label' => __( 'Pagos afiliados pendientes', 'atora-lms' ),
				'value' => $pending_payouts,
			),
		);

		$guide_steps = array(
			__( 'Alinea productos, pricing y propuesta comercial.', 'atora-lms' ),
			__( 'Coordina campañas con CRM Hub y analítica.', 'atora-lms' ),
			__( 'Gestiona afiliados y pagos para escalar adquisición.', 'atora-lms' ),
		);

		$cards = array(
			array(
				'variant'     => 'blue',
				'icon'        => '🛍️',
				'title'       => __( 'Operación comercial', 'atora-lms' ),
				'description' => __( 'Centro operativo de productos, bundles, membresías y activación postcompra.', 'atora-lms' ),
				'features'    => array(
					sprintf( __( '%d productos en catálogo.', 'atora-lms' ), absint( $product_count ) ),
					sprintf( __( '%d cursos comerciales activos.', 'atora-lms' ), absint( $course_count ) ),
					sprintf( __( '%d programas comerciales activos.', 'atora-lms' ), absint( $program_count ) ),
				),
				'url'         => $this->get_commercial_operations_url(),
				'button'      => __( 'Abrir operación', 'atora-lms' ),
			),
				array(
					'variant'     => 'amber',
					'icon'        => '📈',
					'title'       => __( 'Dashboard comercial', 'atora-lms' ),
					'description' => __( 'Sigue conversiones, ingresos y productos con mejor desempeño.', 'atora-lms' ),
					'features'    => array(
						__( 'KPIs comerciales listos para decisión.', 'atora-lms' ),
					__( 'Lectura de funnel y comportamiento de compra.', 'atora-lms' ),
					__( 'Cruce de datos con Hub CRM y analítica.', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=clms-commerce-dashboard' ),
				'button'      => __( 'Abrir dashboard', 'atora-lms' ),
			),
			array(
				'variant'     => 'indigo',
				'icon'        => '🤝',
				'title'       => __( 'Afiliados', 'atora-lms' ),
				'description' => __( 'Gestiona aliados, estados de aprobación y operación de comisiones.', 'atora-lms' ),
				'features'    => array(
					sprintf( __( '%d afiliados registrados.', 'atora-lms' ), absint( $affiliate_total ) ),
					sprintf( __( '%d afiliados pendientes por revisar.', 'atora-lms' ), absint( $affiliate_pending ) ),
					sprintf( __( '%d comisiones aprobadas pendientes de pago.', 'atora-lms' ), absint( $pending_payouts ) ),
				),
				'url'         => admin_url( 'admin.php?page=atora-affiliates' ),
				'button'      => __( 'Abrir afiliados', 'atora-lms' ),
			),
			array(
				'variant'     => 'teal',
				'icon'        => '🧲',
				'title'       => __( 'CRM Hub', 'atora-lms' ),
				'description' => __( 'Conecta estrategia comercial con embudo, mensajería y seguimiento académico.', 'atora-lms' ),
				'features'    => array(
					__( 'Segmentación por estado y origen de contacto.', 'atora-lms' ),
					__( 'Automatización de campañas conectada al cierre.', 'atora-lms' ),
					__( 'Visión integral de adquisición y retención.', 'atora-lms' ),
				),
				'url'         => admin_url( 'admin.php?page=clms-crm-hub' ),
				'button'      => __( 'Abrir CRM Hub', 'atora-lms' ),
			),
		);
		$cards = $this->unique_hub_items_by_url( $cards, 0, true );

		$quick_links = $this->get_operational_hub_links( 'clms-commercial-hub', 8 );
		?>
			<div class="wrap atora-hub atora-hub--commercial">
				<div class="atora-hub__header">
					<div>
						<span class="atora-hub__context"><?php esc_html_e( 'Hub comercial', 'atora-lms' ); ?></span>
						<h1 class="atora-hub__title"><?php esc_html_e( 'Operación comercial y crecimiento', 'atora-lms' ); ?></h1>
						<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( 'Ventas, afiliados y coordinación con CRM', 'atora-lms' ); ?></p>
						<p class="atora-hub__subtitle"><?php esc_html_e( 'Concentra ventas, afiliados y alianzas en un solo lugar operativo para escalar ingresos con una experiencia consistente.', 'atora-lms' ); ?></p>
					</div>
				<div class="atora-hub__meta">
					<?php foreach ( $header_stats as $header_stat ) : ?>
						<div class="atora-hub__meta-item">
							<strong class="atora-hub__meta-num"><?php echo esc_html( number_format_i18n( (int) $header_stat['value'] ) ); ?></strong>
							<span class="atora-hub__meta-lbl"><?php echo esc_html( (string) $header_stat['label'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="atora-hub__guide">
				<h3 class="atora-hub__guide-title"><?php esc_html_e( 'Flujo recomendado en 3 pasos', 'atora-lms' ); ?></h3>
				<div class="atora-hub__guide-list">
					<?php foreach ( $guide_steps as $step_index => $step_label ) : ?>
						<div class="atora-hub__guide-step">
							<span class="atora-hub__guide-badge"><?php echo esc_html( (string) ( $step_index + 1 ) ); ?></span>
							<span><?php echo esc_html( (string) $step_label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="atora-hub__actions">
				<?php foreach ( $cards as $card ) : ?>
					<div class="atora-hub__action-card atora-hub__action-card--<?php echo esc_attr( sanitize_html_class( (string) $card['variant'] ) ); ?>">
						<div class="atora-hub__action-icon"><?php echo esc_html( (string) $card['icon'] ); ?></div>
						<h2 class="atora-hub__action-title"><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<p class="atora-hub__action-desc"><?php echo esc_html( (string) $card['description'] ); ?></p>
						<ul class="atora-hub__feature-list">
							<?php foreach ( (array) ( $card['features'] ?? array() ) as $feature ) : ?>
								<li><?php echo esc_html( (string) $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="atora-hub__action-btn"><?php echo esc_html( (string) $card['button'] ); ?> →</a>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="atora-hub__quick">
				<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Red de hubs ATORA', 'atora-lms' ); ?></h3>
				<div class="atora-hub__quick-grid">
					<?php foreach ( $quick_links as $item ) : ?>
						<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
							<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
							<span><?php echo esc_html( (string) $item['description'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_maintenance_page(): void {
		$maintenance = clms_core('CLMS_Maintenance');
		if ( $maintenance && method_exists( $maintenance, 'render_page' ) ) {
			$maintenance->render_page();
		} elseif ( class_exists( 'CLMS_Maintenance' ) ) {
			( new CLMS_Maintenance() )->render_page();
		} else {
			wp_die( esc_html__( 'Módulo de mantenimiento no disponible.', 'atora-lms' ) );
		}
	}

	/**
	 * Render helper para rutas ocultas de módulos legacy sin sidebar.
	 */
	protected function render_hidden_module_view( string $view_rel_path, string $title, string $access_scope = 'settings' ): void {
		$allowed = false;
		switch ( sanitize_key( $access_scope ) ) {
			case 'commercial':
				$allowed = $this->can_access_commercial_hub();
				break;
			case 'strict_admin':
				$allowed = current_user_can( 'manage_options' );
				break;
			case 'settings':
			default:
				$allowed = $this->can_access_settings_hub();
				break;
		}

		if ( ! $allowed ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$view = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . ltrim( $view_rel_path, '/' ) : '';
		if ( $view && file_exists( $view ) ) {
			try {
				require $view;
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					error_log( '[ATORA Hidden Route] Error al renderizar ' . $view_rel_path . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'No se pudo cargar esta vista. Revisa el log de errores.', 'atora-lms' ) . '</p></div></div>';
			}
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><div class="notice notice-warning"><p>' . esc_html__( 'Vista no disponible. Falta el archivo del módulo.', 'atora-lms' ) . '</p></div></div>';
	}

	public function render_atora_emails_page(): void {
		$this->render_hidden_module_view( 'email-engine/views/admin.php', __( 'Email Engine', 'atora-lms' ), 'settings' );
	}

	public function render_atora_newsletter_page(): void {
		$this->render_hidden_module_view( 'newsletter/views/admin.php', __( 'Newsletter', 'atora-lms' ), 'settings' );
	}

	public function render_atora_messaging_page(): void {
		$this->render_hidden_module_view( 'messaging/views/settings.php', __( 'Mensajería', 'atora-lms' ), 'settings' );
	}

	public function render_atora_automations_page(): void {
		$this->render_hidden_module_view( 'automation/views/admin.php', __( 'Automatizaciones', 'atora-lms' ), 'settings' );
	}

	public function render_atora_webhooks_page(): void {
		$this->render_hidden_module_view( 'automation/views/webhooks.php', __( 'Webhooks', 'atora-lms' ), 'settings' );
	}

	public function render_atora_security_page(): void {
		$this->render_hidden_module_view( 'security/views/settings.php', __( 'Seguridad', 'atora-lms' ), 'settings' );
	}

	public function render_atora_affiliates_page(): void {
		$this->render_hidden_module_view( 'affiliates/views/admin.php', __( 'Afiliados', 'atora-lms' ), 'commercial' );
	}

	public function render_calendar_page(): void {
		$atora_calendar_can_view_academic     = $this->can_access_academic_calendar();
		$atora_calendar_can_view_commercial   = $this->can_access_commercial_calendar();
		$atora_calendar_can_view              = $this->can_access_calendar_page();
		$atora_calendar_can_manage_academic   = $this->can_manage_academic_calendar_events();
		$atora_calendar_can_manage_commercial = $this->can_manage_commercial_calendar_events();
		$atora_calendar_can_manage            = $this->can_manage_calendar_events();

		if ( ! $atora_calendar_can_view ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$view = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'calendar/views/admin.php' : '';
		if ( $view && file_exists( $view ) ) {
			require $view;
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Calendario', 'atora-lms' ) . '</h1>'
			. '<p>' . esc_html__( 'Módulo de calendario no disponible.', 'atora-lms' ) . '</p></div>';
	}

	/**
	 * Pantalla beta de CRM v2 (feature flag + admin).
	 *
	 * @return void
	 */
	/**
	 * Devuelve la URL CRM correcta según flag (audit P1-5).
	 * Evita el loop de redirección circular entre legacy y v2.
	 */
	public static function get_crm_url_for_current_user(): string {
		$crm_v2_active = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'is_crm_v2_enabled' )
			? CLMS_Settings::is_crm_v2_enabled()
			: (bool) get_option( 'clms_crm_v2_enabled', false );
		return $crm_v2_active
			? admin_url( 'admin.php?page=atora-crm-v2' )
			: admin_url( 'admin.php?page=atora-crm-legacy' );
	}

	public function render_crm_v2_page(): void {
		$page_slug = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : 'atora-crm-v2'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Si v2 desactivado y el slug solicitado es un alias legacy → mostrar CRM legacy
		$v2_enabled = $this->is_crm_v2_enabled();
		$is_legacy_alias = in_array( $page_slug, array( 'atora-crm', 'clms-crm-hub' ), true );
		if ( ! $v2_enabled || $is_legacy_alias ) {
			$this->render_crm_legacy_fallback();
			return;
		}
		$app_class = '\ATORA\CRM_V2\CRM_V2_App';
		$app_file  = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm-v2/class-crm-v2-app.php' : '';
		if ( $app_file && file_exists( $app_file ) ) {
			require_once $app_file;
		}
		if ( class_exists( $app_class ) && method_exists( $app_class, 'render_page' ) ) {
			if ( method_exists( $app_class, 'init' ) ) {
				$app_class::init();
			}
			if ( method_exists( $app_class, 'can_access' ) && ! $app_class::can_access() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=atora-crm' ) );
				exit;
			}
			$app_class::render_page( $page_slug );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$module_class = '\ATORA\CRM_V2\CRM_V2';
		$module_file  = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm-v2/class-crm-v2.php' : '';

		if ( $module_file && file_exists( $module_file ) ) {
			require_once $module_file;
		}

		if ( class_exists( $module_class ) && method_exists( $module_class, 'render_admin_page' ) ) {
			$module_class::render_admin_page();
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'CRM v2 (beta)', 'atora-lms' ) . '</h1>'
			. '<p>' . esc_html__( 'Módulo CRM v2 no disponible.', 'atora-lms' ) . '</p></div>';
	}

	/**
	 * Fallback para slugs legacy cuando CRM v2 está desactivado.
	 * Muestra el CRM legacy real o un aviso con link al módulo correcto (audit P1-5).
	 */
	private function render_crm_legacy_fallback(): void {
		$legacy_view = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm/views/admin.php' : '';
		if ( $legacy_view && file_exists( $legacy_view ) ) {
			require $legacy_view;
			return;
		}
		echo '<div class="wrap" style="font-family:sans-serif;max-width:600px;margin-top:2rem">'
			. '<h1>CRM</h1>'
			. '<div style="background:#eff6ff;border:.5px solid #bfdbfe;border-radius:10px;padding:16px 20px;font-size:14px;color:#1e3a8a">'
			. esc_html__( 'CRM v2 está desactivado. Actívalo desde Ajustes → ATORA → CRM para acceder al módulo completo.', 'atora-lms' )
			. '<br><br><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=clms-settings-hub' ) ) . '">'
			. esc_html__( 'Ir a Ajustes', 'atora-lms' ) . '</a>'
			. '</div></div>';
	}

}
