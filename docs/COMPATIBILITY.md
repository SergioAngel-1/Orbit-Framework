# Matriz de compatibilidad

> Versiones con las que esta plantilla está construida y probada. Mantener estas
> versiones (o superiores dentro del mismo *major*, salvo que se indique) evita
> sorpresas. Para subir un *major*, prueba en *staging* y revisa el `CHANGELOG`.

## Infraestructura (Docker)

| Componente | Versión | Notas |
|------------|---------|-------|
| WordPress  | `7.0` (PHP 8.4, Apache) | Imagen `wordpress:7.0-php8.4-apache`. |
| PHP        | `8.4`   | Requerido por la imagen y los mu-plugins (tipado, `aes-256-gcm`). |
| MariaDB    | `11.8` LTS | Imagen `mariadb:11.8` (LTS, soporte hasta 2028). |
| Redis      | `8` (dev y prod) | Cache, rate-limit, idempotencia, locks, revocación, anti-replay. |
| Caddy      | `2.11`  | Solo producción (`docker-compose.prod.yml`): TLS automático + HTTP/3. |
| Node.js    | `24` LTS | Build/runtime del frontend y CI. Imagen `node:24-alpine`. |

## Frontend (npm — ver `frontend/package.json`)

| Paquete | Versión | Rol |
|---------|---------|-----|
| next | `16.2.9` | App Router, RSC, ISR, route handlers (BFF). Turbopack por defecto. |
| react / react-dom | `19.2.7` | UI (ref como prop, sin `forwardRef`). |
| typescript | `6.0.3` | Tipado estricto. |
| tailwindcss | `^4.3.1` | CSS-first (`@theme` en `globals.css`, sin `tailwind.config`). |
| @tailwindcss/postcss | `^4.3.1` | Pipeline PostCSS de Tailwind v4. |
| @tailwindcss/typography | `^0.5.20` | Clase `prose` para descripciones del CMS. |
| next-intl | `^4.13.0` | i18n (routing por locale, mensajes es/en). |
| jose | `6.2.3` | Verificación/firma local de JWT (HS256). |
| ioredis | `5.11.1` | Cliente Redis. |
| otplib | `^13.4.1` | TOTP 2FA (API v13: `generateSecret`, `verify`). |
| zod | `4.4.3` | Validación de entrada en el BFF (API top-level `z.email()`/`z.url()`). |
| isomorphic-dompurify | `3.18.0` | Saneado de HTML del CMS. |
| pino | `^10.3.1` | Logging estructurado. |
| @sentry/nextjs | `^10.59.0` | Captura de errores (opcional, vía `SENTRY_DSN`). |

### Dev / tests

| Paquete | Versión |
|---------|---------|
| vitest | `^4.1.9` |
| @playwright/test | `^1.61.0` |
| @axe-core/playwright | `^4.11.3` |
| eslint / eslint-config-next | `10.5.0` / `16.2.9` (flat config `eslint.config.mjs`) |
| @eslint/eslintrc | `^3.3.5` (compat para `extends` en flat config) |
| @vitejs/plugin-react | `^6.0.2` |
| prettier · husky · lint-staged | `^3.8.4` · `^9.1.7` · `^17.0.8` |

## Plugins de WordPress (instalados por `backend/scripts/setup.sh`)

| Plugin | Versión fijada | Variable de override | Notas |
|--------|----------------|----------------------|-------|
| WooCommerce | `10.8.1` | `WOOCOMMERCE_VERSION` | Tienda, Store API (carrito/checkout), wc/v3 (admin). |
| WPGraphQL | `2.16.0` | `WPGRAPHQL_VERSION` | Lectura del catálogo/contenido (GraphQL). v2.x (monorepo). |
| WPGraphQL JWT Authentication | `0.7.2` | — (fijada + checksum SHA256) | Tokens de sesión; el "user secret" permite `logout-all`. Última release. |
| WooGraphQL (wp-graphql-woocommerce) | `1.0.2` | `WOOGRAPHQL_WPORG_VERSION` | Tipos de WooCommerce en GraphQL (compatible con WPGraphQL 2.x). |
| WPGraphQL CORS | `2.1.1` | — (fijada) | Opcional; el mu-plugin ya cubre CORS. |
| Redis Object Cache | `2.7.0` | `REDISCACHE_VERSION` | Caché de objetos de WordPress sobre Redis. |

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
