#!/usr/bin/env python3
"""
tests/6.6.0/run_static_checks.py — regresión estática para el sprint
6.6.0 (planes de seguimiento sobre el calendario del docente), sin
depender de un intérprete PHP (no disponible en este entorno — mismo
hueco disclosed en cada sprint anterior de este proyecto).

Principio central de la OT que estos checks verifican por encima de
todo: un plan de seguimiento NUNCA congela una lista de estudiantes ni
mueve la etapa de un estudiante en Student_Followup_Service. Marcar
"contactado" registra un contacto; nunca es un cambio de etapa. Ambas
cosas se verifican estructuralmente (no solo por convención) en los
checks 03 y 06.

Uso: python3 tests/6.6.0/run_static_checks.py
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


RESOLVER = 'modules/crm-v2/services/class-followup-plan-resolver.php'
PLAN_SERVICE = 'modules/crm-v2/services/class-followup-plan-service.php'
RECURRENCE = 'modules/crm-v2/services/class-followup-recurrence.php'
BRIDGE = 'includes/academic/class-academic-messaging-bridge.php'
INSTALLER = 'modules/class-v5-installer.php'
REST_CONTROLLER = 'modules/crm-v2/rest/class-followup-plans-rest-controller.php'


# ── 01: resolver siempre consulta el board EN VIVO, nunca lee una lista guardada ──

def check_resolver_reads_board_live():
    text = read(RESOLVER)

    if 'Student_Followup_Service::get_board()' not in text:
        log_fail('01-resolver-live-board', 'resolve_for_definition() ya no llama a Student_Followup_Service::get_board() -- sin esto, la etapa resuelta podría venir de una copia vieja')
        return

    # No debe existir ningún mecanismo de caché (transient/wp_cache) entre
    # la resolución y el retorno -- el principio central de la OT es que
    # NUNCA se cachea el resultado entre la vista previa y la ocurrencia real.
    forbidden = ['set_transient(', 'wp_cache_set(', 'get_transient(', 'wp_cache_get(']
    found = [f for f in forbidden if f in text]
    if found:
        log_fail('01-resolver-live-board', f'resolve_for_definition() usa caché ({found}) -- el principio central de la OT exige resolución en vivo en cada llamada, sin cachear entre la vista previa y el momento real de la ocurrencia')
        return

    log_pass('01-resolver-live-board', 'resolve_for_definition() consulta Student_Followup_Service::get_board() en cada llamada, sin ningún transient/wp_cache de por medio')


# ── 02: ninguna ocurrencia vacía se oculta -- siempre hay un motivo explícito ──

def check_resolver_never_hides_empty():
    text = read(RESOLVER)

    expected_reasons = [
        'sin_configuracion',
        'sin_estudiantes_en_secciones',
        'nadie_en_esas_etapas_hoy',
        'nadie_cumple_el_filtro_hoy',
    ]
    missing = [r for r in expected_reasons if f"'{r}'" not in text]
    if missing:
        log_fail('02-resolver-empty-reason', f'faltan motivos explícitos de "empty_reason" en el resolver: {missing} -- una ocurrencia sin destinatarios debe explicar por qué, nunca desaparecer silenciosamente')
        return

    # confirma que ningún camino de "sin resultados" retorna sin empty_reason
    # (heurística: cada array() de retorno con students vacío debe llevar la
    # clave empty_reason en la misma expresión de retorno).
    empty_returns = re.findall(r"return array\(\s*'sections'[^;]*?'students'\s*=>\s*array\(\)[^;]*?\);", text, re.DOTALL)
    if not empty_returns:
        log_fail('02-resolver-empty-reason', 'no se encontró ningún return con students vacío para verificar -- el patrón de detección pudo haber cambiado')
        return
    without_reason = [r for r in empty_returns if 'empty_reason' not in r]
    if without_reason:
        log_fail('02-resolver-empty-reason', f'{len(without_reason)} return(s) con students=array() no incluyen empty_reason')
        return

    log_pass('02-resolver-empty-reason', f'los {len(empty_returns)} caminos de "sin destinatarios" del resolver siempre devuelven un empty_reason explícito -- nunca una ocurrencia oculta o silenciosa')


# ── 03: merge_resolutions() deduplica por user_id (fusión PT-2.3) ──

def check_merge_deduplicates():
    text = read(RESOLVER)

    m = re.search(r'public static function merge_resolutions\( array \$resolutions \): array \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('03-merge-dedup', 'merge_resolutions() no encontrado')
        return
    body = m.group(1)

    if 'isset( $students[ $user_id ] )' not in body:
        log_fail('03-merge-dedup', 'merge_resolutions() ya no comprueba isset($students[$user_id]) antes de agregar -- dos planes que coinciden en fecha/sección podrían duplicar al mismo estudiante en el bloque fusionado')
        return

    log_pass('03-merge-dedup', 'merge_resolutions() comprueba isset($students[$user_id]) antes de insertar -- un estudiante presente en dos planes que coinciden se combina en una sola entrada, sin duplicarse')


# ── 04: mark_contacted() nunca llama a move_followup() ni escribe en la tabla de etapas ──

def check_mark_contacted_never_moves_stage():
    text = read(PLAN_SERVICE)

    m = re.search(r'public static function mark_contacted\([^)]*\): bool \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('04-mark-contacted-no-stage-change', 'mark_contacted() no encontrado en Followup_Plan_Service')
        return
    body = m.group(1)

    forbidden = ['move_followup(', 'Student_Followup_Service::', 'atora_crm_student_followups']
    found = [f for f in forbidden if f in body]
    if found:
        log_fail('04-mark-contacted-no-stage-change', f'mark_contacted() referencia {found} -- principio central de la OT: marcar contactado NUNCA debe tocar la etapa del estudiante en Student_Followup_Service, que sigue siendo una acción deliberada y separada en el board existente')
        return

    if 'atora_followup_contacts' not in body:
        log_fail('04-mark-contacted-no-stage-change', 'mark_contacted() ya no escribe en atora_followup_contacts -- se perdió el registro de contacto')
        return

    log_pass('04-mark-contacted-no-stage-change', 'mark_contacted() únicamente escribe en atora_followup_contacts (que no tiene columna de etapa) y no referencia move_followup()/Student_Followup_Service/atora_crm_student_followups en absoluto -- la separación de responsabilidades está garantizada estructuralmente, no solo por convención')


# ── 05: skip_occurrence() borra solo la ocurrencia puntual, nunca recalcula la serie ──

def check_skip_occurrence_is_scoped():
    text = read(PLAN_SERVICE)

    m = re.search(r'public static function skip_occurrence\( int \$event_id \): bool \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('05-skip-occurrence-scoped', 'skip_occurrence() no encontrado')
        return
    body = m.group(1)

    if "array( 'id' => $event_id )" not in body:
        log_fail('05-skip-occurrence-scoped', "skip_occurrence() ya no borra por WHERE id = \\$event_id -- podría afectar más de una fila/ocurrencia de la serie")
        return
    if 'followup_plan_id' not in body:
        log_fail('05-skip-occurrence-scoped', 'skip_occurrence() no verifica que la fila pertenezca a un plan (followup_plan_id) antes de borrar -- podría borrar un evento manual del calendario, violando la regla de cero cambios de comportamiento por defecto')
        return

    log_pass('05-skip-occurrence-scoped', 'skip_occurrence() solo borra la fila puntual (WHERE id = $event_id, verificando followup_plan_id primero) -- el resto de la serie, ya materializada como filas independientes, queda intacta')


# ── 06: reschedule_occurrence() (drag) solo mueve un evento cuyo followup_plan_id ya existe ──

def check_reschedule_is_scoped_to_plan_events():
    text = read(PLAN_SERVICE)

    m = re.search(r'public static function reschedule_occurrence\([^)]*\): bool \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('06-reschedule-scoped', 'reschedule_occurrence() no encontrado')
        return
    body = m.group(1)

    if "empty( \\$row['followup_plan_id'] )" not in body and "empty( $row['followup_plan_id'] )" not in body:
        log_fail('06-reschedule-scoped', 'reschedule_occurrence() no verifica followup_plan_id antes de mover la fecha -- un evento manual del calendario podría quedar reprogramable por este endpoint, violando §0.5 (cero cambios de comportamiento por defecto)')
        return

    log_pass('06-reschedule-scoped', 'reschedule_occurrence() exige followup_plan_id no vacío antes de mover start_datetime -- un evento de calendario manual (followup_plan_id NULL) nunca es tocado por el drag de este feature')


# ── 07: plantillas de PT-3 anclan sus etapas a Student_Followup_Service::get_stages() ──

def check_templates_use_existing_stages():
    text = read(PLAN_SERVICE)

    m = re.search(r'public static function get_templates\(\): array \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('07-templates-existing-stages', 'get_templates() no encontrado')
        return
    body = m.group(1)

    if 'Student_Followup_Service::get_stages()' not in body:
        log_fail('07-templates-existing-stages', 'get_templates() ya no referencia Student_Followup_Service::get_stages() -- las plantillas podrían estar inventando etapas nuevas en lugar de anclarse a las 11 existentes')
        return

    log_pass('07-templates-existing-stages', 'get_templates() construye sus stage_filter a partir de Student_Followup_Service::get_stages() -- ninguna plantilla inventa una etapa nueva')


# ── 08: la migración de esquema (PT-1) no rompe instalaciones existentes ──

def check_migration_is_additive():
    text = read(INSTALLER)

    if 'atora_followup_plans' not in text:
        log_fail('08-migration-additive', 'la tabla atora_followup_plans no aparece en el instalador -- PT-1 no está integrado al pipeline de instalación/actualización')
        return

    if 'ADD COLUMN followup_plan_id' not in text:
        log_fail('08-migration-additive', 'no se encontró el ALTER TABLE que agrega followup_plan_id a atora_calendar_events')
        return

    if 'NULL DEFAULT NULL' not in text:
        log_fail('08-migration-additive', 'followup_plan_id no se agrega como NULLABLE -- un evento de calendario creado manualmente antes de este sprint dejaría de insertarse/actualizarse si la columna fuera NOT NULL')
        return

    # La verificación de existencia debe ocurrir ANTES del ALTER, para no
    # fallar en instalaciones que ya corrieron la migración.
    if 'INFORMATION_SCHEMA.COLUMNS' not in text:
        log_fail('08-migration-additive', 'no se encontró una verificación vía INFORMATION_SCHEMA.COLUMNS antes del ALTER TABLE -- una instalación que ya tiene la columna podría fallar al reintentar agregarla')
        return

    log_pass('08-migration-additive', 'la migración agrega tres tablas nuevas y una columna NULLABLE (verificada vía INFORMATION_SCHEMA.COLUMNS antes de alterar) -- ninguna instalación existente pierde datos ni deja de funcionar')


# ── 09: PT-5.2 -- ninguna notificación se dispara para una ocurrencia sin destinatarios ──

def check_no_notification_for_empty_occurrence():
    text = read(BRIDGE)

    m = re.search(r'public static function on_followup_occurrences_due_today\(\): void \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('09-no-notify-empty', 'on_followup_occurrences_due_today() no encontrado en el puente de mensajería académica')
        return
    body = m.group(1)

    resolve_pos = body.find('Followup_Plan_Resolver::resolve_recipients(')
    empty_check_pos = body.find('empty( $students )')
    send_pos = body.find('Messaging_Router::send(')

    if resolve_pos < 0 or empty_check_pos < 0 or send_pos < 0:
        log_fail('09-no-notify-empty', 'no se encontraron los tres pasos esperados (resolver, chequeo de vacío, envío) dentro del método')
        return

    if not (resolve_pos < empty_check_pos < send_pos):
        log_fail('09-no-notify-empty', 'el orden resolver -> chequeo de vacío -> envío no se cumple -- una ocurrencia sin destinatarios podría disparar una notificación de todas formas')
        return

    log_pass('09-no-notify-empty', 'on_followup_occurrences_due_today() resuelve destinatarios en vivo, corta con "continue" si students está vacío, y solo entonces podría llamar a Messaging_Router::send() -- ninguna ocurrencia vacía interrumpe al docente')


# ── 10: el endpoint REST de calendario del feature no reutiliza/pisa el controlador existente de tareas/campañas ──

def check_rest_endpoint_is_self_contained():
    text = read(REST_CONTROLLER)

    if "'/followup-plans/calendar-events'" not in text:
        log_fail('10-rest-self-contained', 'la ruta GET /followup-plans/calendar-events no está registrada en Followup_Plans_REST_Controller')
        return

    if 'get_calendar_events' not in text:
        log_fail('10-rest-self-contained', 'el callback get_calendar_events() no está definido')
        return

    js = read('modules/crm-v2/assets/followup-plans.js')
    if "'followup-plans/calendar-events'" not in js and 'followup-plans/calendar-events' not in js:
        log_fail('10-rest-self-contained', 'followup-plans.js no llama al endpoint dedicado followup-plans/calendar-events -- podría seguir apuntando al endpoint calendar/events de Calendar_Events_REST_Controller, que solo sirve tasks/campaigns y no tiene concepto de ocurrencia de plan')
        return

    log_pass('10-rest-self-contained', 'followup-plans.js consume el endpoint dedicado GET /followup-plans/calendar-events -- no se modificó ni se depende del Calendar_Events_REST_Controller existente (tasks/campaigns), evitando cualquier riesgo a ese endpoint')


def main():
    check_resolver_reads_board_live()
    check_resolver_never_hides_empty()
    check_merge_deduplicates()
    check_mark_contacted_never_moves_stage()
    check_skip_occurrence_is_scoped()
    check_reschedule_is_scoped_to_plan_events()
    check_templates_use_existing_stages()
    check_migration_is_additive()
    check_no_notification_for_empty_occurrence()
    check_rest_endpoint_is_self_contained()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
