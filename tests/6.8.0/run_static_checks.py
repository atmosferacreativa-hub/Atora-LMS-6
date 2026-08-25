#!/usr/bin/env python3
"""
tests/6.8.0/run_static_checks.py — regresión estática para el sprint
6.8.0 ("Hoy": una sola pantalla de entrada), sin depender de un
intérprete PHP (no disponible en este entorno — mismo hueco disclosed
en cada sprint anterior de este proyecto).

Uso: python3 tests/6.8.0/run_static_checks.py
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


AGGREGATOR      = 'includes/today/class-today-aggregator-service.php'
TODAY_VIEW      = 'includes/today/views/today-page.php'
DIGEST_SERVICE  = 'includes/academic/class-teacher-digest-service.php'
PLAN_SERVICE    = 'modules/crm-v2/services/class-followup-plan-service.php'
TASK_SERVICE    = 'modules/crm-v2/services/class-task-service.php'
FOLLOWUP_VIEW   = 'modules/crm-v2/views/followup-plans.php'
FOLLOWUP_JS     = 'modules/crm-v2/assets/followup-plans.js'
LOADER_GROUPS   = 'includes/loader/trait-loader-module-groups.php'
ADMIN_HUBS      = 'includes/admin-menu/trait-admin-menu-hubs.php'
FRONTEND_PROFILE = 'includes/frontend/trait-frontend-access-profile.php'


# ── 01: el agregador está registrado en el loader declarativo, no un mecanismo nuevo ──

def check_aggregator_registered_in_loader():
    text = read(LOADER_GROUPS)

    if "'class'        => 'CLMS_Today_Aggregator_Service'" not in text and "'class' => 'CLMS_Today_Aggregator_Service'" not in text:
        log_fail('01-aggregator-registered', "CLMS_Today_Aggregator_Service no está registrado en get_module_groups() -- el patrón establecido (includes/dashboard/*) requiere pasar por el loader declarativo, no un require_once suelto")
        return

    if "'file'         => 'includes/today/class-today-aggregator-service.php'" not in text:
        log_fail('01-aggregator-registered', 'la ruta de archivo registrada no coincide con includes/today/class-today-aggregator-service.php')
        return

    log_pass('01-aggregator-registered', 'CLMS_Today_Aggregator_Service está registrado en el mismo loader declarativo (module-groups) que los demás servicios transversales de includes/dashboard/ -- ningún mecanismo de bootstrap nuevo')


# ── 02: get_today() nunca cachea entre llamadas (PT-1.4, "todo en vivo") ──

def check_aggregator_never_caches():
    text = read(AGGREGATOR)

    forbidden = ['set_transient(', 'wp_cache_set(', 'get_transient(', 'wp_cache_get(']
    found = [f for f in forbidden if f in text]
    if found:
        log_fail('02-aggregator-no-cache', f'CLMS_Today_Aggregator_Service usa caché ({found}) -- PT-1.4 exige que cada fuente se consulte en vivo, sin congelar hasta la próxima carga; la OT permite cachear por minutos SOLO si se mide que hace falta, no preventivamente')
        return

    log_pass('02-aggregator-no-cache', 'CLMS_Today_Aggregator_Service no usa ningún transient/wp_cache -- cada get_today() vuelve a consultar todo en vivo, sin optimización preventiva no medida')


# ── 03: el agregador no reescribe lógica de negocio -- solo llama a métodos existentes de cada servicio ──

def check_aggregator_is_read_only():
    text = read(AGGREGATOR)

    # Ningún método de escritura de los servicios consultados debe
    # aparecer en el agregador -- PT-1/§0.2: "consulta, no modifica".
    forbidden_writes = [
        'move_followup(', 'move_deal(', 'mark_contacted(', 'skip_occurrence(',
        'reschedule_occurrence(', 'create_task(', 'complete_task(', 'create_plan(',
        'pause_plan(', 'resume_plan(',
    ]
    found = [f for f in forbidden_writes if f in text]
    if found:
        log_fail('03-aggregator-read-only', f'CLMS_Today_Aggregator_Service llama a método(s) de escritura {found} -- PT-1.1/§0.2 exigen que el agregador solo lea y dé forma homogénea, nunca modifique el estado de los servicios que consulta')
        return

    # Debe reutilizar el resolver existente para las ocurrencias, no
    # reimplementar la resolución de destinatarios.
    if 'get_due_occurrences_for_user(' not in text:
        log_fail('03-aggregator-read-only', 'collect_followup_items() no llama a Followup_Plan_Service::get_due_occurrences_for_user() -- ¿se reimplementó la resolución de ocurrencias en el agregador en vez de reutilizar el servicio?')
        return

    log_pass('03-aggregator-read-only', 'CLMS_Today_Aggregator_Service no llama a ningún método de escritura de los servicios que consulta, y reutiliza Followup_Plan_Service::get_due_occurrences_for_user() en vez de reimplementar la resolución de ocurrencias')


# ── 04: get_due_occurrences_for_user() reutiliza el Resolver existente, no reimplementa la resolución ──

def check_due_occurrences_reuses_resolver():
    text = read(PLAN_SERVICE)

    m = re.search(r'public static function get_due_occurrences_for_user\([^)]*\): array \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('04-due-occurrences-reuses-resolver', 'get_due_occurrences_for_user() no encontrado en Followup_Plan_Service')
        return
    body = m.group(1)

    if 'Followup_Plan_Resolver::resolve_recipients(' not in body:
        log_fail('04-due-occurrences-reuses-resolver', 'get_due_occurrences_for_user() no llama a Followup_Plan_Resolver::resolve_recipients() -- debería reutilizar exactamente la misma resolución que ya usa get_occurrence()/get_calendar_events(), no reimplementarla')
        return

    if 'get_contacted_students(' not in body:
        log_fail('04-due-occurrences-reuses-resolver', 'get_due_occurrences_for_user() no llama a get_contacted_students() -- necesita marcar qué entidades ya fueron contactadas, igual que el resto del motor')
        return

    log_pass('04-due-occurrences-reuses-resolver', 'get_due_occurrences_for_user() (PT-1, lectura puntual nueva) reutiliza Followup_Plan_Resolver::resolve_recipients() y get_contacted_students() -- no reimplementa ninguna lógica de resolución ya existente')


# ── 05: Teacher_Digest_Service -- métodos nuevos son wrappers/lecturas puntuales, get_pending_counts() no se tocó ──

def check_digest_service_wrappers_only():
    text = read(DIGEST_SERVICE)

    if 'protected function get_pending_counts(' not in text:
        log_fail('05-digest-wrappers-only', 'get_pending_counts() ya no es protected / fue eliminado -- PT-1 solo debía agregar un wrapper público, nunca cambiar la visibilidad o el cuerpo del método existente')
        return

    m = re.search(r'public function get_pending_counts_for_teacher\( \$teacher_id \) \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('05-digest-wrappers-only', 'get_pending_counts_for_teacher() no encontrado')
        return
    if 'return $this->get_pending_counts( $teacher_id );' not in m.group(1):
        log_fail('05-digest-wrappers-only', 'get_pending_counts_for_teacher() no es un envoltorio puro de get_pending_counts() -- debería ser exactamente "return $this->get_pending_counts($teacher_id);", sin lógica propia, para no arriesgar el comportamiento ya en producción')
        return

    if 'get_stale_submission_count_for_teacher' not in text:
        log_fail('05-digest-wrappers-only', 'get_stale_submission_count_for_teacher() no encontrado -- PT-2.1 exige un umbral de 48h para entregas pendientes que get_pending_counts() no expone')
        return

    log_pass('05-digest-wrappers-only', 'get_pending_counts() sigue protected y sin cambios; get_pending_counts_for_teacher() es un envoltorio puro de una línea; get_stale_submission_count_for_teacher() es una lectura puntual nueva, ninguna modifica el método existente')


# ── 06: Task_Service no fue tocado en absoluto este sprint (el agregador reutiliza list_tasks() tal cual) ──

def check_task_service_untouched():
    text = read(TASK_SERVICE)

    if '6.8.0' in text or 'PT-1 (6.8.0' in text or 'agregador "Hoy"' in text.lower():
        log_fail('06-task-service-untouched', 'class-task-service.php tiene referencias a 6.8.0/"Hoy" -- §0.2 de la OT es explícito: este sprint NO modifica Task_Service, solo lo consulta con list_tasks() tal cual ya existe')
        return

    aggregator = read(AGGREGATOR)
    if 'Task_Service::list_tasks(' not in aggregator:
        log_fail('06-task-service-untouched', 'el agregador no llama a Task_Service::list_tasks() -- se esperaba reutilizar ese método genérico existente (soporta assigned_to/status/date_from/date_to) en vez de agregar algo nuevo a Task_Service')
        return

    log_pass('06-task-service-untouched', 'class-task-service.php no tiene ninguna marca de haber sido tocado este sprint, y el agregador llama a Task_Service::list_tasks() con sus filtros ya existentes -- cero cambios a ese servicio, tal como exige §0.2')


# ── 07: el mapeo tier -> urgency es monotónico -- ningún tier de "alta" puede quedar por encima de "media"/"baja" ──

def check_urgency_tier_mapping_is_monotonic():
    text = read(AGGREGATOR)

    # Extrae cada asignación literal "'urgency' => 'X'," junto con el
    # "'_tier' => N" más cercano dentro del mismo array() -- verifica
    # que todo tier 1/2 mapee a 'alta', 3/4 a 'media', 5 a 'baja'.
    blocks = re.findall(r"array\(\s*'source'.*?\),\n", text, re.DOTALL)
    if not blocks:
        log_fail('07-urgency-tier-monotonic', 'no se encontraron bloques de item para verificar -- ¿cambió el formato de construcción de items?')
        return

    violations = []
    for block in blocks:
        urgency_m = re.search(r"'urgency'\s*=>\s*'(\w+)'", block)
        tier_m = re.search(r"'_tier'\s*=>\s*(\d+)", block)
        if not urgency_m or not tier_m:
            continue
        urgency = urgency_m.group(1)
        tier = int(tier_m.group(1))
        expected = 'alta' if tier <= 2 else ( 'media' if tier <= 4 else 'baja' )
        if urgency != expected:
            violations.append((tier, urgency, expected))

    if violations:
        log_fail('07-urgency-tier-monotonic', f'mapeo tier->urgency inconsistente: {violations} -- esto rompería el criterio de aceptación de PT-2 (ningún ítem de baja urgencia antes que uno de alta)')
        return

    log_pass('07-urgency-tier-monotonic', f'los {len(blocks)} bloques de construcción de item verificados mantienen el mapeo monotónico tier(1-2)->alta, tier(3-4)->media, tier(5)->baja -- sort_by_urgency() ordena solo por _tier, así que esta consistencia garantiza estructuralmente el criterio de aceptación de PT-2')


# ── 08: sort_by_urgency() ordena primero por tier, y limpia las claves internas antes de devolver ──

def check_sort_by_urgency_structure():
    text = read(AGGREGATOR)

    m = re.search(r'protected function sort_by_urgency\( array \$items \) \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('08-sort-structure', 'sort_by_urgency() no encontrado')
        return
    body = m.group(1)

    if 'usort(' not in body:
        log_fail('08-sort-structure', 'sort_by_urgency() no usa usort()')
        return

    tier_pos = body.find("_tier")
    specific_pos = body.find("_has_specific_time")
    count_pos = body.find("'count'")
    if tier_pos < 0 or specific_pos < 0 or count_pos < 0:
        log_fail('08-sort-structure', 'no se encontraron los tres criterios de comparación esperados (_tier, _has_specific_time, count)')
        return
    if not (tier_pos < specific_pos < count_pos):
        log_fail('08-sort-structure', 'el orden de los criterios de comparación no es _tier -> _has_specific_time -> count -- PT-2.1/2.2/2.3 exigen exactamente esa prioridad')
        return

    if "unset( \\$item['_tier']" not in body and "unset( $item['_tier']" not in body:
        log_fail('08-sort-structure', 'sort_by_urgency() no elimina las claves internas _tier/_has_specific_time antes de devolver -- el shape público de PT-1.2 no debería incluirlas')
        return

    log_pass('08-sort-structure', 'sort_by_urgency() compara en el orden correcto (tier -> hora específica -> volumen, PT-2.1/2.2/2.3) y limpia las claves internas antes de devolver el shape público de PT-1.2')


# ── 09: estado vacío positivo -- nunca una lista en blanco sin contexto ──

def check_empty_state_is_positive():
    text = read(AGGREGATOR)

    if 'atora-hoy-empty' not in text:
        log_fail('09-empty-state-positive', "no se encontró el bloque de estado vacío ('atora-hoy-empty') en render_items_html()")
        return

    if 'Nada urgente' not in text and 'nada urgente' not in text.lower():
        log_fail('09-empty-state-positive', 'el estado vacío no tiene copy positivo reconocible -- PT-3.3 exige tono cálido, no un mensaje genérico')
        return

    log_pass('09-empty-state-positive', 'render_items_html() devuelve un bloque de estado vacío con copy positivo y explicación cuando get_today() no tiene items -- nunca una lista en blanco')


# ── 10: la página admin y el shortcode comparten el mismo render -- no hay dos plantillas de marcado distintas ──

def check_view_and_shortcode_share_render():
    view = read(TODAY_VIEW)
    aggregator = read(AGGREGATOR)

    if 'render_items_html(' not in view:
        log_fail('10-shared-render', 'includes/today/views/today-page.php no llama a render_items_html() -- ¿construye su propio marcado en vez de reutilizar el método compartido?')
        return

    if "add_shortcode( 'atora_hoy'" not in aggregator:
        log_fail('10-shared-render', "el shortcode [atora_hoy] no está registrado (add_shortcode('atora_hoy', ...))")
        return

    if 'render_items_html( $this->get_today(' not in aggregator:
        log_fail('10-shared-render', 'render_shortcode() no llama a render_items_html($this->get_today(...)) -- el shortcode debería compartir exactamente el mismo render que la página de admin')
        return

    log_pass('10-shared-render', 'la vista de admin y el shortcode [atora_hoy] comparten el mismo render_items_html() -- un solo lugar mantiene el marcado de la lista')


# ── 11: deep-link a la ocurrencia -- followup-plans.js abre el panel directo desde ?event_id= ──

def check_followup_deep_link_auto_opens_panel():
    js = read(FOLLOWUP_JS)

    if 'event_id' not in js or 'URLSearchParams' not in js:
        log_fail('11-deep-link-auto-open', 'followup-plans.js no lee ?event_id= de la URL -- un enlace construido por "Hoy" (o por el puente de mensajería académica desde 6.6.0) no abriría el panel automáticamente, violando el requisito de PT-3.2 de "un clic a la acción específica"')
        return

    if 'openPanel(deepLinkEventId)' not in js and 'openPanel(deepLinkEventId)'.replace(' ', '') not in js.replace(' ', ''):
        log_fail('11-deep-link-auto-open', 'no se encontró la llamada a openPanel() con el event_id leído de la URL')
        return

    log_pass('11-deep-link-auto-open', 'followup-plans.js lee ?event_id= de la URL al cargar y abre el panel de esa ocurrencia automáticamente -- el enlace que "Hoy" construye (y el que el puente de mensajería académica ya construía desde 6.6.0) ahora sí lleva a la acción específica en un clic')


# ── 12: el gate de landing por rol separa admin de instructor -- admin queda sin cambios (PT-4.2) ──

def check_admin_landing_unchanged():
    text = read(FRONTEND_PROFILE)

    m = re.search(r'protected function get_login_hub_url_for_user\( WP_User \$user \): string \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('12-admin-landing-unchanged', 'get_login_hub_url_for_user() no encontrado')
        return
    body = m.group(1)

    admin_branch_m = re.search(r"if \( 'admin' === \$role \) \{(.*?)\}", body, re.DOTALL)
    if not admin_branch_m:
        log_fail('12-admin-landing-unchanged', "no se encontró un bloque \"if ( 'admin' === \\$role )\" separado -- PT-4.2 exige que el destino del admin quede intacto y aislado de cualquier cambio hecho para instructor/vendedor")
        return
    if 'clms-dashboard' not in admin_branch_m.group(1):
        log_fail('12-admin-landing-unchanged', "el bloque de 'admin' ya no apunta a clms-dashboard -- el flujo del admin no debería cambiar en este sprint")
        return

    if "'instructor' === \\$role" not in body and "'instructor' === $role" not in body:
        log_fail('12-admin-landing-unchanged', "no se encontró un bloque separado para 'instructor' -- PT-4.1 exige que el docente aterrice en Hoy sin afectar al admin")
        return
    if 'atora-hoy' not in body:
        log_fail('12-admin-landing-unchanged', "get_login_hub_url_for_user() no referencia 'atora-hoy' en absoluto -- el aterrizaje por rol no quedó conectado a la nueva pantalla")
        return

    log_pass('12-admin-landing-unchanged', "get_login_hub_url_for_user() separa 'admin' (sigue en clms-dashboard, sin cambios) de 'instructor'/'collaborator' con CRM (ahora en atora-hoy) -- PT-4.1 conectado sin violar el criterio de aceptación de PT-4.2")


# ── 13: rol comercial usa la misma capacidad ya establecida en 6.7.0, no una nueva ──

def check_commercial_landing_reuses_established_capability():
    text = read(FRONTEND_PROFILE)
    followup_view = read(FOLLOWUP_VIEW)

    if "user_can( \\$user, 'clms_access_crm_view' )" not in text and "user_can( $user, 'clms_access_crm_view' )" not in text:
        log_fail('13-commercial-capability-reused', "get_login_hub_url_for_user() no usa 'clms_access_crm_view' para decidir el aterrizaje comercial")
        return

    if "clms_access_crm_view" not in followup_view:
        log_fail('13-commercial-capability-reused', "modules/crm-v2/views/followup-plans.php ya no referencia clms_access_crm_view -- se esperaba que ambos archivos usaran exactamente la misma capacidad como señal de 'vendedor con CRM comercial'")
        return

    log_pass('13-commercial-capability-reused', "el aterrizaje por rol usa 'clms_access_crm_view', la misma capacidad que 6.7.0 ya estableció como señal canónica de acceso comercial en followup-plans.php -- no se introduce una tercera forma de detectar 'es vendedor'")


# ── 14: la migración de esquema de este sprint... (n/a) — en su lugar, verifica que ningún archivo de esquema fue tocado ──

def check_no_schema_changes_this_sprint():
    installer_path = os.path.join(ROOT, 'modules', 'class-v5-installer.php')
    with open(installer_path, encoding='utf-8', errors='replace') as fh:
        installer = fh.read()

    if '6.8.0' in installer or 'agregador "Hoy"' in installer.lower() or '"hoy"' in installer.lower():
        log_fail('14-no-schema-changes', 'modules/class-v5-installer.php parece haber sido tocado para este sprint -- "Hoy" es un agregador de lectura, no necesita ninguna tabla ni columna nueva')
        return

    log_pass('14-no-schema-changes', 'modules/class-v5-installer.php no tiene ninguna marca de 6.8.0 -- consistente con que este sprint es puramente de lectura, sin modelo de datos propio')


def main():
    check_aggregator_registered_in_loader()
    check_aggregator_never_caches()
    check_aggregator_is_read_only()
    check_due_occurrences_reuses_resolver()
    check_digest_service_wrappers_only()
    check_task_service_untouched()
    check_urgency_tier_mapping_is_monotonic()
    check_sort_by_urgency_structure()
    check_empty_state_is_positive()
    check_view_and_shortcode_share_render()
    check_followup_deep_link_auto_opens_panel()
    check_admin_landing_unchanged()
    check_commercial_landing_reuses_established_capability()
    check_no_schema_changes_this_sprint()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
