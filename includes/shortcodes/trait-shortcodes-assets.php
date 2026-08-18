<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Shortcodes_Assets_Trait {
	public function register_assets() {
		if ( ! wp_style_is( 'clms-shortcodes', 'registered' ) ) {
			wp_register_style(
				'clms-shortcodes',
				false,
				wp_style_is( 'clms-ui', 'registered' ) ? array( 'clms-ui' ) : array(),
				defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0'
			);
		}

		if ( ! wp_script_is( 'clms-shortcodes', 'registered' ) ) {
			wp_register_script(
				'clms-shortcodes',
				'',
				array(),
				defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
				true
			);
		}
	}

	/**
	 * Encola assets solo cuando el shortcode se renderiza.
	 *
	 * @return void
	 */
	protected function enqueue_assets() {
		if ( ! wp_style_is( 'clms-shortcodes', 'registered' ) || ! wp_script_is( 'clms-shortcodes', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'clms-shortcodes' );
		wp_enqueue_script( 'clms-shortcodes' );

		if ( self::$assets_enqueued ) {
			return;
		}

		wp_add_inline_style( 'clms-shortcodes', $this->get_inline_css() );
		wp_add_inline_script( 'clms-shortcodes', $this->get_inline_js() );

		self::$assets_enqueued = true;
	}

	/**
	 * CSS mínimo scoped al LMS.
	 *
	 * @return string
	 */
	protected function get_inline_css() {
		$css = '
		.clms-ui .clms-course-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;align-items:stretch}
		.clms-ui .clms-course-grid.clms-grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}
		.clms-ui .clms-course-grid.clms-grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}
		.clms-ui .clms-course-grid.clms-grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}
		.clms-ui .clms-course-grid.clms-grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}
		.clms-ui .clms-course-grid.clms-grid-cols-5{grid-template-columns:repeat(5,minmax(0,1fr))}
		.clms-ui .clms-course-grid.clms-grid-cols-6{grid-template-columns:repeat(6,minmax(0,1fr))}
		.clms-ui .clms-course-card,
		.clms-ui .clms-lesson-list-card,
		.clms-ui .clms-my-courses-card{background:var(--ac-white,#fff);border:1px solid var(--ac-gray-200,#e5e7eb);border-radius:14px;overflow:hidden}
		.clms-ui .clms-course-card{display:flex;flex-direction:column;min-height:100%;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
		.clms-ui .clms-course-card:hover{transform:translateY(-2px);box-shadow:0 18px 42px rgba(15,23,42,.08);border-color:var(--ac-gray-300,#d1d5db)}
		.clms-ui .clms-course-card-thumb,
		.clms-ui .clms-lesson-thumb a{display:block;text-decoration:none}
		.clms-ui .clms-course-card-thumb{aspect-ratio:16/10;overflow:hidden;background:var(--ac-gray-50,#f8fafc)}
		.clms-ui .clms-course-card-thumb img{display:block;width:100%;height:100%;object-fit:cover}
		.clms-ui .clms-lesson-thumb img{display:block;width:100%;height:auto}
		.clms-ui .clms-course-card-thumb-placeholder,
		.clms-ui .clms-lesson-thumb-placeholder{display:flex;align-items:center;justify-content:center;background:var(--ac-gray-50,#f8fafc);color:var(--ac-gray-600,#64748b)}
		.clms-ui .clms-course-card-thumb-placeholder{min-height:180px}
		.clms-ui .clms-lesson-thumb-placeholder{min-height:78px;border-radius:10px}
		.clms-ui .clms-course-card-body,
		.clms-ui .clms-my-courses-body,
		.clms-ui .clms-lesson-list-card{padding:18px}
		.clms-ui .clms-course-card-body{display:flex;flex:1;flex-direction:column}
		.clms-ui .clms-course-card-top{display:grid;gap:8px;margin-bottom:14px}
		.clms-ui .clms-course-card-title,
		.clms-ui .clms-lesson-list-title,
		.clms-ui .clms-my-courses-title{margin:0 0 10px;line-height:1.25}
		.clms-ui .clms-course-card-title a,
		.clms-ui .clms-lesson-main h4 a,
		.clms-ui .clms-my-course-item h4 a{text-decoration:none}
		.clms-ui .clms-course-card-title{margin:0}
		.clms-ui .clms-course-card-subtitle{margin:0;color:var(--ac-gray-700,#374151);font-size:14px;font-weight:600;line-height:1.45}
		.clms-ui .clms-course-card-excerpt{color:var(--ac-gray-600,#4b5563);line-height:1.6;margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
		.clms-ui .clms-course-card-meta,
		.clms-ui .clms-lesson-meta{font-size:14px;color:var(--ac-gray-600,#4b5563)}
		.clms-ui .clms-course-card-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:12px;margin:auto 0 14px}
		.clms-ui .clms-course-card-meta-label{display:block;font-size:12px;color:var(--ac-gray-500,#6b7280);margin-bottom:2px}
		.clms-ui .clms-course-card-level{display:inline-block;margin-bottom:10px;padding:4px 8px;border-radius:999px;background:var(--ac-brand-lt,#eef2ff);color:var(--ac-accent-dk,#3730a3);font-size:12px;font-weight:600}
		.clms-ui .clms-course-card-status{position:absolute;top:12px;left:12px;display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:600;background:var(--ac-gray-900,#111827);color:var(--ac-white,#fff)}
		.clms-ui .clms-course-card-thumb{position:relative}
		.clms-ui .clms-course-card-status.is-complete{background:#065f46}
		.clms-ui .clms-course-card-status.is-enrolled{background:#1d4ed8}
		.clms-ui .clms-course-card-status.is-open{background:#111827}
		.clms-ui .clms-course-card-status.is-login{background:#7c3aed}
		.clms-ui .clms-course-card-progress{margin:0 0 14px}
		.clms-ui .clms-course-card-progress-head{display:flex;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:14px}
		.clms-ui .clms-course-card-progress-bar{height:8px;background:var(--ac-gray-200,#e5e7eb);border-radius:999px;overflow:hidden}
		.clms-ui .clms-course-card-progress-bar span{display:block;height:100%;background:var(--ac-gray-900,#111827)}
		.clms-ui .clms-course-actions,
		.clms-ui .clms-course-card-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:auto}
		.clms-ui .clms-course-link,
		.clms-ui .clms-course-enroll-btn,
		.clms-ui .clms-course-continue-btn,
		.clms-ui .clms-course-login-btn,
		.clms-ui .clms-course-card-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;text-decoration:none;border:1px solid var(--ac-gray-900,#111827);background:var(--ac-gray-900,#111827);color:var(--ac-white,#fff);cursor:pointer;line-height:1.2}
		.clms-ui .clms-course-card-btn-ghost,
		.clms-ui .clms-course-link{background:var(--ac-white,#fff);color:var(--ac-gray-900,#111827)}
		.clms-ui .clms-course-message{margin-top:10px;font-size:14px;line-height:1.45}
		.clms-ui .clms-course-message.is-success{color:#065f46}
		.clms-ui .clms-course-message.is-error{color:#991b1b}
		.clms-ui .clms-catalog-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 18px}
		.clms-ui .clms-catalog-field{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--ac-gray-600,#4b5563)}
		.clms-ui .clms-catalog-field select{min-width:180px;padding:8px 10px;border-radius:10px;border:1px solid var(--ac-gray-200,#e5e7eb);background:var(--ac-white,#fff);color:var(--ac-gray-900,#111827)}
		.clms-ui .clms-catalog-submit{padding:10px 14px;border-radius:10px;border:1px solid var(--ac-gray-900,#111827);background:var(--ac-gray-900,#111827);color:var(--ac-white,#fff);cursor:pointer}
		.clms-ui .clms-catalog-submit:hover{filter:brightness(.96)}
		.clms-ui .clms-catalog-empty{margin:0;padding:12px 0;color:var(--ac-gray-600,#4b5563)}
		.clms-ui .clms-catalog-pagination{margin-top:18px;display:flex;justify-content:center;flex-wrap:wrap;gap:6px}
		.clms-ui .clms-catalog-pagination .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;border-radius:8px;border:1px solid var(--ac-gray-200,#e5e7eb);padding:0 10px;text-decoration:none;color:var(--ac-gray-700,#374151)}
		.clms-ui .clms-catalog-pagination .page-numbers.current{background:var(--ac-gray-900,#111827);border-color:var(--ac-gray-900,#111827);color:var(--ac-white,#fff)}
		.clms-ui .clms-catalog-recommendations{margin-top:26px}
		.clms-ui .clms-catalog-recommendations-title{margin:0 0 12px;font-size:18px;font-weight:700;color:var(--ac-gray-900,#111827)}
		.clms-ui .clms-lesson-list{list-style:none;margin:0;padding:0}
		.clms-ui .clms-lesson-item{display:grid;grid-template-columns:110px minmax(0,1fr);gap:14px;padding:14px 0;border-bottom:1px solid var(--ac-gray-200,#e5e7eb)}
		.clms-ui .clms-lesson-item:last-child,
		.clms-ui .clms-my-course-item:last-child{border-bottom:0}
		.clms-ui .clms-lesson-main h4{margin:0 0 8px}
		.clms-ui .clms-pill{display:inline-block;padding:4px 8px;border-radius:999px;background:var(--ac-brand-lt,#eef2ff);color:var(--ac-accent-dk,#3730a3);font-size:12px;font-weight:600}
		.clms-ui .clms-my-course-item{padding:14px 0;border-bottom:1px solid var(--ac-gray-200,#e5e7eb)}
		@media (max-width:960px){
			.clms-ui .clms-course-grid.clms-grid-cols-4,
			.clms-ui .clms-course-grid.clms-grid-cols-5,
			.clms-ui .clms-course-grid.clms-grid-cols-6{grid-template-columns:repeat(3,minmax(0,1fr))}
		}
		@media (max-width:720px){
			.clms-ui .clms-course-grid.clms-grid-cols-3,
			.clms-ui .clms-course-grid.clms-grid-cols-4,
			.clms-ui .clms-course-grid.clms-grid-cols-5,
			.clms-ui .clms-course-grid.clms-grid-cols-6{grid-template-columns:repeat(2,minmax(0,1fr))}
		}
		@media (max-width:520px){
			.clms-ui .clms-course-grid.clms-grid-cols-2,
			.clms-ui .clms-course-grid.clms-grid-cols-3,
			.clms-ui .clms-course-grid.clms-grid-cols-4,
			.clms-ui .clms-course-grid.clms-grid-cols-5,
			.clms-ui .clms-course-grid.clms-grid-cols-6{grid-template-columns:repeat(1,minmax(0,1fr))}
		}
		@media (max-width:640px){
			.clms-ui .clms-catalog-field,
			.clms-ui .clms-catalog-field select,
			.clms-ui .clms-catalog-submit{width:100%}
			.clms-ui .clms-lesson-item{grid-template-columns:1fr}
		}';
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$css = (string) CLMS_Helper::modular_apply( 'shortcodes_inline_css', $css );
		}

		return $css;
	}

	/**
	 * JS ligero para inscripción por AJAX.
	 *
	 * @return string
	 */
	protected function get_inline_js() {
		$js = "
		document.addEventListener('click', function(e){
			var btn = e.target.closest('.clms-js-enroll');
			if(!btn){ return; }

			e.preventDefault();

			var courseId = btn.getAttribute('data-course-id');
			var nonce = btn.getAttribute('data-nonce');
			var redirect = btn.getAttribute('data-redirect') || window.location.href;
			var box = btn.closest('.clms-course-actions');
			var msg = null;

			if (box) {
				var cardBody = box.parentNode;
				if (cardBody) {
					msg = cardBody.querySelector('.clms-course-message');
				}
			}

			var formData = new FormData();
			formData.append('action', 'clms_enroll_course');
			formData.append('course_id', courseId);
			formData.append('nonce', nonce);
			formData.append('redirect', redirect);

			btn.disabled = true;

			fetch('" . esc_js( admin_url( 'admin-ajax.php' ) ) . "', {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
			.then(function(res){ return res.json(); })
			.then(function(json){
				if (json && json.success) {
					if (msg) {
						msg.className = 'clms-course-message is-success';
						msg.textContent = json.data && json.data.message ? json.data.message : 'Inscripción completada.';
					}
					if (json.data && json.data.button_html && box) {
						box.innerHTML = json.data.button_html;
					}
					return;
				}

				if (msg) {
					msg.className = 'clms-course-message is-error';
					msg.textContent = json && json.data && json.data.message ? json.data.message : 'No se pudo completar la inscripción.';
				}
				btn.disabled = false;
			})
			.catch(function(){
				if (msg) {
					msg.className = 'clms-course-message is-error';
					msg.textContent = 'Error de conexión.';
				}
				btn.disabled = false;
			});
		});
		";
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$js = (string) CLMS_Helper::modular_apply( 'shortcodes_inline_js', $js );
		}

		return $js;
	}

	/**
	 * Lista de cursos.
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
}
