<?php
/**
 * ATORA LMS — Mantenimiento
 *
 * Página de administración para mantener el plugin y la base de datos
 * ligeros: borrar usuarios con todos sus datos, purgar caché, limpiar
 * tablas huérfanas, reparar inscripciones, etc.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Maintenance {

	// ── Meta keys de usuario que gestiona el LMS ────────────────────────────

	const USER_META_KEYS = array(
		'_clms_enrolled_courses',
		'_clms_enrollment_dates',
		'_clms_enrolled_programs',
		'_clms_completed_lessons',
		'_clms_quiz_scores',
		'_clms_quiz_attempts',
		'_clms_lesson_progress',
		'_clms_course_completed',
		'_clms_course_access_expiry',
		'_clms_student_level',
		'_clms_student_interests',
		'_clms_student_goals',
		'_clms_student_memory',
		'_clms_ai_conversation',
		'_clms_ai_context',
		'_clms_peer_review_assigned',
		'_clms_dashboard_cache',
		'_clms_dashboard_cache_hash',
		'_clms_profile_bio',
		'session_tokens',            // Forzar logout al borrar.
	);

	// ── Inicialización ───────────────────────────────────────────────────────

	public function __construct() {
		add_action( 'wp_ajax_clms_maintenance_action', array( $this, 'handle_ajax' ) );
	}

	// ── AJAX central ────────────────────────────────────────────────────────

	public function handle_ajax(): void {
		check_ajax_referer( 'clms_maintenance', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		$action = isset( $_POST['maintenance_action'] ) ? sanitize_key( $_POST['maintenance_action'] ) : '';

		switch ( $action ) {

			case 'delete_user':
				wp_send_json( $this->action_delete_user() );
				break;

			case 'purge_cache':
				wp_send_json( $this->action_purge_cache() );
				break;

			case 'purge_transients':
				wp_send_json( $this->action_purge_transients() );
				break;

			case 'repair_enrollments':
				wp_send_json( $this->action_repair_enrollments() );
				break;

			case 'purge_orphan_submissions':
				wp_send_json( $this->action_purge_orphan_submissions() );
				break;

			case 'purge_orphan_invitations':
				wp_send_json( $this->action_purge_orphan_invitations() );
				break;

			case 'rebuild_course_index':
				wp_send_json( $this->action_rebuild_course_index() );
				break;

			case 'db_stats':
				wp_send_json_success( $this->get_db_stats() );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Acción desconocida.', 'atora-lms' ) ) );
		}
	}

	// ── Acciones de mantenimiento ────────────────────────────────────────────

	/**
	 * Elimina un usuario y, opcionalmente, todos sus datos LMS.
	 */
	private function action_delete_user(): array {
		$user_id     = absint( $_POST['user_id'] ?? 0 );
		$delete_data = ! empty( $_POST['delete_data'] );
		$reassign    = absint( $_POST['reassign_id'] ?? 0 ) ?: null;

		if ( ! $user_id ) {
			return array( 'success' => false, 'data' => array( 'message' => __( 'ID de usuario inválido.', 'atora-lms' ) ) );
		}

		if ( $user_id === get_current_user_id() ) {
			return array( 'success' => false, 'data' => array( 'message' => __( 'No puedes eliminarte a ti mismo.', 'atora-lms' ) ) );
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return array( 'success' => false, 'data' => array( 'message' => __( 'No se puede eliminar a un administrador desde aquí.', 'atora-lms' ) ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'success' => false, 'data' => array( 'message' => __( 'Usuario no encontrado.', 'atora-lms' ) ) );
		}

		$deleted_items = array();

		if ( $delete_data ) {
			// 1. Meta de usuario LMS.
			foreach ( self::USER_META_KEYS as $meta_key ) {
				delete_user_meta( $user_id, $meta_key );
			}
			$deleted_items[] = __( 'Metadatos LMS', 'atora-lms' );

			// 2. Retirar al usuario de los post meta de cursos inscritos.
			global $wpdb;
			$course_ids = (array) get_user_meta( $user_id, '_clms_enrolled_courses', true );
			foreach ( $course_ids as $cid ) {
				$cid = absint( $cid );
				if ( ! $cid ) continue;
				$enrolled = (array) get_post_meta( $cid, '_clms_enrolled_users', true );
				$enrolled = array_values( array_diff( $enrolled, array( $user_id ) ) );
				update_post_meta( $cid, '_clms_enrolled_users', $enrolled );
			}
			$deleted_items[] = __( 'Inscripciones en cursos', 'atora-lms' );

			// 3. Submissions del usuario.
			$submissions = get_posts( array(
				'post_type'      => 'clms_submission',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
				),
				'fields' => 'ids',
			) );
			foreach ( $submissions as $sid ) {
				wp_delete_post( $sid, true );
			}
			if ( ! empty( $submissions ) ) {
				$deleted_items[] = sprintf(
					/* translators: %d: number of submissions */
					_n( '%d entrega', '%d entregas', count( $submissions ), 'atora-lms' ),
					count( $submissions )
				);
			}

			// 4. Peer reviews creadas por el usuario.
			$peer_reviews = get_posts( array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'author'         => $user_id,
				'fields'         => 'ids',
			) );
			foreach ( $peer_reviews as $prid ) {
				wp_delete_post( $prid, true );
			}

			// 5. Invitaciones en la tabla custom.
			$invite_table = $this->get_invitations_table_name();
			$invitations_deleted = 0;

			if ( $this->table_exists( $invite_table ) ) {
				$invitations_deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$invite_table}
						 WHERE created_by = %d
						    OR invited_email = %s",
						$user_id,
						sanitize_email( (string) $user->user_email )
					)
				);
			}

			if ( $invitations_deleted ) {
				$deleted_items[] = sprintf(
					/* translators: %d: number of invitations */
					_n( '%d invitación', '%d invitaciones', $invitations_deleted, 'atora-lms' ),
					$invitations_deleted
				);
			}

			// 6. Transients de quizzes del usuario.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					'%_transient_clms_quiz_timer_' . $user_id . '_%',
					'%_transient_clms_quiz_lock_' . $user_id . '_%'
				)
			);
			$deleted_items[] = __( 'Transients de quiz', 'atora-lms' );
		}

		// 7. Borrar el usuario de WordPress.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$result = wp_delete_user( $user_id, $reassign );

		if ( ! $result ) {
			return array( 'success' => false, 'data' => array( 'message' => __( 'Error al eliminar el usuario.', 'atora-lms' ) ) );
		}

		$summary = $delete_data && ! empty( $deleted_items )
			? sprintf( __( 'Usuario "%s" eliminado junto con: %s.', 'atora-lms' ), $user->display_name, implode( ', ', $deleted_items ) )
			: sprintf( __( 'Usuario "%s" eliminado. Sus contenidos se conservaron.', 'atora-lms' ), $user->display_name );

		return array( 'success' => true, 'data' => array( 'message' => $summary ) );
	}

	/**
	 * Purga transients y caches del LMS.
	 */
	private function action_purge_cache(): array {
		global $wpdb;

		// Dashboard cache en user meta.
		$count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
				'_clms_dashboard_cache',
				'_clms_dashboard_cache_hash'
			)
		);

		// Object cache si está activo.
		if ( class_exists( 'CLMS_Cache' ) && method_exists( 'CLMS_Cache', 'flush' ) ) {
			CLMS_Cache::flush();
		} elseif ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'success' => true,
			'data'    => array(
				'message' => sprintf(
					/* translators: %d: number of cache records deleted */
					_n( '%d registro de caché eliminado.', '%d registros de caché eliminados.', (int) $count, 'atora-lms' ),
					(int) $count
				),
			),
		);
	}

	/**
	 * Purga transients expirados y propios del LMS.
	 */
	private function action_purge_transients(): array {
		global $wpdb;

		// Expirados de WP.
		$exp = $wpdb->query(
			$wpdb->prepare(
				"DELETE a, b FROM {$wpdb->options} a
				 INNER JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_transient_timeout_', '_transient_')
				 WHERE a.option_name LIKE %s
				   AND a.option_value < %d",
				'_transient_timeout_%',
				time()
			)
		);

		// Transients propios del LMS (quiz timers, locks).
		$lms = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s",
				'_transient_clms_%',
				'_transient_timeout_clms_%'
			)
		);

		$total = (int) $exp + (int) $lms;

		return array(
			'success' => true,
			'data'    => array(
				'message' => sprintf(
					/* translators: %d: total transients purged */
					_n( '%d transient eliminado.', '%d transients eliminados.', $total, 'atora-lms' ),
					$total
				),
			),
		);
	}

	/**
	 * Repara inscripciones: reconcilia user meta ↔ post meta.
	 * Detecta usuarios que figuran en un curso pero no en el otro lado.
	 */
	private function action_repair_enrollments(): array {
		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$repaired = 0;

		foreach ( $courses as $course_id ) {
			// Usuarios registrados EN el post meta del curso.
			$in_course = array_filter( array_map( 'absint', (array) get_post_meta( $course_id, '_clms_enrolled_users', true ) ) );

			foreach ( $in_course as $uid ) {
				$in_user = array_filter( array_map( 'absint', (array) get_user_meta( $uid, '_clms_enrolled_courses', true ) ) );
				if ( ! in_array( $course_id, $in_user, true ) ) {
					$in_user[] = $course_id;
					update_user_meta( $uid, '_clms_enrolled_courses', array_values( array_unique( $in_user ) ) );
					// F2.1: avisar al compat layer para que la tabla atora_enrollments no quede desincronizada.
					do_action( 'clms_user_enrolled', (int) $uid, (int) $course_id );
					$repaired++;
				}
			}

			// Usuarios registrados EN el user meta pero no en el post meta.
			$all_enrolled_users = get_users( array(
				'meta_key'   => '_clms_enrolled_courses',
				'meta_value' => $course_id,
				'fields'     => 'ids',
			) );
			foreach ( $all_enrolled_users as $uid ) {
				if ( ! in_array( (int) $uid, $in_course, true ) ) {
					$in_course[] = (int) $uid;
					$repaired++;
				}
			}

			update_post_meta( $course_id, '_clms_enrolled_users', array_values( array_unique( $in_course ) ) );
		}

		return array(
			'success' => true,
			'data'    => array(
				'message' => $repaired > 0
					? sprintf(
						/* translators: %d: number of repaired enrollments */
						_n( '%d inscripción reparada.', '%d inscripciones reparadas.', $repaired, 'atora-lms' ),
						$repaired
					)
					: __( 'Todas las inscripciones estaban sincronizadas. Nada que reparar.', 'atora-lms' ),
			),
		);
	}

	/**
	 * Elimina entregas (clms_submission) cuyo usuario ya no existe.
	 */
	private function action_purge_orphan_submissions(): array {
		global $wpdb;

		$submission_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 LEFT JOIN {$wpdb->users} u ON u.ID = pm.meta_value
				 WHERE p.post_type = %s
				   AND u.ID IS NULL",
				'_clms_submission_user_id',
				'clms_submission'
			)
		);

		foreach ( $submission_ids as $sid ) {
			wp_delete_post( (int) $sid, true );
		}

		$count = count( $submission_ids );

		return array(
			'success' => true,
			'data'    => array(
				'message' => $count > 0
					? sprintf(
						/* translators: %d: number of orphan submissions */
						_n( '%d entrega huérfana eliminada.', '%d entregas huérfanas eliminadas.', $count, 'atora-lms' ),
						$count
					)
					: __( 'No se encontraron entregas huérfanas.', 'atora-lms' ),
			),
		);
	}

	/**
	 * Elimina invitaciones expiradas o de usuarios que ya no existen.
	 */
	private function action_purge_orphan_invitations(): array {
		global $wpdb;

		$table = $this->get_invitations_table_name();

		// Verificar que la tabla existe.
		if ( ! $this->table_exists( $table ) ) {
			return array( 'success' => true, 'data' => array( 'message' => __( 'Tabla de invitaciones no encontrada.', 'atora-lms' ) ) );
		}

		$now_gmt = gmdate( 'Y-m-d H:i:s' );

		// Expiradas / agotadas / revocadas.
		$expired = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE status IN (%s, %s)
				    OR (expires_at IS NOT NULL AND expires_at < %s)
				    OR (max_uses > 0 AND used_count >= max_uses)",
				'revoked',
				'exhausted',
				$now_gmt
			)
		);

		// Con creador inexistente.
		$orphan_creator = $wpdb->query(
			"DELETE i FROM {$table} i
			 LEFT JOIN {$wpdb->users} u ON u.ID = i.created_by
			 WHERE i.created_by > 0 AND u.ID IS NULL"
		);

		// Con curso inexistente.
		$orphan_course = $wpdb->query(
			$wpdb->prepare(
				"DELETE i FROM {$table} i
				 LEFT JOIN {$wpdb->posts} p ON p.ID = i.course_id AND p.post_type = %s
				 WHERE i.course_id > 0 AND p.ID IS NULL",
				'lm_course'
			)
		);

		$total = (int) $expired + (int) $orphan_creator + (int) $orphan_course;

		return array(
			'success' => true,
			'data'    => array(
				'message' => $total > 0
					? sprintf(
						/* translators: %d: number of invitations purged */
						_n( '%d invitación purgada.', '%d invitaciones purgadas.', $total, 'atora-lms' ),
						$total
					)
					: __( 'No hay invitaciones que limpiar.', 'atora-lms' ),
			),
		);
	}

	/**
	 * Reconstruye el índice de lecciones por curso
	 * (post meta _clms_lesson_course_id en cada lección).
	 */
	private function action_rebuild_course_index(): array {
		$lessons = get_posts( array(
			'post_type'      => 'lm_lesson',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$fixed = 0;

		foreach ( $lessons as $lesson_id ) {
			$stored_course = (int) get_post_meta( $lesson_id, '_clms_lesson_course_id', true );
			if ( $stored_course && get_post_type( $stored_course ) === 'lm_course' ) {
				continue; // Ya está bien.
			}

			// Buscar el curso que lista esta lección.
			$courses = get_posts( array(
				'post_type'      => 'lm_course',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_clms_course_lessons',
						'value'   => '"' . $lesson_id . '"',
						'compare' => 'LIKE',
					),
				),
			) );

			if ( ! empty( $courses ) ) {
				update_post_meta( $lesson_id, '_clms_lesson_course_id', $courses[0] );
				$fixed++;
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'message' => $fixed > 0
					? sprintf(
						/* translators: %d: number of lessons re-indexed */
						_n( '%d lección reindexada.', '%d lecciones reindexadas.', $fixed, 'atora-lms' ),
						$fixed
					)
					: __( 'Todas las lecciones tienen su índice de curso correcto.', 'atora-lms' ),
			),
		);
	}

	// ── Estadísticas de BD ───────────────────────────────────────────────────

	private function get_invitations_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clms_invitations';
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function get_db_stats(): array {
		global $wpdb;

		$students    = count_users();
		$submissions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
				'clms_submission',
				'trash'
			)
		);
		$courses = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
				'lm_course',
				'trash'
			)
		);
		$lessons = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
				'lm_lesson',
				'trash'
			)
		);
		$orphan_sub  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 LEFT JOIN {$wpdb->users} u ON u.ID = pm.meta_value
				 WHERE p.post_type = %s AND u.ID IS NULL",
				'_clms_submission_user_id',
				'clms_submission'
			)
		);
		$transients  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_clms_%'
			)
		);
		$cache_rows  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
				'_clms_dashboard_cache',
				'_clms_dashboard_cache_hash'
			)
		);

		// Tamaño de tablas wp_posts + wp_postmeta + wp_usermeta + wp_options.
		$table_sizes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name AS tbl,
				        ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb
				 FROM information_schema.tables
				 WHERE table_schema = %s
				   AND table_name IN (%s, %s, %s, %s, %s)
				 ORDER BY size_mb DESC",
				DB_NAME,
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->usermeta,
				$wpdb->options,
				$wpdb->prefix . 'clms_invitations'
			)
		);

		return array(
			'students'    => $students['avail_roles']['lms_student'] ?? 0,
			'instructors' => $students['avail_roles']['lms_instructor'] ?? 0,
			'courses'     => $courses,
			'lessons'     => $lessons,
			'submissions' => $submissions,
			'orphan_sub'  => $orphan_sub,
			'transients'  => $transients,
			'cache_rows'  => $cache_rows,
			'tables'      => $table_sizes,
		);
	}

	// ── Render de la página ──────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'atora-lms' ) );
		}

		$nonce = wp_create_nonce( 'clms_maintenance' );
		$ajax  = admin_url( 'admin-ajax.php' );
		?>
		<div class="clms-maint-wrap">
			<div class="clms-maint-header">
				<h1><?php esc_html_e( 'Mantenimiento ATORA LMS', 'atora-lms' ); ?></h1>
				<p class="clms-maint-lead"><?php esc_html_e( 'Herramientas para mantener el plugin y la base de datos en óptimas condiciones.', 'atora-lms' ); ?></p>
			</div>

			<!-- Stats en vivo -->
			<div class="clms-maint-card" id="clms-maint-stats">
				<div class="clms-maint-card-head">
					<h2><?php esc_html_e( 'Estado actual de la base de datos', 'atora-lms' ); ?></h2>
					<button class="clms-maint-refresh-btn" id="clms-load-stats"><?php esc_html_e( 'Actualizar', 'atora-lms' ); ?></button>
				</div>
				<div id="clms-stats-body" class="clms-maint-stats-grid">
					<div class="clms-maint-stat"><span><?php esc_html_e( 'Cargando…', 'atora-lms' ); ?></span></div>
				</div>
			</div>

			<!-- Sección: Usuarios -->
			<div class="clms-maint-card">
				<div class="clms-maint-card-head">
					<h2><?php esc_html_e( 'Eliminar usuario', 'atora-lms' ); ?></h2>
					<span class="clms-maint-badge clms-maint-badge--danger"><?php esc_html_e( 'Irreversible', 'atora-lms' ); ?></span>
				</div>
				<p class="clms-maint-desc"><?php esc_html_e( 'Busca un estudiante o instructor por email y elimínalo. Puedes elegir si borrar también todos sus datos LMS (inscripciones, progreso, entregas, quizzes) o conservarlos.', 'atora-lms' ); ?></p>

				<div class="clms-maint-form-row">
					<input type="email"
						id="clms-del-user-email"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'email@ejemplo.com', 'atora-lms' ); ?>" />
					<button class="button" id="clms-del-user-search"><?php esc_html_e( 'Buscar', 'atora-lms' ); ?></button>
				</div>

				<div id="clms-del-user-info" class="clms-maint-user-info" hidden>
					<div class="clms-maint-user-card">
						<div>
							<strong id="clms-del-user-name"></strong>
							<span id="clms-del-user-email-display" class="clms-maint-muted"></span>
							<span id="clms-del-user-role" class="clms-maint-badge"></span>
						</div>
						<div class="clms-maint-user-meta">
							<span id="clms-del-user-courses"></span>
							<span id="clms-del-user-subs"></span>
						</div>
					</div>

					<div class="clms-maint-form-row" style="flex-direction:column;gap:12px">
						<label class="clms-maint-checkbox">
							<input type="checkbox" id="clms-del-data-check" checked />
							<span><?php esc_html_e( 'Eliminar todos los datos LMS (inscripciones, progreso, entregas, quizzes, conversaciones IA)', 'atora-lms' ); ?></span>
						</label>
						<label class="clms-maint-checkbox" id="clms-reassign-wrap">
							<input type="checkbox" id="clms-reassign-check" />
							<span><?php esc_html_e( 'Reasignar posts WordPress a otro usuario', 'atora-lms' ); ?></span>
						</label>
						<div id="clms-reassign-input" hidden>
							<input type="number" id="clms-reassign-id" class="small-text" placeholder="<?php esc_attr_e( 'ID usuario destino', 'atora-lms' ); ?>" />
						</div>
					</div>

					<div class="clms-maint-actions">
						<button class="button button-danger" id="clms-del-user-confirm" data-user-id="">
							<?php esc_html_e( 'Eliminar usuario', 'atora-lms' ); ?>
						</button>
						<button class="button" id="clms-del-user-cancel"><?php esc_html_e( 'Cancelar', 'atora-lms' ); ?></button>
					</div>
				</div>

				<div id="clms-del-user-result" class="clms-maint-result" hidden></div>
			</div>

			<!-- Sección: Limpieza de BD -->
			<div class="clms-maint-card">
				<div class="clms-maint-card-head">
					<h2><?php esc_html_e( 'Limpieza de base de datos', 'atora-lms' ); ?></h2>
				</div>
				<p class="clms-maint-desc"><?php esc_html_e( 'Elimina registros obsoletos que acumulan peso en la base de datos sin aportar valor.', 'atora-lms' ); ?></p>

				<div class="clms-maint-ops-grid">

					<div class="clms-maint-op">
						<div>
							<h3><?php esc_html_e( 'Purgar caché de dashboards', 'atora-lms' ); ?></h3>
							<p><?php esc_html_e( 'Borra los snapshots de métricas almacenados en user meta. Se regeneran al entrar al panel.', 'atora-lms' ); ?></p>
						</div>
						<button class="button button-primary clms-maint-op-btn"
							data-action="purge_cache">
							<?php esc_html_e( 'Purgar caché', 'atora-lms' ); ?>
						</button>
					</div>

					<div class="clms-maint-op">
						<div>
							<h3><?php esc_html_e( 'Purgar transients LMS', 'atora-lms' ); ?></h3>
							<p><?php esc_html_e( 'Elimina transients expirados y los timers/locks de quizzes en wp_options.', 'atora-lms' ); ?></p>
						</div>
						<button class="button button-primary clms-maint-op-btn"
							data-action="purge_transients">
							<?php esc_html_e( 'Purgar transients', 'atora-lms' ); ?>
						</button>
					</div>

					<div class="clms-maint-op">
						<div>
							<h3><?php esc_html_e( 'Entregas huérfanas', 'atora-lms' ); ?></h3>
							<p><?php esc_html_e( 'Borra entregas (clms_submission) de usuarios que ya no existen en el sistema.', 'atora-lms' ); ?></p>
						</div>
						<button class="button button-primary clms-maint-op-btn"
							data-action="purge_orphan_submissions">
							<?php esc_html_e( 'Limpiar entregas', 'atora-lms' ); ?>
						</button>
					</div>

					<div class="clms-maint-op">
						<div>
							<h3><?php esc_html_e( 'Invitaciones vencidas', 'atora-lms' ); ?></h3>
							<p><?php esc_html_e( 'Purga invitaciones ya usadas o con más de 90 días de antigüedad de la tabla clms_invitations.', 'atora-lms' ); ?></p>
						</div>
						<button class="button button-primary clms-maint-op-btn"
							data-action="purge_orphan_invitations">
							<?php esc_html_e( 'Limpiar invitaciones', 'atora-lms' ); ?>
						</button>
					</div>

				</div>
			</div>

			<!-- Sección: Integridad de datos -->
			<div class="clms-maint-card">
				<div class="clms-maint-card-head">
					<h2><?php esc_html_e( 'Integridad y reparación', 'atora-lms' ); ?></h2>
				</div>
				<p class="clms-maint-desc"><?php esc_html_e( 'Detecta y corrige inconsistencias en los datos sin borrar nada.', 'atora-lms' ); ?></p>

				<div class="clms-maint-ops-grid">

					<div class="clms-maint-op">
						<div>
							<h3><?php esc_html_e( 'Reparar inscripciones', 'atora-lms' ); ?></h3>
							<p><?php esc_html_e( 'Sincroniza user meta ↔ post meta de inscripciones. Resuelve desfases que impiden a estudiantes ver sus cursos.', 'atora-lms' ); ?></p>
						</div>
						<button class="button button-primary clms-maint-op-btn"
							data-action="repair_enrollments">
							<?php esc_html_e( 'Reparar', 'atora-lms' ); ?>
						</button>
					</div>

					<div class="clms-maint-op">
						<div>
							<h3><?php esc_html_e( 'Reconstruir índice de lecciones', 'atora-lms' ); ?></h3>
							<p><?php esc_html_e( 'Verifica y escribe el meta _clms_lesson_course_id en cada lección que lo tenga mal o vacío.', 'atora-lms' ); ?></p>
						</div>
						<button class="button button-primary clms-maint-op-btn"
							data-action="rebuild_course_index">
							<?php esc_html_e( 'Reconstruir', 'atora-lms' ); ?>
						</button>
					</div>

				</div>
			</div>

			<!-- Log de operaciones -->
			<div class="clms-maint-card">
				<div class="clms-maint-card-head">
					<h2><?php esc_html_e( 'Registro de operaciones', 'atora-lms' ); ?></h2>
					<button class="clms-maint-refresh-btn" id="clms-maint-clear-log"><?php esc_html_e( 'Limpiar', 'atora-lms' ); ?></button>
				</div>
				<div id="clms-maint-log" class="clms-maint-log">
					<p class="clms-maint-muted"><?php esc_html_e( 'Las operaciones realizadas en esta sesión aparecerán aquí.', 'atora-lms' ); ?></p>
				</div>
			</div>
		</div>

		<!-- CSS + JS inline -->
		<style>
		.clms-maint-wrap{max-width:900px;margin:24px 20px 60px;font-size:14px}
		.clms-maint-header{margin-bottom:28px}
		.clms-maint-header h1{font-size:22px;font-weight:700;margin:0 0 6px;color:#1d2327}
		.clms-maint-lead{color:#50575e;margin:0}
		.clms-maint-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px 24px;margin-bottom:20px}
		.clms-maint-card-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
		.clms-maint-card-head h2{margin:0;font-size:15px;font-weight:700;flex:1}
		.clms-maint-desc{color:#50575e;margin:0 0 18px;font-size:13px}
		.clms-maint-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;background:#f0f0f1;color:#50575e}
		.clms-maint-badge--danger{background:#fce8e8;color:#b32d2e}
		.clms-maint-form-row{display:flex;gap:8px;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap}
		.clms-maint-form-row .regular-text{flex:1;min-width:220px}
		.clms-maint-user-info{margin-top:16px;border-top:1px solid #f0f0f1;padding-top:16px}
		.clms-maint-user-card{display:flex;justify-content:space-between;align-items:flex-start;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px;padding:14px 16px;margin-bottom:16px;flex-wrap:wrap;gap:10px}
		.clms-maint-user-card strong{display:block;font-size:14px;margin-bottom:3px}
		.clms-maint-user-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:#50575e}
		.clms-maint-muted{color:#6b7280;font-size:13px}
		.clms-maint-checkbox{display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px}
		.clms-maint-checkbox input{margin-top:2px;flex-shrink:0}
		.clms-maint-actions{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}
		.button-danger{background:#b32d2e!important;border-color:#8e2122!important;color:#fff!important}
		.button-danger:hover{background:#8e2122!important}
		.clms-maint-ops-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
		.clms-maint-op{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:14px 16px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px}
		.clms-maint-op h3{margin:0 0 4px;font-size:13px;font-weight:700}
		.clms-maint-op p{margin:0;font-size:12px;color:#50575e}
		.clms-maint-op .button{flex-shrink:0;align-self:center}
		.clms-maint-op-btn.is-loading{opacity:.6;pointer-events:none}
		.clms-maint-result{margin-top:12px;padding:10px 14px;border-radius:6px;font-size:13px}
		.clms-maint-result.ok{background:#edfaef;border:1px solid #b7e4c7;color:#166534}
		.clms-maint-result.err{background:#fce8e8;border:1px solid #fca5a5;color:#7f1d1d}
		.clms-maint-log{max-height:280px;overflow-y:auto;border:1px solid #e0e0e0;border-radius:6px;padding:12px;background:#fafafa;font-size:12px;font-family:monospace}
		.clms-maint-log-entry{padding:4px 0;border-bottom:1px solid #f0f0f1;display:flex;gap:8px}
		.clms-maint-log-entry:last-child{border-bottom:none}
		.clms-maint-log-time{color:#9ca3af;flex-shrink:0}
		.clms-maint-log-msg{flex:1}
		.clms-maint-log-msg.ok{color:#166534}
		.clms-maint-log-msg.err{color:#b32d2e}
		.clms-maint-stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-top:12px}
		.clms-maint-stat{background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px;padding:12px 14px;text-align:center}
		.clms-maint-stat strong{display:block;font-size:22px;font-weight:800;color:#1d2327}
		.clms-maint-stat span{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
		.clms-maint-stat.warn strong{color:#b45309}
		.clms-maint-stat.danger strong{color:#b32d2e}
		.clms-maint-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:12px}
		.clms-maint-table th,.clms-maint-table td{text-align:left;padding:6px 10px;border-bottom:1px solid #f0f0f1}
		.clms-maint-table th{font-weight:700;color:#374151}
		.clms-maint-refresh-btn{background:none;border:1px solid #dcdcde;border-radius:4px;padding:3px 10px;font-size:12px;cursor:pointer;color:#2271b1}
		.clms-maint-refresh-btn:hover{background:#f0f6fc;border-color:#2271b1}
		</style>

		<script>
		(function(){
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( $ajax ); ?>;
			var foundUserId = 0;
			var log = document.getElementById('clms-maint-log');

			// ── Logger ──────────────────────────────────────────────────────
			function addLog(msg, isOk) {
				if (log.querySelector('.clms-maint-muted')) {
					log.innerHTML = '';
				}
				var now = new Date();
				var time = now.getHours().toString().padStart(2,'0') + ':' +
					now.getMinutes().toString().padStart(2,'0') + ':' +
					now.getSeconds().toString().padStart(2,'0');
				var el = document.createElement('div');
				el.className = 'clms-maint-log-entry';
				el.innerHTML = '<span class="clms-maint-log-time">' + time + '</span>' +
					'<span class="clms-maint-log-msg ' + (isOk ? 'ok' : 'err') + '">' +
					escHtml(msg) + '</span>';
				log.prepend(el);
			}

			document.getElementById('clms-maint-clear-log').addEventListener('click', function(){
				log.innerHTML = '<p class="clms-maint-muted"><?php echo esc_js( __( 'Las operaciones realizadas en esta sesión aparecerán aquí.', 'atora-lms' ) ); ?></p>';
			});

			function escHtml(s) {
				return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

			// ── AJAX helper ─────────────────────────────────────────────────
			function doAction(params, callback) {
				var body = new URLSearchParams(Object.assign({ action: 'clms_maintenance_action', nonce: nonce }, params));
				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				})
				.then(function(r){ return r.json(); })
				.then(function(json){ callback(null, json); })
				.catch(function(e){ callback(e, null); });
			}

			// ── Stats ────────────────────────────────────────────────────────
			function loadStats() {
				var el = document.getElementById('clms-stats-body');
				el.innerHTML = '<div class="clms-maint-stat"><span><?php echo esc_js( __( 'Cargando…', 'atora-lms' ) ); ?></span></div>';
				doAction({ maintenance_action: 'db_stats' }, function(err, json){
					if (err || !json.success) { el.innerHTML = '<p class="clms-maint-muted"><?php echo esc_js( __( 'Error al cargar estadísticas.', 'atora-lms' ) ); ?></p>'; return; }
					var d = json.data;
					var stats = [
						{ label: '<?php echo esc_js( __( 'Estudiantes', 'atora-lms' ) ); ?>', val: d.students },
						{ label: '<?php echo esc_js( __( 'Instructores', 'atora-lms' ) ); ?>', val: d.instructors },
						{ label: '<?php echo esc_js( __( 'Cursos', 'atora-lms' ) ); ?>', val: d.courses },
						{ label: '<?php echo esc_js( __( 'Lecciones', 'atora-lms' ) ); ?>', val: d.lessons },
						{ label: '<?php echo esc_js( __( 'Entregas', 'atora-lms' ) ); ?>', val: d.submissions },
						{ label: '<?php echo esc_js( __( 'Entregas huérfanas', 'atora-lms' ) ); ?>', val: d.orphan_sub, cls: d.orphan_sub > 0 ? 'warn' : '' },
						{ label: '<?php echo esc_js( __( 'Transients LMS', 'atora-lms' ) ); ?>', val: d.transients, cls: d.transients > 100 ? 'warn' : '' },
						{ label: '<?php echo esc_js( __( 'Filas de caché', 'atora-lms' ) ); ?>', val: d.cache_rows, cls: d.cache_rows > 200 ? 'warn' : '' },
					];
					var html = stats.map(function(s){
						return '<div class="clms-maint-stat ' + (s.cls||'') + '"><strong>' + s.val + '</strong><span>' + escHtml(s.label) + '</span></div>';
					}).join('');
					if (d.tables && d.tables.length) {
						html += '<div class="clms-maint-stat" style="grid-column:1/-1"><table class="clms-maint-table"><tr><th><?php echo esc_js( __( 'Tabla', 'atora-lms' ) ); ?></th><th><?php echo esc_js( __( 'Tamaño (MB)', 'atora-lms' ) ); ?></th></tr>' +
							d.tables.map(function(t){ return '<tr><td>' + escHtml(t.tbl) + '</td><td>' + t.size_mb + ' MB</td></tr>'; }).join('') +
							'</table></div>';
					}
					el.innerHTML = html;
				});
			}
			document.getElementById('clms-load-stats').addEventListener('click', loadStats);
			loadStats();

			// ── Buscar usuario ───────────────────────────────────────────────
			document.getElementById('clms-del-user-search').addEventListener('click', function(){
				var email = document.getElementById('clms-del-user-email').value.trim();
				if (!email) return;
				doAction({ maintenance_action: 'db_stats' }, function(){});
				// Buscar via WP REST.
				fetch(<?php echo wp_json_encode( rest_url( 'wp/v2/users' ) ); ?> + '?search=' + encodeURIComponent(email) + '&context=edit', {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> }
				})
				.then(function(r){ return r.json(); })
				.then(function(users){
					var info = document.getElementById('clms-del-user-info');
					var result = document.getElementById('clms-del-user-result');
					result.hidden = true;

					if (!users || !users.length) {
						info.hidden = true;
						addLog('<?php echo esc_js( __( 'No se encontró ningún usuario con ese email.', 'atora-lms' ) ); ?>', false);
						return;
					}
					var u = users[0];
					foundUserId = u.id;
					document.getElementById('clms-del-user-name').textContent = u.name;
					document.getElementById('clms-del-user-email-display').textContent = u.slug;
					document.getElementById('clms-del-user-role').textContent = u.roles ? u.roles.join(', ') : '';
					document.getElementById('clms-del-user-confirm').dataset.userId = u.id;
					info.hidden = false;
					addLog('<?php echo esc_js( __( 'Usuario encontrado:', 'atora-lms' ) ); ?> ' + u.name + ' (ID ' + u.id + ')', true);
				});
			});

			// Reasignar toggle.
			document.getElementById('clms-reassign-check').addEventListener('change', function(){
				document.getElementById('clms-reassign-input').hidden = !this.checked;
			});

			// Cancelar.
			document.getElementById('clms-del-user-cancel').addEventListener('click', function(){
				document.getElementById('clms-del-user-info').hidden = true;
				document.getElementById('clms-del-user-email').value = '';
				document.getElementById('clms-del-user-result').hidden = true;
				foundUserId = 0;
			});

			// Confirmar borrado.
			document.getElementById('clms-del-user-confirm').addEventListener('click', function(){
				var uid = parseInt(this.dataset.userId, 10);
				if (!uid) return;
				var name = document.getElementById('clms-del-user-name').textContent;
				var confirmMsg = '<?php echo esc_js( __( '¿Eliminar al usuario "%s"? Esta acción no se puede deshacer.', 'atora-lms' ) ); ?>';
				if (!window.confirm(confirmMsg.replace('%s', name))) return;

				var delData   = document.getElementById('clms-del-data-check').checked ? '1' : '0';
				var reassign  = document.getElementById('clms-reassign-check').checked
					? document.getElementById('clms-reassign-id').value : '0';

				this.disabled = true;
				doAction({
					maintenance_action: 'delete_user',
					user_id: uid,
					delete_data: delData,
					reassign_id: reassign
				}, function(err, json){
					var resultEl = document.getElementById('clms-del-user-result');
					var ok = json && json.success;
					resultEl.className = 'clms-maint-result ' + (ok ? 'ok' : 'err');
					resultEl.textContent = json ? json.data.message : '<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>';
					resultEl.hidden = false;
					addLog(resultEl.textContent, ok);
					document.getElementById('clms-del-user-info').hidden = true;
					document.getElementById('clms-del-user-confirm').disabled = false;
					if (ok) loadStats();
				});
			});

			// ── Botones de operaciones ───────────────────────────────────────
			document.querySelectorAll('.clms-maint-op-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					var act = btn.dataset.action;
					btn.classList.add('is-loading');
					btn.disabled = true;
					doAction({ maintenance_action: act }, function(err, json){
						btn.classList.remove('is-loading');
						btn.disabled = false;
						var ok  = json && json.success;
						var msg = json ? json.data.message : '<?php echo esc_js( __( 'Error de red.', 'atora-lms' ) ); ?>';
						addLog(msg, ok);
						if (ok) loadStats();
					});
				});
			});
		})();
		</script>
		<?php
	}
}
