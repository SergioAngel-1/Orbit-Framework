# Matriz de compatibilidad

> Versiones con las que esta plantilla está construida y probada. Mantener estas
> versiones (o superiores dentro del mismo *major*, salvo que se indique) evita
> sorpresas. Para subir un *major*, prueba en *staging* y revisa el `CHANGELOG`.

## Infraestructura (Docker)

| Componente | Versión | Notas |
|------------|---------|-------|
| WordPress  | `6.7` (PHP 8.3, Apache) | Imagen `wordpress:6.7-php8.3-apache`. |
| PHP        | `8.3`   | Requerido por la imagen y los mu-plugins (tipado, `aes-256-gcm`). |
| MariaDB    | `11.4`  | Imagen `mariadb:11.4`. |
| Redis      | `7` (dev) / `7.4` (prod) | Cache, rate-limit, idempotencia, locks, revocación, anti-replay. |
| Caddy      | `2.9`   | Solo producción (`docker-compose.prod.yml`): TLS automático + HTTP/3. |
| Node.js    | `20`    | Build/runtime del frontend y CI. |

## Frontend (npm — ver `frontend/package.json`)

| Paquete | Versión | Rol |
|---------|---------|-----|
| next | `15.1.6` | App Router, RSC, ISR, route handlers (BFF). |
| react / react-dom | `19.0.0` | UI. |
| typescript | `5.7.3` | Tipado estricto. |
| tailwindcss | `^4.0.0` | CSS-first (`@theme` en `globals.css`, sin `tailwind.config`). |
| @tailwindcss/postcss | `^4` | Pipeline PostCSS de Tailwind v4. |
| @tailwindcss/typography | `^0.5.20` | Clase `prose` para descripciones del CMS. |
| next-intl | `^4.13.0` | i18n (routing por locale, mensajes es/en). |
| jose | `5.9.6` | Verificación/firma local de JWT (HS256). |
| ioredis | `5.4.2` | Cliente Redis. |
| otplib | `^13.4.1` | TOTP 2FA (API v13: `generateSecret`, `verify`). |
| zod | `3.24.1` | Validación de entrada en el BFF. |
| isomorphic-dompurify | `2.20.0` | Saneado de HTML del CMS. |
| pino | `^9.6.0` | Logging estructurado. |
| @sentry/nextjs | `^8.55.2` | Captura de errores (opcional, vía `SENTRY_DSN`). |

### Dev / tests

| Paquete | Versión |
|---------|---------|
| vitest | `^2.1.8` |
| @playwright/test | `^1.49.1` |
| @axe-core/playwright | `^4.11.3` |
| eslint / eslint-config-next | `9.18.0` / `15.1.6` |
| prettier · husky · lint-staged | `^3.4.2` · `^9.1.7` · `^15.3.0` |

## Plugins de WordPress (instalados por `backend/scripts/setup.sh`)

| Plugin | Versión fijada | Variable de override | Notas |
|--------|----------------|----------------------|-------|
| WooCommerce | `9.4.3` | `WOOCOMMERCE_VERSION` | Tienda, Store API (carrito/checkout), wc/v3 (admin). |
| WPGraphQL | `1.30.0` | `WPGRAPHQL_VERSION` | Lectura del catálogo/contenido (GraphQL). |
| WPGraphQL JWT Authentication | `0.7.2` | — (fijada + checksum SHA256) | Tokens de sesión; el "user secret" permite `logout-all`. |
| WooGraphQL (wp-graphql-woocommerce) | `1.0.2` | `WOOGRAPHQL_WPORG_VERSION` | Tipos de WooCommerce en GraphQL (fallback a release de GitHub con checksum). |
| WPGraphQL CORS | `2.1.1` | — (fijada) | Opcional; el mu-plugin ya cubre CORS. |
| Redis Object Cache | `2.5.4` | `REDISCACHE_VERSION` | Caché de objetos de WordPress sobre Redis. |

> **Versiones fijadas para reproducibilidad.** `setup.sh` instala estas versiones
> CONCRETAS (no "la última"), definidas como variables al inicio del script y
> overridables por entorno. Si una versión fijada no existiera en wp.org, el
> instalador degrada a la última estable con un aviso (no bloquea el primer
> arranque). Para subir de versión: cambia la variable, prueba en *staging* y
> revisa el `CHANGELOG`. Los plugins de GitHub (JWT, WooGraphQL, CORS) verifican
> checksum SHA256 cuando está disponible.

## Compatibilidad de navegador

SPA/SSR moderna: navegadores *evergreen* (Chrome/Edge/Firefox/Safari recientes).
La CSP, las cookies `SameSite=Lax` y `Secure` (en prod) requieren HTTPS en producción.
