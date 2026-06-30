// ============================================================================
//  Instrumentación de cliente (Sentry SDK v10).
//
//  Sentry 9+ sustituye `sentry.client.config.ts` por `instrumentation-client.ts`
//  (cargado automáticamente por Next.js en el navegador). Además exporta
//  `onRouterTransitionStart` para instrumentar las navegaciones del App Router.
//
//  Solo se inicializa si hay DSN configurado.
// ============================================================================

import * as Sentry from "@sentry/nextjs";

Sentry.init({
  dsn: process.env.SENTRY_DSN,
  environment: process.env.NODE_ENV,
  tracesSampleRate: process.env.NODE_ENV === "production" ? 0.1 : 0,
  enabled: !!process.env.SENTRY_DSN,
  replaysSessionSampleRate: 0.1,
  replaysOnErrorSampleRate: 1.0,
  integrations: [Sentry.replayIntegration()],
});

// Instrumentación de transiciones de ruta del App Router (Sentry v9+).
export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
