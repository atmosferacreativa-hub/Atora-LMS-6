<?php
/**
 * Diagnóstico de matrícula vs. entregas calificadas — ATORA LMS
 *
 * SOLO LECTURA. No escribe nada, no modifica ningún meta, no borra nada.
 *
 * Ejecutar dentro del contenedor de WordPress:
 *
 *   docker cp scripts/diagnostico-matricula.php atora-wordpress:/tmp/
 *   docker exec atora-wordpress php /tmp/diagnostico-matricula.php
 *
 * No hace falta WP-CLI: carga WordPress directamente con wp-load.php.
 *
 * @package ATORA_LMS
 */

// ── Arranque de WordPress ────────────────────────────────────────────────────

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "No encuentro wp-load.php en {$wp_load}\n" );
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
require_once $wp_load;

if ( ! function_exists( 'get_post_meta' ) ) {
	fwrite( STDERR, "WordPress no cargó correctamente.\n" );
	exit( 1 );
}

// ── Utilidades ───────────────────────────────────────────────────────────────

function d_line( $char = '─', $n = 78 ) {
	echo str_repeat( $char, $n ) . "\n";
}

function d_head( $title ) {
	echo "\n";
	d_line( '═' );
	echo " {$title}\n";
	d_line( '═' );
}

/** Lista de IDs desde un meta que puede ser array serializado o basura. */
function d_id_list( $raw ): array {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
}

/** Resuelve el estudiante igual que lo hace publish_submission_grade(). */
function d_student_of( int $submission_id ): int {
	$id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
	if ( ! $id ) {
		$id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
	}
	if ( ! $id ) {
		$id = absint( get_post_field( 'post_author', $submission_id ) );
	}
	return $id;
}

/** Resuelve el curso igual que lo hace publish_submission_grade(). */
function d_course_of( int $submission_id, int $lesson_id ): array {
	$direct = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
	$viaLesson = 0;
	if ( $lesson_id && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_lesson_course_id' ) ) {
		$viaLesson = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
	}
	return array( 'direct' => $direct, 'via_lesson' => $viaLesson, 'used' => $direct ?: $viaLesson );
}

// ── 1 · Inventario de entregas calificadas ───────────────────────────────────

d_head( '1 · Entregas calificadas y su matrícula' );

$submissions = get_posts( array(
	'post_type'      => 'clms_submission',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_query'     => array(
		array(
			'key'     => '_clms_submission_status',
			'value'   => 'graded',
			'compare' => '=',
		),
	),
) );

echo "Entregas con status=graded: " . count( $submissions ) . "\n\n";

$huerfanas       = array();   // calificadas cuyo estudiante NO está matriculado
$sin_nota        = array();   // status=graded pero sin meta de nota
$curso_discrepa  = array();   // course_id directo != course_id vía lección
$courses_tocados = array();

printf( "%-8s %-8s %-8s %-7s %-10s %-12s %s\n",
	'ENTREGA', 'ALUMNO', 'CURSO', 'NOTA', 'EN CURSO?', 'EN USUARIO?', 'OBSERVACIÓN' );
d_line();

foreach ( $submissions as $sid ) {
	$sid       = (int) $sid;
	$student   = d_student_of( $sid );
	$lesson    = absint( get_post_meta( $sid, '_clms_submission_lesson_id', true ) );
	$course    = d_course_of( $sid, $lesson );
	$course_id = $course['used'];

	$grade_raw = get_post_meta( $sid, '_clms_submission_grade', true );
	$has_grade = ( '' !== (string) $grade_raw );

	$enrolled_in_course = d_id_list( get_post_meta( $course_id, '_clms_enrolled_users', true ) );
	$enrolled_of_user   = d_id_list( get_user_meta( $student, '_clms_enrolled_courses', true ) );

	$in_course = in_array( $student, $enrolled_in_course, true );
	$in_user   = in_array( $course_id, $enrolled_of_user, true );

	$obs = array();
	if ( ! $in_course ) {
		$obs[] = 'NO MATRICULADO en el curso';
		$huerfanas[] = $sid;
	}
	if ( $in_course !== $in_user ) {
		$obs[] = 'las dos copias discrepan';
	}
	if ( ! $has_grade ) {
		$obs[] = 'graded SIN nota guardada';
		$sin_nota[] = $sid;
	}
	if ( $course['direct'] && $course['via_lesson'] && $course['direct'] !== $course['via_lesson'] ) {
		$obs[] = sprintf( 'curso entrega=%d vs lección=%d', $course['direct'], $course['via_lesson'] );
		$curso_discrepa[] = $sid;
	}

	if ( $course_id ) {
		$courses_tocados[ $course_id ] = true;
	}

	printf( "%-8d %-8d %-8d %-7s %-10s %-12s %s\n",
		$sid,
		$student,
		$course_id,
		$has_grade ? (string) $grade_raw : '—',
		$in_course ? 'sí' : 'NO',
		$in_user ? 'sí' : 'NO',
		implode( ' · ', $obs )
	);
}

// ── 2 · Las columnas del Gradebook ───────────────────────────────────────────

d_head( '2 · ¿Tienen lecciones y matriculados los cursos implicados?' );

echo "El Gradebook imprime «No hay datos disponibles» si faltan FILAS o COLUMNAS.\n";
echo "Filas = estudiantes matriculados. Columnas = lecciones del curso.\n\n";

printf( "%-8s %-40s %-12s %-10s %s\n", 'CURSO', 'TÍTULO', 'MATRICULADOS', 'LECCIONES', 'VEREDICTO' );
d_line();

foreach ( array_keys( $courses_tocados ) as $cid ) {
	$cid      = (int) $cid;
	$title    = (string) get_the_title( $cid );
	$students = d_id_list( get_post_meta( $cid, '_clms_enrolled_users', true ) );
	$lessons  = array();
	if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_lessons' ) ) {
		$lessons = (array) CLMS_Helper::get_course_lessons( $cid );
	}

	$veredicto = 'rejilla con datos';
	if ( empty( $students ) && empty( $lessons ) ) {
		$veredicto = 'VACÍO: sin matriculados Y sin lecciones';
	} elseif ( empty( $students ) ) {
		$veredicto = 'VACÍO: sin matriculados (faltan FILAS)';
	} elseif ( empty( $lessons ) ) {
		$veredicto = 'VACÍO: sin lecciones (faltan COLUMNAS)';
	}

	printf( "%-8d %-40s %-12d %-10d %s\n",
		$cid,
		mb_strimwidth( $title, 0, 38, '…' ),
		count( $students ),
		count( $lessons ),
		$veredicto
	);
}

// ── 3 · Divergencia entre las dos copias de la matrícula ─────────────────────

d_head( '3 · Divergencia global entre las dos copias' );

echo "La matrícula se guarda dos veces: en postmeta del curso (_clms_enrolled_users)\n";
echo "y en usermeta del alumno (_clms_enrolled_courses). Nada garantiza que coincidan.\n\n";

$all_courses = get_posts( array(
	'post_type'      => 'lm_course',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

$pares_curso  = array();  // "curso:alumno" según el curso
$pares_alumno = array();  // "curso:alumno" según el alumno
$blob_max     = 0;
$blob_curso   = 0;

foreach ( $all_courses as $cid ) {
	$cid  = (int) $cid;
	$list = d_id_list( get_post_meta( $cid, '_clms_enrolled_users', true ) );
	$raw  = get_post_meta( $cid, '_clms_enrolled_users', true );
	$size = strlen( maybe_serialize( $raw ) );
	if ( $size > $blob_max ) {
		$blob_max   = $size;
		$blob_curso = $cid;
	}
	foreach ( $list as $uid ) {
		$pares_curso[ $cid . ':' . $uid ] = true;
	}
}

$users = get_users( array( 'fields' => 'ID', 'number' => -1 ) );
foreach ( $users as $uid ) {
	$uid  = (int) $uid;
	$list = d_id_list( get_user_meta( $uid, '_clms_enrolled_courses', true ) );
	foreach ( $list as $cid ) {
		$pares_alumno[ $cid . ':' . $uid ] = true;
	}
}

$solo_curso  = array_diff_key( $pares_curso, $pares_alumno );
$solo_alumno = array_diff_key( $pares_alumno, $pares_curso );

echo 'Cursos revisados: ' . count( $all_courses ) . "\n";
echo 'Usuarios revisados: ' . count( $users ) . "\n";
echo 'Pares matriculado según el CURSO: ' . count( $pares_curso ) . "\n";
echo 'Pares matriculado según el ALUMNO: ' . count( $pares_alumno ) . "\n";
echo 'Solo en el curso (el alumno no lo sabe): ' . count( $solo_curso ) . "\n";
echo 'Solo en el alumno (el curso no lo sabe): ' . count( $solo_alumno ) . "\n";

if ( $solo_curso ) {
	echo "\n  Ejemplos solo-en-curso (curso:alumno): " . implode( ', ', array_slice( array_keys( $solo_curso ), 0, 10 ) ) . "\n";
}
if ( $solo_alumno ) {
	echo "  Ejemplos solo-en-alumno (curso:alumno): " . implode( ', ', array_slice( array_keys( $solo_alumno ), 0, 10 ) ) . "\n";
}

printf( "\nArray de matrícula más grande: curso %d, %d bytes serializados.\n", $blob_curso, $blob_max );
echo "  (Referencia de escala: este blob se reescribe entero en CADA matrícula.)\n";

// ── Resumen ──────────────────────────────────────────────────────────────────

d_head( 'RESUMEN' );

printf( "Entregas calificadas:                      %d\n", count( $submissions ) );
printf( "  · de alumno NO matriculado (invisibles): %d  %s\n",
	count( $huerfanas ),
	$huerfanas ? '→ ' . implode( ', ', array_slice( $huerfanas, 0, 15 ) ) : '' );
printf( "  · graded pero SIN nota guardada:         %d  %s\n",
	count( $sin_nota ),
	$sin_nota ? '→ ' . implode( ', ', array_slice( $sin_nota, 0, 15 ) ) : '' );
printf( "  · con curso discrepante entrega/lección: %d  %s\n",
	count( $curso_discrepa ),
	$curso_discrepa ? '→ ' . implode( ', ', array_slice( $curso_discrepa, 0, 15 ) ) : '' );
printf( "Divergencias entre las dos copias:          %d\n",
	count( $solo_curso ) + count( $solo_alumno ) );

echo "\nNada de esto se ha modificado. Script de solo lectura.\n";

