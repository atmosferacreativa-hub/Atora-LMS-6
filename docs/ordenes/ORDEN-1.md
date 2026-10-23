# Orden 1 — Publicar plugin 6.33.3 y app 1.0.2 (zoom del material)

Ejecuta las dos partes en orden, sin esperar mi confirmación entre ellas. Guarda esta orden como
`docs/ordenes/ORDEN-1.md` y lleva el avance en `docs/ordenes/PROGRESO-ORDEN-1.md`.

## Reglas
- Todo cambio por PR con CI completo en verde antes de fusionar. El CI de GitHub es la referencia, no
  la suite local.
- `php -l` en cada archivo PHP tocado antes de cada commit (en la 6.33.3 ya aparecieron tres errores
  de sintaxis introducidos por parches).
- Cada arreglo con una prueba que falla antes y pasa después.
- No mover etiquetas publicadas. Etiquetas con "v".
- Prohibido esconder el teclado o saltarse pasos para que una prueba de pantalla pase.
- Continuidad: si el uso se agota, commit y push de la rama en curso (WIP, sin fusionar), actualiza
  PROGRESO-ORDEN-1.md y detente. Si el entorno lo permite, programa la reanudación 5 h 10 min después.

## Parte A — Publicar plugin 6.33.3

La rama `release/6.33.3` (commit a5a1e54) ya está en GitHub, revisada.

1. Abre el PR contra `main` (con `gh pr create` o desde
   https://github.com/atmosferacreativa-hub/Atora-LMS-6/pull/new/release/6.33.3).
2. Deja correr el CI completo. Para cada falla, comprueba si también falla en `main` (6.33.2, cuyo CI
   pasaba entero):
   - si falla solo en la rama, es una regresión: corrígela en la misma rama;
   - si falla también en `main`, repórtala sin tocarla.
3. Si el job de WordPress 7.1.3 falla y no se puede corregir en esta orden: quita 7.1.3 de la matriz,
   deja "Tested up to: 6.8" y repórtalo.
4. Con el CI en verde: fusiona, etiqueta `v6.33.3`, genera el ZIP con `./scripts/build-dist.sh 6.33.3`,
   verifícalo y crea el release en GitHub con el ZIP adjunto. Borra la rama remota.

## Parte B — App 1.0.2: leer cómodo el material

El titular probó la app como estudiante y funciona bien. Falta poder ampliar el material descargado
para leerlo mejor, también sin conexión.

1. **PDF** (visor con pdf.js en WebView, `ResourceViewerScreen`):
   - zoom con dos dedos y doble toque para ampliar o volver;
   - botones + y − y "Ajustar al ancho";
   - desplazamiento horizontal cuando la página ampliada es más ancha que la pantalla;
   - se mantiene el zoom al pasar de página y al girar el teléfono;
   - revisar que el viewport del visor no tenga `user-scalable=no` ni `maximum-scale=1`;
   - funciona igual con el archivo descargado y sin conexión.
2. **Imágenes del material:** visor a pantalla completa con zoom con dos dedos, doble toque y arrastre.
3. **Texto de la lección:** control de tamaño de letra (A− / A+) en la lección, que se recuerda en el
   teléfono y respeta además el tamaño de letra del sistema.
4. **Giro de pantalla:** el visor de PDF e imágenes permite horizontal, aunque el resto de la app siga
   en vertical.
5. **Certificados:** usan el mismo visor, así que heredan el zoom.
6. Pruebas:
   - Jest: el tamaño de letra se guarda y se aplica; el visor de PDF recibe la configuración de zoom.
   - Recorrido de pantalla `material-zoom`: abre un PDF descargado sin conexión, amplía con el botón +,
     comprueba con una captura que la página se ve más grande, y abre una imagen a pantalla completa.
   - Los recorridos existentes siguen en verde.
7. Cierre: 1.0.2, `versionCode` siguiente, changelog, README, `docs/PRUEBA-TELEFONO.md` con las filas
   nuevas (zoom de PDF, imagen, tamaño de letra, giro), etiqueta `v1.0.2`, APK de prueba y AAB. Nada se
   envía a las tiendas.

## Reporte final (máximo 12 líneas)
Parte A: PR, CI, fallas (regresión o preexistente), si pasó WordPress 7.1.3, release y SHA-256 del
ZIP. Parte B: qué se hizo en cada punto, cómo se probó y enlaces a la APK, el AAB y las capturas.
