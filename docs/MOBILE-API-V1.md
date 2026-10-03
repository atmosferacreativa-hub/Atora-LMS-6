# ATORA Mobile API v1

Namespace: `/wp-json/atora-mobile/v1`

## Seguridad

- HTTPS obligatorio salvo entorno WordPress `local` o `ATORA_DEV_MODE`.
- Access tokens opacos de 15 minutos.
- Refresh tokens opacos de 30 días.
- Rotación del refresh token en cada renovación.
- Revocación inmediata por sesión.
- Máximo de cinco sesiones activas por usuario.
- Solo se almacenan hashes de los secretos.
- Login limitado por combinación de usuario e IP resuelta por `ATORA_Client_IP`.
- Las rutas académicas fuerzan ownership mediante la matrícula del usuario actual.

## Endpoints

| Método | Ruta | Autenticación | Uso |
|---|---|---|---|
| GET | `/discovery` | Pública | Detectar instancia, versión y capacidades |
| POST | `/auth/login` | Pública, limitada | Iniciar sesión y emitir tokens |
| POST | `/auth/refresh` | Refresh token | Rotar la sesión |
| POST | `/auth/logout` | Bearer | Revocar la sesión actual |
| GET | `/me` | Bearer | Perfil del usuario |
| GET | `/dashboard` | Bearer | Resumen y cursos del estudiante |
| GET | `/courses` | Bearer | Matrículas del estudiante |
| GET | `/courses/{id}` | Bearer + matrícula | Curso, progreso y currículo (cada lección con `has_video` y `video_thumbnail_url`) |
| GET | `/programs` | Bearer | Programas del estudiante |
| GET | `/programs/{id}` | Bearer + matrícula | Programa y sus cursos |
| GET | `/lessons/{id}` | Bearer + matrícula | Contenido de la lección (`quiz_available`, `assignment_available`, `video_thumbnail_url`) |
| GET, POST | `/lessons/{id}/quiz` | Bearer + matrícula | Consultar y responder la evaluación |
| POST | `/lessons/{id}/complete` | Bearer + matrícula | Completar lección |
| GET | `/assignments/{lesson_id}` | Bearer + matrícula | Consigna, límites y entregas propias (6.27.0) |
| POST | `/assignments/{lesson_id}/submissions` | Bearer + matrícula | Crear intento de entrega (6.27.0) |
| POST | `/uploads/sessions` | Bearer + matrícula | Iniciar subida reanudable (6.27.0) |
| PUT | `/uploads/{upload_token}` | Bearer, dueño del token | Subir fragmento con `Content-Range` (6.27.0) |
| POST | `/uploads/{upload_token}/complete` | Bearer, dueño del token | Cerrar subida y validar tipo real (6.27.0) |

Entregas: contrato completo en `docs/CONTRATO-ENTREGAS-MOVIL.md`; reglas de sincronización en `docs/SINCRONIZACION-OFFLINE.md`. `GET /discovery` declara `capabilities.assignments: true` desde 6.27.0.

## Login

```json
{
  "login": "estudiante@example.com",
  "password": "secreto",
  "device_name": "iPhone de Carlos"
}
```

La contraseña solo se usa para autenticar contra WordPress y nunca se almacena en la sesión móvil.

## Autorización

```http
Authorization: Bearer {access_token}
```

## Renovación

```json
{
  "refresh_token": "{refresh_token}",
  "device_name": "iPhone de Carlos"
}
```

La renovación invalida access y refresh tokens anteriores de esa sesión.

## Miniaturas de video (6.27.1)

`video_thumbnail_url` se resuelve en este orden: miniatura manual del editor de la lección → YouTube → Vimeo (en caché; la primera vez se consulta en segundo plano) → imagen destacada de la lección → portada del curso. Google Drive y MP4 directo no tienen miniatura desde el servidor: la app puede generar una localmente. Nunca viene vacía si el curso tiene portada.
