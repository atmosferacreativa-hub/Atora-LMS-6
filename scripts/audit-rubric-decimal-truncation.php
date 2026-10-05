<?php
/**
 * Diagnóstico de solo lectura (6.29.4): puntajes de rúbrica con decimales que
 * pudieron truncarse al reabrir una entrega en SpeedGrader antes de 6.29.4
 * (el panel mostraba absint(3.5) = 3 y al guardar se reenviaba 3).
 *
 * Uso: wp eval-file scripts/audit-rubric-decimal-truncation.php
 *
 * Fuente: cada guardado de SpeedGrader con rúbrica deja una fila en
 * {prefix}atora_rubric_evaluations con los puntajes por criterio en
 * snapshot_json.scores. Señal de truncado: en dos guardados seguidos de la
 * misma entrega, un criterio pasa de X,d a exactamente floor(X,d).
 * No escribe nada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Ejecutar con: wp eval-file scripts/audit-rubric-decimal-truncation.php\n" );
}

global $wpdb;
$table = $wpdb->prefix . 'atora_rubric_evaluations';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
	echo "Sin tabla {$table}: no hay registro de guardados para revisar.\n";
	return;
}

$rows = $wpdb->get_results( "SELECT id, wp_submission_id, grader_id, created_at, snapshot_json FROM {$table} WHERE wp_submission_id > 0 ORDER BY wp_submission_id, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$by_submission = array();
foreach ( (array) $rows as $row ) {
	$by_submission[ (int) $row['wp_submission_id'] ][] = $row;
}

$decimal = static function ( $value ): bool {
	return is_numeric( $value ) && abs( (float) $value - floor( (float) $value ) ) > 0.0001;
};

$suspects  = array();
$had_decimals = 0;
foreach ( $by_submission as $submission_id => $saves ) {
	$prev = null;
	$seen_decimal = false;
	foreach ( $saves as $save ) {
		$snapshot = json_decode( (string) $save['snapshot_json'], true );
		$scores   = is_array( $snapshot['scores'] ?? null ) ? $snapshot['scores'] : array();
		foreach ( $scores as $i => $score_row ) {
			if ( $decimal( $score_row['score'] ?? '' ) ) {
				$seen_decimal = true;
			}
		}
		if ( null !== $prev ) {
			foreach ( $prev['scores'] as $i => $before_row ) {
				$before = $before_row['score'] ?? '';
				$after  = $scores[ $i ]['score'] ?? '';
				if ( $decimal( $before ) && is_numeric( $after ) && abs( (float) $after - floor( (float) $before ) ) < 0.0001 ) {
					$suspects[] = array(
						'submission' => $submission_id,
						'criterion'  => (string) ( $before_row['name'] ?? $i ),
						'from'       => (float) $before,
						'to'         => (float) $after,
						'saved_at'   => $save['created_at'],
						'grader'     => (int) $save['grader_id'],
						'current'    => '',
					);
				}
			}
		}
		$prev = array( 'scores' => $scores );
	}
	if ( $seen_decimal ) {
		++$had_decimals;
	}
}

foreach ( $suspects as &$suspect ) {
	$current = (array) get_post_meta( $suspect['submission'], '_clms_submission_rubric_scores', true );
	foreach ( $current as $row ) {
		if ( is_array( $row ) && (string) ( $row['name'] ?? '' ) === $suspect['criterion'] ) {
			$suspect['current'] = (string) ( $row['score'] ?? '' );
		}
	}
}
unset( $suspect );

printf( "Entregas con guardados registrados: %d\nEntregas que alguna vez tuvieron un puntaje con decimales: %d\nPosibles truncados: %d\n\n", count( $by_submission ), $had_decimals, count( $suspects ) );
foreach ( $suspects as $s ) {
	printf( "Entrega %d · criterio \"%s\": %s → %s el %s (docente %d) · puntaje actual: %s\n", $s['submission'], $s['criterion'], $s['from'], $s['to'], $s['saved_at'], $s['grader'], '' === $s['current'] ? '—' : $s['current'] );
}
