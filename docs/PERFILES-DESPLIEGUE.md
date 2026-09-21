# Perfiles de despliegue (ATORA)

Decisión de producto: **una instalación de WordPress por institución**.

Estos perfiles sirven para **cotizar**, planificar recursos, y reducir el radio de daño (ventana de backup/restauración y mantenimiento). Los perfiles **no bloquean matrículas**; solo generan avisos cuando la instalación se acerca o supera su tope de diseño.

## Definiciones

- **Asientos usados**: miembros activos con rol `student` en `atora_institution_members` (suma por instalación).
- **Tope de diseño**: límite operativo recomendado por instalación.
- **Perfil activo**: option `atora_deployment_profile` (`small|medium|large`).

## Perfil `small` (hasta 100 estudiantes)

Uso típico: academias pequeñas, pilotos, programas independientes.

- **Servidor recomendado**
  - 1 vCPU, 2–4 GB RAM
  - SSD/NVMe
  - PHP-FPM + OPcache
  - MySQL 8 (o equivalente) en el mismo host o servicio administrado básico
- **Respaldo**
  - Frecuencia: diario (DB) + semanal (archivos) como mínimo
  - Retención: 14–30 días
- **Restauración estimada**
  - 15–60 min (según tamaño de uploads y latencia del storage)
- **Límites operativos conocidos**
  - Importaciones: lotes pequeños (cientos) con validación previa
  - Concurrencia: baja a moderada (picos cortos)
  - Transcodificación/medios: evitar procesos paralelos altos en el mismo host
  - Cuota de IA: mantener límites conservadores por costo y latencia

## Perfil `medium` (hasta 1.500 estudiantes)

Uso típico: institutos y universidades pequeñas.

- **Servidor recomendado**
  - 2–4 vCPU, 8–16 GB RAM
  - SSD/NVMe
  - Object cache (Redis recomendado)
  - MySQL 8 administrado o dedicado
- **Respaldo**
  - Frecuencia: diario (DB) + incremental de archivos (diario) o snapshots
  - Retención: 30 días
- **Restauración estimada**
  - 1–4 h (depende del volumen de uploads y del throughput del storage)
- **Límites operativos conocidos**
  - Importaciones: miles de filas en lotes (con reintentos e idempotencia)
  - Concurrencia: moderada; requiere caché y límites de rate limit configurados
  - Transcodificación/medios: separar si el volumen es alto
  - Cuota de IA: definir por institución y registrar consumo

## Perfil `large` (hasta 10.000 estudiantes) — tope de diseño del producto

Uso típico: universidades con operación institucional.

- **Servidor recomendado**
  - 4–8 vCPU, 16–32 GB RAM (o más según plugins adicionales)
  - SSD/NVMe
  - Object cache (Redis requerido)
  - MySQL 8 dedicado/administrado con monitoreo, backups point-in-time, y ajustes de índices
  - CDN y storage externo para medios (recomendado)
- **Respaldo**
  - Frecuencia: diario (full DB) + point-in-time o incrementales; snapshots frecuentes de archivos
  - Retención: 30–90 días según política institucional
- **Restauración estimada**
  - 4–12 h (o más si el volumen de archivos es alto)
- **Límites operativos conocidos**
  - Importaciones: grandes (decenas de miles) solo por lotes y con ventanas de mantenimiento
  - Concurrencia: alta; requiere límites, caché, y monitoreo continuo
  - Transcodificación/medios: debe estar desacoplada (servicio externo o pipeline)
  - Cuota de IA: debe estar presupuestada y limitada (por costo y rendimiento)

## Fuera de alcance (6.26.4)

- `GET /sync?since=` (consolidadora multi-instalación) se entrega en 6.26.5.

## Mediciones reales (línea base)

Ejecutadas el **2026-09-21** en ATORA Lab local (Docker), con **PHP 8.1.33** y **MySQL 8.0**.

Nota: estos tiempos corresponden a la ejecución de cada archivo de la suite de integración,
que incluye el bootstrap/instalación de WordPress del test runner.

- **Escala 10.000 (cohorte)**: `tests/integration/CohortScaleTest.php` → **8.284 s**
