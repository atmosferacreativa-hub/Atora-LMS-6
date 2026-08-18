<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/helper/trait-helper.php';

class CLMS_Helper {

	/**
	 * Meta oficial de relación curso -> lección.
	 */
	const COURSE_META_KEY = '_clms_course_id';

	/**
	 * Meta legacy soportada temporalmente.
	 */
	const COURSE_META_KEY_LEGACY = 'lm_course_id';

	/**
	 * Otras keys heredadas observadas en el plugin.
	 *
	 * @var array<int,string>
	 */
	const COURSE_META_KEYS_FALLBACK = array(
		'_clms_lesson_course_id',
		'course_id',
		'_lesson_course_id',
		'lesson_course_id',
	);

	/**
	 * User meta oficial de cursos inscritos.
	 */
	const USER_ENROLLED_META = '_clms_enrolled_courses';

	/**
	 * Post meta oficial de usuarios inscritos en curso.
	 */
	const COURSE_ENROLLED_META = '_clms_enrolled_users';

	/**
	 * Meta de fechas de inscripción en usuario.
	 */
	const USER_ENROLLMENT_DATES_META = '_clms_enrollment_dates';

	/**
	 * Meta de expiración de acceso por curso en usuario.
	 */
	const USER_COURSE_ACCESS_EXPIRY_META = '_clms_course_access_expiry';

	/**
	 * Meta de fechas de inscripción en curso.
	 */
	const COURSE_ENROLLMENT_DATES_META = '_clms_enrollment_dates';

	/**
	 * Post meta oficial de cursos agrupados en un programa.
	 */
	const PROGRAM_COURSES_META = '_clms_program_courses';

	/**
	 * Meta inversa curso -> programas.
	 */
	const COURSE_PROGRAMS_META = '_clms_course_program_ids';

	/**
	 * User meta oficial de programas inscritos.
	 */
	const USER_ENROLLED_PROGRAMS_META = '_clms_enrolled_programs';

	/**
	 * Post meta oficial de usuarios inscritos en programa.
	 */
	const PROGRAM_ENROLLED_USERS_META = '_clms_program_enrolled_users';

	/**
	 * Meta de expiración de acceso por programa en usuario.
	 */
	const USER_PROGRAM_ACCESS_EXPIRY_META = '_clms_program_access_expiry';

	/**
	 * Meta de prerrequisitos entre cursos.
	 */
	const COURSE_PREREQUISITES_META = '_clms_course_prerequisite_ids';

	/**
	 * Meta de prerrequisitos entre lecciones.
	 */
	const LESSON_PREREQUISITES_META = '_clms_lesson_prerequisite_ids';

	/**
	 * Caché de lecciones por curso.
	 *
	 * @var array<int,array<int,int>>
	 */
	protected static $course_lessons_cache = array();

	/**
	 * Caché curso por lección.
	 *
	 * @var array<int,int>
	 */
	protected static $lesson_course_cache = array();

	/**
	 * Evita inyectar múltiples veces las cadenas de UI.
	 *
	 * @var bool
	 */
	protected static $ui_i18n_injected = false;

	use CLMS_Helper_Trait;
}
