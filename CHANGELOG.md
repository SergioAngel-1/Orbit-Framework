# Changelog

Todas las novedades relevantes de esta plantilla se documentan aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el
versionado sigue [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

### Added
- **Plugin HWE Banners**: sistema de banners administrables desacoplado del Control
  Center. CPT `hwe_banner` con posiciones genéricas (hero/middle/bottom), slides
  ordenables con imagen desktop/móvil y overrides por idioma, REST pública
  `hwe-banners/v1/banners`, y lector server-only `lib/banners/` con revalidación ISR
  (tag `banners`). Extensible por hooks.

### Changed
- **HWE Control Center**: el grupo `banners` se reduce a `enabled` (activar/desactivar).
  Se elimina `banners.interval_ms`, `banners.slides` y el parser `lib/config/banners.ts`.

## [1.0.0] — 2026-07-14

### Añadido
- **Logo de marca** administrable desde HWE Control Center (`brand.logo`), consumido por el
  header heredado y expuesto en `/wp-json/hwe/v1/config`.
- **Menús gestionables desde WordPress**: locations `primary`/`footer` (+ variantes `_en`) en el
  tema headless, siembra de menú por defecto en `setup.sh`, helper de núcleo
  `lib/navigation/menu.ts` (`getMenu`) y consumo de referencia en header/footer heredados con
  fallback local. Contrato documentado en `docs/FRONTEND_CONNECT.md §A.6`.
- **Errores tipados** en el cliente GraphQL (`GraphQLClientError`: network/http/graphql/empty).
- **Guía de desarrollo de plugins** (`docs/PLUGIN_DEVELOPMENT.md`).
- **Ejemplo mínimo de integración** (`examples/minimal-consumer/`): consumidor Node del
  contrato (config pública + GraphQL + BFF) sin framework.
- **Banner Manager**: banners de portada administrables desde HWE Config (sección Banners),
  expuestos en la config pública, con parser en el núcleo (`parseBanners`) y el carrusel
  heredado (`HeroCarousel`) conectado como implementación de referencia.
- **Frontera núcleo/instancia ejecutable** (AGENTS.md §1.1): regla de lint que prohíbe que
  `lib/`, `app/api/` y `proxy.ts` importen de `components/**` (salvo `ui/`).
- **`docs/UPGRADE.md`**: procedimiento de actualización de instancias (remote `framework` +
  merge de tags de release), enlazado desde `README.md` y `docs/CREATE_INSTANCE.md`.

## [0.9.0] — 2026-07-14

### Corregido
- **ESLint funcional de nuevo**: `eslint.config.mjs` importa los flat configs nativos de
  `eslint-config-next` 16 (cargarlos vía FlatCompat rompía el validador eslintrc) y `eslint`
  baja a `^9` (rango soportado por los plugins que arrastra Next). Se retira `@eslint/eslintrc`.
- **Formato Prettier aplicado** a todo `src/` y `tests/` (122 archivos): `format:check` llevaba
  tiempo en rojo, invisible porque el lint estaba roto.

### Cambiado
- Se retiran `husky` y `lint-staged` (hook de pre-commit eliminado); la calidad la impone la CI.
- `CLAUDE.md` y el plan de implementación v1.0 (`docs/superpowers/plans/`) quedan versionados.

### Decisión de arquitectura: el framework es backend + BFF, no un generador de UI

#### Cambiado
- **Alcance del framework redefinido**: el núcleo reutilizable es WordPress headless
  (`backend/`) + el BFF de Next.js (`frontend/src/app/api/*`, `frontend/src/lib/*`) +
  `frontend/src/components/ui/*` (primitivas sin negocio). Todo lo demás del frontend
  (`components/**` salvo `ui/`, y las vistas de `app/[locale]/*`) pasa a considerarse código
  **heredado al clonar**, responsabilidad de cada instancia — el framework deja de prometer
  que se mantenga genérico o se actualice.
- **Documentación actualizada** para reflejar el límite: `AGENTS.md` (nueva §1.1), `README.md`,
  `docs/CREATE_INSTANCE.md` (§7 reescrita), `docs/FRONTEND_CONNECT.md` (reestructurado en
  Parte A — contrato backend/BFF estable — y Parte B — inventario de lo heredado, no
  mantenido), `docs/FRONTEND_BUILD.md`.

### Modo de desarrollo híbrido (WordPress + frontend nativos, DB/Redis en Docker)

#### Añadido
- **Documentación actualizada** para reflejar dos modos de desarrollo: Docker puro y
  híbrido (recomendado en Windows para evitar latencia de bind mounts).
- **Scripts de inicio local**: `start-local.bat` / `start-local.ps1` que establecen
  las variables de entorno necesarias para WordPress nativo y lo arrancan con
  `php -S`.
- **Puerto MariaDB 3307 expuesto** al host para que WordPress nativo pueda
  conectarse al contenedor Docker.

#### Cambiado
- `README.md`, `AGENTS.md`, `docs/INSTALL.md`, `docs/CONFIGURATION.md`:
  documentación actualizada con los dos modos de desarrollo.
- `docker-compose.yml`: puerto 3307:3306 para MariaDB, volumen wordpress_core
  compartido entre WordPress y wpcli, healthcheck optimizado.

### Auditoría y endurecimiento (remediación del plan `AUDITORIA-Y-PLAN-DE-IMPLEMENTACION.md`)

#### Corregido (flujos rotos — P0)
- **Checkout creaba pedidos de invitado** (`customer_id = 0`), lo que impedía al comprador
  pagar/ver su pedido y vaciaba el historial. Ahora el checkout reenvía el JWT a la Store API
  y, como salvaguarda idempotente, `ensureOrderOwner()` fija `customer_id` vía wc/v3.
- **CI E2E** arrancaba `next start` sin `next build` (falso verde): se añade el build previo.
- **Webhook `order.updated`** con lógica de "estado anterior" muerta: transición real vía
  Redis (`recordAndDiffStatus`) + dispatcher de efectos (`order-events.ts`).

#### Añadido (flujos incompletos — P1)
- **Verificación de email** (opcional): envío tras registro + expiración de token (24 h).
- **2FA endurecido**: secreto TOTP cifrado en reposo (AES-256-GCM), **códigos de recuperación**
  de un solo uso, `2fa-status` no público (secreto interno del BFF) y re-verificación al desactivar.
- **Analítica** real con consentimiento (Plausible/GA4) + eventos `add_to_cart` y `purchase`.
- **Meta de cliente atómica**: wishlist/direcciones serializadas con lock Redis (`lib/security/lock.ts`).

#### Seguridad (endurecimiento — P1/P2)
- **Guard de arranque** que aborta en producción con secretos por defecto/cortos (`secret-guard.ts`).
- **Revocación de sesión**: blocklist del access token en `logout`; `/api/auth/logout-all` rota
  el secreto JWT del usuario en WP.
- **SecretsStorage** del Control Center migrado a AES-256-GCM con clave dedicada `HWE_SECRETS_KEY`.
- **Rate-limit durable** en auth (fallback en memoria si Redis cae).
- **Anti-replay + rate-limit** en webhooks de pago y de WooCommerce (`lib/security/replay.ts`).
- **Anti-spoofing de `X-Forwarded-For`** (Next y WP) vía nº de proxies de confianza.

#### Infraestructura (P2)
- **Restauración probada**: `backend/scripts/restore.sh` + `docs/RUNBOOK.md`.
- **Caddy**: `/graphql` público restringido a POST/OPTIONS.
- **Hardening**: `security_opt: no-new-privileges` en todos los servicios de producción.
- **Sondas**: `/api/health/live` (liveness) y readiness estricta `GET /api/health?ready=1`.

#### Tests (P1)
- Unit (Vitest) de `origin`, `csrf`, `jwt`, `rate-limit`, `idempotency`, `lock`, `replay`,
  `secret-guard`, `request-ip`, `order-events`.
- E2E de **compra completa** (`tests/e2e/purchase.spec.ts`, opt-in `E2E_FULL=1`): regresión del fix de pedido-invitado.

#### Documentación (P1)
- `docs/RUNBOOK.md`, `docs/GO-LIVE.md`, `docs/COMPATIBILITY.md`; `AGENTS.md` actualizado al
  estado real (mapa del repo, flujos 6.4/6.5/6.6.1, gotchas, doc map §11).
- `.env.example` documenta las nuevas variables (analítica, 2FA, proxies, secretos, notificaciones).

#### Diferido a propósito
- **CSP con nonce** (rompería ISR/SSG) y **pasarela de pago real** (requiere credenciales y
  certificación). Ver el plan para el detalle.

### Añadido
- **Fase 8 — Calidad, CI/CD y observabilidad:**
  - Tests unitarios con **Vitest** (`tests/unit/`) para `lib/` (formato, firmas de
    pago, máquina de estados del pedido, validación, proveedor `noop`).
  - Scaffolding **Playwright** (`tests/e2e/`) con smoke tests (i18n, SEO, sonda de
    salud, barrera del BFF).
  - **CI** (`.github/workflows/ci.yml`): lint · type-check · formato · tests ·
    build + **secret scanning** (gitleaks).
  - **Prettier** + **lint-staged** + **husky** (hook pre-commit).
  - Sonda de salud `GET /api/health` (liveness/readiness con estado de Redis y WP)
    y `healthcheck` del servicio `frontend` en `docker-compose.yml`.
  - **Logging estructurado** con pino (`lib/observability/logger.ts`) e
    `instrumentation.ts` (captura de errores; punto de integración de Sentry).
  - **Dockerfile de producción** multi-stage (Next `output: "standalone"`, usuario
    no-root, healthcheck) y `target: dev` para el compose de desarrollo.
  - Script de **backup** de BD + uploads (`backend/scripts/backup.sh`).
- **Fase 9 — Empaquetado comercial:**
  - `LICENSE` (comercial) + `EULA.md`.
  - Documentación de cliente en `docs/`: `INSTALL`, `CONFIGURATION`,
    `CUSTOMIZATION`, `DEPLOYMENT`, `SECURITY`.
  - Páginas legales (privacidad, cookies, términos, devoluciones) localizadas
    bajo `/[locale]/legal/[slug]`, con enlaces en el pie.
  - White-label: marca del header desde `config/site.ts` / i18n; enlace "saltar al
    contenido" y mejoras de accesibilidad base.
  - Plantillas de issues y pull request (`.github/`).
  - Script de **datos demo** (`backend/scripts/seed-demo.sh`).

### Cambiado
- `next.config.mjs`: `output: "standalone"` para la imagen de producción.

## [1.0.0] — Base funcional (Fases 1–7)
- Fase 1 — Endurecimiento de seguridad base (cabeceras/CSP, CORS, hardening, Redis).
- Fase 2 — Autenticación JWT (cookies httpOnly + refresh transparente).
- Fase 3 — Proxy inverso a WooCommerce (BFF, `ck/cs` server-only, anti-IDOR).
- Fase 4 — CSRF + rate-limit + idempotencia.
- Fase 5 — E-commerce (catálogo ISR, carrito, checkout, cuenta, revalidación).
- Fase 6 — SEO + i18n (next-intl, sitemap, hreflang, JSON-LD).
- Fase 7 — Pagos: capa de pasarelas agnóstica (provider-agnostic) + proveedor `noop`.

[Sin publicar]: https://example.com/compare/v1.0.0...HEAD
[1.0.0]: https://example.com/releases/v1.0.0
