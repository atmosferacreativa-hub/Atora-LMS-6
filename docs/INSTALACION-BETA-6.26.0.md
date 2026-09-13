# Instalación beta — ATORA LMS 6.26.0

Este documento guía una actualización controlada en entorno **beta**. No activa Cutover F4.

## Pre-requisitos

1. Confirmar versión de PHP:
   - PHP **8.1** o superior.
2. Confirmar versión de WordPress:
   - WordPress **6.4** o superior.
3. Tener acceso a:
   - Archivos (FTP/SSH/File Manager).
   - Base de datos (phpMyAdmin/CLI) para backup y verificación.

## 1) Respaldo completo (OBLIGATORIO)

1. Respaldo de archivos:
   - Descargar el directorio completo de `wp-content/plugins/atora-lms/` (o el nombre instalado en ese sitio).
2. Respaldo de base de datos:
   - Export completo SQL.
3. Verifica que el backup sea restaurable (tamaño esperado y sin errores).

## 2) Registrar estado actual

1. En WP Admin:
   - Ir a Plugins → ATORA LMS y anotar la versión instalada.
2. En la base de datos (opcional pero recomendado):
   - Revisar opciones:
     - `atora_lms_plugin_version`
     - `atora_lms_current_version`
     - `atora_lms_roles_version`
   - Exportar/respaldar opciones relacionadas con ATORA (si tu entorno lo permite).

## 3) Verificar tablas existentes

En la base de datos, confirmar si existen (prefijo `wp_` puede variar):

- `wp_atora_courses`
- `wp_atora_lessons`
- `wp_atora_enrollments`
- `wp_atora_lesson_progress`
- `wp_atora_mobile_tokens`

No crear ni borrar manualmente tablas en beta.

## 4) Desactivar caché durante la actualización

1. Desactivar temporalmente:
   - LiteSpeed Cache / plugins de caché.
   - CDN/WAF que pueda interferir con admin-ajax/REST (si aplica).

## 5) Reemplazo del plugin (sin instalar ZIP automático)

1. Desactivar el plugin ATORA LMS desde WP Admin.
2. Subir el paquete **validado por CI**:
   - `atora-lms-6.26.0.zip`
3. Reemplazar el plugin existente:
   - Extraer y sobrescribir en `wp-content/plugins/atora-lms/`.
4. Confirmar que el directorio final quede:
   - `wp-content/plugins/atora-lms/atora_lms.php`

## 6) Activación

1. Activar el plugin desde WP Admin.
2. Confirmar que no aparece error fatal al activar.

## 7) Confirmar actualización idempotente de tablas

1. En WP Admin → ATORA:
   - Confirmar que carga el Panel ATORA.
2. En DB:
   - Verificar que las tablas esperadas siguen existiendo y que no se duplicaron.

## 8) Revisar logs

1. Revisar `wp-content/debug.log` (si está habilitado).
2. Revisar logs del servidor (error_log) si hubo pantallas blancas o 500.

Si hay errores fatales o warnings masivos, suspender la prueba y hacer rollback.

## 9) Permalinks (solo si hay síntomas)

No regenerar permalinks “por rutina”.

Solo si el REST o rutas internas fallan:
1. Ajustes → Enlaces permanentes.
2. Guardar sin cambios.

## 10) Purga controlada de caché

1. Re-activar cachés.
2. Purgar LiteSpeed/CDN una vez, al final, si todo está estable.

## 11) Checklist funcional mínima

1. ATORA → Panel ATORA carga.
2. Listados de cursos/lecciones accesibles para admin.
3. Verificación de matrículas (al menos un usuario con matrícula).
4. REST general operativo (wp-json responde).
5. Mobile API registrada (ver documento de configuración móvil).

## 12) Rollback exacto

Si algo sale mal:
1. Desactivar ATORA LMS.
2. Restaurar:
   - Carpeta del plugin desde backup.
   - Base de datos desde export SQL.
3. Confirmar versión previa en WP Admin.

## 13) Condiciones para suspender la beta

- Errores fatales en admin o frontend.
- REST bloqueado o devuelve 5xx.
- Matrículas no cargan (0 cursos para usuarios que sí están matriculados).
- Problemas de permisos (403 inesperados) en operaciones base.
- Regresiones críticas en Panel ATORA o navegación.

## Nota sobre Cutover F4

No activar Cutover F4 durante esta prueba beta.

