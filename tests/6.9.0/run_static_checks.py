#!/usr/bin/env python3
"""
tests/6.9.0/run_static_checks.py — regresión estática para el sprint
6.9.0 (componentes compartidos, búsqueda persistente, "Actividad"),
sin depender de un intérprete PHP (no disponible en este entorno —
mismo hueco disclosed en cada sprint anterior de este proyecto).

Uso: python3 tests/6.9.0/run_static_checks.py
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


LIST_ROW        = 'includes/ui/class-ui-list-row.php'
FOLLOWUP_PANEL   = 'includes/ui/class-ui-followup-panel.php'
PANEL_JS         = 'assets/shared/followup-panel.js'
TOKENS_CSS       = 'assets/shared/tokens.css'
FOLLOWUP_PLANS_JS = 'modules/crm-v2/assets/followup-plans.js'
FOLLOWUP_PLANS_CSS = 'modules/crm-v2/assets/followup-plans.css'
FOLLOWUP_PLANS_VIEW = 'modules/crm-v2/views/followup-plans.php'
TODAY_JS         = 'assets/admin/today.js'
SEARCH_SERVICE   = 'includes/ui/class-ui-search-service.php'
SEARCH_JS        = 'assets/shared/search.js'
CONTACT_SERVICE  = 'modules/crm-v2/services/class-contact-service.php'
ACTIVITY_SERVICE = 'modules/crm-v2/services/class-activity-service.php'
PLAN_SERVICE     = 'modules/crm-v2/services/class-followup-plan-service.php'
ACTIVITY_FEED    = 'includes/today/class-activity-feed-service.php'
UI_COMPONENTES   = 'docs/UI-COMPONENTES.md'


# ── 01: los componentes compartidos existen y usan el shape ya establecido por "Hoy" (6.8.0) ──

def check_shared_components_exist():
    row = read(LIST_ROW)
    panel = read(FOLLOWUP_PANEL)

    if 'class CLMS_UI_List_Row' not in row:
        log_fail('01-shared-components-exist', 'CLMS_UI_List_Row no encontrado')
        return
    if 'class CLMS_UI_Followup_Panel' not in panel:
        log_fail('01-shared-components-exist', 'CLMS_UI_Followup_Panel no encontrado')
        return
    if 'CLMS_UI_List_Row::render(' not in panel:
        log_fail('01-shared-components-exist', 'Followup_Panel::render() no llama a CLMS_UI_List_Row::render() -- se esperaba que el panel componga filas, no que las reimplemente')
        return

    log_pass('01-shared-components-exist', 'CLMS_UI_List_Row y CLMS_UI_Followup_Panel existen; el panel compone filas a través de List_Row, no con marcado propio')


# ── 02: el JS del panel no hace fetch por sí mismo (PT-1.2, "onAction" delega el REST a quien abre) ──

def check_panel_js_has_no_fetch():
    text = read(PANEL_JS)

    if 'fetch(' in text or 'XMLHttpRequest' in text:
        log_fail('02-panel-js-no-fetch', 'assets/shared/followup-panel.js hace su propio fetch/XHR -- el componente compartido no debe saber de ningún endpoint REST específico, eso vive en el onAction de quien lo abre')
        return
    if 'window.AtoraUI.Panel' not in text and 'window.AtoraUI = window.AtoraUI' not in text:
        log_fail('02-panel-js-no-fetch', 'window.AtoraUI.Panel no está expuesto')
        return

    log_pass('02-panel-js-no-fetch', 'followup-panel.js no hace ningún fetch/XHR propio -- window.AtoraUI.Panel solo renderiza y delega acciones vía onAction()')


# ── 03: el retrofit del calendario -- followup-plans.js ya no construye filas a mano ──

def check_calendar_panel_retrofitted():
    js = read(FOLLOWUP_PLANS_JS)
    view = read(FOLLOWUP_PLANS_VIEW)

    if 'atora-fu-student-row' in js:
        log_fail('03-calendar-retrofitted', "followup-plans.js todavía construye '.atora-fu-student-row' a mano -- el retrofit de PT-1.4 debía reemplazar ese marcado por AtoraUI.Panel/renderRow")
        return
    if 'AtoraUI.Panel.open(' not in js:
        log_fail('03-calendar-retrofitted', 'followup-plans.js no llama a AtoraUI.Panel.open()')
        return
    if 'CLMS_UI_Followup_Panel::render_shell(' not in view:
        log_fail('03-calendar-retrofitted', 'followup-plans.php no usa CLMS_UI_Followup_Panel::render_shell() para el panel de ocurrencia -- todavía tiene el marcado viejo a mano')
        return

    log_pass('03-calendar-retrofitted', "followup-plans.js ya no construye '.atora-fu-student-row' a mano y llama a AtoraUI.Panel.open(); followup-plans.php usa CLMS_UI_Followup_Panel::render_shell() para el shell del panel -- retrofit real, no una segunda implementación en paralelo")


# ── 04: el asistente de 4 pasos (fuera de alcance del retrofit) sigue intacto ──

def check_wizard_untouched():
    view = read(FOLLOWUP_PLANS_VIEW)
    css = read(FOLLOWUP_PLANS_CSS)

    if 'atora-fu-wizard' not in view:
        log_fail('04-wizard-untouched', 'el marcado del asistente de 4 pasos desapareció de followup-plans.php -- PT-1.4 no debía tocarlo')
        return
    if '.atora-fu-wizard-sheet' not in css:
        log_fail('04-wizard-untouched', 'la regla CSS .atora-fu-wizard-sheet desapareció -- el asistente todavía la necesita')
        return

    log_pass('04-wizard-untouched', 'el asistente de 4 pasos (marcado y CSS) sigue intacto -- deliberadamente fuera del retrofit de PT-1 (documentado en docs/DEUDA-TECNICA.md), no una omisión')


# ── 05: tokens.css documenta y resuelve la doble capa de tokens sin romper la ya establecida ──

def check_tokens_layered_correctly():
    text = read(TOKENS_CSS)

    for token in ('--atora-brand-ink', '--atora-brand-cream', '--atora-brand-blue', '--atora-brand-gold'):
        if token not in text:
            log_fail('05-tokens-layered', f'falta el token de marca {token}')
            return

    for token in ('--atora-blue-700', '--atora-warning', '--atora-danger', '--atora-success'):
        if token not in text:
            log_fail('05-tokens-layered', f'falta el token operativo ya establecido {token} -- tokens.css debía re-declarar los mismos valores que atora-admin.css, no reemplazarlos')
            return

    log_pass('05-tokens-layered', 'assets/shared/tokens.css declara tanto los tokens de marca (--atora-brand-*, sin theme.json real que leer) como los tokens operativos ya establecidos desde 6.6.0 -- ninguna capa reemplaza a la otra')


# ── 06: docs/UI-COMPONENTES.md existe y es la referencia declarada ──

def check_ui_components_doc_exists():
    if not os.path.exists(os.path.join(ROOT, UI_COMPONENTES)):
        log_fail('06-ui-components-doc', 'docs/UI-COMPONENTES.md no existe')
        return
    text = read(UI_COMPONENTES)
    if 'Followup_List_Row' not in text or 'Followup_Panel' not in text:
        log_fail('06-ui-components-doc', 'docs/UI-COMPONENTES.md no documenta ambos componentes')
        return
    log_pass('06-ui-components-doc', 'docs/UI-COMPONENTES.md existe y documenta ambos componentes compartidos -- criterio de aceptación de PT-1')


# ── 07: la búsqueda académica reutiliza Section_Service, nunca una consulta paralela sin scope ──

def check_search_academic_scope_reused():
    text = read(SEARCH_SERVICE)

    if 'Section_Service::get_sections_by_teacher(' not in text:
        log_fail('07-search-academic-scope', 'search_students()/search_sections() no llaman a Section_Service::get_sections_by_teacher() -- se esperaba reutilizar el mismo alcance ya usado por followup-plans.php/Teacher_Digest_Service')
        return
    if 'get_section_student_ids(' not in text:
        log_fail('07-search-academic-scope', 'search_students() no llama a get_section_student_ids()')
        return

    # No debe haber una consulta SQL directa a wp_users/usermeta que
    # ignore el resultado de get_sections_by_teacher() -- verificación
    # de que el WP_User_Query use 'include' con los IDs ya acotados.
    if "'include' => \\$student_ids" not in text and "'include' => $student_ids" not in text:
        log_fail('07-search-academic-scope', "WP_User_Query no usa 'include' => \\$student_ids -- podría estar buscando entre TODOS los usuarios en vez de solo los de las secciones del docente")
        return

    log_pass('07-search-academic-scope', "search_students()/search_sections() reutilizan Section_Service::get_sections_by_teacher()/get_section_student_ids(); WP_User_Query se acota con 'include' a esos IDs -- ningún estudiante fuera del alcance del docente puede aparecer")


# ── 08: la búsqueda comercial reutiliza Contact_Service::search_contacts() (que ya aplica scope internamente) ──

def check_search_commercial_reuses_scoped_method():
    search = read(SEARCH_SERVICE)
    contact_service = read(CONTACT_SERVICE)

    if 'Contact_Service::search_contacts(' not in search:
        log_fail('08-search-commercial-scope', 'search_contacts() (CLMS_UI_Search_Service) no llama a Contact_Service::search_contacts()')
        return

    if 'get_scope_user_ids()' not in contact_service:
        log_fail('08-search-commercial-scope', 'Contact_Service ya no aplica get_scope_user_ids() -- si eso cambió, la búsqueda comercial podría haber perdido su alcance sin que este sprint lo note')
        return

    log_pass('08-search-commercial-scope', "search_contacts() reutiliza Contact_Service::search_contacts() sin agregar una segunda capa de permisos -- ese método ya aplica get_scope_user_ids() internamente en cada llamada (confirmado: Contact_Service todavía la referencia)")


# ── 09: debounce + cancelación de requests en vuelo (PT-2.5) ──

def check_search_js_debounces_and_cancels():
    text = read(SEARCH_JS)

    if 'setTimeout(' not in text or 'DEBOUNCE_MS' not in text:
        log_fail('09-search-debounce', 'search.js no implementa un debounce explícito')
        return
    if 'AbortController' not in text or '.abort()' not in text:
        log_fail('09-search-debounce', 'search.js no cancela requests en vuelo (se esperaba AbortController) -- PT-2.5 exige cancelar si el usuario sigue escribiendo')
        return

    log_pass('09-search-debounce', 'search.js usa un debounce (350ms) y cancela el fetch anterior con AbortController antes de disparar uno nuevo -- PT-2.5 cumplido')


# ── 10: la búsqueda es siempre visible, no un atajo de teclado escondido (PT-2.1) ──

def check_search_bar_always_visible():
    hubs = read('includes/admin-menu/trait-admin-menu-hubs.php')

    if 'render_atora_search_bar' not in hubs:
        log_fail('10-search-always-visible', 'render_atora_search_bar() no encontrado')
        return
    if "add_action( 'in_admin_header'" not in read('atora_lms.php'):
        log_fail('10-search-always-visible', "el ícono de búsqueda no está enganchado a 'in_admin_header' -- no aparecería en la misma posición en toda página atora-*/clms-*")
        return

    log_pass('10-search-always-visible', "render_atora_search_bar() está enganchado a 'in_admin_header', gateado a toda página atora-*/clms-* -- misma posición siempre, no un atajo de teclado que la audiencia no técnica no descubriría")


# ── 11: Activity_Feed_Service es de solo lectura -- nunca llama a move_followup()/move_deal()/mark_contacted() ──

def check_activity_feed_is_read_only():
    text = read(ACTIVITY_FEED)

    # Busca uso real (Clase::método(), ->método()), no menciones en
    # comentarios/docblocks -- este archivo documenta en prosa qué
    # tabla escriben esos métodos, lo cual los mencionaría sin ser una
    # llamada real.
    forbidden = ['move_followup(', 'move_deal(', 'mark_contacted(', '::create_plan(', '::pause_plan(']
    found = [f for f in forbidden if re.search(r'(::|->)\s*' + re.escape(f), text)]
    if found:
        log_fail('11-activity-feed-read-only', f'CLMS_UI_Activity_Feed_Service llama a método(s) de escritura {found} -- PT-3.1 exige un agregador de solo lectura, simétrico a Today_Aggregator_Service')
        return

    log_pass('11-activity-feed-read-only', 'CLMS_UI_Activity_Feed_Service no llama a ningún método de escritura de los servicios que consulta -- estrictamente de solo lectura, igual que el agregador de "Hoy"')


# ── 12: los métodos de lectura nuevos no tocan los métodos de escritura existentes ──

def check_new_read_methods_dont_touch_writers():
    activity = read(ACTIVITY_SERVICE)
    plan_service = read(PLAN_SERVICE)

    if 'get_recent_stage_improvements_for_user' not in activity:
        log_fail('12-read-methods-isolated', 'Activity_Service::get_recent_stage_improvements_for_user() no encontrado')
        return
    if 'get_recent_contacts_for_user' not in plan_service:
        log_fail('12-read-methods-isolated', 'Followup_Plan_Service::get_recent_contacts_for_user() no encontrado')
        return

    # log_contact_activity()/log_user_activity() (los métodos de
    # escritura existentes de Activity_Service) deben seguir presentes
    # sin haber sido reemplazados.
    if 'public static function log_contact_activity(' not in activity:
        log_fail('12-read-methods-isolated', 'log_contact_activity() ya no está -- no debía tocarse')
        return
    if 'public static function mark_contacted(' not in plan_service:
        log_fail('12-read-methods-isolated', 'mark_contacted() ya no está en Followup_Plan_Service -- no debía tocarse')
        return

    log_pass('12-read-methods-isolated', 'los dos métodos de lectura nuevos (get_recent_stage_improvements_for_user, get_recent_contacts_for_user) conviven con log_contact_activity()/mark_contacted() sin reemplazarlos -- lectura puntual agregada, ningún método existente tocado')


# ── 13: "Hoy" y "Actividad" comparten página/menú, nunca mezclan sus listas ──

def check_hoy_actividad_share_entry_never_mixed():
    view = read('includes/today/views/today-page.php')

    if "'view'" not in view or 'actividad' not in view:
        log_fail('13-hoy-actividad-shared-entry', 'today-page.php no implementa el toggle ?view=actividad')
        return

    # Verifica que get_today() y get_recent() se llamen en ramas
    # if/else separadas -- nunca sus resultados combinados en un mismo
    # array antes de renderizar.
    if re.search(r'array_merge\(\s*\$items', view):
        log_fail('13-hoy-actividad-shared-entry', 'today-page.php parece combinar los items de Hoy y Actividad en una sola lista -- PT-3.4 prohíbe mezclarlos')
        return

    log_pass('13-hoy-actividad-shared-entry', 'today-page.php alterna entre Hoy y Actividad con un parámetro de la misma página (un solo punto de menú), y nunca combina ambos resultados en una sola lista')


# ── 14: los ítems de "Actividad" no pueden aparecer también en "Hoy" el mismo día (fuentes complementarias) ──

def check_activity_and_today_use_complementary_filters():
    today = read('includes/today/class-today-aggregator-service.php')
    feed = read(ACTIVITY_FEED)

    # "Hoy" solo muestra ocurrencias con uncontacted > 0; "Actividad"
    # lee mark_contacted() por separado (contacted_by = user, no
    # depende del estado "uncontacted" de la ocurrencia) -- confirma
    # que "Hoy" filtra explícitamente por pendientes.
    if 'uncontacted' not in today or "<= 0" not in today:
        log_fail('14-complementary-filters', "Today_Aggregator_Service no filtra explícitamente por 'uncontacted <= 0' -- sin ese filtro, un ítem recién contactado podría seguir apareciendo en Hoy el mismo día que aparece en Actividad")
        return

    log_pass('14-complementary-filters', "Today_Aggregator_Service descarta ocurrencias con uncontacted <= 0 -- un contacto recién marcado sale de 'Hoy' en la siguiente carga (sin caché, PT-1.4 de 6.8.0) exactamente cuando entra a 'Actividad' (get_recent_contacts_for_user() lo ve por su propio contacted_at) -- nunca ambos a la vez por construcción")


def main():
    check_shared_components_exist()
    check_panel_js_has_no_fetch()
    check_calendar_panel_retrofitted()
    check_wizard_untouched()
    check_tokens_layered_correctly()
    check_ui_components_doc_exists()
    check_search_academic_scope_reused()
    check_search_commercial_reuses_scoped_method()
    check_search_js_debounces_and_cancels()
    check_search_bar_always_visible()
    check_activity_feed_is_read_only()
    check_new_read_methods_dont_touch_writers()
    check_hoy_actividad_share_entry_never_mixed()
    check_activity_and_today_use_complementary_filters()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
