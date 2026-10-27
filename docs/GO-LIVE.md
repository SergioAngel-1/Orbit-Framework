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
  - [ ] Contraseñas de DB (`MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`).
  - [ ] `WP_ADMIN_PASSWORD` — fuerte y única, **obligatoria**: `setup.sh` ya no cae en `admin` y el compose de producción no arranca sin ella. `wp-login.php` limita a 5 intentos cada 5 min por IP.
- [ ] `WC_CONSUMER_KEY` / `WC_CONSUMER_SECRET` generados con `generate-woo-keys.sh`.
- [ ] Verificar que **ningún** secreto está en el repo (`gitleaks` corre en CI).
- [ ] `.env.prod` con permisos `600` y propiedad del usuario de operación (`chmod 600 .env.prod`).
      Todos los secretos viajan como variables de entorno del contenedor: quien pueda leer
      `/proc/1/environ` o `docker inspect` (es decir, quien esté en el grupo `docker`) los ve
      todos, incluidos los que descifran los secretos del Control Center. El grupo `docker` es,
      de hecho, la frontera de confianza: trátalo como acceso root.

## 2. Dominios y red

- [ ] `PUBLIC_DOMAIN` y `ACME_EMAIL` definidos (Caddy emitirá TLS automático).
- [ ] `ALLOWED_ORIGIN`, `NEXT_PUBLIC_SITE_URL`, `HEADLESS_ALLOWED_ORIGINS` → tu dominio real.
- [ ] `HWE_REVALIDATION_SECRET` es el mismo en el frontend y en WordPress (secreto interno BFF ↔ WP): sin él, GraphQL y la Store API responden 404.
- [ ] `WORDPRESS_PUBLIC_URL` (origen público de WordPress, para sus imágenes) está en el build de la imagen del frontend; no existe URL pública de GraphQL.
- [ ] `TRUSTED_PROXY_COUNT=1` (Caddy delante) y, en WP, `HWE_TRUSTED_PROXY_COUNT=1`.
- [ ] DNS `A`/`AAAA` del dominio (y `www`) apuntando al servidor.

## 3. Despliegue

- [ ] `docker compose -f docker-compose.prod.yml --env-file .env.prod build frontend` (pasa los build-args que el build congela; falla pronto sin `WORDPRESS_PUBLIC_URL`).
- [ ] Tras el primer arranque y cada despliegue: `sh backend/scripts/warm-cache.sh https://<dominio>` (el CD ya lo hace).
- [ ] Volumen de la base de datos creado una vez (`docker volume create hwe_db_data`, o el nombre de `HWE_DB_VOLUME`; con una instalación previa, el nombre del volumen que ya tienes).
- [ ] `COMPOSE_FILE=docker-compose.prod.yml` en `.env.prod` (sin `-f`, compose usaría el de desarrollo).
- [ ] Primer arranque: `docker compose -f docker-compose.prod.yml --env-file .env.prod run --rm wpcli`.
- [ ] Levantar: `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d`.
- [ ] `WORDPRESS_DEBUG=0` y `WP_ENVIRONMENT_TYPE=production` (la introspección GraphQL se apaga).
- [ ] Tras cada `pull` de la imagen de WordPress, actualizar el **core** del volumen
      (`run --rm wpcli /scripts/update-core.sh --apply <versión>` + `restart wordpress`): subir la
      etiqueta no lo actualiza. Ver `docs/RUNBOOK.md` §10.
- [ ] Copias de seguridad **cifradas** y verificadas fuera del servidor (`docs/RUNBOOK.md` §1):
      `./backups` en claro no protege del robo del disco.
- [ ] Retención de imágenes del registro en el servidor (no solo `image prune -f`, que deja las
      etiquetadas): guarda las N últimas para poder volver atrás sin llenar el disco.

## 4. Pagos

- [ ] Elegir una pasarela **real** y `PAYMENT_CURRENCY` en Control Center → Pagos o con
      `PAYMENT_PROVIDER` (`wompi`, `avalpay`…). `noop` es solo de pruebas: en producción el
      arranque aborta con `PAYMENT_PROVIDER=noop`, no se activa aunque quede guardada, y su
      webhook responde 404. Sin pasarela, el cobro responde 503.
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

- [ ] Servicios de analítica y publicidad configurados en Control Center → Integraciones (solo se cargan **tras consentimiento**); `CSP_ANALYTICS_PROVIDERS` estrecha la CSP a los que uses.
- [ ] Revisar textos legales (`/[locale]/legal/*`) con tu información real.

## 7. Operación

- [ ] Backups activados (contenedor `backup` o cron) y copiándose **fuera del servidor**.
- [ ] **Restore probado** en staging con `backend/scripts/restore.sh` (ver `docs/RUNBOOK.md`).
- [ ] Sondas configuradas en tu orquestador: liveness `/api/health/live`, readiness `/api/health?ready=1`.
- [ ] `SENTRY_DSN` (opcional) y revisión de logs (`pino`).

## 8. Endurecimiento opcional (probarlo en staging antes)

La plantilla aplica `no-new-privileges` en todos los servicios y `cap_drop: ALL` en caddy, redis,
frontend, wp-cron y backup. Queda pendiente, y conviene probarlo en staging:

- [ ] `cap_drop: ALL` + `cap_add` mínimas en `wordpress` (CHOWN, DAC_OVERRIDE, SETUID, SETGID,
      NET_BIND_SERVICE) y `user: 999:999` en `db` tras el primer init.
- [ ] `read_only: true` + `tmpfs` en `frontend` (con el volumen `next_cache`) y en `caddy`
      (con `/data` y `/config`).
- [ ] Sacar `wp-content` del checkout (hoy es un bind del árbol de git y `setup.sh` escribe
      plugins ahí): un `git reset --hard` no revierte plugins y un rollback de imagen tampoco.
- [ ] `container_name` fijo impide que dos entornos convivan en el mismo host (blue/green).
- [ ] Frontend de desarrollo como usuario no-root (`user:` + `security_opt`), probando antes que
      los volúmenes de `node_modules`/`.next` son escribibles.

## 9. Verificación final

- [ ] `cd frontend && npx tsc --noEmit && npx next lint && npx next build` en verde.
- [ ] `npm test` (unitarios) en verde; e2e de compra (`E2E_FULL=1`) contra una pila de
      **desarrollo/híbrido** sembrada (la pasarela `noop` solo existe fuera de producción) o con
      una pasarela de sandbox real.
- [ ] Recorrido manual: registro → (2FA opcional) → carrito → checkout → pago → pedido visible en cuenta.
- [ ] Cabeceras de seguridad presentes (CSP, HSTS en prod) y `/wp-admin` accesible solo por HTTPS.
