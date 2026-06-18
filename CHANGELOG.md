# Changelog

Todas las novedades relevantes de esta plantilla se documentan aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el
versionado sigue [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

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
