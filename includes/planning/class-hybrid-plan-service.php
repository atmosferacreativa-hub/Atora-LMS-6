<?php
/**
 * Planificación híbrida (6.37.0): un recorrido guiado que termina en lecciones del curso.
 *
 * - Pasos: objetivo, encuentro presencial, trabajo a distancia, recursos, evidencia, acompañamiento.
 * - Completo sin IA: crear, guardar (con revisión para no pisar cambios), editar, duplicar, vista previa y
 *   publicar. La IA solo sugiere textos (ATORA_Hybrid_Plan_Service::suggest) y el docente decide.
 * - Publicar crea o actualiza (nunca duplica) hasta tres lecciones: trabajo a distancia, encuentro presencial y
 *   evidencia. El mapa plan → lecciones se guarda tras cada lección creada, así un reintento tras un corte no
 *   duplica. Una lección ya publicada se actualiza; la rúbrica de una evidencia con entregas no se cambia.
 * - Permisos: edita quien gestiona lecciones y tiene el curso en su alcance (ATORA_Teacher_Scope). Publica el
 *   docente; el asistente (rol `lms_instructor_assistant` sin ser autor ni docente de sección) prepara y edita,
 *   pero no publica ni cambia la evidencia (evaluación). El coordinador no gestiona lecciones: sin acceso.
 *
 * @package ATORA_LMS
 * @since 6.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Hybrid_Plan_Service {

	const AI_FEATURE    = 'hybrid_planner';
	const MAX_RESOURCES = 30;
	const RESOURCE_TYPES = array( 'link', 'archivo', 'pdf', 'audio', 'video' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_hybrid_plans';
	}

	// ── Datos ────────────────────────────────────────────────────────────────

	/** Plantilla vacía del recorrido (también la usa la vista). */
	public static function empty_data(): array {
		return array(
			'title'      => '',
			'week_label' => '',
			'objective'  => '',
			'in_person'  => array( 'date' => '', 'time' => '', 'place' => '', 'activities' => '' ),
			'remote'     => array( 'before' => '', 'after' => '' ),
			'resources'  => array(),
			'evidence'   => array( 'enabled' => false, 'title' => '', 'instructions' => '', 'due_date' => '', 'due_time' => '', 'rubric_id' => 0, 'criteria' => '' ),
			'support'    => array( 'how' => '', 'when' => '' ),
		);
	}

	/** @param mixed $raw */
	public static function sanitize_data( $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$data = self::empty_data();

		$data['title']      = self::text( $raw['title'] ?? '', 200 );
		$data['week_label'] = self::text( $raw['week_label'] ?? '', 100 );
		$data['objective']  = self::area( $raw['objective'] ?? '', 5000 );

		$in = is_array( $raw['in_person'] ?? null ) ? $raw['in_person'] : array();
		$data['in_person'] = array(
			'date'       => self::date( $in['date'] ?? '' ),
			'time'       => self::time( $in['time'] ?? '' ),
			'place'      => self::text( $in['place'] ?? '', 200 ),
			'activities' => self::area( $in['activities'] ?? '', 8000 ),
		);

		$re = is_array( $raw['remote'] ?? null ) ? $raw['remote'] : array();
		$data['remote'] = array(
			'before' => self::area( $re['before'] ?? '', 8000 ),
			'after'  => self::area( $re['after'] ?? '', 8000 ),
		);

		$resources = array();
		foreach ( array_slice( (array) ( $raw['resources'] ?? array() ), 0, self::MAX_RESOURCES ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type    = sanitize_key( (string) ( $item['type'] ?? 'link' ) );
			$type    = in_array( $type, self::RESOURCE_TYPES, true ) ? $type : 'link';
			$file_id = absint( $item['file_id'] ?? 0 );
			if ( $file_id && ( ! function_exists( 'get_post_type' ) || 'attachment' !== get_post_type( $file_id ) ) ) {
				$file_id = 0;
			}
			$url   = function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) ( $item['url'] ?? '' ) ) : (string) ( $item['url'] ?? '' );
			$title = self::text( $item['title'] ?? '', 200 );
			if ( '' === $title && ! $file_id && '' === $url ) {
				continue;
			}
			$resources[] = array(
				'type'        => $type,
				'title'       => $title,
				'description' => self::area( $item['description'] ?? '', 1000 ),
				'url'         => $url,
				'file_id'     => $file_id,
				'transcript'  => 'audio' === $type ? self::area( $item['transcript'] ?? '', 60000 ) : '',
			);
		}
		$data['resources'] = $resources;

		$ev = is_array( $raw['evidence'] ?? null ) ? $raw['evidence'] : array();
		$rubric_id = absint( $ev['rubric_id'] ?? 0 );
		if ( $rubric_id && function_exists( 'get_post_type' ) && 'clms_rubric' !== get_post_type( $rubric_id ) ) {
			$rubric_id = 0;
		}
		$data['evidence'] = array(
			'enabled'      => ! empty( $ev['enabled'] ),
			'title'        => self::text( $ev['title'] ?? '', 200 ),
			'instructions' => self::area( $ev['instructions'] ?? '', 8000 ),
			'due_date'     => self::date( $ev['due_date'] ?? '' ),
			'due_time'     => self::time( $ev['due_time'] ?? '' ),
			'rubric_id'    => $rubric_id,
			'criteria'     => self::area( $ev['criteria'] ?? '', 5000 ),
		);

		$su = is_array( $raw['support'] ?? null ) ? $raw['support'] : array();
		$data['support'] = array(
			'how'  => self::area( $su['how'] ?? '', 4000 ),
			'when' => self::text( $su['when'] ?? '', 500 ),
		);

		return $data;
	}

	/**
	 * Lecciones que produce la planificación (regla pura, probada aparte).
	 *
	 * @return array<int,array{key:string,title:string,content:string,meta:array,resources:array}>
	 */
	public static function lesson_specs( array $data ): array {
		$data  = self::sanitize_light( $data );
		$title = '' !== $data['title'] ? $data['title'] : self::t( 'Planificación' );
		$label = '' !== $data['week_label'] ? $data['week_label'] . ' · ' : '';
		$specs = array();

		$objective = '' !== $data['objective'] ? self::section( self::t( 'Objetivo de aprendizaje' ), $data['objective'] ) : '';
		$support   = self::support_html( $data['support'] );

		// Trabajo a distancia (con los recursos).
		if ( '' !== $data['remote']['before'] || '' !== $data['remote']['after'] || ! empty( $data['resources'] ) ) {
			$specs[] = array(
				'key'       => 'remote',
				'title'     => $label . $title . ' · ' . self::t( 'Trabajo a distancia' ),
				'content'   => $objective
					. ( '' !== $data['remote']['before'] ? self::section( self::t( 'Antes del encuentro' ), $data['remote']['before'] ) : '' )
					. ( '' !== $data['remote']['after'] ? self::section( self::t( 'Después del encuentro' ), $data['remote']['after'] ) : '' )
					. $support,
				'meta'      => array(),
				'resources' => self::lesson_resources( $data['resources'] ),
			);
		}

		// Encuentro presencial.
		if ( '' !== $data['in_person']['activities'] || '' !== $data['in_person']['date'] ) {
			$when = trim( $data['in_person']['date'] . ' ' . $data['in_person']['time'] );
			$where = $data['in_person']['place'];
			$info  = array_filter( array( '' !== $when ? self::t( 'Fecha' ) . ': ' . $when : '', '' !== $where ? self::t( 'Lugar' ) . ': ' . $where : '' ) );
			$specs[] = array(
				'key'       => 'in_person',
				'title'     => $label . $title . ' · ' . self::t( 'Encuentro presencial' ),
				'content'   => $objective
					. ( $info ? self::paragraph( implode( ' · ', $info ) ) : '' )
					. ( '' !== $data['in_person']['activities'] ? self::section( self::t( 'Qué haremos en el aula' ), $data['in_person']['activities'] ) : '' ),
				// 6.40.0: marca de encuentro presencial (la app ofrece tomar lista en esta lección y ese día).
				'meta'      => array_filter( array(
					'_atora_in_person'      => '1',
					'_atora_in_person_date' => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $data['in_person']['date'] ) ? (string) $data['in_person']['date'] : '',
				) ),
				'resources' => array(),
			);
		}

		// Evidencia (tarea).
		$ev = $data['evidence'];
		if ( ! empty( $ev['enabled'] ) && ( '' !== $ev['title'] || '' !== $ev['instructions'] ) ) {
			$meta = array( 'lm_activity_type' => 'tarea' );
			if ( '' !== $ev['due_date'] ) {
				$meta['_clms_due_date'] = $ev['due_date'];
				$meta['_clms_due_time'] = '' !== $ev['due_time'] ? $ev['due_time'] : '23:59';
			}
			if ( ! empty( $ev['rubric_id'] ) ) {
				$meta['_clms_rubric_id'] = (int) $ev['rubric_id'];
			}
			$specs[] = array(
				'key'       => 'evidence',
				'title'     => $label . $title . ' · ' . ( '' !== $ev['title'] ? $ev['title'] : self::t( 'Evidencia' ) ),
				'content'   => ( '' !== $ev['instructions'] ? self::section( self::t( 'Qué entregar' ), $ev['instructions'] ) : '' )
					. ( '' !== $ev['criteria'] ? self::section( self::t( 'Criterios de evaluación' ), $ev['criteria'] ) : '' )
					. $support,
				'meta'      => $meta,
				'resources' => array(),
			);
		}

		return $specs;
	}

	// ── Permisos ─────────────────────────────────────────────────────────────

	public static function can_edit( int $user_id, int $wp_course_id ): bool {
		return $user_id > 0 && $wp_course_id > 0
			&& user_can( $user_id, 'edit_lm_lessons' )
			&& class_exists( 'ATORA_Teacher_Scope' )
			&& ATORA_Teacher_Scope::can_access_course( $user_id, $wp_course_id );
	}

	/** El asistente que solo llega al curso por delegación prepara, pero no publica ni cambia la evaluación. */
	public static function is_assistant_only( int $user_id, int $wp_course_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'lms_instructor_assistant', (array) $user->roles, true ) ) {
			return false;
		}
		if ( array_intersect( array( 'administrator', 'lms_instructor' ), (array) $user->roles ) ) {
			return false;
		}
		$is_author = (int) get_post_field( 'post_author', $wp_course_id ) === $user_id;
		return ! $is_author && ! ATORA_Teacher_Scope::is_section_teacher( $user_id, $wp_course_id );
	}

	public static function can_publish( int $user_id, int $wp_course_id ): bool {
		return self::can_edit( $user_id, $wp_course_id )
			&& user_can( $user_id, 'publish_lm_lessons' )
			&& ! self::is_assistant_only( $user_id, $wp_course_id );
	}

	// ── CRUD ─────────────────────────────────────────────────────────────────

	/** @return array|WP_Error */
	public static function create( int $user_id, int $wp_course_id, $data ) {
		if ( ! self::can_edit( $user_id, $wp_course_id ) ) {
			return self::forbidden();
		}
		global $wpdb;
		$clean = self::sanitize_data( $data );
		if ( self::is_assistant_only( $user_id, $wp_course_id ) ) {
			$clean['evidence'] = self::empty_data()['evidence'];
		}
		$ok = $wpdb->insert( self::table(), array(
			'institution_id' => self::institution( $wp_course_id ),
			'wp_course_id'   => $wp_course_id,
			'author_id'      => $user_id,
			'updated_by'     => $user_id,
			'title'          => $clean['title'],
			'status'         => 'draft',
			'data_json'      => wp_json_encode( $clean ),
			'revision'       => 1,
		) );
		if ( ! $ok ) {
			return self::db_error();
		}
		return self::get( (int) $wpdb->insert_id );
	}

	public static function get( int $plan_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $plan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::shape( $row ) : null;
	}

	/** @return array|WP_Error */
	public static function get_for_user( int $user_id, int $plan_id ) {
		$plan = self::get( $plan_id );
		if ( ! $plan || ! self::can_edit( $user_id, (int) $plan['wp_course_id'] ) ) {
			return new WP_Error( 'atora_plan_not_found', self::t( 'Planificación no encontrada.' ), array( 'status' => 404 ) );
		}
		$plan['can_publish'] = self::can_publish( $user_id, (int) $plan['wp_course_id'] );
		$plan['evidence_locked'] = self::is_assistant_only( $user_id, (int) $plan['wp_course_id'] );
		return $plan;
	}

	public static function list_for_course( int $user_id, int $wp_course_id ): array {
		if ( ! self::can_edit( $user_id, $wp_course_id ) ) {
			return array();
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wp_course_id = %d ORDER BY updated_at DESC, id DESC LIMIT 200', $wp_course_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( static function ( $row ) {
			$plan = self::shape( $row );
			unset( $plan['data'] );
			return $plan;
		}, $rows );
	}

	/**
	 * Guarda una revisión. Si alguien guardó antes (revisión distinta), 409 con la versión actual.
	 *
	 * @return array|WP_Error
	 */
	public static function update( int $user_id, int $plan_id, $data, int $expected_revision ) {
		$plan = self::get_for_user( $user_id, $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( $expected_revision !== (int) $plan['revision'] ) {
			return new WP_Error( 'atora_plan_conflict', self::t( 'Otra persona guardó esta planificación. Recarga para ver su versión.' ), array( 'status' => 409, 'current' => $plan ) );
		}
		$clean    = self::sanitize_data( $data );
		$warnings = array();
		if ( ! empty( $plan['evidence_locked'] ) && $clean['evidence'] !== $plan['data']['evidence'] ) {
			$clean['evidence'] = $plan['data']['evidence'];
			$warnings[]        = 'evidence_locked';
		}
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'UPDATE ' . self::table() . ' SET data_json = %s, title = %s, revision = revision + 1, updated_by = %d, updated_at = %s WHERE id = %d AND revision = %d',
			wp_json_encode( $clean ),
			$clean['title'],
			$user_id,
			current_time( 'mysql', true ),
			$plan_id,
			$expected_revision
		) );
		if ( false === $updated ) {
			return self::db_error();
		}
		if ( 0 === (int) $updated ) {
			$current = self::get_for_user( $user_id, $plan_id );
			return new WP_Error( 'atora_plan_conflict', self::t( 'Otra persona guardó esta planificación. Recarga para ver su versión.' ), array( 'status' => 409, 'current' => $current ) );
		}
		$fresh             = self::get_for_user( $user_id, $plan_id );
		$fresh['warnings'] = $warnings;
		return $fresh;
	}

	/** Copia como borrador nuevo, en el mismo curso u otro del alcance. @return array|WP_Error */
	public static function duplicate( int $user_id, int $plan_id, int $target_course = 0 ) {
		$plan = self::get_for_user( $user_id, $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$target = $target_course > 0 ? $target_course : (int) $plan['wp_course_id'];
		$data   = $plan['data'];
		$data['title'] = '' !== $data['title'] ? sprintf( self::t( '%s (copia)' ), $data['title'] ) : self::t( 'Planificación (copia)' );
		return self::create( $user_id, $target, $data );
	}

	// ── Vista previa y publicación ───────────────────────────────────────────

	/** @return array|WP_Error */
	public static function preview( int $user_id, int $plan_id ) {
		$plan = self::get_for_user( $user_id, $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$items = array();
		$specs = self::lesson_specs( $plan['data'] );
		foreach ( $specs as $spec ) {
			$lesson_id = self::mapped_lesson( $plan, $spec['key'] );
			$items[]   = array(
				'key'       => $spec['key'],
				'title'     => $spec['title'],
				'action'    => $lesson_id ? 'update' : 'create',
				'lesson_id' => $lesson_id,
				'content'   => $spec['content'],
				'resources' => count( $spec['resources'] ),
				'meta'      => $spec['meta'],
			);
		}
		$keys = wp_list_pluck( $specs, 'key' );
		foreach ( (array) $plan['lesson_map'] as $key => $lesson_id ) {
			if ( ! in_array( $key, $keys, true ) && self::lesson_exists( (int) $lesson_id ) ) {
				$items[] = array( 'key' => $key, 'title' => get_the_title( (int) $lesson_id ), 'action' => 'keep', 'lesson_id' => (int) $lesson_id, 'content' => '', 'resources' => 0, 'meta' => array() );
			}
		}
		return array( 'plan_id' => $plan_id, 'revision' => (int) $plan['revision'], 'can_publish' => $plan['can_publish'], 'items' => $items );
	}

	/**
	 * Crea o actualiza las lecciones. Idempotente: con el mismo plan nunca duplica.
	 *
	 * @return array|WP_Error
	 */
	public static function publish( int $user_id, int $plan_id, int $expected_revision ) {
		$plan = self::get_for_user( $user_id, $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( empty( $plan['can_publish'] ) ) {
			return new WP_Error( 'atora_plan_cannot_publish', self::t( 'Solo el docente del curso puede publicar. Puedes dejarla preparada.' ), array( 'status' => 403 ) );
		}
		if ( $expected_revision !== (int) $plan['revision'] ) {
			return new WP_Error( 'atora_plan_conflict', self::t( 'La planificación cambió desde la vista previa. Revisa los cambios antes de publicar.' ), array( 'status' => 409, 'current' => $plan ) );
		}
		$specs = self::lesson_specs( $plan['data'] );
		if ( ! $specs ) {
			return new WP_Error( 'atora_plan_empty', self::t( 'Completa al menos el trabajo a distancia, el encuentro o la evidencia antes de publicar.' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$lock = 'atora_plan_' . $plan_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new WP_Error( 'atora_plan_busy', self::t( 'La planificación se está publicando. Intenta de nuevo en unos segundos.' ), array( 'status' => 409, 'retryable' => true ) );
		}
		try {
			$plan  = self::get( $plan_id ); // Releer dentro del bloqueo.
			$map   = (array) $plan['lesson_map'];
			$course = (int) $plan['wp_course_id'];
			$order = self::next_menu_order( $course );
			$result = array();
			foreach ( $specs as $spec ) {
				$existing = self::mapped_lesson( array_merge( $plan, array( 'lesson_map' => $map ) ), $spec['key'] );
				$lesson   = $existing
					? self::update_lesson( $existing, $spec, $user_id )
					: self::insert_lesson( $course, $spec, $user_id, $order++ );
				if ( is_wp_error( $lesson ) ) {
					return $lesson;
				}
				$map[ $spec['key'] ] = (int) $lesson['lesson_id'];
				// Guardar el mapa tras cada lección: un reintento tras un corte no duplica.
				if ( false === $wpdb->update( self::table(), array( 'lesson_map_json' => wp_json_encode( $map ) ), array( 'id' => $plan_id ) ) ) {
					return self::db_error();
				}
				$result[] = array( 'key' => $spec['key'], 'title' => $spec['title'] ) + $lesson;
			}
			$now = current_time( 'mysql', true );
			$ok  = $wpdb->update( self::table(), array(
				'status'             => 'published',
				'published_revision' => (int) $plan['revision'],
				'published_at'       => $now,
				'published_by'       => $user_id,
			), array( 'id' => $plan_id ) );
			if ( false === $ok ) {
				return self::db_error();
			}
			return array( 'plan' => self::get_for_user( $user_id, $plan_id ), 'lessons' => $result );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	// ── IA opcional ──────────────────────────────────────────────────────────

	public static function ai_available(): bool {
		return class_exists( 'ATORA_AI_Usage_Service' ) && ATORA_AI_Usage_Service::available( self::AI_FEATURE );
	}

	/**
	 * Sugerencia de texto para un paso. Nunca escribe en la planificación: el docente la revisa y decide.
	 *
	 * @return array{field:string,text:string}|WP_Error
	 */
	public static function suggest( int $user_id, int $plan_id, string $field ) {
		$plan = self::get_for_user( $user_id, $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! self::ai_available() ) {
			return new WP_Error( 'atora_plan_ai_off', self::t( 'La ayuda con IA no está activada. La planificación funciona igual sin ella.' ), array( 'status' => 403 ) );
		}
		$prompts = array(
			'remote'     => 'Propón actividades a distancia (antes y después del encuentro) breves y realizables desde un teléfono con conexión intermitente.',
			'in_person'  => 'Propón actividades para el encuentro presencial que aprovechen el trabajo previo a distancia.',
			'evidence'   => 'Propón una evidencia de aprendizaje con instrucciones claras.',
			'criteria'   => 'Propón entre 3 y 5 criterios de evaluación observables para la evidencia.',
			'support'    => 'Propón cómo y cuándo acompañar y dar retroalimentación al estudiante.',
		);
		if ( ! isset( $prompts[ $field ] ) ) {
			return new WP_Error( 'atora_plan_ai_field', self::t( 'Paso no válido.' ), array( 'status' => 400 ) );
		}
		$data    = $plan['data'];
		$context = sprintf(
			"Curso: %s\nTema: %s\nObjetivo: %s\nEncuentro presencial: %s\nTrabajo a distancia: %s %s\nEvidencia: %s %s",
			get_the_title( (int) $plan['wp_course_id'] ),
			$data['title'],
			$data['objective'],
			$data['in_person']['activities'],
			$data['remote']['before'],
			$data['remote']['after'],
			$data['evidence']['title'],
			$data['evidence']['instructions']
		);
		$manager = function_exists( 'clms_core' ) ? clms_core( 'CLMS_AI_Manager' ) : null;
		if ( ! $manager || ! method_exists( $manager, 'chat_with_meta' ) ) {
			return new WP_Error( 'atora_plan_ai_missing', self::t( 'El servicio de IA no está disponible.' ), array( 'status' => 503 ) );
		}
		$result = $manager->chat_with_meta(
			array( array( 'role' => 'user', 'content' => $prompts[ $field ] . "\nResponde en español, en texto plano y breve.\n\n" . $context ) ),
			array( 'max_tokens' => 600, 'temperature' => 0.5, 'timeout' => 45, 'source' => self::AI_FEATURE, 'feature' => self::AI_FEATURE, 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 503 ) );
			return $result;
		}
		return array( 'field' => $field, 'text' => sanitize_textarea_field( (string) ( $result['text'] ?? '' ) ) );
	}

	// ── Internos ─────────────────────────────────────────────────────────────

	private static function shape( array $row ): array {
		$data = json_decode( (string) ( $row['data_json'] ?? '' ), true );
		$map  = json_decode( (string) ( $row['lesson_map_json'] ?? '' ), true );
		$revision           = (int) $row['revision'];
		$published_revision = (int) ( $row['published_revision'] ?? 0 );
		return array(
			'id'                 => (int) $row['id'],
			'wp_course_id'       => (int) $row['wp_course_id'],
			'author_id'          => (int) $row['author_id'],
			'title'              => (string) $row['title'],
			'status'             => (string) $row['status'],
			'revision'           => $revision,
			'published_revision' => $published_revision,
			'has_unpublished_changes' => 'published' === $row['status'] && $revision > $published_revision,
			'published_at'       => (string) ( $row['published_at'] ?? '' ),
			'updated_at'         => (string) ( $row['updated_at'] ?? '' ),
			'data'               => self::sanitize_light( is_array( $data ) ? $data : array() ),
			'lesson_map'         => is_array( $map ) ? array_map( 'absint', $map ) : array(),
		);
	}

	/** Completa claves sin volver a validar adjuntos (datos ya saneados al guardar). */
	private static function sanitize_light( array $data ): array {
		$base = self::empty_data();
		foreach ( $base as $key => $default ) {
			if ( is_array( $default ) && 'resources' !== $key ) {
				$data[ $key ] = array_merge( $default, is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array() );
			} elseif ( ! array_key_exists( $key, $data ) ) {
				$data[ $key ] = $default;
			}
		}
		$data['resources'] = array_values( array_filter( (array) $data['resources'], 'is_array' ) );
		return $data;
	}

	private static function mapped_lesson( array $plan, string $key ): int {
		$lesson_id = absint( $plan['lesson_map'][ $key ] ?? 0 );
		return self::lesson_exists( $lesson_id ) ? $lesson_id : 0;
	}

	private static function lesson_exists( int $lesson_id ): bool {
		return $lesson_id > 0 && 'lm_lesson' === get_post_type( $lesson_id ) && 'trash' !== get_post_status( $lesson_id );
	}

	private static function next_menu_order( int $wp_course_id ): int {
		global $wpdb;
		$max = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT MAX(p.menu_order) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_clms_course_id'
			 WHERE p.post_type = 'lm_lesson' AND p.post_status <> 'trash' AND m.meta_value = %s",
			(string) $wp_course_id
		) );
		return $max + 1;
	}

	/** @return array|WP_Error */
	private static function insert_lesson( int $course, array $spec, int $user_id, int $order ) {
		$lesson_id = wp_insert_post( array(
			'post_type'    => 'lm_lesson',
			'post_status'  => 'publish',
			'post_title'   => $spec['title'],
			'post_content' => $spec['content'],
			'post_author'  => $user_id,
			'menu_order'   => $order,
			'meta_input'   => array( '_clms_course_id' => $course ),
		), true );
		if ( is_wp_error( $lesson_id ) ) {
			return $lesson_id;
		}
		self::apply_meta( (int) $lesson_id, $spec, true );
		return array( 'lesson_id' => (int) $lesson_id, 'action' => 'created', 'notes' => array() );
	}

	/** @return array|WP_Error */
	private static function update_lesson( int $lesson_id, array $spec, int $user_id ) {
		$updated = wp_update_post( array(
			'ID'           => $lesson_id,
			'post_title'   => $spec['title'],
			'post_content' => $spec['content'],
			'post_status'  => 'publish',
		), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$notes = self::apply_meta( $lesson_id, $spec, false );
		return array( 'lesson_id' => $lesson_id, 'action' => 'updated', 'notes' => $notes );
	}

	/** @return string[] notas para el docente */
	private static function apply_meta( int $lesson_id, array $spec, bool $is_new ): array {
		$notes = array();
		foreach ( $spec['meta'] as $key => $value ) {
			if ( '_clms_rubric_id' === $key && ! $is_new && self::has_submissions( $lesson_id ) ) {
				$current = (int) get_post_meta( $lesson_id, '_clms_rubric_id', true );
				if ( $current !== (int) $value ) {
					$notes[] = 'rubric_kept';
				}
				continue;
			}
			update_post_meta( $lesson_id, $key, $value );
		}
		if ( 'remote' === $spec['key'] ) {
			update_post_meta( $lesson_id, '_clms_lesson_resources', $spec['resources'] );
		}
		// Re-guardar para que la sincronización del editor lleve la meta a las tablas.
		wp_update_post( array( 'ID' => $lesson_id ) );
		return $notes;
	}

	private static function has_submissions( int $lesson_id ): bool {
		$found = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_clms_submission_lesson_id', 'value' => $lesson_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		return ! empty( $found );
	}

	private static function lesson_resources( array $resources ): array {
		$out = array();
		foreach ( $resources as $resource ) {
			$out[] = array(
				'title'       => (string) ( $resource['title'] ?? '' ),
				'description' => (string) ( $resource['description'] ?? '' ),
				'type'        => (string) ( $resource['type'] ?? 'link' ),
				'file_id'     => (int) ( $resource['file_id'] ?? 0 ),
				'url'         => (string) ( $resource['url'] ?? '' ),
				'thumb_id'    => 0,
				'transcript'  => (string) ( $resource['transcript'] ?? '' ),
			);
		}
		return $out;
	}

	private static function support_html( array $support ): string {
		if ( '' === $support['how'] && '' === $support['when'] ) {
			return '';
		}
		$text = $support['how'] . ( '' !== $support['when'] ? "\n\n" . self::t( 'Cuándo' ) . ': ' . $support['when'] : '' );
		return self::section( self::t( 'Acompañamiento y retroalimentación' ), trim( $text ) );
	}

	private static function section( string $heading, string $text ): string {
		return '<h3>' . esc_html( $heading ) . "</h3>\n" . wpautop( esc_html( $text ) ) . "\n";
	}

	private static function paragraph( string $text ): string {
		return '<p>' . esc_html( $text ) . "</p>\n";
	}

	private static function institution( int $wp_course_id ): int {
		return class_exists( 'ATORA_Teacher_Scope' ) ? ATORA_Teacher_Scope::course_institution( $wp_course_id ) : 0;
	}

	private static function text( $value, int $max ): string {
		$text = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	private static function area( $value, int $max ): string {
		$text = sanitize_textarea_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	private static function date( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	private static function time( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{2}:\d{2}$/', $value ) ? $value : '';
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'atora_plan_forbidden', self::t( 'No puedes planificar en este curso.' ), array( 'status' => 403 ) );
	}

	private static function db_error(): WP_Error {
		return new WP_Error( 'atora_plan_db', self::t( 'No se pudo guardar. Intenta de nuevo.' ), array( 'status' => 503, 'retryable' => true ) );
	}

	/** Textos traducibles (literal en cada llamada para la extracción). */
	private static function t( string $text ): string {
		return __( $text, 'atora-lms' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
