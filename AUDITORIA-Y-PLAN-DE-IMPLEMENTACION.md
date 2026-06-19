# Auditoría integral y plan de implementación restante
### Headless Web Ecosystem — WordPress/WooCommerce headless + Next.js 15 BFF

> Auditoría realizada sobre el estado del repositorio a fecha **2026-06-18**, revisando
> infraestructura (Docker dev/prod, Caddy), backend (WordPress mu-plugins, HWE Control
> Center, scripts), el BFF completo (auth, store, payments, webhooks), la capa `lib/`,
> i18n/SEO, CI/CD, observabilidad y tests. Actúa como hoja de ruta para llevar la
> plantilla de **funcional** a **excepcional**.

---

## Estado de implementación

> Actualizado: **2026-06-19**. **Plan completado** (§1–§7), con dos diferidos a propósito
> (CSP nonce §3.2 y pasarela real §2.4) y mejoras menores no bloqueantes anotadas por área.

- **P0 — Flujos rotos (§1): ✅ COMPLETADO.** Los tres bloqueantes están resueltos en código
  (detalle marcado en cada subsección de §1 y en la tabla §9). Pendiente: ejecutar el gate
  local/CI `npx tsc --noEmit && npx next lint && npx next build` y un e2e de compra real.
- **P1 — Flujos incompletos (§2): ✅ MAYORMENTE COMPLETADO.** 2.1 (verificación email
  *opcional*), 2.2 (2FA endurecido), 2.3 (Plausible + eventos), 2.5 (efectos de webhook) y
  2.6 (meta atómica) implementados. **2.4 (pasarela real) diferido por decisión** (se mantiene
  `noop`). Ver detalle por ítem en §2.
- **P1/P2 — Endurecimiento (§3): ✅ COMPLETADO** (3.1, 3.3, 3.4, 3.5, 3.6, 3.7). **3.2 (CSP
  nonce) diferido a propósito** (rompería el ISR/SSG, pilar de la plantilla). Ver detalle en §3.
- **P2 — Infraestructura (§4): ✅ MAYORMENTE COMPLETADO** (restore+runbook, Caddy GraphQL,
  no-new-privileges, liveness/readiness). Pendiente menor: métricas, export de config, cap_drop.
- **P1 — Tests/CI (§6): ✅ MAYORMENTE COMPLETADO** (unit de seguridad/auth, e2e de compra
  opt-in, CI con build+cobertura). Pendiente: fijar umbral de cobertura como gate y ampliar axe.
- **P1 — Documentación/empaquetado (§7): ✅ COMPLETADO** (AGENTS al día, CHANGELOG,
  COMPATIBILITY, GO-LIVE, RUNBOOK, índice en README).
- **P2 — Observabilidad (§5): ✅ COMPLETADO** (correlación request-id Caddy→Next→WP, eventos de
  alerta con `guard.blocked`, `docs/OBSERVABILITY.md` con retención/PII). Pendiente menor:
  métricas OpenTelemetry/Prometheus.
- **AGENTS.md**: ✅ actualizado al estado real (tabla, flujos 6.3/6.4/6.5/6.6.1, gotchas, doc map §11).

---

## 0. Resumen ejecutivo

La plantilla es **arquitectónicamente sólida y muy por encima de la media** de los temas
headless comerciales: el patrón BFF está bien implementado, los secretos viven server-only,
el guard unificado (Origin → CSRF → rate-limit) se aplica de forma consistente, los pagos
tienen una capa agnóstica correcta cuya “prueba de pago” es el webhook firmado, y hay
hardening real tanto en Next como en WordPress (CORS allowlist, límites de profundidad/
complejidad GraphQL, anti-enumeración, rate-limit doble capa).

**Pero `AGENTS.md` está desactualizado y es engañoso en un punto crítico**: declara el
proyecto “completo, sin fases pendientes”, mientras que existen módulos enteros sin
documentar (2FA, verificación de email, wishlist, reseñas, direcciones, cupones, envío,
HWE Control Center) y, sobre todo, **hay un flujo de extremo a extremo roto** que impide a
un usuario autenticado pagar o ver el pedido que acaba de crear.

Hallazgo más grave (detalle en §1): el checkout crea **pedidos de invitado**
(`customer_id = 0`) porque la Store API se invoca solo con `Cart-Token`, sin asociar la
sesión del usuario. Como las comprobaciones anti-IDOR exigen `order.customer_id ===
session.userId`, **el comprador nunca pasa esas comprobaciones**.

Clasificación de hallazgos: **3 críticos**, **6 incompletos**, **9 de
endurecimiento/optimización**, más deuda de tests y documentación.

---

## 1. Flujos rotos (bloqueantes) — Prioridad P0

### 1.1 El checkout crea pedidos de invitado → rompe pago, historial y detalle de pedido
**Severidad: crítica. Es el defecto funcional número uno. — ✅ RESUELTO (2026-06-18).**

> **Implementado:** `store-client.ts` acepta `authToken` y reenvía el JWT del usuario a la
> Store API (vía 1). `checkout/route.ts` obtiene la sesión (opcional; checkout de invitado
> intacto) y, tras crear el pedido, llama a `ensureOrderOwner()` que **fija `customer_id` vía
> wc/v3 de forma idempotente** (vía 2, salvaguarda determinista). Un fallo de vinculación se
> registra (`checkout.link_owner_failed`) sin romper la compra. Pendiente: e2e de compra real.

`POST /api/store/checkout` llama a la Store API (`storeFetch("/checkout", …)`) pasando
únicamente el `Cart-Token` de la cookie. No se reenvía el JWT del usuario ni se fija
`customer_id`, y `checkoutSchema` tampoco lo contempla. Resultado: WooCommerce crea el
pedido como **invitado** (`customer_id = 0`).

Consecuencias en cadena, todas observadas en el código:

- `POST /api/payments/create` exige `order.customer_id === Number(session.userId)`; con
  `customer_id = 0` siempre devuelve **404** → *el comprador no puede iniciar el pago de su
  propio pedido*.
- `GET /api/store/orders/[id]` aplica la misma comprobación → **404** al ver el detalle.
- `getCustomerOrders()` (`lib/account/data.ts`) filtra `wc/v3` por `customer: userId` → el
  **historial de pedidos del usuario sale vacío**.

**Acción.** Asociar el pedido al cliente autenticado. Opciones, de mayor a menor robustez:
1. Autenticar la sesión de carrito/checkout como el usuario: reenviar el JWT en la llamada
   a la Store API (`Authorization: Bearer <authToken>`) además del `Cart-Token`, de modo
   que WooCommerce ligue el pedido al `customer_id` real. *(Requiere validar que el plugin
   JWT de WPGraphQL autentique también la Store API; si no, usar la opción 2.)*
2. Tras crear el pedido, hacer un `PUT /orders/{id}` con `wc/v3` fijando
   `customer_id = session.userId` dentro del mismo handler, antes de responder.
3. Como mínimo, registrar el `order_id` ↔ `userId` en un almacén propio (Redis/meta) y usar
   ese mapa en las comprobaciones de propiedad en vez de `customer_id`.

Recomendado: **opción 1 si es viable, con la opción 2 como salvaguarda** idempotente.
Añadir test e2e que cubra: login → add to cart → checkout → ver pedido → pagar.

### 1.2 El job E2E de CI arranca `next start` sin `next build`
**Severidad: alta (pipeline roto / falsa señal verde). — ✅ RESUELTO (2026-06-18).**

> **Implementado:** `ci.yml` ahora ejecuta `npm run build` antes de `next start`, sube el
> timeout de `wait-on` a 60s y propaga el env a los tests. Mejora pendiente (P2): e2e con la
> pila Docker sembrada para cubrir flujos que dependen de WordPress real.

En `.github/workflows/ci.yml`, el job `e2e` ejecuta `npx next start --port 3000` pero
**nunca corre `next build`** antes. `next start` falla sin un build de producción
(`.next`), así que el job no prueba lo que cree probar (o falla de forma intermitente).
Además levanta el server sin la pila de WordPress, por lo que cualquier prueba más allá del
*smoke* no es fiable.

**Acción.** Añadir `npm run build` antes de `next start` (o reutilizar el artefacto del job
`frontend`). Para pruebas e2e realistas, levantar la pila con `docker compose` (servicios
`db`, `wordpress`, `redis`, `frontend`) y `wait-on` contra `/api/health` con WP sembrado.

### 1.3 Webhook `order-updated`: lógica de “estado anterior” muerta
**Severidad: media (incorrección lógica; hoy solo loguea). — ✅ RESUELTO (2026-06-18).**

> **Implementado:** eliminada la variable engañosa. Nuevo módulo
> `lib/woocommerce/order-events.ts`: `recordAndDiffStatus()` persiste el estado en Redis y
> calcula la **transición real**; `dispatchOrderEffects()` centraliza los efectos (alta,
> pagado, cancelado/fallido/reembolsado) como ganchos documentados para email/inventario/ERP.
> Ambos handlers validan el payload y delegan en el dispatcher. Los **envíos reales** (SMTP,
> inventario) siguen como ganchos a conectar según negocio (ver §2.5).

En `app/api/webhooks/woocommerce/order-updated/route.ts`:
```ts
const previousStatus = payload.status; // WooCommerce envía el estado actual
```
`previousStatus` siempre es igual a `status`; cualquier integración futura basada en la
transición de estado actuará sobre datos falsos. Ambos webhooks de pedido (`order-created`
y `order-updated`) son además **stubs** (solo `logger.info` + `TODO`): no disparan email,
inventario, ERP ni facturación.

**Acción.** Eliminar la variable engañosa; si se necesita la transición, conservar el
estado previo en un almacén (Redis/meta) y comparar. Definir qué efectos deben tener estos
webhooks o documentarlos explícitamente como extensibles.

---

## 2. Flujos incompletos — Prioridad P1

### 2.1 Verificación de email no se aplica ni se dispara
**— ✅ RESUELTO (opcional) (2026-06-19).**

> **Implementado:** `register/route.ts` dispara el envío de verificación tras el alta
> (best-effort, no bloquea). En WP (`hwe-auth.php`) el token de verificación ahora lleva
> **timestamp y expira a 24 h**. Política **opcional** elegida: no se bloquea compra/login;
> la UI permite verificar/reenviar y `me` expone `email_verified`. Para hacerla obligatoria,
> basta bloquear checkout/zona privada cuando `!email_verified`.

El registro (`/api/auth/register`) hace **auto-login sin verificar el email**, y el flujo de
registro **nunca llama** a `send-verification`. La marca `hwe_email_verified` se calcula y
expone, pero **nada bloquea** a un usuario no verificado (ni login, ni checkout). El token de
verificación (`hwe_email_verification_token`) **no tiene expiración ni timestamp**.

**Acción.** Decidir la política (verificación obligatoria u opcional). Si obligatoria:
disparar el email tras registrar, bloquear checkout/zona privada hasta verificar, y caducar
el token (p. ej. 24 h con timestamp). Reflejarlo en la UI (`verify-email`, `resend-verification`).

### 2.2 2FA: almacenamiento y robustez
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** (1) el secreto TOTP se **cifra en reposo con AES-256-GCM** en WP
> (`hwe-auth.php`, clave dedicada `HWE_2FA_KEY` con fallback a `AUTH_KEY`; lectura compatible
> con secretos legacy). (2) **Códigos de recuperación** (10, hasheados, de un solo uso):
> generados al activar y devueltos una vez a la UI (`two-factor-setup.tsx`), consumibles en
> login (`verify-2fa`, campo `recoveryCode`). (3) `2fa-status` **ya no es público**: exige el
> secreto interno del BFF (`X-HWE-Internal-Secret`) o el propio id. (4) **Desactivar exige
> re-verificación** (código TOTP o de recuperación). Bonus: se autorizó el host del QR en la CSP.

- El secreto TOTP se guarda **en texto plano** en `user_meta` (`hwe_2fa_secret`) y se
  recupera por REST para verificarlo en Node. Debería cifrarse en reposo (ver §3.4, ya
  existe `SecretsStorage` con AES).
- **No hay códigos de recuperación/backup**: si el usuario pierde el dispositivo, queda
  fuera. Imprescindible para “excepcional”.
- `GET /auth/2fa-status/{user_id}` es **público** (`__return_true`) → permite enumerar qué
  IDs de usuario tienen 2FA. Debería ir tras la sesión o, al menos, no aceptar IDs
  arbitrarios.
- `disable` exige sesión pero no re-verificación (contraseña o código TOTP vigente).

### 2.3 Analítica / consentimiento: script real sin cablear
**— ✅ RESUELTO (Plausible) (2026-06-19).**

> **Implementado:** la carga del script ya estaba condicionada al consentimiento; se añadió
> el **helper `trackEvent()`** y los eventos e-commerce **`add_to_cart`** (cart-context) y
> **`purchase`** (`checkout/return` vía `PurchaseTracker`). Los **hosts del proveedor se
> autorizan en la CSP** (`next.config.mjs` → `getAnalyticsHosts`). Proveedor elegido:
> **Plausible** (`NEXT_PUBLIC_ANALYTICS_PROVIDER=plausible` + `NEXT_PUBLIC_PLAUSIBLE_DOMAIN`).

El banner opt-in (`components/analytics/`) existe, pero el script real de GA4/Plausible
sigue siendo un **stub** (confirmado en `AGENTS.md §6.7`). El consentimiento no activa
ninguna carga condicional real.

**Acción.** Cablear el proveedor elegido con carga condicionada al consentimiento (Consent
Mode v2 si GA4), y conectar eventos de e-commerce (view_item, add_to_cart, purchase).

### 2.4 Pasarelas de pago reales: solo `noop` funciona
**— ⏸️ DIFERIDO POR DECISIÓN (2026-06-19).** Se mantiene `noop` en este pase; integrar una
pasarela real (Wompi/PayU/Bold) requiere credenciales y certificación en vivo y queda como
trabajo siguiente. La arquitectura agnóstica ya permite añadirla sin tocar el checkout.

`wompi`, `payu` y `bold` son **stubs**. La arquitectura agnóstica es correcta, pero una
plantilla comercializable LATAM necesita **al menos una pasarela real** integrada y probada
end-to-end (firma de webhook, conciliación importe/moneda, retorno UX), que sirva además de
referencia para implementar las demás.

### 2.5 Webhooks de pedido sin efectos (ver §1.3)
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** `dispatchOrderEffects()` (`order-events.ts`) reacciona a transiciones
> reales (alta, **pagado**, cancelado/fallido/reembolsado). Se aclara que el **email al cliente
> lo emite WooCommerce de forma nativa** al cambiar el estado (no se duplica). Para integraciones
> operativas (Slack/ERP/cola) se añadió `notifyOps()` con webhook configurable
> (`ORDER_NOTIFICATION_WEBHOOK_URL`), best-effort y sin afectar el ACK del webhook.

Email transaccional de confirmación, sincronización de stock, factura/comprobante… hoy son
`TODO`. Para una tienda real son esperables al menos: email de confirmación y de cambio de
estado.

### 2.6 Operaciones sobre meta de cliente no atómicas
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** nuevo `lib/security/lock.ts` (lock distribuido Redis `SET NX` con
> liberación segura por token y fail-open). Las secciones de lectura-modificación-escritura de
> `wishlist` y `addresses` van envueltas en `withLock("wishlist:<id>" / "addresses:<id>")`,
> serializando por usuario y evitando lost-update por doble pestaña/clic.

Wishlist, direcciones y (parcialmente) cliente usan **read-modify-write** sobre
`customer.meta_data` vía `wc/v3` (leer cliente → mutar array → `PUT`). Dos peticiones
concurrentes pueden **pisarse** (lost update). Bajo carga real (doble pestaña, doble clic)
se pierden entradas.

**Acción.** Encapsular en un endpoint WP propio que haga la operación atómica (add/remove
sobre meta con bloqueo), o serializar por usuario con un lock en Redis.

---

## 3. Endurecimiento de seguridad — Prioridad P1/P2

### 3.1 Arranque seguro: rechazar secretos por defecto en producción
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** `lib/security/secret-guard.ts` (`assertSecrets`) invocado desde
> `instrumentation.ts`. En producción **aborta el arranque** si `GRAPHQL_JWT_AUTH_SECRET_KEY`,
> `CSRF_SECRET`, `WC_WEBHOOK_SECRET` o `HWE_REVALIDATION_SECRET` siguen con valor por defecto o
> miden <24 chars (y exige `WC_CONSUMER_KEY/SECRET`). En dev solo avisa. Documentado en `.env.example`.

Todo el `.env.example` usa valores `changeme-*` y `noop-sandbox-secret`. No hay un **guard
de arranque** que impida levantar en `production` con secretos por defecto (JWT, CSRF,
webhook, revalidation). Es el fallo de despliegue más común y más caro.

**Acción.** Añadir una verificación en `instrumentation.ts` (o un script `predeploy`) que
**aborte** el arranque si `NODE_ENV=production` y algún secreto sigue con el valor por
defecto o por debajo de una longitud mínima. Replicar en WordPress (`wp-config`/mu-plugin).

### 3.2 CSP con `'unsafe-inline'` en `script-src`
**— ⏸️ DIFERIDO A PROPÓSITO (2026-06-19).** Migrar a CSP con nonce obliga a render dinámico y
**rompería el ISR/SSG**, que es un pilar de esta plantilla. Se mantiene `'unsafe-inline'` como
decisión consciente (resto de vectores bien bloqueados) y queda como endurecimiento opcional
para despliegues que renuncien a ISR. *(Se añadieron, eso sí, solo los hosts de analítica
necesarios a la CSP, sin abrir comodines.)*

Decisión consciente y documentada (mantener ISR/SSG), pero deja superficie XSS. Es el techo
de seguridad de la cabecera más importante.

**Acción (mejora):** migrar a CSP basada en **nonce** para scripts (estrategia de Next con
`middleware` + `headers`), aceptando el coste de render dinámico donde aplique, o usar
hashes para los scripts inline conocidos. Documentar el trade-off por ruta.

### 3.3 Sesión: sin revocación server-side ni rotación de refresh
**— ✅ RESUELTO (parcial documentado) (2026-06-19).**

> **Implementado:** `lib/auth/revocation.ts` (blocklist Redis de hash del token con TTL=exp).
> `logout` **revoca el access token** y `getSession` lo comprueba (fail-open si Redis cae).
> Nuevo `app/api/auth/logout-all` + endpoint WP `auth/logout-all` que **rota el secreto JWT
> del usuario** (invalida sus refresh tokens). *Pendiente menor:* rotación de refresh en **cada
> uso** (requiere cambio en el plugin WPGraphQL JWT); hoy la invalidación global es vía logout-all.

`logout` solo borra cookies; el JWT de acceso **sigue siendo válido hasta `exp`** y el
refresh token **no rota** (documentado). No hay lista de revocación ni invalidación global
salvo rotar el “user secret” en WP.

**Acción.** Para “excepcional”: lista de revocación (jti en Redis con TTL = exp) consultada
en `requireSession`, rotación de refresh en cada uso, y endpoint “cerrar todas las sesiones”.

### 3.4 `SecretsStorage`: AES-256-CBC sin autenticación y clave acoplada al JWT
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** `SecretsStorage.php` migrado a **AES-256-GCM** (cifrado autenticado, formato
> `v2:`) con **clave dedicada** `HWE_SECRETS_KEY` (fallback `AUTH_KEY`/`SECURE_AUTH_KEY`),
> desacoplada del secreto JWT. Lectura **compatible con el formato legacy CBC** para migración
> transparente (los valores antiguos se descifran con la clave anterior).

El cifrado de secretos del Control Center usa **AES-256-CBC sin HMAC** (cifrado maleable, no
autenticado) y deriva la clave del `GRAPHQL_JWT_AUTH_SECRET_KEY`: **rotar el secreto JWT
vuelve indescifrables** todos los secretos guardados.

**Acción.** Migrar a **AES-256-GCM** (autenticado) y desacoplar la clave de cifrado (clave
dedicada, p. ej. `HWE_SECRETS_KEY`), con procedimiento de rotación documentado.

### 3.5 Rate-limit fail-open en endpoints de autenticación
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** `rateLimit()` acepta `{ strict: true }` y, si Redis no está disponible, usa
> un **fallback en memoria** (ventana fija por instancia) en vez de fail-open. Aplicado vía
> `guardMutation` en login, registro, verify-2fa, 2FA setup/activate/disable, forgot/reset y
> logout-all. Los webhooks (§3.6) llevan además su propio rate-limit por IP.

Es razonable como mitigación general, pero login/2FA/forgot-password idealmente quieren un
límite **durable** que no se evapore si Redis cae. Hoy, con Redis caído, no hay límite.

**Acción.** Mantener fail-open en lo no crítico; añadir un segundo limitador (o
fail-closed con backoff) específico para auth, y limitar también `2fa-status` y los webhooks.

### 3.6 Webhooks (pagos y Woo) sin anti-replay ni rate-limit
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** `lib/security/replay.ts` (`markEventOnce`, Redis `SET NX` sobre el hash del
> cuerpo) aplicado en el webhook de pagos y en `order-created`/`order-updated`, descartando
> reenvíos del mismo evento firmado. Cada endpoint añade **rate-limit por IP**. Fail-open si
> Redis cae (la idempotencia de negocio sigue como segunda línea).

La autenticidad la da la firma y la idempotencia el estado del pedido, lo cual es correcto,
pero no hay protección de **replay temporal** (timestamp + ventana) ni rate-limit del
endpoint. Un atacante con un evento firmado capturado podría reintentarlo.

**Acción.** Validar timestamp del evento dentro de una ventana corta y registrar IDs de
evento ya procesados (nonce store con TTL).

### 3.7 Confianza en `X-Forwarded-For`
**— ✅ RESUELTO (2026-06-19).**

> **Implementado:** tanto `lib/http/request-ip.ts` (Next, `TRUSTED_PROXY_COUNT`) como
> `rate-limit.php` (WP, `HWE_TRUSTED_PROXY_COUNT`) toman la IP **a N posiciones del final** del
> XFF (la añadida por el proxy de confianza), no el primer valor (falsificable). Por defecto 1
> (Caddy). Documentado en `.env.example`.

`rate-limit.php` (WP) y `lib/http/request-ip.ts` confían en el primer valor de
`X-Forwarded-For`. Detrás de Caddy es correcto, pero sin restringir a IPs de proxy de
confianza el cliente puede **falsear su IP** y evadir el rate-limit.

**Acción.** Configurar lista de proxies de confianza (Caddy fija la IP real; descartar
`XFF` entrante del cliente) y documentarlo.

---

## 4. Infraestructura y despliegue — Prioridad P2
**— ✅ MAYORMENTE COMPLETADO (2026-06-19).**

> **Implementado:**
> - **Restore probado:** `backend/scripts/restore.sh` (DB + uploads, con confirmación y
>   `FLUSHALL` de Redis) y **`docs/RUNBOOK.md`** con procedimiento de restauración,
>   simulacro trimestral (RTO/RPO), rotación de secretos e incidentes.
> - **Caddy:** `/graphql` público restringido a **POST/OPTIONS** (GET → 405) y documentado
>   cómo eliminarlo para headless puro; nota sobre `/wp-json` (lo requiere wp-admin).
> - **Hardening contenedores:** `security_opt: no-new-privileges` en **todos** los servicios
>   de prod; `cap_drop`/`read_only`/pin por digest documentados como siguiente paso.
> - **Salud:** `/api/health/live` (liveness) + readiness estricta `GET /api/health?ready=1`
>   (503 si `degraded`).
>
> **Pendiente (no bloqueante):** export/import versionado de la config del Control Center
> entre entornos; métricas formales (OpenTelemetry/Prometheus) y check sintético externo;
> aplicar `cap_drop`/`read_only`/digests tras probarlos en staging.

La base es buena: compose dev y prod separados, Caddy con TLS automático, healthchecks,
`deploy` limits, servicio de backup (`backup.sh`, cron, entrypoint), `.env.prod.example`.
Brechas a cerrar:

- **Restore probado**: existen backups; falta un procedimiento de **restauración**
  documentado y verificado (DB + `wp-content` + secretos), idealmente como script y como
  paso de runbook.
- **Caddy expone `/graphql` y `/wp-json/*` públicamente** (fallback). En headless puro, el
  BFF habla con WP por red interna; exponer GraphQL al público amplía superficie (mitigado
  por CORS/límites, pero conviene revisar si el fallback es necesario o restringirlo).
- **Hardening de contenedores**: revisar `read_only`, `cap_drop`, usuarios no-root y
  `no-new-privileges` en prod (parcialmente presente). Pinnéate versiones por digest.
- **Migraciones/seed reproducibles**: `setup.sh`/`seed-demo.sh` sirven para arranque; falta
  estrategia para promover cambios de configuración del Control Center entre entornos
  (export/import versionado).
- **Salud y métricas**: solo `/api/health`. Añadir readiness vs liveness diferenciados y,
  para “excepcional”, métricas (OpenTelemetry/Prometheus) y un check sintético externo.

---

## 5. Observabilidad y operación — Prioridad P2
**— ✅ COMPLETADO (2026-06-19).**

> **Implementado:**
> - **Correlación `request-id` de extremo a extremo**: Caddy genera `X-Request-Id`
>   (`{http.request.uuid}`) y lo reenvía; el `middleware` lo reutiliza/crea, lo propaga a los
>   handlers y lo **devuelve en la respuesta**; un AsyncLocalStorage
>   (`lib/observability/request-context.ts`) lo propaga a las llamadas a WordPress
>   (graphql/wc/store clients añaden `X-Request-Id`). El webhook de pago ya corre dentro del
>   contexto. Helpers en `lib/observability/request-id.ts` y `requestLogger()`.
> - **Señales de alerta**: el `guard` registra `guard.blocked` (origin/csrf/rate_limit) con
>   `requestId`; documentados los eventos que paginan (`payments.webhook.mismatch`, `*.idor`,
>   `checkout.link_owner_failed`, picos 401/403/429, arranque abortado) en `docs/OBSERVABILITY.md`.
> - **Retención y PII**: pino ya redacta `authorization/cookie/password/token`; no se registran
>   emails ni tarjetas; política de retención y tratamiento de IP/`userId` documentada.
> - Sondas liveness/readiness (§4) sirven para checks sintéticos.
>
> **Pendiente (no bloqueante):** métricas formales OpenTelemetry/Prometheus (histogramas de
> latencia/throughput) y un panel/alerting concreto en tu agregador.

- **Sentry está cableado** (`sentry.client/server/edge.config.ts` + `src/instrumentation.ts`)
  y el logger usa `pino` con eventos estructurados consistentes. Buen punto de partida.
- Falta **correlación de request** (request-id propagado de Caddy → Next → WP) y panel/alertas
  definidas (qué eventos paginan: `payments.webhook.mismatch`, `*.idor`, picos de 401/429).
- Definir **retención y PII**: los logs incluyen `userId`/eventos; documentar qué se retiene
  y por cuánto (alineado con la doc de seguridad y, si aplica, RGPD/Habeas Data).

---

## 6. Calidad, tests y CI/CD — Prioridad P1
**— ✅ MAYORMENTE COMPLETADO (2026-06-19).**

> **Implementado:**
> - **Tests unitarios nuevos** (`tests/unit/`): `origin`, `csrf` (double-submit + firma),
>   `jwt` (verify/expirado/firma inválida), `rate-limit` (fail-open vs memoria estricta),
>   `idempotency`, `lock`+`replay` (degradación sin Redis), `secret-guard`, `request-ip`
>   (anti-spoofing XFF) y `order-events`. Sintaxis verificada; lógica clave ejecutada con un
>   smoke contra el código real (origin/secret-guard/lock/replay → 8/8 asserts).
> - **E2E de compra** (`tests/e2e/purchase.spec.ts`, opt-in `E2E_FULL=1`): registro → carrito
>   → checkout → `payments/create` → webhook `noop` firmado → **el comprador ve su pedido**
>   con `customer_id` ligado. Es la regresión directa del bug §1.1.
> - **CI**: e2e con `next build` antes de `next start` (§1.2); paso de cobertura añadido como
>   informe (no bloqueante hasta validar umbrales reales con la suite ejecutada).
>
> **Pendiente menor:** ejecutar la suite de cobertura para fijar umbrales reales y activarla
> como *gate*; ampliar la suite de accesibilidad axe a más páginas. *(Nota: la suite no se
> pudo correr en el sandbox por binarios nativos incompatibles; corre en CI/local con `npm test`.)*

Cobertura actual: unit tests de `format`, `validation`, y la capa de pagos (`noop`,
`orders`, `signature`). E2E es un único *smoke* sin WordPress real. El guard, CSRF, sesión,
IDOR, idempotencia de checkout y los flujos de cuenta **no tienen pruebas**.

**Acción.**
- Unit/integration para: `guardMutation`, `verifyCsrf`, `verifyAuthToken`/`getSession`,
  idempotencia (proceed/replay/conflict), y las comprobaciones de propiedad (anti-IDOR).
- E2E real con la pila Docker sembrada: registro → login (+2FA) → carrito → checkout →
  **pago noop** → webhook firmado → pedido en `processing` → visible en cuenta. Esta única
  prueba habría detectado el bug §1.1.
- Arreglar el job e2e (§1.2) y añadir cobertura mínima exigida (`vitest --coverage`) como
  *gate*.
- Accesibilidad: `@axe-core/playwright` ya es dependencia; convertirlo en una suite que
  recorra las páginas clave y falle ante violaciones WCAG 2.2 AA.

---

## 7. Documentación y empaquetado comercial — Prioridad P1
**— ✅ COMPLETADO (2026-06-19).**

> **Implementado:**
> - **`AGENTS.md` actualizado al estado real**: mapa del repo (mu-plugins, lib, api, scripts,
>   tests, docs), flujos nuevos (2FA, verificación email, pedido↔cliente, meta atómica,
>   webhooks/efectos, analítica), *gotchas* corregidos (typography/Sentry/rate-limit/sesión) y
>   nueva **§11 mapa de documentación**; apunta a leer este plan primero.
> - **`CHANGELOG.md`**: bloque de "Auditoría y endurecimiento" con todo lo de §1–§7 (Keep a
>   Changelog + SemVer), incluido lo diferido a propósito.
> - **`docs/COMPATIBILITY.md`** (matriz WP/Woo/PHP/Node/Next/paquetes) y **`docs/GO-LIVE.md`**
>   (checklist de cero a producción, con el guard de secretos como paso bloqueante).
> - **`README.md`**: índice de documentación y referencia rota a `PRODUCTION-PLAN.md` corregida.
> - Runbook de operación: `docs/RUNBOOK.md` (creado en §4).

- **`AGENTS.md` está desfasado y afirma “proyecto completo, sin fases pendientes”.** Para una
  plantilla que se vende, esto es un riesgo de credibilidad y soporte. No documenta 2FA,
  verificación de email, wishlist, reseñas, direcciones, cupones, envío ni el HWE Control
  Center, y marca como “✅” áreas con flujos incompletos.
  **Acción:** actualizar el mapa de flujos (§6), la tabla de estado (§5) con estados reales
  (✅ / parcial / stub), y corregir *gotchas* obsoletos (p. ej. dice que `@tailwindcss/
  typography`/`prose` no está instalado, pero **sí** está en `devDependencies`).
- **CHANGELOG/versionado** y matriz de compatibilidad (WordPress 6.7, WooCommerce, WPGraphQL,
  PHP 8.3, Node 20, Next 15.1) para soporte a largo plazo.
- **Runbook de operación** (incidentes, rotación de secretos, restore) y guía “de cero a
  producción” que incluya el guard de secretos por defecto.

---

## 8. Qué falta para ser *excepcional* (visión de senior)

Funcionalmente, cerrando §1 y §2 la plantilla es **sólida**. Lo que separa “sólido” de
“excepcional” es **robustez bajo fallo, confianza operativa y experiencia llave en mano**:

1. **Recursividad/idempotencia real en todo el dominio de pago**, no solo en checkout:
   anti-replay con ventana temporal, store de IDs de evento, y conciliación tolerante a
   reentregas desordenadas (approved tardío tras voided, etc.).
2. **Una pasarela real LATAM integrada y certificada** como referencia viva del contrato.
3. **Sesiones de verdad**: revocación, rotación de refresh, “cerrar todas las sesiones”,
   2FA con códigos de recupero. La autenticación es donde más se nota la madurez.
4. **Atomicidad de datos de cliente** (wishlist/direcciones) y resistencia a concurrencia.
5. **Arranque que se niega a ser inseguro** (guard de secretos) — convierte un error humano
   caro en un fallo temprano barato.
6. **Suite de pruebas que recorre los caminos felices y de fallo de extremo a extremo** con
   la pila real, más accesibilidad automatizada como *gate*.
7. **CSP endurecida con nonce** y cifrado autenticado (GCM) en los secretos.
8. **Documentación honesta y operacional**: el mayor multiplicador de valor de una plantilla
   comercial es que el comprador llegue a producción sin sorpresas.

---

## 9. Plan priorizado (orden sugerido de ejecución)

| # | Área | Acción | Prioridad | Esfuerzo | Estado |
|---|------|--------|-----------|----------|--------|
| 1 | Tienda/Pagos | Ligar el pedido del checkout al `customer_id` de la sesión (§1.1) | P0 | M | ✅ |
| 2 | CI/CD | `next build` antes de `next start` en e2e; e2e con pila Docker (§1.2) | P0 | S | ✅ |
| 3 | Webhooks | Corregir `previousStatus` y definir efectos de los webhooks de pedido (§1.3) | P0 | S | ✅ |
| 4 | Auth | Política de verificación de email + disparo + expiración de token (§2.1) | P1 | M | 🟡 |
| 5 | Auth | 2FA: cifrar secreto, códigos de recupero, cerrar `2fa-status` (§2.2) | P1 | M | 🟡 |
| 6 | Seguridad | Guard de arranque que rechaza secretos por defecto en prod (§3.1) | P1 | S | ⬜ |
| 7 | Datos | Atomicidad de wishlist/direcciones (endpoint WP o lock Redis) (§2.6) | P1 | M | 🟡 |
| 8 | Tests | Cobertura de guard/CSRF/sesión/IDOR/idempotencia + e2e de compra (§6) | P1 | L | ⬜ |
| 9 | Docs | Actualizar `AGENTS.md`, tabla de estado real y *gotchas* (§7) | P1 | S | ⬜ |
| 10 | Pagos | Integrar y certificar una pasarela real LATAM (§2.4) | P1 | L | ⬜ |
| 11 | Seguridad | Anti-replay + rate-limit en webhooks; IP de confianza (§3.6, §3.7) | P2 | M | ⬜ |
| 12 | Seguridad | Revocación de sesión + rotación de refresh (§3.3) | P2 | M | ⬜ |
| 13 | Seguridad | `SecretsStorage` a AES-GCM + clave dedicada (§3.4) | P2 | S | ⬜ |
| 14 | Analítica | Cablear GA4/Plausible con Consent Mode y eventos e-commerce (§2.3) | P2 | M | 🟡 |
| 15 | Infra | Restore probado, métricas, revisar exposición de `/graphql` en Caddy (§4) | P2 | M | ⬜ |
| 16 | Seguridad | CSP con nonce (endurecimiento opcional) (§3.2) | P3 | M | ⬜ |

*Esfuerzo orientativo: S ≈ <1 día, M ≈ 1–3 días, L ≈ 1+ semana.*

---

### Nota sobre verificación
Esta auditoría es de **lectura de código estático**. Los hallazgos de los §1.1, §1.2 y §1.3
son deducciones de alta confianza a partir del flujo de datos, pero **no se han ejecutado en
vivo** con la pila Docker + WordPress real. Antes de cerrar el §1.1 conviene reproducir el
fallo con una compra de prueba y confirmar el `customer_id` del pedido resultante.
