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

```bash
git clone <repo-del-framework> <cliente>/instancia
cd <cliente>/instancia
```

Si la instancia va a vivir en su propio repositorio (recomendado, para no
arrastrar el historial del framework base), reinicia el control de versiones:

```bash
rm -rf .git && git init
```

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
`NEXT_PUBLIC_SITE_NAME`, `NEXT_PUBLIC_SITE_URL`.

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
- `i18n.locales`: los idiomas **secundarios** (p. ej. `["en", "fr"]`), sin el de `brand.locale`.
  Deben coincidir con `frontend/src/i18n/routing.ts`: si divergen, el arranque del frontend lo
  avisa en el log. Con ellos, los campos traducibles (`tagline`, `faq`…) ganan una variante
  `<campo>_<idioma>`.
- `ecommerce`: `currency` (**obligatoria**: sin moneda no hay precios ni pagos), `country`
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
`consent-log`, `site-reviews`, `coupon-rules`, `customer-identity` y `external-integration`
(qué hace cada uno: `docs/CONFIGURATION.md`, «Módulos»).

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
docker compose run --rm wpcli wp hwe setup /scripts/instance.config.json
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
- [ ] `frontend/src/i18n/messages/{es,en}.json` (`site.name`, `site.footer`,
      labels de navegación) — deben mantenerse paralelos entre idiomas.
- [ ] Un `frontend/src/i18n/messages/<idioma>.json` por cada idioma de `i18n.locales` y su
      alta en `routing.ts` (`npm run i18n:check` comprueba la paridad).
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

Configura el repo del framework como remote para poder traer sus releases:

```bash
git remote add framework <URL-del-repo-del-framework>
git fetch framework --tags
```

El procedimiento de actualización completo está en [`docs/UPGRADE.md`](UPGRADE.md).
