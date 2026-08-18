# Catálogo de plantillas WhatsApp — sprint 6.4.0

> `WhatsApp::send_template()` (`modules/messaging/class-whatsapp.php`)
> solo envía plantillas **aprobadas por Meta Business**, identificadas
> por `template_key`, con variables **posicionales** (`{{1}}`, `{{2}}`,
> …). No hay texto libre fuera de la ventana de 24h de conversación
> abierta. Cada plantilla de esta lista debe solicitarse y aprobarse en
> Meta Business Manager antes de que el tipo de mensaje correspondiente
> pueda salir por WhatsApp — mientras tanto, cae a email
> automáticamente (ver §Degradación).

## Cómo leer esta tabla

- **`template_key`**: el nombre exacto a registrar en Meta. Usa el
  mismo string que el código pasa a `WhatsApp::send_template()`.
- **Variables**: en orden posicional — el orden de esta lista ES el
  orden de `{{1}}`, `{{2}}`, etc. No se pueden reordenar sin volver a
  aprobar la plantilla.
- **Texto propuesto**: en español de Venezuela, revisado contra los
  requisitos de UX del sprint — sentido completo sin abrir el enlace,
  sin jerga de plataforma, una sola acción por mensaje.
- **Categoría**: la de `Messaging_Router::category_for_type()` (PT-1) —
  determina si el estudiante puede desactivarla desde
  `[atora_preferencias]`.

---

## `atora_assignment_graded`

**Tipo:** `assignment_graded` · **Categoría:** académico · **Prioridad:** alta

**Variables:** `{{1}}` nombre del estudiante, `{{2}}` nombre de la
materia/curso, `{{3}}` nombre de la tarea, `{{4}}` calificación, `{{5}}` enlace corto

**Texto:**
> Hola {{1}}. Tu profesor calificó la tarea "{{3}}" de {{2}}: {{4}}.
> Ver detalle: {{5}}

## `atora_assignment_due_soon`

**Tipo:** `assignment_due_soon` · **Categoría:** recordatorios · **Prioridad:** alta

**Variables:** `{{1}}` nombre del estudiante, `{{2}}` nombre de la
tarea, `{{3}}` materia/curso, `{{4}}` fecha y hora límite, `{{5}}` enlace corto

**Texto:**
> Hola {{1}}. La tarea "{{2}}" de {{3}} vence {{4}}. Entrégala aquí:
> {{5}}

## `atora_submission_received`

**Tipo:** `submission_received` · **Categoría:** académico (destinatario: docente) · **Prioridad:** normal

**Variables:** `{{1}}` nombre del docente, `{{2}}` nombre del
estudiante, `{{3}}` nombre de la tarea, `{{4}}` materia/curso, `{{5}}` enlace corto

**Texto:**
> Hola {{1}}. {{2}} entregó "{{3}}" en {{4}}. Calificar: {{5}}

## `atora_student_inactive`

**Tipo:** `student_inactive` · **Categoría:** recordatorios · **Prioridad:** normal

**Variables:** `{{1}}` nombre del estudiante, `{{2}}` curso, `{{3}}`
días de inactividad, `{{4}}` enlace corto

**Texto:**
> Hola {{1}}. Tienes {{3}} días sin entrar a {{2}}. Retoma donde te
> quedaste: {{4}}

Reemplaza el `subject`/`headline`/`button_text` configurables de
`CLMS_Student_Inactivity_Reminder_Service` (ver PT-2.3) como variables
de esta plantilla para el canal WhatsApp; el canal email sigue usando
esos mismos textos tal cual, sin cambio.

## `atora_at_risk_teacher`

**Tipo:** `at_risk_flagged` · **Categoría:** académico (destinatario:
docente/coordinador — **nunca el estudiante**, ver PT-1.2) · **Prioridad:** alta

**Variables:** `{{1}}` nombre del docente/coordinador, `{{2}}` nombre
del estudiante, `{{3}}` curso/sección, `{{4}}` motivo breve (p.ej.
"promedio bajo" o "inactividad prolongada"), `{{5}}` enlace corto al
perfil del estudiante en el panel

**Texto:**
> Hola {{1}}. {{2}} en {{3}} necesita seguimiento: {{4}}. Ver contexto:
> {{5}}

## `atora_improvement_plan` (catalogada, no se envía en este sprint)

**Tipo:** `improvement_plan_assigned` · **Categoría:** académico ·
**Prioridad:** alta

**Variables:** `{{1}}` nombre del estudiante, `{{2}}` curso, `{{3}}`
resumen del plan, `{{4}}` enlace corto

**Texto:**
> Hola {{1}}. Tienes un plan de mejora en {{2}}: {{3}}. Ver detalle:
> {{4}}

No hay punto de enganche real para este tipo en 6.4.0 — ver
`docs/DEUDA-TECNICA.md`. Se documenta la plantilla igual para no
perder el trabajo de diseño cuando 6.5.0 construya la persistencia de
planes.

## `atora_lesson_published` (uso interno del resumen, PT-5)

**Tipo:** `lesson_published` · **Categoría:** recordatorios ·
**Prioridad:** baja — candidato natural a resumen, normalmente no sale
individual por WhatsApp (ver `$routing_rules`, solo `email` por
defecto). Si en el futuro se activa WhatsApp para este tipo:

**Variables:** `{{1}}` nombre del estudiante, `{{2}}` nombre de la
lección, `{{3}}` curso, `{{4}}` enlace corto

**Texto:**
> Hola {{1}}. Nueva lección disponible en {{3}}: "{{2}}". Verla:
> {{4}}

## `atora_section_announcement`

**Tipo:** `section_announcement` · **Categoría:** institucional ·
**Prioridad:** normal — el texto lo define quien envía el anuncio, no
es fijo como los demás.

**Variables:** `{{1}}` nombre del estudiante, `{{2}}` sección/curso,
`{{3}}` texto del anuncio (truncado a lo que la plantilla permita),
`{{4}}` enlace corto

**Texto:**
> Hola {{1}}. Aviso de {{2}}: {{3}}. Más info: {{4}}

## `atora_daily_digest_student` (PT-5.1/5.2)

**Tipo:** interno del agrupador, no un `type` de `Messaging_Router`
· **Prioridad:** baja

**Variables:** `{{1}}` nombre del estudiante, `{{2}}` número de
novedades, `{{3}}` resumen de una línea (primeras 1-2 novedades),
`{{4}}` enlace corto al resumen completo

**Texto:**
> Hola {{1}}. Tienes {{2}} novedades: {{3}}. Ver todo: {{4}}

## `atora_daily_digest_teacher` (PT-5.3)

**Tipo:** interno del agrupador, no un `type` de `Messaging_Router`
· **Prioridad:** baja

**Variables:** `{{1}}` nombre del docente, `{{2}}` entregas por
calificar, `{{3}}` estudiantes inactivos, `{{4}}` enlace corto al
panel

**Texto:**
> Hola {{1}}. Hoy: {{2}} entregas por calificar, {{3}} estudiantes
> inactivos. Ver panel: {{4}}

Abre con la acción pendiente, no con estadísticas — regla de UX del
sprint (§Docente).

---

## Degradación cuando una plantilla no está aprobada

Ninguna plantilla de esta lista debe asumirse aprobada al desplegar
6.4.0. El código debe:

1. Antes de llamar `WhatsApp::send_template()`, verificar el estado de
   aprobación de la plantilla (PT-6.1: catálogo de estado por
   `template_key` en el panel).
2. Si no está aprobada, el `Messaging_Router` cae al siguiente canal
   de `$routing_rules` para ese tipo (siempre `email` en esta lista) —
   comportamiento normal de la cadena de respaldo, no un caso especial.
3. Registrar en el log de mensajería que la caída fue por plantilla no
   aprobada específicamente (no un fallo genérico) — PT-6.2 lo hace
   visible en el panel: "la falla más probable en producción, hoy
   sería invisible."

## Proceso de aprobación (referencia operativa)

1. Registrar cada plantilla de esta lista en Meta Business Manager,
   categoría "Utility" (transaccional) donde aplique — no "Marketing",
   que tiene reglas de aprobación más estrictas y ventanas de envío
   limitadas.
2. La aprobación puede tardar días y puede rechazarse — variables
   ambiguas o texto que Meta interprete como promocional son las
   causas más comunes de rechazo. Si una plantilla es rechazada,
   ajustar el texto (menos genérico, más específico al evento) y
   reenviar a revisión; no hay atajo.
3. Hasta que una plantilla esté aprobada, ese tipo de mensaje sale por
   email exclusivamente — es el comportamiento esperado, no un bug.
