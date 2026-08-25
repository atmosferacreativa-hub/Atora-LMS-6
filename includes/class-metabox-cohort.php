<?php
/**
 * Metaboxes para cohortes/grupos.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Metabox_Cohort {

	const NONCE_ACTION = 'clms_save_cohort_meta';
	const NONCE_NAME   = 'clms_cohort_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_lm_cohort', array( $this, 'save_meta_boxes' ), 20, 2 );
		add_action( 'admin_post_clms_project_cohort_sections', array( $this, 'handle_project_sections' ) );
	}

	/**
	 * Handler del botón "Proyectar secciones desde esta cohorte".
	 */
	public function handle_project_sections(): void {
		$cohort_id = isset( $_GET['cohort_id'] ) ? absint( wp_unslash( $_GET['cohort_id'] ) ) : 0;

		if ( ! $cohort_id ) {
			wp_die( esc_html__( 'Cohorte no válida.', 'atora-lms' ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'clms_access_admin' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_project_cohort_sections_' . $cohort_id );

		if ( class_exists( 'ATORA\\LMS\\Section_Projector' ) ) {
			\ATORA\LMS\Section_Projector::project_from_cohort( $cohort_id );
		}

		wp_safe_redirect( add_query_arg( array( 'post' => $cohort_id, 'action' => 'edit', 'message' => 'sections_projected' ), admin_url( 'post.php' ) ) );
		exit;
	}

	/**
	 * Registra metaboxes de cohorte.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'clms_cohort_configuration',
			__( 'Configuración académica de cohorte', 'atora-lms' ),
			array( $this, 'render_configuration_box' ),
			'lm_cohort',
			'normal',
			'high'
		);
		add_meta_box(
			'clms_cohort_students',
			__( 'Estudiantes y estado formativo', 'atora-lms' ),
			array( $this, 'render_students_box' ),
			'lm_cohort',
			'normal',
			'default'
		);
		add_meta_box(
			'clms_cohort_sections',
			__( 'Secciones de esta cohorte', 'atora-lms' ),
			array( $this, 'render_sections_box' ),
			'lm_cohort',
			'normal',
			'default'
		);
		add_meta_box(
			'clms_cohort_report',
			__( 'Reporte básico de cohorte', 'atora-lms' ),
			array( $this, 'render_report_box' ),
			'lm_cohort',
			'side',
			'default'
		);
	}

	/**
	 * Render de configuración.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public function render_configuration_box( $post ) {
		if ( ! $post || 'lm_cohort' !== $post->post_type ) {
			return;
		}
		if ( ! class_exists( 'CLMS_Helper' ) || ! CLMS_Helper::user_can_manage_lms() ) {
			return;
		}

		$service = $this->get_service();
		$status_labels = $service ? $service->get_status_labels() : array();
		$course_ids  = $service ? $service->get_cohort_course_ids( $post->ID ) : array();
		$program_ids = $service ? $service->get_cohort_program_ids( $post->ID ) : array();
		$teacher_ids = $service ? $service->get_cohort_teacher_ids( $post->ID ) : array();

		$company   = (string) get_post_meta( $post->ID, CLMS_Cohort_Service::META_COMPANY, true );
		$start     = (string) get_post_meta( $post->ID, CLMS_Cohort_Service::META_START_DATE, true );
		$end       = (string) get_post_meta( $post->ID, CLMS_Cohort_Service::META_END_DATE, true );
		$capacity  = absint( get_post_meta( $post->ID, CLMS_Cohort_Service::META_CAPACITY, true ) );
		$status    = $service ? $service->normalize_cohort_status( get_post_meta( $post->ID, CLMS_Cohort_Service::META_STATUS, true ) ) : 'proximo';
		$notes     = (string) get_post_meta( $post->ID, CLMS_Cohort_Service::META_NOTES, true );

		$courses  = $this->get_course_options();
		$programs = $this->get_program_options();
		$teachers = $this->get_teacher_options();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label for="clms_cohort_status"><strong><?php esc_html_e( 'Estado de cohorte', 'atora-lms' ); ?></strong></label><br>
			<select id="clms_cohort_status" name="clms_cohort_status">
				<?php foreach ( $status_labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="clms_cohort_company"><strong><?php esc_html_e( 'Empresa / cliente institucional (opcional)', 'atora-lms' ); ?></strong></label><br>
			<input id="clms_cohort_company" name="clms_cohort_company" type="text" class="regular-text" value="<?php echo esc_attr( $company ); ?>">
		</p>
		<p>
			<label for="clms_cohort_start"><strong><?php esc_html_e( 'Fecha de inicio', 'atora-lms' ); ?></strong></label><br>
			<input id="clms_cohort_start" name="clms_cohort_start" type="date" value="<?php echo esc_attr( $start ); ?>">
			&nbsp;&nbsp;
			<label for="clms_cohort_end"><strong><?php esc_html_e( 'Fecha de cierre', 'atora-lms' ); ?></strong></label><br>
			<input id="clms_cohort_end" name="clms_cohort_end" type="date" value="<?php echo esc_attr( $end ); ?>">
		</p>
		<p>
			<label for="clms_cohort_capacity"><strong><?php esc_html_e( 'Cupo', 'atora-lms' ); ?></strong></label><br>
			<input id="clms_cohort_capacity" name="clms_cohort_capacity" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $capacity ); ?>">
		</p>
		<p>
			<strong><?php esc_html_e( 'Cursos asociados', 'atora-lms' ); ?></strong><br>
			<select name="clms_cohort_course_ids[]" multiple size="6" style="min-width:380px;max-width:100%">
				<?php foreach ( $courses as $course_id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $course_id ); ?>" <?php selected( in_array( $course_id, $course_ids, true ) ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select><br>
			<small><?php esc_html_e( 'Mantén presionada la tecla Ctrl/Cmd para seleccionar varios cursos.', 'atora-lms' ); ?></small>
		</p>
		<p>
			<strong><?php esc_html_e( 'Programas asociados (opcional)', 'atora-lms' ); ?></strong><br>
			<select name="clms_cohort_program_ids[]" multiple size="4" style="min-width:380px;max-width:100%">
				<?php foreach ( $programs as $program_id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $program_id ); ?>" <?php selected( in_array( $program_id, $program_ids, true ) ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<strong><?php esc_html_e( 'Docentes responsables', 'atora-lms' ); ?></strong><br>
			<select name="clms_cohort_teacher_ids[]" multiple size="5" style="min-width:380px;max-width:100%">
				<?php foreach ( $teachers as $teacher_id => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $teacher_id ); ?>" <?php selected( in_array( $teacher_id, $teacher_ids, true ) ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="clms_cohort_notes"><strong><?php esc_html_e( 'Notas internas (opcional)', 'atora-lms' ); ?></strong></label><br>
			<textarea id="clms_cohort_notes" name="clms_cohort_notes" rows="4" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * Render de estudiantes y estados.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_students_box( $post ) {
		if ( ! $post || 'lm_cohort' !== $post->post_type ) {
			return;
		}
		if ( ! class_exists( 'CLMS_Helper' ) || ! CLMS_Helper::user_can_manage_lms() ) {
			return;
		}

		$service = $this->get_service();
		$student_ids = $service ? $service->get_cohort_student_ids( $post->ID ) : array();
		$status_map  = $service ? $service->get_cohort_student_status_map( $post->ID ) : array();
		$student_labels = $service ? $service->get_student_status_labels() : array();

		$lines = array();
		foreach ( $student_ids as $student_id ) {
			$lines[] = (string) absint( $student_id );
		}
		?>
		<p>
			<label for="clms_cohort_students_raw"><strong><?php esc_html_e( 'Estudiantes de la cohorte', 'atora-lms' ); ?></strong></label><br>
			<textarea id="clms_cohort_students_raw" name="clms_cohort_students_raw" rows="6" class="large-text" placeholder="<?php echo esc_attr__( "ID o email por línea\n12\nalumno@academia.com", 'atora-lms' ); ?>"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
			<small><?php esc_html_e( 'Puedes ingresar IDs de usuario o correos. El sistema evita duplicados automáticamente.', 'atora-lms' ); ?></small>
		</p>
		<?php if ( empty( $student_ids ) ) : ?>
			<p><?php esc_html_e( 'Aún no hay estudiantes asociados a esta cohorte.', 'atora-lms' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Estudiante', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Email', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Estado formativo', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $student_ids as $student_id ) : ?>
					<?php
					$student_id = absint( $student_id );
					$user       = get_userdata( $student_id );
					if ( ! $user ) {
						continue;
					}
					$current_status = isset( $status_map[ $student_id ] ) ? $status_map[ $student_id ] : 'activo';
					?>
					<tr>
						<td><?php echo esc_html( $user->display_name ? $user->display_name : $user->user_login ); ?></td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td>
							<select name="clms_cohort_student_status[<?php echo esc_attr( (string) $student_id ); ?>]">
								<?php foreach ( $student_labels as $status_key => $status_label ) : ?>
									<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $current_status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render reporte de cohorte.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_report_box( $post ) {
		if ( ! $post || 'lm_cohort' !== $post->post_type ) {
			return;
		}
		$service = $this->get_service();
		if ( ! $service || ! method_exists( $service, 'get_cohort_report' ) ) {
			echo '<p>' . esc_html__( 'Reporte no disponible.', 'atora-lms' ) . '</p>';
			return;
		}

		$report = (array) $service->get_cohort_report( $post->ID );
		if ( empty( $report ) ) {
			echo '<p>' . esc_html__( 'Aún no hay datos suficientes para esta cohorte.', 'atora-lms' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Progreso promedio:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $report['progress_average'] ?? 0 ) ) . '%</p>';
		echo '<p><strong>' . esc_html__( 'Estudiantes activos:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $report['students_active'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Estudiantes en riesgo:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $report['students_risk'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Completados:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $report['students_completed'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Certificados emitidos:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $report['certificates_issued'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Pendientes de evaluación:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $report['pending_evaluations'] ?? 0 ) ) . '</p>';
		if ( ! empty( $report['is_ready_for_closure'] ) ) {
			echo '<p><strong>' . esc_html__( 'Cohorte lista para cierre/certificación.', 'atora-lms' ) . '</strong></p>';
		} else {
			echo '<p>' . esc_html__( 'La cohorte aún tiene pendientes antes del cierre.', 'atora-lms' ) . '</p>';
		}
	}

	/**
	 * Panel de secciones asociadas a la cohorte.
	 *
	 * @param WP_Post $post Post actual.
	 * @return void
	 */
	public function render_sections_box( $post ) {
		if ( ! $post || 'lm_cohort' !== $post->post_type ) {
			return;
		}

		if ( ! class_exists( 'ATORA\\LMS\\Section_Service' ) ) {
			echo '<p>' . esc_html__( 'El servicio de secciones no está disponible. Verifica que el plugin está actualizado.', 'atora-lms' ) . '</p>';
			return;
		}

		$sections = \ATORA\LMS\Section_Service::get_sections_by_cohort( $post->ID );

		if ( empty( $sections ) ) {
			echo '<p>' . esc_html__( 'Esta cohorte todavía no tiene secciones.', 'atora-lms' ) . '</p>';
			if ( class_exists( 'ATORA\\LMS\\Section_Projector' ) ) {
				$project_url = wp_nonce_url(
					add_query_arg( array( 'action' => 'clms_project_cohort_sections', 'cohort_id' => $post->ID ), admin_url( 'admin-post.php' ) ),
					'clms_project_cohort_sections_' . $post->ID
				);
				echo '<p><a href="' . esc_url( $project_url ) . '" class="button button-secondary">' . esc_html__( 'Proyectar secciones desde esta cohorte', 'atora-lms' ) . '</a></p>';
				echo '<p class="description">' . esc_html__( 'Crea una sección por cada curso de la cohorte, heredando profesores y estudiantes. Operación idempotente y no destructiva.', 'atora-lms' ) . '</p>';
			}
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Título', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Curso', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Profesor lead', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Coordinador', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Alumnos', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Revisión', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $sections as $section ) {
			$section_id   = (int) $section['id'];
			$course_title = get_the_title( (int) $section['wp_course_id'] ) ?: '—';
			$lead_id      = \ATORA\LMS\Section_Service::get_lead_teacher( $section_id );
			$lead_name    = $lead_id ? ( get_user_by( 'id', $lead_id )->display_name ?? "ID {$lead_id}" ) : '—';
			$roster_count = count( \ATORA\LMS\Section_Service::get_section_student_ids( $section_id ) );

			// PT-3 (6.11.0): antes no había ninguna forma de ver/asignar
			// coordinador desde el admin -- solo un INSERT manual (ver
			// docs/DEUDA-TECNICA.md, PT-3.4).
			$coordinator_id   = \ATORA\LMS\Section_Service::get_coordinator( $section_id );
			$coordinator_name = $coordinator_id ? ( get_user_by( 'id', $coordinator_id )->display_name ?? "ID {$coordinator_id}" ) : '—';
			$assign_url       = admin_url( 'admin.php?page=atora-section-coordinator&section_id=' . $section_id );

			$meta        = json_decode( (string) $section['meta_json'], true );
			$needs_review = ! empty( $meta['needs_review'] );

			echo '<tr>';
			echo '<td>' . esc_html( $section['title'] ?: "Sección #{$section_id}" ) . '</td>';
			echo '<td>' . esc_html( $course_title ) . '</td>';
			echo '<td>' . esc_html( $section['status'] ) . '</td>';
			echo '<td>' . esc_html( $lead_name ) . '</td>';
			echo '<td>' . esc_html( $coordinator_name ) . ' <a href="' . esc_url( $assign_url ) . '">(' . esc_html__( 'asignar', 'atora-lms' ) . ')</a></td>';
			echo '<td>' . esc_html( (string) $roster_count ) . '</td>';
			echo '<td>' . ( $needs_review ? '<span style="color:#b91c1c;font-weight:600">' . esc_html__( 'Requiere revisión', 'atora-lms' ) . '</span>' : '<span style="color:#065f46">' . esc_html__( 'OK', 'atora-lms' ) . '</span>' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Guarda metaboxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save_meta_boxes( $post_id, $post ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! $post || 'lm_cohort' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! class_exists( 'CLMS_Helper' ) || ! CLMS_Helper::user_can_manage_lms() ) {
			return;
		}

		$service = $this->get_service();
		if ( ! $service ) {
			return;
		}

		$course_ids  = isset( $_POST['clms_cohort_course_ids'] ) ? (array) wp_unslash( $_POST['clms_cohort_course_ids'] ) : array();
		$program_ids = isset( $_POST['clms_cohort_program_ids'] ) ? (array) wp_unslash( $_POST['clms_cohort_program_ids'] ) : array();
		$teacher_ids = isset( $_POST['clms_cohort_teacher_ids'] ) ? (array) wp_unslash( $_POST['clms_cohort_teacher_ids'] ) : array();

		update_post_meta( $post_id, CLMS_Cohort_Service::META_COURSE_IDS, $this->normalize_ids( $course_ids ) );
		update_post_meta( $post_id, CLMS_Cohort_Service::META_PROGRAM_IDS, $this->normalize_ids( $program_ids ) );
		update_post_meta( $post_id, CLMS_Cohort_Service::META_TEACHER_IDS, $this->normalize_ids( $teacher_ids ) );

		$company = isset( $_POST['clms_cohort_company'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_cohort_company'] ) ) : '';
		$start   = isset( $_POST['clms_cohort_start'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_cohort_start'] ) ) : '';
		$end     = isset( $_POST['clms_cohort_end'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_cohort_end'] ) ) : '';
		$notes   = isset( $_POST['clms_cohort_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_cohort_notes'] ) ) : '';
		$status  = isset( $_POST['clms_cohort_status'] ) ? $service->normalize_cohort_status( wp_unslash( $_POST['clms_cohort_status'] ) ) : 'proximo';
		$capacity = isset( $_POST['clms_cohort_capacity'] ) ? absint( wp_unslash( $_POST['clms_cohort_capacity'] ) ) : 0;

		update_post_meta( $post_id, CLMS_Cohort_Service::META_COMPANY, $company );
		update_post_meta( $post_id, CLMS_Cohort_Service::META_START_DATE, $this->sanitize_date( $start ) );
		update_post_meta( $post_id, CLMS_Cohort_Service::META_END_DATE, $this->sanitize_date( $end ) );
		update_post_meta( $post_id, CLMS_Cohort_Service::META_NOTES, $notes );
		update_post_meta( $post_id, CLMS_Cohort_Service::META_STATUS, $status );
		update_post_meta( $post_id, CLMS_Cohort_Service::META_CAPACITY, $capacity );

		$students_raw = isset( $_POST['clms_cohort_students_raw'] ) ? (string) wp_unslash( $_POST['clms_cohort_students_raw'] ) : '';
		$student_ids  = $this->parse_student_entries( $students_raw );
		$service->set_cohort_student_ids( $post_id, $student_ids );

		$student_status = isset( $_POST['clms_cohort_student_status'] ) ? (array) wp_unslash( $_POST['clms_cohort_student_status'] ) : array();
		foreach ( $student_status as $student_id => $student_state ) {
			$service->set_student_status_in_cohort( $post_id, absint( $student_id ), $student_state );
		}

		$service->sync_student_states_for_cohort_state( $post_id, $status );
	}

	/**
	 * Lista de cursos para select.
	 *
	 * @return array<int,string>
	 */
	protected function get_course_options() {
		$posts = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 250,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$options = array();
		foreach ( $posts as $post ) {
			$options[ absint( $post->ID ) ] = sanitize_text_field( (string) $post->post_title );
		}
		return $options;
	}

	/**
	 * Lista de programas para select.
	 *
	 * @return array<int,string>
	 */
	protected function get_program_options() {
		$posts = get_posts(
			array(
				'post_type'      => 'lm_program',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$options = array();
		foreach ( $posts as $post ) {
			$options[ absint( $post->ID ) ] = sanitize_text_field( (string) $post->post_title );
		}
		return $options;
	}

	/**
	 * Lista de docentes para select.
	 *
	 * @return array<int,string>
	 */
	protected function get_teacher_options() {
		$users = get_users(
			array(
				'number'  => 400,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			$user_id = absint( $user->ID );
			if ( ! $user_id ) {
				continue;
			}
			$can_teach = user_can( $user_id, 'clms_view_teacher_dashboard' )
				|| user_can( $user_id, 'clms_grade_submissions' )
				|| user_can( $user_id, 'clms_manage_courses' )
				|| user_can( $user_id, 'manage_options' );
			if ( ! $can_teach ) {
				continue;
			}
			$options[ $user_id ] = sanitize_text_field( ( $user->display_name ? $user->display_name : $user->user_email ) );
		}
		return $options;
	}

	/**
	 * Parsea listado de estudiantes.
	 *
	 * @param string $raw Texto.
	 * @return array<int,int>
	 */
	protected function parse_student_entries( $raw ) {
		$raw = (string) $raw;
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$lines = is_array( $lines ) ? $lines : array();
		$ids = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( is_numeric( $line ) ) {
				$user_id = absint( $line );
				if ( $user_id && get_user_by( 'id', $user_id ) ) {
					$ids[] = $user_id;
				}
				continue;
			}
			if ( is_email( $line ) ) {
				$user = get_user_by( 'email', sanitize_email( $line ) );
				if ( $user && ! empty( $user->ID ) ) {
					$ids[] = absint( $user->ID );
				}
			}
		}

		return $this->normalize_ids( $ids );
	}

	/**
	 * Normaliza ids.
	 *
	 * @param array $ids IDs.
	 * @return array<int,int>
	 */
	protected function normalize_ids( $ids ) {
		$ids = is_array( $ids ) ? $ids : array();
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Sanitiza fecha YYYY-MM-DD.
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	protected function sanitize_date( $date ) {
		$date = sanitize_text_field( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		return '';
	}

	/**
	 * Resuelve servicio.
	 *
	 * @return CLMS_Cohort_Service|null
	 */
	protected function get_service() {
		$service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Cohort_Service') : null;
		if ( ! $service && class_exists( 'CLMS_Cohort_Service' ) ) {
			$service = new CLMS_Cohort_Service();
		}
		return $service;
	}
}
