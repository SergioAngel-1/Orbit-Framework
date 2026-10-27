# Seguridad — Modelo de amenazas y guía de hardening

## Modelo de amenazas

El sistema asume un **atacante con capacidad de red** (MITM, CSRF, replay) y un
**atacante con acceso parcial al cliente** (XSS en otros sitios, pero no inyección
directa en el dominio de la tienda). No se protege contra un atacante con acceso al
servidor o que controle la red interna de la plataforma de despliegue.

El principio que lo ordena todo: **el navegador solo habla con el BFF** (Next.js), y
WordPress **solo atiende al BFF**. Los secretos viven en el servidor y toda decisión de
seguridad se toma ahí, **fallando cerrado** cuando falta una pieza.

| Amenaza | Mitigación | Dónde |
|---------|-----------|-------|
| Acceso directo a WordPress (GraphQL, Store API, `hwe/v1/auth\|consent`) saltándose el BFF | `hwe-bff-only`: 404 salvo `X-HWE-Internal-Secret` (comparación en tiempo constante) o WP-CLI; todo el BFF lo envía vía un único cliente (`wpFetch`/clientes de GraphQL, wc/v3 y Store API); un test impide llamadas a WordPress por otra vía | `mu-plugins/hwe-bff-only.php`, `lib/wordpress/internal-fetch.ts` |
| Robo de credenciales WooCommerce | Proxy BFF, `server-only`, env sin `NEXT_PUBLIC_` | `lib/woocommerce/*` |
| XSS → robo de sesión | JWT en cookie `httpOnly`, CSP, `sanitizeHtml` para HTML del CMS (fuerza `rel="noopener noreferrer"`), `safeJsonLd`, tokens de diseño validados por tipo antes de inyectarse | `next.config.mjs`, `lib/security/sanitize.ts` |
| SVG subido con script | Allowlist de MIME y saneado al subir (sin scripts, handlers, entidades externas, `href` con esquemas peligrosos ni animaciones SMIL que los cambien); solo usuarios con `upload_files` | `mu-plugins/hwe-svg-uploads.php` |
| CSRF en escrituras | Token firmado (double-submit) + verificación Origin; lista **cerrada** de rutas firmadas exentas | `lib/api/guard.ts`, `lib/security/signed-routes.ts` |
| Fuerza bruta en login | Rate-limit por IP en el BFF y en `wp-login.php` (5 intentos/5 min); el cubo se elige por la **ruta parseada**, así que ninguna query (`?x=/wp-json/`, `?x=/graphql`, `?rest_route=`…) mueve el intento a otro presupuesto. El login con 2FA pendiente tiene tope de intentos | `lib/security/rate-limit.ts`, `rate-limit.php` |
| Abuso de la API de WordPress | Rate-limit por IP en `/graphql` (60/min) y `/wp-json/` (120/min). Las llamadas del **BFF** (secreto interno válido) **no gastan** ese presupuesto: en producción llegan por la red interna con la IP del contenedor del frontend, compartida por todos los compradores; su abuso lo acota el rate-limit del BFF (p. ej., el listado público `GET /api/store/products` tiene 60/min por IP sobre **todo** el GET y acota `first` antes de consultar WordPress). Sin la cabecera, con un secreto incorrecto o sin `HWE_REVALIDATION_SECRET` se cuentan como cualquier otra petición. `wp-login.php` se cuenta siempre | `mu-plugins/rate-limit.php`, `mu-plugins/hwe-bff-secret.php`, `app/api/store/products/route.ts` |
| Agotamiento de memoria por cuerpos enormes | El rate-limit cuenta peticiones, no bytes. Todo cuerpo del BFF tiene tope: **64 KiB** en JSON de escritura y **256 KiB** en webhooks firmados, comprobado por `Content-Length` y por conteo real del stream; al pasarse se cancela la lectura y se responde **413** antes de hashear o tocar WordPress. Caddy añade 1 MB para `/api/*`, separado de los 130 MB de medios | `lib/api/body.ts`, `lib/api/guard.ts`, `Caddyfile` |
| Robo de credenciales antes del segundo factor | El login con 2FA no entrega tokens: las credenciales se quedan en Redis hasta verificar (id opaco de un solo uso, `GETDEL`); sin Redis falla cerrado | `lib/auth/pending-login.ts` |
| Secreto TOTP en reposo / en tránsito | AES-256-GCM con clave dedicada (`HWE_2FA_KEY`, sin clave de reserva); el QR lo genera el BFF, ningún tercero lo ve; recuperación con códigos hasheados | `mu-plugins/hwe-auth/` |
| Sesión robada o cuenta comprometida | Blocklist de access tokens, `logout-all` que rota el secreto del usuario, cambio **y reset** de contraseña que cierran las demás sesiones (también el reset nativo de `wp-login.php`, vía la acción `wp_set_password`), y un verificador que **solo acepta access tokens**: un refresh token presentado en la cookie de acceso se rechaza, así que no sobrevive a esas revocaciones | `lib/auth/jwt.ts`, `lib/auth/revocation.ts`, `hwe-auth.php` |
| Duplicación de pedidos | Clave de idempotencia (Redis): un duplicado detectado no vuelve a proceder aunque Redis falle después; sin Redis (o si falla la reserva) el checkout sigue y queda registrado como `error` | `lib/security/idempotency.ts` |
| Pago falsificado | Confirmación solo por webhook firmado, con importe y moneda verificados; anti-replay; la pasarela de pruebas (`noop`) no existe en producción: no se activa ni atiende webhooks, sea cual sea la configuración | `lib/payments/*`, `lib/security/replay.ts` |
| Webhook falso | HMAC-SHA256 con secreto compartido, un único verificador, falla cerrado con secreto vacío | `lib/security/webhook.ts` |
| IDOR (otro usuario ve mis datos) | Autorización por propietario en handlers; el pedido de invitado solo con su cookie `order_key` | `lib/woocommerce/client.ts`, `lib/checkout/` |
| Redirecciones abiertas | `returnUrl` de pagos solo del propio sitio; `?return=` con `safeInternalPath` | `lib/navigation/safe-return.ts` |
| Enumeración de usuarios WP | `/wp-json/wp/v2/users` bloqueado + `?author=N`, errores de login genéricos | `mu-plugins/security.php` |
| Integrador externo con demasiado poder | Módulo `external-integration`: IP exacta de una lista, contraseña de aplicación solo en GraphQL, rol mínimo, política **cerrada** de operaciones y campos (suelos que ningún filtro rebaja) | `plugins/hwe-external-integration/` |
| Consentimiento no demostrable (RGPD) | Registro del consentimiento con comprobante (módulo `consent-log`), IP truncada, retención configurable | `plugins/hwe-consent-log/` |
| Configuración con secretos por defecto | Guard de arranque: aborta en producción con secretos por defecto, cortos o `ALLOWED_ORIGIN` mal formado | `lib/security/secret-guard.ts` |
| Fuga de secretos en el repo | Solo `.env.example`, gitleaks en CI | `.gitleaks.toml`, `.github/workflows/ci.yml` |
| Datos de una instancia mezclados con el framework | Puerta anti-instancia en CI (regla de frontera en ESLint + grep) | `.github/scripts/check-anti-instance.sh` |

## Responsabilidades del cliente

El Licenciatario es responsable de:

1. **Generar secretos propios** — No usar los del `.env.example` (`generate-secrets.sh`).
2. **HTTPS en producción** — Terminación TLS antes del proxy inverso (Caddy lo hace).
3. **Copias de seguridad** — Verificar que las del contenedor `backup` salen **fuera del
   servidor** y probar la restauración (`docs/RUNBOOK.md`).
4. **Protección de datos (RGPD)** — Configurar el banner de consentimiento, la
   política de cookies y las páginas legales antes de lanzar.
5. **Pasarela de pago** — Verificar que la integración sigue las guías de seguridad
   del proveedor y que el webhook está firmado.
6. **Actualizaciones** — Mantener al día WordPress, plugins y dependencias npm
   (`npm run audit:gate` en CI; matriz en `docs/COMPATIBILITY.md`).
7. **Monitorización** — Cablear Sentry (u observabilidad equivalente) y revisar
   logs periódicamente (`docs/OBSERVABILITY.md`).
8. **Integraciones externas** — Si enciende `external-integration`: IPs mínimas,
   contraseña de aplicación rotada y política revisada (`MANUAL.md` del plugin).

## Checklist de hardening

- [ ] Secretos generados con `openssl rand` y puestos en `.env` (incluidas `HWE_SECRETS_KEY` y `HWE_2FA_KEY`, estables)
- [ ] `ALLOWED_ORIGIN` exactamente igual al `Origin` del dominio real
- [ ] HTTPS forzado (HSTS + redirect 80→443)
- [ ] Cabeceras verificadas (`securityheaders.com` ≥ A)
- [ ] Webhooks configurados con secreto y prueba de firma
- [ ] Pasarela de pago **real** configurada en producción (sin `noop`: el arranque lo rechaza y su webhook responde 404)
- [ ] Rate-limit con Redis activo y umbrales ajustados
- [ ] Tope de cuerpo del BFF activo (413 por encima de 64 KiB JSON / 256 KiB webhooks) y `/api/*` limitado a 1 MB en Caddy
- [ ] Cookies `Secure` + `SameSite` en producción
- [ ] CSP revisada (`img-src` incluye `WORDPRESS_PUBLIC_URL`; `connect-src` solo `'self'`: el navegador no habla con WordPress; `CSP_ANALYTICS_PROVIDERS` si no usas todos los proveedores)
- [ ] `HWE_REVALIDATION_SECRET` idéntico en WordPress y frontend (secreto interno de `hwe-bff-only`)
- [ ] `WP_ENVIRONMENT_TYPE=production` (apaga la introspección de GraphQL) y `WP_ADMIN_PASSWORD` propia
- [ ] Login movido (`HWE_LOGIN_SLUG`) o `wp-admin` tras IP allowlist / WAF
- [ ] `HWE_INTEGRATION_ALLOWED_IPS` vacío salvo que uses `external-integration`
- [ ] `sh backend/scripts/verify-security.sh --strict` en verde contra la pila real
- [ ] `sh backend/scripts/verify-modules.sh` en verde (cada módulo apagado desaparece del todo)
- [ ] Backups programados, copiados fuera del servidor y probados
- [ ] `lint`, `type-check`, `test`, `build` y `phpunit` verdes en CI

## Verificar la pila con `verify-security.sh`

Los tests unitarios simulan `fetch` y `$_SERVER`: no pueden decir si la pila **real** está cerrada.
`backend/scripts/verify-security.sh` lo comprueba con tráfico HTTP de verdad contra la pila en marcha
(dev o prod). Es idempotente (no cambia nada) y no imprime ningún secreto.

```bash
WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 \
HWE_REVALIDATION_SECRET=… WC_WEBHOOK_SECRET=… \
VERIFY_2FA_USERNAME=… VERIFY_2FA_PASSWORD=… \
PUBLIC_URL=https://tu-dominio.com \    # solo en producción, tras Caddy
  sh backend/scripts/verify-security.sh --strict
```

Qué comprueba:

1. **WordPress solo atiende al BFF** (`hwe-bff-only`): GraphQL, la Store API y `hwe/v1/auth` responden
   **404** sin el secreto interno, por ruta y por parámetro (`/?graphql`, `?rest_route=`) y con un
   secreto incorrecto. Incluye un **control positivo**: con el secreto correcto GraphQL sí responde; sin
   él, los 404 de una pila rota parecerían «cerrada».
2. **Webhooks y barrera de Origin**: uno firmado (HWE y WooCommerce) atraviesa el proxy sin `Origin`; uno
   sin firma o con firma falsa se rechaza; una escritura normal sin `Origin` ni CSRF se rechaza.
3. **Login con 2FA**: la respuesta pide el segundo factor y no trae tokens ni cookies de sesión. Necesita un
   usuario con 2FA activo (se activa desde la cuenta; no se puede automatizar sin TOTP).
4. **Cabeceras**: CSP (sin `api.qrserver.com`), `nosniff`, `X-Frame-Options`, `Referrer-Policy`,
   `Permissions-Policy`, sin `X-Powered-By`; con `PUBLIC_URL`, las de las rutas que Caddy sirve directo desde
   WordPress.

Lo que falta un secreto o un usuario se **omite y se dice** (`(omitida)`); con `--strict` omitir cuenta como
fallo (úsalo en la aceptación de una release). Salida: `0` todo bien · `1` algún fallo · `2` la pila no
responde. El propio script se prueba contra una pila de pega con cada fuga posible
(`backend/tests/Unit/Scripts/VerifySecurityTest.php`): un verificador que nunca falla no verifica nada.

## Otras verificaciones contra la pila

| Script | Para qué |
|--------|----------|
| `backend/scripts/verify-modules.sh` | Que un módulo apagado no deja rutas ni configuración abiertas, y que cada plugin de módulo está completo y montado. |
| `backend/scripts/verify-integration.sh` | La puerta de red, el rol, la contraseña de aplicación y la política cerrada del módulo `external-integration`. Crea y borra un usuario y un pedido de prueba. |

Ambos se prueban a sí mismos con binarios falsos (`backend/tests/Unit/Scripts/`). Lo que
**no** se puede verificar en local —que Caddy vea la IP real del cliente o que la cabecera
`Authorization` atraviese Caddy → Apache— se comprueba en el despliegue.
