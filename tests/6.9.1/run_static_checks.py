#!/usr/bin/env python3
"""
tests/6.9.1/run_static_checks.py — regresión estática para el hotfix
de alcance en el listado de rúbricas (PT-1, 6.9.1), sin depender de un
intérprete PHP (no disponible en este entorno — mismo hueco disclosed
en cada sprint anterior de este proyecto).

Hallazgo (auditoría de deudas técnicas post-6.7.0, confirmado leyendo
el código antes de tocarlo): GET /rubrics (get_rubrics(),
includes/rest/class-rest-extensions-controller.php) SÍ tenía una
verificación de alcance -- pero usaba CLMS_Access::can_access_admin(),
que solo comprueba la capacidad 'clms_access_admin'. Esa capacidad la
tiene el rol instructor POR DEFECTO (includes/class-access.php, bloque
$instructor_caps) -- no significa "puede ver contenido ajeno", solo
"puede entrar al panel ATORA". Con ese chequeo, cualquier instructor
veía las rúbricas de TODOS los instructores al listar, aunque
get_rubric()/update_rubric()/delete_rubric() (mismo archivo, más
abajo) sí verifican propiedad real por rúbrica individual vía
current_user_can_manage_post_resource() -- exactamente el patrón de
bug "un endpoint de la familia tiene el gate correcto, el hermano no"
que esta serie de sprints ya aprendió a reconocer.

Uso: python3 tests/6.9.1/run_static_checks.py
Sale con código 0 si todo pasa, 1 si algo falla.
"""

import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))

FAILURES = []
PASSES = []


def log_pass(name, detail=''):
    PASSES.append((name, detail))
    print(f'PASS  {name}' + (f' — {detail}' if detail else ''))


def log_fail(name, detail):
    FAILURES.append((name, detail))
    print(f'FAIL  {name} — {detail}')


def read(rel_path):
    with open(os.path.join(ROOT, rel_path), encoding='utf-8', errors='replace') as fh:
        return fh.read()


EXTENSIONS_CONTROLLER = 'includes/rest/class-rest-extensions-controller.php'
ACCESS = 'includes/class-access.php'


# ── 01: get_rubrics() ya no usa can_access_admin() como el bypass de "ve todo" ──

def check_get_rubrics_no_longer_uses_can_access_admin():
    text = read(EXTENSIONS_CONTROLLER)

    m = re.search(r'public function get_rubrics\( WP_REST_Request \$request \) \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('01-no-can-access-admin-bypass', 'get_rubrics() no encontrado')
        return
    body = m.group(1)

    # Busca una llamada real (Clase::método()), no la mención en el
    # comentario de docblock que este mismo parche agrega explicando
    # qué se sacó.
    if re.search(r'(::|->)\s*can_access_admin\(', body):
        log_fail('01-no-can-access-admin-bypass', "get_rubrics() todavía llama a can_access_admin() -- esa capacidad la tiene cualquier instructor por defecto, no distingue 'puede ver lo suyo' de 'puede ver todo'")
        return

    log_pass('01-no-can-access-admin-bypass', 'get_rubrics() ya no usa can_access_admin() como criterio de "ve todo"')


# ── 02: get_rubrics() ahora usa edit_others_lm_courses / manage_options — el mismo criterio ya establecido en el LMS ──

def check_get_rubrics_uses_established_broad_scope_criterion():
    text = read(EXTENSIONS_CONTROLLER)

    m = re.search(r'public function get_rubrics\( WP_REST_Request \$request \) \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('02-uses-edit-others-criterion', 'get_rubrics() no encontrado')
        return
    body = m.group(1)

    if "current_user_can( 'edit_others_lm_courses' )" not in body:
        log_fail('02-uses-edit-others-criterion', "get_rubrics() no verifica 'edit_others_lm_courses' -- ese es el criterio de alcance amplio que el resto del LMS ya usa (ver tests/LMS/LMSCourseVisibilityTest.php), no uno inventado para este parche")
        return
    if "current_user_can( 'manage_options' )" not in body:
        log_fail('02-uses-edit-others-criterion', "get_rubrics() no incluye manage_options como respaldo del bypass amplio")
        return
    if "\\$args['author'] = get_current_user_id()" not in body and "$args['author'] = get_current_user_id()" not in body:
        log_fail('02-uses-edit-others-criterion', "get_rubrics() ya no filtra por autor cuando el usuario no tiene el alcance amplio -- se perdió el filtro, no solo se cambió el criterio")
        return

    log_pass('02-uses-edit-others-criterion', "get_rubrics() usa edit_others_lm_courses / manage_options como bypass de alcance amplio -- el mismo criterio ya establecido en otros endpoints del LMS (course visibility) -- y sigue filtrando por autor en caso contrario")


# ── 03: confirma la causa raíz -- clms_access_admin es una capacidad que el instructor SÍ tiene por defecto ──

def check_root_cause_instructor_has_clms_access_admin():
    text = read(ACCESS)

    # Esto no es un chequeo de que el bug exista (ya lo arreglamos) --
    # es la verificación de que el diagnóstico era correcto: si esta
    # capacidad alguna vez deja de estar en el bloque de capacidades
    # por defecto del instructor, el hallazgo original ya no aplicaría
    # y valdría la pena revisar si este parche sigue siendo necesario.
    m = re.search(r"\$instructor_caps = array\((.*?)\n\t\t\);", text, re.DOTALL)
    if not m:
        log_fail('03-root-cause-confirmed', 'no se encontró el bloque $instructor_caps en class-access.php -- no se pudo confirmar la causa raíz documentada')
        return
    block = m.group(1)

    if not re.search(r"'clms_access_admin'\s*=>\s*true", block):
        log_fail('03-root-cause-confirmed', "'clms_access_admin' => true ya no está en \\$instructor_caps -- el diagnóstico documentado en el commit de este parche ya no describe la causa raíz real, revisar si el fix sigue siendo necesario tal como está")
        return
    if re.search(r"'edit_others_lm_courses'\s*=>\s*true", block):
        log_fail('03-root-cause-confirmed', "el instructor ahora SÍ tiene 'edit_others_lm_courses' => true -- el nuevo criterio de get_rubrics() ya no lo excluiría, revisar")
        return

    log_pass('03-root-cause-confirmed', "confirmado: el rol instructor tiene 'clms_access_admin' pero no 'edit_others_lm_courses' en su bloque de capacidades por defecto -- el diagnóstico y el fix son consistentes con el código real")


def main():
    check_get_rubrics_no_longer_uses_can_access_admin()
    check_get_rubrics_uses_established_broad_scope_criterion()
    check_root_cause_instructor_has_clms_access_admin()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
