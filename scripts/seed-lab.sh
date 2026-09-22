#!/usr/bin/env bash
set -euo pipefail

# Seed mínimo y repetible para ATORA Lab (6.26.5).
#
# Requiere WP-CLI. En el contenedor de Lab normalmente se ejecuta como root,
# por eso usamos --allow-root por defecto.

WP=${WP:-"wp --allow-root"}

echo "== Seed ATORA Lab 6.26.5 =="

ADMIN_ID=${ADMIN_ID:-}
INSTRUCTOR_LOGIN=${INSTRUCTOR_LOGIN:-docente_prueba}
ASSISTANT_LOGIN=${ASSISTANT_LOGIN:-asistente_prueba}
STUDENT_LOGIN=${STUDENT_LOGIN:-estudiante_demo}
STUDENT_EMAIL=${STUDENT_EMAIL:-estudiante.demo@atora.test}
STUDENT_PASS=${STUDENT_PASS:-"atora_lab_6265"}

echo "Aplicando perfil de instalación 'academia' (módulos)…"
$WP eval "
if ( class_exists( 'CLMS_Install_Profiles' ) ) {
  if ( ! CLMS_Install_Profiles::apply( 'academia' ) ) {
    CLMS_Install_Profiles::apply( 'academia' );
  }
  echo 'profile=' . CLMS_Install_Profiles::current() . \"\\n\";
  \$active = (array) get_option( 'atora_active_modules', array() );
  sort( \$active );
  echo 'active_modules=' . implode( ', ', \$active ) . \"\\n\";
} else {
  echo \"no-install-profiles\\n\";
}
"

echo "Asegurando tablas de módulos activos (dbDelta)…"
$WP eval 'if ( class_exists( "\\ATORA\\V5_Installer" ) && method_exists( "\\ATORA\\V5_Installer", "ensure_active_module_tables" ) ) { \\ATORA\\V5_Installer::ensure_active_module_tables(); echo "tables_ok\n"; } else { echo "tables_skip\n"; }'

echo "Asegurando usuarios…"
if [ -z "${ADMIN_ID}" ]; then
  ADMIN_ID=$($WP user list --role=administrator --field=ID --format=ids | awk '{print $1}')
fi
if [ -z "${ADMIN_ID}" ]; then
  echo "No se pudo resolver un administrador (ADMIN_ID)." >&2
  exit 1
fi

if $WP user get "$INSTRUCTOR_LOGIN" --field=ID >/dev/null 2>&1; then
  INSTRUCTOR_ID=$($WP user get "$INSTRUCTOR_LOGIN" --field=ID)
else
  INSTRUCTOR_ID=$($WP user create "$INSTRUCTOR_LOGIN" "${INSTRUCTOR_LOGIN}@example.com" --role=author --user_pass="atora_lab_6265" --display_name="Docente Prueba" --porcelain)
fi

if $WP user get "$ASSISTANT_LOGIN" --field=ID >/dev/null 2>&1; then
  ASSISTANT_ID=$($WP user get "$ASSISTANT_LOGIN" --field=ID)
else
  ASSISTANT_ID=$($WP user create "$ASSISTANT_LOGIN" "${ASSISTANT_LOGIN}@example.com" --role=subscriber --user_pass="atora_lab_6265" --display_name="Asistente Prueba" --porcelain)
fi

STUDENT_ROLE=$($WP eval 'echo get_role("student") ? "student" : "subscriber";')
if $WP user get "$STUDENT_LOGIN" --field=ID >/dev/null 2>&1; then
  STUDENT_ID=$($WP user get "$STUDENT_LOGIN" --field=ID)
else
  STUDENT_ID=$($WP user create "$STUDENT_LOGIN" "$STUDENT_EMAIL" --role="$STUDENT_ROLE" --user_pass="$STUDENT_PASS" --display_name="Estudiante Demo" --porcelain)
fi

echo "Usuarios: instructor=$INSTRUCTOR_ID assistant=$ASSISTANT_ID student=$STUDENT_ID"

echo "Creando curso/lección…"
COURSE_POST_ID=$($WP post create --post_type=lm_course --post_status=publish --post_author="$INSTRUCTOR_ID" --post_title="Curso Seed 6.26.5" --porcelain)
LESSON_POST_ID=$($WP post create --post_type=lm_lesson --post_status=publish --post_author="$INSTRUCTOR_ID" --post_title="Lección Seed 6.26.5" --post_content="Contenido de prueba." --porcelain)

echo "Creando rúbricas (postmeta)…"
RUBRIC_STRUCT_ID=$($WP post create --post_type=clms_rubric --post_status=publish --post_author="$INSTRUCTOR_ID" --post_title="Rúbrica Estructurada" --porcelain)
$WP post meta update "$RUBRIC_STRUCT_ID" _clms_rubric_scale_type 0_100 >/dev/null
$WP post meta update "$RUBRIC_STRUCT_ID" _clms_rubric_is_holistic 0 >/dev/null

$WP eval "
\$rubric_id = (int) ${RUBRIC_STRUCT_ID};
\$criteria = array(
  array(
    'name' => 'Claridad',
    'description' => 'El texto se entiende.',
    'max_points' => 10,
    'weight' => 33.33,
    'levels' => array(
      array('label'=>'Inicial','points'=>2,'descriptor'=>''),
      array('label'=>'En desarrollo','points'=>5,'descriptor'=>''),
      array('label'=>'Competente','points'=>8,'descriptor'=>''),
      array('label'=>'Excelente','points'=>10,'descriptor'=>''),
    ),
  ),
  array(
    'name' => 'Estructura',
    'description' => 'La entrega tiene orden lógico.',
    'max_points' => 10,
    'weight' => 33.33,
    'levels' => array(
      array('label'=>'Inicial','points'=>2,'descriptor'=>''),
      array('label'=>'En desarrollo','points'=>5,'descriptor'=>''),
      array('label'=>'Competente','points'=>8,'descriptor'=>''),
      array('label'=>'Excelente','points'=>10,'descriptor'=>''),
    ),
  ),
  array(
    'name' => 'Argumentación',
    'description' => 'Sustenta con razones.',
    'max_points' => 10,
    'weight' => 33.34,
    'levels' => array(
      array('label'=>'Inicial','points'=>2,'descriptor'=>''),
      array('label'=>'En desarrollo','points'=>5,'descriptor'=>''),
      array('label'=>'Competente','points'=>8,'descriptor'=>''),
      array('label'=>'Excelente','points'=>10,'descriptor'=>''),
    ),
  ),
);
update_post_meta( \$rubric_id, '_clms_rubric_criteria', \$criteria );
echo \"ok\\n\";
" >/dev/null

RUBRIC_HOL_ID=$($WP post create --post_type=clms_rubric --post_status=publish --post_author="$INSTRUCTOR_ID" --post_title="Rúbrica Holística" --porcelain)
$WP post meta update "$RUBRIC_HOL_ID" _clms_rubric_scale_type 0_100 >/dev/null
$WP post meta update "$RUBRIC_HOL_ID" _clms_rubric_is_holistic 1 >/dev/null
$WP post meta update "$RUBRIC_HOL_ID" _clms_rubric_criteria '[{"name":"Evaluación global","description":"Impresión general.","max_points":30,"weight":100,"levels":[{"label":"Bajo","points":10,"descriptor":""},{"label":"Medio","points":20,"descriptor":""},{"label":"Alto","points":30,"descriptor":""}]}]' --format=json >/dev/null

RUBRIC_NL_ID=$($WP post create --post_type=clms_rubric --post_status=publish --post_author="$INSTRUCTOR_ID" --post_title="Rúbrica NL" --porcelain)
$WP post meta update "$RUBRIC_NL_ID" _clms_rubric_scale_type 0_100 >/dev/null
$WP post meta update "$RUBRIC_NL_ID" _clms_rubric_is_holistic 0 >/dev/null
$WP eval "
\$rubric_id = (int) ${RUBRIC_NL_ID};
\$criteria = array(
  array(
    'name' => 'Criterio NL',
    'description' => 'Se evalúa en lenguaje natural.',
    'max_points' => 10,
    'weight' => 100,
    'type' => 'natural_language',
    'nl_prompt' => 'Evalúa la entrega considerando claridad, estructura y argumentación.',
    'levels' => array(),
  ),
);
update_post_meta( \$rubric_id, '_clms_rubric_criteria', \$criteria );
echo \"ok\\n\";
" >/dev/null

echo "Asignando rúbrica estructurada a la lección…"
$WP post meta update "$LESSON_POST_ID" _clms_rubric_id "$RUBRIC_STRUCT_ID" >/dev/null
$WP post meta update "$LESSON_POST_ID" _clms_course_id "$COURSE_POST_ID" >/dev/null

echo "Creando entrega (clms_submission)…"
SUBMISSION_ID=$($WP post create --post_type=clms_submission --post_status=publish --post_author="$STUDENT_ID" --post_title="Entrega Seed 6.26.5" --porcelain)
$WP post meta update "$SUBMISSION_ID" _clms_submission_lesson_id "$LESSON_POST_ID" >/dev/null
$WP post meta update "$SUBMISSION_ID" _clms_submission_course_id "$COURSE_POST_ID" >/dev/null
$WP post meta update "$SUBMISSION_ID" _clms_submission_user_id "$STUDENT_ID" >/dev/null
$WP post meta update "$SUBMISSION_ID" _clms_submission_status submitted >/dev/null

echo "Asegurando curso/lección en tablas LMS + matrícula…"
$WP eval "
\$course_post = get_post( (int) ${COURSE_POST_ID} );
\$course_id = \\ATORA\\LMS\\LMS_Course_Service::create( array(
  'title' => (string) \$course_post->post_title,
  'slug' => (string) \$course_post->post_name,
  'description' => 'Curso seed',
  'excerpt' => 'Curso seed',
  'status' => 'published',
  'type' => 'self_paced',
  'instructor_id' => (int) ${INSTRUCTOR_ID},
  'language' => 'es',
) );
\\ATORA\\LMS\\LMS_Course_Service::link_to_legacy_post( \$course_id, (int) ${COURSE_POST_ID} );

	\\ATORA\\LMS\\LMS_Course_Service::upsert_lesson( array(
	  'wp_post_id' => (int) ${LESSON_POST_ID},
	  'course_id' => \$course_id,
	  'title' => 'Lección Seed 6.26.5',
	  'slug' => 'leccion-seed-6265',
  'content' => 'Contenido de prueba.',
  'lesson_order' => 1,
  'section' => 'General',
  'section_order' => 0,
  'type' => 'text',
  'duration_min' => 5,
	  'status' => 'published',
	) );

	// Matrícula legacy autoritativa (escribe ambos metas + dispara compat layer).
	CLMS_Helper::enroll_user_in_course( (int) ${STUDENT_ID}, (int) ${COURSE_POST_ID} );
	echo \"ok\\n\";
	" >/dev/null

echo "Creando delegación instructor→assistant (perm grade)…"
$WP eval "
global \$wpdb;
\$table = \$wpdb->prefix . 'atora_instructor_delegations';
\$inst = (int) get_option( 'atora_default_institution', 1 );
\$wpdb->insert(
  \$table,
  array(
    'institution_id' => \$inst,
    'instructor_id'  => (int) ${INSTRUCTOR_ID},
    'assistant_id'   => (int) ${ASSISTANT_ID},
    'scope'          => 'instructor',
    'wp_course_id'   => 0,
    'status'         => 'active',
    'perms_json'     => wp_json_encode( array( 'grade' => true ) ),
    'created_by'     => (int) ${ADMIN_ID},
    'created_at'     => current_time( 'mysql', true ),
    'expires_at'     => null,
  ),
  array( '%d','%d','%d','%s','%d','%s','%s','%d','%s','%s' )
);
echo \"ok\\n\";
" >/dev/null

echo "== Seed completado =="
echo "Curso (WP): $COURSE_POST_ID"
echo "Lección (WP): $LESSON_POST_ID"
echo "Rúbrica estructurada: $RUBRIC_STRUCT_ID"
echo "Rúbrica holística: $RUBRIC_HOL_ID"
echo "Rúbrica NL: $RUBRIC_NL_ID"
echo "Entrega (WP clms_submission): $SUBMISSION_ID"
echo "Estudiante: $STUDENT_LOGIN / $STUDENT_PASS"
