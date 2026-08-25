#!/usr/bin/env python3
"""
tests/6.11.0/run_static_checks.py — regresión estática para el sprint
6.11.0 (contacts-core, ficha de estudiante con timeline de
intervenciones, vista de coordinador con datos reales), sin depender
de un intérprete PHP (no disponible en este entorno — mismo hueco
disclosed en cada sprint anterior de este proyecto).

Uso: python3 tests/6.11.0/run_static_checks.py
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


def strip_comments(php):
    """Quita /* */ y // para evitar falsos positivos en docblocks."""
    php = re.sub(r'/\*.*?\*/', '', php, flags=re.S)
    php = re.sub(r'//[^\n]*', '', php)
    return php


CONTACTS_CORE   = 'includes/contacts-core/class-contacts-core-service.php'
CRM_ACCESS      = 'modules/crm/trait-crm-access.php'
CRM_CONTACTS    = 'modules/crm/trait-crm-contacts.php'
LOADER_GROUPS   = 'includes/loader/trait-loader-module-groups.php'
BRIDGE          = 'includes/academic/class-academic-messaging-bridge.php'
STUDENT_PROFILE = 'includes/admin-menu/trait-admin-menu-student-profile.php'
SECTION_COORD   = 'includes/admin-menu/trait-admin-menu-section-coordinator.php'
ADMIN_MENU      = 'includes/class-admin-menu.php'
ADMIN_HUBS      = 'includes/admin-menu/trait-admin-menu-hubs.php'
SECTION_SERVICE = 'modules/lms/class-lms-section-service.php'
TODAY_AGG       = 'includes/today/class-today-aggregator-service.php'
METABOX_COHORT  = 'includes/class-metabox-cohort.php'

MIGRATED_PRIMITIVES = (
    'can_access_crm', 'can_manage_crm', 'has_global_contact_scope',
    'get_accessible_contact_user_ids', 'get_contact', 'log_activity', 'get_timeline',
)

# Consumidores confirmados en la investigación de este sprint -- no deben
# haber sido tocados por la extracción (siguen llamando a CRM:: sin cambios).
EXTERNAL_CRM_CONSUMERS = (
    'modules/crm-v2/class-crm-v2-app.php',
    'modules/crm-v2/class-crm-v2.php',
    'modules/crm-v2/trait-crm-v2-pipeline.php',
    'modules/crm-v2/trait-crm-v2-segmenter.php',
    'modules/crm-v2/services/class-contact-service.php',
    'modules/crm-v2/services/class-activity-service.php',
    'modules/crm-v2/rest/class-crm-rest-controller.php',
    'includes/ui/class-ui-search-service.php',
    'includes/frontend/trait-frontend-access-profile.php',
    'includes/enrollment-manager/trait-enrollment-manager-access-enrollment.php',
    'includes/admin-menu/trait-admin-menu-main-pages-academic.php',
    'includes/metabox-program/trait-metabox-program-enrollment-ajax.php',
    'modules/automation/class-automation-engine.php',
)


# ── 01: el servicio neutro existe y contiene las 7 primitivas migradas ──

def check_contacts_core_exists():
    content = read(CONTACTS_CORE)
    missing = [p for p in MIGRATED_PRIMITIVES if f'function {p}(' not in content]
    if missing:
        log_fail('01-contacts-core-exists', f'faltan primitivas en {CONTACTS_CORE}: {missing}')
        return
    log_pass('01-contacts-core-exists', f'{CONTACTS_CORE} define las 7 primitivas migradas')


# ── 02: CRM:: (v1) ya no contiene SQL propio para las primitivas migradas — delega ──

def check_v1_delegates_not_duplicates():
    for path, primitives in ((CRM_ACCESS, ('can_access_crm', 'can_manage_crm', 'has_global_contact_scope', 'get_accessible_contact_user_ids')),
                              (CRM_CONTACTS, ('get_contact', 'log_activity', 'get_timeline'))):
        content = read(path)
        for primitive in primitives:
            m = re.search(r'function\s+' + primitive + r'\([^)]*\)[^{]*\{(.*?)\n\t\}', content, re.S)
            if not m:
                log_fail('02-v1-delegates', f'{path}: no se encontró el cuerpo de {primitive}()')
                continue
            body = m.group(1)
            if 'global $wpdb' in body or 'SELECT' in body.upper() or 'INSERT INTO' in body.upper():
                log_fail('02-v1-delegates', f'{path}: {primitive}() todavía contiene SQL propio -- se esperaba que delegara')
                continue
            if 'clms_core( \'CLMS_Contacts_Core_Service\' )' not in body and 'clms_core(\'CLMS_Contacts_Core_Service\')' not in body:
                log_fail('02-v1-delegates', f'{path}: {primitive}() no delega a CLMS_Contacts_Core_Service')
                continue
    if not any(f[0] == '02-v1-delegates' for f in FAILURES):
        log_pass('02-v1-delegates', 'las 7 primitivas en modules/crm/ delegan al servicio neutro, sin SQL duplicado')


# ── 03: el servicio se registra en el grupo 'core' del loader, sin condition ──

def check_loader_registration():
    content = read(LOADER_GROUPS)
    idx = content.find("'class'        => 'CLMS_Contacts_Core_Service'")
    if idx < 0:
        log_fail('03-loader-registration', 'CLMS_Contacts_Core_Service no está registrado en el loader')
        return
    # Bloque del array que contiene esa línea: buscar el 'condition' más cercano hacia atrás/adelante dentro del mismo array().
    window = content[max(0, idx - 300):idx + 300]
    if "'condition'" in window:
        log_fail('03-loader-registration', 'CLMS_Contacts_Core_Service tiene un condition -- se esperaba que cargue siempre (grupo core, sin condition)')
        return
    log_pass('03-loader-registration', 'registrado en el loader sin condition (carga siempre)')


# ── 04: ningún consumidor externo confirmado de CRM:: fue tocado (sigue llamando CRM::) ──

def check_external_consumers_untouched():
    missing = []
    for path in EXTERNAL_CRM_CONSUMERS:
        full = os.path.join(ROOT, path)
        if not os.path.exists(full):
            missing.append(path)
            continue
        content = read(path)
        if 'CRM::' not in content and '\\ATORA\\CRM\\CRM' not in content:
            missing.append(path)
    if missing:
        log_fail('04-external-consumers-untouched', f'archivos que dejaron de llamar a CRM:: (se esperaba que no cambiaran): {missing}')
        return
    log_pass('04-external-consumers-untouched', f'los {len(EXTERNAL_CRM_CONSUMERS)} consumidores externos confirmados siguen llamando a CRM:: sin cambios')


# ── 05: on_at_risk_signal() persiste en el timeline (academic_at_risk_alert) ──

def check_bridge_logs_at_risk():
    content = strip_comments(read(BRIDGE))
    m = re.search(r'function on_at_risk_signal.*?\n\t\}', content, re.S)
    if not m:
        log_fail('05-bridge-logs-at-risk', 'no se encontró on_at_risk_signal()')
        return
    body = m.group(0)
    if 'academic_at_risk_alert' not in body or 'log_activity(' not in body:
        log_fail('05-bridge-logs-at-risk', 'on_at_risk_signal() no llama a log_activity() con tipo academic_at_risk_alert')
        return
    log_pass('05-bridge-logs-at-risk', 'on_at_risk_signal() persiste una entrada academic_at_risk_alert')


# ── 06: la ficha de estudiante gatea correctamente (no clms_access_admin a secas) ──

def check_student_profile_capability_gate():
    content = strip_comments(read(STUDENT_PROFILE))
    if 'clms_access_admin' in content:
        log_fail('06-student-profile-gate', 'trait-admin-menu-student-profile.php usa clms_access_admin -- mismo error que el fix de 6.9.1 (PT-1), esa cap solo significa "puede entrar al panel", no "ve todo"')
        return
    if "current_user_can( 'edit_others_lm_courses' )" not in content:
        log_fail('06-student-profile-gate', 'no usa edit_others_lm_courses como criterio de alcance amplio')
        return
    if 'get_effective_instructor' not in content or 'get_effective_coordinator' not in content:
        log_fail('06-student-profile-gate', 'no verifica si el usuario es el docente/coordinador efectivo del estudiante+curso')
        return
    log_pass('06-student-profile-gate', 'gate correcto: edit_others_lm_courses/manage_options o docente/coordinador efectivo')


# ── 07: las dos páginas nuevas están registradas y enganchadas ──

def check_pages_wired_up():
    hubs = read(ADMIN_HUBS)
    menu = read(ADMIN_MENU)

    checks = [
        ('atora-student-profile' in hubs, 'atora-student-profile no está registrado en register_admin_pages()'),
        ('atora-section-coordinator' in hubs, 'atora-section-coordinator no está registrado en register_admin_pages()'),
        ('CLMS_Admin_Menu_Student_Profile_Trait' in menu, 'el trait de ficha de estudiante no está usado en CLMS_Admin_Menu'),
        ('CLMS_Admin_Menu_Section_Coordinator_Trait' in menu, 'el trait de coordinador no está usado en CLMS_Admin_Menu'),
        ('admin_post_atora_student_profile_add_note' in menu, 'el handler de notas no está enganchado a admin_post'),
        ('admin_post_atora_set_section_coordinator' in menu, 'el handler de asignación no está enganchado a admin_post'),
    ]
    ok = True
    for condition, msg in checks:
        if not condition:
            log_fail('07-pages-wired-up', msg)
            ok = False
    if ok:
        log_pass('07-pages-wired-up', 'ambas páginas registradas y enganchadas en CLMS_Admin_Menu')


# ── 08: get_allowed_teacher_roles() incluye 'coordinator' ──

def check_coordinator_role_allowed():
    content = read(SECTION_SERVICE)
    m = re.search(r'function get_allowed_teacher_roles\(\): array \{(.*?)\n\t\}', content, re.S)
    if not m or 'ROLE_COORDINATOR' not in m.group(1):
        log_fail('08-coordinator-role-allowed', "get_allowed_teacher_roles() no incluye ROLE_COORDINATOR -- add_teacher() seguiría degradando 'coordinator' a 'lead' en silencio")
        return
    if 'function set_coordinator( int $section_id, int $user_id ): bool' not in content:
        log_fail('08-coordinator-role-allowed', 'falta Section_Service::set_coordinator()')
        return
    if 'function get_coordinator_section_ids( int $user_id ): array' not in content:
        log_fail('08-coordinator-role-allowed', 'falta Section_Service::get_coordinator_section_ids()')
        return
    log_pass('08-coordinator-role-allowed', "'coordinator' es un rol asignable; set_coordinator()/get_coordinator_section_ids() existen")


# ── 09: collect_coordinator_items() sigue el shape de collect_academic_digest_items() ──

def check_coordinator_items_shape():
    content = strip_comments(read(TODAY_AGG))
    m = re.search(r'function collect_coordinator_items.*?\n\t\}', content, re.S)
    if not m:
        log_fail('09-coordinator-items-shape', 'no se encontró collect_coordinator_items()')
        return
    body = m.group(0)
    required_keys = ('source', 'title', 'count', 'urgency', 'url', '_tier', '_has_specific_time')
    missing = [k for k in required_keys if f"'{k}'" not in body]
    if missing:
        log_fail('09-coordinator-items-shape', f'collect_coordinator_items() no tiene las claves {missing} -- no sigue el shape de collect_academic_digest_items()')
        return
    if 'get_today( $user_id )' in content:
        get_today_body = re.search(r'function get_today\(.*?\n\t\}', content, re.S).group(0)
        if 'collect_coordinator_items' not in get_today_body:
            log_fail('09-coordinator-items-shape', 'collect_coordinator_items() existe pero get_today() no la llama')
            return
    log_pass('09-coordinator-items-shape', 'collect_coordinator_items() sigue el shape confirmado y está enganchada en get_today()')


# ── 10: la fuente de datos del coordinador es una consulta agregada, no un loop de get_student_course_status() ──

def check_no_heavy_loop_in_coordinator_items():
    content = strip_comments(read(TODAY_AGG))
    m = re.search(r'function collect_coordinator_items.*?\n\t\}', content, re.S)
    if not m:
        log_fail('10-no-heavy-loop', 'no se encontró collect_coordinator_items() (ver check 09)')
        return
    if 'get_student_course_status' in m.group(0):
        log_fail('10-no-heavy-loop', 'collect_coordinator_items() llama a get_student_course_status() -- riesgo de performance ya identificado en el plan de este sprint (N+1 por estudiante de cada sección en cada carga de Hoy)')
        return
    if 'count_recent_activity_for_users' not in m.group(0):
        log_fail('10-no-heavy-loop', 'collect_coordinator_items() no usa count_recent_activity_for_users() (la consulta agregada de una sola pasada)')
        return
    log_pass('10-no-heavy-loop', 'usa una consulta agregada (count_recent_activity_for_users), no un loop pesado por estudiante')


# ── 11: metabox de cohorte enlaza a la asignación de coordinador (columna nueva) ──

def check_cohort_metabox_links_coordinator():
    content = read(METABOX_COHORT)
    if 'atora-section-coordinator' not in content or 'get_coordinator(' not in content:
        log_fail('11-cohort-metabox-link', 'la tabla de secciones del metabox de cohorte no muestra/enlaza al coordinador')
        return
    log_pass('11-cohort-metabox-link', 'la tabla de secciones del metabox de cohorte muestra el coordinador y enlaza a asignarlo')


def main():
    check_contacts_core_exists()
    check_v1_delegates_not_duplicates()
    check_loader_registration()
    check_external_consumers_untouched()
    check_bridge_logs_at_risk()
    check_student_profile_capability_gate()
    check_pages_wired_up()
    check_coordinator_role_allowed()
    check_coordinator_items_shape()
    check_no_heavy_loop_in_coordinator_items()
    check_cohort_metabox_links_coordinator()

    print(f'\n{len(PASSES)} pasaron, {len(FAILURES)} fallaron.')
    sys.exit(1 if FAILURES else 0)


if __name__ == '__main__':
    main()
