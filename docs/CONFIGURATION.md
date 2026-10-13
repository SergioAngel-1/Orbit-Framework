# Configuración — Variables de entorno

Todas las variables viven en `.env` (raíz, las consume Docker Compose) y, si
trabajas fuera de Docker, en `frontend/.env.local`.

> ⚡ **Modo híbrido**: WordPress nativo obtiene sus variables de DB de
> `WORDPRESS_DB_*` del entorno (se establecen en `start-local.bat` o
> `start-local.ps1`). MariaDB se expone en `localhost:3307` (no 3306) para
> evitar conflictos con instalaciones locales de MySQL. **El repositorio solo contiene
`.env.example`; nunca subas claves reales.**

> **Regla de oro:** todo lo secreto va **sin** el prefijo `NEXT_PUBLIC_`. Solo las
> variables `NEXT_PUBLIC_*` se incluyen en el bundle del navegador.

## Base de datos (Docker)

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `MYSQL_DATABASE` | Nombre de la BD | `wordpress` |
| `MYSQL_USER` / `MYSQL_PASSWORD` | Credenciales de la BD | `wordpress` |
| `MYSQL_ROOT_PASSWORD` | Clave root de MariaDB | *(aleatoria en prod)* |

## Puertos y URLs

| Variable | Descripción |
|----------|-------------|
| `WORDPRESS_PORT` / `FRONTEND_PORT` | Puertos publicados en el host |
| `WORDPRESS_PUBLIC_URL` | URL pública de WordPress |
| `HEADLESS_FRONTEND_URL` | URL del frontend (redirección headless) |
| `HEADLESS_ALLOWED_ORIGINS` | Allowlist CORS de WPGraphQL (coma-separada) |

## Seguridad / sesión

| Variable | Descripción | Cómo generarla |
|----------|-------------|----------------|
| `GRAPHQL_JWT_AUTH_SECRET_KEY` | Secreto JWT compartido WP↔frontend | `openssl rand -base64 64` |
| `CSRF_SECRET` | Firma de tokens CSRF | `openssl rand -base64 32` |
| `ALLOWED_ORIGIN` | Origen autorizado para escrituras `/api/*` | URL del frontend |
| `JWT_REFRESH_COOKIE_NAME`, `AUTH_COOKIE_NAME` | Nombres de cookie (opc.) | — |
| `REFRESH_TOKEN_MAX_AGE_DAYS` | Vida del refresh token (opc., 30) | — |
| `COOKIE_DOMAIN` | Dominio de cookie para subdominios (opc.) | `.tu-dominio.com` |

## WordPress / GraphQL

| Variable | Lado | Descripción |
|----------|------|-------------|
| `WORDPRESS_INTERNAL_API_URL` | servidor | Endpoint GraphQL por red interna Docker. El navegador no habla con WordPress: ya no hay `NEXT_PUBLIC_WORDPRESS_API_URL` |
| `WORDPRESS_PUBLIC_URL` | servidor (build) | Origen público de WordPress, solo para autorizar sus imágenes (CSP y `next/image`). Opcional en desarrollo |
| `HWE_REVALIDATION_SECRET` | servidor + WordPress | Secreto compartido: firma la revalidación y es el **secreto interno** (`X-HWE-Internal-Secret`) que el BFF presenta en cada llamada a WordPress. Debe coincidir en ambos lados (`define('HWE_REVALIDATION_SECRET', …)` en `wp-config.php`) |
| `HWE_BFF_TRUST_PRIVATE_NETWORK` | WordPress (constante PHP) | Opcional, **desactivada por defecto**. Con `define('HWE_BFF_TRUST_PRIVATE_NETWORK', true)` `hwe-bff-only` vuelve a aceptar como BFF una petición sin `X-Forwarded-For` desde una IP privada. Es una suposición sobre la red, no una credencial: úsala solo en redes cerradas |

## WooCommerce (BFF, server-only)

| Variable | Descripción |
|----------|-------------|
| `WC_API_URL` | REST `wc/v3` (pedidos/clientes) |
| `WC_STORE_API_URL` | Store API `wc/store/v1` (carrito/checkout) |
| `WC_CONSUMER_KEY` / `WC_CONSUMER_SECRET` | Claves `ck/cs` (ver INSTALL §4) |
| `WC_WEBHOOK_SECRET` | Firma HMAC del webhook de revalidación |

## Redis

| Variable | Descripción |
|----------|-------------|
| `REDIS_URL` | `redis://redis:6379` (Docker) o tu Upstash/Redis |
| `RATELIMIT_WINDOW_SECONDS` / `RATELIMIT_MAX_REQUESTS` | Ventana global de rate-limit |

## Pagos (Fase 7 — capa agnóstica)

| Variable | Descripción |
|----------|-------------|
| `PAYMENT_PROVIDER` | Pasarela activa: `noop` (sandbox) \| `avalpay` \| `wompi` \| `payu` \| `bold`. Solo como anulación: manda el panel. |
| `PAYMENT_CURRENCY` | Moneda ISO-4217 (`COP`, `USD`…) |
| `NEXT_PUBLIC_PAYMENT_RETURN_URL` | URL de retorno tras el checkout alojado |
| `NOOP_INTEGRITY_SECRET` | Secreto para firmar webhooks del proveedor `noop` |
| `WOMPI_*`, `PAYU_*`, `BOLD_*` | Credenciales server-only al integrar cada pasarela |

> Para integrar una pasarela real, ver `lib/payments/providers/<nombre>.ts` y el
> procedimiento en `docs/PRODUCTION-PLAN.md` §7.4.

## Panel de control (HWE Config)

La fuente única de los campos es `backend/wp-content/mu-plugins/hwe-control-center/Schema.php`;
los valores de una instancia se siembran con `wp hwe setup` desde
`backend/scripts/instance.config.example.json`. Esto es lo que el panel expone además de
marca/diseño/SEO:

| Grupo | Campos | Notas |
|-------|--------|-------|
| Tienda | `checkout_login_required`, `free_shipping.*` | El login se sincroniza con el ajuste nativo de Woo; el envío gratis lo aplica el módulo `shipping` (`hwe_shipping_free_rule`). |
| Pasarelas de pago | `provider`, `gateway_enabled`, `whatsapp_enabled`, `cash_enabled` (+ credenciales por pasarela) | Lo público lo lee el checkout; los secretos solo salen por `hwe/v1/payments/credentials` (canal interno). El efectivo usa la pasarela nativa «Contra reembolso», que se sincroniza al guardar. |
| Integraciones | `gtm_id`, `meta_pixel_id`, `tiktok_pixel_id`, `hubspot_portal_id`, `consent_retention_years` (+ `meta_capi_*` por canal interno) | Cada id activa su proveedor; vacío = desactivado. |
| Legal → Sedes | 4 sedes (nombre, dirección, Maps, teléfono, horario, coordenadas) | Alimentan el JSON-LD `LocalBusiness` y la página de contacto. |
| GEO | `faq`, `faq_en`, `faq_payments`, `faq_payments_en` | Formato «pregunta \| respuesta». Las `_en` son por bloque completo. |
| Backups | retención, `keep_only_latest`, `exclude_thumbnails`, `include_config_export` | Ver `RUNBOOK.md` y el puente en `BackupConfig.php`. |

## Email transaccional (SMTP)

El envío de correo (reset de contraseña, verificación de email y emails de pedido de
WooCommerce) se configura **desde el panel**, no por variables de entorno:
*wp-admin → Ajustes → HWE Config → Integraciones*.

| Campo | Notas |
|-------|-------|
| Activar envío por SMTP | Interruptor maestro. Si está off, WordPress usa el envío PHP por defecto (poco fiable). |
| SMTP Host / Puerto | P. ej. `email-smtp.eu-west-1.amazonaws.com` / `587`. |
| Cifrado | `STARTTLS` (587), `SSL/TLS` (465) o sin cifrado (solo redes internas). |
| Requiere autenticación | Si el relay exige usuario/contraseña (lo normal). |
| Usuario / Contraseña | La **contraseña se guarda cifrada** (AES-256-GCM); déjala en blanco al guardar para conservarla. |
| Email remitente (From) / Nombre | Usa un dominio con **SPF/DKIM** válidos. |

Tras guardar, usa el botón **“Enviar email de prueba”** del propio panel para validar la
entrega. El transporte lo implementa el mu-plugin `hwe-smtp.php` (hook `phpmailer_init`).

Para gestionar el secreto fuera de la BD (p. ej. en `wp-config.php`), define cualquiera de
estas constantes, que **tienen prioridad** sobre el panel: `HWE_SMTP_ENABLED`, `HWE_SMTP_HOST`,
`HWE_SMTP_PORT`, `HWE_SMTP_ENCRYPTION`, `HWE_SMTP_AUTH`, `HWE_SMTP_USER`, `HWE_SMTP_PASSWORD`,
`HWE_SMTP_FROM`, `HWE_SMTP_FROM_NAME`.

> Entregabilidad: configura **SPF, DKIM y DMARC** en el DNS del dominio remitente. Sin ellos,
> los correos legítimos acaban en spam aunque el SMTP funcione.

## Observabilidad (opcional)

| Variable | Descripción |
|----------|-------------|
| `LOG_LEVEL` | Nivel de pino (`debug`/`info`/`warn`/`error`) |
| `SENTRY_DSN` | DSN de Sentry (si cableas el SDK en `instrumentation.ts`) |
