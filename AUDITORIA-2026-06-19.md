# Auditoría integral — Headless Web Ecosystem

> Fecha: 2026-06-19 · Alcance: infraestructura, base de datos, backend (WordPress headless),
> BFF/seguridad, e-commerce, pagos, frontend, i18n/SEO, observabilidad, calidad y DR.
> Método: lectura directa del código (no solo de la documentación). Cuando el árbol de
> trabajo difería del último commit, se auditó el estado **`HEAD`** (el intacto) y se
> documenta aparte el estado del árbol de trabajo.

---

## 0. Veredicto en una frase

El **núcleo es de calidad notable**: la arquitectura BFF, el modelo de seguridad, el flujo
de pagos por webhook y el plugin de configuración central están bien pensados y, en su estado
`HEAD`, son aptos para producción. Para pasar de **funcional** a **excepcional** faltan,
sobre todo: una **pasarela real**, **pruebas E2E
del flujo crítico en CI**, **resiliencia de datos (Redis AOF + backups offsite)**.

---

## 1. Remediación aplicada

Items ya resueltos y, por tanto, eliminados de los hallazgos (§3) y del plan (§4):

- **Árbol de trabajo corrupto** → restaurado desde `HEAD`; `AGENTS.md` y `README.md` saneados (referencias muertas eliminadas).
- **§3.1 · Pipeline de CD** → `.github/workflows/cd.yml`: build + push a GHCR, deploy a *staging* y *promote* a producción con aprobación manual (GitHub Environments).
- **§3.1 · Versiones de plugins WP** → fijadas en `setup.sh` (variables overridables + *fallback* a la última) y reflejadas en `docs/COMPATIBILITY.md`.
- **§3.1 · Hardening de contenedores** → `cap_drop`/`read_only`/`tmpfs` aplicados a `redis`/`frontend`/`caddy`/`backup` en `docker-compose.prod.yml`.
- **Guard anti-corrupción en CI** → job `config`: valida ambos `docker compose`, el `Caddyfile` y la sintaxis de los scripts de shell.
- **§3.3 · Email transaccional (SMTP)** → mu-plugin `hwe-smtp.php` (hook `phpmailer_init`), credenciales **configurables desde el Control Center** (contraseña cifrada AES-256-GCM), botón de email de prueba y `From` coherente con WooCommerce. Docs en `CONFIGURATION.md`/`GO-LIVE.md`.

---

## 2. Mapa completo de flujos identificados

### 2.1 Infraestructura / arranque
- **Boot dev**: `docker compose up -d` → `wpcli` (setup.sh: instala WP, WPGraphQL, WooCommerce, WooGraphQL, JWT, redis-cache, datos demo) → `generate-woo-keys.sh` → `.env` → `frontend`.
- **Boot prod**: build imagen standalone → `wpcli` → `compose.prod up` (Caddy TLS/HTTP3 + WP + Redis + frontend + backup cron).
- **Sondas**: liveness `GET /api/health/live`, readiness `GET /api/health?ready=1` (503 si dependencia caída), `GET /api/health` (200 con detalle).
- **Backups**: contenedor `backup` con cron 03:00 UTC → `backup.sh` (dump DB + tar uploads) → `./backups`. Restore manual `restore.sh`.
- **Correlación**: Caddy genera `X-Request-Id` → middleware lo propaga → clientes WP lo reenvían (AsyncLocalStorage).

### 2.2 Backend WordPress (headless)
- **Bloqueo de frontend nativo** + CORS GraphQL con allowlist (`headless-config.php`).
- **Hardening**: anti-enumeración de usuarios, pingbacks/XML-RPC off, errores de login genéricos, app passwords off (`security.php`).
- **Protección GraphQL**: límite de profundidad (15) y complejidad (1000), introspección off en prod, caché WPGraphQL (`graphql-protection.php`).
- **Rate-limit WP** de `/graphql` y `/wp-json` con XFF de confianza (`rate-limit.php`).
- **Store API**: nonce desactivado (seguro tras BFF) + CORS (`woocommerce-headless.php`).
- **Auth REST propia** `hwe/v1` (`hwe-auth.php`): forgot/reset password, verificación email (TTL 24h), 2FA (secreto AES-256-GCM, 10 códigos de recuperación hasheados), `me`, `logout-all` (rota user secret).
- **Control Center** (`hwe-control-center/`): config white-label, esquema único, walkers de render, secretos cifrados AES-GCM, exposición pública/privada, revalidación ISR on-demand.
- **Email branding** transaccional (`woocommerce-email-branding.php`).

### 2.3 BFF / seguridad (`frontend/src/lib/`)
- **Guard unificado** de escrituras: Origin → CSRF → rate-limit (`api/guard.ts`).
- **CSRF** signed double-submit (`security/csrf.ts`), **Origin barrier** en middleware + handler, **rate-limit** Redis con fallback en memoria (`strict`), **idempotencia** de checkout, **anti-replay** de webhooks, **lock** por usuario (lost-update), **secret-guard** de arranque, **request-ip** anti-spoofing (TRUSTED_PROXY_COUNT).

### 2.4 Autenticación
- Login/register/refresh/logout/me; tokens en cookies httpOnly; verificación JWT local con `jose`; **refresh transparente** en middleware; **revocación** (blocklist Redis); **2FA TOTP** (setup→activate→verify-2fa, recoveryCode); **verificación email** opcional no bloqueante; **change/forgot/reset password**.

### 2.5 E-commerce
- **Catálogo** (lectura GraphQL + ISR, tag `products`), **carrito** (Store API + Cart-Token cookie), **checkout** (idempotente, liga pedido al cliente vía JWT + `ensureOrderOwner` anti-IDOR), **cuenta** (pedidos, direcciones, wishlist, reseñas con moderación), **cupones**, **envío**.
- **Revalidación on-demand**: webhook Woo → verifica HMAC → `revalidateTag("products")`.

### 2.6 Pagos
- Capa agnóstica: `registry` + `PaymentProvider` (types/signature/orders) + providers (`noop` completo; `wompi`/`payu`/`bold` stubs).
- **create** (sesión + propiedad → `createCheckout`), **webhook/[provider]** (server-to-server, verifica firma + importe + moneda → `markOrderPaid`, idempotente + anti-replay), **return** (solo estado UX).
- **order-events**: webhooks `order-created`/`order-updated` → `recordAndDiffStatus` (Redis) → `dispatchOrderEffects` → `notifyOps` (webhook operativo opcional).

### 2.7 SEO / i18n / analítica
- Routing `[locale]` (es canónico, en `/en`), sitemap+hreflang, robots, manifest, JSON-LD; next-intl; analítica Plausible/GA4 con opt-in y eventos e-commerce.

### 2.8 Observabilidad / calidad
- Sentry (client/server/edge) + pino estructurado + correlación request-id. Tests unit (Vitest) + e2e (Playwright smoke + axe + purchase opt-in). CI: lint/types/format/test/build + gitleaks + e2e en PR.

---

## 3. Hallazgos por área — roto / incompleto / optimizable

Leyenda: 🔴 roto/bloqueante · 🟠 incompleto · 🟡 optimizable · 🟢 sólido.

### 3.1 Infraestructura y DevOps
- 🟡 **Hardening de contenedores (residual)**: aplicado `cap_drop`/`read_only`/`tmpfs` a `redis`/`frontend`/`caddy`/`backup`; faltan `db` y `wordpress` (su init/Apache necesita capacidades — endurecer tras probar en *staging*) y el **pinning de imágenes por digest** (`repo@sha256:…`, paso manual según arquitectura).
- 🟡 **Single-host Docker Compose**: sin orquestación ni escalado horizontal; MariaDB y Redis en instancia única (sin réplica). Aceptable para SMB; limitante para "excepcional".
- 🟡 **Sin WAF/CDN** al frente (solo Caddy). Falta protección de borde (Cloudflare/Fastly) para DDoS/cache global de assets.

### 3.2 Base de datos y resiliencia de estado
- 🟠 **Redis solo con RDB** (`--save 60 1`), **sin AOF** (`appendonly`). Idempotencia, revocación de sesión, anti-replay y rate-limit viven en Redis; un crash puede perder hasta ~60s de escrituras → ventana de **pedidos duplicados** o tokens no revocados. AOF (`appendfsync everysec`) cierra casi toda la ventana.
- 🔴/🟠 **Backups no se envían offsite**: `backup.sh` deja `.tar.gz`/`.sql.gz` en `./backups` (mismo host). El comentario dice "guárdalos en S3" pero **no hay automatización**. Pérdida del host = pérdida de los backups. **DR incompleto.**
- 🟡 **Backups sin cifrado ni verificación de restore automática**: añadir GPG/age + un *restore drill* periódico en CI/cron.
- 🟡 **MariaDB sin tuning** (buffer pool, etc.) ni `slow_query_log` para diagnóstico.

### 3.3 Backend WordPress
- 🟢 Hardening, CORS, protección GraphQL y auth REST son sólidos y bien comentados.
- 🟡 **Caché de objetos**: `redis-cache` se instala pero conviene confirmar que `wp redis enable` queda activo y monitorizado.
- 🟡 **Versionado de WooGraphQL/JWT**: ver §3.1 (pinning).

### 3.4 BFF / seguridad
- 🟢 Guard, CSRF firmado, idempotencia, anti-replay, secret-guard, anti-spoofing de IP: diseño correcto y con *defense-in-depth*.
- 🟡 **Fail-open generalizado** (rate-limit, replay, revocación, lock) ante caída de Redis. Es una decisión consciente y documentada, pero para "excepcional" conviene: (a) AOF (§3.2) y (b) un modo `strict` también para revocación/replay en endpoints sensibles (pagos), aceptando fail-closed allí.
- 🟡 **Rate-limit de ventana fija**: permite ráfagas en el borde de la ventana. Sliding window / token bucket (script Lua atómico en Redis) es más justo y resistente.
- 🟡 **CSP con `'unsafe-inline'`** en `script-src` (para no romper ISR). Endurecer a *nonce*/*hash* es la mejora pendiente; valorar `strict-dynamic` con render selectivo.
- 🟡 **Refresh token no rota por uso** (limitación del plugin WPGraphQL JWT). Sin *rotation* ni *reuse detection*, un refresh token robado es válido hasta expirar. Mitigación: acortar TTL del refresh + `logout-all`; ideal: rotación con detección de reuso.

### 3.5 Autenticación y cuenta
- 🟢 2FA cifrado, códigos de recuperación hasheados, logout-all, verificación email con expiración: muy completo.
- 🟡 **Verificación de email opcional**: correcto como default, pero falta el *switch* documentado para hacerla bloqueante (gate de checkout/zona privada) listo para activar.
- 🟡 **Sin lockout progresivo** tras N fallos de login (solo rate-limit por IP/usuario). Añadir *exponential backoff* o captcha tras umbral.
- 🟡 **Sin gestión de sesiones activas** visible para el usuario (listar/revocar dispositivos).

### 3.6 E-commerce y pagos (el corazón)
- 🔴 **Sin pasarela real**: solo `noop` (sandbox) está implementado de extremo a extremo; `wompi`/`payu`/`bold` son stubs que lanzan "no implementada". **No se puede cobrar de verdad.** Es el gap nº1 para ser un e-commerce funcional en producción.
- 🟠 **Checkout de invitado roto a medias**: `/api/store/checkout` permite pedido sin sesión (`customer_id = 0`), pero `/api/payments/create` exige `requireSession()` y propiedad del pedido. Resultado: **un invitado puede crear pedido pero no pagarlo** por el BFF. Hay que decidir y cerrar: o (a) bloquear checkout de invitado, o (b) soportar pago de invitado con token de pedido firmado.
- 🟠 **Sin limpieza de pedidos `pending` abandonados**: no hay cron que cancele/expire pedidos creados pero nunca pagados → acumulación y descuadre de stock reservado.
- 🟠 **`notifyOps` es fire-and-forget sin reintentos**: si el webhook operativo (ERP/Slack) falla, el evento se **pierde** (solo log). Falta patrón *outbox*/cola con reintentos y *dead-letter* para integraciones fiables.
- 🟠 **Solapamiento de fuentes de verdad del estado del pedido**: el pago lo confirma `payments/webhook` (`markOrderPaid`) y, por separado, `order-updated` (Woo) dispara `dispatchOrderEffects`. Está manejado con diffs en Redis, pero conviene documentar/test del orden de llegada y de la idempotencia combinada.
- 🟠 **Sin flujo de reembolso/cancelación desde el frontend** (solo wp-admin). Para soporte/SMB es deseable un endpoint BFF de reembolso con autorización.
- 🟡 **Sin búsqueda de catálogo real** (solo filtros por categoría/precio/orden). Para "excepcional", integrar búsqueda full-text (Typesense/Algolia/Meilisearch) o al menos search de Woo bien indexado.
- 🟡 **Sin manejo explícito de variaciones/stock en UI ante carrera** (se delega en Store API; correcto, pero falta feedback de "sin stock" robusto y test).

### 3.7 Frontend, i18n, SEO, accesibilidad
- 🟢 i18n paralelo, SEO (sitemap/hreflang/JSON-LD), analítica con consentimiento, axe en e2e: buena base.
- 🟡 **Sin verificación de claves i18n en CI**: añadir un check que falle si `es.json`/`en.json` divergen (claves faltantes).
- 🟡 **Accesibilidad**: axe cubre lo automatizable (~30-40% de WCAG). Falta auditoría manual de teclado/lector de pantalla y *focus management* en modales/drawer.
- 🟡 **Performance**: Lighthouse CI configurado, pero sin presupuesto de rendimiento que **bloquee** PRs; sin estrategia de CDN para imágenes (solo next/image contra WP).
- 🟡 **Solo es/en**: el formato de moneda/región está acoplado; preparar multi-moneda si el objetivo es LATAM amplio.

### 3.8 Observabilidad
- 🟢 Sentry + pino + request-id correlado: muy por encima de la media.
- 🟠 **Sin métricas (OTel) ni dashboards/SLO**: no hay visibilidad de latencia, throughput, tasa de error por endpoint, ni alertas proactivas más allá de Sentry. "Excepcional" necesita métricas + alerting (Prometheus/Grafana o un APM) y *uptime monitoring* externo.
- 🟡 **Sin métricas de negocio** (conversión, abandono de carrito, AOV) emitidas como eventos.

### 3.9 Calidad / pruebas / CI
- 🟠 **El flujo crítico de compra NO se prueba en CI**: `purchase.spec.ts` es opt-in (`E2E_FULL=1` + pila WP sembrada) y el job e2e de CI corre **sin** la pila WordPress (solo smoke + axe). La regresión más cara (login→carrito→checkout→pago→pedido) **no tiene puerta automática**. Solución: levantar WP+Woo+Redis en CI (docker compose / testcontainers) y correr el e2e completo contra `noop`.
- 🟠 **Cobertura no se exige**: en CI `test:coverage` corre con `|| true` (no bloquea) y los umbrales (60/50/60/60) son modestos. Subir umbrales y hacerlos bloqueantes.
- 🟡 **Sin tests de componentes React** ni de los Route Handlers (solo `lib/`). Falta también test de integración del BFF↔WP.
- 🟡 **Sin `npm audit`/SAST en CI** (hay Dependabot + gitleaks, pero no escaneo de vulnerabilidades de dependencias ni análisis estático de seguridad).
- 🟡 **Doc drift**: `AGENTS.md` referencia un archivo de auditoría que el árbol de trabajo borró (ver §1). Sincronizar docs ↔ realidad y añadir un check de enlaces rotos en docs.

---

## 4. Plan de implementación restante — organizado por áreas

> Prioridades: **P0** = bloqueante / pre-producción inmediato · **P1** = necesario para un
> producto excepcional y vendible con confianza · **P2** = mejora/diferenciación.

### Área B — Pagos (de sandbox a cobro real)
- **B1 (P0)** Implementar **una** pasarela real de extremo a extremo (recomendado Wompi o Bold para CO; o Stripe si el mercado lo permite): `createCheckout` con firma de integridad + `verifyWebhook` (checksum) + mapeo de estados. Reusar el contrato existente (cero cambios en el checkout).
- **B2 (P0)** Resolver el **checkout de invitado**: decidir política y cerrar el flujo (bloquear invitado, o emitir token de pedido firmado para que el invitado pueda pagar y consultar su pedido).
- **B3 (P1)** Cron de **expiración de pedidos `pending`** (cancelar tras N horas, liberar stock).
- **B4 (P1)** **Outbox/cola con reintentos** para `notifyOps` y efectos de integración (idempotente, con dead-letter). Persistir el evento antes de ACKear el webhook.
- **B5 (P2)** Endpoint BFF de **reembolso/cancelación** con autorización (rol staff) y trazabilidad.
- **B6 (P2)** Suite de pruebas de conciliación (importe/moneda, replays, estados negativos, llegadas fuera de orden).

### Área C — Email transaccional y deliverability
- **C1 (P1)** Configurar **SPF + DKIM + DMARC** en el DNS del dominio remitente (acción de *ops*; el panel y la guía ya existen) y verificar entrega real fuera de spam.
- **C2 (P2)** Prueba de entrega automatizada en *staging* (más allá del botón manual de email de prueba).

### Área D — Resiliencia de datos y DR
- **D1 (P0)** Activar **Redis AOF** (`--appendonly yes --appendfsync everysec`) en prod (y volumen persistente ya existe).
- **D2 (P0)** **Envío offsite de backups** (S3/B2/rclone) + **cifrado** (age/GPG), con retención y rotación.
- **D3 (P1)** *Restore drill* automatizado (cron/CI) que valide un backup reciente periódicamente; registrar resultado.
- **D4 (P2)** Tuning MariaDB (buffer pool, slow query log) y plan de réplica de lectura si crece la carga.

### Área E — CI/CD y calidad
- **E1 (P0)** **E2E del flujo de compra en CI**: levantar WP+Woo+Redis (compose/testcontainers), sembrar datos, correr `purchase.spec.ts` con `noop`. Puerta obligatoria en PR a `main`.
- **E2 (P1)** Hacer **cobertura bloqueante** (quitar `|| true`), subir umbrales por etapas (p. ej. 75/65/75/75) y reportar tendencia.
- **E3 (P2)** `npm audit`/Snyk + SAST (CodeQL) + check de paridad de claves i18n + check de enlaces de docs, todo en CI.

### Área F — Seguridad (endurecimiento avanzado)
- **F1 (P1)** **CSP basada en nonce/hash** (eliminar `'unsafe-inline'`) con estrategia que preserve ISR donde sea posible.
- **F2 (P1)** **Rotación de refresh tokens** con detección de reuso (o, si el plugin lo impide, TTL corto + rotación de user-secret programada).
- **F3 (P1)** Modo **fail-closed** opcional para replay/revocación en endpoints de pago (con AOF de respaldo).
- **F4 (P2)** **Lockout progresivo/captcha** en login tras N fallos; lista de sesiones activas revocables por el usuario.
- **F5 (P2)** Completar hardening: `cap_drop`/`read_only` en `db` y `wordpress` (con pruebas), **pinning de imágenes por digest** y **WAF/CDN** de borde.

### Área G — Observabilidad y operación
- **G1 (P1)** **Métricas OTel** (latencia, error rate, throughput por endpoint) + dashboards (Grafana/APM) + alertas proactivas.
- **G2 (P1)** **Uptime monitoring externo** contra `/api/health?ready=1` con alerta.
- **G3 (P2)** **Métricas de negocio** (conversión, abandono, AOV) como eventos; panel ejecutivo.

### Área H — E-commerce y experiencia (diferenciación)
- **H1 (P1)** **Búsqueda real** del catálogo (Meilisearch/Typesense/Algolia) con indexación por webhook.
- **H2 (P2)** **Multi-moneda / multi-región**; verificación de email como gate activable.
- **H3 (P2)** Accesibilidad WCAG manual (teclado, lector de pantalla, focus management) + presupuesto Lighthouse bloqueante en CI.
- **H4 (P2)** Tests de componentes React + visual regression de las vistas críticas.

---

## 5. Orden recomendado (camino crítico)

1. **B1, B2** (pasarela real + checkout invitado) — sin esto no es un e-commerce real.
2. **D1, D2** (AOF + backups offsite) y **E1** (E2E de compra en CI) — sin esto no es *confiable*.
3. **C2, B3, B4** — operación robusta y fiable.
4. **F1–F3, G1–G2, E2** — endurecimiento y visibilidad de nivel producción.
5. **H1–H4, F4–F5, D3–D4, G3, B5–B6, E3** — de "muy bueno" a **excepcional**.

---

## 6. Resumen del estado real (matriz corregida vs. `AGENTS.md`)

| Área | Estado AGENTS.md | Estado real auditado |
|------|------------------|----------------------|
| Seguridad base / BFF | ✅ | 🟢 sólido (optimizable: CSP nonce, fail-closed selectivo) |
| Auth JWT + 2FA | ✅ | 🟢 sólido (falta rotación refresh, lockout) |
| E-commerce núcleo | ✅ | 🟢 con **checkout de invitado incompleto** 🟠 |
| Pagos | 🧪 stubs | 🔴 **sin pasarela real** (bloqueante producción) |
| Email transaccional | (implícito por Woo) | 🟢 SMTP configurable desde el panel (`hwe-smtp.php`); 🟡 falta SPF/DKIM en DNS (ops) |
| Webhooks de pedido/efectos | 🟡 | 🟠 sin outbox/reintentos; sin expiración de pending |
| Backups / DR | ✅ | 🟠 **sin offsite**; Redis sin AOF |
| Observabilidad | ✅ (OTel pendiente) | 🟢 logs/Sentry; 🟠 sin métricas/alertas |
| Calidad / CI | ✅ | 🟢 CD añadido; 🟠 compra no testeada en CI; cobertura no bloqueante |
| Reproducibilidad backend | — | 🟢 versiones de plugins fijadas en `setup.sh` |
| Infra / DevOps (CD · hardening) | — | 🟢 CD + hardening base + guard de config en CI; 🟡 digest pinning, db/wordpress, WAF/CDN |

---

*Esta auditoría se realizó sobre el estado `HEAD` del repositorio. El diseño de la plantilla
es maduro; los pendientes son los esperables para cerrar el salto a producción real y, más
allá, a un producto excepcional.*
