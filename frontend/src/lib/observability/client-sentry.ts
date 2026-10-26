// ============================================================================
//  Arranque DIFERIDO de Sentry en el navegador.
//
//  El SDK de cliente pesa ~178 kB gz con replay: importarlo de forma estática
//  desde `instrumentation-client.ts` lo metía en el bundle inicial de TODAS las
//  páginas, incluso sin DSN (`enabled: false` ahorraba peticiones, no bytes).
//
//  Reglas de este módulo:
//   - Sin `NEXT_PUBLIC_SENTRY_DSN` no se importa nada de Sentry: cero bytes.
//   - Con DSN, el SDK se carga por import dinámico cuando el navegador queda
//     inactivo (o en la primera navegación del App Router, si llega antes).
//   - Replay es opt-in (`NEXT_PUBLIC_SENTRY_REPLAY=true`); por defecto no se
//     registra la integración.
//   - Los errores de la ventana previa a la carga se guardan en un buffer y se
//     envían al inicializar. Lo único que se pierde es lo que ocurra antes de
//     que se ejecute este script (que Next inyecta como módulo de arranque) y
//     los errores de recursos que el navegador no expone como `ErrorEvent.error`
//     (se envía el mensaje).
//
//  Los DSN NO son secretos: Sentry los diseña para viajar en el navegador. El
//  proyecto activa el cliente con `NEXT_PUBLIC_SENTRY_DSN` y, si no existe,
//  `next.config.mjs` reutiliza `SENTRY_DSN` para no romper despliegues previos.
// ============================================================================

export type SentrySdk = typeof import("@sentry/nextjs");

/** Cuántos errores/transiciones se guardan antes de inicializar. */
const BUFFER_LIMIT = 20;

/** Tope de espera del `requestIdleCallback` (ms). */
const IDLE_TIMEOUT_MS = 3000;

/** DSN público del cliente. Next solo inlinea el acceso literal. */
export function readClientSentryDsn(): string | undefined {
  const value = process.env.NEXT_PUBLIC_SENTRY_DSN?.trim();
  return value ? value : undefined;
}

/** Replay es opt-in explícito; cualquier otro valor (incluido `1`) es «no». */
export function clientSentryReplayEnabled(): boolean {
  return process.env.NEXT_PUBLIC_SENTRY_REPLAY === "true";
}

export interface ClientSentryOptions {
  /** DSN; `null` fuerza «sin Sentry». Por defecto, el público del build. */
  dsn?: string | null;
  /** Registra replay. Por defecto, `NEXT_PUBLIC_SENTRY_REPLAY`. */
  replay?: boolean;
  /** Programador del arranque (los tests lo controlan). */
  defer?: (run: () => void) => void;
  /**
   * Cargador del SDK. Obligatorio para inicializar: el import dinámico vive en
   * `instrumentation-client.ts` (entry), donde la condición de DSN se puede
   * plegar en el build y el chunk no entra si no hay Sentry.
   */
  loadSdk?: () => Promise<SentrySdk>;
}

export interface ClientSentry {
  /** Resuelve cuando termina el intento de carga (al instante sin DSN). */
  readonly ready: Promise<void>;
  /** `false` sin DSN: no se importa ni se inicializa nada. */
  readonly enabled: boolean;
  /** Función que `instrumentation-client.ts` exporta para Next. */
  onRouterTransitionStart(
    href: string,
    navigationType: "push" | "replace" | "traverse",
  ): void;
}

/**
 * Programa la carga para cuando el navegador esté inactivo. Importante: no se
 * ejecuta durante el arranque, así que el chunk de Sentry no compite con el
 * bundle que pinta la página.
 */
function scheduleWhenIdle(run: () => void): void {
  if (typeof window === "undefined") return;

  const start = () => {
    const requestIdle = window.requestIdleCallback;
    if (typeof requestIdle === "function") {
      requestIdle(run, { timeout: IDLE_TIMEOUT_MS });
    } else {
      // Safari (y navegadores sin requestIdleCallback): siguiente macrotarea.
      window.setTimeout(run, 0);
    }
  };

  if (document.readyState === "complete") start();
  else window.addEventListener("load", start, { once: true });
}

/**
 * Crea el arranque del cliente. `instrumentation-client.ts` crea uno por página;
 * los tests crean los suyos con el cargador y el programador inyectados.
 */
export function createClientSentry(options: ClientSentryOptions = {}): ClientSentry {
  const dsn = options.dsn === undefined ? readClientSentryDsn() : options.dsn;
  const replay = options.replay ?? clientSentryReplayEnabled();
  const enabled = typeof dsn === "string" && dsn.length > 0;

  const loadSdk: (() => Promise<SentrySdk>) | null = options.loadSdk ?? null;

  const bufferedErrors: unknown[] = [];
  const bufferedTransitions: Array<{
    href: string;
    navigationType: "push" | "replace" | "traverse";
  }> = [];

  let sdk: SentrySdk | null = null;
  let sdkPromise: Promise<SentrySdk | null> | null = null;
  let bufferInstalled = false;
  let resolveReady!: () => void;
  const ready = new Promise<void>((resolve) => {
    resolveReady = resolve;
  });

  const onError = (event: Event) => {
    const errorEvent = event as ErrorEvent;
    bufferedErrors.push(errorEvent.error ?? errorEvent.message);
    if (bufferedErrors.length > BUFFER_LIMIT) bufferedErrors.shift();
  };
  const onUnhandledRejection = (event: Event) => {
    bufferedErrors.push((event as PromiseRejectionEvent).reason);
    if (bufferedErrors.length > BUFFER_LIMIT) bufferedErrors.shift();
  };

  const installErrorBuffer = () => {
    if (bufferInstalled || typeof window === "undefined") return;
    window.addEventListener("error", onError);
    window.addEventListener("unhandledrejection", onUnhandledRejection);
    bufferInstalled = true;
  };

  const removeErrorBuffer = () => {
    if (!bufferInstalled) return;
    window.removeEventListener("error", onError);
    window.removeEventListener("unhandledrejection", onUnhandledRejection);
    bufferInstalled = false;
  };

  const ensureSdk = (): Promise<SentrySdk | null> => {
    if (!enabled) return Promise.resolve(null);
    if (sdk) return Promise.resolve(sdk);
    if (sdkPromise) return sdkPromise;
    if (!loadSdk) {
      console.warn("[hwe] sentry: DSN configurado sin cargador del SDK");
      resolveReady();
      return Promise.resolve(null);
    }

    sdkPromise = loadSdk()
      .then((loaded) => {
        loaded.init({
          dsn: dsn as string,
          environment: process.env.NODE_ENV,
          tracesSampleRate: process.env.NODE_ENV === "production" ? 0.1 : 0,
          enabled: true,
          ...(replay
            ? {
                replaysSessionSampleRate: 0.1,
                replaysOnErrorSampleRate: 1.0,
                integrations: [loaded.replayIntegration()],
              }
            : {}),
        });

        sdk = loaded;

        // El SDK ya instaló sus handlers globales: el buffer deja de hacer falta.
        removeErrorBuffer();
        for (const error of bufferedErrors.splice(0)) loaded.captureException(error);
        for (const transition of bufferedTransitions.splice(0)) {
          loaded.captureRouterTransitionStart(
            transition.href,
            transition.navigationType,
          );
        }

        return loaded;
      })
      .catch((error: unknown) => {
        // Sin SDK no hay observabilidad, pero la tienda debe seguir funcionando.
        console.warn("[hwe] sentry: no se pudo cargar el SDK de cliente", error);
        return null;
      });

    return sdkPromise;
  };

  if (enabled) {
    installErrorBuffer();

    if (options.defer) {
      options.defer(() => {
        void ensureSdk().finally(resolveReady);
      });
    } else if (typeof window !== "undefined") {
      scheduleWhenIdle(() => {
        void ensureSdk().finally(resolveReady);
      });
    } else {
      // Importado en el servidor (tests, SSR accidental): no cargar el SDK.
      resolveReady();
    }
  } else {
    resolveReady();
  }

  return {
    ready,
    enabled,
    onRouterTransitionStart(href, navigationType) {
      if (!enabled) return;
      if (sdk) {
        sdk.captureRouterTransitionStart(href, navigationType);
        return;
      }

      if (bufferedTransitions.length >= BUFFER_LIMIT) bufferedTransitions.shift();
      bufferedTransitions.push({ href, navigationType });
      // Una navegación es actividad real: adelanta la carga si el idle no llegó.
      void ensureSdk().finally(resolveReady);
    },
  };
}
