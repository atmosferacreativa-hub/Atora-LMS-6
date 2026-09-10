# Cutover F4 — procedimiento operativo

> Para el equipo que opera el despliegue, no para desarrolladores. Ejecuta
> esto cuando quieras mover la lectura del LMS de `legacy` (usermeta) a
> `tables` (tablas propias `atora_*`).

## Antes de empezar

El cutover ya tiene toda su infraestructura construida (F1–F4): router de
lectura, doble escritura, paridad de sombra, panel admin y ahora un gate
programático. Lo único que este documento cubre es **cuándo y cómo apretar
el botón**, no cómo se construyó.

## 1. Verificar el estado del gate

**Desde el panel:** Admin → ATORA → Ajustes → Migración LMS. La sección
"F4 — Cutover de lectura" muestra el botón "🚀 Ejecutar flip" habilitado
solo si el gate está en verde; si no, lista los motivos exactos por los
que está bloqueado.

**Desde WP-CLI** (para despliegues sin acceso al panel):

```bash
wp atora lms cutover --status
```

Muestra la fuente de lectura activa y, si el gate no está listo, cada
motivo por separado.

## 2. Condiciones del gate (D-006)

Las cinco se evalúan juntas en `LMS_Parity::cutover_ready()`:

1. `atora_lms_dualwrite` activo (toggle F2 en el mismo panel).
2. 0 divergencias registradas en `atora_lms_parity_log` en los últimos 14 días.
3. La última reconciliación diaria (`atora_lms_reconcile_result`, cron
   `atora_lms_reconcile_check`) reportó 0 pendientes/huérfanos y fue ejecutada
   hace no más de 48 horas.
4. El volumen de paridad cubre a todos los alumnos activos (`volume_ok = true`).
5. Las 4 tablas núcleo (`atora_courses`, `atora_lessons`,
   `atora_enrollments`, `atora_program_enrollments`) tienen filas.

Si cualquiera falla, el flip está bloqueado — tanto en el botón del panel
como en el endpoint AJAX que lo respalda (`ajax_toggle_read_source`), así
que no hay forma de saltarse el gate llamando la acción directamente.

## 3. Antes de ejecutar

- Backup de base de datos tomado y **verificado** (no solo programado).
- Ventana de bajo tráfico (madrugada o fin de semana) — el flip es
  instantáneo, pero la vigilancia post-cutover debe poder empezar de
  inmediato.
- Confirmar que alguien puede monitorear el panel las siguientes horas.

## 4. Ejecutar el flip

**Panel:** botón "🚀 Ejecutar flip (legacy → tables)" → confirmar en el
diálogo del navegador.

**WP-CLI:**

```bash
wp atora lms cutover --run
```

Ambos caminos: validan el gate de nuevo en el servidor, guardan un
snapshot de la fuente anterior en la opción `atora_lms_cutover_log`,
registran `atora_lms_cutover_at`, y cambian
`atora_lms_read_source` a `tables`.

## 5. Monitoreo post-cutover (F4.3)

El panel muestra, mientras la fuente sea `tables`:

- **Divergencias PC (14 días)** — comparación inversa: ahora tabla es
  canónico, legacy es la sombra. Cualquier divergencia aquí es señal de
  alerta inmediata.
- **Días estables** — días consecutivos sin divergencias PC desde el
  cutover.
- **Gate F5** — se marca listo con 0 divergencias PC y 14 días estables
  (umbral para retirar por completo la doble escritura y la sombra, fuera
  de alcance de este sprint).

## 6. Rollback (D-007)

Cualquier divergencia PC confirmada, o cualquier incidente relacionado,
activa el rollback inmediato. Es instantáneo y sin pérdida de datos —
la doble escritura garantiza que legacy sigue actualizado mientras el
sistema estuvo en modo `tables`.

**Panel:** botón "↩ Rollback (tables → legacy)", siempre disponible
mientras la fuente sea `tables`.

**WP-CLI:**

```bash
wp atora lms cutover --rollback
```

No requiere que el gate esté verde — el rollback nunca está bloqueado.

## 7. Registro

Cada flip y rollback (por panel o CLI) queda anotado en la opción
`atora_lms_cutover_log`: acción, fuente anterior, fuente nueva, fecha
UTC y usuario (o `via: wp-cli` si vino del comando). Útil para
reconstruir la cronología de un incidente.
