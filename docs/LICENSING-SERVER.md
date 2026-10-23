# ATORA LMS — Servidor de Licencias (spec de implementación)

El módulo `modules/licensing/class-licensing.php` (cliente) consume un servidor **configurable** (constante `ATORA_LICENSE_SERVER` en `wp-config.php` o ajuste en la pantalla de licencia). **No hay dominio por defecto**.

Además, para habilitar actualizaciones automáticas el sitio cliente debe configurar la clave pública Ed25519 (base64) vía `ATORA_LICENSE_PUBKEY_B64` (recomendado, en `wp-config.php`). Si no hay clave pública, el cliente rechaza cualquier update.

## Endpoints

Namespace: `/wp-json/atora-licensing/v1`

### POST /activate
Body JSON: `{ "license_key": "...", "site_url": "https://cliente.com", "version": "6.2.0" }`

Lógica: validar que la clave existe, no está expirada y tiene cupo de sitios (p. ej. plan Single = 1 sitio, Agency = 10). Registrar `site_url` como activación.

Respuesta 200:
```json
{ "valid": true, "status": "valid", "plan": "Agency", "expires": "2027-07-18", "sites_used": 3, "sites_max": 10 }
```
Errores: `{ "valid": false, "status": "invalid|expired|site_limit", "message": "..." }`

### POST /deactivate
Mismo body. Libera el `site_url` del cupo. Respuesta: `{ "ok": true }`.

### POST /check
Mismo body. Igual que activate pero sin consumir cupo (verificación semanal del cron cliente).

### GET /update?license_key=&site_url=&version=
Si la licencia es válida, responder con la última versión disponible:
```json
{
  "version": "6.3.0",
  "package": "https://tu-servidor.com/releases/atora-lms-6.3.0.zip?token=FIRMADO",
  "signature": "BASE64_ED25519_SHA256(zip)",
  "tested": "6.6",
  "requires_php": "8.1",
  "sections": { "changelog": "<h4>6.3.0</h4><ul><li>...</li></ul>" }
}
```
**Importante:**
- La URL `package` debe ser de corta duración (token HMAC con expiración de 5 min) para que no se comparta públicamente el zip.
- El cliente **verifica** `signature` antes de instalar. La firma es Ed25519 y se calcula sobre el **SHA-256 binario** del zip.

## Modelo de datos mínimo (tabla `licenses`)
| campo | tipo |
|---|---|
| license_key | varchar(64) único, formato `ATORA-XXXX-XXXX-XXXX` |
| customer_email | varchar |
| plan | enum(single, pro, agency, lifetime) |
| sites_max | int |
| expires_at | datetime (null = lifetime) |
| status | enum(active, expired, refunded) |

Tabla `license_activations`: license_key, site_url, activated_at.

## Generación de claves
Al completarse una compra en WooCommerce (hook `woocommerce_order_status_completed`), generar la clave, guardarla y enviarla por email con el enlace de descarga del zip.

## Seguridad
- Rate-limit por IP en /activate y /check (p. ej. 30 req/h).
- Nunca exponer el zip en URL pública sin token.
- Log de activaciones para detectar claves filtradas (misma clave en >N dominios).
- Firmar cada zip con la clave privada Ed25519 y exponer solo la firma (base64) por el endpoint /update.
