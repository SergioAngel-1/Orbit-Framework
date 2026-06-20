# Configuración — Variables de entorno

Todas las variables viven en `.env` (raíz, las consume Docker Compose) y, si
trabajas fuera de Docker, en `frontend/.env.local`. **El repositorio solo contiene
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
| `NEXT_PUBLIC_WORDPRESS_API_URL` | navegador | Endpoint GraphQL público |
| `WORDPRESS_INTERNAL_API_URL` | servidor | Endpoint GraphQL por red interna Docker |

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
| `PAYMENT_PROVIDER` | Pasarela activa: `noop` (sandbox) \| `wompi` \| `payu` \| `bold` |
| `PAYMENT_CURRENCY` | Moneda ISO-4217 (`COP`, `USD`…) |
| `NEXT_PUBLIC_PAYMENT_RETURN_URL` | URL de retorno tras el checkout alojado |
| `NOOP_INTEGRITY_SECRET` | Secreto para firmar webhooks del proveedor `noop` |
| `WOMPI_*`, `PAYU_*`, `BOLD_*` | Credenciales server-only al integrar cada pasarela |

> Para integrar una pasarela real, ver `lib/payments/providers/<nombre>.ts` y el
> procedimiento en `docs/PRODUCTION-PLAN.md` §7.4.

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
