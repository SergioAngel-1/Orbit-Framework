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
| `hwe-control-center/` | paquete | Clases del panel: `Schema`, `Storage`, `SecretsStorage`, `RestApi`, `AdminPage` (render), `AdminSave` (guardado), `AdminNotices`, `Revalidation`, `BackupConfig`, `BackupActions`, `BackupNotify`, `Walker`, `Walkers/`, `FieldTypes/` (registro y tipos), `Modules`, sincronizaciones con Woo y regla de envío gratis, APIs internas de pagos/integraciones. Expone `/wp-json/hwe/v1/config`. |
| `hwe-instance-loader.php` | mu-plugin | Cargador del código de la **instancia**: hace `require` de los `.php` de primer nivel de `instance/` en orden alfabético (un archivo roto se registra y no tumba el sitio) y define `HWE_CORE_CONTRACT_VERSION`. |
| `instance/` | carpeta de la instancia | **Del usuario de la instancia; el framework nunca la toca** (solo versiona su `README.md`, que explica el contrato de hooks). |
| `hwe-auth.php` | mu-plugin | Endpoints REST `hwe/v1`: reset de contraseña, verificación de email, 2FA (secreto cifrado + códigos de recuperación). |
| `hwe-smtp.php` | mu-plugin | Transporte SMTP de todo el correo (lee config del Control Center; secreto cifrado AES-256-GCM). |
| `external-plugin-update-guard.php` | mu-plugin | Evita falsas actualizaciones de wordpress.org para plugins externos (GitHub), p. ej. la colisión de slug `jwt-auth`. |
| `headless-config.php` | mu-plugin | Bloquea el frontend nativo (redirige a `/wp-admin`) y aplica CORS a `/graphql`. |
| `security.php` | mu-plugin | Hardening: bloquea enumeración de usuarios, pingbacks, oculta versión, etc. |
| `hwe-bff-only.php` | mu-plugin | GraphQL, la Store API (`wc/store`) y `hwe/v1/auth|consent` solo atienden al BFF: exige el secreto interno (`X-HWE-Internal-Secret` = `HWE_REVALIDATION_SECRET`, comparación en tiempo constante) o WP-CLI; si no, 404 por ruta y por parámetro. Falla cerrado sin secreto configurado. |
| `rate-limit.php` | mu-plugin | Rate-limit de `/graphql` y `/wp-json` (XFF de confianza). |
| `graphql-protection.php` | mu-plugin | Límites de profundidad/complejidad de GraphQL e introspección off. |
| `woocommerce-headless.php` | mu-plugin | Ajustes de la Store API (desactiva el nonce; seguro porque el BFF impone Origin + CSRF). Pasarelas headless siempre disponibles (`hwe_offline`, `hwe_whatsapp_link`) y aviso si COD/BACS siguen activos en producción. |
| `hwe-post-revalidate.php` | mu-plugin | Revalida el tag ISR `posts` al guardar entradas, etiquetas o categorías del blog. |
| `hwe-menu-revalidate.php` | mu-plugin | Revalida el tag ISR `menus` al editar menús (una sola vez por petición). |
| `hwe-post-seo.php` | mu-plugin | Título/descripción SEO por entrada + columna en el listado + `Post.seoTitle`/`seoDescription` en GraphQL. |
| `hwe-page-seo.php` | mu-plugin | Idem por página (`Page.seoTitle`/`seoDescription`). |
| `hwe-product-seo.php` | mu-plugin | Idem por producto (`Product.seoTitle`/`seoDescription`). |
| `hwe-seo/` | carpeta | `fields.php`: caja, guardado, contador, columna y registro GraphQL comunes a los cuatro `hwe-*-seo.php` (que la incluyen con `require_once`). |
| `hwe-category-seo.php` | mu-plugin | Idem por categoría de producto (`ProductCategory.seoTitle`/`seoDescription`). |
| `hwe-featured-post.php` | mu-plugin | Selector manual de post destacado (`RootQuery.hweFeaturedPost`). |
| `hwe-login-slug.php` | mu-plugin | Con `HWE_LOGIN_SLUG`, el login del panel pasa a `/<slug>` y `/wp-login.php` (y `/wp-admin` sin sesión) responde 404. Sin la constante, no actúa. |
| `hwe-svg-uploads.php` | mu-plugin | Subida de SVG a la medioteca con saneado (lista de permitidos para enlaces, sin scripts, manejadores ni animaciones que reescriban enlaces). |
| `hwe-space-hygiene.php` | mu-plugin | Al guardar, cambia los espacios duros entre palabras por normales (títulos, cuerpo de entradas y metas del filtro `hwe_hygiene_meta_keys`). |
| `hwe-link-hygiene.php` | mu-plugin | Al guardar, quita `utm_source=chatgpt.com` (lista ampliable con `hwe_link_hygiene_utm_sources`) de los enlaces. |
| `hwe-media-reference.php` | mu-plugin | Casilla «Imagen de referencia» en la medioteca → `MediaItem.isReferenceImage`. |
| `hwe-admin-product-image.php` | mu-plugin | Lista de productos: filtro «Sin imagen / Con imagen» y columna de imagen ordenable. |
| `hwe-guest-order-badge.php` | mu-plugin | Marca «Invitado» en los pedidos sin cuenta (listado y ficha). |
| `hwe-best-sellers.php` | mu-plugin | `GET hwe/v1/best-sellers` (secreto interno): ranking desde las líneas de pedidos pagados. |
| `hwe-order-tracking.php` | mu-plugin | Transportadora, guía y enlace de seguimiento en el pedido (`_carrier`, `_tracking_number`, `_tracking_url`). |
| `hwe-contact.php` | mu-plugin | `POST hwe/v1/contact` (secreto interno): entrega por email el formulario de contacto del BFF. |
| `hwe-email-chrome.php` | mu-plugin | Marco de marca de los correos (colores y fuentes del Control Center), texto por idioma (`hwe_email_copy`, ampliable con el filtro del mismo nombre) y enlaces al frontend. Lo usan las plantillas de `themes/hwe-headless-base/woocommerce/emails/` y los correos de `hwe-auth.php`. |
| `hwe-email-test.php` | mu-plugin | Control Center → Integraciones: envía o previsualiza cualquier plantilla de correo con un pedido de ejemplo (en memoria) y el destinatario forzado. |

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
