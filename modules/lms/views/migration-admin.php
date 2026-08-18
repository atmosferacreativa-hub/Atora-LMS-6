<?php
/**
 * Página admin: Migración LMS a tablas propias — Fase V S16 / F1.7
 *
 * @package ATORA_LMS\LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
}

global $wpdb;

$has_table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'atora_courses' ) ) === $wpdb->prefix . 'atora_courses';

$status = array();
if ( $has_table && ATORA_LMS_Migration_Admin::ensure_migrator_loaded() ) {
	$status = \ATORA\LMS\LMS_Migrator::get_status();
}

$cpt_courses      = (int) ( $status['cpt_courses']      ?? 0 );
$cpt_lessons      = (int) ( $status['cpt_lessons']      ?? 0 );
$migrated_courses = (int) ( $status['migrated_courses'] ?? 0 );
$migrated_lessons = (int) ( $status['migrated_lessons'] ?? 0 );
$migrated_enroll  = (int) ( $status['migrated_enroll']  ?? 0 );
$pct_courses      = (float) ( $status['courses_pct']    ?? 0 );
$pct_lessons      = (float) ( $status['lessons_pct']    ?? 0 );
$reconcile        = (array) ( $status['reconcile']      ?? array() );
$pending_total    = (int) ( $status['pending_total']    ?? 0 );
$is_complete      = (bool) ( $status['is_complete']     ?? false );

$cron_enabled = (bool) get_option( ATORA_LMS_Migration_Admin::OPT_CRON_ENABLED, false );
$cron_batch   = max( 5, min( 100, absint( get_option( ATORA_LMS_Migration_Admin::OPT_CRON_BATCH, 30 ) ) ) );
$cron_next    = wp_next_scheduled( ATORA_LMS_Migration_Admin::CRON_HOOK );

// F2: dualwrite + último resultado de reconcile diaria (F2.4).
$dualwrite_enabled   = (bool) get_option( 'atora_lms_dualwrite', false );
$reconcile_daily     = (array) get_option( ATORA_LMS_Migration_Admin::OPT_RECONCILE_RESULT, array() );
$reconcile_daily_total = (int) ( $reconcile_daily['total'] ?? -1 );  // -1 = nunca ejecutado.
$reconcile_daily_at    = (string) ( $reconcile_daily['checked_at'] ?? '' );

$nonce = wp_create_nonce( 'atora_lms_migration' );

// Etiquetas legibles para cada métrica de reconcile() (F1.0/F1.7).
$reconcile_labels = array(
	'orphan_courses_legacy_to_table'  => __( 'Cursos CPT sin fila en tabla', 'atora-lms' ),
	'orphan_lessons_legacy_to_table'  => __( 'Lecciones CPT sin fila en tabla', 'atora-lms' ),
	'orphan_courses_table_to_legacy'  => __( 'Filas de curso sin CPT', 'atora-lms' ),
	'orphan_lessons_table_to_legacy'  => __( 'Filas de lección sin CPT', 'atora-lms' ),
	'enrollments_missing_in_table'    => __( 'Matrículas de curso sin fila en tabla', 'atora-lms' ),
	'enrollments_missing_in_usermeta' => __( 'Filas de matrícula sin usermeta', 'atora-lms' ),
	'enrollments_blocked_by_course'   => __( 'Matrículas bloqueadas (curso no migrado)', 'atora-lms' ),
	'users_progress_not_migrated'     => __( 'Usuarios con progreso de lección sin migrar', 'atora-lms' ),
	'orphan_programs_legacy'          => __( 'Programas CPT sin fila en tabla', 'atora-lms' ),
	'program_enrollments_missing'     => __( 'Matrículas de programa sin fila en tabla', 'atora-lms' ),
	'gradebook_missing'               => __( 'Calificaciones finales sin migrar', 'atora-lms' ),
	'certificates_missing'            => __( 'Certificados emitidos sin migrar', 'atora-lms' ),
);
?>
<div class="wrap" id="atora-migration-wrap" style="font-family:sans-serif;max-width:860px">

	<h1 style="font-size:22px;font-weight:700;color:#0f172a;margin-bottom:4px">
		🚀 <?php esc_html_e( 'Migración LMS — Tablas propias', 'atora-lms' ); ?>
	</h1>
	<p style="color:#64748b;margin-top:0;font-size:13px">
		<?php esc_html_e( 'Migra cursos, lecciones, matrículas y progreso de CPTs/usermeta a las tablas propias de ATORA LMS. La migración es aditiva, no destruye datos y es repetible sin duplicar.', 'atora-lms' ); ?>
	</p>

	<!-- Estado actual -->
	<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0">

		<div style="background:#f8fafc;border:.5px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center">
			<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:6px"><?php esc_html_e( 'Cursos CPT', 'atora-lms' ); ?></div>
			<div style="font-size:28px;font-weight:800;color:#0f172a"><?php echo esc_html( (string) $cpt_courses ); ?></div>
			<div style="font-size:12px;color:#64748b"><?php esc_html_e( 'publish/draft/private', 'atora-lms' ); ?></div>
		</div>

		<div style="background:#f8fafc;border:.5px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center">
			<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:6px"><?php esc_html_e( 'Cursos migrados', 'atora-lms' ); ?></div>
			<div style="font-size:28px;font-weight:800;color:<?php echo $pct_courses >= 100 ? '#1d9e75' : '#f59e0b'; ?>">
				<?php echo esc_html( (string) $migrated_courses ); ?>
			</div>
			<div style="font-size:12px;color:#64748b"><?php echo esc_html( $pct_courses . '%' ); ?></div>
		</div>

		<div style="background:#f8fafc;border:.5px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center">
			<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:6px"><?php esc_html_e( 'Lecciones migradas', 'atora-lms' ); ?></div>
			<div style="font-size:28px;font-weight:800;color:<?php echo $pct_lessons >= 100 ? '#1d9e75' : '#f59e0b'; ?>">
				<?php echo esc_html( (string) $migrated_lessons ); ?>
			</div>
			<div style="font-size:12px;color:#64748b"><?php echo esc_html( $pct_lessons . '% de ' . $cpt_lessons ); ?></div>
		</div>

		<div style="background:#f8fafc;border:.5px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center">
			<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:6px"><?php esc_html_e( 'Matrículas migradas', 'atora-lms' ); ?></div>
			<div style="font-size:28px;font-weight:800;color:#0f172a"><?php echo esc_html( (string) $migrated_enroll ); ?></div>
			<div style="font-size:12px;color:#64748b"><?php esc_html_e( 'atora_enrollments', 'atora-lms' ); ?></div>
		</div>

	</div>

	<!-- Barra de progreso cursos -->
	<div style="margin-bottom:8px">
		<div style="display:flex;justify-content:space-between;font-size:12px;color:#475569;margin-bottom:4px">
			<span><?php esc_html_e( 'Cursos', 'atora-lms' ); ?></span>
			<span><?php echo esc_html( $migrated_courses . ' / ' . $cpt_courses ); ?></span>
		</div>
		<div style="background:#e2e8f0;border-radius:999px;height:8px">
			<div style="width:<?php echo esc_attr( min( 100, $pct_courses ) . '%' ); ?>;background:<?php echo $pct_courses >= 100 ? '#1d9e75' : '#f59e0b'; ?>;height:8px;border-radius:999px;transition:width .5s"></div>
		</div>
	</div>
	<!-- Barra de progreso lecciones -->
	<div style="margin-bottom:20px">
		<div style="display:flex;justify-content:space-between;font-size:12px;color:#475569;margin-bottom:4px">
			<span><?php esc_html_e( 'Lecciones', 'atora-lms' ); ?></span>
			<span><?php echo esc_html( $migrated_lessons . ' / ' . $cpt_lessons ); ?></span>
		</div>
		<div style="background:#e2e8f0;border-radius:999px;height:8px">
			<div style="width:<?php echo esc_attr( min( 100, $pct_lessons ) . '%' ); ?>;background:<?php echo $pct_lessons >= 100 ? '#1d9e75' : '#f59e0b'; ?>;height:8px;border-radius:999px;transition:width .5s"></div>
		</div>
	</div>

	<?php if ( ! $has_table ) : ?>
	<div style="background:#fee2e2;border:.5px solid #fca5a5;border-radius:10px;padding:14px;margin-bottom:16px;color:#991b1b;font-size:13px">
		⚠️ <?php esc_html_e( 'Las tablas atora_courses / atora_lessons no existen. Activa el plugin primero o ejecuta el instalador desde Ajustes → ATORA.', 'atora-lms' ); ?>
	</div>
	<?php else : ?>

	<!-- Reconciliación (F1.0/F1.7) -->
	<div style="background:#fff;border:.5px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px">
		<h3 style="margin:0 0 4px;font-size:15px;font-weight:700;color:#0f172a"><?php esc_html_e( 'Reconciliación (reconcile())', 'atora-lms' ); ?></h3>
		<p style="color:#64748b;margin:0 0 12px;font-size:12px"><?php esc_html_e( 'Compara legacy (CPT/usermeta) contra las tablas propias sin escribir nada. La fase F1 se considera completa cuando todos los valores llegan a 0.', 'atora-lms' ); ?></p>

		<?php if ( $is_complete ) : ?>
		<div style="background:#d1fae5;border:.5px solid #6ee7b7;border-radius:10px;padding:10px 14px;margin-bottom:12px;color:#065f46;font-size:13px">
			✅ <?php esc_html_e( '0 pendientes. reconcile() está en cero — la migración F1 está completa.', 'atora-lms' ); ?>
		</div>
		<?php else : ?>
		<div style="background:#fef3c7;border:.5px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:12px;color:#92400e;font-size:13px">
			⚠️ <?php echo esc_html( sprintf(
				/* translators: %d: número total de pendientes */
				__( '%d pendientes en total. Ejecuta la migración (o activa el cron de continuación) hasta llegar a 0.', 'atora-lms' ),
				$pending_total
			) ); ?>
		</div>
		<?php endif; ?>

		<table id="atora-reconcile-table" style="width:100%;border-collapse:collapse;font-size:12px">
			<tbody>
			<?php foreach ( $reconcile_labels as $key => $label ) :
				$value = (int) ( $reconcile[ $key ] ?? 0 );
				?>
				<tr data-reconcile-key="<?php echo esc_attr( $key ); ?>" style="border-top:.5px solid #f1f5f9">
					<td style="padding:6px 4px;color:#475569"><?php echo esc_html( $label ); ?></td>
					<td class="reconcile-value" style="padding:6px 4px;text-align:right;font-weight:700;color:<?php echo $value > 0 ? '#b45309' : '#1d9e75'; ?>"><?php echo esc_html( (string) $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<!-- Controles -->
	<div style="background:#fff;border:.5px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px">
		<h3 style="margin:0 0 12px;font-size:15px;font-weight:700;color:#0f172a"><?php esc_html_e( 'Ejecutar migración', 'atora-lms' ); ?></h3>

		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
			<label style="font-size:13px;color:#475569">
				<?php esc_html_e( 'Tamaño de batch:', 'atora-lms' ); ?>
				<select id="atora-migration-batch" style="margin-left:8px;padding:4px 8px;border-radius:6px;border:.5px solid #e2e8f0;font-size:13px">
					<option value="10">10</option>
					<option value="30" selected>30 (recomendado)</option>
					<option value="50">50</option>
				</select>
			</label>
		</div>

		<div style="display:flex;gap:10px;flex-wrap:wrap">
			<button id="btn-migrate" class="button button-primary"
				style="padding:8px 20px;font-size:14px;font-weight:600;border-radius:8px;height:auto"
				<?php echo ! $has_table ? 'disabled' : ''; ?>>
				🚀 <?php esc_html_e( 'Ejecutar hasta completar', 'atora-lms' ); ?>
			</button>
			<button id="btn-refresh" class="button"
				style="padding:8px 16px;font-size:13px;border-radius:8px;height:auto">
				🔄 <?php esc_html_e( 'Refrescar estado', 'atora-lms' ); ?>
			</button>
		</div>
		<p style="color:#94a3b8;margin:8px 0 0;font-size:12px"><?php esc_html_e( 'Corre lotes sucesivos automáticamente (con cursores de continuación persistidos) hasta que reconcile() llegue a 0 pendientes, hasta 50 iteraciones.', 'atora-lms' ); ?></p>

		<!-- Log de salida -->
		<div id="atora-migration-log" style="display:none;margin-top:16px;background:#0f172a;color:#e2e8f0;border-radius:8px;padding:14px;font-family:monospace;font-size:12px;line-height:1.7;max-height:320px;overflow-y:auto;white-space:pre-wrap"></div>
	</div>

	<!-- Cron de continuación (F1.7) -->
	<div style="background:#fff;border:.5px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:16px">
		<h3 style="margin:0 0 4px;font-size:15px;font-weight:700;color:#0f172a"><?php esc_html_e( 'Cron de continuación', 'atora-lms' ); ?></h3>
		<p style="color:#64748b;margin:0 0 12px;font-size:12px">
			<?php echo esc_html( sprintf(
				/* translators: %d: tamaño de batch del cron */
				__( 'Corre un lote (batch=%d) cada 5 minutos vía wp-cron hasta que reconcile() llegue a 0 pendientes; entonces se autodesactiva.', 'atora-lms' ),
				$cron_batch
			) ); ?>
		</p>

		<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#0f172a;cursor:pointer">
			<input type="checkbox" id="atora-cron-toggle" <?php echo $cron_enabled ? 'checked' : ''; ?> <?php echo ! $has_table ? 'disabled' : ''; ?> />
			<span id="atora-cron-status">
				<?php if ( $cron_enabled ) : ?>
					<?php
					echo esc_html__( 'Activo', 'atora-lms' ) . ' — ';
					if ( $cron_next ) {
						echo esc_html( sprintf(
							/* translators: %s: fecha/hora local de la próxima corrida */
							__( 'próxima corrida: %s', 'atora-lms' ),
							get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $cron_next ), 'Y-m-d H:i:s' )
						) );
					} else {
						esc_html_e( 'programando…', 'atora-lms' );
					}
					?>
				<?php else : ?>
					<?php esc_html_e( 'Inactivo', 'atora-lms' ); ?>
				<?php endif; ?>
			</span>
		</label>
	</div>

	<!-- F2: Doble escritura y alerta de reconciliación diaria ──────────────── -->
	<div style="background:#f0f9ff;border:.5px solid #bae6fd;border-radius:10px;padding:16px 20px;margin-bottom:12px">
		<h2 style="font-size:15px;font-weight:600;margin:0 0 10px;color:#0369a1">
			<?php esc_html_e( 'F2 — Doble escritura simétrica', 'atora-lms' ); ?>
		</h2>

		<?php if ( $reconcile_daily_total > 0 ) : ?>
		<div style="background:#fef2f2;border:.5px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:12px;color:#991b1b;font-size:13px">
			⚠️ <?php printf(
				/* translators: %1$d: pendientes, %2$s: fecha */
				esc_html__( 'Reconcile diaria (F2.4): %1$d divergencias detectadas el %2$s. Revisa la tabla de reconciliación arriba.', 'atora-lms' ),
				$reconcile_daily_total,
				esc_html( get_date_from_gmt( $reconcile_daily_at, 'Y-m-d H:i' ) )
			); ?>
		</div>
		<?php elseif ( $reconcile_daily_total === 0 ) : ?>
		<div style="background:#f0fdf4;border:.5px solid #86efac;border-radius:8px;padding:8px 14px;margin-bottom:12px;color:#166534;font-size:13px">
			✅ <?php printf(
				esc_html__( 'Reconcile diaria OK — 0 divergencias el %s.', 'atora-lms' ),
				esc_html( get_date_from_gmt( $reconcile_daily_at, 'Y-m-d H:i' ) )
			); ?>
		</div>
		<?php else : ?>
		<div style="color:#64748b;font-size:12px;margin-bottom:10px">
			<?php esc_html_e( 'Reconcile diaria: todavía no ejecutada (primer check en ~1 hora).', 'atora-lms' ); ?>
		</div>
		<?php endif; ?>

		<label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer">
			<input type="checkbox" id="atora-dualwrite-toggle"
				<?php checked( $dualwrite_enabled ); ?>
				<?php disabled( ! $has_table ); ?> />
			<span>
				<strong><?php esc_html_e( 'Activar doble escritura (atora_lms_dualwrite)', 'atora-lms' ); ?></strong><br>
				<span style="color:#64748b;font-size:11px">
					<?php esc_html_e( 'Cuando está activo, toda escritura en tablas se refleja en usermeta legacy. Activa solo después de que F1 esté completa (reconcile = 0).', 'atora-lms' ); ?>
				</span>
			</span>
		</label>
		<div id="atora-dualwrite-status" style="font-size:11px;color:#64748b;margin-top:4px;margin-left:26px">
			<?php echo $dualwrite_enabled
				? '<span style="color:#16a34a">● ' . esc_html__( 'Activo', 'atora-lms' ) . '</span>'
				: '<span style="color:#94a3b8">○ ' . esc_html__( 'Inactivo', 'atora-lms' ) . '</span>'; ?>
		</div>
	</div>

	<!-- F3: Lectura de paridad (shadow-read) ─────────────────────────────────── -->
	<?php
	$parity_total        = 0;
	$parity_reader_stats = array();
	$parity_volume       = array( 'active_users' => 0, 'observed_users' => 0, 'volume_ok' => false );
	$parity_read_source  = \ATORA\LMS\LMS_Read_Router::source();
	if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
		$parity_total        = \ATORA\LMS\LMS_Parity::total_divergences();
		$parity_reader_stats = \ATORA\LMS\LMS_Parity::get_reader_stats();
		$parity_volume       = \ATORA\LMS\LMS_Parity::get_volume_stats();
	}
	$gate_ok = ( 0 === $parity_total && $parity_volume['volume_ok'] );
	?>
	<div style="background:<?php echo $gate_ok ? '#f0fdf4' : '#fef2f2'; ?>;border:.5px solid <?php echo $gate_ok ? '#86efac' : '#fca5a5'; ?>;border-radius:10px;padding:16px 20px;margin-bottom:12px">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px">
			<h2 style="font-size:15px;font-weight:600;margin:0;color:<?php echo $gate_ok ? '#166534' : '#991b1b'; ?>">
				F3 — Lectura de paridad (shadow-read)
			</h2>
			<div style="display:flex;gap:8px;align-items:center">
				<span style="font-size:11px;background:<?php echo 'legacy' === $parity_read_source ? '#dbeafe' : '#dcfce7'; ?>;color:<?php echo 'legacy' === $parity_read_source ? '#1e40af' : '#166534'; ?>;padding:2px 8px;border-radius:20px;font-weight:600">
					Fuente: <?php echo esc_html( $parity_read_source ); ?>
				</span>
				<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=atora_lms_parity_export&nonce=' . $nonce ) ); ?>"
				   style="font-size:11px;color:#1d4ed8;text-decoration:none">⬇ Exportar CSV</a>
			</div>
		</div>

		<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
			<?php foreach ( \ATORA\LMS\LMS_Parity::READERS as $reader_key => $reader_label ) :
				$rs    = $parity_reader_stats[ $reader_key ] ?? array( 'count' => 0, 'last_at' => '' );
				$is_ok = 0 === $rs['count'];
				?>
			<div style="background:#fff;border:.5px solid <?php echo $is_ok ? '#86efac' : '#fca5a5'; ?>;border-radius:8px;padding:10px;text-align:center">
				<div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:4px"><?php echo esc_html( $reader_label ); ?></div>
				<div style="font-size:24px;font-weight:800;color:<?php echo $is_ok ? '#16a34a' : '#b91c1c'; ?>"><?php echo esc_html( (string) $rs['count'] ); ?></div>
				<div style="font-size:10px;color:#94a3b8"><?php echo $rs['last_at'] ? esc_html( get_date_from_gmt( $rs['last_at'], 'd/m H:i' ) ) : '—'; ?></div>
			</div>
			<?php endforeach; ?>
		</div>

		<!-- Volumen observado (gate D-006: ≥1 lectura por alumno activo) -->
		<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;font-size:12px">
			<?php
			$vol_pct     = $parity_volume['active_users'] > 0
				? min( 100, (int) round( $parity_volume['observed_users'] / $parity_volume['active_users'] * 100 ) )
				: 0;
			$vol_color   = $parity_volume['volume_ok'] ? '#16a34a' : '#b45309';
			$vol_bar_clr = $parity_volume['volume_ok'] ? '#86efac' : '#fcd34d';
			?>
			<div style="flex:1">
				<div style="display:flex;justify-content:space-between;margin-bottom:3px">
					<span style="color:#475569;font-weight:600">Volumen observado (D-006)</span>
					<span style="color:<?php echo esc_attr( $vol_color ); ?>;font-weight:700">
						<?php echo esc_html( $parity_volume['observed_users'] ); ?> / <?php echo esc_html( $parity_volume['active_users'] ); ?> alumnos activos
					</span>
				</div>
				<div style="background:#e2e8f0;border-radius:4px;height:6px;overflow:hidden">
					<div style="background:<?php echo esc_attr( $vol_bar_clr ); ?>;height:6px;width:<?php echo esc_attr( $vol_pct ); ?>%;transition:width .3s"></div>
				</div>
				<div style="color:#64748b;margin-top:2px;font-size:10px">
					<?php if ( $parity_volume['volume_ok'] ) : ?>
						✅ Cobertura completa — "0 divergencias" es estadísticamente significativo.
					<?php elseif ( $parity_volume['active_users'] === 0 ) : ?>
						Sin alumnos activos en tablas (ejecuta la migración F1 primero).
					<?php else : ?>
						⚠️ Todavía faltan <?php echo esc_html( $parity_volume['active_users'] - $parity_volume['observed_users'] ); ?> alumnos sin observar. El gate D-006 no puede cerrarse hasta que haya al menos 1 lectura por alumno activo.
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Gate D-006 -->
		<?php if ( $gate_ok ) : ?>
		<div style="font-size:13px;color:#166534;font-weight:600;background:#dcfce7;padding:8px 12px;border-radius:6px">
			✅ Gate D-006: 0 divergencias en los últimos 14 días · Volumen completo. F4 autorizable.
		</div>
		<?php else : ?>
		<div style="font-size:13px;color:#991b1b;font-weight:600">
			<?php if ( $parity_total > 0 ) : ?>
			⚠️ <?php echo esc_html( number_format( $parity_total ) ); ?> divergencias registradas (últimos 14 días). La ventana se reinicia cuando lleguen a 0.
			<?php elseif ( ! $parity_volume['volume_ok'] ) : ?>
			🟡 0 divergencias, pero volumen insuficiente. Esperando tráfico real.
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<div id="atora-parity-recent" style="margin-top:12px;display:none">
			<h3 style="font-size:12px;font-weight:700;color:#475569;margin:0 0 6px">Últimas divergencias</h3>
			<table style="width:100%;font-size:11px;border-collapse:collapse">
				<thead>
					<tr style="background:#f1f5f9;color:#64748b">
						<th style="padding:4px 8px;text-align:left">Lector</th>
						<th style="padding:4px 8px;text-align:left">Usuario</th>
						<th style="padding:4px 8px;text-align:left">Curso WP</th>
						<th style="padding:4px 8px;text-align:left">Legacy</th>
						<th style="padding:4px 8px;text-align:left">Tabla</th>
						<th style="padding:4px 8px;text-align:left">Hora</th>
					</tr>
				</thead>
				<tbody id="atora-parity-tbody"></tbody>
			</table>
		</div>

		<button id="btn-parity-refresh" style="margin-top:10px;font-size:11px;border:none;background:none;color:#1d4ed8;cursor:pointer;padding:0">
			↻ Actualizar panel
		</button>
	</div>

	<!-- F4 — Cutover de lectura -->
	<?php
	$is_tables_mode = 'tables' === $parity_read_source;
	$pc_total       = 0;
	$pc_stable_days = 0;
	if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
		foreach ( $parity_reader_stats as $rk => $rs ) {
			if ( str_starts_with( $rk, 'pc_' ) ) {
				$pc_total += $rs['count'];
			}
		}
		$pc_stable_days = \ATORA\LMS\LMS_Parity::get_postcutover_stable_days();
	}
	$cutover_gate = class_exists( '\ATORA\LMS\LMS_Parity' )
		? \ATORA\LMS\LMS_Parity::cutover_ready()
		: array( 'ready' => false, 'reasons' => array( 'LMS_Parity no disponible.' ) );
	$f4_border = $is_tables_mode ? ( 0 === $pc_total ? '#86efac' : '#fca5a5' ) : '#bae6fd';
	$f4_bg     = $is_tables_mode ? ( 0 === $pc_total ? '#f0fdf4' : '#fef2f2' ) : '#f0f9ff';
	$f4_head   = $is_tables_mode ? ( 0 === $pc_total ? '#166534' : '#991b1b' ) : '#0369a1';
	?>
	<div style="background:<?php echo esc_attr( $f4_bg ); ?>;border:.5px solid <?php echo esc_attr( $f4_border ); ?>;border-radius:10px;padding:16px 20px;margin-bottom:12px">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px">
			<h2 style="font-size:15px;font-weight:600;margin:0;color:<?php echo esc_attr( $f4_head ); ?>">
				F4 — Cutover de lectura<?php echo $is_tables_mode ? ' <span style="font-size:11px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:20px;font-weight:600">ACTIVO — tablas</span>' : ''; ?>
			</h2>
			<?php if ( $is_tables_mode ) : ?>
			<button id="btn-f4-rollback" style="background:#dc2626;color:#fff;border:none;padding:6px 16px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">
				↩ Rollback (tables → legacy)
			</button>
			<?php elseif ( $cutover_gate['ready'] ) : ?>
			<button id="btn-f4-flip" style="background:#16a34a;color:#fff;border:none;padding:6px 16px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">
				🚀 Ejecutar flip (legacy → tables)
			</button>
			<?php else : ?>
			<span style="font-size:11px;color:#64748b;background:#f1f5f9;padding:4px 10px;border-radius:20px">Gate de cutover no superado — flip bloqueado</span>
			<?php endif; ?>
		</div>

		<?php if ( ! $is_tables_mode && ! $cutover_gate['ready'] ) : ?>
		<div style="font-size:11px;color:#991b1b;background:#fef2f2;border:.5px solid #fca5a5;border-radius:6px;padding:8px 12px;margin-bottom:12px">
			<strong>Motivos:</strong>
			<ul style="margin:4px 0 0;padding-left:18px">
				<?php foreach ( $cutover_gate['reasons'] as $reason ) : ?>
				<li><?php echo esc_html( $reason ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( $is_tables_mode ) : ?>
		<!-- Post-cutover monitoring (F4.3) -->
		<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px">
			<?php
			$pc_ok    = 0 === $pc_total;
			$f5_ready = $pc_ok && $pc_stable_days >= 14;
			?>
			<div style="background:#fff;border:.5px solid <?php echo $pc_ok ? '#86efac' : '#fca5a5'; ?>;border-radius:8px;padding:10px;text-align:center">
				<div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:4px">Divergencias PC (14d)</div>
				<div style="font-size:24px;font-weight:800;color:<?php echo $pc_ok ? '#16a34a' : '#b91c1c'; ?>"><?php echo esc_html( (string) $pc_total ); ?></div>
				<div style="font-size:10px;color:#94a3b8"><?php echo $pc_ok ? 'Lectores PC estables' : 'D-007: rollback recomendado'; ?></div>
			</div>
			<div style="background:#fff;border:.5px solid #bae6fd;border-radius:8px;padding:10px;text-align:center">
				<div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:4px">Días estables PC</div>
				<div style="font-size:24px;font-weight:800;color:#0369a1"><?php echo esc_html( (string) $pc_stable_days ); ?></div>
				<div style="font-size:10px;color:#94a3b8">Gate F5: 14 días</div>
			</div>
			<div style="background:#fff;border:.5px solid <?php echo $f5_ready ? '#86efac' : '#e2e8f0'; ?>;border-radius:8px;padding:10px;text-align:center">
				<div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:4px">Gate F5</div>
				<div style="font-size:24px;font-weight:800;color:<?php echo $f5_ready ? '#16a34a' : '#94a3b8'; ?>"><?php echo $f5_ready ? '✅' : '⏳'; ?></div>
				<div style="font-size:10px;color:#94a3b8"><?php echo $f5_ready ? 'F5 autorizable' : ( $pc_stable_days . '/14 días · 0 divergencias PC requeridas' ); ?></div>
			</div>
		</div>
		<div style="font-size:11px;color:#64748b;background:#f8fafc;border-radius:6px;padding:8px 12px">
			D-007: cualquier divergencia PC o incidente confirmado activa el rollback inmediato →
			<code>atora_lms_read_source = 'legacy'</code>. Usa el botón arriba o
			<code>LMS_Read_Router::set_source('legacy')</code>.
		</div>
		<?php else : ?>
		<div style="font-size:12px;color:#475569;line-height:1.6">
			El flip activa <code>atora_lms_read_source = 'tables'</code>: los lectores canónicos devuelven datos de tablas
			y la sombra compara contra legacy (red de detección post-cutover, D-007).<br>
			<strong>Requisito previo:</strong> Gate D-006 verde (panel F3 arriba) + <code>atora_lms_dualwrite</code> activo (rollback instantáneo disponible).
		</div>
		<?php endif; ?>
	</div>

	<!-- Instrucciones manuales -->
	<details style="background:#f8fafc;border:.5px solid #e2e8f0;border-radius:10px;padding:14px">
		<summary style="font-size:13px;font-weight:600;cursor:pointer;color:#1d4ed8"><?php esc_html_e( 'Alternativa: ejecutar via WP-CLI o REST API', 'atora-lms' ); ?></summary>
		<div style="margin-top:12px;font-size:12px;color:#475569">
			<p><strong>WP-CLI (SSH):</strong></p>
			<code style="display:block;background:#1e3a8a;color:#e0f2fe;padding:10px;border-radius:6px;margin:6px 0">
				wp eval-file wp-content/plugins/atora-lms/modules/lms/bin/run-migration.php
			</code>
			<p><strong>REST API (requiere API Key con scope write):</strong></p>
			<code style="display:block;background:#1e3a8a;color:#e0f2fe;padding:10px;border-radius:6px;margin:6px 0">
				POST /wp-json/atora-lms/v1/migration/run<br>
				Authorization: Bearer atora_TUKEY<br>
				{"batch": 30}
			</code>
			<p><strong>Estado + reconciliación:</strong></p>
			<code style="display:block;background:#1e3a8a;color:#e0f2fe;padding:10px;border-radius:6px;margin:6px 0">
				GET /wp-json/atora-lms/v1/migration/status
			</code>
		</div>
	</details>

</div>

<script>
(function(){
'use strict';

var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
var log     = document.getElementById('atora-migration-log');
var btnRun  = document.getElementById('btn-migrate');
var btnRef  = document.getElementById('btn-refresh');
var cronToggle = document.getElementById('atora-cron-toggle');

var MAX_ITER    = 50;
var STALE_LIMIT = 3;

function appendLog(msg, color) {
	log.style.display = 'block';
	var line = document.createElement('span');
	line.style.color = color || '#e2e8f0';
	line.textContent = msg + '\n';
	log.appendChild(line);
	log.scrollTop = log.scrollHeight;
}

function setRunning(running) {
	btnRun.disabled = running || <?php echo $has_table ? 'false' : 'true'; ?>;
	btnRun.textContent = running ? '⏳ Migrando...' : '🚀 Ejecutar hasta completar';
}

function updateReconcileTable(reconcile) {
	if (!reconcile) { return; }
	Object.keys(reconcile).forEach(function(key) {
		var row = document.querySelector('#atora-reconcile-table tr[data-reconcile-key="' + key + '"] .reconcile-value');
		if (!row) { return; }
		var value = reconcile[key] || 0;
		row.textContent = value;
		row.style.color = value > 0 ? '#b45309' : '#1d9e75';
	});
}

function runBatch(batch) {
	var fd = new FormData();
	fd.append('action', 'atora_lms_run_migration');
	fd.append('batch',  batch);
	fd.append('nonce',  nonce);

	return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
		.then(function(r) { return r.json(); });
}

function runLoop(iteration, lastPending, staleCount) {
	var batch = document.getElementById('atora-migration-batch').value;

	if (iteration > MAX_ITER) {
		appendLog('⏹ Límite de ' + MAX_ITER + ' iteraciones alcanzado (pendientes: ' + lastPending + '). Pulsa de nuevo para continuar.', '#fde68a');
		setRunning(false);
		return;
	}

	runBatch(batch).then(function(d) {
		if (!d.success) {
			appendLog('❌ Error: ' + (d.data && d.data.message ? d.data.message : 'Error desconocido.'), '#fca5a5');
			setRunning(false);
			return;
		}

		var r = d.data;
		appendLog('▶ Iteración ' + iteration + ' — pendientes: ' + r.pending_total, '#93c5fd');
		appendLog(
			'   Cursos ' + r.migrated_courses + '/' + r.cpt_courses + ' (' + r.courses_pct + '%) · ' +
			'Lecciones ' + r.migrated_lessons + ' (' + r.lessons_pct + '%) · ' +
			'Matrículas ' + r.migrated_enroll,
			'#c7d2fe'
		);
		if (r.enroll_migrated || r.progress_migrated || r.categories_updated) {
			appendLog(
				'   + nuevas: matrículas ' + r.enroll_migrated + ' · progreso ' + r.progress_migrated + ' · categorías ' + r.categories_updated,
				'#94a3b8'
			);
		}

		updateReconcileTable(r.reconcile);

		if (r.pending_total === 0) {
			appendLog('✅ reconcile() = 0 pendientes. Migración F1 completa.', '#86efac');
			setRunning(false);
			setTimeout(function(){ window.location.reload(); }, 2000);
			return;
		}

		if (r.pending_total === lastPending) {
			staleCount++;
			if (staleCount >= STALE_LIMIT) {
				appendLog('⚠ Sin progreso en ' + STALE_LIMIT + ' iteraciones (pendientes: ' + r.pending_total + '). Puede haber referencias huérfanas permanentes (p.ej. CPTs eliminados referenciados desde usermeta) — revisa la tabla de reconciliación arriba.', '#fde68a');
				setRunning(false);
				setTimeout(function(){ window.location.reload(); }, 2500);
				return;
			}
		} else {
			staleCount = 0;
		}

		setTimeout(function(){ runLoop(iteration + 1, r.pending_total, staleCount); }, 150);
	}).catch(function(err) {
		appendLog('❌ Error de red: ' + err.message, '#fca5a5');
		setRunning(false);
	});
}

btnRun.addEventListener('click', function() {
	if (btnRun.disabled) { return; }
	setRunning(true);
	log.innerHTML = '';
	appendLog('▶ Iniciando migración por lotes (continúa hasta 0 pendientes o ' + MAX_ITER + ' iteraciones)...', '#93c5fd');
	runLoop(1, null, 0);
});

btnRef.addEventListener('click', function() {
	window.location.reload();
});

if (cronToggle) {
	cronToggle.addEventListener('change', function() {
		var enabled = cronToggle.checked;
		var fd = new FormData();
		fd.append('action', 'atora_lms_toggle_migration_cron');
		fd.append('nonce', nonce);
		if (enabled) { fd.append('enabled', '1'); }

		cronToggle.disabled = true;
		fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success) {
					window.location.reload();
				} else {
					cronToggle.checked = !enabled;
					cronToggle.disabled = false;
					appendLog('❌ Error: ' + (d.data && d.data.message ? d.data.message : 'Error desconocido.'), '#fca5a5');
				}
			})
			.catch(function(err) {
				cronToggle.checked = !enabled;
				cronToggle.disabled = false;
				appendLog('❌ Error de red: ' + err.message, '#fca5a5');
			});
	});
}

// ── F2: toggle dualwrite ──────────────────────────────────────────────────
var dualwriteToggle = document.getElementById('atora-dualwrite-toggle');
var dualwriteStatus = document.getElementById('atora-dualwrite-status');
if (dualwriteToggle) {
	dualwriteToggle.addEventListener('change', function() {
		var enabled = dualwriteToggle.checked;
		var fd = new FormData();
		fd.append('action', 'atora_lms_toggle_dualwrite');
		fd.append('nonce', nonce);
		if (enabled) { fd.append('enabled', '1'); }
		dualwriteToggle.disabled = true;
		fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				dualwriteToggle.disabled = false;
				if (d.success) {
					var on = d.data && d.data.enabled;
					dualwriteStatus.innerHTML = on
						? '<span style="color:#16a34a">● Activo</span>'
						: '<span style="color:#94a3b8">○ Inactivo</span>';
				} else {
					dualwriteToggle.checked = !enabled;
					appendLog('❌ Error al cambiar dualwrite: ' + (d.data && d.data.message ? d.data.message : 'Error.'), '#fca5a5');
				}
			})
			.catch(function(err) {
				dualwriteToggle.checked = !enabled;
				dualwriteToggle.disabled = false;
				appendLog('❌ Error de red: ' + err.message, '#fca5a5');
			});
	});
}

// ── F3: panel de paridad ──────────────────────────────────────────────────
var btnParityRefresh = document.getElementById('btn-parity-refresh');
var parityRecent     = document.getElementById('atora-parity-recent');
var parityTbody      = document.getElementById('atora-parity-tbody');

function loadParityStats() {
	if (!btnParityRefresh) { return; }
	btnParityRefresh.disabled = true;
	btnParityRefresh.textContent = '⏳ Cargando…';

	var fd = new FormData();
	fd.append('action', 'atora_lms_parity_stats');
	fd.append('nonce', nonce);

	fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
		.then(function(r) { return r.json(); })
		.then(function(d) {
			btnParityRefresh.disabled = false;
			btnParityRefresh.textContent = '↻ Actualizar panel';
			if (!d.success || !d.data) { return; }

			var recent = d.data.recent || [];
			if (recent.length === 0) {
				parityRecent.style.display = 'none';
				return;
			}
			parityRecent.style.display = 'block';
			parityTbody.innerHTML = '';
			recent.forEach(function(row) {
				var tr = document.createElement('tr');
				tr.style.borderTop = '1px solid #f1f5f9';
				tr.innerHTML = [
					'<td style="padding:4px 8px">' + row.reader + '</td>',
					'<td style="padding:4px 8px">' + row.user_id + '</td>',
					'<td style="padding:4px 8px">' + (row.wp_course_id || '—') + '</td>',
					'<td style="padding:4px 8px;color:#dc2626;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + row.legacy_summary + '</td>',
					'<td style="padding:4px 8px;color:#2563eb;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + row.table_summary + '</td>',
					'<td style="padding:4px 8px;color:#64748b">' + row.logged_at.substr(0, 16) + '</td>',
				].join('');
				parityTbody.appendChild(tr);
			});
		})
		.catch(function() {
			btnParityRefresh.disabled = false;
			btnParityRefresh.textContent = '↻ Actualizar panel';
		});
}

if (btnParityRefresh) {
	btnParityRefresh.addEventListener('click', loadParityStats);
	// Carga automática al abrir la página si hay divergencias
	if (<?php echo $parity_total > 0 ? 'true' : 'false'; ?>) {
		loadParityStats();
	}
}

// ── F4: flip / rollback de fuente de lectura ──────────────────────────────
function toggleReadSource(source) {
	var confirmed = source === 'tables'
		? confirm('¿Confirmas el flip a "tables"? Los lectores canónicos pasarán a servir desde tablas. Asegúrate de que el gate D-006 está verde y dualwrite activo.')
		: confirm('¿Confirmas el rollback a "legacy"? Los lectores volverán a usermeta inmediatamente.');
	if (!confirmed) { return; }

	var fd = new FormData();
	fd.append('action', 'atora_lms_toggle_read_source');
	fd.append('nonce',  nonce);
	fd.append('source', source);

	fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				window.location.reload();
			} else {
				alert('Error: ' + (d.data && d.data.message ? d.data.message : 'Desconocido.'));
			}
		})
		.catch(function(err) {
			alert('Error de red: ' + err.message);
		});
}

var btnFlip     = document.getElementById('btn-f4-flip');
var btnRollback = document.getElementById('btn-f4-rollback');
if (btnFlip)     { btnFlip.addEventListener('click',     function() { toggleReadSource('tables'); }); }
if (btnRollback) { btnRollback.addEventListener('click', function() { toggleReadSource('legacy'); }); }

})();
</script>
