# DEPLOY.local — Levantar el entorno de desarrollo

Guía práctica para arrancar el proyecto en local. Hay dos modos:

- **Modo A — Todo en Docker.** Cero instalación local (solo Docker). Más lento en
  Windows por los bind mounts.
- **Modo B — Híbrido (recomendado).** MariaDB y Redis en Docker; **WordPress y
  frontend nativos**. Es el modo rápido: el frontend recompila en segundos y
  WordPress no sufre la latencia de los volúmenes Docker.

> El primer arranque (sección 2) es **idéntico para ambos modos** y se hace una
> sola vez. Después eliges modo A o B en el día a día.

---

## 1. Requisitos

| Herramienta | Versión | Para qué |
|-------------|---------|----------|
| Docker Desktop | reciente | Infra (DB, Redis) y modo A |
| Node.js | **≥ 20.18 (recomendado 24 LTS)** | Frontend nativo (modo B) |
| PHP | **≥ 8.4** con `mysqli`, `curl`, `mbstring`, `gd` | WordPress nativo (modo B) |

Puertos libres: **3000** (frontend), **8080** (WordPress), **3307** (MariaDB),
**6379** (Redis).

---

## 2. Primer arranque (una sola vez)

### 2.1 Variables de entorno

```bash
cp .env.example .env
cp frontend/.env.example frontend/.env.local
```

Genera secretos propios y ponlos en `.env`:

```bash
# Linux / macOS / Git Bash
openssl rand -base64 64    # -> GRAPHQL_JWT_AUTH_SECRET_KEY (mismo valor en WP y frontend)
openssl rand -base64 32    # -> CSRF_SECRET
openssl rand -hex 32       # -> WC_WEBHOOK_SECRET
openssl rand -hex 32       # -> HWE_REVALIDATION_SECRET
openssl rand -base64 32    # -> NOOP_INTEGRITY_SECRET
```

En **Windows PowerShell** (sin openssl) usa los equivalentes nativos. Pega esta
función una vez y genera con `New-Secret 64` (base64) o `New-Secret 32 -Hex`:

```powershell
function New-Secret { param([int]$Bytes=32,[switch]$Hex)
  $b=[byte[]]::new($Bytes); [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
  if($Hex){ ([BitConverter]::ToString($b) -replace '-').ToLower() } else { [Convert]::ToBase64String($b) } }

New-Secret 64          # -> GRAPHQL_JWT_AUTH_SECRET_KEY
New-Secret 32          # -> CSRF_SECRET / NOOP_INTEGRITY_SECRET
New-Secret 32 -Hex     # -> WC_WEBHOOK_SECRET / HWE_REVALIDATION_SECRET
```

> Funciona en Windows PowerShell 5.1 y PowerShell 7. Alternativa: si tienes Git
> para Windows, openssl está en `C:\Program Files\Git\usr\bin\openssl.exe`.

> En local los valores por defecto (`changeme-…`) funcionan; los secretos solo
> son obligatorios en producción (el guard de arranque aborta allí si siguen por
> defecto). Detalle de cada variable en [CONFIGURATION.md](./CONFIGURATION.md).

### 2.2 Instalar WordPress + plugins

```bash
docker compose up -d db redis
docker compose run --rm wpcli
```

Esto levanta la infraestructura e instala WordPress 7.0, WooCommerce, WPGraphQL,
WooGraphQL, JWT, Redis Object Cache y datos base. Idempotente: puedes repetirlo.

### 2.3 Claves de WooCommerce (`ck`/`cs`)

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
```

Copia el par `ck_…` / `cs_…` que imprime a **ambos** archivos (`.env` y
`frontend/.env.local`):

```bash
WC_CONSUMER_KEY=ck_xxxxxxxx
WC_CONSUMER_SECRET=cs_xxxxxxxx
```

### 2.4 (Opcional) Datos demo

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed-demo.sh
```

---

## 3. Modo A — Todo en Docker

```bash
docker compose up -d --build
```

| Servicio   | URL                   |
|------------|-----------------------|
| WordPress  | http://localhost:8080 |
| Frontend   | http://localhost:3000 |

Logs: `docker compose logs -f frontend`

---

## 4. Modo B — Híbrido (recomendado)

Solo la infraestructura corre en Docker; WordPress y el frontend van nativos.

```bash
# Terminal 1 — infraestructura
docker compose up -d db redis

# Terminal 2 — WordPress nativo
#   Lanza tu WordPress local (carpeta con el core de WP) apuntando a la BD de
#   Docker en el puerto 3307. start-local.bat ya fija las variables por ti:
C:\Users\sergi\wp-hwe\start-local.bat
#   Equivalente manual:
#   set WORDPRESS_DB_HOST=127.0.0.1:3307
#   php -S 0.0.0.0:8080 -t C:\Users\sergi\wp-hwe

# Terminal 3 — frontend nativo
cd frontend
npm install        # solo la primera vez o tras cambiar dependencias
npm run dev
```

| Servicio    | Dónde corre   | Puerto | URL                   |
|-------------|---------------|--------|-----------------------|
| MariaDB     | Docker        | `3307` | (interno)             |
| Redis       | Docker        | `6379` | (interno)             |
| WordPress   | Nativo (PHP)  | `8080` | http://localhost:8080 |
| Frontend    | Nativo (Node) | `3000` | http://localhost:3000 |

**Requisitos del WordPress nativo** (la carpeta que sirve `php -S`):

- `wp-config.php` con `DB_HOST = 127.0.0.1:3307` y las mismas credenciales que
  `.env` (usuario/clave `wordpress`), y el mismo `GRAPHQL_JWT_AUTH_SECRET_KEY`.
- Debe usar el `wp-content` del repo (`backend/wp-content`) para cargar los
  **mu-plugins** (comportamiento headless). Cópialo o enlázalo dentro de la
  carpeta de WordPress.

> `frontend/.env.local` ya viene cableado para nativo: WP en `localhost:8080`
> (público e interno) y Redis en `localhost:6379`. No hay que tocar nada.

---

## 5. Verificar

- Tienda: <http://localhost:3000>
- WordPress admin: <http://localhost:8080/wp-admin>
- GraphQL: <http://localhost:8080/graphql>
- Sonda de salud: <http://localhost:3000/api/health>

```bash
cd frontend
npm run type-check   # tsc --noEmit
npm run lint         # ESLint (flat config; `next lint` se eliminó en Next 16)
npm run test         # Vitest
npm run build        # ⭐ verificación definitiva (prerender + tipos + edge)
```

---

## 6. Parar y reiniciar

```bash
docker compose stop              # parar sin borrar datos
docker compose up -d db redis    # volver a arrancar la infra (modo B)
docker compose down              # parar y eliminar contenedores (conserva la BD)
docker compose down -v           # ⚠️ BORRA la BD y Redis (reset total)
```

En modo B, WordPress y el frontend se paran con `Ctrl+C` en sus terminales.

---

## 7. Troubleshooting

| Síntoma | Causa | Solución |
|--------|-------|----------|
| `TypeError: fetch failed` / `ECONNREFUSED` en `next build` o al cargar el catálogo | WordPress no está arrancado (el SSR/ISR consulta GraphQL en build) | Levanta WordPress (Docker o nativo) **antes** de `npm run build` |
| `EPERM: operation not permitted, unlink …\*.node` al borrar `node_modules` | DLL nativos de Windows bloqueados por un proceso (dev server / VS Code) | `taskkill /F /IM node.exe /T`, cierra VS Code y borra; si persiste, reinicia. Luego `npm install` |
| El catálogo sale vacío | WooCommerce sin productos | Ejecuta `seed-demo.sh` o crea productos en `/wp-admin` |
| `wc_no_credentials` en `/api/store/*` | Falta `ck/cs` | Repite el paso 2.3 y reinicia el frontend |
| 403 en escrituras `/api/*` | `ALLOWED_ORIGIN` no coincide | Debe ser `http://localhost:3000` |
| MySQL connection error (WP nativo) | `WORDPRESS_DB_HOST` incorrecto | Usa `127.0.0.1:3307` y verifica que MariaDB esté arriba (`docker compose ps`) |
| Imágenes de producto rotas | Dominio no permitido en `next/image` | Revisa `remotePatterns` en `next.config.mjs` |
| Cambios en mu-plugins no se reflejan (solo Docker prod) | OPcache con `validate_timestamps=0` | Reinicia el contenedor de WordPress |

---

Producción: ver [DEPLOYMENT.md](./DEPLOYMENT.md) y `docker-compose.prod.yml`.
