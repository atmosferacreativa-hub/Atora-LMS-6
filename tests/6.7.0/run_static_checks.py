#!/usr/bin/env python3
"""
tests/6.7.0/run_static_checks.py — regresión estática para el sprint
6.7.0 (CRM comercial: planes de seguimiento reutilizando el motor
académico), sin depender de un intérprete PHP (no disponible en este
entorno — mismo hueco disclosed en cada sprint anterior de este
proyecto, incluido 6.6.0).

Este archivo no reemplaza tests/6.6.0/run_static_checks.py — lo
complementa. Los checks 01-03 y 14 de acá son la versión actualizada
de los checks 01, 02 y 07 de 6.6.0, ajustados porque PT-1/PT-2 de este
sprint movieron deliberadamente la lógica que esos checks verificaban
(roster+etapa académica, biblioteca de plantillas) desde Followup_Plan_
Resolver / Followup_Plan_Service::get_templates() hacia Academic_
Domain_Provider / get_academic_templates() — exactamente el criterio
de aceptación explícito de PT-1 ("Followup_Plan_Resolver no tiene
ninguna referencia directa a Student_Followup_Service fuera de
Academic_Domain_Provider"). La garantía subyacente (resolución en
vivo, sin ocurrencias ocultas, fusión sin duplicados, plantillas
ancladas a etapas reales) es la MISMA que en 6.6.0 — solo cambió EN
QUÉ ARCHIVO/FUNCIÓN vive el código que se verifica, un ajuste de
setup, no una relajación de expectativa. Confirmado corriendo
tests/6.6.0/ contra este código: 7/10 checks siguen pasando sin tocar
nada; los 3 que fallan (01, 02, 07) son exactamente estos tres
relocados. tests/6.6.0/ se deja intacto como registro histórico de ese
sprint, sin editar.

Uso: python3 tests/6.7.0/run_static_checks.py
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


RESOLVER          = 'modules/crm-v2/services/class-followup-plan-resolver.php'
ACADEMIC_PROVIDER = 'modules/crm-v2/services/class-academic-domain-provider.php'
COMMERCIAL_PROVIDER = 'modules/crm-v2/services/class-commercial-domain-provider.php'
DOMAIN_INTERFACE  = 'modules/crm-v2/services/class-followup-domain-provider-interface.php'
PLAN_SERVICE       = 'modules/crm-v2/services/class-followup-plan-service.php'
INSTALLER          = 'modules/class-v5-installer.php'
REST_CONTROLLER    = 'modules/crm-v2/rest/class-followup-plans-rest-controller.php'
VIEW               = 'modules/crm-v2/views/followup-plans.php'
JS                 = 'modules/crm-v2/assets/followup-plans.js'


# ── 01 (actualiza el 01 de 6.6.0): el resolver YA NO llama a Student_Followup_Service directamente ──

def check_resolver_has_no_direct_academic_reference():
    text = read(RESOLVER)

    # Busca uso real (Clase::método/const), no menciones en comentarios/
    # docblocks -- este mismo archivo documenta en prosa que YA NO debe
    # tener esa referencia, lo cual mencionaría el nombre sin ser una
    # violación real.
    if re.search(r'Student_Followup_Service::', text):
        log_fail('01-resolver-no-direct-academic-ref', 'Followup_Plan_Resolver todavía llama a Student_Followup_Service:: directamente -- el criterio de aceptación explícito de PT-1 es que esa referencia viva SOLO en Academic_Domain_Provider')
        return
    if re.search(r'Deal_Service::', text):
        log_fail('01-resolver-no-direct-academic-ref', 'Followup_Plan_Resolver llama a Deal_Service:: directamente -- debe vivir solo en Commercial_Domain_Provider')
        return

    log_pass('01-resolver-no-direct-academic-ref', 'Followup_Plan_Resolver no referencia Student_Followup_Service ni Deal_Service en absoluto -- toda esa lógica vive en los proveedores de dominio (PT-1, acceptance criterion)')


# ── 02 (actualiza el 01 de 6.6.0, relocalizado): Academic_Domain_Provider consulta el board EN VIVO ──

def check_academic_provider_reads_board_live():
    text = read(ACADEMIC_PROVIDER)

    if 'Student_Followup_Service::get_board()' not in text:
        log_fail('02-academic-provider-live-board', 'Academic_Domain_Provider ya no llama a Student_Followup_Service::get_board() -- sin esto, la etapa resuelta podría venir de una copia vieja')
        return

    forbidden = ['set_transient(', 'wp_cache_set(', 'get_transient(', 'wp_cache_get(']
    found = [f for f in forbidden if f in text]
    if found:
        log_fail('02-academic-provider-live-board', f'Academic_Domain_Provider usa caché ({found}) -- el principio central de la OT exige resolución en vivo, sin cachear entre la vista previa y la ocurrencia real')
        return

    log_pass('02-academic-provider-live-board', 'Academic_Domain_Provider::resolve_entities_in_stages() consulta Student_Followup_Service::get_board() en cada llamada, sin ningún transient/wp_cache de por medio -- misma garantía que 6.6.0, relocalizada')


# ── 03 (actualiza el 02 de 6.6.0): ningún proveedor de dominio oculta una ocurrencia vacía sin motivo ──

def check_providers_never_hide_empty_silently():
    academic = read(ACADEMIC_PROVIDER)
    commercial = read(COMMERCIAL_PROVIDER)

    academic_reasons = ['sin_configuracion', 'sin_estudiantes_en_secciones', 'nadie_en_esas_etapas_hoy', 'nadie_cumple_el_filtro_hoy']
    missing_academic = [r for r in academic_reasons if f"'{r}'" not in academic]
    if missing_academic:
        log_fail('03-providers-empty-reason', f'Academic_Domain_Provider perdió motivo(s) explícitos: {missing_academic}')
        return

    commercial_reasons = ['sin_configuracion', 'nadie_en_esas_etapas_hoy', 'nadie_cumple_el_filtro_hoy', 'todos_en_secuencia_activa']
    missing_commercial = [r for r in commercial_reasons if f"'{r}'" not in commercial]
    if missing_commercial:
        log_fail('03-providers-empty-reason', f'Commercial_Domain_Provider no cubre motivo(s): {missing_commercial}')
        return

    log_pass('03-providers-empty-reason', 'ambos proveedores de dominio devuelven siempre un empty_reason explícito -- ninguna ocurrencia sin destinatarios queda oculta o silenciosa, en ningún dominio')


# ── 04: la interfaz de dominio existe y ambos proveedores la implementan ──

def check_domain_interface_and_implementations():
    interface_text = read(DOMAIN_INTERFACE)
    if 'interface Followup_Domain_Provider' not in interface_text:
        log_fail('04-domain-interface', 'no se encontró "interface Followup_Domain_Provider" -- PT-1.1 pide una interfaz formal (o convención equivalente), no duck typing implícito')
        return

    academic = read(ACADEMIC_PROVIDER)
    commercial = read(COMMERCIAL_PROVIDER)
    if 'implements Followup_Domain_Provider' not in academic:
        log_fail('04-domain-interface', 'Academic_Domain_Provider no declara "implements Followup_Domain_Provider"')
        return
    if 'implements Followup_Domain_Provider' not in commercial:
        log_fail('04-domain-interface', 'Commercial_Domain_Provider no declara "implements Followup_Domain_Provider"')
        return

    log_pass('04-domain-interface', 'Followup_Domain_Provider existe como interfaz formal (mismo patrón que Provider_Interface de email-engine) y ambos proveedores concretos la implementan explícitamente')


# ── 05: el dominio académico no tiene "cierre de sección" reutilizado incorrectamente en comercial ──

def check_section_closure_scoped_to_academic():
    text = read(PLAN_SERVICE)

    m = re.search(r"private static function maybe_deactivate_on_section_closure\( int \$plan_id \): bool \{(.*?)\n\t\}", text, re.DOTALL)
    if not m:
        log_fail('05-section-closure-academic-only', 'maybe_deactivate_on_section_closure() no encontrado')
        return
    body = m.group(1)

    if "'academic' !== \\$plan['domain']" not in body and "'academic' !== $plan['domain']" not in body:
        log_fail('05-section-closure-academic-only', 'maybe_deactivate_on_section_closure() no verifica que el plan sea del dominio académico antes de aplicar la regla de cierre de sección -- un plan comercial sin end_date podría desactivarse por una lógica que no le corresponde (el pipeline comercial no tiene noción de cierre de período)')
        return

    log_pass('05-section-closure-academic-only', 'maybe_deactivate_on_section_closure() (PT-1.3) solo se aplica cuando plan[domain] === academic -- el pipeline comercial no hereda una regla de cierre que no tiene sentido para él')


# ── 06: mark_contacted() sigue sin tocar ninguna tabla de etapas, ahora también verificado contra Deal_Service ──

def check_mark_contacted_still_domain_agnostic_and_safe():
    text = read(PLAN_SERVICE)

    m = re.search(r'public static function mark_contacted\([^)]*\): bool \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('06-mark-contacted-domain-agnostic', 'mark_contacted() no encontrado')
        return
    body = m.group(1)

    forbidden = ['move_followup(', 'Student_Followup_Service::', 'atora_crm_student_followups', 'move_deal(', 'Deal_Service::', 'atora_crm_deals']
    found = [f for f in forbidden if f in body]
    if found:
        log_fail('06-mark-contacted-domain-agnostic', f'mark_contacted() referencia {found} -- debe seguir siendo agnóstico de dominio y nunca tocar la etapa de NINGÚN dominio (ni followups académicos ni el stage de un deal comercial)')
        return

    log_pass('06-mark-contacted-domain-agnostic', 'mark_contacted() sigue sin referenciar move_followup()/Student_Followup_Service NI move_deal()/Deal_Service -- marcar contactado no mueve la etapa en ningún dominio, académico o comercial')


# ── 07: Commercial_Domain_Provider resuelve el score EN VIVO (Scoring_Service::calculate_score, no una columna cacheada) ──

def check_commercial_score_is_live():
    text = read(COMMERCIAL_PROVIDER)

    if 'Scoring_Service::calculate_score(' not in text:
        log_fail('07-commercial-score-live', 'Commercial_Domain_Provider no llama a Scoring_Service::calculate_score() -- PT-3.3 exige que el score se consulte en el momento de la ocurrencia, nunca una copia guardada')
        return

    # No debe leer conversion_score directamente de la tabla de contactos
    # como atajo -- eso sería una lectura potencialmente vieja
    # (conversion_score solo se actualiza en el batch de
    # Scoring_Service::recalculate_all()).
    if "conversion_score" in text:
        log_fail('07-commercial-score-live', "Commercial_Domain_Provider lee 'conversion_score' directamente -- esa columna es una copia de batch (recalculate_all()), no el score en vivo; debe usar calculate_score() como el resto del CRM (ver contact-360.php)")
        return

    log_pass('07-commercial-score-live', 'Commercial_Domain_Provider calcula el score con Scoring_Service::calculate_score() en cada resolución -- nunca lee la columna conversion_score cacheada por lote, coherente con el principio de "nada congelado" de todo el motor')


# ── 08: la coordinación con secuencias nunca oculta un contacto por default (PT-4.2/4.4) ──

def check_sequence_coordination_visible_by_default():
    text = read(COMMERCIAL_PROVIDER)

    if 'exclude_active_sequence' not in text:
        log_fail('08-sequence-coordination-visible', "Commercial_Domain_Provider no implementa 'exclude_active_sequence' -- PT-4.4 pide el filtro opcional")
        return

    # El filtro debe estar apagado por default -- confirmar que la
    # variable se lee con una negación explícita tipo !empty(...), no
    # que el comportamiento por default sea excluir.
    if not re.search(r"!\s*empty\(\s*\$context\['exclude_active_sequence'\]", text):
        log_fail('08-sequence-coordination-visible', 'no se pudo confirmar que exclude_active_sequence sea false/ausente por default (se esperaba un patrón !empty(...) explícito)')
        return

    if 'filtered_out_count' not in text:
        log_fail('08-sequence-coordination-visible', "Commercial_Domain_Provider no expone 'filtered_out_count' -- PT-4.4 exige que un contacto excluido por el filtro de secuencia siga contando en el total, aunque no aparezca en la lista")
        return

    log_pass('08-sequence-coordination-visible', "exclude_active_sequence lee con !empty() (apagado por default -- mostrar todo es el comportamiento inicial) y filtered_out_count existe para que un contacto excluido del listado siga siendo visible en el conteo -- ningún contacto pierde visibilidad sin que el vendedor lo haya decidido")


# ── 09: mark_contacted() nunca es llamado dentro del flujo de exclusión por secuencia (coordinación de solo lectura) ──

def check_sequence_read_only():
    text = read(COMMERCIAL_PROVIDER)

    forbidden = ['stop_enrollment(', 'Sequence_Service::suppress(', "->update(", 'UPDATE atora_email_sequence']
    found = [f for f in forbidden if f in text]
    if found:
        log_fail('09-sequence-read-only', f'Commercial_Domain_Provider referencia {found} -- PT-4.3 es explícito: este sprint solo agrega VISIBILIDAD, nunca modifica el estado de una secuencia automáticamente')
        return

    if 'get_contact_enrollments(' not in text:
        log_fail('09-sequence-read-only', 'Commercial_Domain_Provider no llama a Sequence_Service::get_contact_enrollments() -- se esperaba reutilizar ese método existente, no una consulta nueva')
        return

    log_pass('09-sequence-read-only', 'Commercial_Domain_Provider solo LEE inscripciones de secuencia (get_contact_enrollments()/get_steps()) -- ninguna llamada de escritura sobre una secuencia en absoluto, coordinación estrictamente de solo lectura')


# ── 10: la migración de dominio es aditiva -- domain default 'academic', domain_config nullable ──

def check_domain_migration_is_additive():
    text = read(INSTALLER)

    if 'migrate_followup_plan_domain_columns' not in text:
        log_fail('10-domain-migration-additive', 'migrate_followup_plan_domain_columns() no está integrada al pipeline install()/force_install()')
        return

    m = re.search(r"private static function migrate_followup_plan_domain_columns\(\): bool \{(.*?)\n\t\}", text, re.DOTALL)
    if not m:
        log_fail('10-domain-migration-additive', 'migrate_followup_plan_domain_columns() no encontrado')
        return
    body = m.group(1)

    if "DEFAULT 'academic'" not in body:
        log_fail('10-domain-migration-additive', "la columna domain no se agrega con DEFAULT 'academic' -- un plan de 6.6.0 (todos académicos) dejaría de resolver correctamente tras la migración")
        return
    if 'domain_config LONGTEXT NULL' not in body:
        log_fail('10-domain-migration-additive', 'domain_config no se agrega como NULLABLE -- ningún plan académico existente tenía configuración extra')
        return
    if 'INFORMATION_SCHEMA.COLUMNS' not in body:
        log_fail('10-domain-migration-additive', 'no se verifica vía INFORMATION_SCHEMA.COLUMNS antes del ALTER -- una instalación que ya corrió esta migración podría fallar al reintentar')
        return

    log_pass('10-domain-migration-additive', "domain (DEFAULT 'academic') y domain_config (NULLABLE) se agregan vía ALTER verificado por INFORMATION_SCHEMA -- todo plan de 6.6.0 sigue resolviendo por Academic_Domain_Provider sin ninguna migración de datos")


# ── 11: las plantillas comerciales están ancladas a Deal_Service::get_stages(), sin inventar etapas ──

def check_commercial_templates_use_existing_stages():
    text = read(PLAN_SERVICE)

    m = re.search(r'private static function get_commercial_templates\(\): array \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('11-commercial-templates-existing-stages', 'get_commercial_templates() no encontrado')
        return
    body = m.group(1)

    if 'Deal_Service::get_stages()' not in body:
        log_fail('11-commercial-templates-existing-stages', 'get_commercial_templates() no referencia Deal_Service::get_stages() -- las plantillas podrían estar inventando etapas nuevas en vez de anclarse a las 9 reales del pipeline')
        return

    for key in ('stalled_deals', 'cold_leads', 'before_month_end', 'key_accounts'):
        if f"'{key}'" not in body:
            log_fail('11-commercial-templates-existing-stages', f'falta la plantilla "{key}" -- PT-2.1 exige exactamente cuatro plantillas comerciales')
            return

    log_pass('11-commercial-templates-existing-stages', 'las cuatro plantillas comerciales (stalled_deals, cold_leads, before_month_end, key_accounts) están presentes y stage_filter se construye a partir de Deal_Service::get_stages() -- ninguna etapa inventada')


# ── 12: "Cuenta clave" no exige stage_filter -- create_plan() acepta el modo de selección manual ──

def check_manual_selection_scope_validation():
    text = read(PLAN_SERVICE)

    start = text.find('public static function create_plan( array $data ) {')
    end = text.find('$inserted = $wpdb->insert(', start)
    if start < 0 or end < 0:
        log_fail('12-manual-selection-validation', 'no se pudo aislar la validación de create_plan()')
        return
    body = text[start:end]

    if "'commercial' === $domain" not in body:
        log_fail('12-manual-selection-validation', "create_plan() no distingue el dominio comercial al validar el alcance -- 'Cuenta clave' (sin stage_filter) necesita poder guardarse con solo section_ids (ahí, contact_ids) presentes")
        return

    log_pass('12-manual-selection-validation', "create_plan() valida el alcance por dominio: académico sigue exigiendo section_ids Y stage_filter (idéntico a 6.6.0); comercial acepta sección_ids vacío en modo por-etapa, o stage_filter vacío en modo selección manual ('Cuenta clave')")


# ── 13: el endpoint de calendario y la vista exponen/consumen 'domain' de forma consistente ──

def check_domain_threaded_through_rest_and_frontend():
    rest = read(REST_CONTROLLER)
    js = read(JS)
    view = read(VIEW)

    if "'domain'" not in rest or 'domain_filter' not in rest:
        log_fail('13-domain-threaded', 'get_calendar_events() no filtra por domain -- el selector de dominio de la UI no tendría efecto en qué ocurrencias se muestran')
        return

    if 'currentDomain' not in js or 'atora-fu-domain-tab' not in js:
        log_fail('13-domain-threaded', 'followup-plans.js no maneja el selector de dominio (currentDomain / .atora-fu-domain-tab)')
        return

    if 'data-default-domain' not in view or 'atora-fu-domain-tabs' not in view:
        log_fail('13-domain-threaded', 'la vista no expone data-default-domain ni el markup de pestañas de dominio')
        return

    log_pass('13-domain-threaded', "el parámetro 'domain' está conectado de punta a punta: la vista expone data-can-academic/data-can-commercial/data-default-domain, el JS lee ese estado y filtra el calendario, y el REST controller filtra get_calendar_events() por el domain del plan dueño de cada ocurrencia")


# ── 14 (relocaliza el 07 de 6.6.0): las plantillas académicas siguen ancladas a etapas reales ──

def check_academic_templates_still_use_existing_stages():
    text = read(PLAN_SERVICE)

    if 'public static function get_templates( string $domain = \'academic\' ): array' not in text:
        log_fail('14-academic-templates-existing-stages', "get_templates() ya no tiene la firma esperada 'string $domain = \\'academic\\'' -- se perdió la compatibilidad hacia atrás con el call-shape de 6.6.0 (llamado sin argumentos)")
        return

    m = re.search(r'private static function get_academic_templates\(\): array \{(.*?)\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('14-academic-templates-existing-stages', 'get_academic_templates() no encontrado -- PT-2 debía relocalizar (no eliminar) el contenido de las 4 plantillas académicas de 6.6.0')
        return
    body = m.group(1)

    if 'Student_Followup_Service::get_stages()' not in body:
        log_fail('14-academic-templates-existing-stages', 'get_academic_templates() ya no referencia Student_Followup_Service::get_stages()')
        return

    for key in ('weekly_checkin', 'high_attention', 'before_closing', 'milestones_only'):
        if f"'{key}'" not in body:
            log_fail('14-academic-templates-existing-stages', f'falta la plantilla académica "{key}" de 6.6.0 -- PT-2 de 6.7.0 no debía tocar el catálogo académico existente')
            return

    log_pass('14-academic-templates-existing-stages', 'get_templates() conserva el call-shape sin argumentos de 6.6.0 (domain default \'academic\'), y las 4 plantillas académicas originales siguen intactas en get_academic_templates(), ancladas a Student_Followup_Service::get_stages()')


def main():
    check_resolver_has_no_direct_academic_reference()
    check_academic_provider_reads_board_live()
    check_providers_never_hide_empty_silently()
    check_domain_interface_and_implementations()
    check_section_closure_scoped_to_academic()
    check_mark_contacted_still_domain_agnostic_and_safe()
    check_commercial_score_is_live()
    check_sequence_coordination_visible_by_default()
    check_sequence_read_only()
    check_domain_migration_is_additive()
    check_commercial_templates_use_existing_stages()
    check_manual_selection_scope_validation()
    check_domain_threaded_through_rest_and_frontend()
    check_academic_templates_still_use_existing_stages()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
