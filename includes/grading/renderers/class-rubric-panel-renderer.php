<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Rubric_Panel_Renderer {

	/**
	 * Renderiza el bloque de rúbrica dentro de SpeedGrade.
	 *
	 * @param array $context Contexto de la entrega.
	 * @return string
	 */
	public static function render( $context ) {
		$context      = is_array( $context ) ? $context : array();
		$rubric_id    = absint( $context['rubric_id'] ?? 0 );
		$snapshot     = isset( $context['rubric_snapshot'] ) && is_array( $context['rubric_snapshot'] ) ? (array) $context['rubric_snapshot'] : array();
		$has_snapshot = $rubric_id
			&& $rubric_id === absint( $snapshot['rubric_id'] ?? 0 )
			&& ! empty( $snapshot['criteria'] )
			&& is_array( $snapshot['criteria'] );

		$criteria = array();
		if ( $has_snapshot ) {
			$criteria = (array) $snapshot['criteria'];
		} elseif ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
			$criteria = CLMS_Rubric::get_criteria( $rubric_id );
		}

		$rubric_title = $has_snapshot
			? sanitize_text_field( (string) ( $snapshot['rubric_title'] ?? '' ) )
			: ( $rubric_id ? (string) get_the_title( $rubric_id ) : '' );

		$rub_max = $has_snapshot ? absint( $snapshot['total_points'] ?? 0 ) : 0;
		if ( ! $rub_max && $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
			$rub_max = absint( CLMS_Rubric::get_total_points( $rubric_id ) );
		}
		if ( ! $rub_max && ! empty( $criteria ) ) {
			foreach ( (array) $criteria as $c ) {
				$c = is_array( $c ) ? $c : array();
				$rub_max += absint( $c['max_points'] ?? 0 );
			}
		}
		$saved_scores = isset( $context['rubric_scores'] ) && is_array( $context['rubric_scores'] ) ? $context['rubric_scores'] : array();

		ob_start();
		?>
		<?php if ( ! empty( $criteria ) ) : ?>
			<div class="clms-sg-field">
				<label style="font-weight:700;display:block;margin-bottom:6px">
					<?php echo esc_html__( 'Rúbrica', 'atora-lms' ); ?>: <?php echo esc_html( $rubric_title ? $rubric_title : ( $rubric_id ? get_the_title( $rubric_id ) : '' ) ); ?>
					<?php if ( $has_snapshot ) : ?>
						<span class="description" style="font-weight:400;margin-left:6px">(<?php echo esc_html__( 'versión guardada', 'atora-lms' ); ?>)</span>
					<?php endif; ?>
				</label>
				<style>
				.clms-sg-rubric-criterion{border:1px solid rgba(255,255,255,.15);border-radius:8px;margin-bottom:12px;overflow:hidden}
				.clms-sg-rubric-criterion-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 12px;background:rgba(255,255,255,.06)}
				.clms-sg-rubric-criterion-info{flex:1;min-width:0}
				.clms-sg-rubric-criterion-info strong{display:block;font-size:13px}
				.clms-sg-rubric-criterion-info p{margin:3px 0 0;font-size:11px;opacity:.7;line-height:1.4}
				.clms-sg-rubric-score-box{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:72px}
				.clms-sg-rubric-score-box input[type="number"]{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:inherit;border-radius:6px;padding:4px 6px;width:60px;text-align:center;font-size:14px;font-weight:700}
				.clms-sg-rubric-score-error{font-size:10px;line-height:1.2;opacity:.9;color:#ffb4b4;text-align:center;min-height:12px}
				.clms-sg-rubric-score-label{font-size:10px;opacity:.6;white-space:nowrap}
				.clms-sg-rubric-levels{display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px;background:rgba(255,255,255,.03)}
				.clms-sg-rubric-level-btn{border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.07);color:inherit;border-radius:6px;padding:6px 10px;cursor:pointer;font-size:12px;text-align:left;transition:all .15s;max-width:180px;word-break:break-word}
				.clms-sg-rubric-level-btn:hover{background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.4)}
				.clms-sg-rubric-level-btn.is-active{background:rgba(99,179,237,.25);border-color:#63b3ed;color:#fff}
				.clms-sg-rubric-level-btn .lvl-label{font-weight:600;display:block}
				.clms-sg-rubric-level-btn .lvl-pts{font-size:11px;opacity:.75;display:block;margin-top:1px}
				.clms-sg-rubric-level-btn .lvl-desc{font-size:10px;opacity:.6;display:block;margin-top:3px;line-height:1.35}
				.clms-sg-rubric-feedback{padding:6px 12px 10px}
				.clms-sg-rubric-feedback input[type="text"]{width:100%;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:inherit;border-radius:6px;padding:5px 8px;font-size:12px;box-sizing:border-box}
				.clms-sg-rubric-total-bar{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-top:1px solid rgba(255,255,255,.1);font-size:13px;font-weight:700}
				.clms-sg-rubric-total-val{font-size:16px}
				.clms-sg-rubric-progress{height:4px;background:rgba(255,255,255,.12);border-radius:2px;margin:0 12px 10px;overflow:hidden}
				.clms-sg-rubric-progress-fill{height:100%;border-radius:2px;background:#63b3ed;transition:width .3s}
				</style>

				<div id="clms-sg-rubric-wrap">
					<?php foreach ( $criteria as $ri => $criterion ) :
						$criterion = is_array( $criterion ) ? $criterion : array();
						$c_name    = (string) ( $criterion['name'] ?? '' );
						$c_desc    = (string) ( $criterion['description'] ?? '' );
						$c_comp    = (string) ( $criterion['competency'] ?? '' );
						$c_tip     = (string) ( $criterion['improvement_tip'] ?? '' );
						$c_max     = isset( $criterion['max_points'] ) ? absint( $criterion['max_points'] ) : 0;
						$c_levels  = isset( $criterion['levels'] ) && is_array( $criterion['levels'] ) ? $criterion['levels'] : array();
						$c_score   = isset( $saved_scores[ $ri ]['score'] ) && '' !== (string) $saved_scores[ $ri ]['score'] ? absint( $saved_scores[ $ri ]['score'] ) : '';
						$c_fb      = isset( $saved_scores[ $ri ]['feedback'] ) ? (string) $saved_scores[ $ri ]['feedback'] : '';

						$saved_level_index = -1;
						if ( '' !== $c_score && ! empty( $c_levels ) ) {
							foreach ( $c_levels as $li => $lv ) {
								if ( absint( $lv['points'] ?? 0 ) === (int) $c_score ) {
									$saved_level_index = $li;
									break;
								}
							}
						}
					?>
						<div class="clms-sg-rubric-criterion">
						<div class="clms-sg-rubric-criterion-header">
							<div class="clms-sg-rubric-criterion-info">
								<strong><?php echo esc_html( $c_name ); ?></strong>
								<?php if ( $c_desc ) : ?>
									<p><?php echo esc_html( $c_desc ); ?></p>
								<?php endif; ?>
								<?php if ( $c_comp ) : ?>
									<p><em><?php esc_html_e( 'Competencia:', 'atora-lms' ); ?></em> <?php echo esc_html( $c_comp ); ?></p>
								<?php endif; ?>
								<?php if ( $c_tip ) : ?>
									<p><em><?php esc_html_e( 'Mejora sugerida:', 'atora-lms' ); ?></em> <?php echo esc_html( $c_tip ); ?></p>
								<?php endif; ?>
							</div>
							<div class="clms-sg-rubric-score-box">
								<?php
								$bands = self::build_level_bands( $c_levels, $c_max );
								$bands_json = wp_json_encode( $bands );
								$bands_title = self::levels_to_tooltip( $c_levels, $c_max );
								?>
								<input
									type="number" min="0" max="<?php echo esc_attr( $c_max ); ?>" step="0.01"
									name="rubric_scores[<?php echo esc_attr( $ri ); ?>]"
									value="<?php echo esc_attr( '' !== $c_score ? $c_score : '' ); ?>"
									class="clms-sg-rubric-score"
									data-max="<?php echo esc_attr( $c_max ); ?>"
									data-criterion="<?php echo esc_attr( $ri ); ?>"
									data-bands="<?php echo esc_attr( (string) $bands_json ); ?>"
									title="<?php echo esc_attr( $bands_title ); ?>"
								>
								<div class="clms-sg-rubric-score-error" aria-live="polite" data-criterion="<?php echo esc_attr( $ri ); ?>"></div>
								<div class="clms-sg-rubric-score-label" data-criterion="<?php echo esc_attr( $ri ); ?>"></div>
								<span class="clms-sg-rubric-score-label"><?php echo esc_html( "/ $c_max pts" ); ?></span>
							</div>
						</div>

						<?php if ( ! empty( $c_levels ) ) : ?>
						<div class="clms-sg-rubric-levels" data-criterion="<?php echo esc_attr( $ri ); ?>">
							<?php foreach ( $c_levels as $li => $level ) :
								$lv_label = (string) ( $level['label'] ?? '' );
								$lv_pts   = absint( $level['points'] ?? 0 );
								$lv_desc  = (string) ( $level['descriptor'] ?? '' );
								$is_active = ( $saved_level_index === $li ) ? ' is-active' : '';
							?>
							<button
								type="button"
								class="clms-sg-rubric-level-btn<?php echo esc_attr( $is_active ); ?>"
								data-points="<?php echo esc_attr( $lv_pts ); ?>"
								data-criterion="<?php echo esc_attr( $ri ); ?>"
								title="<?php echo esc_attr( $lv_desc ); ?>"
							>
								<span class="lvl-label"><?php echo esc_html( $lv_label ); ?></span>
								<span class="lvl-pts"><?php echo esc_html( $lv_pts . ' pts' ); ?></span>
								<?php if ( $lv_desc ) : ?>
									<span class="lvl-desc"><?php echo esc_html( $lv_desc ); ?></span>
								<?php endif; ?>
							</button>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

						<div class="clms-sg-rubric-feedback">
							<input type="text"
								name="rubric_feedback[<?php echo esc_attr( $ri ); ?>]"
								value="<?php echo esc_attr( $c_fb ); ?>"
								placeholder="<?php echo esc_attr__( 'Observación sobre este criterio...', 'atora-lms' ); ?>"
							>
						</div>
					</div>
					<?php endforeach; ?>
				</div>

				<div class="clms-sg-rubric-progress"><div class="clms-sg-rubric-progress-fill" id="clms-sg-rubric-progress-fill" style="width:0%"></div></div>
				<div class="clms-sg-rubric-total-bar">
					<span><?php esc_html_e( 'Total obtenido:', 'atora-lms' ); ?></span>
					<span class="clms-sg-rubric-total-val" id="clms-sg-rubric-total"><?php esc_html_e( '—', 'atora-lms' ); ?></span>
				</div>
				<p style="font-size:11px;opacity:.6;margin:4px 0 0 12px"><?php esc_html_e( 'Haz clic en un nivel para asignar puntos. Puedes editar el número directamente. La nota final la pones tú. El porcentaje de la rúbrica es una referencia, no se copia solo.', 'atora-lms' ); ?></p>
			</div>
			<script>
			(function(){
				var inputs   = document.querySelectorAll('.clms-sg-rubric-score');
				var totalEl  = document.getElementById('clms-sg-rubric-total');
				var fillEl   = document.getElementById('clms-sg-rubric-progress-fill');
				var gradeF   = document.getElementById('clms_sg_grade');
				var rubricPctEl = document.getElementById('clms_sg_rubric_pct_ref');
				var usePctBtn   = document.getElementById('clms_sg_use_rubric_pct');
				var rubMax   = <?php echo esc_js( (string) absint( $rub_max ) ); ?>;
				var emptyLabel = <?php echo wp_json_encode( __( '—', 'atora-lms' ) ); ?>;
				var precision = 2;
				var decimalSep = (document.documentElement && String(document.documentElement.lang||'').toLowerCase().indexOf('es')===0) ? ',' : '.';

				function roundTo(val, d){
					var p = Math.pow(10, d);
					return Math.round(val * p) / p;
				}

				function formatNumber(val){
					if (!isFinite(val)) return '';
					var s = String(val);
					if (decimalSep === ',') s = s.replace('.', ',');
					return s;
				}

				function getErrorEl(ci){
					return document.querySelector('.clms-sg-rubric-score-error[data-criterion="'+ci+'"]');
				}

				function getHintEl(ci){
					return document.querySelector('.clms-sg-rubric-score-label[data-criterion="'+ci+'"]');
				}

				function validateInput(inp){
					var raw = (inp.value || '').trim();
					var max = parseFloat(inp.getAttribute('data-max')) || 0;
					var ci  = inp.getAttribute('data-criterion');
					var errEl = getErrorEl(ci);
					inp.setCustomValidity('');
					if (errEl) errEl.textContent = '';
					if (raw === '') return { ok:true, value:null, max:max };

					var normalized = raw.replace(',', '.');
					var v = parseFloat(normalized);
					if (!isFinite(v)) {
						var msg = <?php echo wp_json_encode( __( 'Debe ser un número.', 'atora-lms' ) ); ?>;
						inp.setCustomValidity(msg);
						if (errEl) errEl.textContent = msg;
						return { ok:false, value:null, max:max };
					}

					var parts = normalized.split('.');
					if (parts.length > 1 && parts[1].length > precision) {
						var msg2 = <?php echo wp_json_encode( __( 'Máximo 2 decimales.', 'atora-lms' ) ); ?>;
						inp.setCustomValidity(msg2);
						if (errEl) errEl.textContent = msg2;
						return { ok:false, value:null, max:max };
					}

					if (v < 0 || v > max) {
						var msg3 = <?php echo wp_json_encode( __( 'Fuera de rango.', 'atora-lms' ) ); ?>;
						inp.setCustomValidity(msg3);
						if (errEl) errEl.textContent = msg3 + ' (0–' + max + ')';
						return { ok:false, value:null, max:max };
					}

					v = roundTo(v, precision);
					return { ok:true, value:v, max:max };
				}

				function parseBands(inp){
					try {
						var raw = inp.getAttribute('data-bands');
						if (!raw) return [];
						var parsed = JSON.parse(raw);
						return Array.isArray(parsed) ? parsed : [];
					} catch(e) { return []; }
				}

				function recalc(){
					var earned = 0;
					var filled = 0;
					var invalid = false;
					var sawComma = false;

					inputs.forEach(function(inp){
						if ((inp.value || '').indexOf(',') !== -1) sawComma = true;
						var r = validateInput(inp);
						if (!r.ok) { invalid = true; return; }
						if (r.value === null) return;
						filled++;
						earned += r.value;
					});

					if (sawComma) decimalSep = ',';
					var missing = inputs.length - filled;
					if (filled > 0 && rubMax > 0 && !invalid) {
						var pct = Math.round((earned/rubMax)*100);
						var tail = missing > 0 ? (' · ' + (<?php echo wp_json_encode( __( 'faltan', 'atora-lms' ) ); ?>) + ' ' + missing) : '';
						totalEl.textContent = formatNumber(roundTo(earned, precision)) + '/' + rubMax + ' (' + pct + '%)' + tail;
						if (fillEl) fillEl.style.width = Math.max(0, Math.min(100, pct)) + '%';
						if (rubricPctEl) rubricPctEl.textContent = pct + '%';
						if (usePctBtn) usePctBtn.disabled = false;
					} else if (invalid) {
						totalEl.textContent = <?php echo wp_json_encode( __( 'Revisa los valores fuera de rango.', 'atora-lms' ) ); ?>;
						if (fillEl) fillEl.style.width = '0%';
						if (rubricPctEl) rubricPctEl.textContent = emptyLabel || '—';
						if (usePctBtn) usePctBtn.disabled = true;
					} else {
						totalEl.textContent = emptyLabel || '—';
						if (fillEl) fillEl.style.width = '0%';
						if (rubricPctEl) rubricPctEl.textContent = emptyLabel || '—';
						if (usePctBtn) usePctBtn.disabled = true;
					}
				}

				document.querySelectorAll('.clms-sg-rubric-level-btn').forEach(function(btn){
					btn.addEventListener('click', function(){
						var ci     = btn.getAttribute('data-criterion');
						var pts    = parseFloat(btn.getAttribute('data-points'))||0;
						var inp    = document.querySelector('.clms-sg-rubric-score[data-criterion="'+ci+'"]');
						if(!inp) return;
						inp.value = String(pts);
						document.querySelectorAll('.clms-sg-rubric-levels[data-criterion="'+ci+'"] .clms-sg-rubric-level-btn').forEach(function(b){ b.classList.remove('is-active'); });
						btn.classList.add('is-active');
						recalc();
					});
				});

				function syncActiveLevelFromValue(ci, inp, v){
					var buttons = Array.prototype.slice.call(document.querySelectorAll('.clms-sg-rubric-levels[data-criterion="'+ci+'"] .clms-sg-rubric-level-btn'));
					var hintEl = getHintEl(ci);
					if (hintEl) hintEl.textContent = '';
					if (!buttons.length) return;
					buttons.forEach(function(b){ b.classList.remove('is-active'); });
					if (!isFinite(v)) return;

					var bands = parseBands(inp);
					if (!bands.length) return;

					var activeLabel = '';
					var activePts = null;
					var between = '';
					var below = '';

					bands.forEach(function(b){
						if (v >= b.min && v <= b.max) {
							activeLabel = b.label || '';
							activePts = b.active_points !== undefined ? b.active_points : null;
							between = b.between || '';
							below = b.below || '';
						}
					});

					// Resaltar por puntos del nivel (umbral).
					if (activePts !== null) {
						for (var i=0;i<buttons.length;i++){
							var pts = parseFloat(buttons[i].getAttribute('data-points'))||0;
							if (pts === activePts) { buttons[i].classList.add('is-active'); break; }
						}
					}

					if (hintEl) {
						if (below) hintEl.textContent = below;
						else if (between) hintEl.textContent = between;
						else hintEl.textContent = '';
					}
				}

				inputs.forEach(function(inp){
					inp.addEventListener('input', function(){
						var ci  = inp.getAttribute('data-criterion');
						var val = parseFloat((inp.value || '').replace(',', '.'));
						if (inp.value.trim() === '') { val = NaN; }
						syncActiveLevelFromValue(ci, inp, val);
						recalc();
					});
				});

				if (usePctBtn && gradeF) {
					usePctBtn.addEventListener('click', function(){
						var txt = rubricPctEl ? (rubricPctEl.textContent || '').trim() : '';
						if (!txt || txt === emptyLabel) return;
						var pct = parseInt(txt, 10);
						if (!isFinite(pct)) return;
						gradeF.value = String(pct);
					});
				}
				recalc();
			})();
			</script>
		<?php elseif ( ! empty( $context['evidence']['is_required_for_certificate'] ) ) : ?>
			<div class="clms-sg-field">
				<p class="clms-sg-copy"><?php esc_html_e( 'Esta evidencia es obligatoria para certificar y aún no tiene rúbrica asociada. Usa feedback claro por criterios para mantener trazabilidad académica.', 'atora-lms' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * @param array $levels
	 * @param int   $max_points
	 * @return array<int,array{min:float,max:float,label:string,active_points:float,between:string,below:string}>
	 */
	public static function build_level_bands( $levels, $max_points ) {
		$levels = is_array( $levels ) ? array_values( $levels ) : array();
		$max_points = absint( $max_points );
		if ( empty( $levels ) || $max_points <= 0 ) {
			return array();
		}

		$ordered = array();
		foreach ( $levels as $idx => $lv ) {
			$lv = is_array( $lv ) ? $lv : array();
			$ordered[] = array(
				'i'     => (int) $idx,
				'label' => sanitize_text_field( (string) ( $lv['label'] ?? '' ) ),
				'pts'   => (float) absint( $lv['points'] ?? 0 ),
			);
		}
		usort( $ordered, static function( $a, $b ) {
			if ( (float) $a['pts'] === (float) $b['pts'] ) {
				return (int) $a['i'] <=> (int) $b['i'];
			}
			return (float) $a['pts'] <=> (float) $b['pts'];
		} );

		// Niveles únicos por puntos, preservando el primero del schema.
		$unique = array();
		foreach ( $ordered as $row ) {
			$key = (string) $row['pts'];
			if ( isset( $unique[ $key ] ) ) {
				continue;
			}
			$unique[ $key ] = $row;
		}
		$unique = array_values( $unique );
		if ( empty( $unique ) ) {
			return array();
		}

		$eps = 0.0001;
		$bands = array();
		$first = $unique[0];

		if ( $first['pts'] > 0 ) {
			$bands[] = array(
				'min' => 0.0,
				'max' => max( 0.0, (float) $first['pts'] - $eps ),
				'label' => '',
				'active_points' => null,
				'between' => '',
				'below' => sprintf( __( 'por debajo de %s', 'atora-lms' ), $first['label'] ?: __( 'el primer nivel', 'atora-lms' ) ),
			);
		}

		// Exact match del primer nivel.
		$bands[] = array(
			'min' => (float) $first['pts'],
			'max' => (float) $first['pts'],
			'label' => (string) $first['label'],
			'active_points' => (float) $first['pts'],
			'between' => '',
			'below' => '',
		);

		for ( $j = 1; $j < count( $unique ); $j++ ) {
			$prev = $unique[ $j - 1 ];
			$cur  = $unique[ $j ];
			$prev_pts = (float) $prev['pts'];
			$cur_pts  = (float) $cur['pts'];

			// Entre niveles: resalta el de abajo.
			if ( $cur_pts - $prev_pts > $eps ) {
				$bands[] = array(
					'min' => $prev_pts + $eps,
					'max' => $cur_pts - $eps,
					'label' => (string) $prev['label'],
					'active_points' => (float) $prev_pts,
					'between' => sprintf(
						/* translators: 1: lower label, 2: upper label */
						__( 'entre %1$s y %2$s', 'atora-lms' ),
						$prev['label'] ?: __( 'nivel anterior', 'atora-lms' ),
						$cur['label'] ?: __( 'nivel siguiente', 'atora-lms' )
					),
					'below' => '',
				);
			}

			// Exact match del nivel actual.
			$bands[] = array(
				'min' => $cur_pts,
				'max' => $cur_pts,
				'label' => (string) $cur['label'],
				'active_points' => (float) $cur_pts,
				'between' => '',
				'below' => '',
			);
		}

		// Clamp de banda máxima.
		foreach ( $bands as &$b ) {
			$b['min'] = max( 0.0, (float) $b['min'] );
			$b['max'] = min( (float) $max_points, (float) $b['max'] );
		}
		unset( $b );

		return $bands;
	}

	/**
	 * Tooltip por criterio, derivado de niveles (ceil/rangos), como referencia.
	 *
	 * @param array $levels
	 * @param int   $max_points
	 * @return string
	 */
	public static function levels_to_tooltip( $levels, $max_points ): string {
		$levels = is_array( $levels ) ? array_values( $levels ) : array();
		$max_points = absint( $max_points );
		if ( empty( $levels ) || $max_points <= 0 ) {
			return '';
		}

		$ordered = array();
		foreach ( $levels as $idx => $lv ) {
			$lv = is_array( $lv ) ? $lv : array();
			$ordered[] = array(
				'i'     => (int) $idx,
				'label' => sanitize_text_field( (string) ( $lv['label'] ?? '' ) ),
				'pts'   => absint( $lv['points'] ?? 0 ),
			);
		}
		usort( $ordered, static function( $a, $b ) {
			if ( (int) $a['pts'] === (int) $b['pts'] ) {
				return (int) $a['i'] <=> (int) $b['i'];
			}
			return (int) $a['pts'] <=> (int) $b['pts'];
		} );

		// Niveles únicos por puntos, preservando el primero del schema.
		$unique = array();
		foreach ( $ordered as $row ) {
			$key = (string) $row['pts'];
			if ( isset( $unique[ $key ] ) ) {
				continue;
			}
			$unique[ $key ] = $row;
		}
		$unique = array_values( $unique );
		if ( empty( $unique ) ) {
			return '';
		}

		$out = array();
		$prev_pts = 0;
		foreach ( $unique as $j => $row ) {
			$pts = absint( $row['pts'] ?? 0 );
			$lab = (string) ( $row['label'] ?? '' );
			if ( 0 === $j ) {
				$out[] = '≤' . $pts . ' ' . $lab;
			} else {
				$out[] = ( $prev_pts + 1 ) . '–' . $pts . ' ' . $lab;
			}
			$prev_pts = $pts;
		}

		// Si el último nivel no llega al máximo, extenderlo.
		$last = $unique[ count( $unique ) - 1 ];
		$last_pts = absint( $last['pts'] ?? 0 );
		$last_lab = (string) ( $last['label'] ?? '' );
		if ( $last_pts < $max_points ) {
			$out[] = ( $last_pts + 1 ) . '–' . $max_points . ' ' . $last_lab;
		}

		return implode( ' · ', array_filter( $out ) );
	}
}
