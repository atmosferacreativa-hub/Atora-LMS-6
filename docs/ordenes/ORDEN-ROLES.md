# Orden — Roles institucionales · plugin 6.41.0 · app 1.4.0

Punto 8 de la lista del titular (antes de Perfil, Períodos y Foros). Índice: `docs/ordenes/INDICE.md`.
Avance: `docs/ordenes/PROGRESO-ROLES.md`.

## Objetivo

Tres perfiles que una institución necesita y hoy faltan o no se ven: **Instructor asistente**, **Control académico**
y **Auxiliar administrativo**. Todos limitados a su institución.

## Reglas

Las de siempre: PR por bloque, CI completo en verde, cada cambio con una prueba que falla antes (también lo que un
rol **no** puede hacer), e2e solo contra WordPress temporal, nada a las tiendas, evidencias separadas.

## Paso 0 — Hallazgos (2026-10-29)

1. `lms_instructor_assistant` («Instructor asistente») existe y lo usan las delegaciones y la app, pero
   `CLMS_Access::filter_editable_roles()` no lo incluye: no aparece en el desplegable de roles del usuario.
2. Delegación: permisos `content` y `moderate` por defecto; `grade`, `enroll` y `access` apagados. Las reglas del
   asistente en la app (6.40.0) le impiden publicar, cambiar fechas, publicar la planificación y la evidencia.
3. Calificación: hay borrador y publicación (`ATORA_Grading_Save_Service`), con revisión y auditoría por entrega.
4. **No existe nota final de curso editable**: el total se calcula desde las entregas
   (`Gradebook_Calculation_Service`). No hay registro de correcciones de la nota final.
5. Comercio: `clms_manage_commerce` (lo tiene `shop_manager`); no hay rol de institución para la parte contable.

## Decisiones del titular

- Se llama **Instructor asistente** (no «auxiliar docente»).
- El asistente tiene en su curso (por delegación) **los mismos privilegios que el instructor, en web y app**, salvo
  uno: lo que califica queda como **pre-calificación**; el estudiante no la ve hasta que el **instructor la valida**
  con su usuario. Cambia lo decidido en 6.40.0 (el asistente ya publica lecciones, cambia fechas y publica la
  planificación).
- **Control académico** corrige **notas finales**, nunca parciales (entregas, rúbricas, intentos), con motivo
  obligatorio y auditoría.
- **Auxiliar administrativo** ve calificaciones en solo lectura (un estudiante que reprueba repite y la institución
  privada vuelve a cobrar el curso); no las modifica.

## Bloque 1 — Instructor asistente

- Aparece en el desplegable de roles.
- En los cursos delegados: mismos permisos que el instructor (lecciones, fechas, planificación y su evidencia,
  asistencia, avisos, clase en vivo). Fuera de su delegación, nada.
- Calificar: guarda una **pre-calificación** (borrador marcado «del asistente», con su nombre). Cola del instructor
  «Por validar» (web y app): validar tal cual (publica), editar y publicar, o devolver con comentario. El estudiante
  no ve nada antes. La validación queda en la auditoría (quién propuso, quién validó).
- La IA y el conflicto de revisión (409) funcionan igual para la pre-calificación.

## Bloque 2 — Control académico

- Rol `lms_academic_control`. Ve todos los cursos, secciones, estudiantes, notas, asistencia y riesgo de su
  institución; no edita contenido ni califica entregas.
- **Nota final ajustada** por estudiante y curso: valor, motivo obligatorio, autor y fecha; el historial guarda la
  nota anterior (calculada o ajustada). La nota ajustada es la que ven el estudiante, el boletín, los certificados y
  los informes; quitar el ajuste vuelve a la calculada.
- Matrículas (alta, baja, traslado de sección) e informes y exportaciones de su institución.
- Cierre de período (lo usa el punto 10).

## Bloque 3 — Auxiliar administrativo

- Rol `lms_admin_assistant`. Pedidos, pagos y reembolsos de su institución; estado de cuenta del estudiante;
  reportes financieros.
- Calificaciones y estado académico en **solo lectura**, con la lista «Reprobados / deben repetir» por período.
- No ve contenido de los cursos ni mensajes; no modifica notas ni matrículas académicas.

## Bloque 4 — Pedidos por institución (decisión del titular: varias instituciones en un mismo WordPress)

Hallazgo: los pedidos de WooCommerce no tienen institución; el Auxiliar administrativo de una institución vería los
de toda la tienda.

- **Institución del pedido** (meta `_atora_institution_id`, compatible con HPOS): se deduce de los productos ligados a
  un curso (`_clms_linked_course_id`) o programa (`_clms_linked_program_id`) al crear el pedido y al pagarlo. Si el
  pedido mezcla instituciones o no tiene productos de ATORA, queda sin institución: solo lo ve el administrador.
  Comando `wp atora orders-institution-backfill` (con `--dry-run`) para los pedidos existentes.
- **Acceso del Auxiliar administrativo:** listas de pedidos (HPOS y clásica) filtradas por su institución; abrir,
  editar o reembolsar un pedido de otra institución se niega (pantalla, AJAX de reembolso y API REST de WooCommerce).
- **Sin Analíticas de WooCommerce** (suman toda la tienda): se quita `view_woocommerce_reports` del rol y se agrega
  «Pagos» con los pedidos de su institución y exportación CSV.
- Pruebas: resolver la institución (curso, programa, mezcla, sin ATORA), decisión de acceso por pedido, filtro de
  la lista y denegación entre instituciones. El entorno de pruebas no tiene WooCommerce: se usan pedidos simulados;
  la prueba con WooCommerce real queda como validación real.

## Cierre

6.41.0 y app 1.4.0 (cola «Por validar» y pre-calificación en la app), changelog, `ESTADO.md`, filas en
`PRUEBA-TELEFONO.md`. Etiquetas: el titular.
