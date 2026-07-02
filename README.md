# Headless Web Ecosystem

Ecosistema web **Headless / JAMstack** completo y contenedorizado:

- **Backend (CMS):** WordPress configurado **exclusivamente como Headless CMS**, exponiendo su contenido vía **WPGraphQL** (con JWT Auth y CORS).
- **Frontend:** **Next.js 15** (App Router) + **React 19** + **TypeScript** + **Tailwind CSS v4** (config CSS-first), con **ISR** (Incremental Static Regeneration).
- **Infraestructura:** **Docker Compose** en producción (MariaDB + WordPress + Next.js); en desarrollo, **modo híbrido** (WordPress + frontend nativos, DB/Redis en Docker) para máximo rendimiento.

> **Alcance del framework:** el núcleo reutilizable es el backend (WordPress headless) y el
> BFF de Next.js (`frontend/src/app/api/*`, `frontend/src/lib/*` — auth, seguridad, proxy a
> WooCommerce, pagos, config). La UI (`frontend/src/components/**` salvo `ui/`, y todas las
> páginas de `frontend/src/app/[locale]/*`) se hereda una sola vez al clonar y pasa a ser
> responsabilidad de cada instancia — el framework no la mantiene ni la actualiza. Ver
> `AGENTS.md §1.1` y `docs/FRONTEND_CONNECT.md`.

---

## 🗂️ Estructura del proyecto

```
Headless Web Ecosystem/
├── docker-compose.yml          # Orquesta servicios para Docker puro
├── docker-compose.prod.yml     # Producción: + Caddy TLS, backup, sin puertos expuestos
├── Caddyfile                   # Reverse proxy de producción (TLS automático)
├── .env.example / .env.prod.example
├── backend/                    # WordPress (Headless CMS)
│   ├── scripts/
│   │   ├── setup.sh            # Instalación automática vía WP-CLI
│   │   ├── generate-woo-keys.sh
│   │   └── seed-demo.sh
│   └── wp-content/mu-plugins/  # ⭐ comportamiento headless + endpoints propios
└── frontend/                   # Next.js (App Router) — web pública + BFF
    ├── src/
    │   ├── app/
    │   │   ├── [locale]/       # ⚠️ vistas heredadas al clonar — responsabilidad de la instancia
    │   │   └── api/            # ⭐ el BFF (núcleo del framework): auth, store, payments, webhooks…
    │   ├── components/         # ⚠️ heredado (salvo ui/, que sí es núcleo): cart, products, auth…
    │   └── lib/                # ⭐ toda la lógica (núcleo del framework): auth, woocommerce, security…
    └── tests/                  # unit (Vitest) + e2e (Playwright)
```

---

## ✅ Requisitos previos

- **Docker** y **Docker Compose v2** (`docker compose`, no `docker-compose`).
- **Node.js ≥ 20.18** (recomendado **24 LTS**, para frontend nativo).
- **PHP ≥ 8.4** (paridad con producción) con extensiones `mysqli`, `curl`, `mbstring`, `gd` (para WordPress nativo — solo desarrollo híbrido).
- Puertos **8080** (WordPress), **3000** (Next.js), **3307** (MariaDB), **6379** (Redis) libres.

---

## 🚀 Puesta en marcha

### 0. Preparar variables de entorno

```bash
git clone <URL_DEL_REPO> "Headless Web Ecosystem"
cd "Headless Web Ecosystem"

# Variables globales
cp .env.example .env
cp frontend/.env.example frontend/.env.local
```

> 🔐 **Importante:** edita `.env` y cambia `GRAPHQL_JWT_AUTH_SECRET_KEY` por una
> clave aleatoria larga. Genérala con:
> ```bash
> openssl rand -base64 64
> ```

### 1. Primer arranque (WP-CLI) — una sola vez

Independientemente del modo que elijas, primero instala WordPress y los plugins:

```bash
docker compose up -d db redis           # solo la base de datos
docker compose run --rm wpcli           # instala WP + plugins + WooCommerce
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
# Copia WC_CONSUMER_KEY / WC_CONSUMER_SECRET a .env y .env.local
```

Elige el modo de desarrollo:

---

### Opción A: Todo en Docker (rápido, sin instalar PHP/Node local)

```bash
docker compose up -d --build
```

| Servicio   | URL                   | Descripción                 |
|------------|-----------------------|-----------------------------|
| `db`       | (interno)             | MariaDB 11                  |
| `wordpress`| http://localhost:8080 | WordPress (Headless CMS)    |
| `redis`    | (interno)             | Cache + rate-limit          |
| `frontend` | http://localhost:3000 | Next.js (dev, hot-reload)   |

---

### Opción B: Híbrido (recomendado para desarrollo pesado)

WordPress y frontend nativos → sin la lentitud de bind mounts Docker en Windows.

**Requisitos adicionales:** PHP ≥ 8.3 instalado y en el PATH.

```bash
# 2. Arrancar servicios de infraestructura (Docker)
docker compose up -d db redis

# 3. WordPress nativo (nueva terminal)
# Apunta -t al directorio raíz de tu instalación local de WordPress.
# Linux/Mac — ejecutar directamente:
php -S 0.0.0.0:8080 -t /path/to/your/local/wordpress
# Windows — puedes crear un archivo start-local.bat con esa misma línea
# y ejecutarlo haciendo doble clic, o lanzarlo desde la terminal.

# 4. Frontend nativo (nueva terminal)
cd frontend
npm run dev
```

| Servicio   | Dónde corre     | Puerto | URL                   |
|------------|-----------------|--------|-----------------------|
| **MariaDB**| Docker          | `3307` | (interno)             |
| **Redis**  | Docker          | `6379` | (interno)             |
| **WordPress**| Nativo (PHP)  | `8080` | http://localhost:8080 |
| **Frontend**| Nativo (Node) | `3000` | http://localhost:3000 |

> ⚡ El frontend nativo responde en segundos, sin WATCHPACK_POLLING.
> WordPress nativo evita la latencia de bind mounts del contenedor.

### Verificar (ambos modos)

- **Panel de administración:** http://localhost:8080/wp-admin (admin / admin)
- **GraphQL:** http://localhost:8080/graphql
- **Frontend:** http://localhost:3000
- **REST API:** http://localhost:8080/wp-json/wp/v2/categories

---

## 🔧 Comandos útiles

```bash
cd frontend
npm run dev          # Desarrollo (frontend nativo)
npm run build        # Build de producción
npx tsc --noEmit     # Comprobación de tipos
npx next lint        # ESLint
npm run test         # Tests unitarios (Vitest)
npm run build        # ⭐ verificación definitiva (prerender + tipos + edge)
```

### Docker

```bash
docker compose up -d          # Todo en Docker (modo A)
docker compose up -d db redis # Solo infraestructura (modo B)
docker compose logs -f frontend
docker compose down           # Parar
docker compose down -v        # ⚠️ Borra la BD (reset total)
```

WP-CLI a demanda:

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed-demo.sh
docker compose run --rm wpcli wp --path=/var/www/html --allow-root plugin list
```

---

## 🧠 Cómo funciona la conexión (red Docker vs. navegador)

El cliente GraphQL (`frontend/src/lib/graphql-client.ts`) resuelve el endpoint
según **quién hace la petición**:

- **Servidor Next (SSR / ISR / RSC):** usa la red interna de Docker o local
  → `WORDPRESS_INTERNAL_API_URL` = `http://wordpress:80/graphql` (Docker) o
  `http://localhost:8080/graphql` (nativo).
- **Navegador del usuario (componentes cliente):** usa la URL pública
  → `NEXT_PUBLIC_WORDPRESS_API_URL` = `http://localhost:8080/graphql`.

Ambos modos (`frontend/.env.local`) apuntan a `localhost:8080` cuando se
trabaja fuera de Docker.

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

### Protección de escrituras (CSRF + rate-limit — Fase 4)

Todo endpoint de **escritura** (POST/PUT/PATCH/DELETE) exige: `Origin` válido,
**token CSRF** y respeta el **rate-limit**. El cliente primero obtiene un token:

```bash
# 1) Obtener token CSRF (se guarda en cookie y se devuelve en el cuerpo)
curl -s http://localhost:3000/api/csrf -c cookies.txt
# -> {"csrfToken":"<token>"}

# 2) Reenviarlo en la cabecera X-CSRF-Token de cada mutación
curl -i -X POST http://localhost:3000/api/auth/login \
  -H "Content-Type: application/json" -H "Origin: http://localhost:3000" \
  -H "X-CSRF-Token: <token>" \
  -c cookies.txt -b cookies.txt \
  -d '{"username":"admin","password":"admin"}'
```

- Rate-limit (por IP): login/registro 5/min, checkout 10/min, carrito 60/min →
  responde `429` con `Retry-After`. Requiere **Redis** (degrada a permitir si cae).
- **Checkout idempotente**: envía `Idempotency-Key: <uuid>` para que los reintentos
  no dupliquen el pedido.

---

## 🛒 Tienda (WooCommerce — Fase 3)

WooCommerce se consume mediante **proxy inverso**: las credenciales `ck`/`cs`
viven **solo en el servidor** y nunca llegan al navegador.

| Endpoint | Método | API usada | Descripción |
|----------|--------|-----------|-------------|
| `/api/store/cart` | GET / DELETE | Store API | Ver / vaciar carrito |
| `/api/store/cart/items` | POST / PATCH / DELETE | Store API | Añadir / actualizar / quitar línea |
| `/api/store/checkout` | POST | Store API | Crear pedido desde el carrito |
| `/api/store/orders/[id]` | GET | wc/v3 (ck/cs) | Pedido **del usuario** (autorizado) |
| `/api/store/customer` | GET / PUT | wc/v3 (ck/cs) | Datos del cliente autenticado |

**Generar las claves `ck`/`cs`** (tras `docker compose run --rm wpcli`):

```bash
docker compose run --rm --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
# Copia WC_CONSUMER_KEY / WC_CONSUMER_SECRET en .env y .env.local
# En modo Docker: docker compose up -d frontend
# En modo híbrido: reinicia npm run dev
```

```bash
# Añadir un producto (id 12) al carrito; la cookie de carrito se guarda en cookies.txt
curl -i -X POST http://localhost:3000/api/store/cart/items \
  -H "Content-Type: application/json" -H "Origin: http://localhost:3000" \
  -c cookies.txt -b cookies.txt \
  -d '{"id":12,"quantity":1}'
```

- **Carrito/checkout** → Store API (`wc/store/v1`), token de carrito en cookie httpOnly.
- **Pedidos/cliente** → `wc/v3` con `ck`/`cs`; el handler **comprueba que el recurso
  pertenece al usuario** de la sesión (anti-IDOR).

### Páginas de tienda (UI — Fase 5)

| Ruta | Descripción |
|------|-------------|
| `/products` | Catálogo (WooGraphQL + ISR) con búsqueda y paginación |
| `/products/[slug]` | Ficha de producto (SSG + ISR, SEO + JSON-LD) |
| `/categories/[slug]` | Productos de una categoría |
| `/cart` | Carrito (estado cliente sobre el BFF) |
| `/checkout` | Finalizar compra (idempotente) |
| `/login`, `/register` | Autenticación |
| `/account`, `/account/orders` | Cuenta protegida (perfil + pedidos) |

### Revalidación on-demand por webhook

Para que el catálogo (ISR) se actualice al cambiar un producto, crea un webhook en
**WooCommerce → Ajustes → Avanzado → Webhooks**:

- **Tema:** `Producto actualizado` (y/o creado).
- **URL de entrega:** `http://frontend:3000/api/revalidate` (Docker) o
  `http://localhost:3000/api/revalidate` (híbrido).
- **Secreto:** el mismo valor que `WC_WEBHOOK_SECRET` en tu `.env`.

El endpoint verifica la firma HMAC y ejecuta `revalidateTag('products')`.

---



---

## 📚 Documentación

| Documento | Para qué |
|-----------|----------|
| [`docs/INSTALL.md`](docs/INSTALL.md) | Instalación paso a paso. |
| [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) | Variables de entorno. |
| [`docs/CUSTOMIZATION.md`](docs/CUSTOMIZATION.md) | White-label / rebranding. |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Despliegue en producción. |
| [`docs/GO-LIVE.md`](docs/GO-LIVE.md) | **Checklist de cero a producción.** |
| [`docs/RUNBOOK.md`](docs/RUNBOOK.md) | **Operación: backup/restore, rotación de secretos, incidentes.** |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Modelo de amenazas y hardening. |
| [`docs/OBSERVABILITY.md`](docs/OBSERVABILITY.md) | Correlación request-id, alertas, retención/PII. |
| [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) | Matriz de versiones soportadas. |
| [`docs/ACCESIBILIDAD.md`](docs/ACCESIBILIDAD.md) | Auditoría WCAG 2.2 AA. |
| [`CHANGELOG.md`](CHANGELOG.md) | Novedades por versión. |

## 🔒 Seguridad

> Modelo de amenazas y hardening detallado en [`docs/SECURITY.md`](docs/SECURITY.md).
> El endurecimiento base **y** el del plan de auditoría (guard de secretos, revocación de
> sesión, anti-replay de webhooks, cifrado GCM, etc.) ya están implementados.

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
