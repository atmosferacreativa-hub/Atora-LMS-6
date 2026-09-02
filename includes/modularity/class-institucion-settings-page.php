<?php
/**
 * CLMS_Institucion_Settings_Page — P5 (sprint 6.12.0)
 *
 * Página propia del perfil `institucion`: ATORA → Institución. Solo se
 * registra cuando ese es el perfil activo — si el perfil no suma nada
 * más allá de restar módulos comerciales, el argumento frente a una
 * evaluación institucional es "producto capado"; esta página es lo que
 * lo convierte en un producto distinto (actas, cohortes, auditoría).
 *
 * No reinventa exportación de actas ni gestión de cohortes — enlaza a
 * las vistas ya existentes (Gradebook, Secciones) y añade lo que hoy no
 * existe en ningún lado: un log de auditoría cross-módulo legible.
 *
 * @package ATORA_LMS
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Institucion_Settings_Page {

	public static function init(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Solo se registra cuando el perfil activo es 'institucion' — es la
	 * razón de ser de esta página, no un ajuste general.
	 */
	public static function register_menu(): void {
		if ( ! class_exists( 'CLMS_Install_Profiles' ) || 'institucion' !== CLMS_Install_Profiles::current() ) {
			return;
		}

		add_submenu_page(
			'clms-dashboard',
			__( 'Institución', 'atora-lms' ),
			__( '🏛️ Institución', 'atora-lms' ),
			'manage_options',
			'atora-institucion',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Institución', 'atora-lms' ); ?></h1>
			<p><?php esc_html_e( 'Herramientas propias del perfil Institución: actas, cohortes y auditoría de acciones administrativas.', 'atora-lms' ); ?></p>

			<?php self::render_cohorts_section(); ?>
			<?php self::render_transcripts_section(); ?>
			<?php self::render_audit_log_section(); ?>
		</div>
		<?php
	}

	private static function render_cohorts_section(): void {
		global $wpdb;
		?>
		<h2 style="margin-top:28px"><?php esc_html_e( 'Cohortes / Secciones', 'atora-lms' ); ?></h2>
		<?php
		$sections_table = $wpdb->prefix . 'atora_sections';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $sections_table ) ) ) !== $sections_table ) {
			echo '<p>' . esc_html__( 'Sin datos todavía.', 'atora-lms' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sections = $wpdb->get_results( "SELECT s.id, s.title, s.wp_course_id, s.capacity, s.status, COUNT(st.id) AS enrolled FROM {$sections_table} s LEFT JOIN {$wpdb->prefix}atora_section_students st ON st.section_id = s.id AND st.status = 'active' GROUP BY s.id ORDER BY s.created_at DESC LIMIT 50" );

		if ( ! $sections ) {
			echo '<p>' . esc_html__( 'Todavía no hay cohortes creadas.', 'atora-lms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Sección', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Curso', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Matriculados', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Capacidad', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $sections as $s ) : ?>
				<tr>
					<td><?php echo esc_html( $s->title ); ?></td>
					<td><?php echo esc_html( get_the_title( (int) $s->wp_course_id ) ?: '#' . (int) $s->wp_course_id ); ?></td>
					<td><?php echo esc_html( (string) (int) $s->enrolled ); ?></td>
					<td><?php echo $s->capacity ? esc_html( (string) (int) $s->capacity ) : '—'; ?></td>
					<td><?php echo esc_html( $s->status ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_transcripts_section(): void {
		?>
		<h2 style="margin-top:28px"><?php esc_html_e( 'Actas (exportación de calificaciones)', 'atora-lms' ); ?></h2>
		<p>
			<?php esc_html_e( 'La exportación de actas se hace desde la vista de Gradebook de cada curso.', 'atora-lms' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-gradebook' ) ); ?>"><?php esc_html_e( 'Ir a Gradebook →', 'atora-lms' ); ?></a>
		</p>
		<?php
	}

	private static function render_audit_log_section(): void {
		?>
		<h2 style="margin-top:28px"><?php esc_html_e( 'Log de auditoría', 'atora-lms' ); ?></h2>
		<p style="color:#64748b;font-size:13px"><?php esc_html_e( 'Quién matriculó, quién cambió una nota, quién exportó datos — las últimas 50 acciones.', 'atora-lms' ); ?></p>
		<?php
		$entries = class_exists( 'CLMS_Audit_Log_Service' ) ? CLMS_Audit_Log_Service::get_recent( 50 ) : array();
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'Sin actividad registrada todavía.', 'atora-lms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:1000px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Usuario', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Objeto', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Detalle', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $entries as $e ) :
				$actor = (int) ( $e['actor_id'] ?? 0 );
				$user  = $actor ? get_userdata( $actor ) : false;
				$details = json_decode( (string) ( $e['details_json'] ?? '' ), true );
			?>
				<tr>
					<td><?php echo esc_html( (string) ( $e['created_at'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( $user ? $user->user_login : ( $actor ? "#{$actor}" : '—' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $e['action'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( sprintf( '%s #%d', (string) ( $e['object_type'] ?? '' ), (int) ( $e['object_id'] ?? 0 ) ) ); ?></td>
					<td><code style="font-size:11px"><?php echo esc_html( is_array( $details ) ? wp_json_encode( $details ) : '' ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
