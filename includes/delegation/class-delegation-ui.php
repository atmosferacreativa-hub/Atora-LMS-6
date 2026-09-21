<?php
/**
 * UI de delegación: "Mi asistente" en perfil del instructor.
 *
 * Minimalista (sin JS complejo): permite otorgar y revocar delegaciones.
 *
 * @package ATORA_LMS
 * @since   6.26.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Delegation_UI {

	public static function init(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'handle_profile_submit' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'handle_profile_submit' ) );
	}

	public static function render_profile_section( WP_User $user ): void {
		$viewer_id = get_current_user_id();
		if ( $viewer_id <= 0 ) { return; }

		// Solo el propio instructor o admin.
		if ( $viewer_id !== (int) $user->ID && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! current_user_can( 'clms_manage_courses' ) ) {
			return;
		}

		if ( ! class_exists( 'ATORA_Delegation_Service' ) ) {
			return;
		}

		$delegations = ATORA_Delegation_Service::for_instructor( absint( $user->ID ) );
		?>
		<h2><?php echo esc_html__( 'Mi asistente', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="atora_assistant_login"><?php echo esc_html__( 'Nuevo asistente', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" name="atora_assistant_login" id="atora_assistant_login" class="regular-text" placeholder="<?php echo esc_attr__( 'Usuario o email', 'atora-lms' ); ?>" />
					<p class="description"><?php echo esc_html__( 'Otorga acceso por delegación sin cambiar post_author ni conceder edit_others_* estático.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php echo esc_html__( 'Alcance', 'atora-lms' ); ?></th>
				<td>
					<label><input type="radio" name="atora_delegation_scope" value="instructor" checked /> <?php echo esc_html__( 'Todos mis cursos', 'atora-lms' ); ?></label><br/>
					<label><input type="radio" name="atora_delegation_scope" value="course" /> <?php echo esc_html__( 'Un curso específico (ID)', 'atora-lms' ); ?></label>
					<input type="number" name="atora_delegation_course_id" value="0" min="0" style="width:120px;" />
				</td>
			</tr>
			<tr>
				<th><?php echo esc_html__( 'Permisos', 'atora-lms' ); ?></th>
				<td>
					<label><input type="checkbox" name="atora_perm_content" value="1" checked /> <?php echo esc_html__( 'Contenido (editar/publicar)', 'atora-lms' ); ?></label><br/>
					<label><input type="checkbox" name="atora_perm_moderate" value="1" checked /> <?php echo esc_html__( 'Moderación', 'atora-lms' ); ?></label><br/>
					<label><input type="checkbox" name="atora_perm_enroll" value="1" /> <?php echo esc_html__( 'Matrículas', 'atora-lms' ); ?></label><br/>
					<label><input type="checkbox" name="atora_perm_grade" value="1" /> <?php echo esc_html__( 'Calificar', 'atora-lms' ); ?></label><br/>
					<label><input type="checkbox" name="atora_perm_access" value="1" /> <?php echo esc_html__( 'Acceso (invitaciones/enlaces)', 'atora-lms' ); ?></label>
				</td>
			</tr>
		</table>
		<?php wp_nonce_field( 'atora_delegation_profile', 'atora_delegation_nonce' ); ?>
		<p><input type="submit" class="button button-primary" name="atora_delegation_grant" value="<?php echo esc_attr__( 'Guardar delegación', 'atora-lms' ); ?>" /></p>

		<?php if ( ! empty( $delegations ) ) : ?>
			<h3><?php echo esc_html__( 'Delegaciones actuales', 'atora-lms' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Asistente', 'atora-lms' ); ?></th>
						<th><?php echo esc_html__( 'Alcance', 'atora-lms' ); ?></th>
						<th><?php echo esc_html__( 'Estado', 'atora-lms' ); ?></th>
						<th><?php echo esc_html__( 'Acción', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $delegations as $row ) : ?>
						<?php
						$assistant = get_user_by( 'id', absint( $row['assistant_id'] ?? 0 ) );
						$assistant_label = $assistant ? $assistant->user_login : '#' . absint( $row['assistant_id'] ?? 0 );
						$scope = sanitize_key( (string) ( $row['scope'] ?? '' ) );
						$label = ( 'course' === $scope )
							? sprintf( __( 'Curso #%d', 'atora-lms' ), absint( $row['course_id'] ?? 0 ) )
							: __( 'Instructor', 'atora-lms' );
						?>
						<tr>
							<td><?php echo esc_html( $assistant_label ); ?></td>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( sanitize_key( (string) ( $row['status'] ?? '' ) ) ); ?></td>
							<td>
								<?php if ( 'active' === (string) ( $row['status'] ?? '' ) ) : ?>
									<button class="button" type="submit" name="atora_delegation_revoke" value="<?php echo esc_attr( absint( $row['id'] ?? 0 ) ); ?>">
										<?php echo esc_html__( 'Revocar', 'atora-lms' ); ?>
									</button>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	public static function handle_profile_submit( int $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return; }

		$viewer_id = get_current_user_id();
		if ( $viewer_id <= 0 ) { return; }
		if ( $viewer_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['atora_delegation_nonce'] ) || ! wp_verify_nonce( (string) $_POST['atora_delegation_nonce'], 'atora_delegation_profile' ) ) {
			return;
		}

		if ( isset( $_POST['atora_delegation_revoke'] ) ) {
			$delegation_id = absint( $_POST['atora_delegation_revoke'] );
			if ( $delegation_id > 0 && class_exists( 'ATORA_Delegation_Service' ) ) {
				ATORA_Delegation_Service::revoke( $delegation_id, $viewer_id );
			}
			return;
		}

		if ( ! isset( $_POST['atora_delegation_grant'] ) ) {
			return;
		}

		$login = sanitize_text_field( (string) ( $_POST['atora_assistant_login'] ?? '' ) );
		if ( '' === $login ) { return; }

		$assistant = get_user_by( 'login', $login );
		if ( ! $assistant ) {
			$assistant = get_user_by( 'email', $login );
		}
		if ( ! $assistant ) { return; }

		$scope = sanitize_key( (string) ( $_POST['atora_delegation_scope'] ?? 'instructor' ) );
		$course_id = absint( $_POST['atora_delegation_course_id'] ?? 0 );
		if ( ! in_array( $scope, array( 'instructor', 'course' ), true ) ) {
			$scope = 'instructor';
		}
		if ( 'course' === $scope && $course_id <= 0 ) {
			return;
		}

		$perms = array(
			'content'  => ! empty( $_POST['atora_perm_content'] ),
			'moderate' => ! empty( $_POST['atora_perm_moderate'] ),
			'enroll'   => ! empty( $_POST['atora_perm_enroll'] ),
			'grade'    => ! empty( $_POST['atora_perm_grade'] ),
			'access'   => ! empty( $_POST['atora_perm_access'] ),
		);

		if ( class_exists( 'ATORA_Delegation_Service' ) ) {
			ATORA_Delegation_Service::grant( $user_id, absint( $assistant->ID ), array(
				'scope'      => $scope,
				'course_id'  => $course_id,
				'perms'      => $perms,
				'created_by' => $viewer_id,
			) );
		}
	}
}

