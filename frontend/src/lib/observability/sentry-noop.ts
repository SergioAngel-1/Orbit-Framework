// ============================================================================
//  Stub de `@sentry/nextjs` para builds SIN DSN.
//
//  `next.config.mjs` lo resuelve en lugar del SDK real cuando no hay DSN (ni
//  público ni de servidor). El SDK de cliente pesa ~70 kB gz (sin replay) y el
//  de servidor arrastra OpenTelemetry: sin observabilidad no hay nada que
//  instrumentar, así que en ese build no viaja ni un byte.
//
//  Implementa solo la superficie que usa el framework (configs de servidor/edge
//  e instrumentación de cliente); es un no-op deliberado.
// ============================================================================

export function init(): void {}

export function captureException(): void {}

export function captureRouterTransitionStart(): void {}

/** Misma forma que la integración real, por si alguien la registra. */
export function replayIntegration(): { name: string } {
  return { name: "NoopReplay" };
}
