# Actualizar a 6.3.0 — qué encuentra un usuario de 6.2.1

## Lo importante primero

**Actualizar no cambia nada.** Todos los módulos quedan activos (perfil
`academia`, el mismo comportamiento de siempre) y la lectura del LMS
sigue exactamente donde estaba (`legacy`, salvo que ya hubieras hecho
el cutover manualmente en un sprint anterior). No hace falta tocar
nada después de actualizar — el plugin se ve y se comporta igual que
en 6.2.1 hasta que un administrador decida explícitamente lo
contrario.

## Qué es nuevo, pero apagado por defecto

### Módulos activables/desactivables

Antes, los ~19 módulos del plugin (CRM, comercio, afiliados,
newsletter, automatización, etc.) siempre estaban todos cargados, sin
forma de apagar los que no se usan. Ahora hay una página nueva —
**Admin → ATORA → Módulos** — desde donde se pueden desactivar. Con la
actualización, la opción que controla esto (`atora_active_modules`)
todavía no existe en la base de datos, así que el plugin se comporta
como si estuviera "todo activo" — que es exactamente el comportamiento
de 6.2.1. Nadie tiene que ir a esa página a menos que quiera empezar a
desactivar algo.

Los módulos `lms`, `academic`, `gradebook` y `security` nunca se
pueden desactivar — son el núcleo.

### Perfiles de instalación

Si se crea una instalación NUEVA, el wizard de configuración ahora
pregunta primero qué tipo de instalación es: **academia** (todo
activo, el default), **institucional** (panel académico puro, sin
CRM/comercio/afiliados) o **corporativo** (institucional + CRM y
automatización). Una instalación que se actualiza desde 6.2.1 nunca
pasa por este wizard de nuevo — se queda en `academia` (equivalente a
"todo activo") a menos que alguien vaya a Módulos y cambie de perfil
manualmente.

### Menú de administración reorganizado

El menú lateral de ATORA se reorganizó de más de 15 entradas dispersas
a 8 secciones (Panel, Academia, Estudiantes, Docentes, Comunicación,
Crecimiento, Informes, Ajustes). **Ninguna página se eliminó** —
páginas que antes eran su propia entrada en el menú (Email, Newsletter,
Mensajería, Calendario, CRM, Comercio, Afiliados, Automatizaciones,
Webhooks, Seguridad, IA, Analytics, Licencia, Formularios, Popups)
ahora se alcanzan desde tarjetas dentro de la sección correspondiente,
o siguen siendo accesibles por la misma URL de siempre si tenías un
enlace guardado. Cualquier URL vieja de una página que cambió de lugar
sigue funcionando — o redirige automáticamente a su reemplazo, nunca
da error "no tienes permitido acceder" ni 404.

### Cutover de lectura LMS (F4)

Si tu instalación viene de un sprint anterior y ya tenía la
infraestructura de migración F1-F3, ahora existe además un comando
WP-CLI (`wp atora lms cutover --status`) y un gate más estricto antes
de poder ejecutar el flip a lectura de tablas. Si no sabes de qué se
trata esto, no aplica a tu instalación — sigue leyendo desde
`_clms_enrolled_courses` como siempre.

## Qué SÍ debería revisar un administrador (opcional, no urgente)

- Si activaste alguna vez la opción "Eliminar datos al desinstalar",
  vale la pena saber que la lista de tablas que se borran ahora es más
  completa (se corrigió un desfase de 16 tablas — mayormente de CRM y
  del motor de secuencias de email — que existía desde antes de esta
  actualización y dejaba tablas huérfanas en un desinstalado completo).
  Si nunca activaste esa opción, esto no te afecta: por defecto el
  plugin nunca borra nada al desinstalar.
- Si administras un piloto institucional, la página nueva de Módulos y
  los perfiles de instalación son el punto de partida — ver
  `docs/CUTOVER-F4.md` si además vas a mover la lectura del LMS a
  tablas propias.
