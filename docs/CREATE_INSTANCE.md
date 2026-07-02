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
`frontend/.env.local` (idempotente). `HWE_SECRETS_KEY` es opcional — si se
omite se deriva de `AUTH_KEY`; ver `docs/INSTALL.md §2` para generarla a mano
si tu instancia la necesita explícita. Alternativa manual (`openssl rand
...`) también en `docs/INSTALL.md §2`.

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
instancia: `brand` (nombre, tagline, descripción, URL, locale), `social`,
`legal`, `design.colors`/`design.typography` (design tokens — ver §4),
`ecommerce` (moneda, país, flags de reviews/wishlist/cupones/búsqueda), `seo`,
`shipping`, `geo`, `backups` (frecuencia/retención de los backups
automáticos — opcional, tiene defaults razonables). **No incluyas**
credenciales de pago o SMTP en este archivo:
esos campos son de tipo `secret` y se configuran aparte desde `wp-admin → HWE
Config` o por variable de entorno — el comando los ignora si no vienen en el
JSON.

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
por ejemplo, para la instancia "Siu":

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

## 7. El frontend (UI y vistas) es responsabilidad de esta instancia

El comando `wp hwe setup` cubre la config de marca/diseño, pero **no** genera ni mantiene
componentes de UI. Esto es intencional: el framework es backend + arquitectura
(WordPress headless + el BFF de Next.js en `frontend/src/app/api/*` y `frontend/src/lib/*`)
— ver `AGENTS.md §1.1`. Todo lo demás del frontend (`frontend/src/components/**` salvo
`ui/`, y todas las vistas bajo `frontend/src/app/[locale]/*`) se **hereda una sola vez** al
clonar y pasa a ser 100% de esta instancia desde ese momento: constrúyelo, cámbialo o
bórralo por completo, sin necesidad de aislarlo en una carpeta separada para "no chocar con
futuras actualizaciones del framework" — no habrá actualizaciones del framework a esa capa,
porque el framework no la mantiene.

Lo único que sí es un contrato estable (y a lo que cualquier vista, heredada o nueva, debe
ajustarse) es el backend/BFF: WPGraphQL/WooGraphQL, los endpoints REST bajo `app/api/*`, el
endpoint de config `/wp-json/hwe/v1/config` y los flags `ecommerce.*_enabled` — documentado en
**`docs/FRONTEND_CONNECT.md` Parte A**. Para decidir qué construir (entrevista de
características del negocio → plan de vistas, reutilizando lo heredado o desde cero): **`docs/FRONTEND_BUILD.md`**.

## 8. Checklist de rebranding restante (manual)

Lo que `wp hwe setup` no cubre porque vive en el código del frontend, no en la
config dinámica — ver `docs/CUSTOMIZATION.md` para el detalle:

- [ ] Logo en `frontend/public/` + referencia en `layout.tsx`.
- [ ] `frontend/src/i18n/messages/{es,en}.json` (`site.name`, `site.footer`,
      labels de navegación) — deben mantenerse paralelos entre idiomas.
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
  `design`, `ecommerce`, `seo`, `shipping`, `geo`, `backups`) — "no-secreto"
  no es lo mismo que "público": `backups` no se expone en la API REST pública
  pero sí se puede seedear desde este JSON. Los campos de tipo `secret`
  (dentro de `payments`/`integrations`) se ignoran si vienen en el
  JSON — configúralos aparte desde wp-admin o variable de entorno.
- Es un *merge* profundo sobre la config existente, no un reemplazo total.
- Se registra solo si WP-CLI está presente
  (`backend/wp-content/mu-plugins/hwe-control-center.php`).
