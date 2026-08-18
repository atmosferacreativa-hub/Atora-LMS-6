<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Lesson_Sidebar {

	/**
	 * Devuelve HTML del sidebar del curso para curso/lección.
	 *
	 * @param int $course_id Curso.
	 * @param int $current_lesson_id Lección actual.
	 * @param int $user_id Usuario.
	 * @return string
	 */
	public function get_sidebar_html( $course_id, $current_lesson_id = 0, $user_id = 0 ) {
		$course_id         = absint( $course_id );
		$current_lesson_id = absint( $current_lesson_id );
		$user_id           = absint( $user_id );

		if ( ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return '';
		}

		$lessons = CLMS_Helper::get_course_lessons( $course_id );

		if ( empty( $lessons ) || ! is_array( $lessons ) ) {
			return '';
		}

		$lessons   = array_values( array_map( 'absint', $lessons ) );
		$completed = array();

		if ( $user_id ) {
			$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
			$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();
		}

		$total_lessons = count( $lessons );
		$done_count    = 0;

		foreach ( $lessons as $lesson_id ) {
			if ( in_array( $lesson_id, $completed, true ) ) {
				$done_count++;
			}
		}

		$progress = $total_lessons > 0 ? (int) round( ( $done_count / $total_lessons ) * 100 ) : 0;

		ob_start();
		?>
		<div class="clms-course-sidebar-box">
			<div class="clms-course-sidebar-head">
				<div class="clms-course-sidebar-kicker">Curso</div>
				<h3 class="clms-course-sidebar-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h3>

				<div class="clms-course-sidebar-progress">
					<div class="clms-course-sidebar-progress-head">
						<span>Progreso</span>
						<strong><?php echo esc_html( $progress ); ?>%</strong>
					</div>
					<div class="clms-course-sidebar-progress-bar" aria-hidden="true">
						<span style="width:<?php echo esc_attr( $progress ); ?>%"></span>
					</div>
				</div>
			</div>

			<div class="clms-course-outline">
				<?php foreach ( $lessons as $index => $lesson_id ) : ?>
					<?php
					$is_current  = $lesson_id === $current_lesson_id;
					$is_done     = in_array( $lesson_id, $completed, true );
					$lesson_link = get_permalink( $lesson_id );
					$type_label  = $this->get_activity_label( $lesson_id );
					?>
					<a class="clms-course-outline-item <?php echo $is_current ? 'is-current' : ''; ?> <?php echo $is_done ? 'is-done' : ''; ?>" href="<?php echo esc_url( $lesson_link ); ?>">
						<span class="clms-course-outline-index">
							<?php if ( $is_done ) : ?>
								✓
							<?php else : ?>
								<?php echo esc_html( $index + 1 ); ?>
							<?php endif; ?>
						</span>

						<span class="clms-course-outline-copy">
							<strong><?php echo esc_html( get_the_title( $lesson_id ) ); ?></strong>
							<small><?php echo esc_html( $type_label ); ?></small>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Etiqueta tipo de actividad.
	 *
	 * @param int $lesson_id Lección.
	 * @return string
	 */
	protected function get_activity_label( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return 'Lección';
		}

		$type = (string) get_post_meta( $lesson_id, 'lm_activity_type', true );

		if ( ! $type ) {
			$type = (string) get_post_meta( $lesson_id, '_clms_activity_mode', true );
		}

		switch ( strtolower( $type ) ) {
			case 'quiz':
			case 'evaluacion':
			case 'evaluation':
				return 'Evaluación';

			case 'task':
			case 'tarea':
			case 'assignment':
				return 'Tarea';

			default:
				return 'Lección';
		}
	}
}