# Instalación

Guía paso a paso para levantar la plantilla desde cero. Tiempo estimado: **< 30 min**.

## 1. Requisitos

- **Docker** y **Docker Compose** (v2+).
- **Node.js ≥ 20.18** (recomendado **24 LTS**, para frontend nativo).
- **PHP ≥ 8.4** (paridad con producción) con extensiones `mysqli`, `curl`, `mbstring`, `gd` (solo para el modo híbrido).
- Puertos libres: `3000` (frontend), `8080` (WordPress), `3307` (MariaDB), `6379` (Redis).

## 2. Clonar y configurar variables

```bash
git clone <tu-repo> headless-web-ecosystem
cd headless-web-ecosystem
cp .env.example .env
cp frontend/.env.example frontend/.env.local
```

Edita `.env` y, como mínimo, genera secretos propios:

```bash
# Linux / macOS / Git Bash
# Secreto JWT (debe coincidir en WordPress y frontend)
openssl rand -base64 64    # -> GRAPHQL_JWT_AUTH_SECRET_KEY
openssl rand -base64 32    # -> CSRF_SECRET
openssl rand -hex 32       # -> WC_WEBHOOK_SECRET
openssl rand -hex 32       # -> HWE_REVALIDATION_SECRET
openssl rand -base64 32    # -> NOOP_INTEGRITY_SECRET (sandbox de pagos)
```

En **Windows PowerShell** (sin openssl), pega esta función y genera con
`New-Secret 64` (base64) o `New-Secret 32 -Hex`:

```powershell
function New-Secret { param([int]$Bytes=32,[switch]$Hex)
  $b=[byte[]]::new($Bytes); [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
  if($Hex){ ([BitConverter]::ToString($b) -replace '-').ToLower() } else { [Convert]::ToBase64String($b) } }

New-Secret 64          # -> GRAPHQL_JWT_AUTH_SECRET_KEY
New-Secret 32          # -> CSRF_SECRET / NOOP_INTEGRITY_SECRET
New-Secret 32 -Hex     # -> WC_WEBHOOK_SECRET / HWE_REVALIDATION_SECRET
```

> Detalle completo de cada variable en [CONFIGURATION.md](./CONFIGURATION.md).

## 3. Primer arranque (WP-CLI)

Arranca la infraestructura e instala WordPress + plugins. **Se hace una sola vez, independientemente del modo de desarrollo.**

```bash
docker compose up -d db redis
docker compose run --rm wpcli
```

## 4. Generar las claves de WooCommerce (`ck`/`cs`)

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
```

Copia el par `ck_…`/`cs_…` que imprime a `.env` y `frontend/.env.local`:

```bash
WC_CONSUMER_KEY=ck_xxxxxxxx
WC_CONSUMER_SECRET=cs_xxxxxxxx
```

## 5. (Opcional) Cargar datos demo

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed-demo.sh
```

## 6. Elegir modo de desarrollo

### Opción A: Todo en Docker

```bash
docker compose up -d --build
```

| Servicio   | URL                   | Descripción                 |
|------------|-----------------------|-----------------------------|
| `db`       | (interno)             | MariaDB 11                  |
| `wordpress`| http://localhost:8080 | WordPress (Headless CMS)    |
| `redis`    | (interno)             | Cache + rate-limit          |
| `frontend` | http://localhost:3000 | Next.js (dev, hot-reload)   |

### Opción B: Híbrido (WordPress + frontend nativos, recomendado)

Evita la lentitud de los bind mounts Docker en Windows. **Requiere PHP ≥ 8.3.**

```bash
# 1. Infraestructura (Docker)
docker compose up -d db redis

# 2. WordPress nativo
C:\Users\sergi\wp-hwe\start-local.bat
# (alternativa manual: WORDPRESS_DB_HOST=127.0.0.1:3307 php -S 0.0.0.0:8080 -t C:\Users\sergi\wp-hwe)

# 3. Frontend nativo
cd frontend
npm install
npm run dev
```

| Servicio   | Dónde corre     | Puerto | URL                   |
|------------|-----------------|--------|-----------------------|
| **MariaDB**| Docker          | `3307` | (interno)             |
| **Redis**  | Docker          | `6379` | (interno)             |
| **WordPress**| Nativo (PHP)  | `8080` | http://localhost:8080 |
| **Frontend**| Nativo (Node) | `3000` | http://localhost:3000 |

## Verificar (ambos modos)

- Tienda: <http://localhost:3000>
- WordPress (admin): <http://localhost:8080/wp-admin> (usuario/clave del `.env`)
- Sonda de salud: <http://localhost:3000/api/health>

```bash
cd frontend
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
| `wc_no_credentials` en `/api/store/*` | Falta `ck/cs` en `.env` o `.env.local` | Repite el paso 4 y reinicia el frontend |
| 403 en escrituras (`/api/*`) | `ALLOWED_ORIGIN` no coincide | Ajusta `ALLOWED_ORIGIN` al origen real |
| Imágenes de producto rotas | Dominio no permitido en `next/image` | Revisa `remotePatterns` en `next.config.mjs` |
| MySQL connection error (WP nativo) | `WORDPRESS_DB_HOST` incorrecto | Asegura `127.0.0.1:3307` y que MariaDB esté levantada |

Más detalle de despliegue en producción: [DEPLOYMENT.md](./DEPLOYMENT.md).
