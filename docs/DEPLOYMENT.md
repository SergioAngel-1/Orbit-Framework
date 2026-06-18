# Despliegue en producción

La plantilla separa **frontend** (Next.js) y **backend** (WordPress headless). Se
pueden desplegar juntos en un VPS con Docker, o separados (frontend en Vercel +
WordPress gestionado).

## Arquitectura de producción

```
Internet
    │
    ▼
[Caddy — TLS automático (Let's Encrypt)]
    │                │
    ▼                ▼
[Next.js :3000]  [WordPress :80]   ← solo accesibles por la red interna
    │                │
    ├── [Redis :6379]               ← rate-limit BFF + Object Cache WP
    └── [MariaDB :3306]
```

- **Caddy** termina TLS, maneja HTTP→HTTPS, sirve HTTP/3 (QUIC).
- El BFF (Next.js) llama a WordPress por la red interna Docker, sin pasar por Caddy.
- Solo Caddy tiene puertos públicos (80 y 443).

---

## Opción A — VPS con Docker Compose (recomendada)

### 1. Preparar el servidor

```bash
# Ubuntu 22.04+
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker
```

### 2. Clonar el repositorio y configurar el entorno

```bash
git clone https://github.com/tu-org/headless-web-ecosystem.git
cd headless-web-ecosystem

# Copiar la plantilla de producción
cp .env.prod.example .env.prod
nano .env.prod         # completar TODOS los secretos antes de continuar

# Crear directorio para backups
mkdir -p backups
```

Claves a generar:
```bash
# GRAPHQL_JWT_AUTH_SECRET_KEY
openssl rand -base64 64
# CSRF_SECRET
openssl rand -base64 32
# WC_WEBHOOK_SECRET
openssl rand -hex 32
# HWE_REVALIDATION_SECRET
openssl rand -hex 32
```

### 3. Construir la imagen del frontend

```bash
docker build --target prod -t hwe-frontend:prod ./frontend
# Anotar el nombre en .env.prod: FRONTEND_IMAGE=hwe-frontend:prod
```

### 4. Primer arranque: instalar WordPress

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm wpcli
```

Esto instala WordPress, WooCommerce, WPGraphQL, JWT Auth y el Redis Object Cache.

### 5. Generar las claves de WooCommerce

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
# Copiar WC_CONSUMER_KEY / WC_CONSUMER_SECRET en .env.prod
```

### 6. Levantar la pila completa

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
# Verificar estado:
docker compose -f docker-compose.prod.yml --env-file .env.prod ps
# Logs:
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f caddy
```

### 7. Verificar el despliegue

```bash
curl -I https://tienda.mi-dominio.com/api/health
# Esperado: HTTP/2 200

curl -s https://tienda.mi-dominio.com/api/health | jq .
# Esperado: { "status": "ok", ... }
```

---

## Opción B — Frontend en Vercel + WordPress gestionado

1. Conecta el repo a Vercel; **Root Directory = `frontend`**.
2. Define las variables de entorno en el panel de Vercel (sin `NEXT_PUBLIC_` para secretos).
   - `WORDPRESS_INTERNAL_API_URL` → URL pública de tu WordPress (sin /graphql).
   - `WC_API_URL`, `WC_STORE_API_URL`, `WC_CONSUMER_KEY`, `WC_CONSUMER_SECRET`.
   - `REDIS_URL` → [Upstash Redis](https://upstash.com) (compatible con ioredis en serverless).
3. Despliega WordPress en hosting gestionado con los plugins del `setup.sh`.
   Copia `backend/wp-content/mu-plugins/` al servidor.
4. Configura los webhooks de WooCommerce (ver §Webhooks más abajo).

---

## Configuración de Caddy

El `Caddyfile` en la raíz del repositorio enruta automáticamente:

| Ruta | Destino |
|------|---------|
| `/wp-admin/*`, `/wp-login.php` | WordPress (panel de admin) |
| `/wp-json/*` | WordPress (REST API / webhooks) |
| `/graphql` | WordPress (GraphQL endpoint) |
| `/wp-content/*` | WordPress (medios, con caché larga) |
| Todo lo demás | Next.js (frontend) |

Variables de entorno que consume el `Caddyfile`:
- `PUBLIC_DOMAIN` — dominio sin protocol (ej. `tienda.mi-dominio.com`).
- `ACME_EMAIL` — email para notificaciones de Let's Encrypt.

---

## Redis Object Cache (WordPress)

El plugin **Redis Cache** (por Till Krüss) convierte a Redis en el Object Cache de WordPress,
reduciendo las consultas a la BD hasta un 90 % en catálogos y páginas de admin. Se instala
automáticamente en `setup.sh` y se activa con las constantes:

```php
define( 'WP_REDIS_HOST', 'redis' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_CACHE', true );
```

Estas constantes se inyectan via `WORDPRESS_CONFIG_EXTRA` en ambos composes. El panel de WP
admin muestra el estado en **Ajustes → Redis**.

---

## Backups

### Automáticos (contenedor `backup` en docker-compose.prod.yml)

El servicio `backup` corre en Alpine con `mariadb-client` y programa un backup diario
a las **03:00 UTC** via cron. Respaldo:
- `backups/db-YYYYMMDD-HHmmss.sql.gz` — volcado completo de MariaDB.
- `backups/uploads-YYYYMMDD-HHmmss.tar.gz` — medios de `wp-content/uploads`.
- Retención: `BACKUP_RETAIN_DAYS` días (por defecto 14 en prod, configurable en `.env.prod`).

Ver logs del backup:
```bash
docker logs hwe_backup
```

### Manuales (host → contenedor)

```bash
sh backend/scripts/backup.sh ./backups
```

### Sincronización a almacenamiento externo

Programa en el host un cron que sincronice `./backups` a S3 o equivalente:

```bash
# crontab -e (host)
30 4 * * * aws s3 sync /ruta/proyecto/backups s3://mi-bucket/backups --delete
```

O con `rclone`:
```bash
30 4 * * * rclone sync /ruta/proyecto/backups remote:mi-bucket/backups
```

### Restaurar la BD

```bash
gunzip < backups/db-YYYYMMDD-HHmmss.sql.gz \
  | docker compose exec -T db \
      mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"
```

---

## Webhooks de WooCommerce

En **WooCommerce → Ajustes → Avanzado → Webhooks**:

| Nombre | Evento | URL de entrega | Secreto |
|--------|--------|---------------|---------|
| Revalidar catálogo | Producto creado + Producto actualizado | `https://tu-dominio/api/revalidate` | `WC_WEBHOOK_SECRET` |

Al integrar una pasarela de pago, configúrala para enviar eventos a:
`https://tu-dominio/api/payments/webhook/<provider>`

---

## HTTPS y cabeceras de seguridad

- Caddy gestiona certificados Let's Encrypt automáticamente (renovación incluida).
- La aplicación emite HSTS con `max-age=63072000` en producción.
- Objetivo: **A+** en [securityheaders.com](https://securityheaders.com).
- Verifica con: `curl -I https://tu-dominio/`

---

## Observabilidad

- **Logs**: pino emite JSON estructurado por stdout → Docker Compose → tu plataforma (Loki, Datadog, CloudWatch).
- **Errores**: cablea Sentry en `frontend/src/instrumentation.ts` (`SENTRY_DSN`).
- **Uptime**: monitoriza `GET /api/health` desde un servicio externo (UptimeRobot, Better Uptime).

---

## Checklist previo a producción

- [ ] `PUBLIC_DOMAIN` y `ACME_EMAIL` configurados en `.env.prod`.
- [ ] Todos los secretos generados (no los valores de ejemplo del template).
- [ ] Imagen del frontend construida (`docker build --target prod`).
- [ ] `npm run build` y `npm run test` verdes en CI.
- [ ] WordPress instalado y Redis Object Cache activo (verde en el panel de WP).
- [ ] HTTPS forzado y cabeceras verificadas (≥ A en securityheaders.com).
- [ ] Claves WooCommerce (`ck/cs`) generadas y en `.env.prod`.
- [ ] Webhooks de WooCommerce configurados y firmados.
- [ ] Backup inicial ejecutado y probada la restauración.
- [ ] Directorio `./backups` sincronizado a almacenamiento externo.
- [ ] Pasarela de pago real integrada y probada en sandbox.
- [ ] Plugin HWE Control Center configurado desde wp-admin.
