<?php
/**
 * Disparadores de automatización: un solo conjunto (6.38.0, Orden C · Bloque 3). Ver docs/AUTOMATIZACION.md.
 *
 * Antes había nombres duplicados para el mismo hecho (lesson_completed y lesson_completed_v2,
 * course_enrolled / enrollment_completed / enrollment_v2…), varios escuchaban hooks que nada dispara
 * (`clms_user_enrolled_in_course`, `atora/email/opened`) o métodos inexistentes (los `_v2`, insignias, rutas), y
 * los disparadores de CRM sin usuario usaban al usuario 1 (el administrador) como destinatario.
 *
 * Ahora:
 * - cada hecho tiene un nombre y un payload documentado;
 * - varias fuentes del mismo hecho (LMS anterior y tablas propias) se unen y se disparan una sola vez por petición;
 * - un disparador sin usuario lleva el contacto (contact_id) y nunca otro usuario;
 * - los nombres retirados se migran (`migrate_aliases`).
 *
 * @package ATORA_LMS
 * @since 6.38.0
 */

namespace ATORA\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Automation_Triggers {

	/** Nombre retirado => nombre vigente. */
	const ALIASES = array(
		'lesson_completed_v2'   => 'lesson_completed',
		'course_completed_v2'   => 'course_completed',
		'enrollment_v2'         => 'course_enrolled',
		'enrollment_completed'  => 'course_enrolled',
		'inactivity_academic'   => 'inactivity_detected',
		'campaign_opened'       => 'email_opened',
		'grade_below_threshold' => 'grade_published', // + condición grade < 60 al migrar.
	);

	/** Retirados sin reemplazo (no tenían fuente). */
	const REMOVED = array( 'webinar_registered' );

	/** @var array<string,true> Disparos ya hechos en esta petición (trigger|sujeto|objeto). */
	private static array $fired = array();

	/**
	 * Catálogo: nombre => [etiqueta, payload].
	 *
	 * @return array<string,array{label:string,payload:string[]}>
	 */
	public static function catalog(): array {
		return array(
			'user_registered'       => array( 'label' => __( 'Usuario registrado', 'atora-lms' ), 'payload' => array() ),
			'course_enrolled'       => array( 'label' => __( 'Matrícula en un curso', 'atora-lms' ), 'payload' => array( 'course_id' ) ),
			'lesson_completed'      => array( 'label' => __( 'Lección completada', 'atora-lms' ), 'payload' => array( 'lesson_id', 'course_id' ) ),
			'course_completed'      => array( 'label' => __( 'Curso completado', 'atora-lms' ), 'payload' => array( 'course_id' ) ),
			'grade_published'       => array( 'label' => __( 'Nota publicada', 'atora-lms' ), 'payload' => array( 'submission_id', 'lesson_id', 'course_id', 'grade' ) ),
			'assignment_due_soon'   => array( 'label' => __( 'Tarea por vencer', 'atora-lms' ), 'payload' => array( 'lesson_id', 'course_id', 'due_at' ) ),
			'student_at_risk'       => array( 'label' => __( 'Estudiante en riesgo', 'atora-lms' ), 'payload' => array( 'course_id', 'reason' ) ),
			'inactivity_detected'   => array( 'label' => __( 'Inactividad en un curso', 'atora-lms' ), 'payload' => array( 'course_id', 'days_inactive', 'progress_percent' ) ),
			'certificate_issued'    => array( 'label' => __( 'Certificado emitido', 'atora-lms' ), 'payload' => array( 'course_id' ) ),
			'badge_earned'          => array( 'label' => __( 'Insignia obtenida', 'atora-lms' ), 'payload' => array( 'badge' ) ),
			'learning_path_updated' => array( 'label' => __( 'Ruta de aprendizaje actualizada', 'atora-lms' ), 'payload' => array() ),
			'purchase_completed'    => array( 'label' => __( 'Compra completada', 'atora-lms' ), 'payload' => array( 'order_id' ) ),
			'cart_abandoned'        => array( 'label' => __( 'Carrito abandonado', 'atora-lms' ), 'payload' => array( 'cart_id', 'contact_id', 'total' ) ),
			'ltv_updated'           => array( 'label' => __( 'Valor de vida del cliente actualizado', 'atora-lms' ), 'payload' => array( 'contact_id', 'amount', 'order_id' ) ),
			'email_opened'          => array( 'label' => __( 'Correo abierto', 'atora-lms' ), 'payload' => array( 'queue_id' ) ),
			'email_clicked'         => array( 'label' => __( 'Enlace de un correo pulsado', 'atora-lms' ), 'payload' => array( 'queue_id' ) ),
			'tag_added'             => array( 'label' => __( 'Etiqueta agregada', 'atora-lms' ), 'payload' => array( 'tag', 'contact_id' ) ),
			'tag_removed'           => array( 'label' => __( 'Etiqueta quitada', 'atora-lms' ), 'payload' => array( 'tag', 'contact_id' ) ),
			'form_submitted'        => array( 'label' => __( 'Formulario enviado', 'atora-lms' ), 'payload' => array( 'form_id', 'entry_id', 'contact_id' ) ),
			'contact_created'       => array( 'label' => __( 'Contacto creado en el CRM', 'atora-lms' ), 'payload' => array( 'contact_id' ) ),
			'deal_stage_changed'    => array( 'label' => __( 'Cambio de etapa en el embudo', 'atora-lms' ), 'payload' => array( 'deal_id', 'stage', 'contact_id' ) ),
		);
	}

	public static function canonical( string $trigger ): string {
		$trigger = sanitize_key( $trigger );
		return self::ALIASES[ $trigger ] ?? $trigger;
	}

	/**
	 * hook => [disparador, n.º de argumentos, función que devuelve [user_id, contact_id, contexto, objeto] o null].
	 *
	 * @return array<string,array{0:string,1:int,2:callable}>
	 */
	private static function hooks(): array {
		$user      = static fn( $u, array $ctx = array(), string $obj = '' ): array => array( absint( $u ), 0, $ctx, $obj );
		$enrolled  = static fn( $u, $c ) => $user( $u, array( 'course_id' => absint( $c ) ), 'course:' . absint( $c ) );
		$lesson    = static fn( $u, $l, $c = 0 ) => $user( $u, array( 'lesson_id' => absint( $l ), 'course_id' => absint( $c ) ?: absint( get_post_meta( absint( $l ), '_clms_course_id', true ) ) ), 'lesson:' . absint( $l ) );
		$completed = static fn( $u, $c ) => $user( $u, array( 'course_id' => absint( $c ) ), 'course:' . absint( $c ) );
		return array(
			'user_register'                     => array( 'user_registered', 1, static fn( $u ) => $user( $u ) ),
			'clms_user_enrolled_in_course'      => array( 'course_enrolled', 2, $enrolled ),
			'atora/lms/enrolled'                => array( 'course_enrolled', 2, $enrolled ),
			'clms_lesson_completed'             => array( 'lesson_completed', 2, $lesson ),
			'atora/lms/lesson_completed'        => array( 'lesson_completed', 3, $lesson ),
			'clms_course_completed'             => array( 'course_completed', 2, $completed ),
			'atora/lms/course_completed'        => array( 'course_completed', 2, $completed ),
			'clms_grade_published'              => array( 'grade_published', 1, static function ( $payload ) use ( $user ) {
				$p = is_array( $payload ) ? $payload : array();
				if ( empty( $p['student_id'] ) || ! is_numeric( $p['grade'] ?? null ) ) {
					return null;
				}
				return $user( $p['student_id'], array(
					'submission_id' => absint( $p['submission_id'] ?? 0 ),
					'lesson_id'     => absint( $p['lesson_id'] ?? 0 ),
					'course_id'     => absint( $p['course_id'] ?? 0 ),
					'grade'         => (float) $p['grade'],
				), 'submission:' . absint( $p['submission_id'] ?? 0 ) );
			} ),
			'atora/academic/assignment_due_soon' => array( 'assignment_due_soon', 4, static fn( $u, $l, $c, $due ) => $user( $u, array( 'lesson_id' => absint( $l ), 'course_id' => absint( $c ), 'due_at' => (string) $due ), 'lesson:' . absint( $l ) ) ),
			'clms_ai_inactivity_alert_generated' => array( 'student_at_risk', 2, static fn( $u, $c ) => $user( $u, array( 'course_id' => absint( $c ), 'reason' => 'inactivity' ), 'course:' . absint( $c ) ) ),
			'clms_ai_low_grade_alert_generated'  => array( 'student_at_risk', 2, static fn( $u, $c ) => $user( $u, array( 'course_id' => absint( $c ), 'reason' => 'low_grade' ), 'course:' . absint( $c ) ) ),
			'clms_certificate_issued'           => array( 'certificate_issued', 3, static fn( $record, $u, $c ) => $user( $u, array( 'course_id' => absint( $c ) ), 'course:' . absint( $c ) ) ),
			'atora/lms/certificate_issued'      => array( 'certificate_issued', 2, $completed ),
			'atora/gamification/badge_earned'   => array( 'badge_earned', 2, static fn( $u, $slug ) => $user( $u, array( 'badge' => sanitize_key( (string) $slug ) ), 'badge:' . sanitize_key( (string) $slug ) ) ),
			'atora/lms/learning_path_updated'   => array( 'learning_path_updated', 1, static fn( $u ) => $user( $u ) ),
			'woocommerce_order_status_completed' => array( 'purchase_completed', 1, static function ( $order_id ) use ( $user ) {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
				return $order && $order->get_customer_id() ? $user( $order->get_customer_id(), array( 'order_id' => absint( $order_id ) ), 'order:' . absint( $order_id ) ) : null;
			} ),
			'atora/cart/abandoned'              => array( 'cart_abandoned', 4, static fn( $cart, $contact = 0, $u = 0, $total = 0 ) => array( absint( $u ), absint( $contact ), array( 'cart_id' => absint( $cart ), 'contact_id' => absint( $contact ), 'total' => (float) $total ), 'cart:' . absint( $cart ) ) ),
			'atora/contact/ltv_updated'         => array( 'ltv_updated', 3, static fn( $contact, $amount = 0, $order = 0 ) => array( 0, absint( $contact ), array( 'contact_id' => absint( $contact ), 'amount' => (float) $amount, 'order_id' => absint( $order ) ), 'order:' . absint( $order ) ) ),
			'atora/email/webhook_event'         => array( 'email_opened', 3, array( __CLASS__, 'from_email_event' ) ),
			'atora/crm/tag_added'               => array( 'tag_added', 3, static fn( $u, $tag = '', $contact = 0 ) => array( absint( $u ), absint( $contact ), array( 'tag' => sanitize_text_field( (string) $tag ), 'contact_id' => absint( $contact ) ), 'tag:' . sanitize_key( (string) $tag ) ) ),
			'atora/crm/tag_removed'             => array( 'tag_removed', 3, static fn( $u, $tag = '', $contact = 0 ) => array( absint( $u ), absint( $contact ), array( 'tag' => sanitize_text_field( (string) $tag ), 'contact_id' => absint( $contact ) ), 'tag:' . sanitize_key( (string) $tag ) ) ),
			'atora/crm/contact_created'         => array( 'contact_created', 2, static fn( $contact, $u = 0 ) => array( absint( $u ), absint( $contact ), array( 'contact_id' => absint( $contact ) ), 'contact:' . absint( $contact ) ) ),
			// El usuario que llega aquí es quien movió la etapa (personal), no el contacto: se ignora.
			'atora/crm/deal_stage_changed'      => array( 'deal_stage_changed', 3, static fn( $deal, $stage = '', $contact = 0 ) => absint( $contact ) ? array( 0, absint( $contact ), array( 'deal_id' => absint( $deal ), 'stage' => sanitize_key( (string) $stage ), 'contact_id' => absint( $contact ) ), 'stage:' . sanitize_key( (string) $stage ) ) : null ),
		);
	}

	public static function register(): void {
		foreach ( self::hooks() as $hook => $def ) {
			list( $trigger, $args, $fn ) = $def;
			add_action(
				$hook,
				static function ( ...$params ) use ( $trigger, $fn ) {
					$out = $fn( ...$params );
					if ( is_array( $out ) && isset( $out['trigger'] ) ) {
						$trigger = $out['trigger'];
						$out     = $out['data'];
					}
					if ( is_array( $out ) ) {
						self::fire( $trigger, (int) $out[0], (int) $out[1], (array) $out[2], (string) $out[3] );
					}
				},
				20,
				$args
			);
		}
		add_action( 'atora_daily_cron', array( __CLASS__, 'scan_inactivity' ) );
	}

	/** Eventos del proveedor de correo: apertura o clic. */
	public static function from_email_event( $event_type, $queue_id, $event = array() ): ?array {
		global $wpdb;
		if ( ! in_array( $event_type, array( 'opened', 'clicked' ), true ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, metadata FROM {$wpdb->prefix}atora_email_queue WHERE id = %d LIMIT 1", absint( $queue_id ) ) ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$meta    = json_decode( (string) $row->metadata, true );
		$contact = is_array( $meta ) ? absint( $meta['contact_id'] ?? 0 ) : 0;
		return array(
			'trigger' => 'email_' . $event_type,
			'data'    => array( (int) $row->user_id, $contact, array( 'queue_id' => absint( $queue_id ), 'contact_id' => $contact ), 'queue:' . absint( $queue_id ) ),
		);
	}

	/**
	 * Dispara un hecho. Una sola vez por petición para el mismo sujeto y objeto, aunque llegue por dos fuentes.
	 */
	public static function fire( string $trigger, int $user_id, int $contact_id, array $context = array(), string $object = '' ): void {
		$trigger = self::canonical( $trigger );
		if ( $user_id <= 0 && $contact_id <= 0 ) {
			return;
		}
		$key = $trigger . '|' . $user_id . '|' . $contact_id . '|' . $object;
		if ( '' !== $object && isset( self::$fired[ $key ] ) ) {
			return;
		}
		self::$fired[ $key ] = true;
		if ( $contact_id > 0 && empty( $context['contact_id'] ) ) {
			$context['contact_id'] = $contact_id;
		}
		do_action( 'atora/automation/trigger', $trigger, $user_id, $context );
		Automation_Flows::start( $trigger, $user_id, $contact_id, $context );
	}

	/** Solo para pruebas: olvida los disparos de esta petición. */
	public static function reset_request_state(): void {
		self::$fired = array();
	}

	/**
	 * Inactividad (diaria): matrículas activas sin actividad desde hace N días (`clms_inactivity_days_threshold`,
	 * 14 por defecto) y sin terminar. La regla de no reingreso de cada flujo evita repetirla cada día.
	 */
	public static function scan_inactivity(): int {
		global $wpdb;
		$days   = max( 1, absint( get_option( 'clms_inactivity_days_threshold', 14 ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT user_id, COALESCE(NULLIF(wp_course_id, 0), course_id) AS course_id, progress_pct
			 FROM {$wpdb->prefix}atora_enrollments
			 WHERE status = 'active' AND progress_pct < 100
			   AND COALESCE(last_activity, enrolled_at) < %s
			 ORDER BY id ASC LIMIT 500",
			$cutoff
		), ARRAY_A );
		foreach ( $rows as $row ) {
			self::fire( 'inactivity_detected', absint( $row['user_id'] ), 0, array(
				'course_id'        => absint( $row['course_id'] ),
				'days_inactive'    => $days,
				'progress_percent' => absint( $row['progress_pct'] ),
			), 'course:' . absint( $row['course_id'] ) );
		}
		return count( $rows );
	}

	/**
	 * Migra los flujos que usan nombres retirados. Idempotente.
	 *
	 * @return array<string,int> nombre retirado => flujos migrados
	 */
	public static function migrate_aliases(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_automations';
		$out   = array();
		foreach ( self::ALIASES as $old => $new ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, conditions FROM {$table} WHERE trigger_type = %s", $old ) ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$update = array( 'trigger_type' => $new );
				if ( 'grade_below_threshold' === $old ) {
					$cond  = json_decode( (string) $row->conditions, true );
					$cond  = is_array( $cond ) ? $cond : array();
					$rules = (array) ( $cond['rules'] ?? array() );
					$has   = (bool) array_filter( $rules, static fn( $r ) => is_array( $r ) && 'grade' === ( $r['field'] ?? '' ) );
					if ( ! $has ) {
						$rules[]                = array( 'field' => 'grade', 'operator' => 'less_than', 'value' => '60' );
						$cond['operator']       = $cond['operator'] ?? 'AND';
						$cond['rules']          = $rules;
						$update['conditions']   = wp_json_encode( $cond );
					}
				}
				$wpdb->update( $table, $update, array( 'id' => (int) $row->id ) );
				$out[ $old ] = ( $out[ $old ] ?? 0 ) + 1;
			}
		}
		foreach ( self::REMOVED as $gone ) {
			$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET active = 0 WHERE trigger_type = %s AND active = 1", $gone ) ); // phpcs:ignore WordPress.DB
			if ( $n ) {
				$out[ $gone ] = $n;
			}
		}
		return $out;
	}
}
