# Escala de notas — decisión vigente

**Estado:** vigente desde antes de 6.26; documentada en 6.29.5. Revisar junto con la edición para colegios.

## La nota global es un entero de 0 a 100

La nota de una entrega, el promedio de un curso y el de un programa son **enteros 0–100** en todo el plugin. No es un descuido de un lugar: es la escala del sistema. Una nota con decimales escrita por el docente se trunca o se redondea al guardarse.

Dónde ocurre:

| Lugar | Archivo | Qué hace |
|---|---|---|
| Campo de nota en SpeedGrader | `includes/grading/trait-grading-speedgrade.php` (`<input … step="1" name="grade">`, y al guardar `(int) round( (float) $grade_raw )`) | Solo admite enteros; un valor con decimales se **redondea**. |
| Nota calculada desde la rúbrica | mismo archivo (`$rubric_pct_ref = (string) (int) round( … * 100 )`) | El porcentaje de la rúbrica se **redondea** a entero. |
| Gradebook (edición en tabla) | `includes/gradebook/class-gradebook-save-service.php` (`absint( $grade )` al validar y al publicar) | Un 87,5 se guarda como 87: se **trunca**. |
| Motor de evaluación | `includes/class-assessment-engine.php` (`publish_submission_grade()` y `get_submission_grade_record()`: `max( 0, min( 100, absint( $grade ) ) )`) | Toda nota que pasa por el motor queda **truncada** a entero. |
| Promedios | `includes/grading/class-grade-average.php` (`CLMS_Grade_Average`) | Promedio redondeado a entero. |

## Lo que sí lleva decimales

- **El puntaje por criterio de la rúbrica** (hasta 2 decimales): se guarda, se muestra y se reenvía sin truncar desde 6.29.4.
- El nivel por criterio se lee con las bandas de SpeedGrader (6.29.3) sobre ese puntaje decimal.

## "Sin notas" no es 0 (6.29.5)

- `null` = el estudiante no tiene notas en ese curso; `0` = tiene una nota de cero.
- Un quiz no intentado o una tarea no calificada (o calificada sin liberar) no cuentan: solo cuentan las notas que existen.
- El promedio del curso combina quizzes y tareas según **cuántas notas hay**, no según el valor del promedio (`CLMS_Grade_Average::combine()`).
- La API móvil (`/grades`, `/courses/{id}/grades`) devuelve `null` y `0` por separado.

## Para revisar más adelante

Pasar la nota global a decimales es un cambio de escala (almacenamiento, validación, gradebook, certificados, informes y exportaciones), no una corrección puntual. Se evaluará junto con la edición para colegios, donde las escalas 1–7 o 1–10 con decimales son habituales.
