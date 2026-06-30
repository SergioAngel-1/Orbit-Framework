# Reinstalación limpia (reset total) — modo Híbrido (WP nativo PHP)

> Runbook para dejar el entorno **como recién clonado** y reinstalar en **modo B
> (Híbrido)**: MariaDB + Redis en Docker, **WordPress nativo con `php -S`** y frontend
> con `npm run dev`. Pensado para Windows / PowerShell. Ejecuta **desde la raíz del repo**
> (`D:\Proyectos\Headless Web Ecosystem`) salvo que se indique otra cosa.
>
> ⚠️ **Esto borra todos los datos locales**: base de datos, uploads, plugins descargados,
> `node_modules` e imágenes Docker del proyecto. No afecta a tu código (`src`, `mu-plugins`)
> ni a `.env` (lo conservamos; ver Fase 2).

---

## Fase 0 — Pre-vuelo

```powershell
cd "D:\Proyectos\Headless Web Ecosystem"
docker compose ps          # ver qué está corriendo
docker volume ls | findstr hwe   # volúmenes del proyecto (db_data, redis_data, wordpress_core)
```

(Opcional) copia de seguridad de tu `.env` por si acaso:

```powershell
Copy-Item .env .env.bak -Force
Copy-Item frontend\.env.local frontend\.env.local.bak -Force
```

---

## Fase 1 — Parar y borrar TODO (Docker + procesos nativos)

```powershell
# 1.1 Matar procesos nativos que bloquean archivos (frontend Node y WP PHP)
taskkill /F /IM node.exe /T 2>$null
taskkill /F /IM php.exe  /T 2>$null

# 1.2 Derribar Docker del proyecto: contenedores + volúmenes + red + imágenes
#     -v   borra db_data, redis_data, wordpress_core (¡BD y caché!)
#     --rmi all borra las imágenes (mariadb, wordpress, wordpress:cli, redis, frontend)
docker compose down -v --rmi all --remove-orphans
```

Verifica que no queda nada del proyecto:

```powershell
docker ps -a   | findstr hwe     # no debe salir nada
docker volume ls | findstr hwe   # no debe salir nada
```

> **Opcional (agresivo):** `docker system prune -af --volumes` libera TODO Docker de la
> máquina, **no solo este proyecto**. Úsalo solo si quieres limpiar imágenes de otros
> proyectos también.

---

## Fase 2 — Limpiar el árbol del repo

```powershell
# 2.1 Frontend: artefactos y dependencias
Remove-Item -Recurse -Force frontend\node_modules, frontend\.next, frontend\.turbo, frontend\tsconfig.tsbuildinfo -ErrorAction SilentlyContinue

# 2.2 WordPress: plugins/themes/uploads descargados (se reinstalan).
#     NO toca mu-plugins (es NUESTRO código y no está en .gitignore).
Remove-Item -Recurse -Force backend\wp-content\plugins\* -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force backend\wp-content\themes\*  -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force backend\wp-content\uploads\* -ErrorAction SilentlyContinue
New-Item -ItemType File backend\wp-content\uploads\.gitkeep -Force | Out-Null
```

> Esto elimina, entre otros, el plugin obsoleto `backend\wp-content\plugins\jwt-auth`
> (el de la falsa actualización 3.0.2). Tras reinstalar, el JWT auténtico v0.7.2 quedará
> en `wp-graphql-jwt-authentication` y **desaparece la colisión de slug**.

Comprobación rápida (deben seguir existiendo tus mu-plugins):

```powershell
Get-ChildItem backend\wp-content\mu-plugins   # headless-config.php, security.php, hwe-control-center, ...
```

---

## Fase 3 — Variables de entorno

Conservas tu `.env`. Si lo quieres **totalmente fresco**, recréalo:

```powershell
# (solo si quieres reset de secretos)
Copy-Item .env.example .env -Force
Copy-Item frontend\.env.example frontend\.env.local -Force
```

Genera secretos (PowerShell, sin openssl):

```powershell
function New-Secret { param([int]$Bytes=32,[switch]$Hex)
  $b=[byte[]]::new($Bytes); [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
  if($Hex){ ([BitConverter]::ToString($b) -replace '-').ToLower() } else { [Convert]::ToBase64String($b) } }

New-Secret 64        # -> GRAPHQL_JWT_AUTH_SECRET_KEY  (MISMO valor en .env y wp-config nativo)
New-Secret 32        # -> CSRF_SECRET / NOOP_INTEGRITY_SECRET
New-Secret 32 -Hex   # -> WC_WEBHOOK_SECRET / HWE_REVALIDATION_SECRET
```

> En local los valores `changeme-…` funcionan; los secretos solo son obligatorios en prod.
> Pero **el `GRAPHQL_JWT_AUTH_SECRET_KEY` debe ser idéntico** en `.env` y en el `wp-config.php`
> del WordPress nativo (Fase 6), o el login fallará.

---

## Fase 4 — Primer arranque (instala WP + plugins en la BD compartida)

Este paso es **idéntico para Docker e Híbrido** y deja la BD (en MariaDB:3307) lista, además
de descargar los plugins en `backend/wp-content/plugins`.

```powershell
docker compose up -d db redis
docker compose run --rm wpcli
```

Esto instala WordPress 7.0, WooCommerce, WPGraphQL, WooGraphQL, **WPGraphQL JWT Auth v0.7.2**,
Redis Object Cache y datos base (idempotente). Espera al healthcheck de `db`.

---

## Fase 5 — Claves de WooCommerce y datos demo

```powershell
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
```

Copia el par `ck_…` / `cs_…` a **ambos** archivos (`.env` y `frontend\.env.local`):

```
WC_CONSUMER_KEY=ck_xxxxxxxx
WC_CONSUMER_SECRET=cs_xxxxxxxx
```

(Opcional) catálogo de ejemplo:

```powershell
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed-demo.sh
```

---

## Fase 6 — WordPress nativo (carpeta `C:\Users\sergi\wp-hwe`)

El WP nativo sirve los **archivos core** localmente, pero usa **la misma BD (3307)** y **el
mismo `wp-content` del repo** (para los mu-plugins headless).

**Si ya tenías `C:\Users\sergi\wp-hwe` funcionando** (con `start-local.bat`), solo asegúrate de:

1. Que `wp-hwe\wp-content` apunta al del repo (junction). Si no existe, créalo:

   ```powershell
   # Borra el wp-content nativo si es una copia vieja y enlázalo al del repo:
   if (Test-Path C:\Users\sergi\wp-hwe\wp-content) { Remove-Item -Recurse -Force C:\Users\sergi\wp-hwe\wp-content }
   cmd /c mklink /J "C:\Users\sergi\wp-hwe\wp-content" "D:\Proyectos\Headless Web Ecosystem\backend\wp-content"
   ```

2. Que `wp-hwe\wp-config.php` tiene los valores correctos (ver plantilla en el Apéndice A).
   En particular `DB_HOST = 127.0.0.1:3307` y el **mismo** `GRAPHQL_JWT_AUTH_SECRET_KEY` que `.env`.

3. Arráncalo:

   ```powershell
   C:\Users\sergi\wp-hwe\start-local.bat
   # equivalente manual:
   #   $env:WORDPRESS_DB_HOST="127.0.0.1:3307"
   #   php -S 0.0.0.0:8080 -t C:\Users\sergi\wp-hwe
   ```

**Si NO tienes `wp-hwe` o quieres recrearlo desde cero** → Apéndice A.

---

## Fase 7 — Frontend nativo

```powershell
cd frontend
npm install        # primera vez tras limpiar node_modules
npm run dev
```

---

## Fase 8 — Verificar

- Tienda: <http://localhost:3000>
- WordPress admin: <http://localhost:8080/wp-admin>
- GraphQL: <http://localhost:8080/graphql>
- Salud: <http://localhost:3000/api/health>
- Plugin JWT correcto (debe decir `wp-graphql-jwt-authentication 0.7.2`, **no** `jwt-auth`):

  ```powershell
  docker compose run --rm wpcli wp --path=/var/www/html --allow-root plugin list
  ```

Calidad (con WordPress arrancado, porque el build consulta GraphQL):

```powershell
cd frontend
npm run type-check
npm run lint
npm run build
```

---

## Apéndice A — Recrear el WordPress nativo desde cero

Requiere **WP-CLI** nativo (o usa el del contenedor para descargar el core a una carpeta
montada). Con WP-CLI instalado en Windows:

```powershell
$WPDIR = "C:\Users\sergi\wp-hwe"
New-Item -ItemType Directory -Force $WPDIR | Out-Null
cd $WPDIR

# 1. Core de WordPress, MISMA versión que la imagen Docker (7.0)
wp core download --version=7.0 --locale=es_ES

# 2. wp-content del repo (junction) — trae mu-plugins + plugins + uploads
if (Test-Path "$WPDIR\wp-content") { Remove-Item -Recurse -Force "$WPDIR\wp-content" }
cmd /c mklink /J "$WPDIR\wp-content" "D:\Proyectos\Headless Web Ecosystem\backend\wp-content"

# 3. wp-config.php (ver plantilla abajo) — NO ejecutes `wp core install`:
#    la BD ya fue instalada por la Fase 4 (misma MariaDB en 3307).
```

**Plantilla `wp-config.php`** (ajusta el JWT al de tu `.env`):

```php
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', '127.0.0.1:3307' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wp_';

// Mismo secreto que .env (¡crítico para el login JWT!)
define( 'GRAPHQL_JWT_AUTH_SECRET_KEY', 'PEGA-AQUI-EL-MISMO-VALOR-QUE-EN-.env' );

// Headless / CORS
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'HEADLESS_FRONTEND_URL', 'http://localhost:3000' );
define( 'HEADLESS_ALLOWED_ORIGINS', 'http://localhost:3000' );
define( 'HWE_REVALIDATION_SECRET', 'PEGA-AQUI-EL-MISMO-VALOR-QUE-EN-.env' );

// Redis nativo (Docker expone 6379 en localhost)
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_CACHE', true );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );

// Sal de autenticación (genera las tuyas en https://api.wordpress.org/secret-key/1.1/salt/)
// AUTH_KEY, SECURE_AUTH_KEY, ... (pega un set nuevo)

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once ABSPATH . 'wp-settings.php';
```

**`start-local.bat`** (en `C:\Users\sergi\wp-hwe`):

```bat
@echo off
set WORDPRESS_DB_HOST=127.0.0.1:3307
php -S 0.0.0.0:8080 -t "C:\Users\sergi\wp-hwe"
```

---

## Apéndice B — Diferencias clave Docker vs nativo (por qué este wp-config)

| Define | Docker (`hwe_wordpress`) | Nativo (`php -S`) |
|---|---|---|
| `DB_HOST` | `db:3306` (red interna) | `127.0.0.1:3307` (puerto publicado) |
| `WP_REDIS_HOST` | `redis` | `127.0.0.1` |
| Core WP | volumen `wordpress_core` | carpeta `wp-hwe` (descarga propia) |
| `wp-content` | bind `./backend/wp-content` | junction a `backend\wp-content` |

La BD (MariaDB) y `wp-content` son **compartidos**, por eso el primer arranque (Fase 4) en
Docker sirve para ambos modos: instala las tablas en la misma MariaDB que usa el WP nativo.
