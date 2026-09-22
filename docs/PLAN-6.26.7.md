# 6.26.7 — seguimiento de correcciones del laboratorio

Alcance actualizado por instrucción del usuario: incorporar B.1–B.5 y ejecutar las
correcciones verificables sin nuevas confirmaciones rutinarias. Este registro no
declara lista la versión ni sustituye las pruebas del laboratorio con sus datos.
La versión del plugin continúa en 6.26.5 hasta autorizar su publicación.

Base: main `5793f8458969614181aaba62c5bae65d3e838300`.
Respaldo: `backup/pre-6.26.7-20260922-5793f84`.
Dependencia: PR #16 (distribución), sin fusionar a main.

| Punto | Estado y evidencia | Pendiente de aceptación |
| --- | --- | --- |
| B.1 Calificación → Gradebook | Confirmado en código: el roster solo consultaba `_clms_enrolled_users`, incluso en modo `tables`. Se añade lector por `atora_courses.wp_post_id` unido a `enrollments.course_id`, manteniendo separados ambos dominios de ID. | Integración que publica desde SpeedGrader y consulta la celda; repetir en el Lab con la entrega afectada. No se presume que todo caso vacío tenga esta causa. |
| B.2 `clms-cohorts` | Corregido antes de esta rama: redirección a `edit.php?post_type=lm_cohort`, también en `admin_page_access_denied`; prueba existente HiddenPagesTest. | Conservar comportamiento y capacidades del CPT. No duplicar reparación. |
| B.3 Contadores | Corrección previa: Panel/Hub cuentan cursos y programas `publish + private`; CountPostsByTypeTest verifica exclusión de borradores/papelera. Diferencia móvil reportada como resuelta por el usuario. | Comparar estados, filtros, rol y versión del Lab. No cambiar cifras ni contenidos para forzar coincidencias. Script de diagnóstico de solo lectura incluido. |
| B.4 Recarga SpeedGrader | URL canónica conserva `clms_speedgrade`, `submission_id` y retorno. Se recuperan enlaces cortos de portada con submission válida y se despacha antes de redirecciones comunes. | Pruebas de resolución, identificadores inválidos y permisos; recarga real con tema ATORA en Lab. No se modifica el tema. |
| B.5 Nombre de estudiante | Confirmado: contexto/cola omitían `_clms_submission_student_id` y autor; se añade fallback y etiqueta explícita para cuenta ausente, sin reasignar ni reparar datos. | Probar identidad y autorización; comprobar entrega 425 en Lab. |

## Otros pendientes que conserva el diagnóstico inicial

- Quizzes móviles nativos: `quiz_context()` exige CPT incluso antes de leer tablas.
- Incremento de `revision` en las rutas de edición de curso/lección.
- Paridad real CPT/tablas, F4, actualización desde versiones anteriores y rollback
  con base de datos; no demostrados solo por existencia de funciones o CI verde.

## Verificación reproducible

1. Ejecutar `vendor/bin/phpunit -c phpunit.integration.xml tests/integration/AcademicLabRegressionTest.php`
   en el entorno de integración WordPress/MySQL y después los runners completos de CI.
2. Construir e inspeccionar ZIP con `bash scripts/build-dist.sh` y
   `python3 scripts/inspect-dist.py dist/atora-lms-6.26.5.zip 6.26.5 "$(git rev-parse HEAD)"`.
3. En un Lab con copia de seguridad propia de DB y archivos, instalar el ZIP de esta
   rama. La rama backup de Git no contiene DB ni uploads.
4. Desde un checkout montado en Docker y con WP-CLI:
   `wp eval-file scripts/diagnose-academic-lab.php 425 6726`.
   Si el plugin se instaló por ZIP, copiar el script por separado: scripts se excluye
   deliberadamente del paquete. El diagnóstico informa versión, fuente de lectura,
   estados/IDs de cursos y programas, claves de matrícula e identidad; no escribe datos.
5. Publicar una nota de una entrega de un alumno matriculado y verificar exactamente
   la misma celda en Gradebook; retirar filtros antes de evaluar un listado vacío.
6. Recargar el enlace canónico y `/?submission_id=6726` como docente autorizado;
   comprobar que alumno y visitante no ven información de corrección.
7. Consultar entrega 425, comparar su identidad con los metadatos y el autor.
   Una cuenta eliminada debe mostrar un aviso identificable, nunca otro estudiante.

No se ha accedido al Docker personal ni a las entregas reales 425/6726 desde este
entorno. Sus verificaciones permanecen pendientes aunque los casos sintéticos pasen.
