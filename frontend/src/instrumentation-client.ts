// ============================================================================
//  Instrumentación de cliente (Sentry SDK v10).
//
//  Sentry 9+ sustituye `sentry.client.config.ts` por `instrumentation-client.ts`
//  (cargado automáticamente por Next.js en el navegador). Además exporta
//  `onRouterTransitionStart` para instrumentar las navegaciones del App Router.
//
//  Este archivo NO importa `@sentry/nextjs`: hacerlo metía el SDK (~70 kB gz
//  sin replay) en el bundle inicial de todas las páginas aunque no hubiera DSN.
//  El arranque vive en `lib/observability/client-sentry.ts` y carga el SDK por
//  import dinámico cuando el navegador está inactivo (ver su cabecera).
// ============================================================================

import { createClientSentry } from "@/lib/observability/client-sentry";

// `process.env.NEXT_PUBLIC_SENTRY_DSN` se sustituye por su valor al construir:
// sin DSN la rama del import dinámico es muerta y Turbopack no emite el chunk
// (por eso el import vive aquí, en el entry, y no en una dependencia síncrona).
const clientSentry = createClientSentry({
  dsn: process.env.NEXT_PUBLIC_SENTRY_DSN || null,
  ...(process.env.NEXT_PUBLIC_SENTRY_DSN
    ? {
        loadSdk: () =>
          import("@/lib/observability/sentry-sdk-loader").then((loader) =>
            loader.loadSentrySdk(),
          ),
      }
    : {}),
});

// Instrumentación de transiciones de ruta del App Router (Sentry v9+).
// Sin DSN es un no-op; con DSN adelanta la carga del SDK en la primera
// navegación si el navegador aún no había quedado inactivo.
export const onRouterTransitionStart = clientSentry.onRouterTransitionStart;
