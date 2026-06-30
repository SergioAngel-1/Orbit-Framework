# mu-plugins — arquitectura

> **Regla clave de WordPress:** `mu-plugins/` auto-carga **solo los `.php` del nivel superior**.
> **No** desciende a subcarpetas. De ahí el patrón de esta carpeta:
>
> - **mu-plugin simple** = un único `.php` en la raíz.
> - **mu-plugin con paquete de clases** = un `.php` *loader* en la raíz **+** una subcarpeta con
>   las clases (las carga el loader, no WordPress).
>
> Mover un `.php` de la raíz a una subcarpeta hace que WordPress **deje de cargarlo**.

Por eso los `hwe-*.php` viven en la raíz aunque "parezcan sueltos": son mu-plugins
independientes, cada uno con su propio concern y su auto-arranque (`add_action`/`add_filter`/
`::register()`). El único que tiene carpeta propia es el Control Center, porque es el único
paquete multi-clase.

## Inventario

| Archivo / carpeta | Tipo | Responsabilidad |
|---|---|---|
| `hwe-control-center.php` | loader | Registra el autoloader PSR-4 de `HWE\ControlCenter\*` y arranca el panel en `init`. |
| `hwe-control-center/` | paquete | Clases del panel: `Schema`, `Storage`, `SecretsStorage`, `RestApi`, `AdminPage`, `Revalidation`, `BackupConfig`, `Walker`, `Walkers/`. Expone `/wp-json/hwe/v1/config`. |
| `hwe-auth.php` | mu-plugin | Endpoints REST `hwe/v1`: reset de contraseña, verificación de email, 2FA (secreto cifrado + códigos de recuperación). |
| `hwe-smtp.php` | mu-plugin | Transporte SMTP de todo el correo (lee config del Control Center; secreto cifrado AES-256-GCM). |
| `external-plugin-update-guard.php` | mu-plugin | Evita falsas actualizaciones de wordpress.org para plugins externos (GitHub), p. ej. la colisión de slug `jwt-auth`. |
| `headless-config.php` | mu-plugin | Bloquea el frontend nativo (redirige a `/wp-admin`) y aplica CORS a `/graphql`. |
| `security.php` | mu-plugin | Hardening: bloquea enumeración de usuarios, pingbacks, oculta versión, etc. |
| `rate-limit.php` | mu-plugin | Rate-limit de `/graphql` y `/wp-json` (XFF de confianza). |
| `graphql-protection.php` | mu-plugin | Límites de profundidad/complejidad de GraphQL e introspección off. |
| `woocommerce-headless.php` | mu-plugin | Ajustes de la Store API (desactiva el nonce; seguro porque el BFF impone Origin + CSRF). |
| `woocommerce-email-branding.php` | mu-plugin | Branding de los emails transaccionales de WooCommerce. |

## Convención para añadir uno nuevo

1. **Concern aislado y simple** → crea `mu-plugins/mi-cosa.php` (en la raíz) que se auto-arranque
   con sus propios `add_action`/`add_filter`.
2. **Varias clases** → crea `mu-plugins/mi-paquete.php` (loader con `spl_autoload_register`) +
   `mu-plugins/mi-paquete/` (las clases). Sigue el modelo de `hwe-control-center`.
3. Nunca dependas de que un archivo de subcarpeta se cargue solo: o lo `require` el loader, o
   está en la raíz.

## ¿Por qué mu-plugins y no plugins normales?

Estos módulos son **infraestructura que debe estar SIEMPRE activa** en un sitio headless:
si alguien los desactivara desde `wp-admin`, se caería el contrato headless (config API,
seguridad, CORS, endpoints de auth). Los *must-use plugins*:

- **No se pueden desactivar** desde el panel (ni por error ni por un cliente).
- **Se cargan siempre y antes** que los plugins normales (orden alfabético), garantizando que
  el hardening y la config estén disponibles desde el primer hook.
- **No reciben auto-updates** de wordpress.org (su versión la fija el repo/deploy).

A cambio: no tienen UI de activación, no hay hooks de activación/desactivación, y —como se
explica arriba— no se auto-cargan desde subcarpetas. Para esta plantilla, esas restricciones
son justamente lo que queremos.
