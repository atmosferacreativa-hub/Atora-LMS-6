# Orden — Analíticas y riesgo académico · plugin 6.36.0

Alias anteriores: "C Analíticas" (lista vieja), "D" (lista ejecutada). Índice: `docs/ordenes/INDICE.md`.
Avance: `docs/ordenes/PROGRESO-ANALITICAS.md`.

## Objetivo

Una sola evaluación del riesgo académico, con motivos comprensibles, que dé el mismo resultado en el panel docente
web, SpeedGrader, la API docente de la app, los informes, las analíticas de aprendizaje y las automatizaciones.
Separada de la medición comercial (captación, participación). Panel institucional coherente con permisos y alcance.

## Reglas

- PR por bloque, CI completo en verde antes de fusionar. Cada cambio con una prueba que falla antes.
- No romper la forma de las respuestas que ya consume la app (`level` = `alto|medio|bajo` en `/teacher/*`).
- Una nota ausente nunca es cero. Trabajar sin conexión no se castiga como inactividad.
- Los registros históricos no se reescriben; se documenta desde qué versión son coherentes.

## Bloque 0 — Lectura (hallazgos)

1. **Dos reglas de riesgo distintas para el mismo estudiante.**
   - `CLMS_Academic_Status_Service::get_risk_level()` (`high|medium|normal`): alto con promedio < 50; medio con
     3+ pendientes o **progreso < 30 %**; inactividad. Lo consumen informes, gradebook, CRM, perfil del estudiante
     y `Learning_Analytics_Service` (que deriva su puntuación de él).
   - `ATORA_Student_Risk_Service::evaluate()` (`alto|medio|bajo`): alto con promedio < 60 o 2+ entregas vencidas;
     medio con promedio < 70, 1 vencida o 3+ pendientes. Lo consumen la API docente de la app y SpeedGrader.
   - Resultado: un estudiante con 55 de promedio es "alto" en la app y "medio" o "normal" en los informes.
2. **Un estudiante recién inscrito sale en riesgo.** `progress < 30` incluye el 0 % del primer día, y "sin acceso
   registrado" cuenta como inactivo.
3. **La app no registra actividad.** `CLMS_Student_Activity_Tracker` solo se alimenta desde el navegador
   (`template_redirect` y un script en `wp_footer`). Quien estudia solo en la app aparece "sin actividad reciente":
   sube su riesgo y recibe recordatorios de inactividad.
4. **`Analytics_Engine` usa también `risk_level`** (puntuación ≥ 80 bajo, ≥ 40 medio) para una medición de
   participación ligada a lo comercial. Mismo nombre, otra cosa.

## Bloque 1 — Regla canónica

- `ATORA_Academic_Risk_Rule::evaluate()`: regla pura con señales explícitas y motivos con código y texto.
  - Promedio (solo si hay notas): < 50 alto; 50–69 medio.
  - Entregas vencidas sin hacer (early-warning abierto): 2+ alto; 1 medio.
  - 3+ actividades pendientes: medio.
  - Progreso < 30 %: medio, **solo** pasado el período de gracia desde la inscripción (14 días, ajustable) y si el
    curso tiene lecciones.
  - Inactividad (sin actividad registrada por web o app en N días, 14 por defecto): medio; con 2+ pendientes o
    progreso < 50 %, alto. Nunca registrado: solo cuenta pasado el período de gracia. El motivo aclara que la
    actividad sin conexión de la app se registra al sincronizar.
- `CLMS_Academic_Status_Service` y `ATORA_Student_Risk_Service` delegan en la regla (misma entrada → mismo nivel);
  el segundo mantiene `alto|medio|bajo` como traducción del código canónico.

## Bloque 2 — Actividad desde la app

- Las peticiones autenticadas de la app registran el acceso (global y por curso), con límite de escritura.
- Prueba: un estudiante que solo usa la app no queda inactivo.

## Bloque 3 — Separar lo comercial

- La medición de `Analytics_Engine` pasa a llamarse participación (`engagement_level`); `risk_level` se mantiene
  un ciclo como alias marcado obsoleto. Ningún consumidor académico la usa.

## Bloque 4 — Panel institucional y consistencia

- Reutilizar el panel de riesgo existente (filtros y exportación): alcance por institución y docente verificado.
- Prueba de consistencia: mismos datos → mismo nivel y motivos en panel web, `/teacher/*`, SpeedGrader, informes y
  analíticas de aprendizaje.

## Cierre

6.36.0, changelog, `ESTADO.md`, nota en "Datos históricos" (riesgos guardados antes de 6.36.0 usan la regla vieja).
