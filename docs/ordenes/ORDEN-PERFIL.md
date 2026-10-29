# ORDEN · Perfil · Punto 9 · Foto (bloque 1, solo plugin)

## Estado actual (antes de `block/perfil-foto`)

### APIs/funciones usadas

- `get_avatar_url()` y `get_avatar()` (WordPress core) se usan como fuente principal de avatar.
- Existe un meta de usuario **custom** `atora_profile_photo_url` (string URL) que hoy se usa como “foto de perfil” en algunas pantallas web/admin.

### Dónde se lee/usa hoy

**Perfil del estudiante / Mi cuenta (web)**
- `includes/class-student-profile.php` usa `get_avatar_url( $profile_user_id, [size => 120] )` para mostrar la foto del estudiante.
- `includes/frontend/trait-frontend-access-profile.php`:
  - Lee `atora_profile_photo_url`; si está vacío, cae a `get_avatar_url()`.
  - Muestra un enlace explícito a **Gravatar**: “Cambiar foto en Gravatar.com”.
  - Permite editar `atora_profile_photo_url` por URL (campo `clms_profile_photo_url`).

**Ficha del docente**
- `includes/class-instructor.php` muestra `get_avatar( $data['user_id'], 176 )` en el perfil público del docente.
- Además existe un campo/foto específica del docente como adjunto (`photo_id`) en:
  - `includes/class-instructor.php` / `includes/ui/*` usando `_clms_instructor_photo_id` y/o thumbnail del “teacher” (CPT), según el contexto.

**App móvil / REST**
- `includes/mobile/class-mobile-rest-controller.php` devuelve `avatar_url` en `GET /atora-mobile/v1/me` con `get_avatar_url( $user_id, [size => 192] )`.
- `includes/rest/class-rest-extensions-controller.php` devuelve `avatar_url` con `get_avatar_url( $user_id, [size => 96] )` para extensiones REST.

**Admin / widgets**
- `includes/admin-menu/trait-admin-menu-main-pages-academic.php`:
  - Usa `get_avatar( $user_id, 120 )`.
  - También expone `atora_profile_photo_url` como `avatar_url` en algunos listados.
- `includes/admin-menu/trait-admin-menu-widgets-and-hubs.php` usa `get_avatar( $user_id, 48, ..., ['force_display' => true] )`.

**Comentarios (WordPress)**
- WordPress core usa `get_avatar()`/`get_avatar_url()` para autores de comentarios cuando el theme lo imprime.

### Implicación actual

- Si un usuario no tiene `atora_profile_photo_url`, el sitio cae a `get_avatar_url()` de WordPress.
- Por defecto WordPress puede resolver esto via **Gravatar**, lo que implica requests a `gravatar.com`.
- Hay una experiencia inconsistente: URL manual (`atora_profile_photo_url`) vs. avatar core vs. “foto del docente” (`photo_id`).
