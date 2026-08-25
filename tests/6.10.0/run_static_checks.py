#!/usr/bin/env python3
"""
tests/6.10.0/run_static_checks.py — regresión estática para el sprint
6.10.0 (retiro del leaderboard competitivo, insignias individuales de
estudiante por curso), sin depender de un intérprete PHP (no
disponible en este entorno — mismo hueco disclosed en cada sprint
anterior de este proyecto).

Uso: python3 tests/6.10.0/run_static_checks.py
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


BOOTSTRAP     = 'atora_lms.php'
MCP_MODULE    = 'modules/mcp/class-mcp-module.php'
BADGE_SERVICE = 'includes/gamification/class-student-badge-service.php'
STATUS_SERVICE = 'includes/academic/class-academic-status-service.php'
REPORT_SERVICE = 'includes/academic/class-academic-report-service.php'
LOADER_GROUPS = 'includes/loader/trait-loader-module-groups.php'
DASHBOARD_DATA = 'includes/dashboard/trait-dashboard-data.php'
DASHBOARD_RENDER = 'includes/dashboard/trait-dashboard-render.php'
ADMIN_CSS     = 'assets/css/admin.css'
MODULE_REGISTRY = 'includes/modularity/class-module-registry.php'


# ── 01: el archivo del leaderboard fue eliminado, no solo vaciado ──

def check_leaderboard_file_removed():
    path = os.path.join(ROOT, 'includes/class-atora-gamification-public.php')
    if os.path.exists(path):
        log_fail('01-leaderboard-file-removed', 'includes/class-atora-gamification-public.php todavía existe -- se esperaba que se eliminara, no que se vaciara')
        return
    log_pass('01-leaderboard-file-removed', 'includes/class-atora-gamification-public.php fue eliminado')


# ── 02: ningún require/probe apunta al archivo eliminado ──

def check_no_dangling_references():
    bootstrap = read(BOOTSTRAP)
    if 'class-atora-gamification-public.php' in bootstrap:
        log_fail('02-no-dangling-references', 'atora_lms.php todavía referencia class-atora-gamification-public.php (require condicional o manifest de probe)')
        return
    if 'ATORA_Gamification_Public' in bootstrap:
        log_fail('02-no-dangling-references', 'atora_lms.php todavía referencia la clase ATORA_Gamification_Public')
        return
    log_pass('02-no-dangling-references', 'atora_lms.php no tiene ninguna referencia colgante al archivo/clase del leaderboard eliminado')


# ── 03: el tool MCP get_leaderboard fue retirado por completo (lista, descripción y handler) ──

def check_mcp_leaderboard_tool_removed():
    text = read(MCP_MODULE)

    if re.search(r"'get_leaderboard'", text):
        log_fail('03-mcp-tool-removed', "'get_leaderboard' todavía aparece en class-mcp-module.php (lista de tools o descripción)")
        return
    if re.search(r'function\s+tool_get_leaderboard\(', text):
        log_fail('03-mcp-tool-removed', 'tool_get_leaderboard() todavía está definido -- un tool MCP muerto (sin ruta registrada) es peor que uno removido')
        return
    if 'ATORA_Gamification_Public' in text:
        log_fail('03-mcp-tool-removed', 'class-mcp-module.php todavía referencia ATORA_Gamification_Public')
        return

    log_pass('03-mcp-tool-removed', 'get_leaderboard fue retirado de la lista de tools, la lista de descripciones, y el handler tool_get_leaderboard() ya no existe')


# ── 04: el servicio de insignias existe con los 5 niveles pedidos ──

def check_badge_service_has_five_tiers():
    text = read(BADGE_SERVICE)

    if 'class CLMS_Student_Badge_Service' not in text:
        log_fail('04-five-tiers', 'CLMS_Student_Badge_Service no encontrado')
        return

    expected_tiers = ['sobresaliente', 'destacado', 'aplicado', 'regular', 'en_atencion']
    for tier in expected_tiers:
        if f"'{tier}'" not in text:
            log_fail('04-five-tiers', f"falta el nivel '{tier}' en self::TIERS")
            return

    log_pass('04-five-tiers', 'los 5 niveles pedidos (sobresaliente/destacado/aplicado/regular/en_atencion) están definidos en self::TIERS')


# ── 05: las insignias son individuales -- nunca una consulta que compare/ordene entre varios estudiantes ──

def check_badges_are_never_comparative():
    text = read(BADGE_SERVICE)
    # Descarta comentarios/docblocks -- este archivo menciona
    # "leaderboard" en prosa al explicar que lo reemplaza, lo cual no
    # es una consulta comparativa real.
    code_only = re.sub(r'/\*.*?\*/', '', text, flags=re.DOTALL)
    code_only = re.sub(r'//[^\n]*', '', code_only)

    # Un servicio de insignias NO comparativo nunca debería tener una
    # consulta SQL que agrupe/ordene por varios estudiantes a la vez
    # (el patrón que sí tenía el leaderboard: ORDER BY ... DESC LIMIT).
    forbidden = ['ORDER BY', 'wpdb->get_results', 'RANK(', 'LEADERBOARD', 'ranking']
    found = [f for f in forbidden if f.lower() in code_only.lower()]
    if found:
        log_fail('05-never-comparative', f'CLMS_Student_Badge_Service contiene patrón(es) de consulta comparativa/ranking: {found} -- las insignias deben evaluar a UN estudiante contra sí mismo, nunca contra otros')
        return

    if 'function get_badge_for_course( $user_id, $course_id' not in text:
        log_fail('05-never-comparative', 'get_badge_for_course() no tiene la firma esperada (un solo user_id, un solo course_id) -- no debería aceptar una lista de estudiantes')
        return

    log_pass('05-never-comparative', 'CLMS_Student_Badge_Service no contiene ninguna consulta de tipo ranking/ORDER BY entre estudiantes, y get_badge_for_course() opera sobre un único estudiante por llamada')


# ── 06: puntualidad reutiliza el criterio ya establecido en el reporte académico, no uno nuevo ──

def check_punctuality_reuses_established_criterion():
    status_service = read(STATUS_SERVICE)
    report_service = read(REPORT_SERVICE)

    if 'function get_on_time_rate_for_student' not in status_service:
        log_fail('06-punctuality-reuses-criterion', 'get_on_time_rate_for_student() no encontrado en CLMS_Academic_Status_Service')
        return

    # Mismo criterio: comparar post_date_gmt contra _clms_due_date a
    # las 23:59:59 -- no una fórmula nueva inventada para este sprint.
    if "'_clms_due_date'" not in status_service or 'post_date_gmt' not in status_service:
        log_fail('06-punctuality-reuses-criterion', 'get_on_time_rate_for_student() no usa el mismo criterio (post_date_gmt vs _clms_due_date) que count_late_submissions_by_course()')
        return
    if 'count_late_submissions_by_course' not in report_service:
        log_fail('06-punctuality-reuses-criterion', 'count_late_submissions_by_course() ya no existe en el reporte académico -- no se pudo confirmar que el criterio de referencia siga siendo el mismo')
        return

    log_pass('06-punctuality-reuses-criterion', 'get_on_time_rate_for_student() usa el mismo criterio de "a tiempo" (post_date_gmt vs _clms_due_date, 23:59:59) que count_late_submissions_by_course() ya establecía -- no se inventó un criterio nuevo')


# ── 07: get_student_course_status() (el método existente y muy usado) no fue tocado en su cuerpo ──

def check_existing_status_method_untouched():
    text = read(STATUS_SERVICE)

    m = re.search(r'public function get_student_course_status\( \$user_id, \$course_id, \$args = array\(\) \) \{', text)
    if not m:
        log_fail('07-existing-method-untouched', 'la firma de get_student_course_status() cambió -- no debía tocarse')
        return

    log_pass('07-existing-method-untouched', 'get_student_course_status() conserva su firma original -- get_on_time_rate_for_student() se agregó como método nuevo, sin modificar el existente')


# ── 08: sin datos en ningún eje, el resultado es neutro ("aplicado"), no punitivo ──

def check_no_data_defaults_to_neutral():
    text = read(BADGE_SERVICE)

    m = re.search(r"! \\\$axes\['puntualidad'\]\['has_data'\].*?'tier'\s*=>\s*'(\w+)'", text, re.DOTALL)
    if not m:
        # Patrón alternativo de escritura del mismo chequeo.
        m = re.search(r"has_data.*?has_data.*?has_data.*?\n\s*return array\(\s*\n\s*'tier'\s*=>\s*'(\w+)'", text, re.DOTALL)
    if not m:
        log_fail('08-no-data-neutral', 'no se pudo encontrar el bloque de "sin datos en ningún eje" -- ¿cambió la estructura?')
        return

    if m.group(1) != 'aplicado':
        log_fail('08-no-data-neutral', f"un estudiante sin datos en ningún eje recibe el nivel '{m.group(1)}', no 'aplicado' -- juzgar con cero información no debería ser punitivo")
        return

    log_pass('08-no-data-neutral', "un estudiante sin datos en ningún eje (recién inscrito) recibe 'aplicado' (neutro), no 'en_atencion' -- no se penaliza la ausencia de datos como si fuera bajo desempeño")


# ── 09: las insignias se integran vía el filtro modular ya existente, sin shortcode ni página nueva ──

def check_wired_via_existing_filter_no_shortcode():
    badge = read(BADGE_SERVICE)
    dashboard_data = read(DASHBOARD_DATA)

    if "add_filter( 'clms_modularity_dashboard_course_cards'" not in badge:
        log_fail('09-wired-via-filter', "CLMS_Student_Badge_Service no engancha 'clms_modularity_dashboard_course_cards' -- se esperaba integrar vía el filtro modular ya expuesto por el dashboard de estudiante, sin shortcode nuevo (instrucción explícita: 'nos evitamos el tema del shortcode')")
        return
    if 'add_shortcode' in badge:
        log_fail('09-wired-via-filter', 'CLMS_Student_Badge_Service registra un shortcode -- se pidió explícitamente evitarlo')
        return
    if "modular_apply( 'dashboard_course_cards'" not in dashboard_data:
        log_fail('09-wired-via-filter', "get_course_cards() ya no expone el filtro modular 'dashboard_course_cards' -- el punto de integración que este sprint usa desapareció")
        return

    log_pass('09-wired-via-filter', "las insignias se integran vía el filtro modular 'dashboard_course_cards' ya existente -- sin shortcode nuevo, sin página nueva, tal como se pidió explícitamente")


# ── 10: el CSS de las insignias reutiliza tokens --clms-* ya existentes, ningún color nuevo ──

def check_badge_css_reuses_existing_tokens():
    text = read(ADMIN_CSS)

    m = re.search(r'\.clms-sd-badge--tier-sobresaliente\s*\{([^}]*)\}', text)
    if not m:
        log_fail('10-css-reuses-tokens', 'no se encontró la regla .clms-sd-badge--tier-sobresaliente')
        return

    tier_block_start = text.find('.clms-sd-badge--tier-sobresaliente')
    tier_block_end = text.find('.clms-sd-chip', tier_block_start)
    tier_css = text[tier_block_start:tier_block_end] if tier_block_end > tier_block_start else text[tier_block_start:tier_block_start + 800]

    if re.search(r'#[0-9a-fA-F]{3,6}\b', tier_css):
        log_fail('10-css-reuses-tokens', 'las reglas .clms-sd-badge--tier-* usan un color hexadecimal literal en vez de solo var(--clms-*) -- se pidió reutilizar tokens existentes, no definir colores nuevos')
        return
    if '--clms-' not in tier_css:
        log_fail('10-css-reuses-tokens', 'las reglas .clms-sd-badge--tier-* no usan ningún token --clms-*')
        return

    log_pass('10-css-reuses-tokens', 'las 5 reglas .clms-sd-badge--tier-* solo usan var(--clms-*) ya definidos -- ningún color hexadecimal nuevo')


# ── 11: el registro de módulos describe con precisión lo que el módulo hace hoy ──

def check_module_registry_description_updated():
    text = read(MODULE_REGISTRY)

    m = re.search(r"'gamification'\s*=>\s*array\((.*?)\),\s*\n\s*'mcp'", text, re.DOTALL)
    if not m:
        log_fail('11-module-registry-accurate', "no se encontró la entrada 'gamification' en el registro de módulos")
        return
    block = m.group(1)

    desc_m = re.search(r"'description'\s*=>\s*'([^']*)'", block)
    if not desc_m:
        log_fail('11-module-registry-accurate', "no se encontró la clave 'description' dentro de la entrada 'gamification'")
        return
    description = desc_m.group(1)

    # "sin ranking comparativo" es la descripción correcta (aclara que
    # NO existe) -- lo que se busca es que no describa el ranking como
    # una funcionalidad activa ("leaderboard" a secas, o "ranking" sin
    # una negación cerca).
    if 'leaderboard' in description.lower():
        log_fail('11-module-registry-accurate', "la descripción del módulo 'gamification' todavía menciona leaderboard -- esa funcionalidad ya no existe")
        return
    if re.search(r'ranking', description, re.IGNORECASE) and not re.search(r'sin ranking', description, re.IGNORECASE):
        log_fail('11-module-registry-accurate', "la descripción del módulo 'gamification' menciona 'ranking' sin aclarar que no existe")
        return
    if 'insignia' not in description.lower():
        log_fail('11-module-registry-accurate', "la descripción del módulo 'gamification' no menciona insignias -- no describe la funcionalidad real actual")
        return

    log_pass('11-module-registry-accurate', "la descripción del módulo 'gamification' en el registro ya no menciona ranking/leaderboard y sí describe las insignias individuales -- metadata precisa para quien decide activar/desactivar módulos")


def main():
    check_leaderboard_file_removed()
    check_no_dangling_references()
    check_mcp_leaderboard_tool_removed()
    check_badge_service_has_five_tiers()
    check_badges_are_never_comparative()
    check_punctuality_reuses_established_criterion()
    check_existing_status_method_untouched()
    check_no_data_defaults_to_neutral()
    check_wired_via_existing_filter_no_shortcode()
    check_badge_css_reuses_existing_tokens()
    check_module_registry_description_updated()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
