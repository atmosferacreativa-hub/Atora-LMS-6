<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Dashboard_Assets_Trait {
	protected function enqueue_assets() {
		wp_register_style( 'clms-teacher-dashboard', false, array(), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
		wp_enqueue_style( 'clms-teacher-dashboard' );
		$base_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL
			: ATORA_LMS_URL;
		$assets_url  = defined( 'ATORA_LMS_ASSETS_URL' ) ? ATORA_LMS_ASSETS_URL : '';
		$assets_path = dirname( dirname( __DIR__ ) ) . '/assets/';
		wp_enqueue_script(
			'atora-ui',
			$base_url . 'assets/js/atora-ui.js',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
			true
		);
		$api_namespace = class_exists( 'CLMS_REST_API' ) ? CLMS_REST_API::API_NAMESPACE : 'clms/v1';
		wp_add_inline_script(
			'atora-ui',
			'window.ATORA = window.ATORA || {}; window.ATORA.rest = window.ATORA.rest || ' . wp_json_encode(
				array(
					'root'  => esc_url_raw( rest_url( $api_namespace ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'add_ui_i18n_script' ) ) {
			CLMS_Helper::add_ui_i18n_script( 'atora-ui' );
		}

		if ( $assets_url && file_exists( $assets_path . 'css/teacher-dashboard.css' ) ) {
			wp_enqueue_style(
				'clms-teacher-dashboard-ui',
				$assets_url . 'css/teacher-dashboard.css',
				array( 'clms-teacher-dashboard' ),
				defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0'
			);
		}

		if ( $assets_url && file_exists( $assets_path . 'js/teacher-dashboard.js' ) ) {
			wp_enqueue_script(
				'clms-teacher-dashboard-ui',
				$assets_url . 'js/teacher-dashboard.js',
				array( 'atora-ui' ),
				defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
				true
			);
		}

		if ( self::$assets_enqueued ) {
			return;
		}

		wp_add_inline_style( 'clms-teacher-dashboard', $this->get_inline_css() );

		self::$assets_enqueued = true;
	}

	protected function get_inline_css() {
		return '
		.clms-td,
		.clms-td *{box-sizing:border-box}
		.clms-td{
			--td-bg:#f8fafc;
			--td-card:#ffffff;
			--td-ink:#0f172a;
			--td-ink-2:#334155;
			--td-muted:#64748b;
			--td-border:#e2e8f0;
			--td-accent:#6366f1;
			--td-accent-2:#4f46e5;
			--td-accent-soft:#eef2ff;
			--td-success:#15803d;
			--td-success-bg:#dcfce7;
			--td-warning:#a16207;
			--td-warning-bg:#fef3c7;
			--td-info:#1d4ed8;
			--td-info-bg:#dbeafe;
			--td-shadow:0 10px 30px rgba(15,23,42,.06);
			color:var(--td-ink);
			font-family:inherit;
			display:grid;
			gap:24px;
			max-width:1280px;
			margin:0 auto;
			padding:0 0 24px;
		}
		.clms-td a{text-decoration:none}
		.clms-td img{max-width:100%;height:auto;display:block}
		.clms-td-layout{
			display:grid;
			grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);
			gap:24px;
			align-items:start;
		}
		.clms-td-main,
		.clms-td-side{
			min-width:0;
			display:grid;
			gap:24px;
		}
		.clms-td-card{
			min-width:0;
			background:var(--td-card);
			border:1px solid var(--td-border);
			border-radius:24px;
			box-shadow:var(--td-shadow);
			padding:24px;
			overflow:hidden;
		}
		.clms-td-card--accent{
			background:linear-gradient(135deg,#0f172a 0%, #172554 45%, #312e81 100%);
			color:#fff;
			border-color:transparent;
		}
		.clms-td-card__body{display:grid;gap:12px}
		.clms-td-card__actions{margin-top:18px}
		.clms-td-eyebrow{
			display:inline-flex;
			align-items:center;
			gap:8px;
			padding:7px 12px;
			border-radius:999px;
			background:#eef2ff;
			color:#4338ca;
			font-size:12px;
			font-weight:700;
			letter-spacing:.06em;
			text-transform:uppercase;
			width:max-content;
			max-width:100%;
		}
		.clms-td-eyebrow--light{
			background:rgba(255,255,255,.12);
			color:#e0e7ff;
		}
		.clms-td-pill{
			display:inline-flex;
			align-items:center;
			padding:8px 14px;
			border-radius:999px;
			background:rgba(255,255,255,.12);
			color:#e2e8f0;
			font-size:12px;
			font-weight:700;
			letter-spacing:.08em;
			text-transform:uppercase;
			width:max-content;
			max-width:100%;
		}
		.clms-td-title,
		.clms-td-hero__title,
		.clms-td-course__title,
		.clms-td-list-card__title,
		.clms-td-mini__title{
			min-width:0;
			overflow-wrap:anywhere;
			word-break:break-word;
		}
		.clms-td-title{
			font-size:28px;
			line-height:1.15;
			font-weight:800;
			letter-spacing:-.02em;
			margin:0;
			color:var(--td-ink);
		}
		.clms-td-title--light{color:#fff}
		.clms-td-hero{
			display:grid;
			grid-template-columns:minmax(0,1.1fr) minmax(340px,.9fr);
			gap:24px;
			padding:28px;
			border-radius:28px;
			background:
				radial-gradient(circle at top right, rgba(99,102,241,.28), transparent 35%),
				linear-gradient(135deg,#0f172a 0%, #172554 55%, #1e1b4b 100%);
			color:#fff;
			overflow:hidden;
		}
		.clms-td-hero__copy,
		.clms-td-hero__stats{
			min-width:0;
			display:grid;
			gap:18px;
			align-content:start;
		}
		.clms-td-hero__title{
			margin:0;
			font-size:clamp(32px,5vw,54px);
			line-height:1.02;
			font-weight:800;
			letter-spacing:-.04em;
			color:#fff;
		}
		.clms-td-hero__text,
		.clms-td-text{
			margin:0;
			font-size:15px;
			line-height:1.7;
			color:var(--td-ink-2);
			overflow-wrap:anywhere;
		}
		.clms-td-hero__text{
			max-width:62ch;
			color:rgba(255,255,255,.82);
			font-size:16px;
		}
		.clms-td-text--light{color:rgba(255,255,255,.82)}
		.clms-td-text--muted{color:var(--td-muted)}
		.clms-td-inline-meta{
			margin:0;
			font-size:13px;
			line-height:1.5;
			color:var(--td-muted);
			overflow-wrap:anywhere;
		}
		.clms-td-inline-meta--light{color:rgba(255,255,255,.76)}
		.clms-td-hero__actions,
		.clms-td-course__actions,
		.clms-td-list-card__foot{
			display:flex;
			flex-wrap:wrap;
			gap:12px;
		}
		.clms-td-list-card__foot--between{
			align-items:center;
			justify-content:space-between;
		}
		.clms-td-btn{
			display:inline-flex;
			align-items:center;
			justify-content:center;
			min-height:46px;
			padding:0 18px;
			border-radius:14px;
			font-size:14px;
			font-weight:700;
			line-height:1;
			border:1px solid transparent;
			transition:all .18s ease;
			white-space:nowrap;
			max-width:100%;
		}
		.clms-td-btn:hover{transform:translateY(-1px)}
		.clms-td-btn--primary{
			background:var(--td-accent);
			border-color:var(--td-accent);
			color:#fff;
			box-shadow:0 10px 24px rgba(99,102,241,.28);
		}
		.clms-td-btn--primary:hover{
			background:var(--td-accent-2);
			border-color:var(--td-accent-2);
			color:#fff;
		}
		.clms-td-btn--secondary{
			background:#fff;
			border-color:var(--td-border);
			color:var(--td-ink);
		}
		.clms-td-btn--secondary:hover{
			border-color:#cbd5e1;
			color:var(--td-ink);
		}
		.clms-td-btn--ghost{
			background:transparent;
			border-color:rgba(255,255,255,.22);
			color:#fff;
		}
		.clms-td-btn--ghost:hover{
			border-color:rgba(255,255,255,.42);
			color:#fff;
		}
		.clms-td-btn--white{
			background:#fff;
			border-color:#fff;
			color:#111827;
		}
		.clms-td-stat-grid{
			display:grid;
			grid-template-columns:repeat(2,minmax(0,1fr));
			gap:14px;
		}
		.clms-td-stat{
			min-width:0;
			display:grid;
			gap:8px;
			padding:18px;
			border-radius:20px;
			background:#fff;
			border:1px solid var(--td-border);
		}
		.clms-td-stat--hero{
			background:rgba(255,255,255,.08);
			border-color:rgba(255,255,255,.14);
			backdrop-filter:blur(4px);
		}
		.clms-td-stat__label{
			font-size:13px;
			line-height:1.4;
			color:var(--td-muted);
			overflow-wrap:anywhere;
		}
		.clms-td-stat--hero .clms-td-stat__label{color:rgba(255,255,255,.78)}
		.clms-td-stat__value{
			font-size:42px;
			line-height:1;
			font-weight:800;
			letter-spacing:-.04em;
			color:var(--td-ink);
		}
		.clms-td-stat--hero .clms-td-stat__value{color:#fff}
		.clms-td-stat__value--sm{font-size:28px}
		.clms-td-stat__hint{
			font-size:13px;
			line-height:1.5;
			color:rgba(255,255,255,.72);
		}
		.clms-td-section-head{
			display:flex;
			align-items:flex-start;
			justify-content:space-between;
			gap:16px;
			margin-bottom:18px;
		}
		.clms-td-quick-grid{
			display:grid;
			grid-template-columns:repeat(2,minmax(0,1fr));
			gap:16px;
		}
		.clms-td-quick{
			min-width:0;
			display:grid;
			gap:8px;
			padding:18px;
			border:1px solid var(--td-border);
			border-radius:20px;
			background:#fff;
			color:var(--td-ink);
			transition:all .18s ease;
		}
		.clms-td-quick:hover{
			transform:translateY(-2px);
			border-color:#c7d2fe;
			box-shadow:0 12px 30px rgba(79,70,229,.08);
		}
		.clms-td-quick__title{
			font-size:16px;
			font-weight:800;
			line-height:1.3;
			color:var(--td-ink);
		}
		.clms-td-quick__text{
			font-size:14px;
			line-height:1.65;
			color:var(--td-muted);
			overflow-wrap:anywhere;
		}
		.clms-td-list,
		.clms-td-mini-list{
			display:grid;
			gap:14px;
		}
		.clms-td-list-card,
		.clms-td-mini{
			min-width:0;
			border:1px solid var(--td-border);
			border-radius:18px;
			background:#fff;
			padding:16px;
		}
		.clms-td-list-card{
			display:grid;
			gap:12px;
		}
		.clms-td-list-card__head{
			display:flex;
			align-items:flex-start;
			justify-content:space-between;
			gap:14px;
		}
		.clms-td-list-card__main{
			min-width:0;
			flex:1 1 auto;
			display:grid;
			gap:6px;
		}
		.clms-td-list-card__aside{
			display:flex;
			align-items:center;
			flex-wrap:wrap;
			gap:8px;
			flex:0 0 auto;
			max-width:42%;
		}
		.clms-td-list-card__aside--stack{
			flex-direction:column;
			align-items:flex-end;
		}
		.clms-td-list-card__title,
		.clms-td-mini__title{
			margin:0;
			font-size:17px;
			line-height:1.35;
			font-weight:800;
			letter-spacing:-.02em;
			color:var(--td-ink);
		}
		.clms-td-list-card__meta,
		.clms-td-mini__meta,
		.clms-td-mini__date,
		.clms-td-course__meta{
			margin:0;
			font-size:13px;
			line-height:1.5;
			color:var(--td-muted);
			overflow-wrap:anywhere;
		}
		.clms-td-badge,
		.clms-td-grade{
			display:inline-flex;
			align-items:center;
			justify-content:center;
			max-width:100%;
			padding:7px 10px;
			border-radius:999px;
			font-size:12px;
			font-weight:800;
			line-height:1.2;
			text-align:center;
			overflow-wrap:anywhere;
		}
		.clms-td-badge{
			background:#e5e7eb;
			color:#111827;
		}
		.clms-td-badge.is-warning{
			background:var(--td-warning-bg);
			color:var(--td-warning);
		}
		.clms-td-badge.is-success{
			background:var(--td-success-bg);
			color:var(--td-success);
		}
		.clms-td-badge.is-info{
			background:var(--td-info-bg);
			color:var(--td-info);
		}
		.clms-td-grade{
			background:#0f172a;
			color:#fff;
		}
		.clms-td-mini{
			display:grid;
			grid-template-columns:minmax(0,1fr) auto;
			align-items:start;
			gap:14px;
		}
		.clms-td-mini__content{
			min-width:0;
			display:grid;
			gap:6px;
		}
		.clms-td-link{
			display:inline-flex;
			align-items:center;
			gap:6px;
			font-size:13px;
			font-weight:700;
			color:var(--td-accent-2);
			white-space:nowrap;
			align-self:start;
		}
		.clms-td-link:hover{color:#312e81}
		.clms-td-grid-2,
		.clms-td-courses{
			display:grid;
			grid-template-columns:repeat(2,minmax(0,1fr));
			gap:16px;
		}
		.clms-td-course{
			min-width:0;
			display:grid;
			grid-template-rows:1fr auto;
			border:1px solid var(--td-border);
			border-radius:20px;
			background:#fff;
			overflow:hidden;
		}
		.clms-td-course__body{
			min-width:0;
			display:grid;
			gap:12px;
			padding:18px 18px 16px;
		}
		.clms-td-course__title{
			margin:0;
			font-size:18px;
			line-height:1.35;
			font-weight:800;
			letter-spacing:-.02em;
			color:var(--td-ink);
			display:-webkit-box;
			-webkit-line-clamp:2;
			-webkit-box-orient:vertical;
			overflow:hidden;
		}
		.clms-td-course__actions{
			padding:16px 18px 18px;
			border-top:1px solid var(--td-border);
		}
		.clms-td-course__actions .clms-td-btn{flex:1 1 160px}
		.clms-td-card--soft{background:#f8fafc}
		.clms-td-kpi-grid{
			display:grid;
			grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
			gap:12px;
			margin-bottom:14px;
		}
		.clms-td-kpi{
			padding:12px;
			border:1px solid var(--td-border);
			border-radius:16px;
			background:#fff;
			display:grid;
			gap:6px;
		}
		.clms-td-kpi__label{
			font-size:11px;
			text-transform:uppercase;
			letter-spacing:.08em;
			font-weight:700;
			color:var(--td-muted);
		}
		.clms-td-kpi__value{
			font-size:22px;
			font-weight:800;
			color:var(--td-ink);
		}
		.clms-td-kpi__meta{
			font-size:12px;
			color:var(--td-muted);
		}
		.clms-td-progress{
			display:grid;
			gap:8px;
		}
		.clms-td-progress__bar{
			height:8px;
			background:var(--td-border);
			border-radius:999px;
			overflow:hidden;
		}
		.clms-td-progress__bar span{
			display:block;
			height:100%;
			background:var(--td-accent);
			border-radius:999px;
		}
		.clms-td-progress__meta{
			font-size:12px;
			color:var(--td-muted);
		}
		.clms-td-incident-grid{
			display:grid;
			grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
			gap:10px;
		}
		.clms-td-incident{
			padding:12px;
			border:1px solid var(--td-border);
			border-radius:14px;
			background:#fff;
			display:grid;
			gap:6px;
		}
		.clms-td-incident.is-warning{
			border-color:rgba(161,98,7,.2);
			background:var(--td-warning-bg);
		}
		.clms-td-incident.is-success{
			border-color:rgba(22,163,74,.2);
			background:var(--td-success-bg);
		}
		.clms-td-incident__label{
			font-size:11px;
			text-transform:uppercase;
			letter-spacing:.08em;
			font-weight:700;
			color:var(--td-muted);
		}
		.clms-td-incident__value{
			font-size:22px;
			font-weight:800;
			color:var(--td-ink);
		}
		.clms-td-incident__note{
			font-size:12px;
			color:var(--td-muted);
		}
		.clms-td-summary{
			display:grid;
			gap:12px;
		}
		.clms-td-summary__row{
			display:flex;
			align-items:center;
			justify-content:space-between;
			gap:12px;
			padding:12px 0;
			border-bottom:1px solid var(--td-border);
			font-size:14px;
			color:var(--td-ink-2);
		}
		.clms-td-summary__row:last-child{border-bottom:0}
			.clms-td-summary__row strong{
				font-size:16px;
				color:var(--td-ink);
			}
			.clms-td-details{
				display:grid;
				gap:10px;
				border:1px dashed var(--td-border);
				border-radius:16px;
				padding:12px;
				background:#fff;
			}
			.clms-td-details summary{
				cursor:pointer;
				font-weight:700;
				font-size:13px;
				color:var(--td-ink);
				list-style:none;
			}
			.clms-td-details summary::-webkit-details-marker{display:none}
			.clms-td-details[open]{border-style:solid}
		@media (max-width: 1120px){
			.clms-td-hero,
			.clms-td-layout,
			.clms-td-grid-2,
			.clms-td-courses{
				grid-template-columns:1fr;
			}
			.clms-td-quick-grid{
				grid-template-columns:1fr 1fr;
			}
		}
		@media (max-width: 782px){
			.clms-td{gap:18px}
			.clms-td-hero,
			.clms-td-card{
				padding:20px;
				border-radius:22px;
			}
			.clms-td-stat-grid{
				grid-template-columns:repeat(2,minmax(0,1fr));
			}
			.clms-td-list-card__head,
			.clms-td-list-card__foot,
			.clms-td-course__actions{
				flex-direction:column;
				align-items:flex-start;
			}
			.clms-td-list-card__aside,
			.clms-td-list-card__aside--stack{
				max-width:100%;
				align-items:flex-start;
			}
			.clms-td-hero__actions{
				flex-direction:column;
				align-items:stretch;
			}
			.clms-td-btn{
				width:100%;
			}
			.clms-td-quick-grid{
				grid-template-columns:1fr;
			}
		}
		@media (max-width: 640px){
			.clms-td-hero{
				padding:18px;
				border-radius:20px;
			}
			.clms-td-card{
				padding:18px;
				border-radius:18px;
			}
			.clms-td-hero__title{
				font-size:clamp(28px,11vw,42px);
			}
			.clms-td-title{
				font-size:24px;
			}
			.clms-td-stat-grid{
				grid-template-columns:1fr;
			}
			.clms-td-stat__value{
				font-size:34px;
			}
			.clms-td-stat__value--sm{
				font-size:26px;
			}
			.clms-td-mini{
				grid-template-columns:1fr;
			}
		}
		/* Urgencia */
		.clms-td-list-card--urgent{
			border-left:4px solid #ef4444;
		}
		.clms-td-badge.is-urgent{
			background:#fee2e2;
			color:#991b1b;
			font-size:11px;
		}
		/* Botón asistente IA */
		.clms-td-ai-open{
			gap:6px;
		}
		.clms-td-ai-actions{
			margin-top:16px;
			display:grid;
			grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
			gap:10px;
		}
		.clms-td-ai-actions .clms-td-btn{
			width:100%;
			justify-content:flex-start;
			text-align:left;
		}
		/* Bandeja docente */
		.clms-td-inbox-filters{
			display:grid;
			grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
			gap:12px;
			margin-bottom:18px;
		}
		.clms-td-field{
			display:grid;
			gap:6px;
			font-size:13px;
			color:var(--td-ink-2);
		}
		.clms-td-field label{
			font-weight:700;
			font-size:12px;
			color:var(--td-muted);
		}
		.clms-td-field select,
		.clms-td-field input{
			border:1px solid var(--td-border);
			border-radius:12px;
			padding:10px 12px;
			font-size:14px;
			background:#fff;
			color:var(--td-ink);
		}
		.clms-td-date-row{
			display:grid;
			grid-template-columns:1fr 1fr;
			gap:8px;
		}
		.clms-td-filter-actions{
			display:flex;
			align-items:center;
			gap:12px;
		}
		.clms-td-table-wrap{
			overflow:auto;
			border:1px solid var(--td-border);
			border-radius:18px;
		}
		.clms-td-table{
			width:100%;
			border-collapse:collapse;
			min-width:780px;
			background:#fff;
		}
		.clms-td-table th,
		.clms-td-table td{
			padding:14px 16px;
			text-align:left;
			border-bottom:1px solid var(--td-border);
			font-size:14px;
			vertical-align:top;
		}
		.clms-td-table th{
			font-size:12px;
			letter-spacing:.06em;
			text-transform:uppercase;
			color:var(--td-muted);
			background:#f8fafc;
		}
		.clms-td-table tr:hover{
			background:#f8fafc;
		}
		.clms-td-table tr[data-atora-row-link]{cursor:pointer}
		.clms-td-students-head{
			display:flex;
			align-items:center;
			justify-content:space-between;
			gap:12px;
			margin-bottom:12px;
			flex-wrap:wrap;
		}
		.clms-td-students-head label{
			font-size:12px;
			font-weight:700;
			color:var(--td-muted);
			text-transform:uppercase;
			letter-spacing:.06em;
		}
		.clms-td-select{
			border:1px solid var(--td-border);
			border-radius:12px;
			padding:10px 12px;
			font-size:14px;
			background:#fff;
			color:var(--td-ink);
			min-width:260px;
			max-width:100%;
		}
		.clms-td-students-toolbar{
			display:flex;
			align-items:center;
			flex-wrap:wrap;
			gap:8px;
			padding:12px;
			margin:8px 0 14px;
			background:var(--td-accent-soft);
			border:1px solid #c7d2fe;
			border-radius:14px;
		}
		.clms-td-students-toolbar[hidden]{
			display:none !important;
		}
		#clms-td-bulk-count{
			font-size:13px;
			font-weight:700;
			color:#3730a3;
			margin-right:6px;
		}
		.clms-td-bulk-danger{
			border-color:#fecaca !important;
			color:#991b1b !important;
			background:#fff7f7 !important;
		}
		.clms-td-students-table th:first-child,
		.clms-td-students-table td:first-child{
			width:42px;
			padding-left:12px;
			padding-right:6px;
		}
		.clms-td-students-table input[type="checkbox"]{
			width:16px;
			height:16px;
			margin:0;
		}
		.clms-td-row-followup{
			background:#fff7ed;
		}
		.clms-td-table-meta{
			display:block;
			font-size:12px;
			color:var(--td-muted);
			margin-top:4px;
		}
		.clms-td-pagination{
			display:flex;
			justify-content:space-between;
			align-items:center;
			gap:12px;
			margin-top:16px;
			flex-wrap:wrap;
		}
		.clms-td-pagination__actions{
			display:flex;
			gap:10px;
			flex-wrap:wrap;
		}
		.clms-td-row-urgent td:first-child{
			border-left:4px solid #ef4444;
		}
		.clms-td-notify-list{
			display:grid;
			gap:12px;
		}
		.clms-td-notify{
			border:1px solid var(--td-border);
			border-radius:16px;
			padding:12px 14px;
			display:grid;
			gap:6px;
		}
		@media (max-width: 782px){
			.clms-td-inbox-filters{
				grid-template-columns:1fr;
			}
			.clms-td-filter-actions{
				flex-direction:column;
				align-items:flex-start;
			}
			.clms-td-date-row{
				grid-template-columns:1fr;
			}
			.clms-td-select{
				min-width:0;
				width:100%;
			}
		}';
	}
}
