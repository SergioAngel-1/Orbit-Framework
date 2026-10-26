# Crear una instancia nueva

Esta plantilla no tiene un generador/CLI de proyectos: **crear una instancia nueva
es clonar el repositorio completo y rebrandearlo**. Cada cliente/proyecto es su
propia copia (backend + frontend + Docker), no un "tenant" dentro de una
instalación compartida.

Esta guía cubre el flujo completo, incluido el comando `wp hwe setup` para
seedear la marca/diseño de la instancia en un solo paso en vez de rellenar el
formulario de `wp-admin` a mano. Para el detalle línea por línea de instalación
ver `docs/INSTALL.md`; para el checklist de rebranding manual (logo, i18n,
páginas legales) ver `docs/CUSTOMIZATION.md`; para producción ver `docs/GO-LIVE.md`.

## 1. Clonar el framework

Parte siempre de una **release** (tag `vX.Y.Z`; la última es la primera entrada con fecha
de `CHANGELOG.md`), nunca de `main`: lo que hay en `main` entre dos releases está en
`[Sin publicar]` y ningún número de versión lo identifica.

```bash
git clone --branch vX.Y.Z --origin framework <repo-del-framework> <cliente>/instancia
cd <cliente>/instancia
git switch -c main
git remote add origin <repo-de-la-instancia>   # su propio repositorio
git push -u origin main
```

`--origin framework` deja el repo del framework como remote `framework`, del que se traen las
releases futuras (`docs/UPGRADE.md`), y `origin` pasa a ser el repositorio de la instancia.

**No reinicies el historial** (`rm -rf .git && git init`). Las actualizaciones son un
`git merge` del tag nuevo, y git solo puede fusionarlo si la instancia comparte historia con el
framework: sin ella, `git merge` responde `refusing to merge unrelated histories` y la
instancia no puede recibir parches. Si ya creaste una instancia así, `docs/UPGRADE.md` §3.1
explica cómo reconectarla.

`FRAMEWORK_VERSION` (raíz) dice de qué release del framework parte la instancia. Es del
núcleo: no lo edites; cada merge de una release lo actualiza.

## 2. Variables de entorno y secretos

```bash
cp .env.example .env
cp frontend/.env.example frontend/.env.local
```

Genera secretos **propios de esta instancia** (nunca reutilices los del
framework base ni los de otra instancia):

```bash
sh backend/scripts/generate-secrets.sh
```

Escribe `GRAPHQL_JWT_AUTH_SECRET_KEY`, `CSRF_SECRET`, `WC_WEBHOOK_SECRET`,
`HWE_REVALIDATION_SECRET` y `NOOP_INTEGRITY_SECRET` directamente en `.env` y
`frontend/.env.local` (idempotente). `HWE_SECRETS_KEY` y `HWE_2FA_KEY` (claves AES-256-GCM
de los secretos del Control Center y del TOTP) son opcionales en desarrollo, donde caen a
`AUTH_KEY`, pero **`docker-compose.prod.yml` las exige** y deben ser estables: genéralas con
`openssl rand -base64 48` y guárdalas con el resto de secretos (`docs/CONFIGURATION.md`,
«Seguridad y sesión»). Alternativa manual (`openssl rand ...`) en `docs/INSTALL.md §2`.

Ajusta también: `WP_TITLE`, `WORDPRESS_PORT`/`FRONTEND_PORT` (si corres varias
instancias en la misma máquina, usa puertos distintos para no chocar),
`NEXT_PUBLIC_SITE_URL` (el nombre de la marca va en `instance.config.json`, no en el entorno).

### Varias pilas en la misma máquina

Dos clones de Orbite (o una instancia junto a la pila de desarrollo del framework) comparten
el daemon de Docker del equipo, y ahí los nombres de contenedor y los puertos publicados son
únicos. Cada pila necesita **su** juego de valores en su `.env` (nada de esto es código: son
variables de entorno, `.env.example` las documenta):

| Variable | Por defecto | Para qué |
|---|---|---|
| `HWE_CONTAINER_PREFIX` | `hwe` | Prefijo de los contenedores (`hwe_db`, `hwe_wordpress`, `hwe_wp_cron`, `hwe_redis`, `hwe_frontend`; en producción también `hwe_caddy` y `hwe_cache_prune`). Cambiarlo en una de las dos pilas evita el choque de nombres. |
| `COMPOSE_PROJECT_NAME` | el nombre de la carpeta | Proyecto de Compose: prefija la red y los volúmenes y separa las pilas ante Docker. Dos clones en carpetas distintas ya lo tienen distinto; fíjalo si renombras la carpeta o corres dos pilas desde la misma ruta. |
| `DB_PORT` | `3307` | Puerto de MariaDB en el host (`127.0.0.1`). El modo híbrido apunta ahí. |
| `REDIS_PORT` | `6379` | Puerto de Redis en el host (`127.0.0.1`). |
| `WORDPRESS_PORT` / `FRONTEND_PORT` | `8080` / `3000` | Puertos de WordPress y del frontend en el host. |

Con `FRONTEND_PORT` distinto de `3000` basta con cambiar esa variable: si no se declaran,
`docker-compose.yml` deriva de ella `NEXT_PUBLIC_SITE_URL`, `ALLOWED_ORIGIN`,
`HEADLESS_FRONTEND_URL` y `HEADLESS_ALLOWED_ORIGINS` (`http://localhost:<FRONTEND_PORT>`), así
que los enlaces de recuperación/verificación y la barrera de Origin apuntan a la pila correcta.
Solo hay que ajustar esas cuatro variables a mano si el origen público no es
`http://localhost:<puerto>` (dominio propio, proxy, acceso desde la LAN); en producción van en
`.env.prod` y no se derivan.

Los scripts de verificación (`backend/scripts/verify-modules.sh`, `verify-integration.sh`)
resuelven sus contenedores **por servicio de Compose del proyecto actual**, no por un nombre
fijo, y aceptan `HWE_WP_CONTAINER` / `HWE_CLI_CONTAINER` como anulación explícita. Si el
contenedor resuelto pertenece a otra pila (etiqueta `com.docker.compose.project`), **abortan**
con un mensaje en vez de activar o desactivar módulos en la pila de otra instancia. Si no hay
pila en este proyecto, responden con el recordatorio de `docker compose up -d`.

## 3. Configuración de marca/diseño — `wp hwe setup`

En vez de rellenar a mano el panel **HWE Config** (`wp-admin`) la primera vez,
puedes seedearlo desde un archivo:

```bash
cp backend/scripts/instance.config.example.json backend/scripts/instance.config.json
```

Edita `backend/scripts/instance.config.json` con los datos reales de la
instancia:

- `brand` (nombre, tagline, descripción, URL, locale; `tagline_en`… para los idiomas
  secundarios), `social`, `legal`, `design.colors`/`design.typography` (design tokens — ver §4).
- `i18n.locales`: los idiomas **secundarios** como una cadena separada por comas (p. ej.
  `"en, fr"`; una lista `["en", "fr"]` también se acepta y se une con comas), sin el de `brand.locale`.
  Deben coincidir con `frontend/src/i18n/routing.ts`: si divergen, el arranque del frontend lo
  avisa en el log. Con ellos, los campos traducibles (`tagline`, `faq`…) ganan una variante
  `<campo>_<idioma>`.
- `ecommerce`: `currency` (defecto `EUR`: **fíjala siempre**, es la moneda de todo importe y se sincroniza con WooCommerce), `country`
  (elige el paquete por país: ciudades, tipos de documento, formato de teléfono y dirección;
  `CO` o `generic`), flags de reviews/wishlist/cupones/búsqueda, `checkout_login_required`
  y `free_shipping`.
- `modules`: los ids de módulo que quedan **activos** (ver abajo).
- `auth`, `seo`, `geo` y `backups` (frecuencia/retención de los backups automáticos —
  opcional, tiene defaults razonables).

**No incluyas** credenciales de pago o SMTP en este archivo: esos campos son de tipo `secret`
y se configuran aparte desde `wp-admin → HWE Config` o por variable de entorno — el comando
los ignora si no vienen en el JSON.

### Módulos

Un módulo es un plugin del framework (`hwe-<id>`) que aporta una funcionalidad que no toda
tienda necesita. El catálogo es: `banners`, `shipping`, `product-landing`, `landings`,
`consent-log`, `site-reviews`, `coupon-rules`, `customer-identity`, `external-integration`,
`loyalty` y `memberships` (qué hace cada uno: `docs/CONFIGURATION.md`, «Módulos»).

`wp hwe setup` **activa los listados en `modules` y desactiva los demás** del catálogo; si el
JSON no trae la clave, no toca ningún plugin (las instalaciones anteriores conservan su estado).
Con un módulo apagado, sus rutas del BFF responden 404 y su interfaz no se pinta. Para
comprobarlo en una pila levantada: `sh backend/scripts/verify-modules.sh`.

El archivo real `instance.config.json` **no se versiona** (está en
`.gitignore`); solo se versiona la plantilla `instance.config.example.json`.

Este paso se aplica automáticamente al final de `setup.sh` (ver §5) mediante:

```bash
wp hwe setup /scripts/instance.config.json
```

El comando reutiliza la misma validación/sanitización por tipo de campo
(`color`, `url`, `email`, `select`, `boolean`, `secret`) que usa el guardado
desde `wp-admin`, hace un *merge* profundo (no borra lo que no incluyas) y
dispara la revalidación ISR del frontend. Es idempotente: puedes reejecutarlo
cuando quieras para reaplicar la config (p. ej. en un entorno reproducible o CI):

```bash
docker compose run --rm --entrypoint wp wpcli hwe setup /scripts/instance.config.json
```

Si no creas `instance.config.json`, `setup.sh` simplemente omite este paso y
puedes configurar todo manualmente en `wp-admin → HWE Config` después.

## 4. Design tokens: mapear la paleta de marca

`design.colors`/`design.typography` alimentan las variables CSS del frontend
(`--color-brand`, `--color-secondary`, `--color-accent`, `--color-surface`,
`--font-sans`, `--font-heading`, etc. — ver `frontend/src/lib/config/tokens.ts`
y `frontend/src/app/globals.css`). Si el sistema de diseño de la marca tiene
más matices que estos campos (p. ej. varios tonos de un mismo color, tipografías
adicionales), documenta el mapeo explícitamente antes de rellenar el JSON —
por ejemplo, para una instancia con un sistema de diseño propio:

| Token de marca (diseño original) | Campo `design.*` de esta instancia |
|---|---|
| Color principal de títulos | `colors.brand` |
| Hover del botón principal | `colors.brand_dark` |
| Color secundario de acento suave | `colors.brand_light` |
| Color secundario de títulos/CTA | `colors.secondary` / `colors.secondary_dark` |
| Color de énfasis/highlight | `colors.accent` |
| Color de superficie (tarjetas) | `colors.surface` |
| Fondo de página | `colors.background` |
| Color de texto | `colors.foreground` |
| Tipografía de cuerpo | `typography.font_sans` |
| Tipografía de titulares | `typography.font_heading` |

## 5. Levantar WordPress + WooCommerce

```bash
docker compose up -d db redis
docker compose run --rm wpcli
```

Esto instala WordPress, los plugins headless (WPGraphQL, JWT Auth, WooCommerce,
WooGraphQL, Redis Object Cache), configura permalinks, registro de usuarios,
impuestos/envío básico y, si existe `instance.config.json`, aplica `wp hwe
setup` automáticamente (§3). Ver `docs/INSTALL.md` para el detalle completo y
troubleshooting.

Si tu instancia trae un plugin propio (`hwe-instance-<slug>/`), en desarrollo hay que
montarlo con un `docker-compose.override.yml`: ver §7, «Plugins de instancia en Docker».

## 6. Claves de WooCommerce para el BFF

```bash
docker compose run --rm --user "$(id -u):$(id -g)" --entrypoint /bin/sh wpcli /scripts/generate-woo-keys.sh
```

Escribe `WC_CONSUMER_KEY`/`WC_CONSUMER_SECRET` directamente en `.env` y
`frontend/.env.local` — el `--user "$(id -u):$(id -g)"` hace que escriba
como tu usuario del host (dueño de esos archivos, en modo `600`) en vez de
como `www-data:33` del contenedor. Usa `--print-only` para solo imprimirlas
y copiarlas a mano (y omite el `--user`). Reinicia el frontend tras esto:
`docker compose up -d frontend`.

## 7. Dónde va el código de tu instancia

El comando `wp hwe setup` cubre la config de marca/diseño, pero **no** genera ni mantiene
componentes de UI. El framework es backend + arquitectura (WordPress headless + el BFF de
Next.js en `frontend/src/app/api/*` y `frontend/src/lib/*`) — ver `AGENTS.md §1.1`. Lo tuyo
vive en rutas que el framework **nunca toca**, así que `git merge` de un release nuevo no choca:

| Qué | Dónde |
|---|---|
| Campos de configuración propios, hooks, tipos de campo | `backend/wp-content/mu-plugins/instance/*.php` (el cargador `hwe-instance-loader.php` los carga en orden alfabético) |
| Un plugin entero (CPT, tablas, UI de admin) | `backend/wp-content/plugins/hwe-instance-<slug>/` |
| El registro de la instancia (tags ISR, landings propias, campos de producto, visuales decorativos) | `frontend/src/instance/registry.ts` — **solo datos**, nunca componentes |
| Tipado de tus ajustes en `SiteConfig.instance` | `frontend/src/instance/config.d.ts` |
| Tu dominio (fetchers, formato, validación) | `frontend/src/instance/lib/` |
| La UI: componentes y vistas | `frontend/src/components/**` (salvo `ui/`) y `frontend/src/app/[locale]/*` |

La UI se **hereda una sola vez** al clonar, como punto de partida neutro (clases de marca,
textos en `es.json`/`en.json`, cada funcionalidad gateada por su flag o módulo). Desde ese
momento es 100% de esta instancia: constrúyela, cámbiala o bórrala sin esperar actualizaciones,
porque el framework no la mantiene. La frontera la impone una regla de ESLint: el núcleo
(`lib/`, `app/api/`, `proxy.ts`, `components/ui/`) no importa nada de tu código salvo
`@/instance/registry`.

Para ver el contrato en pequeño, copia de `examples/instance-minimal/`: un campo
`instance.example.*`, un tipo de campo propio, un tag ISR, una landing con sección única,
campos de producto, un detalle de línea de pedido y una vista. Los
hooks y sus reglas: `backend/wp-content/mu-plugins/instance/README.md`,
`frontend/src/instance/README.md` y `docs/PLUGIN_DEVELOPMENT.md`.

### Plugins de instancia en Docker (`hwe-instance-*`)

Los módulos del framework se montan uno a uno en `docker-compose.yml`; un plugin de tu
instancia (`backend/wp-content/plugins/hwe-instance-<slug>/`) **no se monta por defecto en
desarrollo**, así que WordPress no lo ve. No edites `docker-compose.yml` (es núcleo): copia el
ejemplo versionado a `docker-compose.override.yml` en la raíz —Docker Compose lo carga solo— y
cambia el slug:

```bash
cp docker-compose.override.example.yml docker-compose.override.yml
# edita `hwe-instance-example` → `hwe-instance-<slug>` en x-instance-plugins
docker compose up -d wordpress wp-cron
docker compose run --rm --entrypoint wp wpcli --path=/var/www/html \
    plugin activate hwe-instance-<slug>
```

Se monta **solo el plugin**, nunca `backend/wp-content/plugins/` entera: esa carpeta dentro del
contenedor es el volumen `wordpress_content`, donde `setup.sh` instala WooCommerce y los plugins
de terceros, y un bind mount de la carpeta los taparía. Con varios plugins propios, añade una
línea por plugin a `x-instance-plugins`; los tres servicios (`wordpress`, `wpcli` y `wp-cron`)
la heredan por el ancla YAML, igual que el núcleo monta cada `hwe-*` en los tres.

En **producción no hace falta** el override: `docker-compose.prod.yml` monta
`./backend/wp-content` **entero** en `wordpress`, `wpcli` y `wp-cron`, así que el plugin ya está
en los contenedores. Actívalo una vez con WP-CLI (la activación queda en la base de datos) y,
tras desplegar cambios del plugin, reinicia `wordpress` (OPcache usa
`validate_timestamps=0`):

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm --entrypoint wp wpcli --path=/var/www/html plugin activate hwe-instance-<slug>
docker compose -f docker-compose.prod.yml --env-file .env.prod restart wordpress
```

El código del plugin viaja en el `backend/wp-content` del servidor (bind mount del compose de
producción), no dentro de ninguna imagen: tras actualizarlo en el host, reinicia `wordpress`.
Para una instancia ya desplegada, ver `docs/UPGRADE.md` («Imágenes y plugins de una instancia ya
desplegada»).

### CI de la instancia

`.github/**` es núcleo (`docs/UPGRADE.md` §1): **no lo edites**, cada merge choca. Pero hay
jobs del CI que solo tienen sentido en el repositorio del framework y, sin tocarlos, en una
instancia fallarían o probarían otra cosa:

- `anti-instance` persigue justo los tokens que el código de tu instancia lleva (el de esta
  instancia está en el patrón desde el primer día).
- `contract` superpone `examples/instance-minimal/` al repo y **pisa** tu `registry.ts` y tus
  `mu-plugins/instance/`: estaría probando el ejemplo, no tu código.
- `gitignore.test.ts` describe la política del framework (tu carpeta de instancia ignorada y el
  patrón de plugins comentado), que en tu repo es exactamente al revés.
- `e2e` está escrito contra las vistas del *starter* (`frontend/src/app/[locale]/**`,
  `components/**`), que tú reescribes por contrato (`AGENTS.md` §1.1): en tu repo fallarían
  siempre. Tus E2E viven en `frontend/tests/e2e/instance/`, una carpeta reservada de la que el
  framework solo versiona su `README.md`.

El interruptor es **una variable de repositorio de GitHub** (no un secreto; se lee como
`vars.HWE_INSTANCE`): en el repositorio de la instancia, *Settings → Secrets and variables →
Actions → Variables → New repository variable*, nombre `HWE_INSTANCE`, valor **`true`** exacto.
Con ella, los dos jobs se saltan (`if: ${{ vars.HWE_INSTANCE != 'true' }}`), el job del frontend
la recibe y `gitignore.test.ts` solo omite los bloques de la política del framework. El resto de
la batería (lint, tipos, formato, i18n, tests, cobertura, build, PHPUnit, gitleaks) sigue
corriendo igual y es la que dice si un merge del framework rompió tu instancia.

Con la variable puesta, el job `e2e` cambia de objetivo: sus **dos pasadas** (la normal y la de
módulos apagados) ejecutan **solo** `tests/e2e/instance/`. La segunda pasada está pensada para
que pongas ahí tu propio spec de «módulos apagados» si te interesa; si no, se ejecutan los mismos
specs contra la instalación sin módulos. Sin specs en la carpeta el job **pasa**
(`--pass-with-no-tests`), así que puedes añadirlos cuando quieras. El framework, además, ignora
esa carpeta en su propio `npm run e2e` y en su CI (`testIgnore` de `playwright.config.ts`): si
haces merge de un release con specs nuevos del núcleo, no se ejecutan contra tu instalación.

En local, expórtala antes de `npm run test` (o de `bash .github/scripts/check-anti-instance.sh`)
para que veas lo que verá el CI; con ella, Playwright también deja de ignorar tu carpeta de e2e:

```bash
export HWE_INSTANCE=true
```

Es **fail-closed**: cualquier otro valor (o su ausencia) mantiene el comportamiento del
framework. Si el CI de tu instancia sigue ejecutando la puerta anti-instancia, la variable no
está puesta o no vale exactamente `true`.

#### Qué puede probar tu e2e en CI (y qué no)

En CI los E2E corren contra un **stub** de GraphQL/WooCommerce (`.github/scripts/stub-graphql.mjs`),
no contra tu WordPress: ni base de datos, ni pedidos de verdad, ni webhooks, ni tu Control Center.
La config pública (`/wp-json/hwe/v1/config`) sale del stub con la paleta por defecto del
framework más los `STUB_SITE_CONFIG_EXTRA` que fija el workflow (que es núcleo y no editas).
Con eso:

- **Sí** puedes probar lo que depende de tu frontend: que tus vistas rendericen, tu navegación y
  tus textos, la validación en cliente de tus formularios, los códigos de estado del BFF que no
  necesitan datos reales y la accesibilidad (axe) de tus páginas.
- **No** puedes probar el recorrido real (login, carrito, checkout, pago por webhook, pedidos en
  la cuenta, contenido del CMS) ni nada que dependa de tu catálogo, tus ciudades de envío o tus
  ajustes: el stub devuelve el CMS vacío y una config fija. No escribas specs que dependan de
  datos que no controlas.

Los recorridos contra tu pila real se ejecutan **en local**, con la app levantada y
`E2E_BASE_URL` apuntando a ella:

```bash
cd frontend
HWE_INSTANCE=true E2E_BASE_URL=http://localhost:3000 E2E_FULL=1 npx playwright test tests/e2e/instance/
```

`HWE_INSTANCE=true` hace falta también en local: sin él `playwright.config.ts` ignora la carpeta
(es lo que protege al framework) y Playwright no encuentra ningún test. Marca los specs que
necesitan tu pila real con `test.skip(!process.env.E2E_FULL, "…")`, como hace
`tests/e2e/purchase.spec.ts` del núcleo: así el CI (contra el stub) los salta y en local los
ejecutas con `E2E_FULL=1`.

### Rutas públicas en otro idioma

Las carpetas de `app/[locale]/` y las rutas internas del núcleo están en inglés (`/products`,
`/account`, `/checkout/return`). Si tu tienda publica en español (o cualquier otro idioma), **no
renombres esas carpetas ni edites núcleo**: declara el mapa en `frontend/src/instance/registry.ts`
(solo datos) y el framework traduce enlaces, canónicas, sitemap, JSON-LD, `llms.txt`, correos y
robots, y sirve la URL pública sobre la misma vista:

```ts
export const instanceRegistry: InstanceRegistry = {
  revalidationTags: [],
  publicPaths: {
    "/products": "/productos",      // /products/vela → /productos/vela
    "/categories": "/categorias",
    "/cart": "/carrito",
    "/checkout": "/pago",           // /checkout/return → /pago/return
    "/account": "/cuenta",          // /account/orders → /cuenta/orders
    "/login": "/entrar",
    "/register": "/registro",
    "/forgot-password": "/recuperar",
    "/reset-password": "/restablecer",
    "/verify-email": "/verificar",
  },
};
```

Los hijos heredan el prefijo del padre (una entrada propia manda), el mapa se valida al arrancar
(choques, `api`, un idioma, rutas mal formadas → error claro) y la ruta interna antigua responde
**308** a la pública: no quedan URL duplicadas. Si un idioma conserva el tramo interno, declara el
valor por idioma (`{ es: "/novedades", en: "/blog" }`). Contrato completo:
`docs/FRONTEND_CONNECT.md` §A.4.

Para las **páginas propias** de la instancia y las rutas que no quieres anunciar, no edites el
núcleo: declara `sitemap.pages` (se añaden al sitemap en todos los idiomas, respetando
`publicPaths`) y `sitemap.exclude` (rutas internas exactas o un prefijo con `/*`) en el mismo
`registry.ts`. También afecta a `llms.txt`. Ver `docs/FRONTEND_CONNECT.md` §A.4.

### Instancia de un solo idioma

El framework trae `es` (principal) y `en`. Una tienda que solo publica en un idioma **no
necesita mantener el catálogo inglés** ni que el núcleo lo dé por hecho:

1. En `frontend/src/i18n/routing.ts` deja solo tu idioma: `locales: ["es"]`
   (`defaultLocale: "es"`). Es el único archivo de núcleo que se edita para esto (zona gris).
2. Borra `frontend/src/i18n/messages/en.json`. No hay que tocar el núcleo: los catálogos viven en
   `src/i18n/messages/catalogs.ts`, un idioma sin catálogo cae al principal, y las importaciones,
   los tipos (`tsconfig.json`), los tests (`vitest.config.ts`) y el build (`next.config.mjs`)
   resuelven el catálogo ausente al español. `npm run i18n:check` no compara nada y pasa.
3. Si la tienda no está en la zona horaria de la plantilla, declara la tuya en
   `frontend/src/instance/registry.ts` (solo dato, el núcleo no se toca):

   ```ts
   export const instanceRegistry = {
     // …
     timeZone: "America/Bogota", // o un mapa: { es: "America/Bogota" }
   } satisfies InstanceRegistry;
   ```

   Sin `timeZone` se mantienen los valores de siempre (`es` → `Europe/Madrid`, `en` →
   `America/New_York`), que es lo que espera el framework sin configurar nada.

Los tests del núcleo se ejecutan con los idiomas del framework (un `vitest` compartido fija
`es`/`en`), así que el CI de tu instancia sigue corriendo la misma batería; el job «Frontend (un
solo idioma)» del repositorio del framework comprueba exactamente esta configuración.

Lo único que es un contrato estable (y a lo que cualquier vista, heredada o nueva, debe
ajustarse) es el backend/BFF: WPGraphQL/WooGraphQL, los endpoints REST bajo `app/api/*`, el
endpoint de config `/wp-json/hwe/v1/config` y los flags `ecommerce.*_enabled` y `modules.*` —
documentado en **`docs/FRONTEND_CONNECT.md` Parte A**. Para decidir qué construir (entrevista
de características del negocio → plan de vistas, reutilizando lo heredado o desde cero):
**`docs/FRONTEND_BUILD.md`**.

## 8. Checklist de rebranding restante (manual)

Lo que `wp hwe setup` no cubre porque vive en el código del frontend, no en la
config dinámica — ver `docs/CUSTOMIZATION.md` para el detalle:

- [ ] Logo en `frontend/public/` + referencia en `layout.tsx`.
- [ ] `frontend/src/i18n/messages/es.json` (`site.name`, `site.footer`, labels de navegación) y,
      si la tienda es multi-idioma, un `<idioma>.json` por cada idioma secundario de
      `i18n/routing.ts` — deben mantenerse paralelos entre idiomas (`npm run i18n:check`).
      **Una tienda de un solo idioma deja `locales: ["es"]` y borra `en.json`** (ver §7,
      «Instancia de un solo idioma»).
- [ ] Zona horaria de la tienda en `frontend/src/instance/registry.ts` (`timeZone`), si no es la
      de la plantilla.
- [ ] Páginas legales (`legal.*` en los mensajes i18n).
- [ ] `frontend/package.json` (`name`).
- [ ] `NEXT_PUBLIC_SITE_URL` y dominios/CORS reales antes de producción.

## 9. Producción

Sigue el checklist completo de `docs/GO-LIVE.md`: secretos reales (el arranque
aborta en producción si detecta secretos por defecto o demasiado cortos —
`frontend/src/lib/security/secret-guard.ts`), dominios/CORS, build
`--target prod`, TLS con Caddy, credenciales de pago reales.

## Referencia rápida del comando

```
wp hwe setup <archivo.json>
```

- `<archivo.json>`: ruta al archivo de configuración (ver
  `backend/scripts/instance.config.example.json`).
- Aplica todos los grupos no-secretos del esquema (`brand`, `social`, `legal`,
  `design`, `ecommerce`, `auth`, `i18n`, `seo`, `geo`, `backups`, y `instance` si tu instancia
  lo declara) y activa/desactiva los módulos de `modules` — "no-secreto"
  no es lo mismo que "público": `backups` no se expone en la API REST pública
  pero sí se puede seedear desde este JSON. Los campos de tipo `secret`
  (dentro de `payments`/`integrations`) se ignoran si vienen en el
  JSON — configúralos aparte desde wp-admin o variable de entorno.
- Es un *merge* profundo sobre la config existente, no un reemplazo total.
- Se registra solo si WP-CLI está presente
  (`backend/wp-content/mu-plugins/hwe-control-center.php`).

## Último paso: prepara las actualizaciones futuras

El clon del §1 ya dejó el repo del framework como remote `framework`. Comprueba que trae sus
tags y que la instancia parte de la release que dice `FRAMEWORK_VERSION`:

```bash
git fetch framework --tags
git merge-base --is-ancestor "v$(cat FRAMEWORK_VERSION)" HEAD && echo "historia compartida"
```

El procedimiento de actualización completo está en [`docs/UPGRADE.md`](UPGRADE.md).
