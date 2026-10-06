# Progreso — Fase 5 (IA)

Orden: `docs/ordenes/ORDEN-FASE-5.md`. Al reanudar: leer este archivo, comprobar en GitHub ramas, PR y CI, y seguir desde "Paso actual" sin rehacer lo fusionado.

## Paso actual

**Bloque A — plugin 6.31.1 listo en PR; sigue la app 0.8.1** (A.4: borrador recuperado conserva su revisión; recorrido `docente-borrador-recuperado`; paso de concurrencia real en el CI de la app; A.5 en `PRUEBA-TELEFONO.md`).

## Ramas y PR

| Repo | Rama | Estado |
|---|---|---|
| Atora-LMS-6 | `fix/6.31.1-revision` | PR abierto (6.31.1) |
| atora-mobile | `fix/0.8.1-borrador-revision` | por crear |

## Falta del bloque

- [ ] CI verde del plugin 6.31.1, fusionar, etiqueta `v6.31.1`, ZIP verificado.
- [ ] App 0.8.1: borrador recuperado envía su revisión original hasta "Reemplazar con mi borrador"; Jest; recorrido `docente-borrador-recuperado`.
- [ ] CI de la app: paso `scripts/e2e-concurrent-grade.sh` (concurrencia real) antes del emulador.
- [ ] `PRUEBA-TELEFONO.md`: filas del A.5.
- [ ] App 0.8.1: changelog, etiqueta, sin APK. `ESTADO.md`.

## Decisiones menores

1. Revisión: se reclama bajo `GET_LOCK` por entrega (atómico entre procesos, también el primer guardado) y se **devuelve** con comparar y reemplazar si el guardado falla después. Error de base de datos: se detecta verificando que el estado y la nota decididos quedaron guardados (los avisos de otros listeners no cuentan).
2. Se eliminó el camino sin revisión del servicio: solo lo usaban SpeedGrader y la API, que ahora la exigen. La web sin el campo (página vieja) pide recargar.
3. La prueba de concurrencia real vive en el plugin (`scripts/e2e-concurrent-grade.sh`) y la corre el CI de la app, que es el que tiene el WordPress con HTTP. En local, la carrera del primer guardado no se pudo provocar con el código anterior (ventana muy corta); el diseño nuevo la elimina por construcción.
