# Despliegue en producción

La plantilla separa **frontend** (Next.js) y **backend** (WordPress headless). Se
pueden desplegar juntos (un VPS con Docker) o separados (frontend en Vercel +
WordPress gestionado).

## Arquitectura recomendada

```
[Usuarios] ──HTTPS──► [Frontend Next.js]  ──HTTPS interna──►  [WordPress + WooCommerce]
                            │                                        │
                            └────────► [Redis]                       └──► [MariaDB]
```

El frontend es el **único** expuesto al público para operaciones sensibles (BFF).
WordPress puede vivir en una red privada y exponer solo HTTPS para GraphQL/REST.

## Opción A — VPS con Docker (todo junto)

1. Instala Docker + Compose en el servidor.
2. Clona el repo y crea `.env` con secretos de **producción** (ver CONFIGURATION).
3. Construye la imagen de producción del frontend:
   ```bash
   docker build --target prod -t hwe-frontend:prod ./frontend
   ```
4. Ajusta `docker-compose.yml` para usar la imagen `prod` del frontend (o un
   `docker-compose.prod.yml`) y arranca la pila.
5. Pon un **reverse proxy** (Caddy, Nginx o Traefik) delante para **terminación
   TLS**, HTTP→HTTPS y cabeceras. La app ya emite HSTS y `upgrade-insecure-requests`
   en producción.

## Opción B — Frontend en Vercel + WordPress gestionado

1. Conecta el repo a Vercel; **Root Directory = `frontend`**.
2. Define las variables de entorno en Vercel (las de CONFIGURATION; secretos sin
   `NEXT_PUBLIC_`). Apunta `WORDPRESS_INTERNAL_API_URL` y `WC_*` a tu WordPress.
3. Usa **Upstash Redis** (REST) si quieres rate-limit/idempotencia en serverless
   (ioredis sobre TCP no funciona en edge; ver nota en el plan, Fase 4).
4. Despliega WordPress en un hosting gestionado con WooCommerce + WPGraphQL +
   WooGraphQL + JWT. Copia `backend/wp-content/mu-plugins/` al servidor.

## HTTPS

- La terminación TLS la hace el proxy/plataforma. Configura certificados
  (Let's Encrypt) y redirección 80→443.
- Verifica cabeceras tras desplegar: objetivo `securityheaders.com` ≥ A.

## Webhooks de WooCommerce

En `WooCommerce → Ajustes → Avanzado → Webhooks`, crea:
- **Revalidación de catálogo** → `https://tu-frontend/api/revalidate`
  (secreto = `WC_WEBHOOK_SECRET`).
- **Pagos** (al integrar una pasarela) → la pasarela apunta a
  `https://tu-frontend/api/payments/webhook/<provider>`.

## Healthchecks

- `GET /api/health` devuelve `ok`/`degraded` con el estado de Redis y WordPress.
- El servicio `frontend` ya trae `healthcheck` en `docker-compose.yml`; la imagen
  `prod` añade además un `HEALTHCHECK` nativo.

## Backups

- **Base de datos + uploads:** `sh backend/scripts/backup.sh [destino]`.
  Programa un cron (p. ej. diario) y **guarda las copias fuera del servidor**
  (S3/objeto). Restauración documentada en la cabecera del propio script.
- **`wp-content`:** versiona tus `mu-plugins`/config propios; los plugins/temas y
  `uploads` se respaldan con el backup (uploads) y se reinstalan vía `setup.sh`.
- **Migración:** para mover WordPress, restaura la BD y `wp-content/uploads`, y
  ejecuta `wp search-replace` para los dominios si cambian.

## Observabilidad

- Logs estructurados (pino) por stdout: agrégalos en tu plataforma (Loki/Datadog).
- Errores: cablea Sentry en `frontend/src/instrumentation.ts` (`SENTRY_DSN`).

## Checklist previo a producción

- [ ] Secretos de producción generados (no los de ejemplo).
- [ ] HTTPS forzado y cabeceras verificadas (≥ A).
- [ ] Webhooks configurados y firmados.
- [ ] Backups programados y probados (restauración).
- [ ] `npm run build` y `npm run test` verdes en CI.
- [ ] Pasarela de pago real integrada y probada en sandbox.
