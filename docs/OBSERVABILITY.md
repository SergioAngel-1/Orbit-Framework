# Observabilidad y operación

> Cómo se observan, correlacionan y alertan las peticiones, y qué política de
> retención y datos personales (PII) se aplica a los logs. Complementa a
> `docs/RUNBOOK.md` (procedimientos) y a `docs/SECURITY.md` (modelo de amenazas).

---

## 1. Correlación de peticiones (request-id)

Cada petición lleva un identificador único que atraviesa toda la pila, de modo
que sus logs en Caddy, Next y WordPress se pueden cruzar.

```
Navegador
   │  (sin id de confianza)
   ▼
Caddy ── genera X-Request-Id = {http.request.uuid} y lo inyecta ──► upstream
   │                                                   (Caddyfile: request_header)
   ▼
Next.js (proxy.ts) ── reutiliza el X-Request-Id entrante o crea uno;
   │   lo reenvía a los Route Handlers (request) y lo DEVUELVE en la respuesta
   │   (lib/observability/request-id.ts)
   ▼
Route Handler ── runWithRequestId(id, …)  (lib/observability/request-context.ts)
   │   pone el id en un AsyncLocalStorage
   ▼
Clientes WP (graphql-client / woocommerce/client / store-client)
        añaden X-Request-Id a cada llamada server-to-server ──► WordPress
```

- **Cómo seguir una petición:** toma el `X-Request-Id` de la respuesta (o del log
  de Caddy) y filtra por `requestId` en los logs del frontend (pino) y por
  `X-Request-Id` en los logs/acceso de WordPress.
- **Propagación automática a WP:** ocurre dentro de `runWithRequestId(...)`. El
  webhook de pago ya lo envuelve; para extender la correlación a otro handler,
  envuelve su lógica igual:
  ```ts
  const requestId = getOrCreateRequestId(request.headers);
  return runWithRequestId(requestId, async () => { /* … */ });
  ```
  y usa `requestLogger()` para que cada línea lleve el `requestId`.

## 2. Logging

- **Motor:** `pino` (`lib/observability/logger.ts`), JSON por **stdout** → apto para
  Loki/Datadog/CloudWatch. Nivel con `LOG_LEVEL` (`info` en prod, `debug` en dev).
- **Eventos estructurados:** cada log lleva un campo `event` estable (p. ej.
  `payments.webhook.paid`, `guard.blocked`, `orders.get.idor`). Filtra por `event`.
- **Errores no controlados:** `src/instrumentation.ts` (`onRequestError`) los registra
  y, si `SENTRY_DSN` está definido, los envía a **Sentry**
  (`sentry.server.config.ts` y `sentry.edge.config.ts`).

### 2.1 Sentry en el navegador

- **Sin DSN público no se carga nada.** `src/instrumentation-client.ts` solo importa
  `lib/observability/client-sentry.ts`, que hace un import **dinámico** de
  `@sentry/nextjs`; el SDK (~178 kB gz con replay) ya no viaja en el bundle inicial
  de todas las páginas. Para activarlo define `NEXT_PUBLIC_SENTRY_DSN` (o `SENTRY_DSN`,
  que `next.config.mjs` reexpone como público) **en el build**: Next inlinea el valor.
- **Carga diferida:** el SDK se importa cuando el navegador queda inactivo
  (`requestIdleCallback`, con tope de 3 s) y, si el usuario navega antes, en la
  primera transición del App Router (`onRouterTransitionStart`). Los errores de la
  ventana previa se guardan en un buffer (20) y se envían al inicializar. Lo que se
  pierde: lo ocurrido antes de que se ejecute el script de arranque de Next.
- **Session Replay es opt-in:** `NEXT_PUBLIC_SENTRY_REPLAY=true`. Por defecto no se
  registra `replayIntegration()` (graba la sesión del comprador y pesa). Con replay
  activo, muestreo de sesión 10 % y 100 % en error.

## 3. Eventos que merecen alerta

| Evento (`event`) | HTTP | Significado | Acción sugerida |
|------------------|------|-------------|-----------------|
| `payments.webhook.mismatch` | 422 | Importe/moneda no casan con el pedido | **Páginar.** Posible fraude/config errónea; no marcar pagado a mano. |
| `payments.webhook.invalid` | 401 | Firma de webhook inválida (picos) | Investigar origen; revisar `*_SECRET` de la pasarela. |
| `orders.get.idor`, `payments.create.idor_attempt` | 404 | Intento de acceder a recurso ajeno | **Páginar si hay patrón.** Posible abuso/credenciales robadas. |
| `checkout.link_owner_failed` | — | Pedido creado sin ligar a cliente | Conciliar `customer_id` del pedido; revisar conectividad wc/v3. |
| `guard.blocked` (`reason: rate_limit`) | 429 | Rate-limit disparado (picos) | ¿Ataque o cliente legítimo? Ajustar límite o bloquear IP en Caddy. |
| `guard.blocked` (`reason: csrf`/`origin`) | 403 | Escrituras rechazadas (picos) | Posible CSRF/scraping; revisar `ALLOWED_ORIGIN`. |
| `health.check` con `status: degraded` | 200/503 | Dependencia caída (Redis/WP) | Restaurar la dependencia; el sitio degrada con elegancia. |
| Arranque abortado (guard de secretos) | — | Boot con secretos por defecto/cortos | Corregir `.env`; ver `docs/GO-LIVE.md`. |

> Umbrales orientativos: paginar `payments.webhook.mismatch` y patrones de `*.idor`
> de inmediato; alertar por **tasa** (p. ej. >X/min) en 401/403/429.

## 4. Sondas y métricas

- **Liveness:** `GET /api/health/live` (no toca dependencias).
- **Readiness:** `GET /api/health?ready=1` (503 si Redis/WP caídos) — úsalo en el
  balanceador. `GET /api/health` (sin flag) responde 200 con el detalle.
- **Métricas formales** (OpenTelemetry/Prometheus) **no** vienen incluidas; es el
  siguiente paso natural si necesitas histogramas de latencia/throughput. Mientras
  tanto, usa un check sintético externo contra `/api/health?ready=1`.

## 5. Retención de logs y datos personales (PII)

**Qué se registra** (contexto acotado, nunca cuerpos completos):
- `event`, `requestId`, rutas (`path`), códigos de estado, `userId` (id numérico
  interno de WordPress, **pseudónimo**), `orderId`, e **IP** (solo para rate-limit
  y diagnóstico de abuso), mensajes de error acotados.

**Qué NO se registra:**
- Contraseñas, tokens (JWT/CSRF), cookies, cabeceras `Authorization`: **redactados**
  por pino (`redact`). No se registran emails ni datos de tarjeta (los pagos los
  maneja la pasarela; la plantilla nunca ve la tarjeta).

**Política recomendada** (ajústala a tu jurisdicción — RGPD / Habeas Data):
- **Logs de aplicación:** 30–90 días. **Eventos de seguridad** (`*.idor`, `guard.blocked`,
  webhooks inválidos): 180 días–1 año.
- La **IP** es dato personal: limita el acceso a los logs, no los expongas
  públicamente y aplica la retención mínima necesaria.
- Si un usuario ejerce su derecho de supresión, recuerda que `userId` es pseudónimo;
  documenta cómo correlacionarlo y purgarlo de los agregadores si procede.
- Define **responsable** del acceso a logs y revísalo periódicamente.

> Estos valores son un punto de partida razonable, no asesoramiento legal.
