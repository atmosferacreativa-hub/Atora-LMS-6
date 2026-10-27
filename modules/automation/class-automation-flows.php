<?php
/**
 * Motor de flujos (6.38.0, Orden C · Bloque 3). Ver docs/AUTOMATIZACION.md.
 *
 * Un flujo es una automatización (`atora_automations`) con una definición de pasos:
 *   { "version": 2, "start": "s1", "allow_reentry": false, "goal": {reglas},
 *     "steps": [ {"id":"s1","type":"action","action":{…},"next":"s2"},
 *                {"id":"s2","type":"wait","minutes":1440,"next":"s3"}         // o "until" / "until_field"
 *                {"id":"s3","type":"condition","rules":{…},"yes":"s4","no":null} ] }
 * Las automatizaciones anteriores (lista de acciones con `delay_minutes`) se leen como un flujo lineal.
 *
 * Cada vez que alguien entra a un flujo se crea un recorrido (`atora_automation_runs`) que sigue la versión con la
 * que entró (`atora_automation_versions`). Cada paso ejecutado queda en `atora_automation_run_steps` (clave única
 * recorrido+paso): al reanudar tras un fallo se sigue desde el último paso confirmado y una acción hecha no se
 * repite.
 *
 * Protecciones: no reingreso (salvo `allow_reentry`), detección de bucles (un flujo que dispara otro que dispara el
 * primero), límite de acciones por persona y día (`atora_automation_daily_limit`, 20), salida al cumplir el objetivo,
 * reintentos con espera creciente y recorridos colgados que vuelven a la cola.
 *
 * @package ATORA_LMS
 * @since 6.38.0
 */

namespace ATORA\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Automation_Flows {

	const MAX_ATTEMPTS   = 3;
	const MAX_CHAIN      = 5;
	const MAX_STEPS_TICK = 25;
	const STUCK_MINUTES  = 30;
	const OPTION_LIMIT   = 'atora_automation_daily_limit';

	/** @var int[] Flujos cuyo paso se está ejecutando ahora (para detectar bucles). */
	private static array $chain = array();

	public static function init(): void {
		add_action( 'atora_automation_cron', array( __CLASS__, 'process' ) );
		add_action( 'atora_automation_runs_tick', array( __CLASS__, 'process' ) );
	}

	// ── Tablas ──────────────────────────────────────────────────────────────

	private static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	// ── Definición ──────────────────────────────────────────────────────────

	/** Definición de un flujo: la nueva o la lista anterior convertida a pasos lineales. */
	public static function definition_from_row( object $row ): array {
		$raw = json_decode( (string) ( $row->actions ?? '' ), true );
		if ( is_array( $raw ) && 2 === (int) ( $raw['version'] ?? 0 ) ) {
			return $raw;
		}
		$cfg   = json_decode( (string) ( $row->trigger_config ?? '' ), true );
		$steps = array();
		$prev  = 0;
		$i     = 0;
		foreach ( is_array( $raw ) ? $raw : array() as $action ) {
			if ( ! is_array( $action ) || empty( $action['type'] ) ) {
				continue;
			}
			$delay = absint( $action['delay_minutes'] ?? 0 );
			if ( $delay > $prev ) {
				$steps[] = array( 'id' => 'w' . $i, 'type' => 'wait', 'minutes' => $delay - $prev );
				$prev    = $delay;
			}
			unset( $action['delay_minutes'] );
			$steps[] = array( 'id' => 'a' . $i, 'type' => 'action', 'action' => $action );
			++$i;
		}
		$count = count( $steps );
		foreach ( $steps as $k => $step ) {
			$steps[ $k ]['next'] = $k + 1 < $count ? $steps[ $k + 1 ]['id'] : null;
		}
		return array(
			'version'       => 2,
			'start'         => $steps ? $steps[0]['id'] : null,
			'allow_reentry' => ! empty( $cfg['allow_reentry'] ),
			'steps'         => $steps,
		);
	}

	/** @return array<string,array> pasos por id */
	private static function steps_by_id( array $def ): array {
		$out = array();
		foreach ( (array) ( $def['steps'] ?? array() ) as $step ) {
			if ( is_array( $step ) && isset( $step['id'] ) ) {
				$out[ (string) $step['id'] ] = $step;
			}
		}
		return $out;
	}

	/**
	 * Valida un flujo antes de activarlo.
	 *
	 * @return string[] errores (vacío = válido)
	 */
	public static function validate( array $def ): array {
		$errors = array();
		$steps  = self::steps_by_id( $def );
		if ( ! $steps ) {
			return array( __( 'El flujo no tiene pasos.', 'atora-lms' ) );
		}
		if ( count( $steps ) !== count( (array) $def['steps'] ) ) {
			$errors[] = __( 'Hay pasos con el mismo identificador.', 'atora-lms' );
		}
		$start = (string) ( $def['start'] ?? '' );
		if ( ! isset( $steps[ $start ] ) ) {
			$errors[] = __( 'El paso inicial no existe.', 'atora-lms' );
		}
		$known_actions = Automation_Engine::ACTION_TYPES;
		$templates     = class_exists( '\\ATORA\\EmailEngine\\Email_Templates' ) ? array_keys( \ATORA\EmailEngine\Email_Templates::get_catalog() ) : array();
		foreach ( $steps as $id => $step ) {
			$type  = (string) ( $step['type'] ?? '' );
			/* translators: %s: step id */
			$label = sprintf( __( 'Paso %s', 'atora-lms' ), $id );
			foreach ( array( 'next', 'yes', 'no' ) as $edge ) {
				if ( isset( $step[ $edge ] ) && null !== $step[ $edge ] && '' !== $step[ $edge ] && ! isset( $steps[ (string) $step[ $edge ] ] ) ) {
					/* translators: 1: step 2: target */
					$errors[] = sprintf( __( '%1$s apunta a un paso que no existe (%2$s).', 'atora-lms' ), $label, (string) $step[ $edge ] );
				}
			}
			if ( 'action' === $type ) {
				$action = (array) ( $step['action'] ?? array() );
				$atype  = sanitize_key( (string) ( $action['type'] ?? '' ) );
				if ( ! in_array( $atype, $known_actions, true ) ) {
					/* translators: 1: step 2: action */
					$errors[] = sprintf( __( '%1$s: acción desconocida (%2$s).', 'atora-lms' ), $label, $atype );
				} elseif ( 'send_email' === $atype && $templates && ! in_array( (string) ( $action['template'] ?? '' ), $templates, true ) ) {
					/* translators: 1: step 2: template */
					$errors[] = sprintf( __( '%1$s: la plantilla de correo no existe (%2$s).', 'atora-lms' ), $label, (string) ( $action['template'] ?? '' ) );
				} elseif ( in_array( $atype, array( 'add_tag', 'remove_tag' ), true ) && '' === trim( (string) ( $action['tag'] ?? '' ) ) ) {
					/* translators: %s: step */
					$errors[] = sprintf( __( '%s: falta la etiqueta.', 'atora-lms' ), $label );
				} elseif ( 'webhook' === $atype && 0 !== strpos( (string) ( $action['url'] ?? '' ), 'https://' ) ) {
					/* translators: %s: step */
					$errors[] = sprintf( __( '%s: el webhook necesita una dirección https.', 'atora-lms' ), $label );
				}
			} elseif ( 'wait' === $type ) {
				if ( absint( $step['minutes'] ?? 0 ) <= 0 && empty( $step['until'] ) && empty( $step['until_field'] ) ) {
					/* translators: %s: step */
					$errors[] = sprintf( __( '%s: la espera necesita minutos o una fecha.', 'atora-lms' ), $label );
				}
				if ( ! empty( $step['until'] ) && false === strtotime( (string) $step['until'] ) ) {
					/* translators: %s: step */
					$errors[] = sprintf( __( '%s: la fecha de la espera no es válida.', 'atora-lms' ), $label );
				}
			} elseif ( 'condition' === $type ) {
				if ( empty( $step['rules']['rules'] ) ) {
					/* translators: %s: step */
					$errors[] = sprintf( __( '%s: la condición no tiene reglas.', 'atora-lms' ), $label );
				}
			} else {
				/* translators: 1: step 2: type */
				$errors[] = sprintf( __( '%1$s: tipo de paso desconocido (%2$s).', 'atora-lms' ), $label, $type );
			}
		}
		if ( ! $errors && self::has_cycle( $steps, $start ) ) {
			$errors[] = __( 'El flujo tiene un ciclo: un paso vuelve a uno anterior.', 'atora-lms' );
		}
		return $errors;
	}

	private static function has_cycle( array $steps, string $start ): bool {
		$state = array();
		$visit = static function ( string $id ) use ( &$visit, &$state, $steps ): bool {
			if ( 'open' === ( $state[ $id ] ?? '' ) ) {
				return true;
			}
			if ( 'done' === ( $state[ $id ] ?? '' ) || ! isset( $steps[ $id ] ) ) {
				return false;
			}
			$state[ $id ] = 'open';
			foreach ( array( 'next', 'yes', 'no' ) as $edge ) {
				$to = $steps[ $id ][ $edge ] ?? null;
				if ( null !== $to && '' !== $to && $visit( (string) $to ) ) {
					return true;
				}
			}
			$state[ $id ] = 'done';
			return false;
		};
		return $visit( $start );
	}

	// ── Versiones ───────────────────────────────────────────────────────────

	/**
	 * Guarda un flujo (nuevo o existente). Crea una versión nueva si la definición cambió. No activa un flujo
	 * inválido.
	 *
	 * @param array $data name, description, trigger_type, conditions, definition, active, priority, id?
	 * @return int|\WP_Error
	 */
	public static function save( array $data ) {
		global $wpdb;
		$def     = is_array( $data['definition'] ?? null ) ? $data['definition'] : array();
		$def     = array( 'version' => 2 ) + $def;
		$trigger = Automation_Triggers::canonical( (string) ( $data['trigger_type'] ?? '' ) );
		$errors  = self::validate( $def );
		if ( ! array_key_exists( $trigger, Automation_Triggers::catalog() ) ) {
			$errors[] = __( 'Elige un disparador.', 'atora-lms' );
		}
		$active = ! empty( $data['active'] );
		if ( $active && $errors ) {
			return new \WP_Error( 'atora_flow_invalid', implode( ' ', $errors ), array( 'errors' => $errors ) );
		}
		$row = array(
			'name'           => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'trigger_type'   => $trigger,
			'trigger_config' => wp_json_encode( array( 'allow_reentry' => ! empty( $def['allow_reentry'] ) ) ),
			'conditions'     => wp_json_encode( is_array( $data['conditions'] ?? null ) ? $data['conditions'] : array() ),
			'actions'        => wp_json_encode( $def ),
			'active'         => $active ? 1 : 0,
			'priority'       => max( 1, min( 99, absint( $data['priority'] ?? 10 ) ) ),
			'updated_at'     => current_time( 'mysql', true ),
		);
		$id = absint( $data['id'] ?? 0 );
		if ( $id ) {
			$wpdb->update( self::t( 'atora_automations' ), $row, array( 'id' => $id ) );
		} else {
			$row['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( self::t( 'atora_automations' ), $row );
			$id = (int) $wpdb->insert_id;
		}
		self::snapshot( $id, $def );
		return $id;
	}

	/** Versión vigente; si la definición cambió desde la última, crea una nueva. */
	public static function snapshot( int $automation_id, array $def ): int {
		global $wpdb;
		$table = self::t( 'atora_automation_versions' );
		$json  = wp_json_encode( $def );
		$last  = $wpdb->get_row( $wpdb->prepare( "SELECT version, definition FROM {$table} WHERE automation_id = %d ORDER BY version DESC LIMIT 1", $automation_id ) ); // phpcs:ignore WordPress.DB
		if ( $last && hash_equals( (string) $last->definition, (string) $json ) ) {
			return (int) $last->version;
		}
		$version = $last ? (int) $last->version + 1 : 1;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"INSERT IGNORE INTO {$table} (automation_id, version, definition, created_at, created_by) VALUES (%d, %d, %s, %s, %d)",
			$automation_id,
			$version,
			$json,
			current_time( 'mysql', true ),
			get_current_user_id()
		) );
		return $version;
	}

	public static function version_definition( int $automation_id, int $version ): ?array {
		global $wpdb;
		$json = (string) $wpdb->get_var( $wpdb->prepare( "SELECT definition FROM " . self::t( 'atora_automation_versions' ) . ' WHERE automation_id = %d AND version = %d', $automation_id, $version ) ); // phpcs:ignore WordPress.DB
		$def  = json_decode( $json, true );
		return is_array( $def ) ? $def : null;
	}

	// ── Entrada ─────────────────────────────────────────────────────────────

	/**
	 * Hace entrar al sujeto en cada flujo activo de ese disparador.
	 *
	 * @return int[] recorridos creados
	 */
	public static function start( string $trigger, int $user_id, int $contact_id, array $context = array(), bool $test = false, int $only_automation = 0 ): array {
		global $wpdb;
		$sql   = 'SELECT * FROM ' . self::t( 'atora_automations' ) . ' WHERE trigger_type = %s' . ( $test ? '' : ' AND active = 1' ) . ( $only_automation ? ' AND id = ' . absint( $only_automation ) : '' ) . ' ORDER BY priority ASC, id ASC';
		$flows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $trigger ) ); // phpcs:ignore WordPress.DB
		if ( ! $flows ) {
			return array();
		}
		if ( $contact_id <= 0 && $user_id > 0 ) {
			$contact_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'atora_contacts' ) . ' WHERE user_id = %d LIMIT 1', $user_id ) ); // phpcs:ignore WordPress.DB
		}
		if ( $user_id <= 0 && $contact_id > 0 ) {
			$user_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(user_id, 0) FROM ' . self::t( 'atora_contacts' ) . ' WHERE id = %d', $contact_id ) ); // phpcs:ignore WordPress.DB
		}
		if ( $contact_id > 0 ) {
			$context['contact_id'] = $contact_id;
		}
		$runs = array();
		foreach ( $flows as $flow ) {
			$id    = (int) $flow->id;
			$chain = self::$chain;
			$def   = self::definition_from_row( $flow );
			if ( ! $test && ! self::passes_conditions( json_decode( (string) $flow->conditions, true ), $user_id, $contact_id, $context ) ) {
				continue; // No le corresponde: no es un bucle.
			}
			if ( in_array( $id, $chain, true ) || count( $chain ) >= self::MAX_CHAIN ) {
				self::record_blocked( $id, $user_id, $contact_id, $context, $chain, 'loop' );
				continue;
			}
			if ( ! $test && self::already_in( $id, $user_id, $contact_id, ! empty( $def['allow_reentry'] ) ) ) {
				continue;
			}
			$version = self::snapshot( $id, $def );
			$wpdb->insert( self::t( 'atora_automation_runs' ), array(
				'automation_id' => $id,
				'version'       => $version,
				'user_id'       => $user_id,
				'contact_id'    => $contact_id,
				'status'        => 'waiting',
				'current_step'  => (string) ( $def['start'] ?? '' ),
				'wake_at'       => current_time( 'mysql', true ),
				'attempts'      => 0,
				'context'       => wp_json_encode( $context ),
				'chain'         => implode( ',', $chain ),
				'is_test'       => $test ? 1 : 0,
				'started_at'    => current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
			) );
			$runs[] = (int) $wpdb->insert_id;
		}
		if ( $runs && ! wp_next_scheduled( 'atora_automation_runs_tick' ) ) {
			wp_schedule_single_event( time(), 'atora_automation_runs_tick' );
		}
		return $runs;
	}

	private static function already_in( int $automation_id, int $user_id, int $contact_id, bool $allow_reentry ): bool {
		global $wpdb;
		$status = $allow_reentry ? "AND status IN ('waiting','running')" : "AND status <> 'blocked'";
		$who    = $user_id > 0 ? $wpdb->prepare( 'user_id = %d', $user_id ) : $wpdb->prepare( 'contact_id = %d', $contact_id );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'atora_automation_runs' ) . " WHERE automation_id = %d AND is_test = 0 AND {$who} {$status} LIMIT 1", $automation_id ) ); // phpcs:ignore WordPress.DB
	}

	private static function record_blocked( int $automation_id, int $user_id, int $contact_id, array $context, array $chain, string $reason ): void {
		global $wpdb;
		$wpdb->insert( self::t( 'atora_automation_runs' ), array(
			'automation_id' => $automation_id,
			'version'       => 0,
			'user_id'       => $user_id,
			'contact_id'    => $contact_id,
			'status'        => 'blocked',
			'current_step'  => '',
			'wake_at'       => current_time( 'mysql', true ),
			'context'       => wp_json_encode( $context ),
			'chain'         => implode( ',', $chain ),
			'exit_reason'   => $reason,
			'started_at'    => current_time( 'mysql', true ),
			'updated_at'    => current_time( 'mysql', true ),
			'finished_at'   => current_time( 'mysql', true ),
		) );
		do_action( 'atora/automation/loop_blocked', $automation_id, $chain, $user_id, $contact_id );
	}

	// ── Ejecución ───────────────────────────────────────────────────────────

	/** Procesa los recorridos que tocan (cron cada 5 min y al entrar alguien). @return int recorridos atendidos */
	public static function process( int $limit = 100 ): int {
		global $wpdb;
		$runs = self::t( 'atora_automation_runs' );
		// Recorridos colgados (el proceso murió a mitad): vuelven a la cola. Lo ya hecho no se repite.
		$wpdb->query( $wpdb->prepare( "UPDATE {$runs} SET status = 'waiting' WHERE status = 'running' AND locked_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::STUCK_MINUTES * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs} WHERE status = 'waiting' AND wake_at <= %s ORDER BY wake_at ASC, id ASC LIMIT %d", current_time( 'mysql', true ), $limit ) ) ); // phpcs:ignore WordPress.DB
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += self::advance( $id ) ? 1 : 0;
		}
		return $n;
	}

	/** Avanza un recorrido hasta una espera, el final o un fallo. */
	public static function advance( int $run_id ): bool {
		global $wpdb;
		$runs    = self::t( 'atora_automation_runs' );
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$runs} SET status = 'running', locked_at = %s WHERE id = %d AND status = 'waiting' AND wake_at <= %s", current_time( 'mysql', true ), $run_id, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB
		if ( 1 !== (int) $claimed ) {
			return false;
		}
		$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %d", $run_id ) ); // phpcs:ignore WordPress.DB
		$def = self::version_definition( (int) $run->automation_id, (int) $run->version );
		if ( ! $def ) {
			self::finish( $run_id, 'failed', 'version_missing' );
			return true;
		}
		$steps   = self::steps_by_id( $def );
		$context = (array) json_decode( (string) $run->context, true );
		$user_id = (int) $run->user_id;
		$contact = (int) $run->contact_id;
		$step_id = (string) $run->current_step;
		$guard   = 0;

		while ( '' !== $step_id && $guard++ < self::MAX_STEPS_TICK ) {
			if ( ! empty( $def['goal']['rules'] ) && self::passes_conditions( $def['goal'], $user_id, $contact, $context ) ) {
				self::finish( $run_id, 'exited', 'goal' );
				return true;
			}
			$step = $steps[ $step_id ] ?? null;
			if ( ! $step ) {
				self::finish( $run_id, 'failed', 'step_missing:' . $step_id );
				return true;
			}
			$done = self::step_done( $run_id, $step_id );
			$type = (string) $step['type'];

			if ( 'wait' === $type ) {
				if ( ! $done ) {
					self::mark( $run_id, $step_id, 'wait', '', 'done', '' );
					$wake = self::wake_time( $step, $context );
					if ( $wake > time() ) {
						self::park( $run_id, (string) ( $step['next'] ?? '' ), $wake, 0, $context );
						return true;
					}
				}
				$step_id = (string) ( $step['next'] ?? '' );
				continue;
			}

			if ( 'condition' === $type ) {
				if ( $done ) {
					$branch = $done;
				} else {
					$branch = self::passes_conditions( $step['rules'] ?? array(), $user_id, $contact, $context ) ? 'yes' : 'no';
					self::mark( $run_id, $step_id, 'condition', '', 'done', $branch );
				}
				$step_id = (string) ( $step[ $branch ] ?? '' );
				continue;
			}

			// Acción.
			if ( $done ) {
				$step_id = (string) ( $step['next'] ?? '' );
				continue;
			}
			if ( self::over_daily_limit( $user_id, $contact ) ) {
				self::park( $run_id, $step_id, strtotime( 'tomorrow' ) + 300, (int) $run->attempts, $context, 'daily_limit' );
				return true;
			}
			$action                     = (array) ( $step['action'] ?? array() );
			$ctx                        = $context;
			$ctx['_atora_run']          = $run_id;
			$ctx['_atora_step_key']     = 'flow_' . $run_id . '_' . $step_id;
			self::$chain[]              = (int) $run->automation_id;
			foreach ( array_filter( explode( ',', (string) $run->chain ) ) as $parent ) {
				self::$chain[] = (int) $parent;
			}
			try {
				$ok = Automation_Engine::perform_action( $action, $user_id, $ctx );
			} catch ( \Throwable $e ) {
				$ok = false;
			} finally {
				self::$chain = array();
			}
			if ( ! $ok ) {
				$attempts = (int) $run->attempts + 1;
				if ( $attempts >= self::MAX_ATTEMPTS ) {
					self::mark( $run_id, $step_id, 'action', sanitize_key( (string) ( $action['type'] ?? '' ) ), 'failed', '' );
					self::finish( $run_id, 'failed', 'action_failed:' . $step_id );
					return true;
				}
				self::park( $run_id, $step_id, time() + (int) pow( 5, $attempts ) * MINUTE_IN_SECONDS, $attempts, $context, 'retry' );
				return true;
			}
			self::mark( $run_id, $step_id, 'action', sanitize_key( (string) ( $action['type'] ?? '' ) ), 'done', '' );
			$wpdb->update( $runs, array( 'attempts' => 0 ), array( 'id' => $run_id ) );
			$run->attempts = 0;
			$step_id       = (string) ( $step['next'] ?? '' );
		}

		if ( '' === $step_id ) {
			self::finish( $run_id, 'completed', '' );
		} else {
			self::park( $run_id, $step_id, time(), (int) $run->attempts, $context ); // Más pasos que el tope: sigue en el próximo ciclo.
		}
		return true;
	}

	private static function wake_time( array $step, array $context ): int {
		if ( ! empty( $step['until'] ) ) {
			return (int) strtotime( (string) $step['until'] . ' UTC' );
		}
		if ( ! empty( $step['until_field'] ) ) {
			$base = strtotime( (string) ( $context[ (string) $step['until_field'] ] ?? '' ) . ' UTC' );
			return false === $base ? time() : $base + (int) ( $step['offset_minutes'] ?? 0 ) * MINUTE_IN_SECONDS;
		}
		return time() + absint( $step['minutes'] ?? 0 ) * MINUTE_IN_SECONDS;
	}

	private static function step_done( int $run_id, string $step_id ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, detail FROM ' . self::t( 'atora_automation_run_steps' ) . ' WHERE run_id = %d AND step_id = %s', $run_id, $step_id ) ); // phpcs:ignore WordPress.DB
		if ( ! $row || 'done' !== $row->status ) {
			return '';
		}
		return '' !== (string) $row->detail ? (string) $row->detail : 'done';
	}

	private static function mark( int $run_id, string $step_id, string $type, string $action, string $status, string $detail ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'INSERT INTO ' . self::t( 'atora_automation_run_steps' ) . ' (run_id, step_id, step_type, action_type, status, detail, executed_at) VALUES (%d, %s, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE status = VALUES(status), detail = VALUES(detail), executed_at = VALUES(executed_at)',
			$run_id,
			$step_id,
			$type,
			$action,
			$status,
			$detail,
			current_time( 'mysql', true )
		) );
	}

	private static function park( int $run_id, string $step_id, int $wake, int $attempts, array $context, string $note = '' ): void {
		global $wpdb;
		$wpdb->update( self::t( 'atora_automation_runs' ), array(
			'status'       => 'waiting',
			'current_step' => $step_id,
			'wake_at'      => gmdate( 'Y-m-d H:i:s', $wake ),
			'attempts'     => $attempts,
			'context'      => wp_json_encode( $context ),
			'exit_reason'  => $note,
			'updated_at'   => current_time( 'mysql', true ),
		), array( 'id' => $run_id ) );
		if ( '' === $step_id ) {
			self::finish( $run_id, 'completed', '' );
		}
	}

	private static function finish( int $run_id, string $status, string $reason ): void {
		global $wpdb;
		$wpdb->update( self::t( 'atora_automation_runs' ), array(
			'status'      => $status,
			'exit_reason' => $reason,
			'updated_at'  => current_time( 'mysql', true ),
			'finished_at' => current_time( 'mysql', true ),
		), array( 'id' => $run_id ) );
	}

	/** Reintenta un recorrido fallido desde el paso donde falló (lo hecho no se repite). */
	public static function retry( int $run_id ): bool {
		global $wpdb;
		$step = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT current_step FROM ' . self::t( 'atora_automation_runs' ) . " WHERE id = %d AND status = 'failed'", $run_id ) ); // phpcs:ignore WordPress.DB
		if ( '' === $step ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'atora_automation_run_steps' ) . " WHERE run_id = %d AND step_id = %s AND status = 'failed'", $run_id, $step ) ); // phpcs:ignore WordPress.DB
		return (bool) $wpdb->update( self::t( 'atora_automation_runs' ), array( 'status' => 'waiting', 'attempts' => 0, 'wake_at' => current_time( 'mysql', true ), 'exit_reason' => '', 'finished_at' => null ), array( 'id' => $run_id ) );
	}

	private static function over_daily_limit( int $user_id, int $contact_id ): bool {
		global $wpdb;
		$limit = absint( get_option( self::OPTION_LIMIT, 20 ) );
		if ( $limit <= 0 ) {
			return false;
		}
		$who  = $user_id > 0 ? $wpdb->prepare( 'r.user_id = %d', $user_id ) : $wpdb->prepare( 'r.contact_id = %d', $contact_id );
		$used = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT COUNT(*) FROM ' . self::t( 'atora_automation_run_steps' ) . ' s JOIN ' . self::t( 'atora_automation_runs' ) . " r ON r.id = s.run_id
			 WHERE {$who} AND r.is_test = 0 AND s.step_type = 'action' AND s.status = 'done' AND s.executed_at >= %s",
			gmdate( 'Y-m-d 00:00:00' )
		) );
		return $used >= $limit;
	}

	// ── Condiciones ─────────────────────────────────────────────────────────

	/**
	 * Reglas: {operator: AND|OR, rules: [{field, operator, value}]}. Campos: los del contexto, la meta `atora_{campo}`
	 * y estos especiales: has_tag, risk_level, course_progress, course_completed, submitted_lesson.
	 */
	public static function passes_conditions( $conditions, int $user_id, int $contact_id, array $context ): bool {
		$rules = is_array( $conditions ) ? (array) ( $conditions['rules'] ?? array() ) : array();
		if ( ! $rules ) {
			return true;
		}
		$results = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$field     = sanitize_key( (string) ( $rule['field'] ?? '' ) );
			$op        = sanitize_key( (string) ( $rule['operator'] ?? 'equals' ) );
			$expected  = $rule['value'] ?? '';
			$actual    = self::field_value( $field, (string) $expected, $user_id, $contact_id, $context );
			switch ( $op ) {
				case 'not_equals':
					$results[] = (string) $actual !== (string) $expected;
					break;
				case 'greater_than':
					$results[] = is_numeric( $actual ) && (float) $actual > (float) $expected;
					break;
				case 'less_than':
					$results[] = is_numeric( $actual ) && (float) $actual < (float) $expected;
					break;
				case 'contains':
					$results[] = '' !== (string) $expected && false !== strpos( (string) $actual, (string) $expected );
					break;
				case 'is_true':
					$results[] = ! empty( $actual );
					break;
				case 'is_false':
					$results[] = empty( $actual );
					break;
				default:
					$results[] = (string) $actual === (string) $expected;
			}
		}
		if ( ! $results ) {
			return true;
		}
		return 'OR' === strtoupper( (string) ( $conditions['operator'] ?? 'AND' ) ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	private static function field_value( string $field, string $expected, int $user_id, int $contact_id, array $context ) {
		global $wpdb;
		$course_id = absint( $context['course_id'] ?? 0 );
		switch ( $field ) {
			case 'has_tag':
				if ( $contact_id <= 0 ) {
					return '';
				}
				return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'atora_contact_tags' ) . ' WHERE contact_id = %d AND tag_name = %s', $contact_id, $expected ) ) ? $expected : ''; // phpcs:ignore WordPress.DB
			case 'risk_level':
				if ( $user_id <= 0 || ! $course_id || ! function_exists( 'clms_core' ) ) {
					return '';
				}
				$service = clms_core( 'CLMS_Academic_Status_Service' );
				$status  = $service && method_exists( $service, 'get_student_course_status' ) ? (array) $service->get_student_course_status( $user_id, $course_id ) : array();
				return (string) ( $status['risk_level'] ?? '' );
			case 'course_progress':
				return $user_id > 0 && $course_id ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT progress_pct FROM ' . self::t( 'atora_enrollments' ) . ' WHERE user_id = %d AND (wp_course_id = %d OR course_id = %d) LIMIT 1', $user_id, $course_id, $course_id ) ) : 0; // phpcs:ignore WordPress.DB
			case 'course_completed':
				return $user_id > 0 && $course_id && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'atora_enrollments' ) . " WHERE user_id = %d AND (wp_course_id = %d OR course_id = %d) AND (status = 'completed' OR completed_at IS NOT NULL) LIMIT 1", $user_id, $course_id, $course_id ) ); // phpcs:ignore WordPress.DB
			case 'submitted_lesson':
				$lesson = absint( $context['lesson_id'] ?? 0 );
				if ( $user_id <= 0 || ! $lesson ) {
					return false;
				}
				return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
					"SELECT 1 FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = '_clms_submission_user_id' AND u.meta_value = %d
					 JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_clms_submission_lesson_id' AND l.meta_value = %d
					 WHERE p.post_type = 'lm_submission' LIMIT 1",
					$user_id,
					$lesson
				) );
		}
		if ( array_key_exists( $field, $context ) ) {
			return $context[ $field ];
		}
		return $user_id > 0 ? get_user_meta( $user_id, 'atora_' . $field, true ) : '';
	}

	// ── Modo prueba ─────────────────────────────────────────────────────────

	/**
	 * Recorre el flujo con los datos actuales de un contacto sin ejecutar nada.
	 *
	 * @return array<int,array{step:string,type:string,detail:string}>
	 */
	public static function simulate( array $def, int $user_id, int $contact_id, array $context = array() ): array {
		$steps = self::steps_by_id( $def );
		$path  = array();
		$id    = (string) ( $def['start'] ?? '' );
		$guard = 0;
		while ( '' !== $id && isset( $steps[ $id ] ) && $guard++ < 100 ) {
			if ( ! empty( $def['goal']['rules'] ) && self::passes_conditions( $def['goal'], $user_id, $contact_id, $context ) ) {
				$path[] = array( 'step' => $id, 'type' => 'goal', 'detail' => __( 'Objetivo cumplido: sale del flujo.', 'atora-lms' ) );
				break;
			}
			$step = $steps[ $id ];
			$type = (string) $step['type'];
			if ( 'condition' === $type ) {
				$branch = self::passes_conditions( $step['rules'] ?? array(), $user_id, $contact_id, $context ) ? 'yes' : 'no';
				$path[] = array( 'step' => $id, 'type' => 'condition', 'detail' => 'yes' === $branch ? __( 'sí', 'atora-lms' ) : __( 'no', 'atora-lms' ) );
				$id     = (string) ( $step[ $branch ] ?? '' );
				continue;
			}
			$detail = 'wait' === $type
				? ( ! empty( $step['minutes'] ) ? sprintf( /* translators: %d: minutes */ __( 'espera %d min', 'atora-lms' ), absint( $step['minutes'] ) ) : __( 'espera hasta una fecha', 'atora-lms' ) )
				: (string) ( $step['action']['type'] ?? '' );
			$path[] = array( 'step' => $id, 'type' => $type, 'detail' => $detail );
			$id     = (string) ( $step['next'] ?? '' );
		}
		return $path;
	}

	// ── Plantillas de flujo ─────────────────────────────────────────────────

	/** @return array<string,array{name:string,description:string,trigger_type:string,definition:array}> */
	public static function templates(): array {
		return array(
			'bienvenida_curso'   => array(
				'name'         => __( 'Bienvenida a un curso', 'atora-lms' ),
				'description'  => __( 'Correo de bienvenida al matricularse; a los 3 días, si no avanzó, un recordatorio.', 'atora-lms' ),
				'trigger_type' => 'course_enrolled',
				'definition'   => array(
					'start' => 'bienvenida',
					'steps' => array(
						array( 'id' => 'bienvenida', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'welcome_course' ), 'next' => 'espera' ),
						array( 'id' => 'espera', 'type' => 'wait', 'minutes' => 4320, 'next' => 'avanzo' ),
						array( 'id' => 'avanzo', 'type' => 'condition', 'rules' => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'course_progress', 'operator' => 'greater_than', 'value' => '0' ) ) ), 'yes' => null, 'no' => 'recordatorio' ),
						array( 'id' => 'recordatorio', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'inactivity_reminder' ), 'next' => null ),
					),
				),
			),
			'tarea_por_vencer'   => array(
				'name'         => __( 'Recordatorio de tarea por vencer', 'atora-lms' ),
				'description'  => __( 'Cuando una tarea está por vencer y no hay entrega: aviso en el buzón y correo. Sale si entrega.', 'atora-lms' ),
				'trigger_type' => 'assignment_due_soon',
				'definition'   => array(
					'start'         => 'aviso',
					'allow_reentry' => true,
					'goal'          => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'submitted_lesson', 'operator' => 'is_true', 'value' => '' ) ) ),
					'steps'         => array(
						array( 'id' => 'aviso', 'type' => 'action', 'action' => array( 'type' => 'inbox_message', 'title' => __( 'Tarea por vencer', 'atora-lms' ), 'message' => __( 'Tu tarea vence pronto: {{due_at}}.', 'atora-lms' ) ), 'next' => 'correo' ),
						array( 'id' => 'correo', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'atora_assignment_due_soon' ), 'next' => null ),
					),
				),
			),
			'estudiante_riesgo'  => array(
				'name'         => __( 'Estudiante en riesgo', 'atora-lms' ),
				'description'  => __( 'Con la señal de riesgo del servicio académico: tarea al docente y, si sigue en riesgo alto a la semana, plan de acompañamiento.', 'atora-lms' ),
				'trigger_type' => 'student_at_risk',
				'definition'   => array(
					'start'         => 'docente',
					'allow_reentry' => true,
					'steps'         => array(
						array( 'id' => 'docente', 'type' => 'action', 'action' => array( 'type' => 'teacher_task', 'title' => __( 'Estudiante en riesgo', 'atora-lms' ), 'message' => __( 'Revisa su situación y contacta al estudiante.', 'atora-lms' ) ), 'next' => 'etiqueta' ),
						array( 'id' => 'etiqueta', 'type' => 'action', 'action' => array( 'type' => 'add_tag', 'tag' => 'en-riesgo' ), 'next' => 'espera' ),
						array( 'id' => 'espera', 'type' => 'wait', 'minutes' => 10080, 'next' => 'sigue' ),
						array( 'id' => 'sigue', 'type' => 'condition', 'rules' => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'risk_level', 'operator' => 'equals', 'value' => 'high' ) ) ), 'yes' => 'escala', 'no' => 'quitar' ),
						array( 'id' => 'escala', 'type' => 'action', 'action' => array( 'type' => 'teacher_task', 'title' => __( 'Sigue en riesgo alto', 'atora-lms' ), 'message' => __( 'Una semana después sigue en riesgo alto: considera un plan de mejora.', 'atora-lms' ) ), 'next' => null ),
						array( 'id' => 'quitar', 'type' => 'action', 'action' => array( 'type' => 'remove_tag', 'tag' => 'en-riesgo' ), 'next' => null ),
					),
				),
			),
			'recuperar_inactivo' => array(
				'name'         => __( 'Recuperación de inactivos', 'atora-lms' ),
				'description'  => __( 'Tras días sin actividad: correo, luego aviso en el buzón; si vuelve, sale.', 'atora-lms' ),
				'trigger_type' => 'inactivity_detected',
				'definition'   => array(
					'start' => 'correo',
					'goal'  => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'course_completed', 'operator' => 'is_true', 'value' => '' ) ) ),
					'steps' => array(
						array( 'id' => 'correo', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'inactivity_reminder' ), 'next' => 'espera' ),
						array( 'id' => 'espera', 'type' => 'wait', 'minutes' => 4320, 'next' => 'buzon' ),
						array( 'id' => 'buzon', 'type' => 'action', 'action' => array( 'type' => 'inbox_message', 'title' => __( 'Te esperamos', 'atora-lms' ), 'message' => __( 'Retoma tu curso cuando puedas: tu avance está guardado.', 'atora-lms' ) ), 'next' => null ),
					),
				),
			),
			'felicitar_cert'     => array(
				'name'         => __( 'Felicitación por certificado', 'atora-lms' ),
				'description'  => __( 'Al emitirse un certificado: correo de felicitación y etiqueta de egresado.', 'atora-lms' ),
				'trigger_type' => 'certificate_issued',
				'definition'   => array(
					'start'         => 'correo',
					'allow_reentry' => true,
					'steps'         => array(
						array( 'id' => 'correo', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'certificate_ready' ), 'next' => 'etiqueta' ),
						array( 'id' => 'etiqueta', 'type' => 'action', 'action' => array( 'type' => 'add_tag', 'tag' => 'egresado' ), 'next' => null ),
					),
				),
			),
		);
	}

	/** Crea un flujo (inactivo) desde una plantilla. @return int|\WP_Error */
	public static function create_from_template( string $key ) {
		$templates = self::templates();
		if ( ! isset( $templates[ $key ] ) ) {
			return new \WP_Error( 'atora_flow_template', __( 'Esa plantilla de flujo no existe.', 'atora-lms' ) );
		}
		$t = $templates[ $key ];
		return self::save( array(
			'name'         => $t['name'],
			'description'  => $t['description'],
			'trigger_type' => $t['trigger_type'],
			'definition'   => $t['definition'],
			'active'       => 0,
		) );
	}
}
