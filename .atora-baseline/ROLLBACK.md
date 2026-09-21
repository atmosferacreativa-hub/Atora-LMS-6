# ROLLBACK · ATORA LMS · línea base 6.26.2 → previo a multitenencia/delegación

Estado verificado el: 2026-09-21

## Identificadores de línea base

- Tag git: `v6.26.2-pre-tenancy`
- Rama de trabajo: `feat/6.26.3-tenancy-delegation`

## Opciones antes de migrar

- `atora_v5_schema_version`: `6.26.0-integrated-schema`
- `atora_schema_version`: `6.13.1`
- `atora_lms_read_source`: `tables`
- `atora_lms_dualwrite`: `1`

Los valores completos están en `.atora-baseline/opciones-antes.txt` (no versionado).

## Respaldo de base (referencia)

- Volcado completo: `.atora-baseline/full-20260921-065420-no-tablespaces.sql`
- Volcado dirigido: `.atora-baseline/afectadas-20260921-065420.sql`

Nota: los dumps no se versionan.

## Opción A — rollback total (código + DB completa)

1) Volver el código:

```bash
git checkout v6.26.2-pre-tenancy
```

2) Restaurar DB completa:

```bash
docker exec -i atora-database mysql -uatora -patora_local_password atora_lab < .atora-baseline/full-20260921-065420-no-tablespaces.sql
```

3) Restaurar el schema version (si fuese necesario):

```bash
docker exec -i atora-wordpress wp option update atora_v5_schema_version 6.26.0-integrated-schema --path=/var/www/html --allow-root
```

## Opción B — rollback limpio (aditivo)

Ejecuta el comando CLI implementado por este trabajo (borra solo lo aditivo):

```bash
docker exec -i atora-wordpress wp atora tenancy rollback --yes --path=/var/www/html --allow-root
```

Debe:
- Eliminar las tablas nuevas de tenencia/delegación.
- Quitar columnas añadidas en tablas existentes.
- Borrar opciones: `atora_default_institution`, `atora_cohort_source`.
- Restaurar `atora_v5_schema_version` al valor anterior.

No debe tocar:
- `wp_posts`, `wp_postmeta`, `wp_users`, `wp_usermeta` (ni datos legacy de cohortes).

