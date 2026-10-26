# JS inicial: Sentry sin DSN y zod en los parsers del carrito

Fecha: 2026-10-26 · Rama: `perf/client-bundle` (framework Orbite, sobre `main` + fix de planes sin puntos)

## Resumen

1. **Sentry de cliente ya no viaja en el bundle inicial.** Sin DSN, `@sentry/nextjs` se resuelve a
   un no-op en el build y no queda ninguna referencia al SDK. Con DSN, el SDK se carga por import
   dinámico cuando el navegador queda inactivo (o en la primera navegación) y Session Replay es
   opt-in. El JS inicial de `/` bajó de **331,9 kB gz a 224,6 kB gz sin DSN** y **226,8 kB gz con
   DSN** (−32 %), medido con `next build` + `next start` sobre el stub de CI.
2. **Los parsers de extensiones del carrito ya no importan zod.** `lib/loyalty/cart.ts` y
   `lib/memberships/cart.ts` validan con guards manuales (`lib/validation/light.ts`) con las mismas
   reglas y los mismos tests. En este árbol zod **no** estaba en el bundle compartido (solo en la
   página de checkout, donde valida el formulario); el cambio elimina la dependencia de los parsers
   para cualquier instancia que los importe desde el cajón del carrito.
3. **Hallazgo sobre la premisa:** en este worktree no existe `components/cart/cart-panel.tsx` (el
   cajón es `cart-drawer.tsx`) y el cajón no importa los parsers de loyalty/memberships. Por eso la
   parte 2 no produce una bajada medible en el build del framework; sí deja el contrato a salvo de
   arrastrar zod si una instancia los usa en cliente.

## Cambios

### 1. Sentry de cliente (`instrumentation-client.ts` + `lib/observability/`)

- `src/instrumentation-client.ts` solo importa `createClientSentry` (módulo ligero). Ya no hay
  import estático del SDK.
- `src/lib/observability/client-sentry.ts`:
  - sin DSN → `enabled: false`, no programa nada y `onRouterTransitionStart` es no-op;
  - con DSN → instala un buffer de errores (20; `error` y `unhandledrejection`) y programa la carga
    en `requestIdleCallback` (tope 3 s; `setTimeout` si no existe);
  - la primera navegación del App Router fuerza la carga y encola la transición para no perderla;
  - `init` ocurre una sola vez; después se vacían errores y transiciones y se quitan los listeners
    propios (Sentry instala los suyos);
  - replay **solo** si `NEXT_PUBLIC_SENTRY_REPLAY === "true"` (muestreo 10 % / 100 % en error);
  - si el SDK no carga, se registra con `console.warn` y la tienda sigue.
- `src/lib/observability/sentry-sdk-loader.ts`: frontera del chunk diferido (import estático del SDK
  dentro del chunk, para que Turbopack no lo pliegue al arranque).
- `next.config.mjs`:
  - `NEXT_PUBLIC_SENTRY_DSN || SENTRY_DSN` se congela en el bundle (`env`). Un DSN no es secreto;
    así un despliegue que solo define `SENTRY_DSN` no pierde la captura de cliente;
  - **sin DSN**, `turbopack.resolveAlias` resuelve `@sentry/nextjs` a
    `src/lib/observability/sentry-noop.ts` (no-op con la superficie usada por servidor/edge/cliente);
  - `withSentryConfig` se activa con cualquiera de los dos DSN.
- `frontend/Dockerfile` y `docker-compose.prod.yml`: build args `NEXT_PUBLIC_SENTRY_DSN` y
  `NEXT_PUBLIC_SENTRY_REPLAY` (son valores congelados en el build, no de runtime).

**Qué se pierde:** los errores anteriores a la ejecución del script de instrumentación de Next (no
hay forma de capturarlos sin cargar el SDK antes) y, en un error de recurso sin objeto `Error`, se
envía el mensaje en vez del objeto. Los ocurridos entre el arranque y la inicialización sí se envían
(buffer).

**Limitación de empaquetado observada:** un import dinámico que cuelga de una dependencia síncrona
de `instrumentation-client` era precargado por Turbopack en todas las páginas (comprobado con
builds de prueba). Por eso el import dinámico vive en el propio entry y, sin DSN, el alias elimina
el SDK del build. Con DSN el chunk real (162,2 kB gz) queda fuera del HTML inicial y solo se pide al
ejecutar el import diferido.

### 2. Parsers del carrito sin zod

- `lib/validation/light.ts`: `isRecord`, `isString`, `isBoolean`, `isNonNegativeInteger`,
  `isMinorUnits` (equivalencias exactas de `z.object`, `z.string()`, `z.number().int().nonnegative()`
  y `z.string().regex(/^\d+$/)`).
- `lib/loyalty/cart.ts` y `lib/memberships/cart.ts`: misma aceptación/rechazo que el esquema zod,
  mismos motivos normalizados a `null`, mismas claves extra descartadas al reconstruir el objeto.
  El contrato de datos no cambia.
- Por qué manual y no zod diferido: los esquemas son pequeños y fijos; un validador manual no añade
  un chunk ni una petición, se audita de un vistazo y conserva la promesa de «nunca lanza». Zod
  diferido seguiría descargando ~34 kB gz para validar ocho campos.

## Medición

Método: `next build` con `WORDPRESS_INTERNAL_API_URL=http://127.0.0.1:18162/graphql` (stub de CI en
`STUB_GRAPHQL_PORT=18162`) y `next start` en `:3111`; se suman los `<script src="/_next/...">` del
HTML de cada ruta y su tamaño **gzip** (nivel 9) descargado del servidor. No incluye CSS, fuentes ni
peticiones de datos.

| Ruta | Antes (gzip) | Después sin DSN | Después con DSN |
|------|-------------:|----------------:|----------------:|
| `/` | 331,9 kB | **224,6 kB** | **226,8 kB** |
| `/products` | 328,1 kB | 219,4 kB | — |
| `/cart` | 326,8 kB | 219,5 kB | — |
| `/checkout` | 361,9 kB | 254,6 kB | — |

- Chunk de Sentry antes: `3divv239mbzen.js`, **178,1 kB gz** (incluía también el bootstrap de Next,
  con replay).
- Build final sin DSN: ningún chunk contiene el SDK (`event_id`/`@sentry/*` ausentes); el stub
  no-op son 397 B y no se referencia desde el HTML.
- Build con DSN: el SDK real queda en `1n-razp9zl2zd.js`, **162,2 kB gz**, fuera de los 13 scripts
  iniciales; se pide al ejecutar el import diferido.
- Zod: `ZodError` solo aparece en el chunk del formulario de checkout (33,6 kB gz) antes y después.
  No estaba en el bundle compartido de este árbol (ver «Hallazgo»).

## Pruebas

- `frontend/src/instrumentation-client.test.ts` (nuevo): importar la instrumentación sin DSN no
  importa Sentry (la fábrica mockeada no se ejecuta) y exporta `onRouterTransitionStart`.
- `frontend/src/lib/observability/client-sentry.test.ts` (nuevo, jsdom): sin DSN no carga; con DSN
  espera al programador; replay solo con opción; transición previa encolada y enviada; buffer de
  errores previos; fallo de carga sin lanzar; arranque autónomo por idle.
- `frontend/src/lib/observability/sentry-noop.test.ts` y `sentry-sdk-loader.test.ts` (nuevos).
- `lib/loyalty/cart.test.ts` y `lib/memberships/cart.test.ts`: se añadió `vi.mock("zod")` que
  revienta si el módulo vuelve a importarlo (rojo comprobado antes del cambio) y casos de extensión
  parcial, tipos inválidos, motivo/aviso ausente y claves extra. Los tests existentes no cambiaron
  de expectativas.

Comandos ejecutados (todos en verde):

```bash
cd frontend
npm run type-check
npm run lint
npm run format:check
npm run i18n:check
npm run test            # 343 archivos, 3299 tests
npm run test:coverage   # umbrales superados (statements 88,99 %, branches 82,03 %)
npx next build          # con el stub en STUB_GRAPHQL_PORT=18162 (sin DSN y con DSN)
cd ../backend
COMPOSER_PROCESS_TIMEOUT=0 php /tmp/opencode/tools/composer.phar test   # 2166 tests OK
cd ..
bash .github/scripts/check-anti-instance.sh                             # 0 coincidencias
```

## Pendiente / no verificado

- No se abrió un navegador real: la carga diferida y el envío del buffer están cubiertos con tests
  unitarios y por la estructura del build (el chunk del SDK no aparece en el HTML inicial), pero no
  se comprobó en DevTools ni contra un proyecto Sentry real.
- El build con DSN se hizo con un DSN ficticio (`https://public@example.invalid/1`) para verificar
  el troceado; no se subieron source maps ni se enviaron eventos.
- En esta rama la premisa del cajón (`cart-panel.tsx` importando los parsers) no se reproduce; si la
  instancia que midió 430 kB tiene su propio cajón importando esos parsers, el cambio 2 sí le quita
  zod, pero el framework no puede medir esa bajada.
- Queda abierto (fuera de alcance) revisar si el resto de ~225 kB gz del framework se puede recortar
  (React/Next + UI); el presupuesto de 130–150 kB no se alcanza solo con estos dos cambios.
