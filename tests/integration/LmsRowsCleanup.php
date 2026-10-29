<?php
/**
 * Las tablas propias sobreviven entre archivos de prueba (force_install confirma la transacción), pero los posts sí se
 * revierten y sus ids se reutilizan: una fila de `atora_courses` o `atora_enrollments` que queda atrás termina pegada al
 * curso de otra prueba. Cada prueba que crea cursos y matrículas las borra al terminar.
 */

declare( strict_types = 1 );

trait LmsRowsCleanup {

	/** @param int[] $wp_courses @param int[] $users */
	protected function cleanup_lms_rows( array $wp_courses, array $users ): void {
		global $wpdb;
		$wp_courses = array_filter( array_map( 'absint', $wp_courses ) );
		$users      = array_filter( array_map( 'absint', $users ) );
		if ( $wp_courses ) {
			$in    = implode( ',', $wp_courses );
			$table = array_map( 'absint', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id IN ({$in})" ) ); // phpcs:ignore WordPress.DB
			if ( $table ) {
				$ids = implode( ',', $table );
				foreach ( array( 'atora_enrollments', 'atora_lessons' ) as $name ) {
					$wpdb->query( "DELETE FROM {$wpdb->prefix}{$name} WHERE course_id IN ({$ids})" ); // phpcs:ignore WordPress.DB
				}
				$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_courses WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB
			}
		}
		if ( $users ) {
			$in = implode( ',', $users );
			foreach ( array( 'atora_enrollments', 'atora_lesson_progress' ) as $name ) {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}{$name} WHERE user_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			}
		}
	}
}
