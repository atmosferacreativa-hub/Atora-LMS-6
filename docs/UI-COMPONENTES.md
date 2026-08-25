# UI-COMPONENTES.md — componentes compartidos de lista + panel

Introducido en el sprint 6.9.0. Este documento es la referencia que
cualquier sprint futuro debe consultar **antes** de construir una
interfaz nueva de "mostrar una lista de personas/entidades con una
acción" — el objetivo explícito de 6.9.0 es que mejorar estos dos
componentes mejore automáticamente cada pantalla que ya los usa, sin
tener que tocarlas una por una.

## Los dos componentes

### `Followup_List_Row` (`includes/ui/class-ui-list-row.php`, PHP; `assets/shared/followup-panel.js` → `AtoraUI.renderRow()`, JS)

Una fila: avatar/inicial, nombre, línea de meta, badges opcionales,
estado de urgencia (`alta`/`media`/`baja`) o tono positivo, y
opcionalmente acciones por fila o un enlace directo. Usada:

- Dentro de un panel (`Followup_Panel`, ver abajo).
- En resultados de búsqueda (PT-2, 6.9.0).
- En el feed de Actividad (PT-3, 6.9.0).
- En cualquier listado futuro de "personas + una acción o destino".

**Nunca construyas una fila de lista con marcado propio si el dato
encaja en esta forma.** Si te falta un campo, extiende el shape — no
inventes una segunda fila.

### `Followup_Panel` (`includes/ui/class-ui-followup-panel.php`, PHP; `assets/shared/followup-panel.js` → `AtoraUI.Panel`, JS)

La carcasa de un panel lateral: título, subtítulo/contexto, lista de
`Followup_List_Row`, acciones en lote, cerrar. Dos modos de uso:

1. **Server-render puro** (`CLMS_UI_Followup_Panel::render( $panel )`)
   — HTML completo de una vez, para contextos sin JS/REST detrás.
2. **Shell + relleno por JS** (`CLMS_UI_Followup_Panel::render_shell( $domId )`
   en PHP para el marcado vacío con IDs fijos, `AtoraUI.Panel.open( domId, config )`
   en JS para llenarlo) — el caso real de calendario académico/comercial
   y "Hoy": el panel se abre con datos que llegan vía REST después del
   clic, sin recargar la página.

`AtoraUI.Panel` **no hace ningún fetch por sí mismo** — quien lo abre
ya trae los datos resueltos y le pasa un `onAction(actionId, itemId, scope)`
que decide qué REST llamar. Esto es intencional: el componente no debe
saber nada de qué endpoint específico usa cada pantalla.

## Shape homogéneo del ítem

La misma forma que ya define `Today_Aggregator_Service::get_today()`
(6.8.0 PT-1.2), extendida cuando el contexto es un panel con personas:

```php
[
  'id'      => int,
  'name'    => string,           // antes 'title' en items de "Hoy" -- ver nota abajo
  'meta'    => string,
  'urgency' => 'alta'|'media'|'baja',   // o:
  'tone'    => 'positivo',              // mutuamente excluyente con urgency
  'badges'  => array<string>,
  'url'     => string,           // fila-enlace (búsqueda, Actividad)
  'actions' => [ ['label' => string, 'action_id' => string, 'done_label' => string] ], // fila-con-botones (panel)
  'done'    => bool,
]
```

**Nota de nomenclatura:** `Today_Aggregator_Service::get_today()` usa
`title` para el texto del ítem (una fila de "Hoy" es un evento/resumen,
no una persona con nombre propio). `Followup_List_Row` usa `name` +
`meta` porque ahí sí es una persona (estudiante, contacto). Ambos
shapes son válidos para su contexto — no se unificó el nombre del
campo porque forzar `title` en una fila de persona (o `name` en un
ítem de "Hoy") sería menos claro, no más. Si construís algo que
combina ambos contextos, mapeá explícitamente.

## Tokens

Todo color/tipografía de estos componentes viene de
`assets/shared/tokens.css` (identidad de marca `--atora-brand-*`, y los
mismos tokens operativos `--atora-*` que `assets/admin/atora-admin.css`
ya define desde 6.6.0). **Nunca un color hardcodeado.** Ver el docblock
de `tokens.css` para la razón de por qué conviven dos capas de token.

## Qué NO hacer

- No dupliques el marcado de una fila o un panel en un módulo nuevo
  "porque es más rápido" — extendé el shape o agregá un caso al
  componente compartido.
- No agregues un tercer valor de estado visual fuera de
  `alta`/`media`/`baja`/`positivo` sin actualizar este documento y
  `tokens.css` primero.
- No le des a `AtoraUI.Panel` conocimiento de un endpoint REST
  específico — eso vive en el `onAction` de quien lo abre.

## Historial de retrofit

- 6.9.0 PT-1.4: `#atora-fu-panel` (calendario académico/comercial,
  6.6.0/6.7.0) migrado a `Followup_Panel`/`Followup_List_Row`.
- 6.9.0 PT-1.5: el panel de ocurrencia de "Hoy" (6.8.0) usa el mismo
  componente desde su primera versión con panel en línea.

## PT-4 — verificación del "loop" único (6.9.0)

Confirmado, un punto de entrada a la vez:

- **Calendario académico/comercial** (bloque → clic → panel): abre
  `AtoraUI.Panel` en `#atora-fu-panel` (`followup-plans.js`).
- **"Hoy"** (ítem de followup → clic → panel en línea): abre
  `AtoraUI.Panel` en `#atora-hoy-panel` (`assets/admin/today.js`), sin
  navegar.
- **"Actividad"** (ítem de "contactaste a X" → clic): navega a
  `atora-followup-plans&event_id=N` — la MISMA página de calendario,
  cuyo propio bootstrap ya lee `?event_id=` de la URL (deep-link
  agregado en PT-3.2 de 6.8.0, ver `followup-plans.js`) y abre
  `AtoraUI.Panel` automáticamente al cargar. Resultado idéntico al de
  abrir el panel en línea, solo que vía una recarga de página en vez
  de sin ella — variación aceptada, no una tercera implementación.
- **Búsqueda** (resultado → clic): navega DIRECTO a la ficha/hub
  correspondiente (`Followup_List_Row` con `url`, sin `actions`) — un
  resultado de búsqueda es una entidad puntual, no una ocurrencia con
  una lista de personas adentro, así que no hay panel que abrir ahí;
  el criterio correcto para ese caso es el enlace directo de PT-2.4,
  no forzar el shape de panel donde no aplica.

**Deliberadamente NO retrofiteado** (documentado, no un descuido):
el asistente de 4 pasos (`#atora-fu-wizard`, PT-4.2 de 6.6.0) sigue
con su propio marcado — es un formulario de varios pasos, no una
lista de personas con una acción, así que no encaja en el shape de
`Followup_Panel`/`Followup_List_Row` y forzarlo ahí sería la clase de
"reescritura" que la regla §0.2 de este sprint no pide.

**Criterio de aceptación de PT-4** ("un docente/vendedor que aprendió
el panel en un lugar puede predecir su comportamiento en cualquier
otro"): cumplido para los cuatro casos donde de verdad hay un panel
que abrir. Donde no hay panel (búsqueda, fichas de "Actividad" sin
`event_id`), el comportamiento esperado es un enlace directo, no un
panel — ver `docs/DEUDA-TECNICA.md` para los casos donde esa ficha
directa todavía no existe (estudiante/sección) y cae a un hub general.
