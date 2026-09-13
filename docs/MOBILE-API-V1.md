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
| GET | `/courses/{id}` | Bearer + matrícula | Curso, progreso y currículo |
| POST | `/lessons/{id}/complete` | Bearer + matrícula | Completar lección |

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
