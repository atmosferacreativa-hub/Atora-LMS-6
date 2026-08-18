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
		$criteria     = ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) ? CLMS_Rubric::get_criteria( $rubric_id ) : array();
		$saved_scores = isset( $context['rubric_scores'] ) && is_array( $context['rubric_scores'] ) ? $context['rubric_scores'] : array();

		ob_start();
		?>
		<?php if ( ! empty( $criteria ) ) : ?>
			<div class="clms-sg-field">
				<label style="font-weight:700;display:block;margin-bottom:6px">
					<?php echo esc_html__( 'Rúbrica', 'atora-lms' ); ?>: <?php echo esc_html( get_the_title( $rubric_id ) ); ?>
				</label>
				<style>
				.clms-sg-rubric-criterion{border:1px solid rgba(255,255,255,.15);border-radius:8px;margin-bottom:12px;overflow:hidden}
				.clms-sg-rubric-criterion-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 12px;background:rgba(255,255,255,.06)}
				.clms-sg-rubric-criterion-info{flex:1;min-width:0}
				.clms-sg-rubric-criterion-info strong{display:block;font-size:13px}
				.clms-sg-rubric-criterion-info p{margin:3px 0 0;font-size:11px;opacity:.7;line-height:1.4}
				.clms-sg-rubric-score-box{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:72px}
				.clms-sg-rubric-score-box input[type="number"]{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:inherit;border-radius:6px;padding:4px 6px;width:60px;text-align:center;font-size:14px;font-weight:700}
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
								<input
									type="number" min="0" max="<?php echo esc_attr( $c_max ); ?>" step="1"
									name="rubric_scores[<?php echo esc_attr( $ri ); ?>]"
									value="<?php echo esc_attr( '' !== $c_score ? $c_score : '' ); ?>"
									class="clms-sg-rubric-score"
									data-max="<?php echo esc_attr( $c_max ); ?>"
									data-criterion="<?php echo esc_attr( $ri ); ?>"
								>
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
				<p style="font-size:11px;opacity:.6;margin:4px 0 0 12px"><?php esc_html_e( 'Haz clic en un nivel para asignar puntos. Puedes editar el número directamente. La nota final se calcula automáticamente.', 'atora-lms' ); ?></p>
			</div>
			<script>
			(function(){
				var inputs   = document.querySelectorAll('.clms-sg-rubric-score');
				var totalEl  = document.getElementById('clms-sg-rubric-total');
				var fillEl   = document.getElementById('clms-sg-rubric-progress-fill');
				var gradeF   = document.getElementById('clms_sg_grade');
				var rubMax   = <?php echo esc_js( (string) CLMS_Rubric::get_total_points( $rubric_id ) ); ?>;
				var emptyLabel = <?php echo wp_json_encode( __( '—', 'atora-lms' ) ); ?>;
				var userOverride = gradeF && gradeF.value.trim() !== '';

				function recalc(){
					var earned = 0, allFilled = true;
					inputs.forEach(function(inp){
						if(inp.value.trim()===''){allFilled=false;return;}
						var max = parseInt(inp.getAttribute('data-max'),10)||0;
						earned += Math.min(max, Math.max(0,parseInt(inp.value,10)||0));
					});
					if(allFilled && rubMax > 0){
						var pct = Math.round((earned/rubMax)*100);
						totalEl.textContent = earned+'/'+rubMax+' ('+pct+'%)';
						if(fillEl) fillEl.style.width = pct+'%';
						if(!userOverride && gradeF) gradeF.value = pct;
					} else {
						totalEl.textContent = emptyLabel || '—';
						if(fillEl) fillEl.style.width = '0%';
					}
				}

				document.querySelectorAll('.clms-sg-rubric-level-btn').forEach(function(btn){
					btn.addEventListener('click', function(){
						var ci     = btn.getAttribute('data-criterion');
						var pts    = parseInt(btn.getAttribute('data-points'),10)||0;
						var inp    = document.querySelector('.clms-sg-rubric-score[data-criterion="'+ci+'"]');
						if(!inp) return;
						inp.value = pts;
						document.querySelectorAll('.clms-sg-rubric-levels[data-criterion="'+ci+'"] .clms-sg-rubric-level-btn').forEach(function(b){ b.classList.remove('is-active'); });
						btn.classList.add('is-active');
						recalc();
					});
				});

				inputs.forEach(function(inp){
					inp.addEventListener('input', function(){
						var ci  = inp.getAttribute('data-criterion');
						var val = parseInt(inp.value,10);
						document.querySelectorAll('.clms-sg-rubric-levels[data-criterion="'+ci+'"] .clms-sg-rubric-level-btn').forEach(function(btn){
							btn.classList.toggle('is-active', parseInt(btn.getAttribute('data-points'),10)===val);
						});
						recalc();
					});
				});

				if(gradeF) gradeF.addEventListener('input', function(){ userOverride = gradeF.value.trim()!==''; });
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
}
