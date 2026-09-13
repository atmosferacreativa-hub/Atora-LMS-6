# Configuración de App móvil — ATORA LMS 6.26.0

Este documento prepara la configuración del cliente móvil contra el entorno beta.

Academia beta:

https://beta.academia.atmosferacreativa.com

API móvil esperada:

https://beta.academia.atmosferacreativa.com/wp-json/atora-mobile/v1

## 1) Verificar discovery público

Ejecutar:

```bash
curl -sS https://beta.academia.atmosferacreativa.com/wp-json/atora-mobile/v1/discovery
```

Debe devolver un JSON con al menos:

- `product`
- `api`
- `api_version`
- `lms_version` = `6.26.0`
- `authentication`
- `features`

## 2) Verificar permalinks y REST

1. Abrir en navegador:
   - `https://beta.academia.atmosferacreativa.com/wp-json/`
2. Confirmar:
   - No hay redirecciones inesperadas.
   - No hay 403/406 por WAF.
   - La ruta `atora-mobile/v1` está registrada.

## 3) Confirmar HTTPS real

1. Confirmar certificado válido.
2. Confirmar que el endpoint móvil no responde por HTTP plano.

## 4) Cuenta de prueba (sin credenciales en documentos)

Preparar en beta:

1. Usuario de prueba (email).
2. Usuario matriculado en al menos 1 curso publicado.
3. Curso con correspondencia válida:
   - CPT `lm_course` publicado.
   - fila en tablas ATORA (course_id válido).
4. Lecciones publicadas en ese curso.
5. Quiz habilitado en una lección (si se va a probar quiz).

No guardar contraseñas reales en este documento.

## 5) Casos mínimos a validar (API móvil)

Matrículas:

- Usuario con matrícula legacy.
- Usuario con matrícula en tablas.
- `active`.
- `completed`.
- Mezcla + deduplicación por `course_id`.
- Usuario sin matrícula.

Autorización:

- Usuario intentando abrir un curso ajeno (debe ser 403).
- Lección de otro curso (debe ser 403).
- Completar lección sin acceso (debe ser 403).

Sesión/tokens:

- Sesión expirada.
- Refresh token rotado.
- Token revocado.

## 6) Configuración de la app (atora-mobile)

Variable de entorno requerida:

EXPO_PUBLIC_ATORA_API_URL=https://beta.academia.atmosferacreativa.com/wp-json/atora-mobile/v1

Notas:

- No uses Markdown, corchetes ni paréntesis para asignar la variable.
- La API base debe ir sin slash final.

