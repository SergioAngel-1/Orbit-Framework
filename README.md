# Headless Web Ecosystem

Ecosistema web **Headless / JAMstack** completo y contenedorizado:

- **Backend (CMS):** WordPress configurado **exclusivamente como Headless CMS**, exponiendo su contenido vía **WPGraphQL** (con JWT Auth y CORS).
- **Frontend:** **Next.js 15** (App Router) + **React 19** + **TypeScript** + **Tailwind CSS v4** (config CSS-first), con **ISR** (Incremental Static Regeneration).
- **Infraestructura:** **Docker Compose** (MariaDB + WordPress + Next.js, totalmente aislados).

---

## 🗂️ Estructura del proyecto

```
Headless Web Ecosystem/
├── docker-compose.yml          # Orquesta db + wordpress + wpcli + frontend
├── .env.example                # Variables globales (DB, puertos, claves)
├── .gitignore
├── README.md
│
├── backend/                    # WordPress (Headless CMS)
│   ├── .env.example
│   ├── config/
│   │   └── uploads.ini         # Límites de subida de PHP
│   ├── scripts/
│   │   └── setup.sh            # Instalación automática vía WP-CLI
│   └── wp-content/             # Persistido en local (bind mount)
│       ├── mu-plugins/
│       │   └── headless-config.php   # Bloqueo de frontend + CORS + limpieza
│       └── uploads/.gitkeep
│
└── frontend/                   # Next.js (App Router)
    ├── .env.example
    ├── Dockerfile
    ├── .dockerignore
    ├── package.json
    ├── tsconfig.json
    ├── next.config.mjs
    ├── postcss.config.mjs       # Plugin Tailwind v4 (@tailwindcss/postcss)
    ├── .eslintrc.json
    └── src/
        ├── app/
        │   ├── layout.tsx      # Layout global (fuentes + Tailwind)
        │   ├── page.tsx        # Home: últimos 5 posts vía GraphQL + ISR
        │   └── globals.css     # @import "tailwindcss" + tema (@theme)
        ├── lib/
        │   ├── graphql-client.ts   # Cliente GraphQL (fetch nativo + ISR)
        │   └── queries.ts          # Queries GraphQL
        └── types/
            └── wordpress.ts        # Tipos de la respuesta WPGraphQL
```

---

## ✅ Requisitos previos

- **Docker** y **Docker Compose v2** (`docker compose`, no `docker-compose`).
- Puertos **8080** (WordPress) y **3000** (Next.js) libres.

---

## 🚀 Puesta en marcha (paso a paso)

### 1. Clonar y preparar variables de entorno

```bash
git clone <URL_DEL_REPO> "Headless Web Ecosystem"
cd "Headless Web Ecosystem"

# Variables globales (las consume docker-compose)
cp .env.example .env

# (Opcional) variables de referencia por servicio
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env.local
```

> 🔐 **Importante:** edita `.env` y cambia `GRAPHQL_JWT_AUTH_SECRET_KEY` por una
> clave aleatoria larga. Genérala con:
> ```bash
> openssl rand -base64 64
> ```

### 2. Levantar los contenedores

```bash
docker compose up -d --build
```

Esto arranca:

| Servicio   | URL                              | Descripción                      |
|------------|----------------------------------|----------------------------------|
| `db`       | (interno)                        | MariaDB 11                       |
| `wordpress`| http://localhost:8080            | WordPress (Headless CMS)        |
| `frontend` | http://localhost:3000            | Next.js (dev, hot-reload)       |

### 3. Configurar WordPress por primera vez (automático)

Ejecuta el script de instalación vía WP-CLI (instala el core, los plugins
headless, configura permalinks y crea posts de ejemplo):

```bash
docker compose run --rm wpcli
```

Al terminar verás un resumen con el panel, el endpoint GraphQL y el usuario.

**Plugins que instala y activa automáticamente:**

- `WPGraphQL` — expone el contenido en `/graphql`.
- `WPGraphQL JWT Authentication` — autenticación con JSON Web Tokens.
- `WPGraphQL CORS` — control de orígenes (complementa al mu-plugin).

> El comportamiento **headless** (bloqueo del frontend nativo de WP, redirección
> a `/wp-admin` y cabeceras CORS) lo aplica el **must-use plugin**
> `backend/wp-content/mu-plugins/headless-config.php`, que se carga solo y no
> puede desactivarse desde el panel.

### 4. Verificar

- **Panel de administración:** http://localhost:8080/wp-admin
  (usuario / contraseña por defecto: `admin` / `admin` — cámbialos en `.env`).
- **Endpoint GraphQL (GraphiQL IDE):** http://localhost:8080/wp-admin/admin.php?page=graphiql-ide
- **Frontend:** http://localhost:3000 — debe mostrar los últimos 5 posts.

Visitar cualquier URL pública de WordPress (p. ej. http://localhost:8080/)
redirige automáticamente a `/wp-admin`: el frontend nativo está bloqueado.

---

## 🔧 Desarrollo del frontend

El frontend ya corre dentro de Docker con **hot-reload** (volumen montado).
Edita archivos en `frontend/src/` y los cambios se reflejan en
http://localhost:3000.

Si prefieres ejecutarlo **fuera de Docker**:

```bash
cd frontend
npm install
# Asegúrate de que .env.local apunta a http://localhost:8080/graphql
npm run dev
```

Comandos útiles:

```bash
npm run build       # Build de producción
npm run start       # Servir el build
npm run lint        # ESLint
npm run type-check  # Comprobación de tipos (tsc --noEmit)
```

---

## 🧠 Cómo funciona la conexión (red Docker vs. navegador)

El cliente GraphQL (`frontend/src/lib/graphql-client.ts`) resuelve el endpoint
según **quién hace la petición**:

- **Servidor Next (SSR / ISR / RSC):** usa la red interna de Docker
  → `WORDPRESS_INTERNAL_API_URL` = `http://wordpress:80/graphql`.
- **Navegador del usuario (componentes cliente):** usa la URL pública
  → `NEXT_PUBLIC_WORDPRESS_API_URL` = `http://localhost:8080/graphql`.

La home (`page.tsx`) usa **ISR** con `export const revalidate = 60`: se sirve
estática y se regenera, como máximo, cada 60 segundos.

---

## 🔐 Autenticación (JWT — Fase 2)

Flujo **BFF**: el navegador habla con los Route Handlers de Next.js, que guardan
los tokens en cookies `httpOnly` (inaccesibles desde JS → mitiga XSS). El JWT
nunca llega al cliente.

| Endpoint | Método | Descripción |
|----------|--------|-------------|
| `/api/auth/login` | POST | `{ username, password }` → fija cookies de sesión |
| `/api/auth/register` | POST | `{ username, email, password }` → crea usuario + auto-login |
| `/api/auth/refresh` | POST | Renueva el `authToken` desde el refresh token |
| `/api/auth/logout` | POST | Borra las cookies de sesión |
| `/api/auth/me` | GET | Devuelve el usuario autenticado (o 401) |

```bash
# Ejemplo: iniciar sesión (las cookies se guardan en cookies.txt)
curl -i -X POST http://localhost:3000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:3000" \
  -c cookies.txt \
  -d '{"username":"admin","password":"admin"}'

# Usuario actual reutilizando la cookie
curl http://localhost:3000/api/auth/me -b cookies.txt
```

- El **middleware** refresca el `authToken` caducado de forma transparente.
- En **Server Components** usa `getSession()` / `fetchGraphQLAsViewer()` de
  `src/lib/auth/session.ts` para datos del usuario autenticado.
- Todos los endpoints de escritura validan el `Origin` (CSRF completo en Fase 4).

---

## 🛠️ Comandos Docker frecuentes

```bash
docker compose up -d            # Levantar en segundo plano
docker compose logs -f frontend # Ver logs del frontend
docker compose logs -f wordpress
docker compose ps               # Estado de los servicios
docker compose down             # Parar y eliminar contenedores
docker compose down -v          # ⚠️ Además borra el volumen de la BD (reset total)
```

WP-CLI a demanda (cualquier comando de WordPress):

```bash
docker compose run --rm wpcli wp --path=/var/www/html --allow-root plugin list
```

---

## 🔒 Seguridad

> El plan completo de seguridad y comercialización está en
> [`docs/PRODUCTION-PLAN.md`](docs/PRODUCTION-PLAN.md). **La Fase 1 (endurecimiento
> base) ya está implementada.**

**Ya incluido (Fase 1):**

- **Cabeceras de seguridad** en todas las rutas del frontend (`next.config.mjs`):
  CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy`, COOP/CORP y HSTS (en producción). Sin `X-Powered-By`.
- **CORS con allowlist estricta** en WPGraphQL: solo responden los orígenes de
  `HEADLESS_ALLOWED_ORIGINS`; el resto se deniega.
- **Hardening de WordPress** (`mu-plugins/security.php`): sin enumeración de
  usuarios (REST y `?author=N`), sin pingbacks, errores de login genéricos.
- **Redis** disponible para rate-limit/idempotencia de fases siguientes.

**A configurar en producción:**

- Cambia **todas** las contraseñas y la clave JWT en `.env`; genera secretos con
  `openssl rand -base64 64`.
- Ajusta `HEADLESS_ALLOWED_ORIGINS` y `ALLOWED_ORIGIN` a tus dominios reales (HTTPS).
- Termina TLS y redirige HTTP→HTTPS en el proxy inverso / plataforma (HSTS ya se envía).
- Compila el frontend con la fase `prod` del `Dockerfile` (build standalone).
- Restringe el acceso a `/wp-admin` (IP allowlist / WAF) según tu infraestructura.
