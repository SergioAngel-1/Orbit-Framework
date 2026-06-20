# Checklist "de cero a producción"

> Lista de verificación para pasar de un clon limpio a una tienda en producción
> de forma segura. Complementa a `docs/INSTALL.md` y `docs/DEPLOYMENT.md`.
> Marca cada casilla antes de abrir al público.

## 1. Secretos (bloqueante)

> El **guard de arranque** del frontend (`lib/security/secret-guard.ts`) **aborta el
> boot en producción** si algún secreto sigue con valor por defecto o mide <24
> caracteres. Esto es intencional: prefiere fallar al arrancar que servir inseguro.

- [ ] `cp .env.prod.example .env.prod` y completar **todos** los valores.
- [ ] Generar secretos reales (no `changeme-*`):
  - [ ] `GRAPHQL_JWT_AUTH_SECRET_KEY` — `openssl rand -base64 64` (idéntico en WP y frontend).
  - [ ] `CSRF_SECRET` — `openssl rand -base64 32`.
  - [ ] `WC_WEBHOOK_SECRET` — `openssl rand -hex 32` (y configurarlo en el webhook de Woo).
  - [ ] `HWE_REVALIDATION_SECRET` — `openssl rand -hex 32` (idéntico en WP y frontend).
  - [ ] `HWE_SECRETS_KEY` — `openssl rand -base64 48` (cifrado de secretos del Control Center).
  - [ ] Contraseñas de DB (`MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`) y admin de WP.
- [ ] `WC_CONSUMER_KEY` / `WC_CONSUMER_SECRET` generados con `generate-woo-keys.sh`.
- [ ] Verificar que **ningún** secreto está en el repo (`gitleaks` corre en CI).

## 2. Dominios y red

- [ ] `PUBLIC_DOMAIN` y `ACME_EMAIL` definidos (Caddy emitirá TLS automático).
- [ ] `ALLOWED_ORIGIN`, `NEXT_PUBLIC_SITE_URL`, `HEADLESS_ALLOWED_ORIGINS` → tu dominio real.
- [ ] `NEXT_PUBLIC_WORDPRESS_API_URL` apunta al dominio público; el servidor usa la red interna.
- [ ] `TRUSTED_PROXY_COUNT=1` (Caddy delante) y, en WP, `HWE_TRUSTED_PROXY_COUNT=1`.
- [ ] DNS `A`/`AAAA` del dominio (y `www`) apuntando al servidor.

## 3. Despliegue

- [ ] `docker build --target prod -t hwe-frontend:prod ./frontend`.
- [ ] Primer arranque: `docker compose -f docker-compose.prod.yml --env-file .env.prod run --rm wpcli`.
- [ ] Levantar: `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d`.
- [ ] `WORDPRESS_DEBUG=0` y `WP_ENVIRONMENT_TYPE=production` (la introspección GraphQL se apaga).

## 4. Pagos

- [ ] Elegir `PAYMENT_PROVIDER` y `PAYMENT_CURRENCY`. (`noop` solo para validar el flujo.)
- [ ] Integrar la pasarela real (credenciales server-only) — ver `AGENTS.md §6.8`.
- [ ] Configurar el **webhook de la pasarela** → `/api/payments/webhook/<provider>` y probar
      un pago de extremo a extremo (estado `pending` → `processing` por webhook firmado).

## 5. Contenido y catálogo

- [ ] Productos, categorías y páginas legales reales (no datos demo).
- [ ] Webhook de WooCommerce → `/api/revalidate` con `WC_WEBHOOK_SECRET` (catálogo fresco).
- [ ] **SMTP configurado** en *Ajustes → HWE Config → Integraciones* (host, puerto, cifrado, usuario, contraseña, remitente). Cubre reset de contraseña, verificación de email y emails de pedido.
- [ ] **Email de prueba enviado** desde el panel (botón "Enviar email de prueba") y recibido **fuera de spam**.
- [ ] **SPF, DKIM y DMARC** del dominio remitente configurados en DNS (decisivo para la entregabilidad).

## 6. Analítica y legal

- [ ] `NEXT_PUBLIC_ANALYTICS_PROVIDER` (+ dominio/ID) si se usa; el script carga **tras consentimiento**.
- [ ] Revisar textos legales (`/[locale]/legal/*`) con tu información real.

## 7. Operación

- [ ] Backups activados (contenedor `backup` o cron) y copiándose **fuera del servidor**.
- [ ] **Restore probado** en staging con `backend/scripts/restore.sh` (ver `docs/RUNBOOK.md`).
- [ ] Sondas configuradas en tu orquestador: liveness `/api/health/live`, readiness `/api/health?ready=1`.
- [ ] `SENTRY_DSN` (opcional) y revisión de logs (`pino`).

## 8. Verificación final

- [ ] `cd frontend && npx tsc --noEmit && npx next lint && npx next build` en verde.
- [ ] `npm test` (unitarios) en verde; e2e de compra (`E2E_FULL=1`) contra staging sembrado.
- [ ] Recorrido manual: registro → (2FA opcional) → carrito → checkout → pago → pedido visible en cuenta.
- [ ] Cabeceras de seguridad presentes (CSP, HSTS en prod) y `/wp-admin` accesible solo por HTTPS.
