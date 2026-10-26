# Matriz de compatibilidad

> Versiones con las que esta plantilla está construida y probada (**v2.2**). Mantener estas
> versiones (o superiores dentro del mismo *major*, salvo que se indique) evita sorpresas.
> Para subir un *major*, prueba en *staging* y revisa el `CHANGELOG`. Las versiones de los
> paquetes npm salen de `frontend/package.json`; las de los plugins de WordPress, de
> `backend/scripts/setup.sh`. Si una cifra de aquí no coincide con esos archivos, mandan ellos.

## Infraestructura (Docker)

| Componente | Versión | Notas |
|------------|---------|-------|
| WordPress  | `7.1` (PHP 8.4, Apache) | Imagen `wordpress:7.1-php8.4-apache`. WP-CLI: `wordpress:cli-php8.4`. |
| PHP        | `8.4` (≥ 8.4.27) | Producción y CI (`config.platform.php` = `8.4.0`). El código propio declara `>=8.3`. Requerido por los mu-plugins (tipado, `aes-256-gcm`). |
| MariaDB    | `11.8` LTS | Imagen `mariadb:11.8` (LTS, soporte hasta 2028). |
| Redis      | `8.10` (dev y prod) | Imagen `redis:8.10-alpine` (minor fijado: `8-alpine` es un major flotante). Caché, rate-limit, idempotencia, locks, revocación, anti-replay, 2FA pendiente y estadísticas de consentimiento. **Compartido** con el BFF: WordPress usa el prefijo `hwe-wp:` y borra solo sus claves (ver `RUNBOOK.md`). |
| Caddy      | `2.11`  | Solo producción (`docker-compose.prod.yml`): TLS automático + HTTP/3, sirve `/wp-content/*`. |
| Node.js    | `24.22` LTS | Imágenes `node:24.22-alpine` (minor fijado) y CI. El paquete exige `>=20.18.0`. |
| Alpine     | `3.23`  | Contenedores auxiliares (`backup`, `cache-prune`). |

### Política de etiquetas de imagen

Los tags fijan al menos el **minor** (`node:24.22-alpine`, `redis:8.10-alpine`,
`mariadb:11.8`, `caddy:2.11-alpine`, `wordpress:7.1-php8.4-apache`): dos builds del mismo
commit no pueden usar versiones distintas, y los parches de seguridad dentro del minor siguen
llegando con `docker compose pull`. `wordpress:cli-php8.4` no tiene etiqueta con versión de
WP-CLI para PHP 8.4 en Docker Hub, así que queda como está.

Para una reproducibilidad total en producción se puede fijar el **digest**
(`redis:8.10-alpine@sha256:…`):

```bash
docker buildx imagetools inspect redis:8.10-alpine   # Digest (manifest list)
```

Con digest, `pull` ya no trae parches: el bump es un PR revisable (o se automatiza con un
gestor de dependencias de imágenes). El compose de producción trae `pull_policy: if_not_present`
para que un `up -d` no cambie la base a mitad de un despliegue; actualizar imágenes es un paso
explícito (`docker compose pull`).

## Frontend (npm — ver `frontend/package.json`)

| Paquete | Versión | Rol |
|---------|---------|-----|
| next | `16.4.1` | App Router, RSC, ISR, route handlers (BFF). Turbopack por defecto. Versión exacta: el parche de cabeceras ISR (`scripts/patch-next-isr-headers.mjs`, `postinstall`) comprueba la versión y falla ruidosamente si cambia. |
| react / react-dom | `19.3.0` | UI (ref como prop, sin `forwardRef`). |
| typescript | `6.0.3` | Tipado estricto. |
| tailwindcss / @tailwindcss/postcss | `^4.3.3` | CSS-first (`@theme` en `globals.css`, sin `tailwind.config`). |
| @tailwindcss/typography | `^0.5.20` | Clase `prose` para descripciones del CMS. Su `postcss-selector-parser` va forzado a `^7.1.6` (`overrides`, GHSA-rj75-hqrm-r3gf): comprobado que `prose` se sigue generando; retirar el override cuando el plugin lo suba. |
| next-intl | `^4.14.9` | i18n (routing por locale, mensajes `es`/`en` y los idiomas que declare la instancia). |
| jose | `^6.2.12` | Verificación/firma local de JWT (HS256). |
| ioredis | `5.11.1` | Cliente Redis. |
| otplib | `^13.4.1` | TOTP 2FA (API v13: `generateSecret`, `verify`). |
| qrcode | `^1.5.4` | QR del alta de 2FA, generado en el servidor (ningún tercero ve el secreto). |
| zod | `4.6.7` | Validación de entrada en el BFF (API top-level `z.email()`/`z.url()`). |
| isomorphic-dompurify | `^3.23.0` | Saneado de HTML del CMS. |
| pino | `^10.3.1` | Logging estructurado. |
| @sentry/nextjs | `^10.76.2` | Captura de errores (opcional, vía `SENTRY_DSN`). |

### Dev / tests

| Paquete | Versión |
|---------|---------|
| vitest / @vitest/coverage-v8 | `^4.1.12` |
| @playwright/test | `^1.64.0` |
| @axe-core/playwright | `^4.13.0` |
| @testing-library/react / jsdom | `^16.3.3` / `^30.1.2` |
| eslint / eslint-config-next | `^9.39.5` / `16.4.1` (flat config en `eslint.config.mjs`; eslint-config-next 16 aún no soporta ESLint 10) |
| @vitejs/plugin-react | `^6.1.2` |
| prettier | `^3.9.10` |

## Backend: dependencias de desarrollo (PHP)

Solo para las pruebas (`backend/composer.json`); nada de esto llega a producción.

| Paquete | Versión | Rol |
|---------|---------|-----|
| phpunit/phpunit | `^12.5` | `composer test` (`backend/tests/Unit`). |
| brain/monkey | `^2.7` | Simula funciones y hooks de WordPress sin cargar WordPress. |

## Plugins de WordPress (instalados por `backend/scripts/setup.sh`)

| Plugin | Versión fijada | Variable de override | Notas |
|--------|----------------|----------------------|-------|
| WooCommerce | `11.2.1` | `WOOCOMMERCE_VERSION` | Tienda, Store API (carrito/checkout), wc/v3 (admin). |
| WPGraphQL | `2.25.0` | `WPGRAPHQL_VERSION` | Lectura del catálogo/contenido (GraphQL). v2.x (monorepo). |
| WPGraphQL JWT Authentication | `0.7.2` | — (fijada + checksum SHA256: `JWT_AUTH_SHA256`) | Tokens de sesión; el «user secret» permite `logout-all` (el mu-plugin `hwe-auth` lo hace valer). Última release. |
| WooGraphQL (wp-graphql-woocommerce) | `1.0.3` | `WOOGRAPHQL_WPORG_VERSION` | Tipos de WooCommerce en GraphQL (compatible con WPGraphQL 2.x). Su cabecera declara «WC tested up to 10.4.3»: la combinación con WooCommerce 11.2.x está validada aquí (`verify-integration.sh`, `verify-modules.sh` y la compra de extremo a extremo de `purchase.spec.ts`). No está en wp.org: se instala del release de GitHub, con SHA-256. |
| WPGraphQL CORS | `2.1.1` | — (fijada + `WPGRAPHQL_CORS_SHA256`) | Opcional; el mu-plugin `headless-config.php` ya cubre CORS. |
| Redis Object Cache | `2.8.0` | `REDISCACHE_VERSION` | Caché de objetos de WordPress sobre Redis (con prefijo y flush selectivo). |

Los **módulos del framework** (`hwe-banners`, `hwe-shipping`, `hwe-product-landing`,
`hwe-landings`, `hwe-consent-log`, `hwe-site-reviews`, `hwe-coupon-rules`,
`hwe-customer-identity`, `hwe-external-integration`, `hwe-loyalty`, `hwe-memberships`) son código propio versionado en este
repositorio (`backend/wp-content/plugins/`): no se instalan, se activan (`modules` en
`instance.config.json`). El contrato núcleo ↔ instancia es `HWE_CORE_CONTRACT_VERSION`
(`2.3.0`, `mu-plugins/hwe-instance-loader.php`).

> **Versiones fijadas para reproducibilidad.** `setup.sh` instala estas versiones
> CONCRETAS (no «la última»), definidas como variables al inicio del script y
> overridables por entorno. Si una versión fijada no existiera en wp.org, el
> instalador degrada a la última estable con un aviso (no bloquea el primer
> arranque). Para subir de versión: cambia la variable, prueba en *staging* y
> revisa el `CHANGELOG`. Los plugins de GitHub (JWT, WooGraphQL, CORS) solo se
> instalan si su checksum SHA256 coincide; al subir de versión cambia **siempre**
> versión y checksum a la vez.

## Compatibilidad de navegador

SPA/SSR moderna: navegadores *evergreen* (Chrome/Edge/Firefox/Safari recientes).
La CSP, las cookies `SameSite=Lax` y `Secure` (en prod) requieren HTTPS en producción.
