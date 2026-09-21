# Baseline local · 6.26.4

Esta versión introduce reconciliación de inquilino (`academy_id` → `institution_id`) y pruebas de integración con base de datos real. Antes de aplicar cambios, guarda una línea base local en `.atora-baseline/` (no versionada) y verifica que los volcados no estén vacíos.

Checklist mínima:

- Rama de trabajo: `feat/6.26.4-tenant-reconciliation`
- Backup DB: `.atora-baseline/pre-6264-<timestamp>.sql`
- Esquema previo: `.atora-baseline/esquema-antes-6264.txt`
- Conteos previos: `.atora-baseline/filas-antes-6264.txt`

Notas:

- Si alguna tabla tiene más de un `academy_id` distinto, la migración deja de ser trivial y debe mapearse explícitamente.
- `.atora-baseline/` se ignora por defecto para evitar datos sensibles en git.

