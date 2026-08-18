<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Lesson_Registration_Trait {
	public function register_metaboxes() {
		$boxes = array(
				array(
					'id'       => 'clms_lesson_relation',
					'title'    => __( 'Relación y calendario', 'atora-lms' ),
					'callback' => 'render_relation',
					'context'  => 'normal',
					'priority' => 'high',
				),
			array(
				'id'       => 'clms_lesson_visual',
				'title'    => __( 'Portada y video', 'atora-lms' ),
				'callback' => 'render_visual',
				'context'  => 'normal',
				'priority' => 'high',
			),
			array(
				'id'       => 'clms_lesson_resources',
				'title'    => __( 'Material de apoyo', 'atora-lms' ),
				'callback' => 'render_resources',
				'context'  => 'normal',
				'priority' => 'default',
			),
			array(
				'id'       => 'clms_lesson_evaluation',
				'title'    => __( 'Evaluaciones y banco AI', 'atora-lms' ),
				'callback' => 'render_evaluation',
				'context'  => 'normal',
				'priority' => 'default',
			),
		);

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$boxes = (array) CLMS_Helper::modular_apply( 'lesson_metabox_definitions', $boxes );
		}

		foreach ( $boxes as $box ) {
			$box = is_array( $box ) ? $box : array();
			$id  = isset( $box['id'] ) ? sanitize_key( (string) $box['id'] ) : '';
			$cb  = isset( $box['callback'] ) ? sanitize_key( (string) $box['callback'] ) : '';
			if ( '' === $id || '' === $cb || ! method_exists( $this, $cb ) ) {
				continue;
			}

			add_meta_box(
				$id,
				isset( $box['title'] ) ? (string) $box['title'] : '',
				array( $this, $cb ),
				'lm_lesson',
				isset( $box['context'] ) ? sanitize_key( (string) $box['context'] ) : 'normal',
				isset( $box['priority'] ) ? sanitize_key( (string) $box['priority'] ) : 'default'
			);
		}
	}

	// ── Helpers internos ────────────────────────────────────────────────────────

	protected function check_post( $post ) {
		if ( ! $post || 'lm_lesson' !== $post->post_type ) {
			return false;
		}
		if ( ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Imprime nonce, CSS y JS compartidos una sola vez,
	 * independientemente del orden de renderizado de los metaboxes.
	 */
	protected function shared_assets( $post ) {
		if ( self::$assets_done ) {
			return;
		}
		self::$assets_done = true;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		wp_enqueue_media();
		wp_enqueue_editor();
		?>
		<style>
		#clms_lesson_visual.postbox,
		#clms_lesson_resources.postbox,
		#clms_lesson_evaluation.postbox{
			border:1px solid var(--clms-border,#dcdcde);
			border-radius:12px;
			overflow:hidden;
			box-shadow:var(--clms-shadow-xs,0 1px 3px rgba(0,0,0,.06));
		}
		#clms_lesson_visual .postbox-header,
		#clms_lesson_resources .postbox-header,
		#clms_lesson_evaluation .postbox-header{
			background:var(--clms-bg-raised,#f0f0f1);
			border-bottom:1px solid var(--clms-border,#dcdcde);
		}
		#clms_lesson_visual .inside,
		#clms_lesson_resources .inside,
		#clms_lesson_evaluation .inside{
			margin:0;
			padding:16px;
			background:linear-gradient(180deg,var(--clms-bg,#fff),var(--clms-bg-soft,#f9fafb));
		}
		/* Premium evaluation metabox visual identity (blue) */
		#clms_lesson_evaluation.postbox{
			position:relative;
			border:2px solid #2563eb;
			box-shadow:0 0 0 2px rgba(37,99,235,.18),0 14px 30px rgba(30,64,175,.20);
		}
		#clms_lesson_evaluation.postbox:before{
			content:'';
			position:absolute;
			inset:0;
			border-radius:12px;
			pointer-events:none;
			box-shadow:inset 0 1px 0 rgba(255,255,255,.55);
		}
		#clms_lesson_evaluation .postbox-header{
			position:relative;
			background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 55%,#1e40af 100%);
			border-bottom-color:#1d4ed8;
		}
		#clms_lesson_evaluation .postbox-header h2{
			color:#ffffff;
			display:flex;
			align-items:center;
			gap:8px;
			font-weight:800;
			letter-spacing:.02em;
			text-shadow:0 1px 1px rgba(0,0,0,.18);
		}
		#clms_lesson_evaluation .postbox-header h2:before{
			content:'';
			width:18px;
			height:18px;
			border-radius:999px;
			background:radial-gradient(circle at 34% 34%,#ffffff 0 33%,#bfdbfe 34% 100%);
			box-shadow:0 0 0 2px rgba(255,255,255,.28);
			flex:0 0 auto;
		}
		#clms_lesson_evaluation .postbox-header h2:after{
			content:'PRO';
			display:inline-flex;
			align-items:center;
			padding:2px 8px;
			border-radius:999px;
			font-size:10px;
			font-weight:800;
			letter-spacing:.08em;
			background:#dbeafe;
			color:#1e3a8a;
			margin-left:4px;
		}
		#clms_lesson_evaluation .postbox-header .handlediv .toggle-indicator:before{
			color:#dbeafe;
		}
		#clms_lesson_evaluation .inside{
			background:linear-gradient(180deg,#ffffff 0%,#f3f8ff 58%,#ecf4ff 100%);
		}
		#clms_lesson_evaluation .clms-section-title{
			color:#1d4ed8;
			border-top-color:#bfdbfe;
		}
		#clms_lesson_evaluation .clms-ai-status,
		#clms_lesson_evaluation .clms-video-card,
		#clms_lesson_evaluation .clms-resource-row{
			border-color:#bfdbfe;
		}
		#clms_lesson_evaluation .button.button-primary{
			background:#1d4ed8;
			border-color:#1d4ed8;
		}
		#clms_lesson_evaluation .button.button-primary:hover{
			background:#1e40af;
			border-color:#1e40af;
		}
		#clms_lesson_evaluation.clms-eval-is-quiz{
			border-color:#1d4ed8;
			box-shadow:0 0 0 3px rgba(59,130,246,.30),0 20px 44px rgba(30,64,175,.28);
			animation:clmsEvalPulse 1.6s ease-out 1;
		}
		@keyframes clmsEvalPulse{
			0%{
				box-shadow:0 0 0 0 rgba(59,130,246,.45),0 20px 44px rgba(30,64,175,.22);
			}
			100%{
				box-shadow:0 0 0 3px rgba(59,130,246,.30),0 20px 44px rgba(30,64,175,.28);
			}
		}
		/* Lesson metabox UI aligned with plugin design tokens (admin.css). */
		.clms-f{margin:0 0 14px}
		.clms-f > label{display:block;font-weight:700;font-size:12px;line-height:1.35;margin:0 0 6px;color:var(--clms-ink,#1d2327)}
		.clms-f input[type="text"],
		.clms-f input[type="number"],
		.clms-f input[type="date"],
		.clms-f input[type="time"],
		.clms-f input[type="url"],
		.clms-f select,
		.clms-f textarea{
			width:100%;
			box-sizing:border-box;
			border:1px solid var(--clms-border,#dcdcde);
			background:var(--clms-bg,#fff);
			color:var(--clms-ink,#1d2327);
			border-radius:8px;
			padding:8px 10px;
			min-height:38px;
			transition:border-color .15s,box-shadow .15s,background .15s;
		}
		.clms-f textarea{min-height:110px;line-height:1.45;resize:vertical}
		.clms-f input[type="text"]:focus,
		.clms-f input[type="number"]:focus,
		.clms-f input[type="date"]:focus,
		.clms-f input[type="time"]:focus,
		.clms-f input[type="url"]:focus,
		.clms-f select:focus,
		.clms-f textarea:focus{
			border-color:var(--clms-accent,#4f46e5);
			box-shadow:0 0 0 1px var(--clms-accent-lt,rgba(79,70,229,.18));
			outline:none;
		}
		.clms-f input[readonly]{background:var(--clms-bg-soft,#f9fafb);color:var(--clms-muted,#6b7280)}
		.clms-help{font-size:11px;color:var(--clms-muted-2,#646970);margin:6px 0 0;display:block}
		.clms-help-highlight{
			background:#eff6ff;
			border:1px solid #bfdbfe;
			color:#1e3a8a;
			padding:10px 12px;
			border-radius:10px;
		}
		.clms-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
		.clms-grid-2 .full{grid-column:1/-1}
		.clms-eval-banner{
			display:grid;
			gap:6px;
			padding:12px 14px;
			border-radius:12px;
			border:1px solid #bfdbfe;
			background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);
			color:#1e3a8a;
			margin-bottom:12px;
		}
		.clms-eval-banner strong{font-size:12px;line-height:1.4}
		.clms-eval-banner span{font-size:11px;line-height:1.45}
		/* Módulos de la metabox "Evaluaciones y banco AI": separación por color
		   entre la asignación/tarea (ámbar) y el motor de quiz/IA (azul). */
		.clms-eval-module{
			border-radius:12px;
			padding:14px 16px;
			margin:0 0 18px;
			border:1px solid transparent;
		}
		.clms-eval-module .clms-section-title:first-child{margin-top:0;padding-top:0;border-top:none}
		.clms-eval-task-section{
			background:linear-gradient(135deg,#fff7ed 0%,#ffedd5 100%);
			border-color:#fed7aa;
		}
		#clms_lesson_evaluation .clms-eval-task-section .clms-section-title{color:#b45309;border-top-color:#fed7aa}
		.clms-eval-quiz-section{
			background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 65%,#e0e7ff 100%);
			border-color:#bfdbfe;
		}
		.clms-eval-quiz-section .clms-eval-banner{
			background:rgba(255,255,255,.6);
			border-color:#bfdbfe;
		}
		.clms-is-hidden{display:none!important}
		.clms-actions{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 0}
		.clms-actions .button{border-color:var(--clms-border,#dcdcde)}
		.clms-actions .button:hover{border-color:var(--clms-accent,#4f46e5);color:var(--clms-accent,#4f46e5)}
		.clms-section-title{
			font-size:11px;
			font-weight:800;
			margin:18px 0 12px;
			padding-top:14px;
			border-top:1px solid var(--clms-border-soft,#e5e7eb);
			color:var(--clms-muted,#6b7280);
			letter-spacing:.05em;
			text-transform:uppercase;
		}
		.clms-section-title:first-child{margin-top:0;padding-top:0;border-top:none}
		.clms-dropzone{
			display:grid;
			gap:4px;
			place-items:center;
			border:1px dashed var(--clms-border,#d1d5db);
			border-radius:10px;
			padding:14px 12px;
			text-align:center;
			background:linear-gradient(180deg,var(--clms-bg-soft,#f9fafb),var(--clms-bg,#fff));
			cursor:pointer;
			transition:border-color .15s,background .15s,transform .15s;
		}
		.clms-dropzone:hover{
			border-color:var(--clms-accent,#4f46e5);
			background:var(--clms-accent-lt,#eef2ff);
			transform:translateY(-1px);
		}
		.clms-dropzone.is-dragover{border-color:var(--clms-accent,#4f46e5);background:var(--clms-accent-lt,#eef2ff)}
		.clms-dropzone-title{font-weight:700;color:var(--clms-ink,#1d2327);font-size:12px}
		.clms-dropzone-sub{font-size:11px;color:var(--clms-muted,#6b7280)}
		.clms-cover-preview{margin-top:10px}
		.clms-cover-preview img{
			display:block;
			max-width:180px;
			border-radius:10px;
			border:1px solid var(--clms-border,#dcdcde);
			box-shadow:var(--clms-shadow-xs,0 1px 3px rgba(0,0,0,.06));
		}
		.clms-video-ratio{position:relative;padding-top:56.25%;background:#000;border-radius:8px;overflow:hidden;margin-top:12px}
		.clms-video-ratio iframe,.clms-video-ratio video{position:absolute;inset:0;width:100%;height:100%;border:none}
		.clms-resources-wrap{display:grid;gap:12px}
		.clms-resource-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
		.clms-resource-grid .full{grid-column:1/-1}
		.clms-resource-preview{display:flex;gap:10px;align-items:flex-start;margin-top:8px}
		.clms-resource-row,.clms-video-card{
			background:var(--clms-bg,#fff);
			border:1px solid var(--clms-border,#dcdcde);
			border-radius:10px;
			box-shadow:var(--clms-shadow-xs,0 1px 3px rgba(0,0,0,.06));
		}
		.clms-video-card{margin-top:12px;overflow:hidden}
		.clms-video-card summary{
			padding:11px 14px;
			cursor:pointer;
			list-style:none;
			background:var(--clms-bg-raised,#f0f0f1);
			font-weight:700;
			color:var(--clms-ink,#1d2327);
		}
		.clms-video-card summary::-webkit-details-marker{display:none}
		.clms-video-card[open] summary{border-bottom:1px solid var(--clms-border,#dcdcde)}
		.clms-video-card-body{padding:14px;background:var(--clms-bg-soft,#f9fafb)}
		.clms-video-thumb-wrap{display:flex;gap:10px;align-items:flex-start}
		.clms-video-thumb-wrap img{
			width:140px;
			height:79px;
			object-fit:cover;
			border-radius:6px;
			border:1px solid var(--clms-border,#dcdcde);
			background:#000;
			flex-shrink:0;
		}
		.clms-card-preview{
			max-height:260px;
			overflow:hidden;
			border-radius:8px;
			margin-top:10px;
			border:1px solid var(--clms-border,#dcdcde);
			background:var(--clms-bg,#fff);
		}
		.clms-card-preview iframe,.clms-card-preview video{display:block;width:100%;height:220px;border:none}
		/* TinyMCE/Quicktags shell inside "Descripción del video". */
		.clms-video-card .wp-editor-wrap{
			border:1px solid var(--clms-border,#dcdcde);
			border-radius:8px;
			overflow:hidden;
			background:var(--clms-bg,#fff);
		}
		.clms-video-card .wp-editor-wrap .wp-editor-tools{
			padding:0 8px;
			background:var(--clms-bg-raised,#f0f0f1);
			border-bottom:1px solid var(--clms-border,#dcdcde);
		}
		.clms-video-card .wp-editor-wrap .quicktags-toolbar{background:var(--clms-bg-raised,#f0f0f1)}
		.clms-video-card .wp-editor-wrap .wp-editor-container{background:var(--clms-bg,#fff)}
		.clms-video-card .wp-editor-wrap iframe{background:var(--clms-bg,#fff)}
		@media(max-width:782px){
			#clms_lesson_visual .inside,
			#clms_lesson_resources .inside,
			#clms_lesson_evaluation .inside{padding:12px}
			.clms-grid-2,.clms-resource-grid{grid-template-columns:1fr}
		}
		</style>
		<script>
		var clmsI18n = <?php echo wp_json_encode( array(
			'mediaSelect'         => __( 'Seleccionar', 'atora-lms' ),
			'mediaUse'            => __( 'Usar', 'atora-lms' ),
			'mediaSelectThumb'    => __( 'Seleccionar miniatura', 'atora-lms' ),
			'mediaUseImage'       => __( 'Usar imagen', 'atora-lms' ),
			'mediaVideoThumb'     => __( 'Miniatura del video', 'atora-lms' ),
			'mediaAiGuide'        => __( 'Archivo guía AI', 'atora-lms' ),
			'mediaUseFile'        => __( 'Usar archivo', 'atora-lms' ),
			'mediaResourceFile'   => __( 'Archivo del recurso', 'atora-lms' ),
			'mediaThumbnail'      => __( 'Miniatura', 'atora-lms' ),
			'labelTitle'          => __( 'Título', 'atora-lms' ),
			'labelType'           => __( 'Tipo', 'atora-lms' ),
			'labelDescription'    => __( 'Descripción', 'atora-lms' ),
			'labelFile'           => __( 'Archivo', 'atora-lms' ),
			'labelExternalUrl'    => __( 'URL externa', 'atora-lms' ),
			'labelThumbnail'      => __( 'Miniatura', 'atora-lms' ),
			'labelSource'         => __( 'Fuente', 'atora-lms' ),
			'labelUrl'            => __( 'URL', 'atora-lms' ),
			'labelVideoDescription' => __( 'Descripción del video', 'atora-lms' ),
			'labelTip1'           => __( 'Tip 1', 'atora-lms' ),
			'labelTip2'           => __( 'Tip 2', 'atora-lms' ),
			'labelTip3'           => __( 'Tip 3', 'atora-lms' ),
			'labelVideoThumb'     => __( 'Miniatura del video', 'atora-lms' ),
			'labelVideo'          => __( 'Video %s', 'atora-lms' ),
			'helpDropTitle'       => __( 'Arrastra el archivo aquí', 'atora-lms' ),
			'helpDropSub'         => __( 'o haz clic para seleccionar en la biblioteca', 'atora-lms' ),
			'buttonSelect'        => __( 'Seleccionar', 'atora-lms' ),
			'buttonSelectFile'    => __( 'Seleccionar archivo', 'atora-lms' ),
			'buttonSelectImage'   => __( 'Seleccionar imagen', 'atora-lms' ),
			'buttonSelectThumb'   => __( 'Seleccionar miniatura', 'atora-lms' ),
			'buttonRemove'        => __( 'Quitar', 'atora-lms' ),
			'buttonRemoveResource' => __( 'Eliminar recurso', 'atora-lms' ),
			'helpVideoThumb'      => __( 'YouTube: se detecta automáticamente. Para otras fuentes puedes subirla manualmente.', 'atora-lms' ),
			'helpVideoHtml'       => __( 'Acepta HTML: &lt;strong&gt;, &lt;em&gt;, &lt;a href=&quot;...&quot;&gt;, &lt;ul&gt;&lt;li&gt;, &lt;p&gt;.', 'atora-lms' ),
			'typePdf'             => __( 'PDF', 'atora-lms' ),
			'typeGuide'           => __( 'Guía', 'atora-lms' ),
			'typePresentation'    => __( 'Presentación', 'atora-lms' ),
			'typeAudio'           => __( 'Audio', 'atora-lms' ),
			'typeVideo'           => __( 'Video', 'atora-lms' ),
			'typeLink'            => __( 'Enlace', 'atora-lms' ),
			'typeFile'            => __( 'Archivo', 'atora-lms' ),
			'sourceYoutube'       => __( 'YouTube', 'atora-lms' ),
			'sourceVimeo'         => __( 'Vimeo', 'atora-lms' ),
			'sourceBunny'         => __( 'Bunny.net', 'atora-lms' ),
			'sourceDrive'         => __( 'Google Drive', 'atora-lms' ),
			'sourceUrl'           => __( 'URL directa (MP4)', 'atora-lms' ),
			'trStatus'            => array(
				'not_requested' => __( '—', 'atora-lms' ),
				'pending'       => __( 'En cola…', 'atora-lms' ),
				'processing'    => __( 'Procesando…', 'atora-lms' ),
				'completed'     => __( 'Completada', 'atora-lms' ),
				'failed'        => __( 'Fallida', 'atora-lms' ),
			),
			'trReady'            => __( '✓ Transcripción lista · %s palabras.', 'atora-lms' ),
			'trError'            => __( 'Error: %s', 'atora-lms' ),
			'trFailed'           => __( 'Transcripción fallida.', 'atora-lms' ),
			'trStarting'         => __( 'Iniciando…', 'atora-lms' ),
			'trQueued'           => __( 'Transcripción en cola. Procesando…', 'atora-lms' ),
			'trConnectionError'  => __( 'Error de conexión.', 'atora-lms' ),
			'trStartError'       => __( 'Error al iniciar.', 'atora-lms' ),
			'bankGenerating'     => __( 'Generando… puede tardar unos segundos.', 'atora-lms' ),
			'bankGenerated'      => __( 'Banco generado.', 'atora-lms' ),
			'bankError'          => __( 'Error al generar.', 'atora-lms' ),
			'bankNetworkError'   => __( 'Error de red. Intenta de nuevo.', 'atora-lms' ),
			'evalHintManual'     => __( 'Manual: el docente revisa y publica la nota final.', 'atora-lms' ),
			'evalHintAiAssisted' => __( 'IA asistida: ATORA sugiere evaluación y el docente valida.', 'atora-lms' ),
			'evalHintAiAuto'     => __( 'IA automática: la nota se publica si supera el umbral de confianza.', 'atora-lms' ),
			'evalHintPeer'       => __( 'Revisión entre pares: activa asignación y agregación de pares.', 'atora-lms' ),
			'evalHintHybrid'     => __( 'Híbrido: IA + revisión docente para cerrar la calificación.', 'atora-lms' ),
				'evalHintQuizOff'    => __( 'El quiz está desactivado. El estudiante no verá esta evaluación.', 'atora-lms' ),
				'evalHintNotQuiz'    => __( 'La lección no está en tipo "Evaluación". Cambia el tipo para ver opciones de quiz.', 'atora-lms' ),
				'evalHintNeedsSync'  => __( 'Hay banco de preguntas o quiz activo, pero el tipo no está en "Evaluación". Guarda la lección para sincronizar.', 'atora-lms' ),
		) ); ?>;

			document.addEventListener('DOMContentLoaded', function(){

				function formatText(text, value){
					if(!text){return '';}
					return text.replace('%s', value);
				}

				function getEditorApi(){
					if(typeof wp === 'undefined' || !wp){return null;}
					if(wp.oldEditor && typeof wp.oldEditor.initialize === 'function'){return wp.oldEditor;}
					if(wp.editor && typeof wp.editor.initialize === 'function'){return wp.editor;}
					return null;
				}

				function ensureVisualMode(editorId){
					if(!editorId){return;}
					if(typeof switchEditors !== 'undefined' && switchEditors && typeof switchEditors.go === 'function'){
						try { switchEditors.go(editorId, 'tmce'); } catch (e) {}
					}
					if(typeof tinymce === 'undefined' || !tinymce || typeof tinymce.get !== 'function'){
						return;
					}
					var ed = tinymce.get(editorId);
					if(!ed){return;}
					try {
						if(ed.mode && typeof ed.mode.set === 'function'){
							ed.mode.set('design');
						} else if(typeof ed.setMode === 'function'){
							ed.setMode('design');
						}
						if(typeof ed.getBody === 'function' && ed.getBody()){
							ed.getBody().setAttribute('contenteditable', 'true');
						}
					} catch (e) {}
				}

				function initVideoDescEditor(textarea){
					if(!textarea){return false;}
					if(!textarea.id){
						textarea.id = 'clms_video_desc_dyn_' + Math.random().toString(36).slice(2, 10);
					}
					if(textarea.dataset.clmsEditorInit === '1' || document.getElementById('wp-' + textarea.id + '-wrap')){
						textarea.dataset.clmsEditorInit = '1';
						window.setTimeout(function(){ ensureVisualMode(textarea.id); }, 80);
						window.setTimeout(function(){ ensureVisualMode(textarea.id); }, 260);
						return true;
					}

					var editorApi = getEditorApi();
					if(!editorApi){return false;}

					try {
						editorApi.initialize(textarea.id, {
							tinymce: {
								wpautop: true,
								toolbar1: 'bold,italic,underline,link,unlink,bullist,numlist,undo,redo',
								toolbar2: '',
								menubar: false
							},
							quicktags: {
								buttons: 'strong,em,link,ul,li,close'
							},
							mediaButtons: false
						});
						textarea.dataset.clmsEditorInit = '1';
						window.setTimeout(function(){ ensureVisualMode(textarea.id); }, 80);
						window.setTimeout(function(){ ensureVisualMode(textarea.id); }, 260);
						return true;
					} catch (e) {
						textarea.dataset.clmsEditorInit = '0';
						return false;
					}
				}

				function removeVideoDescEditor(textarea){
					if(!textarea || !textarea.id){return;}
					var editorApi = getEditorApi();
					if(editorApi && typeof editorApi.remove === 'function'){
						try {
							editorApi.remove(textarea.id);
						} catch (e) {}
					}
					textarea.dataset.clmsEditorInit = '0';
				}

				function initVideoEditorsIn(scope){
					var nodes = (scope || document).querySelectorAll('textarea.clms-video-desc-editor');
					nodes.forEach(function(textarea){
						initVideoDescEditor(textarea);
					});
					return nodes.length;
				}

				function initVideoEditorsWithRetry(scope, triesLeft){
					var tries = (typeof triesLeft === 'number') ? triesLeft : 12;
					var count = initVideoEditorsIn(scope);
					if(count > 0 && getEditorApi()){
						(scope || document).querySelectorAll('textarea.clms-video-desc-editor').forEach(function(textarea){
							if(textarea && textarea.id){
								window.setTimeout(function(){ ensureVisualMode(textarea.id); }, 160);
							}
						});
						return;
					}
					if(tries <= 0){return;}
					window.setTimeout(function(){
						initVideoEditorsWithRetry(scope, tries - 1);
					}, 180);
				}

				document.addEventListener('click', function(e){
					var switchBtn = e.target ? e.target.closest('.wp-switch-editor') : null;
					if(!switchBtn || !switchBtn.id){return;}
					var match = switchBtn.id.match(/^(.*)-(tmce|html)$/);
					if(!match || 'tmce' !== match[2]){return;}
					var editorId = match[1];
					window.setTimeout(function(){ ensureVisualMode(editorId); }, 40);
					window.setTimeout(function(){ ensureVisualMode(editorId); }, 160);
				});

				if(window.jQuery && typeof window.jQuery === 'function'){
					window.jQuery(document).on('tinymce-editor-init', function(_event, editor){
						if(editor && editor.id){
							ensureVisualMode(editor.id);
						}
					});
				}

				// ── Media frame helper ────────────────────────────────────────
				function openMediaFrame(options, callback){
					if(typeof wp==='undefined'||!wp.media){return;}
				var frame=wp.media({
					title: options.title||clmsI18n.mediaSelect,
					button:{text:options.button||clmsI18n.mediaUse},
					multiple:false,
					library:options.library||{}
				});
				frame.on('select',function(){
					callback(frame.state().get('selection').first().toJSON());
				});
				frame.open();
			}

			function bindDropzone(zone, handler){
				if(!zone||!handler){return;}
				zone.addEventListener('click', function(e){
					e.preventDefault();
					handler();
				});
				zone.addEventListener('keydown', function(e){
					if(e.key==='Enter'||e.key===' '){
						e.preventDefault();
						handler();
					}
				});
				zone.addEventListener('dragover', function(e){
					e.preventDefault();
					zone.classList.add('is-dragover');
				});
				zone.addEventListener('dragleave', function(){
					zone.classList.remove('is-dragover');
				});
				zone.addEventListener('drop', function(e){
					e.preventDefault();
					zone.classList.remove('is-dragover');
					handler();
				});
			}

			// ── Miniatura de la lección ───────────────────────────────────
			var pickCover  = document.getElementById('clms_pick_lesson_cover');
			var clearCover = document.getElementById('clms_clear_lesson_cover');
			var coverId    = document.getElementById('_clms_lesson_cover_image_id');
			var coverPrev  = document.getElementById('clms_cover_preview');
			if(pickCover&&clearCover&&coverId&&coverPrev){
				pickCover.addEventListener('click',function(e){
					e.preventDefault();
					openMediaFrame(
						{title:clmsI18n.mediaSelectThumb,button:clmsI18n.mediaUseImage,library:{type:'image'}},
						function(att){
							coverId.value=att.id||'';
							var url=att.sizes&&att.sizes.medium?att.sizes.medium.url:att.url;
							coverPrev.innerHTML=url?'<img src="'+url+'" alt="">':'';
						}
					);
				});
				clearCover.addEventListener('click',function(e){
					e.preventDefault();
					coverId.value='';
					coverPrev.innerHTML='';
				});
			}

			// ── Vista previa de video (por tarjeta) ──────────────────────
			function ytId(u){var m=u.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);return m?m[1]:null;}
			function vmId(u){var m=u.match(/vimeo\.com\/(?:video\/)?(\d+)/);return m?m[1]:null;}
			function drId(u){var m=u.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);if(m)return m[1];m=u.match(/[?&]id=([a-zA-Z0-9_-]+)/);return m?m[1]:null;}

			function buildEmbed(src, url){
				if(!url) return '';
				if(src==='url') return '<div class="clms-video-ratio" style="padding-top:0;position:static"><video controls style="width:100%;border-radius:6px;background:#000"><source src="'+url+'"></video></div>';
				var es='';
				if(src==='youtube'){var id=ytId(url);if(id)es='https://www.youtube-nocookie.com/embed/'+id;}
				else if(src==='vimeo'){var id=vmId(url);if(id)es='https://player.vimeo.com/video/'+id;}
				else if(src==='bunny'){es=url;}
				else if(src==='drive'){var id=drId(url);if(id)es='https://drive.google.com/file/d/'+id+'/preview';}
				return es?'<div class="clms-video-ratio"><iframe src="'+es+'" allowfullscreen loading="lazy"></iframe></div>':'';
			}

			function autoYtThumb(url){
				var id=ytId(url);
				return id?'https://img.youtube.com/vi/'+id+'/mqdefault.jpg':'';
			}

			function updateCardThumb(card, src, url){
				var thumbId  = card.querySelector('.clms-video-thumb-id');
				var thumbImg = card.querySelector('.clms-video-thumb-img');
				if(!thumbImg) return;
				// Don't override manual selection
				if(thumbId && thumbId.value) return;
				var auto = (src==='youtube') ? autoYtThumb(url) : '';
				if(auto){thumbImg.src=auto;thumbImg.style.display='';}
				else{thumbImg.src='';thumbImg.style.display='none';}
			}

			function bindCardPreview(card){
				var srcEl  = card.querySelector('select[name$="[source]"]');
				var urlEl  = card.querySelector('input[type="url"]');
				var prevEl = card.querySelector('.clms-card-preview');
				if(!srcEl||!urlEl) return;
				function render(){
					var src=srcEl.value, url=urlEl.value.trim();
					if(prevEl){
						var html=buildEmbed(src, url);
						if(html){prevEl.innerHTML=html;prevEl.style.display='';}
						else{prevEl.innerHTML='';prevEl.style.display='none';}
					}
					updateCardThumb(card, src, url);
				}
				srcEl.addEventListener('change', render);
				urlEl.addEventListener('input', function(){clearTimeout(urlEl._t);urlEl._t=setTimeout(render,600);});
				// Run once to populate auto-thumb on PHP-rendered cards
				updateCardThumb(card, srcEl.value, urlEl.value.trim());
			}

			function bindCardThumb(card){
				var pickBtn  = card.querySelector('.clms-pick-video-thumb');
				var clearBtn = card.querySelector('.clms-clear-video-thumb');
				var thumbId  = card.querySelector('.clms-video-thumb-id');
				var thumbImg = card.querySelector('.clms-video-thumb-img');
				if(!pickBtn||!thumbId||!thumbImg) return;
				pickBtn.addEventListener('click',function(e){
					e.preventDefault();
					openMediaFrame(
						{title:clmsI18n.mediaVideoThumb,button:clmsI18n.mediaUseImage,library:{type:'image'}},
						function(att){
							thumbId.value=att.id||'';
							var u=att.sizes&&att.sizes.medium?att.sizes.medium.url:att.url;
							thumbImg.src=u||'';
							if(u)thumbImg.style.display='';
						}
					);
				});
				if(clearBtn){
					clearBtn.addEventListener('click',function(e){
						e.preventDefault();
						thumbId.value='';
						// Restore auto-thumb if applicable
						var srcEl=card.querySelector('select[name$="[source]"]');
						var urlEl=card.querySelector('input[type="url"]');
						if(srcEl&&urlEl) updateCardThumb(card,srcEl.value,urlEl.value.trim());
						else{thumbImg.src='';thumbImg.style.display='none';}
					});
				}
			}

			// ── Selector de archivo guía AI ───────────────────────────────
			var pickAi  = document.getElementById('clms_ai_pick_file');
			var clearAi = document.getElementById('clms_ai_clear_file');
			var aiDrop  = document.getElementById('clms_ai_guide_drop');
			var aiId    = document.getElementById('_clms_ai_guide_attachment_id');
			var aiLabel = document.getElementById('clms_ai_guide_file_label');
			function pickAiFile(){
				openMediaFrame({title:clmsI18n.mediaAiGuide,button:clmsI18n.mediaUseFile},function(att){
					aiId.value=att.id||'';
					aiLabel.value=att.title||att.filename||'';
				});
			}
			if(pickAi&&clearAi&&aiId&&aiLabel){
				pickAi.addEventListener('click',function(e){
					e.preventDefault();
					pickAiFile();
				});
				clearAi.addEventListener('click',function(e){e.preventDefault();aiId.value='';aiLabel.value='';});
				if(aiDrop){
					bindDropzone(aiDrop, pickAiFile);
				}
			}

			// ── Recursos de apoyo ─────────────────────────────────────────
			var resWrap = document.getElementById('clms_resources_wrap');
			var addBtn  = document.getElementById('clms_add_resource');
			var blank   = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

				function bindRow(row){
					if(!row)return;
					var pf=row.querySelector('.clms-pick-resource-file'),
					    cf=row.querySelector('.clms-clear-resource-file'),
					    fi=row.querySelector('.clms-resource-file-id'),
					    fl=row.querySelector('.clms-resource-file-label'),
					    fd=row.querySelector('[data-clms-dropzone="resource-file"]'),
					    pt=row.querySelector('.clms-pick-resource-thumb'),
					    ct=row.querySelector('.clms-clear-resource-thumb'),
					    ti=row.querySelector('.clms-resource-thumb-id'),
					    tp=row.querySelector('.clms-resource-thumb-preview'),
					    rm=row.querySelector('.clms-remove-resource-row');

					if(pf&&cf&&fi&&fl){
						var pickFile = function(){
							openMediaFrame({title:clmsI18n.mediaResourceFile,button:clmsI18n.mediaUse},function(att){
								fi.value=att.id||'';fl.value=att.title||att.filename||'';
							});
						};
						pf.addEventListener('click',function(e){
							e.preventDefault();
							pickFile();
						});
						cf.addEventListener('click',function(e){e.preventDefault();fi.value='';fl.value='';});
						if(fd){
							bindDropzone(fd, pickFile);
						}
					}
				if(pt&&ct&&ti&&tp){
					pt.addEventListener('click',function(e){
						e.preventDefault();
						openMediaFrame({title:clmsI18n.mediaThumbnail,button:clmsI18n.mediaUseImage,library:{type:'image'}},function(att){
							ti.value=att.id||'';
							tp.src=(att.sizes&&att.sizes.thumbnail?att.sizes.thumbnail.url:att.url)||'';
						});
					});
					ct.addEventListener('click',function(e){e.preventDefault();ti.value='';tp.src=blank;});
				}
				if(rm){
					rm.addEventListener('click',function(e){
						e.preventDefault();
						if(resWrap.querySelectorAll('.clms-resource-row').length>1){
							row.remove();reindex();
						}
					});
				}
			}

			function reindex(){
				resWrap.querySelectorAll('.clms-resource-row').forEach(function(row,i){
					row.querySelectorAll('input,textarea,select').forEach(function(f){
						if(f.name)f.name=f.name.replace(/clms_lesson_resources\[\d+\]/g,'clms_lesson_resources['+i+']');
					});
				});
			}

			if(resWrap){resWrap.querySelectorAll('.clms-resource-row').forEach(bindRow);}

			if(addBtn&&resWrap){
				addBtn.addEventListener('click',function(e){
					e.preventDefault();
					var i=resWrap.querySelectorAll('.clms-resource-row').length;
					var n='clms_lesson_resources['+i+']';
						var html='<div class="clms-resource-row"><div class="clms-resource-grid">'
							+'<p class="clms-f"><label>'+clmsI18n.labelTitle+'</label><input type="text" name="'+n+'[title]" value=""></p>'
							+'<p class="clms-f"><label>'+clmsI18n.labelType+'</label><select name="'+n+'[type]">'
							+'<option value="pdf">'+clmsI18n.typePdf+'</option><option value="guia">'+clmsI18n.typeGuide+'</option>'
							+'<option value="presentacion">'+clmsI18n.typePresentation+'</option><option value="audio">'+clmsI18n.typeAudio+'</option>'
							+'<option value="video">'+clmsI18n.typeVideo+'</option><option value="link">'+clmsI18n.typeLink+'</option>'
							+'<option value="archivo">'+clmsI18n.typeFile+'</option></select></p>'
							+'<p class="clms-f full"><label>'+clmsI18n.labelDescription+'</label><textarea name="'+n+'[description]" rows="2"></textarea></p>'
							+'<p class="clms-f"><label>'+clmsI18n.labelFile+'</label>'
							+'<div class="clms-dropzone" data-clms-dropzone="resource-file" tabindex="0" role="button">'
							+'<span class="clms-dropzone-title">'+clmsI18n.helpDropTitle+'</span>'
							+'<span class="clms-dropzone-sub">'+clmsI18n.helpDropSub+'</span>'
							+'</div>'
							+'<input type="hidden" class="clms-resource-file-id" name="'+n+'[file_id]" value="">'
							+'<input type="text" class="clms-resource-file-label" value="" readonly>'
							+'<div class="clms-actions"><button type="button" class="button clms-pick-resource-file">'+clmsI18n.buttonSelect+'</button>'
						+'<button type="button" class="button clms-clear-resource-file">'+clmsI18n.buttonRemove+'</button></div></p>'
						+'<p class="clms-f"><label>'+clmsI18n.labelExternalUrl+'</label><input type="url" name="'+n+'[url]" value=""></p>'
						+'<p class="clms-f full"><label>'+clmsI18n.labelThumbnail+'</label>'
						+'<input type="hidden" class="clms-resource-thumb-id" name="'+n+'[thumb_id]" value="">'
						+'<div class="clms-actions">'
						+'<button type="button" class="button clms-pick-resource-thumb">'+clmsI18n.buttonSelectThumb+'</button>'
						+'<button type="button" class="button clms-clear-resource-thumb">'+clmsI18n.buttonRemove+'</button>'
						+'<button type="button" class="button-link-delete clms-remove-resource-row">'+clmsI18n.buttonRemoveResource+'</button></div>'
						+'<div class="clms-resource-preview"><img class="clms-resource-thumb-preview" src="'+blank+'" alt=""></div>'
						+'</p></div></div>';
					var tmp=document.createElement('div');
					tmp.innerHTML=html;
					var row=tmp.firstChild;
					resWrap.appendChild(row);
					bindRow(row);
					reindex();
				});
			}
			// ── Tarjetas de video (sincronizadas con el contador) ────────
			(function(){
				var countEl = document.getElementById('_clms_lesson_video_count');
				var wrap    = document.getElementById('clms_video_cards_wrap');
				if(!countEl||!wrap){return;}

				var sourceOpts = [
					{v:'youtube', l:clmsI18n.sourceYoutube},
					{v:'vimeo',   l:clmsI18n.sourceVimeo},
					{v:'bunny',   l:clmsI18n.sourceBunny},
					{v:'drive',   l:clmsI18n.sourceDrive},
					{v:'url',     l:clmsI18n.sourceUrl}
				];

				function buildSourceSelect(name){
					var sel='<select name="'+name+'">';
					sourceOpts.forEach(function(o){sel+='<option value="'+o.v+'">'+o.l+'</option>';});
					return sel+'</select>';
				}

				var blankThumb = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

					function makeCard(idx){
						var n = '_clms_lesson_extra_videos['+idx+']';
						var editorId = 'clms_video_desc_dyn_' + idx;
						var details = document.createElement('details');
						details.className = 'clms-video-card';
						details.setAttribute('open','');
						details.innerHTML =
							'<summary class="clms-video-card-title">'+formatText(clmsI18n.labelVideo,(idx+1))+'</summary>'
							+'<div class="clms-video-card-body clms-grid-2">'
								+'<p class="clms-f"><label>'+clmsI18n.labelSource+'</label>'+buildSourceSelect(n+'[source]')+'</p>'
								+'<p class="clms-f"><label>'+clmsI18n.labelUrl+'</label><input type="url" name="'+n+'[url]" value="" placeholder="https://..."></p>'
								+'<div class="clms-f full"><label>'+clmsI18n.labelVideoDescription+'</label><textarea id="'+editorId+'" name="'+n+'[description]" rows="5" class="clms-video-desc-editor"></textarea><span class="clms-help">'+clmsI18n.helpVideoHtml+'</span></div>'
								+'<p class="clms-f"><label>'+clmsI18n.labelTip1+'</label><input type="text" name="'+n+'[tip_1]" value=""></p>'
								+'<p class="clms-f"><label>'+clmsI18n.labelTip2+'</label><input type="text" name="'+n+'[tip_2]" value=""></p>'
								+'<p class="clms-f"><label>'+clmsI18n.labelTip3+'</label><input type="text" name="'+n+'[tip_3]" value=""></p>'
							+'<p class="clms-f full">'
								+'<label>'+clmsI18n.labelVideoThumb+'</label>'
								+'<input type="hidden" class="clms-video-thumb-id" name="'+n+'[thumb_id]" value="">'
								+'<div class="clms-video-thumb-wrap">'
									+'<img class="clms-video-thumb-img" src="'+blankThumb+'" alt="" style="display:none">'
									+'<div>'
										+'<span class="clms-help">'+clmsI18n.helpVideoThumb+'</span>'
										+'<div class="clms-actions">'
											+'<button type="button" class="button clms-pick-video-thumb">'+clmsI18n.buttonSelectImage+'</button>'
											+'<button type="button" class="button clms-clear-video-thumb">'+clmsI18n.buttonRemove+'</button>'
										+'</div>'
									+'</div>'
								+'</div>'
							+'</p>'
							+'<div class="full clms-card-preview" style="display:none"></div>'
						+'</div>';
					return details;
				}

				function renumber(){
					wrap.querySelectorAll('.clms-video-card').forEach(function(card, i){
						var title = card.querySelector('.clms-video-card-title');
						if(title) title.firstChild.nodeValue = formatText(clmsI18n.labelVideo,(i+1));
						// Reindex field names
						card.querySelectorAll('input,textarea,select').forEach(function(f){
							if(f.name) f.name = f.name.replace(/_clms_lesson_extra_videos\[\d+\]/g,'_clms_lesson_extra_videos['+i+']');
						});
					});
				}

					function syncCards(){
						var total   = Math.max(1, parseInt(countEl.value,10)||1);
						var cards   = wrap.querySelectorAll('.clms-video-card');
						var current = cards.length;

						// Remove excess cards
						for(var r=current-1; r>=total; r--){
							cards[r].querySelectorAll('textarea.clms-video-desc-editor').forEach(removeVideoDescEditor);
							cards[r].parentNode.removeChild(cards[r]);
						}

						// Add missing cards
						for(var i=current; i<total; i++){
							var card = makeCard(i);
							wrap.appendChild(card);
							bindCardPreview(card);
							bindCardThumb(card);
								initVideoEditorsWithRetry(card);
						}

						renumber();
					}

				// Bind existing PHP-rendered cards
						wrap.querySelectorAll('.clms-video-card').forEach(function(card){
							bindCardPreview(card);
							bindCardThumb(card);
							initVideoEditorsWithRetry(card);
						});

				// Safety net: if PHP rendered fewer cards than the count, fill in the gap
				(function(){
					var needed = Math.max(1, parseInt(countEl.value,10)||1);
					var have   = wrap.querySelectorAll('.clms-video-card').length;
					if(have < needed){
							for(var i=have; i<needed; i++){
								var c=makeCard(i);
								wrap.appendChild(c);
								bindCardPreview(c);
								bindCardThumb(c);
									initVideoEditorsWithRetry(c);
								}
								renumber();
							}
						})();

						initVideoEditorsWithRetry(wrap);

						countEl.addEventListener('input',  syncCards);
						countEl.addEventListener('change', syncCards);
					})();

				// ── Activity type -> Evaluation metabox live trigger ─────────────
				(function(){
					var activitySelect   = document.getElementById('lm_activity_type');
					var quizEnabled      = document.getElementById('_clms_quiz_enabled');
						var modeSelect       = document.getElementById('_clms_evaluation_mode');
						var peerReviewSelect = document.getElementById('_clms_peer_review_enabled');
						var evalSummary      = document.getElementById('clms-eval-mode-summary');
						var evalBox          = document.getElementById('clms_lesson_evaluation');
						var bankCounter      = document.getElementById('clms-ai-bank-count');

					if(!activitySelect || !evalBox){return;}

					function openEvaluationBox(){
						evalBox.style.display = '';
						evalBox.hidden = false;
						evalBox.classList.remove('closed');

						var inside = evalBox.querySelector('.inside');
						if(inside){ inside.style.display = ''; }

						var toggleBtn = evalBox.querySelector('.handlediv');
						if(toggleBtn){
							toggleBtn.setAttribute('aria-expanded', 'true');
						}
					}

					function setGroupVisibility(selector, visible){
						evalBox.querySelectorAll(selector).forEach(function(node){
							node.classList.toggle('clms-is-hidden', !visible);
						});
					}

					function getModeHint(mode){
						switch(mode){
							case 'ai_assisted': return clmsI18n.evalHintAiAssisted || '';
							case 'ai_auto_grade': return clmsI18n.evalHintAiAuto || '';
							case 'peer_review': return clmsI18n.evalHintPeer || '';
							case 'hybrid': return clmsI18n.evalHintHybrid || '';
							case 'manual':
							default:
								return clmsI18n.evalHintManual || '';
						}
					}

						function syncEvaluationFields(){
							var isQuizType    = activitySelect.value === 'quiz';
							var isQuizEnabled = !quizEnabled || quizEnabled.value === '1';
							var hasBank       = !!bankCounter && (parseInt(bankCounter.textContent || '0', 10) > 0);
							var mode          = modeSelect ? modeSelect.value : 'manual';
							var aiMode        = mode === 'ai_assisted' || mode === 'ai_auto_grade' || mode === 'hybrid';
							var peerMode      = mode === 'peer_review' || (peerReviewSelect && peerReviewSelect.value === '1');
							var showQuizFields = isQuizType || isQuizEnabled || hasBank;

							setGroupVisibility('.clms-eval-quiz-only', showQuizFields);
							setGroupVisibility('.clms-eval-quiz-inactive-note', !(isQuizType && isQuizEnabled));
							setGroupVisibility('.clms-eval-ai-only', aiMode);
							setGroupVisibility('.clms-eval-peer-only', peerMode);

						if (peerReviewSelect && mode === 'peer_review' && peerReviewSelect.value !== '1') {
							peerReviewSelect.value = '1';
						}

							if(evalSummary){
								if(!isQuizType && (isQuizEnabled || hasBank)){
									evalSummary.textContent = clmsI18n.evalHintNeedsSync || '';
								} else if(!isQuizType){
									evalSummary.textContent = clmsI18n.evalHintNotQuiz || '';
								} else if(!isQuizEnabled){
									evalSummary.textContent = clmsI18n.evalHintQuizOff || '';
							} else {
								evalSummary.textContent = getModeHint(mode);
							}
						}
					}

					function syncEvaluationBox(fromUserAction){
						var isQuiz = activitySelect.value === 'quiz';
						evalBox.classList.toggle('clms-eval-is-quiz', isQuiz);
						if(isQuiz){
							openEvaluationBox();
							if(fromUserAction){
								try {
									evalBox.scrollIntoView({behavior:'smooth', block:'start'});
								} catch (e) {
									evalBox.scrollIntoView(true);
								}
							}
						}
						syncEvaluationFields();
					}

					activitySelect.addEventListener('change', function(){
						syncEvaluationBox(true);
					});
					if(quizEnabled){
						quizEnabled.addEventListener('change', syncEvaluationFields);
					}
					if(modeSelect){
						modeSelect.addEventListener('change', syncEvaluationFields);
					}
					if(peerReviewSelect){
						peerReviewSelect.addEventListener('change', syncEvaluationFields);
					}

					// Initial paint + delayed safety pass for Gutenberg layout timing.
					syncEvaluationBox(false);
					window.setTimeout(function(){ syncEvaluationBox(false); }, 260);
				})();

				// ── Delivery mode -> Activity type / Quiz toggle sync ────────────
				(function(){
					var deliverySelect = document.getElementById('clms_delivery_mode');
					var activitySelect = document.getElementById('lm_activity_type');
					var quizEnabled    = document.getElementById('_clms_quiz_enabled');

					if(!deliverySelect || !activitySelect){return;}

					deliverySelect.addEventListener('change', function(){
						var nextActivity    = activitySelect.value;
						var nextQuizEnabled = quizEnabled ? quizEnabled.value : null;

						switch(deliverySelect.value){
							case 'read_only':
								nextActivity = 'lectura';
								break;
							case 'quiz':
								nextActivity    = 'quiz';
								nextQuizEnabled = '1';
								break;
							case 'file':
							case 'text':
							case 'both':
								nextActivity = 'tarea';
								break;
						}

						if(activitySelect.value !== nextActivity){
							activitySelect.value = nextActivity;
							activitySelect.dispatchEvent(new Event('change', {bubbles:true}));
						}

						if(quizEnabled && nextQuizEnabled !== null && quizEnabled.value !== nextQuizEnabled){
							quizEnabled.value = nextQuizEnabled;
							quizEnabled.dispatchEvent(new Event('change', {bubbles:true}));
						}
					});
				})();

					var postForm = document.getElementById('post');
					if(postForm){
						postForm.addEventListener('submit', function(){
							if(typeof tinyMCE !== 'undefined' && tinyMCE && typeof tinyMCE.triggerSave === 'function'){
							tinyMCE.triggerSave();
						}
					});
				}

			});
			</script>
		<?php
	}

	// ── METABOX 1: Sidebar — Relación y calendario ───────────────────────────

}
