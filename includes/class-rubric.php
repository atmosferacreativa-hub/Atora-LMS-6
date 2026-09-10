<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_Rubric — Rúbricas de evaluación.
 *
 * CPT: clms_rubric
 * Meta: _clms_rubric_criteria → array de {name, description, max_points}
 * Lección usa _clms_rubric_id para apuntar al rubric asignado.
 */
class CLMS_Rubric {

	const CPT          = 'clms_rubric';
	const PRESET_CPT   = 'clms_rubric_preset';
	const META_CRITERIA = '_clms_rubric_criteria';
	const META_SCALE    = '_clms_rubric_scale_type';
	const META_HOLISTIC = '_clms_rubric_is_holistic';
	const NONCE_ACTION = 'clms_save_rubric_meta';
	const NONCE_NAME   = 'clms_rubric_nonce';

	public function __construct() {
		add_action( 'init',             array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes',   array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save_meta_boxes' ), 20, 2 );
		add_action( 'save_post_' . self::PRESET_CPT, array( $this, 'save_meta_boxes' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns',       array( $this, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	// ── CPT ─────────────────────────────────────────────────────────────────────

	public function register_post_type() {
		register_post_type(
			self::CPT,
			array(
				'labels'             => array(
					'name'               => 'Rúbricas',
					'singular_name'      => 'Rúbrica',
					'add_new'            => 'Nueva rúbrica',
					'add_new_item'       => 'Crear rúbrica',
					'edit_item'          => 'Editar rúbrica',
					'view_item'          => 'Ver rúbrica',
					'search_items'       => 'Buscar rúbricas',
					'not_found'          => 'No hay rúbricas.',
					'not_found_in_trash' => 'No hay rúbricas en la papelera.',
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'clms-dashboard',
				'show_in_rest'       => false,
				'supports'           => array( 'title' ),
				'capability_type'    => array( 'clms_rubric', 'clms_rubrics' ),
				'map_meta_cap'       => true,
				'capabilities'       => array(
					'edit_post'              => 'edit_clms_rubric',
					'read_post'              => 'read_clms_rubric',
					'delete_post'            => 'delete_clms_rubric',
					'edit_posts'             => 'edit_clms_rubrics',
					'edit_others_posts'      => 'edit_others_clms_rubrics',
					'publish_posts'          => 'publish_clms_rubrics',
					'read_private_posts'     => 'read_private_clms_rubrics',
					'delete_posts'           => 'delete_clms_rubrics',
					'delete_private_posts'   => 'delete_private_clms_rubrics',
					'delete_published_posts' => 'delete_published_clms_rubrics',
					'delete_others_posts'    => 'delete_others_clms_rubrics',
					'edit_private_posts'     => 'edit_private_clms_rubrics',
					'edit_published_posts'   => 'edit_published_clms_rubrics',
					'create_posts'           => 'create_clms_rubrics',
				),
				'has_archive'        => false,
				'publicly_queryable' => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-feedback',
			)
		);
	}

	// ── Admin columns ────────────────────────────────────────────────────────────

	public function columns( $cols ) {
		unset( $cols['date'] );
		$cols['rubric_criteria_count'] = 'Criterios';
		$cols['rubric_total_points']   = 'Puntos totales';
		$cols['date']                  = 'Fecha';
		return $cols;
	}

	public function column_content( $col, $post_id ) {
		if ( 'rubric_criteria_count' === $col ) {
			echo esc_html( count( self::get_criteria( $post_id ) ) );
		} elseif ( 'rubric_total_points' === $col ) {
			echo esc_html( self::get_total_points( $post_id ) );
		}
	}

	// ── Metabox ──────────────────────────────────────────────────────────────────

	public function add_meta_boxes() {
		add_meta_box(
			'clms_rubric_criteria',
			__( 'Criterios de evaluación', 'atora-lms' ),
			array( $this, 'render_meta_box' ),
			self::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'clms_rubric_preset_criteria',
			__( 'Criterios del preset', 'atora-lms' ),
			array( $this, 'render_meta_box' ),
			self::PRESET_CPT,
			'normal',
			'high'
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, array( self::CPT, self::PRESET_CPT ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'clms-sortablejs',
			'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.3/Sortable.min.js',
			array(),
			'1.15.3',
			true
		);
	}

		public function render_meta_box( $post ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

			$criteria = self::get_criteria( $post->ID );
			$scale_type  = sanitize_key( (string) get_post_meta( $post->ID, self::META_SCALE, true ) );
			$is_holistic = '1' === (string) get_post_meta( $post->ID, self::META_HOLISTIC, true );

			$allowed_scales = array(
				''      => __( '0–100 (interno)', 'atora-lms' ),
				'0_4'   => __( '0–4', 'atora-lms' ),
				'0_5'   => __( '0–5', 'atora-lms' ),
				'0_20'  => __( '0–20', 'atora-lms' ),
				'0_100' => __( '0–100', 'atora-lms' ),
				'a_f'   => __( 'A–F', 'atora-lms' ),
			);
			if ( ! array_key_exists( $scale_type, $allowed_scales ) ) {
				$scale_type = '';
			}

			$preset_posts = get_posts( array(
				'post_type'      => class_exists( '\ATORA\Rubrics\Rubrics_Module' ) ? \ATORA\Rubrics\Rubrics_Module::PRESET_CPT : 'clms_rubric_preset',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
			$preset_payloads = array();
			foreach ( (array) $preset_posts as $preset_post ) {
				$preset_payloads[] = array(
					'id'         => absint( $preset_post->ID ),
					'title'      => (string) $preset_post->post_title,
					'criteria'   => (array) get_post_meta( $preset_post->ID, self::META_CRITERIA, true ),
					'scale_type' => sanitize_key( (string) get_post_meta( $preset_post->ID, self::META_SCALE, true ) ),
					'is_holistic' => '1' === (string) get_post_meta( $preset_post->ID, self::META_HOLISTIC, true ),
				);
			}
			if ( empty( $criteria ) ) {
				$criteria = array(
					array(
						'name'        => '',
						'description' => '',
						'weight'      => 100,
						'max_points'  => 10,
						'levels'      => self::get_default_levels( 10 ),
					),
				);
		}

			foreach ( $criteria as $index => $criterion ) {
				$max_points = isset( $criterion['max_points'] ) ? max( 1, absint( $criterion['max_points'] ) ) : 10;
				if ( empty( $criterion['levels'] ) || ! is_array( $criterion['levels'] ) ) {
					$criteria[ $index ]['levels'] = self::get_default_levels( $max_points );
				}
				if ( ! isset( $criterion['weight'] ) ) {
					$criteria[ $index ]['weight'] = 0;
				}
			}

		$default_level_labels = array(
			__( 'Inicial', 'atora-lms' ),
			__( 'En desarrollo', 'atora-lms' ),
			__( 'Competente', 'atora-lms' ),
			__( 'Excelente', 'atora-lms' ),
		);
		?>
		<style>
		.clms-rubric-builder{display:flex;flex-direction:column;gap:12px}
		.clms-rubric-criterion{border:1px solid #dcdcde;border-radius:8px;background:#fff}
		.clms-rubric-criterion-header{display:grid;grid-template-columns:24px minmax(0,1fr)95px 80px auto auto auto;gap:8px;align-items:center;padding:10px;border-bottom:1px solid #f0f0f1}
		.clms-rubric-drag-handle{cursor:grab;font-size:15px;color:#646970;text-align:center;user-select:none}
		.clms-rubric-criterion-header input[type="text"],.clms-rubric-description textarea{width:100%;box-sizing:border-box}
		.clms-rubric-criterion-header input[type="number"]{width:100%}
		.clms-rubric-icon-btn{border:1px solid #dcdcde;background:#fff;border-radius:6px;padding:2px 8px;line-height:1.6;cursor:pointer}
		.clms-rubric-icon-btn:hover{background:#f6f7f7}
		.clms-rubric-icon-btn.is-danger{color:#d63638}
		.clms-rubric-description{padding:10px}
		.clms-rubric-levels{padding:10px;border-top:1px dashed #dcdcde;background:#fbfbfc}
		.clms-rubric-level{display:grid;grid-template-columns:minmax(0,160px)80px minmax(0,1fr) minmax(0,1fr)34px;gap:8px;align-items:start;margin-bottom:8px}
		.clms-rubric-level textarea{width:100%;box-sizing:border-box;resize:vertical}
		.clms-rubric-level input[type="number"]{width:100%}
		.clms-rubric-controls{display:flex;gap:8px;margin-top:10px}
		.clms-rubric-total-wrap{margin-top:10px;font-size:13px;color:#646970}
		.clms-rubric-total-wrap strong{color:#1d2327}
		.clms-rubric-preview{margin-top:12px;padding:10px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
		.clms-rubric-preview table{width:100%;border-collapse:collapse}
		.clms-rubric-preview th,.clms-rubric-preview td{border:1px solid #dcdcde;padding:8px;text-align:left;vertical-align:top}
		.clms-rubric-preview th{background:#f6f7f7}
		.clms-rubric-ghost{opacity:.5}
		@media (max-width:782px){
			.clms-rubric-criterion-header{grid-template-columns:24px minmax(0,1fr);grid-auto-rows:auto}
			.clms-rubric-level{grid-template-columns:1fr}
		}
		</style>

		<div class="clms-rubric-preview" style="margin-top:0;margin-bottom:12px">
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
				<p style="margin:0">
					<label for="clms_rubric_scale_type"><strong><?php esc_html_e( 'Escala', 'atora-lms' ); ?></strong></label><br>
					<select id="clms_rubric_scale_type" name="<?php echo esc_attr( self::META_SCALE ); ?>" style="width:100%">
						<?php foreach ( $allowed_scales as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $scale_type, $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p style="margin:0">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::META_HOLISTIC ); ?>" value="1" <?php checked( $is_holistic, true ); ?>>
						<strong><?php esc_html_e( 'Rúbrica holística', 'atora-lms' ); ?></strong>
					</label><br>
					<span class="description"><?php esc_html_e( 'MVP: se guarda como configuración; el cálculo sigue siendo por criterios.', 'atora-lms' ); ?></span>
				</p>
			</div>
			<div style="display:flex;gap:8px;align-items:end;margin-top:10px">
				<p style="margin:0;flex:1">
					<label for="clms_rubric_preset_select"><strong><?php esc_html_e( 'Preset', 'atora-lms' ); ?></strong></label><br>
					<select id="clms_rubric_preset_select" style="width:100%">
						<option value=""><?php esc_html_e( '— Sin preset —', 'atora-lms' ); ?></option>
						<?php foreach ( (array) $preset_payloads as $preset ) : ?>
							<option value="<?php echo esc_attr( (string) $preset['id'] ); ?>"><?php echo esc_html( (string) $preset['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Aplica un preset para copiar criterios y configuración.', 'atora-lms' ); ?></span>
				</p>
				<button type="button" class="button" id="clms_rubric_apply_preset"><?php esc_html_e( 'Aplicar preset', 'atora-lms' ); ?></button>
			</div>
		</div>

		<div id="clms-rubric-builder" class="clms-rubric-builder">
			<?php foreach ( $criteria as $i => $c ) :
				$name    = isset( $c['name'] ) ? $c['name'] : '';
				$desc    = isset( $c['description'] ) ? $c['description'] : '';
				$competency = isset( $c['competency'] ) ? $c['competency'] : '';
				$improvement_tip = isset( $c['improvement_tip'] ) ? $c['improvement_tip'] : '';
				$weight  = isset( $c['weight'] ) ? (float) $c['weight'] : 0;
				$max_pts = isset( $c['max_points'] ) ? max( 1, absint( $c['max_points'] ) ) : 10;
				$levels  = isset( $c['levels'] ) && is_array( $c['levels'] ) ? array_values( $c['levels'] ) : array();
			?>
				<div class="clms-rubric-criterion" data-index="<?php echo esc_attr( $i ); ?>">
					<div class="clms-rubric-criterion-header">
						<span class="clms-rubric-drag-handle" title="<?php echo esc_attr__( 'Arrastrar criterio', 'atora-lms' ); ?>">☰</span>
						<input
							type="text"
							class="clms-rubric-name"
							name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][name]"
							value="<?php echo esc_attr( $name ); ?>"
							placeholder="<?php echo esc_attr__( 'Nombre del criterio', 'atora-lms' ); ?>"
						>
						<input
							type="number"
							min="1"
							max="999"
							class="clms-rubric-max-pts"
							name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][max_points]"
							value="<?php echo esc_attr( $max_pts ); ?>"
						>
						<input
							type="number"
							min="0"
							max="100"
							step="0.1"
							class="clms-rubric-weight"
							name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][weight]"
							value="<?php echo esc_attr( (string) $weight ); ?>"
							title="<?php echo esc_attr__( 'Peso (%)', 'atora-lms' ); ?>"
						>
						<button type="button" class="clms-rubric-icon-btn clms-rubric-duplicate" title="<?php echo esc_attr__( 'Duplicar criterio', 'atora-lms' ); ?>">⊕</button>
						<button type="button" class="clms-rubric-icon-btn is-danger clms-rubric-delete" title="<?php echo esc_attr__( 'Eliminar criterio', 'atora-lms' ); ?>">✕</button>
						<button type="button" class="clms-rubric-icon-btn clms-rubric-toggle-levels" title="<?php echo esc_attr__( 'Mostrar niveles', 'atora-lms' ); ?>" aria-expanded="false">▸</button>
					</div>

					<div class="clms-rubric-description">
						<textarea
							rows="2"
							class="clms-rubric-description-input"
							name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][description]"
							placeholder="<?php echo esc_attr__( 'Indicadores de logro...', 'atora-lms' ); ?>"
						><?php echo esc_textarea( $desc ); ?></textarea>
						<input
							type="text"
							class="clms-rubric-competency-input"
							name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][competency]"
							value="<?php echo esc_attr( (string) $competency ); ?>"
							placeholder="<?php echo esc_attr__( 'Competencia asociada (opcional)', 'atora-lms' ); ?>"
							style="margin-top:8px"
						>
						<textarea
							rows="2"
							class="clms-rubric-improvement-input"
							name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][improvement_tip]"
							placeholder="<?php echo esc_attr__( 'Recomendación de mejora para este criterio', 'atora-lms' ); ?>"
							style="margin-top:8px"
						><?php echo esc_textarea( (string) $improvement_tip ); ?></textarea>
					</div>

					<div class="clms-rubric-levels" hidden>
						<div class="clms-rubric-levels-list">
								<?php foreach ( $levels as $li => $level ) :
									$label      = isset( $level['label'] ) ? (string) $level['label'] : '';
									$points     = isset( $level['points'] ) ? max( 0, absint( $level['points'] ) ) : 0;
									$descriptor = isset( $level['descriptor'] ) ? (string) $level['descriptor'] : '';
									$exemplar   = isset( $level['exemplar'] ) ? (string) $level['exemplar'] : '';
								?>
									<div class="clms-rubric-level">
										<input type="text" name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][levels][<?php echo esc_attr( $li ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr__( 'Nivel', 'atora-lms' ); ?>">
										<input type="number" min="0" max="999" name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][levels][<?php echo esc_attr( $li ); ?>][points]" value="<?php echo esc_attr( $points ); ?>">
										<textarea rows="1" name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][levels][<?php echo esc_attr( $li ); ?>][descriptor]" placeholder="<?php echo esc_attr__( 'Descriptor del nivel', 'atora-lms' ); ?>"><?php echo esc_textarea( $descriptor ); ?></textarea>
										<textarea rows="1" name="_clms_rubric_criteria[<?php echo esc_attr( $i ); ?>][levels][<?php echo esc_attr( $li ); ?>][exemplar]" placeholder="<?php echo esc_attr__( 'Ejemplar / benchmark (opcional)', 'atora-lms' ); ?>"><?php echo esc_textarea( $exemplar ); ?></textarea>
										<button type="button" class="clms-rubric-icon-btn is-danger clms-rubric-delete-level" title="<?php echo esc_attr__( 'Eliminar nivel', 'atora-lms' ); ?>">✕</button>
									</div>
								<?php endforeach; ?>
						</div>
						<button type="button" class="button clms-rubric-add-level"><?php echo esc_html__( '+ Nivel', 'atora-lms' ); ?></button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="clms-rubric-total-wrap">
			<?php echo esc_html__( 'Total:', 'atora-lms' ); ?> <strong id="clms-rubric-total-pts">0</strong> <?php echo esc_html__( 'puntos', 'atora-lms' ); ?>
		</div>

		<div class="clms-rubric-controls">
			<button type="button" class="button" id="clms-add-criterion"><?php echo esc_html__( '+ Agregar criterio', 'atora-lms' ); ?></button>
			<button type="button" class="button" id="clms-preview-rubric"><?php echo esc_html__( 'Vista previa', 'atora-lms' ); ?></button>
		</div>

		<div id="clms-rubric-preview" class="clms-rubric-preview" hidden></div>

		<script>
			(function(){
				var builder     = document.getElementById('clms-rubric-builder');
				var addBtn      = document.getElementById('clms-add-criterion');
				var previewBtn  = document.getElementById('clms-preview-rubric');
				var previewBox  = document.getElementById('clms-rubric-preview');
				var totalEl     = document.getElementById('clms-rubric-total-pts');
				var presetSelect = document.getElementById('clms_rubric_preset_select');
				var applyPresetBtn = document.getElementById('clms_rubric_apply_preset');
				var presetData = <?php echo wp_json_encode( $preset_payloads ); ?>;
				var i18n        = {
					namePlaceholder: <?php echo wp_json_encode( __( 'Nombre del criterio', 'atora-lms' ) ); ?>,
					descPlaceholder: <?php echo wp_json_encode( __( 'Indicadores de logro...', 'atora-lms' ) ); ?>,
					levelPlaceholder: <?php echo wp_json_encode( __( 'Nivel', 'atora-lms' ) ); ?>,
					levelDescPlaceholder: <?php echo wp_json_encode( __( 'Descriptor del nivel', 'atora-lms' ) ); ?>,
					levelExemplarPlaceholder: <?php echo wp_json_encode( __( 'Ejemplar / benchmark (opcional)', 'atora-lms' ) ); ?>,
					addLevel: <?php echo wp_json_encode( __( '+ Nivel', 'atora-lms' ) ); ?>,
					removeCriterion: <?php echo wp_json_encode( __( 'Eliminar criterio', 'atora-lms' ) ); ?>,
					duplicateCriterion: <?php echo wp_json_encode( __( 'Duplicar criterio', 'atora-lms' ) ); ?>,
					showLevels: <?php echo wp_json_encode( __( 'Mostrar niveles', 'atora-lms' ) ); ?>,
				hideLevels: <?php echo wp_json_encode( __( 'Ocultar niveles', 'atora-lms' ) ); ?>,
				deleteLevel: <?php echo wp_json_encode( __( 'Eliminar nivel', 'atora-lms' ) ); ?>,
				previewEmpty: <?php echo wp_json_encode( __( 'Agrega al menos un criterio con nombre para previsualizar la rúbrica.', 'atora-lms' ) ); ?>,
				previewTitle: <?php echo wp_json_encode( __( 'Vista previa de rúbrica', 'atora-lms' ) ); ?>,
				previewCriterion: <?php echo wp_json_encode( __( 'Criterio', 'atora-lms' ) ); ?>,
				previewMax: <?php echo wp_json_encode( __( 'Puntos máx.', 'atora-lms' ) ); ?>,
				previewLevels: <?php echo wp_json_encode( __( 'Niveles de desempeño', 'atora-lms' ) ); ?>,
				previewCompetency: <?php echo wp_json_encode( __( 'Competencia', 'atora-lms' ) ); ?>,
				previewImprovement: <?php echo wp_json_encode( __( 'Mejora sugerida', 'atora-lms' ) ); ?>,
				noLevels: <?php echo wp_json_encode( __( 'Sin niveles definidos.', 'atora-lms' ) ); ?>
			};
			var defaultLabels = <?php echo wp_json_encode( $default_level_labels ); ?>;

			function toInt(value, fallback) {
				var parsed = parseInt(value, 10);
				return Number.isFinite(parsed) ? parsed : fallback;
			}

			function getDefaultLevels(maxPoints) {
				var max = Math.max(1, toInt(maxPoints, 10));
				return [
					{ label: defaultLabels[0] || 'Excelente', points: max, descriptor: '' },
					{ label: defaultLabels[1] || 'Bueno', points: Math.max(0, Math.round(max * 0.7)), descriptor: '' },
					{ label: defaultLabels[2] || 'Regular', points: Math.max(0, Math.round(max * 0.4)), descriptor: '' },
					{ label: defaultLabels[3] || 'Insuficiente', points: 0, descriptor: '' }
				];
			}

			function calcTotal() {
				var total = 0;
				builder.querySelectorAll('.clms-rubric-max-pts').forEach(function(el){
					total += toInt(el.value, 0);
				});
				totalEl.textContent = total;
			}

			function reindexAllCriteria() {
				builder.querySelectorAll('.clms-rubric-criterion').forEach(function(criterion, criterionIndex) {
					criterion.dataset.index = String(criterionIndex);
						criterion.querySelectorAll('.clms-rubric-name, .clms-rubric-max-pts, .clms-rubric-weight, .clms-rubric-description-input, .clms-rubric-competency-input, .clms-rubric-improvement-input').forEach(function(field) {
							field.name = field.name.replace(/_clms_rubric_criteria\[\d+\]/g, '_clms_rubric_criteria[' + criterionIndex + ']');
						});
					criterion.querySelectorAll('.clms-rubric-level').forEach(function(levelRow, levelIndex) {
						levelRow.querySelectorAll('input, textarea').forEach(function(field) {
							field.name = field.name
								.replace(/_clms_rubric_criteria\[\d+\]/g, '_clms_rubric_criteria[' + criterionIndex + ']')
								.replace(/\[levels\]\[\d+\]/g, '[levels][' + levelIndex + ']');
						});
					});
				});
			}

				function buildLevelHtml(index, levelIndex, levelData) {
					var data = levelData || { label: '', points: 0, descriptor: '' };
					return '<div class="clms-rubric-level">'
						+ '<input type="text" name="_clms_rubric_criteria[' + index + '][levels][' + levelIndex + '][label]" value="' + escapeHtml(data.label || '') + '" placeholder="' + escapeHtml(i18n.levelPlaceholder) + '">'
						+ '<input type="number" min="0" max="999" name="_clms_rubric_criteria[' + index + '][levels][' + levelIndex + '][points]" value="' + escapeHtml(String(toInt(data.points, 0))) + '">'
						+ '<textarea rows="1" name="_clms_rubric_criteria[' + index + '][levels][' + levelIndex + '][descriptor]" placeholder="' + escapeHtml(i18n.levelDescPlaceholder) + '">' + escapeHtml(data.descriptor || '') + '</textarea>'
						+ '<textarea rows="1" name="_clms_rubric_criteria[' + index + '][levels][' + levelIndex + '][exemplar]" placeholder="' + escapeHtml(i18n.levelExemplarPlaceholder) + '">' + escapeHtml(data.exemplar || '') + '</textarea>'
						+ '<button type="button" class="clms-rubric-icon-btn is-danger clms-rubric-delete-level" title="' + escapeHtml(i18n.deleteLevel) + '">✕</button>'
						+ '</div>';
				}

			function buildCriterionHtml(index, seed) {
				var data = seed || {};
				var maxPoints = Math.max(1, toInt(data.max_points, 10));
				var levels = Array.isArray(data.levels) && data.levels.length ? data.levels : getDefaultLevels(maxPoints);
				var levelsHtml = levels.map(function(level, levelIndex) {
					return buildLevelHtml(index, levelIndex, level);
				}).join('');

					return '<div class="clms-rubric-criterion" data-index="' + index + '">'
						+ '<div class="clms-rubric-criterion-header">'
						+ '<span class="clms-rubric-drag-handle" title="☰">☰</span>'
						+ '<input type="text" class="clms-rubric-name" name="_clms_rubric_criteria[' + index + '][name]" value="' + escapeHtml(data.name || '') + '" placeholder="' + escapeHtml(i18n.namePlaceholder) + '">'
						+ '<input type="number" min="1" max="999" class="clms-rubric-max-pts" name="_clms_rubric_criteria[' + index + '][max_points]" value="' + escapeHtml(String(maxPoints)) + '">'
						+ '<input type="number" min="0" max="100" step="0.1" class="clms-rubric-weight" name="_clms_rubric_criteria[' + index + '][weight]" value="' + escapeHtml(String((data.weight !== undefined && data.weight !== null) ? data.weight : '')) + '" title="Peso (%)">'
						+ '<button type="button" class="clms-rubric-icon-btn clms-rubric-duplicate" title="' + escapeHtml(i18n.duplicateCriterion) + '">⊕</button>'
						+ '<button type="button" class="clms-rubric-icon-btn is-danger clms-rubric-delete" title="' + escapeHtml(i18n.removeCriterion) + '">✕</button>'
						+ '<button type="button" class="clms-rubric-icon-btn clms-rubric-toggle-levels" title="' + escapeHtml(i18n.showLevels) + '" aria-expanded="false">▸</button>'
					+ '</div>'
					+ '<div class="clms-rubric-description">'
					+ '<textarea rows="2" class="clms-rubric-description-input" name="_clms_rubric_criteria[' + index + '][description]" placeholder="' + escapeHtml(i18n.descPlaceholder) + '">' + escapeHtml(data.description || '') + '</textarea>'
					+ '<input type="text" class="clms-rubric-competency-input" name="_clms_rubric_criteria[' + index + '][competency]" value="' + escapeHtml(data.competency || '') + '" placeholder="<?php echo esc_attr( __( 'Competencia asociada (opcional)', 'atora-lms' ) ); ?>" style="margin-top:8px">'
					+ '<textarea rows="2" class="clms-rubric-improvement-input" name="_clms_rubric_criteria[' + index + '][improvement_tip]" placeholder="<?php echo esc_attr( __( 'Recomendación de mejora para este criterio', 'atora-lms' ) ); ?>" style="margin-top:8px">' + escapeHtml(data.improvement_tip || '') + '</textarea>'
					+ '</div>'
					+ '<div class="clms-rubric-levels" hidden><div class="clms-rubric-levels-list">' + levelsHtml + '</div><button type="button" class="button clms-rubric-add-level">' + escapeHtml(i18n.addLevel) + '</button></div>'
					+ '</div>';
			}

			function escapeHtml(value) {
				return String(value)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#039;');
			}

				function getCriterionPayload(criterion) {
					var levels = [];
					criterion.querySelectorAll('.clms-rubric-level').forEach(function(level) {
						levels.push({
							label: level.querySelector('input[name*="[label]"]') ? level.querySelector('input[name*="[label]"]').value : '',
							points: toInt(level.querySelector('input[name*="[points]"]') ? level.querySelector('input[name*="[points]"]').value : 0, 0),
							descriptor: level.querySelector('textarea[name*="[descriptor]"]') ? level.querySelector('textarea[name*="[descriptor]"]').value : '',
							exemplar: level.querySelector('textarea[name*="[exemplar]"]') ? level.querySelector('textarea[name*="[exemplar]"]').value : ''
						});
					});
					return {
						name: criterion.querySelector('.clms-rubric-name') ? criterion.querySelector('.clms-rubric-name').value : '',
						description: criterion.querySelector('.clms-rubric-description-input') ? criterion.querySelector('.clms-rubric-description-input').value : '',
						competency: criterion.querySelector('.clms-rubric-competency-input') ? criterion.querySelector('.clms-rubric-competency-input').value : '',
						improvement_tip: criterion.querySelector('.clms-rubric-improvement-input') ? criterion.querySelector('.clms-rubric-improvement-input').value : '',
						weight: criterion.querySelector('.clms-rubric-weight') ? criterion.querySelector('.clms-rubric-weight').value : '',
						max_points: toInt(criterion.querySelector('.clms-rubric-max-pts') ? criterion.querySelector('.clms-rubric-max-pts').value : 10, 10),
						levels: levels
					};
				}

			function addCriterion(seed) {
				var idx = builder.querySelectorAll('.clms-rubric-criterion').length;
				var wrapper = document.createElement('div');
				wrapper.innerHTML = buildCriterionHtml(idx, seed);
				builder.appendChild(wrapper.firstChild);
				reindexAllCriteria();
				calcTotal();
			}

			function renderPreview() {
				var criteria = Array.from(builder.querySelectorAll('.clms-rubric-criterion')).map(getCriterionPayload).filter(function(item) {
					return item.name.trim() !== '';
				});
				if (!criteria.length) {
					previewBox.innerHTML = '<p>' + escapeHtml(i18n.previewEmpty) + '</p>';
					previewBox.hidden = false;
					return;
				}

				var html = '<h4>' + escapeHtml(i18n.previewTitle) + '</h4><table><thead><tr>'
					+ '<th>' + escapeHtml(i18n.previewCriterion) + '</th>'
					+ '<th>' + escapeHtml(i18n.previewMax) + '</th>'
					+ '<th>' + escapeHtml(i18n.previewCompetency) + '</th>'
					+ '<th>' + escapeHtml(i18n.previewLevels) + '</th>'
					+ '<th>' + escapeHtml(i18n.previewImprovement) + '</th>'
					+ '</tr></thead><tbody>';
				criteria.forEach(function(item) {
					var levelsHtml = item.levels.length
						? item.levels.map(function(level) {
							var descriptor = level.descriptor ? ' — ' + level.descriptor : '';
							return '<li><strong>' + escapeHtml(level.label || i18n.levelPlaceholder) + '</strong> (' + escapeHtml(String(toInt(level.points, 0))) + '): ' + escapeHtml(descriptor.replace(/^ — /, '')) + '</li>';
						}).join('')
						: '<li>' + escapeHtml(i18n.noLevels) + '</li>';
					html += '<tr><td><strong>' + escapeHtml(item.name) + '</strong><br>' + escapeHtml(item.description || '') + '</td>'
						+ '<td>' + escapeHtml(String(toInt(item.max_points, 0))) + '</td>'
						+ '<td>' + escapeHtml(item.competency || '—') + '</td>'
						+ '<td><ul>' + levelsHtml + '</ul></td>'
						+ '<td>' + escapeHtml(item.improvement_tip || '—') + '</td></tr>';
				});
				html += '</tbody></table>';
				previewBox.innerHTML = html;
				previewBox.hidden = false;
			}

			builder.addEventListener('click', function(event) {
				var criterion = event.target.closest('.clms-rubric-criterion');
				if (!criterion) {
					return;
				}

				if (event.target.classList.contains('clms-rubric-delete')) {
					if (builder.querySelectorAll('.clms-rubric-criterion').length > 1) {
						criterion.remove();
						reindexAllCriteria();
						calcTotal();
					}
					return;
				}

				if (event.target.classList.contains('clms-rubric-duplicate')) {
					addCriterion(getCriterionPayload(criterion));
					return;
				}

				if (event.target.classList.contains('clms-rubric-toggle-levels')) {
					var levels = criterion.querySelector('.clms-rubric-levels');
					var expanded = event.target.getAttribute('aria-expanded') === 'true';
					event.target.setAttribute('aria-expanded', expanded ? 'false' : 'true');
					event.target.textContent = expanded ? '▸' : '▾';
					event.target.title = expanded ? i18n.showLevels : i18n.hideLevels;
					if (levels) {
						levels.hidden = expanded;
					}
					return;
				}

				if (event.target.classList.contains('clms-rubric-add-level')) {
					var levelsList = criterion.querySelector('.clms-rubric-levels-list');
					var criterionIndex = toInt(criterion.dataset.index, 0);
					var levelIndex = levelsList ? levelsList.querySelectorAll('.clms-rubric-level').length : 0;
					var maxPoints = toInt(criterion.querySelector('.clms-rubric-max-pts').value, 10);
					var seed = { label: i18n.levelPlaceholder + ' ' + (levelIndex + 1), points: Math.max(0, Math.round(maxPoints * 0.5)), descriptor: '' };
					if (levelsList) {
						var row = document.createElement('div');
						row.innerHTML = buildLevelHtml(criterionIndex, levelIndex, seed);
						levelsList.appendChild(row.firstChild);
						reindexAllCriteria();
					}
					return;
				}

				if (event.target.classList.contains('clms-rubric-delete-level')) {
					var level = event.target.closest('.clms-rubric-level');
					if (level) {
						level.remove();
						reindexAllCriteria();
					}
				}
			});

			builder.addEventListener('input', function(event) {
				if (event.target.classList.contains('clms-rubric-max-pts')) {
					calcTotal();
				}
			});

				addBtn.addEventListener('click', function() {
					addCriterion({});
				});

				if (applyPresetBtn) {
					applyPresetBtn.addEventListener('click', function() {
						var id = presetSelect ? presetSelect.value : '';
						if (!id) {
							return;
						}
						var preset = (presetData || []).find(function(p) { return String(p.id) === String(id); });
						if (!preset) {
							return;
						}
						var scaleEl = document.getElementById('clms_rubric_scale_type');
						if (scaleEl) {
							scaleEl.value = preset.scale_type || '';
						}
						var holisticEl = document.querySelector('input[name="<?php echo esc_js( self::META_HOLISTIC ); ?>"]');
						if (holisticEl) {
							holisticEl.checked = !!preset.is_holistic;
						}
						builder.innerHTML = '';
						var criteria = Array.isArray(preset.criteria) ? preset.criteria : [];
						if (!criteria.length) {
							addCriterion({});
							return;
						}
						criteria.forEach(function(c) { addCriterion(c || {}); });
						reindexAllCriteria();
						calcTotal();
					});
				}

				previewBtn.addEventListener('click', function() {
					if (previewBox.hidden) {
						renderPreview();
						return;
					}
				previewBox.hidden = true;
			});

			if (window.Sortable && builder) {
				window.Sortable.create(builder, {
					handle: '.clms-rubric-drag-handle',
					animation: 150,
					ghostClass: 'clms-rubric-ghost',
					onEnd: function() {
						reindexAllCriteria();
					}
				});
			}

			reindexAllCriteria();
			calcTotal();
		})();
		</script>
		<?php
	}

	public function save_meta_boxes( $post_id, $post ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! $post || ! in_array( $post->post_type, array( self::CPT, self::PRESET_CPT ), true ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			return;
		}

		$allowed_scales = array( '', '0_4', '0_5', '0_20', '0_100', 'a_f' );
		$scale_type = isset( $_POST[ self::META_SCALE ] ) ? sanitize_key( (string) wp_unslash( $_POST[ self::META_SCALE ] ) ) : '';
		if ( ! in_array( $scale_type, $allowed_scales, true ) ) {
			$scale_type = '';
		}
		update_post_meta( $post_id, self::META_SCALE, $scale_type );

		$is_holistic = ! empty( $_POST[ self::META_HOLISTIC ] ) ? '1' : '0';
		update_post_meta( $post_id, self::META_HOLISTIC, $is_holistic );

		$raw      = isset( $_POST['_clms_rubric_criteria'] ) ? wp_unslash( $_POST['_clms_rubric_criteria'] ) : array();
		$criteria = $this->sanitize_criteria( $raw );

		update_post_meta( $post_id, self::META_CRITERIA, $criteria );
	}

	protected function sanitize_criteria( $raw ) {
		$raw   = is_array( $raw ) ? array_values( $raw ) : array();
		$clean = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name   = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
			$desc   = isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '';
			$competency = isset( $item['competency'] ) ? sanitize_text_field( $item['competency'] ) : '';
			$competency_id = isset( $item['competency_id'] ) ? sanitize_key( $item['competency_id'] ) : sanitize_key( sanitize_title( $competency ) );
			$improvement_tip = isset( $item['improvement_tip'] ) ? sanitize_textarea_field( $item['improvement_tip'] ) : '';
			$weight_raw = $item['weight'] ?? '';
			$weight     = '' !== (string) $weight_raw && is_numeric( $weight_raw ) ? max( 0.0, min( 100.0, (float) $weight_raw ) ) : 0.0;
			$max    = isset( $item['max_points'] ) ? max( 1, absint( $item['max_points'] ) ) : 10;
			$levels = isset( $item['levels'] ) && is_array( $item['levels'] ) ? array_values( $item['levels'] ) : array();

			if ( '' === $name ) {
				continue;
			}

			$clean_levels = array();
			foreach ( $levels as $level ) {
				if ( ! is_array( $level ) ) {
					continue;
				}

				$label      = isset( $level['label'] ) ? sanitize_text_field( $level['label'] ) : '';
				$points     = isset( $level['points'] ) ? max( 0, absint( $level['points'] ) ) : 0;
				$descriptor = isset( $level['descriptor'] ) ? sanitize_textarea_field( $level['descriptor'] ) : '';
				$exemplar   = isset( $level['exemplar'] ) ? sanitize_textarea_field( $level['exemplar'] ) : '';

				if ( '' === $label && '' === $descriptor && '' === $exemplar && 0 === $points ) {
					continue;
				}

				$clean_levels[] = array(
					'label'      => $label,
					'points'     => $points,
					'descriptor' => $descriptor,
					'exemplar'   => $exemplar,
				);
			}

			$clean[] = array(
					'name'       => $name,
					'description' => $desc,
					'competency' => $competency,
					'competency_id' => $competency_id,
					'improvement_tip' => $improvement_tip,
					'weight'     => $weight,
					'max_points' => $max,
					'levels'     => $clean_levels,
				);
		}

		// Normalizar pesos a 100%.
		$sum = 0.0;
		foreach ( $clean as $row ) {
			$sum += (float) ( $row['weight'] ?? 0 );
		}
		$count = count( $clean );
		if ( $count > 0 ) {
			if ( $sum <= 0.0 ) {
				$equal = 100.0 / $count;
				foreach ( $clean as &$row ) {
					$row['weight'] = round( $equal, 2 );
				}
				unset( $row );
			} else {
				foreach ( $clean as &$row ) {
					$row['weight'] = round( ( (float) ( $row['weight'] ?? 0 ) / $sum ) * 100.0, 2 );
				}
				unset( $row );
			}
		}

		return $clean;
	}

	protected static function get_default_levels( $max_points = 10 ) {
		$max_points = max( 1, absint( $max_points ) );

		return array(
			array(
				'label'      => __( 'Inicial', 'atora-lms' ),
				'points'     => max( 0, (int) round( $max_points * 0.25 ) ),
				'descriptor' => '',
			),
			array(
				'label'      => __( 'En desarrollo', 'atora-lms' ),
				'points'     => max( 0, (int) round( $max_points * 0.5 ) ),
				'descriptor' => '',
			),
			array(
				'label'      => __( 'Competente', 'atora-lms' ),
				'points'     => max( 0, (int) round( $max_points * 0.75 ) ),
				'descriptor' => '',
			),
			array(
				'label'      => __( 'Excelente', 'atora-lms' ),
				'points'     => $max_points,
				'descriptor' => '',
			),
		);
	}

	// ── Static helpers ───────────────────────────────────────────────────────────

	/**
	 * Devuelve los criterios de una rúbrica.
	 *
	 * @param int $rubric_id
	 * @return array
	 */
	public static function get_criteria( $rubric_id ) {
		$rubric_id = absint( $rubric_id );

		if ( ! $rubric_id || self::CPT !== get_post_type( $rubric_id ) ) {
			return array();
		}

		$criteria = get_post_meta( $rubric_id, self::META_CRITERIA, true );
		$criteria = is_array( $criteria ) ? $criteria : array();
		$normalized = array();

		foreach ( $criteria as $criterion ) {
			$criterion = is_array( $criterion ) ? $criterion : array();
			$competency = isset( $criterion['competency'] ) ? sanitize_text_field( (string) $criterion['competency'] ) : '';
			$criterion['competency_id'] = isset( $criterion['competency_id'] )
				? sanitize_key( (string) $criterion['competency_id'] )
				: sanitize_key( sanitize_title( $competency ) );
			$normalized[] = $criterion;
		}

		return $normalized;
	}

	/**
	 * Suma de puntos máximos de todos los criterios.
	 *
	 * @param int $rubric_id
	 * @return int
	 */
	public static function get_total_points( $rubric_id ) {
		$total = 0;
		foreach ( self::get_criteria( $rubric_id ) as $c ) {
			$total += isset( $c['max_points'] ) ? absint( $c['max_points'] ) : 0;
		}
		return $total;
	}

	/**
	 * Devuelve el rubric_id asignado a una lección (0 si ninguno).
	 *
	 * @param int $lesson_id
	 * @return int
	 */
	public static function get_rubric_for_lesson( $lesson_id ) {
		return absint( get_post_meta( absint( $lesson_id ), '_clms_rubric_id', true ) );
	}

	/**
	 * Formatea los criterios como texto para incluir en el prompt de IA.
	 *
	 * @param int $rubric_id
	 * @return string
	 */
	public static function format_criteria_for_prompt( $rubric_id ) {
		$criteria = self::get_criteria( $rubric_id );

		if ( empty( $criteria ) ) {
			return '';
		}

		$rubric_title = get_the_title( absint( $rubric_id ) );
		$lines        = array();

		$lines[] = 'Rúbrica: ' . $rubric_title;

		foreach ( $criteria as $i => $c ) {
			$name   = isset( $c['name'] ) ? $c['name'] : '';
			$desc   = isset( $c['description'] ) ? $c['description'] : '';
			$competency = isset( $c['competency'] ) ? sanitize_text_field( (string) $c['competency'] ) : '';
			$improvement_tip = isset( $c['improvement_tip'] ) ? sanitize_textarea_field( (string) $c['improvement_tip'] ) : '';
			$max    = isset( $c['max_points'] ) ? absint( $c['max_points'] ) : 0;
			$line = sprintf( '%d. %s (máx. %d pts) — %s', $i + 1, $name, $max, $desc );
			if ( '' !== $competency ) {
				$line .= ' | Competencia: ' . $competency;
			}
			if ( '' !== $improvement_tip ) {
				$line .= ' | Mejora sugerida: ' . $improvement_tip;
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Lista de rúbricas disponibles para selectores admin.
	 *
	 * @return array [ id => title ]
	 */
	public static function get_all_rubrics() {
		$posts = get_posts( array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );

		$result = array();
		foreach ( (array) $posts as $id ) {
			$result[ absint( $id ) ] = get_the_title( absint( $id ) );
		}

		return $result;
	}
}
