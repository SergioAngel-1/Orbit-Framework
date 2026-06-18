# Instalación

Guía paso a paso para levantar la plantilla desde cero. Tiempo estimado: **< 30 min**.

## 1. Requisitos

- **Docker** y **Docker Compose** (v2+).
- **Node.js ≥ 20** (solo si quieres ejecutar el frontend fuera de Docker).
- Puertos libres: `3000` (frontend), `8080` (WordPress), `6379` (Redis interno).

## 2. Clonar y configurar variables

```bash
git clone <tu-repo> headless-web-ecosystem
cd headless-web-ecosystem
cp .env.example .env
```

Edita `.env` y, como mínimo, genera secretos propios:

```bash
# Secreto JWT (debe coincidir en WordPress y frontend)
openssl rand -base64 64    # -> GRAPHQL_JWT_AUTH_SECRET_KEY
openssl rand -base64 32    # -> CSRF_SECRET
openssl rand -hex 32       # -> WC_WEBHOOK_SECRET
openssl rand -base64 32    # -> NOOP_INTEGRITY_SECRET (sandbox de pagos)
```

> Detalle completo de cada variable en [CONFIGURATION.md](./CONFIGURATION.md).

## 3. Primer arranque

```bash
docker compose up -d db wordpress redis
# Instala WP + plugins + WooCommerce + datos base (WP-CLI):
docker compose run --rm wpcli
```

## 4. Generar las claves de WooCommerce (`ck`/`cs`)

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
```

Copia el par `ck_…`/`cs_…` que imprime a tu `.env`:

```bash
WC_CONSUMER_KEY=ck_xxxxxxxx
WC_CONSUMER_SECRET=cs_xxxxxxxx
```

## 5. (Opcional) Cargar datos demo

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed-demo.sh
```

## 6. Levantar el frontend

```bash
docker compose up -d frontend
```

- Tienda: <http://localhost:3000>
- WordPress (admin): <http://localhost:8080/wp-admin> (usuario/clave del `.env`)
- Sonda de salud: <http://localhost:3000/api/health>

## 7. Verificar

```bash
cd frontend
npm install          # solo si trabajas fuera de Docker
npm run type-check
npm run lint
npm run test
npm run build        # verificación definitiva
```

## Troubleshooting

| Síntoma | Causa probable | Solución |
|--------|----------------|----------|
| `Couldn't find next-intl config` | Falta `export default nextConfig` | No edites `next.config.mjs` sin mantener el export |
| El catálogo sale vacío | WooCommerce sin productos | Ejecuta `seed-demo.sh` o crea productos en `/wp-admin` |
| `wc_no_credentials` en `/api/store/*` | Falta `ck/cs` en `.env` | Repite el paso 4 y reinicia `frontend` |
| 403 en escrituras (`/api/*`) | `ALLOWED_ORIGIN` no coincide | Ajusta `ALLOWED_ORIGIN` al origen real |
| Imágenes de producto rotas | Dominio no permitido en `next/image` | Revisa `remotePatterns` en `next.config.mjs` |

Más detalle de despliegue en producción: [DEPLOYMENT.md](./DEPLOYMENT.md).
