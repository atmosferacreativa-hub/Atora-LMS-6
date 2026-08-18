<?php
/**
 * Servicio para resumen de gamificación en dashboard.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gamification_Summary_Service {

	/**
	 * Resumen académico de logros para dashboard.
	 *
	 * @param int   $user_id Estudiante.
	 * @param array $status  Estado académico del curso activo.
	 * @return array<string,mixed>
	 */
	public function get_dashboard_summary( $user_id, $status = array() ) {
		$user_id = absint( $user_id );
		$status  = is_array( $status ) ? $status : array();

		$summary = isset( $status['gamification_summary'] ) && is_array( $status['gamification_summary'] )
			? $status['gamification_summary']
			: $this->get_summary_from_core( $user_id );

		$points      = absint( $summary['points'] ?? 0 );
		$level       = max( 1, absint( $summary['level'] ?? 1 ) );
		$streak      = absint( $summary['streak_days'] ?? 0 );
		$next_points = absint( $summary['points_to_next_level'] ?? 0 );
		$next_level  = max( $level, absint( $summary['next_level'] ?? $level ) );

		$rules       = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Rules') : null;
		$level_names = ( $rules && method_exists( $rules, 'get_level_names' ) ) ? (array) $rules->get_level_names() : array();

		$level_label = isset( $level_names[ $level ] ) ? sanitize_text_field( (string) $level_names[ $level ] ) : sprintf( __( 'Nivel %d', 'atora-lms' ), $level );
		$level_name  = isset( $level_names[ $level ] ) ? sanitize_text_field( (string) $level_names[ $level ] ) : '';

		$next_level_note = $next_points > 0
			? sprintf(
				/* translators: 1: puntos faltantes, 2: nivel próximo */
				__( '%1$d pts para %2$s', 'atora-lms' ),
				$next_points,
				isset( $level_names[ $next_level ] ) ? $level_names[ $next_level ] : sprintf( __( 'nivel %d', 'atora-lms' ), $next_level )
			)
			: __( 'Ya alcanzaste tu nivel actual máximo', 'atora-lms' );

		$latest = $this->get_latest_ledger_event( $user_id );

		$badges     = isset( $summary['badges'] ) && is_array( $summary['badges'] ) ? $summary['badges'] : array();
		$next_badge = $this->guess_next_badge( $badges );
		$pending_badges = $this->get_pending_badges( $badges, 3 );
		$pathway_progress = $this->calculate_pathway_progress( $badges );
		$narrative  = $this->build_professional_narrative( $level, $points, $streak, $latest, $next_badge );

		return array(
			'level'            => $level_label,
			'level_number'     => $level,
			'level_name'       => $level_name,
			'points'           => $points,
			'next_level_points'=> $next_points,
			'streak'           => $streak,
			'badges'           => $badges,
			'latest_event'     => $latest,
			'next_badge'       => $next_badge,
			'pending_badges'   => $pending_badges,
			'pathway_progress' => $pathway_progress,
			'next_level_note'  => $next_level_note,
			'narrative'        => $narrative,
		);
	}

	/**
	 * Roadmap oficial de badges.
	 *
	 * @return array<string,string>
	 */
	protected function get_badge_roadmap() {
		return array(
			'primer_paso'          => __( 'Primer paso', 'atora-lms' ),
			'entrega_enviada'      => __( 'Primera entrega enviada', 'atora-lms' ),
			'evaluacion_aprobada'  => __( 'Primera evaluación aprobada', 'atora-lms' ),
			'constancia'           => __( 'Constancia', 'atora-lms' ),
			'mejora_continua'      => __( 'Mejora continua', 'atora-lms' ),
			'alto_desempeno'       => __( 'Alto desempeño', 'atora-lms' ),
			'curso_completado'     => __( 'Curso completado', 'atora-lms' ),
			'certificado_emitido'  => __( 'Certificado obtenido', 'atora-lms' ),
			'programa_completado'  => __( 'Programa completado', 'atora-lms' ),
		);
	}

	protected function get_summary_from_core( $user_id ) {
		$core = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Core') : null;
		if ( ! $core || ! method_exists( $core, 'get_user_summary' ) ) {
			return array();
		}
		return (array) $core->get_user_summary( absint( $user_id ) );
	}

	protected function get_latest_ledger_event( $user_id ) {
		if ( ! class_exists( 'CLMS_Gamification_Core' ) || ! defined( 'CLMS_Gamification_Core::META_LEDGER' ) ) {
			return array();
		}

		$ledger = get_user_meta( absint( $user_id ), constant( 'CLMS_Gamification_Core::META_LEDGER' ), true );
		$ledger = is_array( $ledger ) ? $ledger : array();
		if ( empty( $ledger[0] ) || ! is_array( $ledger[0] ) ) {
			return array(
				'label' => __( 'Sin eventos recientes', 'atora-lms' ),
				'date'  => __( 'Aún sin registro visible', 'atora-lms' ),
			);
		}

		$item = $ledger[0];
		$type = isset( $item['event_type'] )
			? sanitize_key( (string) $item['event_type'] )
			: ( isset( $item['event'] ) ? sanitize_key( (string) $item['event'] ) : '' );
		$date = isset( $item['date_gmt'] )
			? sanitize_text_field( (string) $item['date_gmt'] )
			: ( isset( $item['recorded_at'] ) ? sanitize_text_field( (string) $item['recorded_at'] ) : '' );

		$labels = array(
			'lesson_completed'   => __( 'Lección completada', 'atora-lms' ),
			'submission_sent'    => __( 'Entrega enviada', 'atora-lms' ),
			'evaluation_passed'  => __( 'Evaluación aprobada', 'atora-lms' ),
			'feedback_received'  => __( 'Feedback recibido', 'atora-lms' ),
			'course_completed'   => __( 'Curso completado', 'atora-lms' ),
			'program_completed'  => __( 'Programa completado', 'atora-lms' ),
			'certificate_issued' => __( 'Certificado emitido', 'atora-lms' ),
			'peer_review_quality'=> __( 'Peer review de calidad', 'atora-lms' ),
		);

		return array(
			'label' => isset( $labels[ $type ] ) ? $labels[ $type ] : __( 'Evento académico', 'atora-lms' ),
			'date'  => $date,
		);
	}

	protected function guess_next_badge( $badges ) {
		$badges = is_array( $badges ) ? array_map( 'sanitize_key', $badges ) : array();
		$roadmap = $this->get_badge_roadmap();

		foreach ( $roadmap as $slug => $label ) {
			if ( ! in_array( $slug, $badges, true ) ) {
				return $label;
			}
		}

		return __( 'Trayectoria destacada', 'atora-lms' );
	}

	/**
	 * Devuelve próximos badges pendientes.
	 *
	 * @param array<int,string> $badges Badges obtenidos.
	 * @param int               $limit  Límite.
	 * @return array<int,string>
	 */
	protected function get_pending_badges( $badges, $limit = 3 ) {
		$badges = is_array( $badges ) ? array_map( 'sanitize_key', $badges ) : array();
		$limit  = max( 1, absint( $limit ) );
		$list   = array();

		foreach ( $this->get_badge_roadmap() as $slug => $label ) {
			if ( in_array( $slug, $badges, true ) ) {
				continue;
			}
			$list[] = sanitize_text_field( (string) $label );
			if ( count( $list ) >= $limit ) {
				break;
			}
		}

		return $list;
	}

	/**
	 * Progreso porcentual dentro de la ruta de badges.
	 *
	 * @param array<int,string> $badges Badges obtenidos.
	 * @return int
	 */
	protected function calculate_pathway_progress( $badges ) {
		$badges = is_array( $badges ) ? array_map( 'sanitize_key', $badges ) : array();
		$total  = count( $this->get_badge_roadmap() );

		if ( $total <= 0 ) {
			return 0;
		}

		$done = 0;
		foreach ( array_keys( $this->get_badge_roadmap() ) as $slug ) {
			if ( in_array( $slug, $badges, true ) ) {
				++$done;
			}
		}

		return (int) min( 100, round( ( $done / $total ) * 100 ) );
	}

	/**
	 * Mensaje motivador en tono académico/profesional.
	 *
	 * @param int   $level      Nivel actual.
	 * @param int   $points     Puntos acumulados.
	 * @param int   $streak     Racha.
	 * @param array $latest     Último evento.
	 * @param string $next_badge Próximo logro.
	 * @return string
	 */
	protected function build_professional_narrative( $level, $points, $streak, $latest, $next_badge ) {
		$level  = max( 1, absint( $level ) );
		$points = max( 0, absint( $points ) );
		$streak = max( 0, absint( $streak ) );
		$latest = is_array( $latest ) ? $latest : array();
		$next_badge = sanitize_text_field( (string) $next_badge );

		if ( $streak >= 7 ) {
			return sprintf(
				/* translators: 1: racha, 2: próximo logro */
				__( 'Sostienes una constancia académica de %1$d días. Próximo logro recomendado: %2$s.', 'atora-lms' ),
				$streak,
				$next_badge ? $next_badge : __( 'continuar tu evidencia certificable', 'atora-lms' )
			);
		}

		if ( $points >= 250 || $level >= 4 ) {
			return __( 'Tu desempeño muestra avance sostenido y madurez académica en tus entregas recientes.', 'atora-lms' );
		}

		if ( ! empty( $latest['label'] ) ) {
			return sprintf(
				/* translators: %s: último evento */
				__( 'Tu último hito fue: %s. Mantén este ritmo para consolidar tu progreso.', 'atora-lms' ),
				sanitize_text_field( (string) $latest['label'] )
			);
		}

		return __( 'Tu ruta académica ya registra avances. Continúa con la próxima actividad para fortalecer tu perfil.', 'atora-lms' );
	}
}
