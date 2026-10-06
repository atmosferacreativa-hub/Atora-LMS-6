<?php
/**
 * Diagnóstico de solo lectura (6.30.1): ¿alguien vio una nota antes de tiempo?
 *
 * Hasta 6.30.0, "Guardar borrador" en SpeedGrader avisaba al estudiante con la
 * nota ("Nota: 85/100", "Nota actual: 85/100") y, en tareas grupales, dejaba
 * publicada la copia de cada integrante.
 *
 * Uso (con el plugin que esté instalado, antes o después de actualizar):
 *   wp eval-file wp-content/plugins/atora-lms/scripts/audit-draft-grade-leaks.php > auditoria-borradores.txt
 *
 * A. Entregas hoy en borrador (`in_review`) cuyo estudiante recibió un aviso o
 *    mensaje con nota.
 * B. Entregas ya publicadas cuyo estudiante recibió antes la nota de un borrador
 *    (la vio antes de que se publicara; la nota pudo cambiar).
 * C. Copias grupales publicadas (`graded`) cuya entrega maestra está en
 *    borrador: esos integrantes ven hoy una nota no publicada.
 *
 * No modifica nada. La salida trae nombres de estudiantes: guardarla en un
 * lugar privado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Ejecutar con: wp eval-file scripts/audit-draft-grade-leaks.php\n" );
}

global $wpdb;

$version  = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '?';
$messages = $wpdb->prefix . 'atora_messages';
$members  = $wpdb->prefix . 'atora_message_participants';

$has_inbox = $messages === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $messages ) ) );

$who = static function ( int $user_id ): string {
	$user = $user_id ? get_userdata( $user_id ) : null;
	return $user ? sprintf( '%s <%s> (#%d)', $user->display_name, $user->user_email, $user_id ) : sprintf( '#%d', $user_id );
};

$label = static function ( int $submission_id ): string {
	$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
	$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
	return sprintf( 'entrega #%d · %s · curso #%d', $submission_id, $lesson_id ? get_the_title( $lesson_id ) : '?', $course_id );
};

/**
 * Avisos y mensajes con nota ligados a una entrega, enviados a su estudiante,
 * cuyo texto dice que la entrega estaba en revisión (borrador).
 */
$draft_notices = static function ( int $submission_id, int $student_id ) use ( $wpdb, $messages, $members, $has_inbox ): array {
	if ( ! $has_inbox || ! $student_id ) {
		return array();
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT m.id, m.kind, m.body, m.dedupe_key, m.created_at
		 FROM {$messages} m
		 INNER JOIN {$members} p ON p.thread_id = m.thread_id AND p.user_id = %d
		 WHERE m.submission_id = %d AND m.author_id <> %d
		 ORDER BY m.id ASC",
		$student_id,
		$submission_id,
		$student_id
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $row ) {
		$body      = (string) $row['body'];
		$has_grade = (bool) preg_match( '/Nota( actual)?:\s*\d+\s*\/\s*100/u', $body );
		$is_draft  = false !== strpos( (string) $row['dedupe_key'], '_in_review_' )
			|| (bool) preg_match( '/Estado:\s*En revisi[oó]n/iu', $body );
		if ( $has_grade && $is_draft ) {
			$out[] = $row;
		}
	}
	return $out;
};

$student_of = static function ( int $submission_id ): int {
	$id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
	if ( ! $id ) {
		$id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
	}
	return $id ?: absint( get_post_field( 'post_author', $submission_id ) );
};

$ids_with_status = static function ( array $statuses ): array {
	return array_map( 'absint', get_posts( array(
		'post_type'      => 'clms_submission',
		'post_status'    => array( 'publish', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => '_clms_submission_status', 'value' => $statuses, 'compare' => 'IN' ),
		),
	) ) );
};

$a = array();
$b = array();
$c = array();

foreach ( $ids_with_status( array( 'in_review', 'submitted', 'pending' ) ) as $submission_id ) {
	$student = $student_of( $submission_id );
	$found   = $draft_notices( $submission_id, $student );
	if ( $found && 'in_review' === get_post_meta( $submission_id, '_clms_submission_status', true ) ) {
		$a[] = array( $submission_id, $student, $found );
	}
	// C: maestra grupal en borrador con copias publicadas.
	if ( '1' === (string) get_post_meta( $submission_id, '_clms_submission_group_master', true ) ) {
		$shadows = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_clms_submission_group_master_id', 'value' => $submission_id, 'type' => 'NUMERIC' ),
				array( 'key' => '_clms_submission_status', 'value' => 'graded' ),
			),
		) );
		foreach ( $shadows as $shadow_id ) {
			$grade = get_post_meta( $shadow_id, '_clms_submission_grade', true );
			if ( '' !== (string) $grade ) {
				$c[] = array( $submission_id, absint( $shadow_id ), $student_of( absint( $shadow_id ) ), $grade );
			}
		}
	}
}

foreach ( $ids_with_status( array( 'graded', 'needs_revision', 'returned' ) ) as $submission_id ) {
	$student = $student_of( $submission_id );
	$found   = $draft_notices( $submission_id, $student );
	if ( $found ) {
		$b[] = array( $submission_id, $student, $found );
	}
}

echo "Auditoría de notas vistas antes de tiempo — plugin {$version} — " . current_time( 'mysql' ) . "\n";
if ( ! $has_inbox ) {
	echo "AVISO: no existe {$messages} (plugin anterior a 6.30.0): A y B no se pueden revisar.\n";
}
echo "\n";

$print_notices = static function ( array $rows ) {
	foreach ( $rows as $row ) {
		printf( "      - %s [%s] %s\n", $row['created_at'], $row['kind'], wp_html_excerpt( wp_strip_all_tags( (string) $row['body'] ), 140, '…' ) );
	}
};

printf( "A. En borrador y el estudiante recibió la nota: %d\n", count( $a ) );
foreach ( $a as list( $submission_id, $student, $found ) ) {
	printf( "   - %s — %s\n", $label( $submission_id ), $who( $student ) );
	$print_notices( $found );
}

printf( "\nB. Ya publicadas, pero el estudiante recibió antes la nota del borrador: %d\n", count( $b ) );
foreach ( $b as list( $submission_id, $student, $found ) ) {
	printf( "   - %s — %s — nota publicada: %s\n", $label( $submission_id ), $who( $student ), (string) get_post_meta( $submission_id, '_clms_submission_grade', true ) );
	$print_notices( $found );
}

printf( "\nC. Integrantes de grupo que ven hoy una nota no publicada: %d\n", count( $c ) );
foreach ( $c as list( $master_id, $shadow_id, $student, $grade ) ) {
	printf( "   - %s — copia #%d — %s — ve: %s\n", $label( $master_id ), $shadow_id, $who( $student ), (string) $grade );
}

echo "\nSolo lectura: no se cambió nada.\n";
